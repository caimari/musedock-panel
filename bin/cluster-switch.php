<?php
/**
 * Cambio de roles master/slave desde la terminal (switchover planificado o relevo).
 * Funciona aunque Caddy esté parado (el panel web del nodo apartado no responde).
 * Cada orden hace UNA cosa y dice lo que ha hecho; el orden lo marca el guion.
 *
 * Uso (como root):
 *   php bin/cluster-switch.php status          rol, aislamiento, réplicas de este nodo
 *   php bin/cluster-switch.php push-config     (master) manda la config de relevo a los slaves
 *   php bin/cluster-switch.php dns-plan        qué registros DNS movería un relevo desde aquí
 *   php bin/cluster-switch.php fence           aparta ESTE nodo: para Caddy y lsyncd, BBDD de
 *                                              datos en solo lectura (la del panel no)
 *   php bin/cluster-switch.php unfence         lo contrario (arranca Caddy; lsyncd si es master)
 *   php bin/cluster-switch.php promote [--force]
 *                                              convierte ESTE nodo en master (PostgreSQL,
 *                                              MariaDB, Redis, correo, Caddy, hooks). --force
 *                                              solo si el master antiguo YA está apartado (fence).
 *   php bin/cluster-switch.php demote <ip-vpn-del-nuevo-master>
 *                                              reconstruye ESTE nodo como slave del nuevo master
 *                                              (pg_rewind, MariaDB desde cero, Redis)
 *   php bin/cluster-switch.php switch-check <id-nodo>   (master) comprobaciones de un cambio de rol
 *   php bin/cluster-switch.php switch-to <id-nodo>      (master) cambio de rol completo, con avance
 *   php bin/cluster-switch.php adopt-peer <ip-vpn>
 *                                              (master) registra el otro nodo y le manda los
 *                                              ficheros en vivo (lsyncd), como hacía el master
 *   php bin/cluster-switch.php dns-failover    DNS de los primarios → este servidor de relevo
 *   php bin/cluster-switch.php dns-failback    devuelve SOLO lo que movió el relevo (diario)
 *   php bin/cluster-switch.php failover-normalize   (master) este servidor principal y TITULAR del relevo, estado normal
 *   php bin/cluster-switch.php pg-rebuild <ip-master> <clúster|all> [--max-rate=20M]   copia completa con avance
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\FailoverSafetyService;
use MuseDockPanel\Services\FailoverService;
use MuseDockPanel\Settings;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$cmd = $argv[1] ?? '';
// Avance en directo: cada paso largo (rebobinado, copia de MariaDB, Redis…) se ve al momento.
ClusterService::$progressCb = static function (string $m): void {
    echo '[' . date('H:i:s') . '] ' . $m . "\n";
    @ob_flush();
    flush();
};
$out = static function ($data): void {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
};
$ok = true;

switch ($cmd) {
    case 'status':
        $out([
            'host' => gethostname(),
            'cluster_role' => Settings::get('cluster_role', ''),
            'fenced' => Settings::get('cluster_fenced', '0') === '1',
            'failover_state' => FailoverService::getState(),
            'caddy' => trim((string)shell_exec('systemctl is-active caddy')),
            'lsyncd' => trim((string)shell_exec('systemctl is-active lsyncd')),
            'postgresql' => \MuseDockPanel\Mcp\McpClusterTools::run('failover_preflight', [])['postgresql'] ?? null,
            'redis' => \MuseDockPanel\Mcp\McpClusterTools::run('failover_preflight', [])['redis'] ?? null,
        ]);
        break;

    case 'push-config':
        $r = FailoverService::pushConfigToSlaves();
        $out($r);
        $ok = !empty($r['ok']);
        break;

    case 'dns-plan':
        $out(\MuseDockPanel\Mcp\McpClusterTools::run('failover_dns_plan', []));
        break;

    case 'fence':
        $r = FailoverSafetyService::fenceSelf('switchover planificado (cluster-switch.php)');
        $out($r);
        break;

    case 'unfence':
        $out(FailoverSafetyService::unfenceSelf());
        break;

    case 'promote':
        $r = ClusterService::promoteToMaster(in_array('--force', $argv, true));
        $out($r);
        $ok = !empty($r['ok']);
        if ($ok && Settings::get('cluster_fenced', '0') === '1') {
            // Si este nodo estuvo apartado antes (vuelta de un switchover), quitarle el
            // solo lectura que le puso el fence: ahora es el master.
            echo "Este nodo estaba apartado: se reactiva.\n";
            $out(FailoverSafetyService::unfenceSelf());
        }
        break;

    case 'demote':
        $ip = (string)($argv[2] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            fwrite(STDERR, "Falta la IP (de la VPN) del nuevo master: demote 10.10.70.X\n");
            exit(1);
        }
        $r = ClusterService::demoteToSlave($ip);
        $out($r);
        $ok = !empty($r['ok']);
        break;

    case 'switch-check':
        // (en el master) comprobaciones previas de un cambio de rol hacia el nodo <id>
        $out(\MuseDockPanel\Services\RoleSwitchService::preflight((int)($argv[2] ?? 0)));
        break;

    case 'switch-to':
        // (en el master) cambio de rol completo hacia el nodo <id>, en primer plano
        $pre = \MuseDockPanel\Services\RoleSwitchService::start((int)($argv[2] ?? 0), 'terminal');
        if (empty($pre['ok'])) {
            $out($pre);
            exit(1);
        }
        echo "Tarea {$pre['task']} en segundo plano. Avance:\n";
        $seen = 0;
        do {
            sleep(3);
            $st = \MuseDockPanel\Services\RoleSwitchService::status($pre['task']);
            foreach (array_slice($st['steps'] ?? [], $seen) as $x) {
                echo $x['at'] . ' ' . ($x['ok'] === true ? '[OK] ' : ($x['ok'] === false ? '[ERROR] ' : '')) . $x['msg'] . "\n";
            }
            $seen = count($st['steps'] ?? []);
        } while (!in_array($st['state'] ?? '', ['done', 'failed'], true));
        $ok = ($st['state'] ?? '') === 'done';
        break;

    case 'failover-normalize':
        // En el master: este servidor como principal del relevo DNS, los demás de relevo,
        // estado normal y sin restos de un relevo por caída (dns-failover a mano).
        // Además, este servidor pasa a ser el TITULAR del mando (decisión del administrador):
        // si cae y otro le sustituye, en modo auto el mando vuelve aquí cuando esté estable.
        foreach (\MuseDockPanel\Services\FailoverService::getServers() as $s) {
            if (in_array((string)($s['ip'] ?? ''), preg_split('/\s+/', trim((string)shell_exec('hostname -I'))) ?: [], true)) {
                Settings::set('failover_preferred_ip', (string)$s['ip']);
            }
        }
        $r = \MuseDockPanel\Services\RoleSwitchService::normalizeFailover() + ['titular' => Settings::get('failover_preferred_ip', '')];
        $out($r);
        $ok = !empty($r['ok']);
        break;

    case 'pg-rebuild':
        // Copia completa (pg_basebackup) de un clúster PostgreSQL desde el master, para
        // cuando el rebobinado no es posible. Aparta los datos actuales (no los borra).
        $ip = (string)($argv[2] ?? '');
        $which = (string)($argv[3] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP) || $which === '') {
            fwrite(STDERR, "Uso: pg-rebuild <ip-vpn-del-master> <clúster, p. ej. 14/main | all> [--max-rate=20M]\n");
            exit(1);
        }
        foreach ($argv as $a) {
            if (preg_match('/^--max-rate=(\d+[kM]?|0)$/', $a, $m)) {
                Settings::set('repl_pg_basebackup_max_rate', $m[1] === '0' ? '' : $m[1]);
            }
        }
        $pgUser = Settings::get('repl_pg_user', Settings::get('repl_panel_slave_ip', '') !== '' ? 'repl_panel' : 'replicator');
        $pgPass = \MuseDockPanel\Services\ReplicationService::decryptPassword(Settings::get('repl_pg_password', Settings::get('repl_pg_pass', '')));
        $panelPort = (int)\MuseDockPanel\Env::int('DB_PORT', 5432);
        $ok = true;
        foreach (\MuseDockPanel\Services\PgClusterService::listClusters() as $c) {
            if ($c['cluster'] === 'panel' || (int)$c['port'] === $panelPort || ($which !== 'all' && $c['key'] !== $which)) {
                continue;
            }
            echo '[' . date('H:i:s') . "] {$c['key']}: copia completa desde {$ip} (límite " . (Settings::get('repl_pg_basebackup_max_rate', '20M') ?: 'ninguno') . ")…\n";
            $r = \MuseDockPanel\Services\ReplicationService::setupPgSlaveForCluster($c, $ip, (int)$c['port'], $pgUser, $pgPass, true);
            echo '[' . date('H:i:s') . "] {$c['key']}: " . (!empty($r['ok']) ? 'OK, replicando' : 'ERROR ' . ($r['error'] ?? '')) . "\n";
            $ok = $ok && !empty($r['ok']);
        }
        break;

    case 'adopt-peer':
        // (en el master) registrar el otro nodo y mandarle los ficheros en vivo
        $out(ClusterService::adoptPeerAsFileSyncTarget((string)($argv[2] ?? '')));
        break;

    case 'dns-failover':
        $r = FailoverService::transitionTo(FailoverService::STATE_PRIMARY_DOWN, 'manual (cluster-switch.php)');
        $out($r);
        $ok = !empty($r['ok']);
        break;

    case 'dns-failback':
        $r = FailoverService::transitionTo(FailoverService::STATE_NORMAL, 'manual (cluster-switch.php)');
        $out($r);
        $ok = !empty($r['ok']);
        break;

    default:
        fwrite(STDERR, "Orden no válida. Mira la cabecera de bin/cluster-switch.php.\n");
        exit(1);
}
exit($ok ? 0 : 1);
