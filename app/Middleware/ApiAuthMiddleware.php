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
                $peers = Database::fetchAll('SELECT id, auth_token FROM federation_peers');
                foreach ($peers as $peer) {
                    $decrypted = ReplicationService::decryptPassword($peer['auth_token']);
                    if ($decrypted !== '' && hash_equals($decrypted, $token)) {
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

        self::sendError(401, 'Invalid or unrecognized token');
        return false;
    }

    private static function sendError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $message]);
    }
}
