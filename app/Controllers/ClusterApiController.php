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
    /** Payload keys whose values must never be written to the (replicated) log. */
    private const SECRET_KEYS = [
        'master_db_pass', 'db_pass', 'dsync_secret', 'shared_secret', 'password',
        'admin_password', 'setup_token', 'db_password', 'secret',
        // Mailbox password hash: travels in mail_create_mailbox payloads and would
        // otherwise land (bcrypt hash) in the replicated panel_log.
        'password_hash', 'dkim_private_key',
        // CardDAV DB credentials + encryption key sent to the slave when preparing
        // its DAV replica (carddav_setup_replica).
        'enc_key',
        // Emparejamiento (pair-accept): token del master y nonce.
        'master_token', 'nonce',
        // Avisos (set-notify-config): contraseña SMTP y token de Telegram en claro
        // por el canal autenticado; el nodo los vuelve a cifrar con su clave.
        'smtp_pass', 'smtp2_pass', 'telegram_token',
        // Cuentas de Cloudflare (sync-failover-config): el token va descifrado.
        'token',
    ];

    /** Nombre de copia seguro (una sola carpeta, sin "." ni ".."); '' si no vale. */
    private static function safeBackupName(mixed $name): string
    {
        $name = basename((string)$name);
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._@+-]{0,200}$/', $name) && !str_contains($name, '..') ? $name : '';
    }

    /** Return a copy of $payload with secret values masked, recursively. */
    private static function redactSecrets(array $payload): array
    {
        foreach ($payload as $k => $v) {
            if (is_array($v)) {
                $payload[$k] = self::redactSecrets($v);
            } elseif ((in_array((string)$k, self::SECRET_KEYS, true)
                    // Cualquier otra clave que por su nombre parezca un secreto (p. ej. caddy_token).
                    || preg_match('/pass|secret|token|private|api_?key|enc_key|hash/i', (string)$k))
                && $v !== '' && $v !== null) {
                $payload[$k] = '***';
            }
        }
        return $payload;
    }

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

        // Record who is monitoring us (master tracking).
        // - Un MASTER también recibe latidos (los de sus slaves): no debe apuntarse
        //   a un slave como "su master".
        // - cluster_master_ip es la IP que vigila el failover y se compara con las
        //   IPs PÚBLICAS de failover_servers. Los latidos suelen llegar por la VPN
        //   (10.10.70.x): no se sustituye una IP pública ya configurada (por el
        //   emparejamiento o a mano) por la privada del latido.
        $callerIp = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
        if ($callerIp !== '' && $effectiveRole !== 'master') {
            $currentMasterIp = Settings::get('cluster_master_ip', '');
            $isPublic = static fn(string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            if ($currentMasterIp === '' || !$isPublic($currentMasterIp) || $isPublic($callerIp)) {
                Settings::set('cluster_master_ip', $callerIp);
            }
            Settings::set('cluster_master_heartbeat_ip', $callerIp);
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

        // Mail backup-replica state, so the master's Infra table can show it without
        // an extra live call per render (best-effort; never let it break heartbeat).
        $mailReplica = 'unknown';
        try {
            $rep = MailService::slaveMailReplicaStatus();
            $mailReplica = $rep['next_step'] === 'ready' ? 'ready'
                         : (!empty($rep['installing']) ? 'installing'
                         : (!empty($rep['services_installed']) ? 'services_only' : 'none'));
        } catch (\Throwable) {}

        echo json_encode([
            'ok'                => true,
            'timestamp'         => date('Y-m-d H:i:s'),
            'role'              => $effectiveRole,
            'cluster_role'      => $clusterRole ?: $envRole,
            'panel_version'     => defined('PANEL_VERSION') ? PANEL_VERSION : 'unknown',
            'db_hash_mismatch'  => $dbHashMismatch,
            'mail_replica'      => $mailReplica,
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

        // Redact secrets before logging: panel_log is replicated (publication FOR
        // ALL TABLES), so a cleartext password/secret here would be persisted and
        // copied to every node. Never let credentials reach the log.
        // Some payloads are bulk data, not control metadata: 'receive-files' and
        // 'carddav_apply_snapshot' (a full contacts/calendars snapshot = PII +
        // large). Log only a summary for those — never dump the body into the
        // replicated panel_log.
        $bulkActions = ['receive-files', 'carddav_apply_snapshot'];
        $logSuffix = in_array($action, $bulkActions, true)
            ? ' (payload omitido: datos en bloque)'
            : ': ' . json_encode(self::redactSecrets($payload));
        LogService::log('cluster.api', $action, "Recibido de nodo #{$callerNodeId}{$logSuffix}");

        // C5 hardening: 'promote' and 'demote' are destructive (demote rebuilds
        // this node's databases from new_master_ip via pg_rewind/basebackup and a
        // full MySQL reseed). A leaked node token must NOT be able to point a node
        // at an arbitrary IP and wipe it. Require that new_master_ip be a
        // REGISTERED cluster node, and that these actions arrive from an
        // authenticated peer (the caller node id must resolve). We also refuse if
        // the caller isn't a known node.
        if (in_array($action, ['promote', 'demote'], true)) {
            if ($callerNodeId <= 0 || !ClusterService::getNode($callerNodeId)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Acción de failover rechazada: llamante no es un nodo del clúster reconocido.']);
                return;
            }
            if ($action === 'demote') {
                $newMasterIp = trim((string)($payload['new_master_ip'] ?? ''));
                if (!ClusterService::isKnownNodeIp($newMasterIp)) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'demote rechazado: new_master_ip no corresponde a ningún nodo registrado del clúster.']);
                    return;
                }
            }
        }

        try {
            $result = match ($action) {
                'sync-hosting'     => ClusterService::handleSyncAction($action, $payload),
                'promote'          => ClusterService::promoteToMaster(),
                'demote'           => ClusterService::demoteToSlave($payload['new_master_ip'] ?? ''),
                'test-connection'  => ['ok' => true, 'message' => 'Connection successful'],
                // Emparejamiento: el master que aprobó nuestra solicitud nos envía su
                // token. Solo vale si ESTE servidor tiene una solicitud en curso hacia
                // ese master y el nonce coincide (ver ClusterPairingService).
                'pair-accept'      => \MuseDockPanel\Services\ClusterPairingService::acceptPairing($payload),
                // Witness probe: does THIS node reach $ip? Used by the failover
                // quorum guard to distinguish a dead master from a network
                // partition before auto-promoting (anti split-brain).
                'probe-host'       => (function () use ($payload) {
                    $ip = trim((string)($payload['ip'] ?? ''));
                    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                        return ['ok' => false, 'error' => 'ip inválida'];
                    }
                    // Anti-SSRF: only probe a REGISTERED cluster node, and only on
                    // an allowed port (panel port / 443). Otherwise a leaked token
                    // could turn this into an arbitrary TCP port scanner. (M3)
                    if (!\MuseDockPanel\Services\ClusterService::isKnownNodeIp($ip)) {
                        return ['ok' => false, 'error' => 'ip no es un nodo del clúster'];
                    }
                    $panelPort = (int)\MuseDockPanel\Settings::get('panel_port', '8444') ?: 8444;
                    $reqPort = (int)($payload['port'] ?? $panelPort);
                    $port = in_array($reqPort, [$panelPort, 443], true) ? $reqPort : $panelPort;
                    $r = \MuseDockPanel\Services\FailoverService::checkHost($ip, $port, 4);
                    return ['ok' => true, 'reachable' => !empty($r['ok'] ?? $r['up'] ?? false), 'ip' => $ip];
                })(),
                'repl-create-user' => \MuseDockPanel\Services\ReplicationService::createReplicationUserForRemote(
                    $payload['engine'] ?? 'pg',
                    $payload['slave_ip'] ?? ''
                ),
                // Report this node's Caddy binary (version/hash/DNS modules) so the
                // master can detect binary drift — DNS provider modules are compiled
                // in and never travel via DB replication or lsyncd. Read-only.
                // Stop serving on this node because another node is being promoted.
                // This is the fencing step that prevents split-brain: without it,
                // the old master keeps accepting writes while the new one does too.
                'fence-self'       => \MuseDockPanel\Services\FailoverSafetyService::fenceSelf(
                    (string)($payload['reason'] ?? 'remote request')
                ),
                'unfence-self'     => \MuseDockPanel\Services\FailoverSafetyService::unfenceSelf(),
                'caddy-info'       => \MuseDockPanel\Services\CaddyBinaryService::localInfo(),
                // Build the missing DNS provider module INTO this node's Caddy via
                // xcaddy. ASYNC: xcaddy takes minutes but FPM kills requests at
                // 120s, so we launch the build detached and return a task_id
                // immediately; the master polls 'caddy-install-status'.
                'caddy-install-dns-module' => \MuseDockPanel\Services\SystemService::startCaddyDnsProviderBuild(
                    (string)($payload['provider'] ?? '')
                ),
                'caddy-install-status' => \MuseDockPanel\Services\SystemService::caddyDnsBuildStatus(
                    (string)($payload['task_id'] ?? '')
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
                'mail_upsert_alias'     => MailService::nodeUpsertAlias($payload),
                'mail_delete_alias'     => MailService::nodeDeleteAlias($payload),
                'mail_update_quota'     => MailService::nodeUpdateQuota($payload),
                'mail_suspend_mailbox'  => MailService::nodeSuspendMailbox($payload),
                'mail_activate_mailbox' => MailService::nodeActivateMailbox($payload),
                'mail_update_autoresponder' => MailService::nodeUpdateAutoresponder($payload),
                'mail_enable_sieve'     => MailService::nodeEnableSieve($payload),
                // Mailbox replication (dsync) — configure this node to replicate
                // its Maildir with a partner mail node over WireGuard.
                'mail_setup_replication'   => \MuseDockPanel\Services\MailReplicationService::configureNode(
                    (string)($payload['partner_ip'] ?? ''),
                    false,
                    (string)($payload['shared_secret'] ?? '')
                ),
                'mail_replication_status'  => \MuseDockPanel\Services\MailReplicationService::status(),
                'mail_replication_initial_sync' => \MuseDockPanel\Services\MailReplicationService::initialSync(
                    (string)($payload['partner_ip'] ?? '')
                ),
                // Backup-replica install driven by the master orchestrator: install
                // mail services reading the master's DB (clear password provided by
                // the master), then set up this node's dsync side with the shared
                // secret. All secrets arrive over the authenticated cluster channel.
                'mail_setup_backup_replica' => MailService::nodeSetupBackupReplica($payload),
                // Phase 2 (enqueued): configure dsync + initial sync once services
                // are installed. Retried by the cluster worker until Dovecot is ready.
                'mail_finalize_backup_replica' => MailService::finalizeBackupReplica($payload),
                // Read-only replica state for the master's Infra table (per node).
                'mail_replica_status' => ['ok' => true, 'result' => MailService::slaveMailReplicaStatus()],
                // Apply an anti-abuse policy toggle pushed from the master, so every
                // mail node shares the same fail2ban/rate-limit/whitelist protection.
                'mail_apply_policy' => \MuseDockPanel\Services\MailPolicyService::nodeApplyPolicy($payload),
                'mail_set_rate'     => \MuseDockPanel\Services\MailPolicyService::nodeSetRate($payload),
                // CardDAV/CalDAV failover: the master pushes a full snapshot of
                // the Baïkal data tables; this node replaces its local baikal DB
                // with that authoritative snapshot (see CardDavService).
                'carddav_apply_snapshot' => \MuseDockPanel\Services\CardDavService::applySnapshot($payload),
                // Master-orchestrated: install Baïkal on this slave so it can
                // receive DAV snapshots and serve after a failover.
                'carddav_setup_replica'  => \MuseDockPanel\Services\CardDavService::nodeSetupReplica($payload),
                // Report this node's CardDAV install progress (for the master's modal).
                'carddav_install_status' => \MuseDockPanel\Services\CardDavService::installStatus(),
                'mail_setup_node'          => MailService::nodeSetupMail($payload),
                'mail_setup_status'        => MailService::nodeSetupStatus($payload),
                'mail_generate_setup_token' => MailService::nodeGenerateSetupToken($payload),
                'mail_rotate_db_password'  => MailService::nodeRotateDbPassword($payload),
                'mail_check_configured'   => MailService::nodeCheckConfigured(),
                'mail_db_health'          => MailService::nodeMailDbHealth($payload),
                'mail_relay_create_domain' => MailService::nodeRelayCreateDomain($payload),
                'mail_relay_delete_domain' => MailService::nodeRelayDeleteDomain($payload),
                'mail_relay_create_user'   => MailService::nodeRelayCreateUser($payload),
                'mail_relay_update_user'   => MailService::nodeRelayUpdateUser($payload),
                'mail_relay_delete_user'   => MailService::nodeRelayDeleteUser($payload),
                'mail_migration_preflight' => MailService::nodeMailMigrationPreflight($payload),
                'mail_relay_import_domain' => MailService::nodeRelayImportDomain($payload),
                'mail_relay_import_user'   => MailService::nodeRelayImportUser($payload),

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
                // Cambio de rol planificado (RoleSwitchService).
                'role-switch-health'  => ['ok' => true, 'result' => \MuseDockPanel\Services\RoleSwitchService::health()],
                'role-switch-promote' => ['ok' => true, 'result' => \MuseDockPanel\Services\RoleSwitchService::startPromoteHere(
                    (string)($payload['old_vpn_ip'] ?? ''), (string)($payload['old_public_ip'] ?? ''), (string)($payload['new_public_ip'] ?? ''),
                    (string)($payload['orchestrator_task'] ?? ''))],
                // Un nodo que acaba de ponerse como copia pide al master su configuración de
                // relevo (si estuvo caído, el envío normal pudo agotar sus reintentos).
                'push-failover-config' => \MuseDockPanel\Settings::get('cluster_role', '') === 'master'
                    ? ['ok' => true, 'result' => \MuseDockPanel\Services\FailoverService::pushConfigToSlaves()]
                    : ['ok' => false, 'error' => 'este nodo no es el master'],
                'role-switch-status'  => ['ok' => true, 'result' => \MuseDockPanel\Services\RoleSwitchService::status((string)($payload['task'] ?? ''))],
                // Otro nodo del cluster pide enviar un aviso (él puede tener el correo parado, p. ej. apartado).
                'notify-relay' => (function () use ($payload) {
                    $subj = mb_substr(trim((string)($payload['subject'] ?? '')), 0, 200);
                    $msg = mb_substr((string)($payload['message'] ?? ''), 0, 20000);
                    if ($subj === '') {
                        return ['ok' => false, 'error' => 'falta el asunto'];
                    }
                    \MuseDockPanel\Services\NotificationService::send($subj, $msg);
                    return ['ok' => true];
                })(),
                // Un slave pide al master que le pase el mando a él.
                'role-switch-request' => (function () {
                    $nid = (int)($_REQUEST['_api_node_id'] ?? 0);
                    if ($nid < 1) {
                        return ['ok' => false, 'error' => 'quien pide no es un nodo registrado en este master'];
                    }
                    return ['ok' => true, 'result' => \MuseDockPanel\Services\RoleSwitchService::start($nid, 'petición del nodo')];
                })(),
                // Configuración de copia de ficheros de este nodo (sin secretos), para que
                // el nodo que se promueve la adopte (ClusterService::adoptPeerAsFileSyncTarget).
                'filesync-snapshot' => ['ok' => true, 'snapshot' => \MuseDockPanel\Services\FileSyncService::snapshotForPeer()],
                // Configuración del sistema (supervisor, cron, Caddyfile, pools PHP) que
                // el slave copia y adapta (ConfigMirrorService). Solo lectura aquí.
                'export-system-config' => \MuseDockPanel\Services\ConfigMirrorService::export(),
                // Clientes del portal y a quién pertenece cada hosting (la base del panel es
                // de cada nodo): el slave los pide para poder servir el portal si se promueve.
                'export-portal-state' => \MuseDockPanel\Services\PortalService::exportState(!empty($payload['own_only'])),
                // El master pide las cuentas de Cloudflare de este nodo (con el token en claro,
                // como cuando él las envía): misma confianza que ese envío (token del cluster;
                // los peers federados no llegan a esta API) y solo si este nodo no manda.
                'export-cf-accounts' => Settings::get('cluster_role', '') === 'slave'
                    ? ['ok' => true, 'accounts' => \MuseDockPanel\Services\CloudflareService::accountsForTransfer()]
                    : ['ok' => false, 'error' => 'solo lo responde una copia (slave)'],
                // El principal avisa de un cambio de clientes: la copia los trae ya.
                'portal-sync-now' => \MuseDockPanel\Services\PortalService::pullFromMaster(),
                // Avisos del master copiados a este nodo (notify_configure copy_to_nodes).
                'set-notify-config' => \MuseDockPanel\Services\NotificationService::importConfig($payload),
                'set-alert-policy' => \MuseDockPanel\Services\AlertPolicyService::import($payload),
                'set-alert-policy-master' => \MuseDockPanel\Services\AlertPolicyService::importFromNode($payload),
                // Inventario de este nodo (solo lectura) para que el master compare
                // (cluster_drift). No depende de que el MCP esté activado aquí.
                'clone-inventory'  => ['ok' => true, 'inventory' => \MuseDockPanel\Mcp\McpInventory::build(
                    in_array($payload['section'] ?? 'all', array_merge(['all'], \MuseDockPanel\Mcp\McpInventory::SECTIONS), true)
                        ? (string)($payload['section'] ?? 'all') : 'all', false)],
                'mcp-call'         => $this->handleMcpCall($payload),

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
        $dumpPath = (string)($payload['dump_path'] ?? '/tmp/musedock-dumps');
        // Security: only allow known dump paths (sin ".." para no salir de la carpeta)
        if (!str_starts_with($dumpPath, '/tmp/musedock-dumps') || str_contains($dumpPath, '..')) {
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
            // En un slave, la IP del master que vigila el failover es la del servidor
            // PRIMARIO de esta configuración (pública), no la de la VPN de los latidos.
            $role = Settings::get('cluster_role', '') ?: Env::get('PANEL_ROLE', 'standalone');
            if ($role === 'slave') {
                foreach ($servers as $srv) {
                    if (($srv['role'] ?? '') === 'primary' && ($srv['enabled'] ?? true)
                        && filter_var($srv['ip'] ?? '', FILTER_VALIDATE_IP)) {
                        Settings::set('cluster_master_ip', (string)$srv['ip']);
                        break;
                    }
                }
            }
        }

        // Save Cloudflare accounts (force encrypted-at-rest for tokens).
        // Una lista VACÍA del master no borra las cuentas propias del slave: es el
        // slave quien cambia el DNS en un relevo, y puede tener su propia cuenta
        // (p. ej. un token limitado a sus zonas) aunque el master no tenga ninguna.
        if (is_array($cfAccounts) && $cfAccounts === []
            && (json_decode(Settings::get('failover_cf_accounts', '[]'), true) ?: []) !== []) {
            $cfAccounts = null;
        }
        if ($cfAccounts !== null && is_array($cfAccounts)) {
            // Cifrado con la clave LOCAL; lo que no tenga forma de token (p. ej. el
            // texto cifrado de un master antiguo) no pisa el token que ya había.
            $kept = \MuseDockPanel\Services\CloudflareService::storeIncomingAccounts($cfAccounts);
            if ($kept) {
                LogService::log('failover.sync', 'cf-kept', 'Token de Cloudflare recibido no válido; se conserva el local en: ' . implode(', ', $kept));
            }
        }

        // Save remote domains
        if ($remoteDomains !== null) {
            Settings::set('failover_remote_domains', (string)$remoteDomains);
        }

        // Propagate Cloudflare token to /etc/default/caddy on slave (for SSL certificates).
        //
        // Every failure below used to be silent: the master reported "synced with
        // slaves" while the slave had quietly written nothing (missing helper
        // script on a freshly installed node, or a token it could not decrypt
        // because each node derives its key from its OWN DB_PASS). Report the
        // reason back to the master instead.
        $caddyTokenUpdated = false;
        $caddyTokenError = '';
        $caddyTokenChecked = false;
        $updateCaddyToken = !empty($payload['update_caddy_token']);
        $wantedToken = trim((string)($payload['caddy_token'] ?? ''));
        if ($wantedToken !== '') {
            // Master moderno: manda el token elegido; solo se reinicia Caddy si cambia
            // (o si el master pide forzar). Verificado contra Cloudflare antes.
            $caddyTokenChecked = true;
            [$caddyTokenUpdated, $err] = \MuseDockPanel\Services\CloudflareService::syncCaddyToken($wantedToken, $updateCaddyToken);
            $caddyTokenError = (string)($err ?? '');
            if ($caddyTokenUpdated) {
                LogService::log('failover.sync', 'caddy-token', 'Caddy CLOUDFLARE_API_TOKEN updated on slave from master sync');
            }
        } elseif ($updateCaddyToken) {
            // Master antiguo: primer token de la lista.
            $first = is_array($cfAccounts) ? ($cfAccounts[0] ?? []) : [];
            $tokenRaw = (string)($first['token'] ?? '');
            $token = $tokenRaw !== '' ? \MuseDockPanel\Services\ReplicationService::decryptPassword($tokenRaw) : '';
            if ($token === '') { $token = trim($tokenRaw); }
            if ($token === '') {
                $caddyTokenError = 'El master no envió un token utilizable.';
            } else {
                $caddyTokenChecked = true;
                [$caddyTokenUpdated, $err] = \MuseDockPanel\Services\CloudflareService::syncCaddyToken($token, true);
                $caddyTokenError = (string)($err ?? '');
            }
        }
        if ($caddyTokenError !== '') {
            LogService::log('failover.sync', 'caddy-token-failed', $caddyTokenError);
        }

        Settings::set('failover_config_synced_at', date('Y-m-d H:i:s'));
        LogService::log('failover.sync', 'received', 'Failover config synced from master' . ($caddyTokenUpdated ? ' (Caddy token updated)' : ''));

        return [
            'ok'                  => true,
            'message'             => 'Failover config synced',
            'synced_at'           => date('Y-m-d H:i:s'),
            'caddy_token_updated' => $caddyTokenUpdated,
            // Tell the master WHY the token did not land, so the UI can stop
            // claiming success while the slave silently wrote nothing.
            'caddy_token_error'   => $caddyTokenError,
            'caddy_token_checked' => $caddyTokenChecked,
        ];
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
        // Descifrado: el nodo que pide la configuración no puede descifrar lo cifrado aquí.
        $cfAccounts = \MuseDockPanel\Services\CloudflareService::accountsForTransfer();
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

        // El antiguo master (o un nodo apartado) NO se reconfigura por aquí: eso lo hace
        // el cambio de rol / demote con sus comprobaciones (rebobinado de PostgreSQL,
        // GTID de MariaDB). Antes, el aviso del nodo recién promovido hacía que el antiguo
        // master se sembrara MariaDB entera dentro de esta petición, con el panel
        // bloqueado mientras tanto (2026-10-03).
        if ($myRole === 'master' || Settings::get('cluster_fenced', '0') === '1' || is_file(\MuseDockPanel\Services\FailoverSafetyService::FENCE_FLAG)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'este nodo es (o era) el master o está apartado: se convierte en copia con el demote, no por aviso'];
        }

        $errors = [];
        $results = [];

        // PostgreSQL: no se toca por aquí. El camino antiguo (setupPgSlave) es de un solo
        // clúster y reconstruye desde cero; las réplicas por clúster se gestionan en
        // Replicación o con el demote (rebobinado + slots).
        $results['pg'] = ['skipped' => true, 'reason' => 'PostgreSQL se reconfigura por clúster (Replicación / demote), no por aviso'];

        // MariaDB: solo si este nodo ya es réplica, y solo siguiendo por GTID (solo lo
        // nuevo). Nunca una copia completa automática: si no cuadra, se avisa y se decide.
        $mysqlUser = Settings::get('repl_mysql_user', 'repl_user');
        $mysqlPass = \MuseDockPanel\Services\ReplicationService::decryptPassword(Settings::get('repl_mysql_pass', ''));
        $mysqlPort = (int)Settings::get('repl_mysql_port', '3306');

        if (Settings::get('repl_mysql_role', 'standalone') !== 'slave') {
            $results['mysql'] = ['skipped' => true, 'reason' => 'MariaDB de este nodo no es réplica'];
        } elseif ($mysqlUser && $mysqlPass) {
            try {
                $gtid = \MuseDockPanel\Services\FailoverSafetyService::mysqlCanFollowByGtid($newMasterIp, $mysqlPort, $mysqlUser, $mysqlPass);
                if (!empty($gtid['ok'])) {
                    $mysqlResult = \MuseDockPanel\Services\ReplicationService::setupMysqlSlave($newMasterIp, $mysqlPort, $mysqlUser, $mysqlPass, false);
                    $results['mysql'] = $mysqlResult + ['mode' => 'GTID (solo lo nuevo)'];
                    if (!$mysqlResult['ok']) {
                        $errors[] = 'MySQL: ' . ($mysqlResult['error'] ?? 'Unknown');
                    }
                } else {
                    $errors[] = 'MariaDB no puede seguir al nuevo master por GTID (' . ($gtid['reason'] ?? '?') . '): necesita una copia completa, que no se hace sola. Hazla desde Replicación cuando decidas.';
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
                        $acct['token'], $zone['id'], $srcIp, $backupIp, $ttl,
                        \MuseDockPanel\Services\FailoverService::DNS_JOURNAL_BACKUP
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
                    $r = \MuseDockPanel\Services\CloudflareService::revertJournal(
                        \MuseDockPanel\Services\FailoverService::DNS_JOURNAL_BACKUP,
                        $acct['token'], $zone['id'], $backupIp, $ttl
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
    /**
     * Ejecuta una herramienta MCP de solo lectura en ESTE nodo, a petición del
     * master (MCP con argumento `node`). Llega ya autenticada con el token del
     * cluster, pero además exige que el MCP esté activado en este nodo: por
     * defecto todo está parado en cada nodo. Sin `node` anidado (no hay saltos).
     */
    private function handleMcpCall(array $payload): array
    {
        if (\MuseDockPanel\Settings::get('mcp_enabled', '0') !== '1') {
            return ['ok' => false, 'error' => 'El MCP está desactivado en este nodo (Ajustes → MCP).'];
        }
        if (\MuseDockPanel\Settings::get('mcp_allow_forwarded', '1') !== '1') {
            return ['ok' => false, 'error' => 'Este nodo no acepta consultas MCP reenviadas desde otros nodos (Ajustes → MCP): conéctate a su MCP con su propio token.'];
        }
        $tool = (string)($payload['tool'] ?? '');
        if (!\MuseDockPanel\Mcp\McpTools::exists($tool)) {
            return ['ok' => false, 'error' => "Herramienta desconocida: {$tool}"];
        }
        // Por la vía nodo-a-nodo solo se permiten herramientas de lectura.
        if (\MuseDockPanel\Mcp\McpTools::isWrite($tool)) {
            return ['ok' => false, 'error' => 'Las acciones que modifican no se ejecutan a través de otro nodo.'];
        }
        $args = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
        unset($args['node']);
        // Que quede constancia AQUÍ (en el nodo consultado) de quién preguntó.
        try {
            $from = (int)($_REQUEST['_api_node_id'] ?? 0);
            $fromName = $from > 0 ? (string)(\MuseDockPanel\Services\ClusterService::getNode($from)['name'] ?? "nodo {$from}") : 'token de cluster local';
            LogService::log('mcp.call', $tool, "reenviada desde {$fromName}");
        } catch (\Throwable) {
        }
        try {
            $data = \MuseDockPanel\Mcp\McpTools::runLocal($tool, $args);
            return ['ok' => true, 'data' => \MuseDockPanel\Mcp\McpTools::redact($data)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function handleQueryLocalState(): array
    {
        return [
            'ok'   => true,
            'state' => [
                'hostname'                    => (string)gethostname(),
                'panel_hostname'              => (string)\MuseDockPanel\Settings::get('panel_hostname', ''),
                'failover_state'              => Settings::get('failover_state', 'normal'),
                'failover_iface_mode'         => Settings::get('failover_iface_mode', 'normal'),
                'failover_dns_changed_locally' => Settings::get('failover_dns_changed_locally', '0') === '1',
                'failover_dns_changed_at'     => Settings::get('failover_dns_changed_at', ''),
                'cluster_role'                => Settings::get('cluster_role', 'slave'),
                'repl_role'                   => Settings::get('repl_role', 'slave'),
                // Para que un master que vuelve detecte que otro se promovió después
                // que él (FailoverSafetyService::checkStaleMaster).
                'cluster_promoted_at'         => Settings::get('cluster_promoted_at', ''),
                'cluster_fenced'              => Settings::get('cluster_fenced', '0') === '1',
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
        $backupName = self::safeBackupName($payload['backup_name'] ?? '');

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
        $backupName = self::safeBackupName($payload['backup_name'] ?? '');

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
        $backupName = self::safeBackupName($payload['backup_name'] ?? '');

        if (!$backupName) {
            return ['ok' => false, 'error' => 'Missing backup_name'];
        }

        $backupPath = $backupDir . '/' . $backupName;
        $realPath = realpath($backupPath);
        $realBackupDir = realpath($backupDir);

        if (!$realPath || !$realBackupDir || !str_starts_with($realPath, $realBackupDir . '/') || $realPath === $realBackupDir) {
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
