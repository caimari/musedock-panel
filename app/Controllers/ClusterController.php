<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;
use MuseDockPanel\Flash;
use MuseDockPanel\Settings;
use MuseDockPanel\Env;
use MuseDockPanel\Services\ClusterService;
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

        // Intervals
        Settings::set('cluster_heartbeat_interval', (string)(int)($_POST['cluster_heartbeat_interval'] ?? 30));
        Settings::set('cluster_unreachable_timeout', (string)(int)($_POST['cluster_unreachable_timeout'] ?? 300));

        // SMTP
        Settings::set('cluster_smtp_host', trim($_POST['cluster_smtp_host'] ?? ''));
        Settings::set('cluster_smtp_port', (string)(int)($_POST['cluster_smtp_port'] ?? 587));
        Settings::set('cluster_smtp_user', trim($_POST['cluster_smtp_user'] ?? ''));
        Settings::set('cluster_smtp_from', trim($_POST['cluster_smtp_from'] ?? ''));
        Settings::set('cluster_smtp_to', trim($_POST['cluster_smtp_to'] ?? ''));

        $smtpPass = $_POST['cluster_smtp_pass'] ?? '';
        if ($smtpPass !== '') {
            Settings::set('cluster_smtp_pass', ReplicationService::encryptPassword($smtpPass));
        }

        // Telegram
        Settings::set('cluster_telegram_token', trim($_POST['cluster_telegram_token'] ?? ''));
        Settings::set('cluster_telegram_chat_id', trim($_POST['cluster_telegram_chat_id'] ?? ''));

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
}
