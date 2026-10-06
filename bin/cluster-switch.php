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
 *   php bin/cluster-switch.php apply-master-caddyfile [--apply]   (master) enseña / pone las webs del Caddyfile del master anterior
 *   php bin/cluster-switch.php failover-normalize   (master) este servidor principal y TITULAR del relevo, estado normal
 *   php bin/cluster-switch.php pg-rebuild <ip-master> <clúster|all> [--max-rate=20M]   copia completa con avance
 *   php bin/cluster-switch.php relay-standby <nodo> [--apply]   (master con relay privado) instala en ese nodo
 *                                              el relay de reserva, parado, y le envía dominios y usuarios
 *   php bin/cluster-switch.php sync-status     (master) carpetas de /opt y /srv: copiadas, propias y sin copia
 *   php bin/cluster-switch.php sync-add <carpeta>... [--apply]   (master) añadir carpetas de apps a la copia (ESPEJO)
 *   php bin/cluster-switch.php sync-local <carpeta>...   marcar carpetas como propias de esta máquina (no avisa)
 *   php bin/cluster-switch.php sync-unlocal <carpeta>... quitar esa marca
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

    case 'mirror-exclude':
    case 'mirror-include':
        // Elementos propios de la máquina del master que este slave no copia (ni avisa de ellos).
        $items = array_slice($argv, 2);
        $list = $cmd === 'mirror-exclude'
            ? \MuseDockPanel\Services\ConfigMirrorService::setExcluded($items)
            : \MuseDockPanel\Services\ConfigMirrorService::setExcluded([], $items);
        echo 'Excluidos de la copia: ' . (implode(', ', $list) ?: 'ninguno') . "\n";
        break;

    case 'relay-standby':
        // En el master con relay privado: relay de reserva en un nodo de relevo.
        $who = (string)($argv[2] ?? '');
        $node = null;
        foreach (ClusterService::getNodes() as $n) {
            if ((string)$n['id'] === $who || strcasecmp((string)$n['name'], $who) === 0) {
                $node = $n;
            }
        }
        if (!$node || Settings::get('mail_mode', '') !== 'relay') {
            echo $node ? "Este servidor no tiene relay privado (mail_mode=relay).\n" : "Nodo no encontrado: usa su id o nombre (php bin/cluster-switch.php status).\n";
            exit(1);
        }
        $cfg = [
            'mail_hostname' => Settings::get('mail_relay_host', '') ?: Settings::get('mail_hostname', ''),
            'wireguard_ip' => Settings::get('mail_relay_wireguard_ip', ''),
            'wireguard_cidr' => Settings::get('mail_relay_wireguard_cidr', '10.10.70.0/24'),
            'outbound_domain' => Settings::get('mail_outbound_domain', ''),
        ];
        echo "Relay de reserva en {$node['name']}: instalar Postfix + OpenDKIM + SASL con la configuración de este relay\n"
            . "  (nombre {$cfg['mail_hostname']}, escucha en la IP flotante {$cfg['wireguard_ip']}:587, red {$cfg['wireguard_cidr']}),\n"
            . "  PARADO mientras ese nodo sea copia; el panel lo arranca si toma el mando y tiene la IP flotante.\n"
            . "  Después se le envían los dominios (con su clave DKIM) y los usuarios SMTP. No se borra nada.\n";
        if (!in_array('--apply', $argv, true)) {
            echo "Repite con --apply para hacerlo.\n";
            break;
        }
        $r = ClusterService::callNode((int)$node['id'], 'POST', 'api/cluster/action', ['action' => 'mail_relay_standby_setup', 'payload' => $cfg]);
        $out($r['data'] ?? $r);
        echo "La instalación sigue en segundo plano en el nodo (1-2 min). Los dominios y usuarios se envían solos en cuanto termine (cada 5 min).\n";
        $ok = !empty($r['ok']) && (!empty($r['data']['ok']) || !empty($r['data']['task_id']));
        break;

    case 'sync-status':
        // En el master: carpetas de /opt y /srv que se copian, las propias de la máquina y
        // las que no están en ninguna lista (no llegarían al servidor de relevo).
        $fs = \MuseDockPanel\Services\FileSyncService::class;
        $out(['copied' => $fs::extraPaths(), 'local' => $fs::localPaths(), 'unsynced' => $fs::unsyncedAppFolders()]);
        break;

    case 'sync-local':
    case 'sync-unlocal':
        // Carpetas propias de esta máquina: no se copian y no se avisa de ellas.
        $items = array_slice($argv, 2);
        $list = $cmd === 'sync-local'
            ? \MuseDockPanel\Services\FileSyncService::setLocalPaths($items)
            : \MuseDockPanel\Services\FileSyncService::setLocalPaths([], $items);
        echo 'Propias de esta máquina: ' . (implode(', ', $list) ?: 'ninguna') . "\n";
        break;

    case 'sync-add':
        // En el master: añadir carpetas a la copia hacia los nodos que ya reciben las extra.
        // Es ESPEJO: en el destino se borra lo que no exista aquí dentro de esa carpeta.
        $fs = \MuseDockPanel\Services\FileSyncService::class;
        if (Settings::get('cluster_role', '') !== 'master') {
            echo "sync-add se ejecuta en el MASTER.\n";
            exit(1);
        }
        $items = array_values(array_filter(array_slice($argv, 2), static fn($a) => $a !== '--apply'));
        $new = $fs::extraPaths(implode("\n", $items));
        $nodes = $fs::parseExcludePatterns(Settings::get('filesync_extra_nodes', ''));
        if (!$new || !$nodes) {
            echo $new ? "No hay nodos que reciban carpetas extra: configúralo una vez por MCP filesync_extra_paths (target_nodes).\n"
                : "Ninguna carpeta válida (solo existentes bajo /opt, /srv, /home o /var/www menos vhosts; nunca el panel).\n";
            exit(1);
        }
        $paths = array_values(array_unique(array_merge($fs::extraPaths(), $new)));
        if (!in_array('--apply', $argv, true)) {
            echo "Se copiarían (ESPEJO: en el nodo destino se borra lo que no exista aquí dentro de esas carpetas):\n  "
                . implode("\n  ", $new) . "\nA los nodos: " . implode(', ', array_map(static fn($id) => (ClusterService::getNode((int)$id)['name'] ?? "#{$id}"), $nodes))
                . "\nRepite con --apply para hacerlo.\n";
            break;
        }
        Settings::set('filesync_extra_paths', implode("\n", $paths));
        $fs::setLocalPaths([], $new);
        $r = $fs::reloadLsyncd();
        echo 'Copiadas: ' . implode(', ', $paths) . "\nlsyncd " . (!empty($r['ok']) ? 'reiniciado' : 'NO arrancó: ' . ($r['error'] ?? '')) . "\n";
        $ok = !empty($r['ok']);
        break;

    case 'apply-master-caddyfile':
        // En el que manda: poner las webs del Caddyfile del master anterior que no se
        // pusieron al promover (validando con el entorno real de Caddy).
        $r = \MuseDockPanel\Services\ConfigMirrorService::applyStagedCaddyfile(in_array('--apply', $argv, true));
        $out($r);
        $ok = !empty($r['ok']);
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
