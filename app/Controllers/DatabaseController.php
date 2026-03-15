<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Env;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;
use MuseDockPanel\Services\LogService;

class DatabaseController
{
    /**
     * List all databases grouped by account, including system DB
     */
    public function index(): void
    {
        $databases = Database::fetchAll("
            SELECT d.*, a.username, a.domain
            FROM hosting_databases d
            JOIN hosting_accounts a ON a.id = d.account_id
            ORDER BY a.username, d.db_name
        ");

        // Group by account
        $grouped = [];
        foreach ($databases as $db) {
            $key = $db['account_id'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'account_id' => $db['account_id'],
                    'username' => $db['username'],
                    'domain' => $db['domain'],
                    'databases' => [],
                ];
            }
            $db['is_system'] = false;
            $grouped[$key]['databases'][] = $db;
        }

        // Add system database (musedock_panel) as a read-only entry
        $systemDbName = Env::get('DB_NAME', 'musedock_panel');
        $systemDbUser = Env::get('DB_USER', 'musedock_panel');
        $systemDb = [
            'id' => null,
            'db_name' => $systemDbName,
            'db_user' => $systemDbUser,
            'db_type' => 'pgsql',
            'created_at' => null,
            'is_system' => true,
        ];

        View::render('databases/index', [
            'layout' => 'main',
            'pageTitle' => 'Bases de Datos',
            'grouped' => $grouped,
            'systemDb' => $systemDb,
            'totalDbs' => count($databases) + 1, // +1 for system DB
        ]);
    }

    /**
     * Show form to create a new database
     */
    public function create(): void
    {
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
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $dbSuffix = trim($_POST['db_name'] ?? '');
        $dbType = trim($_POST['db_type'] ?? 'mysql');

        // Validate db_type
        if (!in_array($dbType, ['mysql', 'pgsql'], true)) {
            $dbType = 'mysql';
        }

        if (empty($accountId) || empty($dbSuffix)) {
            Flash::set('error', 'Todos los campos son obligatorios.');
            Router::redirect('/databases/create');
            return;
        }

        // Validate db name: alphanumeric + underscore only
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbSuffix)) {
            Flash::set('error', 'El nombre de la base de datos solo puede contener letras, numeros y guion bajo.');
            Router::redirect('/databases/create');
            return;
        }

        // Get account
        $account = Database::fetchOne("SELECT id, username, domain FROM hosting_accounts WHERE id = :id", ['id' => $accountId]);
        if (!$account) {
            Flash::set('error', 'Cuenta de hosting no encontrada.');
            Router::redirect('/databases/create');
            return;
        }

        $username = $account['username'];
        $fullDbName = $username . '_' . $dbSuffix;
        $fullDbUser = $username . '_' . $dbSuffix;

        // Validate max length
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

        // Check if database already exists in panel
        $existing = Database::fetchOne("SELECT id FROM hosting_databases WHERE db_name = :name", ['name' => $fullDbName]);
        if ($existing) {
            Flash::set('error', "La base de datos '{$fullDbName}' ya existe.");
            Router::redirect('/databases/create');
            return;
        }

        // Generate random password
        $dbPassword = bin2hex(random_bytes(12));

        if ($dbType === 'pgsql') {
            // Create PostgreSQL database + user via shell
            $sqlUser = sprintf(
                "CREATE USER %s WITH PASSWORD %s;",
                escapeshellarg($fullDbUser),
                escapeshellarg($dbPassword)
            );
            $cmdUser = 'sudo -u postgres psql -c ' . escapeshellarg($sqlUser) . ' 2>&1';
            $outputUser = shell_exec($cmdUser);

            if ($outputUser !== null && stripos($outputUser, 'ERROR') !== false) {
                LogService::log('database.create.error', $fullDbName, "PostgreSQL error creating user: {$outputUser}");
                Flash::set('error', 'Error al crear el usuario en PostgreSQL: ' . $outputUser);
                Router::redirect('/databases/create');
                return;
            }

            $sqlDb = sprintf(
                "CREATE DATABASE %s OWNER %s;",
                escapeshellarg($fullDbName),
                escapeshellarg($fullDbUser)
            );
            $cmdDb = 'sudo -u postgres psql -c ' . escapeshellarg($sqlDb) . ' 2>&1';
            $outputDb = shell_exec($cmdDb);

            if ($outputDb !== null && stripos($outputDb, 'ERROR') !== false) {
                LogService::log('database.create.error', $fullDbName, "PostgreSQL error creating database: {$outputDb}");
                Flash::set('error', 'Error al crear la base de datos en PostgreSQL: ' . $outputDb);
                Router::redirect('/databases/create');
                return;
            }

            $sqlGrant = sprintf(
                "GRANT ALL PRIVILEGES ON DATABASE %s TO %s;",
                escapeshellarg($fullDbName),
                escapeshellarg($fullDbUser)
            );
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
            // Create MySQL database + user via shell
            $mysqlCmd = $this->buildMysqlCommand();
            if ($mysqlCmd === null) {
                Flash::set('error', 'No se pudo determinar el metodo de autenticacion de MySQL. Verifica MYSQL_AUTH_METHOD en .env');
                Router::redirect('/databases/create');
                return;
            }

            $sqlCreate = sprintf(
                "CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
                $this->quoteIdentifier($fullDbName)
            );
            $sqlUser = sprintf(
                "CREATE USER IF NOT EXISTS %s@'localhost' IDENTIFIED BY %s;",
                $this->quoteLiteral($fullDbUser),
                $this->quoteLiteral($dbPassword)
            );
            $sqlGrant = sprintf(
                "GRANT ALL PRIVILEGES ON %s.* TO %s@'localhost';",
                $this->quoteIdentifier($fullDbName),
                $this->quoteLiteral($fullDbUser)
            );
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

        // Save to panel database
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

        // Password verification
        $password = $_POST['password'] ?? '';
        if (empty($password)) {
            Flash::set('error', 'Debes ingresar tu contrasena de administrador para confirmar la eliminacion.');
            Router::redirect('/databases');
            return;
        }

        $admin = Database::fetchOne("SELECT password_hash FROM panel_admins WHERE id = :id", ['id' => $_SESSION['admin_id']]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Flash::set('error', 'Contrasena incorrecta. La base de datos no fue eliminada.');
            Router::redirect('/databases');
            return;
        }

        $dbType = $db['db_type'] ?? 'mysql';

        if ($dbType === 'pgsql') {
            // Drop PostgreSQL database and user
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
            // Drop MySQL database and user
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

        // Remove from panel database
        Database::delete('hosting_databases', 'id = :id', ['id' => $id]);

        LogService::log('database.delete', $db['db_name'], "Deleted {$logType} database: {$db['db_name']}, user: {$db['db_user']} from account {$db['username']}");
        Flash::set('success', "Base de datos '{$db['db_name']}' eliminada exitosamente.");
        Router::redirect('/databases');
    }

    /**
     * Build the mysql command prefix based on auth method
     */
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

    /**
     * Quote a MySQL identifier (backtick-escaped)
     */
    private function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Quote a MySQL string literal (single-quote-escaped)
     */
    private function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "\\'", $value) . "'";
    }
}
