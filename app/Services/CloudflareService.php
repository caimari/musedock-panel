<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * CloudflareService — Cloudflare API v4 client for DNS management.
 * Used by the failover system to batch-update A/CNAME records.
 * Supports multiple Cloudflare accounts (primary + custom domains).
 */
class CloudflareService
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * Known Cloudflare IPv4 ranges.
     * Source: https://www.cloudflare.com/ips-v4/
     */
    private const CF_IPV4_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    // --- Core API ---------------------------------------------------------

    /**
     * Make an authenticated request to Cloudflare API.
     */
    public static function apiRequest(string $token, string $method, string $endpoint, array $data = [], int $timeout = 15): array
    {
        $url = self::API_BASE . $endpoint;

        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        if ($method !== 'GET' && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error) {
            return ['ok' => false, 'error' => $error ?: 'Connection failed', 'http_code' => $httpCode];
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'error' => 'Invalid JSON from Cloudflare', 'http_code' => $httpCode];
        }

        $ok = !empty($decoded['success']);
        return [
            'ok'        => $ok,
            'result'    => $decoded['result'] ?? null,
            'errors'    => $decoded['errors'] ?? [],
            'error'     => $ok ? '' : self::parseError($decoded),
            'http_code' => $httpCode,
        ];
    }

    // --- Token verification -----------------------------------------------

    /**
     * Verify that a Cloudflare API token is valid and list its permissions.
     */
    public static function verifyToken(string $token): array
    {
        return self::apiRequest($token, 'GET', '/user/tokens/verify');
    }

    // --- Zone operations --------------------------------------------------

    /**
     * List zones accessible by this token.
     */
    public static function listZones(string $token, int $page = 1, int $perPage = 50): array
    {
        return self::apiRequest($token, 'GET', '/zones', [
            'page'     => $page,
            'per_page' => $perPage,
            'status'   => 'active',
        ]);
    }

    /**
     * Get zone details by ID.
     */
    public static function getZone(string $token, string $zoneId): array
    {
        return self::apiRequest($token, 'GET', "/zones/{$zoneId}");
    }

    // --- DNS Record operations --------------------------------------------

    /**
     * List DNS records for a zone, optionally filtered.
     */
    public static function listRecords(string $token, string $zoneId, array $filters = []): array
    {
        $params = array_merge(['per_page' => 100], $filters);
        return self::apiRequest($token, 'GET', "/zones/{$zoneId}/dns_records", $params);
    }

    /**
     * Get all A and CNAME records for a zone (paginated).
     */
    public static function getAllDomainRecords(string $token, string $zoneId): array
    {
        $all = [];
        $page = 1;
        do {
            $resp = self::apiRequest($token, 'GET', "/zones/{$zoneId}/dns_records", [
                'per_page' => 100,
                'page'     => $page,
                'type'     => 'A,CNAME',
            ]);
            if (!$resp['ok']) return $resp;
            $records = $resp['result'] ?? [];
            $all = array_merge($all, $records);
            $page++;
        } while (count($records) === 100);

        return ['ok' => true, 'result' => $all];
    }

    /**
     * Update a DNS record (change IP, TTL, proxy status).
     */
    public static function updateRecord(string $token, string $zoneId, string $recordId, array $data): array
    {
        return self::apiRequest($token, 'PATCH', "/zones/{$zoneId}/dns_records/{$recordId}", $data);
    }

    /**
     * Create a DNS record.
     */
    public static function createRecord(string $token, string $zoneId, array $data): array
    {
        return self::apiRequest($token, 'POST', "/zones/{$zoneId}/dns_records", $data);
    }

    /**
     * Delete a DNS record.
     */
    public static function deleteRecord(string $token, string $zoneId, string $recordId): array
    {
        return self::apiRequest($token, 'DELETE', "/zones/{$zoneId}/dns_records/{$recordId}");
    }

    // --- Batch DNS update (failover) --------------------------------------

    /**
     * Batch-update A records: change the IP of multiple records in a zone.
     */
    public static function batchUpdateIp(string $token, string $zoneId, string $oldIp, string $newIp, int $ttl = 60): array
    {
        $records = self::listRecords($token, $zoneId, ['type' => 'A', 'content' => $oldIp, 'per_page' => 100]);
        if (!$records['ok']) {
            return ['ok' => false, 'error' => $records['error'], 'updated' => 0, 'failed' => 0, 'details' => []];
        }

        $updated = 0;
        $failed  = 0;
        $details = [];

        foreach ($records['result'] ?? [] as $record) {
            $result = self::updateRecord($token, $zoneId, $record['id'], [
                'type'    => 'A',
                'name'    => $record['name'],
                'content' => $newIp,
                'ttl'     => $ttl,
                'proxied' => $record['proxied'] ?? false,
            ]);

            if ($result['ok']) {
                $updated++;
                $details[] = ['name' => $record['name'], 'ok' => true];
            } else {
                $failed++;
                $details[] = ['name' => $record['name'], 'ok' => false, 'error' => $result['error']];
            }
        }

        return [
            'ok'      => $failed === 0,
            'updated' => $updated,
            'failed'  => $failed,
            'details' => $details,
        ];
    }

    /**
     * Batch-update TTL for all A records matching an IP in a zone.
     */
    public static function batchUpdateTtl(string $token, string $zoneId, string $ip, int $newTtl): array
    {
        $records = self::listRecords($token, $zoneId, ['type' => 'A', 'content' => $ip, 'per_page' => 100]);
        if (!$records['ok']) return $records;

        $updated = 0;
        foreach ($records['result'] ?? [] as $record) {
            if (($record['ttl'] ?? 0) !== $newTtl) {
                self::updateRecord($token, $zoneId, $record['id'], [
                    'type'    => 'A',
                    'name'    => $record['name'],
                    'content' => $record['content'],
                    'ttl'     => $newTtl,
                    'proxied' => $record['proxied'] ?? false,
                ]);
                $updated++;
            }
        }

        return ['ok' => true, 'updated' => $updated];
    }

    // --- Configured accounts helper ---------------------------------------

    /**
     * Get all configured Cloudflare accounts from panel settings.
     * Returns array of ['name' => ..., 'token' => ..., 'zones' => [...]].
     */
    public static function getConfiguredAccounts(): array
    {
        $raw = Settings::get('failover_cf_accounts', '');
        if (!$raw) return [];

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];

        // Decrypt tokens (they are stored encrypted with AES-256-CBC)
        foreach ($decoded as &$acct) {
            if (!empty($acct['token'])) {
                $decrypted = ReplicationService::decryptPassword($acct['token']);
                // If decryption succeeds, use it; otherwise token was stored in plain text (legacy)
                if ($decrypted !== '') {
                    $acct['token'] = $decrypted;
                }
            }
        }

        return $decoded;
    }

    /**
     * Save Cloudflare accounts configuration.
     */
    public static function saveAccounts(array $accounts): void
    {
        Settings::set('failover_cf_accounts', json_encode($accounts));
    }

    // --- DNS / IP detection -----------------------------------------------

    /**
     * Check if an IP belongs to Cloudflare.
     */
    public static function isCloudflareIp(string $ip): bool
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) return false;

        foreach (self::CF_IPV4_RANGES as $cidr) {
            [$subnet, $mask] = explode('/', $cidr);
            $subnetLong = ip2long($subnet);
            $maskLong   = ~((1 << (32 - (int)$mask)) - 1);
            if (($ipLong & $maskLong) === ($subnetLong & $maskLong)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Analyse DNS for a domain. Returns status + IPs.
     * Statuses: 'ok', 'cloudflare', 'elsewhere', 'none'.
     */
    public static function checkDomainDns(string $domain, string $serverIp): array
    {
        $records = @dns_get_record($domain, DNS_A);
        $ips = [];
        $pointsHere = false;
        $isCloudflare = false;

        if ($records) {
            foreach ($records as $r) {
                $ip = $r['ip'] ?? '';
                if ($ip === '') continue;
                $ips[] = $ip;
                if ($ip === $serverIp) $pointsHere = true;
                if (self::isCloudflareIp($ip)) $isCloudflare = true;
            }
        }

        if (empty($ips)) {
            $status = 'none';
        } elseif ($pointsHere) {
            $status = 'ok';
        } elseif ($isCloudflare) {
            $status = 'cloudflare';
        } else {
            $status = 'elsewhere';
        }

        return [
            'status'    => $status,
            'ips'       => $ips,
            'server_ip' => $serverIp,
        ];
    }

    // --- Zone lookup helpers ----------------------------------------------

    /**
     * Find zone ID for a domain from configured accounts.
     */
    public static function findZoneForDomain(string $domain): ?array
    {
        $accounts = self::getConfiguredAccounts();
        $parts = explode('.', $domain);
        $root = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $domain;

        foreach ($accounts as $account) {
            $token = $account['token'] ?? '';
            if (!$token) continue;

            foreach ($account['zones'] ?? [] as $zone) {
                if (($zone['name'] ?? '') === $root) {
                    return [
                        'token'   => $token,
                        'zone_id' => $zone['id'],
                        'zone'    => $zone['name'],
                        'account' => $account['name'] ?? '',
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Get all DNS records for a domain by name filter.
     */
    public static function getRecordsForDomain(string $token, string $zoneId, string $domain): array
    {
        return self::listRecords($token, $zoneId, ['name' => $domain]);
    }

    // --- Private helpers --------------------------------------------------

    private static function parseError(array $response): string
    {
        if (!empty($response['errors']) && is_array($response['errors'])) {
            $msgs = array_map(fn($e) => $e['message'] ?? 'Unknown', $response['errors']);
            return implode('; ', $msgs);
        }
        return 'Unknown Cloudflare error';
    }
}
