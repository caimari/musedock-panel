<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\View;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\MonitorService;

class MonitorController
{
    /**
     * GET /monitor — Main monitoring dashboard
     */
    public function index(): void
    {
        $host = $_GET['host'] ?? (gethostname() ?: 'localhost');
        $interfaces = MonitorService::getInterfaces();
        $status = MonitorService::getCurrentStatus($host);
        $healthScore = MonitorService::getHealthScore($host);
        $alertCount = MonitorService::getUnacknowledgedCount($host);
        $syncDegraded = MonitorService::getSyncDegradedStatus($host);

        $gpus = MonitorService::detectGpus($host);
        $panelTz = Settings::get('panel_timezone', 'UTC');
        $ifaceIPs = MonitorService::getInterfaceIPs($interfaces);

        $disks = MonitorService::getDiskUsage();

        $alertSettings = [
            'enabled'          => Settings::get('monitor_enabled', '1'),
            'cpu'              => Settings::get('monitor_alert_cpu', '90'),
            'ram'              => Settings::get('monitor_alert_ram', '90'),
            'net_mbps'         => Settings::get('monitor_alert_net_mbps', '800'),
            'disk'             => Settings::get('monitor_alert_disk', '90'),
            'gpu_temp'         => Settings::get('monitor_alert_gpu_temp', '85'),
            'gpu_util'         => Settings::get('monitor_alert_gpu_util', '95'),
            'noise_level'      => Settings::get('monitor_alert_noise_level', 'normal'),
            'notify_email'     => Settings::get('monitor_notify_email', '0'),
            'notify_telegram'  => Settings::get('monitor_notify_telegram', '0'),
        ];

        View::render('monitor/index', [
            'layout'        => 'main',
            'pageTitle'     => 'Monitoring',
            'interfaces'    => $interfaces,
            'ifaceIPs'      => $ifaceIPs,
            'status'        => $status,
            'healthScore'   => $healthScore,
            'alertCount'    => $alertCount,
            'syncDegraded'  => $syncDegraded,
            'host'          => $host,
            'gpus'          => $gpus,
            'disks'         => $disks,
            'panelTz'       => $panelTz,
            'alertSettings' => $alertSettings,
        ]);
    }

    /**
     * GET /monitor/api/metrics — Chart data JSON
     */
    public function apiMetrics(): void
    {
        header('Content-Type: application/json');

        $host   = $_GET['host'] ?? (gethostname() ?: 'localhost');
        $metric = $_GET['metric'] ?? 'net_eth0_rx';
        $range  = $_GET['range'] ?? '1h';

        // Validate range
        $allowed = ['1h', '6h', '24h', '7d', '30d', '1y'];
        if (!in_array($range, $allowed, true)) {
            $range = '1h';
        }

        // Sanitize metric name (alphanumeric + underscore only)
        $metric = preg_replace('/[^a-zA-Z0-9_]/', '', $metric);

        $data = MonitorService::getMetrics($host, $metric, $range);

        echo json_encode(['ok' => true, 'data' => $data]);
        exit;
    }

    /**
     * GET /monitor/api/status — Current values for stat cards
     */
    public function apiStatus(): void
    {
        header('Content-Type: application/json');

        $host = $_GET['host'] ?? (gethostname() ?: 'localhost');
        $status = MonitorService::getCurrentStatus($host);
        $healthScore = MonitorService::getHealthScore($host);
        $alertCount = MonitorService::getUnacknowledgedCount($host);
        $syncDegraded = MonitorService::getSyncDegradedStatus($host);

        echo json_encode([
            'ok'          => true,
            'status'      => $status,
            'healthScore' => $healthScore,
            'alertCount'  => $alertCount,
            'syncDegraded' => $syncDegraded,
        ]);
        exit;
    }

    /**
     * GET /monitor/api/realtime — Real-time CPU, RAM and network stats (250ms sample).
     * Used by monitor cards for instant readings instead of collector data.
     */
    public function apiRealtime(): void
    {
        header('Content-Type: application/json');

        $cacheDir = dirname(__DIR__, 2) . '/storage/cache';
        $cacheFile = $cacheDir . '/monitor-realtime.json';
        $cacheTtl = 2; // seconds
        $now = time();

        if (is_file($cacheFile) && ($now - (int)@filemtime($cacheFile)) < $cacheTtl) {
            $cached = @file_get_contents($cacheFile);
            if ($cached !== false && $cached !== '') {
                echo $cached;
                exit;
            }
        }

        $cores = (int) trim((string)shell_exec('nproc 2>/dev/null'));
        if ($cores < 1) {
            $cores = 1;
        }
        $load = sys_getloadavg();

        // --- CPU from /proc/stat (500ms sample) ---
        $cpuPercent = 0;
        $stat1 = @file_get_contents('/proc/stat');
        if ($stat1 && preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat1, $m1)) {
            // Read network counters BEFORE sleeping (so network delta = same 500ms)
            $netBefore = [];
            foreach (glob('/sys/class/net/*/statistics/rx_bytes') as $f) {
                $iface = basename(dirname(dirname($f)));
                if ($iface === 'lo') continue;
                $netBefore[$iface] = [
                    'rx' => (int) @file_get_contents("/sys/class/net/{$iface}/statistics/rx_bytes"),
                    'tx' => (int) @file_get_contents("/sys/class/net/{$iface}/statistics/tx_bytes"),
                ];
            }

            usleep(250000); // 250ms

            $stat2 = @file_get_contents('/proc/stat');
            if ($stat2 && preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat2, $m2)) {
                $idle1 = (int)$m1[4] + (int)$m1[5];
                $idle2 = (int)$m2[4] + (int)$m2[5];
                $total1 = array_sum(array_slice($m1, 1));
                $total2 = array_sum(array_slice($m2, 1));
                $td = $total2 - $total1;
                if ($td > 0) $cpuPercent = round((1 - ($idle2 - $idle1) / $td) * 100, 1);
            }

            // Read network counters AFTER sleep
            $netAfter = [];
            foreach ($netBefore as $iface => $_) {
                $netAfter[$iface] = [
                    'rx' => (int) @file_get_contents("/sys/class/net/{$iface}/statistics/rx_bytes"),
                    'tx' => (int) @file_get_contents("/sys/class/net/{$iface}/statistics/tx_bytes"),
                ];
            }
        }

        // --- RAM from /proc/meminfo ---
        $meminfo = @file_get_contents('/proc/meminfo');
        $totalMem = 0; $availMem = 0;
        if ($meminfo) {
            if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $mt)) $totalMem = (int)$mt[1] * 1024;
            if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $ma)) $availMem = (int)$ma[1] * 1024;
        }
        $usedMem = $totalMem - $availMem;

        // --- Network rates (bytes/sec) ---
        $net = [];
        if (!empty($netBefore)) {
            foreach ($netBefore as $iface => $before) {
                $after = $netAfter[$iface] ?? $before;
                $rxDelta = max(0, $after['rx'] - $before['rx']);
                $txDelta = max(0, $after['tx'] - $before['tx']);
                $net[$iface] = [
                    'rx' => round($rxDelta / 0.25), // bytes per second
                    'tx' => round($txDelta / 0.25),
                ];
            }
        }

        $payload = json_encode([
            'ok' => true,
            'cpu_percent' => $cpuPercent,
            'cpu_load' => round($load[0], 2),
            'cores' => $cores,
            'mem_percent' => $totalMem > 0 ? round(($usedMem / $totalMem) * 100, 1) : 0,
            'mem_used_gb' => round($usedMem / 1073741824, 1),
            'mem_total_gb' => round($totalMem / 1073741824, 1),
            'net' => $net,
        ]);
        if ($payload === false) {
            echo json_encode(['ok' => false, 'error' => 'encode_failed']);
            exit;
        }

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($cacheFile, $payload, LOCK_EX);

        echo $payload;
        exit;
    }

    /**
     * GET /monitor/api/network-detail — Detailed info for a network interface.
     */
    public function apiNetworkDetail(): void
    {
        header('Content-Type: application/json');
        $iface = preg_replace('/[^a-z0-9_.-]/i', '', $_GET['iface'] ?? '');
        if (!$iface || !is_dir("/sys/class/net/{$iface}")) {
            echo json_encode(['ok' => false, 'error' => 'Interface not found']);
            exit;
        }

        $read = fn($f) => trim((string)@file_get_contents("/sys/class/net/{$iface}/{$f}"));

        // Basic info
        $speed = $read('speed'); // Mbps (-1 or empty for virtual)
        $mtu = $read('mtu');
        $operstate = $read('operstate');
        $duplex = $read('duplex');
        $carrier = $read('carrier');

        // WireGuard/virtual interfaces report "unknown" but are up if they have an IP and flags UP
        if ($operstate === 'unknown') {
            $flags = $read('flags');
            // flags is hex: 0x1 = UP, check if interface has IFF_UP
            if ($flags && (hexdec($flags) & 0x1)) {
                $operstate = 'up';
            }
        }

        // IP addresses
        $ipOutput = trim((string)shell_exec("ip -o addr show " . escapeshellarg($iface) . " 2>/dev/null"));
        $ips = [];
        foreach (explode("\n", $ipOutput) as $line) {
            if (preg_match('/inet6?\s+(\S+)/', $line, $m)) $ips[] = $m[1];
        }

        // Traffic totals
        $rxBytes = (int)$read('statistics/rx_bytes');
        $txBytes = (int)$read('statistics/tx_bytes');
        $rxPackets = (int)$read('statistics/rx_packets');
        $txPackets = (int)$read('statistics/tx_packets');
        $rxErrors = (int)$read('statistics/rx_errors');
        $txErrors = (int)$read('statistics/tx_errors');
        $rxDropped = (int)$read('statistics/rx_dropped');
        $txDropped = (int)$read('statistics/tx_dropped');

        // Real-time rate (500ms sample)
        $rxBefore = $rxBytes;
        $txBefore = $txBytes;
        usleep(500000);
        $rxAfter = (int)@file_get_contents("/sys/class/net/{$iface}/statistics/rx_bytes");
        $txAfter = (int)@file_get_contents("/sys/class/net/{$iface}/statistics/tx_bytes");
        $rxRate = round(max(0, $rxAfter - $rxBefore) / 0.5);
        $txRate = round(max(0, $txAfter - $txBefore) / 0.5);

        echo json_encode([
            'ok' => true,
            'iface' => $iface,
            'state' => $operstate,
            'speed' => is_numeric($speed) && (int)$speed > 0 ? (int)$speed . ' Mbps' : 'N/A',
            'mtu' => $mtu,
            'duplex' => $duplex ?: 'N/A',
            'ips' => $ips,
            'rx_bytes' => $rxBytes,
            'tx_bytes' => $txBytes,
            'rx_packets' => $rxPackets,
            'tx_packets' => $txPackets,
            'rx_errors' => $rxErrors,
            'tx_errors' => $txErrors,
            'rx_dropped' => $rxDropped,
            'tx_dropped' => $txDropped,
            'rx_rate' => $rxRate,
            'tx_rate' => $txRate,
        ]);
        exit;
    }

    /**
     * GET /monitor/api/disk-detail — Detailed info for a mount point.
     */
    public function apiDiskDetail(): void
    {
        header('Content-Type: application/json');
        $mount = trim($_GET['mount'] ?? '/');

        // Validate mount exists
        $disks = MonitorService::getDiskUsage();
        $disk = null;
        foreach ($disks as $d) {
            if ($d['mount'] === $mount) { $disk = $d; break; }
        }
        if (!$disk) {
            echo json_encode(['ok' => false, 'error' => 'Mount point not found']);
            exit;
        }

        // Inode usage
        $inodeOutput = trim((string)shell_exec(sprintf('df -i %s 2>/dev/null | tail -1', escapeshellarg($mount))));
        $inodes = [];
        if ($inodeOutput && preg_match('/\S+\s+(\d+)\s+(\d+)\s+(\d+)\s+(\S+)/', $inodeOutput, $im)) {
            $inodes = ['total' => (int)$im[1], 'used' => (int)$im[2], 'free' => (int)$im[3], 'percent' => $im[4]];
        }

        // Filesystem type
        $fstype = trim((string)shell_exec(sprintf("df -T %s 2>/dev/null | tail -1 | awk '{print $2}'", escapeshellarg($mount))));

        // Top 10 largest directories (only first level, quick)
        $topDirs = [];
        $duOutput = trim((string)shell_exec(sprintf('nice -n 19 du -sm --max-depth=1 %s 2>/dev/null | sort -rn | head -11', escapeshellarg($mount))));
        if ($duOutput) {
            foreach (explode("\n", $duOutput) as $line) {
                if (preg_match('/^(\d+)\s+(.+)$/', trim($line), $dm)) {
                    $path = $dm[2];
                    if ($path === $mount) continue; // skip total
                    $topDirs[] = ['path' => $path, 'mb' => (int)$dm[1]];
                }
            }
        }

        echo json_encode([
            'ok' => true,
            'device' => $disk['device'],
            'mount' => $disk['mount'],
            'fstype' => $fstype,
            'size' => $disk['size'],
            'used' => $disk['used'],
            'free' => $disk['size'] - $disk['used'],
            'percent' => $disk['percent'],
            'inodes' => $inodes,
            'top_dirs' => array_slice($topDirs, 0, 10),
        ]);
        exit;
    }

    /**
     * GET /monitor/api/bandwidth — Bandwidth chart data (hourly) with optional domain filter.
     * Params: range (1h,6h,24h,7d,30d,1y), account_id (0 = all)
     */
    public function apiBandwidth(): void
    {
        header('Content-Type: application/json');
        $range = $_GET['range'] ?? '1h';
        $accountId = (int)($_GET['account_id'] ?? 0);
        $allowed = ['1h', '6h', '24h', '7d', '30d', '1y'];
        if (!in_array($range, $allowed)) $range = '1h';

        $intervals = [
            '1h'  => '1 hour',
            '6h'  => '6 hours',
            '24h' => '24 hours',
            '7d'  => '7 days',
            '30d' => '30 days',
            '1y'  => '365 days',
        ];
        $group = match($range) {
            '30d' => 'day',
            '1y'  => 'month',
            default => 'hour',
        };

        $interval = $intervals[$range];
        // Whitelist group values (can't parameterize DATE_TRUNC argument in PostgreSQL)
        $safeGroup = in_array($group, ['hour', 'day', 'month'], true) ? $group : 'hour';

        if ($accountId > 0) {
            $rows = \MuseDockPanel\Database::fetchAll("
                SELECT EXTRACT(EPOCH FROM DATE_TRUNC('{$safeGroup}', ts))::bigint as ts,
                       SUM(bytes_out) as bytes_out, SUM(bytes_in) as bytes_in,
                       SUM(requests) as requests
                FROM hosting_bandwidth
                WHERE account_id = :aid AND ts >= (NOW() - INTERVAL '{$interval}')
                GROUP BY 1 ORDER BY 1
            ", ['aid' => $accountId]);
        } else {
            $rows = \MuseDockPanel\Database::fetchAll("
                SELECT EXTRACT(EPOCH FROM DATE_TRUNC('{$safeGroup}', ts))::bigint as ts,
                       SUM(bytes_out) as bytes_out, SUM(bytes_in) as bytes_in,
                       SUM(requests) as requests
                FROM hosting_bandwidth
                WHERE ts >= (NOW() - INTERVAL '{$interval}')
                GROUP BY 1 ORDER BY 1
            ");
        }

        echo json_encode(['ok' => true, 'data' => $rows, 'range' => $range]);
        exit;
    }

    /**
     * GET /monitor/api/alerts — Recent alerts JSON
     */
    public function apiAlerts(): void
    {
        header('Content-Type: application/json');

        $host = $_GET['host'] ?? (gethostname() ?: 'localhost');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
        $total = MonitorService::getAlertsCount($host);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $alerts = MonitorService::getAlerts($host, $perPage, $offset);
        $unacknowledged = MonitorService::getUnacknowledgedCount($host);

        echo json_encode([
            'ok' => true,
            'alerts' => $alerts,
            'unacknowledged' => $unacknowledged,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $pages,
            ],
        ]);
        exit;
    }

    /**
     * POST /monitor/api/alerts/ack — Acknowledge an alert
     */
    public function apiAckAlert(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            echo json_encode(['ok' => false, 'error' => 'Invalid alert ID']);
            exit;
        }

        $ok = MonitorService::acknowledgeAlert($id);
        echo json_encode(['ok' => $ok]);
        exit;
    }

    /**
     * POST /monitor/api/alerts/clear — Clear all alerts
     */
    public function apiClearAlerts(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $host = $_POST['host'] ?? (gethostname() ?: 'localhost');
        Database::query("DELETE FROM monitor_alerts WHERE host = :host", ['host' => $host]);

        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * POST /monitor/settings — Save alert thresholds
     */
    public function saveSettings(): void
    {
        View::verifyCsrf();
        $noiseLevel = strtolower((string)($_POST['alert_noise_level'] ?? 'normal'));
        if (!in_array($noiseLevel, ['high', 'normal', 'low'], true)) {
            $noiseLevel = 'normal';
        }

        $fields = [
            'monitor_enabled'          => isset($_POST['monitor_enabled']) ? '1' : '0',
            'monitor_alert_cpu'        => $_POST['alert_cpu'] ?? '90',
            'monitor_alert_ram'        => $_POST['alert_ram'] ?? '90',
            'monitor_alert_net_mbps'   => $_POST['alert_net_mbps'] ?? '800',
            'monitor_alert_disk'       => $_POST['alert_disk'] ?? '90',
            'monitor_alert_gpu_temp'   => $_POST['alert_gpu_temp'] ?? '85',
            'monitor_alert_gpu_util'   => $_POST['alert_gpu_util'] ?? '95',
            'monitor_alert_noise_level'=> $noiseLevel,
            'monitor_notify_email'     => isset($_POST['notify_email']) ? '1' : '0',
            'monitor_notify_telegram'  => isset($_POST['notify_telegram']) ? '1' : '0',
        ];

        foreach ($fields as $key => $value) {
            Settings::set($key, $value);
        }

        \MuseDockPanel\Flash::set('success', 'Alert settings saved.');
        \MuseDockPanel\Router::redirect('/monitor');
    }
}
