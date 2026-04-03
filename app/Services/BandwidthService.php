<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * BandwidthService — Parses Caddy access logs and aggregates bandwidth per hosting account per day.
 *
 * Log format: JSON lines from Caddy's hosting-access logger.
 * Each line has: request.host, size (response bytes), status, ts (unix timestamp).
 *
 * Uses a byte-offset cursor (stored in panel_settings) to resume parsing
 * after log rotation or between runs. Only processes new lines.
 */
class BandwidthService
{
    private const LOG_FILE = '/var/log/caddy/hosting-access.log';
    private const OFFSET_KEY = 'bandwidth_log_offset';

    /**
     * Parse new log entries and aggregate into hosting_bandwidth table.
     * Returns number of lines processed.
     */
    public static function collectFromLog(): array
    {
        $logFile = self::LOG_FILE;
        if (!file_exists($logFile) || !is_readable($logFile)) {
            return ['ok' => false, 'error' => 'Log file not found or not readable', 'lines' => 0];
        }

        $fileSize = filesize($logFile);
        $offset = (int) Settings::get(self::OFFSET_KEY, '0');

        // If file is smaller than offset, it was rotated — start from beginning
        if ($fileSize < $offset) {
            $offset = 0;
        }

        // Nothing new
        if ($fileSize <= $offset) {
            return ['ok' => true, 'lines' => 0, 'message' => 'No new data'];
        }

        // Build domain → account_id map
        $domainMap = self::buildDomainMap();
        if (empty($domainMap)) {
            return ['ok' => false, 'error' => 'No hosting accounts found', 'lines' => 0];
        }

        // Read new lines from offset
        $fh = fopen($logFile, 'r');
        if (!$fh) {
            return ['ok' => false, 'error' => 'Cannot open log file', 'lines' => 0];
        }

        // Build subdomain → subdomain_id map
        $subdomainIdMap = self::buildSubdomainIdMap();

        fseek($fh, $offset);
        $processed = 0;
        $aggregated = [];    // [account_id][hour] => ['bytes_out' => int, 'bytes_in' => int, 'requests' => int]
        $subAggregated = []; // [subdomain_id][hour] => ['bytes_out' => int, 'bytes_in' => int, 'requests' => int]

        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;

            $entry = @json_decode($line, true);
            if (!$entry) continue;

            $host = $entry['request']['host'] ?? '';
            $size = (int)($entry['size'] ?? 0);
            $bytesRead = (int)($entry['bytes_read'] ?? 0);
            $ts = $entry['ts'] ?? 0;

            // Real client IP: Cf-Connecting-Ip (behind Cloudflare) or remote_ip (direct)
            $headers = $entry['request']['headers'] ?? [];
            $clientIp = $headers['Cf-Connecting-Ip'][0]
                     ?? $headers['X-Forwarded-For'][0]
                     ?? $entry['request']['remote_ip']
                     ?? '';
            // X-Forwarded-For can have multiple IPs (client, proxy1, proxy2) — take first
            if (str_contains($clientIp, ',')) {
                $clientIp = trim(explode(',', $clientIp)[0]);
            }

            if (empty($host) || ($size <= 0 && $bytesRead <= 0)) continue;

            // Remove www. prefix for lookup
            $lookupHost = preg_replace('/^www\./', '', strtolower($host));
            $accountId = $domainMap[$lookupHost] ?? $domainMap[$host] ?? null;
            if (!$accountId) continue;

            // Hourly bucket: "2026-04-02 14:00:00"
            $hour = date('Y-m-d H:00:00', (int)$ts);

            // Account-level aggregation (includes subdomain traffic)
            if (!isset($aggregated[$accountId][$hour])) {
                $aggregated[$accountId][$hour] = ['bytes_out' => 0, 'bytes_in' => 0, 'requests' => 0];
            }
            $aggregated[$accountId][$hour]['bytes_out'] += $size;
            $aggregated[$accountId][$hour]['bytes_in'] += $bytesRead;
            $aggregated[$accountId][$hour]['requests'] += 1;

            // Subdomain-level aggregation
            $subId = $subdomainIdMap[$lookupHost] ?? $subdomainIdMap[$host] ?? null;
            if ($subId) {
                if (!isset($subAggregated[$subId][$hour])) {
                    $subAggregated[$subId][$hour] = ['bytes_out' => 0, 'bytes_in' => 0, 'requests' => 0];
                }
                $subAggregated[$subId][$hour]['bytes_out'] += $size;
                $subAggregated[$subId][$hour]['bytes_in'] += $bytesRead;
                $subAggregated[$subId][$hour]['requests'] += 1;
            }

            $processed++;
        }

        $newOffset = ftell($fh);
        fclose($fh);

        // Upsert aggregated data
        foreach ($aggregated as $accountId => $dates) {
            foreach ($dates as $date => $data) {
                Database::query("
                    INSERT INTO hosting_bandwidth (account_id, ts, bytes_out, bytes_in, requests, updated_at)
                    VALUES (:aid, :d, :bo, :bi, :r, NOW())
                    ON CONFLICT (account_id, ts)
                    DO UPDATE SET bytes_out = hosting_bandwidth.bytes_out + :bo2,
                                  bytes_in = hosting_bandwidth.bytes_in + :bi2,
                                  requests = hosting_bandwidth.requests + :r2,
                                  updated_at = NOW()
                ", [
                    'aid' => $accountId,
                    'd' => $date,
                    'bo' => $data['bytes_out'],
                    'bi' => $data['bytes_in'],
                    'r' => $data['requests'],
                    'bo2' => $data['bytes_out'],
                    'bi2' => $data['bytes_in'],
                    'r2' => $data['requests'],
                ]);
            }
        }

        // Upsert subdomain bandwidth
        foreach ($subAggregated as $subId => $dates) {
            foreach ($dates as $date => $data) {
                Database::query("
                    INSERT INTO hosting_subdomain_bandwidth (subdomain_id, ts, bytes_out, bytes_in, requests, updated_at)
                    VALUES (:sid, :d, :bo, :bi, :r, NOW())
                    ON CONFLICT (subdomain_id, ts)
                    DO UPDATE SET bytes_out = hosting_subdomain_bandwidth.bytes_out + :bo2,
                                  bytes_in = hosting_subdomain_bandwidth.bytes_in + :bi2,
                                  requests = hosting_subdomain_bandwidth.requests + :r2,
                                  updated_at = NOW()
                ", [
                    'sid' => $subId,
                    'd' => $date,
                    'bo' => $data['bytes_out'],
                    'bi' => $data['bytes_in'],
                    'r' => $data['requests'],
                    'bo2' => $data['bytes_out'],
                    'bi2' => $data['bytes_in'],
                    'r2' => $data['requests'],
                ]);
            }
        }

        // Save new offset
        Settings::set(self::OFFSET_KEY, (string)$newOffset);

        return [
            'ok' => true,
            'lines' => $processed,
            'accounts' => count($aggregated),
            'offset' => $newOffset,
        ];
    }

    /**
     * Build a map of domain/subdomain → account_id.
     */
    public static function buildDomainMapPublic(): array { return self::buildDomainMap(); }

    private static function buildDomainMap(): array
    {
        $map = [];

        // Main domains
        $accounts = Database::fetchAll("SELECT id, domain FROM hosting_accounts");
        foreach ($accounts as $acc) {
            $map[strtolower($acc['domain'])] = (int)$acc['id'];
        }

        // Aliases
        $aliases = Database::fetchAll("SELECT hosting_account_id, domain FROM hosting_domain_aliases");
        foreach ($aliases as $alias) {
            $map[strtolower($alias['domain'])] = (int)$alias['hosting_account_id'];
        }

        // Subdomains — attribute traffic to parent account
        $subs = Database::fetchAll("SELECT account_id, subdomain FROM hosting_subdomains");
        foreach ($subs as $sub) {
            $map[strtolower($sub['subdomain'])] = (int)$sub['account_id'];
        }

        return $map;
    }

    /**
     * Build a map of subdomain domain → subdomain_id.
     */
    private static function buildSubdomainIdMap(): array
    {
        $map = [];
        $subs = Database::fetchAll("SELECT id, subdomain FROM hosting_subdomains");
        foreach ($subs as $sub) {
            $map[strtolower($sub['subdomain'])] = (int)$sub['id'];
        }
        return $map;
    }

    /**
     * Get current month totals for all subdomains of an account.
     * Returns array indexed by subdomain_id.
     */
    public static function getSubdomainMonthlyTotals(int $accountId): array
    {
        $rows = Database::fetchAll("
            SELECT sb.subdomain_id,
                   COALESCE(SUM(sb.bytes_out), 0) as bytes_out,
                   COALESCE(SUM(sb.bytes_in), 0) as bytes_in,
                   COALESCE(SUM(sb.requests), 0) as requests
            FROM hosting_subdomain_bandwidth sb
            JOIN hosting_subdomains s ON s.id = sb.subdomain_id
            WHERE s.account_id = :aid AND sb.ts >= DATE_TRUNC('month', CURRENT_DATE)
            GROUP BY sb.subdomain_id
        ", ['aid' => $accountId]);

        $map = [];
        foreach ($rows as $row) { $map[(int)$row['subdomain_id']] = $row; }
        return $map;
    }

    public static function getAllSubdomainMonthlyTotals(): array
    {
        $rows = Database::fetchAll("
            SELECT subdomain_id, COALESCE(SUM(bytes_out), 0) as bytes_out, COALESCE(SUM(bytes_in), 0) as bytes_in, COALESCE(SUM(requests), 0) as requests
            FROM hosting_subdomain_bandwidth WHERE ts >= DATE_TRUNC('month', CURRENT_DATE) GROUP BY subdomain_id
        ");
        $map = [];
        foreach ($rows as $row) { $map[(int)$row['subdomain_id']] = $row; }
        return $map;
    }

    /**
     * Get bandwidth for an account with range selector (like monitor charts).
     *
     * @param string $range  '1h', '6h', '24h', '7d', '30d', '1y'
     */
    public static function getByAccount(int $accountId, string $range = '24h'): array
    {
        $intervals = [
            '1h'  => ['interval' => '1 hour',    'group' => null],         // raw hourly buckets
            '6h'  => ['interval' => '6 hours',   'group' => null],
            '24h' => ['interval' => '24 hours',  'group' => null],
            '7d'  => ['interval' => '7 days',    'group' => 'hour'],       // hourly
            '30d' => ['interval' => '30 days',   'group' => 'day'],        // daily
            '1y'  => ['interval' => '365 days',  'group' => 'month'],      // monthly
        ];

        $cfg = $intervals[$range] ?? $intervals['24h'];

        if ($cfg['group']) {
            $safeGroup = in_array($cfg['group'], ['hour', 'day', 'month'], true) ? $cfg['group'] : 'hour';
            return Database::fetchAll("
                SELECT EXTRACT(EPOCH FROM DATE_TRUNC('{$safeGroup}', ts))::bigint as period,
                       SUM(bytes_out) as bytes_out,
                       SUM(bytes_in) as bytes_in,
                       SUM(requests) as requests
                FROM hosting_bandwidth
                WHERE account_id = :aid AND ts >= (NOW() - INTERVAL '{$cfg['interval']}')
                GROUP BY 1 ORDER BY 1
            ", ['aid' => $accountId]);
        }

        // Raw hourly data
        return Database::fetchAll("
            SELECT EXTRACT(EPOCH FROM ts)::bigint as period, bytes_out, bytes_in, requests
            FROM hosting_bandwidth
            WHERE account_id = :aid AND ts >= (NOW() - INTERVAL '{$cfg['interval']}')
            ORDER BY ts
        ", ['aid' => $accountId]);
    }

    public static function getMonthlyTotal(int $accountId): array
    {
        $row = Database::fetchOne("
            SELECT COALESCE(SUM(bytes_out), 0) as bytes_out, COALESCE(SUM(bytes_in), 0) as bytes_in, COALESCE(SUM(requests), 0) as requests
            FROM hosting_bandwidth WHERE account_id = :aid AND ts >= DATE_TRUNC('month', CURRENT_DATE)
        ", ['aid' => $accountId]);
        return $row ?: ['bytes_out' => 0, 'requests' => 0];
    }

    public static function getAllMonthlyTotals(): array
    {
        $rows = Database::fetchAll("
            SELECT account_id, COALESCE(SUM(bytes_out), 0) as bytes_out, COALESCE(SUM(bytes_in), 0) as bytes_in, COALESCE(SUM(requests), 0) as requests
            FROM hosting_bandwidth WHERE ts >= DATE_TRUNC('month', CURRENT_DATE) GROUP BY account_id
        ");
        $map = [];
        foreach ($rows as $row) { $map[(int)$row['account_id']] = $row; }
        return $map;
    }
}
