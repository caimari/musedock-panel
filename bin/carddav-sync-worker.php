<?php
/**
 * MuseDock CardDAV sync worker.
 *
 * Runs from cron on every node. ONLY the node that is currently master pushes a
 * snapshot of the Baïkal DAV data tables (contacts + calendars) to the other
 * cluster node(s). Direction follows cluster_role, so after a failover the newly
 * promoted node becomes the pusher automatically (reverse direction), and the
 * old master receives again when it returns as slave — "last promotion wins".
 *
 * Idempotent + cheap: exits immediately if CardDAV isn't installed or this node
 * isn't master. The receive side (carddav_apply_snapshot) does an authoritative
 * whole-snapshot replace inside one transaction.
 *
 * Installed as /etc/cron.d/musedock-carddav-sync (staggered +25s).
 */
require_once __DIR__ . '/../app/bootstrap.php';

use MuseDockPanel\Settings;
use MuseDockPanel\Services\CardDavService;

$log = static function (string $m): void {
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $m . PHP_EOL;
};

// 1. Skip unless CardDAV is installed on this node.
if (!CardDavService::isInstalled()) {
    // Silent no-op: nothing to replicate here.
    exit(0);
}

// 2. Only the master pushes. A slave must never overwrite the master.
$role = Settings::get('cluster_role', 'standalone');
if ($role !== 'master') {
    exit(0);
}

// 3. Simple lockfile so a slow cycle doesn't overlap the next minute's run.
// In storage/ (root-owned, not world-writable) so a local unprivileged process
// can't pre-create/flock it to block sync (a /tmp lock would be DoS-able).
$lockDir = PANEL_ROOT . '/storage';
@mkdir($lockDir, 0750, true);
$lock = $lockDir . '/carddav-sync.lock';
$fh = @fopen($lock, 'c');
if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
    $log('Otro ciclo de sync CardDAV en curso; se omite.');
    exit(0);
}

try {
    $nodes = CardDavService::replicaNodes();
    if (!$nodes) {
        $log('Sin nodos réplica online con mail; nada que hacer.');
        exit(0);
    }

    $snap = CardDavService::exportSnapshot();
    if ($snap === null) {
        $log('ERROR: no se pudo exportar el snapshot local de Baïkal.');
        exit(1);
    }
    $total = 0;
    foreach ($snap['tables'] as $rows) { $total += is_array($rows) ? count($rows) : 0; }

    foreach ($nodes as $node) {
        $nodeId = (int) ($node['id'] ?? 0);
        $name = $node['name'] ?? ('#' . $nodeId);
        if ($nodeId <= 0) continue;
        $res = CardDavService::syncToNode($nodeId);
        if (!empty($res['ok'])) {
            $log("OK → {$name}: {$total} filas empujadas.");
        } else {
            $log("FALLO → {$name}: " . ($res['error'] ?? 'error desconocido'));
        }
    }
    Settings::set('carddav_last_sync', gmdate('c'));
} finally {
    flock($fh, LOCK_UN);
    fclose($fh);
}
