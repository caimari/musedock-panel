<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;

/**
 * First-run setup wizard.
 * Shown only when no admin users exist in the database.
 */
class SetupController
{
    public static function needsSetup(): bool
    {
        try {
            $admin = Database::fetchOne("SELECT id FROM panel_admins LIMIT 1");
            return $admin === null;
        } catch (\Throwable) {
            // Database not ready — show setup
            return true;
        }
    }

    public function index(): void
    {
        if (!self::needsSetup()) {
            Router::redirect('/login');
            return;
        }

        // Check system requirements
        $checks = $this->runChecks();

        View::render('setup/index', [
            'pageTitle' => 'Setup',
            'checks' => $checks,
        ]);
    }

    public function install(): void
    {
        if (!self::needsSetup()) {
            Router::redirect('/login');
            return;
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $email = trim($_POST['email'] ?? '');

        // Validate
        if (empty($username) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{2,49}$/', $username)) {
            Flash::set('error', 'Username invalido (3-50 caracteres, alfanumerico).');
            Router::redirect('/setup');
            return;
        }

        if (strlen($password) < 8) {
            Flash::set('error', 'La contrasena debe tener al menos 8 caracteres.');
            Router::redirect('/setup');
            return;
        }

        if ($password !== $passwordConfirm) {
            Flash::set('error', 'Las contrasenas no coinciden.');
            Router::redirect('/setup');
            return;
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Email no valido.');
            Router::redirect('/setup');
            return;
        }

        try {
            // Try to create tables if they don't exist
            $schemaFile = PANEL_ROOT . '/database/schema.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                Database::connect()->exec($sql);
            }

            // Create admin user
            Database::insert('panel_admins', [
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'email' => $email ?: null,
                'role' => 'admin',
            ]);

            Flash::set('success', 'Panel instalado correctamente. Inicia sesion con tu cuenta de admin.');
            Router::redirect('/login');
        } catch (\Throwable $e) {
            Flash::set('error', 'Error durante la instalacion: ' . $e->getMessage());
            Router::redirect('/setup');
        }
    }

    private function runChecks(): array
    {
        $phpVer = PHP_VERSION;
        $checks = [
            [
                'name' => "PHP $phpVer",
                'ok' => version_compare($phpVer, '8.0.0', '>='),
                'detail' => version_compare($phpVer, '8.0.0', '>=') ? 'OK' : 'Se requiere PHP 8.0+',
            ],
            [
                'name' => 'Extension: pdo_pgsql',
                'ok' => extension_loaded('pdo_pgsql'),
                'detail' => extension_loaded('pdo_pgsql') ? 'OK' : 'Instalar: apt install php-pgsql',
            ],
            [
                'name' => 'Extension: curl',
                'ok' => extension_loaded('curl'),
                'detail' => extension_loaded('curl') ? 'OK' : 'Instalar: apt install php-curl',
            ],
            [
                'name' => 'Extension: mbstring',
                'ok' => extension_loaded('mbstring'),
                'detail' => extension_loaded('mbstring') ? 'OK' : 'Instalar: apt install php-mbstring',
            ],
        ];

        // Database connection
        $dbOk = false;
        $dbDetail = '';
        try {
            Database::connect();
            $dbOk = true;
            $dbDetail = 'Conectado';
        } catch (\Throwable $e) {
            $dbDetail = 'Error: ' . $e->getMessage();
        }
        $checks[] = ['name' => 'PostgreSQL', 'ok' => $dbOk, 'detail' => $dbDetail];

        // Caddy API
        $caddyOk = false;
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyUrl = $config['caddy']['api_url'] . '/config/';
        $ch = curl_init($caddyUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 3]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $caddyOk = $code >= 200 && $code < 400;
        $checks[] = ['name' => 'Caddy API', 'ok' => $caddyOk, 'detail' => $caddyOk ? 'Online' : 'No accesible en ' . $config['caddy']['api_url']];

        // Writable storage
        $storageOk = is_writable(PANEL_ROOT . '/storage');
        $checks[] = ['name' => 'Storage dir writable', 'ok' => $storageOk, 'detail' => $storageOk ? 'OK' : 'chmod 750 storage/'];

        // .env exists
        $envOk = file_exists(PANEL_ROOT . '/.env');
        $checks[] = ['name' => '.env file', 'ok' => $envOk, 'detail' => $envOk ? 'OK' : 'Copiar .env.example a .env y configurar'];

        return $checks;
    }
}
