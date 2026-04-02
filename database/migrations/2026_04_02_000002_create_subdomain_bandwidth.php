<?php
/**
 * Migration: Create hosting_subdomain_bandwidth table.
 * Stores bandwidth per subdomain per day (same structure as hosting_bandwidth).
 */

use MuseDockPanel\Database;

return new class {
    public function up(): void
    {
        Database::query("
            CREATE TABLE IF NOT EXISTS hosting_subdomain_bandwidth (
                id              SERIAL PRIMARY KEY,
                subdomain_id    INTEGER NOT NULL REFERENCES hosting_subdomains(id) ON DELETE CASCADE,
                date            DATE NOT NULL,
                bytes_out       BIGINT NOT NULL DEFAULT 0,
                requests        INTEGER NOT NULL DEFAULT 0,
                updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        Database::query("CREATE UNIQUE INDEX IF NOT EXISTS idx_sub_bw_sub_date ON hosting_subdomain_bandwidth(subdomain_id, date)");
    }

    public function down(): void
    {
        Database::query("DROP TABLE IF EXISTS hosting_subdomain_bandwidth");
    }
};
