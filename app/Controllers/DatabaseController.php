<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Env;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Settings;
use MuseDockPanel\View;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\ReplicationService;

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
            SELECT d.*, a.username
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

        LogService::log('database.delete', $db['db_name'], "Deleted {$logType} database: {$db['db_name']}, user: {$db['db_user']} from account {$db['username']}");
        Flash::set('success', "Base de datos '{$db['db_name']}' eliminada exitosamente.");
        Router::redirect('/databases');
    }

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
