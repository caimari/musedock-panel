<?php
/**
 * Migration: Create hosting_subdomains table
 *
 * Subdomains belong to a parent hosting account, share the same Linux user
 * and PHP-FPM pool, but have their own document root and Caddy route.
 */

use MuseDockPanel\Database;

return new class {
    public function up(): void
    {
        Database::query("
            CREATE TABLE IF NOT EXISTS hosting_subdomains (
                id              SERIAL PRIMARY KEY,
                account_id      INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
                subdomain       VARCHAR(255) NOT NULL,
                document_root   VARCHAR(500) NOT NULL,
                caddy_route_id  VARCHAR(255),
                status          VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at      TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        Database::query("CREATE UNIQUE INDEX IF NOT EXISTS idx_hosting_subdomains_subdomain ON hosting_subdomains(subdomain)");
        Database::query("CREATE INDEX IF NOT EXISTS idx_hosting_subdomains_account ON hosting_subdomains(account_id)");
    }

    public function down(): void
    {
        Database::query("DROP TABLE IF EXISTS hosting_subdomains");
    }
};
