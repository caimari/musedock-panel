<?php
/**
 * File Sync Worker — Runs periodically via cron to sync hosting files to slave nodes.
 * Usage: * * * * * /usr/bin/php /opt/musedock-panel/bin/filesync-worker.php >> /opt/musedock-panel/storage/logs/filesync-worker.log 2>&1
 */

define('PANEL_ROOT', dirname(__DIR__));

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require $file;
});

\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

// Lock to prevent overlapping runs
$lockFile = PANEL_ROOT . '/storage/filesync-worker.lock';
$fp = fopen($lockFile, 'w');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    exit(0); // Another instance is running
}
fwrite($fp, (string)getmypid());

$log = function (string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
};

try {
    $config = \MuseDockPanel\Services\FileSyncService::getConfig();

    // Only run if enabled and this server is master
    if (!$config['enabled']) {
        exit(0);
    }

    $clusterRole = \MuseDockPanel\Settings::get('cluster_role', 'standalone');
    if ($clusterRole !== 'master') {
        exit(0);
    }

    // Check interval — only run if enough time has elapsed
    $lastRun = \MuseDockPanel\Settings::get('filesync_last_run', '0');
    $intervalSeconds = $config['interval_minutes'] * 60;
    $now = time();

    if (($now - (int)$lastRun) < $intervalSeconds) {
        exit(0); // Not time yet
    }

    // Update last run timestamp
    \MuseDockPanel\Settings::set('filesync_last_run', (string)$now);

    $log('File sync worker started (method: ' . $config['method'] . ')');

    // Get all online nodes
    $nodes = \MuseDockPanel\Services\ClusterService::getNodes();
    if (empty($nodes)) {
        $log('No nodes configured, nothing to sync');
        exit(0);
    }

    $accounts = \MuseDockPanel\Database::fetchAll("SELECT * FROM hosting_accounts WHERE status = 'active' ORDER BY domain");
    if (empty($accounts)) {
        $log('No active hosting accounts, nothing to sync');
        exit(0);
    }

    $totalOk = 0;
    $totalFail = 0;

    foreach ($nodes as $node) {
        $nodeName = $node['name'] ?? 'Unknown';
        $log("Syncing to node: {$nodeName}");

        foreach ($accounts as $acc) {
            $domain = $acc['domain'];
            $result = \MuseDockPanel\Services\FileSyncService::syncHostingToNode($acc, $node);

            if ($result['ok']) {
                $totalOk++;
                $elapsed = $result['elapsed_seconds'] ?? 0;
                $log("  OK: {$domain} ({$elapsed}s)");
            } else {
                $totalFail++;
                $error = $result['error'] ?? 'Unknown error';
                $log("  FAIL: {$domain} — {$error}");
            }
        }

        // Sync SSL certs if enabled
        if ($config['sync_ssl_certs']) {
            $log("  Syncing SSL certificates...");
            $sslResult = \MuseDockPanel\Services\FileSyncService::syncSslCerts($node);
            if ($sslResult['ok']) {
                $log("  SSL certs synced OK");
            } else {
                $log("  SSL certs FAILED: " . ($sslResult['error'] ?? ''));
            }
        }
    }

    $log("Sync completed: {$totalOk} OK, {$totalFail} failed");

    // Log rotation (max 5 MB)
    $logFile = PANEL_ROOT . '/storage/logs/filesync-worker.log';
    if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
        $archive = $logFile . '.' . date('Y-m-d_His');
        rename($logFile, $archive);
    }

} catch (\Throwable $e) {
    $log('ERROR: ' . $e->getMessage());
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
    @unlink($lockFile);
}
