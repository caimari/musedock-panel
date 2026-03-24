<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Auth;
use MuseDockPanel\Database;
use MuseDockPanel\Env;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Settings;
use MuseDockPanel\View;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\ReplicationService;

class BackupController
{
    private const BACKUP_DIR = PANEL_ROOT . '/storage/backups';

    /**
     * List all backups
     */
    public function index(): void
    {
        $backups = [];
        $backupDir = self::BACKUP_DIR;

        if (is_dir($backupDir)) {
            foreach (glob("{$backupDir}/*/metadata.json") as $metaFile) {
                $dir = dirname($metaFile);
                $meta = @json_decode(file_get_contents($metaFile), true);
                if (!$meta) continue;

                $meta['dir_name'] = basename($dir);
                $meta['full_path'] = $dir;

                // Count database dumps
                $dbDir = $dir . '/databases';
                $meta['db_count'] = is_dir($dbDir) ? count(glob("{$dbDir}/*.sql")) : 0;

                // Check if files.tar.gz exists
                $meta['has_files'] = file_exists($dir . '/files.tar.gz');

                $backups[] = $meta;
            }
        }

        // Sort by date descending
        usort($backups, fn($a, $b) => strtotime($b['date'] ?? '0') - strtotime($a['date'] ?? '0'));

        // Get cluster nodes for remote backup options
        $nodes = ClusterService::getNodes();

        View::render('backups/index', [
            'layout' => 'main',
            'pageTitle' => 'Backups',
            'backups' => $backups,
            'nodes' => $nodes,
        ]);
    }

    /**
     * Show form to create a new backup
     */
    public function create(): void
    {
        $accounts = Database::fetchAll("
            SELECT id, username, domain
            FROM hosting_accounts
            WHERE status = 'active'
            ORDER BY username
        ");

        View::render('backups/create', [
            'layout' => 'main',
            'pageTitle' => 'Crear Backup',
            'accounts' => $accounts,
            'autoBackupEnabled' => Settings::get('auto_backup_enabled', '0') === '1',
            'autoBackupFrequency' => Settings::get('auto_backup_frequency', 'daily'),
            'autoBackupTime' => Settings::get('auto_backup_time', '03:00'),
            'autoBackupRetainDaily' => (int) Settings::get('auto_backup_retain_daily', '7'),
            'autoBackupRetainWeekly' => (int) Settings::get('auto_backup_retain_weekly', '4'),
            'autoBackupScope' => Settings::get('auto_backup_scope', 'full'),
            'backupExclusions' => Settings::get('backup_exclusions', ''),
            'autoBackupRemoteEnabled' => Settings::get('auto_backup_remote_enabled', '0') === '1',
            'autoBackupRemoteNodeId' => (int) Settings::get('auto_backup_remote_node_id', '0'),
            'nodes' => ClusterService::getNodes(),
        ]);
    }

    /**
     * Launch backup in background (AJAX)
     */
    public function store(): void
    {
        header('Content-Type: application/json');

        $accountId = (int) ($_POST['account_id'] ?? 0);
        $includeFiles = isset($_POST['include_files']) || ($_POST['include_files'] ?? '') === '1';
        $includeDatabases = isset($_POST['include_databases']) || ($_POST['include_databases'] ?? '') === '1';

        if (empty($accountId)) {
            echo json_encode(['ok' => false, 'error' => 'Debes seleccionar una cuenta.']);
            exit;
        }

        if (!$includeFiles && !$includeDatabases) {
            echo json_encode(['ok' => false, 'error' => 'Debes seleccionar al menos archivos o bases de datos.']);
            exit;
        }

        // Check if a backup is already running
        $statusFile = self::BACKUP_DIR . '/.backup_status.json';
        if (file_exists($statusFile)) {
            $existing = @json_decode(file_get_contents($statusFile), true);
            if ($existing && ($existing['status'] ?? '') === 'running') {
                // Check if the process is actually still running
                $pid = $existing['pid'] ?? 0;
                if ($pid > 0 && file_exists("/proc/{$pid}")) {
                    echo json_encode(['ok' => false, 'error' => 'Ya hay un backup en curso. Espera a que termine.']);
                    exit;
                }
                // Process died, clean up stale status
            }
        }

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $accountId]);
        if (!$account) {
            echo json_encode(['ok' => false, 'error' => 'Cuenta no encontrada.']);
            exit;
        }

        $username = $account['username'];
        $domain = $account['domain'];
        $timestamp = date('Y-m-d_His');
        $backupName = "{$username}_{$timestamp}";

        // Count total steps for progress
        $totalSteps = 0;
        if ($includeFiles) $totalSteps++;
        if ($includeDatabases) {
            $dbCount = Database::fetchOne(
                "SELECT COUNT(*) as cnt FROM hosting_databases WHERE account_id = :id",
                ['id' => $accountId]
            );
            $totalSteps += max(1, (int)($dbCount['cnt'] ?? 0));
        }
        $totalSteps++; // metadata step

        // Write initial status
        $status = [
            'status' => 'running',
            'backup_name' => $backupName,
            'username' => $username,
            'domain' => $domain,
            'account_id' => $accountId,
            'include_files' => $includeFiles,
            'include_databases' => $includeDatabases,
            'started_at' => date('Y-m-d H:i:s'),
            'step' => 0,
            'total_steps' => $totalSteps,
            'current_task' => 'Iniciando backup...',
            'pid' => 0,
            'errors' => [],
        ];
        file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));

        // Build the worker command
        $scope = ($_POST['scope'] ?? 'full') === 'httpdocs' ? 'httpdocs' : 'full';
        $workerScript = PANEL_ROOT . '/bin/backup-worker.php';
        $args = sprintf(
            '%d %s %s %s %s',
            $accountId,
            $includeFiles ? '1' : '0',
            $includeDatabases ? '1' : '0',
            escapeshellarg($backupName),
            escapeshellarg($scope)
        );
        $cmd = sprintf(
            'php %s %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($workerScript),
            $args
        );

        $pid = trim((string)shell_exec($cmd));

        // Update status with PID
        $status['pid'] = (int)$pid;
        file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));

        echo json_encode([
            'ok' => true,
            'backup_name' => $backupName,
            'pid' => (int)$pid,
            'message' => "Backup iniciado para {$domain}",
        ]);
        exit;
    }

    /**
     * GET /backups/status (JSON) — Poll backup progress
     */
    public function status(): void
    {
        header('Content-Type: application/json');

        $statusFile = self::BACKUP_DIR . '/.backup_status.json';
        if (!file_exists($statusFile)) {
            echo json_encode(['ok' => true, 'status' => 'idle']);
            exit;
        }

        $data = @json_decode(file_get_contents($statusFile), true);
        if (!$data) {
            echo json_encode(['ok' => true, 'status' => 'idle']);
            exit;
        }

        // If running, verify process is alive
        if (($data['status'] ?? '') === 'running') {
            $pid = $data['pid'] ?? 0;
            if ($pid > 0 && !file_exists("/proc/{$pid}")) {
                // Process died unexpectedly
                $data['status'] = 'error';
                $data['current_task'] = 'El proceso de backup termino inesperadamente';
                file_put_contents($statusFile, json_encode($data, JSON_PRETTY_PRINT));
            }
        }

        echo json_encode(array_merge(['ok' => true], $data));
        exit;
    }

    /**
     * POST /backups/status/clear — Clear finished backup status
     */
    public function statusClear(): void
    {
        header('Content-Type: application/json');
        $statusFile = self::BACKUP_DIR . '/.backup_status.json';
        if (file_exists($statusFile)) {
            @unlink($statusFile);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * Download a specific backup file
     */
    public function download(): void
    {
        $file = $_GET['path'] ?? '';
        if (empty($file)) {
            Flash::set('error', 'Archivo no especificado.');
            Router::redirect('/backups');
            return;
        }

        // Security: only allow basename to prevent traversal
        $file = basename($file);
        $backupId = $_GET['backup'] ?? '';
        $backupId = basename($backupId);

        // Determine subfolder
        $subdir = $_GET['subdir'] ?? '';
        $subdir = ($subdir === 'databases') ? 'databases/' : '';

        $fullPath = self::BACKUP_DIR . '/' . $backupId . '/' . $subdir . $file;

        // Validate path is within backup dir
        $realPath = realpath($fullPath);
        $realBackupDir = realpath(self::BACKUP_DIR);

        if (!$realPath || !$realBackupDir || strpos($realPath, $realBackupDir) !== 0) {
            Flash::set('error', 'Archivo no encontrado o ruta no valida.');
            Router::redirect('/backups');
            return;
        }

        if (!file_exists($realPath) || !is_file($realPath)) {
            Flash::set('error', 'Archivo no encontrado.');
            Router::redirect('/backups');
            return;
        }

        // Stream the file
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($realPath) . '"');
        header('Content-Length: ' . filesize($realPath));
        header('Cache-Control: no-cache');
        readfile($realPath);
        exit;
    }

    /**
     * Show restore confirmation page
     */
    public function restore(array $params): void
    {
        $backupId = basename($params['id'] ?? '');
        $backupPath = self::BACKUP_DIR . '/' . $backupId;
        $metaFile = $backupPath . '/metadata.json';

        if (!file_exists($metaFile)) {
            Flash::set('error', 'Backup no encontrado.');
            Router::redirect('/backups');
            return;
        }

        $meta = json_decode(file_get_contents($metaFile), true);
        if (!$meta) {
            Flash::set('error', 'Metadata del backup corrupta.');
            Router::redirect('/backups');
            return;
        }

        $meta['dir_name'] = $backupId;
        $meta['has_files'] = file_exists($backupPath . '/files.tar.gz');

        // List database dumps
        $dbFiles = [];
        $dbDir = $backupPath . '/databases';
        if (is_dir($dbDir)) {
            foreach (glob("{$dbDir}/*.sql") as $sqlFile) {
                $dbFiles[] = [
                    'filename' => basename($sqlFile),
                    'name' => basename($sqlFile, '.sql'),
                    'size' => filesize($sqlFile),
                ];
            }
        }
        $meta['db_files'] = $dbFiles;

        // Check if account still exists
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE username = :u", ['u' => $meta['username'] ?? '']);
        $meta['account_exists'] = (bool) $account;
        $meta['account'] = $account;

        View::render('backups/restore', [
            'layout' => 'main',
            'pageTitle' => 'Restaurar Backup',
            'backup' => $meta,
        ]);
    }

    /**
     * Execute restore from backup
     */
    public function restoreExecute(array $params): void
    {
        $backupId = basename($params['id'] ?? '');
        $backupPath = self::BACKUP_DIR . '/' . $backupId;
        $metaFile = $backupPath . '/metadata.json';

        if (!file_exists($metaFile)) {
            Flash::set('error', 'Backup no encontrado.');
            Router::redirect('/backups');
            return;
        }

        $meta = json_decode(file_get_contents($metaFile), true);
        if (!$meta) {
            Flash::set('error', 'Metadata del backup corrupta.');
            Router::redirect('/backups');
            return;
        }

        $username = $meta['username'] ?? '';
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE username = :u", ['u' => $username]);
        if (!$account) {
            Flash::set('error', "La cuenta '{$username}' ya no existe. No se puede restaurar.");
            Router::redirect('/backups');
            return;
        }

        $homeDir = $account['home_dir'];
        $restoreFiles = isset($_POST['restore_files']);
        $restoreDatabases = isset($_POST['restore_databases']);
        $errors = [];

        // Restore files
        if ($restoreFiles && file_exists($backupPath . '/files.tar.gz')) {
            $cmd = sprintf(
                'tar xzf %s -C %s 2>&1',
                escapeshellarg($backupPath . '/files.tar.gz'),
                escapeshellarg($homeDir)
            );
            $output = shell_exec($cmd);
            if (!empty($output)) {
                $errors[] = "Advertencia al extraer archivos: {$output}";
            }

            // Fix ownership — full home dir if scope was full, otherwise just httpdocs
            $scope = $meta['scope'] ?? 'httpdocs';
            $chownTarget = ($scope === 'full') ? $homeDir : $homeDir . '/httpdocs/';
            $cmd = sprintf(
                'chown -R %s:%s %s 2>&1',
                escapeshellarg($username),
                escapeshellarg($username),
                escapeshellarg($chownTarget)
            );
            shell_exec($cmd);
        }

        // Restore databases
        if ($restoreDatabases) {
            $dbDir = $backupPath . '/databases';
            if (is_dir($dbDir)) {
                foreach (glob("{$dbDir}/*.sql") as $sqlFile) {
                    $dbName = basename($sqlFile, '.sql');

                    // Check db type from metadata
                    $dbType = 'mysql';
                    foreach ($meta['databases'] ?? [] as $dbInfo) {
                        if (($dbInfo['name'] ?? '') === $dbName) {
                            $dbType = $dbInfo['type'] ?? 'mysql';
                            break;
                        }
                    }

                    if ($dbType === 'mysql') {
                        $mysqlCmd = $this->buildMysqlCommand();
                        if ($mysqlCmd) {
                            $cmd = sprintf(
                                '%s %s < %s 2>&1',
                                $mysqlCmd,
                                escapeshellarg($dbName),
                                escapeshellarg($sqlFile)
                            );
                            $output = shell_exec($cmd);
                            if (!empty($output) && stripos($output, 'ERROR') !== false) {
                                $errors[] = "Error al importar {$dbName}: {$output}";
                            }
                        }
                    } elseif ($dbType === 'pgsql') {
                        $cmd = sprintf(
                            'sudo -u postgres psql %s < %s 2>&1',
                            escapeshellarg($dbName),
                            escapeshellarg($sqlFile)
                        );
                        $output = shell_exec($cmd);
                        if (!empty($output) && stripos($output, 'ERROR') !== false) {
                            $errors[] = "Error al importar {$dbName}: {$output}";
                        }
                    }
                }
            }
        }

        // Restart FPM
        $phpVersion = $account['php_version'] ?? '8.3';
        shell_exec(sprintf('systemctl restart php%s-fpm 2>&1', escapeshellarg($phpVersion)));

        LogService::log('backup.restore', $account['domain'], "Backup restaurado: {$backupId}");

        if (!empty($errors)) {
            Flash::set('warning', 'Backup restaurado con advertencias: ' . implode('; ', $errors));
        } else {
            Flash::set('success', "Backup '{$backupId}' restaurado exitosamente para {$account['domain']}.");
        }

        Router::redirect('/backups');
    }

    /**
     * Delete a backup directory
     */
    public function delete(array $params): void
    {
        $backupId = basename($params['id'] ?? '');
        $backupPath = self::BACKUP_DIR . '/' . $backupId;

        // Validate path is within backup dir
        $realPath = realpath($backupPath);
        $realBackupDir = realpath(self::BACKUP_DIR);

        if (!$realPath || !$realBackupDir || strpos($realPath, $realBackupDir) !== 0 || $realPath === $realBackupDir) {
            Flash::set('error', 'Backup no encontrado o ruta no valida.');
            Router::redirect('/backups');
            return;
        }

        if (!is_dir($realPath)) {
            Flash::set('error', 'Backup no encontrado.');
            Router::redirect('/backups');
            return;
        }

        // Admin password confirmation
        $password = $_POST['admin_password'] ?? '';
        $user = Auth::user();
        if ($user) {
            $admin = Database::fetchOne("SELECT * FROM panel_admins WHERE id = :id", ['id' => $user['id']]);
            if (!$admin || !password_verify($password, $admin['password_hash'])) {
                Flash::set('error', 'Contrasena de administrador incorrecta.');
                Router::redirect('/backups');
                return;
            }
        }

        // Read metadata for logging
        $metaFile = $realPath . '/metadata.json';
        $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];
        $domain = $meta['domain'] ?? $backupId;

        // Delete directory recursively
        $cmd = sprintf('rm -rf %s 2>&1', escapeshellarg($realPath));
        shell_exec($cmd);

        LogService::log('backup.delete', $domain, "Backup eliminado: {$backupId}");
        Flash::set('success', "Backup '{$backupId}' eliminado.");
        Router::redirect('/backups');
    }

    // ================================================================
    // Auto-Backup Settings
    // ================================================================

    /**
     * Save auto-backup configuration
     */
    public function saveAutoBackupSettings(): void
    {
        $enabled = ($_POST['auto_backup_enabled'] ?? '0') === '1' ? '1' : '0';
        $frequency = in_array($_POST['auto_backup_frequency'] ?? '', ['daily', 'weekly']) ? $_POST['auto_backup_frequency'] : 'daily';
        $time = $_POST['auto_backup_time'] ?? '03:00';
        $retainDaily = max(1, min(90, (int)($_POST['auto_backup_retain_daily'] ?? 7)));
        $retainWeekly = max(0, min(52, (int)($_POST['auto_backup_retain_weekly'] ?? 4)));
        $scope = ($_POST['auto_backup_scope'] ?? 'full') === 'httpdocs' ? 'httpdocs' : 'full';
        $exclusions = trim($_POST['backup_exclusions'] ?? '');

        Settings::set('auto_backup_enabled', $enabled);
        Settings::set('auto_backup_frequency', $frequency);
        Settings::set('auto_backup_time', $time);
        Settings::set('auto_backup_retain_daily', (string)$retainDaily);
        Settings::set('auto_backup_retain_weekly', (string)$retainWeekly);
        Settings::set('auto_backup_scope', $scope);
        Settings::set('backup_exclusions', $exclusions);

        // Remote backup settings
        $remoteEnabled = ($_POST['auto_backup_remote_enabled'] ?? '0') === '1' ? '1' : '0';
        $remoteNodeId = max(0, (int) ($_POST['auto_backup_remote_node_id'] ?? 0));
        Settings::set('auto_backup_remote_enabled', $remoteEnabled);
        Settings::set('auto_backup_remote_node_id', (string) $remoteNodeId);

        // Write or remove cron
        $this->updateAutoBackupCron($enabled === '1', $time);

        LogService::log('backup.settings', 'auto-backup', "Auto-backup " . ($enabled === '1' ? 'enabled' : 'disabled') . " freq={$frequency} time={$time} retain={$retainDaily}d+{$retainWeekly}w scope={$scope}");
        Flash::set('success', 'Configuracion de backups automaticos guardada.');
        Router::redirect('/backups/create');
    }

    /**
     * Update the cron file for auto-backups
     */
    private function updateAutoBackupCron(bool $enabled, string $time): void
    {
        $cronFile = '/etc/cron.d/musedock-auto-backup';

        if (!$enabled) {
            if (file_exists($cronFile)) {
                @unlink($cronFile);
            }
            return;
        }

        // Parse time HH:MM
        $parts = explode(':', $time);
        $hour = max(0, min(23, (int)($parts[0] ?? 3)));
        $minute = max(0, min(59, (int)($parts[1] ?? 0)));

        $workerPath = PANEL_ROOT . '/bin/auto-backup-worker.php';

        $cronContent = "# MuseDock Panel — Automatic hosting backups\n";
        $cronContent .= "{$minute} {$hour} * * * root php {$workerPath} > /dev/null 2>&1\n";

        file_put_contents($cronFile, $cronContent);
        @chmod($cronFile, 0644);
    }

    // ================================================================
    // Remote Backup Operations
    // ================================================================

    /**
     * POST /backups/{id}/transfer — Launch background transfer to remote node
     */
    public function transferToNode(array $params): void
    {
        header('Content-Type: application/json');

        $backupId = basename($params['id'] ?? '');
        $nodeId = (int) ($_POST['node_id'] ?? 0);
        $backupPath = self::BACKUP_DIR . '/' . $backupId;

        if (!$nodeId) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no especificado.']);
            exit;
        }

        if (!is_dir($backupPath) || !file_exists($backupPath . '/metadata.json')) {
            echo json_encode(['ok' => false, 'error' => 'Backup no encontrado.']);
            exit;
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado.']);
            exit;
        }

        // Check if a transfer is already running
        $statusFile = self::BACKUP_DIR . '/.transfer_status.json';
        if (file_exists($statusFile)) {
            $existing = @json_decode(file_get_contents($statusFile), true);
            if ($existing && ($existing['status'] ?? '') === 'running') {
                $pid = $existing['pid'] ?? 0;
                if ($pid > 0 && file_exists("/proc/{$pid}")) {
                    echo json_encode(['ok' => false, 'error' => 'Ya hay una transferencia en curso.']);
                    exit;
                }
            }
        }

        // Launch background worker
        $workerScript = PANEL_ROOT . '/bin/backup-transfer-worker.php';
        $cmd = sprintf(
            'php %s %s %d > /dev/null 2>&1 & echo $!',
            escapeshellarg($workerScript),
            escapeshellarg($backupId),
            $nodeId
        );
        $pid = trim((string) shell_exec($cmd));

        echo json_encode([
            'ok' => true,
            'message' => "Transferencia iniciada a {$node['name']}",
            'pid' => (int) $pid,
        ]);
        exit;
    }

    /**
     * GET /backups/transfer/status — Poll transfer progress (JSON)
     */
    public function transferStatus(): void
    {
        header('Content-Type: application/json');

        $statusFile = self::BACKUP_DIR . '/.transfer_status.json';
        if (!file_exists($statusFile)) {
            echo json_encode(['ok' => true, 'status' => 'idle']);
            exit;
        }

        $data = @json_decode(file_get_contents($statusFile), true);
        if (!$data) {
            echo json_encode(['ok' => true, 'status' => 'idle']);
            exit;
        }

        // If running, verify process is alive
        if (($data['status'] ?? '') === 'running') {
            $pid = $data['pid'] ?? 0;
            if ($pid > 0 && !file_exists("/proc/{$pid}")) {
                $data['status'] = 'error';
                $data['error'] = 'El proceso de transferencia termino inesperadamente';
                file_put_contents($statusFile, json_encode($data, JSON_PRETTY_PRINT));
            }
        }

        echo json_encode(array_merge(['ok' => true], $data));
        exit;
    }

    /**
     * POST /backups/transfer/clear — Clear finished transfer status
     */
    public function transferClear(): void
    {
        header('Content-Type: application/json');
        $statusFile = self::BACKUP_DIR . '/.transfer_status.json';
        if (file_exists($statusFile)) {
            @unlink($statusFile);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * GET /backups/remote — List backups on a remote node (JSON)
     */
    public function listRemoteBackups(): void
    {
        header('Content-Type: application/json');

        $nodeId = (int) ($_GET['node_id'] ?? 0);
        if (!$nodeId) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no especificado.']);
            exit;
        }

        $result = ClusterService::callNode($nodeId, 'POST', 'api/cluster/action', ['action' => 'list-backups']);

        if ($result['ok'] && $result['data']) {
            echo json_encode(['ok' => true, 'backups' => $result['data']['backups'] ?? [], 'count' => $result['data']['count'] ?? 0]);
        } else {
            echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Error de conexion']);
        }
        exit;
    }

    /**
     * POST /backups/remote/fetch — Download a backup from a remote node and save locally
     */
    public function fetchFromNode(): void
    {
        header('Content-Type: application/json');

        $nodeId = (int) ($_POST['node_id'] ?? 0);
        $backupName = basename($_POST['backup_name'] ?? '');

        if (!$nodeId || !$backupName) {
            echo json_encode(['ok' => false, 'error' => 'Faltan parametros.']);
            exit;
        }

        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            echo json_encode(['ok' => false, 'error' => 'Nodo no encontrado.']);
            exit;
        }

        // Check if backup already exists locally
        $localPath = self::BACKUP_DIR . '/' . $backupName;
        if (is_dir($localPath) && file_exists($localPath . '/metadata.json')) {
            echo json_encode(['ok' => false, 'error' => 'El backup ya existe localmente.']);
            exit;
        }

        // Download via cluster API — this endpoint streams binary, not JSON
        $token = ReplicationService::decryptPassword($node['auth_token'] ?? '');
        $url = rtrim($node['api_url'], '/') . '/api/cluster/action';

        $tmpFile = sys_get_temp_dir() . '/backup_fetch_' . $backupName . '_' . time() . '.tar.gz';
        $fp = fopen($tmpFile, 'w');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/gzip',
            ],
            CURLOPT_POSTFIELDS => json_encode(['action' => 'download-backup', 'backup_name' => $backupName]),
            CURLOPT_FILE => $fp,
        ]);

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($error || $httpCode >= 400 || filesize($tmpFile) < 100) {
            @unlink($tmpFile);
            // Try reading error from response if it was JSON
            if (str_contains($contentType ?? '', 'json')) {
                $errData = @json_decode(file_get_contents($tmpFile), true);
                echo json_encode(['ok' => false, 'error' => $errData['error'] ?? "Error HTTP {$httpCode}"]);
            } else {
                echo json_encode(['ok' => false, 'error' => $error ?: "Error HTTP {$httpCode}"]);
            }
            exit;
        }

        // Extract to local backup directory
        @mkdir($localPath, 0750, true);
        $cmd = sprintf('tar xzf %s -C %s 2>&1', escapeshellarg($tmpFile), escapeshellarg($localPath));
        shell_exec($cmd);
        @unlink($tmpFile);

        if (!file_exists($localPath . '/metadata.json')) {
            shell_exec(sprintf('rm -rf %s 2>&1', escapeshellarg($localPath)));
            echo json_encode(['ok' => false, 'error' => 'Backup descargado pero sin metadata.json']);
            exit;
        }

        $meta = @json_decode(file_get_contents($localPath . '/metadata.json'), true);
        LogService::log('backup.fetch', $meta['domain'] ?? $backupName, "Backup recuperado del nodo: {$node['name']}");

        echo json_encode(['ok' => true, 'message' => "Backup recuperado de {$node['name']}"]);
        exit;
    }

    /**
     * POST /backups/remote/delete — Delete a backup on a remote node
     */
    public function deleteRemoteBackup(): void
    {
        header('Content-Type: application/json');

        $nodeId = (int) ($_POST['node_id'] ?? 0);
        $backupName = basename($_POST['backup_name'] ?? '');

        if (!$nodeId || !$backupName) {
            echo json_encode(['ok' => false, 'error' => 'Faltan parametros.']);
            exit;
        }

        $result = ClusterService::callNode($nodeId, 'POST', 'api/cluster/action', [
            'action' => 'delete-backup',
            'backup_name' => $backupName,
        ]);

        if ($result['ok'] && ($result['data']['ok'] ?? false)) {
            LogService::log('backup.remote_delete', $backupName, "Backup eliminado del nodo remoto");
            echo json_encode(['ok' => true, 'message' => 'Backup eliminado del nodo remoto.']);
        } else {
            echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Error al eliminar']);
        }
        exit;
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function buildMysqlDumpCommand(): ?string
    {
        $authMethod = Env::get('MYSQL_AUTH_METHOD', 'socket');

        if ($authMethod === 'socket') {
            return 'mysqldump -u root';
        }

        if ($authMethod === 'password') {
            $pass = Env::get('MYSQL_ROOT_PASS', '');
            if (empty($pass)) {
                return null;
            }
            return 'mysqldump -u root -p' . escapeshellarg($pass);
        }

        return null;
    }

    private function buildMysqlCommand(): ?string
    {
        $authMethod = Env::get('MYSQL_AUTH_METHOD', 'socket');

        if ($authMethod === 'socket') {
            return 'mysql -u root';
        }

        if ($authMethod === 'password') {
            $pass = Env::get('MYSQL_ROOT_PASS', '');
            if (empty($pass)) {
                return null;
            }
            return 'mysql -u root -p' . escapeshellarg($pass);
        }

        return null;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
