<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;

class DomainAliasService
{
    /**
     * Get all aliases/redirects for an account.
     */
    public static function getAll(int $accountId): array
    {
        return Database::fetchAll(
            "SELECT * FROM hosting_domain_aliases WHERE hosting_account_id = :id ORDER BY type, domain",
            ['id' => $accountId]
        );
    }

    /**
     * Get aliases only (type=alias).
     */
    public static function getAliases(int $accountId): array
    {
        return Database::fetchAll(
            "SELECT * FROM hosting_domain_aliases WHERE hosting_account_id = :id AND type = 'alias' ORDER BY domain",
            ['id' => $accountId]
        );
    }

    /**
     * Get redirects only (type=redirect).
     */
    public static function getRedirects(int $accountId): array
    {
        return Database::fetchAll(
            "SELECT * FROM hosting_domain_aliases WHERE hosting_account_id = :id AND type = 'redirect' ORDER BY domain",
            ['id' => $accountId]
        );
    }

    /**
     * Get alias domains as flat array (for Caddy rebuild).
     */
    public static function getAliasDomains(int $accountId): array
    {
        $rows = self::getAliases($accountId);
        return array_column($rows, 'domain');
    }

    /**
     * Validate a domain for alias/redirect use.
     */
    public static function validateDomain(string $domain, int $excludeAccountId = 0): ?string
    {
        $domain = strtolower(trim($domain));

        if (empty($domain)) {
            return 'El dominio es obligatorio.';
        }

        // Basic format check
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain)) {
            return 'Formato de dominio inválido.';
        }

        // Check against hosting_accounts
        $existing = Database::fetchOne(
            "SELECT id, domain FROM hosting_accounts WHERE domain = :d",
            ['d' => $domain]
        );
        if ($existing) {
            return "El dominio '{$domain}' ya tiene un hosting propio.";
        }

        // Check against hosting_domain_aliases (exclude self if editing)
        $existingAlias = Database::fetchOne(
            "SELECT id, hosting_account_id FROM hosting_domain_aliases WHERE domain = :d",
            ['d' => $domain]
        );
        if ($existingAlias && (int)$existingAlias['hosting_account_id'] !== $excludeAccountId) {
            return "El dominio '{$domain}' ya está en uso como alias o redirección.";
        }

        // Check www variant
        $wwwDomain = "www.{$domain}";
        $existingWww = Database::fetchOne(
            "SELECT id FROM hosting_accounts WHERE domain = :d",
            ['d' => $wwwDomain]
        );
        if ($existingWww) {
            return "El dominio 'www.{$domain}' ya tiene un hosting propio.";
        }

        return null; // Valid
    }

    /**
     * Add a domain alias (same content as main domain).
     */
    public static function addAlias(int $accountId, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $error = self::validateDomain($domain, $accountId);
        if ($error) {
            return ['ok' => false, 'error' => $error];
        }

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $accountId]);
        if (!$account) {
            return ['ok' => false, 'error' => 'Cuenta no encontrada.'];
        }

        // Insert DB
        $id = Database::insert('hosting_domain_aliases', [
            'hosting_account_id' => $accountId,
            'domain'             => $domain,
            'type'               => 'alias',
            'redirect_code'      => 301,
            'preserve_path'      => true,
        ]);

        // Rebuild Caddy route with all aliases
        $rebuildOk = self::rebuildCaddyRoute($account);

        return [
            'ok'    => true,
            'id'    => $id,
            'caddy' => $rebuildOk,
        ];
    }

    /**
     * Add a domain redirect (301/302 to main domain).
     */
    public static function addRedirect(int $accountId, string $domain, int $code = 301, bool $preservePath = true): array
    {
        $domain = strtolower(trim($domain));
        $error = self::validateDomain($domain, $accountId);
        if ($error) {
            return ['ok' => false, 'error' => $error];
        }

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $accountId]);
        if (!$account) {
            return ['ok' => false, 'error' => 'Cuenta no encontrada.'];
        }

        // Add Caddy redirect route
        $caddyRouteId = SystemService::addCaddyRedirectRoute($domain, $account['domain'], $code, $preservePath);

        // Insert DB
        $id = Database::insert('hosting_domain_aliases', [
            'hosting_account_id' => $accountId,
            'domain'             => $domain,
            'type'               => 'redirect',
            'redirect_code'      => $code,
            'preserve_path'      => $preservePath,
            'caddy_route_id'     => $caddyRouteId,
        ]);

        return [
            'ok'    => true,
            'id'    => $id,
            'caddy' => $caddyRouteId !== null,
        ];
    }

    /**
     * Remove an alias or redirect by ID.
     */
    public static function remove(int $aliasId): array
    {
        $row = Database::fetchOne("SELECT * FROM hosting_domain_aliases WHERE id = :id", ['id' => $aliasId]);
        if (!$row) {
            return ['ok' => false, 'error' => 'No encontrado.'];
        }

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => (int)$row['hosting_account_id']]);

        // Delete from DB
        Database::delete('hosting_domain_aliases', 'id = :id', ['id' => $aliasId]);

        if ($row['type'] === 'alias' && $account) {
            // Rebuild Caddy route without this alias
            self::rebuildCaddyRoute($account);
        } elseif ($row['type'] === 'redirect') {
            // Remove redirect Caddy route
            SystemService::removeCaddyRedirectRoute($row['domain']);
        }

        return ['ok' => true, 'domain' => $row['domain'], 'type' => $row['type']];
    }

    /**
     * Rebuild Caddy route for an account including all aliases.
     */
    public static function rebuildCaddyRoute(array $account): bool
    {
        $aliases = self::getAliasDomains((int)$account['id']);
        $routeId = SystemService::rebuildCaddyRouteWithAliases(
            $account['domain'],
            $aliases,
            $account['document_root'],
            $account['username'],
            $account['php_version'] ?? '8.3'
        );
        return $routeId !== null;
    }

    /**
     * Rebuild all Caddy redirect routes for an account.
     */
    public static function rebuildRedirects(array $account): void
    {
        $redirects = self::getRedirects((int)$account['id']);
        foreach ($redirects as $r) {
            SystemService::addCaddyRedirectRoute(
                $r['domain'],
                $account['domain'],
                (int)$r['redirect_code'],
                (bool)$r['preserve_path']
            );
        }
    }

    /**
     * Get all aliases/redirects for export (used in cluster sync).
     */
    public static function exportForSync(int $accountId): array
    {
        $rows = self::getAll($accountId);
        return array_map(fn($r) => [
            'domain'        => $r['domain'],
            'type'          => $r['type'],
            'redirect_code' => (int)$r['redirect_code'],
            'preserve_path' => (bool)$r['preserve_path'],
        ], $rows);
    }

    /**
     * Import aliases/redirects from master (used in Sync Todo).
     * Syncs the full list: adds missing, removes stale.
     */
    public static function importFromMaster(int $accountId, array $account, array $aliasData): void
    {
        $existing = self::getAll($accountId);
        $existingDomains = array_column($existing, 'domain');
        $incomingDomains = array_column($aliasData, 'domain');

        // Remove stale (exist locally but not in master)
        foreach ($existing as $e) {
            if (!in_array($e['domain'], $incomingDomains, true)) {
                self::remove((int)$e['id']);
            }
        }

        // Add missing (exist in master but not locally)
        foreach ($aliasData as $a) {
            if (!in_array($a['domain'], $existingDomains, true)) {
                if ($a['type'] === 'alias') {
                    Database::insert('hosting_domain_aliases', [
                        'hosting_account_id' => $accountId,
                        'domain'             => $a['domain'],
                        'type'               => 'alias',
                        'redirect_code'      => (int)($a['redirect_code'] ?? 301),
                        'preserve_path'      => (bool)($a['preserve_path'] ?? true),
                    ]);
                } elseif ($a['type'] === 'redirect') {
                    $caddyRouteId = SystemService::addCaddyRedirectRoute(
                        $a['domain'],
                        $account['domain'],
                        (int)($a['redirect_code'] ?? 301),
                        (bool)($a['preserve_path'] ?? true)
                    );
                    Database::insert('hosting_domain_aliases', [
                        'hosting_account_id' => $accountId,
                        'domain'             => $a['domain'],
                        'type'               => 'redirect',
                        'redirect_code'      => (int)($a['redirect_code'] ?? 301),
                        'preserve_path'      => (bool)($a['preserve_path'] ?? true),
                        'caddy_route_id'     => $caddyRouteId,
                    ]);
                }
            }
        }

        // Rebuild Caddy hosting route with current aliases
        self::rebuildCaddyRoute($account);
    }
}
