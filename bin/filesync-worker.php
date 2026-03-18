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

    $syncMode = $config['sync_mode'] ?? 'periodic';
    $log('File sync worker started (mode: ' . $syncMode . ', method: ' . $config['method'] . ')');

    // Get only active nodes (excludes standby/maintenance)
    $nodes = \MuseDockPanel\Services\ClusterService::getActiveNodes();
    if (empty($nodes)) {
        $log('No active nodes to sync (all may be in standby)');
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

        // File sync — skip when lsyncd handles it in real-time
        if ($syncMode === 'lsyncd') {
            $log("  File sync skipped — lsyncd handles real-time sync");
        } else {
            // Sync entire /var/www/vhosts/ in one rsync — mirrors everything, not just panel hostings
            $log("  Syncing /var/www/vhosts/ ...");
            $result = \MuseDockPanel\Services\FileSyncService::syncVhostsToNode($node);
            if ($result['ok']) {
                $totalOk++;
                $elapsed = $result['elapsed_seconds'] ?? 0;
                $log("  OK: /var/www/vhosts/ ({$elapsed}s)");
            } else {
                $totalFail++;
                $error = $result['error'] ?? 'Unknown error';
                $log("  FAIL: /var/www/vhosts/ — {$error}");
            }
        }

        // Update disk_used_mb on master and slave (always runs)
        $diskResult = \MuseDockPanel\Services\FileSyncService::updateRemoteDiskUsage($node, $accounts);
        if ($diskResult['ok']) {
            $log("  Disk usage updated: {$diskResult['updated']} accounts");
        } else {
            $log("  Disk usage update FAILED: " . ($diskResult['error'] ?? ''));
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

        // Sync database dumps if enabled (Nivel 1 — simple cluster sync)
        // Check streaming replication per engine — only skip dumps for engines with active streaming
        if ($config['db_dumps'] ?? false) {
            $streamingStatus = \MuseDockPanel\Services\ReplicationService::isStreamingActive();

            $skipPgsql = $streamingStatus['pg'] ?? false;
            $skipMysql = $streamingStatus['mysql'] ?? false;

            if ($skipPgsql && $skipMysql) {
                $log("  DB dumps skipped — streaming replication active for both engines");
            } else {
                // Temporarily disable dump for engines with active streaming
                $origDumpPgsql = $config['db_dump_pgsql'];
                $origDumpMysql = $config['db_dump_mysql'];
                if ($skipPgsql) {
                    \MuseDockPanel\Settings::set('filesync_db_dump_pgsql', '0');
                    $log("  PostgreSQL dumps skipped — streaming replication active");
                }
                if ($skipMysql) {
                    \MuseDockPanel\Settings::set('filesync_db_dump_mysql', '0');
                    $log("  MySQL dumps skipped — streaming replication active");
                }

                $log("  Dumping databases...");
                $dumpResults = \MuseDockPanel\Services\FileSyncService::dumpAllDatabases();
                $dumpOk = count(array_filter($dumpResults, fn($r) => $r['ok']));
                $log("  {$dumpOk}/" . count($dumpResults) . " databases dumped");

                $log("  Syncing database dumps to {$nodeName}...");
                $dbSyncResult = \MuseDockPanel\Services\FileSyncService::syncDatabaseDumps($node);
                if ($dbSyncResult['ok'] ?? false) {
                    $log("  Database dumps synced and restored OK");
                } else {
                    $log("  Database dumps FAILED: " . ($dbSyncResult['error'] ?? ''));
                }

                // Restore original settings
                if ($skipPgsql) {
                    \MuseDockPanel\Settings::set('filesync_db_dump_pgsql', $origDumpPgsql ? '1' : '0');
                }
                if ($skipMysql) {
                    \MuseDockPanel\Settings::set('filesync_db_dump_mysql', $origDumpMysql ? '1' : '0');
                }
            }
        }
    }

    if ($syncMode === 'lsyncd') {
        $log("Periodic tasks completed (lsyncd handles file sync)");
    } else {
        $log("Sync completed: {$totalOk} OK, {$totalFail} failed");
    }

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
