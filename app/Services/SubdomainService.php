<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;

/**
 * SubdomainService — Manages subdomains under a hosting account.
 *
 * Subdomains share the parent account's Linux user and PHP-FPM pool,
 * but have their own document_root directory and Caddy route.
 */
class SubdomainService
{
    /**
     * Get all subdomains for a hosting account.
     */
    public static function getAll(int $accountId): array
    {
        return Database::fetchAll(
            "SELECT * FROM hosting_subdomains WHERE account_id = :aid ORDER BY subdomain",
            ['aid' => $accountId]
        );
    }

    /**
     * Get a single subdomain by ID.
     */
    public static function getById(int $id): ?array
    {
        return Database::fetchOne("SELECT * FROM hosting_subdomains WHERE id = :id", ['id' => $id]);
    }

    /**
     * Get a single subdomain by domain name.
     */
    public static function getByDomain(string $subdomain): ?array
    {
        return Database::fetchOne("SELECT * FROM hosting_subdomains WHERE subdomain = :s", ['s' => $subdomain]);
    }

    /**
     * Validate a subdomain name before creation.
     *
     * @return string|null Error message, or null if valid
     */
    public static function validate(string $subdomain, int $accountId): ?string
    {
        $subdomain = strtolower(trim($subdomain));

        if (empty($subdomain)) {
            return 'El subdominio no puede estar vacío.';
        }

        // Must be a valid domain format
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/i', $subdomain)) {
            return 'Formato de subdominio no válido.';
        }

        // Must not exist as a hosting account
        $existsAccount = Database::fetchOne(
            "SELECT id FROM hosting_accounts WHERE domain = :d",
            ['d' => $subdomain]
        );
        if ($existsAccount) {
            return "'{$subdomain}' ya existe como cuenta de hosting.";
        }

        // Must not exist as alias/redirect
        $existsAlias = Database::fetchOne(
            "SELECT id FROM hosting_domain_aliases WHERE domain = :d",
            ['d' => $subdomain]
        );
        if ($existsAlias) {
            return "'{$subdomain}' ya existe como alias o redirección.";
        }

        // Must not exist as another subdomain
        $existsSub = Database::fetchOne(
            "SELECT id FROM hosting_subdomains WHERE subdomain = :s",
            ['s' => $subdomain]
        );
        if ($existsSub) {
            return "'{$subdomain}' ya existe como subdominio.";
        }

        return null;
    }

    /**
     * Create a subdomain with its own document_root and Caddy route.
     *
     * @param int    $accountId   Parent hosting account ID
     * @param string $subdomain   Full subdomain (e.g., blog.example.com)
     * @param string|null $customDocRoot  Custom document root (default: parent_home/{subdomain_folder}/)
     * @return array ['ok' => bool, 'error' => string, 'id' => int, 'document_root' => string]
     */
    public static function create(int $accountId, string $subdomain, ?string $customDocRoot = null): array
    {
        $subdomain = strtolower(trim($subdomain));

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $accountId]);
        if (!$account) {
            return ['ok' => false, 'error' => 'Cuenta de hosting no encontrada.'];
        }

        // Validate
        $error = self::validate($subdomain, $accountId);
        if ($error) {
            return ['ok' => false, 'error' => $error];
        }

        // Determine document root
        // Subdomain folder lives inside parent home: /var/www/vhosts/domain.com/sub.domain.com/
        // No httpdocs — files go directly in the subdomain folder
        $homeDir = rtrim($account['home_dir'], '/');
        $subFolder = $subdomain;
        $documentRoot = $customDocRoot ?: "{$homeDir}/{$subFolder}";

        // Create directory structure
        $dirs = [$documentRoot, "{$homeDir}/{$subFolder}/logs", "{$homeDir}/{$subFolder}/tmp"];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        // Create default index.html
        $indexContent = "<!DOCTYPE html><html><head><title>{$subdomain}</title></head><body><h1>{$subdomain}</h1><p>Subdomain ready.</p></body></html>";
        if (!file_exists("{$documentRoot}/index.html")) {
            @file_put_contents("{$documentRoot}/index.html", $indexContent);
        }

        // Set ownership to parent account user
        $username = $account['username'];
        shell_exec(sprintf('chown -R %s:www-data %s 2>&1', escapeshellarg($username), escapeshellarg($documentRoot)));

        // Create Caddy route (reuses parent's FPM pool)
        $caddyRouteId = SystemService::addCaddyRoute($subdomain, $documentRoot, $username, $account['php_version'] ?? '8.3');

        // Insert DB record
        $id = Database::insert('hosting_subdomains', [
            'account_id'    => $accountId,
            'subdomain'     => $subdomain,
            'document_root' => $documentRoot,
            'caddy_route_id' => $caddyRouteId,
            'status'        => 'active',
        ]);

        LogService::log('subdomain.create', $subdomain, "Subdomain created under {$account['domain']}");

        return [
            'ok'            => true,
            'id'            => $id,
            'document_root' => $documentRoot,
            'caddy_route_id' => $caddyRouteId,
        ];
    }

    /**
     * Delete a subdomain: remove Caddy route, DB record.
     * Does NOT delete files (safety: user might want to keep them).
     *
     * @param int  $id           Subdomain ID
     * @param bool $deleteFiles  Whether to delete the document root directory
     * @return array ['ok' => bool, 'error' => string]
     */
    public static function delete(int $id, bool $deleteFiles = false): array
    {
        $sub = self::getById($id);
        if (!$sub) {
            return ['ok' => false, 'error' => 'Subdominio no encontrado.'];
        }

        // Remove Caddy route
        if (!empty($sub['caddy_route_id'])) {
            $config = require PANEL_ROOT . '/config/panel.php';
            $caddyApi = $config['caddy']['api_url'];
            $ch = curl_init("{$caddyApi}/id/{$sub['caddy_route_id']}");
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($ch);
            curl_close($ch);
        }

        // Optionally delete files
        if ($deleteFiles && !empty($sub['document_root'])) {
            $docRoot = rtrim($sub['document_root'], '/');
            // Safety: only delete if within a vhosts directory and not the vhost root itself
            if (str_contains($docRoot, '/var/www/vhosts/') && is_dir($docRoot) && substr_count($docRoot, '/') > 4) {
                shell_exec(sprintf('rm -rf %s 2>&1', escapeshellarg($docRoot)));
            }
        }

        Database::delete('hosting_subdomains', 'id = :id', ['id' => $id]);

        LogService::log('subdomain.delete', $sub['subdomain'], "Subdomain deleted" . ($deleteFiles ? ' (files removed)' : ''));

        return ['ok' => true];
    }

    /**
     * Get hosting accounts that are subdomains of a given parent domain.
     * These are candidates for adoption as subdomains.
     */
    public static function getAdoptableAccounts(string $parentDomain): array
    {
        // Find accounts whose domain ends with .parentDomain (e.g., api.example.com for example.com)
        $pattern = '%.' . $parentDomain;
        return Database::fetchAll(
            "SELECT id, domain, username, home_dir, document_root, status, php_version
             FROM hosting_accounts WHERE domain LIKE :p ORDER BY domain",
            ['p' => $pattern]
        );
    }

    /**
     * Adopt an independent hosting account as a subdomain of a parent account.
     *
     * This:
     * 1. Moves files from the child's document_root to parent_home/subdomain/
     * 2. Removes the child's Caddy route, FPM pool, and Linux user
     * 3. Creates a subdomain record under the parent
     * 4. Creates a new Caddy route using the parent's FPM pool
     * 5. Reassigns file ownership to the parent user
     * 6. Removes the child hosting_account record
     *
     * @return array ['ok' => bool, 'error' => string]
     */
    public static function adopt(int $parentAccountId, int $childAccountId): array
    {
        $parent = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $parentAccountId]);
        $child = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $childAccountId]);

        if (!$parent || !$child) {
            return ['ok' => false, 'error' => 'Cuenta padre o hijo no encontrada.'];
        }

        // Verify child is actually a subdomain of parent
        $parentDomain = $parent['domain'];
        $childDomain = $child['domain'];
        if (!str_ends_with($childDomain, '.' . $parentDomain)) {
            return ['ok' => false, 'error' => "'{$childDomain}' no es un subdominio de '{$parentDomain}'."];
        }

        // Check it doesn't already exist as subdomain
        $existing = Database::fetchOne(
            "SELECT id FROM hosting_subdomains WHERE subdomain = :s",
            ['s' => $childDomain]
        );
        if ($existing) {
            return ['ok' => false, 'error' => "'{$childDomain}' ya existe como subdominio."];
        }

        $parentHome = rtrim($parent['home_dir'], '/');
        $parentUser = $parent['username'];
        $childUser = $child['username'];
        $childHome = rtrim($child['home_dir'], '/');
        $childDocRoot = $child['document_root'];

        // New location: /parent_home/subdomain.parent.com/
        $newSubDir = "{$parentHome}/{$childDomain}";
        $newDocRoot = $newSubDir;

        // 1. Create new directory structure
        @mkdir($newDocRoot, 0755, true);
        @mkdir("{$newSubDir}/logs", 0755, true);
        @mkdir("{$newSubDir}/tmp", 0755, true);

        // 2. Move files from child document root to new location
        // Use rsync to preserve permissions and handle overlapping files
        if (is_dir($childDocRoot) && $childDocRoot !== $newDocRoot) {
            shell_exec(sprintf(
                'rsync -a %s/ %s/ 2>&1',
                escapeshellarg($childDocRoot),
                escapeshellarg($newDocRoot)
            ));
        }

        // 3. Set ownership to parent user
        shell_exec(sprintf('chown -R %s:www-data %s 2>&1', escapeshellarg($parentUser), escapeshellarg($newSubDir)));

        // 4. Remove child's system resources (Caddy route, FPM pool, Linux user)
        SystemService::deleteAccount($childUser, $childDomain, $childHome);

        // 5. Remove child from hosting_accounts (cascade deletes hosting_domains, hosting_databases)
        // First save database associations to re-register under parent
        $childDatabases = Database::fetchAll(
            "SELECT db_name, db_user, db_type FROM hosting_databases WHERE account_id = :id",
            ['id' => $childAccountId]
        );
        Database::delete('hosting_accounts', 'id = :id', ['id' => $childAccountId]);

        // 6. Re-register databases under parent account
        foreach ($childDatabases as $db) {
            $exists = Database::fetchOne(
                "SELECT id FROM hosting_databases WHERE account_id = :aid AND db_name = :n",
                ['aid' => $parentAccountId, 'n' => $db['db_name']]
            );
            if (!$exists) {
                Database::insert('hosting_databases', [
                    'account_id' => $parentAccountId,
                    'db_name'    => $db['db_name'],
                    'db_user'    => $db['db_user'],
                    'db_type'    => $db['db_type'],
                ]);
            }
        }

        // 7. Create subdomain record with Caddy route
        $caddyRouteId = SystemService::addCaddyRoute($childDomain, $newDocRoot, $parentUser, $parent['php_version'] ?? '8.3');

        $id = Database::insert('hosting_subdomains', [
            'account_id'     => $parentAccountId,
            'subdomain'      => $childDomain,
            'document_root'  => $newDocRoot,
            'caddy_route_id' => $caddyRouteId,
            'status'         => 'active',
        ]);

        LogService::log('subdomain.adopt', $childDomain, "Account adopted as subdomain of {$parentDomain}. Files moved from {$childDocRoot} to {$newDocRoot}");

        return [
            'ok'            => true,
            'id'            => $id,
            'document_root' => $newDocRoot,
            'old_home'      => $childHome,
        ];
    }

    /**
     * Promote a subdomain to an independent hosting account.
     *
     * This:
     * 1. Creates a new Linux user, FPM pool, and Caddy route
     * 2. Moves files from parent_home/subdomain/ to /var/www/vhosts/subdomain/httpdocs/
     * 3. Creates a hosting_accounts record
     * 4. Removes the subdomain record and its Caddy route
     *
     * @return array ['ok' => bool, 'error' => string, 'account_id' => int]
     */
    public static function promote(int $subdomainId): array
    {
        $sub = self::getById($subdomainId);
        if (!$sub) {
            return ['ok' => false, 'error' => 'Subdominio no encontrado.'];
        }

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $sub['account_id']]);
        if (!$account) {
            return ['ok' => false, 'error' => 'Cuenta padre no encontrada.'];
        }

        $subDomain = $sub['subdomain'];
        $subDocRoot = $sub['document_root'];

        // Check domain doesn't already exist as hosting account
        $exists = Database::fetchOne("SELECT id FROM hosting_accounts WHERE domain = :d", ['d' => $subDomain]);
        if ($exists) {
            return ['ok' => false, 'error' => "'{$subDomain}' ya existe como cuenta de hosting."];
        }

        // Generate username from domain (same logic as account creation)
        $username = preg_replace('/[^a-z0-9]/', '', str_replace('.', '', $subDomain));
        $username = substr($username, 0, 28);
        // Check if user exists
        $existingUser = shell_exec(sprintf('id %s 2>&1', escapeshellarg($username)));
        if (strpos($existingUser, 'no such user') === false) {
            $username .= rand(10, 99);
        }

        $newHomeDir = "/var/www/vhosts/{$subDomain}";
        $newDocRoot = "{$newHomeDir}/httpdocs";
        $phpVersion = $account['php_version'] ?? '8.3';

        // 1. Create the new hosting account at system level
        $sysResult = SystemService::createAccount($username, $subDomain, $newHomeDir, $newDocRoot, $phpVersion);
        if (!$sysResult['success']) {
            return ['ok' => false, 'error' => 'Error creando cuenta: ' . ($sysResult['error'] ?? 'unknown')];
        }

        // 2. Move files from subdomain folder to new document root
        if (is_dir($subDocRoot) && $subDocRoot !== $newDocRoot) {
            shell_exec(sprintf('rsync -a %s/ %s/ 2>&1', escapeshellarg($subDocRoot), escapeshellarg($newDocRoot)));
            shell_exec(sprintf('chown -R %s:www-data %s 2>&1', escapeshellarg($username), escapeshellarg($newHomeDir)));
        }

        // 3. Remove old subdomain Caddy route
        if (!empty($sub['caddy_route_id'])) {
            $config = require PANEL_ROOT . '/config/panel.php';
            $caddyApi = $config['caddy']['api_url'];
            $ch = curl_init("{$caddyApi}/id/{$sub['caddy_route_id']}");
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($ch);
            curl_close($ch);
        }

        // 4. Insert into hosting_accounts
        $newAccountId = Database::insert('hosting_accounts', [
            'domain'        => $subDomain,
            'username'      => $username,
            'home_dir'      => $newHomeDir,
            'document_root' => $newDocRoot,
            'php_version'   => $phpVersion,
            'status'        => 'active',
            'caddy_route_id' => $sysResult['caddy_route_id'] ?? null,
            'customer_id'   => $account['customer_id'] ?? null,
        ]);

        // 5. Remove subdomain record
        Database::delete('hosting_subdomains', 'id = :id', ['id' => $subdomainId]);

        LogService::log('subdomain.promote', $subDomain, "Subdomain promoted to independent account from {$account['domain']}. New user: {$username}");

        return [
            'ok'         => true,
            'account_id' => $newAccountId,
            'username'   => $username,
            'home_dir'   => $newHomeDir,
            'doc_root'   => $newDocRoot,
        ];
    }

    /**
     * Export subdomains for cluster sync.
     */
    public static function exportForSync(int $accountId): array
    {
        $rows = self::getAll($accountId);
        return array_map(fn($r) => [
            'subdomain'      => $r['subdomain'],
            'document_root'  => $r['document_root'],
            'status'         => $r['status'],
        ], $rows);
    }

    /**
     * Import subdomains from master (cluster sync).
     * Adds missing, removes stale.
     */
    public static function importFromMaster(int $accountId, array $account, array $subdomainData): void
    {
        $existing = self::getAll($accountId);
        $existingDomains = array_column($existing, 'subdomain');
        $incomingDomains = array_column($subdomainData, 'subdomain');

        // Remove stale
        foreach ($existing as $e) {
            if (!in_array($e['subdomain'], $incomingDomains, true)) {
                self::delete((int)$e['id'], false);
            }
        }

        // Add missing
        foreach ($subdomainData as $s) {
            if (!in_array($s['subdomain'], $existingDomains, true)) {
                self::create($accountId, $s['subdomain'], $s['document_root'] ?? null);
            }
        }
    }
}
