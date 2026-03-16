<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Env;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\LogService;

class ClusterApiController
{
    /**
     * GET /api/cluster/status
     * Returns comprehensive local server status as JSON.
     */
    public function status(): void
    {
        header('Content-Type: application/json');

        try {
            $localStatus = ClusterService::getLocalStatus();
            echo json_encode(['ok' => true, 'data' => $localStatus], JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * GET /api/cluster/heartbeat
     * Returns a simple heartbeat response.
     */
    public function heartbeat(): void
    {
        header('Content-Type: application/json');

        echo json_encode([
            'ok'           => true,
            'timestamp'    => date('Y-m-d H:i:s'),
            'role'         => Env::get('PANEL_ROLE', 'standalone'),
            'cluster_role' => Settings::get('cluster_role', 'standalone'),
        ]);
        exit;
    }

    /**
     * POST /api/cluster/action
     * Dispatches cluster actions from remote nodes.
     */
    public function action(): void
    {
        header('Content-Type: application/json');

        // Handle multipart file uploads (receive-files)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'multipart/form-data')) {
            $action = $_POST['action'] ?? '';
            $payload = $_POST;
        } else {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input || !isset($input['action'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Missing action parameter']);
                exit;
            }
            $action = $input['action'];
            $payload = $input['payload'] ?? [];
        }

        $callerNodeId = (int)($_REQUEST['_api_node_id'] ?? 0);

        LogService::log('cluster.api', $action, "Recibido de nodo #{$callerNodeId}" . ($action !== 'receive-files' ? ': ' . json_encode($payload) : ''));

        try {
            $result = match ($action) {
                'sync-hosting'     => ClusterService::handleSyncAction($action, $payload),
                'promote'          => ClusterService::promoteToMaster(),
                'demote'           => ClusterService::demoteToSlave($payload['new_master_ip'] ?? ''),
                'test-connection'  => ['ok' => true, 'message' => 'Connection successful'],
                'repl-create-user' => \MuseDockPanel\Services\ReplicationService::createReplicationUserForRemote(
                    $payload['engine'] ?? 'pg',
                    $payload['slave_ip'] ?? ''
                ),
                'receive-files'    => $this->handleReceiveFiles($payload),
                'install-ssh-key'  => \MuseDockPanel\Services\FileSyncService::installPublicKey($payload['public_key'] ?? ''),
                default            => ['ok' => false, 'message' => "Unknown action: {$action}"],
            };

            $httpCode = ($result['ok'] ?? false) ? 200 : 422;
            http_response_code($httpCode);
            echo json_encode($result);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Handle file reception on the slave.
     */
    private function handleReceiveFiles(array $payload): array
    {
        $remotePath = $payload['remote_path'] ?? '';
        $uploadedFile = $_FILES['archive'] ?? null;

        if (!$remotePath || !$uploadedFile) {
            return ['ok' => false, 'error' => 'Faltan parametros: remote_path o archive'];
        }

        $result = \MuseDockPanel\Services\FileSyncService::receiveFiles($remotePath, $uploadedFile);

        // Rewrite DB_HOST if configured
        if (($result['ok'] ?? false) && Settings::get('filesync_rewrite_dbhost', '1') === '1') {
            $changes = \MuseDockPanel\Services\FileSyncService::rewriteDbHost($remotePath);
            if (!empty($changes)) {
                $result['db_host_rewritten'] = $changes;
            }
        }

        return $result;
    }
}
