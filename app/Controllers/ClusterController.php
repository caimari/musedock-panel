<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\View;
use MuseDockPanel\Flash;
use MuseDockPanel\Settings;
use MuseDockPanel\Env;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\FileSyncService;
use MuseDockPanel\Services\ReplicationService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\SystemService;

class ClusterController
{
    /**
     * GET /settings/cluster
     */
    public function index(): void
    {
        $nodes = ClusterService::getNodes();
        $localStatus = [];
        try {
            $localStatus = ClusterService::getLocalStatus();
        } catch (\Throwable) {}

        $queueStats = [];
        try {
            $queueStats = ClusterService::getQueueStats();
        } catch (\Throwable) {}

        $recentQueue = [];
        try {
            $recentQueue = ClusterService::getRecentQueue(20);
        } catch (\Throwable) {}

        $settings = Settings::getAll();

        // Decrypt local token for display
        $localToken = '';
        $rawToken = $settings['cluster_local_token'] ?? '';
        if ($rawToken) {
            $localToken = ReplicationService::decryptPassword($rawToken);
        }

        // Enrich nodes with mute status
        $now = time();
        foreach ($nodes as &$node) {
            $mutedUntil = Settings::get("cluster_node_{$node['id']}_muted_until", '');
            $node['alerts_muted'] = ($mutedUntil && strtotime($mutedUntil) > $now);
        }
        unset($node);

        // Failover data
        $failoverConfig = [];
        $failoverStatus = [];
        $failoverServers = [];
        $cfAccounts = [];
        try {
            $failoverConfig = \MuseDockPanel\Services\FailoverService::getConfig();
            $failoverStatus = \MuseDockPanel\Services\FailoverService::getStatusSummary();
            $failoverServers = \MuseDockPanel\Services\FailoverService::getServers();
            $cfAccounts = \MuseDockPanel\Services\CloudflareService::getConfiguredAccounts();
        } catch (\Throwable $e) {
            error_log("Failover data load error: " . $e->getMessage());
        }

        View::render('settings/cluster', [
            'layout'         => 'main',
            'pageTitle'      => 'Cluster',
            'nodes'          => $nodes,
            'localStatus'    => $localStatus,
            'queueStats'     => $queueStats,
            'recentQueue'    => $recentQueue,
            'settings'       => $settings,
            'localToken'     => $localToken,
            'failoverConfig'  => $failoverConfig,
            'failoverStatus'  => $failoverStatus,
            'failoverServers' => $failoverServers,
            'cfAccounts'      => $cfAccounts,
        ]);
    }

    /**
     * POST /settings/cluster/add-node
     */
    public function addNode(): void
    {
        View::verifyCsrf();

        $name   = trim($_POST['node_name'] ?? '');
        $apiUrl = trim($_POST['api_url'] ?? '');
        $token  = trim($_POST['auth_token'] ?? '');

        if ($name === '' || $apiUrl === '' || $token === '') {
            Flash::set('error', 'Todos los campos son obligatorios');
            header('Location: /settings/cluster');
            exit;
        }

        if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
            Flash::set('error', 'URL de API no valida');
            header('Location: /settings/cluster');
            exit;
        }

        // Test connection before saving
        $testResult = ClusterService::callNodeDirect($apiUrl, $token, 'POST', 'api/cluster/action', [
            'action' => 'test-connection',
        ]);

        if (!$testResult['ok']) {
            Flash::set('error', 'No se pudo conectar al nodo: ' . ($testResult['error'] ?? 'Error desconocido'));
            header('Location: /settings/cluster');
            exit;
        }

        // Parse services from checkboxes (default to ["web"] if none selected)
        $services = $_POST['services'] ?? ['web'];
        $validServices = array_intersect($services, ['web', 'mail']);
        if (empty($validServices)) $validServices = ['web'];

        $id = ClusterService::addNode($name, $apiUrl, $token, array_values($validServices));
        LogService::log('cluster.node', 'add', "Nodo anadido: {$name} ({$apiUrl}), servicios: " . implode(',', $validServices) . ", ID: {$id}");
        Flash::set('success', "Nodo '{$name}' anadido correctamente");
        header('Location: /settings/cluster');
        exit;
    }

    /**
     * POST /settings/cluster/update-node
     */
    public function updateNode(): void
    {
        View::verifyCsrf();

        $id     = (int)($_POST['node_id'] ?? 0);
        $name   = trim($_POST['node_name'] ?? '');
        $apiUrl = trim($_POST['api_url'] ?? '');
        $token  = trim($_POST['auth_token'] ?? '');

        if ($id < 1) {
            Flash::set('error', 'ID de nodo no valido');
            header('Location: /settings/cluster');
            exit;
        }

        $node = ClusterService::getNode($id);
        if (!$node) {
            Flash::set('error', 'Nodo no encontrado');
            header('Location: /settings/cluster');
            exit;
        }

        $data = [];
        if ($name !== '') $data['name'] = $name;
        if ($apiUrl !== '') $data['api_url'] = $apiUrl;
        if ($token !== '') $data['auth_token'] = $token;

        if (!empty($data)) {
            ClusterService::updateNode($id, $data);
            LogService::log('cluster.node', 'update', "Nodo actualizado: ID {$id}");
            Flash::set('success', 'Nodo actualizado correctamente');
        }

        header('Location: /settings/cluster');
        exit;
    }

    /**
     * POST /settings/cluster/toggle-node-service (JSON)
     * Toggle a service (web/mail) on a node.
     */
    public function toggleNodeService(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $nodeId  = (int)($_POST['node_id'] ?? 0);
        $service = trim($_POST['service'] ?? '');

        if (!$nodeId || !in_array($service, ['web', 'mail'])) {
            echo json_encode(['ok' => false, 'error' => 'Parametros invalidos']);
            exit;
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado']);
            exit;
        }

        $services = json_decode($node['services'] ?? '["web"]', true) ?: ['web'];
        $isRemoving = in_array($service, $services);
        $confirmed = !empty($_POST['confirmed']);

        if ($isRemoving) {
            // Check for active data before removing — require confirmation
            if (!$confirmed) {
                $count = 0;
                $label = '';
                if ($service === 'web') {
                    $row = Database::fetchOne("SELECT COUNT(*) AS cnt FROM hosting_accounts WHERE status != 'deleted'");
                    $count = (int)($row['cnt'] ?? 0);
                    $label = $count . ' hosting(s) activo(s)';
                } elseif ($service === 'mail') {
                    $row = Database::fetchOne("SELECT COUNT(*) AS cnt FROM mail_domains WHERE mail_node_id = :nid", ['nid' => $nodeId]);
                    $count = (int)($row['cnt'] ?? 0);
                    $label = $count . ' dominio(s) de correo';
                }
                if ($count > 0) {
                    echo json_encode([
                        'ok' => false,
                        'confirm_required' => true,
                        'count' => $count,
                        'label' => $label,
                        'service' => $service,
                        'message' => "Este nodo tiene {$label}.",
                    ]);
                    exit;
                }
            }

            $services = array_values(array_diff($services, [$service]));
            if (empty($services)) {
                echo json_encode(['ok' => false, 'error' => 'El nodo debe tener al menos un servicio']);
                exit;
            }
        } else {
            // Activating a service — verify prerequisites
            if ($service === 'mail') {
                // Check if the mail stack is installed on this node
                $token = \MuseDockPanel\Services\ReplicationService::decryptPassword($node['auth_token'] ?? '');
                $configured = false;
                try {
                    $check = ClusterService::callNodeDirect($node['api_url'], $token, 'POST', 'api/cluster/action', [
                        'action' => 'mail_check_configured',
                        'payload' => [],
                    ], 10);
                    $configured = !empty($check['data']['configured']);
                } catch (\Exception $e) {
                    // Node unreachable — can't verify
                }

                if (!$configured) {
                    echo json_encode([
                        'ok' => false,
                        'setup_required' => true,
                        'error' => 'El stack de mail no esta instalado en este nodo. Configuralo primero desde Mail → Configurar Nodo de Mail.',
                    ]);
                    exit;
                }
            }

            $services[] = $service;
        }

        ClusterService::updateNode($nodeId, ['services' => json_encode(array_values($services))]);
        LogService::log('cluster.node', 'services-changed', "Servicios del nodo {$node['name']} actualizados: " . json_encode($services));

        echo json_encode(['ok' => true, 'services' => array_values($services)]);
        exit;
    }

    /**
     * POST /settings/cluster/remove-node/{id}
     */
    public function removeNode(): void
    {
        View::verifyCsrf();

        $id = (int)($_REQUEST['id'] ?? $_POST['node_id'] ?? 0);
        if ($id < 1) {
            Flash::set('error', 'ID de nodo no valido');
            header('Location: /settings/cluster');
            exit;
        }

        $node = ClusterService::getNode($id);
        if (!$node) {
            Flash::set('error', 'Nodo no encontrado');
            header('Location: /settings/cluster');
            exit;
        }

        $name = $node['name'];
        ClusterService::removeNode($id);
        LogService::log('cluster.node', 'remove', "Nodo eliminado: {$name}, ID: {$id}");
        Flash::set('success', "Nodo '{$name}' eliminado correctamente");
        header('Location: /settings/cluster');
        exit;
    }

    /**
     * POST /settings/cluster/test-node (JSON response)
     */
    public function testNode(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $apiUrl = trim($_POST['api_url'] ?? '');
        $token  = trim($_POST['auth_token'] ?? '');

        if (!$apiUrl || !$token) {
            echo json_encode(['ok' => false, 'message' => 'URL y token son obligatorios']);
            exit;
        }

        // Test basic connection
        $testResult = ClusterService::callNodeDirect($apiUrl, $token, 'POST', 'api/cluster/action', [
            'action' => 'test-connection',
        ]);

        if (!$testResult['ok']) {
            echo json_encode(['ok' => false, 'message' => 'No se pudo conectar: ' . ($testResult['error'] ?? 'Error')]);
            exit;
        }

        // Get remote status
        $statusResult = ClusterService::callNodeDirect($apiUrl, $token, 'GET', 'api/cluster/status');
        $remoteStatus = $statusResult['data']['data'] ?? $statusResult['data'] ?? [];

        echo json_encode([
            'ok'      => true,
            'message' => 'Conexion exitosa',
            'remote'  => $remoteStatus,
        ]);
        exit;
    }

    /**
     * GET /settings/cluster/node-status (JSON for AJAX polling)
     */
    public function nodeStatus(): void
    {
        header('Content-Type: application/json');

        $nodes = ClusterService::getNodes();
        $nodesData = [];

        foreach ($nodes as $node) {
            $result = ClusterService::sendHeartbeat((int)$node['id']);
            $nodesData[] = [
                'id'           => $node['id'],
                'name'         => $node['name'],
                'api_url'      => $node['api_url'],
                'status'       => $result['ok'] ? 'online' : 'offline',
                'role'         => $result['data']['role'] ?? $node['role'] ?? 'unknown',
                'last_seen_at' => $result['ok'] ? date('Y-m-d H:i:s') : ($node['last_seen_at'] ?? null),
                'error'        => $result['error'] ?? '',
            ];
        }

        $localStatus = [];
        try {
            $localStatus = ClusterService::getLocalStatus();
        } catch (\Throwable) {}

        // Master monitoring info (for slaves)
        $masterInfo = null;
        $masterIp = Settings::get('cluster_master_ip', '');
        if ($masterIp) {
            $masterLastHb = Settings::get('cluster_master_last_heartbeat', '');
            $masterInfo = [
                'ip'             => $masterIp,
                'last_heartbeat' => $masterLastHb,
                'age_seconds'    => $masterLastHb ? (time() - strtotime($masterLastHb)) : null,
            ];
        }

        echo json_encode([
            'ok'          => true,
            'nodes'       => $nodesData,
            'local'       => $localStatus,
            'queue_stats' => ClusterService::getQueueStats(),
            'master_info' => $masterInfo,
            'timestamp'   => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * GET /settings/cluster/node-status-quick?node_id=X (JSON)
     * Returns node data from DB instantly (no heartbeat/ping).
     */
    public function nodeStatusQuick(): void
    {
        header('Content-Type: application/json');

        $nodeId = (int)($_GET['node_id'] ?? 0);
        if ($nodeId < 1) {
            echo json_encode(['ok' => false, 'error' => 'Node ID requerido']);
            exit;
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado']);
            exit;
        }

        $lastSeen = $node['last_seen_at'] ?? null;
        $age = $lastSeen ? (time() - strtotime($lastSeen)) : null;

        $mutedUntil = Settings::get("cluster_node_{$nodeId}_muted_until", '');
        $isMuted = ($mutedUntil && strtotime($mutedUntil) > time());

        echo json_encode([
            'ok'             => true,
            'id'             => $node['id'],
            'name'           => $node['name'],
            'api_url'        => $node['api_url'],
            'status'         => $node['status'] ?? 'offline',
            'role'           => $node['role'] ?? 'unknown',
            'last_seen_at'   => $lastSeen,
            'age_seconds'    => $age,
            'sync_lag'       => (int)($node['sync_lag_seconds'] ?? 0),
            'alerts_muted'   => $isMuted,
        ]);
        exit;
    }

    /**
     * GET /settings/cluster/ping-node?node_id=X (JSON)
     * Performs a live heartbeat to a single node (may be slow if offline).
     */
    public function pingNode(): void
    {
        header('Content-Type: application/json');

        $nodeId = (int)($_GET['node_id'] ?? 0);
        if ($nodeId < 1) {
            echo json_encode(['ok' => false, 'error' => 'Node ID requerido']);
            exit;
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado']);
            exit;
        }

        $result = ClusterService::sendHeartbeat($nodeId);

        echo json_encode([
            'ok'           => $result['ok'],
            'status'       => $result['ok'] ? 'online' : 'offline',
            'role'         => $result['data']['role'] ?? $node['role'] ?? 'unknown',
            'last_seen_at' => $result['ok'] ? date('Y-m-d H:i:s') : ($node['last_seen_at'] ?? null),
            'error'        => $result['error'] ?? '',
            'response_ms'  => $result['data']['response_ms'] ?? null,
        ]);
        exit;
    }

    /**
     * POST /settings/cluster/process-queue
     */
    public function processQueue(): void
    {
        View::verifyCsrf();

        $results = ClusterService::processQueue();
        $ok = 0;
        $fail = 0;
        foreach ($results as $r) {
            if ($r['ok']) $ok++;
            else $fail++;
        }

        LogService::log('cluster.queue', 'process', "Procesados: {$ok} OK, {$fail} fallidos");

        if ($fail > 0) {
            Flash::set('warning', "Cola procesada: {$ok} completados, {$fail} fallidos");
        } elseif ($ok > 0) {
            Flash::set('success', "Cola procesada: {$ok} elementos completados");
        } else {
            Flash::set('info', 'No habia elementos pendientes en la cola');
        }

        header('Location: /settings/cluster');
        exit;
    }

    /**
     * POST /settings/cluster/promote
     */
    public function promoteLocal(): void
    {
        View::verifyCsrf();

        $result = ClusterService::promoteToMaster();
        if ($result['ok']) {
            Flash::set('success', 'Servidor promovido a Master correctamente');
        } else {
            Flash::set('error', 'Error al promover: ' . implode(', ', $result['errors'] ?? ['Error desconocido']));
        }

        header('Location: /settings/cluster');
        exit;
    }

    /**
     * POST /settings/cluster/demote
     */
    public function demoteLocal(): void
    {
        View::verifyCsrf();

        $newMasterIp = trim($_POST['new_master_ip'] ?? '');
        if (!filter_var($newMasterIp, FILTER_VALIDATE_IP)) {
            Flash::set('error', 'IP del nuevo master no valida');
            header('Location: /settings/cluster');
            exit;
        }

        $result = ClusterService::demoteToSlave($newMasterIp);
        if ($result['ok']) {
            Flash::set('success', 'Servidor degradado a Slave correctamente');
        } else {
            Flash::set('error', 'Error al degradar: ' . implode(', ', $result['errors'] ?? ['Error desconocido']));
        }

        header('Location: /settings/cluster');
        exit;
    }

    /**
     * POST /settings/cluster/generate-token (JSON response)
     */
    public function generateToken(): void
    {
        header('Content-Type: application/json');

        $token = ClusterService::generateToken();
        echo json_encode(['ok' => true, 'token' => $token]);
        exit;
    }

    /**
     * POST /settings/cluster/save-settings
     */
    public function saveSettings(): void
    {
        View::verifyCsrf();

        // Local token
        $localToken = trim($_POST['cluster_local_token'] ?? '');
        if ($localToken !== '') {
            Settings::set('cluster_local_token', ReplicationService::encryptPassword($localToken));
        }

        // Cluster role
        $clusterRole = $_POST['cluster_role'] ?? 'standalone';
        if (in_array($clusterRole, ['standalone', 'master', 'slave'], true)) {
            Settings::set('cluster_role', $clusterRole);
            // Also update PANEL_ROLE in .env so API reports correct role
            ClusterService::updateEnvRole($clusterRole);
        }

        // Intervals
        Settings::set('cluster_heartbeat_interval', (string)(int)($_POST['cluster_heartbeat_interval'] ?? 30));
        Settings::set('cluster_unreachable_timeout', (string)(int)($_POST['cluster_unreachable_timeout'] ?? 300));

        // Slave notification preferences
        Settings::set('cluster_slave_notify_email', isset($_POST['cluster_slave_notify_email']) ? '1' : '0');
        Settings::set('cluster_slave_notify_telegram', isset($_POST['cluster_slave_notify_telegram']) ? '1' : '0');
        Settings::set('cluster_auto_failover', isset($_POST['cluster_auto_failover']) ? '1' : '0');

        LogService::log('cluster.settings', 'save', 'Configuracion del cluster guardada');
        Flash::set('success', 'Configuracion del cluster guardada');
        header('Location: /settings/cluster');
        exit;
    }

    /**
     * POST /settings/cluster/save-setting (AJAX, single key-value)
     */
    public function saveSetting(): void
    {
        header('Content-Type: application/json');
        View::verifyCsrf();

        $key = trim($_POST['key'] ?? '');
        $value = trim($_POST['value'] ?? '');

        $allowed = ['cluster_auto_failover'];
        if (!in_array($key, $allowed, true)) {
            echo json_encode(['ok' => false, 'error' => 'Setting not allowed']);
            return;
        }

        Settings::set($key, $value);
        echo json_encode(['ok' => true]);
    }

    /**
     * POST /settings/cluster/verify-admin-password (JSON)
     */
    public function verifyAdminPassword(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $password = $_POST['password'] ?? '';
        $adminId = $_SESSION['panel_user']['id'] ?? 0;

        if (!$adminId || !$password) {
            echo json_encode(['ok' => false]);
            exit;
        }

        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        if ($admin && password_verify($password, $admin['password_hash'])) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    /**
     * POST /settings/cluster/clean-queue
     */
    public function cleanQueue(): void
    {
        View::verifyCsrf();

        $type = $_POST['type'] ?? 'completed';

        if ($type === 'failed') {
            $deleted = ClusterService::cleanFailedItems();
            LogService::log('cluster.queue', 'clean', "Eliminados {$deleted} elementos fallidos");
            Flash::set('success', "Se eliminaron {$deleted} elementos fallidos de la cola");
        } else {
            $deleted = ClusterService::cleanOldItems(0);
            LogService::log('cluster.queue', 'clean', "Eliminados {$deleted} elementos completados");
            Flash::set('success', "Se eliminaron {$deleted} elementos completados de la cola");
        }
        header('Location: /settings/cluster#cola');
        exit;
    }

    /**
     * POST /settings/cluster/sync-all-hostings
     * Enqueue all existing hostings to a specific node
     */
    public function syncAllHostings(): void
    {
        View::verifyCsrf();

        $nodeId = (int)($_POST['node_id'] ?? 0);
        if ($nodeId < 1) {
            Flash::set('error', 'Nodo no valido');
            header('Location: /settings/cluster');
            exit;
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            Flash::set('error', 'Nodo no encontrado');
            header('Location: /settings/cluster');
            exit;
        }

        // Only allow from master
        $clusterRole = Settings::get('cluster_role', 'standalone');
        if ($clusterRole === 'slave') {
            Flash::set('error', 'Este servidor es Slave. Solo el Master puede sincronizar hostings.');
            header('Location: /settings/cluster');
            exit;
        }

        $accounts = Database::fetchAll("SELECT * FROM hosting_accounts WHERE status != 'deleted' ORDER BY id");
        $count = 0;

        foreach ($accounts as $acc) {
            // Get password hash from system for exact replication
            $passwordHash = SystemService::getPasswordHash($acc['username']);

            ClusterService::enqueue($nodeId, 'sync-hosting', [
                'hosting_action' => 'create_hosting',
                'hosting_data' => [
                    'username' => $acc['username'],
                    'domain' => $acc['domain'],
                    'home_dir' => $acc['home_dir'],
                    'document_root' => $acc['document_root'],
                    'php_version' => $acc['php_version'] ?? '8.3',
                    'password' => '',
                    'password_hash' => $passwordHash,
                    'shell' => $acc['shell'] ?? '/usr/sbin/nologin',
                    'system_uid' => $acc['system_uid'] ?? null,
                    'caddy_route_id' => $acc['caddy_route_id'] ?? null,
                    'customer_id' => $acc['customer_id'] ?? null,
                    'disk_quota_mb' => $acc['disk_quota_mb'] ?? 1024,
                    'description' => $acc['description'] ?? '',
                ],
            ], 5);
            $count++;
        }

        LogService::log('cluster.sync', 'sync-all', "Sincronizados {$count} hostings al nodo {$node['name']}");
        Flash::set('success', "{$count} hostings encolados para sincronizar al nodo '{$node['name']}'");
        header('Location: /settings/cluster');
        exit;
    }

    // ═══════════════════════════════════════════════════════════════
    // File Sync
    // ═══════════════════════════════════════════════════════════════

    /**
     * POST /settings/cluster/filesync-settings
     */
    public function saveFileSyncSettings(): void
    {
        View::verifyCsrf();

        FileSyncService::saveConfig([
            'filesync_enabled'         => isset($_POST['filesync_enabled']) ? '1' : '0',
            'filesync_sync_mode'       => in_array($_POST['filesync_sync_mode'] ?? '', ['periodic', 'lsyncd']) ? $_POST['filesync_sync_mode'] : 'periodic',
            'filesync_method'          => in_array($_POST['filesync_method'] ?? '', ['ssh', 'https']) ? $_POST['filesync_method'] : 'ssh',
            'filesync_ssh_port'        => (string)max(1, (int)($_POST['filesync_ssh_port'] ?? 22)),
            'filesync_ssh_key_path'    => trim($_POST['filesync_ssh_key_path'] ?? '/root/.ssh/id_ed25519'),
            'filesync_ssh_user'        => trim($_POST['filesync_ssh_user'] ?? 'root'),
            'filesync_interval'        => (string)max(1, (int)($_POST['filesync_interval'] ?? 15)),
            'filesync_bwlimit'         => (string)max(0, (int)($_POST['filesync_bwlimit'] ?? 0)),
            'filesync_exclude'         => trim($_POST['filesync_exclude'] ?? '.cache,*.log,*.tmp,node_modules'),
            'filesync_ssl_certs'       => isset($_POST['filesync_ssl_certs']) ? '1' : '0',
            'filesync_ssl_cert_path'   => trim($_POST['filesync_ssl_cert_path'] ?? ''),
            'filesync_rewrite_dbhost'  => isset($_POST['filesync_rewrite_dbhost']) ? '1' : '0',
            'filesync_db_dumps'        => isset($_POST['filesync_db_dumps']) ? '1' : '0',
            'filesync_db_dump_mysql'   => isset($_POST['filesync_db_dump_mysql']) ? '1' : '0',
            'filesync_db_dump_pgsql'   => isset($_POST['filesync_db_dump_pgsql']) ? '1' : '0',
            'filesync_db_dump_path'    => trim($_POST['filesync_db_dump_path'] ?? '/tmp/musedock-dumps'),
        ]);

        LogService::log('cluster.filesync', 'save', 'Configuracion de sincronizacion de archivos guardada');
        Flash::set('success', 'Configuracion de sincronizacion de archivos guardada');
        header('Location: /settings/cluster');
        exit;
    }

    /**
     * POST /settings/cluster/generate-ssh-key (JSON)
     */
    public function generateSshKey(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $keyPath = trim($_POST['key_path'] ?? Settings::get('filesync_ssh_key_path', '/root/.ssh/id_ed25519'));
        $result = FileSyncService::generateSshKey($keyPath);
        echo json_encode($result);
        exit;
    }

    /**
     * POST /settings/cluster/install-ssh-key (JSON)
     */
    public function installSshKey(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $nodeId = (int)($_POST['node_id'] ?? 0);
        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado']);
            exit;
        }

        $pubKey = FileSyncService::getPublicKey();
        if (!$pubKey) {
            echo json_encode(['ok' => false, 'error' => 'No hay clave publica generada. Genera una primero.']);
            exit;
        }

        // Send to remote node via API
        $token = ReplicationService::decryptPassword($node['auth_token'] ?? '');
        $result = ClusterService::callNodeDirect($node['api_url'], $token, 'POST', 'api/cluster/action', [
            'action' => 'install-ssh-key',
            'payload' => ['public_key' => $pubKey],
        ]);

        echo json_encode($result['data'] ?? $result);
        exit;
    }

    /**
     * POST /settings/cluster/test-ssh (JSON)
     */
    public function testSshConnection(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $nodeId = (int)($_POST['node_id'] ?? 0);
        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado']);
            exit;
        }

        $host = FileSyncService::extractHostFromUrl($node['api_url']);
        $config = FileSyncService::getConfig();
        $result = FileSyncService::testSshConnection($host, $config['ssh_port'], $config['ssh_key_path']);
        echo json_encode($result);
        exit;
    }

    /**
     * POST /settings/cluster/sync-files-now (JSON)
     * Launches sync in background and returns a sync_id for polling.
     */
    public function syncFilesNow(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $nodeId = (int)($_POST['node_id'] ?? 0);

        if (Settings::get('cluster_role', 'standalone') !== 'master') {
            echo json_encode(['ok' => false, 'error' => 'Solo el master puede sincronizar archivos']);
            exit;
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado']);
            exit;
        }

        // Generate unique sync ID and launch background process
        $syncId = 'sync-' . $nodeId . '-' . time();
        $progressFile = FileSyncService::progressFilePath($syncId);

        // Write initial progress
        file_put_contents($progressFile, json_encode([
            'status' => 'starting', 'total' => 0, 'current' => 0,
            'ok' => 0, 'fail' => 0, 'current_domain' => 'Iniciando...',
            'elapsed' => 0,
        ]));

        // Launch background PHP process
        $cmd = sprintf(
            'nohup /usr/bin/php /opt/musedock-panel/bin/filesync-run.php %d %s > /dev/null 2>&1 &',
            $nodeId,
            escapeshellarg($syncId)
        );
        shell_exec($cmd);

        LogService::log('cluster.filesync', 'manual', "Sync archivos iniciado al nodo {$node['name']} (ID: {$syncId})");

        echo json_encode(['ok' => true, 'sync_id' => $syncId, 'node_name' => $node['name']]);
        exit;
    }

    /**
     * GET /settings/cluster/sync-progress?sync_id=xxx (JSON)
     * Returns current sync progress for polling.
     */
    public function syncProgress(): void
    {
        header('Content-Type: application/json');
        $syncId = $_GET['sync_id'] ?? '';
        if (!$syncId) {
            echo json_encode(['error' => 'Missing sync_id']);
            exit;
        }

        $progress = FileSyncService::readProgress($syncId);
        if (!$progress) {
            echo json_encode(['status' => 'unknown', 'error' => 'Sync not found']);
            exit;
        }

        echo json_encode($progress);
        exit;
    }

    /**
     * POST /settings/cluster/check-dbhost (JSON)
     * Check DB_HOST in all hostings' config files.
     */
    public function checkDbHost(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $accounts = Database::fetchAll("SELECT * FROM hosting_accounts WHERE status = 'active' ORDER BY domain");
        $allWarnings = [];

        foreach ($accounts as $acc) {
            $path = ($acc['home_dir'] ?? '') . '/httpdocs';
            $check = FileSyncService::checkDbHostConfig($path);
            if (!empty($check['warnings'])) {
                foreach ($check['warnings'] as $w) {
                    $w['domain'] = $acc['domain'];
                    $allWarnings[] = $w;
                }
            }
        }

        echo json_encode([
            'ok' => true,
            'total_accounts' => count($accounts),
            'warnings' => $allWarnings,
            'warning_count' => count($allWarnings),
        ]);
        exit;
    }

    // ═══════════════════════════════════════════════════════════════
    // Node Alert Muting
    // ═══════════════════════════════════════════════════════════════

    /**
     * POST /settings/cluster/mute-node-alerts (JSON)
     * Mute alerts for a specific offline node.
     */
    public function muteNodeAlerts(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $nodeId = (int)($_POST['node_id'] ?? 0);
        if ($nodeId < 1) {
            echo json_encode(['ok' => false, 'error' => 'Node ID requerido']);
            exit;
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado']);
            exit;
        }

        // Mute indefinitely (set far future date) — unmute on recovery or manual action
        $mutedUntil = date('Y-m-d H:i:s', strtotime('+10 years'));
        Settings::set("cluster_node_{$nodeId}_muted_until", $mutedUntil);

        LogService::log('cluster.mute', 'mute', "Alertas silenciadas para nodo {$node['name']}");

        echo json_encode(['ok' => true, 'message' => "Alertas silenciadas para {$node['name']}"]);
        exit;
    }

    /**
     * POST /settings/cluster/unmute-node-alerts (JSON)
     * Unmute alerts for a specific node.
     */
    public function unmuteNodeAlerts(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $nodeId = (int)($_POST['node_id'] ?? 0);
        if ($nodeId < 1) {
            echo json_encode(['ok' => false, 'error' => 'Node ID requerido']);
            exit;
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado']);
            exit;
        }

        Settings::set("cluster_node_{$nodeId}_muted_until", '');

        LogService::log('cluster.mute', 'unmute', "Alertas reactivadas para nodo {$node['name']}");

        echo json_encode(['ok' => true, 'message' => "Alertas reactivadas para {$node['name']}"]);
        exit;
    }

    // ═══════════════════════════════════════════════════════════════
    // Full Sync Orchestrator
    // ═══════════════════════════════════════════════════════════════

    /**
     * POST /settings/cluster/full-sync (JSON)
     * Launches a background orchestrator that runs:
     *   1) Sync hostings (API) — always
     *   2) Sync files (rsync/SSH) — if SSH configured
     *   3) Sync SSL certs — if SSH configured and enabled
     * Returns sync_id for polling via syncProgress().
     */
    public function fullSync(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $nodeId = (int)($_POST['node_id'] ?? 0);

        if (Settings::get('cluster_role', 'standalone') !== 'master') {
            echo json_encode(['ok' => false, 'error' => 'Solo el master puede ejecutar sincronizacion completa']);
            exit;
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado']);
            exit;
        }

        // Generate unique sync ID and launch background process
        $syncId = 'fullsync-' . $nodeId . '-' . time();
        $progressFile = FileSyncService::progressFilePath($syncId);

        // Write initial progress
        file_put_contents($progressFile, json_encode([
            'status' => 'starting',
            'phase' => 'init',
            'phase_label' => 'Preparando sincronizacion completa...',
            'total' => 0, 'current' => 0, 'ok' => 0, 'fail' => 0,
            'current_domain' => '',
            'elapsed' => 0,
            'steps' => [],
        ]));

        // Launch background PHP process
        $cmd = sprintf(
            'nohup /usr/bin/php /opt/musedock-panel/bin/fullsync-run.php %d %s > /dev/null 2>&1 &',
            $nodeId,
            escapeshellarg($syncId)
        );
        shell_exec($cmd);

        LogService::log('cluster.fullsync', 'manual', "Sync completa iniciada al nodo {$node['name']} (ID: {$syncId})");

        echo json_encode(['ok' => true, 'sync_id' => $syncId, 'node_name' => $node['name']]);
        exit;
    }

    // ─── lsyncd Management ─────────────────────────────────────

    /** GET /settings/cluster/lsyncd-status (JSON) */
    public function lsyncdStatus(): void
    {
        header('Content-Type: application/json');
        echo json_encode(FileSyncService::getLsyncdStatus());
        exit;
    }

    /** POST /settings/cluster/lsyncd-install (JSON) */
    public function lsyncdInstall(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');
        $result = FileSyncService::installLsyncd();
        LogService::log('cluster.lsyncd', 'install', $result['ok'] ? 'lsyncd instalado' : 'Error instalando lsyncd');
        echo json_encode($result);
        exit;
    }

    /** POST /settings/cluster/lsyncd-start (JSON) */
    public function lsyncdStart(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');
        $result = FileSyncService::startLsyncd();
        LogService::log('cluster.lsyncd', 'start', $result['ok'] ? 'lsyncd iniciado' : 'Error iniciando lsyncd');
        echo json_encode($result);
        exit;
    }

    /** POST /settings/cluster/lsyncd-stop (JSON) */
    public function lsyncdStop(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');
        $result = FileSyncService::stopLsyncd();
        LogService::log('cluster.lsyncd', 'stop', $result['ok'] ? 'lsyncd detenido' : 'Error deteniendo lsyncd');
        echo json_encode($result);
        exit;
    }

    /** POST /settings/cluster/lsyncd-reload (JSON) */
    public function lsyncdReload(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');
        $result = FileSyncService::reloadLsyncd();
        LogService::log('cluster.lsyncd', 'reload', $result['ok'] ? 'lsyncd recargado' : 'Error recargando lsyncd');
        echo json_encode($result);
        exit;
    }

    // ─── Node Standby (Maintenance Mode) ───────────────────

    /** POST /settings/cluster/node-standby (JSON) — requires admin password */
    public function toggleNodeStandby(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $nodeId = (int)($_POST['node_id'] ?? 0);
        $action = $_POST['action'] ?? ''; // 'activate' or 'deactivate'
        $password = $_POST['admin_password'] ?? '';
        $reason = trim($_POST['reason'] ?? '');

        if (!$nodeId || !in_array($action, ['activate', 'deactivate'])) {
            echo json_encode(['ok' => false, 'error' => 'Parámetros inválidos']);
            exit;
        }

        // Verify admin password
        $adminId = $_SESSION['admin_id'] ?? $_SESSION['panel_user']['id'] ?? 0;
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            echo json_encode(['ok' => false, 'error' => 'Contraseña incorrecta']);
            exit;
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado']);
            exit;
        }

        if ($action === 'activate') {
            Database::update('cluster_nodes', [
                'standby'        => true,
                'standby_since'  => date('Y-m-d H:i:s'),
                'standby_reason' => $reason ?: 'Mantenimiento',
            ], 'id = :id', ['id' => $nodeId]);

            // Also mute alerts implicitly
            $meta = json_decode($node['metadata'] ?? '{}', true) ?: [];
            $meta['alerts_muted'] = true;
            $meta['alerts_muted_at'] = date('Y-m-d H:i:s');
            Database::update('cluster_nodes', [
                'metadata' => json_encode($meta),
            ], 'id = :id', ['id' => $nodeId]);

            // Regenerate lsyncd config excluding standby nodes — stop if none active
            $activeNodes = array_filter(ClusterService::getNodes(), fn($n) => empty($n['standby']) && $n['id'] !== $nodeId);
            if (empty($activeNodes)) {
                FileSyncService::stopLsyncd();
            } else {
                FileSyncService::reloadLsyncd();
            }

            // Notify the slave node to enter standby mode (short timeout — node may be unreachable)
            try {
                ClusterService::callNodeDirect(
                    rtrim($node['api_url'], '/'),
                    \MuseDockPanel\Services\ReplicationService::decryptPassword($node['auth_token']),
                    'POST', 'api/cluster/action',
                    ['action' => 'set-standby', 'payload' => ['enabled' => true, 'reason' => $reason ?: 'Mantenimiento']],
                    5 // 5s timeout
                );
            } catch (\Throwable) {} // non-critical — node may already be unreachable

            LogService::log('cluster.standby', $node['name'], "Nodo en standby: {$reason}");
            echo json_encode(['ok' => true, 'message' => "Nodo {$node['name']} en standby. Sync, cola y alertas pausadas."]);
        } else {
            Database::update('cluster_nodes', [
                'standby'        => 0,
                'standby_since'  => null,
                'standby_reason' => null,
            ], 'id = :id', ['id' => $nodeId]);

            // Unmute alerts
            $meta = json_decode($node['metadata'] ?? '{}', true) ?: [];
            unset($meta['alerts_muted'], $meta['alerts_muted_at']);
            Database::update('cluster_nodes', [
                'metadata' => json_encode($meta),
            ], 'id = :id', ['id' => $nodeId]);

            // Notify the slave node to exit standby mode (short timeout to avoid blocking)
            try {
                ClusterService::callNodeDirect(
                    rtrim($node['api_url'], '/'),
                    \MuseDockPanel\Services\ReplicationService::decryptPassword($node['auth_token']),
                    'POST', 'api/cluster/action',
                    ['action' => 'set-standby', 'payload' => ['enabled' => false]],
                    5 // 5s timeout — don't block if slave is slow
                );
            } catch (\Throwable) {} // non-critical

            // Push failover config to the reactivated node so it has the latest settings
            try {
                ClusterService::callNodeDirect(
                    rtrim($node['api_url'], '/'),
                    \MuseDockPanel\Services\ReplicationService::decryptPassword($node['auth_token']),
                    'POST', 'api/cluster/action',
                    ['action' => 'sync-failover-config', 'payload' => [
                        'config'         => call_user_func(function() {
                            $keys = \MuseDockPanel\Services\FailoverService::getSyncableConfigKeys();
                            $c = [];
                            foreach ($keys as $k) { $v = \MuseDockPanel\Settings::get($k, ''); if ($v !== '') $c[$k] = $v; }
                            return $c;
                        }),
                        'servers'        => \MuseDockPanel\Services\FailoverService::getServers(),
                        'cf_accounts'    => \MuseDockPanel\Services\CloudflareService::getConfiguredAccounts(),
                        'remote_domains' => \MuseDockPanel\Settings::get('failover_remote_domains', ''),
                    ]],
                    10
                );
            } catch (\Throwable) {} // best-effort, worker will pull within 1h anyway

            // Regenerate lsyncd config including reactivated node and restart
            FileSyncService::reloadLsyncd();

            LogService::log('cluster.standby', $node['name'], 'Nodo reactivado');
            echo json_encode(['ok' => true, 'message' => "Nodo {$node['name']} reactivado. Config failover sincronizada, sync y alertas reanudadas."]);
        }
        exit;
    }

    // ─── Exclusion Browser ─────────────────────────────────────

    /** GET /settings/cluster/browse-vhosts?path=... (JSON) */
    public function browseVhosts(): void
    {
        header('Content-Type: application/json');

        $basePath = '/var/www/vhosts';
        $requestedPath = $_GET['path'] ?? '';

        // Security: resolve real path and ensure it's within /var/www/vhosts
        $targetPath = $requestedPath ? realpath($basePath . '/' . ltrim($requestedPath, '/')) : $basePath;
        if (!$targetPath || !str_starts_with($targetPath, $basePath) || !is_dir($targetPath)) {
            echo json_encode(['ok' => false, 'error' => 'Ruta no válida']);
            exit;
        }

        // Load current exclusions
        $exclusions = array_filter(array_map('trim', explode("\n", Settings::get('filesync_exclusions_list', ''))));

        $items = [];
        $entries = @scandir($targetPath);
        if ($entries === false) {
            echo json_encode(['ok' => false, 'error' => 'No se puede leer el directorio']);
            exit;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $fullPath = $targetPath . '/' . $entry;
            $relativePath = str_replace($basePath . '/', '', $fullPath);
            $isDir = is_dir($fullPath);

            $size = 0;
            if (!$isDir) {
                $size = @filesize($fullPath) ?: 0;
            }

            $items[] = [
                'name'     => $entry,
                'path'     => $relativePath,
                'is_dir'   => $isDir,
                'size'     => $size,
                'excluded' => in_array($relativePath, $exclusions),
            ];
        }

        // Sort: directories first, then alphabetical
        usort($items, function ($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] <=> $a['is_dir'];
            return strcasecmp($a['name'], $b['name']);
        });

        $currentRelative = str_replace($basePath . '/', '', $targetPath);
        $currentRelative = $currentRelative === $basePath ? '' : $currentRelative;

        echo json_encode([
            'ok'         => true,
            'path'       => $currentRelative,
            'items'      => $items,
            'exclusions' => $exclusions,
        ]);
        exit;
    }

    // ═══════════════════════════════════════════════════════════════
    // Mail Node Provisioning
    // ═══════════════════════════════════════════════════════════════

    /**
     * POST /settings/cluster/setup-mail-node (JSON)
     *
     * Remotely provisions a node as a mail server via the cluster API.
     * Flow:
     *   1. Generate a one-time setup_token on the remote node
     *   2. Send mail_setup_node with that token → node returns 202 + task_id
     *   3. Return task_id to frontend for polling via mail-setup-progress
     *
     * The actual installation runs asynchronously on the node.
     */
    public function setupMailNode(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $nodeId       = (int)($_POST['node_id'] ?? 0);
        $mailHostname = trim($_POST['mail_hostname'] ?? '');
        $sslMode      = in_array($_POST['ssl_mode'] ?? '', ['letsencrypt', 'selfsigned', 'manual'])
                         ? $_POST['ssl_mode'] : 'letsencrypt';
        $dbHost       = trim($_POST['db_host'] ?? 'localhost');

        // Require admin password for this destructive operation
        $adminPassword = $_POST['admin_password'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? $_SESSION['panel_user']['id'] ?? 0;
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        if (!$admin || !password_verify($adminPassword, $admin['password_hash'])) {
            echo json_encode(['ok' => false, 'error' => 'Contrasena de administrador incorrecta']);
            exit;
        }

        if (!$nodeId || !$mailHostname) {
            echo json_encode(['ok' => false, 'error' => 'Faltan campos obligatorios: node_id, mail_hostname']);
            exit;
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado']);
            exit;
        }

        // Validate hostname uniqueness (across nodes + local)
        $existingHostname = Database::fetchOne(
            "SELECT id, name FROM cluster_nodes WHERE mail_hostname = :h AND id != :nid",
            ['h' => $mailHostname, 'nid' => $nodeId]
        );
        if ($existingHostname) {
            echo json_encode(['ok' => false, 'error' => "El hostname '{$mailHostname}' ya esta asignado al nodo '{$existingHostname['name']}'."]);
            exit;
        }
        $localHostname = Settings::get('mail_local_hostname', '');
        if ($localHostname && $localHostname === $mailHostname) {
            echo json_encode(['ok' => false, 'error' => "El hostname '{$mailHostname}' ya esta asignado al servidor local."]);
            exit;
        }

        $token = \MuseDockPanel\Services\ReplicationService::decryptPassword($node['auth_token'] ?? '');
        $dbPort = \MuseDockPanel\Env::int('DB_PORT', 5433);

        // Step 0: Create/reuse musedock_mail PostgreSQL user on master
        // Replicas are read-only — CREATE USER must run on master, then replicates to all nodes.
        // Password is auto-generated once and stored encrypted in Settings for reuse across nodes.
        $encryptedDbPass = Settings::get('mail_db_password_enc', '');
        if ($encryptedDbPass) {
            $dbPass = \MuseDockPanel\Services\ReplicationService::decryptPassword($encryptedDbPass);
        } else {
            $dbPass = bin2hex(random_bytes(20));
            Settings::set('mail_db_password_enc', \MuseDockPanel\Services\ReplicationService::encryptPassword($dbPass));
        }

        try {
            $existing = Database::fetchOne("SELECT 1 FROM pg_roles WHERE rolname = 'musedock_mail'");
            $escapedPass = str_replace("'", "''", $dbPass);
            if (!$existing) {
                Database::execute("CREATE USER musedock_mail WITH PASSWORD '{$escapedPass}'");
                Database::execute("GRANT CONNECT ON DATABASE musedock_panel TO musedock_mail");
                Database::execute("GRANT USAGE ON SCHEMA public TO musedock_mail");
                Database::execute("GRANT SELECT ON mail_domains, mail_accounts, mail_aliases TO musedock_mail");
            } else {
                // User exists — ensure password and grants are current
                Database::execute("ALTER USER musedock_mail WITH PASSWORD '{$escapedPass}'");
                Database::execute("GRANT SELECT ON mail_domains, mail_accounts, mail_aliases TO musedock_mail");
            }
        } catch (\Exception $e) {
            echo json_encode(['ok' => false, 'error' => 'Error creando usuario musedock_mail en el master: ' . $e->getMessage()]);
            exit;
        }

        // Step 1: Generate one-time setup token on the remote node
        $tokenResult = ClusterService::callNodeDirect($node['api_url'], $token, 'POST', 'api/cluster/action', [
            'action' => 'mail_generate_setup_token',
            'payload' => [],
        ]);

        $setupToken = $tokenResult['data']['setup_token'] ?? '';
        if (!$setupToken) {
            echo json_encode(['ok' => false, 'error' => 'No se pudo generar token de setup en el nodo: ' . ($tokenResult['error'] ?? 'Error desconocido')]);
            exit;
        }

        // Step 2: Send mail_setup_node — returns immediately with task_id
        $result = ClusterService::callNodeDirect($node['api_url'], $token, 'POST', 'api/cluster/action', [
            'action'  => 'mail_setup_node',
            'payload' => [
                'db_host'       => $dbHost,
                'db_port'       => (string)$dbPort,
                'db_name'       => 'musedock_panel',
                'db_user'       => 'musedock_mail',
                'db_pass'       => $dbPass,
                'mail_hostname' => $mailHostname,
                'ssl_mode'      => $sslMode,
                'setup_token'   => $setupToken,
            ],
        ], 30); // Short timeout — node should respond immediately

        $taskId = $result['data']['task_id'] ?? '';
        if (!$taskId) {
            echo json_encode(['ok' => false, 'error' => 'El nodo no devolvio task_id: ' . ($result['error'] ?? json_encode($result['data'] ?? []))]);
            exit;
        }

        // Store task info for polling (including start time for master-side timeout)
        Settings::set('mail_setup_node_id', (string)$nodeId);
        Settings::set('mail_setup_task_id', $taskId);
        Settings::set('mail_setup_hostname', $mailHostname);
        Settings::set('mail_setup_started_at', date('Y-m-d H:i:s'));

        // Update node services to include "mail" and save hostname
        $services = json_decode($node['services'] ?? '["web"]', true) ?: ['web'];
        if (!in_array('mail', $services)) {
            $services[] = 'mail';
        }
        ClusterService::updateNode($nodeId, [
            'services' => json_encode($services),
            'mail_hostname' => $mailHostname,
        ]);
        Settings::set('mail_enabled', '1');

        LogService::log('cluster.mail', 'setup-started', "Instalacion de mail iniciada en nodo {$node['name']} ({$mailHostname}), task: {$taskId}");

        echo json_encode([
            'ok'        => true,
            'async'     => true,
            'task_id'   => $taskId,
            'node_id'   => $nodeId,
            'node_name' => $node['name'],
            'message'   => 'Instalacion iniciada en background. Puede tardar varios minutos.',
        ]);
        exit;
    }

    /**
     * POST /settings/cluster/rotate-mail-db-password (JSON)
     *
     * Rotates the musedock_mail PostgreSQL password on master and propagates to all mail nodes.
     * Use if a node is compromised or as periodic security hygiene.
     */
    public function rotateMailDbPassword(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $adminPassword = $_POST['admin_password'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? $_SESSION['panel_user']['id'] ?? 0;
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        if (!$admin || !password_verify($adminPassword, $admin['password_hash'])) {
            echo json_encode(['ok' => false, 'error' => 'Contrasena de administrador incorrecta']);
            exit;
        }

        // Generate new password
        $newPass = bin2hex(random_bytes(20));
        $dbPort = \MuseDockPanel\Env::int('DB_PORT', 5433);

        // Update on master PostgreSQL
        try {
            $escapedPass = str_replace("'", "''", $newPass);
            Database::execute("ALTER USER musedock_mail WITH PASSWORD '{$escapedPass}'");
        } catch (\Exception $e) {
            echo json_encode(['ok' => false, 'error' => 'Error actualizando password en master PG: ' . $e->getMessage()]);
            exit;
        }

        // Store encrypted in Settings
        Settings::set('mail_db_password_enc', \MuseDockPanel\Services\ReplicationService::encryptPassword($newPass));

        // Propagate to all active mail nodes
        $nodeResults = MailService::rotateDbPassword($newPass);

        LogService::log('cluster.mail', 'db-password-rotated', 'Password de musedock_mail rotada. Nodos actualizados: ' . count($nodeResults));

        echo json_encode([
            'ok'      => true,
            'nodes'   => $nodeResults,
            'message' => 'Password rotada en master y propagada a los nodos.',
        ]);
        exit;
    }

    /**
     * POST /settings/cluster/setup-mail-local (JSON)
     *
     * Install Postfix/Dovecot/OpenDKIM/Rspamd on the master server itself.
     * Runs bin/mail-setup-run.php locally via nohup — no cluster node required.
     * The DB is local (localhost:5433), no replica needed.
     */
    public function setupMailLocal(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $mailHostname = trim($_POST['mail_hostname'] ?? '');
        $sslMode      = in_array($_POST['ssl_mode'] ?? '', ['letsencrypt', 'selfsigned', 'manual'])
                         ? $_POST['ssl_mode'] : 'letsencrypt';

        // Require admin password
        $adminPassword = $_POST['admin_password'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? $_SESSION['panel_user']['id'] ?? 0;
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        if (!$admin || !password_verify($adminPassword, $admin['password_hash'])) {
            echo json_encode(['ok' => false, 'error' => 'Contrasena de administrador incorrecta']);
            exit;
        }

        if (!$mailHostname) {
            echo json_encode(['ok' => false, 'error' => 'Falta el hostname de mail']);
            exit;
        }

        // Validate hostname uniqueness (across nodes + local)
        $existingHostname = Database::fetchOne(
            "SELECT id, name FROM cluster_nodes WHERE mail_hostname = :h",
            ['h' => $mailHostname]
        );
        if ($existingHostname) {
            echo json_encode(['ok' => false, 'error' => "El hostname '{$mailHostname}' ya esta asignado al nodo '{$existingHostname['name']}'."]);
            exit;
        }

        $dbPort = \MuseDockPanel\Env::int('DB_PORT', 5433);

        // Create/reuse musedock_mail PostgreSQL user on this (master) server
        $encryptedDbPass = Settings::get('mail_db_password_enc', '');
        if ($encryptedDbPass) {
            $dbPass = \MuseDockPanel\Services\ReplicationService::decryptPassword($encryptedDbPass);
        } else {
            $dbPass = bin2hex(random_bytes(20));
            Settings::set('mail_db_password_enc', \MuseDockPanel\Services\ReplicationService::encryptPassword($dbPass));
        }

        try {
            $existing = Database::fetchOne("SELECT 1 FROM pg_roles WHERE rolname = 'musedock_mail'");
            $escapedPass = str_replace("'", "''", $dbPass);
            if (!$existing) {
                Database::execute("CREATE USER musedock_mail WITH PASSWORD '{$escapedPass}'");
                Database::execute("GRANT CONNECT ON DATABASE musedock_panel TO musedock_mail");
                Database::execute("GRANT USAGE ON SCHEMA public TO musedock_mail");
                Database::execute("GRANT SELECT ON mail_domains, mail_accounts, mail_aliases TO musedock_mail");
            } else {
                Database::execute("ALTER USER musedock_mail WITH PASSWORD '{$escapedPass}'");
                Database::execute("GRANT SELECT ON mail_domains, mail_accounts, mail_aliases TO musedock_mail");
            }
        } catch (\Exception $e) {
            echo json_encode(['ok' => false, 'error' => 'Error creando usuario musedock_mail: ' . $e->getMessage()]);
            exit;
        }

        // Launch mail-setup-run.php locally (same code that runs on remote nodes)
        $payload = [
            'db_host'       => 'localhost',
            'db_port'       => (string)$dbPort,
            'db_name'       => 'musedock_panel',
            'db_user'       => 'musedock_mail',
            'db_pass'       => $dbPass,
            'mail_hostname' => $mailHostname,
            'ssl_mode'      => $sslMode,
            'setup_token'   => 'local',  // Not validated for local setup
            'local_mode'    => true,
        ];

        // Generate task ID
        $taskId = 'mail-local-' . time() . '-' . bin2hex(random_bytes(4));

        // Write initial progress file
        $safeTaskId = preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId);
        $progressFile = PANEL_ROOT . '/storage/mail-setup-' . $safeTaskId . '.json';
        file_put_contents($progressFile, json_encode([
            'status'      => 'starting',
            'step'        => 0,
            'total_steps' => 10,
            'message'     => 'Iniciando instalacion local...',
            'updated_at'  => date('Y-m-d H:i:s'),
        ]));

        // Launch background process
        $payloadB64 = base64_encode(json_encode($payload));
        $cmd = sprintf(
            'nohup /usr/bin/php %s %s %s > /dev/null 2>&1 &',
            escapeshellarg(PANEL_ROOT . '/bin/mail-setup-run.php'),
            escapeshellarg($taskId),
            escapeshellarg($payloadB64)
        );
        shell_exec($cmd);

        // Store task info for polling
        Settings::set('mail_setup_node_id', 'local');
        Settings::set('mail_setup_task_id', $taskId);
        Settings::set('mail_setup_hostname', $mailHostname);
        Settings::set('mail_setup_started_at', date('Y-m-d H:i:s'));
        Settings::set('mail_local_enabled', '1');
        Settings::set('mail_enabled', '1');
        Settings::set('mail_local_hostname', $mailHostname);

        LogService::log('cluster.mail', 'local-setup-started', "Instalacion de mail local iniciada ({$mailHostname}), task: {$taskId}");

        echo json_encode([
            'ok'        => true,
            'async'     => true,
            'task_id'   => $taskId,
            'node_id'   => 'local',
            'node_name' => 'Este servidor (local)',
            'message'   => 'Instalacion local iniciada en background. Puede tardar varios minutos.',
        ]);
        exit;
    }

    /**
     * GET /settings/cluster/mail-setup-progress-local?task_id=Y (JSON)
     *
     * Polls local setup progress by reading the progress file directly.
     */
    public function mailSetupProgressLocal(): void
    {
        header('Content-Type: application/json');

        $taskId = $_GET['task_id'] ?? '';
        if (!$taskId) {
            echo json_encode(['ok' => false, 'error' => 'task_id requerido']);
            exit;
        }

        // Master-side timeout
        $setupStarted = Settings::get('mail_setup_started_at', '');
        if ($setupStarted) {
            $elapsedMin = (time() - strtotime($setupStarted)) / 60;
            if ($elapsedMin > 15) {
                echo json_encode([
                    'ok' => true,
                    'progress' => [
                        'status'  => 'timeout',
                        'step'    => 0,
                        'total_steps' => 10,
                        'current' => 'master_timeout',
                        'label'   => 'Timeout',
                        'errors'  => [[
                            'step'    => 'master_timeout',
                            'command' => 'polling_timeout',
                            'exit'    => -3,
                            'output'  => "No se completo en 15 minutos. Revisa los logs en storage/logs/.",
                        ]],
                        'elapsed_min' => round($elapsedMin, 1),
                    ],
                ]);
                Settings::set('mail_setup_started_at', '');
                exit;
            }
        }

        // Read progress directly from local file (same as nodeSetupStatus but without cluster API)
        $result = MailService::nodeSetupStatus(['task_id' => $taskId]);

        // If completed, clean up tracking and mark local mail as configured
        $status = $result['progress']['status'] ?? '';
        if (in_array($status, ['completed', 'completed_with_errors', 'failed', 'stale', 'timeout'])) {
            Settings::set('mail_setup_started_at', '');
            Settings::set('mail_setup_task_id', '');

            if ($status === 'completed') {
                Settings::set('mail_node_configured', '1');
                Settings::set('mail_local_configured', '1');
            }

            LogService::log('cluster.mail', "local-setup-{$status}", "Mail setup local {$status}");
        }

        echo json_encode($result);
        exit;
    }

    /**
     * GET /settings/cluster/mail-setup-progress?node_id=X&task_id=Y (JSON)
     *
     * Polls the remote node for setup progress.
     * Includes master-side timeout: if polling for >15 min with no completion,
     * returns a timeout error so the frontend stops polling.
     */
    public function mailSetupProgress(): void
    {
        header('Content-Type: application/json');

        $nodeId = (int)($_GET['node_id'] ?? 0);
        $taskId = $_GET['task_id'] ?? '';

        if (!$nodeId || !$taskId) {
            echo json_encode(['ok' => false, 'error' => 'node_id y task_id requeridos']);
            exit;
        }

        // Master-side timeout: if we started polling >15 min ago, give up
        $setupStarted = Settings::get('mail_setup_started_at', '');
        if ($setupStarted) {
            $elapsedMin = (time() - strtotime($setupStarted)) / 60;
            if ($elapsedMin > 15) {
                echo json_encode([
                    'ok' => true,
                    'progress' => [
                        'status'  => 'timeout',
                        'step'    => 0,
                        'total_steps' => 9,
                        'current' => 'master_timeout',
                        'label'   => 'Timeout',
                        'errors'  => [[
                            'step'    => 'master_timeout',
                            'command' => 'polling_timeout',
                            'exit'    => -3,
                            'output'  => "No se completo en 15 minutos. El nodo puede estar caido, la instalacion puede seguir en background, o el proceso murio. Verifica el nodo directamente.",
                        ]],
                        'elapsed_min' => round($elapsedMin, 1),
                    ],
                ]);
                // Clean up
                Settings::set('mail_setup_started_at', '');
                exit;
            }
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado']);
            exit;
        }

        $token = \MuseDockPanel\Services\ReplicationService::decryptPassword($node['auth_token'] ?? '');
        $result = ClusterService::callNodeDirect($node['api_url'], $token, 'POST', 'api/cluster/action', [
            'action'  => 'mail_setup_status',
            'payload' => ['task_id' => $taskId],
        ]);

        if (!($result['ok'] ?? false)) {
            // Node unreachable during setup
            echo json_encode([
                'ok' => true,
                'progress' => [
                    'status'  => 'node_unreachable',
                    'step'    => 0,
                    'total_steps' => 9,
                    'current' => 'node_unreachable',
                    'label'   => 'Nodo no accesible',
                    'errors'  => [[
                        'step'    => 'polling',
                        'command' => 'node_unreachable',
                        'exit'    => -4,
                        'output'  => 'No se pudo contactar al nodo: ' . ($result['error'] ?? 'Error desconocido') . '. La instalacion puede seguir en background.',
                    ]],
                ],
            ]);
            exit;
        }

        $response = $result['data'] ?? $result;

        // If completed, clean up tracking
        $status = $response['progress']['status'] ?? '';
        if (in_array($status, ['completed', 'completed_with_errors', 'failed', 'stale', 'timeout'])) {
            Settings::set('mail_setup_started_at', '');
            Settings::set('mail_setup_task_id', '');
            LogService::log('cluster.mail', "setup-{$status}", "Mail setup {$status} en nodo #{$nodeId}");
        }

        echo json_encode($response);
        exit;
    }

    /** POST /settings/cluster/save-exclusions (JSON) */
    public function saveExclusions(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $exclusions = $_POST['exclusions'] ?? '';
        // Store as newline-separated list
        Settings::set('filesync_exclusions_list', $exclusions);

        // Also update lsyncd config if active
        $config = FileSyncService::getConfig();
        if ($config['sync_mode'] === 'lsyncd') {
            FileSyncService::generateLsyncdConfig();
        }

        LogService::log('cluster.filesync', 'exclusions', 'Lista de exclusiones actualizada');
        echo json_encode(['ok' => true]);
        exit;
    }
}
