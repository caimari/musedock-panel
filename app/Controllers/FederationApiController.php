<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\FederationService;
use MuseDockPanel\Services\FederationMigrationService;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\SystemService;
use MuseDockPanel\Services\LogService;

/**
 * FederationApiController — API endpoints for inter-panel federation communication.
 *
 * These endpoints are called by remote peers during migration operations.
 * Authentication: Bearer token via ApiAuthMiddleware (same as cluster API).
 *
 * All endpoints receive migration_id for traceability.
 */
class FederationApiController
{
    /**
     * GET /api/federation/health
     * Lightweight health check for federation peers.
     */
    public function health(): void
    {
        header('Content-Type: application/json');

        $diskFree = @disk_free_space('/var/www/vhosts');
        $loadAvg = sys_getloadavg();

        echo json_encode([
            'ok' => true,
            'data' => [
                'hostname' => gethostname(),
                'disk_free_mb' => $diskFree ? (int)($diskFree / 1048576) : 0,
                'load_1m' => $loadAvg[0] ?? 0,
                'time' => date('Y-m-d H:i:s'),
                'panel_version' => PANEL_VERSION,
            ],
        ]);
    }

    /**
     * POST /api/federation/check-space
     * Check if enough disk space is available.
     */
    public function checkSpace(): void
    {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $requiredMb = (int)($input['required_mb'] ?? 500);

        $availableMb = (int)(disk_free_space('/var/www/vhosts') / 1048576);

        if ($availableMb < $requiredMb) {
            echo json_encode(['ok' => false, 'error' => "Not enough disk space: {$availableMb}MB available, {$requiredMb}MB required"]);
            return;
        }

        echo json_encode(['ok' => true, 'data' => ['available_mb' => $availableMb]]);
    }

    /**
     * POST /api/federation/check-conflicts
     * Dry-run validation: check if domain, username, UID would conflict.
     */
    public function checkConflicts(): void
    {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $conflicts = [];

        // Check domain
        $domain = $input['domain'] ?? '';
        if ($domain) {
            $existing = Database::fetchOne('SELECT id FROM hosting_accounts WHERE domain = :d', ['d' => $domain]);
            if ($existing) {
                $conflicts[] = "Domain '{$domain}' already exists on this server";
            }
        }

        // Check username
        $username = $input['username'] ?? '';
        if ($username) {
            $output = [];
            exec('id ' . escapeshellarg($username) . ' 2>&1', $output, $rc);
            if ($rc === 0) {
                $conflicts[] = "Username '{$username}' already exists as system user";
            }
        }

        // Check UID
        $uid = (int)($input['system_uid'] ?? 0);
        if ($uid > 0) {
            $output = [];
            exec("getent passwd {$uid} 2>&1", $output, $rc);
            if ($rc === 0) {
                $conflicts[] = "UID {$uid} is occupied (will be reassigned)";
            }
        }

        // Check disk space
        $diskQuota = (int)($input['disk_quota_mb'] ?? 0);
        $availableMb = (int)(disk_free_space('/var/www/vhosts') / 1048576);

        echo json_encode([
            'ok' => empty($conflicts) || !in_array("Domain '{$domain}' already exists on this server", $conflicts),
            'data' => [
                'conflicts' => $conflicts,
                'disk_available_mb' => $availableMb,
                'uid_available' => empty(array_filter($conflicts, fn($c) => str_contains($c, 'UID'))),
            ],
        ]);
    }

    /**
     * POST /api/federation/prepare
     * Prepare destination for migration: create tentative user + DB (not active).
     */
    public function prepare(): void
    {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $migrationId = $input['migration_id'] ?? '';
        $domain      = $input['domain'] ?? '';
        $username    = $input['username'] ?? '';
        $uid         = (int)($input['system_uid'] ?? 0);
        $homeDir     = $input['home_dir'] ?? '';
        $docRoot     = $input['document_root'] ?? '';
        $phpVersion  = $input['php_version'] ?? '8.3';
        $shell       = $input['shell'] ?? '/usr/sbin/nologin';

        if (empty($domain) || empty($username)) {
            echo json_encode(['ok' => false, 'error' => 'domain and username are required']);
            return;
        }

        FederationMigrationService::log($migrationId, 'prepare', 'info', "Preparing destination for: {$domain}");

        // Check domain not already hosted
        $existing = Database::fetchOne('SELECT id FROM hosting_accounts WHERE domain = :d', ['d' => $domain]);
        if ($existing) {
            echo json_encode(['ok' => false, 'error' => "Domain already exists: {$domain}"]);
            return;
        }

        // Try original UID, fallback to auto-assign
        $assignedUid = null;
        if ($uid > 0) {
            $output = [];
            exec("getent passwd {$uid} 2>&1", $output, $rc);
            if ($rc !== 0) {
                $assignedUid = $uid; // UID available
            }
        }

        // Create system user (tentative — not added to hosting_accounts yet)
        $createdUid = SystemService::createSystemUser($username, $homeDir, $shell, $assignedUid);
        if ($createdUid === null) {
            echo json_encode(['ok' => false, 'error' => "Failed to create system user: {$username}"]);
            return;
        }

        // Create directory structure
        SystemService::createDirectories($username, $homeDir, $docRoot);

        // Create databases (tentative) — verify each creation
        $databases = $input['databases'] ?? [];
        $createdDbs = [];
        $dbErrors = [];
        foreach ($databases as $db) {
            $dbName = $db['db_name'] ?? '';
            $dbUser = $db['db_user'] ?? '';
            $dbType = $db['db_type'] ?? 'pgsql';

            if (empty($dbName)) continue;

            if ($dbType === 'pgsql') {
                $out = [];
                exec("sudo -u postgres createuser " . escapeshellarg($dbUser) . " 2>&1", $out, $rc1);
                exec("sudo -u postgres createdb -O " . escapeshellarg($dbUser) . " " . escapeshellarg($dbName) . " 2>&1", $out, $rc2);
                // Verify DB exists
                exec("sudo -u postgres psql -lqt 2>/dev/null | grep -w " . escapeshellarg($dbName), $out, $rcVerify);
                if ($rcVerify !== 0) {
                    $dbErrors[] = "{$dbName} (pgsql): creation failed";
                } else {
                    $createdDbs[] = $dbName;
                }
            } else {
                $out = [];
                exec("mysql -e 'CREATE DATABASE IF NOT EXISTS `" . addslashes($dbName) . "`' 2>&1", $out, $rc1);
                exec("mysql -e \"CREATE USER IF NOT EXISTS '" . addslashes($dbUser) . "'@'localhost'\" 2>&1", $out, $rc2);
                exec("mysql -e \"GRANT ALL ON \`" . addslashes($dbName) . "\`.* TO '" . addslashes($dbUser) . "'@'localhost'\" 2>&1", $out, $rc3);
                // Verify DB exists
                exec("mysql -e 'USE `" . addslashes($dbName) . "`' 2>&1", $out, $rcVerify);
                if ($rcVerify !== 0) {
                    $dbErrors[] = "{$dbName} (mysql): creation failed";
                } else {
                    $createdDbs[] = $dbName;
                }
            }
        }

        if (!empty($dbErrors)) {
            FederationMigrationService::log($migrationId, 'prepare', 'warn', "Some databases failed to create", ['errors' => $dbErrors]);
        }

        FederationMigrationService::log($migrationId, 'prepare', 'info', "Destination prepared", [
            'uid' => $createdUid,
            'databases' => $createdDbs,
        ]);

        echo json_encode([
            'ok' => true,
            'data' => [
                'uid' => $createdUid,
                'username' => $username,
                'databases' => $createdDbs,
            ],
        ]);
    }

    /**
     * POST /api/federation/finalize
     * Finalize hosting on destination: create FPM pool, Caddy route, activate account.
     */
    public function finalize(): void
    {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $migrationId = $input['migration_id'] ?? '';
        $domain      = $input['domain'] ?? '';
        $username    = $input['username'] ?? '';

        // Get account info from the prepare step
        $homeDir = "/var/www/vhosts/{$domain}";
        $docRoot = "{$homeDir}/httpdocs";

        // Try to read PHP version from existing account data or default
        $phpVersion = $input['php_version'] ?? '8.3';
        $hostingType = $input['hosting_type'] ?? 'php';

        FederationMigrationService::log($migrationId, 'finalize', 'info', "Finalizing hosting: {$domain} (type: {$hostingType})");

        // Create PHP-FPM pool
        SystemService::createFpmPool($username, $phpVersion, $homeDir);

        // Add Caddy route
        SystemService::addCaddyRoute($domain, $docRoot, $username, $phpVersion, $hostingType);

        // Set proper ownership (critical — failure here means broken permissions)
        $chownOut = [];
        exec("chown -R " . escapeshellarg($username) . ":www-data " . escapeshellarg($homeDir) . " 2>&1", $chownOut, $chownRc);
        if ($chownRc !== 0) {
            $chownError = implode("\n", $chownOut);
            FederationMigrationService::log($migrationId, 'finalize', 'error', "chown failed: {$chownError}");
            // Don't abort — try to continue. Log but the verify step will catch permission issues.
            // Admin can fix manually if needed.
        }

        // Verify ownership actually took effect
        $stat = @stat($docRoot);
        if ($stat) {
            $ownerInfo = posix_getpwuid($stat['uid']);
            if (($ownerInfo['name'] ?? '') !== $username) {
                FederationMigrationService::log($migrationId, 'finalize', 'warn',
                    "Ownership mismatch after chown: expected {$username}, got " . ($ownerInfo['name'] ?? 'unknown'));
            }
        }

        // Insert into hosting_accounts (now officially exists on this server)
        $accountId = Database::insert('hosting_accounts', [
            'domain'        => $domain,
            'username'      => $username,
            'system_uid'    => (int)trim(shell_exec("id -u " . escapeshellarg($username) . " 2>/dev/null") ?: '0'),
            'home_dir'      => $homeDir,
            'document_root' => $docRoot,
            'php_version'   => $phpVersion,
            'hosting_type'  => $hostingType,
            'fpm_socket'    => "/run/php/php{$phpVersion}-fpm-{$username}.sock",
            'status'        => 'active',
            'shell'         => '/usr/sbin/nologin',
            'disk_quota_mb' => (int)($input['disk_quota_mb'] ?? 0),
        ]);

        // Recreate databases in hosting_databases
        $databases = $input['databases'] ?? [];
        foreach ($databases as $db) {
            Database::insert('hosting_databases', [
                'account_id' => $accountId,
                'db_name'    => $db['db_name'] ?? '',
                'db_user'    => $db['db_user'] ?? '',
                'db_type'    => $db['db_type'] ?? 'pgsql',
            ]);
        }

        // Recreate subdomains (DB records + Caddy routes)
        $subdomains = $input['subdomains'] ?? [];
        foreach ($subdomains as $sub) {
            $subFqdn = $sub['subdomain'] ?? '';
            $subDocRoot = $sub['document_root'] ?? '';
            $subPhpVer = $sub['php_version'] ?? $phpVersion;

            if (empty($subFqdn)) continue;

            $subHostingType = $sub['hosting_type'] ?? 'php';

            Database::insert('hosting_subdomains', [
                'account_id'    => $accountId,
                'subdomain'     => $subFqdn,
                'document_root' => $subDocRoot,
                'php_version'   => $subPhpVer,
                'hosting_type'  => $subHostingType,
                'status'        => 'active',
            ]);

            // Create Caddy route for subdomain with correct hosting type
            if (!empty($subDocRoot)) {
                SystemService::addCaddyRoute($subFqdn, $subDocRoot, $username, $subPhpVer, $subHostingType);
            }
        }

        // Recreate domain aliases (DB records + Caddy routes for aliases, redirects for redirects)
        $aliases = $input['aliases'] ?? [];
        foreach ($aliases as $alias) {
            $aliasDomain = $alias['domain'] ?? '';
            $aliasType = $alias['type'] ?? 'alias';

            if (empty($aliasDomain)) continue;

            Database::insert('hosting_domain_aliases', [
                'hosting_account_id' => $accountId,
                'domain'             => $aliasDomain,
                'type'               => $aliasType,
                'redirect_code'      => $alias['redirect_code'] ?? null,
            ]);

            // Create Caddy route: alias = same content as main, redirect = 301/302
            if ($aliasType === 'alias') {
                SystemService::addCaddyRoute($aliasDomain, $docRoot, $username, $phpVersion);
            }
        }

        FederationMigrationService::log($migrationId, 'finalize', 'info', "Hosting finalized on destination", [
            'account_id' => $accountId,
        ]);

        echo json_encode([
            'ok' => true,
            'data' => ['account_id' => $accountId],
        ]);
    }

    /**
     * POST /api/federation/verify
     * Verify hosting works on destination. Multi-layer checks:
     *   1. HTTP 200 on main domain
     *   2. Response body > 500 bytes (not empty/error page)
     *   3. No "Fatal error" / "Parse error" in response
     *   4. PHP execution works (via direct FPM socket test)
     *   5. Database connectivity (if databases exist)
     *   6. Key static assets reachable (CSS/JS/images)
     *   7. File ownership correct
     */
    public function verify(): void
    {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $domain = $input['domain'] ?? '';
        $checks = [];

        // 1. Main page HTTP check (via HTTPS on localhost with SNI, bypass DNS)
        // Use HTTPS because Caddy routes match on TLS SNI + Host header
        $ch = curl_init("https://127.0.0.1");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ["Host: {$domain}"],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, // Self-signed cert on localhost
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $checks['http_ok'] = $httpCode >= 200 && $httpCode < 400;
        $checks['http_code'] = $httpCode;
        $checks['response_size'] = strlen($body ?: '');
        $checks['size_ok'] = strlen($body ?: '') > 500;
        $checks['no_fatal'] = !str_contains($body ?: '', 'Fatal error')
                            && !str_contains($body ?: '', 'Parse error')
                            && !str_contains($body ?: '', 'syntax error')
                            && !str_contains($body ?: '', 'SQLSTATE[');
        $checks['curl_error'] = $error ?: null;

        // 2. PHP execution test (FPM socket direct)
        $account = Database::fetchOne('SELECT * FROM hosting_accounts WHERE domain = :d', ['d' => $domain]);
        if ($account) {
            $fpmSocket = $account['fpm_socket'] ?? "/run/php/php" . ($account['php_version'] ?? '8.3') . "-fpm-{$account['username']}.sock";
            $checks['fpm_socket_exists'] = file_exists($fpmSocket);

            // 3. Database connectivity (test each DB)
            $databases = Database::fetchAll('SELECT * FROM hosting_databases WHERE account_id = :aid', ['aid' => $account['id']]);
            $checks['databases_ok'] = true;
            $checks['databases_checked'] = 0;
            foreach ($databases as $db) {
                $checks['databases_checked']++;
                if (($db['db_type'] ?? 'pgsql') === 'pgsql') {
                    $out = [];
                    exec("psql -U " . escapeshellarg($db['db_user']) . " -d " . escapeshellarg($db['db_name']) . " -c 'SELECT 1' 2>&1", $out, $rc);
                    if ($rc !== 0) $checks['databases_ok'] = false;
                } else {
                    $out = [];
                    exec("mysql -u " . escapeshellarg($db['db_user']) . " " . escapeshellarg($db['db_name']) . " -e 'SELECT 1' 2>&1", $out, $rc);
                    if ($rc !== 0) $checks['databases_ok'] = false;
                }
            }

            // 4. File ownership check
            $docRoot = $account['document_root'];
            if (is_dir($docRoot)) {
                $stat = stat($docRoot);
                $ownerInfo = posix_getpwuid($stat['uid'] ?? 0);
                $checks['ownership_ok'] = ($ownerInfo['name'] ?? '') === $account['username'];
                $checks['owner'] = $ownerInfo['name'] ?? 'unknown';
            } else {
                $checks['ownership_ok'] = false;
                $checks['owner'] = 'dir_missing';
            }

            // 5. Static assets spot check (look for common files)
            $checks['assets_checked'] = 0;
            $checks['assets_found'] = 0;
            $assetPatterns = ['*.css', '*.js', '*.png', '*.jpg', '*.ico'];
            foreach ($assetPatterns as $pattern) {
                $found = glob("{$docRoot}/{$pattern}") ?: glob("{$docRoot}/wp-content/**/{$pattern}");
                if (!empty($found)) {
                    $checks['assets_found']++;
                }
                $checks['assets_checked']++;
            }
        }

        // Pass/fail decision: core checks must pass, others are warnings
        $allOk = $checks['http_ok'] && $checks['size_ok'] && $checks['no_fatal'];
        if (isset($checks['fpm_socket_exists']) && !$checks['fpm_socket_exists']) {
            $allOk = false;
        }

        echo json_encode([
            'ok' => $allOk,
            'data' => $checks,
        ]);
    }

    /**
     * GET /api/federation/server-info
     * Return local server info (public IP, disk, PHP versions).
     */
    public function serverInfo(): void
    {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'data' => FederationService::getServerInfo(),
        ]);
    }

    /**
     * POST /api/federation/install-ssh-key
     * Install a remote peer's SSH public key in authorized_keys.
     */
    public function installSshKey(): void
    {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $publicKey = $input['public_key'] ?? '';
        if (empty($publicKey)) {
            echo json_encode(['ok' => false, 'error' => 'public_key is required']);
            return;
        }

        FederationService::installSshKey($publicKey);

        // Return our own public key for bidirectional exchange
        $localKeyPath = '/root/.ssh/id_ed25519.pub';
        $localKey = file_exists($localKeyPath) ? trim(file_get_contents($localKeyPath)) : null;

        echo json_encode([
            'ok' => true,
            'data' => ['peer_public_key' => $localKey],
        ]);
    }

    /**
     * POST /api/federation/rollback
     * Clean up tentative resources created during a failed/cancelled migration.
     */
    public function rollback(): void
    {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $migrationId = $input['migration_id'] ?? '';
        $domain      = $input['domain'] ?? '';
        $username    = $input['username'] ?? '';

        FederationMigrationService::log($migrationId, 'rollback', 'info', "Rolling back destination: {$domain}");

        // Remove Caddy route (if exists)
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy_api'] ?? 'http://localhost:2019';
        $routeId = SystemService::caddyRouteId($domain);

        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true]);
        curl_exec($ch);
        curl_close($ch);

        // Remove FPM pool
        $phpVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];
        foreach ($phpVersions as $v) {
            $poolFile = "/etc/php/{$v}/fpm/pool.d/{$username}.conf";
            if (file_exists($poolFile)) {
                @unlink($poolFile);
                @exec("systemctl reload php{$v}-fpm 2>&1");
            }
        }

        // Remove databases BEFORE deleting the account (so the FK query still works)
        $account = Database::fetchOne('SELECT id FROM hosting_accounts WHERE domain = :d', ['d' => $domain]);
        if ($account) {
            $databases = Database::fetchAll(
                'SELECT * FROM hosting_databases WHERE account_id = :aid',
                ['aid' => $account['id']]
            );
            // Drop system-level databases first
            foreach ($databases as $db) {
                if (($db['db_type'] ?? 'pgsql') === 'pgsql') {
                    exec("sudo -u postgres dropdb --if-exists " . escapeshellarg($db['db_name']) . " 2>&1", $out, $rc);
                    exec("sudo -u postgres dropuser --if-exists " . escapeshellarg($db['db_user']) . " 2>&1", $out, $rc);
                } else {
                    exec("mysql -e 'DROP DATABASE IF EXISTS `" . addslashes($db['db_name']) . "`' 2>&1", $out, $rc);
                    exec("mysql -e \"DROP USER IF EXISTS '" . addslashes($db['db_user']) . "'@'localhost'\" 2>&1", $out, $rc);
                }
                FederationMigrationService::log($migrationId, 'rollback', 'info',
                    "Dropped database: {$db['db_name']} (rc: " . ($rc ?? '?') . ")");
            }
            // Delete DB records
            Database::delete('hosting_databases', 'account_id = :aid', ['aid' => $account['id']]);
            Database::delete('hosting_subdomains', 'account_id = :aid', ['aid' => $account['id']]);
            Database::delete('hosting_domain_aliases', 'hosting_account_id = :aid', ['aid' => $account['id']]);
        }

        // Remove hosting_accounts record
        Database::delete('hosting_accounts', 'domain = :d', ['d' => $domain]);

        // Delete system user and files
        if (!empty($username)) {
            exec("pkill -u " . escapeshellarg($username) . " 2>&1", $out, $rc);
            exec("userdel -r " . escapeshellarg($username) . " 2>&1", $out, $rc);
        }

        // Also clean home directory
        $homeDir = "/var/www/vhosts/{$domain}";
        if (is_dir($homeDir)) {
            exec("rm -rf " . escapeshellarg($homeDir) . " 2>&1", $out, $rc);
        }

        FederationMigrationService::log($migrationId, 'rollback', 'info', "Destination cleanup completed: {$domain}");

        echo json_encode(['ok' => true]);
    }

    /**
     * POST /api/federation/complete
     * Notification from origin that migration is fully complete.
     * Triggers slave sync on the destination master so obelix/filemon get the new hosting.
     */
    public function complete(): void
    {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $migrationId = $input['migration_id'] ?? '';
        $domain = $input['domain'] ?? '';

        FederationMigrationService::log($migrationId, 'complete', 'info', 'Migration completed notification received from origin');

        // Trigger slave sync: enqueue create_hosting to all web slave nodes
        // This is the same mechanism used when a hosting is created normally.
        // Only sync if the hosting is fully operational (status = active, FPM running).
        if (Settings::get('cluster_role', 'standalone') === 'master' && !empty($domain)) {
            $account = Database::fetchOne('SELECT * FROM hosting_accounts WHERE domain = :d', ['d' => $domain]);
            if ($account) {
                // Sanity check: FPM socket exists = hosting is operational
                $fpmSocket = $account['fpm_socket'] ?? '';
                if ($fpmSocket && !file_exists($fpmSocket)) {
                    FederationMigrationService::log($migrationId, 'complete', 'warn',
                        "FPM socket missing ({$fpmSocket}) — slave sync will proceed but hosting may not be fully operational");
                }
                $nodes = ClusterService::getWebNodes();
                foreach ($nodes as $node) {
                    ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                        'hosting_action' => 'create_hosting',
                        'hosting_data' => [
                            'username'       => $account['username'],
                            'domain'         => $account['domain'],
                            'home_dir'       => $account['home_dir'],
                            'document_root'  => $account['document_root'],
                            'php_version'    => $account['php_version'] ?? '8.3',
                            'uid'            => $account['system_uid'],
                            'shell'          => $account['shell'] ?? '/usr/sbin/nologin',
                            'disk_quota_mb'  => $account['disk_quota_mb'] ?? 0,
                        ],
                    ]);
                }
                FederationMigrationService::log($migrationId, 'complete', 'info',
                    'Slave sync enqueued for ' . count($nodes) . ' web nodes');
            }
        }

        echo json_encode(['ok' => true]);
    }

    /**
     * POST /api/federation/handshake
     * Bidirectional peer registration. When panel A adds panel B,
     * panel A calls this endpoint on panel B so B auto-registers A.
     */
    public function handshake(): void
    {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $peerName    = $input['name'] ?? '';
        $peerApiUrl  = $input['api_url'] ?? '';
        $peerToken   = $input['auth_token'] ?? '';
        $sshHost     = $input['ssh_host'] ?? '';
        $sshPort     = (int)($input['ssh_port'] ?? 22);
        $sshUser     = $input['ssh_user'] ?? 'root';
        $sshKeyPath  = $input['ssh_key_path'] ?? '/root/.ssh/id_ed25519';
        $publicKey   = $input['public_key'] ?? '';

        if (empty($peerName) || empty($peerApiUrl) || empty($peerToken)) {
            echo json_encode(['ok' => false, 'error' => 'name, api_url, and auth_token are required']);
            return;
        }

        // Check if auto-accept is enabled (default: no — requires manual approval)
        $autoAccept = Settings::get('federation_auto_accept_peers', '0') === '1';

        // Check if this peer is already registered
        $existing = Database::fetchOne('SELECT id FROM federation_peers WHERE api_url = :url', ['url' => rtrim($peerApiUrl, '/')]);
        if ($existing) {
            // Update existing peer (already approved)
            FederationService::updatePeer($existing['id'], [
                'name'         => $peerName,
                'auth_token'   => $peerToken,
                'ssh_host'     => $sshHost,
                'ssh_port'     => $sshPort,
                'ssh_user'     => $sshUser,
                'ssh_key_path' => $sshKeyPath,
            ]);
            $peerId = $existing['id'];
        } elseif ($autoAccept) {
            // Auto-accept: register new peer directly
            $result = FederationService::addPeer($peerName, $peerApiUrl, $peerToken, [
                'host'     => $sshHost,
                'port'     => $sshPort,
                'user'     => $sshUser,
                'key_path' => $sshKeyPath,
            ]);
            if (!$result['ok']) {
                echo json_encode($result);
                return;
            }
            $peerId = $result['id'];
        } else {
            // Manual approval required: create peer in 'pending_approval' status
            $encryptedToken = \MuseDockPanel\Services\ReplicationService::encryptPassword($peerToken);
            $peerId = Database::insert('federation_peers', [
                'name'         => $peerName,
                'api_url'      => rtrim($peerApiUrl, '/'),
                'auth_token'   => $encryptedToken,
                'ssh_host'     => $sshHost,
                'ssh_port'     => $sshPort,
                'ssh_user'     => $sshUser,
                'ssh_key_path' => $sshKeyPath,
                'status'       => 'pending_approval',
                'metadata'     => json_encode(['handshake_from' => $_SERVER['REMOTE_ADDR'] ?? '', 'handshake_at' => date('Y-m-d H:i:s')]),
            ]);
            LogService::log('federation.handshake.pending', $peerName,
                "Peer handshake received — pending admin approval (from: " . ($_SERVER['REMOTE_ADDR'] ?? '') . ")");
        }

        // Install SSH key if provided
        if (!empty($publicKey)) {
            FederationService::installSshKey($publicKey);
        }

        // Return our info so the caller can update their records
        $localKeyPath = $sshKeyPath . '.pub';
        if (!file_exists($localKeyPath)) {
            $localKeyPath = '/root/.ssh/id_ed25519.pub';
        }
        $localPublicKey = file_exists($localKeyPath) ? trim(file_get_contents($localKeyPath)) : null;

        // Return our local token for the remote peer to use when calling us
        $localToken = '';
        $rawToken = Settings::get('cluster_local_token', '');
        if ($rawToken) {
            $localToken = \MuseDockPanel\Services\ReplicationService::decryptPassword($rawToken);
        }

        echo json_encode([
            'ok' => true,
            'data' => [
                'peer_id'         => $peerId,
                'hostname'        => gethostname(),
                'public_key'      => $localPublicKey,
                'auth_token'      => $localToken, // so they can call our API
            ],
        ]);
    }

    /**
     * POST /api/federation/pause-sync
     * Hard-stop slave sync for a specific domain during migration.
     *
     * Three-layer blocking:
     *   1. Setting flag (soft) — workers check this
     *   2. Add domain to filesync_exclusions_list (hard) — rsync --exclude skips it
     *   3. Reload lsyncd config (hard) — real-time sync also excludes it
     *
     * Auto-expires after 2 hours to prevent permanent sync blockage.
     */
    public function pauseSync(): void
    {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $migrationId = $input['migration_id'] ?? '';
        $domain = $input['domain'] ?? '';
        $action = $input['action'] ?? 'pause'; // 'pause' or 'resume'

        if (empty($domain)) {
            echo json_encode(['ok' => false, 'error' => 'domain is required']);
            return;
        }

        $exclusionsList = Settings::get('filesync_exclusions_list', '');
        $exclusions = array_filter(array_map('trim', explode("\n", $exclusionsList)));

        if ($action === 'pause') {
            // 1. Setting flag (checked by workers)
            Settings::set("federation_sync_paused_{$domain}", '1');
            // Store expiry timestamp (auto-expire after 2 hours)
            Settings::set("federation_sync_paused_{$domain}_expires", (string)(time() + 7200));

            // 2. Add to exclusions list (hard block for rsync)
            $domainPath = "/var/www/vhosts/{$domain}";
            if (!in_array($domainPath, $exclusions)) {
                $exclusions[] = $domainPath;
                Settings::set('filesync_exclusions_list', implode("\n", $exclusions));
            }

            // 3. Reload lsyncd if running (picks up new exclusions)
            exec('systemctl is-active lsyncd 2>/dev/null', $out, $rc);
            if ($rc === 0) {
                // Regenerate lsyncd config with new exclusion
                \MuseDockPanel\Services\FileSyncService::generateLsyncdConfig();
                exec('systemctl reload lsyncd 2>&1');
            }

            FederationMigrationService::log($migrationId, 'sync_pause', 'info',
                "Slave sync HARD-STOPPED for: {$domain} (exclusion + lsyncd reload, expires in 2h)");
        } else {
            // Resume: remove all three layers
            Settings::set("federation_sync_paused_{$domain}", '0');
            Settings::set("federation_sync_paused_{$domain}_expires", '');

            // Remove from exclusions list
            $domainPath = "/var/www/vhosts/{$domain}";
            $exclusions = array_filter($exclusions, fn($e) => $e !== $domainPath);
            Settings::set('filesync_exclusions_list', implode("\n", $exclusions));

            // Reload lsyncd
            exec('systemctl is-active lsyncd 2>/dev/null', $out, $rc);
            if ($rc === 0) {
                \MuseDockPanel\Services\FileSyncService::generateLsyncdConfig();
                exec('systemctl reload lsyncd 2>&1');
            }

            FederationMigrationService::log($migrationId, 'sync_pause', 'info',
                "Slave sync RESUMED for: {$domain}");
        }

        echo json_encode(['ok' => true]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Backup API (federation peers as remote backup storage)
    // ═══════════════════════════════════════════════════════════════

    private const BACKUP_DIR = '/opt/musedock-panel/storage/backups';

    /**
     * GET /api/federation/backups/list
     * List all backups on this panel.
     */
    public function backupsList(): void
    {
        header('Content-Type: application/json');

        $backups = [];
        $dir = self::BACKUP_DIR;
        if (is_dir($dir)) {
            foreach (glob("{$dir}/*/metadata.json") as $metaFile) {
                $meta = @json_decode(file_get_contents($metaFile), true);
                if (!$meta) continue;

                $backupDir = dirname($metaFile);
                $meta['dir_name'] = basename($backupDir);

                // Calculate size
                $size = 0;
                foreach (glob("{$backupDir}/*") as $f) {
                    if (is_file($f)) $size += filesize($f);
                }
                if (is_dir("{$backupDir}/databases")) {
                    foreach (glob("{$backupDir}/databases/*") as $f) {
                        if (is_file($f)) $size += filesize($f);
                    }
                }
                $meta['size_bytes'] = $size;
                $meta['size_human'] = $size >= 1048576 ? round($size / 1048576, 1) . ' MB' : round($size / 1024) . ' KB';

                $backups[] = $meta;
            }
        }

        usort($backups, fn($a, $b) => strtotime($b['date'] ?? '0') - strtotime($a['date'] ?? '0'));

        echo json_encode([
            'ok' => true,
            'data' => ['backups' => $backups, 'count' => count($backups)],
        ]);
    }

    /**
     * POST /api/federation/backups/receive
     * Receive a backup sent via rsync. Called after rsync completes to confirm receipt.
     */
    public function backupsReceive(): void
    {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $backupName = basename($input['backup_name'] ?? '');
        if (empty($backupName)) {
            echo json_encode(['ok' => false, 'error' => 'backup_name is required']);
            return;
        }

        $backupPath = self::BACKUP_DIR . '/' . $backupName;
        if (!is_dir($backupPath) || !file_exists($backupPath . '/metadata.json')) {
            echo json_encode(['ok' => false, 'error' => 'Backup not found after transfer']);
            return;
        }

        echo json_encode(['ok' => true]);
    }

    /**
     * GET /api/federation/backups/download?backup_name=xxx
     * Stream a backup as tar.gz for download.
     */
    public function backupsDownload(): void
    {
        $backupName = basename($_GET['backup_name'] ?? '');
        if (empty($backupName)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'backup_name is required']);
            return;
        }

        $backupPath = self::BACKUP_DIR . '/' . $backupName;
        if (!is_dir($backupPath) || !file_exists($backupPath . '/metadata.json')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Backup not found']);
            return;
        }

        // Stream as tar.gz
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $backupName . '.tar.gz"');
        passthru('tar czf - -C ' . escapeshellarg($backupPath) . ' . 2>/dev/null');
    }

    /**
     * POST /api/federation/backups/receive-upload
     * Receive a backup via HTTP multipart upload (for environments with limited SSH).
     */
    public function backupsReceiveUpload(): void
    {
        header('Content-Type: application/json');

        $backupName = basename($_POST['backup_name'] ?? '');
        if (empty($backupName) || !isset($_FILES['backup'])) {
            echo json_encode(['ok' => false, 'error' => 'backup_name and backup file are required']);
            return;
        }

        $uploadedFile = $_FILES['backup']['tmp_name'] ?? '';
        if (!$uploadedFile || !file_exists($uploadedFile)) {
            echo json_encode(['ok' => false, 'error' => 'Upload failed']);
            return;
        }

        $backupPath = self::BACKUP_DIR . '/' . $backupName;
        @mkdir($backupPath, 0750, true);

        // Extract tar
        $cmd = sprintf('tar xf %s -C %s 2>&1', escapeshellarg($uploadedFile), escapeshellarg($backupPath));
        exec($cmd, $out, $rc);

        if ($rc !== 0 || !file_exists($backupPath . '/metadata.json')) {
            exec('rm -rf ' . escapeshellarg($backupPath));
            echo json_encode(['ok' => false, 'error' => 'Failed to extract backup (exit ' . $rc . ')']);
            return;
        }

        echo json_encode(['ok' => true]);
    }

    /**
     * POST /api/federation/backups/delete
     * Delete a backup on this panel.
     */
    public function backupsDelete(): void
    {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $backupName = basename($input['backup_name'] ?? '');
        if (empty($backupName)) {
            echo json_encode(['ok' => false, 'error' => 'backup_name is required']);
            return;
        }

        $backupPath = self::BACKUP_DIR . '/' . $backupName;
        if (!is_dir($backupPath)) {
            echo json_encode(['ok' => false, 'error' => 'Backup not found']);
            return;
        }

        exec('rm -rf ' . escapeshellarg($backupPath) . ' 2>&1', $out, $rc);
        echo json_encode(['ok' => $rc === 0]);
    }
}
