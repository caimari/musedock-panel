<?php
namespace MuseDockPanel\Services;

/**
 * SystemService - Manages Linux users, directories, PHP-FPM pools, and Caddy routes.
 *
 * Panel runs as root — no sudo needed.
 */
class SystemService
{
    private static array $allowedPhpVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];

    /**
     * Dedicated UID range for HOSTING system users, kept clear of the OS/admin
     * range (1000-9999). Reserving a high band means a hosting UID assigned on the
     * master is almost always free on every slave, so cluster sync can reproduce
     * the SAME UID everywhere and file ownership stays consistent across nodes.
     * (login.defs default UID_MAX is 60000, so 20000-59999 is safely inside it.)
     */
    public const HOSTING_UID_MIN = 20000;
    public const HOSTING_UID_MAX = 59999;

    private const PANEL_ADMIN_SERVER_ID = 'srv_panel_admin';
    private const PANEL_DOMAIN_ROUTE_ID = 'panel-domain-route';
    private const PANEL_DOMAIN_HTTPS_ROUTE_ID = 'panel-domain-https-route';
    private const PANEL_FALLBACK_ROUTE_ID = 'panel-fallback-route';
    private static ?bool $hasCloudflareDnsProvider = null;
    private static array $dnsProviderAvailability = [];
    private static ?array $installedDnsProviders = null;

    /**
     * Generate a unique Caddy route ID for a domain.
     * Uses domain-based IDs to avoid collisions when subdomains share a username.
     */
    public static function caddyRouteId(string $domain): string
    {
        return 'hosting-' . preg_replace('/[^a-z0-9]/', '', strtolower($domain));
    }

    /**
     * Validate and sanitize PHP version string
     */
    private static function safePhpVersion(string $version): string
    {
        if (!in_array($version, self::$allowedPhpVersions, true)) {
            return '8.3'; // safe default
        }
        return $version;
    }

    /**
     * Create a full hosting account:
     * 1. Linux user
     * 2. Directory structure
     * 3. PHP-FPM pool
     * 4. Caddy route
     * 5. Default index.html
     */
    public static function createAccount(string $username, string $domain, string $homeDir, string $documentRoot, string $phpVersion = '8.3', string $password = '', string $shell = '/usr/sbin/nologin', ?int $forceUid = null): array
    {
        $errors = [];

        // 0. Validación central (todos los puntos de entrada: panel, API cluster, federación, MCP).
        // Dominio, rutas y versión PHP acaban en comandos, Caddy y ficheros de configuración.
        if (strlen($domain) > 253
            || !preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/i', $domain)) {
            return ['success' => false, 'error' => 'Dominio no válido.'];
        }
        foreach ([$homeDir, $documentRoot] as $p) {
            if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $p) || str_contains($p, '..')) {
                return ['success' => false, 'error' => 'Ruta no válida.'];
            }
        }

        // 1. Create Linux system user (with forced UID for cluster sync)
        $uid = self::createSystemUser($username, $homeDir, $shell, $forceUid);
        if ($uid === null) {
            return ['success' => false, 'error' => "Failed to create system user: {$username}"];
        }

        // Set password if provided
        if (!empty($password)) {
            self::setUserPassword($username, $password);
        }

        // 2. Create directory structure
        if (!self::createDirectories($username, $homeDir, $documentRoot)) {
            return ['success' => false, 'error' => "Failed to create directories for: {$domain}"];
        }

        // 3. Create default index.html
        self::createDefaultPage($documentRoot, $domain);

        // 4. Create PHP-FPM pool
        $fpmSocket = self::createFpmPool($username, $phpVersion, $homeDir);
        if (!$fpmSocket) {
            $errors[] = "Warning: FPM pool creation failed. Create manually.";
        }

        // 5. Add Caddy route
        $caddyRouteId = self::addCaddyRoute($domain, $documentRoot, $username, $phpVersion);
        if (!$caddyRouteId) {
            $errors[] = "Warning: Caddy route creation failed. Add manually.";
        }

        // 6. Final chown to ensure all files belong to the user
        shell_exec(sprintf('chown -R %s:www-data %s 2>&1', escapeshellarg($username), escapeshellarg($homeDir)));

        // 7. Restart lsyncd so it picks up the new vhost directory
        self::restartLsyncd();

        return [
            'success' => true,
            'uid' => $uid,
            'caddy_route_id' => $caddyRouteId,
            'warnings' => $errors,
        ];
    }

    /**
     * Create a Linux system user with home directory
     */
    public static function createSystemUser(string $username, string $homeDir, string $shell = '/usr/sbin/nologin', ?int $forceUid = null): ?int
    {
        // Validate username format (alphanumeric, underscore, hyphen, max 32 chars)
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/i', $username)) {
            return null;
        }

        // Check if user already exists
        $check = shell_exec(sprintf('id -u %s 2>/dev/null', escapeshellarg($username)));
        if ($check !== null && trim($check) !== '') {
            return (int) trim($check);
        }

        // Decide the UID to use.
        //  - forceUid set (cluster sync from master): reproduce the master's UID
        //    exactly. If it's taken by ANOTHER user we must NOT silently drift —
        //    record the conflict AND still keep the divergent account inside the
        //    dedicated hosting band (never let it land in the OS/admin range 1000+).
        //  - forceUid not set (fresh account on the master): pick the next free UID
        //    from the dedicated hosting band so slaves can reproduce it later.
        $chosenUid = null;
        if ($forceUid !== null && $forceUid > 0) {
            $uidCheck = trim((string)shell_exec(sprintf('getent passwd %d 2>/dev/null', $forceUid)));
            if ($uidCheck === '') {
                $chosenUid = $forceUid;
            } else {
                // UID collision: the master's UID is used by a different account here.
                // Keep the divergent user inside the hosting band anyway (fix #2) and
                // record it. Logging must NEVER break user creation (fix #6).
                $chosenUid = self::nextFreeHostingUid();
                self::safeLog(
                    'system.uid_conflict',
                    $username,
                    "El UID {$forceUid} del master ya está ocupado en este nodo por: {$uidCheck}. "
                    . "Se asigna " . ($chosenUid ?? 'auto') . " (divergente); requiere reconciliación."
                );
            }
        } elseif ($forceUid === null) {
            // Fresh account: allocate from the dedicated hosting band.
            $chosenUid = self::nextFreeHostingUid();
        }

        // Band exhausted (fix #3): surface it — 40k UIDs used up is an operational
        // event, and a hosting user landing in 1000+ would break cluster sync.
        if ($chosenUid === null) {
            self::safeLog('system.uid_band_exhausted', $username,
                "Banda de UID de hosting (" . self::HOSTING_UID_MIN . "-" . self::HOSTING_UID_MAX
                . ") agotada. El usuario se creará con UID del rango por defecto — riesgo de divergencia.");
        }

        // Force the primary GID to equal the UID (fix #4): with -u N + -g N +
        // --user-group, the per-user group gets the SAME number as the UID on every
        // node, so numeric owner:group in tar/rsync/backups stays consistent. Create
        // the group first (idempotent) so useradd -g can reference it.
        $uidFlag = '';
        if ($chosenUid !== null) {
            shell_exec(sprintf('getent group %1$d >/dev/null 2>&1 || groupadd -g %1$d %2$s 2>/dev/null',
                $chosenUid, escapeshellarg($username)));
            $uidFlag = sprintf(' -u %d -g %d', $chosenUid, $chosenUid);
        }

        $cmd = sprintf(
            'useradd -m -d %s -s %s%s -G www-data %s 2>&1',
            escapeshellarg($homeDir),
            escapeshellarg($shell),
            $uidFlag,
            escapeshellarg($username)
        );
        shell_exec($cmd);

        $uid = shell_exec(sprintf('id -u %s 2>/dev/null', escapeshellarg($username)));
        return $uid ? (int) trim($uid) : null;
    }

    /**
     * Log without ever letting a logging failure break the caller. Used on the
     * system-user creation path, where a transient panel-DB outage during cluster
     * sync must not abort account creation.
     */
    private static function safeLog(string $event, string $target, string $message): void
    {
        try {
            LogService::log($event, $target, $message);
        } catch (\Throwable $e) {
            error_log("musedock safeLog({$event}): " . $e->getMessage());
        }
    }

    /**
     * Next free UID in the dedicated hosting band (HOSTING_UID_MIN..MAX). Scans the
     * existing passwd entries once and returns the first gap. Returns null if the
     * band is exhausted (caller then logs and lets useradd pick).
     */
    public static function nextFreeHostingUid(): ?int
    {
        $taken = [];
        $out = (string)shell_exec("getent passwd | awk -F: '{print $3}'");
        foreach (preg_split('/\s+/', trim($out)) as $u) {
            if ($u === '') continue;
            $n = (int)$u;
            if ($n >= self::HOSTING_UID_MIN && $n <= self::HOSTING_UID_MAX) {
                $taken[$n] = true;
            }
        }
        for ($uid = self::HOSTING_UID_MIN; $uid <= self::HOSTING_UID_MAX; $uid++) {
            if (empty($taken[$uid])) return $uid;
        }
        return null;
    }

    /**
     * Set password for a Linux user
     */
    public static function setUserPassword(string $username, string $password): bool
    {
        // usuario:contraseña por stdin de chpasswd (no en la línea de comandos, visible con ps)
        DatabaseService::runWithStdin(
            'chpasswd 2>&1',
            str_replace(["\r", "\n"], '', $username) . ':' . str_replace(["\r", "\n"], '', $password) . "\n"
        );
        return true;
    }

    /**
     * Get the password hash from /etc/shadow for a system user.
     */
    public static function getPasswordHash(string $username): string
    {
        $line = trim((string)shell_exec(sprintf('getent shadow %s 2>/dev/null', escapeshellarg($username))));
        if (!$line) return '';
        $parts = explode(':', $line);
        return $parts[1] ?? '';
    }

    /**
     * Set a pre-hashed password directly in /etc/shadow (for cluster sync).
     */
    public static function setPasswordHash(string $username, string $hash): bool
    {
        if (empty($hash) || $hash === '!' || $hash === '*' || $hash === '!!') {
            return false;
        }
        $cmd = sprintf(
            'usermod -p %s %s 2>&1',
            escapeshellarg($hash),
            escapeshellarg($username)
        );
        shell_exec($cmd);
        return true;
    }

    /**
     * Get system user info: uid, gid, groups, shell, home, password hash.
     */
    public static function getUserInfo(string $username): ?array
    {
        $passwd = trim((string)shell_exec(sprintf('getent passwd %s 2>/dev/null', escapeshellarg($username))));
        if (!$passwd) return null;

        $parts = explode(':', $passwd);
        $groups = trim((string)shell_exec(sprintf('id -Gn %s 2>/dev/null', escapeshellarg($username))));

        return [
            'username' => $parts[0],
            'uid'      => (int)($parts[2] ?? 0),
            'gid'      => (int)($parts[3] ?? 0),
            'home'     => $parts[5] ?? '',
            'shell'    => $parts[6] ?? '',
            'groups'   => $groups ? explode(' ', $groups) : [],
            'password_hash' => self::getPasswordHash($parts[0]),
        ];
    }

    /**
     * Repair a system user to match expected UID, shell, groups, and password.
     * Returns list of changes made.
     */
    public static function repairSystemUser(string $username, ?int $expectedUid, string $expectedShell, string $expectedPasswordHash = '', array $expectedGroups = ['www-data']): array
    {
        $info = self::getUserInfo($username);
        if (!$info) return ['error' => 'User does not exist'];

        $changes = [];

        // Fix UID if wrong
        if ($expectedUid !== null && $expectedUid > 0 && $info['uid'] !== $expectedUid) {
            // Check if target UID is free
            $uidCheck = trim((string)shell_exec(sprintf('getent passwd %d 2>/dev/null', $expectedUid)));
            if (empty($uidCheck)) {
                $oldUid = $info['uid'];
                shell_exec(sprintf('usermod -u %d %s 2>&1', $expectedUid, escapeshellarg($username)));
                // Fix file ownership in home dir
                $home = $info['home'];
                if ($home && is_dir($home)) {
                    shell_exec(sprintf('find %s -user %d -exec chown %d {} + 2>/dev/null', escapeshellarg($home), $oldUid, $expectedUid));
                }
                $changes[] = "UID: {$oldUid} → {$expectedUid}";
            } else {
                $changes[] = "UID: no se pudo cambiar a {$expectedUid} (ya en uso)";
            }
        }

        // Fix shell if wrong
        if (!empty($expectedShell) && $info['shell'] !== $expectedShell) {
            shell_exec(sprintf('usermod -s %s %s 2>&1', escapeshellarg($expectedShell), escapeshellarg($username)));
            $changes[] = "Shell: {$info['shell']} → {$expectedShell}";
        }

        // Fix groups
        foreach ($expectedGroups as $group) {
            if (!in_array($group, $info['groups'])) {
                shell_exec(sprintf('usermod -aG %s %s 2>&1', escapeshellarg($group), escapeshellarg($username)));
                $changes[] = "Grupo añadido: {$group}";
            }
        }

        // Fix password hash if provided and different
        if (!empty($expectedPasswordHash) && $expectedPasswordHash !== '!' && $expectedPasswordHash !== '*') {
            $currentHash = $info['password_hash'];
            if ($currentHash !== $expectedPasswordHash) {
                self::setPasswordHash($username, $expectedPasswordHash);
                $changes[] = "Password hash sincronizado";
            }
        }

        return $changes ?: ['sin cambios'];
    }

    /**
     * Create directory structure for a hosting account
     */
    public static function createDirectories(string $username, string $homeDir, string $documentRoot): bool
    {
        $dirs = [
            $homeDir,
            $documentRoot,
            "{$homeDir}/httpdocs",
            "{$homeDir}/logs",
            "{$homeDir}/tmp",
            "{$homeDir}/sessions",
        ];

        foreach ($dirs as $dir) {
            shell_exec(sprintf('mkdir -p %s 2>&1', escapeshellarg($dir)));
        }

        // Set ownership
        shell_exec(sprintf('chown -R %s:www-data %s 2>&1', escapeshellarg($username), escapeshellarg($homeDir)));

        // Set permissions
        shell_exec(sprintf('chmod 750 %s 2>&1', escapeshellarg($homeDir)));
        shell_exec(sprintf('chmod -R 755 %s 2>&1', escapeshellarg($documentRoot)));

        return is_dir($homeDir);
    }

    /**
     * Create a default index.html page
     */
    public static function createDefaultPage(string $documentRoot, string $domain): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$domain} — Hosted by MuseDock Panel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
               display: flex; justify-content: center; align-items: center; min-height: 100vh;
               background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #e2e8f0; }
        .card { text-align: center; padding: 3rem; background: rgba(255,255,255,0.05);
                border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); max-width: 500px; }
        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; color: #38bdf8; }
        p { color: #94a3b8; font-size: 0.95rem; }
        .domain { font-size: 1.1rem; color: #f1f5f9; margin-bottom: 1rem; }
        .badge { display: inline-block; padding: 4px 12px; background: rgba(56,189,248,0.15);
                 border-radius: 20px; font-size: 0.75rem; color: #38bdf8; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="domain">{$domain}</div>
        <h1>Your site is ready</h1>
        <p>Upload your files to get started.</p>
        <div class="badge">MuseDock Panel</div>
    </div>
</body>
</html>
HTML;

        file_put_contents("{$documentRoot}/index.html", $html);
        // Get the username from the home dir path
        $parentDir = dirname($documentRoot);
        $owner = trim(shell_exec(sprintf('stat -c %%U %s 2>/dev/null', escapeshellarg($parentDir))) ?: 'www-data');
        shell_exec(sprintf('chown %s:www-data %s/index.html 2>&1', escapeshellarg($owner), escapeshellarg($documentRoot)));
    }

    /**
     * Create a PHP-FPM pool configuration
     */
    public static function createFpmPool(string $username, string $phpVersion = '8.3', string $homeDir = ''): ?string
    {
        $phpVersion = self::safePhpVersion($phpVersion);
        if (empty($homeDir)) {
            $homeDir = "/var/www/vhosts/{$username}";
        }
        // Usuario y ruta se interpolan en el fichero del pool: sin saltos de línea ni caracteres raros
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/i', $username)
            || !preg_match('#^/[A-Za-z0-9._/-]+$#', $homeDir) || str_contains($homeDir, '..')) {
            return null;
        }
        $socketPath = "/run/php/php{$phpVersion}-fpm-{$username}.sock";
        $poolConfig = <<<CONF
[{$username}]
user = {$username}
group = www-data
listen = {$socketPath}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
pm.max_requests = 500
request_terminate_timeout = 120

php_admin_value[open_basedir] = {$homeDir}/:/tmp/:/usr/share/php/
php_admin_value[upload_tmp_dir] = {$homeDir}/tmp
php_admin_value[session.save_path] = {$homeDir}/sessions
php_admin_value[sys_temp_dir] = {$homeDir}/tmp
php_admin_value[error_log] = {$homeDir}/logs/php-error.log

php_admin_flag[log_errors] = on
php_value[max_execution_time] = 60
php_value[memory_limit] = 128M
php_value[post_max_size] = 64M
php_value[upload_max_filesize] = 64M

security.limit_extensions = .php
CONF;

        $poolDir = "/etc/php/{$phpVersion}/fpm/pool.d";
        file_put_contents("{$poolDir}/{$username}.conf", $poolConfig);

        // Reload FPM
        shell_exec(sprintf('systemctl reload php%s-fpm 2>&1', self::safePhpVersion($phpVersion)));

        return $socketPath;
    }

    /**
     * Ensure Caddy has a default TLS automation policy for non-musedock hosting domains.
     * Supports both HTTP-01 (direct domains) and DNS-01 (Cloudflare proxied domains).
     * DNS-01 uses IPv4 resolvers because IPv6 is disabled on this server.
     */
    /**
     * Ensure Caddy TLS policies are complete and correct.
     * This method is IDEMPOTENT — it always builds the full correct state from scratch,
     * regardless of what's currently in Caddy. Safe to call after caddy reload, restart, etc.
     *
     * Policy structure:
     * 1. Per-account policies: each CF account's domains get DNS-01 with their specific token
     * 2. Panel IP fallback policy: internal issuer for server IPs (safe emergency admin access)
     * 3. Catch-all: HTTP-01 first (for non-CF domains), DNS-01 with primary token as fallback
     */
    public static function ensureTlsCatchAllPolicy(string $caddyApi): void
    {
        $canUseCloudflareDns = self::caddyHasCloudflareDnsProvider();

        // Read primary Cloudflare API token
        $cfToken = trim(getenv('CLOUDFLARE_API_TOKEN') ?: '');
        if (!$cfToken) {
            $caddyEnv = @file_get_contents('/etc/default/caddy');
            if ($caddyEnv && preg_match('/^CLOUDFLARE_API_TOKEN=(.+)$/m', $caddyEnv, $m)) {
                $cfToken = trim($m[1]);
            }
        }
        if (!$canUseCloudflareDns) {
            $cfToken = '';
        }

        // Build policies from scratch (idempotent — doesn't depend on current state)
        $newPolicies = [];

        // Per-account policies for each CF account with a different token
        $cfAccounts = CloudflareService::getConfiguredAccounts();
        foreach ($cfAccounts as $acct) {
            if (!$canUseCloudflareDns) {
                // Per-account policies depend on DNS challenge provider.
                continue;
            }

            $token = $acct['token'] ?? '';
            if (!$token || $token === $cfToken) continue;

            $zones = $acct['zones'] ?? [];
            if (empty($zones)) continue;

            $subjects = [];
            foreach ($zones as $zone) {
                $zoneName = $zone['name'] ?? '';
                if (!$zoneName) continue;
                $subjects[] = $zoneName;
                $subjects[] = '*.' . $zoneName;
            }
            if (!empty($subjects)) {
                $newPolicies[] = self::buildCfPolicy($token, $subjects, true);
            }
        }

        // Dedicated policy for panel IP access (e.g. https://SERVER_IP:8444)
        // This avoids lockouts when ACME cannot issue certificates.
        $panelIpPolicy = self::buildPanelIpFallbackPolicy();
        if ($panelIpPolicy !== null) {
            $newPolicies[] = $panelIpPolicy;
        }

        // Catch-all: HTTP-01 first (non-CF domains), DNS-01 fallback (CF domains with primary token)
        $newPolicies[] = self::buildCfPolicy($cfToken, [], $canUseCloudflareDns);

        self::patchTlsPolicies($caddyApi, $newPolicies);
    }

    private static function buildPanelIpFallbackPolicy(): ?array
    {
        $subjects = [];

        $storedServerIp = trim((string)\MuseDockPanel\Settings::get('server_ip', ''));
        if ($storedServerIp !== '' && filter_var($storedServerIp, FILTER_VALIDATE_IP)) {
            $subjects[$storedServerIp] = true;
        }

        $rawIps = trim((string)shell_exec('hostname -I 2>/dev/null'));
        if ($rawIps !== '') {
            $detectedIps = preg_split('/\s+/', $rawIps) ?: [];
            foreach ($detectedIps as $ip) {
                $ip = trim((string)$ip);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $subjects[$ip] = true;
                }
            }
        }

        // Always keep local loopback available for local diagnostics/recovery.
        $subjects['127.0.0.1'] = true;
        $subjects['localhost'] = true;

        $subjects = array_keys($subjects);
        if (empty($subjects)) {
            return null;
        }

        return [
            'subjects' => $subjects,
            'issuers' => [[
                'module' => 'internal',
            ]],
        ];
    }

    private static function buildCfPolicy(string $cfToken, array $subjects = [], bool $allowDns = true): array
    {
        if (!empty($subjects)) {
            // Per-account policy: DNS-01 only (domains behind Cloudflare proxy)
            $acmeIssuer = ['email' => self::acmeEmail(), 'module' => 'acme'];
            if ($allowDns && $cfToken) {
                $acmeIssuer['challenges'] = [
                    'dns' => [
                        'provider' => ['name' => 'cloudflare', 'api_token' => $cfToken],
                        'resolvers' => ['1.1.1.1:53', '8.8.8.8:53']
                    ]
                ];
            }
            return ['subjects' => $subjects, 'issuers' => [$acmeIssuer]];
        }

        // Catch-all policy: HTTP-01 first (direct domains), then DNS-01 as fallback
        $httpIssuer = ['email' => self::acmeEmail(), 'module' => 'acme'];
        $issuers = [$httpIssuer];

        if ($allowDns && $cfToken) {
            $dnsIssuer = [
                'email' => self::acmeEmail(),
                'module' => 'acme',
                'challenges' => [
                    'dns' => [
                        'provider' => ['name' => 'cloudflare', 'api_token' => $cfToken],
                        'resolvers' => ['1.1.1.1:53', '8.8.8.8:53']
                    ]
                ]
            ];
            $issuers[] = $dnsIssuer;
        }

        return ['issuers' => $issuers];
    }

    private static function caddyHasCloudflareDnsProvider(): bool
    {
        if (self::$hasCloudflareDnsProvider === null) {
            self::$hasCloudflareDnsProvider = self::caddyHasDnsProvider('cloudflare');
        }
        return self::$hasCloudflareDnsProvider;
    }

    public static function installedDnsProviders(): array
    {
        if (self::$installedDnsProviders !== null) {
            return self::$installedDnsProviders;
        }

        $out = trim((string)shell_exec('caddy list-modules 2>/dev/null'));
        $providers = [];
        foreach (preg_split('/\R+/', $out) ?: [] as $line) {
            $line = strtolower(trim((string)$line));
            if (!str_starts_with($line, 'dns.providers.')) {
                continue;
            }
            $name = substr($line, strlen('dns.providers.'));
            if ($name !== '' && preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/', $name)) {
                $providers[$name] = true;
                self::$dnsProviderAvailability[$name] = true;
                if ($name === 'cloudflare') {
                    self::$hasCloudflareDnsProvider = true;
                }
            }
        }

        self::$installedDnsProviders = array_keys($providers);
        sort(self::$installedDnsProviders, SORT_NATURAL);
        return self::$installedDnsProviders;
    }

    public static function isDnsProviderInstalled(string $provider): bool
    {
        return self::caddyHasDnsProvider($provider);
    }

    public static function dnsProviderCatalog(): array
    {
        return [
            'cloudflare' => [
                'label' => 'Cloudflare',
                'example' => '{"api_token":"..."}',
                'module' => 'github.com/caddy-dns/cloudflare',
                'required' => ['api_token'],
            ],
            'digitalocean' => [
                'label' => 'DigitalOcean',
                'example' => '{"token":"..."}',
                'module' => 'github.com/caddy-dns/digitalocean',
                'required' => ['token'],
            ],
            'route53' => [
                'label' => 'Amazon Route53',
                'example' => '{"access_key_id":"...","secret_access_key":"...","region":"us-east-1"}',
                'module' => 'github.com/caddy-dns/route53',
                'required' => ['access_key_id', 'secret_access_key'],
            ],
            'hetzner' => [
                'label' => 'Hetzner DNS',
                'example' => '{"api_token":"..."}',
                'module' => 'github.com/caddy-dns/hetzner',
                'required' => ['api_token'],
            ],
            'ovh' => [
                'label' => 'OVH',
                'example' => '{"endpoint":"ovh-eu","application_key":"...","application_secret":"...","consumer_key":"..."}',
                'module' => 'github.com/caddy-dns/ovh',
                'required' => ['endpoint', 'application_key', 'application_secret', 'consumer_key'],
            ],
            'vultr' => [
                'label' => 'Vultr',
                'example' => '{"api_token":"..."}',
                'module' => 'github.com/caddy-dns/vultr',
                'required' => ['api_token'],
            ],
            'linode' => [
                'label' => 'Linode',
                'example' => '{"token":"..."}',
                'module' => 'github.com/caddy-dns/linode',
                'required' => ['token'],
            ],
            'porkbun' => [
                'label' => 'Porkbun',
                'example' => '{"api_key":"...","secret_api_key":"..."}',
                'module' => 'github.com/caddy-dns/porkbun',
                'required' => ['api_key', 'secret_api_key'],
            ],
            'namecheap' => [
                'label' => 'Namecheap',
                'example' => '{"api_user":"...","api_key":"..."}',
                'module' => 'github.com/caddy-dns/namecheap',
                'required' => ['api_user', 'api_key'],
            ],
            'gandi' => [
                'label' => 'Gandi',
                'example' => '{"api_token":"..."}',
                'module' => 'github.com/caddy-dns/gandi',
                'required' => ['api_token'],
            ],
            'powerdns' => [
                'label' => 'PowerDNS',
                'example' => '{"server_url":"https://dns.example.com","api_token":"..."}',
                'module' => 'github.com/caddy-dns/powerdns',
                'required' => ['server_url', 'api_token'],
            ],
            'rfc2136' => [
                'label' => 'RFC2136 / BIND',
                'example' => '{"key_name":"...","key_alg":"hmac-sha256","key":"...","server":"127.0.0.1:53"}',
                'module' => 'github.com/caddy-dns/rfc2136',
                'required' => ['key_name', 'key_alg', 'key', 'server'],
            ],
        ];
    }

    /**
     * Start the DNS-provider build in the BACKGROUND (detached) and return a
     * task id immediately. xcaddy takes minutes; PHP-FPM kills requests at 120s,
     * so the build must not run inline. The detached CLI (nohup + setsid) keeps
     * running after the HTTP request returns. Poll with caddyDnsBuildStatus().
     */
    public static function startCaddyDnsProviderBuild(string $provider): array
    {
        $provider = strtolower(trim($provider));
        if ($provider === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/', $provider)) {
            return ['ok' => false, 'error' => 'Proveedor DNS invalido'];
        }
        if (self::caddyHasDnsProvider($provider)) {
            return ['ok' => true, 'installed' => false, 'status' => 'done', 'message' => "dns.providers.{$provider} ya esta instalado"];
        }
        $taskId = 'caddybuild-' . $provider . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        $dir = '/var/lib/musedock';
        @mkdir($dir, 0750, true);
        $statusFile = "{$dir}/caddy-build-" . preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId) . '.json';
        @file_put_contents($statusFile, json_encode(['status' => 'building', 'provider' => $provider, 'started_at' => gmdate('c')]));
        $cmd = sprintf(
            'setsid nohup php %s %s %s > /dev/null 2>&1 &',
            escapeshellarg(PANEL_ROOT . '/bin/caddy-build-run.php'),
            escapeshellarg($provider),
            escapeshellarg($taskId)
        );
        shell_exec($cmd);
        return ['ok' => true, 'status' => 'building', 'task_id' => $taskId, 'message' => "Compilando dns.providers.{$provider} en segundo plano"];
    }

    /** Read the status of an async DNS-provider build launched above. */
    public static function caddyDnsBuildStatus(string $taskId): array
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId);
        if ($safe === '') return ['status' => 'unknown', 'error' => 'task_id vacío'];
        $statusFile = "/var/lib/musedock/caddy-build-{$safe}.json";
        if (!is_file($statusFile)) {
            return ['status' => 'unknown', 'error' => 'build no encontrado'];
        }
        $data = json_decode((string)@file_get_contents($statusFile), true);
        return is_array($data) ? $data : ['status' => 'unknown'];
    }

    /**
     * $force: recompilar aunque el módulo ya esté (para actualizarlo a su última
     * versión; p. ej. caddy-dns/cloudflare < v0.2.4 rechaza los tokens nuevos cfut_/cfat_).
     */
    public static function installCaddyDnsProvider(string $provider, bool $force = false): array
    {
        $provider = strtolower(trim($provider));
        if ($provider === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/', $provider)) {
            return ['ok' => false, 'error' => 'Proveedor DNS invalido'];
        }
        if (!$force && self::caddyHasDnsProvider($provider)) {
            return ['ok' => true, 'installed' => false, 'message' => "dns.providers.{$provider} ya esta instalado"];
        }

        $catalog = self::dnsProviderCatalog();
        $modulePackage = (string)($catalog[$provider]['module'] ?? '');
        if ($modulePackage === '') {
            return ['ok' => false, 'error' => "Proveedor {$provider} no esta en el catalogo instalable de MuseDock"];
        }

        $caddyPath = trim((string)shell_exec('command -v caddy 2>/dev/null'));
        if ($caddyPath === '' || !is_file($caddyPath)) {
            return ['ok' => false, 'error' => 'No se encontro el binario caddy'];
        }
        $caddyPath = realpath($caddyPath) ?: $caddyPath;

        $prep = self::ensureXcaddyToolchain();
        if (!($prep['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string)($prep['error'] ?? 'No se pudo preparar xcaddy'), 'output' => (string)($prep['output'] ?? '')];
        }
        $xcaddy = (string)$prep['xcaddy'];
        $envPrefix = self::XCADDY_ENV;

        $versionRaw = trim((string)shell_exec(escapeshellarg($caddyPath) . ' version 2>/dev/null'));
        $caddyVersion = '';
        if (preg_match('/\bv?(\d+\.\d+\.\d+)\b/', $versionRaw, $m)) {
            $caddyVersion = 'v' . $m[1];
        }
        if ($caddyVersion === '') {
            return ['ok' => false, 'error' => "No se pudo detectar la version actual de Caddy ({$versionRaw})"];
        }

        $modulePackages = self::currentCaddyNonStandardPackages($caddyPath);
        $modulePackages[$modulePackage] = true;

        $backupDir = '/var/backups/musedock/caddy';
        @mkdir($backupDir, 0750, true);
        $stamp = gmdate('Ymd-His');
        $backupPath = "{$backupDir}/caddy-{$stamp}";
        if (!@copy($caddyPath, $backupPath)) {
            return ['ok' => false, 'error' => "No se pudo crear backup de {$caddyPath} en {$backupPath}"];
        }
        @chmod($backupPath, 0755);

        $buildPath = "/tmp/musedock-caddy-{$provider}-{$stamp}";
        @unlink($buildPath);
        $cmd = $envPrefix . escapeshellarg($xcaddy)
            . ' build ' . escapeshellarg($caddyVersion)
            . ' --output ' . escapeshellarg($buildPath);
        foreach (array_keys($modulePackages) as $pkg) {
            $cmd .= ' --with ' . escapeshellarg($pkg);
        }
        $buildOut = trim((string)shell_exec($cmd . ' 2>&1'));
        if (!is_file($buildPath) || !is_executable($buildPath)) {
            return [
                'ok' => false,
                'error' => 'xcaddy no genero un binario ejecutable',
                'output' => self::trimCommandOutput($buildOut),
                'backup' => $backupPath,
            ];
        }

        $builtModules = trim((string)shell_exec(escapeshellarg($buildPath) . ' list-modules --packages --skip-standard 2>/dev/null'));
        if (!str_contains($builtModules, "dns.providers.{$provider} ")) {
            @unlink($buildPath);
            return [
                'ok' => false,
                'error' => "El binario compilado no contiene dns.providers.{$provider}",
                'output' => self::trimCommandOutput($builtModules . "\n" . $buildOut),
                'backup' => $backupPath,
            ];
        }

        // Con el entorno del servicio (token de Cloudflare de /etc/default/caddy): sin él
        // la validación falla siempre con "API token '' appears invalid".
        $validateOut = trim((string)shell_exec('sh -c ' . escapeshellarg(
            'set -a; [ -r /etc/default/caddy ] && . /etc/default/caddy; set +a; '
            . escapeshellarg($buildPath) . ' validate --config /etc/caddy/Caddyfile') . ' 2>&1'));
        if (stripos($validateOut, 'valid') === false && stripos($validateOut, 'adapted config') === false) {
            // Validation output varies by Caddy version; do not block on empty output,
            // but block on explicit errors.
            if (stripos($validateOut, 'error') !== false || stripos($validateOut, 'invalid') !== false) {
                @unlink($buildPath);
                return [
                    'ok' => false,
                    'error' => 'El nuevo Caddy no valida /etc/caddy/Caddyfile',
                    'output' => self::trimCommandOutput($validateOut),
                    'backup' => $backupPath,
                ];
            }
        }

        $installOut = trim((string)shell_exec(sprintf(
            'install -m 0755 %s %s 2>&1',
            escapeshellarg($buildPath),
            escapeshellarg($caddyPath)
        )));
        @unlink($buildPath);
        if ($installOut !== '' && stripos($installOut, 'error') !== false) {
            return ['ok' => false, 'error' => 'No se pudo reemplazar el binario de Caddy', 'output' => self::trimCommandOutput($installOut), 'backup' => $backupPath];
        }

        $restartOut = trim((string)shell_exec('systemctl restart caddy 2>&1'));
        sleep(2);
        $active = trim((string)shell_exec('systemctl is-active caddy 2>/dev/null')) === 'active';
        $providerNow = self::binaryHasDnsProvider($caddyPath, $provider);
        if (!$active || !$providerNow) {
            @copy($backupPath, $caddyPath);
            @chmod($caddyPath, 0755);
            $rollbackOut = trim((string)shell_exec('systemctl restart caddy 2>&1'));
            return [
                'ok' => false,
                'rolled_back' => true,
                'error' => $active ? "Caddy arranco pero no reporta dns.providers.{$provider}" : 'Caddy no arranco con el nuevo binario',
                'output' => self::trimCommandOutput($restartOut . "\nRollback:\n" . $rollbackOut),
                'backup' => $backupPath,
            ];
        }

        self::$installedDnsProviders = null;
        self::$dnsProviderAvailability = [];
        self::$hasCloudflareDnsProvider = null;

        return [
            'ok' => true,
            'installed' => true,
            'provider' => $provider,
            'module' => $modulePackage,
            'version' => $caddyVersion,
            'backup' => $backupPath,
            'message' => "dns.providers.{$provider} instalado y Caddy reiniciado correctamente",
            'output' => self::trimCommandOutput($buildOut),
        ];
    }

    /**
     * Go + xcaddy para compilar Caddy. El Go de apt en Ubuntu 22.04 es 1.18:
     * demasiado viejo para xcaddy y para Caddy 2.10+. Con Go >= 1.21 basta,
     * porque GOTOOLCHAIN=auto descarga solo la versión que pida Caddy.
     */
    private const XCADDY_ENV = 'PATH=/usr/local/go/bin:/root/go/bin:/usr/local/bin:/usr/bin:/bin HOME=/root GOPATH=/root/go GOTOOLCHAIN=auto ';

    private static function ensureXcaddyToolchain(): array
    {
        $envPrefix = self::XCADDY_ENV;
        $out = '';
        $arch = match (php_uname('m')) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => '',
        };

        $go = trim((string)shell_exec($envPrefix . 'command -v go 2>/dev/null'));
        $goVer = $go !== '' && preg_match('/go(\d+)\.(\d+)/', (string)shell_exec($envPrefix . 'go version 2>/dev/null'), $gm)
            ? [(int)$gm[1], (int)$gm[2]] : [0, 0];
        if ($goVer[0] < 1 || ($goVer[0] === 1 && $goVer[1] < 21)) {
            if ($arch === '') {
                return ['ok' => false, 'error' => 'Arquitectura no soportada para instalar Go: ' . php_uname('m')];
            }
            $latest = trim((string)shell_exec("curl -fsSL -m 20 'https://go.dev/VERSION?m=text' 2>/dev/null | head -1"));
            if (!preg_match('/^go\d+\.\d+(\.\d+)?$/', $latest)) {
                return ['ok' => false, 'error' => 'No se pudo consultar la última versión de Go en go.dev', 'output' => $latest];
            }
            // Solo se sustituye /usr/local/go (instalación oficial); el Go de apt no se toca.
            $tmp = '/tmp/musedock-' . $latest . '.tar.gz';
            $out .= (string)shell_exec(sprintf(
                'curl -fsSL -m 300 -o %1$s %2$s 2>&1 && rm -rf /usr/local/go.new && mkdir -p /usr/local/go.new'
                . ' && tar -xzf %1$s -C /usr/local/go.new --strip-components=1 2>&1'
                . ' && rm -rf /usr/local/go && mv /usr/local/go.new /usr/local/go; rm -f %1$s',
                escapeshellarg($tmp),
                escapeshellarg("https://go.dev/dl/{$latest}.linux-{$arch}.tar.gz")
            ));
            $go = is_executable('/usr/local/go/bin/go') ? '/usr/local/go/bin/go' : '';
            if ($go === '') {
                return ['ok' => false, 'error' => "No se pudo instalar Go ({$latest})", 'output' => self::trimCommandOutput($out)];
            }
        }

        $xcaddy = trim((string)shell_exec($envPrefix . 'command -v xcaddy 2>/dev/null'));
        if ($xcaddy === '') {
            $out .= (string)shell_exec($envPrefix . 'go install github.com/caddyserver/xcaddy/cmd/xcaddy@latest 2>&1');
            $xcaddy = is_executable('/root/go/bin/xcaddy') ? '/root/go/bin/xcaddy' : '';
        }
        if ($xcaddy === '' && $arch !== '') {
            // Plan B: binario publicado en GitHub.
            $tag = trim((string)shell_exec("curl -fsSI -m 20 https://github.com/caddyserver/xcaddy/releases/latest 2>/dev/null | grep -i '^location:' | sed 's#.*/tag/v##' | tr -d '\\r'"));
            if (preg_match('/^\d+\.\d+\.\d+$/', $tag)) {
                $out .= (string)shell_exec(sprintf(
                    'curl -fsSL -m 120 %s 2>&1 | tar -xz -C /usr/local/bin xcaddy 2>&1 && chmod 0755 /usr/local/bin/xcaddy',
                    escapeshellarg("https://github.com/caddyserver/xcaddy/releases/download/v{$tag}/xcaddy_{$tag}_linux_{$arch}.tar.gz")
                ));
                $xcaddy = is_executable('/usr/local/bin/xcaddy') ? '/usr/local/bin/xcaddy' : '';
            }
        }
        if ($xcaddy === '') {
            return ['ok' => false, 'error' => 'No se pudo instalar/encontrar xcaddy', 'output' => self::trimCommandOutput($out)];
        }

        return ['ok' => true, 'go' => $go, 'xcaddy' => $xcaddy, 'output' => self::trimCommandOutput($out)];
    }

    private static function currentCaddyNonStandardPackages(string $caddyPath): array
    {
        $out = trim((string)shell_exec(escapeshellarg($caddyPath) . ' list-modules --packages --skip-standard 2>/dev/null'));
        $packages = [];
        foreach (preg_split('/\R+/', $out) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_contains($line, 'Non-standard modules') || str_contains($line, 'Unknown modules')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) >= 2 && str_contains($parts[1], '/')) {
                $packages[$parts[1]] = true;
            }
        }
        return $packages;
    }

    private static function binaryHasDnsProvider(string $caddyPath, string $provider): bool
    {
        $out = trim((string)shell_exec(escapeshellarg($caddyPath) . ' list-modules 2>/dev/null | grep -E "^dns\\.providers\\.' . preg_quote($provider, '/') . '$"'));
        return $out === "dns.providers.{$provider}";
    }

    private static function trimCommandOutput(string $output, int $max = 4000): string
    {
        $output = trim($output);
        if (strlen($output) <= $max) {
            return $output;
        }
        return substr($output, -$max);
    }

    private static function caddyHasDnsProvider(string $provider): bool
    {
        $provider = strtolower(trim($provider));
        if ($provider === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/', $provider)) {
            return false;
        }

        if (array_key_exists($provider, self::$dnsProviderAvailability)) {
            return self::$dnsProviderAvailability[$provider];
        }

        foreach (self::installedDnsProviders() as $installedProvider) {
            if ($installedProvider === $provider) {
                self::$dnsProviderAvailability[$provider] = true;
                return true;
            }
        }

        $needle = 'dns.providers.' . $provider;
        $out = trim((string)shell_exec('caddy list-modules 2>/dev/null | grep -E "^' . preg_quote($needle, '/') . '$"'));
        $ok = ($out === $needle);
        self::$dnsProviderAvailability[$provider] = $ok;
        if ($provider === 'cloudflare') {
            self::$hasCloudflareDnsProvider = $ok;
        }
        return $ok;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Escritura SEGURA en la API admin de Caddy (1.0.225).
    //
    // Comprobado contra un Caddy real:
    //  - PATCH SUSTITUYE el objeto de la ruta, NO fusiona. PATCH srv0 {listen:[…]}
    //    dejaba srv0 sin rutas (todas las webs fuera); PATCH servers {x:…} borraba
    //    todos los demás servers; PATCH tls/automation {policies} borraba el resto
    //    de ajustes TLS; PATCH apps {tls:…} borraba TODAS las apps. Esta fue la causa
    //    real del incidente de agosto (no un "null transitorio") y de la caída de
    //    Caddy en obelix con 1.0.224.
    //  - GET de una ruta inexistente devuelve 200 con cuerpo "null", no 404.
    //  - POST sobre una clave inexistente la crea sin tocar a sus hermanas.
    //  - PUT sobre una clave existente da 409.
    // Regla: escribir SOLO la hoja exacta; si no existe, crearla con POST colgando
    // del antepasado existente más profundo. Nunca PATCH sobre un objeto contenedor.
    // ─────────────────────────────────────────────────────────────────────

    /** @return array{0:int,1:string} [código HTTP, cuerpo] */
    private static function caddyRequest(string $method, string $url, mixed $body = null, int $timeout = 10): array
    {
        $ch = curl_init($url);
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        curl_setopt_array($ch, $opts);
        $resp = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $resp];
    }

    /** ¿Existe la ruta? (Caddy devuelve 200 + "null" para las que no existen.) */
    private static function caddyPathExists(string $caddyApi, string $path): bool
    {
        [$code, $body] = self::caddyRequest('GET', rtrim("{$caddyApi}/config/" . trim($path, '/'), '/'));
        $b = trim($body);
        return $code >= 200 && $code < 300 && $b !== '' && $b !== 'null';
    }

    /**
     * Fija el valor de UNA hoja sin tocar nada más. Si ya tiene ese valor, no
     * escribe. Si existe, PATCH de la hoja; si no, la crea (caddyCreatePath).
     * @return array{0:bool,1:string} [ok, cuerpo de la respuesta]
     */
    private static function caddySetLeaf(string $caddyApi, string $path, mixed $value): array
    {
        $path = trim($path, '/');
        [$code, $body] = self::caddyRequest('GET', "{$caddyApi}/config/{$path}");
        $b = trim($body);
        if ($code >= 200 && $code < 300 && $b !== '' && $b !== 'null') {
            if (json_encode(json_decode($b, true)) === json_encode($value)) {
                return [true, 'unchanged'];
            }
            [$code, $resp] = self::caddyRequest('PATCH', "{$caddyApi}/config/{$path}", $value);
            return [$code >= 200 && $code < 300, $resp];
        }
        return self::caddyCreatePath($caddyApi, $path, $value);
    }

    /**
     * Crea $path con $value colgándolo del antepasado existente más profundo, con
     * POST sobre la primera clave que falta (POST sobre una clave inexistente la
     * crea sin tocar a sus hermanas).
     * @return array{0:bool,1:string}
     */
    private static function caddyCreatePath(string $caddyApi, string $path, mixed $value): array
    {
        $segs = explode('/', trim($path, '/'));
        for ($i = count($segs) - 1; $i >= 0; $i--) {
            $anc = implode('/', array_slice($segs, 0, $i));
            if ($i > 0 && !self::caddyPathExists($caddyApi, $anc)) {
                continue;
            }
            $nested = $value;
            for ($j = count($segs) - 1; $j > $i; $j--) {
                $nested = [$segs[$j] => $nested];
            }
            [$code, $resp] = self::caddyRequest('POST', "{$caddyApi}/config/" . ($anc === '' ? '' : $anc . '/') . $segs[$i], $nested);
            return [$code >= 200 && $code < 300, $resp];
        }
        return [false, 'no existing ancestor'];
    }

    private static function patchTlsPolicies(string $caddyApi, array $policies): void
    {
        // Solo la hoja apps/tls/automation/policies. Antes: PATCH sobre
        // tls/automation (borraba el resto de ajustes TLS) y, si faltaba, PATCH
        // sobre /apps (¡borraba todas las apps, http incluida!).
        // caddySetLeaf no escribe si ya coinciden (idempotencia del incidente
        // TLS 2026-09-14) y el reemplazo de la hoja es atómico (sin ventana vacía).
        [$ok, $resp] = self::caddySetLeaf($caddyApi, 'apps/tls/automation/policies', $policies);
        if ($ok) {
            return;
        }

        // Some nodes run a Caddy build without cloudflare DNS provider.
        // If that happens, retry with a degraded HTTP-only policy set so TLS automation
        // still works for direct (non-wildcard) domains and panel hostnames.
        if (!str_contains(strtolower($resp), 'dns.providers.cloudflare')) {
            return;
        }
        self::caddySetLeaf($caddyApi, 'apps/tls/automation/policies', self::degradePoliciesWithoutDnsProvider($policies));
    }

    private static function degradePoliciesWithoutDnsProvider(array $policies): array
    {
        $result = [];

        foreach ($policies as $policy) {
            $subjects = $policy['subjects'] ?? [];

            // Keep catch-all policy always (it supports HTTP-01).
            if (!empty($subjects)) {
                // Subject-specific policies are mostly for wildcard/DNS-01.
                // Keep only non-wildcard subjects; drop wildcard entries.
                $subjects = array_values(array_filter($subjects, static fn($s) => !str_starts_with((string)$s, '*.')));
                if (empty($subjects)) {
                    continue;
                }
                $policy['subjects'] = $subjects;
            }

            // Remove explicit DNS challenge block from issuers.
            $issuers = [];
            foreach (($policy['issuers'] ?? []) as $iss) {
                unset($iss['challenges']['dns']);
                if (isset($iss['challenges']) && empty($iss['challenges'])) {
                    unset($iss['challenges']);
                }
                $issuers[] = $iss;
            }
            if (!empty($issuers)) {
                $policy['issuers'] = $issuers;
            }

            $result[] = $policy;
        }

        if (empty($result)) {
            $result[] = [
                'issuers' => [[
                    'email' => self::acmeEmail(),
                    'module' => 'acme',
                ]],
            ];
        }

        return $result;
    }

    /**
     * Ensure the 'hosting-access' logger exists in Caddy and register domains to use it.
     * Writes all hosting access logs to /var/log/caddy/hosting-access.log for Fail2Ban.
     */
    /**
     * El registro de accesos de los hostings (/var/log/caddy/hosting-access.log) solo se
     * configuraba al crear la ruta de un hosting. En un nodo cuyas webs llegaron por
     * sincronización o por un relevo (Filemon, 2026-10-04) faltaba, y el monitor no tenía
     * tráfico web ni el ancho de banda por hosting, ni fail2ban de WordPress veía nada.
     * Esto lo asegura para todos los hostings, subdominios y alias, y solo escribe en
     * Caddy lo que falte (de una vez). Lo llama el cluster-worker cada 30 min.
     */
    public static function ensureHostingAccessLogAll(): array
    {
        $api = rtrim((string)((require PANEL_ROOT . '/config/panel.php')['caddy']['api_url'] ?? 'http://localhost:2019'), '/');
        $get = static function (string $path) use ($api) {
            $ch = curl_init($api . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            $b = (string)curl_exec($ch);
            $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $c === 200 ? json_decode($b, true) : false;
        };
        $send = static function (string $method, string $path, $body) use ($api): int {
            $ch = curl_init($api . $path);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($ch);
            $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $c;
        };
        if ($get('/config/apps/http/servers/srv0') === false) {
            return ['ok' => false, 'error' => 'Caddy no responde o no tiene srv0'];
        }
        $out = ['ok' => true, 'logger' => 'ok', 'added' => 0];
        // Caddy escribe con su usuario: si la carpeta o el fichero son de root (los creó un
        // script antes que Caddy), no puede escribir y no hay tráfico web en el monitor.
        $caddyUser = function_exists('posix_getpwnam') ? posix_getpwnam('caddy') : false;
        if ($caddyUser) {
            foreach (['/var/log/caddy', '/var/log/caddy/hosting-access.log'] as $f) {
                if (file_exists($f) && fileowner($f) !== (int)$caddyUser['uid']) {
                    @chown($f, 'caddy');
                    @chgrp($f, 'caddy');
                    $out['fixed_owner'][] = $f;
                }
            }
        }
        $logger = $get('/config/logging/logs/hosting-access');
        if (!is_array($logger) || empty($logger['writer'])) {
            self::ensureHostingAccessLog($api, []); // definición del registro + excluirlo del general
            $out['logger'] = 'creado';
        }
        $hosts = [];
        $add = static function (string $d) use (&$hosts): void {
            foreach (self::hostsWithWww(strtolower(trim($d))) as $h) {
                if ($h !== '') {
                    $hosts[$h] = true;
                }
            }
        };
        foreach ([['hosting_accounts', 'domain'], ['hosting_subdomains', 'subdomain'], ['hosting_domain_aliases', 'domain'], ['hosting_domains', 'domain']] as [$t, $c]) {
            try {
                foreach (\MuseDockPanel\Database::fetchAll("SELECT {$c} AS d FROM {$t}") as $r) {
                    $add((string)$r['d']);
                }
            } catch (\Throwable) {
                // tabla que no existe en este nodo
            }
        }
        $logs = $get('/config/apps/http/servers/srv0/logs');
        $names = is_array($logs) ? (array)($logs['logger_names'] ?? []) : [];
        $before = count($names);
        foreach (array_keys($hosts) as $h) {
            if (!isset($names[$h])) {
                $names[$h] = ['hosting-access'];
            }
        }
        $out['added'] = count($names) - $before;
        if ($out['added'] > 0) {
            $code = is_array($logs)
                ? $send('PATCH', '/config/apps/http/servers/srv0/logs/logger_names', $names)
                : $send('PUT', '/config/apps/http/servers/srv0/logs', ['logger_names' => $names]);
            $out['ok'] = $code >= 200 && $code < 300;
            if (!$out['ok']) {
                $out['error'] = "Caddy respondió HTTP {$code}";
            }
        }
        return $out;
    }

    public static function ensureHostingAccessLog(string $caddyApi, array $domains): void
    {
        // 1) Ensure the logger definition exists
        $loggerConfig = [
            'encoder' => ['format' => 'json'],
            'include' => ['http.log.access.hosting-access'],
            'writer' => [
                'output' => 'file',
                'filename' => '/var/log/caddy/hosting-access.log',
                'roll_size_mb' => 100,
                'roll_keep' => 5,
            ],
        ];
        $ch = curl_init("{$caddyApi}/config/logging/logs/hosting-access");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($loggerConfig),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);

        // 2) Exclude hosting-access from default log to avoid duplicates
        $ch = curl_init("{$caddyApi}/config/logging/logs/default/exclude");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $excludes = json_decode($resp, true) ?: [];
        if (!in_array('http.log.access.hosting-access', $excludes)) {
            $excludes[] = 'http.log.access.hosting-access';
            $ch = curl_init("{$caddyApi}/config/logging/logs/default/exclude");
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => json_encode($excludes),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }

        // 3) Map each domain to the hosting-access logger
        foreach ($domains as $d) {
            $d = trim($d);
            if (empty($d)) continue;
            $encoded = urlencode($d);
            $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/logs/logger_names/{$encoded}");
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => json_encode(['hosting-access']),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    /**
     * Add a Caddy route via API for this domain
     */
    /**
     * Build Caddy subroutes based on hosting type.
     *
     * @param string $hostingType 'php' | 'spa' | 'static'
     */
    public static function buildCaddySubroutes(string $documentRoot, string $username, string $phpVersion, string $hostingType): array
    {
        $routes = [];

        // 1. Set root for all subroutes (+ firma de Blindar WordPress, para saber si está al día)
        $vars = ['handler' => 'vars', 'root' => $documentRoot];
        $wpMark = $hostingType === 'php' ? WordPressHardenService::marker($documentRoot, $username) : '';
        if ($wpMark !== '') {
            $vars['wp_harden'] = $wpMark;
        }
        $routes[] = ['handle' => [$vars]];

        // 1b. Blindar WordPress: xmlrpc, PHP en uploads, ficheros sensibles, ?author= (403)
        if ($hostingType === 'php') {
            array_push($routes, ...WordPressHardenService::caddyRoutes($documentRoot, $username));
        }

        // 2. Static file cache headers (all types)
        $routes[] = [
            'match' => [['path' => ['*.jpg', '*.jpeg', '*.png', '*.gif', '*.webp', '*.svg', '*.ico', '*.css', '*.js', '*.woff', '*.woff2']]],
            'handle' => [['handler' => 'headers', 'response' => ['set' => ['Cache-Control' => ['public, max-age=2592000']]]]],
        ];

        if ($hostingType === 'spa') {
            // SPA: try file first, fallback to /index.html (React Router, Vue Router, etc.)
            $routes[] = [
                'match' => [['file' => ['try_files' => ['{http.request.uri.path}', '/index.html']]]],
                'handle' => [['handler' => 'rewrite', 'uri' => '{http.matchers.file.relative}']],
            ];
        } elseif ($hostingType === 'static') {
            // Static: serve files directly, no fallback
            $routes[] = [
                'match' => [['file' => ['try_files' => ['{http.request.uri.path}', '{http.request.uri.path}/index.html']]]],
                'handle' => [['handler' => 'rewrite', 'uri' => '{http.matchers.file.relative}']],
            ];
        } else {
            // PHP: try file, then index.php (Laravel, WordPress, etc.)
            $socketPath = "/run/php/php{$phpVersion}-fpm-{$username}.sock";

            $routes[] = [
                'match' => [['file' => ['try_files' => ['{http.request.uri.path}', '{http.request.uri.path}/index.php', '/index.php']]]],
                'handle' => [['handler' => 'rewrite', 'uri' => '{http.matchers.file.relative}']],
            ];
            $routes[] = [
                'match' => [['path' => ['*.php']]],
                'handle' => [[
                    'handler' => 'reverse_proxy',
                    'transport' => ['protocol' => 'fastcgi', 'root' => $documentRoot, 'split_path' => ['.php']],
                    'upstreams' => [['dial' => "unix/{$socketPath}"]],
                ]],
            ];
        }

        // File server (all types)
        $routes[] = [
            'handle' => [['handler' => 'file_server', 'root' => $documentRoot, 'hide' => ['.git', '.env', '.htaccess']]],
        ];

        return $routes;
    }

    /**
     * Auto-detect hosting type based on document root contents.
     *
     * Returns: 'php', 'spa', or 'static'
     */
    public static function detectHostingType(string $documentRoot): string
    {
        if (!is_dir($documentRoot)) return 'php';

        // Check parent dir too (for Laravel/MuseDock with /public)
        $projectRoot = $documentRoot;
        if (str_ends_with($documentRoot, '/public')) {
            $projectRoot = dirname($documentRoot);
        }

        // PHP indicators (check first — most common)
        if (file_exists($documentRoot . '/index.php')) return 'php';
        if (file_exists($documentRoot . '/wp-config.php')) return 'php';
        if (file_exists($projectRoot . '/artisan')) return 'php'; // Laravel
        if (file_exists($projectRoot . '/muse')) return 'php'; // MuseDock CMS

        // SPA indicators: index.html + JS bundle files
        if (file_exists($documentRoot . '/index.html')) {
            $html = @file_get_contents($documentRoot . '/index.html', false, null, 0, 2000);
            if ($html) {
                // React/Vue/Angular SPA: <div id="root"> or <div id="app"> + bundled JS
                if (preg_match('/<div\s+id="(root|app|__next)"/', $html) ||
                    preg_match('/src="[^"]*\/(assets|static|js)\/[^"]*\.js"/', $html) ||
                    str_contains($html, 'modulepreload') ||
                    str_contains($html, 'type="module"')) {
                    // Verify there's no index.php alongside (could be a PHP app with SPA frontend)
                    if (!file_exists($documentRoot . '/index.php')) {
                        return 'spa';
                    }
                }
            }
            // Has index.html but no JS app indicators → static site
            if (!file_exists($documentRoot . '/index.php')) {
                // Check if there are multiple .html files (static site) vs single index.html (SPA)
                $htmlFiles = glob($documentRoot . '/*.html');
                if (count($htmlFiles) > 3) return 'static';
                return 'spa'; // Single index.html, likely SPA
            }
        }

        return 'php'; // Default
    }

    public static function addCaddyRoute(string $domain, string $documentRoot, string $username, string $phpVersion = '8.3', string $hostingType = 'php'): ?string
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];
        if (!self::ensureCaddyHttpServerReady($caddyApi)) {
            return null;
        }

        $routeId = self::caddyRouteId($domain);

        // Refresh CF zones if this domain isn't in any known zone, then rebuild TLS policies
        $knownZone = CloudflareService::findZoneForDomain($domain); // también dominios de dos niveles (x.org.es)
        if (!$knownZone) {
            CloudflareService::refreshZones();
        }
        self::ensureTlsCatchAllPolicy($caddyApi);

        $hosts = self::hostsWithWww($domain);
        $subroutes = self::buildCaddySubroutes($documentRoot, $username, $phpVersion, $hostingType);

        $caddyConfig = [
            '@id' => $routeId,
            'match' => [['host' => $hosts]],
            'handle' => [['handler' => 'subroute', 'routes' => $subroutes]],
            'terminal' => true,
        ];

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($caddyConfig),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            // Register domains for access logging (Fail2Ban wp-login protection)
            self::ensureHostingAccessLog($caddyApi, $hosts);
            return $routeId;
        }
        return null;
    }

    /**
     * Create/update a dedicated Caddy route for panel domain access via the configured panel port.
     * Result:
     *  - ok: bool
     *  - error: string (when ok=false)
     *  - warning: string (non-blocking diagnostics)
     */
    public static function configurePanelDomainRoute(string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return ['ok' => false, 'error' => 'Hostname vacio'];
        }

        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'] ?? 'http://localhost:2019';
        $panelPublicPort = self::getPanelPublicPort();
        $internalPort = self::getPanelInternalPort();
        $panelServerName = self::getPanelAdminServerName();
        $panelRouteServer = self::resolvePanelRouteServer($caddyApi, true);
        if ($panelRouteServer === null) {
            return ['ok' => false, 'error' => 'No se pudo preparar servidor Caddy para el panel'];
        }
        // If PANEL_PORT is served by an unrelated Caddyfile server, avoid
        // mutating it. Caddyfile-adapted panel blocks can appear as srv1/srv2,
        // so allow them when they already proxy to the panel internal port.
        $panelRouteServerIsExternal = $panelRouteServer !== 'srv0' && $panelRouteServer !== $panelServerName;
        $panelRouteServerTargetsPanel = !$panelRouteServerIsExternal
            || self::serverProxiesToPanelInternalPort($caddyApi, $panelRouteServer);

        if (!$panelRouteServerTargetsPanel) {
            $panelTlsResult = self::ensurePanelTlsPolicyFromSettings($caddyApi, $hostname);
            if (!($panelTlsResult['ok'] ?? false)) {
                return ['ok' => false, 'error' => (string)($panelTlsResult['error'] ?? 'No se pudo aplicar la politica TLS del panel')];
            }
            $warning = "El puerto {$panelPublicPort} ya esta servido por {$panelRouteServer}; se omite ruta dedicada gestionada por panel.";
            if (!empty($panelTlsResult['warning'])) {
                $warning = trim($warning . ' ' . (string)$panelTlsResult['warning']);
            }
            return [
                'ok' => true,
                'applied' => false,
                'skipped' => true,
                'reason' => "panel-port-owned-by-{$panelRouteServer}",
                'warning' => $warning,
            ];
        }

        $routesResult = self::fetchCaddyRoutes($caddyApi, false, $panelRouteServer);
        if (!($routesResult['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string)($routesResult['error'] ?? 'No se pudo leer la config de Caddy')];
        }
        $routes = $routesResult['routes'] ?? [];

        $conflict = self::findHostRouteConflict($routes, $hostname, [
            self::PANEL_DOMAIN_ROUTE_ID,
            self::PANEL_DOMAIN_HTTPS_ROUTE_ID,
        ]);
        if ($conflict !== null) {
            return ['ok' => false, 'error' => "El dominio {$hostname} ya esta en uso por la ruta Caddy '{$conflict}'."];
        }

        // Keep TLS policies fresh so DNS-01 fallback is available when needed.
        self::ensureTlsCatchAllPolicy($caddyApi);
        $panelTlsResult = self::ensurePanelTlsPolicyFromSettings($caddyApi, $hostname);
        if (!($panelTlsResult['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string)($panelTlsResult['error'] ?? 'No se pudo aplicar la politica TLS del panel')];
        }
        $httpsRouteResult = self::ensurePanelDomainHttpsRoute($caddyApi, $hostname, $panelPublicPort);

        $route = self::buildPanelDomainRoute($hostname, $panelPublicPort, $internalPort);
        foreach ($routes as $existingRoute) {
            if ((string)($existingRoute['@id'] ?? '') !== self::PANEL_DOMAIN_ROUTE_ID) {
                continue;
            }
            if (self::panelDomainRouteMatches($existingRoute, $hostname, $panelPublicPort, $internalPort)) {
                self::warmupPanelDomainTls($hostname, $panelPublicPort);
                $warning = self::buildPanelDomainDnsWarning($hostname);
                if (!empty($panelTlsResult['warning'])) {
                    $warning = trim($warning . ' ' . (string)$panelTlsResult['warning']);
                }
                if (!empty($httpsRouteResult['warning'])) {
                    $warning = trim($warning . ' ' . (string)$httpsRouteResult['warning']);
                }
                return [
                    'ok' => true,
                    'applied' => true,
                    'idempotent' => true,
                    'server' => $panelRouteServer,
                    'warning' => $warning,
                ];
            }
            self::deleteRouteById($caddyApi, self::PANEL_DOMAIN_ROUTE_ID);
            break;
        }
        self::deleteRouteById($caddyApi, self::PANEL_DOMAIN_ROUTE_ID);

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/{$panelRouteServer}/routes");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($route),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!($httpCode >= 200 && $httpCode < 300)) {
            return ['ok' => false, 'error' => "No se pudo crear la ruta panel-domain en Caddy (HTTP {$httpCode}). {$response}"];
        }

        // Trigger first handshake locally (helps cert bootstrap without waiting for first user hit).
        self::warmupPanelDomainTls($hostname, $panelPublicPort);

        $warning = self::buildPanelDomainDnsWarning($hostname);
        if (!empty($panelTlsResult['warning'])) {
            $warning = trim($warning . ' ' . (string)$panelTlsResult['warning']);
        }
        if (!empty($httpsRouteResult['warning'])) {
            $warning = trim($warning . ' ' . (string)$httpsRouteResult['warning']);
        }
        return [
            'ok' => true,
            'applied' => true,
            'server' => $panelRouteServer,
            'warning' => $warning,
        ];
    }

    /**
     * Ensure the panel hostname route (if configured in settings) exists in Caddy.
     * Used by cluster-worker after reloads to self-heal runtime API config.
     */
    public static function ensurePanelDomainRouteFromSettings(): array
    {
        $hostname = self::normalizeStoredHostname((string)\MuseDockPanel\Settings::get('panel_hostname', ''));
        if ($hostname === '') {
            return ['ok' => true, 'applied' => false, 'skipped' => true, 'reason' => 'panel_hostname_empty'];
        }

        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'] ?? 'http://localhost:2019';
        $panelPublicPort = self::getPanelPublicPort();
        $panelServerName = self::getPanelAdminServerName();
        $panelRouteServer = self::resolvePanelRouteServer($caddyApi, true);
        if ($panelRouteServer === null) {
            return ['ok' => false, 'error' => 'No se pudo preparar servidor Caddy para el panel'];
        }
        $panelRouteServerIsExternal = $panelRouteServer !== 'srv0' && $panelRouteServer !== $panelServerName;
        $panelRouteServerTargetsPanel = !$panelRouteServerIsExternal
            || self::serverProxiesToPanelInternalPort($caddyApi, $panelRouteServer);

        if (!$panelRouteServerTargetsPanel) {
            $panelTlsResult = self::ensurePanelTlsPolicyFromSettings($caddyApi, $hostname);
            if (!($panelTlsResult['ok'] ?? false)) {
                return ['ok' => false, 'error' => (string)($panelTlsResult['error'] ?? 'No se pudo aplicar la politica TLS del panel')];
            }
            $result = [
                'ok' => true,
                'applied' => false,
                'skipped' => true,
                'reason' => "panel-port-owned-by-{$panelRouteServer}",
            ];
            if (!empty($panelTlsResult['warning'])) {
                $result['warning'] = (string)$panelTlsResult['warning'];
            }
            return $result;
        }

        $panelTlsResult = self::ensurePanelTlsPolicyFromSettings($caddyApi, $hostname);
        if (!($panelTlsResult['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string)($panelTlsResult['error'] ?? 'No se pudo aplicar la politica TLS del panel')];
        }
        $httpsRouteResult = self::ensurePanelDomainHttpsRoute($caddyApi, $hostname, $panelPublicPort);

        $routesRaw = @file_get_contents("{$caddyApi}/config/apps/http/servers/{$panelRouteServer}/routes");
        $routes = json_decode((string)$routesRaw, true);
        if (!is_array($routes) || !array_is_list($routes)) {
            return ['ok' => false, 'error' => "No se pudo leer rutas Caddy del servidor {$panelRouteServer}"];
        }

        foreach ($routes as $route) {
            if ((string)($route['@id'] ?? '') !== self::PANEL_DOMAIN_ROUTE_ID) {
                continue;
            }
            $match = $route['match'][0] ?? [];
            $hosts = $match['host'] ?? [];
            $expr = (string)($match['expression'] ?? '');
            $expectedExpr = '{http.request.port} == ' . $panelPublicPort;
            if (in_array($hostname, $hosts, true)
                && trim($expr) === $expectedExpr
                && self::panelDomainRouteMatches($route, $hostname, $panelPublicPort, self::getPanelInternalPort())
            ) {
                $warning = (string)($panelTlsResult['warning'] ?? '');
                $result = [
                    'ok' => true,
                    'applied' => true,
                    'idempotent' => true,
                    'server' => $panelRouteServer,
                ];
                if ($warning !== '') {
                    $result['warning'] = $warning;
                }
                if (!empty($httpsRouteResult['warning'])) {
                    $result['warning'] = trim((string)($result['warning'] ?? '') . ' ' . (string)$httpsRouteResult['warning']);
                }
                self::warmupPanelDomainTls($hostname, $panelPublicPort);
                return $result;
            }
            break;
        }

        return self::configurePanelDomainRoute($hostname);
    }

    /**
     * Remove panel domain Caddy route if present.
     */
    public static function removePanelDomainRoute(?string $hostname = null): bool
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'] ?? 'http://localhost:2019';
        $routeDeleted = self::deleteRouteById($caddyApi, self::PANEL_DOMAIN_ROUTE_ID);
        $httpsRouteDeleted = self::deleteRouteById($caddyApi, self::PANEL_DOMAIN_HTTPS_ROUTE_ID);
        $targetHost = self::normalizeStoredHostname((string)$hostname);
        if ($targetHost === '') {
            $targetHost = self::normalizeStoredHostname((string)\MuseDockPanel\Settings::get('panel_hostname', ''));
        }
        $tlsDeleted = self::removePanelTlsPolicy($caddyApi, $targetHost);
        return $routeDeleted && $httpsRouteDeleted && $tlsDeleted;
    }

    private static function getPanelInternalPort(): int
    {
        $internal = (int)\MuseDockPanel\Env::get('PANEL_INTERNAL_PORT', 0);
        if ($internal > 0) {
            return $internal;
        }

        $panelPort = self::getPanelPublicPort();
        if ($panelPort <= 0) {
            $panelPort = 8444;
        }
        return $panelPort + 1;
    }

    private static function getPanelPublicPort(): int
    {
        $panelPort = (int)\MuseDockPanel\Env::get('PANEL_PORT', 8444);
        if ($panelPort <= 0) {
            $panelPort = 8444;
        }
        return $panelPort;
    }

    private static function getPanelAdminServerName(): string
    {
        $name = trim((string)\MuseDockPanel\Env::get('CADDY_PANEL_SERVER_NAME', self::PANEL_ADMIN_SERVER_ID));
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name)) {
            return self::PANEL_ADMIN_SERVER_ID;
        }
        return $name;
    }

    public static function panelPortOwner(string $caddyApi): ?string
    {
        return self::findServerByListenPort($caddyApi, self::getPanelPublicPort());
    }

    public static function panelRuntimeManagedByPanel(string $caddyApi): bool
    {
        $owner = self::panelPortOwner($caddyApi);
        if ($owner === null) {
            return true;
        }
        $panelServer = self::getPanelAdminServerName();
        return $owner === 'srv0' || $owner === $panelServer;
    }

    private static function resolvePanelRouteServer(string $caddyApi, bool $createIfMissing = true): ?string
    {
        $panelPort = self::getPanelPublicPort();
        $owner = self::findServerByListenPort($caddyApi, $panelPort);
        if ($owner !== null) {
            return $owner;
        }

        if (!$createIfMissing) {
            return null;
        }

        $panelServer = self::getPanelAdminServerName();
        if (!self::ensurePanelAdminServerReady($caddyApi, $panelServer)) {
            return null;
        }
        return $panelServer;
    }

    private static function ensurePanelAdminServerReady(string $caddyApi, ?string $serverName = null): bool
    {
        $serverName = $serverName ?: self::getPanelAdminServerName();
        $serverUrl = "{$caddyApi}/config/apps/http/servers/{$serverName}";
        $panelListen = ':' . self::getPanelPublicPort();

        // Ensure server object exists. (Caddy devuelve 200 + "null" si no existe,
        // no 404: la comprobación antigua por 404 nunca se cumplía.) Se crea SOLO
        // esta clave; antes era PATCH sobre /servers, que BORRABA todos los demás
        // servers (srv0 con todas las webs incluido).
        $serverPath = "apps/http/servers/{$serverName}";
        $serverRaw = null;
        if (!self::caddyPathExists($caddyApi, $serverPath)) {
            $initialServer = [
                "listen" => [$panelListen],
                "automatic_https" => ["disable_redirects" => true],
                // [{}] y no [[]]: json_encode de [[]] da una lista dentro de la lista y
                // Caddy la rechaza ("cannot unmarshal array into ... ConnectionPolicy").
                "tls_connection_policies" => [new \stdClass()],
                "routes" => [],
            ];
            [$created] = self::caddyCreatePath($caddyApi, $serverPath, $initialServer);
            if (!$created) {
                return false;
            }
        } else {
            [, $serverRaw] = self::caddyRequest("GET", $serverUrl, null, 8);
        }

        // Normalize critical server fields but keep existing routes untouched.
        $decodedServer = json_decode((string)$serverRaw, true);
        $existingListen = [];
        if (is_array($decodedServer)) {
            $listen = $decodedServer["listen"] ?? null;
            if (is_array($listen) && array_is_list($listen)) {
                foreach ($listen as $entry) {
                    if (is_string($entry) && $entry !== "") {
                        $existingListen[] = $entry;
                    }
                }
            }
        }
        if (!in_array($panelListen, $existingListen, true)) {
            $existingListen[] = $panelListen;
        }
        $existingListen = array_values(array_unique($existingListen));

        // Hoja a hoja (antes: PATCH del objeto server entero → borraba sus rutas).
        $leaves = [
            "listen" => $existingListen,
            "automatic_https/disable_redirects" => true,
        ];
        // Políticas TLS: solo si faltan o no son una lista de objetos; si ya hay unas
        // válidas (p. ej. del Caddyfile) no se tocan. Antes se enviaba [[]] en cada
        // pasada, que Caddy rechaza: el reparador fallaba al arrancar Caddy
        // ("no se pudo preparar srv0/listeners", obelix 2026-10-06).
        $policies = is_array($decodedServer) ? ($decodedServer["tls_connection_policies"] ?? null) : null;
        $policiesOk = is_array($policies) && $policies !== [] && array_is_list($policies);
        foreach ($policiesOk ? $policies : [] as $policy) {
            if (!is_array($policy) || ($policy !== [] && array_is_list($policy))) {
                $policiesOk = false;
                break;
            }
        }
        if (!$policiesOk) {
            $leaves["tls_connection_policies"] = [new \stdClass()];
        }
        foreach ($leaves as $leaf => $value) {
            [$ok] = self::caddySetLeaf($caddyApi, "{$serverPath}/{$leaf}", $value);
            if (!$ok) {
                return false;
            }
        }

        // Ensure routes key exists and is a list.
        $ch = curl_init("{$serverUrl}/routes");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $routesRaw = curl_exec($ch);
        $routesCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($routesCode === 404 || trim((string)$routesRaw) === '' || strtolower(trim((string)$routesRaw)) === 'null') {
            $ch = curl_init("{$serverUrl}/routes");
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => json_encode([]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
            ]);
            curl_exec($ch);
            $putCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!($putCode >= 200 && $putCode < 300)) {
                return false;
            }
        } elseif (!($routesCode >= 200 && $routesCode < 300)) {
            return false;
        } else {
            $decodedRoutes = json_decode((string)$routesRaw, true);
            if (!(is_array($decodedRoutes) && array_is_list($decodedRoutes))) {
                return false;
            }
        }

        return true;
    }

    private static function normalizeStoredHostname(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('#^https?://#', '', $value);
        $value = explode('/', $value, 2)[0] ?? '';
        $value = explode(':', $value, 2)[0] ?? '';
        return trim((string)$value);
    }

    /**
     * Keep TLS automation policy for panel hostname aligned with selected mode.
     *
     * Supported modes:
     * - self_signed: Caddy internal issuer (recommended for private admin port)
     * - http01: ACME HTTP/TLS-ALPN (requires public 80/443 reachability)
     * - dns01: ACME DNS challenge with configurable DNS provider module
     */
    public static function ensurePanelTlsPolicyFromSettings(string $caddyApi, string $hostname = ''): array
    {
        $hostname = self::normalizeStoredHostname($hostname !== '' ? $hostname : (string)\MuseDockPanel\Settings::get('panel_hostname', ''));
        if ($hostname === '') {
            return ['ok' => true, 'skipped' => true, 'reason' => 'panel_hostname_empty'];
        }

        $mode = strtolower(trim((string)\MuseDockPanel\Settings::get('panel_tls_mode', 'self_signed')));
        if (!in_array($mode, ['self_signed', 'http01', 'dns01'], true)) {
            $mode = 'self_signed';
        }

        $email = trim((string)\MuseDockPanel\Settings::get('panel_acme_email', ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = self::resolvePanelAcmeEmail('');
        }

        $modeWarning = '';
        if ($mode === 'self_signed' && self::panelHostnameNeedsPublicTls($hostname)) {
            $mode = 'http01';
            $email = self::resolvePanelAcmeEmail($email);
            $modeWarning = 'Dominio publico detectado: se fuerza Let\'s Encrypt HTTP-01/TLS-ALPN-01 para evitar certificado interno bloqueado por HSTS.';
        }

        $provider = strtolower(trim((string)\MuseDockPanel\Settings::get('panel_dns_provider', '')));
        $providerConfigRaw = trim((string)\MuseDockPanel\Settings::get('panel_dns_provider_config', ''));
        $providerConfigEnc = trim((string)\MuseDockPanel\Settings::get('panel_dns_provider_config_enc', ''));
        if ($providerConfigEnc !== '') {
            $decryptedProviderConfig = \MuseDockPanel\Services\ReplicationService::decryptPassword($providerConfigEnc);
            if ($decryptedProviderConfig !== '') {
                $providerConfigRaw = $decryptedProviderConfig;
            }
        }
        $providerConfig = [];
        if ($providerConfigRaw !== '') {
            $decoded = json_decode($providerConfigRaw, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'error' => 'JSON invalido en configuracion DNS del panel'];
            }
            $providerConfig = $decoded;
        }

        $warning = '';
        $policy = self::buildPanelTlsPolicy($hostname, $mode, $email, $provider, $providerConfig, $warning);
        if (!($policy['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string)($policy['error'] ?? 'No se pudo construir la politica TLS del panel')];
        }
        if ($modeWarning !== '') {
            $warning = trim($modeWarning . ' ' . $warning);
        }
        $panelPolicy = $policy['policy'];

        $ch = curl_init("{$caddyApi}/config/apps/tls/automation/policies");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $policies = [];
        if ($httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded) && array_is_list($decoded)) {
                $policies = $decoded;
            }
        }

        $filtered = [];
        foreach ($policies as $existingPolicy) {
            $subjects = $existingPolicy['subjects'] ?? [];
            if (!is_array($subjects)) {
                $subjects = [];
            }
            $normalizedSubjects = array_values(array_filter(array_map(
                static fn($s) => strtolower(trim((string)$s)),
                $subjects
            )));

            // Remove previous dedicated policy for this panel hostname.
            if (count($normalizedSubjects) === 1 && $normalizedSubjects[0] === $hostname) {
                continue;
            }
            $filtered[] = $existingPolicy;
        }

        array_unshift($filtered, $panelPolicy);
        self::patchTlsPolicies($caddyApi, $filtered);

        return $warning !== '' ? ['ok' => true, 'warning' => $warning] : ['ok' => true];
    }

    private static function buildPanelTlsPolicy(
        string $hostname,
        string $mode,
        string $email,
        string $provider,
        array $providerConfig,
        string &$warning
    ): array {
        $warning = '';

        if ($mode === 'self_signed') {
            return [
                'ok' => true,
                'policy' => [
                    'subjects' => [$hostname],
                    'issuers' => [[
                        'module' => 'internal',
                    ]],
                ],
            ];
        }

        if ($mode === 'http01') {
            $issuers = [[
                'module' => 'acme',
                'email' => $email,
            ]];
            if (!self::panelHostnameNeedsPublicTls($hostname)) {
                $issuers[] = [
                    'module' => 'internal',
                ];
            }

            return [
                'ok' => true,
                'policy' => [
                    'subjects' => [$hostname],
                    'issuers' => $issuers,
                ],
            ];
        }

        if ($mode !== 'dns01') {
            return ['ok' => false, 'error' => "Modo TLS de panel no soportado: {$mode}"];
        }

        if ($provider === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/i', $provider)) {
            return ['ok' => false, 'error' => 'DNS-01 requiere un proveedor DNS valido (ej: cloudflare, route53, digitalocean)'];
        }

        // Backward compatibility: if provider=cloudflare and no JSON config set, try env token.
        if ($provider === 'cloudflare' && empty($providerConfig)) {
            $token = trim((string)getenv('CLOUDFLARE_API_TOKEN'));
            if ($token === '') {
                $caddyEnv = @file_get_contents('/etc/default/caddy');
                if ($caddyEnv && preg_match('/^CLOUDFLARE_API_TOKEN=(.+)$/m', $caddyEnv, $m)) {
                    $token = trim($m[1]);
                }
            }
            if ($token !== '') {
                $providerConfig = ['api_token' => $token];
                $warning = 'DNS-01 del panel usando token heredado de /etc/default/caddy (CLOUDFLARE_API_TOKEN).';
            }
        }

        if (empty($providerConfig)) {
            return ['ok' => false, 'error' => 'DNS-01 requiere configuracion JSON del proveedor DNS'];
        }

        if (!self::caddyHasDnsProvider($provider)) {
            return ['ok' => false, 'error' => "Caddy no tiene cargado dns.providers.{$provider}"];
        }

        $issuers = [[
            'module' => 'acme',
            'email' => $email,
            'challenges' => [
                'dns' => [
                    'provider' => array_merge($providerConfig, ['name' => $provider]),
                    'resolvers' => ['1.1.1.1:53', '8.8.8.8:53'],
                ],
            ],
        ]];
        if (!self::panelHostnameNeedsPublicTls($hostname)) {
            $issuers[] = [
                'module' => 'internal',
            ];
        }

        return [
            'ok' => true,
            'policy' => [
                'subjects' => [$hostname],
                'issuers' => $issuers,
            ],
        ];
    }

    private static function panelHostnameNeedsPublicTls(string $hostname): bool
    {
        $host = strtolower(trim($hostname));
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (['.local', '.localhost', '.lan', '.internal', '.test'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }
        return str_contains($host, '.');
    }

    private static function resolvePanelAcmeEmail(string $candidate): string
    {
        $candidates = [
            $candidate,
            (string)\MuseDockPanel\Settings::get('panel_acme_email', ''),
            (string)\MuseDockPanel\Settings::get('notify_smtp_from', ''),
            (string)\MuseDockPanel\Settings::get('notify_email_to', ''),
            (string)\MuseDockPanel\Settings::get('mail_from_address', ''),
            \MuseDockPanel\Services\NotificationService::getAdminEmail(),
        ];

        foreach ($candidates as $email) {
            $email = trim((string)$email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        // Sin correo configurado: Let's Encrypt lo permite. Antes caía en admin@musedock.com,
        // y en la instalación de un cliente sus certificados quedaban a nombre de MuseDock.
        return '';
    }

    /** Correo de la cuenta ACME (Let's Encrypt) de ESTE panel: el suyo, nunca uno fijo. */
    public static function acmeEmail(): string
    {
        static $email = null;
        return $email ??= self::resolvePanelAcmeEmail('');
    }

    private static function removePanelTlsPolicy(string $caddyApi, string $hostname): bool
    {
        if ($hostname === '') {
            return true;
        }

        $ch = curl_init("{$caddyApi}/config/apps/tls/automation/policies");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!($httpCode >= 200 && $httpCode < 300)) {
            return true;
        }

        $policies = json_decode((string)$raw, true);
        if (!is_array($policies) || !array_is_list($policies)) {
            return true;
        }

        $changed = false;
        $filtered = [];
        foreach ($policies as $policy) {
            $subjects = $policy['subjects'] ?? [];
            if (!is_array($subjects)) {
                $subjects = [];
            }
            $normalizedSubjects = array_values(array_filter(array_map(
                static fn($s) => strtolower(trim((string)$s)),
                $subjects
            )));
            if (count($normalizedSubjects) === 1 && $normalizedSubjects[0] === $hostname) {
                $changed = true;
                continue;
            }
            $filtered[] = $policy;
        }

        if (!$changed) {
            return true;
        }

        self::patchTlsPolicies($caddyApi, $filtered);
        return true;
    }

    private static function panelReverseProxyHandle(int $internalPort): array
    {
        return [[
            'handler' => 'reverse_proxy',
            'upstreams' => [['dial' => "127.0.0.1:{$internalPort}"]],
            'headers' => [
                'request' => [
                    'set' => [
                        'X-Forwarded-Proto' => ['https'],
                        'X-Forwarded-Host' => ['{http.request.host}'],
                        'X-Real-IP' => ['{remote_host}'],
                    ],
                ],
            ],
        ]];
    }

    private static function buildPanelDomainRoute(string $hostname, int $panelPublicPort, int $internalPort): array
    {
        return [
            '@id' => self::PANEL_DOMAIN_ROUTE_ID,
            // Panel hostname must only be served on PANEL_PORT (e.g. 8444).
            'match' => [[
                'host' => [$hostname],
                'expression' => '{http.request.port} == ' . $panelPublicPort,
            ]],
            'handle' => self::panelReverseProxyHandle($internalPort),
            'terminal' => true,
        ];
    }

    private static function buildPanelDomainHttpsRoute(string $hostname, int $panelPublicPort): array
    {
        $location = "https://{http.request.host}:{$panelPublicPort}{http.request.uri}";
        if ($panelPublicPort === 443) {
            $location = 'https://{http.request.host}{http.request.uri}';
        }

        // Peticiones al dominio del panel que llegan al :443:
        //  - con puerto 443 → 308 hacia el puerto del panel;
        //  - con OTRO puerto (p. ej. :8444) → 421 Misdirected Request. Pasa cuando el
        //    navegador reutiliza su conexión HTTP/3 del 443 (anunciada con alt-svc
        //    por las webs de ese servidor) para pedir el :8444. Sin esta rama no
        //    había ruta, Caddy respondía vacío y el panel salía en blanco. Con 421 el
        //    navegador reintenta por una conexión nueva al puerto correcto. NO se
        //    sirve el panel por el 443: se saltaría el firewall que protege el 8444.
        // La ruta solo se aplica a lo que llega de verdad por el 443 (puerto LOCAL de
        // la conexión): en servidores donde el 443 y el panel comparten el mismo
        // servidor de Caddy, sin esto atrapaba también las peticiones al panel y
        // respondía 421 (visto en Filemon). Dentro: el puerto de la cabecera Host
        // puede venir vacío (https://dominio/ sin puerto) → también es el 443.
        return [
            '@id' => self::PANEL_DOMAIN_HTTPS_ROUTE_ID,
            'match' => [[
                'host' => [$hostname],
                'expression' => '{http.request.local.port} == 443',
            ]],
            'handle' => [[
                'handler' => 'subroute',
                'routes' => [
                    [
                        'match' => [['expression' => "{http.request.port} == 443 || {http.request.port} == ''"]],
                        'handle' => [[
                            'handler' => 'static_response',
                            'status_code' => 308,
                            'headers' => [
                                'Location' => [$location],
                            ],
                        ]],
                        'terminal' => true,
                    ],
                    [
                        'handle' => [[
                            'handler' => 'static_response',
                            'status_code' => 421,
                        ]],
                        'terminal' => true,
                    ],
                ],
            ]],
            'terminal' => true,
        ];
    }

    private static function panelDomainHttpsRouteMatches(array $route, string $hostname, int $panelPublicPort): bool
    {
        if ((string)($route['@id'] ?? '') !== self::PANEL_DOMAIN_HTTPS_ROUTE_ID) {
            return false;
        }

        $match = $route['match'][0] ?? [];
        if (!is_array($match)) {
            return false;
        }

        $hosts = $match['host'] ?? [];
        if (!is_array($hosts) || !in_array($hostname, $hosts, true)) {
            return false;
        }

        // Se compara match y handle con la ruta esperada: las versiones anteriores
        // (308 directo; o, en 1.0.232–1.0.242, sin exigir el puerto local 443) no
        // coinciden y se sustituyen. Caddy devuelve el JSON con las claves en orden
        // alfabético: se normalizan las dos antes de comparar.
        $expected = self::buildPanelDomainHttpsRoute($hostname, $panelPublicPort);
        if (trim((string)($match['expression'] ?? '')) !== $expected['match'][0]['expression']) {
            return false;
        }
        $canon = static function ($v) use (&$canon) {
            if (!is_array($v)) {
                return $v;
            }
            $v = array_map($canon, $v);
            if (!array_is_list($v)) {
                ksort($v);
            }
            return $v;
        };
        return json_encode($canon($route['handle'] ?? null)) === json_encode($canon($expected['handle']));
    }

    private static function panelDomainRouteMatches(array $route, string $hostname, int $panelPublicPort, int $internalPort): bool
    {
        if ((string)($route['@id'] ?? '') !== self::PANEL_DOMAIN_ROUTE_ID) {
            return false;
        }

        $match = $route['match'][0] ?? [];
        if (!is_array($match)) {
            return false;
        }

        $hosts = $match['host'] ?? [];
        if (!is_array($hosts) || !in_array($hostname, $hosts, true)) {
            return false;
        }

        $expectedExpr = '{http.request.port} == ' . $panelPublicPort;
        if (trim((string)($match['expression'] ?? '')) !== $expectedExpr) {
            return false;
        }

        $expectedDial = "127.0.0.1:{$internalPort}";
        foreach (($route['handle'] ?? []) as $handler) {
            if (!is_array($handler) || ($handler['handler'] ?? '') !== 'reverse_proxy') {
                continue;
            }
            foreach (($handler['upstreams'] ?? []) as $upstream) {
                if (($upstream['dial'] ?? '') === $expectedDial) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function ensurePanelDomainHttpsRoute(string $caddyApi, string $hostname, int $panelPublicPort): array
    {
        if ($panelPublicPort === 443 || !self::panelHostnameNeedsPublicTls($hostname)) {
            return ['ok' => true, 'skipped' => true];
        }

        $routesResult = self::fetchCaddyRoutes($caddyApi, false, 'srv0');
        if (!($routesResult['ok'] ?? false)) {
            return ['ok' => false, 'warning' => 'No se pudo preparar ruta HTTPS :443 para el dominio del panel.'];
        }
        $routes = $routesResult['routes'] ?? [];

        $conflict = self::findHostRouteConflict($routes, $hostname, [
            self::PANEL_DOMAIN_ROUTE_ID,
            self::PANEL_DOMAIN_HTTPS_ROUTE_ID,
        ]);
        if ($conflict !== null) {
            return [
                'ok' => true,
                'skipped' => true,
                'warning' => "No se crea redirect :443 para {$hostname}: ya existe ruta Caddy '{$conflict}' para ese host.",
            ];
        }

        foreach ($routes as $route) {
            if ((string)($route['@id'] ?? '') !== self::PANEL_DOMAIN_HTTPS_ROUTE_ID) {
                continue;
            }
            if (self::panelDomainHttpsRouteMatches($route, $hostname, $panelPublicPort)) {
                self::warmupPanelDomainTls($hostname, 443);
                return ['ok' => true, 'applied' => true, 'idempotent' => true];
            }
            break;
        }

        self::deleteRouteById($caddyApi, self::PANEL_DOMAIN_HTTPS_ROUTE_ID);
        $route = self::buildPanelDomainHttpsRoute($hostname, $panelPublicPort);
        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($route),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!($httpCode >= 200 && $httpCode < 300)) {
            return [
                'ok' => false,
                'warning' => "No se pudo crear redirect :443 para {$hostname} (HTTP {$httpCode}). {$response}",
            ];
        }

        self::warmupPanelDomainTls($hostname, 443);
        return ['ok' => true, 'applied' => true];
    }

    /**
     * Ensure Caddy has srv0 and required listeners.
     * By default only enforces :443 on srv0 (hosting path).
     * When $enforcePanelPort is true, panel port is handled in a dedicated server
     * (srv_panel_admin by default) and never forced into srv0.
     *
     * Returns false only when the admin API is unreachable or the config cannot be repaired.
     */
    public static function ensureCaddyHttpServerReady(string $caddyApi, bool $enforcePanelPort = false): bool
    {
        $requiredListen = [':443'];
        $panelRouteServer = null;
        if ($enforcePanelPort) {
            $panelRouteServer = self::resolvePanelRouteServer($caddyApi, true);
            if ($panelRouteServer === null) {
                return false;
            }
        }

        $serverUrl = "{$caddyApi}/config/apps/http/servers/srv0";

        // Check if srv0 exists. (Caddy devuelve 200 + "null" si no existe, no 404.)
        // Si falta, se crea SOLO srv0 colgándolo del antepasado existente (POST).
        // Antes había una cadena de PATCH sobre /servers, /apps/http y /apps que, al
        // sustituir el objeto entero, podía borrar los demás servers o TODAS las apps.
        if (!self::caddyPathExists($caddyApi, 'apps/http/servers/srv0')) {
            [$created] = self::caddyCreatePath($caddyApi, 'apps/http/servers/srv0', ['listen' => $requiredListen, 'routes' => []]);
            if (!$created) {
                return false;
            }
        }

        // WITNESS (anti-TOCTOU, incidente 2026-08-06): cuántas rutas tiene srv0 ANTES
        // de tocar el listen. Parchear el listen puede hacer que la lectura inmediata
        // de /routes devuelva null/vacío de forma TRANSITORIA; sin este testigo,
        // confundiríamos esa lectura perdida con un server realmente vacío y borraríamos
        // las webs de terceros (srv0 es compartido). -1 = desconocido.
        $routesCountBefore = -1;
        $wch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
        curl_setopt_array($wch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $witnessRaw = curl_exec($wch);
        $witnessCode = (int)curl_getinfo($wch, CURLINFO_HTTP_CODE);
        curl_close($wch);
        if ($witnessCode >= 200 && $witnessCode < 300) {
            $witnessDecoded = json_decode((string)$witnessRaw, true);
            if (is_array($witnessDecoded) && array_is_list($witnessDecoded)) {
                $routesCountBefore = count($witnessDecoded);
            }
        }

        // Ensure listen includes :443 and panel port (fallback IP access).
        $existingListen = [];
        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/listen");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $listenRaw = curl_exec($ch);
        $listenCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($listenCode >= 200 && $listenCode < 300) {
            $decoded = json_decode((string)$listenRaw, true);
            // If Caddy returns malformed listen payload (e.g. null), treat it as empty and
            // rebuild listeners. This is a targeted self-heal that does not touch routes.
            if (is_array($decoded) && array_is_list($decoded)) {
                foreach ($decoded as $entry) {
                    if (is_string($entry) && $entry !== '') {
                        $existingListen[] = $entry;
                    }
                }
            }
        }

        $listen = $existingListen;
        foreach ($requiredListen as $requiredPort) {
            if (!in_array($requiredPort, $listen, true)) {
                $listen[] = $requiredPort;
            }
        }
        $listen = array_values(array_unique($listen));

        $needsListenUpdate = false;
        foreach ($requiredListen as $requiredPort) {
            if (!in_array($requiredPort, $existingListen, true)) {
                $needsListenUpdate = true;
                break;
            }
        }
        if ($listenCode < 200 || $listenCode >= 300 || $needsListenUpdate) {
            // SOLO la hoja listen. Antes, si ya existía, se hacía PATCH sobre srv0
            // entero con {listen:[…]}: Caddy SUSTITUYE el objeto, así que srv0 se
            // quedaba SIN RUTAS (todas las webs fuera). Causa real del incidente de
            // agosto y de la caída de Caddy en obelix con 1.0.224.
            [$listenOk] = self::caddySetLeaf($caddyApi, 'apps/http/servers/srv0/listen', $listen);
            if (!$listenOk) {
                return false;
            }
        }

        // Ensure routes key exists
        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $routesRaw = curl_exec($ch);
        $routesCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($routesCode === 404) {
            $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => json_encode([]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
            ]);
            curl_exec($ch);
            $putCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!($putCode >= 200 && $putCode < 300)) {
                return false;
            }
        } elseif ($routesCode >= 200 && $routesCode < 300) {
            // Recover only from the specific broken state observed in some nodes:
            // routes endpoint returns null/empty after srv0 listen patching.
            // Do not touch routes in any other malformed case.
            $routesRawTrim = trim((string)$routesRaw);
            if ($routesRawTrim === '' || strtolower($routesRawTrim) === 'null') {
                // ── Anti-TOCTOU (incidente 2026-08-06) ──
                // Un routes vacío/null AQUÍ suele ser un artefacto TRANSITORIO del PATCH
                // de listen de arriba, no un server realmente vacío. Reintentamos la
                // lectura antes de actuar; y NUNCA vaciamos srv0 (server compartido con
                // webs de terceros) por una sola lectura perdida.
                $reappeared = false;
                for ($attempt = 0; $attempt < 3 && !$reappeared; $attempt++) {
                    usleep(500000); // 0.5s entre reintentos
                    $rch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
                    curl_setopt_array($rch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
                    $reRaw = curl_exec($rch);
                    $reCode = (int)curl_getinfo($rch, CURLINFO_HTTP_CODE);
                    curl_close($rch);
                    $reDecoded = json_decode((string)$reRaw, true);
                    if ($reCode >= 200 && $reCode < 300 && is_array($reDecoded) && array_is_list($reDecoded) && count($reDecoded) > 0) {
                        $reappeared = true; // era un null transitorio; las rutas siguen ahí
                    }
                }
                if ($reappeared) {
                    // Config intacta: no hay nada que inicializar; no tocar srv0.
                    $routesRaw = null;
                } elseif ($routesCountBefore > 0) {
                    // Teníamos rutas antes y siguen sin leerse tras los reintentos: es una
                    // lectura perdida, NO un estado vacío. Rehusar vaciar un server
                    // compartido; abortar (el guard del CLI / el caller decide).
                    error_log("[caddy] ensureCaddyHttpServerReady: srv0/routes se leyó vacío tras {$routesCountBefore} ruta(s) previa(s); se aborta para no vaciar el server compartido (posible null transitorio del PATCH de listen).");
                    return false;
                } else {
                    // Genuinamente vacío (no había rutas antes y persiste vacío tras
                    // reintentos) → inicializar la clave a [] es seguro.
                    $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
                    curl_setopt_array($ch, [
                        CURLOPT_CUSTOMREQUEST => 'PUT',
                        CURLOPT_POSTFIELDS => json_encode([]),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 8,
                    ]);
                    curl_exec($ch);
                    $putCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if (!($putCode >= 200 && $putCode < 300)) {
                        return false;
                    }
                }
            } else {
                $decodedRoutes = json_decode((string)$routesRaw, true);
                $isValidList = is_array($decodedRoutes) && array_is_list($decodedRoutes);
                if (!$isValidList) {
                    return false;
                }
            }
        } else {
            return false;
        }

        if ($enforcePanelPort) {
            $panelServerName = self::getPanelAdminServerName();
            $canApplyFallbackRoute = $panelRouteServer === 'srv0'
                || $panelRouteServer === $panelServerName
                || self::serverProxiesToPanelInternalPort($caddyApi, $panelRouteServer);
            if ($canApplyFallbackRoute && !self::ensurePanelFallbackRoute($caddyApi, $panelRouteServer)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Keep a deterministic fallback route for admin access by IP/hostname on PANEL_PORT.
     * This prevents panel lockouts after caddy reloads that drop runtime routes.
     */
    private static function ensurePanelFallbackRoute(string $caddyApi, string $serverName = 'srv0'): bool
    {
        $panelPort = self::getPanelPublicPort();
        $internalPort = self::getPanelInternalPort();
        $expectedExpr = '{http.request.port} == ' . $panelPort;
        $expectedHosts = self::panelIpFallbackHosts();

        $routesUrl = "{$caddyApi}/config/apps/http/servers/{$serverName}/routes";
        $ch = curl_init($routesUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $routesRaw = curl_exec($ch);
        $routesCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!($routesCode >= 200 && $routesCode < 300)) {
            return false;
        }
        $routes = json_decode((string)$routesRaw, true);
        if (!is_array($routes) || !array_is_list($routes)) {
            return false;
        }

        foreach ($routes as $route) {
            if ((string)($route['@id'] ?? '') !== self::PANEL_FALLBACK_ROUTE_ID) {
                continue;
            }
            if (self::panelFallbackRouteMatches($route, $expectedHosts, $expectedExpr, $internalPort)) {
                return true;
            }
            break;
        }

        $fallbackRoute = [
            '@id' => self::PANEL_FALLBACK_ROUTE_ID,
            'match' => [[
                'host' => $expectedHosts,
                'expression' => $expectedExpr,
            ]],
            'handle' => self::panelReverseProxyHandle($internalPort),
            'terminal' => true,
        ];

        // Route IDs are global in Caddy. Delete a stale fallback from any server
        // before POSTing into the actual PANEL_PORT owner (srv1 on Caddyfile installs).
        self::deleteRouteById($caddyApi, self::PANEL_FALLBACK_ROUTE_ID);
        $ch = curl_init($routesUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($fallbackRoute),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        curl_exec($ch);
        $postCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $postCode >= 200 && $postCode < 300;
    }

    private static function panelIpFallbackHosts(): array
    {
        $hosts = [];
        $add = static function (string $host) use (&$hosts): void {
            $host = strtolower(trim($host));
            if ($host === '') {
                return;
            }
            if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $hosts[$host] = true;
            }
        };

        $add((string)\MuseDockPanel\Settings::get('server_ip', ''));

        $rawIps = trim((string)shell_exec('hostname -I 2>/dev/null'));
        if ($rawIps !== '') {
            foreach (preg_split('/\s+/', $rawIps) ?: [] as $ip) {
                $add((string)$ip);
            }
        }

        $add('127.0.0.1');
        $add('localhost');

        $list = array_keys($hosts);
        sort($list, SORT_NATURAL);
        return $list;
    }

    private static function panelFallbackRouteMatches(array $route, array $expectedHosts, string $expectedExpr, int $internalPort): bool
    {
        if ((string)($route['@id'] ?? '') !== self::PANEL_FALLBACK_ROUTE_ID) {
            return false;
        }

        $match = $route['match'][0] ?? [];
        if (!is_array($match)) {
            return false;
        }

        $expr = trim((string)($match['expression'] ?? ''));
        if ($expr !== $expectedExpr) {
            return false;
        }

        $hosts = $match['host'] ?? [];
        if (!is_array($hosts)) {
            return false;
        }
        $normalizedHosts = array_fill_keys(array_map(
            static fn($host) => strtolower(trim((string)$host)),
            $hosts
        ), true);
        foreach ($expectedHosts as $host) {
            if (!isset($normalizedHosts[strtolower((string)$host)])) {
                return false;
            }
        }

        $expectedDial = "127.0.0.1:{$internalPort}";
        return self::routesContainReverseProxyDial([$route], $expectedDial);
    }

    /**
     * Returns the HTTP server name (srvX) currently listening on the provided port,
     * excluding an optional server name. Returns null when no owner is found.
     */
    private static function findServerByListenPort(string $caddyApi, int $port, string $excludeServer = ''): ?string
    {
        if ($port <= 0) {
            return null;
        }

        $ch = curl_init("{$caddyApi}/config/apps/http/servers");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!($httpCode >= 200 && $httpCode < 300)) {
            return null;
        }

        $servers = json_decode((string)$raw, true);
        if (!is_array($servers)) {
            return null;
        }

        $needle = ':' . $port;
        foreach ($servers as $serverName => $serverCfg) {
            if (!is_string($serverName) || ($excludeServer !== '' && $serverName === $excludeServer)) {
                continue;
            }
            $listen = $serverCfg['listen'] ?? null;
            if (!is_array($listen) || !array_is_list($listen)) {
                continue;
            }
            if (in_array($needle, $listen, true)) {
                return $serverName;
            }
        }

        return null;
    }

    private static function fetchCaddyRoutes(string $caddyApi, bool $enforcePanelPort = false, string $serverName = 'srv0'): array
    {
        if (!self::ensureCaddyHttpServerReady($caddyApi, $enforcePanelPort)) {
            return ['ok' => false, 'error' => 'No se pudo preparar srv0/listeners en Caddy'];
        }

        if ($serverName === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $serverName)) {
            $serverName = 'srv0';
        }

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/{$serverName}/routes");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!($httpCode >= 200 && $httpCode < 300)) {
            return ['ok' => false, 'error' => "Caddy API no disponible (HTTP {$httpCode})"];
        }

        $routes = json_decode((string)$response, true);
        if (!is_array($routes)) {
            $routes = [];
        }
        return ['ok' => true, 'routes' => $routes];
    }

    private static function serverProxiesToPanelInternalPort(string $caddyApi, string $serverName): bool
    {
        if ($serverName === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $serverName)) {
            return false;
        }

        $expectedDial = '127.0.0.1:' . self::getPanelInternalPort();
        $raw = @file_get_contents("{$caddyApi}/config/apps/http/servers/{$serverName}/routes");
        $routes = json_decode((string)$raw, true);
        if (!is_array($routes)) {
            return false;
        }

        return self::routesContainReverseProxyDial($routes, $expectedDial);
    }

    private static function routesContainReverseProxyDial(array $routes, string $expectedDial): bool
    {
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            foreach (($route['handle'] ?? []) as $handler) {
                if (!is_array($handler)) {
                    continue;
                }

                if (($handler['handler'] ?? '') === 'reverse_proxy') {
                    foreach (($handler['upstreams'] ?? []) as $upstream) {
                        if (($upstream['dial'] ?? '') === $expectedDial) {
                            return true;
                        }
                    }
                }

                if (($handler['handler'] ?? '') === 'subroute') {
                    $nestedRoutes = $handler['routes'] ?? [];
                    if (is_array($nestedRoutes) && self::routesContainReverseProxyDial($nestedRoutes, $expectedDial)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function findHostRouteConflict(array $routes, string $hostname, string|array $excludeRouteId): ?string
    {
        $excludeRouteIds = is_array($excludeRouteId)
            ? array_fill_keys(array_map('strval', $excludeRouteId), true)
            : [(string)$excludeRouteId => true];

        foreach ($routes as $route) {
            $rid = (string)($route['@id'] ?? '');
            if (isset($excludeRouteIds[$rid])) {
                continue;
            }

            foreach (($route['match'] ?? []) as $match) {
                foreach (($match['host'] ?? []) as $host) {
                    if (strcasecmp((string)$host, $hostname) === 0) {
                        return $rid !== '' ? $rid : 'route-sin-id';
                    }
                }
            }
        }
        return null;
    }

    private static function deleteRouteById(string $caddyApi, string $routeId): bool
    {
        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 300) || $httpCode === 404;
    }

    private static function warmupPanelDomainTls(string $hostname, int $panelPort): void
    {
        $url = $panelPort === 443 ? "https://{$hostname}/" : "https://{$hostname}:{$panelPort}/";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RESOLVE => ["{$hostname}:{$panelPort}:127.0.0.1"],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private static function buildPanelDomainDnsWarning(string $hostname): string
    {
        $publicIp = trim((string)@file_get_contents(
            'https://ifconfig.me/ip',
            false,
            stream_context_create(['http' => ['timeout' => 3]])
        ));
        if ($publicIp === '') {
            $publicIp = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));
        }
        if ($publicIp === '') {
            return '';
        }

        $dns = CloudflareService::checkDomainDns($hostname, $publicIp);
        $status = (string)($dns['status'] ?? '');

        if ($status === 'ok') {
            return '';
        }

        if ($status === 'none') {
            return "Dominio guardado, pero {$hostname} aun no tiene registro A en DNS. Sin DNS no habra certificado publico.";
        }

        if ($status === 'elsewhere') {
            $ips = implode(', ', $dns['ips'] ?? []);
            return "Dominio guardado. DNS de {$hostname} apunta a {$ips}, no a este servidor ({$publicIp}).";
        }

        if ($status === 'cloudflare') {
            return "Dominio guardado. {$hostname} parece pasar por Cloudflare; revisa SSL mode (Full/Strict) y que el origen este accesible.";
        }

        return '';
    }

    /**
     * Add a Caddy route matching multiple domains (main + aliases).
     * Deletes existing route first, then creates with all domains.
     */
    public static function rebuildCaddyRouteWithAliases(string $mainDomain, array $aliasDomains, string $documentRoot, string $username, string $phpVersion = '8.3', string $hostingType = 'php'): ?string
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];
        if (!self::ensureCaddyHttpServerReady($caddyApi)) {
            return null;
        }
        $routeId = self::caddyRouteId($mainDomain);

        // Build full host list: main + www.main + each alias + www.alias
        $hosts = self::hostsWithWww($mainDomain);
        foreach ($aliasDomains as $alias) {
            $alias = trim($alias);
            if ($alias && !in_array($alias, $hosts)) {
                $hosts = array_merge($hosts, self::hostsWithWww($alias));
            }
        }

        // Delete existing route
        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($ch);
        curl_close($ch);

        // Refresh CF zones for new alias domains, then rebuild TLS policies
        $allDomains = array_merge([$mainDomain], $aliasDomains);
        foreach ($allDomains as $d) {
            $rootD = implode('.', array_slice(explode('.', trim($d)), -2));
            if ($rootD && !CloudflareService::findZoneForDomain($rootD)) {
                CloudflareService::refreshZones();
                break;
            }
        }
        self::ensureTlsCatchAllPolicy($caddyApi);

        $subroutes = self::buildCaddySubroutes($documentRoot, $username, $phpVersion, $hostingType);
        $caddyConfig = [
            '@id' => $routeId,
            'match' => [['host' => $hosts]],
            'handle' => [['handler' => 'subroute', 'routes' => $subroutes]],
            'terminal' => true,
        ];

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($caddyConfig),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            // Register all domains for access logging (Fail2Ban wp-login protection)
            self::ensureHostingAccessLog($caddyApi, $hosts);
            return $routeId;
        }
        return null;
    }

    /**
     * Add a Caddy redirect route (301/302) for a domain pointing to another domain.
     */
    /**
     * Los nombres que sirve Caddy para un dominio: el dominio y, si es la raíz de su
     * zona (ejemplo.com, aca.org.es), también www. A un subdominio (develop.ejemplo.org,
     * webmail.cliente.com) solo se le añade www si ese www existe en el DNS: antes se
     * añadía siempre y quedaban nombres sin DNS a los que Caddy intentaba sacar certificado.
     */
    public static function hostsWithWww(string $domain): array
    {
        $d = strtolower(trim($domain));
        if ($d === '' || str_starts_with($d, 'www.') || str_starts_with($d, '*.')) {
            return $d === '' ? [] : [$d];
        }
        return self::isApexDomain($d) || self::nameHasDns("www.{$d}") ? [$d, "www.{$d}"] : [$d];
    }

    /** ¿Es la raíz de su zona? Primero por las zonas de Cloudflare del panel; si no, por sufijos conocidos. */
    public static function isApexDomain(string $domain): bool
    {
        static $zones = null;
        if ($zones === null) {
            $zones = [];
            try {
                foreach (CloudflareService::getConfiguredAccounts() as $a) {
                    foreach (($a['zones'] ?? []) as $z) {
                        $zones[strtolower((string)($z['name'] ?? ''))] = true;
                    }
                }
            } catch (\Throwable) {
            }
        }
        $d = strtolower(rtrim($domain, '.'));
        if (isset($zones[$d])) {
            return true;
        }
        foreach (array_keys($zones) as $z) {
            if ($z !== '' && str_ends_with($d, '.' . $z)) {
                return false;   // subdominio de una zona conocida
            }
        }
        // Sin zona conocida: dos niveles (ejemplo.com) o tres con sufijo de dos niveles (ejemplo.org.es).
        $parts = explode('.', $d);
        if (count($parts) <= 2) {
            return true;
        }
        $second = ['com', 'org', 'net', 'edu', 'gob', 'gov', 'nom', 'co', 'ac', 'or', 'ne', 'go', 'ltd', 'plc', 'me'];
        return count($parts) === 3 && in_array($parts[1], $second, true) && strlen($parts[2]) === 2;
    }

    private static function nameHasDns(string $name): bool
    {
        static $cache = [];
        return $cache[$name] ??= (bool)(@dns_get_record($name, DNS_A) ?: @dns_get_record($name, DNS_CNAME));
    }

    public static function addCaddyRedirectRoute(string $fromDomain, string $toDomain, int $code = 301, bool $preservePath = true): ?string
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];
        if (!self::ensureCaddyHttpServerReady($caddyApi)) {
            return null;
        }
        $routeId = 'redirect-' . str_replace('.', '-', $fromDomain);

        // Refresh CF zones if redirect domain is unknown
        if ($fromDomain !== '' && !CloudflareService::findZoneForDomain($fromDomain)) {
            CloudflareService::refreshZones();
        }
        self::ensureTlsCatchAllPolicy($caddyApi);

        $location = $preservePath
            ? "https://{$toDomain}{http.request.uri}"
            : "https://{$toDomain}/";

        $caddyConfig = [
            '@id' => $routeId,
            'match' => [['host' => self::hostsWithWww($fromDomain)]],
            'handle' => [
                [
                    'handler' => 'static_response',
                    'status_code' => (string)$code,
                    'headers' => ['Location' => [$location]],
                ]
            ],
            'terminal' => true,
        ];

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($caddyConfig),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 300) ? $routeId : null;
    }

    /**
     * Remove a Caddy redirect route by domain.
     */
    public static function removeCaddyRedirectRoute(string $fromDomain): bool
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];
        $routeId = 'redirect-' . str_replace('.', '-', $fromDomain);

        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Update just the document root on an existing Caddy route (delete + recreate)
     */
    public static function updateCaddyDocumentRoot(string $domain, string $newDocRoot, string $username, string $phpVersion = '8.3'): bool
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];
        $routeId = self::caddyRouteId($domain);

        // Delete existing route
        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($ch);
        curl_close($ch);

        // Recreate with new document root
        $newRouteId = self::addCaddyRoute($domain, $newDocRoot, $username, $phpVersion);
        return $newRouteId !== null;
    }

    /**
     * Suspend an account (stop FPM pool, replace Caddy route with maintenance page)
     */
    public static function suspendAccount(string $username, string $fpmSocket, string $domain = '', string $phpVersion = '', bool $removeFromCaddy = false): void
    {
        // Lock the user
        shell_exec(sprintf('usermod -L %s 2>&1', escapeshellarg($username)));

        // Rename FPM pool to disable it — use the account's PHP version, not the global default
        if (empty($phpVersion)) {
            $config = require PANEL_ROOT . '/config/panel.php';
            $phpVersion = $config['fpm']['php_version'];
        }
        $poolFile = "/etc/php/{$phpVersion}/fpm/pool.d/{$username}.conf";
        // Also check other PHP versions in case the pool is there
        if (!file_exists($poolFile)) {
            foreach (['8.3', '8.2', '8.1', '8.0'] as $ver) {
                $altPool = "/etc/php/{$ver}/fpm/pool.d/{$username}.conf";
                if (file_exists($altPool)) {
                    $poolFile = $altPool;
                    $phpVersion = $ver;
                    break;
                }
            }
        }
        shell_exec(sprintf('mv %s %s.disabled 2>&1', escapeshellarg($poolFile), escapeshellarg($poolFile)));
        shell_exec(sprintf('systemctl reload php%s-fpm 2>&1', self::safePhpVersion($phpVersion)));

        // Replace Caddy route with maintenance page — o, si se pide (dominio caducado o
        // sin uso), quitarla: Caddy deja de servirlo y de pedir su certificado. No se
        // borra nada; activateAccount() la vuelve a crear.
        if ($domain && $removeFromCaddy) {
            $ch = curl_init($config['caddy']['api_url'] . '/id/' . self::caddyRouteId($domain));
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($ch);
            curl_close($ch);
        } elseif ($domain) {
            self::setCaddyMaintenanceRoute($username, $domain, $config['caddy']['api_url']);
        }
    }

    /**
     * Activate a suspended account
     */
    public static function activateAccount(string $username, string $fpmSocket, string $domain = '', string $documentRoot = '', string $phpVersion = ''): void
    {
        // Unlock the user
        shell_exec(sprintf('usermod -U %s 2>&1', escapeshellarg($username)));

        // Re-enable FPM pool
        $config = require PANEL_ROOT . '/config/panel.php';
        $phpVer = $phpVersion ?: $config['fpm']['php_version'];
        $poolFile = "/etc/php/{$phpVer}/fpm/pool.d/{$username}.conf";
        // Search other PHP versions if disabled pool not found
        if (!file_exists($poolFile . '.disabled')) {
            foreach (['8.3', '8.2', '8.1', '8.0'] as $ver) {
                $altPool = "/etc/php/{$ver}/fpm/pool.d/{$username}.conf";
                if (file_exists($altPool . '.disabled')) {
                    $poolFile = $altPool;
                    $phpVer = $ver;
                    break;
                }
            }
        }
        shell_exec(sprintf('mv %s.disabled %s 2>&1', escapeshellarg($poolFile), escapeshellarg($poolFile)));
        shell_exec(sprintf('systemctl reload php%s-fpm 2>&1', self::safePhpVersion($phpVer)));

        // Restore normal Caddy route
        if ($domain && $documentRoot) {
            $caddyApi = $config['caddy']['api_url'];
            $routeId = self::caddyRouteId($domain);
            // Delete maintenance route
            $ch = curl_init("{$caddyApi}/id/{$routeId}");
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($ch);
            curl_close($ch);
            // Recreate normal route
            self::addCaddyRoute($domain, $documentRoot, $username, $phpVer);
        }
    }

    /**
     * Replace a domain's Caddy route with a maintenance/suspended page
     */
    public static function setCaddyMaintenanceRoute(string $username, string $domain, string $caddyApi): void
    {
        $routeId = self::caddyRouteId($domain);

        $html = self::getMaintenanceHtml($domain);

        $maintenanceRoute = [
            '@id' => $routeId,
            'match' => [['host' => self::hostsWithWww($domain)]],
            'handle' => [
                [
                    'handler' => 'static_response',
                    'status_code' => '503',
                    'headers' => [
                        'Content-Type' => ['text/html; charset=utf-8'],
                        'Retry-After' => ['3600'],
                    ],
                    'body' => $html,
                ]
            ],
            'terminal' => true,
        ];

        // Use PUT by ID to REPLACE the existing route (not DELETE + POST which can leave duplicates)
        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($maintenanceRoute),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    public static function getMaintenanceHtml(string $domain): string
    {
        return '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sitio en mantenimiento</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f172a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#e2e8f0}
.container{text-align:center;max-width:500px;padding:2rem}
.icon{font-size:4rem;margin-bottom:1.5rem;opacity:0.6}
h1{font-size:1.5rem;font-weight:600;margin-bottom:0.75rem;color:#f1f5f9}
p{color:#94a3b8;line-height:1.6;margin-bottom:0.5rem}
.domain{color:#38bdf8;font-weight:500}
.badge{display:inline-block;margin-top:1.5rem;padding:0.4rem 1rem;background:rgba(251,191,36,0.15);color:#fbbf24;border-radius:20px;font-size:0.8rem;border:1px solid rgba(251,191,36,0.25)}
</style>
</head>
<body>
<div class="container">
<div class="icon">&#128736;</div>
<h1>Sitio en mantenimiento</h1>
<p>El sitio <span class="domain">' . htmlspecialchars($domain) . '</span> se encuentra temporalmente fuera de servicio por tareas de mantenimiento.</p>
<p>Disculpa las molestias. Volveremos pronto.</p>
<div class="badge">&#9202; Mantenimiento programado</div>
</div>
</body>
</html>';
    }

    /**
     * Delete an account completely
     */
    public static function deleteAccount(string $username, string $domain, string $homeDir): void
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $phpVersion = $config['fpm']['php_version'];

        // Remove FPM pool
        $poolFile = "/etc/php/{$phpVersion}/fpm/pool.d/{$username}.conf";
        shell_exec(sprintf('rm -f %s %s.disabled 2>&1', escapeshellarg($poolFile), escapeshellarg($poolFile)));
        shell_exec(sprintf('systemctl reload php%s-fpm 2>&1', self::safePhpVersion($phpVersion)));

        // Remove Caddy route
        $caddyApi = $config['caddy']['api_url'];
        $routeId = self::caddyRouteId($domain);
        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Remove system user (keeps home dir for safety - manual cleanup)
        shell_exec(sprintf('userdel %s 2>&1', escapeshellarg($username)));

        // Restart lsyncd so it stops watching the deleted vhost
        self::restartLsyncd();
    }

    /**
     * Restart lsyncd so it re-scans /var/www/vhosts/ for new or removed directories.
     */
    public static function restartLsyncd(): void
    {
        shell_exec('systemctl restart lsyncd 2>&1');
    }

    /**
     * Change the shell for a Linux user (SSH, SFTP-only, or no access)
     */
    public static function changeShell(string $username, string $shell): bool
    {
        $allowed = ['/bin/bash', '/usr/sbin/nologin', '/bin/false'];
        if (!in_array($shell, $allowed)) $shell = '/usr/sbin/nologin';

        shell_exec(sprintf('usermod -s %s %s 2>&1', escapeshellarg($shell), escapeshellarg($username)));
        return true;
    }

    /**
     * Rename a system user: changes Linux username, home dir, FPM pool, Caddy route, file ownership.
     * Like Plesk's "Change System User" feature.
     */
    public static function renameUser(string $oldUsername, string $newUsername, string $domain, string $phpVersion = '8.3'): array
    {
        $errors = [];
        $homeDir = "/var/www/vhosts/{$domain}";
        $documentRoot = "{$homeDir}/httpdocs";

        // 1. Stop FPM pool for old user
        $oldPoolFile = "/etc/php/{$phpVersion}/fpm/pool.d/{$oldUsername}.conf";
        if (file_exists($oldPoolFile)) {
            shell_exec(sprintf('rm -f %s 2>&1', escapeshellarg($oldPoolFile)));
            shell_exec(sprintf('systemctl reload php%s-fpm 2>&1', self::safePhpVersion($phpVersion)));
        }

        // 2. Kill any processes of old user
        shell_exec(sprintf('pkill -u %s 2>/dev/null', escapeshellarg($oldUsername)));
        usleep(500000); // wait 0.5s

        // 3. Rename the Linux user
        $output = shell_exec(sprintf('usermod -l %s %s 2>&1', escapeshellarg($newUsername), escapeshellarg($oldUsername)));
        if (!empty(trim($output ?? '')) && strpos($output, 'no changes') === false) {
            // Check if rename actually worked
            $check = shell_exec(sprintf('id -u %s 2>/dev/null', escapeshellarg($newUsername)));
            if (empty(trim($check ?? ''))) {
                return ['success' => false, 'error' => 'Error renombrando usuario Linux: ' . trim($output)];
            }
        }

        // 4. Update group name
        shell_exec(sprintf('groupmod -n %s %s 2>&1', escapeshellarg($newUsername), escapeshellarg($oldUsername)));

        // 5. Change ownership of all files in home dir
        shell_exec(sprintf('chown -R %s:www-data %s 2>&1', escapeshellarg($newUsername), escapeshellarg($homeDir)));

        // 6. Create new FPM pool
        $fpmSocket = self::createFpmPool($newUsername, $phpVersion, $homeDir);
        if (!$fpmSocket) {
            $errors[] = 'FPM pool creation failed';
        }

        // 7. Update Caddy route (delete old, create new)
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];

        // Delete old route
        $oldRouteId = self::caddyRouteId($domain);
        $ch = curl_init("{$caddyApi}/id/{$oldRouteId}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($ch);
        curl_close($ch);

        // Create new route
        $newRouteId = self::addCaddyRoute($domain, $documentRoot, $newUsername, $phpVersion);
        if (!$newRouteId) {
            $errors[] = 'Caddy route creation failed';
        }

        return [
            'success' => true,
            'fpm_socket' => $fpmSocket ?? "unix//run/php/php{$phpVersion}-fpm-{$newUsername}.sock",
            'caddy_route_id' => $newRouteId ?? self::caddyRouteId($domain),
            'warnings' => $errors,
        ];
    }

    /**
     * Get disk usage of a directory in MB
     */
    public static function getDiskUsage(string $path): int
    {
        if (!is_dir($path)) return 0;
        $output = shell_exec(sprintf('/opt/musedock-panel/bin/du-throttled -sm %s 2>/dev/null | cut -f1', escapeshellarg($path)));
        return (int) trim($output ?: '0');
    }
}
