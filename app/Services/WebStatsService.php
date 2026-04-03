<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * WebStatsService — Collects web analytics from Caddy access logs.
 *
 * Aggregates daily per-account: top pages, IPs, countries, referrers,
 * user agents, status codes, methods, unique visitors.
 *
 * Uses the same log file and offset system as BandwidthService.
 * Designed to run via the bandwidth-collector.php cron (every 10 min).
 */
class WebStatsService
{
    private const LOG_FILE = '/var/log/caddy/hosting-access.log';
    private const OFFSET_KEY = 'webstats_log_offset';

    /**
     * Parse new log entries and aggregate web stats per account per day.
     */
    public static function collectFromLog(): array
    {
        $logFile = self::LOG_FILE;
        if (!file_exists($logFile) || !is_readable($logFile)) {
            return ['ok' => false, 'error' => 'Log file not found'];
        }

        $fileSize = filesize($logFile);
        $offset = (int) Settings::get(self::OFFSET_KEY, '0');
        if ($fileSize < $offset) $offset = 0;
        if ($fileSize <= $offset) return ['ok' => true, 'lines' => 0];

        $domainMap = BandwidthService::buildDomainMapPublic();
        if (empty($domainMap)) return ['ok' => false, 'error' => 'No accounts'];

        $fh = fopen($logFile, 'r');
        if (!$fh) return ['ok' => false, 'error' => 'Cannot open log'];

        fseek($fh, $offset);
        $processed = 0;
        // [account_id][date] => ['pages' => [...], 'ips' => [...], ...]
        $stats = [];

        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;

            $entry = @json_decode($line, true);
            if (!$entry) continue;

            $host = $entry['request']['host'] ?? '';
            $ts = $entry['ts'] ?? 0;
            if (empty($host) || !$ts) continue;

            $lookupHost = preg_replace('/^www\./', '', strtolower($host));
            $accountId = $domainMap[$lookupHost] ?? $domainMap[$host] ?? null;
            if (!$accountId) continue;

            $date = date('Y-m-d', (int)$ts);
            $headers = $entry['request']['headers'] ?? [];

            // Extract fields
            $uri = $entry['request']['uri'] ?? '/';
            $method = $entry['request']['method'] ?? 'GET';
            $status = (string)($entry['status'] ?? '0');
            $size = (int)($entry['size'] ?? 0);

            // Real IP
            $ip = $headers['Cf-Connecting-Ip'][0]
               ?? $headers['X-Forwarded-For'][0]
               ?? $entry['request']['remote_ip'] ?? '';
            if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);

            // Country (only available behind Cloudflare proxy)
            $country = $headers['Cf-Ipcountry'][0] ?? '';

            // User-Agent
            $ua = $headers['User-Agent'][0] ?? '';
            $uaShort = self::simplifyUserAgent($ua);

            // Referrer
            $referer = $headers['Referer'][0] ?? '';
            $refDomain = '';
            if ($referer) {
                $parsed = parse_url($referer);
                $refDomain = $parsed['host'] ?? '';
                // Skip self-referrals and internal panel/server refs
                $serverHost = gethostname() ?: '';
                $panelExcludes = array_filter(array_map('trim', explode(',', Settings::get('stats_exclude_referrers', ''))));
                $isInternal = ($refDomain === $host || $refDomain === "www.{$host}"
                    || $refDomain === 'localhost'
                    || str_contains($refDomain, $serverHost)
                    || preg_match('/:\d{4,}$/', $refDomain)); // any port-based URL (panel, dev servers)
                if (!$isInternal) {
                    foreach ($panelExcludes as $excl) {
                        if ($excl && str_contains($refDomain, $excl)) { $isInternal = true; break; }
                    }
                }
                if ($isInternal) { $refDomain = ''; $referer = ''; }
            }

            // Clean URI (remove query string for grouping)
            $cleanUri = strtok($uri, '?');

            // Init account+date bucket
            if (!isset($stats[$accountId][$date])) {
                $stats[$accountId][$date] = [
                    'pages' => [], 'ips' => [], 'countries' => [],
                    'referrers' => [], 'referrer_urls' => [], 'uas' => [],
                    'statuses' => [], 'methods' => [],
                    'ip_set' => [],
                ];
            }
            $s = &$stats[$accountId][$date];

            // Aggregate
            $s['pages'][$cleanUri] = ($s['pages'][$cleanUri] ?? 0) + 1;
            $s['ips'][$ip] = ($s['ips'][$ip] ?? 0) + 1;
            if ($country) $s['countries'][$country] = ($s['countries'][$country] ?? 0) + 1;
            if ($refDomain) {
                $s['referrers'][$refDomain] = ($s['referrers'][$refDomain] ?? 0) + 1;
                // Store top URL per referrer domain (keep the most frequent)
                if ($referer) {
                    $s['referrer_urls'][$refDomain][$referer] = ($s['referrer_urls'][$refDomain][$referer] ?? 0) + 1;
                }
            }
            if ($uaShort) $s['uas'][$uaShort] = ($s['uas'][$uaShort] ?? 0) + 1;
            $s['statuses'][$status] = ($s['statuses'][$status] ?? 0) + 1;
            $s['methods'][$method] = ($s['methods'][$method] ?? 0) + 1;
            $s['ip_set'][$ip] = true;

            $processed++;
        }

        $newOffset = ftell($fh);
        fclose($fh);

        // Save to DB — merge with existing daily stats
        foreach ($stats as $accountId => $dates) {
            foreach ($dates as $date => $data) {
                self::upsertDayStats($accountId, $date, $data);
            }
        }

        Settings::set(self::OFFSET_KEY, (string)$newOffset);

        return ['ok' => true, 'lines' => $processed, 'accounts' => count($stats)];
    }

    /**
     * Upsert daily stats — merge new data with existing.
     */
    private static function upsertDayStats(int $accountId, string $date, array $data): void
    {
        $existing = Database::fetchOne(
            "SELECT * FROM hosting_web_stats WHERE account_id = :aid AND date = :d",
            ['aid' => $accountId, 'd' => $date]
        );

        if ($existing) {
            // Merge with existing
            $merge = function(array $new, string $jsonCol) use ($existing): array {
                $old = json_decode($existing[$jsonCol] ?: '[]', true) ?: [];
                // If it's a list of [key => count], merge counts
                if (is_array($old) && isset($old[0]['name'])) {
                    $map = [];
                    foreach ($old as $item) $map[$item['name']] = $item['count'];
                    foreach ($new as $k => $v) $map[$k] = ($map[$k] ?? 0) + $v;
                    return $map;
                }
                // If it's a raw assoc, merge
                foreach ($new as $k => $v) $old[$k] = ($old[$k] ?? 0) + $v;
                return $old;
            };

            $pages = $merge($data['pages'], 'top_pages');
            $ips = $merge($data['ips'], 'top_ips');
            $countries = $merge($data['countries'], 'top_countries');
            $referrers = $merge($data['referrers'], 'top_referrers');
            $uas = $merge($data['uas'], 'top_user_agents');
            $statuses = $merge($data['statuses'], 'status_codes');
            $methods = $merge($data['methods'], 'methods');

            $oldIps = (int)($existing['unique_ips'] ?? 0);
            $newUniqueIps = max($oldIps, count($data['ip_set']));

            // Merge referrer URLs map
            $oldRefUrls = json_decode($existing['referrer_urls'] ?: '{}', true) ?: [];
            $refUrlMap = self::buildRefUrlMap($data['referrer_urls'] ?? [], $oldRefUrls);

            Database::query("
                UPDATE hosting_web_stats SET
                    top_pages = :pages, top_ips = :ips, top_countries = :countries,
                    top_referrers = :refs, top_user_agents = :uas,
                    status_codes = :sc, methods = :m, unique_ips = :uips,
                    referrer_urls = :rurls, updated_at = NOW()
                WHERE account_id = :aid AND date = :d
            ", [
                'pages' => json_encode(self::topN($pages, 50)),
                'ips' => json_encode(self::topN($ips, 50)),
                'countries' => json_encode(self::topN($countries, 30)),
                'refs' => json_encode(self::topN($referrers, 30)),
                'uas' => json_encode(self::topN($uas, 30)),
                'sc' => json_encode($statuses),
                'm' => json_encode($methods),
                'uips' => $newUniqueIps,
                'rurls' => json_encode($refUrlMap),
                'aid' => $accountId,
                'd' => $date,
            ]);
        } else {
            $refUrlMap = self::buildRefUrlMap($data['referrer_urls'] ?? []);

            Database::insert('hosting_web_stats', [
                'account_id' => $accountId,
                'date' => $date,
                'top_pages' => json_encode(self::topN($data['pages'], 50)),
                'top_ips' => json_encode(self::topN($data['ips'], 50)),
                'top_countries' => json_encode(self::topN($data['countries'], 30)),
                'top_referrers' => json_encode(self::topN($data['referrers'], 30)),
                'top_user_agents' => json_encode(self::topN($data['uas'], 30)),
                'referrer_urls' => json_encode($refUrlMap),
                'status_codes' => json_encode($data['statuses']),
                'methods' => json_encode($data['methods']),
                'unique_ips' => count($data['ip_set']),
            ]);
        }
    }

    /**
     * Sort by count desc and keep top N items.
     */
    private static function topN(array $map, int $n): array
    {
        arsort($map);
        $result = [];
        $i = 0;
        foreach ($map as $key => $count) {
            $result[] = ['name' => (string)$key, 'count' => (int)$count];
            if (++$i >= $n) break;
        }
        return $result;
    }

    /**
     * Simplify User-Agent to a short readable name.
     */
    private static function simplifyUserAgent(string $ua): string
    {
        if (empty($ua)) return '';
        // Bots
        if (preg_match('/Googlebot/i', $ua)) return 'Googlebot';
        if (preg_match('/bingbot/i', $ua)) return 'Bingbot';
        if (preg_match('/Yandex/i', $ua)) return 'YandexBot';
        if (preg_match('/Baidu/i', $ua)) return 'Baidu';
        if (preg_match('/DuckDuckBot/i', $ua)) return 'DuckDuckBot';
        if (preg_match('/facebookexternalhit|Facebot/i', $ua)) return 'Facebook';
        if (preg_match('/Twitterbot/i', $ua)) return 'Twitter';
        if (preg_match('/LinkedInBot/i', $ua)) return 'LinkedIn';
        if (preg_match('/Slurp/i', $ua)) return 'Yahoo';
        if (preg_match('/Applebot/i', $ua)) return 'Applebot';
        if (preg_match('/AhrefsBot/i', $ua)) return 'AhrefsBot';
        if (preg_match('/SemrushBot/i', $ua)) return 'SemrushBot';
        if (preg_match('/MJ12bot/i', $ua)) return 'MajesticBot';
        if (preg_match('/curl/i', $ua)) return 'curl';
        if (preg_match('/python|scrapy|httpx/i', $ua)) return 'Python';
        if (preg_match('/Go-http-client/i', $ua)) return 'Go Bot';
        if (preg_match('/bot|crawler|spider|scan/i', $ua)) return 'Other Bot';
        // Browsers
        if (preg_match('/Edg/i', $ua)) return 'Edge';
        if (preg_match('/Chrome/i', $ua) && !preg_match('/Edg|OPR/i', $ua)) return 'Chrome';
        if (preg_match('/Firefox/i', $ua)) return 'Firefox';
        if (preg_match('/Safari/i', $ua) && !preg_match('/Chrome/i', $ua)) return 'Safari';
        if (preg_match('/OPR|Opera/i', $ua)) return 'Opera';
        if (preg_match('/MSIE|Trident/i', $ua)) return 'IE';
        return 'Other';
    }

    /**
     * Get stats for an account for a date range.
     */
    public static function getStats(int $accountId, string $range = '7d'): array
    {
        $intervals = [
            '1d' => '1 day', '7d' => '7 days', '30d' => '30 days', '1y' => '365 days',
        ];
        $interval = $intervals[$range] ?? '7 days';

        $rows = Database::fetchAll("
            SELECT date, top_pages, top_ips, top_countries, top_referrers,
                   top_user_agents, status_codes, methods, unique_ips
            FROM hosting_web_stats
            WHERE account_id = :aid AND date >= (CURRENT_DATE - INTERVAL '{$interval}')
            ORDER BY date
        ", ['aid' => $accountId]);

        if (empty($rows)) return self::emptyStats();

        // Merge all days
        $merged = [
            'pages' => [], 'ips' => [], 'countries' => [],
            'referrers' => [], 'referrer_urls' => [], 'uas' => [], 'statuses' => [],
            'methods' => [], 'unique_ips_total' => 0,
            'days' => [],
        ];

        foreach ($rows as $row) {
            $merged['days'][] = [
                'date' => $row['date'],
                'unique_ips' => (int)$row['unique_ips'],
            ];
            $merged['unique_ips_total'] += (int)$row['unique_ips'];

            foreach (['top_pages' => 'pages', 'top_ips' => 'ips', 'top_countries' => 'countries', 'top_referrers' => 'referrers', 'top_user_agents' => 'uas'] as $col => $key) {
                $items = json_decode($row[$col] ?: '[]', true) ?: [];
                foreach ($items as $item) {
                    $n = $item['name'] ?? '';
                    if ($n) $merged[$key][$n] = ($merged[$key][$n] ?? 0) + ($item['count'] ?? 0);
                }
            }
            foreach (['status_codes' => 'statuses', 'methods' => 'methods'] as $col => $key) {
                $items = json_decode($row[$col] ?: '{}', true) ?: [];
                foreach ($items as $k => $v) {
                    $merged[$key][$k] = ($merged[$key][$k] ?? 0) + $v;
                }
            }
            // Merge referrer URL map
            $refUrls = json_decode($row['referrer_urls'] ?? '{}', true) ?: [];
            foreach ($refUrls as $domain => $url) {
                $merged['referrer_urls'][$domain] = $url;
            }
        }

        // Sort and limit
        return [
            'pages' => self::topN($merged['pages'], 30),
            'ips' => self::topN($merged['ips'], 30),
            'countries' => self::topN($merged['countries'], 20),
            'referrers' => self::topN($merged['referrers'], 20),
            'referrer_urls' => $merged['referrer_urls'],
            'user_agents' => self::topN($merged['uas'], 20),
            'status_codes' => $merged['statuses'],
            'methods' => $merged['methods'],
            'unique_visitors' => $merged['unique_ips_total'],
            'days' => $merged['days'],
        ];
    }

    /**
     * Build a map of domain → most frequent full URL for that domain.
     */
    private static function buildRefUrlMap(array $newData, array $existing = []): array
    {
        $map = $existing;
        foreach ($newData as $domain => $urls) {
            if (!is_array($urls)) continue;
            // Find the URL with most hits for this domain
            arsort($urls);
            $topUrl = array_key_first($urls);
            if ($topUrl) {
                // Keep whichever has more total hits
                $map[$domain] = $topUrl;
            }
        }
        return $map;
    }

    private static function emptyStats(): array
    {
        return [
            'pages' => [], 'ips' => [], 'countries' => [],
            'referrers' => [], 'referrer_urls' => [], 'user_agents' => [],
            'status_codes' => [], 'methods' => [],
            'unique_visitors' => 0, 'days' => [],
        ];
    }
}
