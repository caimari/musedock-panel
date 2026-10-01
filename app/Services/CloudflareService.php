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
     * TODAS las zonas de la cuenta, recorriendo todas las páginas de la API.
     * Mismo formato de retorno que listZones() (ok/result/error).
     *
     * listZones() solo devuelve una página (50 zonas). Usarlo para "todas las
     * zonas" dejaba fuera a las cuentas con más de 50: el failover DNS no las
     * repuntaba y el publicador de DNS de correo no las encontraba.
     */
    public static function listAllZones(string $token): array
    {
        $all = [];
        for ($page = 1; $page <= 40; $page++) {        // tope: 2.000 zonas
            $resp = self::listZones($token, $page, 50);
            if (!($resp['ok'] ?? false)) {
                if ($page === 1) {
                    return $resp;                       // error real: se propaga igual
                }
                // Fallo a mitad: mejor devolver lo leído que perder todo, pero avisando.
                return ['ok' => true, 'result' => $all, 'errors' => $resp['errors'] ?? [],
                    'error' => 'Lectura de zonas incompleta a partir de la página ' . $page . ': ' . ($resp['error'] ?? ''),
                    'http_code' => $resp['http_code'] ?? 0, 'partial' => true];
            }
            $batch = is_array($resp['result'] ?? null) ? $resp['result'] : [];
            array_push($all, ...$batch);
            if (count($batch) < 50) {
                break;
            }
        }
        return ['ok' => true, 'result' => $all, 'errors' => [], 'error' => '', 'http_code' => 200];
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
    /**
     * Todos los registros A de una zona que apuntan a una IP, paginando: con
     * per_page=100 a secas, una zona con más de 100 registros en esa IP (p. ej.
     * subdominios de tenants) se quedaba a medias en un relevo.
     */
    public static function listARecordsByIp(string $token, string $zoneId, string $ip): array
    {
        return self::listRecordsAll($token, $zoneId, ['type' => 'A', 'content' => $ip]);
    }

    /** listRecords con todas las páginas. */
    public static function listRecordsAll(string $token, string $zoneId, array $filters): array
    {
        $all = [];
        for ($page = 1; $page <= 50; $page++) {
            $resp = self::listRecords($token, $zoneId, array_merge($filters, ['per_page' => 100, 'page' => $page]));
            if (!$resp['ok']) {
                return $resp;
            }
            $rows = $resp['result'] ?? [];
            $all = array_merge($all, $rows);
            if (count($rows) < 100) {
                break;
            }
        }
        return ['ok' => true, 'result' => $all];
    }

    /** TTL válido para el registro: los que van por el proxy de Cloudflare solo admiten 1 (automático). */
    private static function ttlFor(array $record, int $ttl): int
    {
        return !empty($record['proxied']) ? 1 : $ttl;
    }

    /**
     * Diario de registros movidos por un relevo (Settings $journal): para que la
     * vuelta devuelva SOLO lo que se movió. Antes, el failback cambiaba todo lo
     * que apuntaba a la IP del servidor de relevo, incluidos sus propios
     * registros de siempre (p. ej. filemon.musedock.com) y otros servicios en esa IP.
     */
    private static function journalAdd(string $journal, string $zoneId, array $record, string $from, string $to): void
    {
        $list = json_decode(Settings::get($journal, '[]'), true);
        $list = is_array($list) ? $list : [];
        $key = $zoneId . '/' . $record['id'];
        $list[$key] = ['zone' => $zoneId, 'id' => (string)$record['id'], 'name' => (string)$record['name'],
            'from' => $from, 'to' => $to, 'at' => date('Y-m-d H:i:s')];
        Settings::set($journal, json_encode($list, JSON_UNESCAPED_SLASHES));
    }

    /** Lo anotado en el diario (para mostrarlo o revisarlo). */
    public static function journal(string $journal): array
    {
        $list = json_decode(Settings::get($journal, '[]'), true);
        return is_array($list) ? array_values($list) : [];
    }

    /**
     * Devuelve a su IP original los registros anotados en el diario que hoy
     * siguen apuntando a $currentIp. Lo que alguien haya cambiado después a mano
     * no se toca. Sin diario no hace nada (no se devuelve a ciegas).
     */
    public static function revertJournal(string $journal, string $token, string $zoneId, string $currentIp, int $ttl = 300): array
    {
        $list = json_decode(Settings::get($journal, '[]'), true);
        $list = is_array($list) ? $list : [];
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($list as $key => $e) {
            if (($e['zone'] ?? '') !== $zoneId || ($e['to'] ?? '') !== $currentIp) {
                continue;
            }
            $cur = self::apiRequest($token, 'GET', "/zones/{$zoneId}/dns_records/" . rawurlencode((string)$e['id']));
            $rec = $cur['result'] ?? null;
            if (!$cur['ok'] || !is_array($rec)) {
                $failed++;
                continue;
            }
            if (($rec['content'] ?? '') !== $currentIp) {
                $skipped++;          // ya no apunta donde lo dejó el relevo: no se toca
                unset($list[$key]);
                continue;
            }
            $r = self::updateRecord($token, $zoneId, (string)$e['id'], [
                'type' => 'A', 'name' => $rec['name'], 'content' => (string)$e['from'],
                'ttl' => self::ttlFor($rec, $ttl), 'proxied' => $rec['proxied'] ?? false,
            ]);
            if ($r['ok']) {
                $updated++;
                unset($list[$key]);
            } else {
                $failed++;
            }
        }
        Settings::set($journal, json_encode($list, JSON_UNESCAPED_SLASHES));
        return ['ok' => $failed === 0, 'updated' => $updated, 'skipped' => $skipped, 'failed' => $failed];
    }

    public static function batchUpdateIp(string $token, string $zoneId, string $oldIp, string $newIp, int $ttl = 60, ?string $journal = null): array
    {
        $records = self::listARecordsByIp($token, $zoneId, $oldIp);
        if (!$records['ok']) {
            return ['ok' => false, 'error' => $records['error'] ?? 'error', 'updated' => 0, 'failed' => 0, 'details' => []];
        }

        $updated = 0;
        $failed  = 0;
        $details = [];

        foreach ($records['result'] ?? [] as $record) {
            $result = self::updateRecord($token, $zoneId, $record['id'], [
                'type'    => 'A',
                'name'    => $record['name'],
                'content' => $newIp,
                'ttl'     => self::ttlFor($record, $ttl),
                'proxied' => $record['proxied'] ?? false,
            ]);

            if ($result['ok']) {
                $updated++;
                $details[] = ['name' => $record['name'], 'ok' => true];
                if ($journal !== null) {
                    self::journalAdd($journal, $zoneId, $record, $oldIp, $newIp);
                }
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
        $records = self::listARecordsByIp($token, $zoneId, $ip);
        if (!$records['ok']) return $records;

        $updated = 0;
        foreach ($records['result'] ?? [] as $record) {
            if (!empty($record['proxied'])) {
                continue; // por el proxy el TTL es siempre automático
            }
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

        // Decrypt tokens (stored encrypted-at-rest). If legacy plain token is found,
        // return plain in memory and schedule re-encryption at rest.
        $needsReencrypt = false;
        foreach ($decoded as &$acct) {
            if (!empty($acct['token'])) {
                $decrypted = ReplicationService::decryptPassword($acct['token']);
                // If decryption succeeds, use it; otherwise token was stored in plain text (legacy)
                if ($decrypted !== '') {
                    $acct['token'] = $decrypted;
                } else {
                    $needsReencrypt = true;
                }
            }
        }
        unset($acct);

        if ($needsReencrypt) {
            // Persist encrypted-at-rest normalization transparently.
            self::saveAccounts($decoded);
        }

        return $decoded;
    }

    /**
     * Save Cloudflare accounts configuration.
     */
    public static function saveAccounts(array $accounts): void
    {
        Settings::set('failover_cf_accounts', json_encode(self::normalizeAccountsEncrypted($accounts)));
    }

    /**
     * Refresh zone lists from Cloudflare API for all configured accounts.
     * Updates stored zones and regenerates TLS policies so new domains get the right token.
     * Returns true if any zones were added or removed.
     */
    public static function refreshZones(): bool
    {
        $raw = Settings::get('failover_cf_accounts', '');
        if (!$raw) return false;

        $accounts = json_decode($raw, true);
        if (!is_array($accounts) || empty($accounts)) return false;

        $changed = false;
        foreach ($accounts as &$acct) {
            $token = $acct['token'] ?? '';
            if (!$token) continue;

            // Decrypt token if encrypted
            $decrypted = ReplicationService::decryptPassword($token);
            $plainToken = ($decrypted !== '') ? $decrypted : $token;

            $resp = self::listAllZones($plainToken);
            if (!($resp['ok'] ?? false) || empty($resp['result'])) continue;

            $newZones = [];
            foreach ($resp['result'] as $z) {
                $newZones[] = ['id' => $z['id'], 'name' => $z['name']];
            }

            // Compare with stored zones
            $oldNames = array_column($acct['zones'] ?? [], 'name');
            $newNames = array_column($newZones, 'name');
            sort($oldNames);
            sort($newNames);
            if ($oldNames !== $newNames) {
                $acct['zones'] = $newZones;
                $changed = true;
            }
        }
        unset($acct);

        if ($changed) {
            self::saveAccounts($accounts);
        }

        return $changed;
    }

    /**
     * Ensure Cloudflare account tokens are encrypted-at-rest.
     * Accepts plain or encrypted tokens and always returns encrypted storage format.
     */
    private static function normalizeAccountsEncrypted(array $accounts): array
    {
        foreach ($accounts as &$acct) {
            $tokenRaw = trim((string)($acct['token'] ?? ''));
            if ($tokenRaw === '') {
                continue;
            }

            // Already encrypted? keep as-is.
            $dec = ReplicationService::decryptPassword($tokenRaw);
            if ($dec !== '') {
                continue;
            }

            // Legacy/plain -> encrypt before persisting.
            $acct['token'] = ReplicationService::encryptPassword($tokenRaw);
        }
        unset($acct);

        return $accounts;
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
