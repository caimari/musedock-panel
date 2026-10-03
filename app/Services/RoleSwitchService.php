<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * Cambio de rol planificado (switchover) desde el panel: el master actual pasa el
 * mando a un nodo del cluster y se queda como su copia en vivo. Independiente del
 * relevo por caída (FailoverService): aquí los dos servidores están bien.
 *
 * Reparto del trabajo:
 *  - el MASTER orquesta (orchestrate): comprueba, se aparta (fence), pide al nodo
 *    elegido que se promueva y espera, y después se convierte en su copia (demote);
 *  - el NODO ELEGIDO (promoteHere): se promueve, mueve el DNS de la IP pública del
 *    antiguo master a la suya e invierte los papeles del relevo DNS.
 * Ambos trabajos van en segundo plano (bin/role-switch-run.php) y dejan su avance en
 * /var/lib/musedock/role-switch-<tarea>.json, que el panel consulta.
 *
 * Nada aquí tiene nombres fijos: nodos, IPs y cuentas salen del cluster y del relevo.
 */
class RoleSwitchService
{
    private const DIR = '/var/lib/musedock';

    // ── Estado de un trabajo ────────────────────────────────────────────

    public static function statusFile(string $task): string
    {
        return self::DIR . '/role-switch-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $task) . '.json';
    }

    public static function status(string $task): array
    {
        $f = self::statusFile($task);
        $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($d) ? $d : ['state' => 'unknown', 'steps' => []];
    }

    private static function write(string $task, array $data): void
    {
        @mkdir(self::DIR, 0750, true);
        $data['updated_at'] = gmdate('c');
        file_put_contents(self::statusFile($task), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function step(string $task, array &$st, string $msg, ?bool $ok = null): void
    {
        $st['steps'][] = ['at' => date('H:i:s'), 'msg' => $msg, 'ok' => $ok];
        self::write($task, $st);
    }

    // ── Salud de réplica de ESTE nodo (la pide el master por la API) ───────

    public static function health(): array
    {
        $panelPort = (int)\MuseDockPanel\Env::int('DB_PORT', 5432);
        $pg = [];
        foreach (PgClusterService::listClusters() as $c) {
            if ($c['cluster'] === 'panel' || (int)$c['port'] === $panelPort) {
                continue;
            }
            $s = ReplicationService::getPgSlaveStatusForCluster($c);
            $hints = ReplicationService::queryCluster($c, 'show wal_log_hints');
            $pg[$c['key']] = [
                'in_recovery' => $s !== null || is_file(rtrim((string)$c['data_dir'], '/') . '/standby.signal'),
                'streaming'   => (bool)($s['streaming'] ?? false),
                'sender'      => (string)($s['sender_host'] ?? ''),
                'lag_seconds' => (int)($s['lag_seconds'] ?? 0),
                'wal_log_hints' => ($hints[0][0] ?? '') === 'on',
            ];
        }
        $my = ['configured' => Settings::get('repl_mysql_role', 'standalone') !== 'standalone'];
        try {
            $pdo = ReplicationService::getMysqlPdo();
            if ($pdo) {
                $ss = $pdo->query('SHOW SLAVE STATUS')->fetch(\PDO::FETCH_ASSOC) ?: [];
                $my += [
                    'is_slave'  => !empty($ss),
                    'io'        => $ss['Slave_IO_Running'] ?? '',
                    'sql'       => $ss['Slave_SQL_Running'] ?? '',
                    'lag'       => isset($ss['Seconds_Behind_Master']) ? (int)$ss['Seconds_Behind_Master'] : null,
                    'master'    => $ss['Master_Host'] ?? '',
                    'log_slave_updates' => (string)$pdo->query('SELECT @@log_slave_updates')->fetchColumn() === '1',
                    'read_only' => (string)$pdo->query('SELECT @@read_only')->fetchColumn() === '1',
                ];
            }
        } catch (\Throwable $e) {
            $my['error'] = $e->getMessage();
        }
        $redis = ['installed' => trim((string)shell_exec('command -v redis-cli 2>/dev/null')) !== ''];
        if ($redis['installed']) {
            $pass = '';
            foreach (@file('/etc/redis/redis.conf', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                if (preg_match('/^\s*requirepass\s+(\S+)/', $l, $m)) {
                    $pass = trim($m[1], '"\'');
                }
            }
            $info = (string)shell_exec(($pass !== '' ? 'REDISCLI_AUTH=' . escapeshellarg($pass) . ' ' : '') . 'timeout 5 redis-cli --no-auth-warning INFO replication 2>/dev/null');
            preg_match('/^role:(\w+)/m', $info, $r1);
            preg_match('/^master_link_status:(\w+)/m', $info, $r2);
            $redis += ['role' => $r1[1] ?? '?', 'link' => $r2[1] ?? ''];
        }
        return [
            'hostname'  => (string)gethostname(),
            'role'      => Settings::get('cluster_role', ''),
            'fenced'    => Settings::get('cluster_fenced', '0') === '1' || is_file(FailoverSafetyService::FENCE_FLAG),
            'local_ips' => array_values(array_filter(preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) ?: [])),
            'version'   => (string)((require PANEL_ROOT . '/config/panel.php')['version'] ?? ''),
            'pg'        => $pg,
            'mysql'     => $my,
            'redis'     => $redis,
        ];
    }

    // ── IPs: VPN para hablar entre nodos, públicas para el DNS ──────────────

    public static function nodeVpnIp(array $node): string
    {
        return (string)(parse_url((string)$node['api_url'], PHP_URL_HOST) ?: '');
    }

    /** Mi IP por la ruta hacia ese nodo (normalmente la de la VPN). */
    public static function myIpTowards(string $ip): string
    {
        preg_match('/\bsrc\s+(\S+)/', (string)shell_exec('ip route get ' . escapeshellarg($ip) . ' 2>/dev/null'), $m);
        return $m[1] ?? '';
    }

    /** IP pública (la del DNS) de un servidor, según la configuración del relevo. */
    public static function publicIpFor(array $localIps): string
    {
        foreach (FailoverService::getServers() as $srv) {
            if (in_array((string)($srv['ip'] ?? ''), $localIps, true)) {
                return (string)$srv['ip'];
            }
        }
        return '';
    }

    // ── Comprobaciones previas (en el master) ───────────────────────────────

    public static function candidates(): array
    {
        $out = [];
        foreach (ClusterService::getNodes() as $n) {
            if (($n['status'] ?? '') === 'online' && empty($n['standby'])) {
                $out[] = ['id' => (int)$n['id'], 'name' => (string)$n['name']];
            }
        }
        return $out;
    }

    public static function preflight(int $nodeId): array
    {
        $checks = [];
        $add = static function (string $name, bool $ok, string $detail, bool $blocking = true) use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'blocking' => $blocking];
        };
        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            return ['ok' => false, 'checks' => [['name' => 'Nodo', 'ok' => false, 'detail' => 'no existe', 'blocking' => true]]];
        }
        $me = self::health();
        $add('Este servidor es el master activo', $me['role'] === 'master' && !$me['fenced'],
            "rol {$me['role']}" . ($me['fenced'] ? ', APARTADO' : ''));

        $r = ClusterService::callNode($nodeId, 'POST', 'api/cluster/action', ['action' => 'role-switch-health', 'payload' => []]);
        $t = $r['data']['result'] ?? $r['data']['health'] ?? null;
        if (empty($r['ok']) || !is_array($t)) {
            $add("{$node['name']} responde", false, (string)($r['error'] ?? 'sin respuesta (¿panel antiguo?)'));
            return ['ok' => false, 'checks' => $checks, 'node' => $node['name']];
        }
        $add("{$node['name']} responde", true, "panel {$t['version']}");
        $add("{$node['name']} es slave", ($t['role'] ?? '') === 'slave' && empty($t['fenced']), 'rol ' . ($t['role'] ?? '?') . (!empty($t['fenced']) ? ', APARTADO' : ''));

        $vpn = self::nodeVpnIp($node);
        $myVpn = self::myIpTowards($vpn);
        foreach ($me['pg'] as $key => $mine) {
            $their = $t['pg'][$key] ?? null;
            $ok = $their && $their['in_recovery'] && $their['streaming'] && $their['lag_seconds'] <= 10;
            $add("PostgreSQL {$key}: {$node['name']} copia al día", $ok,
                $their ? (($their['streaming'] ? 'streaming' : 'SIN streaming') . ", retraso {$their['lag_seconds']} s") : 'no existe en el nodo');
            $add("PostgreSQL {$key}: wal_log_hints en los dos", $mine['wal_log_hints'] && ($their['wal_log_hints'] ?? false),
                'aquí ' . ($mine['wal_log_hints'] ? 'on' : 'OFF') . ', allí ' . (($their['wal_log_hints'] ?? false) ? 'on' : 'OFF')
                . ' (sin esto la vuelta copia la base entera)', false);
        }
        if (!empty($me['mysql']['configured'])) {
            $tm = $t['mysql'] ?? [];
            $add("MariaDB: {$node['name']} replica al día", ($tm['io'] ?? '') === 'Yes' && ($tm['sql'] ?? '') === 'Yes' && ($tm['lag'] ?? 99) <= 10,
                'IO ' . ($tm['io'] ?? '?') . ', SQL ' . ($tm['sql'] ?? '?') . ', retraso ' . ($tm['lag'] ?? '?') . ' s');
            $add('MariaDB: log_slave_updates en los dos', !empty($me['mysql']['log_slave_updates']) && !empty($tm['log_slave_updates']),
                'aquí ' . (!empty($me['mysql']['log_slave_updates']) ? 'on' : 'OFF') . ', allí ' . (!empty($tm['log_slave_updates']) ? 'on' : 'OFF')
                . ' (sin esto la vuelta copia MariaDB entera)', false);
        }
        if (!empty($me['redis']['installed']) && !empty($t['redis']['installed'])) {
            $add("Redis: {$node['name']} es réplica conectada", ($t['redis']['role'] ?? '') === 'slave' && ($t['redis']['link'] ?? '') === 'up',
                ($t['redis']['role'] ?? '?') . ', enlace ' . ($t['redis']['link'] ?? '?'), false);
        }
        $myPub = self::publicIpFor($me['local_ips']);
        $theirPub = self::publicIpFor($t['local_ips'] ?? []);
        $add('IPs públicas para mover el DNS', $myPub !== '' && $theirPub !== '' && $myPub !== $theirPub,
            ($myPub ?: '¿?') . ' → ' . ($theirPub ?: '¿?') . ($theirPub === '' ? ' (añade el nodo en Failover → servidores con su IP pública)' : ''));
        $add('Ruta entre los dos nodos', $myVpn !== '' && $vpn !== '', "{$myVpn} ↔ {$vpn}");

        $blocking = array_filter($checks, static fn($c) => !$c['ok'] && $c['blocking']);
        return ['ok' => !$blocking, 'checks' => $checks, 'node' => $node['name'],
                'ips' => ['my_vpn' => $myVpn, 'their_vpn' => $vpn, 'my_public' => $myPub, 'their_public' => $theirPub]];
    }

    // ── Lanzar y orquestar (en el master) ──────────────────────────────────

    public static function start(int $nodeId, string $by = ''): array
    {
        $pre = self::preflight($nodeId);
        if (empty($pre['ok'])) {
            return ['ok' => false, 'error' => 'Las comprobaciones previas no pasan', 'preflight' => $pre];
        }
        $task = 'switch-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        self::write($task, ['state' => 'running', 'role' => 'orchestrator', 'node' => $pre['node'], 'by' => $by,
                            'started_at' => gmdate('c'), 'steps' => []]);
        shell_exec(sprintf('setsid nohup php %s orchestrate %s %d > /dev/null 2>&1 &',
            escapeshellarg(PANEL_ROOT . '/bin/role-switch-run.php'), escapeshellarg($task), $nodeId));
        Settings::set('role_switch_last_task', $task);
        LogService::log('cluster.role-switch', 'start', "Cambio de rol hacia {$pre['node']} iniciado" . ($by !== '' ? " por {$by}" : ''));
        return ['ok' => true, 'task' => $task];
    }

    public static function orchestrate(string $task, int $nodeId): void
    {
        $st = self::status($task);
        $st['state'] = 'running';
        $fail = static function (string $msg) use ($task, &$st): void {
            $st['state'] = 'failed';
            $st['error'] = $msg;
            self::step($task, $st, 'PARADO: ' . $msg, false);
            LogService::log('cluster.role-switch', 'failed', $msg);
            try {
                NotificationService::send('Cambio de rol PARADO', $msg . "\n\nRevisa el panel (Dashboard → Rol de este servidor).");
            } catch (\Throwable) {
            }
        };
        ClusterService::$progressCb = static function (string $m) use ($task, &$st): void {
            self::step($task, $st, $m);
        };

        self::step($task, $st, 'Comprobaciones previas…');
        $pre = self::preflight($nodeId);
        if (empty($pre['ok'])) {
            $bad = array_map(static fn($c) => $c['name'] . ': ' . $c['detail'], array_filter($pre['checks'], static fn($c) => !$c['ok'] && $c['blocking']));
            $fail('no pasan las comprobaciones: ' . implode('; ', $bad));
            return;
        }
        $ips = $pre['ips'];
        self::step($task, $st, "Comprobaciones OK. Pasando el mando a {$pre['node']} ({$ips['their_vpn']}).", true);

        // 1) Apartarse: ya no escribe nadie aquí.
        self::step($task, $st, 'Apartando este servidor (sin webs, bases en solo lectura, panel de rescate)…');
        FailoverSafetyService::fenceSelf("cambio de rol hacia {$pre['node']}");
        self::step($task, $st, 'Este servidor está apartado.', true);
        sleep(3);   // que las réplicas reciban lo último

        // 2) Que el otro se promueva (en segundo plano allí) y esperarle.
        $r = ClusterService::callNode($nodeId, 'POST', 'api/cluster/action', ['action' => 'role-switch-promote', 'payload' => [
            'old_vpn_ip' => $ips['my_vpn'], 'old_public_ip' => $ips['my_public'], 'new_public_ip' => $ips['their_public'],
        ]]);
        $remoteTask = (string)($r['data']['result']['task'] ?? $r['data']['task'] ?? '');
        if (empty($r['ok']) || $remoteTask === '') {
            FailoverSafetyService::unfenceSelf();
            $fail("{$pre['node']} no aceptó promoverse (" . ($r['error'] ?? 'sin respuesta') . '). Este servidor se ha reactivado: todo sigue como antes.');
            return;
        }
        self::step($task, $st, "{$pre['node']} se está promoviendo…");
        $seen = 0;
        $remote = [];
        for ($i = 0; $i < 360; $i++) {   // hasta 30 min
            sleep(5);
            $q = ClusterService::callNode($nodeId, 'POST', 'api/cluster/action', ['action' => 'role-switch-status', 'payload' => ['task' => $remoteTask]]);
            $remote = $q['data']['result'] ?? $q['data'] ?? [];
            foreach (array_slice((array)($remote['steps'] ?? []), $seen) as $s) {
                self::step($task, $st, "[{$pre['node']}] " . ($s['msg'] ?? ''), $s['ok'] ?? null);
            }
            $seen = count((array)($remote['steps'] ?? []));
            if (in_array($remote['state'] ?? '', ['done', 'failed'], true)) {
                break;
            }
        }
        if (($remote['state'] ?? '') !== 'done') {
            // Si el otro no llegó a promover nada, volver atrás es seguro; si promovió
            // algo, no: dos masters. Se para y se avisa.
            if (empty($remote['promoted'])) {
                FailoverSafetyService::unfenceSelf();
                $fail("{$pre['node']} no se promovió (" . ($remote['error'] ?? 'tiempo agotado') . '). Este servidor se ha reactivado: todo sigue como antes.');
            } else {
                $fail("{$pre['node']} se promovió pero terminó con errores (" . ($remote['error'] ?? '?') . '). Este servidor sigue APARTADO; revisa los dos antes de seguir.');
            }
            return;
        }

        // 3) Convertirse en copia del nuevo master.
        self::step($task, $st, "Convirtiendo este servidor en copia de {$pre['node']}…");
        $d = ClusterService::demoteToSlave($ips['their_vpn']);
        if (empty($d['ok'])) {
            $fail('el nuevo master funciona, pero este servidor no quedó bien como copia: ' . implode('; ', $d['errors'] ?? []));
            return;
        }
        self::step($task, $st, "Este servidor es ahora copia de {$pre['node']}.", true);
        $st['state'] = 'done';
        self::step($task, $st, "Cambio de rol terminado: {$pre['node']} es el master.", true);
        LogService::log('cluster.role-switch', 'done', "{$pre['node']} es el master; este servidor es su copia");
        try {
            NotificationService::send("Cambio de rol hecho: {$pre['node']} es el master", implode("\n", array_map(static fn($s) => "{$s['at']} {$s['msg']}", $st['steps'])));
        } catch (\Throwable) {
        }
    }

    // ── En el nodo elegido ─────────────────────────────────────────────────

    public static function startPromoteHere(string $oldVpn, string $oldPub, string $newPub): array
    {
        foreach ([$oldVpn, $oldPub, $newPub] as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return ['ok' => false, 'error' => 'IP no válida'];
            }
        }
        if (Settings::get('cluster_role', '') !== 'slave') {
            return ['ok' => false, 'error' => 'este nodo no es slave'];
        }
        $task = 'promote-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        self::write($task, ['state' => 'running', 'role' => 'target', 'promoted' => false, 'started_at' => gmdate('c'), 'steps' => []]);
        shell_exec(sprintf('setsid nohup php %s promote %s %s %s %s > /dev/null 2>&1 &',
            escapeshellarg(PANEL_ROOT . '/bin/role-switch-run.php'), escapeshellarg($task),
            escapeshellarg($oldVpn), escapeshellarg($oldPub), escapeshellarg($newPub)));
        return ['ok' => true, 'task' => $task];
    }

    public static function promoteHere(string $task, string $oldVpn, string $oldPub, string $newPub): void
    {
        $st = self::status($task);
        ClusterService::$progressCb = static function (string $m) use ($task, &$st): void {
            self::step($task, $st, $m);
        };
        self::step($task, $st, 'Promoviendo este servidor a master…');
        $st['promoted'] = true;   // a partir de aquí ya no es "como antes"
        $p = ClusterService::promoteToMaster(true, $oldVpn);
        if (empty($p['ok'])) {
            $st['state'] = 'failed';
            $st['error'] = implode('; ', $p['errors'] ?? ['error']);
            self::step($task, $st, 'La promoción terminó con errores: ' . $st['error'], false);
            return;
        }
        self::step($task, $st, 'Promovido: bases de datos con escritura, Caddy con las webs.', true);

        // DNS: de la IP pública del antiguo master a la mía (los nombres de máquina se quedan).
        self::step($task, $st, "Moviendo el DNS {$oldPub} → {$newPub}…");
        $c = FailoverService::getConfig();
        $ttl = (int)($c['failover_ttl_normal'] ?? 300) ?: 300;
        $moved = 0;
        foreach (CloudflareService::getConfiguredAccounts() as $acct) {
            foreach (($acct['zones'] ?? []) as $zone) {
                $r = CloudflareService::batchUpdateIp((string)$acct['token'], (string)$zone['id'], $oldPub, $newPub, $ttl, 'role_switch');
                $moved += (int)($r['updated'] ?? 0);
            }
        }
        self::step($task, $st, "DNS movido: {$moved} registros (lo que va por CNAME a ellos se mueve con ellos).", true);

        // Invertir los papeles del relevo DNS: ahora vigila el otro.
        $servers = FailoverService::getServers();
        $newId = $oldId = null;
        foreach ($servers as $srv) {
            if (($srv['ip'] ?? '') === $newPub) $newId = $srv['id'];
            if (($srv['ip'] ?? '') === $oldPub) $oldId = $srv['id'];
        }
        foreach ($servers as &$srv) {
            if ($srv['id'] === $newId) { $srv['role'] = 'primary'; $srv['failover_to'] = (string)$oldId; }
            if ($srv['id'] === $oldId) { $srv['role'] = 'failover'; $srv['failover_to'] = ''; }
        }
        unset($srv);
        if ($newId !== null && $oldId !== null) {
            FailoverService::saveServers($servers);
            Settings::set('failover_state', 'normal');
            self::step($task, $st, 'Relevo DNS invertido: este servidor es ahora el principal y el otro el de relevo.', true);
        }
        try {
            FailoverService::pushConfigToSlaves();
        } catch (\Throwable) {
        }
        $st['state'] = 'done';
        self::step($task, $st, 'Listo en este lado.', true);
        LogService::log('cluster.role-switch', 'promoted', "Promovido por cambio de rol; DNS {$oldPub} → {$newPub} ({$moved} registros)");
    }
}
