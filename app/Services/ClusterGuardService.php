<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Vigilancia de un nodo del cluster tras (y antes de) un relevo. Nace del relevo real del
 * 2026-10-18, que dejó a la vista fallos que ningún aviso recogía:
 *
 *  - un slave vigilando SU PROPIA IP como si fuera el master (cluster_master_ip no se
 *    actualizaba tras el demote, y los latidos por VPN no la sustituían);
 *  - Dovecot fallido en un slave durante horas, sin que nadie lo reintentara ni avisara;
 *  - PostgreSQL caído en un nodo por parámetros menores que los del primario;
 *  - lsyncd sin instalar en el nodo que pasó a mandar (nunca copió nada a nadie);
 *  - un nodo registrado por un nombre de DNS que el propio relevo movió a otro servidor.
 *
 * Todo es idempotente y se llama desde el cluster-worker (cada minuto o más espaciado).
 * Nada de lo que hace aquí borra datos.
 */
class ClusterGuardService
{
    private const SERVICE_STATE = 'service_guard_state';

    // ─────────────────────────────────────────────────────────
    // Utilidades
    // ─────────────────────────────────────────────────────────

    /** IPs de este servidor (todas las interfaces, también la de la VPN). */
    public static function localIps(): array
    {
        $ips = array_values(array_filter(preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) ?: [], static fn($i) => filter_var($i, FILTER_VALIDATE_IP) !== false));
        foreach (preg_split('/\R/', trim((string)shell_exec('ip -o addr show 2>/dev/null'))) ?: [] as $l) {
            if (preg_match('#\binet6?\s+([0-9a-f:.]+)/\d+#i', $l, $m) && filter_var($m[1], FILTER_VALIDATE_IP) && !in_array($m[1], $ips, true)) {
                $ips[] = $m[1];
            }
        }
        return $ips;
    }

    /** ¿Este nombre o IP es este mismo servidor? (los nombres se resuelven; el DNS puede haberlos movido) */
    public static function hostIsSelf(string $host, ?array $localIps = null): bool
    {
        if ($host === '') {
            return false;
        }
        $localIps ??= self::localIps();
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return in_array($host, $localIps, true);
        }
        $r = gethostbyname($host);
        return $r !== $host && in_array($r, $localIps, true);
    }

    private static function role(): string
    {
        return Settings::get('cluster_role', '') ?: \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
    }

    private static function fenced(): bool
    {
        return is_file(FailoverSafetyService::FENCE_FLAG) || Settings::get('cluster_fenced', '0') === '1';
    }

    // ─────────────────────────────────────────────────────────
    // 1. La IP del master que vigila un slave no puede ser la suya
    // ─────────────────────────────────────────────────────────

    /**
     * Un slave cuyo cluster_master_ip es una IP de ESTE servidor se vigila a sí mismo: su
     * «el master responde» es mentira y no notaría que el master real cae (pasó en mortadelo
     * tras el relevo del 2026-10-18). Se sustituye por la del master real. Devuelve el texto
     * de lo hecho o ''.
     */
    public static function ensureMasterIpNotSelf(): string
    {
        if (self::role() !== 'slave') {
            return '';
        }
        $current = trim((string)Settings::get('cluster_master_ip', ''));
        $mine = self::localIps();
        if ($current === '' || !in_array($current, $mine, true)) {
            return '';
        }
        $candidate = '';
        foreach (['cluster_master_heartbeat_ip', 'repl_remote_ip'] as $key) {
            $v = trim((string)Settings::get($key, ''));
            if (filter_var($v, FILTER_VALIDATE_IP) && !in_array($v, $mine, true)) {
                $candidate = $v;
                break;
            }
        }
        if ($candidate === '') {
            foreach (ClusterService::getNodes() as $n) {
                $h = (string)(parse_url((string)($n['api_url'] ?? ''), PHP_URL_HOST) ?: '');
                if (($n['role'] ?? '') === 'master' && filter_var($h, FILTER_VALIDATE_IP) && !in_array($h, $mine, true)) {
                    $candidate = $h;
                    break;
                }
            }
        }
        if ($candidate === '') {
            return "cluster_master_ip ({$current}) es una IP de este mismo servidor y no se encuentra al master real: el slave no vigila a nadie";
        }
        Settings::set('cluster_master_ip', $candidate);
        Settings::set('cluster_master_down_alerted', '');
        Settings::set('cluster_master_down_alert_count', '0');
        LogService::log('cluster.guard', 'master-ip', "cluster_master_ip era una IP de este servidor ({$current}); ahora {$candidate}");
        return "cluster_master_ip apuntaba a este mismo servidor ({$current}); ahora vigila al master {$candidate}";
    }

    // ─────────────────────────────────────────────────────────
    // 1b. Orden de arranque: los servicios que escuchan en la IP de la VPN, después de la VPN
    // ─────────────────────────────────────────────────────────

    /**
     * Dovecot escucha en la IP de WireGuard (doveadm/réplica) y PostgreSQL también (réplica). Tras el
     * reinicio del 2026-10-18 Dovecot arrancó tras network-online pero UN SEGUNDO ANTES de que
     * WireGuard creara la interfaz (12:10:02 frente a 12:10:03), no pudo escuchar en 10.10.70.x y
     * se quedó fallido horas. PostgreSQL en esa situación arranca, pero sin escuchar en la VPN
     * hasta que alguien lo reinicia (la réplica no podría conectar).
     */
    public static function wireguardDropinContent(string $wgUnit, bool $restart): string
    {
        $body = "# MuseDock: este servicio escucha en la IP de la VPN (WireGuard). Si arranca antes que ella no puede\n"
              . "# escuchar («Cannot assign requested address») y se queda caído (relevo 2026-10-18).\n"
              . "[Unit]\nAfter={$wgUnit}\nWants={$wgUnit}\n";
        if ($restart) {
            $body .= "\n[Service]\nRestart=on-failure\nRestartSec=15\n";
        }
        return $body;
    }

    /** Pone (idempotente) esos drop-ins de systemd. No reinicia nada: valen desde el próximo arranque. */
    public static function ensureWireguardOrdering(): string
    {
        $wants = glob('/etc/systemd/system/*.target.wants/wg-quick@*.service') ?: [];
        if (!$wants) {
            return '';
        }
        $wg = basename($wants[0]);
        $targets = [
            // unidad que debe existir en el sistema => [carpeta del drop-in, reintentar si falla]
            '/lib/systemd/system/dovecot.service'      => ['/etc/systemd/system/dovecot.service.d', true],
            '/lib/systemd/system/postgresql@.service'  => ['/etc/systemd/system/postgresql@.service.d', false],
            '/lib/systemd/system/postfix@.service'     => ['/etc/systemd/system/postfix@.service.d', false],
        ];
        $changed = [];
        foreach ($targets as $unitFile => [$dir, $restart]) {
            if (!is_file($unitFile)) {
                continue;
            }
            $want = self::wireguardDropinContent($wg, $restart);
            $file = $dir . '/zz-musedock-after-wg.conf';
            if (is_file($file) && (string)@file_get_contents($file) === $want) {
                continue;
            }
            @mkdir($dir, 0755, true);
            if (@file_put_contents($file, $want) !== false) {
                @chmod($file, 0644);
                $changed[] = basename($dir, '.d');
            }
        }
        if (!$changed) {
            return '';
        }
        shell_exec('systemctl daemon-reload 2>&1');
        LogService::log('cluster.guard', 'wg-ordering', 'Arrancan después de ' . $wg . ': ' . implode(', ', $changed));
        return 'arrancan ahora después de ' . $wg . ': ' . implode(', ', $changed) . ' (vale desde el próximo arranque)';
    }

    // ─────────────────────────────────────────────────────────
    // 1c. Guardián de arranque (bin/boot-guard.php): anti split-brain ANTES de que arranque nada
    // ─────────────────────────────────────────────────────────

    /**
     * Texto de la unidad systemd del guardián. Corre tras la red, WireGuard y la BD del panel (necesita
     * leer ajustes y preguntar a los otros nodos) y ANTES de las bases de datos de datos, Caddy y el
     * correo. Nunca bloquea el arranque: ExecStart con «-» y tiempo máximo de 120 s.
     * Pura (se prueba sin tocar systemd).
     */
    public static function bootGuardUnitContent(string $phpScript, string $panelPgUnit, array $beforeUnits, string $wgUnit = 'wg-quick@wg0.service'): string
    {
        // systemd exige el sufijo en las instancias de plantilla (postgresql@14-main → postgresql@14-main.service)
        $suffix = static fn(string $u): string => ($u !== '' && !preg_match('/\.(service|target|socket|mount|path|timer)$/', $u)) ? $u . '.service' : $u;
        $after = trim('network-online.target ' . ($wgUnit !== '' ? $wgUnit : '') . ' ' . $suffix($panelPgUnit));
        $before = implode(' ', array_values(array_unique(array_filter(array_map($suffix, $beforeUnits)))));
        return "# MuseDock: guardián de arranque. Si este nodo vuelve creyéndose master y otro se promovió después,\n"
             . "# se aparta ANTES de que arranquen sus bases de datos (si no, escribían unos minutos hasta que el\n"
             . "# cluster-worker lo aislaba: relevo del 2026-10-18). Falla siempre abierto: nunca impide arrancar.\n"
             . "[Unit]\nDescription=MuseDock: guardian de arranque (anti split-brain)\n"
             . "After={$after}\nWants=network-online.target" . ($wgUnit !== '' ? " {$wgUnit}" : '') . "\n"
             . ($before !== '' ? "Before={$before}\n" : '')
             . "\n[Service]\nType=oneshot\nRemainAfterExit=yes\nTimeoutStartSec=120\n"
             . "ExecStart=-/usr/bin/php {$phpScript}\n"
             . "\n[Install]\nWantedBy=multi-user.target\n";
    }

    /** Si el nodo ya no está apartado, fuera el solo-lectura que el guardián dejó en conf.d. */
    public static function ensureFencedConfConsistent(): string
    {
        if (self::fenced()) {
            return '';
        }
        $cleared = FailoverSafetyService::clearFencedReadOnlyConf();
        return $cleared ? 'solo-lectura de arranque quitado (el nodo ya no está apartado): ' . implode(', ', $cleared) : '';
    }

    // ─────────────────────────────────────────────────────────
    // 2. Servicios que deberían estar en marcha
    // ─────────────────────────────────────────────────────────

    /** Unidades que deben estar activas aquí: las de correo y Caddy que estén habilitadas. */
    public static function expectedUnits(): array
    {
        // unidad vigilada => [nombre en llano, unidad cuyo «enabled» decide si debe estar]
        // (postfix@-.service es una instancia generada: «enabled» es postfix.service).
        $candidates = [
            'postfix@-.service'   => ['Postfix (correo)', 'postfix.service'],
            'dovecot.service'     => ['Dovecot (buzones)', 'dovecot.service'],
            'opendkim.service'    => ['OpenDKIM (firma del correo)', 'opendkim.service'],
            'caddy.service'       => ['Caddy (webs)', 'caddy.service'],
        ];
        $out = [];
        foreach ($candidates as $unit => [$label, $probe]) {
            $enabled = trim((string)shell_exec('systemctl is-enabled ' . escapeshellarg($probe) . ' 2>/dev/null'));
            if ($enabled === 'enabled') {
                $out[$unit] = $label;
            }
        }
        return $out;
    }

    /**
     * Pura (probada sin tocar systemd): qué hacer con una unidad según su estado y cuánto
     * lleva así. Devuelve ['restart' => bool, 'alert' => bool, 'since' => int, 'tries' => int].
     */
    public static function serviceDecision(string $active, array $prev, int $now): array
    {
        if (in_array($active, ['active', 'activating', 'deactivating', 'reloading'], true)) {
            return ['restart' => false, 'alert' => false, 'since' => 0, 'tries' => 0, 'recovered' => !empty($prev['alerted']) && $active === 'active'];
        }
        $since = (int)($prev['since'] ?? 0) ?: $now;
        $tries = (int)($prev['tries'] ?? 0);
        $lastTry = (int)($prev['last_try'] ?? 0);
        $down = $now - $since;
        // Solo se reintenta lo que FALLÓ (crash); lo que alguien paró a propósito (inactive) no se arranca solo.
        $restart = $active === 'failed' && $down >= 120 && $tries < 3 && ($now - $lastTry) >= 300;
        $alertAfter = $active === 'failed' ? 600 : 900;
        $alert = $down >= $alertAfter && ($now - (int)($prev['alerted'] ?? 0)) >= 21600;
        return ['restart' => $restart, 'alert' => $alert, 'since' => $since, 'tries' => $tries, 'recovered' => false];
    }

    /** Cada pasada del worker: reintenta lo caído (si es seguro) y avisa si no se arregla. */
    public static function ensureServices(): array
    {
        $msgs = [];
        if (self::fenced() || Settings::get('cluster_self_standby', '0') === '1') {
            return $msgs;
        }
        $uptime = (float)@file_get_contents('/proc/uptime');
        if ($uptime > 0 && $uptime < 300) {
            return $msgs; // acaba de arrancar: las unidades aún se están levantando
        }
        $host = (string)gethostname();
        $state = json_decode((string)Settings::get(self::SERVICE_STATE, '{}'), true);
        $state = is_array($state) ? $state : [];
        $now = time();
        $before = json_encode($state);

        foreach (self::expectedUnits() as $unit => $label) {
            $active = trim((string)shell_exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null'));
            $prev = $state[$unit] ?? [];
            $d = self::serviceDecision($active, $prev, $now);

            if ($active === 'active') {
                if (!empty($d['recovered'])) {
                    NotificationService::send("Recuperado: {$label} en {$host}", "{$label} vuelve a estar en marcha en {$host}.", 'service_down');
                    $msgs[] = "{$unit} recuperado";
                }
                unset($state[$unit]);
                continue;
            }
            if (in_array($active, ['activating', 'deactivating', 'reloading'], true)) {
                continue;
            }
            $st = ['since' => $d['since'], 'tries' => $d['tries'], 'last_try' => (int)($prev['last_try'] ?? 0), 'alerted' => (int)($prev['alerted'] ?? 0)];
            if ($d['restart']) {
                shell_exec('systemctl reset-failed ' . escapeshellarg($unit) . ' 2>&1');
                shell_exec('systemctl start ' . escapeshellarg($unit) . ' 2>&1');
                $st['tries']++;
                $st['last_try'] = $now;
                $after = trim((string)shell_exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null'));
                LogService::log('cluster.guard', 'service-restart', "{$unit} estaba {$active} desde hace " . ($now - $st['since']) . " s; arrancado de nuevo: {$after}");
                $msgs[] = "{$unit} estaba {$active}: se ha intentado arrancar ({$after}, intento {$st['tries']}/3)";
                if ($after === 'active') {
                    unset($state[$unit]);
                    continue;
                }
            }
            if ($d['alert']) {
                $why = trim((string)shell_exec('journalctl -u ' . escapeshellarg($unit) . ' -n 8 --no-pager -o cat 2>/dev/null'));
                NotificationService::send(
                    "Servicio caído: {$label} en {$host}",
                    "{$label} está '{$active}' en {$host} desde hace " . round(($now - $st['since']) / 60) . " min"
                    . ($st['tries'] ? " (el panel lo ha reintentado {$st['tries']} vez/veces)" : '') . ".\n\n"
                    . "Un servicio parado tras un relevo deja a este nodo sin hacer de copia: el correo o las webs no se replican.\n\n"
                    . ($why !== '' ? "Últimas líneas del servicio:\n{$why}\n\n" : '')
                    . "Mira: systemctl status {$unit}  ·  journalctl -u {$unit} -b",
                    'service_down'
                );
                $st['alerted'] = $now;
                $msgs[] = "{$unit}: aviso enviado ({$active})";
            }
            $state[$unit] = $st;
        }

        foreach (self::pgClustersDown() as $c) {
            $key = 'pg:' . $c['key'];
            $prev = $state[$key] ?? [];
            $since = (int)($prev['since'] ?? 0) ?: $now;
            $st = ['since' => $since, 'tries' => 0, 'last_try' => 0, 'alerted' => (int)($prev['alerted'] ?? 0)];
            if ($now - $since >= 120 && ($now - $st['alerted']) >= 21600) {
                $hint = $c['hint'] !== '' ? "\n\nMotivo probable según su log:\n{$c['hint']}" : '';
                NotificationService::send(
                    "PostgreSQL {$c['key']} parado en {$host}",
                    "El clúster PostgreSQL {$c['key']} lleva " . round(($now - $since) / 60) . " min parado en {$host}. "
                    . "El panel no lo arranca solo: tras un relevo podría ser una réplica que no pudo arrancar, y arrancarla mal la dejaría como segundo primario." . $hint
                    . "\n\nMira: pg_lsclusters  ·  tail /var/log/postgresql/postgresql-{$c['version']}-{$c['cluster']}.log",
                    'service_down'
                );
                $st['alerted'] = $now;
                $msgs[] = "PostgreSQL {$c['key']} parado: aviso enviado";
            }
            $state[$key] = $st;
        }
        // Los clústeres que ya funcionan salen del estado
        $downKeys = array_map(static fn($c) => 'pg:' . $c['key'], self::pgClustersDown());
        foreach (array_keys($state) as $k) {
            if (str_starts_with((string)$k, 'pg:') && !in_array($k, $downKeys, true)) {
                if (!empty($state[$k]['alerted'])) {
                    NotificationService::send('PostgreSQL ' . substr((string)$k, 3) . " vuelve a estar en marcha en {$host}", 'El clúster ya funciona.', 'service_down');
                }
                unset($state[$k]);
            }
        }

        // Servicios propios en bucle de reinicios: aviso (una vez cada 12 h por servicio), no se tocan.
        $loops = self::restartLoops();
        foreach ($loops as $unit => $n) {
            $key = 'loop:' . $unit;
            $lastAlert = (int)($state[$key]['alerted'] ?? 0);
            if ($now - $lastAlert >= 43200) {
                NotificationService::send("Servicio en bucle de reinicios: {$unit} en {$host}",
                    "{$unit} lleva {$n} reinicios seguidos en {$host} (systemd lo reintenta sin parar). Suele faltar un fichero que lanza (un script, una carpeta) en este nodo. "
                    . "Mira: systemctl status {$unit}  ·  journalctl -u {$unit} -n 20", 'service_down');
                $msgs[] = "{$unit}: en bucle de reinicios ({$n}), aviso enviado";
                $state[$key] = ['since' => $now, 'tries' => 0, 'last_try' => 0, 'alerted' => $now];
            } else {
                $state[$key] = $state[$key] ?? ['since' => $now, 'tries' => 0, 'last_try' => 0, 'alerted' => $lastAlert];
            }
        }
        foreach (array_keys($state) as $k) {
            if (str_starts_with((string)$k, 'loop:') && !isset($loops[substr((string)$k, 5)])) {
                unset($state[$k]);
            }
        }

        if (json_encode($state) !== $before) {
            Settings::set(self::SERVICE_STATE, json_encode($state));
        }
        return $msgs;
    }

    /** ¿Un servicio en bucle de reinicios? (systemd lo está reintentando y ya lleva muchos intentos). Pura. */
    public static function isRestartLoop(int $nRestarts, string $subState): bool
    {
        return $subState === 'auto-restart' && $nRestarts >= 20;
    }

    /**
     * Servicios propios (/etc/systemd/system/*.service, no enlaces) que se reinician sin parar. El caso real:
     * microcortes en Filemon, más de 4.500 intentos cada 5 s porque faltaba su script (relevo 2026-10-18).
     * Devuelve unidad => intentos. Solo avisa: arreglarlo (copiar el fichero, quitar el servicio) es del administrador.
     */
    public static function restartLoops(): array
    {
        $loops = [];
        foreach (glob('/etc/systemd/system/*.service') ?: [] as $f) {
            if (is_link($f)) {
                continue;
            }
            $unit = basename($f);
            $show = (string)shell_exec('systemctl show ' . escapeshellarg($unit) . ' -p NRestarts -p SubState 2>/dev/null');
            if (preg_match('/NRestarts=(\d+)/', $show, $a) && preg_match('/SubState=(\S+)/', $show, $b) && self::isRestartLoop((int)$a[1], $b[1])) {
                $loops[$unit] = (int)$a[1];
            }
        }
        return $loops;
    }

    /** Clústeres de PostgreSQL parados (pg_lsclusters), con la pista del log si fue por parámetros. */
    public static function pgClustersDown(): array
    {
        $down = [];
        foreach (PgClusterService::listClusters() as $c) {
            $unit = (string)($c['unit'] ?? '');
            if ($unit === '') {
                continue;
            }
            $enabled = trim((string)shell_exec('systemctl is-enabled ' . escapeshellarg($unit) . ' 2>/dev/null'));
            if ($enabled !== 'enabled' && $enabled !== 'enabled-runtime') {
                continue;
            }
            if (PgClusterService::isRunning($c)) {
                continue;
            }
            $log = '/var/log/postgresql/postgresql-' . $c['version'] . '-' . $c['cluster'] . '.log';
            $tail = (string)shell_exec('tail -n 40 ' . escapeshellarg($log) . ' 2>/dev/null');
            $hint = '';
            if ($need = ReplicationService::standbyParamsFromLog($tail)) {
                $parts = [];
                foreach ($need as $k => $v) {
                    $parts[] = "{$k} debe ser ≥ {$v} (el primario lo tiene así)";
                }
                $hint = 'parámetros menores que los del primario: ' . implode(', ', $parts);
            } elseif (preg_match('/(FATAL|PANIC):.*$/m', $tail, $m)) {
                $hint = trim($m[0]);
            }
            $down[] = $c + ['hint' => $hint];
        }
        return $down;
    }

    // ─────────────────────────────────────────────────────────
    // 3. Registro de nodos: nombres que el relevo mueve, duplicados
    // ─────────────────────────────────────────────────────────

    /** Un fallo de conexión a un nodo, más claro si su nombre ya apunta a este mismo servidor. */
    public static function explainNodeError(string $apiUrl, string $error): string
    {
        $host = (string)(parse_url($apiUrl, PHP_URL_HOST) ?: '');
        if ($host !== '' && !filter_var($host, FILTER_VALIDATE_IP) && self::hostIsSelf($host)) {
            return "el nombre {$host} apunta ahora a ESTE servidor (el relevo movió su registro): contacta a ese nodo por su IP de VPN. {$error}";
        }
        return $error;
    }

    /**
     * Nodos registrados que no se pueden contactar bien: los de nombre de DNS que ya apunta a
     * este servidor, y los que parecen duplicados de otro (misma primera palabra del nombre).
     * No borra ni cambia nada: lo decide el administrador.
     */
    public static function nodeRegistryIssues(): array
    {
        $issues = [];
        $mine = self::localIps();
        $nodes = ClusterService::getNodes();
        foreach ($nodes as $n) {
            $host = (string)(parse_url((string)($n['api_url'] ?? ''), PHP_URL_HOST) ?: '');
            if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (self::hostIsSelf($host, $mine)) {
                $first = strtolower(preg_replace('/[^a-z0-9].*$/i', '', (string)$n['name']));
                $dupOf = '';
                foreach ($nodes as $o) {
                    if ((int)$o['id'] !== (int)$n['id'] && $first !== '' && str_starts_with(strtolower((string)$o['name']), $first)) {
                        $dupOf = " Parece un duplicado de «{$o['name']}» (#{$o['id']}).";
                        break;
                    }
                }
                $issues[] = ['severity' => 'warn', 'key' => 'node-self-name-' . $n['id'],
                    'text' => "El nodo #{$n['id']} «{$n['name']}» se contacta por el nombre {$host}, que ahora apunta a este mismo servidor (el relevo movió ese nombre): este panel se llama a sí mismo y falla cada minuto.{$dupOf} Regístralo por su IP de VPN o quita el duplicado."];
            }
        }
        return $issues;
    }

    // ─────────────────────────────────────────────────────────
    // 4. ¿Está este nodo preparado para tomar el mando?
    // ─────────────────────────────────────────────────────────

    /** ¿Se usa la copia de ficheros en el cluster (aquí o en lo que mandó el master)? */
    public static function filesyncWanted(): bool
    {
        if (Settings::get('filesync_enabled', '0') === '1') {
            return true;
        }
        $snap = json_decode((string)Settings::get('filesync_snapshot', ''), true);
        return is_array($snap) && ($snap['filesync_enabled'] ?? '0') === '1';
    }

    /** Valores locales de los parámetros de réplica de cada clúster PostgreSQL (menos el del panel). */
    public static function pgParams(): array
    {
        $out = [];
        $panelPort = (int)\MuseDockPanel\Env::int('DB_PORT', 5432);
        $all = PgClusterService::listClusters();
        foreach ($all as $c) {
            if ($c['cluster'] === 'panel' || (count($all) > 1 && (int)$c['port'] === $panelPort)) {
                continue;
            }
            $vals = [];
            if (PgClusterService::isRunning($c)) {
                $in = "'" . implode("','", ReplicationService::STANDBY_MIN_PARAMS) . "'";
                $res = (string)shell_exec('runuser -u postgres -- psql -p ' . (int)$c['port'] . ' -XAtc ' . escapeshellarg("SELECT name||'='||setting FROM pg_settings WHERE name IN ({$in})") . ' 2>/dev/null');
                foreach (preg_split('/\R/', trim($res)) ?: [] as $l) {
                    if (preg_match('/^(\w+)=(\d+)$/', trim($l), $m)) {
                        $vals[$m[1]] = (int)$m[2];
                    }
                }
            }
            if (!$vals) {
                foreach (ReplicationService::STANDBY_MIN_PARAMS as $p) {
                    $v = ReplicationService::localEffectiveParam($c, $p);
                    if ($v !== null) {
                        $vals[$p] = $v;
                    }
                }
            }
            $out[$c['key']] = $vals;
        }
        return $out;
    }

    /** Pura: dado lo de este nodo y lo de otro, qué parámetros deja al menor sin poder seguir al mayor. */
    public static function pgParamMismatches(array $mine, array $theirs): array
    {
        $issues = [];
        foreach ($mine as $cluster => $vals) {
            if (!isset($theirs[$cluster])) {
                continue;
            }
            foreach (ReplicationService::STANDBY_MIN_PARAMS as $p) {
                if (!isset($vals[$p], $theirs[$cluster][$p]) || $vals[$p] === $theirs[$cluster][$p]) {
                    continue;
                }
                $issues[] = ['cluster' => $cluster, 'param' => $p, 'mine' => $vals[$p], 'theirs' => $theirs[$cluster][$p]];
            }
        }
        return $issues;
    }

    // ─────────────────────────────────────────────────────────
    // Correo del sistema (cron, root…): que no salga del servidor ni se acumule
    // ─────────────────────────────────────────────────────────

    /**
     * ¿El correo que genera el propio servidor (cron, root) sale con un remitente que Postfix trata como ajeno?
     * Caso 2026-10-18: myorigin = mail.musedock.com y mydestination = localhost → un cron fallando cada minuto
     * generaba usuario@mail.musedock.com, se mandaba por el relayhost a otro nodo, que lo rechazaba («Relay access
     * denied») y se acumulaban 1.305 mensajes reintentándose sin parar. Pura: recibe lo que dice Postfix.
     * @param array{origin:string,mydestination:string,virtual?:list<string>} $pf
     */
    public static function systemMailProblem(array $pf): ?string
    {
        $origin = strtolower(trim((string)($pf['origin'] ?? '')));
        if ($origin === '') {
            return null;
        }
        $local = preg_split('/[\s,]+/', strtolower((string)($pf['mydestination'] ?? ''))) ?: [];
        if (in_array($origin, $local, true) || in_array('$myhostname', $local, true) && $origin === strtolower((string)($pf['myhostname'] ?? ''))) {
            return null;
        }
        if (in_array($origin, array_map('strtolower', (array)($pf['virtual'] ?? [])), true)) {
            return null;
        }
        return "El correo del propio servidor (cron, root…) sale como usuario@{$origin}, pero Postfix no considera ese dominio local: lo manda fuera"
            . ((string)($pf['relayhost'] ?? '') !== '' ? ' por el relayhost' : '') . ' y, si el destino no lo acepta, se acumula en la cola y se reintenta sin parar. '
            . "Solución: que {$origin} figure en mydestination (entrega local) o dirigir root y los usuarios a un buzón real.";
    }

    /**
     * Tareas del panel (bin/*.php) que están a la vez en un crontab de usuario y en /etc/cron.d: la de usuario
     * suele fallar (sin permiso para el log) y cada fallo es un correo por minuto. Pura.
     * @param array<string,string> $userTabs usuario => texto del crontab
     * @param array<string,string> $cronD    fichero => texto
     * @return list<string> descripciones en llano
     */
    public static function duplicateCronJobs(array $userTabs, array $cronD): array
    {
        $scripts = static function (string $text): array {
            $out = [];
            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (preg_match('#/bin/([A-Za-z0-9_.-]+\.php)#', $line, $m)) {
                    $out[$m[1]] = true;
                }
            }
            return array_keys($out);
        };
        $inD = [];
        foreach ($cronD as $f => $t) {
            foreach ($scripts($t) as $sc) {
                $inD[$sc] = $f;
            }
        }
        $dups = [];
        foreach ($userTabs as $u => $t) {
            if ($u === 'root') {
                continue;
            }
            foreach ($scripts($t) as $sc) {
                if (isset($inD[$sc])) {
                    $dups[] = "{$sc} está en el crontab de «{$u}» y también en /etc/cron.d/{$inD[$sc]}: la del usuario suele fallar (sin permiso para su log) y cada fallo es un correo por minuto.";
                }
            }
        }
        return $dups;
    }

    /** Mensajes en la cola de Postfix (0 si no se puede saber). Mide con «postqueue -p» (sin root solo ve lo suyo). */
    public static function mailQueueSize(): int
    {
        $out = (string)@shell_exec('postqueue -p 2>/dev/null | tail -n 1');
        if (preg_match('/in (\d+) Requests?/', $out, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    /** Fallos de configuración del correo del sistema para readiness(). Solo lectura. */
    public static function systemMailIssues(): array
    {
        $issues = [];
        if (trim((string)@shell_exec('command -v postconf 2>/dev/null')) === '') {
            return $issues;
        }
        $pc = static fn(string $k): string => trim((string)@shell_exec('postconf -h ' . escapeshellarg($k) . ' 2>/dev/null'));
        // -x: el valor ya expandido (myorigin = $mydomain salía literal en el aviso: «usuario@$mydomain»)
        $origin = trim((string)@shell_exec('postconf -h -x myorigin 2>/dev/null'));
        if ($origin !== '' && $origin[0] === '/') {
            $origin = trim((string)strtok((string)@file_get_contents($origin), "\n"));
        }
        $problem = self::systemMailProblem([
            'origin' => $origin, 'myhostname' => $pc('myhostname'), 'mydestination' => $pc('mydestination'),
            'relayhost' => $pc('relayhost'),
            'virtual' => [],
        ]);
        if ($problem !== null) {
            // Qué haría el panel con ello (solo lectura): da el comando o explica por qué no se puede solo.
            try {
                $plan = SystemMailService::plan(SystemMailService::inspect());
                if ($plan['action'] === 'add') {
                    $problem .= ' El panel puede arreglarlo solo: «php bin/mail-system-local.php --apply» en este nodo (con copia de main.cf y marcha atrás), o ajuste mail_system_local_mode = auto.';
                } elseif ($plan['action'] === 'skip') {
                    $problem .= ' El panel no lo toca solo: ' . $plan['reason'];
                }
            } catch (\Throwable) {
            }
            $issues[] = ['severity' => 'warn', 'key' => 'system-mail-origin', 'text' => $problem, 'doc' => '/docs/system-mail'];
        }
        // Postfix con un mapa de consultas sin contraseña: rechaza TODO envío autenticado («Temporary lookup failure»)
        try {
            foreach (MailPolicyService::cfFilesWithEmptyPassword() as $f) {
                $issues[] = ['severity' => 'warn', 'key' => 'postfix-cf-empty-' . md5($f), 'doc' => '/docs/system-mail',
                    'text' => "Postfix: {$f} tiene la contraseña de la base de datos vacía. Mientras siga así, Postfix no puede consultar la base y rechaza el envío autenticado de TODO el servidor (programas de correo, webmail, apps) con «451 4.3.0 Temporary lookup failure». Copia la contraseña de otro fichero pgsql-*.cf del mismo usuario (o restablécela desde Correo), comprueba con «postmap -q» y recarga Postfix."];
            }
        } catch (\Throwable) {
        }
        $limit = max(10, (int)Settings::get('mail_queue_warn', '100'));
        $q = self::mailQueueSize();
        if ($q > $limit) {
            $issues[] = ['severity' => 'warn', 'key' => 'mail-queue-big', 'text' => "La cola de correo de Postfix tiene {$q} mensajes (aviso a partir de {$limit}). Mira «mailq | head»: si son avisos internos que rebotan, se reintentan sin parar y llenan el tráfico saliente.", 'doc' => '/docs/system-mail'];
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $tabs = [];
            foreach (glob('/var/spool/cron/crontabs/*') ?: [] as $f) {
                $tabs[basename($f)] = (string)@file_get_contents($f);
            }
            $d = [];
            foreach (glob('/etc/cron.d/*') ?: [] as $f) {
                $d[basename($f)] = (string)@file_get_contents($f);
            }
            foreach (self::duplicateCronJobs($tabs, $d) as $i => $text) {
                $issues[] = ['severity' => 'warn', 'key' => 'cron-dup-' . substr(md5($text), 0, 8), 'text' => $text, 'doc' => '/docs/system-mail'];
            }
        }
        return $issues;
    }

    /**
     * Lo que le falta a este nodo para tomar el mando de verdad. Cada punto: severity
     * (warn|info), key (estable, para no repetir avisos) y text (en llano).
     */
    public static function readiness(): array
    {
        $issues = [];
        $role = self::role();

        if ($role === 'slave') {
            $mip = trim((string)Settings::get('cluster_master_ip', ''));
            if ($mip === '') {
                $issues[] = ['severity' => 'warn', 'key' => 'no-master-ip', 'text' => 'Este slave no conoce la IP del master (cluster_master_ip): no puede detectar su caída.'];
            } elseif (in_array($mip, self::localIps(), true)) {
                $issues[] = ['severity' => 'warn', 'key' => 'master-ip-self', 'text' => "El master que vigila este slave ({$mip}) es una IP de este mismo servidor: nunca notaría que el master real cae."];
            }
        }

        try {
            foreach (self::systemMailIssues() as $i) {
                $issues[] = $i;
            }
        } catch (\Throwable) {
        }

        if (self::filesyncWanted()) {
            if (!FileSyncService::isLsyncdInstalled()) {
                $issues[] = ['severity' => 'warn', 'key' => 'lsyncd-missing', 'text' => 'lsyncd no está instalado: si este nodo toma el mando no podrá copiar ficheros a ningún sitio.'];
            }
            $key = (string)(Settings::get('filesync_ssh_key_path', '/root/.ssh/id_ed25519') ?: '/root/.ssh/id_ed25519');
            // (solo como root: sin permiso para entrar en /root, un usuario normal vería «falta» donde no falta)
            if ((function_exists('posix_geteuid') ? posix_geteuid() === 0 : true) && !is_file($key)) {
                $issues[] = ['severity' => 'warn', 'key' => 'filesync-ssh-key', 'text' => "Falta la clave SSH de la copia de ficheros ({$key}): sin ella, lsyncd no podrá entrar en el otro nodo."];
            }
            if ($role === 'master' && FileSyncService::getConfig()['sync_mode'] === 'lsyncd' && trim((string)shell_exec('systemctl is-active lsyncd 2>/dev/null')) !== 'active') {
                $issues[] = ['severity' => 'warn', 'key' => 'lsyncd-stopped-master', 'text' => 'Este nodo manda pero lsyncd no está en marcha: los ficheros nuevos no se copian a ningún otro nodo.'];
            }
        }

        // Parámetros de PostgreSQL: el menor de los dos no podría seguir al mayor tras un relevo
        try {
            $mine = self::pgParams();
            if ($mine) {
                $mineIps = self::localIps();
                foreach (ClusterService::getNodes() as $n) {
                    $h = (string)(parse_url((string)($n['api_url'] ?? ''), PHP_URL_HOST) ?: '');
                    if (($n['status'] ?? '') !== 'online' || !filter_var($h, FILTER_VALIDATE_IP) || in_array($h, $mineIps, true)) {
                        continue;
                    }
                    $r = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'pg-params', 'payload' => []]);
                    $theirs = $r['data']['result']['params'] ?? $r['data']['params'] ?? null;
                    if (!is_array($theirs)) {
                        continue; // un nodo con una versión anterior del panel: no se puede comparar
                    }
                    foreach (self::pgParamMismatches($mine, $theirs) as $m) {
                        $lower = $m['mine'] < $m['theirs'] ? 'este nodo' : "«{$n['name']}»";
                        $issues[] = ['severity' => 'warn', 'key' => "pg-{$n['id']}-{$m['cluster']}-{$m['param']}",
                            'text' => "PostgreSQL {$m['cluster']}: {$m['param']} vale {$m['mine']} aquí y {$m['theirs']} en «{$n['name']}». Si cambia el mando, {$lower} no podría seguir al otro. "
                                . 'El demote ya lo corrige al reincorporarlo, pero conviene igualarlo antes (necesita reinicio de PostgreSQL).'];
                    }
                }
            }
        } catch (\Throwable) {
        }

        foreach (self::nodeRegistryIssues() as $i) {
            $issues[] = $i;
        }

        // Portal de clientes: la licencia sigue al clúster, pero solo si el servidor de licencias conoce el clúster
        try {
            if (PortalService::installed() && !empty(LicenseService::getPortalStatus()['active'])
                && (string)Settings::get('portal_license_instance_registered', '') !== LicenseService::instanceId()) {
                $issues[] = ['severity' => 'warn', 'key' => 'portal-license-instance',
                    'text' => 'El servidor de licencias aún no conoce este clúster: si cambia el mando, el portal de clientes no podría renovar su licencia en el nuevo master («Server changed») y se pararía. Se registra solo en cuanto el nodo al que está ligada la renueve; ya: php bin/portal-license.php --renew (en el nodo ligado, como root).'];
            }
        } catch (\Throwable) {
        }

        // Servicios que llevan caídos un rato
        $state = json_decode((string)Settings::get(self::SERVICE_STATE, '{}'), true);
        foreach ((is_array($state) ? $state : []) as $unit => $st) {
            if (time() - (int)($st['since'] ?? time()) >= 300) {
                $issues[] = ['severity' => 'warn', 'key' => 'svc-' . $unit, 'text' => (str_starts_with((string)$unit, 'pg:') ? 'PostgreSQL ' . substr((string)$unit, 3) : $unit) . ' lleva caído ' . round((time() - (int)$st['since']) / 60) . ' min.'];
            }
        }

        if (!is_file('/etc/systemd/system/musedock-boot-guard.service')) {
            $issues[] = ['severity' => 'info', 'key' => 'boot-guard', 'text' => 'No está instalado el guardián de arranque: si este nodo vuelve tras un reinicio creyéndose master, sus bases de datos arrancan escribibles unos minutos antes de que el panel lo aísle. Instálalo con bin/install-boot-guard.sh (como root).'];
        }

        return ['ok' => !array_filter($issues, static fn($i) => $i['severity'] === 'warn'), 'role' => $role, 'issues' => $issues, 'checked_at' => date('c')];
    }

    /**
     * Guarda la última comprobación (para el dashboard) y avisa UNA vez por cada cosa nueva
     * que falte (y cuando todo vuelve a estar en orden). Cada 30 min desde el worker.
     */
    public static function checkAndNotify(): array
    {
        $r = self::readiness();
        Settings::set('ha_readiness_last', json_encode($r));
        $warn = array_values(array_filter($r['issues'], static fn($i) => $i['severity'] === 'warn'));
        $keys = array_map(static fn($i) => $i['key'], $warn);
        sort($keys);
        $hash = $keys ? md5(implode('|', $keys)) : '';
        $prev = (string)Settings::get('ha_readiness_hash', '');
        if ($hash !== $prev) {
            Settings::set('ha_readiness_hash', $hash);
            $host = (string)gethostname();
            if ($hash !== '') {
                $lines = array_map(static fn($i) => '• ' . $i['text'] . (!empty($i['doc']) ? ' [Guía en el panel: ' . $i['doc'] . ']' : ''), $warn);
                NotificationService::send("Nodo no preparado para tomar el mando: {$host}",
                    "Este servidor podría tomar el mando, pero no está completamente preparado:\n\n" . implode("\n", $lines)
                    . "\n\nSe avisa ahora, no durante un relevo. Detalle y estado: Dashboard.", 'ha_readiness');
            } elseif ($prev !== '') {
                NotificationService::send("Nodo preparado para tomar el mando: {$host}", 'Ya no falta nada de lo que se había avisado.', 'ha_readiness');
            }
        }
        return $r;
    }
}
