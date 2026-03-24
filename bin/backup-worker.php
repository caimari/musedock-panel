<?php
/**
 * Backup Worker — Runs in background, updates status file with progress.
 * Usage: php backup-worker.php <account_id> <include_files:0|1> <include_databases:0|1> <backup_name> [scope:full|httpdocs]
 */

define('PANEL_ROOT', dirname(__DIR__));

// Bootstrap — use the same autoloader as the main app
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require $file;
});

MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

$accountId        = (int)($argv[1] ?? 0);
$includeFiles     = ($argv[2] ?? '0') === '1';
$includeDatabases = ($argv[3] ?? '0') === '1';
$backupName       = $argv[4] ?? '';
$scope            = $argv[5] ?? 'full'; // 'full' = entire domain dir, 'httpdocs' = only httpdocs/

if (!$accountId || !$backupName) {
    exit(1);
}

$backupDir  = PANEL_ROOT . '/storage/backups';
$statusFile = $backupDir . '/.backup_status.json';
$backupPath = $backupDir . '/' . $backupName;

// Default exclusions (patterns that waste space in backups)
$defaultExclusions = [
    'node_modules',
    '.git',
    '.svn',
    'vendor/bin',
    '__pycache__',
    '.cache',
    '.npm',
    '.composer/cache',
    'storage/logs/*.log',
    '*.log',
    'tmp/',
    'temp/',
];

// Load custom exclusions from settings
$customExclusions = MuseDockPanel\Settings::get('backup_exclusions', '');
if (!empty($customExclusions)) {
    $extra = array_filter(array_map('trim', explode("\n", $customExclusions)));
    $defaultExclusions = array_merge($defaultExclusions, $extra);
}

// Helper: update status file
function updateStatus(string $file, array $merge): void
{
    $data = @json_decode(@file_get_contents($file) ?: '{}', true) ?: [];
    $data = array_merge($data, $merge);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// Helper: format bytes
function fmtSize(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

try {
    // Get account info
    $account = MuseDockPanel\Database::fetchOne(
        "SELECT * FROM hosting_accounts WHERE id = :id",
        ['id' => $accountId]
    );
    if (!$account) {
        updateStatus($statusFile, [
            'status' => 'error',
            'current_task' => 'Cuenta no encontrada',
        ]);
        exit(1);
    }

    $username = $account['username'];
    $domain   = $account['domain'];
    $homeDir  = $account['home_dir'];
    $step     = 0;
    $totalSize = 0;
    $dbList   = [];
    $errors   = [];

    // Create backup directory
    if (!is_dir($backupPath)) {
        @mkdir($backupPath, 0750, true);
    }
    if (!is_dir($backupPath)) {
        updateStatus($statusFile, [
            'status' => 'error',
            'current_task' => 'No se pudo crear el directorio de backup',
        ]);
        exit(1);
    }

    // ── Step: Archive files ─────────────────────────────────────
    if ($includeFiles) {
        $step++;

        if ($scope === 'full') {
            $archiveLabel = 'directorio completo';
            $sourceDir = $homeDir;
            $tarTarget = '.';
        } else {
            $archiveLabel = 'httpdocs/';
            $sourceDir = $homeDir;
            $tarTarget = 'httpdocs/';
        }

        updateStatus($statusFile, [
            'step' => $step,
            'current_task' => "Comprimiendo archivos ({$archiveLabel})...",
        ]);

        $archiveDir = ($scope === 'full') ? $homeDir : $homeDir . '/httpdocs';
        if (is_dir($archiveDir)) {
            $tarFile = $backupPath . '/files.tar.gz';

            // Build exclusion flags
            $excludeFlags = '';
            foreach ($defaultExclusions as $pattern) {
                $excludeFlags .= ' --exclude=' . escapeshellarg($pattern);
            }

            $cmd = sprintf(
                'tar czf %s -C %s %s %s 2>&1',
                escapeshellarg($tarFile),
                escapeshellarg($sourceDir),
                $excludeFlags,
                escapeshellarg($tarTarget)
            );
            $output = shell_exec($cmd);
            if (file_exists($tarFile)) {
                $totalSize += filesize($tarFile);
                updateStatus($statusFile, [
                    'current_task' => "Archivos comprimidos ({$archiveLabel}: " . fmtSize(filesize($tarFile)) . ')',
                ]);
            } else {
                $errors[] = "Error al crear tar.gz: {$output}";
            }
        } else {
            $errors[] = "Directorio no encontrado: {$archiveDir}";
        }
    }

    // ── Step: Dump databases ────────────────────────────────────
    if ($includeDatabases) {
        $databases = MuseDockPanel\Database::fetchAll(
            "SELECT * FROM hosting_databases WHERE account_id = :id",
            ['id' => $accountId]
        );

        if (!empty($databases)) {
            $dbDir = $backupPath . '/databases';
            @mkdir($dbDir, 0750, true);

            foreach ($databases as $db) {
                $step++;
                $dbName = $db['db_name'];
                $dbType = $db['db_type'] ?? 'mysql';

                updateStatus($statusFile, [
                    'step' => $step,
                    'current_task' => "Exportando base de datos: {$dbName} ({$dbType})...",
                ]);

                $dumpFile = $dbDir . '/' . $dbName . '.sql';

                if ($dbType === 'mysql') {
                    $mysqlCmd = buildMysqlDumpCmd();
                    if ($mysqlCmd) {
                        $cmd = sprintf('%s --single-transaction --quick %s > %s 2>&1', $mysqlCmd, escapeshellarg($dbName), escapeshellarg($dumpFile));
                        shell_exec($cmd);
                    } else {
                        $errors[] = "No se pudo determinar autenticacion MySQL para {$dbName}";
                    }
                } elseif ($dbType === 'pgsql') {
                    $cmd = sprintf('sudo -u postgres pg_dump %s > %s 2>&1', escapeshellarg($dbName), escapeshellarg($dumpFile));
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
        } else {
            $step++;
            updateStatus($statusFile, [
                'step' => $step,
                'current_task' => 'No hay bases de datos asociadas a esta cuenta',
            ]);
        }
    }

    // ── Step: Write metadata ────────────────────────────────────
    $step++;
    updateStatus($statusFile, [
        'step' => $step,
        'current_task' => 'Escribiendo metadata...',
    ]);

    $metadata = [
        'username' => $username,
        'domain' => $domain,
        'account_id' => $accountId,
        'date' => date('Y-m-d H:i:s'),
        'timestamp' => str_replace($username . '_', '', $backupName),
        'include_files' => $includeFiles,
        'include_databases' => $includeDatabases,
        'scope' => $scope,
        'exclusions' => $defaultExclusions,
        'file_size' => $totalSize,
        'databases' => $dbList,
    ];
    file_put_contents(
        $backupPath . '/metadata.json',
        json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    // Log
    MuseDockPanel\Services\LogService::log(
        'backup.create',
        $domain,
        "Backup creado: {$backupName} (" . fmtSize($totalSize) . ") scope={$scope}"
    );

    // ── Done ────────────────────────────────────────────────────
    updateStatus($statusFile, [
        'status' => 'completed',
        'step' => $step,
        'current_task' => 'Backup completado',
        'completed_at' => date('Y-m-d H:i:s'),
        'file_size' => $totalSize,
        'file_size_human' => fmtSize($totalSize),
        'errors' => $errors,
        'db_count' => count($dbList),
    ]);

} catch (\Throwable $e) {
    updateStatus($statusFile, [
        'status' => 'error',
        'current_task' => 'Error: ' . $e->getMessage(),
    ]);
    exit(1);
}

// ── Helpers ─────────────────────────────────────────────────────

function buildMysqlDumpCmd(): ?string
{
    $authMethod = MuseDockPanel\Env::get('MYSQL_AUTH_METHOD', 'socket');
    if ($authMethod === 'socket') {
        return 'mysqldump -u root';
    }
    if ($authMethod === 'password') {
        $pass = MuseDockPanel\Env::get('MYSQL_ROOT_PASS', '');
        return $pass ? 'mysqldump -u root -p' . escapeshellarg($pass) : null;
    }
    return null;
}
