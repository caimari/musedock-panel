<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\View;

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

        View::render('dashboard/index', [
            'layout' => 'main',
            'pageTitle' => 'Dashboard',
            'stats' => $stats,
            'accounts' => $accounts,
            'recentLog' => $recentLog,
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
}
