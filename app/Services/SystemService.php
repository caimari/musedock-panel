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
    private const PANEL_DOMAIN_ROUTE_ID = 'panel-domain-route';
    private static ?bool $hasCloudflareDnsProvider = null;

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

        // Build useradd command — force UID if provided (for cluster sync)
        $uidFlag = '';
        if ($forceUid !== null && $forceUid > 0) {
            // Check if UID is already taken by another user
            $uidCheck = shell_exec(sprintf('getent passwd %d 2>/dev/null', $forceUid));
            if (empty(trim($uidCheck ?? ''))) {
                $uidFlag = sprintf(' -u %d', $forceUid);
            }
        }

        $cmd = sprintf(
            'useradd -m -d %s -s %s -G www-data%s %s 2>&1',
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
     * Set password for a Linux user
     */
    public static function setUserPassword(string $username, string $password): bool
    {
        $cmd = sprintf(
            'echo %s:%s | chpasswd 2>&1',
            escapeshellarg($username),
            escapeshellarg($password)
        );
        shell_exec($cmd);
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
     * 2. Catch-all: HTTP-01 first (for non-CF domains), DNS-01 with primary token as fallback
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

        // Catch-all: HTTP-01 first (non-CF domains), DNS-01 fallback (CF domains with primary token)
        $newPolicies[] = self::buildCfPolicy($cfToken, [], $canUseCloudflareDns);

        self::patchTlsPolicies($caddyApi, $newPolicies);
    }

    private static function buildCfPolicy(string $cfToken, array $subjects = [], bool $allowDns = true): array
    {
        if (!empty($subjects)) {
            // Per-account policy: DNS-01 only (domains behind Cloudflare proxy)
            $acmeIssuer = ['email' => 'admin@musedock.com', 'module' => 'acme'];
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
        $httpIssuer = ['email' => 'admin@musedock.com', 'module' => 'acme'];
        $issuers = [$httpIssuer];

        if ($allowDns && $cfToken) {
            $dnsIssuer = [
                'email' => 'admin@musedock.com',
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
        if (self::$hasCloudflareDnsProvider !== null) {
            return self::$hasCloudflareDnsProvider;
        }

        $out = trim((string) shell_exec('caddy list-modules 2>/dev/null | grep -E "^dns\\.providers\\.cloudflare$"'));
        self::$hasCloudflareDnsProvider = ($out === 'dns.providers.cloudflare');
        return self::$hasCloudflareDnsProvider;
    }

    private static function patchTlsPolicies(string $caddyApi, array $policies): void
    {
        // Some Caddy states do not have apps.tls.automation yet.
        // Seed the path so PATCH below does not fail with "invalid traversal path".
        $ensureTlsPath = static function () use ($caddyApi): void {
            $ch = curl_init("{$caddyApi}/config/apps/tls/automation");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                return;
            }

            $ch = curl_init("{$caddyApi}/config/apps");
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => json_encode(['tls' => ['automation' => ['policies' => []]]]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);
        };

        $ensureTlsPath();

        // Use DELETE + POST to fully replace (PATCH merges and can leave stale policies)
        $ch = curl_init("{$caddyApi}/config/apps/tls/automation/policies");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Set the complete policy list
        $ch = curl_init("{$caddyApi}/config/apps/tls/automation");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode(['policies' => $policies]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (($httpCode < 200 || $httpCode >= 300) && str_contains(strtolower($resp), 'invalid traversal path')) {
            // Retry once after recreating tls.automation path
            $ensureTlsPath();
            $ch = curl_init("{$caddyApi}/config/apps/tls/automation");
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => json_encode(['policies' => $policies]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $resp = (string) curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        // Some nodes run a Caddy build without cloudflare DNS provider.
        // If that happens, retry with a degraded HTTP-only policy set so TLS automation
        // still works for direct (non-wildcard) domains and panel hostnames.
        if ($httpCode >= 200 && $httpCode < 300) {
            return;
        }

        if (!str_contains(strtolower($resp), 'dns.providers.cloudflare')) {
            return;
        }

        $fallbackPolicies = self::degradePoliciesWithoutDnsProvider($policies);
        $ch = curl_init("{$caddyApi}/config/apps/tls/automation");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode(['policies' => $fallbackPolicies]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
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
                    'email' => 'admin@musedock.com',
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
    private static function buildCaddySubroutes(string $documentRoot, string $username, string $phpVersion, string $hostingType): array
    {
        $routes = [];

        // 1. Set root for all subroutes
        $routes[] = ['handle' => [['handler' => 'vars', 'root' => $documentRoot]]];

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
        $rootDomain = implode('.', array_slice(explode('.', $domain), -2));
        $knownZone = CloudflareService::findZoneForDomain($rootDomain);
        if (!$knownZone) {
            CloudflareService::refreshZones();
        }
        self::ensureTlsCatchAllPolicy($caddyApi);

        $hosts = [$domain, "www.{$domain}"];
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
            self::ensureHostingAccessLog($caddyApi, [$domain, "www.{$domain}"]);
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
        // Keep admin panel access on :8444 to preserve MIT/admin fallback separation.
        $panelPublicPort = 8444;
        $internalPort = self::getPanelInternalPort();

        $routesResult = self::fetchCaddyRoutes($caddyApi, true);
        if (!($routesResult['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string)($routesResult['error'] ?? 'No se pudo leer la config de Caddy')];
        }
        $routes = $routesResult['routes'] ?? [];

        $conflict = self::findHostRouteConflict($routes, $hostname, self::PANEL_DOMAIN_ROUTE_ID);
        if ($conflict !== null) {
            return ['ok' => false, 'error' => "El dominio {$hostname} ya esta en uso por la ruta Caddy '{$conflict}'."];
        }

        // Keep TLS policies fresh so DNS-01 fallback is available when needed.
        self::ensureTlsCatchAllPolicy($caddyApi);

        self::deleteRouteById($caddyApi, self::PANEL_DOMAIN_ROUTE_ID);

        $route = [
            '@id' => self::PANEL_DOMAIN_ROUTE_ID,
            // Panel hostname must only be served on PANEL_PORT (e.g. 8444).
            'match' => [[
                'host' => [$hostname],
                'expression' => '{http.request.port} == ' . $panelPublicPort,
            ]],
            'handle' => [[
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
            ]],
            'terminal' => true,
        ];

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
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
        return ['ok' => true, 'warning' => $warning];
    }

    /**
     * Remove panel domain Caddy route if present.
     */
    public static function removePanelDomainRoute(): bool
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'] ?? 'http://localhost:2019';
        return self::deleteRouteById($caddyApi, self::PANEL_DOMAIN_ROUTE_ID);
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

    /**
     * Ensure Caddy has srv0 and required listeners.
     * By default only enforces :443 (hosting path). When $enforcePanelPort is true,
     * also enforces panel public port (8444 by default).
     *
     * Returns false only when the admin API is unreachable or the config cannot be repaired.
     */
    public static function ensureCaddyHttpServerReady(string $caddyApi, bool $enforcePanelPort = false): bool
    {
        $requiredListen = [':443'];
        if ($enforcePanelPort) {
            $panelPort = self::getPanelPublicPort();
            $panelListen = ':' . $panelPort;
            if (!in_array($panelListen, $requiredListen, true)) {
                $requiredListen[] = $panelListen;
            }
        }

        $serverUrl = "{$caddyApi}/config/apps/http/servers/srv0";

        // Check if srv0 exists
        $ch = curl_init($serverUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 404) {
            $initialServer = ['listen' => $requiredListen, 'routes' => []];

            // Create srv0 directly
            $ch = curl_init($serverUrl);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($initialServer),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
            ]);
            curl_exec($ch);
            $createCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!($createCode >= 200 && $createCode < 300)) {
                // Fallback: patch into servers map
                $ch = curl_init("{$caddyApi}/config/apps/http/servers");
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => 'PATCH',
                    CURLOPT_POSTFIELDS => json_encode(['srv0' => $initialServer]),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 8,
                ]);
                curl_exec($ch);
                $patchCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if (!($patchCode >= 200 && $patchCode < 300)) {
                    // Last fallback: create whole http app with srv0
                    $ch = curl_init("{$caddyApi}/config/apps/http");
                    curl_setopt_array($ch, [
                        CURLOPT_CUSTOMREQUEST => 'PATCH',
                        CURLOPT_POSTFIELDS => json_encode(['servers' => ['srv0' => $initialServer]]),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 8,
                    ]);
                    curl_exec($ch);
                    $httpPatchCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if (!($httpPatchCode >= 200 && $httpPatchCode < 300)) {
                        // Final fallback: patch parent apps object (some builds reject /apps/http when key is missing)
                        $ch = curl_init("{$caddyApi}/config/apps");
                        curl_setopt_array($ch, [
                            CURLOPT_CUSTOMREQUEST => 'PATCH',
                            CURLOPT_POSTFIELDS => json_encode(['http' => ['servers' => ['srv0' => $initialServer]]]),
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 8,
                        ]);
                        curl_exec($ch);
                        $appsPatchCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if (!($appsPatchCode >= 200 && $appsPatchCode < 300)) {
                            return false;
                        }
                    }
                }
            }
        } elseif (!($httpCode >= 200 && $httpCode < 300)) {
            return false;
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
            if ($listenCode === 404) {
                // listen key missing: create it
                $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/listen");
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_POSTFIELDS => json_encode($listen),
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
            } else {
                // listen key already exists: update parent object (PUT /listen returns 409 on some Caddy builds)
                $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0");
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => 'PATCH',
                    CURLOPT_POSTFIELDS => json_encode(['listen' => $listen]),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 8,
                ]);
                curl_exec($ch);
                $patchCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if (!($patchCode >= 200 && $patchCode < 300)) {
                    return false;
                }
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

        return true;
    }

    private static function fetchCaddyRoutes(string $caddyApi, bool $enforcePanelPort = false): array
    {
        if (!self::ensureCaddyHttpServerReady($caddyApi, $enforcePanelPort)) {
            return ['ok' => false, 'error' => 'No se pudo preparar srv0/listeners en Caddy'];
        }

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
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

    private static function findHostRouteConflict(array $routes, string $hostname, string $excludeRouteId): ?string
    {
        foreach ($routes as $route) {
            $rid = (string)($route['@id'] ?? '');
            if ($rid === $excludeRouteId) {
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
        $hosts = [$mainDomain, "www.{$mainDomain}"];
        foreach ($aliasDomains as $alias) {
            $alias = trim($alias);
            if ($alias && !in_array($alias, $hosts)) {
                $hosts[] = $alias;
                $hosts[] = "www.{$alias}";
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
    public static function addCaddyRedirectRoute(string $fromDomain, string $toDomain, int $code = 301, bool $preservePath = true): ?string
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];
        if (!self::ensureCaddyHttpServerReady($caddyApi)) {
            return null;
        }
        $routeId = 'redirect-' . str_replace('.', '-', $fromDomain);

        // Refresh CF zones if redirect domain is unknown
        $rootFrom = implode('.', array_slice(explode('.', $fromDomain), -2));
        if ($rootFrom && !CloudflareService::findZoneForDomain($rootFrom)) {
            CloudflareService::refreshZones();
        }
        self::ensureTlsCatchAllPolicy($caddyApi);

        $location = $preservePath
            ? "https://{$toDomain}{http.request.uri}"
            : "https://{$toDomain}/";

        $caddyConfig = [
            '@id' => $routeId,
            'match' => [['host' => [$fromDomain, "www.{$fromDomain}"]]],
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
    public static function suspendAccount(string $username, string $fpmSocket, string $domain = '', string $phpVersion = ''): void
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

        // Replace Caddy route with maintenance page
        if ($domain) {
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
            'match' => [['host' => [$domain, "www.{$domain}"]]],
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
