<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Env;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Settings;
use MuseDockPanel\View;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\ReplicationService;
use MuseDockPanel\Services\SystemService;

class DatabaseController
{
    /**
     * List all databases: PG main (5432) + PG panel (5433) + MySQL
     */
    public function index(): void
    {
        // Panel-managed databases (for cross-referencing)
        $panelDbs = Database::fetchAll("
            SELECT d.*, a.username, a.domain
            FROM hosting_databases d
            JOIN hosting_accounts a ON a.id = d.account_id
            ORDER BY a.username, d.db_name
        ");

        $panelDbMap = [];
        foreach ($panelDbs as $db) {
            $panelDbMap[$db['db_name']] = $db;
        }

        // ─── PostgreSQL Main (port 5432) — hosting databases, replicable ─────
        $pgMainDatabases = $this->getPgDatabasesViaShell(5432);
        $pgMainReplication = [];
        $replRole = Settings::get('repl_pg_role', 'standalone');
        if ($replRole === 'master') {
            $replOutput = trim((string)shell_exec("sudo -u postgres psql -p 5432 -t -A -c \"SELECT count(*) FROM pg_stat_replication\" 2>/dev/null"));
            $slaveCount = is_numeric($replOutput) ? (int)$replOutput : 0;
            $pgMainReplication = ['role' => 'master', 'slaves' => $slaveCount, 'state' => $slaveCount > 0 ? 'streaming' : 'no_slaves'];
        } elseif ($replRole === 'slave') {
            $walStatus = trim((string)shell_exec("sudo -u postgres psql -p 5432 -t -A -c \"SELECT status FROM pg_stat_wal_receiver LIMIT 1\" 2>/dev/null"));
            $pgMainReplication = ['role' => 'slave', 'state' => $walStatus ?: 'disconnected'];
        }

        // ─── PostgreSQL Panel (port 5433) — panel database, not replicable ───
        $pgPanelDatabases = [];
        try {
            $pgPanelDatabases = Database::fetchAll("
                SELECT d.datname AS db_name,
                       pg_catalog.pg_get_userbyid(d.datdba) AS owner,
                       pg_catalog.pg_database_size(d.datname) AS size_bytes
                FROM pg_catalog.pg_database d
                WHERE d.datistemplate = false
                ORDER BY (d.datname = 'musedock_panel') DESC, d.datname
            ");
        } catch (\Throwable) {}

        // ─── MySQL: real databases ──────────────────────────
        $mysqlDatabases = [];
        $mysqlReplication = [];
        $mysqlAvailable = false;
        try {
            $mysqlPdo = ReplicationService::getMysqlPdo();
            if ($mysqlPdo) {
                $mysqlAvailable = true;
                $rows = $mysqlPdo->query("SHOW DATABASES")->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($rows as $dbName) {
                    $sizeBytes = 0;
                    try {
                        $sizeRow = $mysqlPdo->query(
                            "SELECT SUM(data_length + index_length) AS size_bytes
                             FROM information_schema.TABLES
                             WHERE table_schema = " . $mysqlPdo->quote($dbName)
                        )->fetch(\PDO::FETCH_ASSOC);
                        $sizeBytes = (int)($sizeRow['size_bytes'] ?? 0);
                    } catch (\Throwable) {}

                    $mysqlDatabases[] = [
                        'db_name' => $dbName,
                        'size_bytes' => $sizeBytes,
                    ];
                }

                // Check MySQL replication
                $replMysqlRole = Settings::get('repl_mysql_role', 'standalone');
                if ($replMysqlRole === 'master') {
                    try {
                        $masterStatus = $mysqlPdo->query("SHOW MASTER STATUS")->fetch(\PDO::FETCH_ASSOC);
                        $mysqlReplication = ['role' => 'master', 'file' => $masterStatus['File'] ?? '', 'position' => $masterStatus['Position'] ?? ''];
                    } catch (\Throwable) {
                        $mysqlReplication = ['role' => 'master'];
                    }
                } elseif ($replMysqlRole === 'slave') {
                    try {
                        $slaveStatus = $mysqlPdo->query("SHOW SLAVE STATUS")->fetch(\PDO::FETCH_ASSOC);
                        if (!$slaveStatus) {
                            $slaveStatus = $mysqlPdo->query("SHOW REPLICA STATUS")->fetch(\PDO::FETCH_ASSOC);
                        }
                        $mysqlReplication = [
                            'role' => 'slave',
                            'io_running' => $slaveStatus['Slave_IO_Running'] ?? $slaveStatus['Replica_IO_Running'] ?? 'No',
                            'sql_running' => $slaveStatus['Slave_SQL_Running'] ?? $slaveStatus['Replica_SQL_Running'] ?? 'No',
                        ];
                    } catch (\Throwable) {
                        $mysqlReplication = ['role' => 'slave'];
                    }
                }
            }
        } catch (\Throwable) {}

        // System databases
        $pgMainSystemDbs = ['postgres'];
        $pgPanelSystemDbs = ['postgres', 'musedock_panel'];
        $mysqlSystemDbs = ['mysql', 'information_schema', 'performance_schema', 'sys'];

        // Credentials flash
        $creds = Flash::get('db_credentials');
        if ($creds) {
            $creds = json_decode($creds, true);
        }

        // DB dump sync status (for slave servers)
        $dbSyncStatus = null;
        $clusterRole = Settings::get('cluster_role', 'standalone');
        if ($clusterRole === 'slave') {
            $dumpPath = Settings::get('filesync_db_dump_path', '/tmp/musedock-dumps');
            $manifestFile = $dumpPath . '/manifest.json';
            if (file_exists($manifestFile)) {
                $manifest = json_decode(file_get_contents($manifestFile), true);
                $manifestTime = filemtime($manifestFile);
                $dbSyncStatus = [
                    'has_dumps' => true,
                    'last_sync' => date('Y-m-d H:i:s', $manifestTime),
                    'ago' => time() - $manifestTime,
                    'databases' => $manifest ?: [],
                ];
            } else {
                $dbSyncStatus = ['has_dumps' => false];
            }
        }

        // Hosting accounts for associate modal
        $hostingAccounts = Database::fetchAll("
            SELECT id, username, domain
            FROM hosting_accounts
            WHERE status = 'active'
            ORDER BY domain
        ");

        View::render('databases/index', [
            'layout'              => 'main',
            'pageTitle'           => 'Bases de Datos',
            'pgMainDatabases'     => $pgMainDatabases,
            'pgPanelDatabases'    => $pgPanelDatabases,
            'mysqlDatabases'      => $mysqlDatabases,
            'mysqlAvailable'      => $mysqlAvailable,
            'pgMainReplication'   => $pgMainReplication,
            'mysqlReplication'    => $mysqlReplication,
            'panelDbMap'          => $panelDbMap,
            'pgMainSystemDbs'     => $pgMainSystemDbs,
            'pgPanelSystemDbs'    => $pgPanelSystemDbs,
            'mysqlSystemDbs'      => $mysqlSystemDbs,
            'totalPgMain'         => count($pgMainDatabases),
            'totalPgPanel'        => count($pgPanelDatabases),
            'totalMysql'          => count($mysqlDatabases),
            'creds'               => $creds,
            'dbSyncStatus'        => $dbSyncStatus,
            'clusterRole'         => $clusterRole,
            'hostingAccounts'     => $hostingAccounts,
            'dbBackups'           => Database::fetchAll("
                SELECT b.*, a.username AS admin_username
                FROM database_backups b
                LEFT JOIN panel_admins a ON a.id = b.created_by
                ORDER BY b.created_at DESC
                LIMIT 100
            "),
            'backupDir'           => Settings::get('db_backup_path', '/opt/musedock-panel/storage/db-backups'),
        ]);
    }

    /**
     * Query PostgreSQL databases via shell (sudo -u postgres psql)
     * Used for the main instance on a specific port.
     */
    private function getPgDatabasesViaShell(int $port): array
    {
        $query = "SELECT datname, pg_get_userbyid(datdba) AS owner, pg_database_size(datname) AS size_bytes FROM pg_database WHERE datistemplate = false ORDER BY datname";
        $cmd = sprintf(
            "sudo -u postgres psql -p %d -t -A -F '|' -c %s 2>/dev/null",
            $port,
            escapeshellarg($query)
        );
        $output = trim((string)shell_exec($cmd));
        if ($output === '') return [];

        $databases = [];
        foreach (explode("\n", $output) as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) >= 3) {
                $databases[] = [
                    'db_name'    => $parts[0],
                    'owner'      => $parts[1],
                    'size_bytes' => (int)$parts[2],
                ];
            }
        }
        return $databases;
    }

    /**
     * Show form to create a new database
     */
    public function create(): void
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es Slave. La creacion de bases de datos solo esta permitida en el Master.');
            Router::redirect('/databases');
            return;
        }

        $accounts = Database::fetchAll("
            SELECT id, username, domain
            FROM hosting_accounts
            WHERE status = 'active'
            ORDER BY username
        ");

        View::render('databases/create', [
            'layout' => 'main',
            'pageTitle' => 'Crear Base de Datos',
            'accounts' => $accounts,
        ]);
    }

    /**
     * Actually create the database + user (MySQL or PostgreSQL)
     */
    public function store(): void
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es Slave. La creacion de bases de datos solo esta permitida en el Master.');
            Router::redirect('/databases');
            return;
        }

        $accountId = (int) ($_POST['account_id'] ?? 0);
        $dbSuffix = trim($_POST['db_name'] ?? '');
        $dbType = trim($_POST['db_type'] ?? 'mysql');

        if (!in_array($dbType, ['mysql', 'pgsql'], true)) {
            $dbType = 'mysql';
        }

        if (empty($accountId) || empty($dbSuffix)) {
            Flash::set('error', 'Todos los campos son obligatorios.');
            Router::redirect('/databases/create');
            return;
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbSuffix)) {
            Flash::set('error', 'El nombre de la base de datos solo puede contener letras, numeros y guion bajo.');
            Router::redirect('/databases/create');
            return;
        }

        $account = Database::fetchOne("SELECT id, username, domain FROM hosting_accounts WHERE id = :id", ['id' => $accountId]);
        if (!$account) {
            Flash::set('error', 'Cuenta de hosting no encontrada.');
            Router::redirect('/databases/create');
            return;
        }

        $username = $account['username'];
        $fullDbName = $username . '_' . $dbSuffix;
        $fullDbUser = $username . '_' . $dbSuffix;

        if (strlen($fullDbName) > 64) {
            Flash::set('error', 'El nombre completo de la base de datos no puede exceder 64 caracteres. Actual: ' . strlen($fullDbName));
            Router::redirect('/databases/create');
            return;
        }

        if (strlen($fullDbUser) > 32) {
            Flash::set('error', 'El nombre de usuario de la base de datos no puede exceder 32 caracteres. Actual: ' . strlen($fullDbUser));
            Router::redirect('/databases/create');
            return;
        }

        $existing = Database::fetchOne("SELECT id FROM hosting_databases WHERE db_name = :name", ['name' => $fullDbName]);
        if ($existing) {
            Flash::set('error', "La base de datos '{$fullDbName}' ya existe.");
            Router::redirect('/databases/create');
            return;
        }

        $dbPassword = bin2hex(random_bytes(12));

        if ($dbType === 'pgsql') {
            $sqlUser = sprintf("CREATE USER %s WITH PASSWORD %s;", escapeshellarg($fullDbUser), escapeshellarg($dbPassword));
            $cmdUser = 'sudo -u postgres psql -c ' . escapeshellarg($sqlUser) . ' 2>&1';
            $outputUser = shell_exec($cmdUser);

            if ($outputUser !== null && stripos($outputUser, 'ERROR') !== false) {
                LogService::log('database.create.error', $fullDbName, "PostgreSQL error creating user: {$outputUser}");
                Flash::set('error', 'Error al crear el usuario en PostgreSQL: ' . $outputUser);
                Router::redirect('/databases/create');
                return;
            }

            $sqlDb = sprintf("CREATE DATABASE %s OWNER %s;", escapeshellarg($fullDbName), escapeshellarg($fullDbUser));
            $cmdDb = 'sudo -u postgres psql -c ' . escapeshellarg($sqlDb) . ' 2>&1';
            $outputDb = shell_exec($cmdDb);

            if ($outputDb !== null && stripos($outputDb, 'ERROR') !== false) {
                LogService::log('database.create.error', $fullDbName, "PostgreSQL error creating database: {$outputDb}");
                Flash::set('error', 'Error al crear la base de datos en PostgreSQL: ' . $outputDb);
                Router::redirect('/databases/create');
                return;
            }

            $sqlGrant = sprintf("GRANT ALL PRIVILEGES ON DATABASE %s TO %s;", escapeshellarg($fullDbName), escapeshellarg($fullDbUser));
            $cmdGrant = 'sudo -u postgres psql -c ' . escapeshellarg($sqlGrant) . ' 2>&1';
            $outputGrant = shell_exec($cmdGrant);

            if ($outputGrant !== null && stripos($outputGrant, 'ERROR') !== false) {
                LogService::log('database.create.error', $fullDbName, "PostgreSQL error granting privileges: {$outputGrant}");
                Flash::set('error', 'Error al asignar privilegios en PostgreSQL: ' . $outputGrant);
                Router::redirect('/databases/create');
                return;
            }

            $dbHost = 'localhost';
            $logType = 'PostgreSQL';
        } else {
            $mysqlCmd = $this->buildMysqlCommand();
            if ($mysqlCmd === null) {
                Flash::set('error', 'No se pudo determinar el metodo de autenticacion de MySQL. Verifica MYSQL_AUTH_METHOD en .env');
                Router::redirect('/databases/create');
                return;
            }

            $sqlCreate = sprintf("CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;", $this->quoteIdentifier($fullDbName));
            $sqlUser = sprintf("CREATE USER IF NOT EXISTS %s@'localhost' IDENTIFIED BY %s;", $this->quoteLiteral($fullDbUser), $this->quoteLiteral($dbPassword));
            $sqlGrant = sprintf("GRANT ALL PRIVILEGES ON %s.* TO %s@'localhost';", $this->quoteIdentifier($fullDbName), $this->quoteLiteral($fullDbUser));
            $sqlFlush = "FLUSH PRIVILEGES;";

            $fullSql = $sqlCreate . ' ' . $sqlUser . ' ' . $sqlGrant . ' ' . $sqlFlush;
            $cmd = $mysqlCmd . ' -e ' . escapeshellarg($fullSql) . ' 2>&1';
            $output = shell_exec($cmd);

            if ($output !== null && stripos($output, 'ERROR') !== false) {
                LogService::log('database.create.error', $fullDbName, "MySQL error: {$output}");
                Flash::set('error', 'Error al crear la base de datos en MySQL: ' . $output);
                Router::redirect('/databases/create');
                return;
            }

            $dbHost = 'localhost';
            $logType = 'MySQL';
        }

        Database::insert('hosting_databases', [
            'account_id' => $accountId,
            'db_name' => $fullDbName,
            'db_user' => $fullDbUser,
            'db_type' => $dbType,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        LogService::log('database.create', $fullDbName, "Created {$logType} database: {$fullDbName}, user: {$fullDbUser} for account {$username}");

        // Sync database registration to slaves
        $this->syncDatabasesForAccount($accountId, $account['domain']);

        Flash::set('success', "Base de datos '{$fullDbName}' creada exitosamente. Las credenciales se muestran abajo.");
        Flash::set('db_credentials', json_encode([
            'db_name' => $fullDbName,
            'db_user' => $fullDbUser,
            'db_pass' => $dbPassword,
            'db_host' => $dbHost,
            'db_type' => $dbType,
        ]));

        Router::redirect('/databases');
    }

    /**
     * Drop database + user, remove from panel (with password verification)
     */
    public function delete(array $params = []): void
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es Slave. Eliminar bases de datos solo esta permitido en el Master.');
            Router::redirect('/databases');
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        $db = Database::fetchOne("
            SELECT d.*, a.username, a.domain AS account_domain
            FROM hosting_databases d
            JOIN hosting_accounts a ON a.id = d.account_id
            WHERE d.id = :id
        ", ['id' => $id]);

        if (!$db) {
            Flash::set('error', 'Base de datos no encontrada.');
            Router::redirect('/databases');
            return;
        }

        $password = $_POST['password'] ?? '';
        if (empty($password)) {
            Flash::set('error', 'Debes ingresar tu contrasena de administrador para confirmar la eliminacion.');
            Router::redirect('/databases');
            return;
        }

        $admin = Database::fetchOne("SELECT password_hash FROM panel_admins WHERE id = :id", ['id' => $_SESSION['panel_user']['id'] ?? 0]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Flash::set('error', 'Contrasena incorrecta. La base de datos no fue eliminada.');
            Router::redirect('/databases');
            return;
        }

        $dbType = $db['db_type'] ?? 'mysql';

        if ($dbType === 'pgsql') {
            $sqlDropDb = sprintf("DROP DATABASE IF EXISTS %s;", escapeshellarg($db['db_name']));
            $cmdDropDb = 'sudo -u postgres psql -c ' . escapeshellarg($sqlDropDb) . ' 2>&1';
            $outputDropDb = shell_exec($cmdDropDb);

            if ($outputDropDb !== null && stripos($outputDropDb, 'ERROR') !== false) {
                LogService::log('database.delete.error', $db['db_name'], "PostgreSQL error: {$outputDropDb}");
                Flash::set('error', 'Error al eliminar la base de datos en PostgreSQL: ' . $outputDropDb);
                Router::redirect('/databases');
                return;
            }

            $sqlDropUser = sprintf("DROP USER IF EXISTS %s;", escapeshellarg($db['db_user']));
            $cmdDropUser = 'sudo -u postgres psql -c ' . escapeshellarg($sqlDropUser) . ' 2>&1';
            $outputDropUser = shell_exec($cmdDropUser);

            if ($outputDropUser !== null && stripos($outputDropUser, 'ERROR') !== false) {
                LogService::log('database.delete.error', $db['db_name'], "PostgreSQL error dropping user: {$outputDropUser}");
                Flash::set('error', 'Error al eliminar el usuario en PostgreSQL: ' . $outputDropUser);
                Router::redirect('/databases');
                return;
            }

            $logType = 'PostgreSQL';
        } else {
            $mysqlCmd = $this->buildMysqlCommand();
            if ($mysqlCmd === null) {
                Flash::set('error', 'No se pudo determinar el metodo de autenticacion de MySQL.');
                Router::redirect('/databases');
                return;
            }

            $sqlDrop = sprintf("DROP DATABASE IF EXISTS %s;", $this->quoteIdentifier($db['db_name']));
            $sqlDropUser = sprintf("DROP USER IF EXISTS %s@'localhost';", $this->quoteLiteral($db['db_user']));
            $sqlFlush = "FLUSH PRIVILEGES;";

            $fullSql = $sqlDrop . ' ' . $sqlDropUser . ' ' . $sqlFlush;
            $cmd = $mysqlCmd . ' -e ' . escapeshellarg($fullSql) . ' 2>&1';
            $output = shell_exec($cmd);

            if ($output !== null && stripos($output, 'ERROR') !== false) {
                LogService::log('database.delete.error', $db['db_name'], "MySQL error: {$output}");
                Flash::set('error', 'Error al eliminar la base de datos en MySQL: ' . $output);
                Router::redirect('/databases');
                return;
            }

            $logType = 'MySQL';
        }

        Database::delete('hosting_databases', 'id = :id', ['id' => $id]);

        // Sync database removal to slaves
        $this->syncDatabasesForAccount((int)$db['account_id'], $db['account_domain']);

        LogService::log('database.delete', $db['db_name'], "Deleted {$logType} database: {$db['db_name']}, user: {$db['db_user']} from account {$db['username']}");
        Flash::set('success', "Base de datos '{$db['db_name']}' eliminada exitosamente.");
        Router::redirect('/databases');
    }

    /**
     * Associate an external/orphan database to a hosting account
     */
    public function associate(): void
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es Slave. Asociar bases de datos solo esta permitido en el Master.');
            Router::redirect('/databases');
            return;
        }

        $dbName = trim($_POST['db_name'] ?? '');
        $dbType = trim($_POST['db_type'] ?? 'pgsql');
        $accountId = (int) ($_POST['account_id'] ?? 0);

        if (empty($dbName) || empty($accountId)) {
            Flash::set('error', 'Datos incompletos.');
            Router::redirect('/databases');
            return;
        }

        // Check not already registered
        $existing = Database::fetchOne("SELECT id FROM hosting_databases WHERE db_name = :n", ['n' => $dbName]);
        if ($existing) {
            Flash::set('error', "La base de datos '{$dbName}' ya esta registrada en el panel.");
            Router::redirect('/databases');
            return;
        }

        $account = Database::fetchOne("SELECT id, username, domain FROM hosting_accounts WHERE id = :id", ['id' => $accountId]);
        if (!$account) {
            Flash::set('error', 'Cuenta de hosting no encontrada.');
            Router::redirect('/databases');
            return;
        }

        // Detect the db_user (owner)
        $dbUser = $account['username'];
        if ($dbType === 'pgsql') {
            $ownerQuery = sprintf(
                "sudo -u postgres psql -p 5432 -t -A -c %s 2>/dev/null",
                escapeshellarg("SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = " . escapeshellarg($dbName))
            );
            $detectedOwner = trim((string)shell_exec($ownerQuery));
            if (!empty($detectedOwner)) {
                $dbUser = $detectedOwner;
            }
        }

        Database::insert('hosting_databases', [
            'account_id' => $accountId,
            'db_name'    => $dbName,
            'db_user'    => $dbUser,
            'db_type'    => $dbType,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        LogService::log('database.associate', $dbName, "Associated {$dbType} database '{$dbName}' to hosting {$account['domain']} ({$account['username']})");

        // Sync database association to slaves
        $this->syncDatabasesForAccount((int)$account['id'], $account['domain']);

        Flash::set('success', "Base de datos '{$dbName}' asociada correctamente al hosting {$account['domain']}.");
        Router::redirect('/databases');
    }

    /**
     * Get unassociated hosting accounts for AJAX
     */
    public function getAccounts(): void
    {
        header('Content-Type: application/json');
        $accounts = Database::fetchAll("
            SELECT id, username, domain
            FROM hosting_accounts
            WHERE status = 'active'
            ORDER BY domain
        ");
        echo json_encode($accounts);
        exit;
    }

    // ─── Database Backup Methods ──────────────────────────────────────

    /**
     * Get the backup directory path (configurable via settings)
     */
    private function getBackupDir(): string
    {
        return Settings::get('db_backup_path', '/opt/musedock-panel/storage/db-backups');
    }

    /**
     * Backup a single database
     */
    public function backup(): void
    {
        $dbName = trim($_POST['db_name'] ?? '');
        $dbType = trim($_POST['db_type'] ?? 'pgsql');

        if (empty($dbName)) {
            Flash::set('error', 'Nombre de base de datos requerido.');
            Router::redirect('/databases');
            return;
        }

        $backupDir = $this->getBackupDir();
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $filename = "{$dbName}_{$timestamp}.sql.gz";
        $filepath = "{$backupDir}/{$filename}";

        if ($dbType === 'pgsql') {
            $port = 5432;
            // Check if it's a panel DB (port 5433)
            if (in_array($dbName, ['musedock_panel'])) {
                $port = 5433;
            }
            $cmd = sprintf(
                'sudo -u postgres pg_dump -p %d -Fc %s 2>/dev/null | gzip > %s',
                $port,
                escapeshellarg($dbName),
                escapeshellarg($filepath)
            );
        } else {
            $mysqlCmd = $this->buildMysqlCommand();
            if ($mysqlCmd === null) {
                Flash::set('error', 'No se pudo determinar el método de autenticación de MySQL.');
                Router::redirect('/databases');
                return;
            }
            $cmd = sprintf(
                '%s --single-transaction --quick --complete-insert %s 2>/dev/null | gzip > %s',
                $mysqlCmd,
                escapeshellarg($dbName),
                escapeshellarg($filepath)
            );
        }

        shell_exec($cmd);

        if (!file_exists($filepath) || filesize($filepath) < 30) {
            // Cleanup empty/failed file
            if (file_exists($filepath)) unlink($filepath);
            Flash::set('error', "Error al crear el backup de '{$dbName}'. Verifica que la base de datos existe.");
            Router::redirect('/databases');
            return;
        }

        $fileSize = filesize($filepath);

        Database::insert('database_backups', [
            'db_name'    => $dbName,
            'db_type'    => $dbType,
            'filename'   => $filename,
            'file_size'  => $fileSize,
            'status'     => 'completed',
            'created_by' => $_SESSION['panel_user']['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        LogService::log('database.backup', $dbName, "Backup created: {$filename} (" . $this->formatBytes($fileSize) . ")");
        Flash::set('success', "Backup de '{$dbName}' creado exitosamente: {$filename} (" . $this->formatBytes($fileSize) . ")");
        Router::redirect('/databases');
    }

    /**
     * Backup all non-system databases
     */
    public function backupAll(): void
    {
        $backupDir = $this->getBackupDir();
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $pgSystemDbs = ['postgres', 'template0', 'template1'];
        $mysqlSystemDbs = ['mysql', 'information_schema', 'performance_schema', 'sys'];
        $count = 0;
        $errors = [];

        // Backup PostgreSQL databases (port 5432 + port 5433 panel)
        $pgPorts = [5432, 5433];
        foreach ($pgPorts as $port) {
            $pgDatabases = $this->getPgDatabasesViaShell($port);
            foreach ($pgDatabases as $db) {
                if (in_array($db['db_name'], $pgSystemDbs)) continue;

                $filename = "{$db['db_name']}_{$timestamp}.sql.gz";
                $filepath = "{$backupDir}/{$filename}";
                $cmd = sprintf(
                    'sudo -u postgres pg_dump -p %d -Fc %s 2>/dev/null | gzip > %s',
                    $port,
                    escapeshellarg($db['db_name']),
                    escapeshellarg($filepath)
                );
                shell_exec($cmd);

                if (file_exists($filepath) && filesize($filepath) >= 30) {
                    Database::insert('database_backups', [
                        'db_name'    => $db['db_name'],
                        'db_type'    => 'pgsql',
                        'filename'   => $filename,
                        'file_size'  => filesize($filepath),
                        'status'     => 'completed',
                        'created_by' => $_SESSION['panel_user']['id'] ?? null,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $count++;
                } else {
                    if (file_exists($filepath)) unlink($filepath);
                    $errors[] = $db['db_name'];
                }
            }
        }

        // Backup MySQL databases
        try {
            $mysqlPdo = ReplicationService::getMysqlPdo();
            if ($mysqlPdo) {
                $mysqlCmd = $this->buildMysqlCommand();
                if ($mysqlCmd) {
                    $rows = $mysqlPdo->query("SHOW DATABASES")->fetchAll(\PDO::FETCH_COLUMN);
                    foreach ($rows as $dbName) {
                        if (in_array($dbName, $mysqlSystemDbs)) continue;

                        $filename = "{$dbName}_{$timestamp}.sql.gz";
                        $filepath = "{$backupDir}/{$filename}";
                        $cmd = sprintf(
                            '%s --single-transaction --quick --complete-insert %s 2>/dev/null | gzip > %s',
                            $mysqlCmd,
                            escapeshellarg($dbName),
                            escapeshellarg($filepath)
                        );
                        shell_exec($cmd);

                        if (file_exists($filepath) && filesize($filepath) >= 30) {
                            Database::insert('database_backups', [
                                'db_name'    => $dbName,
                                'db_type'    => 'mysql',
                                'filename'   => $filename,
                                'file_size'  => filesize($filepath),
                                'status'     => 'completed',
                                'created_by' => $_SESSION['panel_user']['id'] ?? null,
                                'created_at' => date('Y-m-d H:i:s'),
                            ]);
                            $count++;
                        } else {
                            if (file_exists($filepath)) unlink($filepath);
                            $errors[] = $dbName;
                        }
                    }
                }
            }
        } catch (\Throwable) {}

        $msg = "{$count} backup(s) creados exitosamente.";
        if (!empty($errors)) {
            $msg .= ' Errores en: ' . implode(', ', $errors);
        }

        LogService::log('database.backup_all', 'all', "Backup all: {$count} databases backed up" . (!empty($errors) ? ', errors: ' . implode(', ', $errors) : ''));
        Flash::set(!empty($errors) ? 'warning' : 'success', $msg);
        Router::redirect('/databases');
    }

    /**
     * Download a backup file
     */
    public function downloadBackup(array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        $backup = Database::fetchOne("SELECT * FROM database_backups WHERE id = :id", ['id' => $id]);

        if (!$backup) {
            Flash::set('error', 'Backup no encontrado.');
            Router::redirect('/databases');
            return;
        }

        $filepath = $this->getBackupDir() . '/' . $backup['filename'];
        if (!file_exists($filepath)) {
            Flash::set('error', 'El archivo de backup no existe en el disco. Puede haber sido eliminado manualmente.');
            Router::redirect('/databases');
            return;
        }

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $backup['filename'] . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache');
        readfile($filepath);
        exit;
    }

    /**
     * Restore a database from backup (with password verification)
     */
    public function restoreBackup(array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        $password = $_POST['password'] ?? '';

        if (empty($password)) {
            Flash::set('error', 'Debes ingresar tu contraseña de administrador para confirmar la restauración.');
            Router::redirect('/databases');
            return;
        }

        $admin = Database::fetchOne("SELECT password_hash FROM panel_admins WHERE id = :id", ['id' => $_SESSION['panel_user']['id'] ?? 0]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Flash::set('error', 'Contraseña incorrecta.');
            Router::redirect('/databases');
            return;
        }

        $backup = Database::fetchOne("SELECT * FROM database_backups WHERE id = :id", ['id' => $id]);
        if (!$backup) {
            Flash::set('error', 'Backup no encontrado.');
            Router::redirect('/databases');
            return;
        }

        $filepath = $this->getBackupDir() . '/' . $backup['filename'];
        if (!file_exists($filepath)) {
            Flash::set('error', 'El archivo de backup no existe en el disco.');
            Router::redirect('/databases');
            return;
        }

        $dbName = $backup['db_name'];
        $dbType = $backup['db_type'];

        if ($dbType === 'pgsql') {
            $port = 5432;
            if ($dbName === 'musedock_panel') {
                $port = 5433;
            }
            // pg_dump -Fc format → pg_restore
            $cmd = sprintf(
                'gunzip -c %s | sudo -u postgres pg_restore -p %d -d %s --clean --if-exists 2>&1',
                escapeshellarg($filepath),
                $port,
                escapeshellarg($dbName)
            );
        } else {
            $mysqlCmd = $this->buildMysqlCommand();
            if ($mysqlCmd === null) {
                Flash::set('error', 'No se pudo determinar el método de autenticación de MySQL.');
                Router::redirect('/databases');
                return;
            }
            $cmd = sprintf(
                'gunzip -c %s | %s %s 2>&1',
                escapeshellarg($filepath),
                $mysqlCmd,
                escapeshellarg($dbName)
            );
        }

        $output = shell_exec($cmd);

        // Check for critical errors (ignore warnings)
        $hasError = false;
        if ($output !== null) {
            foreach (explode("\n", $output) as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (stripos($line, 'ERROR') !== false && stripos($line, 'does not exist') === false) {
                    $hasError = true;
                    break;
                }
            }
        }

        if ($hasError) {
            LogService::log('database.restore.error', $dbName, "Restore failed: {$output}");
            Flash::set('error', "Error al restaurar '{$dbName}': " . substr($output, 0, 500));
        } else {
            LogService::log('database.restore', $dbName, "Restored from backup: {$backup['filename']}");
            Flash::set('success', "Base de datos '{$dbName}' restaurada exitosamente desde {$backup['filename']}.");
        }

        Router::redirect('/databases');
    }

    /**
     * Delete a backup record and file
     */
    public function deleteBackup(array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        $backup = Database::fetchOne("SELECT * FROM database_backups WHERE id = :id", ['id' => $id]);

        if (!$backup) {
            Flash::set('error', 'Backup no encontrado.');
            Router::redirect('/databases');
            return;
        }

        $filepath = $this->getBackupDir() . '/' . $backup['filename'];
        if (file_exists($filepath)) {
            unlink($filepath);
        }

        Database::delete('database_backups', 'id = :id', ['id' => $id]);

        LogService::log('database.backup.delete', $backup['db_name'], "Deleted backup: {$backup['filename']}");
        Flash::set('success', "Backup '{$backup['filename']}' eliminado.");
        Router::redirect('/databases');
    }

    /**
     * Cleanup: reconcile database records with filesystem
     */
    public function cleanupBackups(): void
    {
        $backupDir = $this->getBackupDir();
        $records = Database::fetchAll("SELECT * FROM database_backups ORDER BY created_at");
        $removed = 0;

        // Remove records whose files no longer exist
        foreach ($records as $record) {
            $filepath = $backupDir . '/' . $record['filename'];
            if (!file_exists($filepath)) {
                Database::delete('database_backups', 'id = :id', ['id' => $record['id']]);
                $removed++;
            }
        }

        // Find orphan files (in filesystem but not in DB)
        $orphans = 0;
        if (is_dir($backupDir)) {
            $dbFilenames = array_column($records, 'filename');
            foreach (scandir($backupDir) as $file) {
                if ($file === '.' || $file === '..' || $file === '.gitkeep') continue;
                if (!in_array($file, $dbFilenames)) {
                    // Register orphan file in DB
                    $filepath = $backupDir . '/' . $file;
                    if (preg_match('/^(.+?)_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql\.gz$/', $file, $m)) {
                        $dbName = $m[1];
                        $dbType = 'pgsql'; // default assumption
                        Database::insert('database_backups', [
                            'db_name'    => $dbName,
                            'db_type'    => $dbType,
                            'filename'   => $file,
                            'file_size'  => filesize($filepath),
                            'status'     => 'completed',
                            'notes'      => 'Recuperado por cleanup',
                            'created_at' => date('Y-m-d H:i:s', filemtime($filepath)),
                        ]);
                        $orphans++;
                    }
                }
            }
        }

        $msg = "Cleanup completado. {$removed} registro(s) huérfano(s) eliminados, {$orphans} archivo(s) huérfano(s) registrados.";
        LogService::log('database.backup.cleanup', 'all', $msg);
        Flash::set('success', $msg);
        Router::redirect('/databases');
    }

    /**
     * Save backup settings (backup directory path)
     */
    public function saveBackupSettings(): void
    {
        $path = trim($_POST['db_backup_path'] ?? '');
        if (empty($path)) {
            Flash::set('error', 'La ruta de backups no puede estar vacía.');
            Router::redirect('/databases');
            return;
        }

        // Validate path is absolute
        if ($path[0] !== '/') {
            Flash::set('error', 'La ruta debe ser absoluta (comenzar con /).');
            Router::redirect('/databases');
            return;
        }

        // Try to create if not exists
        if (!is_dir($path)) {
            if (!@mkdir($path, 0750, true)) {
                Flash::set('error', "No se pudo crear el directorio: {$path}");
                Router::redirect('/databases');
                return;
            }
        }

        Settings::set('db_backup_path', $path);
        LogService::log('database.backup.settings', $path, "Backup directory changed to: {$path}");
        Flash::set('success', "Directorio de backups actualizado: {$path}");
        Router::redirect('/databases');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 2) . ' GB';
    }

    // ─── Cluster Sync ────────────────────────────────────────────────

    /**
     * Sync all database registrations for a hosting account to slave nodes.
     * This only syncs the panel record (hosting_databases table), not the actual
     * database data — that is handled by streaming replication or dump sync.
     */
    private function syncDatabasesForAccount(int $accountId, string $domain): void
    {
        if (Settings::get('cluster_role', 'standalone') !== 'master') return;

        $nodes = ClusterService::getNodes();
        if (empty($nodes)) return;

        $databases = Database::fetchAll(
            "SELECT db_name, db_user, db_type, created_at FROM hosting_databases WHERE account_id = :aid",
            ['aid' => $accountId]
        );

        foreach ($nodes as $node) {
            if (($node['role'] ?? '') !== 'slave') continue;
            ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                'hosting_action' => 'sync_databases',
                'hosting_data' => [
                    'main_domain' => $domain,
                    'databases'   => $databases,
                ],
            ]);
        }
    }

    // ─── MySQL Helpers ──────────────────────────────────────────────

    private function buildMysqlCommand(): ?string
    {
        $authMethod = Env::get('MYSQL_AUTH_METHOD', 'socket');
        if ($authMethod === 'socket') {
            return 'mysql -u root';
        }
        if ($authMethod === 'password') {
            $pass = Env::get('MYSQL_ROOT_PASS', '');
            if (empty($pass)) return null;
            return 'mysql -u root -p' . escapeshellarg($pass);
        }
        return null;
    }

    private function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "\\'", $value) . "'";
    }
}
