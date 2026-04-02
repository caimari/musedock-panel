<?php
/**
 * Migration: Create hosting_bandwidth table.
 *
 * Stores aggregated bandwidth per hosting account per day.
 * Data is collected by parsing Caddy access logs hourly.
 */

use MuseDockPanel\Database;

return new class {
    public function up(): void
    {
        Database::query("
            CREATE TABLE IF NOT EXISTS hosting_bandwidth (
                id          SERIAL PRIMARY KEY,
                account_id  INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
                date        DATE NOT NULL,
                bytes_out   BIGINT NOT NULL DEFAULT 0,
                requests    INTEGER NOT NULL DEFAULT 0,
                updated_at  TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        Database::query("CREATE UNIQUE INDEX IF NOT EXISTS idx_bandwidth_account_date ON hosting_bandwidth(account_id, date)");
        Database::query("CREATE INDEX IF NOT EXISTS idx_bandwidth_date ON hosting_bandwidth(date)");

        // Track log parser position
        Database::query("
            INSERT INTO panel_settings (key, value) VALUES ('bandwidth_log_offset', '0')
            ON CONFLICT (key) DO NOTHING
        ");
    }

    public function down(): void
    {
        Database::query("DROP TABLE IF EXISTS hosting_bandwidth");
        Database::query("DELETE FROM panel_settings WHERE key = 'bandwidth_log_offset'");
    }
};
