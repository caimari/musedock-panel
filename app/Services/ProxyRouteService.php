<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;

/**
 * ProxyRouteService — Manages permanent SNI proxy routes via caddy-l4.
 *
 * Unlike failover (temporary, emergency), proxy routes are always active.
 * They allow domains hosted on internal/NAT servers to be served through
 * a server with a public IP using SNI-based TCP passthrough.
 */
class ProxyRouteService
{
    /**
     * Get all proxy routes.
     */
    public static function getAll(): array
    {
        return Database::fetchAll('SELECT * FROM proxy_routes ORDER BY domain');
    }

    /**
     * Get only enabled proxy routes.
     */
    public static function getEnabled(): array
    {
        return Database::fetchAll('SELECT * FROM proxy_routes WHERE enabled = TRUE ORDER BY domain');
    }

    /**
     * Get a single route by ID.
     */
    public static function getById(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM proxy_routes WHERE id = :id', ['id' => $id]);
    }

    /**
     * Get a single route by domain.
     */
    public static function getByDomain(string $domain): ?array
    {
        return Database::fetchOne('SELECT * FROM proxy_routes WHERE domain = :d', ['d' => $domain]);
    }

    /**
     * Create a new proxy route.
     */
    public static function create(array $data): int
    {
        return Database::insert('proxy_routes', [
            'name'        => $data['name'] ?? '',
            'domain'      => $data['domain'],
            'target_ip'   => $data['target_ip'],
            'target_port' => (int)($data['target_port'] ?? 443),
            'enabled'     => !empty($data['enabled']),
            'notes'       => $data['notes'] ?? '',
        ]);
    }

    /**
     * Update an existing proxy route.
     */
    public static function update(int $id, array $data): int
    {
        return Database::update('proxy_routes', [
            'name'        => $data['name'] ?? '',
            'domain'      => $data['domain'],
            'target_ip'   => $data['target_ip'],
            'target_port' => (int)($data['target_port'] ?? 443),
            'enabled'     => !empty($data['enabled']),
            'notes'       => $data['notes'] ?? '',
            'updated_at'  => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
    }

    /**
     * Delete a proxy route.
     */
    public static function delete(int $id): int
    {
        return Database::delete('proxy_routes', 'id = :id', ['id' => $id]);
    }

    /**
     * Count total routes (for license gating).
     */
    public static function count(): int
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS cnt FROM proxy_routes');
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Check if adding a new route is allowed under the current license.
     * Free tier: max 1 proxy route. Pro: unlimited.
     */
    public static function canAddRoute(): bool
    {
        if (LicenseService::hasFeature(LicenseService::FEATURE_PROXY_ROUTES)) {
            return true;
        }
        return self::count() < 1;
    }

    /**
     * Get caddy-l4 route entries for permanent proxy.
     * Groups domains by target (ip:port) for efficient routing.
     *
     * @return array  Array of caddy-l4 route objects
     */
    public static function getCaddyL4Routes(): array
    {
        $routes = self::getEnabled();
        if (empty($routes)) {
            return [];
        }

        // Group domains by target_ip:target_port
        $grouped = [];
        foreach ($routes as $r) {
            $target = $r['target_ip'] . ':' . $r['target_port'];
            $grouped[$target][] = $r['domain'];
        }

        $caddyRoutes = [];
        foreach ($grouped as $target => $domains) {
            $caddyRoutes[] = [
                'match'  => [['tls' => ['sni' => array_values($domains)]]],
                'handle' => [['handler' => 'proxy', 'upstreams' => [['dial' => $target]]]],
            ];
        }

        return $caddyRoutes;
    }

    /**
     * Get caddy-l4 HTTP (port 80) route entries for permanent proxy.
     * These allow Let's Encrypt HTTP-01 challenges to reach the target server.
     * Uses the layer4 "http" matcher with host matching.
     *
     * @return array  Array of caddy-l4 route objects for port 80
     */
    public static function getCaddyL4HttpRoutes(): array
    {
        $routes = self::getEnabled();
        if (empty($routes)) {
            return [];
        }

        // Group domains by target_ip (port 80 always)
        $grouped = [];
        foreach ($routes as $r) {
            $ip = $r['target_ip'];
            $grouped[$ip][] = $r['domain'];
        }

        $caddyRoutes = [];
        foreach ($grouped as $ip => $domains) {
            $caddyRoutes[] = [
                'match'  => [['http' => [['host' => array_values($domains)]]]],
                'handle' => [['handler' => 'proxy', 'upstreams' => [['dial' => "{$ip}:80"]]]],
            ];
        }

        return $caddyRoutes;
    }

    /**
     * Test connectivity to a target IP:port via TCP.
     */
    public static function testTarget(string $ip, int $port = 443, int $timeout = 5): array
    {
        $start = microtime(true);
        $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        $elapsed = round((microtime(true) - $start) * 1000);

        if ($sock) {
            fclose($sock);
            return ['ok' => true, 'ms' => $elapsed, 'error' => ''];
        }

        return ['ok' => false, 'ms' => $elapsed, 'error' => "{$errstr} ({$errno})"];
    }

    /**
     * Regenerate and apply caddy-l4 config (merges proxy + failover routes).
     * Only applies if caddy-l4 binary exists and proxy routes are configured.
     */
    public static function applyCaddyL4Config(): array
    {
        $log = [];
        $foConfig = FailoverService::getConfig();
        $confPath = $foConfig['failover_caddy_l4_conf'] ?: '/etc/caddy/caddy-l4.json';
        $binPath  = $foConfig['failover_caddy_l4_bin'] ?: '/usr/local/bin/caddy-l4';

        if (!file_exists($binPath)) {
            $log[] = 'caddy-l4 binary not found — skipping config apply';
            return $log;
        }

        $config = FailoverService::generateCaddyL4Config($foConfig);
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($confPath, $json) === false) {
            $log[] = "ERROR: Failed to write caddy-l4 config to {$confPath}";
            return $log;
        }
        $log[] = "caddy-l4 config written to {$confPath}";

        $isRunning = trim(shell_exec('systemctl is-active caddy-l4 2>/dev/null') ?? '') === 'active';
        if ($isRunning) {
            exec('systemctl reload caddy-l4 2>&1', $out, $code);
            $log[] = $code === 0 ? 'caddy-l4 reloaded' : 'ERROR reloading caddy-l4: ' . implode(' ', $out);
        }

        return $log;
    }
}
