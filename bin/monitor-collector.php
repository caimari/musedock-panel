#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — Monitor Collector
 *
 * Reads network, CPU, and RAM metrics from the system and stores them
 * in the monitor_metrics table. Also runs aggregation and cleanup.
 *
 * Usage:
 *   php bin/monitor-collector.php
 *   (via cron every 30s)
 */

define('PANEL_ROOT', dirname(__DIR__));
define('PANEL_VERSION', '0.7.7');

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
$lockFile = PANEL_ROOT . '/storage/monitor-collector.lock';
$lockFp = fopen($lockFile, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    exit(0);
}
ftruncate($lockFp, 0);
fwrite($lockFp, (string)getmypid());

$startTime = microtime(true);
$logLines = [];

function logMsg(string $msg): void
{
    global $logLines;
    $logLines[] = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
}

try {
    \MuseDockPanel\Database::connect();
} catch (\Throwable $e) {
    logMsg("ERROR: Database connection failed: " . $e->getMessage());
    goto cleanup;
}

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

// Check if monitoring is enabled
if (Settings::get('monitor_enabled', '1') !== '1') {
    logMsg("Monitoring disabled. Exiting.");
    goto cleanup;
}

$hostname = gethostname() ?: 'localhost';
$interfaces = \MuseDockPanel\Services\MonitorService::getInterfaces();
$lastFile = '/tmp/musedock_monitor_last.json';
$last = file_exists($lastFile) ? json_decode(file_get_contents($lastFile), true) : [];
$now = microtime(true);
$elapsed = isset($last['_ts']) ? ($now - $last['_ts']) : 0;

$current = ['_ts' => $now];
$inserts = [];

// ─── Network metrics ─────────────────────────────────────────
foreach ($interfaces as $iface) {
    $rxFile = "/sys/class/net/{$iface}/statistics/rx_bytes";
    $txFile = "/sys/class/net/{$iface}/statistics/tx_bytes";

    if (!file_exists($rxFile) || !file_exists($txFile)) {
        logMsg("Interface {$iface} not found, skipping.");
        continue;
    }

    $rx = (int) trim(file_get_contents($rxFile));
    $tx = (int) trim(file_get_contents($txFile));

    if ($elapsed > 0 && isset($last["{$iface}_rx"])) {
        $rxDelta = $rx - $last["{$iface}_rx"];
        $txDelta = $tx - $last["{$iface}_tx"];

        // Handle counter reset (reboot)
        if ($rxDelta < 0) $rxDelta = $rx;
        if ($txDelta < 0) $txDelta = $tx;

        $rxRate = $rxDelta / $elapsed;
        $txRate = $txDelta / $elapsed;

        $inserts[] = [$hostname, "net_{$iface}_rx", $rxRate];
        $inserts[] = [$hostname, "net_{$iface}_tx", $txRate];

        logMsg("  {$iface}: RX=" . formatBytes($rxRate) . " TX=" . formatBytes($txRate));
    } else {
        logMsg("  {$iface}: first reading (no delta yet).");
    }

    $current["{$iface}_rx"] = $rx;
    $current["{$iface}_tx"] = $tx;
}

// ─── CPU metric ──────────────────────────────────────────────
$load = sys_getloadavg();
$cores = (int) trim(shell_exec('nproc') ?: '1');
$cpuPercent = min(100, round(($load[0] / $cores) * 100, 2));
$inserts[] = [$hostname, 'cpu_percent', $cpuPercent];
logMsg("  CPU: {$cpuPercent}% (load {$load[0]}, {$cores} cores)");

// ─── RAM metric ──────────────────────────────────────────────
$free = shell_exec('free -b');
preg_match('/Mem:\s+(\d+)\s+(\d+)/', $free, $m);
$totalMem = (int)($m[1] ?? 0);
$usedMem = (int)($m[2] ?? 0);
$ramPercent = $totalMem > 0 ? round(($usedMem / $totalMem) * 100, 2) : 0;
$inserts[] = [$hostname, 'ram_percent', $ramPercent];
logMsg("  RAM: {$ramPercent}%");

// ─── GPU metrics (NVIDIA only) ───────────────────────────
$gpuOutput = @shell_exec('nvidia-smi --query-gpu=index,utilization.gpu,utilization.memory,memory.used,memory.total,temperature.gpu,power.draw --format=csv,noheader,nounits 2>/dev/null');
if (!empty($gpuOutput)) {
    foreach (explode("\n", trim($gpuOutput)) as $gpuLine) {
        $gp = array_map('trim', explode(',', $gpuLine));
        if (count($gp) >= 7) {
            $gpuIdx = (int) $gp[0];
            $gpuUtil = (float) $gp[1];     // GPU utilization %
            $memUtil = (float) $gp[2];     // Memory utilization %
            $memUsed = (float) $gp[3];     // Memory used MiB
            $memTotal = (float) $gp[4];    // Memory total MiB
            $gpuTemp = (float) $gp[5];     // Temperature C
            $gpuPower = (float) $gp[6];    // Power draw W

            $inserts[] = [$hostname, "gpu{$gpuIdx}_util", $gpuUtil];
            $inserts[] = [$hostname, "gpu{$gpuIdx}_mem_percent", $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 2) : 0];
            $inserts[] = [$hostname, "gpu{$gpuIdx}_temp", $gpuTemp];
            $inserts[] = [$hostname, "gpu{$gpuIdx}_power", $gpuPower];

            logMsg("  GPU{$gpuIdx}: util={$gpuUtil}% mem={$memUsed}/{$memTotal}MiB temp={$gpuTemp}C power={$gpuPower}W");
        }
    }
} else {
    // No GPU or nvidia-smi not available — skip silently
}

// ─── Batch INSERT ────────────────────────────────────────────
if (!empty($inserts)) {
    $values = [];
    $params = [];
    $i = 0;
    foreach ($inserts as $row) {
        $values[] = "(NOW(), :host{$i}, :metric{$i}, :value{$i})";
        $params["host{$i}"] = $row[0];
        $params["metric{$i}"] = $row[1];
        $params["value{$i}"] = $row[2];
        $i++;
    }
    $sql = "INSERT INTO monitor_metrics (ts, host, metric, value) VALUES " . implode(', ', $values);
    Database::query($sql, $params);
    logMsg("Inserted {$i} metrics.");
}

// Save current readings for next delta calculation
file_put_contents($lastFile, json_encode($current));

// ─── Hourly aggregation ─────────────────────────────────────
try {
    Database::query("
        INSERT INTO monitor_metrics_hourly (ts, host, metric, avg_val, max_val, min_val, samples)
        SELECT date_trunc('hour', ts), host, metric,
               AVG(value), MAX(value), MIN(value), COUNT(*)
        FROM monitor_metrics
        WHERE ts < date_trunc('hour', NOW())
          AND ts >= date_trunc('hour', NOW()) - INTERVAL '2 hours'
        GROUP BY date_trunc('hour', ts), host, metric
        ON CONFLICT (ts, host, metric) DO UPDATE SET
            avg_val = EXCLUDED.avg_val,
            max_val = EXCLUDED.max_val,
            min_val = EXCLUDED.min_val,
            samples = EXCLUDED.samples
    ");
} catch (\Throwable $e) {
    logMsg("Hourly aggregation error: " . $e->getMessage());
}

// ─── Daily aggregation (once per hour at minute 0-1) ─────────
$minute = (int)date('i');
if ($minute < 2) {
    try {
        Database::query("
            INSERT INTO monitor_metrics_daily (ts, host, metric, avg_val, max_val, min_val, samples)
            SELECT ts::date, host, metric,
                   AVG(avg_val), MAX(max_val), MIN(min_val), SUM(samples)
            FROM monitor_metrics_hourly
            WHERE ts < date_trunc('day', NOW())
              AND ts >= date_trunc('day', NOW()) - INTERVAL '2 days'
            GROUP BY ts::date, host, metric
            ON CONFLICT (ts, host, metric) DO UPDATE SET
                avg_val = EXCLUDED.avg_val,
                max_val = EXCLUDED.max_val,
                min_val = EXCLUDED.min_val,
                samples = EXCLUDED.samples
        ");
        logMsg("Daily aggregation completed.");
    } catch (\Throwable $e) {
        logMsg("Daily aggregation error: " . $e->getMessage());
    }
}

// ─── Cleanup old data ────────────────────────────────────────
$retentionRawHours = (int) Settings::get('monitor_retention_raw_hours', '48');
$retentionHourlyDays = (int) Settings::get('monitor_retention_hourly_days', '90');

try {
    $deleted = Database::delete('monitor_metrics', "ts < NOW() - INTERVAL '{$retentionRawHours} hours'");
    if ($deleted > 0) logMsg("Cleaned {$deleted} raw rows (>{$retentionRawHours}h).");

    $deleted = Database::delete('monitor_metrics_hourly', "ts < NOW() - INTERVAL '{$retentionHourlyDays} days'");
    if ($deleted > 0) logMsg("Cleaned {$deleted} hourly rows (>{$retentionHourlyDays}d).");

    // Clean old acknowledged alerts (>30 days)
    $deleted = Database::delete('monitor_alerts', "acknowledged = true AND ts < NOW() - INTERVAL '30 days'");
    if ($deleted > 0) logMsg("Cleaned {$deleted} old alerts.");
} catch (\Throwable $e) {
    logMsg("Cleanup error: " . $e->getMessage());
}

// ─── Alert checks ────────────────────────────────────────────
$alertCpuThreshold = (float) Settings::get('monitor_alert_cpu', '90');
$alertRamThreshold = (float) Settings::get('monitor_alert_ram', '90');
$alertNetMbps = (float) Settings::get('monitor_alert_net_mbps', '800');
$alertNetBps = $alertNetMbps * 1000000 / 8; // Convert Mbps to bytes/sec

/**
 * Get top processes by resource usage for alert context
 */
function getTopProcesses(string $type, int $limit = 5): string
{
    $lines = [];
    try {
        if ($type === 'CPU_HIGH') {
            $output = shell_exec("ps aux --sort=-%cpu 2>/dev/null | head -" . ($limit + 1));
        } elseif ($type === 'RAM_HIGH') {
            $output = shell_exec("ps aux --sort=-%mem 2>/dev/null | head -" . ($limit + 1));
        } elseif (str_starts_with($type, 'GPU')) {
            $output = @shell_exec('nvidia-smi --query-compute-apps=pid,process_name,used_gpu_memory --format=csv,noheader,nounits 2>/dev/null');
            if (!empty($output)) {
                $lines[] = "GPU Processes:";
                foreach (explode("\n", trim($output)) as $i => $line) {
                    if ($i >= $limit) break;
                    $parts = array_map('trim', explode(',', $line));
                    if (count($parts) >= 3) {
                        $lines[] = "  PID {$parts[0]} — {$parts[1]} ({$parts[2]} MiB)";
                    }
                }
                return implode("\n", $lines);
            }
            return "No GPU processes found.";
        } else {
            return '';
        }

        if (!empty($output)) {
            $rows = explode("\n", trim($output));
            $header = array_shift($rows); // Remove header
            $lines[] = "Top {$limit} processes by " . ($type === 'CPU_HIGH' ? 'CPU' : 'RAM') . ":";
            foreach ($rows as $row) {
                $cols = preg_split('/\s+/', trim($row), 11);
                if (count($cols) >= 11) {
                    $user = $cols[0];
                    $pid  = $cols[1];
                    $cpu  = $cols[2];
                    $mem  = $cols[3];
                    $cmd  = $cols[10];
                    // Truncate command to 60 chars
                    if (strlen($cmd) > 60) $cmd = substr($cmd, 0, 57) . '...';
                    $lines[] = "  PID {$pid} ({$user}) CPU:{$cpu}% MEM:{$mem}% — {$cmd}";
                }
            }
        }
    } catch (\Throwable $e) {
        $lines[] = "(Could not retrieve processes: {$e->getMessage()})";
    }

    return !empty($lines) ? implode("\n", $lines) : '';
}

function checkAlert(string $host, string $type, string $message, float $value): void
{
    // Anti-spam: max 1 alert per type per 5 minutes
    $recent = Database::fetchOne(
        "SELECT id FROM monitor_alerts WHERE host = :host AND type = :type AND ts > NOW() - INTERVAL '5 minutes'",
        ['host' => $host, 'type' => $type]
    );
    if ($recent) return;

    // Capture top processes for context
    $processInfo = getTopProcesses($type);

    Database::insert('monitor_alerts', [
        'host'    => $host,
        'type'    => $type,
        'message' => $message,
        'value'   => $value,
    ]);

    // Send notification with process details
    try {
        $body = "{$message}\nHost: {$host}\nValue: {$value}\nTime: " . date('Y-m-d H:i:s');
        if (!empty($processInfo)) {
            $body .= "\n\n{$processInfo}";
        }
        \MuseDockPanel\Services\NotificationService::send(
            "[MuseDock Monitor] {$type}",
            $body
        );
    } catch (\Throwable $e) {
        logMsg("Notification error: " . $e->getMessage());
    }

    logMsg("ALERT: {$type} - {$message} ({$value})");
}

// Threshold = 0 means disabled
if ($alertCpuThreshold > 0 && $cpuPercent > $alertCpuThreshold) {
    checkAlert($hostname, 'CPU_HIGH', "CPU usage at {$cpuPercent}% (threshold: {$alertCpuThreshold}%)", $cpuPercent);
}
if ($alertRamThreshold > 0 && $ramPercent > $alertRamThreshold) {
    checkAlert($hostname, 'RAM_HIGH', "RAM usage at {$ramPercent}% (threshold: {$alertRamThreshold}%)", $ramPercent);
}

// Check network alerts for each interface (0 = disabled)
if ($alertNetBps > 0) {
    foreach ($inserts as $row) {
        if (str_contains($row[1], '_rx') && $row[2] > $alertNetBps) {
            $iface = str_replace(['net_', '_rx'], '', $row[1]);
            checkAlert($hostname, 'NET_HIGH', "High inbound traffic on {$iface}: " . formatBytes($row[2]), $row[2]);
        }
    }
}

// Check GPU alerts (0 = disabled)
$alertGpuTemp = (float) Settings::get('monitor_alert_gpu_temp', '85');
$alertGpuUtil = (float) Settings::get('monitor_alert_gpu_util', '95');
foreach ($inserts as $row) {
    if ($alertGpuTemp > 0 && preg_match('/^gpu(\d+)_temp$/', $row[1], $gm) && $row[2] > $alertGpuTemp) {
        checkAlert($hostname, 'GPU_TEMP', "GPU{$gm[1]} temperature at {$row[2]}°C (threshold: {$alertGpuTemp}°C)", $row[2]);
    }
    if ($alertGpuUtil > 0 && preg_match('/^gpu(\d+)_util$/', $row[1], $gm) && $row[2] > $alertGpuUtil) {
        checkAlert($hostname, 'GPU_HIGH', "GPU{$gm[1]} utilization at {$row[2]}% (threshold: {$alertGpuUtil}%)", $row[2]);
    }
}

cleanup:

$elapsed = round((microtime(true) - $startTime) * 1000, 1);
logMsg("Done in {$elapsed}ms.");

// Write log
$logDir = PANEL_ROOT . '/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/monitor-collector.log';

// Rotate if > 5MB
if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
    @rename($logFile, $logFile . '.' . date('Ymd-His'));
}

file_put_contents($logFile, implode("\n", $logLines) . "\n", FILE_APPEND | LOCK_EX);

// Release lock
flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink($lockFile);

// ─── Helper function ──────────────────────────────────────────
function formatBytes(float $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB/s';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB/s';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB/s';
    return round($bytes, 0) . ' B/s';
}
