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
define('PANEL_VERSION', '1.0.4');

// ─── Early CPU sample (before ANY work, captures real system state) ──
$cpuStat1 = @file_get_contents('/proc/stat');
usleep(500000); // 500ms measurement window
$cpuStat2 = @file_get_contents('/proc/stat');

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
$lastFile = PANEL_ROOT . '/storage/.monitor_last.json';
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

// ─── CPU metric (from early sample taken before any work) ───
$cores = (int) trim(shell_exec('nproc') ?: '1');
$load = sys_getloadavg();
$cpuPercent = min(100, round(($load[0] / $cores) * 100, 2)); // fallback
if ($cpuStat1 && $cpuStat2
    && preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $cpuStat1, $m1)
    && preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $cpuStat2, $m2)) {
    $idle1 = (int)$m1[4] + (int)$m1[5];
    $idle2 = (int)$m2[4] + (int)$m2[5];
    $total1 = array_sum(array_slice($m1, 1));
    $total2 = array_sum(array_slice($m2, 1));
    $totalDelta = $total2 - $total1;
    $idleDelta = $idle2 - $idle1;
    if ($totalDelta > 0) {
        $cpuPercent = round((1 - $idleDelta / $totalDelta) * 100, 2);
    }
}
$inserts[] = [$hostname, 'cpu_percent', $cpuPercent];
logMsg("  CPU: {$cpuPercent}% (load {$load[0]}, {$cores} cores)");

// ─── RAM metric (MemAvailable = truly free for apps) ─────────
$meminfo = @file_get_contents('/proc/meminfo');
$totalMem = 0;
$availMem = 0;
if ($meminfo) {
    if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $mt)) $totalMem = (int)$mt[1] * 1024;
    if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $ma)) $availMem = (int)$ma[1] * 1024;
}
$usedMem = $totalMem - $availMem;
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

// ─── Disk metrics ────────────────────────────────────────────
$diskData = [];
$dfOutput = shell_exec("df -B1 --output=source,fstype,size,used,avail,pcent,target 2>/dev/null");
if (!empty($dfOutput)) {
    $dfLines = explode("\n", trim($dfOutput));
    array_shift($dfLines); // Remove header
    $skipFs = ['tmpfs', 'devtmpfs', 'efivarfs', 'squashfs', 'overlay', 'fuse.snapfuse', 'fuse.lxcfs'];
    foreach ($dfLines as $dfLine) {
        $cols = preg_split('/\s+/', trim($dfLine), 7);
        if (count($cols) < 7) continue;
        [$device, $fstype, $size, $used, $avail, $pct, $mount] = $cols;
        // Only real block devices: /dev/sdX, /dev/nvmeX, /dev/vdX, /dev/mdX, /dev/mapper/*
        if (!str_starts_with($device, '/dev/')) continue;
        if (in_array($fstype, $skipFs, true)) continue;
        // Skip tiny partitions (< 500MB) like /boot/efi
        if ((int)$size < 500 * 1024 * 1024) continue;

        $usedPct = (float) str_replace('%', '', $pct);
        $sizeBytes = (int) $size;
        $usedBytes = (int) $used;

        // Create a safe metric name from mount point
        $metricMount = ($mount === '/') ? 'root' : trim(str_replace('/', '_', $mount), '_');
        $inserts[] = [$hostname, "disk_{$metricMount}_percent", $usedPct];

        $diskData[] = [
            'device'  => $device,
            'mount'   => $mount,
            'size'    => $sizeBytes,
            'used'    => $usedBytes,
            'percent' => $usedPct,
            'metric'  => "disk_{$metricMount}_percent",
            'metricMount' => $metricMount,
        ];

        logMsg("  Disk {$device} ({$mount}): {$usedPct}%");
    }
}

// ─── Disk I/O metrics (read/write throughput) ────────────────
// Uses /proc/diskstats: field 6 = sectors read, field 10 = sectors written
// Sector size = 512 bytes
if (!empty($diskData)) {
    $diskstats = @file_get_contents('/proc/diskstats');
    if (!empty($diskstats)) {
        foreach ($diskData as $d) {
            // Extract base device name (sda1 from /dev/sda1, nvme0n1p2 from /dev/nvme0n1p2)
            $devBase = basename($d['device']);
            // Match the device in /proc/diskstats
            if (preg_match('/\s+\d+\s+\d+\s+' . preg_quote($devBase, '/') . '\s+(.+)/', $diskstats, $dsm)) {
                $fields = preg_split('/\s+/', trim($dsm[1]));
                // fields[2] = sectors read (index 2 in remaining), fields[6] = sectors written
                if (count($fields) >= 7) {
                    $sectorsRead = (int)$fields[2];
                    $sectorsWritten = (int)$fields[6];
                    $readBytes = $sectorsRead * 512;
                    $writeBytes = $sectorsWritten * 512;

                    $lastKeyR = "diskio_{$devBase}_read";
                    $lastKeyW = "diskio_{$devBase}_write";

                    if ($elapsed > 0 && isset($last[$lastKeyR])) {
                        $readDelta = $readBytes - $last[$lastKeyR];
                        $writeDelta = $writeBytes - $last[$lastKeyW];
                        if ($readDelta < 0) $readDelta = $readBytes;
                        if ($writeDelta < 0) $writeDelta = $writeBytes;

                        $readRate = $readDelta / $elapsed;
                        $writeRate = $writeDelta / $elapsed;

                        $mm = $d['metricMount'];
                        $inserts[] = [$hostname, "disk_{$mm}_read", $readRate];
                        $inserts[] = [$hostname, "disk_{$mm}_write", $writeRate];

                        logMsg("  Disk I/O {$devBase}: R=" . formatBytes($readRate) . " W=" . formatBytes($writeRate));
                    }

                    $current[$lastKeyR] = $readBytes;
                    $current[$lastKeyW] = $writeBytes;
                }
            }
        }
    }
}

// Save current readings for next delta calculation BEFORE DB operations
// This ensures we always have fresh counters even if DB fails
file_put_contents($lastFile, json_encode($current));

// ─── Bandwidth metric (parse new log lines since last run) ──
try {
    $bwLogFile = '/var/log/caddy/hosting-access.log';
    $bwOffsetFile = '/tmp/musedock-bw-monitor-offset';
    $bwOffset = file_exists($bwOffsetFile) ? (int)file_get_contents($bwOffsetFile) : 0;

    if (file_exists($bwLogFile) && is_readable($bwLogFile)) {
        $bwFileSize = filesize($bwLogFile);
        if ($bwFileSize < $bwOffset) $bwOffset = 0; // rotated

        if ($bwFileSize > $bwOffset) {
            $bwFh = fopen($bwLogFile, 'r');
            if ($bwFh) {
                fseek($bwFh, $bwOffset);
                $bwTotalBytes = 0;
                $bwTotalReqs = 0;
                while (($bwLine = fgets($bwFh)) !== false) {
                    $bwEntry = @json_decode(trim($bwLine), true);
                    if ($bwEntry && isset($bwEntry['size'])) {
                        $bwTotalBytes += (int)$bwEntry['size'];
                        $bwTotalReqs++;
                    }
                }
                $bwNewOffset = ftell($bwFh);
                fclose($bwFh);
                file_put_contents($bwOffsetFile, $bwNewOffset);

                // Convert to rate (bytes/sec) based on elapsed time
                if ($elapsed > 0) {
                    $inserts[] = [$hostname, 'bw_bytes_sec', round($bwTotalBytes / $elapsed)];
                    $inserts[] = [$hostname, 'bw_requests_sec', round($bwTotalReqs / $elapsed, 2)];
                    logMsg("  BW: " . formatBytes($bwTotalBytes / $elapsed) . "/s ({$bwTotalReqs} reqs in {$elapsed}s)");
                }
            }
        }
    }
} catch (\Throwable $e) {
    logMsg("  BW error: " . $e->getMessage());
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

// ─── Hourly aggregation ─────────────────────────────────────
try {
    Database::query("
        INSERT INTO monitor_metrics_hourly (ts, host, metric, avg_val, p95_val, max_val, min_val, samples)
        SELECT date_trunc('hour', ts), host, metric,
               AVG(value),
               PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY value),
               MAX(value), MIN(value), COUNT(*)
        FROM monitor_metrics
        WHERE ts < date_trunc('hour', NOW())
          AND ts >= date_trunc('hour', NOW()) - INTERVAL '2 hours'
        GROUP BY date_trunc('hour', ts), host, metric
        ON CONFLICT (ts, host, metric) DO UPDATE SET
            avg_val = EXCLUDED.avg_val,
            p95_val = EXCLUDED.p95_val,
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
            INSERT INTO monitor_metrics_daily (ts, host, metric, avg_val, p95_val, max_val, min_val, samples)
            SELECT ts::date, host, metric,
                   AVG(avg_val),
                   PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY COALESCE(p95_val, avg_val)),
                   MAX(max_val), MIN(min_val), SUM(samples)
            FROM monitor_metrics_hourly
            WHERE ts < date_trunc('day', NOW())
              AND ts >= date_trunc('day', NOW()) - INTERVAL '2 days'
            GROUP BY ts::date, host, metric
            ON CONFLICT (ts, host, metric) DO UPDATE SET
                avg_val = EXCLUDED.avg_val,
                p95_val = EXCLUDED.p95_val,
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
$alertDiskThreshold = (float) Settings::get('monitor_alert_disk', '90');
$alertNetBps = $alertNetMbps * 1000000 / 8; // Convert Mbps to bytes/sec
$alertNoiseLevel = strtolower((string) Settings::get('monitor_alert_noise_level', 'normal'));

function resolveAlertCooldownSeconds(string $noiseLevel): int
{
    return match ($noiseLevel) {
        'high' => 120, // more sensitive, more alerts
        'low' => 900,  // less sensitive, less noise
        default => 300,
    };
}

$alertCooldownSeconds = resolveAlertCooldownSeconds($alertNoiseLevel);
logMsg("Alert anti-spam: {$alertNoiseLevel} ({$alertCooldownSeconds}s por tipo)");

/**
 * Get top processes by resource usage for alert context
 */
function getTopProcesses(string $type, int $limit = 5): string
{
    $lines = [];
    try {
        if ($type === 'CPU_HIGH') {
            // ww = unlimited width, shows full command line
            $output = shell_exec("ps aux ww --sort=-%cpu 2>/dev/null | head -" . ($limit + 1));
        } elseif ($type === 'RAM_HIGH') {
            $output = shell_exec("ps aux ww --sort=-%mem 2>/dev/null | head -" . ($limit + 1));
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
        } elseif ($type === 'NET_HIGH') {
            // Network alert: show top connections and processes using network
            return getNetworkSnapshot($limit);
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
                    $cmd  = $cols[10]; // Full command line (ww = no truncation)
                    $lines[] = "  PID {$pid} ({$user}) CPU:{$cpu}% MEM:{$mem}% — {$cmd}";
                }
            }
        }
    } catch (\Throwable $e) {
        $lines[] = "(Could not retrieve processes: {$e->getMessage()})";
    }

    return !empty($lines) ? implode("\n", $lines) : '';
}

/**
 * Capture network snapshot: active connections, processes, and associated domains
 */
function getNetworkSnapshot(int $limit = 10): string
{
    $lines = [];

    // 1. Get active TCP connections with process info
    //    ss -tnp shows: State, Recv-Q, Send-Q, Local Address:Port, Peer Address:Port, Process
    $ssOutput = shell_exec("ss -tnp 2>/dev/null");
    if (!empty($ssOutput)) {
        $connections = [];
        $ssRows = explode("\n", trim($ssOutput));
        array_shift($ssRows); // Remove header

        foreach ($ssRows as $ssRow) {
            $cols = preg_split('/\s+/', trim($ssRow));
            if (count($cols) < 5) continue;

            $state = $cols[0];
            $recvQ = (int)$cols[1];
            $sendQ = (int)$cols[2];
            $local = $cols[3];
            $peer  = $cols[4];
            $proc  = isset($cols[5]) ? $cols[5] : '';

            // Extract process name and PID from users:(("caddy",pid=1234,fd=5))
            $procName = '-';
            $pid = '-';
            if (preg_match('/\("([^"]+)",pid=(\d+)/', $proc, $pm)) {
                $procName = $pm[1];
                $pid = $pm[2];
            }

            // Extract peer IP (without port)
            $peerIp = preg_replace('/:\d+$/', '', $peer);
            // For IPv6-mapped IPv4, extract the IPv4 part
            $peerIp = preg_replace('/^::ffff:/', '', $peerIp);

            // Queue sizes indicate data in flight
            $queueTotal = $recvQ + $sendQ;

            $connections[] = [
                'state'    => $state,
                'recvQ'    => $recvQ,
                'sendQ'    => $sendQ,
                'queue'    => $queueTotal,
                'local'    => $local,
                'peer'     => $peer,
                'peerIp'   => $peerIp,
                'process'  => $procName,
                'pid'      => $pid,
            ];
        }

        // Sort by queue size (most active data transfer first)
        usort($connections, fn($a, $b) => $b['queue'] <=> $a['queue']);

        // Count connections per process
        $procCounts = [];
        foreach ($connections as $c) {
            $key = $c['process'];
            $procCounts[$key] = ($procCounts[$key] ?? 0) + 1;
        }
        arsort($procCounts);

        $lines[] = "Connections by process:";
        $i = 0;
        foreach ($procCounts as $proc => $count) {
            if ($i++ >= 8) break;
            $lines[] = "  {$proc}: {$count} connections";
        }

        // Show top connections with data in queues
        $topConns = array_filter($connections, fn($c) => $c['queue'] > 0);
        if (!empty($topConns)) {
            $lines[] = "";
            $lines[] = "Active data transfers (by queue):";
            $shown = 0;
            foreach ($topConns as $c) {
                if ($shown++ >= $limit) break;
                $recvH = $c['recvQ'] > 0 ? formatDiskSize($c['recvQ']) : '0';
                $sendH = $c['sendQ'] > 0 ? formatDiskSize($c['sendQ']) : '0';
                $lines[] = "  {$c['process']} (PID {$c['pid']}) {$c['local']} → {$c['peer']} recv={$recvH} send={$sendH}";
            }
        }

        // Show top connections (by volume, even without queue)
        $lines[] = "";
        $lines[] = "Top {$limit} connections:";
        $shown = 0;
        foreach ($connections as $c) {
            if ($shown++ >= $limit) break;
            $lines[] = "  {$c['process']} (PID {$c['pid']}) {$c['state']} {$c['local']} → {$c['peer']}";
        }
    }

    // 2. Try to identify domains from peer IPs using Caddy/panel vhosts
    //    Match peer IPs to hosted domains
    $peerIps = array_unique(array_column($connections ?? [], 'peerIp'));
    if (!empty($peerIps)) {
        // Get all hosted domains with their IPs (reverse DNS or panel data)
        $domainMap = identifyDomainsFromConnections($peerIps);
        if (!empty($domainMap)) {
            $lines[] = "";
            $lines[] = "Identified domains/hosts:";
            foreach ($domainMap as $ip => $info) {
                $lines[] = "  {$ip} → {$info}";
            }
        }
    }

    // 3. Top processes by network file descriptors (socket count)
    $netProcs = shell_exec("ss -tnp 2>/dev/null | grep -oP 'pid=\K\d+' | sort | uniq -c | sort -rn | head -5 2>/dev/null");
    if (!empty($netProcs)) {
        $lines[] = "";
        $lines[] = "Top processes by socket count:";
        foreach (explode("\n", trim($netProcs)) as $np) {
            $np = trim($np);
            if (preg_match('/^(\d+)\s+(\d+)$/', $np, $npm)) {
                $sockCount = $npm[1];
                $npid = $npm[2];
                // Get process command
                $cmdline = @file_get_contents("/proc/{$npid}/cmdline");
                $cmd = $cmdline ? str_replace("\0", ' ', trim($cmdline)) : '(unknown)';
                $lines[] = "  PID {$npid}: {$sockCount} sockets — {$cmd}";
            }
        }
    }

    return !empty($lines) ? implode("\n", $lines) : 'No network data available.';
}

/**
 * Try to identify domains from peer IPs via reverse DNS and panel data
 */
function identifyDomainsFromConnections(array $ips): array
{
    $result = [];
    $checked = 0;

    foreach ($ips as $ip) {
        if ($checked >= 20) break; // Limit to avoid slowdown
        if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') continue;

        // Try reverse DNS (with short timeout)
        $hostname = @gethostbyaddr($ip);
        if ($hostname && $hostname !== $ip) {
            $result[$ip] = $hostname;
        }
        $checked++;
    }

    // Also check if any IPs match hosted accounts in the panel
    try {
        $accounts = Database::fetchAll(
            "SELECT domain, ip FROM hosting_accounts WHERE ip IS NOT NULL AND ip != '' AND suspended = false LIMIT 200"
        );
        $domainByIp = [];
        foreach ($accounts as $acc) {
            $domainByIp[$acc['ip']][] = $acc['domain'];
        }
        foreach ($ips as $ip) {
            if (isset($domainByIp[$ip])) {
                $domains = implode(', ', array_slice($domainByIp[$ip], 0, 3));
                $result[$ip] = ($result[$ip] ?? $ip) . " [hosted: {$domains}]";
            }
        }
    } catch (\Throwable $e) {
        // Skip if table doesn't exist or other error
    }

    return $result;
}

/**
 * Get disk usage summary for alert context
 */
function getDiskInfo(): string
{
    global $diskData;
    if (empty($diskData)) return '';
    $lines = ["Disk usage:"];
    foreach ($diskData as $d) {
        $sizeH = formatDiskSize($d['size']);
        $usedH = formatDiskSize($d['used']);
        $freeH = formatDiskSize($d['size'] - $d['used']);
        $lines[] = "  {$d['device']} ({$d['mount']}): {$d['percent']}% used — {$usedH}/{$sizeH} (free: {$freeH})";
    }
    return implode("\n", $lines);
}

function formatDiskSize(float $bytes): string
{
    if ($bytes >= 1099511627776) return round($bytes / 1099511627776, 1) . 'T';
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'G';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'M';
    return round($bytes / 1024, 1) . 'K';
}

function checkAlert(string $host, string $type, string $message, float $value): void
{
    global $alertCooldownSeconds;
    $cooldownSeconds = max(60, min(3600, (int)$alertCooldownSeconds));

    // Anti-spam: max 1 alert per type per configured cooldown
    $recent = Database::fetchOne(
        "SELECT id FROM monitor_alerts
         WHERE host = :host
           AND type = :type
           AND ts > NOW() - (CAST(:cooldown_seconds AS integer) * INTERVAL '1 second')",
        ['host' => $host, 'type' => $type, 'cooldown_seconds' => $cooldownSeconds]
    );
    if ($recent) return;

    // Capture top processes + disk info for context
    $processInfo = getTopProcesses($type);
    $diskInfo = getDiskInfo();
    $details = trim($processInfo . ($processInfo && $diskInfo ? "\n\n" : '') . $diskInfo);

    Database::insert('monitor_alerts', [
        'host'    => $host,
        'type'    => $type,
        'message' => $message,
        'value'   => $value,
        'details' => !empty($details) ? $details : null,
    ]);

    // Send notification with process + disk details
    try {
        $body = "{$message}\nHost: {$host}\nValue: {$value}\nTime: " . date('Y-m-d H:i:s');
        if (!empty($details)) {
            $body .= "\n\n{$details}";
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

function shortHash(string $value): string
{
    $value = trim($value);
    if ($value === '') return 'none';
    return substr($value, 0, 12);
}

function insertEventAlert(string $host, string $type, string $message, string $details = '', float $value = 1.0, int $cooldownSeconds = 300): bool
{
    $cooldownSeconds = max(60, min(86400, $cooldownSeconds));
    $recent = Database::fetchOne(
        "SELECT id FROM monitor_alerts
         WHERE host = :host
           AND type = :type
           AND ts > NOW() - (CAST(:cooldown_seconds AS integer) * INTERVAL '1 second')
         ORDER BY ts DESC
         LIMIT 1",
        ['host' => $host, 'type' => $type, 'cooldown_seconds' => $cooldownSeconds]
    );
    if ($recent) {
        return false;
    }

    Database::insert('monitor_alerts', [
        'host'    => $host,
        'type'    => $type,
        'message' => $message,
        'value'   => $value,
        'details' => $details !== '' ? $details : null,
    ]);

    return true;
}

function normalizeFirewallDump(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $lines = preg_split('/\r?\n/', $raw) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = rtrim((string)$line);
        if ($line === '') {
            continue;
        }

        // iptables-save noise that changes on every run but not rules.
        if (preg_match('/^#\s*Generated by (?:ip|ip6)tables-save\b/i', $line)) {
            continue;
        }
        if (preg_match('/^#\s*Completed on\b/i', $line)) {
            continue;
        }

        // Normalize chain packet/byte counters: :INPUT DROP [123:456] -> :INPUT DROP [0:0]
        if (preg_match('/^:([A-Za-z0-9_-]+)\s+([A-Z-]+)\s+\[[0-9]+:[0-9]+\]$/', $line, $m)) {
            $line = ':' . $m[1] . ' ' . $m[2] . ' [0:0]';
        }

        $out[] = $line;
    }

    return trim(implode("\n", $out));
}

function stripFail2BanDynamicRules(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $lines = preg_split('/\r?\n/', $raw) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = rtrim((string)$line);
        if ($line === '') {
            continue;
        }

        // Dynamic per-IP bans added/removed by fail2ban inside f2b-* chains.
        // Keep static chain scaffolding (RETURN/jumps), ignore only source-specific entries.
        if (preg_match('/^-A\s+f2b-[A-Za-z0-9_.:-]+\s+-s\s+\S+\s+-j\s+\S+/i', $line)) {
            continue;
        }

        $out[] = $line;
    }

    return trim(implode("\n", $out));
}

function detectFirewallSnapshot(): array
{
    $type = \MuseDockPanel\Services\FirewallService::detectType();
    \MuseDockPanel\Services\FirewallService::resetTypeCache();
    $active = \MuseDockPanel\Services\FirewallService::isActive();

    $policy = 'unknown';
    if ($type === 'ufw') {
        $policy = (string)\MuseDockPanel\Services\FirewallService::ufwGetDefault();
    } elseif ($type === 'iptables') {
        $policy = (string)\MuseDockPanel\Services\FirewallService::iptablesGetPolicy();
    }

    $v4Raw = trim((string)shell_exec('iptables-save 2>/dev/null'));
    if ($v4Raw === '' && $type === 'ufw') {
        $v4Raw = trim((string)shell_exec('ufw status verbose 2>/dev/null'));
    }
    $v6Raw = trim((string)shell_exec('ip6tables-save 2>/dev/null'));
    $v4Norm = normalizeFirewallDump($v4Raw);
    $v6Norm = normalizeFirewallDump($v6Raw);
    $v4NormStored = strlen($v4Norm) > 120000
        ? (substr($v4Norm, 0, 120000) . "\n# ... truncated by monitor collector")
        : $v4Norm;
    $v6NormStored = strlen($v6Norm) > 120000
        ? (substr($v6Norm, 0, 120000) . "\n# ... truncated by monitor collector")
        : $v6Norm;

    $v4Hash = hash('sha256', $v4Norm);
    $v6Hash = hash('sha256', $v6Norm);
    $fingerprint = hash('sha256', json_encode([
        'type'   => $type,
        'active' => $active ? 1 : 0,
        'policy' => $policy,
        'v4'     => $v4Hash,
        'v6'     => $v6Hash,
    ], JSON_UNESCAPED_SLASHES));

    return [
        'type'        => $type,
        'active'      => $active,
        'policy'      => $policy,
        'v4_hash'     => $v4Hash,
        'v6_hash'     => $v6Hash,
        'v4_norm_b64' => base64_encode($v4NormStored),
        'v6_norm_b64' => base64_encode($v6NormStored),
        'norm_ver'    => 2,
        'fingerprint' => $fingerprint,
    ];
}

function recentPanelFirewallAction(int $windowSeconds = 180): ?array
{
    $windowSeconds = max(30, min(3600, $windowSeconds));
    try {
        $row = Database::fetchOne(
            "SELECT action, target, details, created_at
             FROM panel_log
             WHERE action LIKE 'firewall.%'
               AND created_at > NOW() - (CAST(:seconds AS integer) * INTERVAL '1 second')
             ORDER BY created_at DESC
             LIMIT 1",
            ['seconds' => $windowSeconds]
        );
        return is_array($row) && !empty($row) ? $row : null;
    } catch (\Throwable) {
        return null;
    }
}

function recentPanelConfigAction(int $windowSeconds = 240): ?array
{
    $windowSeconds = max(30, min(3600, $windowSeconds));
    try {
        $row = Database::fetchOne(
            "SELECT action, target, details, created_at
             FROM panel_log
             WHERE (
                    action LIKE 'settings.security.%'
                 OR action LIKE 'fail2ban.%'
                 OR action LIKE 'settings.fail2ban.%'
                 OR action LIKE 'updates.%'
             )
               AND created_at > NOW() - (CAST(:seconds AS integer) * INTERVAL '1 second')
             ORDER BY created_at DESC
             LIMIT 1",
            ['seconds' => $windowSeconds]
        );
        return is_array($row) && !empty($row) ? $row : null;
    } catch (\Throwable) {
        return null;
    }
}

function checkFirewallIntegrityWatch(string $host): void
{
    if (Settings::get('firewall_change_watch_enabled', '0') !== '1') {
        return;
    }

    $interval = (int)Settings::get('firewall_change_watch_interval_seconds', '60');
    $interval = max(30, min(3600, $interval));

    $cacheDir = PANEL_ROOT . '/storage/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $stateFile = $cacheDir . '/firewall-watch-state.json';
    $now = time();

    $state = [];
    if (is_file($stateFile)) {
        $decoded = json_decode((string)@file_get_contents($stateFile), true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

    $lastChecked = (int)($state['last_checked_at'] ?? 0);
    if ($lastChecked > 0 && ($now - $lastChecked) < $interval) {
        return;
    }

    $snapshot = detectFirewallSnapshot();
    $prev = is_array($state['snapshot'] ?? null) ? $state['snapshot'] : [];
    $prevFp = (string)($prev['fingerprint'] ?? '');
    $newFp = (string)($snapshot['fingerprint'] ?? '');
    $prevNormVer = (int)($prev['norm_ver'] ?? 1);
    $currNormVer = (int)($snapshot['norm_ver'] ?? 2);

    // One-time migration guard: when snapshot normalization logic changes,
    // re-baseline silently to avoid false positives.
    if ($prevFp !== '' && $prevNormVer !== $currNormVer) {
        logMsg('Firewall watch: snapshot normalization upgraded, baseline refreshed without alert.');
        $state['snapshot'] = $snapshot;
        $state['last_checked_at'] = $now;
        @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return;
    }

    if ($prevFp !== '' && $newFp !== '' && $prevFp !== $newFp) {
        $panelAction = recentPanelFirewallAction(180);
        $source = $panelAction ? 'panel' : 'externo';

        if ($source === 'panel') {
            logMsg('Firewall watch: cambio detectado (origen panel), notificacion externa omitida.');
        } else {
            $prevV4Raw = base64_decode((string)($prev['v4_norm_b64'] ?? $prev['v4_raw_b64'] ?? ''), true);
            $currV4Raw = base64_decode((string)($snapshot['v4_norm_b64'] ?? $snapshot['v4_raw_b64'] ?? ''), true);
            $prevV6Raw = base64_decode((string)($prev['v6_norm_b64'] ?? $prev['v6_raw_b64'] ?? ''), true);
            $currV6Raw = base64_decode((string)($snapshot['v6_norm_b64'] ?? $snapshot['v6_raw_b64'] ?? ''), true);
            if ($prevV4Raw === false) $prevV4Raw = '';
            if ($currV4Raw === false) $currV4Raw = '';
            if ($prevV6Raw === false) $prevV6Raw = '';
            if ($currV6Raw === false) $currV6Raw = '';
            $v4Diff = buildDiffSnippet($prevV4Raw, $currV4Raw, 40);
            $v6Diff = buildDiffSnippet($prevV6Raw, $currV6Raw, 40);
            $prevV4NoF2b = stripFail2BanDynamicRules($prevV4Raw);
            $currV4NoF2b = stripFail2BanDynamicRules($currV4Raw);
            $prevV6NoF2b = stripFail2BanDynamicRules($prevV6Raw);
            $currV6NoF2b = stripFail2BanDynamicRules($currV6Raw);
            $onlyFail2BanDynamic =
                ($prevV4NoF2b === $currV4NoF2b) &&
                ($prevV6NoF2b === $currV6NoF2b);

            if ($onlyFail2BanDynamic) {
                logMsg('Firewall watch: cambio detectado solo en bans dinamicos de Fail2Ban, alerta externa omitida.');
                $state['snapshot'] = $snapshot;
                $state['last_checked_at'] = $now;
                @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
                return;
            }

            $message = "Cambio externo detectado en firewall ({$snapshot['type']})";
            $details =
                "Host: {$host}\n" .
                "Origen probable: shell/manual/externo\n" .
                "Activo: " . ($snapshot['active'] ? 'si' : 'no') . "\n" .
                "Politica INPUT: {$snapshot['policy']}\n" .
                "Fingerprint anterior: " . shortHash($prevFp) . "\n" .
                "Fingerprint actual: " . shortHash($newFp) . "\n" .
                "Hash v4 (normalizado) anterior/actual: " . shortHash((string)($prev['v4_hash'] ?? '')) . ' -> ' . shortHash((string)($snapshot['v4_hash'] ?? '')) . "\n" .
                "Hash v6 (normalizado) anterior/actual: " . shortHash((string)($prev['v6_hash'] ?? '')) . ' -> ' . shortHash((string)($snapshot['v6_hash'] ?? '')) . "\n" .
                "Hora: " . gmdate('Y-m-d H:i:s') . " UTC";
            if ($v4Diff !== '') {
                $details .= "\n\nDiff v4:\n{$v4Diff}";
            }
            if ($v6Diff !== '') {
                $details .= "\n\nDiff v6:\n{$v6Diff}";
            }

            $inserted = insertEventAlert($host, 'FIREWALL_CHANGED', $message, $details, 1.0, 300);
            if ($inserted) {
                logMsg("ALERT: FIREWALL_CHANGED - {$message}");
            } else {
                logMsg('Firewall watch: alerta FIREWALL_CHANGED suprimida por cooldown.');
            }

            $cooldown = (int)Settings::get('firewall_change_watch_email_cooldown_seconds', '1800');
            $cooldown = max(300, min(86400, $cooldown));
            $sent = \MuseDockPanel\Services\NotificationService::sendEventEmail(
                'firewall_change',
                "[MuseDock Firewall] Cambio externo detectado en {$host}",
                $details,
                $cooldown
            );
            logMsg($sent
                ? 'Firewall watch: email enviado.'
                : 'Firewall watch: email omitido (cooldown/canal no configurado).');
        }
    }

    $state['snapshot'] = $snapshot;
    $state['last_checked_at'] = $now;
    @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function checkServerRebootWatch(string $host): void
{
    if (Settings::get('server_reboot_notify_enabled', '0') !== '1') {
        return;
    }

    $bootId = trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id'));
    if ($bootId === '') {
        return;
    }

    $cacheDir = PANEL_ROOT . '/storage/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $stateFile = $cacheDir . '/server-reboot-watch-state.json';
    $state = [];
    if (is_file($stateFile)) {
        $decoded = json_decode((string)@file_get_contents($stateFile), true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

    $prevBootId = (string)($state['boot_id'] ?? '');
    if ($prevBootId === '') {
        $state['boot_id'] = $bootId;
        $state['last_checked_at'] = time();
        @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return;
    }

    if ($prevBootId !== $bootId) {
        $uptime = trim((string)shell_exec("uptime -p 2>/dev/null | sed 's/^up //'"));
        if ($uptime === '') {
            $uptime = 'desconocido';
        }

        $message = "Reinicio detectado en servidor {$host}";
        $details =
            "Host: {$host}\n" .
            "Boot ID anterior: {$prevBootId}\n" .
            "Boot ID actual: {$bootId}\n" .
            "Uptime actual: {$uptime}\n" .
            "Hora deteccion: " . gmdate('Y-m-d H:i:s') . " UTC";

        $inserted = insertEventAlert($host, 'SERVER_REBOOT', $message, $details, 1.0, 1800);
        if ($inserted) {
            logMsg("ALERT: SERVER_REBOOT - {$message}");
        } else {
            logMsg('Reboot watch: alerta SERVER_REBOOT suprimida por cooldown.');
        }

        $sent = \MuseDockPanel\Services\NotificationService::sendEventEmail(
            'server_reboot',
            "[MuseDock Server] Reinicio detectado en {$host}",
            $details,
            14400
        );
        logMsg($sent
            ? 'Reboot watch: email enviado.'
            : 'Reboot watch: email omitido (cooldown/canal no configurado).');
    }

    $state['boot_id'] = $bootId;
    $state['last_checked_at'] = time();
    @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function formatDurationHuman(int $seconds): string
{
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return floor($seconds / 60) . 'm';
    if ($seconds < 86400) return floor($seconds / 3600) . 'h';
    return floor($seconds / 86400) . 'd';
}

function checkCollectorGapWatch(string $host): void
{
    if (Settings::get('notify_event_collector_gap_enabled', '1') !== '1') {
        return;
    }

    $threshold = (int)Settings::get('notify_event_collector_gap_seconds', '300');
    $threshold = max(120, min(86400, $threshold));

    $cacheDir = PANEL_ROOT . '/storage/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $stateFile = $cacheDir . '/collector-gap-watch-state.json';
    $now = time();

    $state = [];
    if (is_file($stateFile)) {
        $decoded = json_decode((string)@file_get_contents($stateFile), true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

    $lastTs = (int)($state['last_run_ts'] ?? 0);
    if ($lastTs > 0) {
        $gap = $now - $lastTs;
        if ($gap >= $threshold) {
            $message = "Parada detectada: gap del monitor de {$gap}s";
            $details =
                "Host: {$host}\n" .
                "Gap detectado: {$gap}s (" . formatDurationHuman($gap) . ")\n" .
                "Umbral configurado: {$threshold}s\n" .
                "Ultima ejecucion collector: " . gmdate('Y-m-d H:i:s', $lastTs) . " UTC\n" .
                "Deteccion actual: " . gmdate('Y-m-d H:i:s', $now) . " UTC";

            $inserted = insertEventAlert($host, 'MONITOR_GAP', $message, $details, (float)$gap, 1800);
            if ($inserted) {
                logMsg("ALERT: MONITOR_GAP - {$message}");
            } else {
                logMsg('Gap watch: alerta MONITOR_GAP suprimida por cooldown.');
            }

            $cooldown = (int)Settings::get('notify_event_collector_gap_email_cooldown_seconds', '7200');
            $cooldown = max(600, min(86400, $cooldown));
            $sent = \MuseDockPanel\Services\NotificationService::sendEventEmail(
                'monitor_gap',
                "[MuseDock Server] Parada detectada en {$host}",
                $details,
                $cooldown
            );
            logMsg($sent
                ? 'Gap watch: email enviado.'
                : 'Gap watch: email omitido (cooldown/canal no configurado).');
        }
    }

    $state['last_run_ts'] = $now;
    @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function readWatchState(string $stateFile): array
{
    if (!is_file($stateFile)) {
        return [];
    }
    $decoded = json_decode((string)@file_get_contents($stateFile), true);
    return is_array($decoded) ? $decoded : [];
}

function writeWatchState(string $stateFile, array $state): void
{
    $dir = dirname($stateFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function criticalConfigTargets(): array
{
    return [
        '/etc/ssh/sshd_config' => 'sshd_config',
        '/etc/fail2ban/jail.local' => 'fail2ban jail.local',
        '/etc/fail2ban/jail.d/musedock.conf' => 'fail2ban jail musedock',
        '/etc/fail2ban/filter.d/musedock-wordpress.conf' => 'fail2ban filter musedock-wordpress',
    ];
}

function readCriticalConfigSnapshot(): array
{
    $snapshot = [];
    foreach (criticalConfigTargets() as $path => $label) {
        $exists = is_file($path);
        $content = $exists ? (string)@file_get_contents($path) : '';
        if (strlen($content) > 200000) {
            $content = substr($content, 0, 200000) . "\n# ... truncated by monitor collector";
        }
        $snapshot[$path] = [
            'label' => $label,
            'exists' => $exists ? 1 : 0,
            'hash' => hash('sha256', $content),
            'content_b64' => base64_encode($content),
        ];
    }
    return $snapshot;
}

function buildDiffSnippet(string $oldContent, string $newContent, int $maxLines = 60): string
{
    if ($oldContent === $newContent) {
        return '';
    }

    $oldFile = @tempnam('/tmp', 'md_old_');
    $newFile = @tempnam('/tmp', 'md_new_');
    if (!$oldFile || !$newFile) {
        if ($oldFile && is_file($oldFile)) @unlink($oldFile);
        if ($newFile && is_file($newFile)) @unlink($newFile);
        return '';
    }

    @file_put_contents($oldFile, $oldContent);
    @file_put_contents($newFile, $newContent);
    $cmd = sprintf(
        "diff -u --label anterior --label actual %s %s 2>/dev/null | sed -n '1,160p'",
        escapeshellarg($oldFile),
        escapeshellarg($newFile)
    );
    $diff = trim((string)shell_exec($cmd));
    @unlink($oldFile);
    @unlink($newFile);

    if ($diff === '') {
        return '';
    }

    $lines = preg_split('/\r?\n/', $diff) ?: [];
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $lines[] = '... (diff truncado)';
    }
    return trim(implode("\n", $lines));
}

function analyzeConfigDriftChanges(array $prevSnapshot, array $currSnapshot): array
{
    $allPaths = array_unique(array_merge(array_keys($prevSnapshot), array_keys($currSnapshot)));
    sort($allPaths, SORT_STRING);

    $changes = [];
    $diffs = [];

    foreach ($allPaths as $path) {
        $prev = is_array($prevSnapshot[$path] ?? null) ? $prevSnapshot[$path] : [];
        $curr = is_array($currSnapshot[$path] ?? null) ? $currSnapshot[$path] : [];

        $prevExists = (int)($prev['exists'] ?? 0) === 1;
        $currExists = (int)($curr['exists'] ?? 0) === 1;
        $prevHash = (string)($prev['hash'] ?? '');
        $currHash = (string)($curr['hash'] ?? '');
        $label = (string)($curr['label'] ?? $prev['label'] ?? basename($path));

        if (!$prevExists && !$currExists) {
            continue;
        }

        if (!$prevExists && $currExists) {
            $changes[] = "CREATED {$path} ({$label})";
            continue;
        }

        if ($prevExists && !$currExists) {
            $changes[] = "REMOVED {$path} ({$label})";
            continue;
        }

        if ($prevHash !== $currHash) {
            $changes[] = "MODIFIED {$path} ({$label})";
            $oldContent = base64_decode((string)($prev['content_b64'] ?? ''), true);
            $newContent = base64_decode((string)($curr['content_b64'] ?? ''), true);
            if ($oldContent === false) $oldContent = '';
            if ($newContent === false) $newContent = '';
            $snippet = buildDiffSnippet($oldContent, $newContent, 80);
            if ($snippet !== '') {
                $diffs[$path] = $snippet;
            }
        }
    }

    return [
        'changes' => $changes,
        'diffs' => $diffs,
        'hash' => hash('sha256', json_encode($changes, JSON_UNESCAPED_SLASHES)),
    ];
}

function checkConfigDriftWatch(string $host): void
{
    if (Settings::get('notify_event_config_drift_enabled', '1') !== '1') {
        return;
    }

    $interval = (int)Settings::get('notify_event_config_drift_interval_seconds', '120');
    $interval = max(60, min(3600, $interval));
    $now = time();
    $stateFile = PANEL_ROOT . '/storage/cache/config-drift-watch-state.json';
    $state = readWatchState($stateFile);
    $lastChecked = (int)($state['last_checked_at'] ?? 0);
    if ($lastChecked > 0 && ($now - $lastChecked) < $interval) {
        return;
    }

    $currSnapshot = readCriticalConfigSnapshot();
    $prevSnapshot = is_array($state['snapshot'] ?? null) ? $state['snapshot'] : [];

    if (!empty($prevSnapshot)) {
        $analysis = analyzeConfigDriftChanges($prevSnapshot, $currSnapshot);
        $changes = is_array($analysis['changes'] ?? null) ? $analysis['changes'] : [];
        $changeHash = (string)($analysis['hash'] ?? '');
        $prevHash = (string)($state['change_hash'] ?? '');

        if (!empty($changes) && $changeHash !== '' && $changeHash !== $prevHash) {
            $panelAction = recentPanelConfigAction(240);
            if ($panelAction) {
                logMsg('Config drift watch: cambio detectado con origen panel, alerta externa omitida.');
            } else {
                $details = "Host: {$host}\n" .
                    "Origen probable: shell/manual/externo\n" .
                    "Archivos cambiados: " . count($changes) . "\n" .
                    "Hora: " . gmdate('Y-m-d H:i:s') . " UTC\n\n" .
                    implode("\n", $changes);

                $diffs = is_array($analysis['diffs'] ?? null) ? $analysis['diffs'] : [];
                if (!empty($diffs)) {
                    $details .= "\n\nDiff resumido:\n";
                    $shown = 0;
                    foreach ($diffs as $path => $snippet) {
                        $details .= "\n[{$path}]\n{$snippet}\n";
                        $shown++;
                        if ($shown >= 3) {
                            break;
                        }
                    }
                }

                $message = 'Drift detectado en archivos criticos de seguridad';
                $inserted = insertEventAlert($host, 'CONFIG_DRIFT', $message, $details, 1.0, 600);
                if ($inserted) {
                    logMsg("ALERT: CONFIG_DRIFT - {$message}");
                } else {
                    logMsg('Config drift watch: alerta CONFIG_DRIFT suprimida por cooldown.');
                }

                $cooldown = (int)Settings::get('notify_event_config_drift_email_cooldown_seconds', '1800');
                $cooldown = max(300, min(86400, $cooldown));
                $sent = \MuseDockPanel\Services\NotificationService::sendEventEmail(
                    'config_drift',
                    "[MuseDock Security] Drift de configuracion en {$host}",
                    $details,
                    $cooldown
                );
                logMsg($sent
                    ? 'Config drift watch: email enviado.'
                    : 'Config drift watch: email omitido (cooldown/canal no configurado).');
            }
        }

        $state['change_hash'] = $changeHash;
    }

    $state['snapshot'] = $currSnapshot;
    $state['last_checked_at'] = $now;
    writeWatchState($stateFile, $state);
}

function checkHardeningWatch(string $host): void
{
    if (Settings::get('notify_event_hardening_enabled', '1') !== '1') {
        return;
    }

    $interval = (int)Settings::get('notify_event_hardening_interval_seconds', '180');
    $interval = max(60, min(3600, $interval));
    $now = time();
    $stateFile = PANEL_ROOT . '/storage/cache/security-hardening-watch-state.json';
    $state = readWatchState($stateFile);
    $lastChecked = (int)($state['last_checked_at'] ?? 0);
    if ($lastChecked > 0 && ($now - $lastChecked) < $interval) {
        return;
    }

    $audit = \MuseDockPanel\Services\SecurityService::getHardeningAudit();
    $checks = is_array($audit['checks'] ?? null) ? $audit['checks'] : [];
    $failed = [];
    foreach ($checks as $check) {
        if (!empty($check['ok'])) {
            continue;
        }
        $failed[] = [
            'title' => (string)($check['title'] ?? 'control'),
            'current' => (string)($check['current'] ?? ''),
            'recommended' => (string)($check['recommended'] ?? ''),
        ];
    }

    $failedHash = hash('sha256', json_encode($failed, JSON_UNESCAPED_SLASHES));
    $prevFailedHash = (string)($state['failed_hash'] ?? '');
    $prevFailedCount = (int)($state['failed_count'] ?? 0);
    $failedCount = count($failed);
    $score = (int)($audit['score'] ?? 0);

    if ($failedCount > 0 && $failedHash !== $prevFailedHash) {
        $lines = [];
        foreach ($failed as $f) {
            $lines[] = '- ' . $f['title'] . ': actual=' . ($f['current'] !== '' ? $f['current'] : 'n/a') . ', recomendado=' . $f['recommended'];
        }

        $message = "Hardening degradado: {$failedCount} controles fuera de baseline";
        $details =
            "Host: {$host}\n" .
            "Score: {$score}/100\n" .
            "Controles en fallo: {$failedCount}\n" .
            "Hora: " . gmdate('Y-m-d H:i:s') . " UTC\n\n" .
            implode("\n", $lines);

        $inserted = insertEventAlert($host, 'SECURITY_HARDENING', $message, $details, (float)$score, 900);
        if ($inserted) {
            logMsg("ALERT: SECURITY_HARDENING - {$message}");
        } else {
            logMsg('Hardening watch: alerta SECURITY_HARDENING suprimida por cooldown.');
        }

        $cooldown = (int)Settings::get('notify_event_hardening_email_cooldown_seconds', '3600');
        $cooldown = max(300, min(86400, $cooldown));
        $sent = \MuseDockPanel\Services\NotificationService::sendEventEmail(
            'security_hardening',
            "[MuseDock Security] Hardening degradado en {$host}",
            $details,
            $cooldown
        );
        logMsg($sent
            ? 'Hardening watch: email enviado.'
            : 'Hardening watch: email omitido (cooldown/canal no configurado).');
    } elseif ($failedCount === 0 && $prevFailedCount > 0) {
        logMsg('Hardening watch: baseline recuperado.');
    }

    $state['failed_hash'] = $failedHash;
    $state['failed_count'] = $failedCount;
    $state['score'] = $score;
    $state['last_checked_at'] = $now;
    writeWatchState($stateFile, $state);
}

function addressIsPublicListener(string $addr): bool
{
    $addr = trim($addr);
    if ($addr === '' || $addr === '*') {
        return true;
    }
    if ($addr === '0.0.0.0' || $addr === '::') {
        return true;
    }
    if ($addr === '127.0.0.1' || $addr === '::1') {
        return false;
    }
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    return false;
}

function getPublicListeningTcpListeners(): array
{
    $raw = trim((string)shell_exec('ss -lntH 2>/dev/null'));
    if ($raw === '') {
        return [];
    }

    $listeners = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $cols = preg_split('/\s+/', $line);
        if (!is_array($cols) || count($cols) < 4) {
            continue;
        }

        $local = (string)$cols[3];
        $addr = '';
        $port = 0;

        if (preg_match('/^\[(.*)\]:(\d+)$/', $local, $m)) {
            $addr = trim((string)$m[1]);
            $port = (int)$m[2];
        } elseif (preg_match('/^(.*):(\d+)$/', $local, $m)) {
            $addr = trim((string)$m[1]);
            $port = (int)$m[2];
        }

        if ($port < 1 || $port > 65535) {
            continue;
        }
        if (!addressIsPublicListener($addr)) {
            continue;
        }

        if (!isset($listeners[$port])) {
            $listeners[$port] = [];
        }
        $listeners[$port][$addr === '' ? '*' : $addr] = true;
    }

    ksort($listeners, SORT_NUMERIC);
    $normalized = [];
    foreach ($listeners as $port => $binds) {
        $bindList = array_keys($binds);
        sort($bindList, SORT_STRING);
        $normalized[$port] = $bindList;
    }
    return $normalized;
}

function checkPublicExposureWatch(string $host): void
{
    if (Settings::get('notify_event_public_exposure_enabled', '1') !== '1') {
        return;
    }

    $interval = (int)Settings::get('notify_event_public_exposure_interval_seconds', '120');
    $interval = max(60, min(3600, $interval));
    $now = time();
    $stateFile = PANEL_ROOT . '/storage/cache/public-exposure-watch-state.json';
    $state = readWatchState($stateFile);
    $lastChecked = (int)($state['last_checked_at'] ?? 0);
    if ($lastChecked > 0 && ($now - $lastChecked) < $interval) {
        return;
    }

    $listeners = getPublicListeningTcpListeners();
    $actual = array_map('intval', array_keys($listeners));
    sort($actual, SORT_NUMERIC);
    $expected = \MuseDockPanel\Services\SecurityService::getExpectedPublicPorts();
    $extra = array_values(array_diff($actual, $expected));
    sort($extra, SORT_NUMERIC);

    $hashInput = [
        'extra' => $extra,
        'actual' => $actual,
        'expected' => $expected,
    ];
    $currentHash = hash('sha256', json_encode($hashInput, JSON_UNESCAPED_SLASHES));
    $prevHash = (string)($state['exposure_hash'] ?? '');

    if (!empty($extra) && $currentHash !== $prevHash) {
        $bindLines = [];
        foreach ($extra as $port) {
            $binds = implode(', ', $listeners[$port] ?? ['*']);
            $bindLines[] = "- {$port}/tcp en {$binds}";
        }

        $snapshot = detectFirewallSnapshot();
        $message = 'Exposicion publica inesperada detectada';
        $details =
            "Host: {$host}\n" .
            "Puertos esperados: " . (empty($expected) ? '(ninguno)' : implode(',', $expected)) . "\n" .
            "Puertos detectados publicos: " . (empty($actual) ? '(ninguno)' : implode(',', $actual)) . "\n" .
            "Puertos inesperados: " . implode(',', $extra) . "\n" .
            "Firewall: {$snapshot['type']} / policy={$snapshot['policy']} / activo=" . ($snapshot['active'] ? 'si' : 'no') . "\n" .
            "Hora: " . gmdate('Y-m-d H:i:s') . " UTC\n\n" .
            "Detalle binds publicos:\n" . implode("\n", $bindLines);

        $inserted = insertEventAlert($host, 'PORT_EXPOSURE', $message, $details, (float)count($extra), 900);
        if ($inserted) {
            logMsg("ALERT: PORT_EXPOSURE - {$message}");
        } else {
            logMsg('Exposure watch: alerta PORT_EXPOSURE suprimida por cooldown.');
        }

        $cooldown = (int)Settings::get('notify_event_public_exposure_email_cooldown_seconds', '1800');
        $cooldown = max(300, min(86400, $cooldown));
        $sent = \MuseDockPanel\Services\NotificationService::sendEventEmail(
            'public_exposure',
            "[MuseDock Security] Exposicion publica inesperada en {$host}",
            $details,
            $cooldown
        );
        logMsg($sent
            ? 'Exposure watch: email enviado.'
            : 'Exposure watch: email omitido (cooldown/canal no configurado).');
    } elseif (empty($extra) && !empty($state['extra_ports'])) {
        logMsg('Exposure watch: exposicion inesperada recuperada.');
    }

    $state['exposure_hash'] = $currentHash;
    $state['extra_ports'] = $extra;
    $state['last_checked_at'] = $now;
    writeWatchState($stateFile, $state);
}

function checkTemporaryLockdownExpiryWatch(string $host): void
{
    $lockdown = \MuseDockPanel\Services\FirewallService::getTemporaryLockdownState();
    $untilTs = (int)($lockdown['until_ts'] ?? 0);
    if ($untilTs <= 0 || $untilTs > time()) {
        return;
    }

    $result = \MuseDockPanel\Services\FirewallService::stopTemporaryLockdown();
    $ok = (bool)($result['ok'] ?? false);
    $steps = is_array($result['steps'] ?? null) ? $result['steps'] : [];
    $stepLines = [];
    foreach ($steps as $step) {
        $stepLines[] = '- ' . (string)($step['name'] ?? 'step') . ': ' . (!empty($step['ok']) ? 'ok' : 'fail');
    }

    if ($ok) {
        $message = 'Lockdown temporal expirado y desactivado automaticamente';
        $details =
            "Host: {$host}\n" .
            "Expiracion: " . gmdate('Y-m-d H:i:s', $untilTs) . " UTC\n" .
            "Hora desactivacion: " . gmdate('Y-m-d H:i:s') . " UTC\n" .
            "IP admin mantenida: " . ((string)($lockdown['admin_ip'] ?? '') ?: 'n/a') . "\n" .
            (empty($stepLines) ? '' : ("\nPasos:\n" . implode("\n", $stepLines)));
        insertEventAlert($host, 'FIREWALL_LOCKDOWN_EXPIRED', $message, $details, 1.0, 600);
        logMsg("ALERT: FIREWALL_LOCKDOWN_EXPIRED - {$message}");
    } else {
        $error = (string)($result['error'] ?? 'error desconocido');
        $message = 'Lockdown temporal expirado con error al desactivar';
        $details =
            "Host: {$host}\n" .
            "Expiracion: " . gmdate('Y-m-d H:i:s', $untilTs) . " UTC\n" .
            "Error: {$error}\n" .
            "Hora: " . gmdate('Y-m-d H:i:s') . " UTC\n" .
            (empty($stepLines) ? '' : ("\nPasos:\n" . implode("\n", $stepLines)));
        insertEventAlert($host, 'FIREWALL_LOCKDOWN_ERROR', $message, $details, 1.0, 900);
        logMsg("ALERT: FIREWALL_LOCKDOWN_ERROR - {$message} ({$error})");
    }
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

// Check disk alerts (0 = disabled)
if ($alertDiskThreshold > 0 && !empty($diskData)) {
    foreach ($diskData as $d) {
        if ($d['percent'] > $alertDiskThreshold) {
            checkAlert($hostname, 'DISK_HIGH', "Disk {$d['device']} ({$d['mount']}) at {$d['percent']}% (threshold: {$alertDiskThreshold}%)", $d['percent']);
        }
    }
}

// ─── Event watchers: firewall external changes + server reboot ───────
try {
    checkFirewallIntegrityWatch($hostname);
} catch (\Throwable $e) {
    logMsg('Firewall watch error: ' . $e->getMessage());
}

try {
    checkTemporaryLockdownExpiryWatch($hostname);
} catch (\Throwable $e) {
    logMsg('Lockdown expiry watch error: ' . $e->getMessage());
}

try {
    checkServerRebootWatch($hostname);
} catch (\Throwable $e) {
    logMsg('Reboot watch error: ' . $e->getMessage());
}

try {
    checkCollectorGapWatch($hostname);
} catch (\Throwable $e) {
    logMsg('Gap watch error: ' . $e->getMessage());
}

try {
    checkHardeningWatch($hostname);
} catch (\Throwable $e) {
    logMsg('Hardening watch error: ' . $e->getMessage());
}

try {
    checkConfigDriftWatch($hostname);
} catch (\Throwable $e) {
    logMsg('Config drift watch error: ' . $e->getMessage());
}

try {
    checkPublicExposureWatch($hostname);
} catch (\Throwable $e) {
    logMsg('Public exposure watch error: ' . $e->getMessage());
}

// ─── Update disk usage for all hosting accounts (configurable cadence) ─
// du is expensive even with throttling — no need to run it every collector cycle
$duLockFile = '/tmp/musedock-du-lastrun';
$duLastRun = file_exists($duLockFile) ? (int)file_get_contents($duLockFile) : 0;
$duInterval = (int) Settings::get('monitor_disk_scan_interval_seconds', '1200'); // default: 20 minutes
$duInterval = max(300, min(86400, $duInterval)); // clamp: 5 min .. 24 h
$duRunMs = (int) Settings::get('monitor_du_run_ms', '10');
$duPauseMs = (int) Settings::get('monitor_du_pause_ms', '30'); // default duty cycle: 25%
$duRunMs = max(1, min(1000, $duRunMs));
$duPauseMs = max(1, min(5000, $duPauseMs));

if ((time() - $duLastRun) >= $duInterval) {
    try {
        $accounts = Database::fetchAll("SELECT id, home_dir FROM hosting_accounts WHERE status = 'active'");
        $homeDirs = array_filter(array_column($accounts, 'home_dir'), fn($d) => is_dir($d));
        if (!empty($homeDirs)) {
            $cmd = sprintf(
                'DU_THROTTLE_RUN_MS=%d DU_THROTTLE_PAUSE_MS=%d /opt/musedock-panel/bin/du-throttled -sm %s 2>/dev/null',
                $duRunMs,
                $duPauseMs,
                implode(' ', array_map('escapeshellarg', $homeDirs))
            );
            $output = shell_exec($cmd) ?: '';
            $diskMap = [];
            foreach (explode("\n", trim($output)) as $line) {
                if (preg_match('/^(\d+)\s+(.+)$/', $line, $m)) {
                    $diskMap[rtrim($m[2], '/')] = (int)$m[1];
                }
            }
            $updated = 0;
            foreach ($accounts as $acc) {
                $key = rtrim($acc['home_dir'], '/');
                $mb = $diskMap[$key] ?? null;
                if ($mb !== null) {
                    Database::query("UPDATE hosting_accounts SET disk_used_mb = :mb WHERE id = :id", ['mb' => $mb, 'id' => $acc['id']]);
                    $updated++;
                }
            }
            if ($updated > 0) {
                logMsg("Disk usage updated for {$updated} accounts.");
            }
        }
        file_put_contents($duLockFile, time());
    } catch (\Throwable $e) {
        logMsg("ERROR updating disk usage: " . $e->getMessage());
    }
} else {
    logMsg("Disk usage: skipped (next in " . ($duInterval - (time() - $duLastRun)) . "s)");
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
