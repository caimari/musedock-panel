<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\View;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\ClusterService;

class DashboardController
{
    public function index(): void
    {
        // System stats
        $stats = [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemoryUsage(),
            'disk' => $this->getDiskUsage(),
            'uptime' => $this->getUptime(),
            'hostname' => gethostname(),
            'php_version' => PHP_VERSION,
            'os' => php_uname('s') . ' ' . php_uname('r'),
        ];

        // Account counts
        $accounts = Database::fetchOne("SELECT COUNT(*) as total, COUNT(*) FILTER (WHERE status = 'active') as active, COUNT(*) FILTER (WHERE status = 'suspended') as suspended FROM hosting_accounts");

        // Recent log
        $recentLog = Database::fetchAll("SELECT l.*, a.username as admin_name FROM panel_log l LEFT JOIN panel_admins a ON a.id = l.admin_id ORDER BY l.created_at DESC LIMIT 10");

        // Cluster info for slave dashboard
        $clusterInfo = null;
        $clusterRole = Settings::get('cluster_role', 'standalone');
        if ($clusterRole !== 'standalone') {
            $clusterInfo = [
                'role'             => $clusterRole,
                'master_ip'        => Settings::get('cluster_master_ip', ''),
                'master_last_hb'   => Settings::get('cluster_master_last_heartbeat', ''),
                'self_standby'     => Settings::get('cluster_self_standby', '0') === '1',
            ];
        }

        // Cluster nodes (offline/standby for alert, online for info)
        $offlineNodes = [];
        $onlineNodes = [];
        try {
            $allNodes = ClusterService::getNodes();
            $now = time();
            foreach ($allNodes as $node) {
                $isStandby = !empty($node['standby']);
                $isDown = !$isStandby && ($node['status'] === 'offline' || empty($node['last_seen_at']) || (time() - strtotime($node['last_seen_at'])) > 300);

                if ($isStandby || $isDown) {
                    $meta = json_decode($node['metadata'] ?? '{}', true) ?: [];
                    $isMuted = !empty($meta['alerts_muted']);
                    $downSince = $node['last_seen_at'] ? date('Y-m-d H:i:s', strtotime($node['last_seen_at'])) : 'Nunca';
                    $downMinutes = $node['last_seen_at'] ? round(($now - strtotime($node['last_seen_at'])) / 60) : null;
                    $offlineNodes[] = [
                        'id'             => $node['id'],
                        'name'           => $node['name'],
                        'api_url'        => $node['api_url'],
                        'last_seen_at'   => $downSince,
                        'down_minutes'   => $downMinutes,
                        'muted'          => $isMuted,
                        'standby'        => $isStandby,
                        'standby_reason' => $node['standby_reason'] ?? '',
                        'standby_since'  => $node['standby_since'] ?? '',
                    ];
                } elseif (!$isDown && !$isStandby) {
                    $lastSeen = $node['last_seen_at'] ? date('Y-m-d H:i:s', strtotime($node['last_seen_at'])) : '';
                    $onlineNodes[] = [
                        'id'           => $node['id'],
                        'name'         => $node['name'],
                        'api_url'      => $node['api_url'],
                        'last_seen_at' => $lastSeen,
                    ];
                }
            }
        } catch (\Throwable) {}

        // Failover status
        $failoverStatus = null;
        try {
            $foSvc = \MuseDockPanel\Services\FailoverService::class;
            if ($foSvc::isConfigured()) {
                $failoverStatus = $foSvc::getStatusSummary();
            }
        } catch (\Throwable) {}

        View::render('dashboard/index', [
            'layout' => 'main',
            'pageTitle' => 'Dashboard',
            'stats' => $stats,
            'accounts' => $accounts,
            'recentLog' => $recentLog,
            'clusterInfo' => $clusterInfo,
            'offlineNodes' => $offlineNodes,
            'onlineNodes' => $onlineNodes,
            'failoverStatus' => $failoverStatus,
        ]);
    }

    private function getCpuUsage(): array
    {
        $load = sys_getloadavg();
        $cores = (int) trim(shell_exec('nproc') ?: '1');
        return [
            'load_1' => round($load[0], 2),
            'load_5' => round($load[1], 2),
            'load_15' => round($load[2], 2),
            'cores' => $cores,
            'percent' => min(100, round(($load[0] / $cores) * 100, 1)),
        ];
    }

    private function getMemoryUsage(): array
    {
        $free = shell_exec('free -b');
        preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $free, $m);
        $total = (int)($m[1] ?? 0);
        $used = (int)($m[2] ?? 0);
        $available = (int)($m[6] ?? 0);
        return [
            'total' => $total,
            'used' => $used,
            'available' => $available,
            'total_gb' => round($total / 1073741824, 1),
            'used_gb' => round($used / 1073741824, 1),
            'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    private function getDiskUsage(): array
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        $used = $total - $free;
        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'total_gb' => round($total / 1073741824, 1),
            'used_gb' => round($used / 1073741824, 1),
            'free_gb' => round($free / 1073741824, 1),
            'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    private function getUptime(): string
    {
        $uptime = @file_get_contents('/proc/uptime');
        if (!$uptime) return 'N/A';
        $seconds = (int) explode(' ', $uptime)[0];
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return "{$days}d {$hours}h {$minutes}m";
    }

    /**
     * GET /dashboard/processes (JSON)
     * Returns top processes sorted by CPU or RAM usage.
     */
    public function processes(): void
    {
        header('Content-Type: application/json');

        $sort = ($_GET['sort'] ?? 'cpu'); // 'cpu' or 'ram'
        $limit = min(30, max(10, (int)($_GET['limit'] ?? 20)));

        if ($sort === 'ram') {
            // Sort by RSS (resident memory) descending
            $cmd = "ps aux --sort=-%mem 2>/dev/null | head -" . ($limit + 1);
        } else {
            // Sort by CPU descending
            $cmd = "ps aux --sort=-%cpu 2>/dev/null | head -" . ($limit + 1);
        }

        $output = shell_exec($cmd);
        if (!$output) {
            echo json_encode(['ok' => false, 'error' => 'No se pudo ejecutar ps']);
            exit;
        }

        $lines = explode("\n", trim($output));
        $header = array_shift($lines); // Remove header line

        $processes = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line), 11);
            if (count($parts) < 11) continue;

            $processes[] = [
                'user'    => $parts[0],
                'pid'     => (int)$parts[1],
                'cpu'     => (float)$parts[2],
                'mem'     => (float)$parts[3],
                'vsz'     => (int)$parts[4],    // Virtual memory KB
                'rss'     => (int)$parts[5],    // Resident memory KB
                'stat'    => $parts[7],
                'time'    => $parts[9],
                'command' => $parts[10],
            ];
        }

        // Also include current totals
        $load = sys_getloadavg();
        $cores = (int) trim(shell_exec('nproc') ?: '1');
        $free = shell_exec('free -b');
        preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $free, $m);
        $totalMem = (int)($m[1] ?? 0);
        $usedMem = (int)($m[2] ?? 0);

        echo json_encode([
            'ok'        => true,
            'sort'      => $sort,
            'processes' => $processes,
            'summary'   => [
                'cpu_percent' => min(100, round(($load[0] / $cores) * 100, 1)),
                'cpu_load'    => round($load[0], 2),
                'cores'       => $cores,
                'mem_percent' => $totalMem > 0 ? round(($usedMem / $totalMem) * 100, 1) : 0,
                'mem_used_gb' => round($usedMem / 1073741824, 1),
                'mem_total_gb'=> round($totalMem / 1073741824, 1),
            ],
        ]);
        exit;
    }

    /**
     * GET /dashboard/process-detail?pid=XXX (JSON)
     * Returns full details for a specific process.
     */
    public function processDetail(): void
    {
        header('Content-Type: application/json');

        $pid = (int)($_GET['pid'] ?? 0);
        if ($pid < 1) {
            echo json_encode(['ok' => false, 'error' => 'PID no valido']);
            exit;
        }

        // Full command line from /proc
        $cmdline = '';
        if (is_readable("/proc/{$pid}/cmdline")) {
            $raw = file_get_contents("/proc/{$pid}/cmdline");
            $cmdline = str_replace("\0", ' ', trim($raw));
        }

        // Process info from ps
        $psOutput = shell_exec("ps -p {$pid} -o pid,user,%cpu,%mem,vsz,rss,stat,start,time,args --no-headers 2>/dev/null");
        if (!$psOutput || trim($psOutput) === '') {
            echo json_encode(['ok' => false, 'error' => "Proceso {$pid} no encontrado (puede haber terminado)"]);
            exit;
        }

        $parts = preg_split('/\s+/', trim($psOutput), 10);

        // Parent PID
        $ppid = trim((string)shell_exec("ps -p {$pid} -o ppid --no-headers 2>/dev/null"));

        // Open files count
        $fdCount = trim((string)shell_exec("ls /proc/{$pid}/fd 2>/dev/null | wc -l"));

        // Threads
        $threads = trim((string)shell_exec("ls /proc/{$pid}/task 2>/dev/null | wc -l"));

        // cwd
        $cwd = @readlink("/proc/{$pid}/cwd") ?: '';

        // exe
        $exe = @readlink("/proc/{$pid}/exe") ?: '';

        echo json_encode([
            'ok'       => true,
            'pid'      => $pid,
            'user'     => $parts[1] ?? '',
            'cpu'      => (float)($parts[2] ?? 0),
            'mem'      => (float)($parts[3] ?? 0),
            'vsz'      => (int)($parts[4] ?? 0),
            'rss'      => (int)($parts[5] ?? 0),
            'stat'     => $parts[6] ?? '',
            'started'  => $parts[7] ?? '',
            'time'     => $parts[8] ?? '',
            'command'  => $parts[9] ?? '',
            'cmdline'  => $cmdline,
            'ppid'     => (int)$ppid,
            'fd_count' => (int)$fdCount,
            'threads'  => (int)$threads,
            'cwd'      => $cwd,
            'exe'      => $exe,
        ]);
        exit;
    }

    /**
     * POST /dashboard/process-kill (JSON)
     * Kill a process by PID. Requires CSRF.
     */
    public function processKill(): void
    {
        \MuseDockPanel\View::verifyCsrf();
        header('Content-Type: application/json');

        $pid = (int)($_POST['pid'] ?? 0);
        $signal = ($_POST['signal'] ?? 'TERM');

        if ($pid < 2) {
            echo json_encode(['ok' => false, 'error' => 'PID no valido (no se puede matar PID 0 o 1)']);
            exit;
        }

        // Validate signal
        $allowedSignals = ['TERM', 'KILL', 'HUP', 'INT'];
        if (!in_array($signal, $allowedSignals, true)) {
            $signal = 'TERM';
        }

        // Check process exists
        $exists = trim((string)shell_exec("ps -p {$pid} --no-headers 2>/dev/null"));
        if ($exists === '') {
            echo json_encode(['ok' => false, 'error' => "Proceso {$pid} no existe"]);
            exit;
        }

        // Get process info for logging
        $processInfo = trim((string)shell_exec("ps -p {$pid} -o user,args --no-headers 2>/dev/null"));

        $output = shell_exec("kill -{$signal} {$pid} 2>&1");
        $stillRunning = trim((string)shell_exec("ps -p {$pid} --no-headers 2>/dev/null")) !== '';

        // Log the action
        \MuseDockPanel\Services\LogService::log('system.process', 'kill', "PID {$pid} (signal {$signal}): {$processInfo}");

        echo json_encode([
            'ok'      => true,
            'pid'     => $pid,
            'signal'  => $signal,
            'killed'  => !$stillRunning,
            'message' => !$stillRunning
                ? "Proceso {$pid} terminado con SIG{$signal}"
                : "Signal SIG{$signal} enviada al proceso {$pid} (aun activo, prueba SIGKILL)",
        ]);
        exit;
    }
}
