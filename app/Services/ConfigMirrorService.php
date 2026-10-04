<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Copia de la CONFIGURACIÓN DEL SISTEMA del master a un slave (genérico).
 *
 * Complementa a lo que ya se replica (BD, Redis, ficheros de /var/www/vhosts y
 * hostings del panel) con lo que vive fuera de esas carpetas y hace falta para
 * que un relevo funcione: programas de supervisor, tareas cron, webs fijas del
 * Caddyfile y pools de PHP-FPM.
 *
 * Reglas:
 *  - Solo actúa en un SLAVE no aislado. Nunca en el master.
 *  - Adapta al papel de reserva: programas con autostart=false, crons desactivados
 *    (#MUSEDOCK-OFF# en un bloque propio del crontab; ficheros de cron.d como
 *    <nombre>.musedock-off, que cron ignora). Al promover, activate() los enciende
 *    y deactivate() los vuelve a apagar al degradar/aislar.
 *  - Verifica antes de aplicar (ejecutable/carpeta/usuario de cada programa,
 *    sintaxis de cron, caddy validate, php-fpm -t). Si algo no pasa, se omite ese
 *    elemento y se informa; lo demás se aplica.
 *  - NUNCA borra: si el master deja de tener algo que se copió, se aparta con el
 *    sufijo .removed-by-mirror. Solo toca lo que copió él (estado en Settings):
 *    lo que sea propio del slave no se modifica.
 *  - Caddy NO se recarga y el Caddyfile en uso NO se toca: las webs del master se
 *    guardan validadas en CADDY_STAGED y activate() las pone al promover (restart,
 *    que dispara el reparador de rutas).
 * Copias de seguridad en /var/backups/musedock-mirror/.
 */
final class ConfigMirrorService
{
    private const OFF = '#MUSEDOCK-OFF#';
    private const BLOCK_BEGIN = '# >>> musedock-mirror: tareas copiadas del master (se activan al promover) >>>';
    private const BLOCK_END = '# <<< musedock-mirror <<<';
    private const BACKUP_DIR = '/var/backups/musedock-mirror';
    private const CRON_SKIP = ['e2scrub_all', 'php', 'sysstat', 'certbot', '.placeholder', 'popularity-contest'];
    /** Unidades que cada nodo tiene por sí mismo (del panel) y no se copian. */
    private const UNIT_SKIP = ['musedock-panel.service', 'musedock-stale-master-check.service'];

    // ── MASTER: exportar ─────────────────────────────────────────────────

    /** Acción de cluster export-system-config (la llama el slave). */
    public static function export(): array
    {
        $sup = [];
        foreach (glob('/etc/supervisor/conf.d/*.conf') ?: [] as $f) {
            $sup[basename($f)] = (string)@file_get_contents($f);
        }
        $crontabs = [];
        foreach (glob('/var/spool/cron/crontabs/*') ?: [] as $f) {
            $crontabs[basename($f)] = (string)@file_get_contents($f);
        }
        $cronD = [];
        foreach (glob('/etc/cron.d/*') ?: [] as $f) {
            $n = basename($f);
            if (str_starts_with($n, 'musedock') || in_array($n, self::CRON_SKIP, true) || str_contains($n, '.')) {
                continue; // los del panel los pone cada panel; con punto = desactivados
            }
            $cronD[$n] = (string)@file_get_contents($f);
        }
        $pools = [];
        foreach (glob('/etc/php/*/fpm/pool.d/*.conf') ?: [] as $f) {
            $pools[basename(dirname(dirname(dirname($f)))) . '/' . basename($f)] = (string)@file_get_contents($f);
        }
        // Servicios systemd propios (apps fuera del panel: /opt/<app>...), con si están
        // habilitados en el master. No los del propio panel ni los de snap.
        $units = [];
        foreach (glob('/etc/systemd/system/*.service') ?: [] as $f) {
            $n = basename($f);
            if (is_link($f) || in_array($n, self::UNIT_SKIP, true) || str_starts_with($n, 'snap.')) {
                continue;
            }
            $units[$n] = ['content' => (string)@file_get_contents($f),
                'enabled' => trim((string)shell_exec('systemctl is-enabled ' . escapeshellarg($n) . ' 2>/dev/null')) === 'enabled'];
        }
        return [
            'ok' => true,
            'panel_port' => (int)(Settings::get('panel_port', '8444') ?: 8444),
            'supervisor' => $sup,
            'crontabs' => $crontabs,
            'cron_d' => $cronD,
            'caddyfile' => (string)@file_get_contents('/etc/caddy/Caddyfile'),
            'fpm_pools' => $pools,
            'systemd' => $units,
        ];
    }

    // ── SLAVE: traer y aplicar ───────────────────────────────────────────

    private static function role(): string
    {
        return Settings::get('cluster_role', '') ?: \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
    }

    private static function state(): array
    {
        $defaults = ['supervisor' => [], 'cron_d' => [], 'crontabs' => [], 'systemd' => [], 'caddy_pending' => false];
        $s = json_decode(Settings::get('cluster_config_mirror_state', '{}'), true);
        return is_array($s) ? $s + $defaults : $defaults;
    }

    private static function saveState(array $s): void
    {
        Settings::set('cluster_config_mirror_state', json_encode($s, JSON_UNESCAPED_SLASHES));
    }

    private static function backup(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        @mkdir(self::BACKUP_DIR, 0700, true);
        @copy($path, self::BACKUP_DIR . '/' . str_replace('/', '_', ltrim($path, '/')) . '.' . date('Ymd-His'));
    }

    private static function masterNode(): ?array
    {
        foreach (ClusterService::getNodes() as $n) {
            if (($n['role'] ?? '') === 'master') {
                return $n;
            }
        }
        return null;
    }

    /**
     * Una pasada completa. $apply=false = solo informa de lo que haría.
     * @return array{ok:bool, applied:bool, actions:array, issues:array}
     */
    public static function run(bool $apply = true): array
    {
        if (self::role() !== 'slave') {
            return ['ok' => false, 'applied' => false, 'actions' => [], 'issues' => ['Solo se ejecuta en un slave (este servidor es ' . self::role() . ').']];
        }
        if (Settings::get('cluster_fenced', '0') === '1') {
            return ['ok' => false, 'applied' => false, 'actions' => [], 'issues' => ['Este nodo está aislado: no se copia nada.']];
        }
        $master = self::masterNode();
        if ($master) {
            $r = ClusterService::callNode((int)$master['id'], 'POST', 'api/cluster/action', ['action' => 'export-system-config', 'payload' => []]);
        } else {
            // Slave unido por el método antiguo: no tiene al master registrado como nodo,
            // pero el master sí tiene a este slave con SU token de cluster, así que la
            // API del master acepta ese mismo token. Se usa la IP de la que llegan los
            // latidos del master (VPN) y el puerto del panel.
            $ip = Settings::get('cluster_master_heartbeat_ip', '') ?: Settings::get('cluster_master_ip', '');
            $raw = Settings::get('cluster_local_token', '');
            $token = $raw !== '' ? ReplicationService::decryptPassword($raw) : '';
            if (!filter_var($ip, FILTER_VALIDATE_IP) || $token === '') {
                return ['ok' => false, 'applied' => false, 'actions' => [], 'issues' => ['No hay master registrado ni se conoce su IP (latidos) o el token de este nodo.']];
            }
            $port = (int)(Settings::get('panel_port', '8444') ?: 8444);
            $r = ClusterService::callNodeDirect("https://{$ip}:{$port}", $token, 'POST', 'api/cluster/action',
                ['action' => 'export-system-config', 'payload' => []], 60);
        }
        $cfg = $r['data'] ?? null;
        if (empty($r['ok']) || empty($cfg['ok'])) {
            return ['ok' => false, 'applied' => false, 'actions' => [], 'issues' => ['El master no devolvió su configuración: ' . ($cfg['error'] ?? $r['error'] ?? '?') . ' (¿panel del master anterior a 1.0.242?)']];
        }

        // Lo que este slave no debe copiar (propio de la máquina del master, p. ej. el
        // cambio de líneas WAN o el ventilador de una GPU): ni se copia ni se avisa.
        $excluded = self::excluded();
        $skipped = [];
        foreach (['supervisor' => 'supervisor/', 'cron_d' => 'cron.d/', 'crontabs' => 'crontab:', 'fpm_pools' => 'php-fpm ', 'systemd' => 'systemd/'] as $k => $prefix) {
            foreach (array_keys((array)($cfg[$k] ?? [])) as $name) {
                if (in_array($prefix . $name, $excluded, true)) {
                    unset($cfg[$k][$name]);
                    $skipped[] = $prefix . $name;
                }
            }
        }

        $state = self::state();
        $actions = [];
        $issues = [];
        self::mirrorSupervisor((array)$cfg['supervisor'], $state, $apply, $actions, $issues);
        self::mirrorCronD((array)$cfg['cron_d'], $state, $apply, $actions, $issues);
        self::mirrorCrontabs((array)$cfg['crontabs'], $state, $apply, $actions, $issues);
        self::mirrorCaddyfile((string)$cfg['caddyfile'], (int)$cfg['panel_port'], $state, $apply, $actions, $issues);
        self::mirrorPools((array)$cfg['fpm_pools'], $apply, $actions, $issues);
        self::mirrorSystemd((array)($cfg['systemd'] ?? []), $state, $apply, $actions, $issues);

        if ($apply) {
            self::saveState($state);
            Settings::set('cluster_config_mirror_last', json_encode([
                'at' => date('Y-m-d H:i:s'), 'changes' => count(array_filter($actions, static fn($a) => $a['result'] !== 'igual')),
                'issues' => $issues,
            ], JSON_UNESCAPED_UNICODE));
            $changed = array_filter($actions, static fn($a) => !in_array($a['result'], ['igual', 'omitido'], true));
            if ($changed || $issues) {
                LogService::log('cluster.mirror', 'run', count($changed) . ' cambios, ' . count($issues) . ' avisos');
            }
            // Se avisa cuando la lista de problemas CAMBIA (antes, cada hora mientras siguiera:
            // dos servicios propios de Filemon habrían mandado 24 correos al día).
            $hash = $issues ? md5(implode("\n", $issues)) : '';
            if ($hash !== '' && $hash !== Settings::get('cluster_config_mirror_alert_hash', '')) {
                try {
                    NotificationService::send('Cluster: la copia de configuración del master tiene avisos',
                        "Algunos elementos no se han copiado a este slave porque no pasaron la verificación:\n- " . implode("\n- ", $issues)
                        . "\n\nNo se repetirá mientras no cambie. Si alguno es propio de la máquina del master (p. ej. un servicio de su hardware),"
                        . " exclúyelo de la copia: MCP config_mirror con exclude, o php bin/cluster-switch.php mirror-exclude <elemento>.",
                        'config_mirror');
                } catch (\Throwable) {
                }
            }
            Settings::set('cluster_config_mirror_alert_hash', $hash);
        }
        return ['ok' => true, 'applied' => $apply, 'actions' => $actions, 'issues' => $issues, 'excluded' => $skipped];
    }

    /** Elementos excluidos de la copia en este slave, p. ej. "systemd/wan-failover.service", "cron.d/x", "crontab:usuario". */
    public static function excluded(): array
    {
        $v = json_decode((string)Settings::get('cluster_config_mirror_exclude', '[]'), true);
        return is_array($v) ? array_values(array_map('strval', $v)) : [];
    }

    public static function setExcluded(array $add, array $remove = []): array
    {
        $list = array_values(array_diff(array_unique(array_merge(self::excluded(), array_map('trim', $add))), array_map('trim', $remove)));
        $list = array_values(array_filter($list, static fn($x) => (bool)preg_match('#^(systemd/|supervisor/|cron\.d/|crontab:|php-fpm )[A-Za-z0-9_.@/ -]+$#', $x)));
        Settings::set('cluster_config_mirror_exclude', json_encode($list));
        LogService::log('cluster.mirror', 'exclude', 'Excluidos de la copia: ' . (implode(', ', $list) ?: 'ninguno'));
        return $list;
    }

    // ── Supervisor ──

    /** Fuerza autostart=false en cada [program:x]; devuelve [contenido, autostart original por programa]. */
    private static function supervisorOff(string $content): array
    {
        $out = [];
        $orig = [];
        $prog = null;
        foreach (preg_split('/\r?\n/', $content) as $line) {
            if (preg_match('/^\s*\[program:([^\]]+)\]\s*$/', $line, $m)) {
                $prog = trim($m[1]);
                $orig[$prog] = 'true'; // supervisor: autostart=true por defecto
                $out[] = $line;
                $out[] = 'autostart=false';
                continue;
            }
            if (preg_match('/^\s*\[/', $line)) {
                $prog = null;
            }
            if ($prog !== null && preg_match('/^\s*autostart\s*=\s*(\S+)/i', $line, $m)) {
                $orig[$prog] = strtolower($m[1]);
                continue; // se sustituye por la línea forzada
            }
            $out[] = $line;
        }
        return [implode("\n", $out), $orig];
    }

    private static function supervisorProblems(string $content): array
    {
        $p = [];
        $ini = @parse_ini_string($content, true, INI_SCANNER_RAW);
        if (!is_array($ini)) {
            return ['sintaxis INI no válida'];
        }
        foreach ($ini as $sec => $kv) {
            if (!str_starts_with((string)$sec, 'program:') || !is_array($kv)) {
                continue;
            }
            $cmd = trim((string)($kv['command'] ?? ''));
            $bin = strtok($cmd, " \t") ?: '';
            if ($bin === '') {
                $p[] = "{$sec}: sin command";
            } elseif (str_starts_with($bin, '/') ? !is_executable($bin) : trim((string)shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null')) === '') {
                $p[] = "{$sec}: no existe el ejecutable {$bin}";
            }
            if (!empty($kv['directory']) && !is_dir((string)$kv['directory'])) {
                $p[] = "{$sec}: no existe la carpeta {$kv['directory']}";
            }
            if (!empty($kv['user']) && function_exists('posix_getpwnam') && !posix_getpwnam((string)$kv['user'])) {
                $p[] = "{$sec}: no existe el usuario {$kv['user']}";
            }
        }
        return $p;
    }

    private static function mirrorSupervisor(array $files, array &$state, bool $apply, array &$actions, array &$issues): void
    {
        if (!is_dir('/etc/supervisor/conf.d')) {
            if ($files) {
                $issues[] = 'Supervisor no está instalado en este slave: no se copian sus ' . count($files) . ' programas.';
            }
            return;
        }
        $changed = false;
        foreach ($files as $name => $content) {
            if (!preg_match('/^[A-Za-z0-9_.-]+\.conf$/', (string)$name)) {
                continue;
            }
            $path = "/etc/supervisor/conf.d/{$name}";
            [$adapted, $orig] = self::supervisorOff((string)$content);
            $problems = self::supervisorProblems($adapted);
            if ($problems) {
                $issues[] = "Supervisor {$name}: " . implode('; ', $problems);
                $actions[] = ['what' => "supervisor/{$name}", 'result' => 'omitido'];
                continue;
            }
            $current = is_file($path) ? (string)file_get_contents($path) : null;
            $state['supervisor'][$name] = $orig;
            if ($current !== null && self::normSup($current) === self::normSup($adapted)) {
                $actions[] = ['what' => "supervisor/{$name}", 'result' => 'igual'];
                continue;
            }
            $actions[] = ['what' => "supervisor/{$name}", 'result' => $current === null ? 'nuevo (parado)' : 'actualizado (parado)'];
            if ($apply) {
                self::backup($path);
                file_put_contents($path, $adapted);
                $changed = true;
            }
        }
        // Lo que se copió antes y el master ya no tiene: se aparta, no se borra.
        foreach (array_keys($state['supervisor']) as $name) {
            if (!array_key_exists($name, $files) && is_file("/etc/supervisor/conf.d/{$name}")) {
                $actions[] = ['what' => "supervisor/{$name}", 'result' => 'ya no está en el master: se aparta (.removed-by-mirror)'];
                if ($apply) {
                    @rename("/etc/supervisor/conf.d/{$name}", "/etc/supervisor/conf.d/{$name}.removed-by-mirror");
                    unset($state['supervisor'][$name]);
                    $changed = true;
                }
            }
        }
        if ($apply && $changed) {
            // reread + update: registra los cambios; con autostart=false no arranca nada.
            shell_exec('supervisorctl reread >/dev/null 2>&1; supervisorctl update >/dev/null 2>&1');
        }
    }

    /** Comparación ignorando espacios finales y líneas vacías. */
    private static function normSup(string $s): string
    {
        return implode("\n", array_filter(array_map('rtrim', preg_split('/\r?\n/', $s)), static fn($l) => $l !== ''));
    }

    // ── Cron ──

    private static function cronLineValid(string $l, bool $withUser): bool
    {
        if (preg_match('/^@(reboot|yearly|annually|monthly|weekly|daily|midnight|hourly)\s+\S/', $l)) {
            return true;
        }
        $n = $withUser ? 7 : 6; // 5 campos (+ usuario en cron.d) + comando
        return count(preg_split('/\s+/', trim($l), $n)) >= $n;
    }

    private static function normCron(string $l): string
    {
        $l = trim($l);
        if (str_starts_with($l, self::OFF)) {
            $l = trim(substr($l, strlen(self::OFF)));
        }
        return preg_replace('/\s+/', ' ', $l);
    }

    private static function mirrorCronD(array $files, array &$state, bool $apply, array &$actions, array &$issues): void
    {
        foreach ($files as $name => $content) {
            if (!preg_match('/^[A-Za-z0-9_-]+$/', (string)$name)) {
                continue;
            }
            $active = "/etc/cron.d/{$name}";
            // El slave ya lo tiene (activo, o apartado por otro medio con el mismo contenido): no se toca.
            foreach ([$active, "{$active}.disabled"] as $existing) {
                if (is_file($existing) && self::normSup((string)file_get_contents($existing)) === self::normSup((string)$content)) {
                    $actions[] = ['what' => "cron.d/{$name}", 'result' => 'igual'];
                    continue 2;
                }
            }
            if (is_file($active)) {
                $issues[] = "cron.d/{$name}: el slave tiene su propia versión activa, distinta de la del master; no se toca.";
                $actions[] = ['what' => "cron.d/{$name}", 'result' => 'omitido'];
                continue;
            }
            $bad = [];
            foreach (preg_split('/\r?\n/', (string)$content) as $l) {
                $t = trim($l);
                if ($t !== '' && !str_starts_with($t, '#') && !preg_match('/^[A-Z_]+=/', $t) && !self::cronLineValid($t, true)) {
                    $bad[] = $t;
                }
            }
            if ($bad) {
                $issues[] = "cron.d/{$name}: líneas no válidas: " . implode(' | ', array_slice($bad, 0, 3));
                $actions[] = ['what' => "cron.d/{$name}", 'result' => 'omitido'];
                continue;
            }
            $off = "{$active}.musedock-off"; // cron ignora ficheros con punto en el nombre
            $state['cron_d'][$name] = true;
            if (is_file($off) && self::normSup((string)file_get_contents($off)) === self::normSup((string)$content)) {
                $actions[] = ['what' => "cron.d/{$name}", 'result' => 'igual'];
                continue;
            }
            $actions[] = ['what' => "cron.d/{$name}", 'result' => is_file($off) ? 'actualizado (desactivado)' : 'nuevo (desactivado)'];
            if ($apply) {
                self::backup($off);
                file_put_contents($off, $content);
                @chmod($off, 0644);
            }
        }
    }

    private static function readCrontab(string $user): string
    {
        return (string)@file_get_contents("/var/spool/cron/crontabs/{$user}");
    }

    private static function mirrorCrontabs(array $tabs, array &$state, bool $apply, array &$actions, array &$issues): void
    {
        foreach ($tabs as $user => $content) {
            if (!preg_match('/^[A-Za-z0-9_.-]+$/', (string)$user)) {
                continue;
            }
            if (function_exists('posix_getpwnam') && !posix_getpwnam((string)$user)) {
                $issues[] = "crontab de {$user}: el usuario no existe en este slave.";
                $actions[] = ['what' => "crontab:{$user}", 'result' => 'omitido'];
                continue;
            }
            $current = self::readCrontab((string)$user);
            // Lo que ya tiene el slave FUERA del bloque (activo o desactivado) no se duplica.
            [$outside, $insideOld] = self::splitBlock($current);
            $have = [];
            foreach ($outside as $l) {
                $have[self::normCron($l)] = true;
            }
            $block = [];
            foreach (preg_split('/\r?\n/', (string)$content) as $l) {
                $t = trim($l);
                if ($t === '' || (str_starts_with($t, '#') && !str_starts_with($t, self::OFF)) || preg_match('/^[A-Z_]+=/', $t)) {
                    continue;
                }
                $n = self::normCron($t);
                if (isset($have[$n])) {
                    continue;
                }
                if (!self::cronLineValid($n, false)) {
                    $issues[] = "crontab de {$user}: línea no válida: {$n}";
                    continue;
                }
                $block[] = self::OFF . ' ' . $n;
            }
            if (implode("\n", $block) === implode("\n", $insideOld)) {
                $actions[] = ['what' => "crontab:{$user}", 'result' => 'igual'];
                if ($block) {
                    $state['crontabs'][$user] = true;
                }
                continue;
            }
            $actions[] = ['what' => "crontab:{$user}", 'result' => ($block ? count($block) . ' tareas en el bloque copiado (desactivadas)' : 'bloque copiado vacío')];
            if ($apply) {
                $new = rtrim(implode("\n", $outside)) . "\n";
                if ($block) {
                    $new .= self::BLOCK_BEGIN . "\n" . implode("\n", $block) . "\n" . self::BLOCK_END . "\n";
                    $state['crontabs'][$user] = true;
                } else {
                    unset($state['crontabs'][$user]);
                }
                self::installCrontab((string)$user, $new);
            }
        }
    }

    /** [líneas fuera del bloque, líneas dentro del bloque] */
    private static function splitBlock(string $crontab): array
    {
        $out = [];
        $in = [];
        $inside = false;
        foreach (preg_split('/\r?\n/', $crontab) as $l) {
            if (trim($l) === self::BLOCK_BEGIN) {
                $inside = true;
                continue;
            }
            if (trim($l) === self::BLOCK_END) {
                $inside = false;
                continue;
            }
            if ($inside) {
                if (trim($l) !== '') {
                    $in[] = trim($l);
                }
            } else {
                $out[] = $l;
            }
        }
        return [$out, $in];
    }

    private static function installCrontab(string $user, string $content): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mdcron');
        file_put_contents($tmp, $content);
        shell_exec('crontab -u ' . escapeshellarg($user) . ' ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);
    }

    // ── Caddyfile ──

    /** Bloques de primer nivel: [['head' => string, 'text' => string], ...]. */
    private static function caddyBlocks(string $caddyfile): array
    {
        $blocks = [];
        $cur = null;
        $depth = 0;
        foreach (preg_split('/\r?\n/', $caddyfile) as $line) {
            $code = preg_replace('/(^|\s)#.*$/', '', $line);
            if ($cur === null) {
                if (trim($code) === '') {
                    continue;
                }
                $cur = ['head' => trim($line), 'lines' => []];
            }
            $cur['lines'][] = $line;
            $depth += substr_count($code, '{') - substr_count($code, '}');
            if ($depth <= 0 && str_contains($code . implode('', $cur['lines']), '{')) {
                $blocks[] = ['head' => $cur['head'], 'text' => implode("\n", $cur['lines'])];
                $cur = null;
                $depth = 0;
            }
        }
        return $blocks;
    }

    private static function isPanelOrGlobal(array $b, int $panelPort, int $i): bool
    {
        if ($i === 0 && trim($b['head']) === '{') {
            return true; // opciones globales
        }
        return (bool)preg_match('/:' . $panelPort . '\b/', $b['head']);
    }

    /**
     * Las webs del master NO van al Caddyfile en uso mientras el nodo es slave:
     * cualquier reinicio de Caddy (reboot, bin/update.sh) lo cargaría, pediría
     * certificados de dominios que no apuntan aquí y, si le falta un secreto
     * ({env.…}), Caddy no arrancaría y caerían el panel y el correo del slave.
     * Se guardan aparte (CADDY_STAGED) y activate() las pone al promover.
     */
    private const CADDY_LIVE = '/etc/caddy/Caddyfile';
    private const CADDY_STAGED = '/var/lib/musedock/Caddyfile.from-master';
    /** Caddyfile propio del slave, guardado al poner el del master (promote) para devolverlo (demote). */
    private const CADDY_OWN = '/var/lib/musedock/Caddyfile.own';

    /** Bloques de webs (ni globales ni del panel) de un Caddyfile. */
    private static function caddySites(string $file, int $panelPort): array
    {
        $sites = [];
        foreach (self::caddyBlocks($file) as $i => $b) {
            if (!self::isPanelOrGlobal($b, $panelPort, $i)) {
                $sites[] = $b['text'];
            }
        }
        return $sites;
    }

    /** Opciones globales + bloque del panel de un Caddyfile. */
    private static function caddyKeep(string $file, int $panelPort): array
    {
        $keep = [];
        foreach (self::caddyBlocks($file) as $i => $b) {
            if (self::isPanelOrGlobal($b, $panelPort, $i)) {
                $keep[] = $b['text'];
            }
        }
        return $keep;
    }

    /**
     * Hasta 1.0.252 la copia escribía las webs del master en el Caddyfile en uso.
     * Lo devuelve a lo propio del slave: globales + panel actuales y las webs que
     * tenía antes de la primera copia (la copia de seguridad más antigua).
     */
    private static function restoreOwnCaddyfile(array &$state, int $myPort, bool $apply, array &$actions, array &$issues): void
    {
        if (empty($state['caddy_pending']) || !empty($state['caddy_staged']) || !is_file(self::CADDY_LIVE)) {
            return;
        }
        $live = (string)file_get_contents(self::CADDY_LIVE);
        $backups = glob(self::BACKUP_DIR . '/etc_caddy_Caddyfile.*') ?: [];
        sort($backups);
        $ownSites = $backups ? self::caddySites((string)@file_get_contents($backups[0]), $myPort) : [];
        $own = implode("\n\n", array_merge(self::caddyKeep($live, $myPort), $ownSites)) . "\n";
        if (self::normSup($own) === self::normSup($live)) {
            $state['caddy_staged'] = true;
            return;
        }
        [$ok, $out] = self::caddyValidate($own, $ownSites, false);
        if (!$ok) {
            $issues[] = 'Caddyfile en uso: tiene las webs del master (versión anterior de la copia) y no se pudo reconstruir el propio del slave: '
                . trim(implode(' ', array_slice($out, -2))) . '. Revísalo a mano: si Caddy se reinicia, cargará las webs del master.';
            return;
        }
        $actions[] = ['what' => 'Caddyfile en uso', 'result' => 'se devuelve el propio del slave (panel + ' . count($ownSites) . ' webs propias); las del master quedan aparte hasta el relevo'];
        if ($apply) {
            self::backup(self::CADDY_LIVE);
            file_put_contents(self::CADDY_LIVE, $own);
            $state['caddy_staged'] = true;
        }
    }

    /**
     * caddy validate como el usuario caddy, con el entorno del servicio. Los
     * `output file` donde caddy aún no puede escribir se apuntan a /tmp. Con
     * $fillMissingEnv, las {env.X} que falten llevan un valor de relleno para
     * validar el resto. Devuelve [ok, salida, variables que faltan].
     */
    private static function caddyValidate(string $content, array $sites, bool $fillMissingEnv): array
    {
        $toValidate = $content;
        $logFix = self::caddyLogPathsToFix($sites);
        if ($logFix) {
            $tmpLogs = sys_get_temp_dir() . '/mdcaddy-logs';
            @mkdir($tmpLogs, 0777, true);
            @chmod($tmpLogs, 0777);
            foreach (array_keys($logFix) as $n => $p) {
                $toValidate = preg_replace('/(\boutput\s+file\s+)' . preg_quote($p, '/') . '(?=\s|$)/m', '${1}' . "{$tmpLogs}/log{$n}.log", $toValidate);
            }
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mdcaddy');
        file_put_contents($tmp, $toValidate);
        @chmod($tmp, 0644);
        $validate = self::userExists('caddy') && is_dir('/var/lib/caddy')
            ? 'runuser -u caddy -- env HOME=/var/lib/caddy caddy validate --adapter caddyfile --config ' . escapeshellarg($tmp) . ' 2>&1'
            : 'caddy validate --adapter caddyfile --config ' . escapeshellarg($tmp) . ' 2>&1';
        // Con el entorno del servicio caddy ({env.CLOUDFLARE_API_TOKEN}…), como al
        // arrancar de verdad. Por el entorno del proceso, no por la línea de
        // órdenes: así el token no aparece en `ps`.
        $env = self::caddyServiceEnv();
        $missingEnv = [];
        preg_match_all('/\{env\.([A-Za-z_][A-Za-z0-9_]*)\}/', implode("\n", $sites), $em);
        foreach (array_unique($em[1] ?? []) as $var) {
            if (($env[$var] ?? '') === '') {
                $missingEnv[] = $var;
                if ($fillMissingEnv) {
                    $env[$var] = str_repeat('x', 40);
                }
            }
        }
        // runuser borra el entorno al cambiar de usuario: las variables pasadas al proceso
        // no llegaban a caddy validate, que fallaba siempre con {env.CLOUDFLARE_API_TOKEN}
        // vacío ("loading TLS automation management module"), y al promover el Caddyfile
        // del master no se aplicaba nunca (Filemon, 2026-10-03). Ahora el entorno se carga
        // DENTRO de la orden, desde un fichero temporal que solo puede leer caddy (el token
        // tampoco sale en `ps`).
        $envFile = '';
        if (str_starts_with($validate, 'runuser') && $env) {
            $envFile = tempnam('/run', 'mdcaddyenv') ?: tempnam(sys_get_temp_dir(), 'mdcaddyenv');
            $lines = '';
            foreach ($env as $k => $v) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$k)) {
                    $lines .= 'export ' . $k . '=' . escapeshellarg((string)$v) . "\n";
                }
            }
            file_put_contents($envFile, $lines);
            @chmod($envFile, 0600);
            @chown($envFile, 'caddy');
            $validate = 'runuser -u caddy -- sh -c ' . escapeshellarg('. ' . escapeshellarg($envFile) . ' && HOME=/var/lib/caddy exec caddy validate --adapter caddyfile --config ' . escapeshellarg($tmp)) . ' 2>&1';
        }
        [$out, $rc] = self::runWithEnv($validate, $env);
        @unlink($tmp);
        if ($envFile !== '') {
            @unlink($envFile);
        }
        return [$rc === 0, $out, $missingEnv];
    }

    private static function mirrorCaddyfile(string $masterFile, int $masterPanelPort, array &$state, bool $apply, array &$actions, array &$issues): void
    {
        $path = self::CADDY_LIVE;
        if (!is_file($path) || $masterFile === '') {
            return;
        }
        $myPort = (int)(Settings::get('panel_port', '8444') ?: 8444);
        self::restoreOwnCaddyfile($state, $myPort, $apply, $actions, $issues);
        $mine = (string)file_get_contents($path);
        $sites = self::caddySites($masterFile, $masterPanelPort);
        $candidate = implode("\n\n", array_merge(self::caddyKeep($mine, $myPort), $sites)) . "\n";
        $staged = is_file(self::CADDY_STAGED) ? (string)file_get_contents(self::CADDY_STAGED) : '';
        if ($staged !== '' && self::normSup($candidate) === self::normSup($staged)) {
            $actions[] = ['what' => 'Caddyfile', 'result' => 'igual'];
            return;
        }
        // Logs de Caddy (`output file …`) donde caddy no puede escribir en el slave:
        // en el master la carpeta logs/ del hosting es de caddy, aquí la creó el panel
        // a nombre del hosting (lsyncd excluye logs/, así que nadie la iguala).
        // Para validar se apuntan a /tmp; al aplicar se les da permiso a caddy.
        $logFix = self::caddyLogPathsToFix($sites);
        [$ok, $out, $missingEnv] = self::caddyValidate($candidate, $sites, true);
        foreach ($missingEnv as $var) {
            $issues[] = "Caddy de este nodo no tiene {$var} en su entorno (/etc/default/caddy) y las webs del master lo usan: si hubiera un relevo, "
                . 'NO se pondrían esas webs (Caddy seguiría con su configuración propia) hasta que esté. '
                . ($var === 'CLOUDFLARE_API_TOKEN'
                    ? 'Copia /etc/default/caddy del master (no hace falta reiniciar Caddy: se lee al promover), o desde el master en Cluster → Failover → Cuentas Cloudflare con "Actualizar token de Caddy" (ojo: usa la PRIMERA cuenta y reinicia Caddy en todos los nodos).'
                    : 'Hay que añadirlo a mano en /etc/default/caddy.');
        }
        $rc = $ok ? 0 : 1;
        if ($rc !== 0) {
            $issues[] = 'Caddyfile: el resultado no pasa caddy validate; no se aplica: ' . trim(implode(' ', array_slice($out, -2)));
            $actions[] = ['what' => 'Caddyfile', 'result' => 'omitido'];
            return;
        }
        // Qué líneas cambian (para revisarlo antes de aplicar): solo las de las webs.
        // Sin secretos en la salida: hashes de basic_auth ($2a$…) y tokens largos.
        $norm = static fn(string $s) => array_values(array_filter(array_map(
            static fn($l) => preg_replace(['/\$2[aby]\$\S+/', '/\b[A-Za-z0-9_\-]{32,}\b/'], '***', trim($l)),
            preg_split('/\r?\n/', $s)), static fn($l) => $l !== '' && !str_starts_with($l, '#')));
        $prevSites = $staged !== '' ? self::caddySites($staged, $myPort) : self::caddySites($mine, $myPort);
        [$was, $will] = [$norm(implode("\n", $prevSites)), $norm(implode("\n", $sites))];
        $actions[] = ['what' => 'Caddyfile', 'result' => 'guardado aparte (' . self::CADDY_STAGED . '); el Caddyfile en uso no se toca: se pone al promover',
            'lines_added' => array_slice(array_values(array_diff($will, $was)), 0, 30),
            'lines_removed' => array_slice(array_values(array_diff($was, $will)), 0, 30)];
        foreach ($logFix as $p => $how) {
            $actions[] = ['what' => "log de Caddy {$p}", 'result' => $how];
        }
        if ($apply) {
            foreach ($logFix as $p => $how) {
                $dir = dirname($p);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                    @chown($dir, 'caddy');
                    @chgrp($dir, 'caddy');
                } else {
                    // Permiso añadido (ACL): el dueño de la carpeta no cambia.
                    exec('setfacl -m u:caddy:rwx -m d:u:caddy:rw ' . escapeshellarg($dir) . ' 2>&1', $o, $rc);
                    if (is_file($p)) {
                        exec('setfacl -m u:caddy:rw ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
                    }
                }
                if (!self::caddyCanWrite($p)) {
                    $issues[] = "Caddyfile: caddy sigue sin poder escribir {$p}; no se aplica.";
                    $actions[] = ['what' => 'Caddyfile', 'result' => 'omitido'];
                    return;
                }
            }
            @mkdir(dirname(self::CADDY_STAGED), 0750, true);
            file_put_contents(self::CADDY_STAGED, $candidate);
            @chmod(self::CADDY_STAGED, 0600);
            $state['caddy_pending'] = true;
            $state['caddy_staged'] = true;
        }
    }

    /**
     * Al promover: pone las webs del master (guardadas aparte) en el Caddyfile en
     * uso, solo si valida con el entorno REAL de Caddy (sin rellenos). Si no
     * valida, Caddy sigue con lo que tenía: mejor sin esas webs que sin Caddy.
     */
    private static function activateCaddyfile(array &$done, bool $stagedMode): void
    {
        if (!is_file(self::CADDY_STAGED) || !is_file(self::CADDY_LIVE)) {
            if (!$stagedMode && is_file(self::CADDY_LIVE)) {
                shell_exec('systemctl restart caddy 2>&1'); // modo antiguo: ya estaba en uso
                $done[] = 'Caddy reiniciado con el Caddyfile copiado';
            }
            return;
        }
        $myPort = (int)(Settings::get('panel_port', '8444') ?: 8444);
        $live = (string)file_get_contents(self::CADDY_LIVE);
        $sites = self::caddySites((string)file_get_contents(self::CADDY_STAGED), $myPort);
        $candidate = implode("\n\n", array_merge(self::caddyKeep($live, $myPort), $sites)) . "\n";
        foreach (array_keys(self::caddyLogPathsToFix($sites)) as $p) {
            $dir = dirname($p);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
                @chown($dir, 'caddy');
                @chgrp($dir, 'caddy');
            } else {
                exec('setfacl -m u:caddy:rwx -m d:u:caddy:rw ' . escapeshellarg($dir) . ' 2>&1');
                if (is_file($p)) {
                    exec('setfacl -m u:caddy:rw ' . escapeshellarg($p) . ' 2>&1');
                }
            }
        }
        [$ok, $out, $missing] = self::caddyValidate($candidate, $sites, false);
        if (!$ok) {
            $done[] = 'Caddyfile del master NO puesto (no valida' . ($missing ? '; falta ' . implode(', ', $missing) . ' en /etc/default/caddy' : '')
                . '): Caddy sigue con su configuración. ' . trim(implode(' ', array_slice($out, -1)));
            return;
        }
        self::backup(self::CADDY_LIVE);
        @copy(self::CADDY_LIVE, self::CADDY_OWN);   // para devolverlo al volver a ser slave
        file_put_contents(self::CADDY_LIVE, $candidate);
        // Caddy con --resume arranca con su configuración guardada e IGNORA el Caddyfile:
        // reiniciarlo no ponía nada (Filemon, 2026-10-03). Se inyectan por la API.
        if (self::caddyUsesResume()) {
            $r = self::injectSites($sites);
            $done[] = !empty($r['ok'])
                ? 'Webs del master puestas en Caddy por la API: ' . implode(', ', $r['hosts'])
                : 'Caddyfile del master NO puesto en Caddy (la API lo rechazó): ' . ($r['error'] ?? '?');
            return;
        }
        shell_exec('systemctl restart caddy 2>&1'); // el reparador repone las rutas del panel
        $done[] = 'Caddy reiniciado con las webs del master';
    }

    /** ¿Arranca Caddy con --resume (configuración guardada en vez del Caddyfile)? */
    private static function caddyUsesResume(): bool
    {
        return str_contains((string)shell_exec('systemctl show caddy -p ExecStart --value 2>/dev/null'), '--resume');
    }

    /**
     * Mete en el Caddy en marcha las webs de unos bloques de Caddyfile, sin tocar nada
     * más: se convierten a JSON (caddy adapt) y se añaden sus rutas (con @id
     * "cfmirror-<host>"; las que ya estaban se sustituyen) y sus políticas de
     * certificado. Los comodines (*.dominio) van al final, para no tapar las rutas de
     * nombres concretos. Si la API de Caddy rechaza algo, no se aplica esa parte.
     */
    public static function injectSites(array $sites, bool $dryRun = false): array
    {
        if (!$sites) {
            return ['ok' => true, 'hosts' => []];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mdadapt');
        file_put_contents($tmp, implode("\n\n", $sites) . "\n");
        @chmod($tmp, 0644);
        $json = (string)shell_exec('caddy adapt --adapter caddyfile --config ' . escapeshellarg($tmp) . ' 2>/dev/null');
        @unlink($tmp);
        $cfg = json_decode($json, true);
        // Lo que se manda a la API sale de esta copia con objetos: con arrays asociativos,
        // un objeto vacío ({}, p. ej. el matcher "file" de php_fastcgi) volvería como []
        // y Caddy lo rechaza ("cannot unmarshal array into ... MatchFile").
        $cfgObj = json_decode($json);
        if (!is_array($cfg) || !is_object($cfgObj)) {
            return ['ok' => false, 'error' => 'caddy adapt no pudo convertir los bloques'];
        }
        $api = rtrim((string)((require PANEL_ROOT . '/config/panel.php')['caddy']['api_url'] ?? 'http://localhost:2019'), '/');
        $call = static function (string $method, string $path, $body = null) use ($api): array {
            $ch = curl_init($api . $path);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
            }
            $out = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$code, $out];
        };

        // Rutas de todos los servidores HTTP adaptados → al servidor de las webs (srv0).
        $routes = [];
        $skipped = [];
        foreach ((array)($cfg['apps']['http']['servers'] ?? []) as $srvName => $srv) {
            // Solo las webs del 443. Bloques de otros puertos (:8446, :8448…) son servicios
            // propios de aquel servidor: no se meten en el servidor de las webs.
            if (!in_array(':443', (array)($srv['listen'] ?? []), true)) {
                $skipped[] = implode(',', (array)($srv['listen'] ?? []));
                continue;
            }
            foreach ((array)($srv['routes'] ?? []) as $ri => $r) {
                $hosts = [];
                foreach ((array)($r['match'] ?? []) as $m) {
                    $hosts = array_merge($hosts, (array)($m['host'] ?? []));
                }
                if (!$hosts) {
                    continue;
                }
                $id = 'cfmirror-' . substr(preg_replace('/[^a-z0-9]+/', '-', strtolower(str_replace('*', 'wild', $hosts[0]))), 0, 60);
                $obj = $cfgObj->apps->http->servers->{$srvName}->routes[$ri];
                $obj->{'@id'} = $id;
                $routes[] = ['id' => $id, 'route' => $obj, 'hosts' => $hosts, 'wild' => str_starts_with($hosts[0], '*.')];
            }
        }
        usort($routes, static fn($a, $b) => (int)$a['wild'] <=> (int)$b['wild']);

        // Lo que ya sirve este Caddy por el panel u otra aplicación (hostings, CMS…) NO
        // se toca: esa ruta es la buena aquí (p. ej. musedock.com con su raíz y su
        // socket de PHP de este nodo). Solo se mete lo que falta (*.dominio, license…).
        $served = [];
        [$lc, $lj] = $call('GET', '/config/apps/http/servers/srv0/routes');
        $walk = static function (array $rs) use (&$walk, &$served): void {
            foreach ($rs as $r) {
                if (str_starts_with((string)($r['@id'] ?? ''), 'cfmirror-')) {
                    continue;
                }
                foreach ((array)($r['match'] ?? []) as $m) {
                    foreach ((array)($m['host'] ?? []) as $h) {
                        $served[strtolower((string)$h)] = true;
                    }
                }
            }
        };
        $walk($lc === 200 ? (json_decode($lj, true) ?: []) : []);
        $alreadyServed = [];
        $routes = array_values(array_filter($routes, static function ($x) use ($served, &$alreadyServed) {
            $missing = array_filter($x['hosts'], static fn($h) => !isset($served[strtolower((string)$h)]));
            if (!$missing) {
                $alreadyServed = array_merge($alreadyServed, $x['hosts']);
                return false;
            }
            return true;
        }));

        if ($dryRun) {
            return ['ok' => true, 'dry_run' => true,
                'would_add' => array_values(array_merge(...array_map(static fn($x) => $x['hosts'], $routes ?: [['hosts' => []]]))),
                'already_served' => $alreadyServed, 'other_ports_skipped' => $skipped];
        }
        $put = [];
        $errors = [];
        foreach ($routes as $x) {
            $id = $x['id'];
            [$code] = $call('GET', "/id/{$id}");
            if ($code === 200) {
                [$c2, $o2] = $call('PATCH', "/id/{$id}", $x['route']);
            } elseif ($x['wild']) {
                [$c2, $o2] = $call('POST', '/config/apps/http/servers/srv0/routes', $x['route']);
            } else {
                [$c2, $o2] = $call('PUT', '/config/apps/http/servers/srv0/routes/0', $x['route']);
            }
            if ($c2 >= 200 && $c2 < 300) {
                $put = array_merge($put, $x['hosts']);
            } else {
                $errors[] = implode(',', $x['hosts']) . ': HTTP ' . $c2 . ' ' . trim($o2);
            }
        }

        // Políticas de certificado de esos bloques (p. ej. reto DNS): delante de las demás.
        $newPol = array_values(array_filter((array)($cfgObj->apps->tls->automation->policies ?? []),
            static fn($p) => (bool)array_intersect(array_map('strtolower', (array)($p->subjects ?? [])), array_map('strtolower', $put))));
        if ($newPol) {
            [$pc, $pj] = $call('GET', '/config/apps/tls/automation/policies');
            $live = $pc === 200 ? (array)(json_decode($pj) ?: []) : [];
            $subj = static fn($p) => json_encode(array_values((array)($p->subjects ?? [])));
            $newKeys = array_map($subj, $newPol);
            $keep = array_values(array_filter($live, static fn($p) => !in_array($subj($p), $newKeys, true)));
            [$c3, $o3] = $call($pc === 200 ? 'PATCH' : 'PUT', '/config/apps/tls/automation/policies', array_merge($newPol, $keep));
            if ($c3 < 200 || $c3 >= 300) {
                $errors[] = 'políticas TLS: HTTP ' . $c3 . ' ' . trim($o3);
            }
        }
        return ['ok' => !$errors, 'hosts' => $put, 'error' => implode(' | ', $errors), 'other_ports_skipped' => $skipped, 'already_served' => $alreadyServed];
    }

    /**
     * Lo contrario de activateCaddyfile: al volver a ser slave, el Caddyfile vuelve a
     * ser el propio (sin las webs del master). Usa la copia guardada al promover; si no
     * hay (promoción con una versión anterior), el bloque propio del Caddyfile actual +
     * las webs de la copia más antigua del panel. Valida antes de escribir. No reinicia
     * Caddy si está parado (nodo apartado): lo arrancará unfence.
     */
    public static function deactivateCaddyfile(): ?string
    {
        if (!is_file(self::CADDY_LIVE) || !is_file(self::CADDY_STAGED)) {
            return null;
        }
        $live = (string)file_get_contents(self::CADDY_LIVE);
        $myPort = (int)(Settings::get('panel_port', '8444') ?: 8444);
        if (is_file(self::CADDY_OWN)) {
            $own = (string)file_get_contents(self::CADDY_OWN);
            $ownSites = self::caddySites($own, $myPort);
        } else {
            $backups = glob(self::BACKUP_DIR . '/etc_caddy_Caddyfile.*') ?: [];
            sort($backups);
            $ownSites = $backups ? self::caddySites((string)@file_get_contents($backups[0]), $myPort) : [];
            $own = implode("\n\n", array_merge(self::caddyKeep($live, $myPort), $ownSites)) . "\n";
        }
        if (self::normSup($own) === self::normSup($live)) {
            return null;
        }
        [$ok, $out] = self::caddyValidate($own, $ownSites, false);
        if (!$ok) {
            return 'Caddyfile propio NO restaurado (no valida): ' . trim(implode(' ', array_slice($out, -1)));
        }
        self::backup(self::CADDY_LIVE);
        file_put_contents(self::CADDY_LIVE, $own);
        if (trim((string)shell_exec('systemctl is-active caddy 2>/dev/null')) === 'active') {
            shell_exec('systemctl restart caddy 2>&1');
            return 'Caddyfile propio de slave restaurado y Caddy reiniciado';
        }
        return 'Caddyfile propio de slave restaurado (Caddy parado: arrancará al reactivar)';
    }

    /**
     * Rutas `output file` de las webs del master donde caddy no puede escribir
     * aquí. Solo las que se pueden igualar sin riesgo: carpeta logs/ de un
     * hosting o /var/log/caddy. Devuelve [ruta => qué se hará].
     */
    private static function caddyLogPathsToFix(array $sites): array
    {
        if (!self::userExists('caddy')) {
            return [];
        }
        preg_match_all('/\boutput\s+file\s+(\S+)/', implode("\n", $sites), $m);
        $fix = [];
        foreach (array_unique($m[1] ?? []) as $p) {
            if (!preg_match('#^(/var/www/vhosts/[A-Za-z0-9._-]+/logs|/var/log/caddy)/[A-Za-z0-9._-]+$#', $p) || str_contains($p, '..')) {
                continue;
            }
            if (!self::caddyCanWrite($p)) {
                $fix[$p] = is_dir(dirname($p))
                    ? 'se da permiso de escritura a caddy (ACL; el dueño no cambia)'
                    : 'se crea la carpeta, de caddy:caddy';
            }
        }
        return $fix;
    }

    /** Entorno con el que systemd arranca caddy (EnvironmentFile= y Environment=). */
    private static function caddyServiceEnv(): array
    {
        $env = [];
        $files = trim((string)shell_exec('systemctl show caddy -p EnvironmentFiles --value 2>/dev/null'));
        foreach (preg_split('/\R/', $files) ?: [] as $line) {
            $f = preg_replace('/\s*\(ignore_errors=\w+\)\s*$/', '', trim($line));
            if ($f === '' || !is_file($f)) {
                continue;
            }
            foreach (preg_split('/\R/', (string)@file_get_contents($f)) ?: [] as $l) {
                if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $l, $m)) {
                    $env[$m[1]] = trim(trim($m[2]), "\"'");
                }
            }
        }
        $inline = trim((string)shell_exec('systemctl show caddy -p Environment --value 2>/dev/null'));
        foreach (preg_split('/\s+/', $inline) ?: [] as $kv) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $kv, $m)) {
                $env[$m[1]] = $m[2];
            }
        }
        return $env;
    }

    /** Ejecuta un comando añadiendo variables al entorno. Devuelve [líneas, código]. */
    private static function runWithEnv(string $cmd, array $extra): array
    {
        $env = array_merge(['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'], getenv(), $extra);
        $p = proc_open($cmd, [1 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($p)) {
            return [['no se pudo ejecutar caddy validate'], 1];
        }
        $outStr = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $rc = proc_close($p);
        return [preg_split('/\R/', trim($outStr)) ?: [], $rc];
    }

    private static function caddyCanWrite(string $file): bool
    {
        // La carpeta también: Caddy rota el log creando ficheros nuevos en ella.
        $targets = is_file($file) ? [$file, dirname($file)] : [dirname($file)];
        foreach ($targets as $t) {
            if (!file_exists($t)) {
                return false;
            }
            exec('runuser -u caddy -- test -w ' . escapeshellarg($t) . ' 2>/dev/null', $o, $rc);
            if ($rc !== 0) {
                return false;
            }
        }
        return true;
    }

    // ── Pools de PHP-FPM ──

    private static function mirrorPools(array $pools, bool $apply, array &$actions, array &$issues): void
    {
        $reload = [];
        foreach ($pools as $key => $content) {
            if (!preg_match('#^(\d+\.\d+)/([A-Za-z0-9_.-]+\.conf)$#', (string)$key, $m)) {
                continue;
            }
            [$ver, $file] = [$m[1], $m[2]];
            $dir = "/etc/php/{$ver}/fpm/pool.d";
            if (!is_dir($dir)) {
                continue; // esa versión de PHP no está en el slave (lo señala cluster_drift)
            }
            $user = preg_match('/^\s*user\s*=\s*(\S+)/m', (string)$content, $u) ? $u[1] : '';
            if ($user !== '' && function_exists('posix_getpwnam') && !posix_getpwnam($user)) {
                $actions[] = ['what' => "php-fpm {$ver}/{$file}", 'result' => 'omitido'];
                continue; // hosting que este slave no tiene
            }
            $path = "{$dir}/{$file}";
            $current = is_file($path) ? (string)file_get_contents($path) : null;
            if ($current !== null && self::normSup($current) === self::normSup((string)$content)) {
                $actions[] = ['what' => "php-fpm {$ver}/{$file}", 'result' => 'igual'];
                continue;
            }
            // Otro pool del slave (con otro nombre de fichero) con el mismo [nombre] o el
            // mismo socket: copiarlo dejaría a PHP-FPM sin poder arrancar (php-fpm -t no
            // lo detecta). Pasa cuando el panel del slave creó su propio pool al
            // sincronizar el hosting.
            $poolName = preg_match('/^\s*\[([^\]]+)\]/m', (string)$content, $pn) ? trim($pn[1]) : '';
            $listen = preg_match('/^\s*listen\s*=\s*(\S+)/m', (string)$content, $ln) ? trim($ln[1]) : '';
            $clash = null;
            foreach (glob("{$dir}/*.conf") ?: [] as $other) {
                if (basename($other) === $file) {
                    continue;
                }
                $oc = (string)@file_get_contents($other);
                if (($poolName !== '' && preg_match('/^\s*\[' . preg_quote($poolName, '/') . '\]/m', $oc))
                    || ($listen !== '' && preg_match('/^\s*listen\s*=\s*' . preg_quote($listen, '/') . '\s*$/m', $oc))) {
                    $clash = basename($other);
                    break;
                }
            }
            if ($clash !== null) {
                $issues[] = "php-fpm {$ver}/{$file}: el slave ya tiene {$clash} con el mismo pool o socket; no se copia para no romper PHP-FPM (revisa cuál debe quedar).";
                $actions[] = ['what' => "php-fpm {$ver}/{$file}", 'result' => 'omitido'];
                continue;
            }
            $actions[] = ['what' => "php-fpm {$ver}/{$file}", 'result' => $current === null ? 'nuevo' : 'actualizado'];
            if ($apply) {
                self::backup($path);
                file_put_contents($path, $content);
                exec("php-fpm{$ver} -t 2>&1", $o, $rc);
                if ($rc !== 0) {
                    if ($current === null) {
                        @rename($path, "{$path}.rejected-by-mirror");
                    } else {
                        file_put_contents($path, $current);
                    }
                    $issues[] = "php-fpm {$ver}/{$file}: PHP-FPM lo rechaza; se deja como estaba.";
                    continue;
                }
                $reload[$ver] = true;
            }
        }
        foreach (array_keys($reload) as $ver) {
            shell_exec("systemctl reload php{$ver}-fpm 2>&1");
        }
    }

    // ── Servicios systemd propios ──

    private static function unitProblems(string $content): array
    {
        $p = [];
        if (preg_match('/^\s*ExecStart\s*=\s*[-@+!:]*(\S+)/m', $content, $m)) {
            $bin = $m[1];
            if (str_starts_with($bin, '/') ? !is_executable($bin) : trim((string)shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null')) === '') {
                $p[] = "no existe el ejecutable {$bin}";
            }
        } else {
            $p[] = 'sin ExecStart';
        }
        if (preg_match('/^\s*WorkingDirectory\s*=\s*-?(\S+)/m', $content, $m) && !is_dir($m[1])) {
            $p[] = "no existe la carpeta {$m[1]}";
        }
        if (preg_match('/^\s*User\s*=\s*(\S+)/m', $content, $m) && function_exists('posix_getpwnam') && !posix_getpwnam($m[1])) {
            $p[] = "no existe el usuario {$m[1]}";
        }
        return $p;
    }

    private static function mirrorSystemd(array $units, array &$state, bool $apply, array &$actions, array &$issues): void
    {
        $state['systemd'] = $state['systemd'] ?? [];
        $reload = false;
        foreach ($units as $name => $u) {
            if (!preg_match('/^[A-Za-z0-9_.@-]+\.service$/', (string)$name) || in_array($name, self::UNIT_SKIP, true)) {
                continue;
            }
            $path = "/etc/systemd/system/{$name}";
            $content = (string)($u['content'] ?? '');
            $current = is_file($path) ? (string)file_get_contents($path) : null;
            // Una unidad propia del slave con el mismo nombre (no copiada antes) no se toca.
            if ($current !== null && !isset($state['systemd'][$name]) && self::normSup($current) !== self::normSup($content)) {
                $issues[] = "systemd {$name}: el slave tiene su propia versión, distinta de la del master; no se toca.";
                $actions[] = ['what' => "systemd/{$name}", 'result' => 'omitido'];
                continue;
            }
            $problems = self::unitProblems($content);
            if ($problems) {
                $issues[] = "systemd {$name}: " . implode('; ', $problems) . ' (¿faltan sus ficheros? se reintentará)';
                $actions[] = ['what' => "systemd/{$name}", 'result' => 'omitido'];
                continue;
            }
            $state['systemd'][$name] = !empty($u['enabled']);
            if ($current !== null && self::normSup($current) === self::normSup($content)) {
                $actions[] = ['what' => "systemd/{$name}", 'result' => 'igual'];
                continue;
            }
            $actions[] = ['what' => "systemd/{$name}", 'result' => ($current === null ? 'nuevo' : 'actualizado') . ' (parado y deshabilitado hasta el relevo)'];
            if ($apply) {
                self::backup($path);
                file_put_contents($path, $content);
                @chmod($path, 0644);
                $reload = true;
            }
        }
        foreach (array_keys($state['systemd']) as $name) {
            if (!array_key_exists($name, $units) && is_file("/etc/systemd/system/{$name}")) {
                $actions[] = ['what' => "systemd/{$name}", 'result' => 'ya no está en el master: se para y se aparta (.removed-by-mirror)'];
                if ($apply) {
                    shell_exec('systemctl disable --now ' . escapeshellarg($name) . ' >/dev/null 2>&1');
                    @rename("/etc/systemd/system/{$name}", "/etc/systemd/system/{$name}.removed-by-mirror");
                    unset($state['systemd'][$name]);
                    $reload = true;
                }
            }
        }
        if ($apply && $reload) {
            shell_exec('systemctl daemon-reload 2>&1');
        }
        if ($apply) {
            // En un slave, lo copiado está siempre parado y deshabilitado.
            foreach (array_keys($state['systemd']) as $name) {
                shell_exec('systemctl disable --now ' . escapeshellarg($name) . ' >/dev/null 2>&1');
            }
        }
    }

    // ── Relevo: encender / apagar lo copiado ─────────────────────────────

    /** Al PROMOVER: enciende lo copiado del master (antes de los scripts promote.d). */
    public static function activate(): array
    {
        $state = self::state();
        $done = [];
        foreach ($state['supervisor'] as $name => $orig) {
            $path = "/etc/supervisor/conf.d/{$name}";
            if (!is_file($path)) {
                continue;
            }
            $content = (string)file_get_contents($path);
            foreach ((array)$orig as $prog => $autostart) {
                $content = preg_replace('/(\[program:' . preg_quote((string)$prog, '/') . '\]\s*\n)autostart=false/', '${1}autostart=' . ($autostart === 'false' ? 'false' : 'true'), $content);
            }
            file_put_contents($path, $content);
            $done[] = "supervisor/{$name}: autostart original";
        }
        if ($state['supervisor']) {
            shell_exec('supervisorctl reread >/dev/null 2>&1; supervisorctl update >/dev/null 2>&1');
            foreach ($state['supervisor'] as $orig) {
                foreach ((array)$orig as $prog => $autostart) {
                    if ($autostart !== 'false') {
                        shell_exec('supervisorctl start ' . escapeshellarg($prog . ':*') . ' >/dev/null 2>&1 || supervisorctl start ' . escapeshellarg((string)$prog) . ' >/dev/null 2>&1');
                    }
                }
            }
        }
        foreach (array_keys($state['cron_d']) as $name) {
            if (is_file("/etc/cron.d/{$name}.musedock-off") && !is_file("/etc/cron.d/{$name}")) {
                rename("/etc/cron.d/{$name}.musedock-off", "/etc/cron.d/{$name}");
                $done[] = "cron.d/{$name}: activado";
            }
        }
        foreach (array_keys($state['crontabs']) as $user) {
            [$outside, $inside] = self::splitBlock(self::readCrontab((string)$user));
            if (!$inside) {
                continue;
            }
            $on = array_map(static fn($l) => self::normCron($l), $inside);
            self::installCrontab((string)$user, rtrim(implode("\n", $outside)) . "\n" . self::BLOCK_BEGIN . "\n" . implode("\n", $on) . "\n" . self::BLOCK_END . "\n");
            $done[] = "crontab:{$user}: tareas copiadas activadas";
        }
        foreach ($state['systemd'] as $name => $enabledOnMaster) {
            if ($enabledOnMaster && is_file("/etc/systemd/system/{$name}")) {
                shell_exec('systemctl enable --now ' . escapeshellarg((string)$name) . ' >/dev/null 2>&1');
                $done[] = "systemd/{$name}: habilitado y arrancado";
            }
        }
        if (!empty($state['caddy_pending'])) {
            $n = count($done);
            self::activateCaddyfile($done, !empty($state['caddy_staged']));
            $failed = (bool)array_filter(array_slice($done, $n), static fn($l) => str_contains((string)$l, 'NO puesto'));
            // Si no se pudo poner, sigue pendiente (antes se daba por hecho y no se
            // reintentaba nunca): se reintenta con apply-master-caddyfile.
            $state['caddy_pending'] = $failed;
            self::saveState($state);
        }
        return $done;
    }

    /**
     * En un nodo que ya manda: poner ahora las webs del Caddyfile del master anterior
     * guardadas aparte (si al promover no se pusieron). Mismas comprobaciones que al
     * promover; si no valida, Caddy sigue como estaba.
     */
    public static function applyStagedCaddyfile(bool $apply = false): array
    {
        $done = [];
        if (!is_file(self::CADDY_STAGED)) {
            return ['ok' => false, 'done' => ['no hay webs del master guardadas aparte (' . self::CADDY_STAGED . ')']];
        }
        if (!$apply) {
            $myPort = (int)(Settings::get('panel_port', '8444') ?: 8444);
            $sites = self::caddySites((string)file_get_contents(self::CADDY_STAGED), $myPort);
            [$ok, $out, $missing] = self::caddyValidate(implode("\n\n", $sites) . "\n", $sites, false);
            return ['ok' => $ok, 'plan' => self::injectSites($sites, true),
                'validate' => $ok ? 'valida con el entorno real de Caddy' : 'NO valida: ' . trim(implode(' ', array_slice($out, -2))) . ($missing ? ' (falta ' . implode(', ', $missing) . ')' : ''),
                'staged_at' => date('Y-m-d H:i', (int)filemtime(self::CADDY_STAGED)),
                'next' => 'Si es lo esperado: apply-master-caddyfile --apply'];
        }
        self::activateCaddyfile($done, true);
        $failed = (bool)array_filter($done, static fn($l) => str_contains((string)$l, 'NO puesto'));
        $state = self::state();
        $state['caddy_pending'] = $failed;
        self::saveState($state);
        return ['ok' => !$failed && $done !== [], 'done' => $done];
    }

    /** Al DEGRADAR o AISLAR: apaga lo copiado (antes de los scripts demote.d). */
    public static function deactivate(): array
    {
        $state = self::state();
        $done = [];
        try {
            $cf = self::deactivateCaddyfile();
            if ($cf) {
                $done[] = $cf;
            }
        } catch (\Throwable $e) {
            $done[] = 'Caddyfile: ' . $e->getMessage();
        }
        foreach ($state['supervisor'] as $name => $orig) {
            $path = "/etc/supervisor/conf.d/{$name}";
            if (!is_file($path)) {
                continue;
            }
            foreach (array_keys((array)$orig) as $prog) {
                shell_exec('supervisorctl stop ' . escapeshellarg($prog . ':*') . ' >/dev/null 2>&1 || supervisorctl stop ' . escapeshellarg((string)$prog) . ' >/dev/null 2>&1');
            }
            [$off] = self::supervisorOff((string)file_get_contents($path));
            file_put_contents($path, $off);
            $done[] = "supervisor/{$name}: parado, autostart=false";
        }
        if ($state['supervisor']) {
            shell_exec('supervisorctl reread >/dev/null 2>&1; supervisorctl update >/dev/null 2>&1');
        }
        foreach (array_keys($state['cron_d']) as $name) {
            if (is_file("/etc/cron.d/{$name}") && !is_file("/etc/cron.d/{$name}.musedock-off")) {
                rename("/etc/cron.d/{$name}", "/etc/cron.d/{$name}.musedock-off");
                $done[] = "cron.d/{$name}: desactivado";
            }
        }
        foreach (array_keys($state['systemd']) as $name) {
            shell_exec('systemctl disable --now ' . escapeshellarg((string)$name) . ' >/dev/null 2>&1');
            $done[] = "systemd/{$name}: parado y deshabilitado";
        }
        foreach (array_keys($state['crontabs']) as $user) {
            [$outside, $inside] = self::splitBlock(self::readCrontab((string)$user));
            if (!$inside) {
                continue;
            }
            $off = array_map(static fn($l) => self::OFF . ' ' . self::normCron($l), $inside);
            self::installCrontab((string)$user, rtrim(implode("\n", $outside)) . "\n" . self::BLOCK_BEGIN . "\n" . implode("\n", $off) . "\n" . self::BLOCK_END . "\n");
            $done[] = "crontab:{$user}: tareas copiadas desactivadas";
        }
        return $done;
    }

    private static function userExists(string $user): bool
    {
        return function_exists('posix_getpwnam') ? (bool)posix_getpwnam($user) : trim((string)shell_exec('id -u ' . escapeshellarg($user) . ' 2>/dev/null')) !== '';
    }
}
