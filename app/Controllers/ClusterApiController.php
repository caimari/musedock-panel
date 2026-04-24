<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Env;
use MuseDockPanel\Settings;
use MuseDockPanel\Security\TlsClient;
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
        //    Skip if Caddy is not installed (DB-only replica nodes)
        $caddyInstalled = !empty(trim((string)shell_exec('which caddy 2>/dev/null')));
        if (!$caddyInstalled) {
            $checks['caddy'] = ['status' => 'ok', 'installed' => false, 'configured_severity' => $caddySeverity];
        } else {
            $caddyConn = @fsockopen('127.0.0.1', 443, $errno, $errstr, 2);
            if ($caddyConn) {
                fclose($caddyConn);
                $checks['caddy'] = ['status' => 'ok', 'configured_severity' => $caddySeverity];
            } else {
                $checks['caddy'] = self::applySeverity('critical', $caddySeverity, $hasCritical, $hasWarning);
                $checks['caddy']['error'] = $errstr ?: 'Connection refused';
            }
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

        // Check DB associations hash from master
        $dbHashMismatch = false;
        $masterDbHash = $_GET['db_hash'] ?? '';
        if ($masterDbHash !== '') {
            $localDbHash = ClusterService::computeDbAssociationsHash();
            $dbHashMismatch = ($masterDbHash !== $localDbHash);
        }

        echo json_encode([
            'ok'                => true,
            'timestamp'         => date('Y-m-d H:i:s'),
            'role'              => $effectiveRole,
            'cluster_role'      => $clusterRole ?: $envRole,
            'db_hash_mismatch'  => $dbHashMismatch,
        ]);
        exit;
    }

    /**
     * GET /api/domains
     * Returns list of active domains hosted on this server.
     * Used by remote servers to configure caddy-l4 proxy (emergency mode).
     * Authentication: Bearer token (same as cluster API).
     */
    public function domains(): void
    {
        header('Content-Type: application/json');

        $domains = \MuseDockPanel\Services\FailoverService::getLocalDomains();

        echo json_encode([
            'ok'      => true,
            'domains' => $domains,
            'count'   => count($domains),
            'server'  => gethostname(),
            'updated' => date('Y-m-d H:i:s'),
        ]);
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
                'tls-export-ca'    => $this->handleTlsExportCa($payload),

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
                'mail_db_health'          => MailService::nodeMailDbHealth($payload),

                // ── Standby management ─────────────────────
                'set-standby' => $this->handleSetStandby($payload),

                // ── Failover config sync ──────────────────
                'sync-failover-config' => $this->handleSyncFailoverConfig($payload),
                'pull-failover-config' => $this->handlePullFailoverConfig(),

                // ── Replication reconfiguration ──────────────
                'reconfigure-replication' => $this->handleReconfigureReplication($payload),

                // ── Interface failover notifications ─────────
                'notify-iface-down' => $this->handleNotifyIfaceDown($payload),
                'notify-iface-up'   => $this->handleNotifyIfaceUp($payload),
                'query-local-state' => $this->handleQueryLocalState(),

                // ── Remote backup operations ──────────────────
                'backup-preflight'   => $this->handleBackupPreflight($payload),
                'receive-backup'     => $this->handleReceiveBackup($payload),
                'receive-db-backup'  => $this->handleReceiveDbBackup($payload),
                'list-db-backups'    => $this->handleListDbBackups(),
                'list-backups'       => $this->handleListBackups($payload),
                'download-backup'    => $this->handleDownloadBackup($payload),
                'delete-backup'      => $this->handleDeleteBackup($payload),

                default            => ['ok' => false, 'error' => "Unknown action: {$action}"],
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
     * Export local Caddy CA certificate for trusted bootstrap on peer nodes.
     * Response is signed with HMAC(token) to prevent tampering on first-contact flows.
     */
    private function handleTlsExportCa(array $payload): array
    {
        $nonce = trim((string)($payload['nonce'] ?? ''));
        if ($nonce === '' || !preg_match('/^[a-f0-9]{16,128}$/i', $nonce)) {
            return ['ok' => false, 'error' => 'Invalid nonce'];
        }

        $token = (string)($_REQUEST['_api_token'] ?? '');
        if ($token === '') {
            return ['ok' => false, 'error' => 'Missing authenticated token'];
        }

        $caFile = TlsClient::detectLocalCaddyCaFile();
        if ($caFile === null) {
            return ['ok' => false, 'error' => 'Local Caddy CA not found'];
        }

        $caPem = (string)@file_get_contents($caFile);
        if (trim($caPem) === '') {
            return ['ok' => false, 'error' => 'Local Caddy CA not readable'];
        }

        $parsed = @openssl_x509_parse($caPem);
        if (!is_array($parsed)) {
            return ['ok' => false, 'error' => 'Invalid CA certificate format'];
        }

        $caSha = hash('sha256', $caPem);
        $sig = hash_hmac('sha256', $nonce . '|' . $caSha, $token);

        return [
            'ok'         => true,
            'nonce'      => $nonce,
            'ca_pem'     => $caPem,
            'ca_sha256'  => $caSha,
            'sig'        => $sig,
            'not_before' => isset($parsed['validFrom_time_t']) ? date('c', (int)$parsed['validFrom_time_t']) : null,
            'not_after'  => isset($parsed['validTo_time_t']) ? date('c', (int)$parsed['validTo_time_t']) : null,
        ];
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

    /**
     * Handle incoming failover config from master (push).
     * Slave receives and stores config locally. Only config keys are written,
     * never local runtime state (counters, timestamps, current failover state).
     */
    private function handleSyncFailoverConfig(array $payload): array
    {
        $config = $payload['config'] ?? [];
        $servers = $payload['servers'] ?? null;
        $cfAccounts = $payload['cf_accounts'] ?? null;
        $remoteDomains = $payload['remote_domains'] ?? null;

        if (empty($config) && $servers === null && $cfAccounts === null) {
            return ['ok' => false, 'error' => 'No config data received'];
        }

        // Save scalar failover settings (config only, not runtime state)
        $configKeys = \MuseDockPanel\Services\FailoverService::getSyncableConfigKeys();
        foreach ($config as $key => $value) {
            if (in_array($key, $configKeys, true)) {
                Settings::set($key, (string)$value);
            }
        }

        // Save servers list
        if ($servers !== null && is_array($servers)) {
            Settings::set('failover_servers', json_encode($servers));
        }

        // Save Cloudflare accounts (force encrypted-at-rest for tokens).
        if ($cfAccounts !== null && is_array($cfAccounts)) {
            foreach ($cfAccounts as &$acct) {
                $tokenRaw = trim((string)($acct['token'] ?? ''));
                if ($tokenRaw === '') {
                    continue;
                }
                // If token is plain (legacy/buggy payload), encrypt before storing.
                $dec = \MuseDockPanel\Services\ReplicationService::decryptPassword($tokenRaw);
                if ($dec === '') {
                    $acct['token'] = \MuseDockPanel\Services\ReplicationService::encryptPassword($tokenRaw);
                }
            }
            unset($acct);
            Settings::set('failover_cf_accounts', json_encode($cfAccounts));
        }

        // Save remote domains
        if ($remoteDomains !== null) {
            Settings::set('failover_remote_domains', (string)$remoteDomains);
        }

        // Propagate Cloudflare token to /etc/default/caddy on slave (for SSL certificates)
        $caddyTokenUpdated = false;
        $updateCaddyToken = !empty($payload['update_caddy_token']);
        if ($updateCaddyToken && !empty($cfAccounts) && is_array($cfAccounts)) {
            $firstAccount = $cfAccounts[0] ?? null;
            if ($firstAccount && !empty($firstAccount['token'])) {
                $tokenRaw = (string)$firstAccount['token'];
                $token = \MuseDockPanel\Services\ReplicationService::decryptPassword($tokenRaw);
                if ($token === '') {
                    // Compatibility: accept plain token from old payloads.
                    $token = trim($tokenRaw);
                }
                if ($token && file_exists('/usr/local/bin/update-caddy-token.sh')) {
                    $out = shell_exec('sudo /usr/local/bin/update-caddy-token.sh ' . escapeshellarg($token) . ' 2>&1');
                    $caddyTokenUpdated = str_contains($out ?? '', 'OK');
                    if ($caddyTokenUpdated) {
                        LogService::log('failover.sync', 'caddy-token', 'Caddy CLOUDFLARE_API_TOKEN updated on slave from master sync');
                    }
                }
            }
        }

        Settings::set('failover_config_synced_at', date('Y-m-d H:i:s'));
        LogService::log('failover.sync', 'received', 'Failover config synced from master' . ($caddyTokenUpdated ? ' (Caddy token updated)' : ''));

        return ['ok' => true, 'message' => 'Failover config synced', 'synced_at' => date('Y-m-d H:i:s'), 'caddy_token_updated' => $caddyTokenUpdated];
    }

    /**
     * Handle pull request from slave: return full failover config.
     * Called when slave boots/reconnects and wants the latest config.
     */
    private function handlePullFailoverConfig(): array
    {
        $configKeys = \MuseDockPanel\Services\FailoverService::getSyncableConfigKeys();
        $config = [];
        foreach ($configKeys as $key) {
            $val = Settings::get($key, '');
            if ($val !== '') {
                $config[$key] = $val;
            }
        }

        $servers = json_decode(Settings::get('failover_servers', '[]'), true) ?: [];
        $cfAccounts = json_decode(Settings::get('failover_cf_accounts', '[]'), true) ?: [];
        $remoteDomains = Settings::get('failover_remote_domains', '');

        return [
            'ok' => true,
            'config' => $config,
            'servers' => $servers,
            'cf_accounts' => $cfAccounts,
            'remote_domains' => $remoteDomains,
        ];
    }

    // ── Reconfigure replication to point to new master ──────────

    private function handleReconfigureReplication(array $payload): array
    {
        $newMasterIp = $payload['new_master_ip'] ?? '';
        if (!$newMasterIp || !filter_var($newMasterIp, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'error' => 'new_master_ip inválido'];
        }

        $myRole = Settings::get('cluster_role', 'standalone');
        // Don't reconfigure if this node IS the new master
        $localIp = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));
        if ($localIp === $newMasterIp) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'This node is the new master'];
        }

        $errors = [];
        $results = [];

        // Reconfigure PostgreSQL (port 5432 — hosting DB)
        $pgUser = Settings::get('repl_pg_user', 'replicator');
        $pgPass = \MuseDockPanel\Services\ReplicationService::decryptPassword(Settings::get('repl_pg_pass', ''));
        $pgPort = (int)Settings::get('repl_pg_port', '5432');

        if ($pgUser && $pgPass) {
            try {
                $pgResult = \MuseDockPanel\Services\ReplicationService::setupPgSlave($newMasterIp, $pgPort, $pgUser, $pgPass);
                $results['pg'] = $pgResult;
                if (!$pgResult['ok']) {
                    $errors[] = 'PG: ' . ($pgResult['error'] ?? 'Unknown');
                }
            } catch (\Throwable $e) {
                $errors[] = 'PG: ' . $e->getMessage();
            }
        } else {
            $results['pg'] = ['skipped' => true, 'reason' => 'No replication credentials configured'];
        }

        // Reconfigure MySQL
        $mysqlUser = Settings::get('repl_mysql_user', 'repl_user');
        $mysqlPass = \MuseDockPanel\Services\ReplicationService::decryptPassword(Settings::get('repl_mysql_pass', ''));
        $mysqlPort = (int)Settings::get('repl_mysql_port', '3306');

        if ($mysqlUser && $mysqlPass) {
            try {
                $mysqlResult = \MuseDockPanel\Services\ReplicationService::setupMysqlSlave($newMasterIp, $mysqlPort, $mysqlUser, $mysqlPass);
                $results['mysql'] = $mysqlResult;
                if (!$mysqlResult['ok']) {
                    $errors[] = 'MySQL: ' . ($mysqlResult['error'] ?? 'Unknown');
                }
            } catch (\Throwable $e) {
                $errors[] = 'MySQL: ' . $e->getMessage();
            }
        } else {
            $results['mysql'] = ['skipped' => true, 'reason' => 'No replication credentials configured'];
        }

        // Update stored master IP
        Settings::set('repl_remote_ip', $newMasterIp);

        LogService::log('cluster.replication', 'reconfigure',
            "Replicación reconfigurada → nuevo master: {$newMasterIp}" .
            (empty($errors) ? '' : ' (errores: ' . implode(', ', $errors) . ')')
        );

        return [
            'ok' => empty($errors),
            'results' => $results,
            'errors' => $errors,
        ];
    }

    // ── Interface failover: slave notifies master ───────────

    /**
     * Slave's primary interface went down — switch DNS to DynDNS/backup IP.
     * Received on the MASTER from a slave that detected its own iface failure.
     */
    private function handleNotifyIfaceDown(array $payload): array
    {
        $slaveIp  = $payload['slave_ip'] ?? '';
        $dyndnsIp = $payload['dyndns_ip'] ?? '';

        if (!$slaveIp) {
            return ['ok' => false, 'error' => 'slave_ip required'];
        }

        $actions = [];

        // Find the backup IP to use (DynDNS resolved or backup server IP)
        $backupIp = $dyndnsIp;
        if (!$backupIp) {
            $backupServers = \MuseDockPanel\Services\FailoverService::getServersByRole(
                \MuseDockPanel\Services\FailoverService::ROLE_BACKUP
            );
            $backupIp = !empty($backupServers) ? ($backupServers[0]['ip'] ?? '') : '';
        }

        if (!$backupIp) {
            return ['ok' => false, 'error' => 'No backup IP available'];
        }

        // Update DNS: slave's fixed IP → DynDNS/backup IP
        $c = \MuseDockPanel\Services\FailoverService::getConfig();
        $accounts = \MuseDockPanel\Services\CloudflareService::getConfiguredAccounts();
        $ttl = (int)($c['failover_ttl_failover'] ?: 60);

        // Also update the failover server's IP if it matches the slave
        $failoverServers = \MuseDockPanel\Services\FailoverService::getServersByRole(
            \MuseDockPanel\Services\FailoverService::ROLE_FAILOVER
        );
        $failoverIp = '';
        foreach ($failoverServers as $fs) {
            if ($fs['ip'] === $slaveIp) {
                $failoverIp = $slaveIp;
                break;
            }
        }

        $ipsToSwitch = array_filter([$slaveIp, $failoverIp]);
        $ipsToSwitch = array_unique($ipsToSwitch);

        foreach ($ipsToSwitch as $srcIp) {
            foreach ($accounts as $acct) {
                foreach ($acct['zones'] ?? [] as $zone) {
                    $r = \MuseDockPanel\Services\CloudflareService::batchUpdateIp(
                        $acct['token'], $zone['id'], $srcIp, $backupIp, $ttl
                    );
                    if ($r['updated'] > 0) {
                        $actions[] = "DNS {$srcIp}→{$backupIp}: zone {$zone['name']} — {$r['updated']} records";
                    }
                }
            }
        }

        LogService::log('failover.iface', 'master-dns-switch',
            "Slave {$slaveIp} iface down → DNS switched to {$backupIp}: " . implode('; ', $actions));

        return ['ok' => true, 'backup_ip' => $backupIp, 'actions' => $actions];
    }

    /**
     * Slave's primary interface recovered — revert DNS to original IP.
     */
    private function handleNotifyIfaceUp(array $payload): array
    {
        $slaveIp = $payload['slave_ip'] ?? '';
        if (!$slaveIp) {
            return ['ok' => false, 'error' => 'slave_ip required'];
        }

        // Revert DNS: find backup IP currently in use, switch back to slave's fixed IP
        $c = \MuseDockPanel\Services\FailoverService::getConfig();
        $accounts = \MuseDockPanel\Services\CloudflareService::getConfiguredAccounts();
        $ttl = (int)($c['failover_ttl_normal'] ?: 300);
        $actions = [];

        // The backup IP could be DynDNS or static backup
        $dyndnsIp = \MuseDockPanel\Services\FailoverService::resolveDynDns();
        $backupServers = \MuseDockPanel\Services\FailoverService::getServersByRole(
            \MuseDockPanel\Services\FailoverService::ROLE_BACKUP
        );
        $possibleBackupIps = array_filter([$dyndnsIp, !empty($backupServers) ? ($backupServers[0]['ip'] ?? '') : '']);

        foreach ($possibleBackupIps as $backupIp) {
            foreach ($accounts as $acct) {
                foreach ($acct['zones'] ?? [] as $zone) {
                    $r = \MuseDockPanel\Services\CloudflareService::batchUpdateIp(
                        $acct['token'], $zone['id'], $backupIp, $slaveIp, $ttl
                    );
                    if ($r['updated'] > 0) {
                        $actions[] = "DNS {$backupIp}→{$slaveIp}: zone {$zone['name']} — {$r['updated']} records reverted";
                    }
                }
            }
        }

        LogService::log('failover.iface', 'master-dns-revert',
            "Slave {$slaveIp} iface recovered → DNS reverted: " . implode('; ', $actions));

        return ['ok' => true, 'actions' => $actions];
    }

    // ── Reconciliation: master queries slave state ──────────

    /**
     * Master asks: "did you change anything while I was down?"
     * Returns local flags so master can reconcile before taking action.
     */
    private function handleQueryLocalState(): array
    {
        return [
            'ok'   => true,
            'state' => [
                'failover_state'              => Settings::get('failover_state', 'normal'),
                'failover_iface_mode'         => Settings::get('failover_iface_mode', 'normal'),
                'failover_dns_changed_locally' => Settings::get('failover_dns_changed_locally', '0') === '1',
                'failover_dns_changed_at'     => Settings::get('failover_dns_changed_at', ''),
                'cluster_role'                => Settings::get('cluster_role', 'slave'),
                'repl_role'                   => Settings::get('repl_role', 'slave'),
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Remote Backup Handlers ──────────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Receive a backup file from another node.
     * Accepts multipart upload with 'backup' file field.
     */
    /**
     * Pre-flight check: disk space, PHP limits, tmp space.
     * Called before sending a backup to verify the node can receive it.
     */
    private function handleBackupPreflight(array $payload): array
    {
        $backupDir = PANEL_ROOT . '/storage/backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0750, true);
        }

        // Disk free on backup partition
        $diskFree = disk_free_space($backupDir) ?: 0;
        $diskTotal = disk_total_space($backupDir) ?: 0;

        // Disk free on /tmp (where uploads land)
        $tmpFree = disk_free_space(sys_get_temp_dir()) ?: 0;

        // PHP limits
        $uploadMax = ini_get('upload_max_filesize') ?: '2M';
        $postMax = ini_get('post_max_size') ?: '8M';

        // Convert to bytes for comparison
        $uploadMaxBytes = $this->phpSizeToBytes($uploadMax);
        $postMaxBytes = $this->phpSizeToBytes($postMax);

        // Incoming backup size (sent by caller)
        $incomingSize = (int) ($payload['file_size'] ?? 0);

        $errors = [];
        if ($incomingSize > 0) {
            if ($incomingSize > $uploadMaxBytes) {
                $errors[] = "El archivo ({$this->formatBytesStatic($incomingSize)}) excede upload_max_filesize ({$uploadMax}). Ajusta el servicio del panel con: -d upload_max_filesize=2G";
            }
            if ($incomingSize > $postMaxBytes) {
                $errors[] = "El archivo ({$this->formatBytesStatic($incomingSize)}) excede post_max_size ({$postMax}). Ajusta el servicio del panel con: -d post_max_size=2G";
            }
            if ($incomingSize > $tmpFree) {
                $errors[] = "Espacio insuficiente en /tmp para recibir el upload ({$this->formatBytesStatic($tmpFree)} disponible)";
            }
            // Need ~2x size: upload tmp + extracted
            if (($incomingSize * 2) > $diskFree) {
                $errors[] = "Espacio en disco insuficiente: {$this->formatBytesStatic($diskFree)} disponible, se necesitan ~{$this->formatBytesStatic($incomingSize * 2)}";
            }
        }

        return [
            'ok' => empty($errors),
            'disk_free' => $diskFree,
            'disk_free_human' => $this->formatBytesStatic($diskFree),
            'disk_total' => $diskTotal,
            'tmp_free' => $tmpFree,
            'tmp_free_human' => $this->formatBytesStatic($tmpFree),
            'upload_max_filesize' => $uploadMax,
            'upload_max_bytes' => $uploadMaxBytes,
            'post_max_size' => $postMax,
            'post_max_bytes' => $postMaxBytes,
            'errors' => $errors,
        ];
    }

    private function phpSizeToBytes(string $size): int
    {
        $size = trim($size);
        $unit = strtolower(substr($size, -1));
        $val = (int) $size;
        return match ($unit) {
            'g' => $val * 1073741824,
            'm' => $val * 1048576,
            'k' => $val * 1024,
            default => $val,
        };
    }

    private function formatBytesStatic(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }

    private function handleReceiveBackup(array $payload): array
    {
        $backupDir = PANEL_ROOT . '/storage/backups';
        $uploadedFile = $_FILES['backup'] ?? null;
        $backupName = basename($payload['backup_name'] ?? '');

        if (!$backupName) {
            return ['ok' => false, 'error' => 'Missing backup_name parameter'];
        }

        if (!$uploadedFile) {
            $maxUpload = ini_get('upload_max_filesize');
            $maxPost = ini_get('post_max_size');
            return ['ok' => false, 'error' => "No file received. PHP limits: upload_max_filesize={$maxUpload}, post_max_size={$maxPost}. The backup may exceed these limits."];
        }

        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            $errors = [1 => 'File exceeds upload_max_filesize', 2 => 'File exceeds MAX_FILE_SIZE', 3 => 'Partial upload', 4 => 'No file sent', 6 => 'Missing tmp dir', 7 => 'Disk write failed', 8 => 'Extension blocked'];
            $errMsg = $errors[$uploadedFile['error']] ?? "Upload error code {$uploadedFile['error']}";
            return ['ok' => false, 'error' => "Upload failed: {$errMsg}. Check PHP upload_max_filesize and post_max_size on this node."];
        }

        $targetDir = $backupDir . '/' . $backupName;
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0750, true);
        }

        // Extract archive into backup directory (auto-detect compression)
        $tmpFile = $uploadedFile['tmp_name'];
        $cmd = sprintf('tar xf %s -C %s 2>&1', escapeshellarg($tmpFile), escapeshellarg($targetDir));
        $output = shell_exec($cmd);

        if (!file_exists($targetDir . '/metadata.json')) {
            return ['ok' => false, 'error' => 'Backup extracted but no metadata.json found. Output: ' . ($output ?? '')];
        }

        $meta = @json_decode(file_get_contents($targetDir . '/metadata.json'), true);
        LogService::log('backup.receive', $meta['domain'] ?? $backupName, "Remote backup received: {$backupName}");

        return ['ok' => true, 'message' => "Backup {$backupName} received", 'path' => $targetDir];
    }

    /**
     * Receive a single database backup file (.sql.gz) from another node.
     */
    private function handleReceiveDbBackup(array $payload): array
    {
        $filename  = basename($payload['filename'] ?? '');
        $dbName    = $payload['db_name'] ?? '';
        $dbType    = $payload['db_type'] ?? 'pgsql';
        $overwrite = !empty($payload['overwrite']);

        if (!$filename || !$dbName) {
            return ['ok' => false, 'error' => 'Missing filename or db_name'];
        }

        $uploadedFile = $_FILES['db_backup'] ?? null;
        if (!$uploadedFile || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
            $maxUpload = ini_get('upload_max_filesize');
            $maxPost = ini_get('post_max_size');
            return ['ok' => false, 'error' => "No file received or upload error. PHP limits: upload_max_filesize={$maxUpload}, post_max_size={$maxPost}"];
        }

        $backupDir = Settings::get('db_backup_path', PANEL_ROOT . '/storage/db-backups');
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0750, true);
        }

        $targetPath = $backupDir . '/' . $filename;

        // Check if already exists
        $existing = Database::fetchOne("SELECT id FROM database_backups WHERE filename = :f", ['f' => $filename]);
        if ($existing && !$overwrite) {
            return ['ok' => false, 'error' => 'exists', 'message' => "Backup {$filename} already exists on this node"];
        }

        if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
            return ['ok' => false, 'error' => 'Failed to move uploaded file'];
        }

        $fileSize = filesize($targetPath);

        if ($existing) {
            // Overwrite: update existing record
            Database::update('database_backups', [
                'file_size'  => $fileSize,
                'created_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $existing['id']]);
        } else {
            Database::insert('database_backups', [
                'db_name'    => $dbName,
                'db_type'    => $dbType,
                'filename'   => $filename,
                'file_size'  => $fileSize,
                'status'     => 'completed',
                'created_by' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        LogService::log('database.backup.receive', $dbName, "DB backup received from remote: {$filename}" . ($existing ? ' (overwritten)' : ''));

        return ['ok' => true, 'message' => "DB backup {$filename} received", 'file_size' => $fileSize, 'overwritten' => (bool)$existing];
    }

    /**
     * List DB backups registered on this node (filenames).
     */
    private function handleListDbBackups(): array
    {
        $rows = Database::fetchAll("SELECT filename FROM database_backups ORDER BY created_at DESC");
        $filenames = array_column($rows, 'filename');
        return ['ok' => true, 'filenames' => $filenames];
    }

    /**
     * List available backups on this node.
     */
    private function handleListBackups(array $payload): array
    {
        $backupDir = PANEL_ROOT . '/storage/backups';
        $backups = [];

        if (is_dir($backupDir)) {
            foreach (glob("{$backupDir}/*/metadata.json") as $metaFile) {
                $dir = dirname($metaFile);
                $meta = @json_decode(file_get_contents($metaFile), true);
                if (!$meta) continue;

                $meta['dir_name'] = basename($dir);
                $meta['has_files'] = file_exists($dir . '/files.tar.gz');
                $dbDir = $dir . '/databases';
                $meta['db_count'] = is_dir($dbDir) ? count(glob("{$dbDir}/*.sql")) : 0;

                // Calculate total size on disk
                $totalSize = 0;
                if ($meta['has_files']) $totalSize += filesize($dir . '/files.tar.gz');
                if (is_dir($dbDir)) {
                    foreach (glob("{$dbDir}/*.sql") as $sqlFile) {
                        $totalSize += filesize($sqlFile);
                    }
                }
                $meta['disk_size'] = $totalSize;

                $backups[] = $meta;
            }
        }

        usort($backups, fn($a, $b) => strtotime($b['date'] ?? '0') - strtotime($a['date'] ?? '0'));

        return ['ok' => true, 'backups' => $backups, 'count' => count($backups)];
    }

    /**
     * Download a backup — streams the tar.gz back to the caller.
     */
    private function handleDownloadBackup(array $payload): array
    {
        $backupDir = PANEL_ROOT . '/storage/backups';
        $backupName = basename($payload['backup_name'] ?? '');

        if (!$backupName) {
            return ['ok' => false, 'error' => 'Missing backup_name'];
        }

        $backupPath = $backupDir . '/' . $backupName;
        if (!is_dir($backupPath) || !file_exists($backupPath . '/metadata.json')) {
            return ['ok' => false, 'error' => 'Backup not found'];
        }

        // Create a temporary tar.gz of the entire backup directory
        $tmpFile = sys_get_temp_dir() . '/backup_download_' . $backupName . '.tar.gz';
        $cmd = sprintf('tar czf %s -C %s . 2>&1', escapeshellarg($tmpFile), escapeshellarg($backupPath));
        shell_exec($cmd);

        if (!file_exists($tmpFile)) {
            return ['ok' => false, 'error' => 'Failed to create archive for download'];
        }

        // Stream file directly
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $backupName . '.tar.gz"');
        header('Content-Length: ' . filesize($tmpFile));
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    }

    /**
     * Delete a backup on this node.
     */
    private function handleDeleteBackup(array $payload): array
    {
        $backupDir = PANEL_ROOT . '/storage/backups';
        $backupName = basename($payload['backup_name'] ?? '');

        if (!$backupName) {
            return ['ok' => false, 'error' => 'Missing backup_name'];
        }

        $backupPath = $backupDir . '/' . $backupName;
        $realPath = realpath($backupPath);
        $realBackupDir = realpath($backupDir);

        if (!$realPath || !$realBackupDir || !str_starts_with($realPath, $realBackupDir) || $realPath === $realBackupDir) {
            return ['ok' => false, 'error' => 'Invalid backup path'];
        }

        if (!is_dir($realPath)) {
            return ['ok' => false, 'error' => 'Backup not found'];
        }

        shell_exec(sprintf('rm -rf %s 2>&1', escapeshellarg($realPath)));
        LogService::log('backup.remote_delete', $backupName, "Remote backup deleted: {$backupName}");

        return ['ok' => true, 'message' => "Backup {$backupName} deleted"];
    }
}
