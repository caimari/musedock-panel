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
     * GET /api/health
     * Lightweight health check for failover monitoring.
     * Verifies: Caddy, PostgreSQL (5432+5433), disk space, panel responsiveness.
     * Returns HTTP 200 if healthy, 503 if degraded.
     */
    public function health(): void
    {
        header('Content-Type: application/json');

        $checks = [];
        $healthy = true;

        // 1. Caddy (port 443)
        $caddyCheck = @fsockopen('127.0.0.1', 443, $errno, $errstr, 2);
        $checks['caddy'] = ['ok' => (bool)$caddyCheck];
        if ($caddyCheck) { fclose($caddyCheck); } else { $healthy = false; }

        // 2. PostgreSQL 5432 (hosting databases)
        $pg5432 = @fsockopen('127.0.0.1', 5432, $errno, $errstr, 2);
        $checks['pg_5432'] = ['ok' => (bool)$pg5432];
        if ($pg5432) {
            fclose($pg5432);
            // Verify it actually accepts queries
            try {
                $result = trim((string)shell_exec("sudo -u postgres psql -p 5432 -tAc 'SELECT 1' 2>/dev/null"));
                $checks['pg_5432']['queryable'] = ($result === '1');
                if ($result !== '1') $healthy = false;
            } catch (\Throwable $e) {
                $checks['pg_5432']['queryable'] = false;
                $healthy = false;
            }
        } else {
            $healthy = false;
        }

        // 3. PostgreSQL 5433 (panel database)
        $pg5433 = @fsockopen('127.0.0.1', 5433, $errno, $errstr, 2);
        $checks['pg_5433'] = ['ok' => (bool)$pg5433];
        if ($pg5433) {
            fclose($pg5433);
            try {
                $result = trim((string)shell_exec("sudo -u postgres psql -p 5433 -tAc 'SELECT 1' 2>/dev/null"));
                $checks['pg_5433']['queryable'] = ($result === '1');
                if ($result !== '1') $healthy = false;
            } catch (\Throwable $e) {
                $checks['pg_5433']['queryable'] = false;
                $healthy = false;
            }
        } else {
            $healthy = false;
        }

        // 4. Disk space (warn <10%, fail <5%)
        $diskFree = @disk_free_space('/');
        $diskTotal = @disk_total_space('/');
        if ($diskTotal > 0) {
            $diskPct = round(($diskFree / $diskTotal) * 100, 1);
            $checks['disk'] = [
                'ok' => $diskPct >= 5,
                'free_pct' => $diskPct,
                'free_gb' => round($diskFree / 1073741824, 1),
                'warning' => $diskPct < 10,
            ];
            if ($diskPct < 5) $healthy = false;
        } else {
            $checks['disk'] = ['ok' => false, 'error' => 'Cannot read disk'];
            $healthy = false;
        }

        // 5. System load (fail if load > 2x CPU cores)
        $cpuCores = (int)trim((string)shell_exec('nproc 2>/dev/null')) ?: 1;
        $load = sys_getloadavg();
        $load1 = $load[0] ?? 0;
        $checks['load'] = [
            'ok' => $load1 < ($cpuCores * 2),
            'load_1m' => $load1,
            'cores' => $cpuCores,
            'warning' => $load1 > $cpuCores,
        ];
        if ($load1 >= ($cpuCores * 3)) $healthy = false; // only fail on extreme load

        // 6. Panel role info
        $clusterRole = Settings::get('cluster_role', '');
        $envRole = Env::get('PANEL_ROLE', 'standalone');
        $role = ($clusterRole !== '' && $clusterRole !== 'standalone') ? $clusterRole : $envRole;

        if (!$healthy) http_response_code(503);

        echo json_encode([
            'ok' => $healthy,
            'timestamp' => date('Y-m-d H:i:s'),
            'role' => $role,
            'version' => defined('PANEL_VERSION') ? PANEL_VERSION : 'unknown',
            'checks' => $checks,
        ], JSON_PRETTY_PRINT);
        exit;
    }

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
