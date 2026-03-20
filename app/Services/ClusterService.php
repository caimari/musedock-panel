<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;
use MuseDockPanel\Env;
use MuseDockPanel\Services\FirewallService;
use MuseDockPanel\Services\NotificationService;

class ClusterService
{
    // ═══════════════════════════════════════════════════════════
    // ─── Node Management ─────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function getNodes(): array
    {
        return Database::fetchAll('SELECT * FROM cluster_nodes ORDER BY name');
    }

    /** Get only nodes with web service — use for hosting sync (excludes mail-only nodes) */
    public static function getWebNodes(): array
    {
        return Database::fetchAll("SELECT * FROM cluster_nodes WHERE services::text LIKE '%web%' ORDER BY name");
    }

    /** Get only nodes NOT in standby — use for sync, queue processing, alerts */
    public static function getActiveNodes(): array
    {
        return Database::fetchAll('SELECT * FROM cluster_nodes WHERE standby = false ORDER BY name');
    }

    public static function getNode(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM cluster_nodes WHERE id = :id', ['id' => $id]);
    }

    public static function addNode(string $name, string $apiUrl, string $authToken, array $services = ['web']): int
    {
        $encryptedToken = ReplicationService::encryptPassword($authToken);
        return Database::insert('cluster_nodes', [
            'name'       => $name,
            'api_url'    => rtrim($apiUrl, '/'),
            'auth_token' => $encryptedToken,
            'status'     => 'offline',
            'role'       => 'standalone',
            'services'   => json_encode($services),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function updateNode(int $id, array $data): void
    {
        // Encrypt token if present
        if (isset($data['auth_token']) && $data['auth_token'] !== '') {
            $data['auth_token'] = ReplicationService::encryptPassword($data['auth_token']);
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        Database::update('cluster_nodes', $data, 'id = :id', ['id' => $id]);
    }

    public static function removeNode(int $id): void
    {
        // Delete related queue items first (cascade should handle it, but be explicit)
        Database::delete('cluster_queue', 'node_id = :nid', ['nid' => $id]);
        Database::delete('cluster_nodes', 'id = :id', ['id' => $id]);
    }

    public static function generateToken(): string
    {
        return bin2hex(openssl_random_pseudo_bytes(32));
    }

    public static function getLocalStatus(): array
    {
        // Effective role: cluster_role (DB) takes priority over PANEL_ROLE (.env)
        $clusterRole = Settings::get('cluster_role', '');
        $envRole = Env::get('PANEL_ROLE', 'standalone');
        $role = ($clusterRole !== '' && $clusterRole !== 'standalone') ? $clusterRole : $envRole;

        // Uptime
        $uptime = trim((string)shell_exec('uptime -s 2>/dev/null'));

        // PostgreSQL 5432 (main cluster)
        $pg5432 = self::checkServiceStatus('postgresql', 5432);

        // PostgreSQL 5433 (panel cluster)
        $pg5433 = self::checkServiceStatus('postgresql', 5433);

        // MySQL status
        $mysqlStatus = self::checkServiceStatus('mysql', 3306);

        // Replication status
        $pgReplication = null;
        $mysqlReplication = null;
        $replRole = Settings::get('repl_role', 'standalone');

        try {
            if ($replRole === 'master') {
                $pgReplication = ReplicationService::getPgMasterStatus();
                $mysqlReplication = ReplicationService::getMysqlMasterStatus();
            } elseif ($replRole === 'slave') {
                $pgReplication = ReplicationService::getPgSlaveStatus();
                $mysqlReplication = ReplicationService::getMysqlSlaveStatus();
            }
        } catch (\Throwable) {}

        // Disk usage
        $diskUsage = self::parseDiskUsage();

        // RAM usage
        $ramUsage = self::parseMemInfo();

        // CPU load
        $cpuLoad = self::parseCpuLoad();

        // Hosting count
        $hostingCount = 0;
        try {
            $row = Database::fetchOne('SELECT COUNT(*) AS cnt FROM hosting_accounts');
            $hostingCount = (int)($row['cnt'] ?? 0);
        } catch (\Throwable) {}

        // Panel version
        $panelVersion = defined('PANEL_VERSION') ? PANEL_VERSION : '0.4.0';

        // Active hostings
        $hostings = [];
        try {
            $hostings = Database::fetchAll("SELECT id, domain, username, status FROM hosting_accounts WHERE status != 'deleted' ORDER BY domain");
        } catch (\Throwable) {}

        return [
            'role'              => $role,
            'repl_role'         => $replRole,
            'cluster_role'      => Settings::get('cluster_role', 'standalone'),
            'uptime'            => $uptime,
            'pg_5432_status'    => $pg5432,
            'pg_5433_status'    => $pg5433,
            'mysql_status'      => $mysqlStatus,
            'pg_replication'    => $pgReplication,
            'mysql_replication' => $mysqlReplication,
            'disk_usage'        => $diskUsage,
            'ram_usage'         => $ramUsage,
            'cpu_load'          => $cpuLoad,
            'hosting_count'     => $hostingCount,
            'panel_version'     => $panelVersion,
            'hostings'          => $hostings,
            'timestamp'         => date('Y-m-d H:i:s'),
        ];
    }

    private static function checkServiceStatus(string $service, int $port): array
    {
        $running = false;
        $replRole = 'standalone';

        if ($service === 'postgresql') {
            // Check if port is listening
            $check = trim((string)shell_exec("ss -tlnp 2>/dev/null | grep ':{$port} '"));
            $running = !empty($check);

            if ($running) {
                // Detect replication role
                try {
                    $isInRecovery = trim((string)shell_exec("sudo -u postgres psql -p {$port} -tAc \"SELECT pg_is_in_recovery()\" 2>/dev/null"));
                    if ($isInRecovery === 't') {
                        $replRole = 'slave';
                    } elseif ($isInRecovery === 'f') {
                        // Check if has any replication connections
                        $replCount = trim((string)shell_exec("sudo -u postgres psql -p {$port} -tAc \"SELECT count(*) FROM pg_stat_replication\" 2>/dev/null"));
                        $replRole = ((int)$replCount > 0) ? 'master' : 'standalone';
                    }
                } catch (\Throwable) {}
            }
        } elseif ($service === 'mysql') {
            $check = trim((string)shell_exec("ss -tlnp 2>/dev/null | grep ':{$port} '"));
            $running = !empty($check);

            if ($running) {
                try {
                    $slaveStatus = trim((string)shell_exec("mysql -e \"SHOW SLAVE STATUS\\G\" 2>/dev/null"));
                    if (!empty($slaveStatus) && str_contains($slaveStatus, 'Slave_IO_Running')) {
                        $replRole = 'slave';
                    } else {
                        $masterStatus = trim((string)shell_exec("mysql -e \"SHOW MASTER STATUS\\G\" 2>/dev/null"));
                        if (!empty($masterStatus) && str_contains($masterStatus, 'File:')) {
                            $replRole = 'master';
                        }
                    }
                } catch (\Throwable) {}
            }
        }

        return [
            'running'  => $running,
            'repl_role' => $replRole,
        ];
    }

    private static function parseDiskUsage(): array
    {
        $output = trim((string)shell_exec("df -h / 2>/dev/null | tail -1"));
        if (!$output) {
            return ['total' => '?', 'used' => '?', 'available' => '?', 'percent' => '?'];
        }
        $parts = preg_split('/\s+/', $output);
        return [
            'total'     => $parts[1] ?? '?',
            'used'      => $parts[2] ?? '?',
            'available' => $parts[3] ?? '?',
            'percent'   => $parts[4] ?? '?',
        ];
    }

    private static function parseMemInfo(): array
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if (!$meminfo) {
            return ['total' => 0, 'used' => 0, 'available' => 0, 'percent' => 0];
        }

        $total = 0;
        $available = 0;
        if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) $total = (int)$m[1];
        if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $m)) $available = (int)$m[1];
        $used = $total - $available;
        $percent = $total > 0 ? round(($used / $total) * 100, 1) : 0;

        return [
            'total'     => round($total / 1024, 0),   // MB
            'used'      => round($used / 1024, 0),
            'available' => round($available / 1024, 0),
            'percent'   => $percent,
        ];
    }

    private static function parseCpuLoad(): array
    {
        $loadavg = @file_get_contents('/proc/loadavg');
        if (!$loadavg) {
            return ['1min' => 0, '5min' => 0, '15min' => 0];
        }
        $parts = explode(' ', $loadavg);
        return [
            '1min'  => (float)($parts[0] ?? 0),
            '5min'  => (float)($parts[1] ?? 0),
            '15min' => (float)($parts[2] ?? 0),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Queue Management ────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function enqueue(int $nodeId, string $action, array $payload, int $priority = 5): int
    {
        return Database::insert('cluster_queue', [
            'node_id'      => $nodeId,
            'action'       => $action,
            'payload'      => json_encode($payload),
            'status'       => 'pending',
            'priority'     => $priority,
            'attempts'     => 0,
            'max_attempts' => 3,
            'scheduled_at' => date('Y-m-d H:i:s'),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    public static function getPendingQueue(int $nodeId = 0): array
    {
        if ($nodeId > 0) {
            return Database::fetchAll(
                "SELECT q.*, n.name AS node_name FROM cluster_queue q LEFT JOIN cluster_nodes n ON n.id = q.node_id WHERE q.status = 'pending' AND q.node_id = :nid AND (n.standby IS NULL OR n.standby = false) ORDER BY q.priority ASC, q.scheduled_at ASC",
                ['nid' => $nodeId]
            );
        }
        return Database::fetchAll(
            "SELECT q.*, n.name AS node_name FROM cluster_queue q LEFT JOIN cluster_nodes n ON n.id = q.node_id WHERE q.status = 'pending' AND (n.standby IS NULL OR n.standby = false) ORDER BY q.priority ASC, q.scheduled_at ASC"
        );
    }

    public static function processQueue(): array
    {
        $pending = self::getPendingQueue();
        $results = [];

        foreach ($pending as $item) {
            $id = (int)$item['id'];
            $nodeId = (int)$item['node_id'];

            // Mark as processing
            Database::update('cluster_queue', [
                'status'     => 'processing',
                'started_at' => date('Y-m-d H:i:s'),
                'attempts'   => (int)$item['attempts'] + 1,
            ], 'id = :id', ['id' => $id]);

            $payload = json_decode($item['payload'] ?? '{}', true) ?: [];

            try {
                $response = self::callNode($nodeId, 'POST', 'api/cluster/action', [
                    'action'  => $item['action'],
                    'payload' => $payload,
                ]);

                if ($response['ok']) {
                    self::markCompleted($id);
                    $results[] = ['id' => $id, 'ok' => true, 'action' => $item['action']];
                } else {
                    $error = $response['error'] ?? 'Remote node returned error';
                    self::markFailed($id, $error);
                    $results[] = ['id' => $id, 'ok' => false, 'action' => $item['action'], 'error' => $error];
                }
            } catch (\Throwable $e) {
                self::markFailed($id, $e->getMessage());
                $results[] = ['id' => $id, 'ok' => false, 'action' => $item['action'], 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    public static function markCompleted(int $id): void
    {
        Database::update('cluster_queue', [
            'status'       => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
    }

    public static function markFailed(int $id, string $error): void
    {
        $item = Database::fetchOne('SELECT attempts, max_attempts FROM cluster_queue WHERE id = :id', ['id' => $id]);
        $status = 'failed';
        if ($item && (int)$item['attempts'] < (int)$item['max_attempts']) {
            $status = 'pending'; // Will be retried
        }

        Database::update('cluster_queue', [
            'status'        => $status,
            'error_message' => $error,
        ], 'id = :id', ['id' => $id]);
    }

    public static function retryItem(int $id): void
    {
        $item = Database::fetchOne('SELECT attempts, max_attempts FROM cluster_queue WHERE id = :id', ['id' => $id]);
        if (!$item) return;

        if ((int)$item['attempts'] < (int)$item['max_attempts']) {
            Database::update('cluster_queue', [
                'status'        => 'pending',
                'error_message' => null,
                'started_at'    => null,
                'completed_at'  => null,
            ], 'id = :id', ['id' => $id]);
        }
    }

    public static function cleanOldItems(int $daysOld = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));
        return Database::delete(
            'cluster_queue',
            "status = 'completed' AND completed_at < :cutoff",
            ['cutoff' => $cutoff]
        );
    }

    public static function getQueueStats(): array
    {
        $rows = Database::fetchAll(
            "SELECT status, COUNT(*) AS cnt FROM cluster_queue GROUP BY status"
        );
        $stats = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $stats[$r['status']] = (int)$r['cnt'];
        }
        return $stats;
    }

    public static function getRecentQueue(int $limit = 20): array
    {
        return Database::fetchAll(
            "SELECT q.*, n.name AS node_name FROM cluster_queue q LEFT JOIN cluster_nodes n ON n.id = q.node_id ORDER BY q.created_at DESC LIMIT :lim",
            ['lim' => $limit]
        );
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Remote API Calls ────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function callNode(int $nodeId, string $method, string $endpoint, array $data = []): array
    {
        $node = self::getNode($nodeId);
        if (!$node) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Node not found'];
        }

        $token = ReplicationService::decryptPassword($node['auth_token']);
        $apiUrl = rtrim($node['api_url'], '/');

        return self::callNodeDirect($apiUrl, $token, $method, $endpoint, $data);
    }

    public static function callNodeDirect(string $apiUrl, string $token, string $method, string $endpoint, array $data = [], int $timeout = 30): array
    {
        $url = rtrim($apiUrl, '/') . '/' . ltrim($endpoint, '/');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $method = strtoupper($method);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error) {
            return ['ok' => false, 'status' => $httpCode, 'data' => null, 'error' => $error ?: 'Connection failed'];
        }

        $decoded = json_decode($response, true);
        $ok = $httpCode >= 200 && $httpCode < 300;

        return [
            'ok'     => $ok,
            'status' => $httpCode,
            'data'   => $decoded,
            'error'  => $ok ? '' : ($decoded['error'] ?? "HTTP {$httpCode}"),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Heartbeat ───────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function sendHeartbeat(int $nodeId): array
    {
        $result = self::callNode($nodeId, 'GET', 'api/cluster/heartbeat');

        if ($result['ok']) {
            $remoteData = $result['data'] ?? [];
            Database::update('cluster_nodes', [
                'status'       => 'online',
                'last_seen_at' => date('Y-m-d H:i:s'),
                'role'         => $remoteData['role'] ?? 'unknown',
                'updated_at'   => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $nodeId]);
        } else {
            Database::update('cluster_nodes', [
                'status'     => 'offline',
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $nodeId]);
        }

        return $result;
    }

    public static function checkAllNodes(): array
    {
        $nodes = self::getNodes();
        $summary = [];

        foreach ($nodes as $node) {
            $result = self::sendHeartbeat((int)$node['id']);
            $summary[] = [
                'id'     => $node['id'],
                'name'   => $node['name'],
                'ok'     => $result['ok'],
                'status' => $result['ok'] ? 'online' : 'offline',
                'error'  => $result['error'] ?? '',
            ];
        }

        return $summary;
    }

    public static function isNodeReachable(int $nodeId): bool
    {
        $node = self::getNode($nodeId);
        if (!$node || empty($node['last_seen_at'])) {
            return false;
        }

        $lastSeen = strtotime($node['last_seen_at']);
        return (time() - $lastSeen) < 300; // 5 minutes
    }

    public static function getUnreachableNodes(int $timeoutMinutes = 5): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$timeoutMinutes} minutes"));
        return Database::fetchAll(
            "SELECT * FROM cluster_nodes WHERE last_seen_at IS NULL OR last_seen_at < :cutoff ORDER BY name",
            ['cutoff' => $cutoff]
        );
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Hosting Sync ────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function syncHostingToNode(int $nodeId, string $action, array $hostingData): array
    {
        return self::callNode($nodeId, 'POST', 'api/cluster/action', [
            'action'  => 'sync-hosting',
            'payload' => [
                'hosting_action' => $action,
                'hosting_data'   => $hostingData,
            ],
        ]);
    }

    public static function handleSyncAction(string $action, array $payload): array
    {
        $hostingAction = $payload['hosting_action'] ?? $action;
        $hostingData = $payload['hosting_data'] ?? $payload;

        try {
            switch ($hostingAction) {
                case 'create_hosting':
                    $domain = $hostingData['domain'] ?? '';
                    $username = $hostingData['username'] ?? '';
                    $homeDir = $hostingData['home_dir'] ?? '/var/www/vhosts/' . $domain;
                    $documentRoot = $hostingData['document_root'] ?? $homeDir . '/httpdocs';
                    $phpVersion = $hostingData['php_version'] ?? '8.3';
                    $password = $hostingData['password'] ?? '';
                    $shell = $hostingData['shell'] ?? '/usr/sbin/nologin';

                    // Check if already exists in DB
                    $existing = Database::fetchOne(
                        "SELECT id, system_uid FROM hosting_accounts WHERE domain = :d OR username = :u",
                        ['d' => $domain, 'u' => $username]
                    );
                    if ($existing) {
                        // Hosting exists — repair system user if needed
                        $expectedUid = isset($hostingData['system_uid']) ? (int)$hostingData['system_uid'] : null;
                        $passwordHash = $hostingData['password_hash'] ?? '';
                        $repairs = SystemService::repairSystemUser($username, $expectedUid, $shell, $passwordHash);

                        // Update DB record — sync all fields from master
                        $updateFields = ['shell' => $shell];
                        if ($expectedUid && $expectedUid > 0) {
                            $updateFields['system_uid'] = $expectedUid;
                        }
                        // Sync caddy_route_id from master
                        if (!empty($hostingData['caddy_route_id'])) {
                            $updateFields['caddy_route_id'] = $hostingData['caddy_route_id'];
                        }
                        // Sync quota
                        if (isset($hostingData['disk_quota_mb'])) {
                            $updateFields['disk_quota_mb'] = (int)$hostingData['disk_quota_mb'];
                        }
                        // Sync PHP version
                        if (!empty($hostingData['php_version'])) {
                            $updateFields['php_version'] = $hostingData['php_version'];
                        }
                        // Recalculate disk usage on this server
                        if ($homeDir && is_dir($homeDir)) {
                            $duOutput = trim((string)shell_exec(sprintf('du -sm %s 2>/dev/null | cut -f1', escapeshellarg($homeDir))));
                            if (is_numeric($duOutput)) {
                                $updateFields['disk_used_mb'] = (int)$duOutput;
                            }
                        }
                        Database::update('hosting_accounts', $updateFields, 'id = :id', ['id' => (int)$existing['id']]);

                        $repairMsg = implode(', ', $repairs);
                        LogService::log('cluster.sync', $domain, "Hosting exists, repaired: {$repairMsg}");
                        return ['ok' => true, 'message' => "Hosting {$domain} exists — repaired: {$repairMsg}"];
                    }

                    // Create system account (user, dirs, PHP-FPM, Caddy)
                    // Force same UID as master for consistency
                    $forceUid = isset($hostingData['system_uid']) ? (int)$hostingData['system_uid'] : null;
                    $result = SystemService::createAccount($username, $domain, $homeDir, $documentRoot, $phpVersion, $password, $shell, $forceUid);

                    if (!($result['success'] ?? false)) {
                        return ['ok' => false, 'message' => $result['error'] ?? 'System account creation failed'];
                    }

                    // Apply password hash from master (exact copy, not plaintext)
                    $passwordHash = $hostingData['password_hash'] ?? '';
                    if ($passwordHash) {
                        SystemService::setPasswordHash($username, $passwordHash);
                    }

                    // Insert into hosting_accounts DB
                    $fpmSocket = "unix//run/php/php{$phpVersion}-fpm-{$username}.sock";
                    $accountId = Database::insert('hosting_accounts', [
                        'customer_id'    => $hostingData['customer_id'] ?? null,
                        'domain'         => $domain,
                        'username'       => $username,
                        'system_uid'     => $result['uid'] ?? null,
                        'home_dir'       => $homeDir,
                        'document_root'  => $documentRoot,
                        'php_version'    => $phpVersion,
                        'fpm_socket'     => $fpmSocket,
                        'disk_quota_mb'  => $hostingData['disk_quota_mb'] ?? 1024,
                        'caddy_route_id' => $result['caddy_route_id'] ?? null,
                        'description'    => $hostingData['description'] ?? 'Synced from master',
                        'shell'          => $shell,
                    ]);

                    // Insert primary domain
                    Database::insert('hosting_domains', [
                        'account_id' => $accountId,
                        'domain'     => $domain,
                        'is_primary' => true,
                    ]);

                    LogService::log('cluster.sync', $domain, "Hosting synced from master: {$username}@{$domain}");

                    return ['ok' => true, 'message' => "Account {$domain} created and registered"];

                case 'update_hosting':
                    if (!empty($hostingData['id'])) {
                        $updateFields = array_intersect_key($hostingData, array_flip(['domain', 'username', 'status', 'php_version']));
                        $updateFields['updated_at'] = date('Y-m-d H:i:s');
                        Database::update('hosting_accounts', $updateFields, 'id = :id', ['id' => (int)$hostingData['id']]);
                    }
                    return ['ok' => true, 'message' => 'Hosting updated'];

                case 'update_hosting_full':
                    $domain = $hostingData['domain'] ?? '';
                    $username = $hostingData['username'] ?? '';
                    if (empty($domain)) {
                        return ['ok' => false, 'message' => 'Domain required for update_hosting_full'];
                    }

                    $existing = Database::fetchOne(
                        "SELECT * FROM hosting_accounts WHERE domain = :d",
                        ['d' => $domain]
                    );
                    if (!$existing) {
                        return ['ok' => false, 'message' => "Hosting {$domain} not found on slave"];
                    }

                    $updateFields = ['updated_at' => date('Y-m-d H:i:s')];
                    $changes = [];

                    // Sync document_root
                    $newDocRoot = $hostingData['document_root'] ?? '';
                    if (!empty($newDocRoot) && $newDocRoot !== $existing['document_root']) {
                        // Create directory if needed
                        if (!is_dir($newDocRoot)) {
                            @mkdir($newDocRoot, 0755, true);
                            @chown($newDocRoot, $existing['username']);
                        }
                        // Update Caddy route
                        $phpVer = $hostingData['php_version'] ?? $existing['php_version'];
                        SystemService::updateCaddyDocumentRoot($domain, $newDocRoot, $existing['username'], $phpVer);
                        $updateFields['document_root'] = $newDocRoot;
                        $changes[] = "document_root: {$existing['document_root']} -> {$newDocRoot}";
                    }

                    // Sync PHP version
                    if (!empty($hostingData['php_version']) && $hostingData['php_version'] !== $existing['php_version']) {
                        $updateFields['php_version'] = $hostingData['php_version'];
                        $changes[] = "php: {$existing['php_version']} -> {$hostingData['php_version']}";
                    }

                    // Sync shell
                    if (!empty($hostingData['shell']) && $hostingData['shell'] !== $existing['shell']) {
                        shell_exec(sprintf('usermod -s %s %s 2>&1', escapeshellarg($hostingData['shell']), escapeshellarg($existing['username'])));
                        $updateFields['shell'] = $hostingData['shell'];
                        $changes[] = "shell: {$hostingData['shell']}";
                    }

                    // Sync disk quota
                    if (isset($hostingData['disk_quota_mb'])) {
                        $updateFields['disk_quota_mb'] = (int)$hostingData['disk_quota_mb'];
                    }

                    // Sync description
                    if (isset($hostingData['description'])) {
                        $updateFields['description'] = $hostingData['description'];
                    }

                    Database::update('hosting_accounts', $updateFields, 'id = :id', ['id' => (int)$existing['id']]);

                    $changeMsg = !empty($changes) ? implode(', ', $changes) : 'metadata only';
                    LogService::log('cluster.sync', $domain, "Hosting updated from master: {$changeMsg}");
                    return ['ok' => true, 'message' => "Hosting {$domain} updated: {$changeMsg}"];

                case 'suspend_hosting':
                    $username = $hostingData['username'] ?? '';
                    if ($username) {
                        $result = SystemService::suspendAccount($username);
                        return ['ok' => $result['success'] ?? false, 'message' => $result['error'] ?? 'Account suspended'];
                    }
                    return ['ok' => false, 'message' => 'Username required'];

                case 'activate_hosting':
                    $username = $hostingData['username'] ?? '';
                    if ($username) {
                        $result = SystemService::activateAccount($username);
                        return ['ok' => $result['success'] ?? false, 'message' => $result['error'] ?? 'Account activated'];
                    }
                    return ['ok' => false, 'message' => 'Username required'];

                case 'delete_hosting':
                    $username = $hostingData['username'] ?? '';
                    if ($username) {
                        $result = SystemService::deleteAccount($username);
                        return ['ok' => $result['success'] ?? false, 'message' => $result['error'] ?? 'Account deleted'];
                    }
                    return ['ok' => false, 'message' => 'Username required'];

                default:
                    return ['ok' => false, 'message' => "Unknown hosting action: {$hostingAction}"];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Failover ────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function promoteToMaster(): array
    {
        $errors = [];
        $results = [];

        // Promote PostgreSQL on port 5432
        try {
            $pgVersion = ReplicationService::detectPgVersion();
            if ($pgVersion) {
                $pgCmd = "sudo -u postgres pg_ctl promote -D /var/lib/postgresql/{$pgVersion}/main 2>&1";
                $output = trim((string)shell_exec($pgCmd));
                $results['pg_promote'] = $output;

                // Wait for promotion
                sleep(2);
                $isRecovery = trim((string)shell_exec("sudo -u postgres psql -p 5432 -tAc \"SELECT pg_is_in_recovery()\" 2>/dev/null"));
                if ($isRecovery === 'f') {
                    $results['pg_status'] = 'promoted';
                } else {
                    $errors[] = 'PostgreSQL aun esta en modo recovery despues de promover';
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'PG promote: ' . $e->getMessage();
        }

        // Promote MySQL (STOP SLAVE)
        try {
            shell_exec("mysql -e \"STOP SLAVE; RESET SLAVE ALL;\" 2>&1");
            $results['mysql_promote'] = 'STOP SLAVE executed';
        } catch (\Throwable $e) {
            $errors[] = 'MySQL promote: ' . $e->getMessage();
        }

        // Update env and settings
        self::updateEnvRole('master');
        Settings::set('repl_role', 'master');

        try {
            Database::update('servers', ['role' => 'master'], 'is_local = true');
        } catch (\Throwable) {}

        // Open public ports (80/443) so this server can serve web traffic
        try {
            $fwResults = FirewallService::openPublicPorts();
            $results['firewall'] = $fwResults;
            foreach ($fwResults as $fwr) {
                if (!$fwr['ok']) {
                    $errors[] = "Firewall: no se pudo abrir puerto {$fwr['port']}";
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'Firewall: ' . $e->getMessage();
        }

        LogService::log('cluster.failover', 'promote', 'Servidor promovido a master (puertos 80/443 abiertos)' . (empty($errors) ? '' : ' (con errores: ' . implode(', ', $errors) . ')'));

        NotificationService::send(
            'Failover: Slave promovido a Master',
            'El servidor ha sido promovido a Master. Puertos HTTP/HTTPS abiertos al publico.'
        );

        return [
            'ok'      => empty($errors),
            'results' => $results,
            'errors'  => $errors,
        ];
    }

    public static function demoteToSlave(string $newMasterIp): array
    {
        $errors = [];
        $results = [];

        if (!filter_var($newMasterIp, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'results' => [], 'errors' => ['IP del nuevo master no valida']];
        }

        // Reconfigure PG as slave
        try {
            $pgVersion = ReplicationService::detectPgVersion();
            if ($pgVersion) {
                $pgUser = Settings::get('repl_pg_user', 'replicator');
                $pgPass = ReplicationService::decryptPassword(Settings::get('repl_pg_pass', ''));
                $pgPort = (int)Settings::get('repl_pg_port', '5432');

                $result = ReplicationService::setupPgSlave($newMasterIp, $pgPort, $pgUser, $pgPass);
                $results['pg_demote'] = $result;
                if (!$result['ok']) {
                    $errors[] = 'PG demote: ' . ($result['error'] ?? 'Unknown error');
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'PG demote: ' . $e->getMessage();
        }

        // Reconfigure MySQL as slave
        try {
            $mysqlUser = Settings::get('repl_mysql_user', 'repl_user');
            $mysqlPass = ReplicationService::decryptPassword(Settings::get('repl_mysql_pass', ''));
            $mysqlPort = (int)Settings::get('repl_mysql_port', '3306');

            $result = ReplicationService::setupMysqlSlave($newMasterIp, $mysqlPort, $mysqlUser, $mysqlPass);
            $results['mysql_demote'] = $result;
            if (!$result['ok']) {
                $errors[] = 'MySQL demote: ' . ($result['error'] ?? 'Unknown error');
            }
        } catch (\Throwable $e) {
            $errors[] = 'MySQL demote: ' . $e->getMessage();
        }

        // Update env and settings
        self::updateEnvRole('slave');
        Settings::set('repl_role', 'slave');
        Settings::set('repl_remote_ip', $newMasterIp);

        try {
            Database::update('servers', ['role' => 'slave'], 'is_local = true');
        } catch (\Throwable) {}

        // Close public ports (80/443) — only removes failover-tagged rules
        try {
            $fwResults = FirewallService::closePublicPorts();
            $results['firewall'] = $fwResults;
        } catch (\Throwable $e) {
            $errors[] = 'Firewall: ' . $e->getMessage();
        }

        LogService::log('cluster.failover', 'demote', "Servidor degradado a slave, nuevo master: {$newMasterIp} (puertos 80/443 cerrados)" . (empty($errors) ? '' : ' (con errores: ' . implode(', ', $errors) . ')'));

        NotificationService::send(
            'Failover: Master degradado a Slave',
            "El servidor ha sido degradado a Slave. Nuevo master: {$newMasterIp}. Puertos HTTP/HTTPS cerrados."
        );

        return [
            'ok'      => empty($errors),
            'results' => $results,
            'errors'  => $errors,
        ];
    }

    public static function updateEnvRole(string $role): void
    {
        $envFile = (defined('PANEL_ROOT') ? PANEL_ROOT : '/opt/musedock-panel') . '/.env';
        if (!file_exists($envFile)) return;

        $content = file_get_contents($envFile);

        if (preg_match('/^PANEL_ROLE=.*$/m', $content)) {
            $content = preg_replace('/^PANEL_ROLE=.*$/m', "PANEL_ROLE={$role}", $content);
        } else {
            $content .= "\nPANEL_ROLE={$role}\n";
        }

        file_put_contents($envFile, $content);
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Notifications ───────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Send alert via all configured notification channels.
     * Delegates to the unified NotificationService.
     */
    public static function sendAlert(string $subject, string $message): void
    {
        NotificationService::send($subject, $message);
    }
}
