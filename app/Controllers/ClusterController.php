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

        View::render('settings/cluster', [
            'layout'       => 'main',
            'pageTitle'    => 'Cluster',
            'nodes'        => $nodes,
            'localStatus'  => $localStatus,
            'queueStats'   => $queueStats,
            'recentQueue'  => $recentQueue,
            'settings'     => $settings,
            'localToken'   => $localToken,
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

        $id = ClusterService::addNode($name, $apiUrl, $token);
        LogService::log('cluster.node', 'add', "Nodo anadido: {$name} ({$apiUrl}), ID: {$id}");
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

        echo json_encode([
            'ok'          => true,
            'nodes'       => $nodesData,
            'local'       => $localStatus,
            'queue_stats' => ClusterService::getQueueStats(),
            'timestamp'   => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT);
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
        }

        // Intervals
        Settings::set('cluster_heartbeat_interval', (string)(int)($_POST['cluster_heartbeat_interval'] ?? 30));
        Settings::set('cluster_unreachable_timeout', (string)(int)($_POST['cluster_unreachable_timeout'] ?? 300));

        LogService::log('cluster.settings', 'save', 'Configuracion del cluster guardada');
        Flash::set('success', 'Configuracion del cluster guardada');
        header('Location: /settings/cluster');
        exit;
    }

    /**
     * POST /settings/cluster/clean-queue
     */
    public function cleanQueue(): void
    {
        View::verifyCsrf();

        $deleted = ClusterService::cleanOldItems(0); // Clean all completed
        LogService::log('cluster.queue', 'clean', "Eliminados {$deleted} elementos completados");
        Flash::set('success', "Se eliminaron {$deleted} elementos completados de la cola");
        header('Location: /settings/cluster');
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
            ClusterService::enqueue($nodeId, 'sync-hosting', [
                'hosting_action' => 'create_hosting',
                'hosting_data' => [
                    'username' => $acc['username'],
                    'domain' => $acc['domain'],
                    'home_dir' => $acc['home_dir'],
                    'document_root' => $acc['document_root'],
                    'php_version' => $acc['php_version'] ?? '8.3',
                    'password' => '',
                    'shell' => $acc['shell'] ?? '/usr/sbin/nologin',
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

        $result = FileSyncService::syncAllToNode($nodeId);
        LogService::log('cluster.filesync', 'manual', "Sync archivos al nodo #{$nodeId}: {$result['synced']}/{$result['total']} OK");

        echo json_encode($result);
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
}
