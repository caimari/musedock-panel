<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Auth;
use MuseDockPanel\Database;
use MuseDockPanel\Env;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;
use MuseDockPanel\Services\LogService;

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

        View::render('backups/index', [
            'layout' => 'main',
            'pageTitle' => 'Backups',
            'backups' => $backups,
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
        $workerScript = PANEL_ROOT . '/bin/backup-worker.php';
        $args = sprintf(
            '%d %s %s %s',
            $accountId,
            $includeFiles ? '1' : '0',
            $includeDatabases ? '1' : '0',
            escapeshellarg($backupName)
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

            // Fix ownership
            $cmd = sprintf(
                'chown -R %s:%s %s 2>&1',
                escapeshellarg($username),
                escapeshellarg($username),
                escapeshellarg($homeDir . '/httpdocs/')
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
