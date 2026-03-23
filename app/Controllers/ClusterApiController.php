<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Env;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\MailService;
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

        $clusterRole = Settings::get('cluster_role', '');
        $envRole = Env::get('PANEL_ROLE', 'standalone');
        $effectiveRole = ($clusterRole !== '' && $clusterRole !== 'standalone') ? $clusterRole : $envRole;

        // Record who is monitoring us (master tracking)
        $callerIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if ($callerIp) {
            Settings::set('cluster_master_ip', $callerIp);
            Settings::set('cluster_master_last_heartbeat', date('Y-m-d H:i:s'));
        }

        // Sync standby state from master — every heartbeat carries this flag
        $masterSaysStandby = ($_GET['standby'] ?? '0') === '1';
        $currentStandby = Settings::get('cluster_self_standby', '0') === '1';
        if ($masterSaysStandby !== $currentStandby) {
            Settings::set('cluster_self_standby', $masterSaysStandby ? '1' : '0');
            if ($masterSaysStandby) {
                Settings::set('cluster_master_down_alerted', '');
                Settings::set('cluster_master_down_alert_count', '0');
            }
            LogService::log('cluster.standby', 'self', $masterSaysStandby
                ? 'Standby activado via heartbeat del master'
                : 'Standby desactivado via heartbeat del master');
        }

        echo json_encode([
            'ok'           => true,
            'timestamp'    => date('Y-m-d H:i:s'),
            'role'         => $effectiveRole,
            'cluster_role' => $clusterRole ?: $envRole,
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
                'restore-db-dumps' => $this->handleRestoreDbDumps($payload),

                // ── Mail node actions ────────────────────────
                'mail_create_domain'    => MailService::nodeCreateDomain($payload),
                'mail_delete_domain'    => MailService::nodeDeleteDomain($payload),
                'mail_create_mailbox'   => MailService::nodeCreateMailbox($payload),
                'mail_delete_mailbox'   => MailService::nodeDeleteMailbox($payload),
                'mail_update_quota'     => MailService::nodeUpdateQuota($payload),
                'mail_suspend_mailbox'  => MailService::nodeSuspendMailbox($payload),
                'mail_activate_mailbox' => MailService::nodeActivateMailbox($payload),
                'mail_setup_node'          => MailService::nodeSetupMail($payload),
                'mail_setup_status'        => MailService::nodeSetupStatus($payload),
                'mail_generate_setup_token' => MailService::nodeGenerateSetupToken($payload),
                'mail_rotate_db_password'  => MailService::nodeRotateDbPassword($payload),
                'mail_check_configured'   => MailService::nodeCheckConfigured(),

                // ── Standby management ─────────────────────
                'set-standby' => $this->handleSetStandby($payload),

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
    /**
     * Handle database dump restoration on slave.
     */
    private function handleRestoreDbDumps(array $payload): array
    {
        $dumpPath = $payload['dump_path'] ?? '/tmp/musedock-dumps';
        // Security: only allow known dump paths
        if (!str_starts_with($dumpPath, '/tmp/musedock-dumps')) {
            return ['ok' => false, 'error' => 'Ruta de dumps no permitida'];
        }
        return \MuseDockPanel\Services\FileSyncService::restoreDatabaseDumps($dumpPath);
    }

    private function handleReceiveFiles(array $payload): array
    {
        $remotePath = $payload['remote_path'] ?? '';
        $uploadedFile = $_FILES['archive'] ?? null;

        if (!$remotePath || !$uploadedFile) {
            return ['ok' => false, 'error' => 'Faltan parametros: remote_path o archive'];
        }

        $ownerUser = $payload['owner_user'] ?? '';
        $result = \MuseDockPanel\Services\FileSyncService::receiveFiles($remotePath, $uploadedFile, $ownerUser);

        // Rewrite DB_HOST if configured
        if (($result['ok'] ?? false) && Settings::get('filesync_rewrite_dbhost', '1') === '1') {
            $changes = \MuseDockPanel\Services\FileSyncService::rewriteDbHost($remotePath);
            if (!empty($changes)) {
                $result['db_host_rewritten'] = $changes;
            }
        }

        return $result;
    }

    /**
     * Handle standby mode toggling from master.
     */
    private function handleSetStandby(array $payload): array
    {
        $enabled = !empty($payload['enabled']);
        $reason = $payload['reason'] ?? '';

        Settings::set('cluster_self_standby', $enabled ? '1' : '0');

        if ($enabled) {
            // Clear alert counters so they don't resume mid-escalation when reactivated
            Settings::set('cluster_master_down_alerted', '');
            Settings::set('cluster_master_down_alert_count', '0');
            LogService::log('cluster.standby', 'self', "Puesto en standby por master: {$reason}");
        } else {
            Settings::set('cluster_self_standby', '0');
            LogService::log('cluster.standby', 'self', 'Reactivado por master');
        }

        return ['ok' => true, 'message' => $enabled ? 'Standby activado' : 'Standby desactivado'];
    }
}
