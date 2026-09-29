<?php
namespace MuseDockPanel\Mcp;

use MuseDockPanel\Database;

/**
 * Inventario para clonar un servidor (herramienta MCP `clone_inventory`).
 *
 * Objetivo: describir TODO lo que haría falta para montar un slave exacto, sobre
 * todo lo que el panel NO gestiona (apps propias, procesos Node/PHP, supervisor,
 * drop-ins de systemd, Redis, crons, referencias a las IPs de este servidor...).
 *
 * Solo lectura. Nunca devuelve valores de .env, contraseñas ni claves: de los .env
 * solo salen NOMBRES de variables y las IPs/hosts a los que apuntan; los crons se
 * enmascaran. Todo pasa además por McpTools::redact().
 */
final class McpInventory
{
    public const SECTIONS = ['sites', 'processes', 'services', 'apps', 'databases', 'cron', 'runtime', 'network', 'caddy'];

    /** Puertos que es normal ver abiertos al exterior en un nodo del panel. */
    private const EXPECTED_PUBLIC_PORTS = [22, 25, 80, 443, 465, 587, 993, 995, 143, 110, 8444, 51820, 4190];

    /** Comandos auxiliares que heredan sockets del proceso que los lanza (no son el dueño real). */
    private const HELPER_COMMS = ['ss', 'sh', 'timeout', 'bash'];

    private static array $procInfo = [];
    private static ?array $listen = null;
    private static ?array $managed = null;
    private static ?array $localIps = null;

    public static function build(string $section = 'all'): array
    {
        $section = strtolower(trim($section)) ?: 'all';
        if ($section !== 'all' && !in_array($section, self::SECTIONS, true)) {
            throw new \InvalidArgumentException('Sección no válida. Usa: all, ' . implode(', ', self::SECTIONS) . '.');
        }
        self::$procInfo = [];
        self::$listen = null;
        self::$managed = null;
        self::$localIps = null;

        $wanted = $section === 'all' ? self::SECTIONS : [$section];
        $data = [];
        foreach ($wanted as $s) {
            try {
                $data[$s] = match ($s) {
                    'sites'     => self::sites(),
                    'processes' => self::processes(),
                    'services'  => self::services(),
                    'apps'      => self::apps(),
                    'databases' => self::databases(),
                    'cron'      => self::cron(),
                    'runtime'   => self::runtime(),
                    'network'   => self::network(),
                    'caddy'     => self::caddy(),
                };
            } catch (\Throwable $e) {
                $data[$s] = ['error' => $e->getMessage()];
            }
        }

        $checklist = self::checklist($data);

        // En la vista completa, las listas largas se resumen para no pasar del límite de
        // salida (60 KB) y perder las últimas secciones; completas en su propia sección.
        if ($section === 'all' && isset($data['runtime']['apt_manual'])) {
            $data['runtime']['apt_manual'] = ['count' => count($data['runtime']['apt_manual']),
                'note' => 'Lista completa con section=runtime.'];
            foreach (($data['runtime']['php'] ?? []) as $v => $info) {
                $data['runtime']['php'][$v]['extensions'] = count($info['extensions'] ?? []) . ' extensiones (lista con section=runtime)';
            }
        }

        return [
            'host' => gethostname(),
            'section' => $section,
            'checklist' => $checklist,
            'note' => 'Solo lectura. "checklist" = lo que el asistente de slave tendría que resolver a mano o con un paso específico. '
                . 'Para comparar dos servidores, pide la misma sección en ambos. Secciones: ' . implode(', ', self::SECTIONS) . '.',
        ] + $data;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Utilidades
    // ─────────────────────────────────────────────────────────────────────

    private static function sh(string $cmd, int $timeout = 5): string
    {
        return trim((string)shell_exec('timeout ' . (int)$timeout . ' sh -c ' . escapeshellarg($cmd) . ' 2>/dev/null'));
    }

    private static function lines(string $s): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $s)), static fn($l) => $l !== ''));
    }

    /** Enmascara secretos típicos en líneas de comandos (crons, ExecStart...). */
    private static function mask(string $s): string
    {
        $s = preg_replace('#(//[^:/\s@]+:)[^@\s]+@#', '$1***@', $s);
        $s = preg_replace('/((?:pass(?:word)?|passwd|pwd|token|secret|api[_-]?key|auth)\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i', '$1***', $s);
        $s = preg_replace('/(--(?:pass(?:word)?|token|secret|api-key|auth)\s+)("[^"]*"|\'[^\']*\'|\S+)/i', '$1***', $s);
        $s = preg_replace('/(\s-p)(?!\s)\S+/', '$1***', $s);
        $s = preg_replace('/((?:PGPASSWORD|MYSQL_PWD|REDISCLI_AUTH)=)\S+/', '$1***', $s);
        return mb_substr($s, 0, 300);
    }

    /** Dominios que sí gestiona el panel (hostings + alias). */
    private static function managed(): array
    {
        if (self::$managed !== null) {
            return self::$managed;
        }
        $m = [];
        try {
            foreach (Database::fetchAll("SELECT domain FROM hosting_accounts") as $r) {
                $d = strtolower((string)$r['domain']);
                $m[$d] = true;
                $m['www.' . $d] = true;
            }
            foreach (Database::fetchAll("SELECT domain FROM hosting_domain_aliases") as $r) {
                $m[strtolower((string)$r['domain'])] = true;
            }
        } catch (\Throwable) {
        }
        return self::$managed = $m;
    }

    /** IPv4 propias (públicas, privadas y de la VPN), sin loopback ni docker. */
    private static function localIps(): array
    {
        if (self::$localIps !== null) {
            return self::$localIps;
        }
        $ips = [];
        foreach (self::lines(self::sh('ip -4 -o addr show')) as $l) {
            if (preg_match('/^\d+:\s+(\S+)\s+inet\s+([\d.]+)\//', $l, $m)
                && $m[2] !== '127.0.0.1' && !preg_match('/^(docker|br-|veth|lxdbr)/', $m[1])) {
                $ips[$m[2]] = $m[1];
            }
        }
        return self::$localIps = $ips;
    }

    /** Datos de un proceso: nombre, comando, directorio, usuario y QUIÉN lo arranca. */
    private static function proc(int $pid): ?array
    {
        if (isset(self::$procInfo[$pid])) {
            return self::$procInfo[$pid];
        }
        if ($pid <= 0 || !is_dir("/proc/{$pid}")) {
            return null;
        }
        $cmd = trim(str_replace("\0", ' ', (string)@file_get_contents("/proc/{$pid}/cmdline")));
        $uid = null;
        if (preg_match('/^Uid:\s+(\d+)/m', (string)@file_get_contents("/proc/{$pid}/status"), $m)) {
            $uid = (int)$m[1];
        }
        $user = $uid !== null && function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? (string)$uid) : $uid;
        return self::$procInfo[$pid] = [
            'pid' => $pid,
            'comm' => trim((string)@file_get_contents("/proc/{$pid}/comm")),
            'cmd' => self::mask($cmd),
            'cwd' => @readlink("/proc/{$pid}/cwd") ?: null,
            'user' => $user,
            'launcher' => self::launcher($pid),
        ];
    }

    /**
     * Quién arranca el proceso (clave para clonarlo y para saber si sobrevive a un
     * reinicio): supervisor, PM2, una unidad systemd, cron, docker, o "manual" si
     * vive en una sesión de usuario (se arrancó a mano y muere al reiniciar).
     */
    private static function launcher(int $pid): array
    {
        // Variables que supervisor y PM2 inyectan a sus hijos (solo leemos esas).
        // Se sube por los padres: hay procesos (p. ej. el servidor swoole que lanza
        // `artisan octane:start`) que no heredan el entorno, pero su padre sí lo tiene.
        $p = $pid;
        for ($i = 0; $i < 8 && $p > 1; $i++) {
            $env = (string)@file_get_contents("/proc/{$p}/environ");
            $vars = [];
            foreach (explode("\0", $env) as $kv) {
                [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
                if (in_array($k, ['SUPERVISOR_PROCESS_NAME', 'SUPERVISOR_GROUP_NAME', 'pm_id', 'PM2_HOME', 'name'], true)) {
                    $vars[$k] = $v;
                }
            }
            $via = $p !== $pid ? ['via_parent_pid' => $p] : [];
            if (!empty($vars['SUPERVISOR_PROCESS_NAME'])) {
                return ['type' => 'supervisor', 'name' => $vars['SUPERVISOR_GROUP_NAME'] ?? $vars['SUPERVISOR_PROCESS_NAME']] + $via;
            }
            if (isset($vars['pm_id']) || isset($vars['PM2_HOME'])) {
                return ['type' => 'pm2', 'name' => $vars['name'] ?? null, 'pm2_home' => $vars['PM2_HOME'] ?? null] + $via;
            }
            $stat = (string)@file_get_contents("/proc/{$p}/stat");
            // Campo 4 de stat = ppid (el nombre del proceso va entre paréntesis y puede tener espacios).
            $after = substr($stat, (int)strrpos($stat, ')') + 2);
            $next = (int)(explode(' ', $after)[1] ?? 0);
            $comm = trim((string)@file_get_contents("/proc/{$next}/comm"));
            if ($next <= 1 || in_array($comm, ['supervisord', 'systemd', 'cron', 'sshd', 'containerd-shim'], true)) {
                break;
            }
            $p = $next;
        }
        $cg = (string)@file_get_contents("/proc/{$pid}/cgroup");
        if (preg_match('#/docker[-/]([0-9a-f]{12})#', $cg, $m)) {
            return ['type' => 'docker', 'name' => $m[1]];
        }
        if (preg_match_all('#/([^/\n]+\.service)#', $cg, $m) && $m[1]) {
            $unit = end($m[1]);
            if (str_starts_with($unit, 'user@')) {
                return ['type' => 'manual', 'name' => $unit, 'survives_reboot' => false];
            }
            if ($unit === 'cron.service') {
                return ['type' => 'cron', 'name' => $unit];
            }
            if ($unit === 'supervisor.service') {
                return ['type' => 'supervisor', 'name' => null];
            }
            return ['type' => 'systemd', 'name' => $unit];
        }
        if (preg_match('#/(session-[^/\n]+\.scope)#', $cg, $m)) {
            return ['type' => 'manual', 'name' => $m[1], 'survives_reboot' => false];
        }
        return ['type' => 'unknown', 'name' => null];
    }

    /** Puertos TCP en escucha con el proceso dueño real. */
    private static function listening(): array
    {
        if (self::$listen !== null) {
            return self::$listen;
        }
        $out = [];
        foreach (self::lines(self::sh('ss -ltnpH')) as $l) {
            $c = preg_split('/\s+/', $l);
            $local = $c[3] ?? '';
            if (!preg_match('/^(.*):(\d+)$/', $local, $am)) {
                continue;
            }
            $addr = trim($am[1], '[]');
            $addr = preg_replace('/%.*$/', '', $addr);
            // El hijo de este mismo proceso (ss/sh/timeout) hereda los sockets del
            // panel; el dueño real es el primer proceso que no sea un auxiliar.
            $owner = null;
            preg_match_all('/\("([^"]+)",pid=(\d+)/', $l, $pm, PREG_SET_ORDER);
            foreach ($pm as $p) {
                if (!in_array($p[1], self::HELPER_COMMS, true)) {
                    $owner = (int)$p[2];
                    break;
                }
            }
            $info = $owner ? self::proc($owner) : null;
            $out[] = [
                'addr' => $addr,
                'port' => (int)$am[2],
                'public_bind' => in_array($addr, ['0.0.0.0', '*', '::', ''], true)
                    || (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false),
                'pid' => $owner,
                'process' => $info['comm'] ?? ($pm[0][1] ?? '(desconocido)'),
                'launcher' => $info['launcher'] ?? null,
            ];
        }
        // Una fila por dirección:puerto; los comodines IPv4 e IPv6 del mismo puerto
        // (0.0.0.0 y ::) son la misma escucha a efectos prácticos.
        $uniq = [];
        foreach ($out as $r) {
            $wild = in_array($r['addr'], ['0.0.0.0', '*', '::', ''], true);
            $key = ($wild ? '*' : $r['addr']) . '|' . $r['port'];
            if ($wild) {
                $r['addr'] = '*';
            }
            if (!isset($uniq[$key]) || $uniq[$key]['pid'] === null) {
                $uniq[$key] = $r;
            }
        }
        usort($uniq, static fn($a, $b) => [$a['port'], $a['addr']] <=> [$b['port'], $b['addr']]);
        return self::$listen = array_values($uniq);
    }

    /** Estado del firewall de INPUT: política y puertos abiertos a cualquiera. */
    private static function firewall(): array
    {
        $rules = self::lines(self::sh('iptables -S INPUT'));
        $policy = 'unknown';
        $openAll = [];
        $openFrom = [];
        foreach ($rules as $r) {
            if (preg_match('/^-P INPUT (\S+)/', $r, $m)) {
                $policy = $m[1];
                continue;
            }
            if (!str_contains($r, '-j ACCEPT') || !preg_match('/--dports? (\S+)/', $r, $m)) {
                continue;
            }
            foreach (explode(',', $m[1]) as $p) {
                if (preg_match('/-s (\S+)/', $r, $sm)) {
                    $openFrom[$p][] = $sm[1];
                } else {
                    $openAll[$p] = true;
                }
            }
        }
        return ['input_policy' => $policy, 'open_to_all' => array_keys($openAll), 'open_to_sources' => $openFrom];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Secciones
    // ─────────────────────────────────────────────────────────────────────

    /** Sitios del Caddyfile (con su root / reverse_proxy) y hosts en ejecución fuera del panel. */
    private static function sites(): array
    {
        $managed = self::managed();
        $sites = [];
        $cur = null;
        $depth = 0;
        foreach (@file('/etc/caddy/Caddyfile', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#')) {
                continue;
            }
            if ($depth === 0 && str_ends_with($t, '{') && !str_starts_with($t, '{') && !str_starts_with($t, '(')) {
                $labels = array_values(array_filter(array_map('trim', explode(',', rtrim(substr($t, 0, -1))))));
                $hosts = array_map(static fn($l) => strtolower(preg_replace('#^https?://#', '', preg_replace('/:\d+$/', '', $l))), $labels);
                $isPanel = (bool)preg_grep('/:8444$/', $labels);
                $sites[] = [
                    'labels' => $labels,
                    'kind' => $isPanel ? 'panel' : (count(array_filter($hosts, static fn($h) => isset($managed[$h]))) ? 'panel_hosting' : 'not_managed'),
                    'root' => null, 'reverse_proxy' => [], 'php_fastcgi' => null,
                ];
                $cur = array_key_last($sites);
            } elseif ($cur !== null && $depth >= 1) {
                if (preg_match('/^root\s+(?:\*\s+)?(\S+)/', $t, $m)) {
                    $sites[$cur]['root'] = $m[1];
                } elseif (preg_match('/^reverse_proxy\s+(?:\S*\/\S*\s+)?(.+?)\s*\{?$/', $t, $m)) {
                    foreach (preg_split('/\s+/', $m[1]) as $up) {
                        if (preg_match('/^(unix\/|\S*:\d+$|localhost|\d)/', $up)) {
                            $sites[$cur]['reverse_proxy'][] = $up;
                        }
                    }
                } elseif (preg_match('/^php_fastcgi\s+(\S+)/', $t, $m)) {
                    $sites[$cur]['php_fastcgi'] = $m[1];
                }
            }
            $depth = max(0, $depth + substr_count($t, '{') - substr_count($t, '}'));
            if ($depth === 0) {
                $cur = null;
            }
        }

        $runtimeUnmanaged = [];
        foreach (self::caddyServers() as $srv) {
            foreach (($srv['routes'] ?? []) as $route) {
                foreach (($route['match'] ?? []) as $m) {
                    foreach (($m['host'] ?? []) as $h) {
                        $hl = strtolower($h);
                        if (!isset($managed[$hl]) && !filter_var($hl, FILTER_VALIDATE_IP) && $hl !== 'localhost') {
                            $runtimeUnmanaged[$hl] = true;
                        }
                    }
                }
            }
        }
        ksort($runtimeUnmanaged);

        return [
            'panel_hostings' => count(array_unique(array_map(static fn($d) => preg_replace('/^www\./', '', $d), array_keys($managed)))),
            'caddyfile_sites' => array_values(array_filter($sites, static fn($s) => $s['kind'] !== 'panel')),
            'caddy_runtime_hosts_not_in_panel' => array_keys($runtimeUnmanaged),
        ];
    }

    private static function caddyServers(): array
    {
        $ctx = stream_context_create(['http' => ['timeout' => 4]]);
        $servers = json_decode((string)@file_get_contents('http://localhost:2019/config/apps/http/servers', false, $ctx), true);
        return is_array($servers) ? $servers : [];
    }

    /** Puertos en escucha + procesos de aplicación (Node, PHP fuera de FPM, Python...) con su lanzador. */
    private static function processes(): array
    {
        $apps = [];
        $pattern = '/(^|\/)(node|nodejs|bun|deno|pm2|python[0-9.]*|gunicorn|uvicorn|frankenphp|rr)$/';
        foreach (self::lines(self::sh('ps -eo pid=,comm=,args=')) as $l) {
            if (!preg_match('/^(\d+)\s+(\S+)\s+(.*)$/', $l, $m)) {
                continue;
            }
            [$pid, $comm, $args] = [(int)$m[1], $m[2], $m[3]];
            if (preg_match('/vscode-server|cursor-server|\.vscode|networkd-dispatcher|unattended-upgrade|fail2ban|certbot|landscape|mcp-stdio/', $args)) {
                continue;
            }
            $isPhpApp = preg_match('/^php/', $comm) && !str_contains($comm, 'fpm')
                && !str_contains($args, '/opt/musedock-panel') && preg_match('/artisan|-S |octane|horizon|reverb|websockets|queue:|schedule:work/', $args);
            if (!$isPhpApp && !preg_match($pattern, $comm)) {
                continue;
            }
            $info = self::proc($pid);
            if (!$info) {
                continue;
            }
            // Workers idénticos (Horizon, Octane...) en una sola fila con su recuento.
            $key = json_encode([$info['launcher'], $info['cmd'], $info['cwd'], $info['user']]);
            if (isset($apps[$key])) {
                $apps[$key]['count']++;
                $apps[$key]['pids'][] = $pid;
                continue;
            }
            $apps[$key] = ['count' => 1, 'pids' => [$pid]] + $info;
            unset($apps[$key]['pid']);
        }
        $apps = array_values($apps);
        $ports = self::listening();
        return [
            'app_processes' => $apps,
            'listening_ports' => $ports,
            'public_binds_unexpected' => array_values(array_map(
                static fn($p) => "{$p['addr']}:{$p['port']} ({$p['process']})",
                array_filter($ports, static fn($p) => $p['public_bind'] && !in_array($p['port'], self::EXPECTED_PUBLIC_PORTS, true))
            )),
        ];
    }

    /** systemd (unidades propias, drop-ins, servicios en marcha), supervisor. */
    private static function services(): array
    {
        $units = [];
        foreach (glob('/etc/systemd/system/*.service') ?: [] as $f) {
            if (is_link($f)) {
                continue;
            }
            $name = basename($f);
            $body = (string)@file_get_contents($f);
            preg_match('/^ExecStart=(.*)$/m', $body, $ex);
            preg_match('/^WorkingDirectory=(.*)$/m', $body, $wd);
            preg_match('/^User=(.*)$/m', $body, $us);
            $units[$name] = [
                'unit' => $name,
                'category' => str_starts_with($name, 'musedock-') ? 'panel' : (str_starts_with($name, 'snap.') ? 'snap' : 'custom'),
                'exec' => isset($ex[1]) ? self::mask(trim($ex[1])) : null,
                'workdir' => isset($wd[1]) ? trim($wd[1]) : null,
                'user' => isset($us[1]) ? trim($us[1]) : null,
            ];
        }
        // Estado de todas en UNA llamada (el panel es monohilo: nada de un systemctl por unidad).
        if ($units) {
            $raw = self::sh('systemctl show --no-pager --property=Id,ActiveState,UnitFileState '
                . implode(' ', array_map('escapeshellarg', array_keys($units))));
            foreach (preg_split('/\n\s*\n/', $raw) ?: [] as $block) {
                $p = [];
                foreach (explode("\n", trim($block)) as $kv) {
                    [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
                    $p[$k] = $v;
                }
                if (isset($p['Id'], $units[$p['Id']])) {
                    $units[$p['Id']]['active'] = $p['ActiveState'] ?? null;
                    $units[$p['Id']]['enabled'] = $p['UnitFileState'] ?? null;
                }
            }
        }

        // Drop-ins (p. ej. postgresql@16-main esperando a WireGuard): hay que clonarlos.
        $dropins = [];
        foreach (glob('/etc/systemd/system/*.d/*.conf') ?: [] as $f) {
            $body = (string)@file_get_contents($f);
            $directives = [];
            foreach (explode("\n", $body) as $l) {
                $l = trim($l);
                if ($l !== '' && !str_starts_with($l, '#') && !str_starts_with($l, '[')) {
                    $directives[] = self::mask($l);
                }
            }
            $dropins[] = ['unit' => basename(dirname($f), '.d'), 'file' => basename($f), 'directives' => array_slice($directives, 0, 12)];
        }

        // Supervisor: programas definidos + estado real.
        $supervisor = null;
        if (is_dir('/etc/supervisor')) {
            $programs = [];
            foreach (array_merge(glob('/etc/supervisor/conf.d/*.conf') ?: [], glob('/etc/supervisor/conf.d/*.ini') ?: []) as $f) {
                $ini = @parse_ini_file($f, true, INI_SCANNER_RAW) ?: [];
                foreach ($ini as $sec => $kv) {
                    if (!preg_match('/^program:(.+)$/', (string)$sec, $m) || !is_array($kv)) {
                        continue;
                    }
                    $envKeys = [];
                    if (!empty($kv['environment']) && preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)=/', (string)$kv['environment'], $em)) {
                        $envKeys = $em[1];
                    }
                    $programs[] = [
                        'program' => $m[1],
                        'file' => basename($f),
                        'command' => self::mask((string)($kv['command'] ?? '')),
                        'directory' => $kv['directory'] ?? null,
                        'user' => $kv['user'] ?? null,
                        'numprocs' => (int)($kv['numprocs'] ?? 1),
                        'autostart' => $kv['autostart'] ?? 'true',
                        'environment_keys' => $envKeys,
                    ];
                }
            }
            $status = [];
            foreach (self::lines(self::sh('supervisorctl status')) as $l) {
                if (preg_match('/^(\S+)\s+(\S+)/', $l, $m)) {
                    $status[$m[1]] = $m[2];
                }
            }
            $supervisor = [
                'active' => self::sh('systemctl is-active supervisor'),
                'programs' => $programs,
                'status' => $status,
            ];
        }

        $running = [];
        foreach (self::lines(self::sh('systemctl list-units --type=service --state=running --no-legend --plain')) as $l) {
            $running[] = preg_replace('/\.service$/', '', strtok($l, ' '));
        }
        $failed = [];
        foreach (self::lines(self::sh('systemctl list-units --type=service --state=failed --no-legend --plain')) as $l) {
            $failed[] = preg_replace('/\.service$/', '', strtok(ltrim($l, '● '), ' '));
        }

        return [
            'custom_units' => array_values(array_filter($units, static fn($u) => $u['category'] === 'custom')),
            'panel_units' => array_keys(array_filter($units, static fn($u) => $u['category'] === 'panel')),
            'dropins' => $dropins,
            'supervisor' => $supervisor,
            'running_services' => $running,
            'failed_services' => $failed,
        ];
    }

    /** Raíz del proyecto a partir de un directorio (sube hasta artisan/composer/package/.git/.env). */
    private static function projectRoot(string $dir): ?string
    {
        $dir = rtrim($dir, '/');
        if ($dir === '' || !is_dir($dir) || str_starts_with($dir, '/opt/musedock-panel')) {
            return null;
        }
        if (basename($dir) === 'public') {
            $dir = dirname($dir);
        }
        $d = $dir;
        for ($i = 0; $i < 4 && strlen($d) > 1; $i++) {
            foreach (['artisan', 'composer.json', 'package.json', 'pyproject.toml', 'requirements.txt', '.git', '.env'] as $marker) {
                if (file_exists("{$d}/{$marker}")) {
                    return $d;
                }
            }
            if (in_array($d, ['/var/www', '/var/www/vhosts', '/opt', '/home', '/srv', '/root'], true)) {
                break;
            }
            $d = dirname($d);
        }
        return $dir;
    }

    /** Aplicaciones propias: dónde están, qué son, git, .env (solo nombres) y dependencias. */
    private static function apps(): array
    {
        $candidates = [];
        $add = static function (?string $dir, string $source) use (&$candidates) {
            $root = $dir ? self::projectRoot($dir) : null;
            if ($root) {
                $candidates[$root][$source] = true;
            }
        };
        foreach ((self::sites()['caddyfile_sites'] ?? []) as $s) {
            if ($s['kind'] === 'not_managed') {
                $add($s['root'], 'caddyfile');
            }
        }
        foreach ((self::processes()['app_processes'] ?? []) as $p) {
            $add($p['cwd'], 'process');
        }
        $svc = self::services();
        foreach (($svc['supervisor']['programs'] ?? []) as $p) {
            $add($p['directory'], 'supervisor');
            if (preg_match('#(/\S+?)/artisan\b#', (string)$p['command'], $m)) {
                $add($m[1], 'supervisor');
            }
        }
        foreach ($svc['custom_units'] as $u) {
            $add($u['workdir'], 'systemd');
        }
        foreach ((self::cron()['entries'] ?? []) as $c) {
            if (preg_match('#(?:cd\s+|\s)(/(?:var/www|home|srv|opt)/\S+?)(?:/artisan\b|\s|&&|;|$)#', $c['line'], $m)) {
                $add($m[1], 'cron');
            }
        }

        $roots = array_keys($candidates);
        sort($roots);
        $localIps = self::localIps();
        $host = strtolower((string)gethostname());
        $apps = [];
        foreach ($roots as $root) {
            $parent = null;
            foreach ($roots as $other) {
                if ($other !== $root && str_starts_with($root, $other . '/')) {
                    $parent = $other;
                }
            }
            $type = file_exists("{$root}/artisan") ? 'laravel'
                : (file_exists("{$root}/package.json") ? 'node' : (file_exists("{$root}/composer.json") ? 'php'
                : (file_exists("{$root}/pyproject.toml") || file_exists("{$root}/requirements.txt") || is_dir("{$root}/.venv") ? 'python' : 'static')));
            $owner = @fileowner($root);
            $app = [
                'path' => $root,
                'parent_app' => $parent,
                'found_by' => array_keys($candidates[$root]),
                'type' => $type,
                'owner' => $owner !== false && function_exists('posix_getpwuid') ? (posix_getpwuid($owner)['name'] ?? $owner) : null,
            ];

            if (is_dir("{$root}/.git")) {
                $g = 'git -c safe.directory=' . escapeshellarg($root) . ' -C ' . escapeshellarg($root) . ' ';
                $remote = self::sh($g . 'remote get-url origin');
                $ignored = [];
                foreach (self::lines(self::sh($g . 'ls-files --others --ignored --exclude-standard --directory', 8)) as $p) {
                    // Hasta 2 niveles: "vendor", "public/build", "storage/app"...
                    $ignored[implode('/', array_slice(explode('/', rtrim($p, '/')), 0, 2))] = true;
                }
                $app['git'] = [
                    'remote' => preg_replace('#//[^@/]+@#', '//', $remote),
                    'branch' => self::sh($g . 'rev-parse --abbrev-ref HEAD'),
                    'commit' => self::sh($g . 'rev-parse --short HEAD'),
                    'uncommitted_changes' => count(self::lines(self::sh($g . 'status --porcelain', 8))),
                    // Lo ignorado por git (vendor, node_modules, public/build, storage, .env...)
                    // NO viene con un git clone: o se reconstruye o se copia con rsync.
                    'not_in_git' => array_slice(array_keys($ignored), 0, 40),
                ];
            } else {
                $app['git'] = null;
            }

            $envFiles = array_values(array_filter(
                array_map('basename', glob("{$root}/.env*") ?: []),
                static fn($f) => !preg_match('/\.(example|sample|dist)$/', $f)
            ));
            $app['env_files'] = $envFiles;
            if (in_array('.env', $envFiles, true)) {
                $app['env'] = self::envSummary("{$root}/.env", $localIps, $host);
            }

            if (is_file("{$root}/composer.json")) {
                $cj = json_decode((string)@file_get_contents("{$root}/composer.json"), true) ?: [];
                $app['composer'] = ['php' => $cj['require']['php'] ?? null, 'vendor_present' => is_dir("{$root}/vendor"),
                    'packages' => count($cj['require'] ?? [])];
            }
            if (is_file("{$root}/package.json")) {
                $pj = json_decode((string)@file_get_contents("{$root}/package.json"), true) ?: [];
                $app['package'] = ['name' => $pj['name'] ?? null, 'node_engine' => $pj['engines']['node'] ?? null,
                    'node_modules_present' => is_dir("{$root}/node_modules"),
                    'scripts' => array_slice(array_keys($pj['scripts'] ?? []), 0, 15),
                    'dependencies' => count($pj['dependencies'] ?? []) + count($pj['devDependencies'] ?? [])];
            }
            if ($type === 'laravel') {
                $app['laravel'] = [
                    'public_build' => is_dir("{$root}/public/build"),
                    'storage_link' => is_link("{$root}/public/storage"),
                ];
            }
            $apps[] = $app;
        }
        return ['count' => count($apps), 'apps' => $apps];
    }

    /**
     * Resumen de un .env SIN valores: nº de variables, si hay APP_KEY, y las variables
     * que dependen de ESTE servidor (apuntan a sus IPs, a localhost, a su hostname o a
     * rutas locales). Son las que hay que revisar al clonar.
     */
    private static function envSummary(string $file, array $localIps, string $host): array
    {
        $keys = 0;
        $appKey = false;
        $dependent = [];
        foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $l, $m)) {
                continue;
            }
            $keys++;
            [$k, $v] = [$m[1], trim($m[2], " \t\"'")];
            if ($k === 'APP_KEY') {
                $appKey = $v !== '';
                continue;
            }
            if ($v === '' || preg_match('/(PASS|SECRET|TOKEN|_KEY$|PRIVATE)/i', $k)) {
                continue;
            }
            $refs = [];
            if (preg_match_all('/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', $v, $im)) {
                foreach ($im[1] as $ip) {
                    $refs[] = isset($localIps[$ip]) ? "{$ip} (este servidor, {$localIps[$ip]})" : $ip;
                }
            }
            if (preg_match('/\blocalhost\b/i', $v)) {
                $refs[] = 'localhost';
            }
            if ($host !== '' && stripos($v, $host) !== false) {
                $refs[] = 'hostname del servidor';
            }
            if (preg_match('#^/(var|home|opt|srv|etc|tmp)/#', $v)) {
                $refs[] = 'ruta local';
            }
            if ($refs) {
                $dependent[] = ['key' => $k, 'refs' => array_values(array_unique($refs))];
            }
        }
        return ['variables' => $keys, 'app_key_set' => $appKey, 'server_dependent' => $dependent];
    }

    /** PostgreSQL (con deriva config↔realidad y reglas de pg_hba), MySQL/MariaDB y Redis. */
    private static function databases(): array
    {
        $ports = self::listening();
        $pg = [];
        foreach (self::lines(self::sh('pg_lsclusters --no-header')) as $l) {
            $c = preg_split('/\s+/', $l);
            if (count($c) < 4) {
                continue;
            }
            $port = (int)$c[2];
            $q = static fn(string $sql, int $t = 5) => self::sh('runuser -u postgres -- psql -p ' . $port . ' -XAtc ' . escapeshellarg($sql), $t);
            $cl = ['cluster' => "{$c[0]}/{$c[1]}", 'major' => (int)$c[0], 'port' => $port, 'status' => $c[3]];
            if ($c[3] === 'online') {
                $cl['databases'] = self::lines($q("SELECT datname FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY 1"));
                $cl['in_recovery'] = $q('SELECT pg_is_in_recovery()') === 't';
                $cl['settings'] = [];
                foreach (self::lines($q("SELECT name||'='||setting FROM pg_settings WHERE name IN ('listen_addresses','wal_level','max_wal_senders','wal_log_hints','hot_standby','archive_mode')")) as $kv) {
                    [$k, $v] = explode('=', $kv, 2);
                    $cl['settings'][$k] = $v;
                }
                // Deriva: direcciones configuradas en listen_addresses en las que NO escucha
                // (p. ej. arrancó antes que WireGuard y la IP de la VPN no existía).
                $configured = array_filter(array_map('trim', explode(',', $cl['settings']['listen_addresses'] ?? '')));
                $actual = array_map(static fn($p) => $p['addr'], array_filter($ports, static fn($p) => $p['port'] === $port));
                $cl['listen_actual'] = array_values(array_unique($actual));
                $missing = [];
                foreach ($configured as $a) {
                    if ($a === '*' || $a === '0.0.0.0') {
                        if (!array_intersect($actual, ['0.0.0.0', '*', '::'])) {
                            $missing[] = $a;
                        }
                    } elseif ($a !== 'localhost' && !in_array($a, $actual, true)) {
                        $missing[] = $a;
                    }
                }
                $cl['listen_missing'] = $missing;
                $cl['hba_remote_rules'] = self::lines($q("SELECT type||' '||array_to_string(database,',')||' '||array_to_string(user_name,',')||' '||coalesce(address,'')||coalesce('/'||netmask,'')||' '||auth_method FROM pg_hba_file_rules WHERE type <> 'local' AND coalesce(address,'') NOT IN ('127.0.0.1','::1') ORDER BY line_number"));
                $cl['replicas'] = self::lines($q("SELECT coalesce(client_addr::text,'local')||' '||state||' '||coalesce(sync_state,'') FROM pg_stat_replication"));
            }
            $pg[] = $cl;
        }

        $mysql = null;
        if (self::sh('command -v mysql') !== '') {
            $auth = is_readable('/etc/mysql/debian.cnf') ? '--defaults-file=/etc/mysql/debian.cnf ' : '';
            $mq = static fn(string $sql) => self::sh('mysql ' . $auth . '-N -B -e ' . escapeshellarg($sql));
            $vars = [];
            foreach (self::lines($mq("SELECT CONCAT_WS('=', 'version', @@version), CONCAT_WS('=', 'bind_address', @@bind_address), CONCAT_WS('=', 'server_id', @@server_id), CONCAT_WS('=', 'log_bin', @@log_bin), CONCAT_WS('=', 'read_only', @@read_only)")) as $row) {
                foreach (explode("\t", $row) as $kv) {
                    [$k, $v] = array_pad(explode('=', $kv, 2), 2, null);
                    $vars[$k] = $v;
                }
            }
            $dbs = self::lines($mq('SHOW DATABASES'));
            $mysql = [
                'reachable' => $vars !== [],
                'auth' => $auth !== '' ? 'debian.cnf' : 'root socket',
                'vars' => $vars,
                'databases' => array_values(array_diff($dbs, ['information_schema', 'performance_schema', 'mysql', 'sys'])),
            ];
        }

        $redis = null;
        if (self::sh('command -v redis-server') !== '') {
            $pass = '';
            foreach (@file('/etc/redis/redis.conf', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                if (preg_match('/^\s*requirepass\s+(\S+)/', $l, $m)) {
                    $pass = trim($m[1], '"\'');
                }
            }
            $cli = ($pass !== '' ? 'REDISCLI_AUTH=' . escapeshellarg($pass) . ' ' : '') . 'redis-cli --no-auth-warning ';
            $info = [];
            foreach (self::lines(self::sh($cli . 'INFO')) as $l) {
                if (str_contains($l, ':')) {
                    [$k, $v] = explode(':', $l, 2);
                    $info[$k] = $v;
                }
            }
            $conf = static fn(string $k) => self::lines(self::sh($cli . 'CONFIG GET ' . $k))[1] ?? null;
            $keyspace = [];
            foreach ($info as $k => $v) {
                if (preg_match('/^db\d+$/', $k) && preg_match('/keys=(\d+)/', $v, $km)) {
                    $keyspace[$k] = (int)$km[1];
                }
            }
            $redis = [
                'active' => self::sh('systemctl is-active redis-server'),
                'version' => $info['redis_version'] ?? null,
                'role' => $info['role'] ?? null,
                'connected_slaves' => isset($info['connected_slaves']) ? (int)$info['connected_slaves'] : null,
                'master_host' => $info['master_host'] ?? null,
                'password_set' => $pass !== '',
                'bind' => $conf('bind'),
                'dir' => $conf('dir'),
                'appendonly' => $conf('appendonly'),
                'maxmemory' => $conf('maxmemory'),
                'keys_per_db' => $keyspace,
            ];
        }

        return ['postgresql' => $pg, 'mysql' => $mysql, 'redis' => $redis];
    }

    /** Crons que no son del panel, CON su contenido (enmascarado). */
    private static function cron(): array
    {
        $skip = ['e2scrub_all', 'php', 'sysstat', 'certbot', '.placeholder', 'popularity-contest'];
        $entries = [];
        $files = [];
        foreach (glob('/etc/cron.d/*') ?: [] as $f) {
            $n = basename($f);
            if (str_starts_with($n, 'musedock') || in_array($n, $skip, true)) {
                continue;
            }
            $files[] = $f;
            foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                $l = trim($l);
                if ($l !== '' && !str_starts_with($l, '#') && !preg_match('/^[A-Z_]+=/', $l)) {
                    $entries[] = ['source' => $f, 'line' => self::mask($l)];
                }
            }
        }
        $users = [];
        foreach (glob('/var/spool/cron/crontabs/*') ?: [] as $f) {
            $u = basename($f);
            $users[] = $u;
            foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                $l = trim($l);
                if ($l !== '' && !str_starts_with($l, '#') && !preg_match('/^[A-Z_]+=/', $l)) {
                    $entries[] = ['source' => "crontab:{$u}", 'line' => self::mask($l)];
                }
            }
        }
        return [
            'files' => $files,
            'user_crontabs' => $users,
            'entries' => $entries,
            'laravel_scheduler' => array_values(array_map(static fn($e) => $e['source'],
                array_filter($entries, static fn($e) => str_contains($e['line'], 'schedule:run')))),
        ];
    }

    /** Versiones y paquetes: PHP (+extensiones por versión), Node, Composer, apt manual. */
    private static function runtime(): array
    {
        $php = [];
        foreach (glob('/usr/bin/php[0-9].[0-9]') ?: [] as $bin) {
            $v = substr(basename($bin), 3);
            $mods = array_values(array_unique(array_filter(
                array_map('strtolower', self::lines(self::sh(escapeshellarg($bin) . ' -m'))),
                static fn($m) => !str_starts_with($m, '[')
            )));
            $pools = array_map(static fn($f) => basename($f, '.conf'), glob("/etc/php/{$v}/fpm/pool.d/*.conf") ?: []);
            $php[$v] = [
                'fpm' => is_dir("/etc/php/{$v}/fpm"),
                'fpm_pools' => count($pools),
                'extensions' => $mods,
            ];
        }
        $node = self::sh('node -v');
        // Paquetes npm globales leyendo los directorios (npm ls -g tarda ~1 s).
        $npmGlobal = [];
        $npmVersion = null;
        foreach (['/usr/lib/node_modules', '/usr/local/lib/node_modules'] as $nm) {
            foreach (glob("{$nm}/*", GLOB_ONLYDIR) ?: [] as $p) {
                $n = basename($p);
                if (str_starts_with($n, '@')) {
                    foreach (glob("{$p}/*", GLOB_ONLYDIR) ?: [] as $sp) {
                        $npmGlobal[] = $n . '/' . basename($sp);
                    }
                    continue;
                }
                $npmGlobal[] = $n;
                if ($n === 'npm' && $npmVersion === null) {
                    $npmVersion = json_decode((string)@file_get_contents("{$p}/package.json"), true)['version'] ?? null;
                }
            }
        }
        $sources = array_map('basename', array_merge(glob('/etc/apt/sources.list.d/*.list') ?: [], glob('/etc/apt/sources.list.d/*.sources') ?: []));
        return [
            'os' => self::sh('. /etc/os-release && echo "$PRETTY_NAME"'),
            'kernel' => php_uname('r'),
            'php_default_cli' => self::sh('php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;"'),
            'php' => $php,
            'node' => $node ?: null,
            'npm' => $npmVersion,
            'npm_global' => array_values(array_unique($npmGlobal)),
            'pm2' => self::sh('command -v pm2 >/dev/null && pm2 -v') ?: null,
            'composer' => self::sh('composer --version --no-ansi 2>/dev/null | head -1') ?: null,
            'caddy' => self::sh('caddy version') ?: null,
            'apt_sources' => $sources,
            // Paquetes instalados a mano: la forma más fiable de comparar dos servidores.
            'apt_manual' => self::aptManual(),
        ];
    }

    /**
     * Equivalente a `apt-mark showmanual` (que tarda ~1 s): paquetes instalados
     * menos los marcados Auto-Installed en /var/lib/apt/extended_states.
     */
    private static function aptManual(): array
    {
        $auto = [];
        $pkg = null;
        foreach (@file('/var/lib/apt/extended_states', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (str_starts_with($l, 'Package: ')) {
                $pkg = trim(substr($l, 9));
            } elseif ($pkg !== null && trim($l) === 'Auto-Installed: 1') {
                $auto[$pkg] = true;
            }
        }
        $out = [];
        foreach (self::lines(self::sh("dpkg-query -W -f='\${db:Status-Abbrev} \${binary:Package}\\n'", 8)) as $l) {
            if (!preg_match('/^ii\s+(\S+)/', $l, $m)) {
                continue;
            }
            $name = preg_replace('/:(amd64|arm64|i386|all)$/', '', $m[1]);
            if (!isset($auto[$name]) && !isset($auto[$m[1]])) {
                $out[$name] = true;
            }
        }
        ksort($out);
        return array_keys($out);
    }

    /** Red: IPs, WireGuard (sin claves), firewall y ficheros de /etc que citan IPs de este servidor. */
    private static function network(): array
    {
        $ips = self::localIps();
        $wg = [];
        foreach (self::lines(self::sh('wg show interfaces')) as $ifs) {
            foreach (preg_split('/\s+/', $ifs) as $if) {
                $peers = [];
                foreach (self::lines(self::sh('wg show ' . escapeshellarg($if) . ' endpoints')) as $l) {
                    [$pk, $ep] = array_pad(preg_split('/\s+/', $l), 2, null);
                    $peers[$pk] = ['endpoint' => $ep];
                }
                foreach (self::lines(self::sh('wg show ' . escapeshellarg($if) . ' allowed-ips')) as $l) {
                    $parts = preg_split('/\s+/', $l);
                    $pk = array_shift($parts);
                    $peers[$pk]['allowed_ips'] = $parts;
                }
                foreach (self::lines(self::sh('wg show ' . escapeshellarg($if) . ' latest-handshakes')) as $l) {
                    [$pk, $ts] = array_pad(preg_split('/\s+/', $l), 2, 0);
                    $peers[$pk]['handshake_age_s'] = (int)$ts > 0 ? time() - (int)$ts : null;
                }
                $wg[$if] = [
                    'address' => array_keys(array_filter($ips, static fn($i) => $i === $if)),
                    'listen_port' => self::sh('wg show ' . escapeshellarg($if) . ' listen-port'),
                    // La clave pública no es secreta, pero se recorta: basta para identificar.
                    'peers' => array_map(null, array_map(static fn($k) => substr((string)$k, 0, 8) . '…', array_keys($peers)), array_values($peers)),
                ];
            }
        }

        // Ficheros de /etc que citan IPs de este servidor: al clonar hay que adaptarlos
        // (o mudar la IP flotante). Se excluye lo que no aporta (certificados, ssh...).
        $refs = [];
        if ($ips) {
            $pat = implode(' ', array_map(static fn($ip) => '-e ' . escapeshellarg($ip), array_keys($ips)));
            $files = self::lines(self::sh('grep -rlIFw ' . $pat . ' /etc --exclude-dir=ssl --exclude-dir=ssh --exclude-dir=alternatives --exclude-dir=fonts --exclude-dir=wireguard 2>/dev/null | head -80', 8));
            foreach ($files as $f) {
                $which = [];
                $body = (string)@file_get_contents($f, false, null, 0, 262144);
                foreach (array_keys($ips) as $ip) {
                    if (preg_match('/(?<![\d.])' . preg_quote($ip, '/') . '(?![\d])/', $body)) {
                        $which[] = $ip;
                    }
                }
                $refs[] = ['file' => $f, 'ips' => $which];
            }
        }

        $hosts = [];
        foreach (@file('/etc/hosts', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $l = trim($l);
            if ($l !== '' && !str_starts_with($l, '#') && !preg_match('/^(127\.0\.[01]\.1|::1|fe00::|ff0[0-2]::)/', $l)) {
                $hosts[] = $l;
            }
        }

        return [
            'hostname' => gethostname(),
            'ipv4' => $ips,
            'wireguard' => $wg,
            'firewall' => self::firewall(),
            'etc_hosts_custom' => $hosts,
            'etc_files_referencing_local_ips' => $refs,
        ];
    }

    /** Caddy: opciones globales, almacén de certificados y módulos no estándar. */
    private static function caddy(): array
    {
        $global = [];
        $cf = @file('/etc/caddy/Caddyfile', FILE_IGNORE_NEW_LINES) ?: [];
        $in = false;
        $depth = 0;
        foreach ($cf as $line) {
            $t = trim($line);
            if (!$in && $depth === 0 && $t === '{') {
                $in = true;
                $depth = 1;
                continue;
            }
            if ($in) {
                $depth += substr_count($t, '{') - substr_count($t, '}');
                if ($depth <= 0) {
                    break;
                }
                if ($t !== '' && !str_starts_with($t, '#')) {
                    $global[] = self::mask($t);
                }
            } elseif ($t !== '' && !str_starts_with($t, '#')) {
                break;
            }
        }
        $storage = null;
        foreach ($global as $g) {
            if (preg_match('/^storage\s+file_system\s+(?:\{?\s*root\s+)?(\S+)/', $g, $m)) {
                $storage = $m[1];
            }
        }
        if (!$storage) {
            foreach (['/var/lib/caddy/.local/share/caddy', '/root/.local/share/caddy'] as $d) {
                if (is_dir("{$d}/certificates")) {
                    $storage = $d;
                    break;
                }
            }
        }
        $certs = [];
        foreach (glob(($storage ?: '/nonexistent') . '/certificates/*/*', GLOB_ONLYDIR) ?: [] as $d) {
            $certs[] = basename(dirname($d)) . ':' . basename($d);
        }
        sort($certs);
        return [
            'global_options' => $global,
            'storage' => $storage,
            'certificates' => ['count' => count($certs), 'hosts' => array_slice($certs, 0, 200)],
            // Módulos compilados aparte (p. ej. dns.providers.cloudflare): el slave necesita el mismo binario.
            'non_standard_modules' => array_values(preg_grep('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', self::lines(self::sh('caddy list-modules --skip-standard')))),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Checklist en lenguaje llano
    // ─────────────────────────────────────────────────────────────────────

    private static function checklist(array $d): array
    {
        $c = [];
        foreach (($d['processes']['app_processes'] ?? []) as $p) {
            $pids = implode(',', array_slice($p['pids'] ?? [], 0, 5));
            if (($p['launcher']['type'] ?? '') === 'manual') {
                $c[] = "Proceso arrancado A MANO (pid {$pids}, {$p['comm']}, {$p['cwd']}): no lo arranca nadie al reiniciar; en el slave habría que ponerlo en supervisor/systemd.";
            } elseif (($p['launcher']['type'] ?? '') === 'unknown') {
                $c[] = "Proceso sin lanzador identificado (pid {$pids}, {$p['comm']}, {$p['cwd']}): revisar cómo se arranca.";
            }
        }
        $fw = null;
        foreach (($d['processes']['listening_ports'] ?? []) as $p) {
            if (!$p['public_bind'] || in_array($p['port'], self::EXPECTED_PUBLIC_PORTS, true)) {
                continue;
            }
            $fw ??= self::firewall();
            $open = in_array((string)$p['port'], $fw['open_to_all'], true) || $fw['input_policy'] === 'ACCEPT';
            $c[] = "{$p['process']} escucha en {$p['addr']}:{$p['port']} (todas las interfaces)" . match (true) {
                $fw['input_policy'] === 'unknown' => '; no se pudo leer el firewall para saber si está expuesto.',
                $open => ' y el firewall lo DEJA ABIERTO a internet.',
                default => '; el firewall lo bloquea desde fuera, pero conviene que escuche solo en 127.0.0.1 o en la VPN.',
            };
        }
        foreach (($d['services']['failed_services'] ?? []) as $u) {
            $c[] = "Servicio fallido: {$u}.";
        }
        foreach (($d['services']['custom_units'] ?? []) as $u) {
            $c[] = "Unidad systemd propia {$u['unit']} ({$u['active']}): clonar el fichero y habilitarla.";
        }
        if (!empty($d['services']['dropins'])) {
            $c[] = count($d['services']['dropins']) . ' drop-ins de systemd en /etc/systemd/system/*.d (revisar cuáles hay que copiar).';
        }
        foreach (($d['services']['supervisor']['programs'] ?? []) as $p) {
            $c[] = "Programa de supervisor '{$p['program']}' ({$p['file']}): copiar su .conf; decidir si arranca en el slave o solo al promover.";
        }
        foreach (($d['sites']['caddyfile_sites'] ?? []) as $s) {
            if ($s['kind'] === 'not_managed') {
                $c[] = 'Sitio del Caddyfile fuera del panel: ' . implode(', ', $s['labels']) . ' → copiar el bloque del Caddyfile.';
            }
        }
        foreach (($d['apps']['apps'] ?? []) as $a) {
            if (!empty($a['env']['server_dependent'])) {
                $c[] = "{$a['path']}/.env tiene " . count($a['env']['server_dependent']) . ' variables que apuntan a este servidor ('
                    . implode(', ', array_map(static fn($x) => $x['key'], $a['env']['server_dependent'])) . ').';
            }
            if (!empty($a['git']['uncommitted_changes'])) {
                $c[] = "{$a['path']}: {$a['git']['uncommitted_changes']} cambios sin commit (un git clone no los traería).";
            }
        }
        foreach (($d['databases']['postgresql'] ?? []) as $pg) {
            if (!empty($pg['listen_missing'])) {
                $c[] = "PostgreSQL {$pg['cluster']} tiene configurado escuchar en " . implode(', ', $pg['listen_missing'])
                    . ' pero NO lo hace (¿arrancó antes que la VPN?).';
            }
        }
        $r = $d['databases']['redis'] ?? null;
        if ($r && ($r['role'] ?? '') === 'master' && ($r['connected_slaves'] ?? 0) === 0 && array_sum($r['keys_per_db'] ?? []) > 0) {
            $c[] = 'Redis con datos (' . array_sum($r['keys_per_db']) . ' claves) y sin réplica.';
        }
        if (!empty($d['cron']['entries'])) {
            $c[] = count($d['cron']['entries']) . ' líneas de cron que no son del panel (' . count($d['cron']['files']) . ' ficheros en /etc/cron.d, '
                . count($d['cron']['user_crontabs']) . ' crontabs de usuario): copiarlas y decidir cuáles corren en el slave.';
        }
        if (!empty($d['cron']['laravel_scheduler'])) {
            $c[] = 'Scheduler de Laravel (schedule:run) en ' . implode(', ', array_unique($d['cron']['laravel_scheduler'])) . ': en el slave solo al promover.';
        }
        if (!empty($d['network']['etc_files_referencing_local_ips'])) {
            $c[] = count($d['network']['etc_files_referencing_local_ips']) . ' ficheros de /etc citan IPs de este servidor (ver network.etc_files_referencing_local_ips).';
        }
        return $c;
    }
}
