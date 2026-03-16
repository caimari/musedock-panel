<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Auth;
use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Settings;
use MuseDockPanel\View;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\SystemService;
use MuseDockPanel\Services\LogService;

class AccountController
{
    public function index(): void
    {
        $accounts = Database::fetchAll(
            "SELECT h.*, c.name as customer_name, c.email as customer_email
             FROM hosting_accounts h
             LEFT JOIN customers c ON c.id = h.customer_id
             ORDER BY h.created_at DESC"
        );

        // Update disk usage for each account
        foreach ($accounts as &$acc) {
            $acc['disk_used_mb'] = SystemService::getDiskUsage($acc['home_dir']);
        }

        View::render('accounts/index', [
            'layout' => 'main',
            'pageTitle' => 'Hosting Accounts',
            'accounts' => $accounts,
        ]);
    }

    public function create(): void
    {
        $customers = Database::fetchAll("SELECT id, name, email, company FROM customers WHERE status = 'active' ORDER BY name");

        View::render('accounts/create', [
            'layout' => 'main',
            'pageTitle' => 'Create Hosting Account',
            'customers' => $customers,
        ]);
    }

    public function store(): void
    {
        // Block hosting creation on slave nodes
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es Slave. La creacion de hostings solo esta permitida en el Master.');
            Router::redirect('/accounts');
            return;
        }

        $domain = trim($_POST['domain'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $shell = $_POST['shell'] ?? '/usr/sbin/nologin';
        $customerId = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : null;
        $description = trim($_POST['description'] ?? '');
        $diskQuota = (int) ($_POST['disk_quota_mb'] ?? 1024);
        $phpVersion = $_POST['php_version'] ?? '8.3';

        // Validation
        if (empty($domain) || empty($username) || empty($password)) {
            Flash::set('error', 'Dominio, usuario y password son obligatorios.');
            Router::redirect('/accounts/create');
            return;
        }

        // Validate username (Linux compatible)
        if (!preg_match('/^[a-z][a-z0-9_]{2,30}$/', $username)) {
            Flash::set('error', 'El usuario debe empezar por letra minúscula, solo a-z, 0-9 y _ (3-31 caracteres).');
            Router::redirect('/accounts/create');
            return;
        }

        // Check unique
        $existing = Database::fetchOne("SELECT id FROM hosting_accounts WHERE domain = :d OR username = :u", ['d' => $domain, 'u' => $username]);
        if ($existing) {
            Flash::set('error', 'El dominio o usuario ya existe.');
            Router::redirect('/accounts/create');
            return;
        }

        if (strlen($password) < 8) {
            Flash::set('error', 'El password debe tener al menos 8 caracteres.');
            Router::redirect('/accounts/create');
            return;
        }

        // Validate shell
        if (!in_array($shell, ['/bin/bash', '/usr/sbin/nologin'])) {
            $shell = '/usr/sbin/nologin';
        }

        $homeDir = "/var/www/vhosts/{$domain}";
        $documentRoot = "{$homeDir}/httpdocs";
        $fpmSocket = "unix//run/php/php{$phpVersion}-fpm-{$username}.sock";

        try {
            // 1. Create system user and directories
            $result = SystemService::createAccount($username, $domain, $homeDir, $documentRoot, $phpVersion, $password, $shell);

            if (!$result['success']) {
                Flash::set('error', 'Error del sistema: ' . $result['error']);
                Router::redirect('/accounts/create');
                return;
            }

            // 2. Save to database
            $id = Database::insert('hosting_accounts', [
                'customer_id' => $customerId,
                'domain' => $domain,
                'username' => $username,
                'system_uid' => $result['uid'] ?? null,
                'home_dir' => $homeDir,
                'document_root' => $documentRoot,
                'php_version' => $phpVersion,
                'fpm_socket' => $fpmSocket,
                'disk_quota_mb' => $diskQuota,
                'caddy_route_id' => $result['caddy_route_id'] ?? null,
                'description' => $description,
                'shell' => $shell,
            ]);

            // 3. Add primary domain
            Database::insert('hosting_domains', [
                'account_id' => $id,
                'domain' => $domain,
                'is_primary' => true,
            ]);

            LogService::log('account.create', $domain, "Created hosting account: {$username}@{$domain}");

            // Sync to cluster nodes if master
            if (Settings::get('cluster_role', 'standalone') === 'master') {
                $nodes = ClusterService::getNodes();
                foreach ($nodes as $node) {
                    ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                        'hosting_action' => 'create_hosting',
                        'hosting_data' => [
                            'username' => $username,
                            'domain' => $domain,
                            'home_dir' => $homeDir,
                            'document_root' => $documentRoot,
                            'php_version' => $phpVersion,
                            'password' => $password,
                            'shell' => $shell,
                        ],
                    ]);
                }
            }

            Flash::set('success', "Cuenta creada: {$domain}");
            Router::redirect('/accounts');

        } catch (\Throwable $e) {
            Flash::set('error', 'Error: ' . $e->getMessage());
            Router::redirect('/accounts/create');
        }
    }

    public function show(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        $domains = Database::fetchAll("SELECT * FROM hosting_domains WHERE account_id = :id", ['id' => $params['id']]);
        $databases = Database::fetchAll("SELECT * FROM hosting_databases WHERE account_id = :id", ['id' => $params['id']]);
        $account['disk_used_mb'] = SystemService::getDiskUsage($account['home_dir']);

        View::render('accounts/show', [
            'layout' => 'main',
            'pageTitle' => $account['domain'],
            'account' => $account,
            'domains' => $domains,
            'databases' => $databases,
        ]);
    }

    public function edit(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        // Parse PHP settings from pool conf file
        $phpSettings = [
            'memory_limit' => '128M',
            'upload_max_filesize' => '2M',
            'post_max_size' => '8M',
            'max_execution_time' => '30',
            'max_input_vars' => '1000',
            'open_basedir' => '',
        ];

        $poolFile = "/etc/php/{$account['php_version']}/fpm/pool.d/{$account['username']}.conf";
        if (file_exists($poolFile)) {
            $poolContent = file_get_contents($poolFile);
            if ($poolContent !== false) {
                // Match php_admin_value[key] = value and php_value[key] = value
                if (preg_match_all('/^php(?:_admin)?_value\[([^\]]+)\]\s*=\s*(.+)$/m', $poolContent, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $key = trim($match[1]);
                        $val = trim($match[2]);
                        if (array_key_exists($key, $phpSettings)) {
                            $phpSettings[$key] = $val;
                        }
                    }
                }
            }
        }

        View::render('accounts/edit', [
            'layout' => 'main',
            'pageTitle' => 'Editar: ' . $account['domain'],
            'account' => $account,
            'phpSettings' => $phpSettings,
            'poolFileExists' => file_exists($poolFile),
        ]);
    }

    public function update(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        $description = trim($_POST['description'] ?? '');
        $diskQuota = (int) ($_POST['disk_quota_mb'] ?? $account['disk_quota_mb']);
        $shell = $_POST['shell'] ?? $account['shell'] ?? '/usr/sbin/nologin';
        $docRootRelative = trim($_POST['document_root_relative'] ?? '');
        $phpVersion = trim($_POST['php_version'] ?? $account['php_version']);

        // Validate shell
        $allowedShells = ['/bin/bash', '/usr/sbin/nologin', '/bin/false'];
        if (!in_array($shell, $allowedShells)) $shell = '/usr/sbin/nologin';

        // Update shell on Linux if changed
        $currentShell = $account['shell'] ?? '/usr/sbin/nologin';
        if ($shell !== $currentShell) {
            SystemService::changeShell($account['username'], $shell);
            LogService::log('account.shell', $account['domain'], "Shell changed: {$currentShell} -> {$shell}");
        }

        // Handle PHP version change
        $effectivePhpVersion = $account['php_version'];
        if ($phpVersion !== $account['php_version']) {
            // Validate that the version is installed
            if (is_dir("/etc/php/{$phpVersion}/fpm")) {
                // Remove old FPM pool
                $oldPoolFile = "/etc/php/{$account['php_version']}/fpm/pool.d/{$account['username']}.conf";
                if (file_exists($oldPoolFile)) {
                    shell_exec(sprintf('rm -f %s 2>&1', escapeshellarg($oldPoolFile)));
                    shell_exec("systemctl reload php{$account['php_version']}-fpm 2>&1");
                }

                // Create new FPM pool with new PHP version
                $newSocket = SystemService::createFpmPool($account['username'], $phpVersion, $account['home_dir']);

                // Update Caddy route with new socket
                SystemService::updateCaddyDocumentRoot(
                    $account['domain'], $account['document_root'], $account['username'], $phpVersion
                );

                $effectivePhpVersion = $phpVersion;
                LogService::log('account.php', $account['domain'], "PHP version changed: {$account['php_version']} -> {$phpVersion}");
            }
        }

        // Handle document root change
        $dbFields = [
            'description' => $description,
            'disk_quota_mb' => $diskQuota,
            'shell' => $shell,
            'php_version' => $effectivePhpVersion,
            'fpm_socket' => "unix//run/php/php{$effectivePhpVersion}-fpm-{$account['username']}.sock",
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (!empty($docRootRelative)) {
            // Sanitize: only allow alphanumeric, hyphens, underscores, slashes, dots (no ..)
            $docRootRelative = trim($docRootRelative, '/');
            $docRootRelative = preg_replace('#/+#', '/', $docRootRelative);
            // Reject any path traversal attempts
            if (preg_match('/\.\./', $docRootRelative) || !preg_match('#^[a-zA-Z0-9/_.-]+$#', $docRootRelative)) {
                Flash::set('error', 'Ruta de document root no valida.');
                Router::redirect('/accounts/' . $account['id'] . '/edit');
                return;
            }

            $newDocRoot = $account['home_dir'] . '/' . $docRootRelative;
            // Verify resolved path stays within home dir
            $realHome = realpath($account['home_dir']);
            if ($realHome && is_dir($newDocRoot) && strpos(realpath($newDocRoot), $realHome) !== 0) {
                Flash::set('error', 'Ruta de document root fuera del directorio home.');
                Router::redirect('/accounts/' . $account['id'] . '/edit');
                return;
            }
            $oldDocRoot = $account['document_root'];

            if ($newDocRoot !== $oldDocRoot) {
                // Verify the directory exists
                if (!is_dir($newDocRoot)) {
                    // Create it if it doesn't exist
                    shell_exec(sprintf('mkdir -p %s 2>&1', escapeshellarg($newDocRoot)));
                    shell_exec(sprintf('chown %s:www-data %s 2>&1', escapeshellarg($account['username']), escapeshellarg($newDocRoot)));
                }

                // Update Caddy route with new document root
                $newFpmSocket = SystemService::updateCaddyDocumentRoot(
                    $account['domain'], $newDocRoot, $account['username'], $effectivePhpVersion
                );

                $dbFields['document_root'] = $newDocRoot;
                LogService::log('account.docroot', $account['domain'], "Document root changed: {$oldDocRoot} -> {$newDocRoot}");
            }
        }

        Database::update('hosting_accounts', $dbFields, 'id = :id', ['id' => $params['id']]);

        LogService::log('account.update', $account['domain'], "Updated account settings");
        Flash::set('success', 'Cuenta actualizada.');
        Router::redirect('/accounts/' . $params['id']);
    }

    public function renameUser(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        $newUsername = trim($_POST['new_username'] ?? '');

        if (empty($newUsername)) {
            Flash::set('error', 'El nuevo nombre de usuario es obligatorio.');
            Router::redirect('/accounts/' . $params['id'] . '/edit');
            return;
        }

        if (!preg_match('/^[a-z][a-z0-9_]{2,30}$/', $newUsername)) {
            Flash::set('error', 'El usuario debe empezar por letra minuscula, solo a-z, 0-9 y _ (3-31 caracteres).');
            Router::redirect('/accounts/' . $params['id'] . '/edit');
            return;
        }

        if ($newUsername === $account['username']) {
            Flash::set('error', 'El nuevo usuario es igual al actual.');
            Router::redirect('/accounts/' . $params['id'] . '/edit');
            return;
        }

        // Check if new username already exists (in DB or Linux)
        $existing = Database::fetchOne("SELECT id FROM hosting_accounts WHERE username = :u AND id != :id", ['u' => $newUsername, 'id' => $params['id']]);
        if ($existing) {
            Flash::set('error', "El usuario '{$newUsername}' ya existe en otra cuenta.");
            Router::redirect('/accounts/' . $params['id'] . '/edit');
            return;
        }

        $linuxCheck = shell_exec("id -u {$newUsername} 2>/dev/null");
        if (!empty(trim($linuxCheck ?? ''))) {
            Flash::set('error', "El usuario Linux '{$newUsername}' ya existe en el sistema.");
            Router::redirect('/accounts/' . $params['id'] . '/edit');
            return;
        }

        $oldUsername = $account['username'];
        $result = SystemService::renameUser($oldUsername, $newUsername, $account['domain'], $account['php_version']);

        if (!$result['success']) {
            Flash::set('error', $result['error']);
            Router::redirect('/accounts/' . $params['id'] . '/edit');
            return;
        }

        // Update database
        $fpmSocket = $result['fpm_socket'] ?? $account['fpm_socket'];
        $caddyRouteId = $result['caddy_route_id'] ?? $account['caddy_route_id'];

        Database::update('hosting_accounts', [
            'username' => $newUsername,
            'fpm_socket' => $fpmSocket,
            'caddy_route_id' => $caddyRouteId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $params['id']]);

        // Update hosting_databases user references
        Database::query("UPDATE hosting_databases SET db_user = :new WHERE db_user = :old", ['new' => $newUsername, 'old' => $oldUsername]);

        $warnings = '';
        if (!empty($result['warnings'])) {
            $warnings = ' Advertencias: ' . implode(', ', $result['warnings']);
        }

        LogService::log('account.rename', $account['domain'], "User renamed: {$oldUsername} -> {$newUsername}");
        Flash::set('success', "Usuario renombrado: {$oldUsername} -> {$newUsername}. Archivos, FPM y Caddy actualizados.{$warnings}");
        Router::redirect('/accounts/' . $params['id'] . '/edit');
    }

    public function changePassword(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        $password = $_POST['password'] ?? '';
        if (strlen($password) < 8) {
            Flash::set('error', 'El password debe tener al menos 8 caracteres.');
            Router::redirect('/accounts/' . $params['id'] . '/edit');
            return;
        }

        SystemService::setUserPassword($account['username'], $password);
        LogService::log('account.password', $account['domain'], "Changed password for user: {$account['username']}");
        Flash::set('success', "Password cambiado para {$account['username']}.");
        Router::redirect('/accounts/' . $params['id'] . '/edit');
    }

    public function renewSsl(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        // Force Caddy to re-obtain the certificate by removing and re-adding the route
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];
        $routeId = $account['caddy_route_id'] ?? "hosting-{$account['username']}";

        // Delete existing route
        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($ch);
        curl_close($ch);

        // Re-add the route (this triggers new certificate)
        $newRouteId = SystemService::addCaddyRoute($account['domain'], $account['document_root'], $account['username'], $account['php_version']);

        if ($newRouteId) {
            LogService::log('account.ssl', $account['domain'], "SSL certificate renewal triggered");
            Flash::set('success', "Renovación SSL iniciada para {$account['domain']}. Si el DNS apunta a este servidor, el certificado se obtendrá en segundos.");
        } else {
            Flash::set('error', 'Error al recrear la ruta Caddy. Revisa los logs.');
        }

        Router::redirect('/accounts/' . $params['id']);
    }

    public function suspend(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        SystemService::suspendAccount($account['username'], $account['fpm_socket'], $account['domain']);
        Database::update('hosting_accounts', ['status' => 'suspended', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $params['id']]);

        LogService::log('account.suspend', $account['domain'], "Suspended account");

        // Sync suspension to cluster nodes
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            foreach (ClusterService::getNodes() as $node) {
                ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                    'hosting_action' => 'suspend_hosting',
                    'hosting_data' => [
                        'username' => $account['username'],
                        'domain' => $account['domain'],
                    ],
                ]);
            }
        }

        Flash::set('warning', "Cuenta {$account['domain']} suspendida. Se muestra pagina de mantenimiento.");
        Router::redirect('/accounts/' . $params['id']);
    }

    public function activate(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        SystemService::activateAccount($account['username'], $account['fpm_socket'], $account['domain'], $account['document_root'], $account['php_version']);
        Database::update('hosting_accounts', ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $params['id']]);

        LogService::log('account.activate', $account['domain'], "Activated account");

        // Sync activation to cluster nodes
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            foreach (ClusterService::getNodes() as $node) {
                ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                    'hosting_action' => 'activate_hosting',
                    'hosting_data' => [
                        'username' => $account['username'],
                        'domain' => $account['domain'],
                        'document_root' => $account['document_root'],
                        'php_version' => $account['php_version'] ?? '8.3',
                    ],
                ]);
            }
        }

        Flash::set('success', "Cuenta {$account['domain']} activada. Ruta Caddy restaurada.");
        Router::redirect('/accounts/' . $params['id']);
    }

    // ================================================================
    // Import existing hostings
    // ================================================================

    public function importList(): void
    {
        $orphans = $this->discoverOrphanVhosts();

        View::render('accounts/import', [
            'layout' => 'main',
            'pageTitle' => 'Importar Hostings Existentes',
            'orphans' => $orphans,
        ]);
    }

    public function importStore(): void
    {
        $domain = trim($_POST['domain'] ?? '');
        if (empty($domain)) {
            Flash::set('error', 'Dominio no especificado.');
            Router::redirect('/accounts/import');
            return;
        }

        // Check not already registered
        $existing = Database::fetchOne("SELECT id FROM hosting_accounts WHERE domain = :d", ['d' => $domain]);
        if ($existing) {
            Flash::set('error', "El dominio {$domain} ya esta registrado.");
            Router::redirect('/accounts/import');
            return;
        }

        $homeDir = "/var/www/vhosts/{$domain}";
        if (!is_dir($homeDir)) {
            Flash::set('error', "El directorio {$homeDir} no existe.");
            Router::redirect('/accounts/import');
            return;
        }

        // Detect owner
        $stat = stat($homeDir);
        $ownerInfo = posix_getpwuid($stat['uid']);
        $username = $ownerInfo ? $ownerInfo['name'] : null;
        $uid = $stat['uid'];
        $shell = $ownerInfo ? ($ownerInfo['shell'] ?? '/usr/sbin/nologin') : '/usr/sbin/nologin';

        if (!$username) {
            Flash::set('error', "No se pudo detectar el usuario propietario de {$homeDir}.");
            Router::redirect('/accounts/import');
            return;
        }

        // Detect document root
        $documentRoot = $homeDir . '/httpdocs';
        if (!is_dir($documentRoot)) {
            // Try public
            if (is_dir($homeDir . '/public')) {
                $documentRoot = $homeDir . '/public';
            } else {
                $documentRoot = $homeDir;
            }
        }

        // Detect PHP version from existing FPM pool
        $phpVersion = '8.3';
        $fpmSocket = null;
        foreach (glob('/etc/php/*/fpm/pool.d/*.conf') as $poolFile) {
            $poolContent = file_get_contents($poolFile);
            if (stripos($poolContent, "user = {$username}") !== false) {
                // Extract PHP version from path
                if (preg_match('#/etc/php/([\d.]+)/fpm/#', $poolFile, $m)) {
                    $phpVersion = $m[1];
                }
                // Extract pool name for socket
                $poolName = basename($poolFile, '.conf');
                $fpmSocket = "unix//run/php/php{$phpVersion}-fpm-{$poolName}.sock";
                break;
            }
        }

        if (!$fpmSocket) {
            $fpmSocket = "unix//run/php/php{$phpVersion}-fpm-{$username}.sock";
        }

        // Detect Caddy route
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];
        $caddyRouteId = null;

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $routesJson = curl_exec($ch);
        curl_close($ch);

        if ($routesJson) {
            $routes = json_decode($routesJson, true) ?: [];
            foreach ($routes as $route) {
                $hosts = [];
                foreach ($route['match'] ?? [] as $match) {
                    $hosts = array_merge($hosts, $match['host'] ?? []);
                }
                if (in_array($domain, $hosts) || in_array("*.{$domain}", $hosts)) {
                    $caddyRouteId = $route['@id'] ?? null;
                    break;
                }
            }
        }

        // Calculate disk usage
        $diskUsed = SystemService::getDiskUsage($homeDir);

        // Insert into database
        $id = Database::insert('hosting_accounts', [
            'domain' => $domain,
            'username' => $username,
            'system_uid' => $uid,
            'home_dir' => $homeDir,
            'document_root' => $documentRoot,
            'php_version' => $phpVersion,
            'fpm_socket' => $fpmSocket,
            'disk_quota_mb' => 0, // 0 = unlimited (imported, no quota set)
            'disk_used_mb' => $diskUsed,
            'caddy_route_id' => $caddyRouteId,
            'shell' => $shell,
            'description' => 'Importado automaticamente desde /var/www/vhosts/',
        ]);

        // Add primary domain
        Database::insert('hosting_domains', [
            'account_id' => $id,
            'domain' => $domain,
            'is_primary' => true,
        ]);

        LogService::log('account.import', $domain, "Imported existing hosting: {$username}@{$domain}");
        Flash::set('success', "Hosting {$domain} importado correctamente como {$username}.");
        Router::redirect('/accounts');
    }

    private function discoverOrphanVhosts(): array
    {
        $vhostsDir = '/var/www/vhosts';
        $orphans = [];

        // Get registered domains
        $registered = Database::fetchAll("SELECT domain FROM hosting_accounts");
        $registeredDomains = array_column($registered, 'domain');

        // Scan vhosts directory
        foreach (glob("{$vhostsDir}/*", GLOB_ONLYDIR) as $dir) {
            $domain = basename($dir);

            // Skip if not a valid domain name
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain)) {
                continue;
            }

            // Skip if already registered
            if (in_array($domain, $registeredDomains)) {
                continue;
            }

            // Gather info
            $stat = stat($dir);
            $ownerInfo = $stat ? posix_getpwuid($stat['uid']) : null;
            $username = $ownerInfo ? $ownerInfo['name'] : null;
            $uid = $stat ? $stat['uid'] : null;
            $shell = $ownerInfo ? ($ownerInfo['shell'] ?? '?') : '?';

            // Detect document root
            $docRoot = $dir . '/httpdocs';
            if (!is_dir($docRoot)) {
                $docRoot = is_dir($dir . '/public') ? $dir . '/public' : $dir;
            }

            // Detect PHP version and FPM pool
            $phpVersion = null;
            $fpmPool = false;
            if ($username) {
                foreach (glob('/etc/php/*/fpm/pool.d/*.conf') as $poolFile) {
                    $poolContent = file_get_contents($poolFile);
                    if (stripos($poolContent, "user = {$username}") !== false) {
                        if (preg_match('#/etc/php/([\d.]+)/fpm/#', $poolFile, $m)) {
                            $phpVersion = $m[1];
                        }
                        $fpmPool = true;
                        break;
                    }
                }
            }

            // Detect Caddy route
            $caddyRoute = null;
            $config = require PANEL_ROOT . '/config/panel.php';
            $caddyApi = $config['caddy']['api_url'];
            $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
            $routesJson = curl_exec($ch);
            curl_close($ch);

            if ($routesJson) {
                $routes = json_decode($routesJson, true) ?: [];
                foreach ($routes as $route) {
                    $hosts = [];
                    foreach ($route['match'] ?? [] as $match) {
                        $hosts = array_merge($hosts, $match['host'] ?? []);
                    }
                    if (in_array($domain, $hosts) || in_array("*.{$domain}", $hosts)) {
                        $caddyRoute = $route['@id'] ?? 'si (sin @id)';
                        break;
                    }
                }
            }

            // Disk usage
            $diskMb = SystemService::getDiskUsage($dir);

            // Warnings
            $warnings = [];
            if (!$username) $warnings[] = 'No se pudo detectar el usuario propietario';
            if (!$fpmPool) $warnings[] = 'No se encontro pool FPM para este usuario';
            if (!$caddyRoute) $warnings[] = 'Sin ruta en Caddy (no tiene web server configurado)';

            $orphans[] = [
                'domain' => $domain,
                'home_dir' => $dir,
                'document_root' => $docRoot,
                'username' => $username,
                'uid' => $uid,
                'shell' => $shell,
                'php_version' => $phpVersion,
                'fpm_pool' => $fpmPool,
                'caddy_route' => $caddyRoute,
                'disk_mb' => $diskMb,
                'warnings' => $warnings,
            ];
        }

        return $orphans;
    }

    public function updatePhp(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        $poolFile = "/etc/php/{$account['php_version']}/fpm/pool.d/{$account['username']}.conf";
        if (!file_exists($poolFile)) {
            Flash::set('error', 'No se encontró el archivo de pool FPM.');
            Router::redirect('/accounts/' . $params['id'] . '/edit');
            return;
        }

        $poolContent = file_get_contents($poolFile);
        if ($poolContent === false) {
            Flash::set('error', 'No se pudo leer el archivo de pool FPM.');
            Router::redirect('/accounts/' . $params['id'] . '/edit');
            return;
        }

        // Allowed settings with validation patterns
        $allowedSettings = [
            'memory_limit' => '/^\d+[MmGgKk]?$/',
            'upload_max_filesize' => '/^\d+[MmGgKk]?$/',
            'post_max_size' => '/^\d+[MmGgKk]?$/',
            'max_execution_time' => '/^\d+$/',
            'max_input_vars' => '/^\d+$/',
        ];

        $changes = [];
        foreach ($allowedSettings as $key => $pattern) {
            $value = trim($_POST[$key] ?? '');
            if (empty($value)) continue;

            if (!preg_match($pattern, $value)) {
                Flash::set('error', "Valor no válido para {$key}: {$value}");
                Router::redirect('/accounts/' . $params['id'] . '/edit');
                return;
            }

            $changes[$key] = $value;
        }

        if (empty($changes)) {
            Flash::set('error', 'No se especificaron valores para actualizar.');
            Router::redirect('/accounts/' . $params['id'] . '/edit');
            return;
        }

        // Update or add each setting in the pool conf
        foreach ($changes as $key => $value) {
            // Try to replace existing php_admin_value[key] or php_value[key] line
            $replaced = false;

            // Replace php_admin_value[key] = ...
            $pattern = '/^(php_admin_value\[' . preg_quote($key, '/') . '\])\s*=\s*.+$/m';
            if (preg_match($pattern, $poolContent)) {
                $poolContent = preg_replace($pattern, "php_admin_value[{$key}] = {$value}", $poolContent);
                $replaced = true;
            }

            // Replace php_value[key] = ...
            if (!$replaced) {
                $pattern = '/^(php_value\[' . preg_quote($key, '/') . '\])\s*=\s*.+$/m';
                if (preg_match($pattern, $poolContent)) {
                    $poolContent = preg_replace($pattern, "php_value[{$key}] = {$value}", $poolContent);
                    $replaced = true;
                }
            }

            // If not found, add as php_admin_value before security.limit_extensions or at end
            if (!$replaced) {
                $newLine = "php_admin_value[{$key}] = {$value}";
                if (strpos($poolContent, 'security.limit_extensions') !== false) {
                    $poolContent = preg_replace(
                        '/^(security\.limit_extensions\s*=)/m',
                        $newLine . "\n\n$1",
                        $poolContent
                    );
                } else {
                    $poolContent = rtrim($poolContent) . "\n{$newLine}\n";
                }
            }
        }

        // Write the updated pool conf
        if (file_put_contents($poolFile, $poolContent) === false) {
            Flash::set('error', 'No se pudo escribir el archivo de pool FPM.');
            Router::redirect('/accounts/' . $params['id'] . '/edit');
            return;
        }

        // Restart PHP-FPM
        shell_exec("systemctl reload php{$account['php_version']}-fpm 2>&1");

        $changesLog = implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($changes), array_values($changes)));
        LogService::log('account.php_settings', $account['domain'], "PHP settings updated: {$changesLog}");
        Flash::set('success', 'Ajustes PHP actualizados y FPM recargado.');
        Router::redirect('/accounts/' . $params['id'] . '/edit');
    }

    public function delete(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        // Only allow deleting suspended accounts (safety)
        if ($account['status'] !== 'suspended') {
            Flash::set('error', 'Solo se pueden eliminar cuentas suspendidas. Suspende primero.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        SystemService::deleteAccount($account['username'], $account['domain'], $account['home_dir']);
        Database::delete('hosting_accounts', 'id = :id', ['id' => $params['id']]);

        LogService::log('account.delete', $account['domain'], "Deleted account and system user: {$account['username']}");

        // Sync deletion to cluster nodes
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            foreach (ClusterService::getNodes() as $node) {
                ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                    'hosting_action' => 'delete_hosting',
                    'hosting_data' => [
                        'username' => $account['username'],
                        'domain' => $account['domain'],
                        'home_dir' => $account['home_dir'],
                    ],
                ]);
            }
        }

        Flash::set('success', "Cuenta {$account['domain']} eliminada.");
        Router::redirect('/accounts');
    }
}
