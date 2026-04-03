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
use MuseDockPanel\Services\MailService;
use MuseDockPanel\Services\SubdomainService;

class AccountController
{
    private function slaveGuard(string $action = 'Esta accion'): bool
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', "Este servidor es Slave. {$action} solo esta permitido en el Master.");
            Router::redirect('/accounts');
            return true;
        }
        return false;
    }

    public function index(): void
    {
        $accounts = Database::fetchAll(
            "SELECT h.*, c.name as customer_name, c.email as customer_email
             FROM hosting_accounts h
             LEFT JOIN customers c ON c.id = h.customer_id
             ORDER BY h.created_at DESC"
        );

        // Disk usage is read from DB (updated periodically by monitor-collector worker)
        // No real-time du call — that's what made this page slow

        // Fetch alias/redirect details per account
        $aliasCounts = [];
        $redirectCounts = [];
        $aliasDetails = []; // grouped by account_id
        try {
            $aliasRows = Database::fetchAll(
                "SELECT hosting_account_id, domain, type, redirect_code, preserve_path
                 FROM hosting_domain_aliases
                 ORDER BY hosting_account_id, type, domain"
            );
            foreach ($aliasRows as $row) {
                $aid = (int)$row['hosting_account_id'];
                $aliasDetails[$aid][] = $row;
                if ($row['type'] === 'alias') {
                    $aliasCounts[$aid] = ($aliasCounts[$aid] ?? 0) + 1;
                } else {
                    $redirectCounts[$aid] = ($redirectCounts[$aid] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {}

        // Fetch subdomains per account
        $subCounts = [];
        $subDetails = [];
        try {
            $subRows = Database::fetchAll(
                "SELECT id, account_id, subdomain, document_root, status FROM hosting_subdomains ORDER BY account_id, subdomain"
            );
            foreach ($subRows as $row) {
                $aid = (int)$row['account_id'];
                $subCounts[$aid] = ($subCounts[$aid] ?? 0) + 1;
                $subDetails[$aid][] = $row;
            }
        } catch (\Throwable $e) {}

        // Bandwidth totals for current month
        $bwTotals = \MuseDockPanel\Services\BandwidthService::getAllMonthlyTotals();
        $subBwTotals = \MuseDockPanel\Services\BandwidthService::getAllSubdomainMonthlyTotals();

        foreach ($accounts as &$acc) {
            $acc['alias_count'] = $aliasCounts[(int)$acc['id']] ?? 0;
            $acc['redirect_count'] = $redirectCounts[(int)$acc['id']] ?? 0;
            $acc['alias_details'] = $aliasDetails[(int)$acc['id']] ?? [];
            $acc['subdomain_count'] = $subCounts[(int)$acc['id']] ?? 0;
            $acc['subdomain_details'] = $subDetails[(int)$acc['id']] ?? [];
            $acc['bw_bytes'] = (int)($bwTotals[(int)$acc['id']]['bytes_out'] ?? 0);
            // Attach BW to each subdomain detail
            if (!empty($acc['subdomain_details'])) {
                foreach ($acc['subdomain_details'] as &$sd) {
                    $sd['bw_bytes'] = (int)($subBwTotals[(int)$sd['id']]['bytes_out'] ?? 0);
                }
                unset($sd);
            }
        }

        View::render('accounts/index', [
            'layout' => 'main',
            'pageTitle' => 'Hosting Accounts',
            'accounts' => $accounts,
        ]);
    }

    public function create(): void
    {
        if ($this->slaveGuard('La creacion de hostings')) return;

        $customers = Database::fetchAll("SELECT id, name, email, company FROM customers WHERE status = 'active' ORDER BY name");

        View::render('accounts/create', [
            'layout' => 'main',
            'pageTitle' => 'Create Hosting Account',
            'customers' => $customers,
        ]);
    }

    public function store(): void
    {
        if ($this->slaveGuard('La creacion de hostings')) return;

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
                $nodes = ClusterService::getWebNodes();
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
                            'password_hash' => SystemService::getPasswordHash($username),
                            'shell' => $shell,
                            'system_uid' => $result['uid'] ?? null,
                            'caddy_route_id' => $result['caddy_route_id'] ?? null,
                            'customer_id' => $customerId,
                            'disk_quota_mb' => $diskQuota,
                            'description' => $description,
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

    // ── Async provisioning with SSE progress ──

    private function provisionStatusFile(string $token): string
    {
        return "/tmp/provision_status_{$token}.json";
    }

    private function provisionSendSSE(string $event, string $data, ?string $token = null): void
    {
        echo "event: {$event}\n";
        echo "data: {$data}\n\n";
        if (ob_get_level()) ob_flush();
        @flush();

        if ($token) {
            $this->provisionAppendStatus($token, $event, $data);
        }
    }

    private function provisionAppendStatus(string $token, string $event, string $data): void
    {
        $file = $this->provisionStatusFile($token);
        $status = [];
        if (file_exists($file)) {
            $status = json_decode(file_get_contents($file), true) ?: [];
        }
        if (!isset($status['logs'])) $status['logs'] = [];

        if ($event === 'log') {
            $status['logs'][] = $data;
        } elseif ($event === 'step') {
            $status['step'] = $data;
        } elseif ($event === 'progress') {
            $decoded = json_decode($data, true);
            if ($decoded) $status['progress'] = $decoded;
        } elseif ($event === 'error') {
            $status['logs'][] = 'ERROR: ' . $data;
        } elseif ($event === 'done') {
            $status['done'] = true;
            $status['active'] = false;
            $status['result'] = json_decode($data, true);
        }

        file_put_contents($file, json_encode($status, JSON_UNESCAPED_UNICODE));
    }

    /**
     * POST /accounts/store-async — validate + prepare SSE token, return JSON
     */
    public function storeAsync(): void
    {
        header('Content-Type: application/json');

        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            echo json_encode(['ok' => false, 'error' => 'La creacion de hostings solo se puede hacer en el nodo master.']);
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
            echo json_encode(['ok' => false, 'error' => 'Dominio, usuario y password son obligatorios.']);
            return;
        }
        if (!preg_match('/^[a-z][a-z0-9_]{2,30}$/', $username)) {
            echo json_encode(['ok' => false, 'error' => 'El usuario debe empezar por letra minuscula, solo a-z, 0-9 y _ (3-31 caracteres).']);
            return;
        }
        $existing = Database::fetchOne("SELECT id FROM hosting_accounts WHERE domain = :d OR username = :u", ['d' => $domain, 'u' => $username]);
        if ($existing) {
            echo json_encode(['ok' => false, 'error' => 'El dominio o usuario ya existe.']);
            return;
        }
        if (strlen($password) < 8) {
            echo json_encode(['ok' => false, 'error' => 'El password debe tener al menos 8 caracteres.']);
            return;
        }
        if (!in_array($shell, ['/bin/bash', '/usr/sbin/nologin'])) {
            $shell = '/usr/sbin/nologin';
        }

        // Store data in session for SSE stream to pick up
        $token = bin2hex(random_bytes(16));
        $sessionKey = 'provision_stream_' . $token;
        $_SESSION[$sessionKey] = [
            'domain' => $domain,
            'username' => $username,
            'password' => $password,
            'shell' => $shell,
            'customer_id' => $customerId,
            'description' => $description,
            'disk_quota_mb' => $diskQuota,
            'php_version' => $phpVersion,
        ];

        echo json_encode(['ok' => true, 'token' => $token]);
    }

    /**
     * GET /accounts/provision-stream?token=xxx — SSE stream for provisioning
     */
    public function provisionStream(): void
    {
        $token = $_GET['token'] ?? '';
        $sessionKey = 'provision_stream_' . $token;

        if (empty($token) || empty($_SESSION[$sessionKey])) {
            header('Content-Type: text/plain');
            echo 'Invalid token';
            return;
        }

        $data = $_SESSION[$sessionKey];
        unset($_SESSION[$sessionKey]);
        session_write_close();

        ignore_user_abort(true);
        set_time_limit(120);

        // SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        while (ob_get_level()) ob_end_flush();
        ini_set('output_buffering', 'off');
        ini_set('zlib.output_compression', false);

        $st = $token;
        $domain = $data['domain'];
        $username = $data['username'];
        $password = $data['password'];
        $phpVersion = $data['php_version'];
        $shell = $data['shell'];
        $customerId = $data['customer_id'];
        $description = $data['description'];
        $diskQuota = $data['disk_quota_mb'];

        $homeDir = "/var/www/vhosts/{$domain}";
        $documentRoot = "{$homeDir}/httpdocs";
        $fpmSocket = "unix//run/php/php{$phpVersion}-fpm-{$username}.sock";

        // Initialize status file
        file_put_contents($this->provisionStatusFile($st), json_encode([
            'active' => true, 'step' => 'Iniciando', 'logs' => [],
            'done' => false, 'result' => null, 'domain' => $domain,
        ], JSON_UNESCAPED_UNICODE));

        $this->provisionSendSSE('log', "Creando hosting para {$domain}...", $st);
        $this->provisionSendSSE('progress', json_encode(['step' => 1, 'total' => 7, 'percent' => 0]), $st);

        $errors = [];

        // ── Step 1: Create system user ──
        $this->provisionSendSSE('step', 'Creando usuario del sistema', $st);
        $this->provisionSendSSE('log', "Creando usuario: {$username}", $st);
        $this->provisionSendSSE('progress', json_encode(['step' => 1, 'total' => 7, 'percent' => 10]), $st);

        $uid = SystemService::createSystemUser($username, $homeDir, $shell);
        if ($uid === null) {
            $this->provisionSendSSE('error', "Error critico: no se pudo crear el usuario del sistema: {$username}", $st);
            $this->provisionSendSSE('done', json_encode(['ok' => false]), $st);
            return;
        }
        $this->provisionSendSSE('log', "Usuario creado (UID: {$uid})", $st);

        if (!empty($password)) {
            SystemService::setUserPassword($username, $password);
            $this->provisionSendSSE('log', 'Password configurado', $st);
        }

        // ── Step 2: Create directories ──
        $this->provisionSendSSE('step', 'Creando directorios', $st);
        $this->provisionSendSSE('progress', json_encode(['step' => 2, 'total' => 7, 'percent' => 25]), $st);
        $this->provisionSendSSE('log', "Creando estructura: {$homeDir}/httpdocs, logs, tmp, sessions", $st);

        if (!SystemService::createDirectories($username, $homeDir, $documentRoot)) {
            $this->provisionSendSSE('error', "Error critico: no se pudieron crear los directorios para {$domain}", $st);
            $this->provisionSendSSE('done', json_encode(['ok' => false]), $st);
            return;
        }
        $this->provisionSendSSE('log', 'Directorios creados correctamente', $st);

        // ── Step 3: Create default page ──
        $this->provisionSendSSE('step', 'Creando pagina por defecto', $st);
        $this->provisionSendSSE('progress', json_encode(['step' => 3, 'total' => 7, 'percent' => 40]), $st);

        SystemService::createDefaultPage($documentRoot, $domain);
        $this->provisionSendSSE('log', 'index.html creado', $st);

        // ── Step 4: Create PHP-FPM pool ──
        $this->provisionSendSSE('step', 'Configurando PHP-FPM', $st);
        $this->provisionSendSSE('progress', json_encode(['step' => 4, 'total' => 7, 'percent' => 55]), $st);
        $this->provisionSendSSE('log', "Creando pool PHP {$phpVersion} para {$username}", $st);

        $fpmSocketPath = SystemService::createFpmPool($username, $phpVersion, $homeDir);
        if (!$fpmSocketPath) {
            $errors[] = 'PHP-FPM pool creation failed';
            $this->provisionSendSSE('error', 'AVISO: No se pudo crear el pool PHP-FPM. Crear manualmente.', $st);
        } else {
            $this->provisionSendSSE('log', "Pool PHP-FPM creado: {$fpmSocketPath}", $st);
        }

        // ── Step 5: Add Caddy route ──
        $this->provisionSendSSE('step', 'Configurando servidor web', $st);
        $this->provisionSendSSE('progress', json_encode(['step' => 5, 'total' => 7, 'percent' => 70]), $st);
        $this->provisionSendSSE('log', "Configurando Caddy para {$domain} + www.{$domain}", $st);

        $caddyRouteId = SystemService::addCaddyRoute($domain, $documentRoot, $username, $phpVersion);
        if (!$caddyRouteId) {
            $errors[] = 'Caddy route creation failed';
            $this->provisionSendSSE('error', 'AVISO: No se pudo crear la ruta en Caddy. Configurar manualmente.', $st);
        } else {
            $this->provisionSendSSE('log', "Ruta Caddy configurada (ID: {$caddyRouteId}). SSL se configurara automaticamente.", $st);
        }

        // ── Step 6: Final permissions + lsyncd ──
        $this->provisionSendSSE('step', 'Ajustando permisos', $st);
        $this->provisionSendSSE('progress', json_encode(['step' => 6, 'total' => 7, 'percent' => 85]), $st);

        shell_exec(sprintf('chown -R %s:www-data %s 2>&1', escapeshellarg($username), escapeshellarg($homeDir)));
        $this->provisionSendSSE('log', 'Permisos ajustados', $st);

        SystemService::restartLsyncd();
        $this->provisionSendSSE('log', 'lsyncd reiniciado', $st);

        // ── Step 7: Save to database ──
        $this->provisionSendSSE('step', 'Guardando en base de datos', $st);
        $this->provisionSendSSE('progress', json_encode(['step' => 7, 'total' => 7, 'percent' => 95]), $st);

        try {
            $id = Database::insert('hosting_accounts', [
                'customer_id' => $customerId,
                'domain' => $domain,
                'username' => $username,
                'system_uid' => $uid,
                'home_dir' => $homeDir,
                'document_root' => $documentRoot,
                'php_version' => $phpVersion,
                'fpm_socket' => $fpmSocket,
                'disk_quota_mb' => $diskQuota,
                'caddy_route_id' => $caddyRouteId,
                'description' => $description,
                'shell' => $shell,
            ]);

            Database::insert('hosting_domains', [
                'account_id' => $id,
                'domain' => $domain,
                'is_primary' => true,
            ]);

            LogService::log('account.create', $domain, "Created hosting account: {$username}@{$domain}");

            // Sync to cluster nodes if master
            if (Settings::get('cluster_role', 'standalone') === 'master') {
                $nodes = ClusterService::getWebNodes();
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
                            'password_hash' => SystemService::getPasswordHash($username),
                            'shell' => $shell,
                            'system_uid' => $uid,
                            'caddy_route_id' => $caddyRouteId,
                            'customer_id' => $customerId,
                            'disk_quota_mb' => $diskQuota,
                            'description' => $description,
                        ],
                    ]);
                }
                $this->provisionSendSSE('log', 'Sincronizacion con nodos del cluster encolada', $st);
            }

            $this->provisionSendSSE('log', "Cuenta registrada en base de datos (ID: {$id})", $st);
        } catch (\Throwable $e) {
            $this->provisionSendSSE('error', 'Error guardando en BD: ' . $e->getMessage(), $st);
            $this->provisionSendSSE('done', json_encode(['ok' => false]), $st);
            return;
        }

        // ── Done ──
        $this->provisionSendSSE('progress', json_encode(['step' => 7, 'total' => 7, 'percent' => 100]), $st);
        $this->provisionSendSSE('log', 'Hosting creado correctamente!', $st);
        $this->provisionSendSSE('done', json_encode([
            'ok' => true,
            'account_id' => $id,
            'domain' => $domain,
            'username' => $username,
            'warnings' => $errors,
        ]), $st);
    }

    /**
     * GET /accounts/provision-status?token=xxx — poll status for reconnection
     */
    public function provisionStatus(): void
    {
        header('Content-Type: application/json');
        $token = $_GET['token'] ?? '';
        $statusFile = $this->provisionStatusFile($token);
        if (empty($token) || !file_exists($statusFile)) {
            echo json_encode(['active' => false]);
            return;
        }
        $status = json_decode(file_get_contents($statusFile), true) ?: ['active' => false];
        echo json_encode($status);
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

        // Mail info for this domain
        $mailDomain = Database::fetchOne(
            "SELECT md.*, cn.name AS node_name FROM mail_domains md LEFT JOIN cluster_nodes cn ON cn.id = md.mail_node_id WHERE md.domain = :d",
            ['d' => $account['domain']]
        );
        $mailAccounts = [];
        if ($mailDomain) {
            $mailAccounts = Database::fetchAll(
                "SELECT email, status, quota_mb, used_mb FROM mail_accounts WHERE mail_domain_id = :mid ORDER BY email",
                ['mid' => $mailDomain['id']]
            );
        }
        $mailEnabled = Settings::get('mail_enabled', '') === '1';
        $mailLocalConfigured = Settings::get('mail_local_configured', '') === '1';
        $hasMailNodes = !empty(MailService::getMailNodes()) || $mailLocalConfigured;

        // Domain aliases & redirects
        $aliases = \MuseDockPanel\Services\DomainAliasService::getAliases((int)$account['id']);
        $redirects = \MuseDockPanel\Services\DomainAliasService::getRedirects((int)$account['id']);

        // Subdomains
        $subdomains = SubdomainService::getAll((int)$account['id']);
        $adoptableAccounts = SubdomainService::getAdoptableAccounts($account['domain']);

        $isSlave = Settings::get('cluster_role', 'standalone') === 'slave';

        // WordPress detection + WP-Cron status
        $wpInfo = null;
        $docRoot = !empty($account['document_root']) ? $account['document_root'] : (rtrim($account['home_dir'], '/') . '/httpdocs');
        $wpConfigPath = rtrim($docRoot, '/') . '/wp-config.php';
        if (file_exists($wpConfigPath)) {
            $wpContent = file_get_contents($wpConfigPath) ?: '';
            $wpCronDisabled = preg_match("/define\s*\(\s*['\"]DISABLE_WP_CRON['\"]\s*,\s*true\s*\)/i", $wpContent);
            $wpInfo = [
                'is_wordpress' => true,
                'wp_cron_disabled' => (bool)$wpCronDisabled,
                'wp_config_path' => $wpConfigPath,
            ];
        }

        // Bandwidth
        $bwMonthly = \MuseDockPanel\Services\BandwidthService::getMonthlyTotal((int)$account['id']);

        View::render('accounts/show', [
            'layout' => 'main',
            'pageTitle' => $account['domain'],
            'account' => $account,
            'domains' => $domains,
            'databases' => $databases,
            'mailDomain' => $mailDomain,
            'mailAccounts' => $mailAccounts,
            'mailEnabled' => $mailEnabled,
            'hasMailNodes' => $hasMailNodes,
            'aliases' => $aliases,
            'redirects' => $redirects,
            'subdomains' => $subdomains,
            'adoptableAccounts' => $adoptableAccounts,
            'isSlave' => $isSlave,
            'wpInfo' => $wpInfo,
            'bwMonthly' => $bwMonthly,
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

        $isSlave = Settings::get('cluster_role', 'standalone') === 'slave';

        View::render('accounts/edit', [
            'layout' => 'main',
            'pageTitle' => 'Editar: ' . $account['domain'],
            'account' => $account,
            'phpSettings' => $phpSettings,
            'poolFileExists' => file_exists($poolFile),
            'isSlave' => $isSlave,
        ]);
    }

    public function update(array $params): void
    {
        if ($this->slaveGuard('La edicion de hostings')) return;

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

        // Sync changes to cluster slave nodes
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            $updated = array_merge($account, $dbFields); // Merge new values
            $nodes = \MuseDockPanel\Services\ClusterService::getWebNodes();
            foreach ($nodes as $node) {
                \MuseDockPanel\Services\ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                    'hosting_action' => 'update_hosting_full',
                    'hosting_data' => [
                        'domain'         => $account['domain'],
                        'username'       => $account['username'],
                        'document_root'  => $updated['document_root'] ?? $account['document_root'],
                        'php_version'    => $updated['php_version'] ?? $account['php_version'],
                        'disk_quota_mb'  => $updated['disk_quota_mb'] ?? $account['disk_quota_mb'],
                        'shell'          => $updated['shell'] ?? $account['shell'],
                        'description'    => $updated['description'] ?? $account['description'],
                    ],
                ]);
            }
        }

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

        // Sync rename to cluster slaves
        if (\MuseDockPanel\Settings::get('cluster_role', 'standalone') === 'master') {
            $nodes = \MuseDockPanel\Services\ClusterService::getWebNodes();
            foreach ($nodes as $node) {
                \MuseDockPanel\Services\ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                    'hosting_action' => 'rename_user',
                    'hosting_data' => [
                        'old_username' => $oldUsername,
                        'new_username' => $newUsername,
                        'domain'       => $account['domain'],
                        'php_version'  => $account['php_version'],
                    ],
                ]);
            }
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

        // Sync password to cluster slaves (hash, not plaintext)
        if (\MuseDockPanel\Settings::get('cluster_role', 'standalone') === 'master') {
            $passwordHash = trim((string)shell_exec(sprintf("getent shadow %s 2>/dev/null | cut -d: -f2", escapeshellarg($account['username']))));
            if ($passwordHash && $passwordHash !== '!' && $passwordHash !== '*') {
                $nodes = \MuseDockPanel\Services\ClusterService::getWebNodes();
                foreach ($nodes as $node) {
                    \MuseDockPanel\Services\ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                        'hosting_action' => 'change_password',
                        'hosting_data' => [
                            'username'      => $account['username'],
                            'password_hash' => $passwordHash,
                        ],
                    ]);
                }
            }
        }

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
        $routeId = $account['caddy_route_id'] ?? \MuseDockPanel\Services\SystemService::caddyRouteId($account['domain']);

        // Re-add the route including aliases (this triggers new certificates)
        $newRouteId = \MuseDockPanel\Services\DomainAliasService::rebuildCaddyRoute($account) ? $routeId : null;

        if ($newRouteId) {
            LogService::log('account.ssl', $account['domain'], "SSL certificate renewal triggered");
            Flash::set('success', "Renovación SSL iniciada para {$account['domain']}. Si el DNS apunta a este servidor, el certificado se obtendrá en segundos.");
        } else {
            Flash::set('error', 'Error al recrear la ruta Caddy. Revisa los logs.');
        }

        Router::redirect('/accounts/' . $params['id']);
    }

    // ─── Domain Aliases ──────────────────────────────────────

    public function addAlias(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $result = \MuseDockPanel\Services\DomainAliasService::addAlias((int)$account['id'], $domain);

        if (!$result['ok']) {
            Flash::set('error', $result['error']);
        } else {
            // Cluster sync
            if (Settings::get('cluster_role', 'standalone') === 'master') {
                $this->syncAliasToCluster($account, $domain, 'alias', 'add');
            }
            LogService::log('account.alias', $account['domain'], "Alias añadido: {$domain}");
            Flash::set('success', "Alias '{$domain}' añadido. Caddy: " . ($result['caddy'] ? 'OK' : 'pendiente'));
        }
        Router::redirect('/accounts/' . $params['id']);
    }

    public function removeAlias(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        // Verify admin password
        $password = $_POST['admin_password'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? $_SESSION['panel_user']['id'] ?? 0;
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Flash::set('error', 'Contraseña incorrecta.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        $result = \MuseDockPanel\Services\DomainAliasService::remove((int)$params['alias_id']);

        if (!$result['ok']) {
            Flash::set('error', $result['error']);
        } else {
            if (Settings::get('cluster_role', 'standalone') === 'master') {
                $this->syncAliasToCluster($account, $result['domain'], $result['type'], 'remove');
            }
            LogService::log('account.alias', $account['domain'], ucfirst($result['type']) . " eliminado: {$result['domain']}");
            Flash::set('success', ucfirst($result['type']) . " '{$result['domain']}' eliminado.");
        }
        Router::redirect('/accounts/' . $params['id']);
    }

    public function addRedirect(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $code = (int)($_POST['redirect_code'] ?? 301);
        $preservePath = !empty($_POST['preserve_path']);

        if (!in_array($code, [301, 302])) $code = 301;

        $result = \MuseDockPanel\Services\DomainAliasService::addRedirect((int)$account['id'], $domain, $code, $preservePath);

        if (!$result['ok']) {
            Flash::set('error', $result['error']);
        } else {
            if (Settings::get('cluster_role', 'standalone') === 'master') {
                $this->syncAliasToCluster($account, $domain, 'redirect', 'add', $code, $preservePath);
            }
            LogService::log('account.redirect', $account['domain'], "Redirección {$code} añadida: {$domain}");
            Flash::set('success', "Redirección {$code} '{$domain}' → '{$account['domain']}' añadida.");
        }
        Router::redirect('/accounts/' . $params['id']);
    }

    private function syncAliasToCluster(array $account, string $domain, string $type, string $action, int $code = 301, bool $preservePath = true): void
    {
        $nodes = \MuseDockPanel\Services\ClusterService::getWebNodes();
        foreach ($nodes as $node) {
            \MuseDockPanel\Services\ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                'hosting_action' => $action === 'add' ? 'add_domain_alias' : 'remove_domain_alias',
                'hosting_data' => [
                    'main_domain'   => $account['domain'],
                    'alias_domain'  => $domain,
                    'type'          => $type,
                    'redirect_code' => $code,
                    'preserve_path' => $preservePath,
                    'username'      => $account['username'],
                    'document_root' => $account['document_root'],
                    'php_version'   => $account['php_version'],
                ],
            ]);
        }
    }

    // ─── Subdomains ─────────────────────────────────────────

    public function addSubdomain(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        $subdomain = strtolower(trim($_POST['subdomain'] ?? ''));
        $result = SubdomainService::create((int)$account['id'], $subdomain);

        if (!$result['ok']) {
            Flash::set('error', $result['error']);
        } else {
            // Cluster sync
            if (Settings::get('cluster_role', 'standalone') === 'master') {
                $this->syncSubdomainToCluster($account, $subdomain, 'add', $result['document_root']);
            }
            Flash::set('success', "Subdominio '{$subdomain}' creado. Document root: {$result['document_root']}");
        }
        Router::redirect('/accounts/' . $params['id']);
    }

    public function removeSubdomain(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        // Verify admin password
        $password = $_POST['admin_password'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? $_SESSION['panel_user']['id'] ?? 0;
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Flash::set('error', 'Contraseña incorrecta.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        $sub = SubdomainService::getById((int)$params['sub_id']);
        $deleteFiles = !empty($_POST['delete_files']);
        $result = SubdomainService::delete((int)$params['sub_id'], $deleteFiles);

        if (!$result['ok']) {
            Flash::set('error', $result['error']);
        } else {
            if (Settings::get('cluster_role', 'standalone') === 'master' && $sub) {
                $this->syncSubdomainToCluster($account, $sub['subdomain'], 'remove');
            }
            Flash::set('success', "Subdominio '{$sub['subdomain']}' eliminado." . ($deleteFiles ? ' Archivos borrados.' : ''));
        }
        Router::redirect('/accounts/' . $params['id']);
    }

    public function showSubdomain(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        $sub = SubdomainService::getById((int)$params['sub_id']);
        if (!$sub || (int)$sub['account_id'] !== (int)$account['id']) {
            Flash::set('error', 'Subdominio no encontrado.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        // Redirect to edit page (subdomain detail IS the edit page)
        Router::redirect("/accounts/{$params['id']}/subdomains/{$params['sub_id']}/edit");
    }

    public function editSubdomain(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        $sub = SubdomainService::getById((int)$params['sub_id']);
        if (!$sub || (int)$sub['account_id'] !== (int)$account['id']) {
            Flash::set('error', 'Subdominio no encontrado.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        $phpData = SubdomainService::getEffectivePhpSettings((int)$sub['id']);
        $isSlave = Settings::get('cluster_role', 'standalone') === 'slave';

        View::render('accounts/subdomain_edit', [
            'layout' => 'main',
            'pageTitle' => $sub['subdomain'],
            'account' => $account,
            'subdomain' => $sub,
            'phpSettings' => $phpData['settings'] ?? [],
            'phpOverrides' => $phpData['overrides'] ?? [],
            'parentPoolExists' => $phpData['parent_pool_exists'] ?? false,
            'isSlave' => $isSlave,
        ]);
    }

    public function updateSubdomain(array $params): void
    {
        if ($this->slaveGuard('La edición de subdominios')) return;

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        $sub = SubdomainService::getById((int)$params['sub_id']);
        if (!$sub || (int)$sub['account_id'] !== (int)$account['id']) {
            Flash::set('error', 'Subdominio no encontrado.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        $docRootRelative = trim($_POST['document_root_relative'] ?? '');

        if ($docRootRelative !== '' || isset($_POST['document_root_relative'])) {
            $result = SubdomainService::updateDocumentRoot((int)$sub['id'], $docRootRelative);
            if (!$result['ok']) {
                Flash::set('error', $result['error']);
                Router::redirect("/accounts/{$params['id']}/subdomains/{$params['sub_id']}/edit");
                return;
            }
        }

        // Cluster sync
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            $updatedSub = SubdomainService::getById((int)$sub['id']);
            $this->syncSubdomainToCluster($account, $sub['subdomain'], 'add', $updatedSub['document_root'] ?? $sub['document_root']);
        }

        Flash::set('success', 'Subdominio actualizado.');
        Router::redirect("/accounts/{$params['id']}/subdomains/{$params['sub_id']}/edit");
    }

    public function updateSubdomainPhp(array $params): void
    {
        if ($this->slaveGuard('Cambiar PHP de subdominios')) return;

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        $sub = SubdomainService::getById((int)$params['sub_id']);
        if (!$sub || (int)$sub['account_id'] !== (int)$account['id']) {
            Flash::set('error', 'Subdominio no encontrado.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        $overrides = [
            'memory_limit' => trim($_POST['memory_limit'] ?? ''),
            'upload_max_filesize' => trim($_POST['upload_max_filesize'] ?? ''),
            'post_max_size' => trim($_POST['post_max_size'] ?? ''),
            'max_execution_time' => trim($_POST['max_execution_time'] ?? ''),
            'max_input_vars' => trim($_POST['max_input_vars'] ?? ''),
        ];

        $result = SubdomainService::updatePhpOverrides((int)$sub['id'], $overrides);
        if (!$result['ok']) {
            Flash::set('error', $result['error']);
        } else {
            Flash::set('success', 'Ajustes PHP del subdominio actualizados (.user.ini regenerado).');
        }

        Router::redirect("/accounts/{$params['id']}/subdomains/{$params['sub_id']}/edit");
    }

    public function toggleSubdomainStatus(array $params): void
    {
        if ($this->slaveGuard('Cambiar estado de subdominios')) return;

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        $sub = SubdomainService::getById((int)$params['sub_id']);
        if (!$sub || (int)$sub['account_id'] !== (int)$account['id']) {
            Flash::set('error', 'Subdominio no encontrado.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        if ($sub['status'] === 'active') {
            $result = SubdomainService::suspend((int)$sub['id']);
            $msg = "Subdominio '{$sub['subdomain']}' suspendido.";
        } else {
            $result = SubdomainService::activate((int)$sub['id']);
            $msg = "Subdominio '{$sub['subdomain']}' activado.";
        }

        if (!$result['ok']) {
            Flash::set('error', $result['error']);
        } else {
            if (Settings::get('cluster_role', 'standalone') === 'master') {
                $this->syncSubdomainToCluster($account, $sub['subdomain'], $sub['status'] === 'active' ? 'remove' : 'add', $sub['document_root']);
            }
            Flash::set('success', $msg);
        }

        Router::redirect('/accounts/' . $params['id']);
    }

    private function syncSubdomainToCluster(array $account, string $subdomain, string $action, ?string $documentRoot = null): void
    {
        $nodes = ClusterService::getWebNodes();
        foreach ($nodes as $node) {
            ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                'hosting_action' => $action === 'add' ? 'add_subdomain' : 'remove_subdomain',
                'hosting_data' => [
                    'main_domain'   => $account['domain'],
                    'subdomain'     => $subdomain,
                    'document_root' => $documentRoot,
                    'username'      => $account['username'],
                    'php_version'   => $account['php_version'],
                ],
            ]);
        }
    }

    public function adoptSubdomain(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        // Verify admin password
        $password = $_POST['admin_password'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? $_SESSION['panel_user']['id'] ?? 0;
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Flash::set('error', 'Contraseña incorrecta.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        $childAccountId = (int)($_POST['child_account_id'] ?? 0);
        if (!$childAccountId) {
            Flash::set('error', 'Selecciona una cuenta a adoptar.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        $child = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $childAccountId]);
        $result = SubdomainService::adopt((int)$account['id'], $childAccountId);

        if (!$result['ok']) {
            Flash::set('error', $result['error']);
        } else {
            $msg = "Cuenta '{$child['domain']}' adoptada como subdominio. Archivos movidos a {$result['document_root']}.";
            if (!empty($result['old_home'])) {
                $msg .= " La carpeta original ({$result['old_home']}) se conserva por seguridad — puedes borrarla manualmente.";
            }
            // Cluster sync
            if (Settings::get('cluster_role', 'standalone') === 'master') {
                $this->syncSubdomainToCluster($account, $child['domain'], 'add', $result['document_root']);
            }
            Flash::set('success', $msg);
        }
        Router::redirect('/accounts/' . $params['id']);
    }

    /**
     * POST /accounts/{id}/toggle-wp-cron — Enable/disable WP-Cron for a WordPress account
     */
    public function toggleWpCron(array $params): void
    {
        if ($this->slaveGuard('Modificar WP-Cron')) return;
        View::verifyCsrf();

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        $docRoot = !empty($account['document_root']) ? $account['document_root'] : (rtrim($account['home_dir'], '/') . '/httpdocs');
        $wpConfigPath = rtrim($docRoot, '/') . '/wp-config.php';

        if (!file_exists($wpConfigPath)) {
            Flash::set('error', 'No se encontro wp-config.php en esta cuenta.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        $content = file_get_contents($wpConfigPath);
        if ($content === false) {
            Flash::set('error', 'No se pudo leer wp-config.php.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        $isDisabled = preg_match("/define\s*\(\s*['\"]DISABLE_WP_CRON['\"]\s*,\s*true\s*\)/i", $content);

        if ($isDisabled) {
            $content = preg_replace("/\n?define\s*\(\s*['\"]DISABLE_WP_CRON['\"]\s*,\s*true\s*\);\s*\n?/i", "\n", $content);
            file_put_contents($wpConfigPath, $content);
            Flash::set('success', "WP-Cron reactivado para {$account['domain']}.");
        } else {
            $line = "\ndefine('DISABLE_WP_CRON', true);\n";
            if (preg_match("/^(<\?php)/m", $content, $m, \PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + strlen($m[0][0]);
                $content = substr($content, 0, $pos) . $line . substr($content, $pos);
            } else {
                $content = "<?php\n" . $line . "?>\n" . $content;
            }
            file_put_contents($wpConfigPath, $content);
            Flash::set('success', "WP-Cron desactivado para {$account['domain']}. WordPress ya no ejecutara tareas programadas en cada visita.");
        }

        LogService::log('account.wp_cron', $account['domain'], ($isDisabled ? 'Enabled' : 'Disabled') . " WP-Cron for {$account['domain']}");
        Router::redirect('/accounts/' . $params['id']);
    }

    /**
     * POST /accounts/bulk-disable-wp-cron — Disable WP-Cron in all WordPress accounts
     */
    public function bulkDisableWpCron(): void
    {
        if ($this->slaveGuard('Modificar WP-Cron')) return;
        View::verifyCsrf();

        $accounts = Database::fetchAll("SELECT id, domain, home_dir, document_root FROM hosting_accounts WHERE status = 'active'");
        $disabled = 0;
        $alreadyDisabled = 0;
        $notWp = 0;

        foreach ($accounts as $acc) {
            $docRoot = !empty($acc['document_root']) ? $acc['document_root'] : (rtrim($acc['home_dir'], '/') . '/httpdocs');
            $wpConfigPath = rtrim($docRoot, '/') . '/wp-config.php';

            if (!file_exists($wpConfigPath)) {
                $notWp++;
                continue;
            }

            $content = file_get_contents($wpConfigPath);
            if ($content === false) {
                $notWp++;
                continue;
            }

            if (preg_match("/define\s*\(\s*['\"]DISABLE_WP_CRON['\"]\s*,\s*true\s*\)/i", $content)) {
                $alreadyDisabled++;
                continue;
            }

            $line = "\ndefine('DISABLE_WP_CRON', true);\n";
            if (preg_match("/^(<\?php)/m", $content, $m, \PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + strlen($m[0][0]);
                $content = substr($content, 0, $pos) . $line . substr($content, $pos);
            } else {
                $content = "<?php\n" . $line . "?>\n" . $content;
            }
            file_put_contents($wpConfigPath, $content);
            $disabled++;
            LogService::log('account.wp_cron', $acc['domain'], "Disabled WP-Cron for {$acc['domain']} (bulk action)");
        }

        $msg = "WP-Cron desactivado en {$disabled} WordPress.";
        if ($alreadyDisabled > 0) $msg .= " {$alreadyDisabled} ya estaban desactivados.";
        if ($notWp > 0) $msg .= " {$notWp} cuentas no son WordPress.";
        Flash::set('success', $msg);
        Router::redirect('/accounts');
    }

    public function promoteSubdomain(array $params): void
    {
        if ($this->slaveGuard('Promover subdominios')) return;

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        // Verify admin password
        $password = $_POST['admin_password'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? $_SESSION['panel_user']['id'] ?? 0;
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Flash::set('error', 'Contraseña incorrecta.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        $sub = SubdomainService::getById((int)$params['sub_id']);
        if (!$sub) {
            Flash::set('error', 'Subdominio no encontrado.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        $result = SubdomainService::promote((int)$params['sub_id']);

        if (!$result['ok']) {
            Flash::set('error', $result['error']);
        } else {
            // Sync to cluster: remove subdomain + create new account
            if (Settings::get('cluster_role', 'standalone') === 'master') {
                // Remove subdomain on slaves
                $this->syncSubdomainToCluster($account, $sub['subdomain'], 'remove');

                // Create new account on slaves
                foreach (ClusterService::getWebNodes() as $node) {
                    ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                        'hosting_action' => 'create_hosting',
                        'hosting_data' => [
                            'domain'        => $sub['subdomain'],
                            'username'      => $result['username'],
                            'home_dir'      => $result['home_dir'],
                            'document_root' => $result['doc_root'],
                            'php_version'   => $account['php_version'] ?? '8.3',
                            'status'        => 'active',
                            'customer_id'   => $account['customer_id'] ?? null,
                        ],
                    ]);
                }
            }
            Flash::set('success', "Subdominio '{$sub['subdomain']}' promovido a cuenta independiente. Nuevo usuario: {$result['username']}");
        }
        Router::redirect('/accounts/' . $params['id']);
    }

    public function suspend(array $params): void
    {
        if ($this->slaveGuard('Suspender hostings')) return;

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        SystemService::suspendAccount($account['username'], $account['fpm_socket'], $account['domain'], $account['php_version']);
        Database::update('hosting_accounts', ['status' => 'suspended', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $params['id']]);

        // Suspend mail domain if requested
        $suspendMail = ($_POST['suspend_mail'] ?? '0') === '1';
        if ($suspendMail) {
            $mailDomain = Database::fetchOne(
                "SELECT id FROM mail_domains WHERE domain = :d AND status = 'active'",
                ['d' => $account['domain']]
            );
            if ($mailDomain) {
                MailService::updateDomain((int)$mailDomain['id'], ['status' => 'suspended']);
                // Suspend all accounts under this domain
                $mailAccounts = Database::fetchAll(
                    "SELECT id FROM mail_accounts WHERE mail_domain_id = :mid AND status = 'active'",
                    ['mid' => $mailDomain['id']]
                );
                foreach ($mailAccounts as $ma) {
                    MailService::updateAccount((int)$ma['id'], ['status' => 'suspended']);
                }
                LogService::log('mail.domain.suspend', $account['domain'], "Mail suspended with hosting (" . count($mailAccounts) . " accounts)");
            }
        }

        LogService::log('account.suspend', $account['domain'], "Suspended account");

        // Sync suspension to cluster nodes (only web nodes)
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            foreach (ClusterService::getWebNodes() as $node) {
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
        if ($this->slaveGuard('Activar hostings')) return;

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return;
        }

        SystemService::activateAccount($account['username'], $account['fpm_socket'], $account['domain'], $account['document_root'], $account['php_version']);
        Database::update('hosting_accounts', ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $params['id']]);

        // Reactivate mail domain if it was suspended
        $mailDomain = Database::fetchOne(
            "SELECT id FROM mail_domains WHERE domain = :d AND status = 'suspended'",
            ['d' => $account['domain']]
        );
        if ($mailDomain) {
            MailService::updateDomain((int)$mailDomain['id'], ['status' => 'active']);
            $suspendedAccounts = Database::fetchAll(
                "SELECT id FROM mail_accounts WHERE mail_domain_id = :mid AND status = 'suspended'",
                ['mid' => $mailDomain['id']]
            );
            foreach ($suspendedAccounts as $ma) {
                MailService::updateAccount((int)$ma['id'], ['status' => 'active']);
            }
            LogService::log('mail.domain.activate', $account['domain'], "Mail reactivated with hosting (" . count($suspendedAccounts) . " accounts)");
        }

        LogService::log('account.activate', $account['domain'], "Activated account");

        // Sync activation to cluster nodes (only web nodes)
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            foreach (ClusterService::getWebNodes() as $node) {
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

        // Accept home_dir from POST (for sites outside /var/www/vhosts/) or default
        $homeDir = trim($_POST['home_dir'] ?? '');
        if (empty($homeDir)) {
            $homeDir = "/var/www/vhosts/{$domain}";
        }
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

        // Accept document_root from POST (preserves original path) or auto-detect
        $documentRoot = trim($_POST['document_root'] ?? '');
        if (empty($documentRoot) || !is_dir($documentRoot)) {
            $documentRoot = $homeDir . '/httpdocs';
            if (!is_dir($documentRoot)) {
                $documentRoot = is_dir($homeDir . '/public') ? $homeDir . '/public' : $homeDir;
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

        // Detect existing Caddy route — or create one automatically
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

        // If no Caddy route exists, create one automatically
        if (!$caddyRouteId) {
            $caddyRouteId = SystemService::addCaddyRoute($domain, $documentRoot, $username, $phpVersion);
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

        // Auto-detect databases owned by this user (PostgreSQL port 5432)
        $detectedDbs = [];
        $pgQuery = "SELECT datname FROM pg_database WHERE datistemplate = false AND pg_get_userbyid(datdba) = " . escapeshellarg($username);
        $pgCmd = sprintf("sudo -u postgres psql -p 5432 -t -A -c %s 2>/dev/null", escapeshellarg($pgQuery));
        $pgOutput = trim((string)shell_exec($pgCmd));
        if (!empty($pgOutput)) {
            foreach (explode("\n", $pgOutput) as $dbName) {
                $dbName = trim($dbName);
                if (empty($dbName) || $dbName === 'postgres') continue;
                // Check not already registered
                $alreadyRegistered = Database::fetchOne("SELECT id FROM hosting_databases WHERE db_name = :n", ['n' => $dbName]);
                if (!$alreadyRegistered) {
                    Database::insert('hosting_databases', [
                        'account_id' => $id,
                        'db_name'    => $dbName,
                        'db_user'    => $username,
                        'db_type'    => 'pgsql',
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $detectedDbs[] = $dbName . ' (pgsql)';
                }
            }
        }

        // Auto-detect MySQL databases owned by this user
        try {
            $mysqlPdo = \MuseDockPanel\Services\ReplicationService::getMysqlPdo();
            if ($mysqlPdo) {
                // Find databases where the user has grants
                $mysqlUserCheck = $mysqlPdo->query("SELECT DISTINCT TABLE_SCHEMA FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE LIKE " . $mysqlPdo->quote("'$username%'"))->fetchAll(\PDO::FETCH_COLUMN);
                // Also check databases named with user prefix
                $allDbs = $mysqlPdo->query("SHOW DATABASES")->fetchAll(\PDO::FETCH_COLUMN);
                $mysqlSystemDbs = ['mysql', 'information_schema', 'performance_schema', 'sys'];
                foreach ($allDbs as $dbName) {
                    if (in_array($dbName, $mysqlSystemDbs)) continue;
                    // Match databases prefixed with the username (convention: username_suffix)
                    if (str_starts_with($dbName, str_replace('.', '_', $username) . '_') || in_array($dbName, $mysqlUserCheck)) {
                        $alreadyRegistered = Database::fetchOne("SELECT id FROM hosting_databases WHERE db_name = :n", ['n' => $dbName]);
                        if (!$alreadyRegistered) {
                            Database::insert('hosting_databases', [
                                'account_id' => $id,
                                'db_name'    => $dbName,
                                'db_user'    => $username,
                                'db_type'    => 'mysql',
                                'created_at' => date('Y-m-d H:i:s'),
                            ]);
                            $detectedDbs[] = $dbName . ' (mysql)';
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // MySQL not available, skip
        }

        // Sync to cluster nodes if master
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            $nodes = \MuseDockPanel\Services\ClusterService::getWebNodes();
            foreach ($nodes as $node) {
                \MuseDockPanel\Services\ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                    'hosting_action' => 'create_hosting',
                    'hosting_data' => [
                        'username'       => $username,
                        'domain'         => $domain,
                        'home_dir'       => $homeDir,
                        'document_root'  => $documentRoot,
                        'php_version'    => $phpVersion,
                        'password'       => '',
                        'password_hash'  => SystemService::getPasswordHash($username),
                        'shell'          => $shell,
                        'system_uid'     => $uid,
                        'caddy_route_id' => $caddyRouteId,
                        'disk_quota_mb'  => 0,
                        'description'    => 'Importado desde master (huerfano)',
                    ],
                ]);
            }
        }

        LogService::log('account.import', $domain, "Imported existing hosting: {$username}@{$domain}" . (!empty($detectedDbs) ? " | DBs: " . implode(', ', $detectedDbs) : ''));

        $msg = "Hosting {$domain} importado correctamente como {$username}.";
        if ($caddyRouteId) {
            $msg .= " Ruta Caddy configurada ({$caddyRouteId}).";
        } else {
            $msg .= " ⚠ No se pudo crear la ruta Caddy — configura manualmente.";
        }
        if (!empty($detectedDbs)) {
            $msg .= " Se detectaron y vincularon " . count($detectedDbs) . " base(s) de datos: " . implode(', ', $detectedDbs);
        }
        Flash::set('success', $msg);
        Router::redirect('/accounts');
    }

    private function discoverOrphanVhosts(): array
    {
        $vhostsDir = '/var/www/vhosts';
        $orphans = [];
        $discoveredDomains = [];

        // Get registered domains
        $registered = Database::fetchAll("SELECT domain FROM hosting_accounts");
        $registeredDomains = array_column($registered, 'domain');

        // Fetch Caddy routes once (used by both discovery methods)
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];
        $caddyRoutes = [];

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $routesJson = curl_exec($ch);
        curl_close($ch);

        if ($routesJson) {
            $caddyRoutes = json_decode($routesJson, true) ?: [];
        }

        // Helper: find Caddy route for a domain
        $findCaddyRoute = function (string $domain) use ($caddyRoutes): ?string {
            foreach ($caddyRoutes as $route) {
                $hosts = [];
                foreach ($route['match'] ?? [] as $match) {
                    $hosts = array_merge($hosts, $match['host'] ?? []);
                }
                if (in_array($domain, $hosts) || in_array("*.{$domain}", $hosts)) {
                    return $route['@id'] ?? 'si (sin @id)';
                }
            }
            return null;
        };

        // Helper: detect PHP version and FPM pool for a username
        $detectFpm = function (?string $username): array {
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
            return [$phpVersion, $fpmPool];
        };

        // --- Source 1: Scan /var/www/vhosts/ directories ---
        foreach (glob("{$vhostsDir}/*", GLOB_ONLYDIR) as $dir) {
            $domain = basename($dir);

            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain)) {
                continue;
            }
            if (in_array($domain, $registeredDomains)) {
                continue;
            }

            $stat = stat($dir);
            $ownerInfo = $stat ? posix_getpwuid($stat['uid']) : null;
            $username = $ownerInfo ? $ownerInfo['name'] : null;
            $uid = $stat ? $stat['uid'] : null;
            $shell = $ownerInfo ? ($ownerInfo['shell'] ?? '?') : '?';

            $docRoot = $dir . '/httpdocs';
            if (!is_dir($docRoot)) {
                $docRoot = is_dir($dir . '/public') ? $dir . '/public' : $dir;
            }

            [$phpVersion, $fpmPool] = $detectFpm($username);
            $caddyRoute = $findCaddyRoute($domain);

            $diskMb = SystemService::getDiskUsage($dir);

            $warnings = [];
            if (!$username) $warnings[] = 'No se pudo detectar el usuario propietario';
            if (!$fpmPool) $warnings[] = 'No se encontro pool FPM para este usuario';
            if (!$caddyRoute) $warnings[] = 'Sin ruta en Caddy — se creara automaticamente al importar';

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
                'source' => 'vhosts',
            ];
            $discoveredDomains[] = $domain;
        }

        // --- Source 2: Scan active Caddy routes for sites outside /var/www/vhosts/ ---
        foreach ($caddyRoutes as $route) {
            $routeId = $route['@id'] ?? null;
            // Skip panel routes and non-hosting routes
            if (!$routeId || !str_starts_with($routeId, 'hosting-')) {
                continue;
            }

            $hosts = [];
            foreach ($route['match'] ?? [] as $match) {
                $hosts = array_merge($hosts, $match['host'] ?? []);
            }
            // Use the first non-www host as the primary domain
            $domain = null;
            foreach ($hosts as $h) {
                if (!str_starts_with($h, 'www.') && !str_starts_with($h, '*.')) {
                    $domain = $h;
                    break;
                }
            }
            if (!$domain) continue;

            // Skip if already registered or already discovered from vhosts dir
            if (in_array($domain, $registeredDomains) || in_array($domain, $discoveredDomains)) {
                continue;
            }

            // Extract document root from the route config
            $docRoot = null;
            foreach ($route['handle'] ?? [] as $handler) {
                if (($handler['handler'] ?? '') === 'subroute') {
                    foreach ($handler['routes'] ?? [] as $subroute) {
                        foreach ($subroute['handle'] ?? [] as $h) {
                            if (($h['handler'] ?? '') === 'vars' && isset($h['root'])) {
                                $docRoot = $h['root'];
                                break 3;
                            }
                            if (($h['handler'] ?? '') === 'file_server' && isset($h['root'])) {
                                $docRoot = $h['root'];
                            }
                        }
                    }
                }
            }
            if (!$docRoot || !is_dir($docRoot)) continue;

            // Resolve home_dir: go up from docRoot if it ends with /httpdocs or /public
            $homeDir = $docRoot;
            if (preg_match('#^(.+)/(httpdocs|public|html)$#', $docRoot, $m) && is_dir($m[1])) {
                $homeDir = $m[1];
            }

            $stat = stat($homeDir);
            $ownerInfo = $stat ? posix_getpwuid($stat['uid']) : null;
            $username = $ownerInfo ? $ownerInfo['name'] : null;
            $uid = $stat ? $stat['uid'] : null;
            $shell = $ownerInfo ? ($ownerInfo['shell'] ?? '?') : '?';

            [$phpVersion, $fpmPool] = $detectFpm($username);
            $diskMb = SystemService::getDiskUsage($homeDir);

            $warnings = [];
            if (!$username) $warnings[] = 'No se pudo detectar el usuario propietario';
            if (!$fpmPool) $warnings[] = 'No se encontro pool FPM para este usuario';
            if ($homeDir !== "/var/www/vhosts/{$domain}") {
                $warnings[] = "Directorio fuera de /var/www/vhosts/ (migrado desde otro web server)";
            }

            $orphans[] = [
                'domain' => $domain,
                'home_dir' => $homeDir,
                'document_root' => $docRoot,
                'username' => $username,
                'uid' => $uid,
                'shell' => $shell,
                'php_version' => $phpVersion,
                'fpm_pool' => $fpmPool,
                'caddy_route' => $routeId,
                'disk_mb' => $diskMb,
                'warnings' => $warnings,
                'source' => 'caddy',
            ];
            $discoveredDomains[] = $domain;
        }

        return $orphans;
    }

    public function updatePhp(array $params): void
    {
        if ($this->slaveGuard('Cambiar PHP de hostings')) return;

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

    public function stats(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { Flash::set('error', 'Cuenta no encontrada.'); Router::redirect('/accounts'); return; }

        $range = $_GET['range'] ?? '7d';
        $allowed = ['1d', '7d', '30d', '1y'];
        if (!in_array($range, $allowed)) $range = '7d';

        $stats = \MuseDockPanel\Services\WebStatsService::getStats((int)$account['id'], $range);
        $bwMonthly = \MuseDockPanel\Services\BandwidthService::getMonthlyTotal((int)$account['id']);

        View::render('accounts/stats', [
            'layout' => 'main',
            'pageTitle' => 'Stats: ' . $account['domain'],
            'account' => $account,
            'stats' => $stats,
            'bwMonthly' => $bwMonthly,
            'currentRange' => $range,
        ]);
    }

    public function apiBandwidth(array $params): void
    {
        header('Content-Type: application/json');
        $range = $_GET['range'] ?? '24h';
        $allowed = ['1h', '6h', '24h', '7d', '30d', '1y'];
        if (!in_array($range, $allowed)) $range = '24h';
        $data = \MuseDockPanel\Services\BandwidthService::getByAccount((int)$params['id'], $range);
        echo json_encode(['ok' => true, 'data' => $data, 'range' => $range]);
        exit;
    }

    public function delete(array $params): void
    {
        if ($this->slaveGuard('Eliminar hostings')) return;

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

        // Verify admin password
        $password = $_POST['admin_password'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? $_SESSION['panel_user']['id'] ?? 0;
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Flash::set('error', 'Contraseña incorrecta.');
            Router::redirect('/accounts/' . $params['id']);
            return;
        }

        $deleteFiles = ($_POST['delete_files'] ?? '0') === '1';
        $deleteDatabases = ($_POST['delete_databases'] ?? '0') === '1';
        $deleteMail = ($_POST['delete_mail'] ?? '0') === '1';

        // Clean up Caddy redirect routes before account deletion (aliases cascade in DB)
        $redirects = \MuseDockPanel\Services\DomainAliasService::getRedirects((int)$account['id']);
        foreach ($redirects as $r) {
            SystemService::removeCaddyRedirectRoute($r['domain']);
        }

        // Delete subdomains (Caddy routes, optionally files)
        $subdomains = SubdomainService::getAll((int)$account['id']);
        foreach ($subdomains as $sub) {
            SubdomainService::delete((int)$sub['id'], $deleteFiles);
        }

        // Delete databases if requested
        $databases = Database::fetchAll("SELECT * FROM hosting_databases WHERE account_id = :id", ['id' => $account['id']]);
        if ($deleteDatabases) {
            foreach ($databases as $db) {
                $dbType = $db['db_type'] ?? 'mysql';
                if ($dbType === 'pgsql') {
                    shell_exec(sprintf('sudo -u postgres psql -c %s 2>&1', escapeshellarg("DROP DATABASE IF EXISTS \"{$db['db_name']}\" WITH (FORCE);")));
                    shell_exec(sprintf('sudo -u postgres psql -c %s 2>&1', escapeshellarg("DROP USER IF EXISTS \"{$db['db_user']}\";")));
                } else {
                    $mysqlCmd = 'mysql';
                    if (file_exists('/etc/mysql/debian.cnf')) {
                        $mysqlCmd = 'mysql --defaults-file=/etc/mysql/debian.cnf';
                    }
                    $sql = "DROP DATABASE IF EXISTS `{$db['db_name']}`; DROP USER IF EXISTS '{$db['db_user']}'@'localhost'; FLUSH PRIVILEGES;";
                    shell_exec(sprintf('%s -e %s 2>&1', $mysqlCmd, escapeshellarg($sql)));
                }
            }
            LogService::log('database.delete', $account['domain'], "Deleted " . count($databases) . " database(s) with hosting account");
        }

        SystemService::deleteAccount($account['username'], $account['domain'], $account['home_dir']);

        // Delete files if requested
        if ($deleteFiles) {
            $homeDir = rtrim($account['home_dir'], '/');
            if (str_contains($homeDir, '/var/www/vhosts/') && substr_count($homeDir, '/') >= 4) {
                shell_exec(sprintf('rm -rf %s 2>&1', escapeshellarg($homeDir)));
                LogService::log('account.delete', $account['domain'], "Home directory deleted: {$homeDir}");
            }
        }

        // Delete associated mail domain if requested
        $mailDomain = Database::fetchOne(
            "SELECT id, domain FROM mail_domains WHERE domain = :d",
            ['d' => $account['domain']]
        );
        if ($mailDomain && $deleteMail) {
            MailService::deleteDomain((int)$mailDomain['id']);
            LogService::log('mail.domain.delete', $account['domain'], "Mail domain deleted with hosting account");
        } elseif ($mailDomain) {
            LogService::log('account.delete', $account['domain'], "Hosting deleted but mail domain preserved (mail_domain #{$mailDomain['id']})");
        }

        Database::delete('hosting_accounts', 'id = :id', ['id' => $params['id']]);

        $logParts = ["Deleted account and system user: {$account['username']}"];
        if ($deleteFiles) $logParts[] = 'files removed';
        if ($deleteDatabases) $logParts[] = count($databases) . ' DB(s) dropped';
        if ($deleteMail && $mailDomain) $logParts[] = 'mail domain removed';
        LogService::log('account.delete', $account['domain'], implode(', ', $logParts));

        // Sync deletion to cluster nodes (only web nodes)
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            foreach (ClusterService::getWebNodes() as $node) {
                ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                    'hosting_action' => 'delete_hosting',
                    'hosting_data' => [
                        'username'         => $account['username'],
                        'domain'           => $account['domain'],
                        'home_dir'         => $account['home_dir'],
                        'delete_files'     => $deleteFiles,
                        'delete_databases' => $deleteDatabases,
                        'delete_mail'      => $deleteMail,
                    ],
                ]);
            }
        }

        Flash::set('success', "Cuenta {$account['domain']} eliminada correctamente.");
        Router::redirect('/accounts');
    }
}
