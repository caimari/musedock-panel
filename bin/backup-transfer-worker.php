<?php
/**
 * Background worker for transferring a backup to a remote node.
 * Writes progress to a status file so the frontend can poll.
 *
 * Usage: php backup-transfer-worker.php <backup_name> <node_id>
 */

define('PANEL_ROOT', dirname(__DIR__));

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

function formatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// ── Validate backup exists ────────────────────────────────────

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

// ── Step 0: Calculate backup size ────────────────────────────

$totalSize = 0;
foreach (glob("{$backupPath}/*") as $f) {
    if (is_file($f)) $totalSize += filesize($f);
}
if (is_dir($backupPath . '/databases')) {
    foreach (glob("{$backupPath}/databases/*") as $f) {
        if (is_file($f)) $totalSize += filesize($f);
    }
}

// ── Step 1: Pre-flight check on remote node ─────────────────

updateStatus($statusFile, [
    'status' => 'running',
    'step' => 'validating',
    'backup_name' => $backupName,
    'node_name' => $node['name'],
    'domain' => $meta['domain'] ?? '',
    'started_at' => date('Y-m-d H:i:s'),
    'percent' => 2,
    'message' => "Verificando capacidad de {$node['name']}...",
    'pid' => getmypid(),
]);

$preflight = MuseDockPanel\Services\ClusterService::callNode($nodeId, 'POST', 'api/cluster/action', [
    'action' => 'backup-preflight',
    'payload' => ['file_size' => $totalSize],
]);

if (!$preflight['ok']) {
    $errMsg = $preflight['error'] ?? '';
    // Check if it's an "Unknown action" = old panel version
    if (str_contains($errMsg, 'Unknown action') || str_contains($errMsg, 'unknown')) {
        $errMsg = "El nodo {$node['name']} no soporta backups remotos. Actualiza el panel en ese nodo (v1.0.6+).";
    } elseif (str_contains($errMsg, 'Connection') || str_contains($errMsg, 'timed out') || str_contains($errMsg, 'failed')) {
        $errMsg = "No se puede conectar con {$node['name']}: {$errMsg}";
    }
    updateStatus($statusFile, [
        'status' => 'error',
        'error' => $errMsg,
        'backup_name' => $backupName,
        'node_name' => $node['name'],
    ]);
    exit(1);
}

// Check preflight data for errors
$preflightData = $preflight['data'] ?? [];
if (!empty($preflightData['errors'])) {
    $errMsg = "El nodo {$node['name']} no puede recibir el backup:\n" . implode("\n", $preflightData['errors']);
    updateStatus($statusFile, [
        'status' => 'error',
        'error' => $errMsg,
        'backup_name' => $backupName,
        'node_name' => $node['name'],
        'preflight' => $preflightData,
    ]);
    exit(1);
}

updateStatus($statusFile, [
    'status' => 'running',
    'step' => 'packing',
    'backup_name' => $backupName,
    'node_name' => $node['name'],
    'domain' => $meta['domain'] ?? '',
    'started_at' => date('Y-m-d H:i:s'),
    'percent' => 5,
    'message' => 'Empaquetando backup (' . formatBytes($totalSize) . ')...',
    'pid' => getmypid(),
]);

$packStart = microtime(true);
$tmpFile = sys_get_temp_dir() . '/backup_transfer_' . $backupName . '_' . time() . '.tar.gz';
$cmd = sprintf('tar czf %s -C %s . 2>&1', escapeshellarg($tmpFile), escapeshellarg($backupPath));
shell_exec($cmd);
$packElapsed = round(microtime(true) - $packStart, 1);

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
$fileSizeHuman = formatBytes($fileSize);

updateStatus($statusFile, [
    'status' => 'running',
    'step' => 'uploading',
    'backup_name' => $backupName,
    'node_name' => $node['name'],
    'domain' => $meta['domain'] ?? '',
    'started_at' => date('Y-m-d H:i:s'),
    'percent' => 10,
    'message' => "Empaquetado en {$packElapsed}s. Enviando {$fileSizeHuman} a {$node['name']}...",
    'file_size' => $fileSize,
    'file_size_human' => $fileSizeHuman,
    'pid' => getmypid(),
]);

// ── Step 2: Upload via CURL with progress ──────────────────────

$token = MuseDockPanel\Services\ReplicationService::decryptPassword($node['auth_token'] ?? '');
$url = rtrim($node['api_url'], '/') . '/api/cluster/action';
$uploadStartTime = microtime(true);

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
    CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($statusFile, $backupName, $node, $meta, $fileSizeHuman, $fileSize, $uploadStartTime) {
        static $lastUpdate = 0;
        $now = microtime(true);
        if ($now - $lastUpdate < 0.8) return 0;
        $lastUpdate = $now;

        if ($ulTotal <= 0) return 0;

        $uploadPercent = round(($ulNow / $ulTotal) * 100);
        $overallPercent = 10 + round($uploadPercent * 0.85);

        $uploaded = formatBytes((int) $ulNow);
        $total = formatBytes((int) $ulTotal);

        // Speed & ETA
        $elapsed = $now - $uploadStartTime;
        $speed = '';
        $eta = '';
        if ($elapsed > 0.5 && $ulNow > 0) {
            $bytesPerSec = $ulNow / $elapsed;
            $speed = formatBytes((int) $bytesPerSec) . '/s';
            $remaining = $ulTotal - $ulNow;
            if ($bytesPerSec > 0) {
                $etaSec = (int) ($remaining / $bytesPerSec);
                if ($etaSec < 60) {
                    $eta = $etaSec . 's';
                } else {
                    $eta = floor($etaSec / 60) . 'm ' . ($etaSec % 60) . 's';
                }
            }
        }

        $msg = "Enviando: {$uploaded} / {$total}";
        if ($speed) $msg .= " ({$speed})";
        if ($eta) $msg .= " — ETA: {$eta}";

        updateStatus($statusFile, [
            'status' => 'running',
            'step' => 'uploading',
            'backup_name' => $backupName,
            'node_name' => $node['name'],
            'domain' => $meta['domain'] ?? '',
            'percent' => $overallPercent,
            'message' => $msg,
            'file_size' => $fileSize,
            'file_size_human' => $fileSizeHuman,
            'speed' => $speed,
            'eta' => $eta,
            'pid' => getmypid(),
        ]);

        return 0;
    },
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$totalTime = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 1);
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
    // Calculate avg speed
    $avgSpeed = $totalTime > 0 ? formatBytes((int) ($fileSize / $totalTime)) . '/s' : '';

    MuseDockPanel\Services\LogService::log('backup.transfer', $meta['domain'] ?? $backupName, "Backup transferido a {$node['name']} ({$fileSizeHuman} en {$totalTime}s, {$avgSpeed})");

    updateStatus($statusFile, [
        'status' => 'completed',
        'backup_name' => $backupName,
        'node_name' => $node['name'],
        'domain' => $meta['domain'] ?? '',
        'percent' => 100,
        'message' => "Transferido a {$node['name']} — {$fileSizeHuman} en {$totalTime}s ({$avgSpeed})",
        'file_size' => $fileSize,
        'file_size_human' => $fileSizeHuman,
        'elapsed' => $totalTime,
    ]);
} else {
    $errMsg = $data['error'] ?? $data['message'] ?? "Error HTTP {$httpCode}";
    updateStatus($statusFile, [
        'status' => 'error',
        'error' => $errMsg,
        'backup_name' => $backupName,
        'node_name' => $node['name'],
    ]);
    exit(1);
}
