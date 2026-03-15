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
     * Create backup for an account
     */
    public function store(): void
    {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $includeFiles = isset($_POST['include_files']);
        $includeDatabases = isset($_POST['include_databases']);

        if (empty($accountId)) {
            Flash::set('error', 'Debes seleccionar una cuenta.');
            Router::redirect('/backups/create');
            return;
        }

        if (!$includeFiles && !$includeDatabases) {
            Flash::set('error', 'Debes seleccionar al menos archivos o bases de datos.');
            Router::redirect('/backups/create');
            return;
        }

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $accountId]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/backups/create');
            return;
        }

        $username = $account['username'];
        $domain = $account['domain'];
        $homeDir = $account['home_dir'];
        $timestamp = date('Y-m-d_His');
        $backupName = "{$username}_{$timestamp}";
        $backupPath = self::BACKUP_DIR . "/{$backupName}";

        // Create backup directory
        if (!@mkdir($backupPath, 0750, true)) {
            Flash::set('error', 'No se pudo crear el directorio de backup.');
            Router::redirect('/backups/create');
            return;
        }

        $totalSize = 0;
        $dbList = [];
        $errors = [];

        // Archive files
        if ($includeFiles) {
            $vhostDir = dirname($homeDir); // /var/www/vhosts
            $dirName = basename($homeDir); // domain.com
            if (is_dir($homeDir . '/httpdocs')) {
                $tarFile = $backupPath . '/files.tar.gz';
                $cmd = sprintf(
                    'tar czf %s -C %s %s 2>&1',
                    escapeshellarg($tarFile),
                    escapeshellarg($homeDir),
                    'httpdocs/'
                );
                $output = shell_exec($cmd);
                if (file_exists($tarFile)) {
                    $totalSize += filesize($tarFile);
                } else {
                    $errors[] = "Error al crear archivos tar.gz: {$output}";
                }
            } else {
                $errors[] = "Directorio httpdocs no encontrado en {$homeDir}";
            }
        }

        // Dump databases
        if ($includeDatabases) {
            $databases = Database::fetchAll(
                "SELECT * FROM hosting_databases WHERE account_id = :id",
                ['id' => $accountId]
            );

            if (!empty($databases)) {
                $dbDir = $backupPath . '/databases';
                @mkdir($dbDir, 0750, true);

                foreach ($databases as $db) {
                    $dbName = $db['db_name'];
                    $dbType = $db['db_type'] ?? 'mysql';
                    $dumpFile = $dbDir . '/' . $dbName . '.sql';

                    if ($dbType === 'mysql') {
                        $mysqlCmd = $this->buildMysqlDumpCommand();
                        if ($mysqlCmd) {
                            $cmd = sprintf(
                                '%s %s > %s 2>&1',
                                $mysqlCmd,
                                escapeshellarg($dbName),
                                escapeshellarg($dumpFile)
                            );
                            shell_exec($cmd);
                        } else {
                            $errors[] = "No se pudo determinar autenticacion MySQL para {$dbName}";
                        }
                    } elseif ($dbType === 'pgsql') {
                        $cmd = sprintf(
                            'sudo -u postgres pg_dump %s > %s 2>&1',
                            escapeshellarg($dbName),
                            escapeshellarg($dumpFile)
                        );
                        shell_exec($cmd);
                    }

                    if (file_exists($dumpFile) && filesize($dumpFile) > 0) {
                        $totalSize += filesize($dumpFile);
                        $dbList[] = [
                            'name' => $dbName,
                            'type' => $dbType,
                            'size' => filesize($dumpFile),
                        ];
                    } else {
                        $errors[] = "Error al hacer dump de {$dbName}";
                        @unlink($dumpFile);
                    }
                }
            }
        }

        // Write metadata
        $metadata = [
            'username' => $username,
            'domain' => $domain,
            'account_id' => $accountId,
            'date' => date('Y-m-d H:i:s'),
            'timestamp' => $timestamp,
            'include_files' => $includeFiles,
            'include_databases' => $includeDatabases,
            'file_size' => $totalSize,
            'databases' => $dbList,
        ];
        file_put_contents($backupPath . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        LogService::log('backup.create', $domain, "Backup creado: {$backupName} ({$this->formatSize($totalSize)})");

        if (!empty($errors)) {
            Flash::set('warning', 'Backup creado con advertencias: ' . implode('; ', $errors));
        } else {
            Flash::set('success', "Backup creado exitosamente: {$backupName} — Tamano total: {$this->formatSize($totalSize)}");
        }

        Router::redirect('/backups');
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
