<?php
/**
 * Background worker for transferring a backup to a remote node.
 * Writes progress to a status file so the frontend can poll.
 *
 * Usage: php backup-transfer-worker.php <backup_name> <node_id>
 */

define('PANEL_ROOT', dirname(__DIR__));

// Bootstrap
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require $file;
});

MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

$backupName = $argv[1] ?? '';
$nodeId = (int) ($argv[2] ?? 0);

if (!$backupName || !$nodeId) {
    exit(1);
}

$backupDir = PANEL_ROOT . '/storage/backups';
$backupPath = $backupDir . '/' . $backupName;
$statusFile = $backupDir . '/.transfer_status.json';

function updateStatus(string $file, array $data): void
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// Validate
if (!is_dir($backupPath) || !file_exists($backupPath . '/metadata.json')) {
    updateStatus($statusFile, ['status' => 'error', 'error' => 'Backup no encontrado', 'backup_name' => $backupName]);
    exit(1);
}

$node = MuseDockPanel\Services\ClusterService::getNode($nodeId);
if (!$node) {
    updateStatus($statusFile, ['status' => 'error', 'error' => 'Nodo no encontrado', 'backup_name' => $backupName]);
    exit(1);
}

$meta = @json_decode(file_get_contents($backupPath . '/metadata.json'), true) ?: [];

updateStatus($statusFile, [
    'status' => 'running',
    'step' => 'packing',
    'backup_name' => $backupName,
    'node_name' => $node['name'],
    'domain' => $meta['domain'] ?? '',
    'started_at' => date('Y-m-d H:i:s'),
    'percent' => 5,
    'message' => 'Empaquetando backup...',
    'pid' => getmypid(),
]);

// Step 1: Create tar.gz
$tmpFile = sys_get_temp_dir() . '/backup_transfer_' . $backupName . '_' . time() . '.tar.gz';
$cmd = sprintf('tar czf %s -C %s . 2>&1', escapeshellarg($tmpFile), escapeshellarg($backupPath));
shell_exec($cmd);

if (!file_exists($tmpFile) || filesize($tmpFile) === 0) {
    @unlink($tmpFile);
    updateStatus($statusFile, [
        'status' => 'error',
        'error' => 'Error al crear archivo para transferencia',
        'backup_name' => $backupName,
        'node_name' => $node['name'],
    ]);
    exit(1);
}

$fileSize = filesize($tmpFile);
$fileSizeHuman = $fileSize >= 1048576
    ? round($fileSize / 1048576, 2) . ' MB'
    : round($fileSize / 1024, 2) . ' KB';

updateStatus($statusFile, [
    'status' => 'running',
    'step' => 'uploading',
    'backup_name' => $backupName,
    'node_name' => $node['name'],
    'domain' => $meta['domain'] ?? '',
    'started_at' => date('Y-m-d H:i:s'),
    'percent' => 15,
    'message' => "Enviando {$fileSizeHuman} a {$node['name']}...",
    'file_size' => $fileSize,
    'file_size_human' => $fileSizeHuman,
    'pid' => getmypid(),
]);

// Step 2: Upload via CURL with progress callback
$token = MuseDockPanel\Services\ReplicationService::decryptPassword($node['auth_token'] ?? '');
$url = rtrim($node['api_url'], '/') . '/api/cluster/action';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 600,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => [
        'action' => 'receive-backup',
        'backup_name' => $backupName,
        'backup' => new CURLFile($tmpFile, 'application/gzip', 'backup.tar.gz'),
    ],
    CURLOPT_NOPROGRESS => false,
    CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($statusFile, $backupName, $node, $meta, $fileSizeHuman, $fileSize) {
        static $lastUpdate = 0;
        $now = time();
        if ($now - $lastUpdate < 1) return 0; // Update max once per second
        $lastUpdate = $now;

        $uploadPercent = ($ulTotal > 0) ? round(($ulNow / $ulTotal) * 100) : 0;
        // Map upload 0-100% to overall 15-95%
        $overallPercent = 15 + round($uploadPercent * 0.8);

        $uploaded = $ulNow >= 1048576
            ? round($ulNow / 1048576, 1) . ' MB'
            : round($ulNow / 1024, 1) . ' KB';

        updateStatus($statusFile, [
            'status' => 'running',
            'step' => 'uploading',
            'backup_name' => $backupName,
            'node_name' => $node['name'],
            'domain' => $meta['domain'] ?? '',
            'percent' => $overallPercent,
            'message' => "Enviando: {$uploaded} / {$fileSizeHuman}",
            'file_size' => $fileSize,
            'file_size_human' => $fileSizeHuman,
            'upload_total' => $ulTotal,
            'upload_now' => $ulNow,
            'pid' => getmypid(),
        ]);

        return 0; // Continue
    },
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

@unlink($tmpFile);

if ($error) {
    updateStatus($statusFile, [
        'status' => 'error',
        'error' => "Error de conexion: {$error}",
        'backup_name' => $backupName,
        'node_name' => $node['name'],
    ]);
    exit(1);
}

$data = @json_decode($response, true);
if ($httpCode >= 200 && $httpCode < 300 && ($data['ok'] ?? false)) {
    MuseDockPanel\Services\LogService::log('backup.transfer', $meta['domain'] ?? $backupName, "Backup transferido a nodo: {$node['name']}");

    updateStatus($statusFile, [
        'status' => 'completed',
        'backup_name' => $backupName,
        'node_name' => $node['name'],
        'domain' => $meta['domain'] ?? '',
        'percent' => 100,
        'message' => "Backup transferido a {$node['name']}",
        'file_size' => $fileSize,
        'file_size_human' => $fileSizeHuman,
    ]);
} else {
    updateStatus($statusFile, [
        'status' => 'error',
        'error' => $data['error'] ?? "Error HTTP {$httpCode}",
        'backup_name' => $backupName,
        'node_name' => $node['name'],
    ]);
    exit(1);
}
