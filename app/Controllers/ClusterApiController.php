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
     *
     * Returns severity levels:
     *   critical → failover should be triggered (Caddy down, PG hosting down)
     *   warning  → notify admin but do NOT failover (PG panel down, disk low, high load)
     *   ok       → everything healthy
     *
     * HTTP status: 200 (ok), 503 (critical or warning)
     */
    public function health(): void
    {
        header('Content-Type: application/json');

        // Load configurable thresholds from DB (admin can adjust in Settings > Failover)
        $diskCriticalPct  = (float)(Settings::get('failover_disk_critical_pct', '5') ?: 5);
        $diskWarningPct   = (float)(Settings::get('failover_disk_warning_pct', '10') ?: 10);
        $loadCriticalMult = (float)(Settings::get('failover_load_critical_mult', '3') ?: 3);
        $loadWarningMult  = (float)(Settings::get('failover_load_warning_mult', '2') ?: 2);
        $pgPanelSeverity    = Settings::get('failover_pg_panel_severity', 'warning') ?: 'warning';
        $pgHostingSeverity  = Settings::get('failover_pg_hosting_severity', 'critical') ?: 'critical';
        $mysqlSeverity      = Settings::get('failover_mysql_severity', 'warning') ?: 'warning';
        $caddySeverity      = Settings::get('failover_caddy_severity', 'critical') ?: 'critical';

        $checks = [];
        $hasCritical = false;
        $hasWarning = false;

        // 1. Caddy (port 443) — configurable severity (default: critical)
        $caddyConn = @fsockopen('127.0.0.1', 443, $errno, $errstr, 2);
        if ($caddyConn) {
            fclose($caddyConn);
            $checks['caddy'] = ['status' => 'ok', 'configured_severity' => $caddySeverity];
        } else {
            $checks['caddy'] = self::applySeverity('critical', $caddySeverity, $hasCritical, $hasWarning);
            $checks['caddy']['error'] = $errstr ?: 'Connection refused';
        }

        // 2. PostgreSQL 5432 (hosting databases) — configurable severity (default: critical)
        $checks['pg_hosting'] = self::checkPostgres(5432);
        if ($checks['pg_hosting']['status'] === 'critical') {
            $checks['pg_hosting'] = array_merge($checks['pg_hosting'], self::applySeverity('critical', $pgHostingSeverity, $hasCritical, $hasWarning));
        }
        $checks['pg_hosting']['configured_severity'] = $pgHostingSeverity;

        // 3. PostgreSQL 5433 (panel database) — configurable severity (default: warning)
        $checks['pg_panel'] = self::checkPostgres(5433);
        if ($checks['pg_panel']['status'] === 'critical') {
            $checks['pg_panel'] = array_merge($checks['pg_panel'], self::applySeverity('critical', $pgPanelSeverity, $hasCritical, $hasWarning));
        }
        $checks['pg_panel']['configured_severity'] = $pgPanelSeverity;

        // 4. MySQL (port 3306) — configurable severity (default: warning)
        $mysqlConn = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 2);
        if ($mysqlConn) {
            fclose($mysqlConn);
            // Verify it accepts queries
            $mysqlTest = trim((string)shell_exec("mysql -e 'SELECT 1' 2>/dev/null | tail -1"));
            if ($mysqlTest === '1') {
                $checks['mysql'] = ['status' => 'ok', 'queryable' => true, 'configured_severity' => $mysqlSeverity];
            } else {
                $checks['mysql'] = self::applySeverity('critical', $mysqlSeverity, $hasCritical, $hasWarning);
                $checks['mysql']['queryable'] = false;
            }
        } else {
            // MySQL might not be installed — check if it's expected
            $mysqlInstalled = !empty(trim((string)shell_exec('which mysql 2>/dev/null')));
            if ($mysqlInstalled) {
                $checks['mysql'] = self::applySeverity('critical', $mysqlSeverity, $hasCritical, $hasWarning);
                $checks['mysql']['error'] = $errstr ?: 'Connection refused';
            } else {
                $checks['mysql'] = ['status' => 'ok', 'installed' => false, 'configured_severity' => $mysqlSeverity];
            }
        }

        // 5. Disk space — configurable thresholds
        $diskFree = @disk_free_space('/');
        $diskTotal = @disk_total_space('/');
        if ($diskTotal > 0) {
            $diskPct = round(($diskFree / $diskTotal) * 100, 1);
            $diskStatus = 'ok';
            if ($diskPct < $diskCriticalPct) {
                $diskStatus = 'critical';
                $hasCritical = true;
            } elseif ($diskPct < $diskWarningPct) {
                $diskStatus = 'warning';
                $hasWarning = true;
            }
            $checks['disk'] = [
                'status' => $diskStatus,
                'free_percent' => $diskPct,
                'free_gb' => round($diskFree / 1073741824, 1),
                'thresholds' => ['critical' => $diskCriticalPct, 'warning' => $diskWarningPct],
            ];
        } else {
            $checks['disk'] = ['status' => 'critical', 'error' => 'Cannot read disk'];
            $hasCritical = true;
        }

        // 6. System load — configurable multipliers
        $cpuCores = (int)trim((string)shell_exec('nproc 2>/dev/null')) ?: 1;
        $load = sys_getloadavg();
        $load1 = $load[0] ?? 0;
        $loadStatus = 'ok';
        if ($load1 >= ($cpuCores * $loadCriticalMult)) {
            $loadStatus = 'critical';
            $hasCritical = true;
        } elseif ($load1 >= ($cpuCores * $loadWarningMult)) {
            $loadStatus = 'warning';
            $hasWarning = true;
        }
        $checks['load'] = [
            'status' => $loadStatus,
            'load_1m' => $load1,
            'cores' => $cpuCores,
            'thresholds' => ['critical' => $cpuCores * $loadCriticalMult, 'warning' => $cpuCores * $loadWarningMult],
        ];

        // Determine overall severity
        $severity = 'ok';
        $status = 'healthy';
        if ($hasCritical) {
            $severity = 'critical';
            $status = 'unhealthy';
        } elseif ($hasWarning) {
            $severity = 'warning';
            $status = 'degraded';
        }

        // Role info
        $clusterRole = Settings::get('cluster_role', '');
        $envRole = Env::get('PANEL_ROLE', 'standalone');
        $role = ($clusterRole !== '' && $clusterRole !== 'standalone') ? $clusterRole : $envRole;

        if ($severity !== 'ok') http_response_code(503);

        echo json_encode([
            'ok' => ($severity === 'ok'),
            'status' => $status,
            'severity' => $severity,
            'timestamp' => date('Y-m-d H:i:s'),
            'role' => $role,
            'version' => defined('PANEL_VERSION') ? PANEL_VERSION : 'unknown',
            'checks' => $checks,
        ], JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Check a PostgreSQL instance by port.
     * Returns status: 'ok' or 'critical' (caller decides if it's warning-level).
     */
    private static function checkPostgres(int $port): array
    {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
        if (!$conn) {
            return ['status' => 'critical', 'port' => $port, 'error' => $errstr ?: 'Connection refused'];
        }
        fclose($conn);

        // Verify it actually accepts queries
        $result = trim((string)shell_exec("sudo -u postgres psql -p {$port} -tAc 'SELECT 1' 2>/dev/null"));
        if ($result === '1') {
            return ['status' => 'ok', 'port' => $port, 'queryable' => true];
        }
        return ['status' => 'critical', 'port' => $port, 'queryable' => false];
    }

    /**
     * Apply configured severity to a failed check.
     * Maps a raw 'critical' result to what the admin configured (critical/warning/ignore).
     * Updates $hasCritical/$hasWarning by reference.
     */
    private static function applySeverity(string $rawStatus, string $configuredSeverity, bool &$hasCritical, bool &$hasWarning): array
    {
        if ($rawStatus !== 'critical' && $rawStatus !== 'fail') {
            return ['status' => $rawStatus];
        }

        switch ($configuredSeverity) {
            case 'critical':
                $hasCritical = true;
                return ['status' => 'critical'];
            case 'warning':
                $hasWarning = true;
                return ['status' => 'warning'];
            case 'ignore':
            default:
                return ['status' => 'info'];
        }
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
