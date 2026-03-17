<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

class MonitorService
{
    /**
     * Get metric data points for charting.
     * Automatically selects the right source table based on time range.
     */
    public static function getMetrics(string $host, string $metric, string $range = '1h'): array
    {
        $table = self::getSourceTable($range);
        $interval = self::getInterval($range);

        // Return epoch (seconds since 1970 UTC).
        // PG timezone = UTC, ts column = TIMESTAMP WITHOUT TZ, stored as UTC.
        // extract(epoch FROM ts AT TIME ZONE 'UTC') treats ts as UTC → correct epoch.
        $epochExpr = "extract(epoch FROM ts AT TIME ZONE 'UTC')";

        if ($table === 'monitor_metrics') {
            return Database::fetchAll(
                "SELECT {$epochExpr} AS ts, value FROM monitor_metrics
                 WHERE host = :host AND metric = :metric AND ts >= NOW() - INTERVAL '{$interval}'
                 ORDER BY ts ASC",
                ['host' => $host, 'metric' => $metric]
            );
        }

        // Hourly or daily tables
        return Database::fetchAll(
            "SELECT {$epochExpr} AS ts, avg_val AS value, max_val, min_val FROM {$table}
             WHERE host = :host AND metric = :metric AND ts >= NOW() - INTERVAL '{$interval}'
             ORDER BY ts ASC",
            ['host' => $host, 'metric' => $metric]
        );
    }

    /**
     * Get the latest value for each metric (for stat cards).
     */
    public static function getCurrentStatus(?string $host = null): array
    {
        $host = self::resolveHost($host);
        $rows = Database::fetchAll(
            "SELECT DISTINCT ON (metric) metric, value,
                    extract(epoch FROM ts AT TIME ZONE 'UTC') AS ts
             FROM monitor_metrics
             WHERE host = :host AND ts >= NOW() - INTERVAL '5 minutes'
             ORDER BY metric, ts DESC",
            ['host' => $host]
        );

        $status = [];
        foreach ($rows as $row) {
            $status[$row['metric']] = [
                'value' => (float) $row['value'],
                'ts'    => $row['ts'],
            ];
        }
        return $status;
    }

    /**
     * Calculate health score 0-100 based on current metrics.
     */
    public static function getHealthScore(?string $host = null): int
    {
        $host = self::resolveHost($host);
        $status = self::getCurrentStatus($host);

        $cpu = $status['cpu_percent']['value'] ?? 0;
        $ram = $status['ram_percent']['value'] ?? 0;

        // Sum all network RX rates for net penalty
        $netTotal = 0;
        foreach ($status as $key => $s) {
            if (str_ends_with($key, '_rx')) {
                $netTotal += $s['value'];
            }
        }
        // Normalize net: 100MB/s = 100% penalty
        $netPct = min(100, ($netTotal / 104857600) * 100);

        $score = 100;
        $score -= min(30, $cpu * 0.3);
        $score -= min(30, $ram * 0.3);
        $score -= min(20, $netPct * 0.2);

        // Penalty for no recent data (heartbeat missing)
        if (empty($status)) {
            $score -= 20;
        }

        return max(0, (int) round($score));
    }

    /**
     * Get recent alerts.
     */
    public static function getAlerts(?string $host = null, int $limit = 50): array
    {
        $host = self::resolveHost($host);
        return Database::fetchAll(
            "SELECT id, extract(epoch FROM ts AT TIME ZONE 'UTC') AS ts,
                    host, type, message, value, acknowledged
             FROM monitor_alerts
             WHERE host = :host
             ORDER BY ts DESC
             LIMIT :lim",
            ['host' => $host, 'lim' => $limit]
        );
    }

    /**
     * Acknowledge an alert by ID.
     */
    public static function acknowledgeAlert(int $id): bool
    {
        $rows = Database::update('monitor_alerts', ['acknowledged' => true], 'id = :id', ['id' => $id]);
        return $rows > 0;
    }

    /**
     * Get configured network interfaces.
     */
    public static function getInterfaces(): array
    {
        $raw = Settings::get('monitor_interfaces', 'eth0,wg0');
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    /**
     * Format bytes/sec to human-readable string.
     */
    public static function formatBytes(float $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB/s';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB/s';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB/s';
        return round($bytes, 0) . ' B/s';
    }

    /**
     * Get unacknowledged alert count.
     */
    public static function getUnacknowledgedCount(?string $host = null): int
    {
        $host = self::resolveHost($host);
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM monitor_alerts WHERE host = :host AND acknowledged = false",
            ['host' => $host]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Detect available NVIDIA GPUs via nvidia-smi.
     * Returns array of ['index' => 0, 'name' => 'RTX 4090', 'memory_total' => 24576] or empty.
     */
    /**
     * Detect GPUs for a given host.
     * For the local host: runs nvidia-smi directly.
     * For any host: also checks the database for collected gpu metrics,
     * which handles remote hosts where nvidia-smi can't be run locally.
     */
    public static function detectGpus(?string $host = null): array
    {
        $host = self::resolveHost($host);
        $localHost = gethostname() ?: 'localhost';

        static $cache = [];
        if (isset($cache[$host])) return $cache[$host];

        $gpus = [];

        // For the local host, try nvidia-smi first
        if ($host === $localHost) {
            $output = @shell_exec('nvidia-smi --query-gpu=index,name,memory.total --format=csv,noheader,nounits 2>/dev/null');
            if (!empty($output)) {
                foreach (explode("\n", trim($output)) as $line) {
                    $parts = array_map('trim', explode(',', $line));
                    if (count($parts) >= 3) {
                        $gpus[] = [
                            'index'        => (int) $parts[0],
                            'name'         => $parts[1],
                            'memory_total' => (int) $parts[2],
                        ];
                    }
                }
            }
        }

        // If no GPUs found via nvidia-smi (or remote host), detect from database
        if (empty($gpus)) {
            $rows = Database::fetchAll(
                "SELECT DISTINCT metric FROM monitor_metrics
                 WHERE host = :host AND metric LIKE 'gpu%_util'
                 AND ts >= NOW() - INTERVAL '1 hour'
                 ORDER BY metric",
                ['host' => $host]
            );
            foreach ($rows as $row) {
                if (preg_match('/^gpu(\d+)_util$/', $row['metric'], $m)) {
                    $idx = (int) $m[1];
                    $gpus[] = [
                        'index'        => $idx,
                        'name'         => "GPU {$idx}",
                        'memory_total' => 0,
                    ];
                }
            }
        }

        $cache[$host] = $gpus;
        return $gpus;
    }

    /**
     * Check if this server has NVIDIA GPUs.
     */
    public static function hasGpu(?string $host = null): bool
    {
        return !empty(self::detectGpus($host));
    }

    // ─── Private helpers ─────────────────────────────────────

    private static function resolveHost(?string $host): string
    {
        return $host ?? gethostname() ?: 'localhost';
    }

    private static function getSourceTable(string $range): string
    {
        return match ($range) {
            '1h', '6h', '24h' => 'monitor_metrics',       // raw has 48h retention, covers 24h fine
            '7d'              => 'monitor_metrics_hourly',
            '30d', '1y'       => 'monitor_metrics_daily',
            default           => 'monitor_metrics_hourly',
        };
    }

    private static function getInterval(string $range): string
    {
        return match ($range) {
            '1h'  => '1 hour',
            '6h'  => '6 hours',
            '24h' => '24 hours',
            '7d'  => '7 days',
            '30d' => '30 days',
            '1y'  => '365 days',
            default => '24 hours',
        };
    }
}
