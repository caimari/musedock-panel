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

    // ─── Core API ──────────────────────────────────────────────

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

    // ─── Token verification ────────────────────────────────────

    /**
     * Verify that a Cloudflare API token is valid and list its permissions.
     */
    public static function verifyToken(string $token): array
    {
        return self::apiRequest($token, 'GET', '/user/tokens/verify');
    }

    // ─── Zone operations ───────────────────────────────────────

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

    // ─── DNS Record operations ─────────────────────────────────

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

    // ─── Batch DNS update (failover) ───────────────────────────

    /**
     * Batch-update A records: change the IP of multiple records in a zone.
     * Returns summary of successes and failures.
     *
     * @param string $token     CF API token
     * @param string $zoneId    Zone ID
     * @param string $oldIp     Current IP to match
     * @param string $newIp     New IP to set
     * @param int    $ttl       TTL to set (1 = auto)
     * @return array ['ok' => bool, 'updated' => int, 'failed' => int, 'details' => [...]]
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

    // ─── Configured accounts helper ────────────────────────────

    /**
     * Get all configured Cloudflare accounts from panel settings.
     * Returns array of ['name' => ..., 'token' => ..., 'zones' => [...]].
     */
    public static function getConfiguredAccounts(): array
    {
        $accounts = [];
        $raw = Settings::get('failover_cf_accounts', '');
        if (!$raw) return [];

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Save Cloudflare accounts configuration.
     */
    public static function saveAccounts(array $accounts): void
    {
        Settings::set('failover_cf_accounts', json_encode($accounts));
    }

    // ─── Private helpers ───────────────────────────────────────

    private static function parseError(array $response): string
    {
        if (!empty($response['errors']) && is_array($response['errors'])) {
            $msgs = array_map(fn($e) => $e['message'] ?? 'Unknown', $response['errors']);
            return implode('; ', $msgs);
        }
        return 'Unknown Cloudflare error';
    }
}
