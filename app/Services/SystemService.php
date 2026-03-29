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
    public static function ensureTlsCatchAllPolicy(string $caddyApi): void
    {
        $policies = json_decode(
            @file_get_contents("{$caddyApi}/config/apps/tls/automation/policies") ?: '[]',
            true
        ) ?: [];

        // Read primary Cloudflare API token (for catch-all)
        $cfToken = trim(getenv('CLOUDFLARE_API_TOKEN') ?: '');
        if (!$cfToken) {
            $caddyEnv = @file_get_contents('/etc/default/caddy');
            if ($caddyEnv && preg_match('/^CLOUDFLARE_API_TOKEN=(.+)$/m', $caddyEnv, $m)) {
                $cfToken = trim($m[1]);
            }
        }

        // Get all configured Cloudflare accounts to create per-account TLS policies
        // This ensures each domain gets its certificate via the correct CF token
        $cfAccounts = CloudflareService::getConfiguredAccounts();

        // Collect all zone names from all additional CF accounts (skip the first/primary)
        // The primary account's domains are covered by the catch-all policy
        $additionalPolicies = [];
        foreach ($cfAccounts as $idx => $acct) {
            $token = $acct['token'] ?? '';
            if (!$token) continue;

            // Skip if this token matches the primary catch-all token
            if ($token === $cfToken) continue;

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
                $additionalPolicies[$token] = $subjects;
            }
        }

        // Rebuild policies: keep existing with subjects, update/add per-account and catch-all
        $newPolicies = [];
        $handledTokens = [];

        foreach ($policies as $p) {
            $subjects = $p['subjects'] ?? [];

            if (empty($subjects)) {
                // Catch-all — will be re-added at the end
                continue;
            }

            // Check if this policy's subjects belong to an additional CF account
            $isAdditionalCf = false;
            foreach ($additionalPolicies as $addToken => $addSubjects) {
                if (!empty(array_intersect($subjects, $addSubjects))) {
                    // This is one of our per-account policies — rebuild it
                    $isAdditionalCf = true;
                    if (!isset($handledTokens[$addToken])) {
                        $newPolicies[] = self::buildCfPolicy($addToken, $addSubjects);
                        $handledTokens[$addToken] = true;
                    }
                    break;
                }
            }

            if (!$isAdditionalCf) {
                // Keep existing policy as-is (musedock.com, mortadelo, etc.)
                $newPolicies[] = $p;
            }
        }

        // Add policies for additional accounts not yet handled
        foreach ($additionalPolicies as $addToken => $addSubjects) {
            if (!isset($handledTokens[$addToken])) {
                $newPolicies[] = self::buildCfPolicy($addToken, $addSubjects);
            }
        }

        // Add catch-all policy with primary token as fallback
        $newPolicies[] = self::buildCfPolicy($cfToken, []);

        self::patchTlsPolicies($caddyApi, $newPolicies);
    }

    private static function buildCfPolicy(string $cfToken, array $subjects = []): array
    {
        if (!empty($subjects)) {
            // Per-account policy: DNS-01 only (domains behind Cloudflare proxy)
            $acmeIssuer = ['email' => 'admin@musedock.com', 'module' => 'acme'];
            if ($cfToken) {
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

        if ($cfToken) {
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

    private static function patchTlsPolicies(string $caddyApi, array $policies): void
    {
        $ch = curl_init("{$caddyApi}/config/apps/tls/automation/policies");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode($policies),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Add a Caddy route via API for this domain
     */
    public static function addCaddyRoute(string $domain, string $documentRoot, string $username, string $phpVersion = '8.3'): ?string
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];
        $routeId = "hosting-{$username}";
        $socketPath = "/run/php/php{$phpVersion}-fpm-{$username}.sock";

        // Refresh CF zones if this domain isn't in any known zone, then rebuild TLS policies
        $rootDomain = implode('.', array_slice(explode('.', $domain), -2));
        $knownZone = CloudflareService::findZoneForDomain($rootDomain);
        if (!$knownZone) {
            CloudflareService::refreshZones();
        }
        self::ensureTlsCatchAllPolicy($caddyApi);

        $caddyConfig = [
            '@id' => $routeId,
            'match' => [
                ['host' => [$domain, "www.{$domain}"]]
            ],
            'handle' => [
                [
                    'handler' => 'subroute',
                    'routes' => [
                        // Set root for all subroutes
                        [
                            'handle' => [
                                ['handler' => 'vars', 'root' => $documentRoot]
                            ]
                        ],
                        // Static file headers
                        [
                            'match' => [['path' => ['*.jpg', '*.jpeg', '*.png', '*.gif', '*.webp', '*.svg', '*.ico', '*.css', '*.js', '*.woff', '*.woff2']]],
                            'handle' => [['handler' => 'headers', 'response' => ['set' => ['Cache-Control' => ['public, max-age=2592000']]]]]
                        ],
                        // try_files: rewrite to existing file or index.php (Laravel/WordPress friendly)
                        [
                            'match' => [['file' => ['try_files' => ['{http.request.uri.path}', '{http.request.uri.path}/index.php', '/index.php']]]],
                            'handle' => [['handler' => 'rewrite', 'uri' => '{http.matchers.file.relative}']]
                        ],
                        // PHP handler via FPM
                        [
                            'match' => [['path' => ['*.php']]],
                            'handle' => [
                                [
                                    'handler' => 'reverse_proxy',
                                    'transport' => [
                                        'protocol' => 'fastcgi',
                                        'root' => $documentRoot,
                                        'split_path' => ['.php'],
                                    ],
                                    'upstreams' => [
                                        ['dial' => "unix/{$socketPath}"]
                                    ]
                                ]
                            ]
                        ],
                        // File server
                        [
                            'handle' => [
                                ['handler' => 'file_server', 'root' => $documentRoot, 'hide' => ['.git', '.env', '.htaccess']]
                            ]
                        ]
                    ]
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
     * Add a Caddy route matching multiple domains (main + aliases).
     * Deletes existing route first, then creates with all domains.
     */
    public static function rebuildCaddyRouteWithAliases(string $mainDomain, array $aliasDomains, string $documentRoot, string $username, string $phpVersion = '8.3'): ?string
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];
        $routeId = "hosting-{$username}";

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

        $socketPath = "/run/php/php{$phpVersion}-fpm-{$username}.sock";
        $caddyConfig = [
            '@id' => $routeId,
            'match' => [['host' => $hosts]],
            'handle' => [
                [
                    'handler' => 'subroute',
                    'routes' => [
                        [
                            'handle' => [['handler' => 'vars', 'root' => $documentRoot]]
                        ],
                        [
                            'match' => [['path' => ['*.jpg', '*.jpeg', '*.png', '*.gif', '*.webp', '*.svg', '*.ico', '*.css', '*.js', '*.woff', '*.woff2']]],
                            'handle' => [['handler' => 'headers', 'response' => ['set' => ['Cache-Control' => ['public, max-age=2592000']]]]]
                        ],
                        [
                            'match' => [['file' => ['try_files' => ['{http.request.uri.path}', '{http.request.uri.path}/index.php', '/index.php']]]],
                            'handle' => [['handler' => 'rewrite', 'uri' => '{http.matchers.file.relative}']]
                        ],
                        [
                            'match' => [['path' => ['*.php']]],
                            'handle' => [
                                [
                                    'handler' => 'reverse_proxy',
                                    'transport' => ['protocol' => 'fastcgi', 'root' => $documentRoot, 'split_path' => ['.php']],
                                    'upstreams' => [['dial' => "unix/{$socketPath}"]]
                                ]
                            ]
                        ],
                        [
                            'handle' => [['handler' => 'file_server', 'root' => $documentRoot, 'hide' => ['.git', '.env', '.htaccess']]]
                        ]
                    ]
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
     * Add a Caddy redirect route (301/302) for a domain pointing to another domain.
     */
    public static function addCaddyRedirectRoute(string $fromDomain, string $toDomain, int $code = 301, bool $preservePath = true): ?string
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];
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
        $routeId = "hosting-{$username}";

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
    public static function suspendAccount(string $username, string $fpmSocket, string $domain = ''): void
    {
        // Lock the user
        shell_exec(sprintf('usermod -L %s 2>&1', escapeshellarg($username)));

        // Rename FPM pool to disable it
        $config = require PANEL_ROOT . '/config/panel.php';
        $phpVersion = $config['fpm']['php_version'];
        $poolFile = "/etc/php/{$phpVersion}/fpm/pool.d/{$username}.conf";
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
        shell_exec(sprintf('mv %s.disabled %s 2>&1', escapeshellarg($poolFile), escapeshellarg($poolFile)));
        shell_exec(sprintf('systemctl reload php%s-fpm 2>&1', self::safePhpVersion($phpVer)));

        // Restore normal Caddy route
        if ($domain && $documentRoot) {
            $caddyApi = $config['caddy']['api_url'];
            $routeId = "hosting-{$username}";
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
    private static function setCaddyMaintenanceRoute(string $username, string $domain, string $caddyApi): void
    {
        $routeId = "hosting-{$username}";

        // Delete existing route
        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($ch);
        curl_close($ch);

        $html = self::getMaintenanceHtml($domain);

        // Create maintenance route (static response, no PHP needed)
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

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($maintenanceRoute),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private static function getMaintenanceHtml(string $domain): string
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
        $routeId = "hosting-{$username}";
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
        $oldRouteId = "hosting-{$oldUsername}";
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
            'caddy_route_id' => $newRouteId ?? "hosting-{$newUsername}",
            'warnings' => $errors,
        ];
    }

    /**
     * Get disk usage of a directory in MB
     */
    public static function getDiskUsage(string $path): int
    {
        if (!is_dir($path)) return 0;
        $output = shell_exec(sprintf('du -sm %s 2>/dev/null | cut -f1', escapeshellarg($path)));
        return (int) trim($output ?: '0');
    }
}
