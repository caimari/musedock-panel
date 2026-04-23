<?php
/**
 * Auto-Backup Worker — Runs from cron, backs up ALL active hosting accounts.
 * Applies retention policy (daily + weekly) after each run.
 *
 * Called by: /etc/cron.d/musedock-auto-backup
 * No arguments needed — reads settings from panel_settings table.
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

// Check if auto-backup is enabled
if (MuseDockPanel\Settings::get('auto_backup_enabled', '0') !== '1') {
    exit(0);
}

$scope = MuseDockPanel\Settings::get('auto_backup_scope', 'full');
$retainDaily = max(1, (int) MuseDockPanel\Settings::get('auto_backup_retain_daily', '7'));
$retainWeekly = max(0, (int) MuseDockPanel\Settings::get('auto_backup_retain_weekly', '4'));

$backupDir = PANEL_ROOT . '/storage/backups';
$logFile = PANEL_ROOT . '/storage/logs/auto-backup.log';

function logMsg(string $file, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents($file, $line, FILE_APPEND);
}

// Get all active hosting accounts
$accounts = MuseDockPanel\Database::fetchAll("
    SELECT id, username, domain, home_dir
    FROM hosting_accounts
    WHERE status = 'active'
    ORDER BY username
");

if (empty($accounts)) {
    logMsg($logFile, 'No active accounts to backup.');
    exit(0);
}

logMsg($logFile, '=== Auto-backup started: ' . count($accounts) . ' accounts, scope=' . $scope . ' ===');

$timestamp = date('Y-m-d_His');
$successCount = 0;
$errorCount = 0;
$totalSize = 0;

foreach ($accounts as $account) {
    $username = $account['username'];
    $domain = $account['domain'];
    $homeDir = $account['home_dir'];
    $backupName = "{$username}_{$timestamp}_auto";
    $backupPath = "{$backupDir}/{$backupName}";

    logMsg($logFile, "Backing up: {$domain} ({$username})...");

    // Run the backup worker synchronously (one at a time)
    $workerScript = PANEL_ROOT . '/bin/backup-worker.php';
    $cmd = sprintf(
        'php %s %d 1 1 %s %s 2>&1',
        escapeshellarg($workerScript),
        $account['id'],
        escapeshellarg($backupName),
        escapeshellarg($scope)
    );

    // Don't use the status file for auto-backups (it would interfere with manual backups)
    // Instead, run directly and check for metadata.json
    $output = shell_exec($cmd);

    $metaFile = $backupPath . '/metadata.json';
    if (file_exists($metaFile)) {
        $meta = json_decode(file_get_contents($metaFile), true);
        $size = $meta['file_size'] ?? 0;
        $totalSize += $size;
        $dbCount = count($meta['databases'] ?? []);
        logMsg($logFile, "  OK: {$backupName} (" . formatSize($size) . ", {$dbCount} DBs)");
        $successCount++;
    } else {
        logMsg($logFile, "  ERROR: Backup failed for {$domain}");
        $errorCount++;
    }

    // Clear the status file after each account (so it doesn't interfere with UI)
    $statusFile = $backupDir . '/.backup_status.json';
    if (file_exists($statusFile)) {
        @unlink($statusFile);
    }
}

logMsg($logFile, "=== Auto-backup completed: {$successCount} OK, {$errorCount} errors, total " . formatSize($totalSize) . ' ===');

// ── Remote Transfer (if enabled) ──────────────────────────────────

$remoteEnabled = MuseDockPanel\Settings::get('auto_backup_remote_enabled', '0') === '1';
$remoteNodeId = (int) MuseDockPanel\Settings::get('auto_backup_remote_node_id', '0');

if ($remoteEnabled && $remoteNodeId > 0) {
    $node = MuseDockPanel\Services\ClusterService::getNode($remoteNodeId);
    if ($node) {
        logMsg($logFile, "Transferring backups to remote node: {$node['name']}...");
        $transferOk = 0;
        $transferErr = 0;

        foreach ($accounts as $account) {
            $username = $account['username'];
            $backupName = "{$username}_{$timestamp}_auto";
            $backupPath = "{$backupDir}/{$backupName}";

            if (!is_dir($backupPath) || !file_exists($backupPath . '/metadata.json')) {
                continue; // Backup failed for this account
            }

            // Create tar.gz of the backup
            $tmpFile = sys_get_temp_dir() . '/auto_transfer_' . $backupName . '.tar.gz';
            $cmd = sprintf('tar czf %s -C %s . 2>&1', escapeshellarg($tmpFile), escapeshellarg($backupPath));
            shell_exec($cmd);

            if (!file_exists($tmpFile) || filesize($tmpFile) === 0) {
                @unlink($tmpFile);
                logMsg($logFile, "  ERROR: Could not create archive for {$backupName}");
                $transferErr++;
                continue;
            }

            // Send via CURL
            $token = MuseDockPanel\Services\ReplicationService::decryptPassword($node['auth_token'] ?? '');
            $url = rtrim($node['api_url'], '/') . '/api/cluster/action';

            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => [
                    'action' => 'receive-backup',
                    'backup_name' => $backupName,
                    'backup' => new CURLFile($tmpFile, 'application/gzip', 'backup.tar.gz'),
                ],
            ];
            $opts = array_replace($opts, \MuseDockPanel\Security\TlsClient::forUrl($url, [
                'metadata' => $node['metadata'] ?? null,
            ]));

            $ch = curl_init($url);
            curl_setopt_array($ch, $opts);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            @unlink($tmpFile);

            $data = @json_decode($response, true);
            if ($httpCode >= 200 && $httpCode < 300 && ($data['ok'] ?? false)) {
                logMsg($logFile, "  OK: {$backupName} transferred to {$node['name']}");
                $transferOk++;
            } else {
                logMsg($logFile, "  ERROR: Transfer failed for {$backupName}: " . ($data['error'] ?? "HTTP {$httpCode}"));
                $transferErr++;
            }
        }

        logMsg($logFile, "Remote transfer: {$transferOk} OK, {$transferErr} errors");
    } else {
        logMsg($logFile, "WARNING: Remote node ID {$remoteNodeId} not found, skipping transfer");
    }
}

// ── Retention: clean old backups ────────────────────────────────

logMsg($logFile, 'Applying retention policy: keep ' . $retainDaily . ' daily + ' . $retainWeekly . ' weekly');

// Collect all auto-backups grouped by account
$autoBackups = [];
if (is_dir($backupDir)) {
    foreach (glob("{$backupDir}/*_auto/metadata.json") as $metaFile) {
        $dir = dirname($metaFile);
        $dirName = basename($dir);
        $meta = @json_decode(file_get_contents($metaFile), true);
        if (!$meta) continue;

        $username = $meta['username'] ?? '';
        $date = strtotime($meta['date'] ?? '0');
        if (!$username || !$date) continue;

        $autoBackups[$username][] = [
            'dir' => $dir,
            'dir_name' => $dirName,
            'date' => $date,
            'domain' => $meta['domain'] ?? '',
        ];
    }
}

$deleted = 0;
foreach ($autoBackups as $username => $backups) {
    // Sort by date descending (newest first)
    usort($backups, fn($a, $b) => $b['date'] - $a['date']);

    $keep = [];
    $dailyKept = 0;
    $weeklyKept = 0;
    $seenWeeks = [];

    foreach ($backups as $bk) {
        $dayKey = date('Y-m-d', $bk['date']);
        $weekKey = date('Y-W', $bk['date']);

        // Keep as daily if within daily retention
        if ($dailyKept < $retainDaily) {
            $keep[] = $bk['dir'];
            $dailyKept++;
            $seenWeeks[$weekKey] = true;
            continue;
        }

        // Keep as weekly if within weekly retention and it's a new week
        if ($retainWeekly > 0 && !isset($seenWeeks[$weekKey]) && $weeklyKept < $retainWeekly) {
            $keep[] = $bk['dir'];
            $weeklyKept++;
            $seenWeeks[$weekKey] = true;
            continue;
        }
    }

    // Delete backups not in keep list
    foreach ($backups as $bk) {
        if (!in_array($bk['dir'], $keep)) {
            $cmd = sprintf('rm -rf %s 2>&1', escapeshellarg($bk['dir']));
            shell_exec($cmd);
            logMsg($logFile, "Retention: deleted {$bk['dir_name']} ({$bk['domain']})");
            $deleted++;
        }
    }
}

if ($deleted > 0) {
    logMsg($logFile, "Retention: {$deleted} old backup(s) deleted.");
}

// Log to panel
MuseDockPanel\Services\LogService::log(
    'backup.auto',
    'all',
    "Auto-backup: {$successCount} OK, {$errorCount} errors, " . formatSize($totalSize) . ". Retention: {$deleted} deleted."
);

function formatSize(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
