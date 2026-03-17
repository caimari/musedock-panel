#!/usr/bin/env php
<?php
/**
 * Background file sync runner — launched by ClusterController::syncFilesNow()
 * Usage: php filesync-run.php <node_id> <sync_id>
 */

$nodeId = (int)($argv[1] ?? 0);
$syncId = $argv[2] ?? '';

if ($nodeId < 1 || empty($syncId)) {
    file_put_contents('php://stderr', "Usage: php filesync-run.php <node_id> <sync_id>\n");
    exit(1);
}

// Bootstrap panel (same as cluster-worker.php)
define('PANEL_ROOT', dirname(__DIR__));
define('PANEL_VERSION', '0.4.0');

spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');
$config = require PANEL_ROOT . '/config/panel.php';

use MuseDockPanel\Services\FileSyncService;
use MuseDockPanel\Services\LogService;

$progressFile = FileSyncService::progressFilePath($syncId);

try {
    $result = FileSyncService::syncAllToNode($nodeId, $progressFile);
    LogService::log('cluster.filesync', 'background', sprintf(
        "Sync archivos nodo #%d: %d/%d OK (%.1fs)",
        $nodeId, $result['ok_count'], $result['total'], $result['elapsed']
    ));
} catch (\Throwable $e) {
    // Write error to progress file
    file_put_contents($progressFile, json_encode([
        'status' => 'error',
        'error' => $e->getMessage(),
        'total' => 0, 'current' => 0, 'ok' => 0, 'fail' => 0,
    ]));
    LogService::log('cluster.filesync', 'error', "Sync error nodo #{$nodeId}: " . $e->getMessage());
}
