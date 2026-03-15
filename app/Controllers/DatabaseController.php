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
     * List all databases grouped by account
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
            $grouped[$key]['databases'][] = $db;
        }

        View::render('databases/index', [
            'layout' => 'main',
            'pageTitle' => 'Bases de Datos',
            'grouped' => $grouped,
            'totalDbs' => count($databases),
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
     * Actually create the MySQL database + user
     */
    public function store(): void
    {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $dbSuffix = trim($_POST['db_name'] ?? '');

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

        // Validate max length (MySQL limit: 64 chars)
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

        // Create MySQL database + user via shell
        $mysqlCmd = $this->buildMysqlCommand();
        if ($mysqlCmd === null) {
            Flash::set('error', 'No se pudo determinar el metodo de autenticacion de MySQL. Verifica MYSQL_AUTH_METHOD en .env');
            Router::redirect('/databases/create');
            return;
        }

        // SQL commands
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

        // Check if there was an error (basic check)
        if ($output !== null && stripos($output, 'ERROR') !== false) {
            LogService::log('database.create.error', $fullDbName, "MySQL error: {$output}");
            Flash::set('error', 'Error al crear la base de datos en MySQL: ' . $output);
            Router::redirect('/databases/create');
            return;
        }

        // Save to panel database
        Database::insert('hosting_databases', [
            'account_id' => $accountId,
            'db_name' => $fullDbName,
            'db_user' => $fullDbUser,
            'db_type' => 'mysql',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        LogService::log('database.create', $fullDbName, "Created MySQL database: {$fullDbName}, user: {$fullDbUser} for account {$username}");

        Flash::set('success', "Base de datos '{$fullDbName}' creada exitosamente. Las credenciales se muestran abajo.");
        // Store password in a separate flash so the view can show a copyable block
        Flash::set('db_credentials', json_encode([
            'db_name' => $fullDbName,
            'db_user' => $fullDbUser,
            'db_pass' => $dbPassword,
            'db_host' => 'localhost',
        ]));

        Router::redirect('/databases');
    }

    /**
     * Drop MySQL database + user, remove from panel
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

        $mysqlCmd = $this->buildMysqlCommand();
        if ($mysqlCmd === null) {
            Flash::set('error', 'No se pudo determinar el metodo de autenticacion de MySQL.');
            Router::redirect('/databases');
            return;
        }

        // Drop database and user
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

        // Remove from panel database
        Database::delete('hosting_databases', 'id = :id', ['id' => $id]);

        LogService::log('database.delete', $db['db_name'], "Deleted MySQL database: {$db['db_name']}, user: {$db['db_user']} from account {$db['username']}");
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
