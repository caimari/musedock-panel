#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — Cluster Worker
 *
 * Runs as cron every minute. Performs:
 * 1. Process pending queue items
 * 2. Send heartbeat to all nodes
 * 3. Check for unreachable nodes and send alerts
 * 4. Log results
 *
 * Usage:
 *   php bin/cluster-worker.php
 *   (or via cron: * * * * * /usr/bin/php /opt/musedock-panel/bin/cluster-worker.php >> /opt/musedock-panel/storage/logs/cluster-worker.log 2>&1)
 */

define('PANEL_ROOT', dirname(__DIR__));
define('PANEL_VERSION', '0.4.0');

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

// Load .env and config
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');
$config = require PANEL_ROOT . '/config/panel.php';

// Lock file to prevent overlapping runs
$lockFile = PANEL_ROOT . '/storage/cluster-worker.lock';
$lockFp = fopen($lockFile, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Another instance is running
    fclose($lockFp);
    exit(0);
}

// Write PID
ftruncate($lockFp, 0);
fwrite($lockFp, (string)getmypid());

$startTime = microtime(true);
$logLines = [];

function logMsg(string $msg): void
{
    global $logLines;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $logLines[] = $line;
}

try {
    // Verify database connectivity
    \MuseDockPanel\Database::connect();
} catch (\Throwable $e) {
    logMsg("ERROR: Database connection failed: " . $e->getMessage());
    goto cleanup;
}

use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Settings;

// ─── Step 1: Process pending queue items ──────────────────────
logMsg("Processing queue...");
try {
    $queueResults = ClusterService::processQueue();
    $ok = 0;
    $fail = 0;
    foreach ($queueResults as $r) {
        if ($r['ok']) {
            $ok++;
            logMsg("  Queue item #{$r['id']} ({$r['action']}): OK");
        } else {
            $fail++;
            logMsg("  Queue item #{$r['id']} ({$r['action']}): FAILED - " . ($r['error'] ?? '?'));
        }
    }
    if (empty($queueResults)) {
        logMsg("  No pending items.");
    } else {
        logMsg("  Queue processed: {$ok} OK, {$fail} failed.");
    }
} catch (\Throwable $e) {
    logMsg("ERROR processing queue: " . $e->getMessage());
}

// ─── Step 2: Send heartbeat to all nodes ──────────────────────
logMsg("Sending heartbeats...");
try {
    $heartbeatResults = ClusterService::checkAllNodes();
    foreach ($heartbeatResults as $hb) {
        $icon = $hb['ok'] ? 'OK' : 'FAIL';
        logMsg("  Node #{$hb['id']} ({$hb['name']}): {$icon}" . ($hb['error'] ? " - {$hb['error']}" : ''));
    }
    if (empty($heartbeatResults)) {
        logMsg("  No nodes configured.");
    }
} catch (\Throwable $e) {
    logMsg("ERROR sending heartbeats: " . $e->getMessage());
}

// ─── Step 3: Check for unreachable nodes ──────────────────────
logMsg("Checking unreachable nodes...");
try {
    $timeoutMinutes = (int)(Settings::get('cluster_unreachable_timeout', '300')) / 60;
    if ($timeoutMinutes < 1) $timeoutMinutes = 5;

    $unreachable = ClusterService::getUnreachableNodes((int)$timeoutMinutes);
    if (!empty($unreachable)) {
        $names = array_map(fn($n) => $n['name'] . ' (' . $n['api_url'] . ')', $unreachable);
        $alertMsg = "Los siguientes nodos del cluster no responden:\n" . implode("\n", $names);

        logMsg("  Unreachable nodes: " . implode(', ', array_column($unreachable, 'name')));

        // Send alert
        ClusterService::sendAlert(
            '[MuseDock Cluster] Nodos inaccesibles',
            $alertMsg . "\n\nFecha: " . date('Y-m-d H:i:s')
        );

        LogService::log('cluster.alert', 'unreachable', 'Nodos inaccesibles: ' . implode(', ', array_column($unreachable, 'name')));
    } else {
        logMsg("  All nodes reachable.");
    }
} catch (\Throwable $e) {
    logMsg("ERROR checking unreachable: " . $e->getMessage());
}

// ─── Step 4: Slave — detect master down ──────────────────────
$clusterRole = Settings::get('cluster_role', 'standalone');
if ($clusterRole === 'slave') {
    logMsg("Checking master heartbeat (slave mode)...");
    try {
        $masterLastHb = Settings::get('cluster_master_last_heartbeat', '');
        $masterIp = Settings::get('cluster_master_ip', '');
        $timeoutSec = (int)Settings::get('cluster_unreachable_timeout', '300');

        if ($masterLastHb && $masterIp) {
            $age = time() - strtotime($masterLastHb);

            if ($age > $timeoutSec) {
                // Master is down — check if we already alerted recently (avoid spam)
                $lastAlert = Settings::get('cluster_master_down_alerted', '');
                $alertAge = $lastAlert ? (time() - strtotime($lastAlert)) : 99999;

                if ($alertAge > $timeoutSec) {
                    // Send alert via configured channels
                    $notifyEmail = Settings::get('cluster_slave_notify_email', '1') === '1';
                    $notifyTelegram = Settings::get('cluster_slave_notify_telegram', '1') === '1';

                    $subject = '[MuseDock Cluster] ALERTA: Master caido';
                    $body = "El servidor Master ({$masterIp}) no envia heartbeat desde hace " . round($age / 60) . " minutos.\n\n"
                          . "Ultimo heartbeat: {$masterLastHb}\n"
                          . "Timeout configurado: {$timeoutSec}s\n"
                          . "Servidor slave: " . gethostname() . "\n"
                          . "Fecha: " . date('Y-m-d H:i:s') . "\n\n"
                          . "Puede promover este servidor a Master desde:\n"
                          . "https://" . (\MuseDockPanel\Env::get('PANEL_DOMAIN', gethostname())) . ":8444/settings/cluster";

                    if ($notifyEmail || $notifyTelegram) {
                        if ($notifyEmail && $notifyTelegram) {
                            \MuseDockPanel\Services\NotificationService::send($subject, $body);
                        } elseif ($notifyEmail) {
                            \MuseDockPanel\Services\NotificationService::sendEmail($subject, $body);
                        } elseif ($notifyTelegram) {
                            \MuseDockPanel\Services\NotificationService::sendTelegram($subject . "\n\n" . $body);
                        }
                    }

                    Settings::set('cluster_master_down_alerted', date('Y-m-d H:i:s'));
                    LogService::log('cluster.alert', 'master-down', "Master {$masterIp} sin heartbeat desde hace " . round($age / 60) . " min");
                    logMsg("  ALERT: Master {$masterIp} down for " . round($age / 60) . " min. Notification sent.");

                    // Auto-failover if enabled
                    $autoFailover = Settings::get('cluster_auto_failover', '0') === '1';
                    if ($autoFailover) {
                        logMsg("  AUTO-FAILOVER: Promoting to master...");
                        $result = ClusterService::promoteToMaster();
                        if ($result['ok']) {
                            logMsg("  AUTO-FAILOVER: Success — this server is now Master.");
                            LogService::log('cluster.failover', 'auto-promote', "Auto-failover ejecutado: promovido a master");
                            \MuseDockPanel\Services\NotificationService::send(
                                '[MuseDock Cluster] Auto-Failover ejecutado',
                                "Este servidor ha sido promovido automaticamente a Master tras detectar que {$masterIp} no responde.\n\nFecha: " . date('Y-m-d H:i:s')
                            );
                        } else {
                            logMsg("  AUTO-FAILOVER: FAILED — " . implode(', ', $result['errors'] ?? []));
                            LogService::log('cluster.failover', 'auto-promote-failed', implode(', ', $result['errors'] ?? []));
                        }
                    }
                } else {
                    logMsg("  Master down (already alerted " . round($alertAge / 60) . " min ago).");
                }
            } else {
                // Master is alive — clear alert flag if set
                if (Settings::get('cluster_master_down_alerted', '')) {
                    Settings::set('cluster_master_down_alerted', '');
                }
                logMsg("  Master {$masterIp} alive (last heartbeat {$age}s ago).");
            }
        } else {
            logMsg("  No master heartbeat recorded yet.");
        }
    } catch (\Throwable $e) {
        logMsg("ERROR checking master: " . $e->getMessage());
    }
}

// ─── Step 5: Clean old completed items (once a day check) ─────
$hour = (int)date('H');
$minute = (int)date('i');
if ($hour === 3 && $minute < 2) {
    logMsg("Running daily cleanup...");
    try {
        $cleaned = ClusterService::cleanOldItems(30);
        logMsg("  Cleaned {$cleaned} old completed queue items.");
    } catch (\Throwable $e) {
        logMsg("ERROR cleaning: " . $e->getMessage());
    }
}

cleanup:

$elapsed = round((microtime(true) - $startTime) * 1000, 1);
logMsg("Done in {$elapsed}ms.");

// Write log
$logDir = PANEL_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/cluster-worker.log';

// Rotate if > 5MB
if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
    @rename($logFile, $logFile . '.' . date('Ymd-His'));
}

file_put_contents($logFile, implode("\n", $logLines) . "\n", FILE_APPEND | LOCK_EX);

// Release lock
flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink($lockFile);
