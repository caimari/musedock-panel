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
 *  - Caddy NO se recarga (un reload desde el Caddyfile quitaría las rutas del
 *    panel): se escribe el Caddyfile validado y se aplica al promover (restart,
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
            if ($issues && (time() - (int)Settings::get('cluster_config_mirror_alert_at', '0')) > 3600) {
                Settings::set('cluster_config_mirror_alert_at', (string)time());
                try {
                    NotificationService::send('Cluster: la copia de configuración del master tiene avisos',
                        "Algunos elementos no se han copiado a este slave porque no pasaron la verificación:\n- " . implode("\n- ", $issues));
                } catch (\Throwable) {
                }
            }
        }
        return ['ok' => true, 'applied' => $apply, 'actions' => $actions, 'issues' => $issues];
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

    private static function mirrorCaddyfile(string $masterFile, int $masterPanelPort, array &$state, bool $apply, array &$actions, array &$issues): void
    {
        $path = '/etc/caddy/Caddyfile';
        if (!is_file($path) || $masterFile === '') {
            return;
        }
        $mine = (string)file_get_contents($path);
        $myPort = (int)(Settings::get('panel_port', '8444') ?: 8444);
        $keep = [];
        foreach (self::caddyBlocks($mine) as $i => $b) {
            if (self::isPanelOrGlobal($b, $myPort, $i)) {
                $keep[] = $b['text'];
            }
        }
        $sites = [];
        foreach (self::caddyBlocks($masterFile) as $i => $b) {
            if (!self::isPanelOrGlobal($b, $masterPanelPort, $i)) {
                $sites[] = $b['text'];
            }
        }
        $candidate = implode("\n\n", array_merge($keep, $sites)) . "\n";
        if (self::normSup($candidate) === self::normSup($mine)) {
            $actions[] = ['what' => 'Caddyfile', 'result' => 'igual'];
            return;
        }
        // Logs de Caddy (`output file …`) donde caddy no puede escribir en el slave:
        // en el master la carpeta logs/ del hosting es de caddy, aquí la creó el panel
        // a nombre del hosting (lsyncd excluye logs/, así que nadie la iguala).
        // Para validar se apuntan a /tmp; al aplicar se dejan como en el master.
        $logFix = self::caddyLogPathsToFix($sites);
        $toValidate = $candidate;
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
        exec($validate, $out, $rc);
        @unlink($tmp);
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
        $mineSites = [];
        foreach (self::caddyBlocks($mine) as $i => $b) {
            if (!self::isPanelOrGlobal($b, $myPort, $i)) {
                $mineSites[] = $b['text'];
            }
        }
        [$was, $will] = [$norm(implode("\n", $mineSites)), $norm(implode("\n", $sites))];
        $actions[] = ['what' => 'Caddyfile', 'result' => 'actualizado (webs del master; se aplica al promover, sin recargar Caddy ahora)',
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
            self::backup($path);
            file_put_contents($path, $candidate);
            $state['caddy_pending'] = true;
        }
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
            shell_exec('systemctl restart caddy 2>&1'); // el reparador repone las rutas del panel
            $state['caddy_pending'] = false;
            self::saveState($state);
            $done[] = 'Caddy reiniciado con el Caddyfile copiado';
        }
        return $done;
    }

    /** Al DEGRADAR o AISLAR: apaga lo copiado (antes de los scripts demote.d). */
    public static function deactivate(): array
    {
        $state = self::state();
        $done = [];
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
