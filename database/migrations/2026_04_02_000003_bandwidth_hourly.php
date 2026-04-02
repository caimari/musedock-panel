<?php
/**
 * Migration: Change bandwidth tables from daily to hourly granularity.
 * Renames 'date' column to 'ts' (timestamp) for hourly aggregation.
 */

use MuseDockPanel\Database;

return new class {
    public function up(): void
    {
        // hosting_bandwidth: date -> ts
        Database::query("ALTER TABLE hosting_bandwidth DROP CONSTRAINT IF EXISTS idx_bandwidth_account_date");
        Database::query("ALTER TABLE hosting_bandwidth ALTER COLUMN date TYPE TIMESTAMP USING date::timestamp");
        Database::query("ALTER TABLE hosting_bandwidth RENAME COLUMN date TO ts");
        Database::query("CREATE UNIQUE INDEX IF NOT EXISTS idx_bandwidth_account_ts ON hosting_bandwidth(account_id, ts)");

        // hosting_subdomain_bandwidth: date -> ts
        Database::query("ALTER TABLE hosting_subdomain_bandwidth DROP CONSTRAINT IF EXISTS idx_sub_bw_sub_date");
        Database::query("ALTER TABLE hosting_subdomain_bandwidth ALTER COLUMN date TYPE TIMESTAMP USING date::timestamp");
        Database::query("ALTER TABLE hosting_subdomain_bandwidth RENAME COLUMN date TO ts");
        Database::query("CREATE UNIQUE INDEX IF NOT EXISTS idx_sub_bw_sub_ts ON hosting_subdomain_bandwidth(subdomain_id, ts)");
    }

    public function down(): void
    {
        Database::query("ALTER TABLE hosting_bandwidth RENAME COLUMN ts TO date");
        Database::query("ALTER TABLE hosting_bandwidth ALTER COLUMN date TYPE DATE");
        Database::query("ALTER TABLE hosting_subdomain_bandwidth RENAME COLUMN ts TO date");
        Database::query("ALTER TABLE hosting_subdomain_bandwidth ALTER COLUMN date TYPE DATE");
    }
};
