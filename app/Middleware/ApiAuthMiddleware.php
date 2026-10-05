<?php
namespace MuseDockPanel\Middleware;

use MuseDockPanel\Database;
use MuseDockPanel\Services\ReplicationService;

class ApiAuthMiddleware
{
    public static function handle(): bool
    {
        $uri = strtok($_SERVER['REQUEST_URI'], '?');
        $uri = rtrim($uri, '/') ?: '/';

        // Apply to sensitive machine APIs.
        // /api/health and /api/domains are also used for inter-node operations.
        $needsAuth = str_starts_with($uri, '/api/cluster/')
            || str_starts_with($uri, '/api/federation/')
            || in_array($uri, ['/api/health', '/api/domains'], true);

        if (!$needsAuth) {
            return true;
        }

        // Extract Bearer token from Authorization header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            self::sendError(401, 'Authorization header missing or invalid');
            return false;
        }

        $token = trim($matches[1]);
        if ($token === '') {
            self::sendError(401, 'Empty token');
            return false;
        }

        // Freno a la fuerza bruta: demasiados tokens erróneos seguidos desde la misma IP
        // (las locales no cuentan: detrás de Caddy podrían ser todos los nodos).
        $clientIp = \MuseDockPanel\Security\ClientIp::resolve();
        if (self::tooManyFailures($clientIp)) {
            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: 600');
            echo json_encode(['ok' => false, 'error' => 'Demasiados intentos fallidos. Espera unos minutos.']);
            return false;
        }

        // Check against cluster_nodes auth_token values
        try {
            $nodes = Database::fetchAll('SELECT id, auth_token FROM cluster_nodes');
            foreach ($nodes as $node) {
                $decrypted = ReplicationService::decryptPassword($node['auth_token']);
                if ($decrypted !== '' && hash_equals($decrypted, $token)) {
                    $_REQUEST['_api_node_id'] = (int)$node['id'];
                    $_REQUEST['_api_token'] = $token;
                    return true;
                }
            }

            // Also check against the local cluster token in settings
            $localToken = '';
            try {
                $row = Database::fetchOne("SELECT value FROM panel_settings WHERE key = 'cluster_local_token'");
                if ($row) {
                    $localToken = ReplicationService::decryptPassword($row['value']);
                }
            } catch (\Throwable) {}

            if ($localToken !== '' && hash_equals($localToken, $token)) {
                $_REQUEST['_api_node_id'] = 0; // Local/external caller
                $_REQUEST['_api_token'] = $token;
                return true;
            }

            // Check against federation_peers auth_token values
            try {
                // El token de un peer federado solo vale para /api/federation/ (nunca para la
                // API del cluster) y no si el peer aún está pendiente de aprobación.
                $peers = Database::fetchAll("SELECT id, auth_token FROM federation_peers WHERE status <> 'pending_approval'");
                foreach ($peers as $peer) {
                    $decrypted = ReplicationService::decryptPassword($peer['auth_token']);
                    if ($decrypted !== '' && hash_equals($decrypted, $token)
                        && (str_starts_with($uri, '/api/federation/') || $uri === '/api/health')) {
                        $_REQUEST['_api_peer_id'] = (int)$peer['id'];
                        $_REQUEST['_api_token'] = $token;
                        return true;
                    }
                }
            } catch (\Throwable) {
                // federation_peers table may not exist yet
            }
        } catch (\Throwable $e) {
            self::sendError(500, 'Internal authentication error');
            return false;
        }

        self::recordFailure($clientIp);
        self::sendError(401, 'Invalid or unrecognized token');
        return false;
    }

    private const FAIL_MAX = 30;      // fallos permitidos
    private const FAIL_WINDOW = 600;  // en estos segundos

    private static function failFile(string $ip): string
    {
        $dir = '/tmp/musedock-panel-ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/apifail-' . hash('sha256', $ip);
    }

    private static function failureTimes(string $ip): array
    {
        $raw = (string)@file_get_contents(self::failFile($ip));
        $min = time() - self::FAIL_WINDOW;
        $times = array_map('intval', $raw !== '' ? explode("\n", trim($raw)) : []);
        return array_values(array_filter($times, static fn($t) => $t > $min));
    }

    private static function tooManyFailures(string $ip): bool
    {
        if ($ip === '' || in_array($ip, ['127.0.0.1', '::1'], true)) {
            return false;
        }
        return count(self::failureTimes($ip)) >= self::FAIL_MAX;
    }

    private static function recordFailure(string $ip): void
    {
        if ($ip === '' || in_array($ip, ['127.0.0.1', '::1'], true)) {
            return;
        }
        $times = self::failureTimes($ip);
        $times[] = time();
        @file_put_contents(self::failFile($ip), implode("\n", $times), LOCK_EX);
    }

    private static function sendError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $message]);
    }
}
