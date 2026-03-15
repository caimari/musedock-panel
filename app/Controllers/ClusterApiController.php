<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Env;
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
            'ok'        => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'role'      => Env::get('PANEL_ROLE', 'standalone'),
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

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['action'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing action parameter']);
            exit;
        }

        $action = $input['action'];
        $payload = $input['payload'] ?? [];
        $callerNodeId = (int)($_REQUEST['_api_node_id'] ?? 0);

        LogService::log('cluster.api', $action, "Recibido de nodo #{$callerNodeId}: " . json_encode($payload));

        try {
            $result = match ($action) {
                'sync-hosting'    => ClusterService::handleSyncAction($action, $payload),
                'promote'         => ClusterService::promoteToMaster(),
                'demote'          => ClusterService::demoteToSlave($payload['new_master_ip'] ?? ''),
                'test-connection' => ['ok' => true, 'message' => 'Connection successful'],
                default           => ['ok' => false, 'message' => "Unknown action: {$action}"],
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
}
