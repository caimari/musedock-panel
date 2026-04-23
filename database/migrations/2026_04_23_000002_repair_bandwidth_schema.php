<?php
/**
 * Migration: Repair bandwidth schema drift on upgraded/slave nodes.
 *
 * Ensures both bandwidth tables exist with the current columns used by services:
 * - ts (timestamp bucket)
 * - bytes_out / bytes_in / requests
 */

use MuseDockPanel\Database;

return new class {
    public function up(): void
    {
        // Ensure hosting_bandwidth exists (final schema)
        Database::query("
            CREATE TABLE IF NOT EXISTS hosting_bandwidth (
                id          SERIAL PRIMARY KEY,
                account_id  INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
                ts          TIMESTAMP NOT NULL,
                bytes_out   BIGINT NOT NULL DEFAULT 0,
                bytes_in    BIGINT NOT NULL DEFAULT 0,
                requests    INTEGER NOT NULL DEFAULT 0,
                updated_at  TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        // Ensure hosting_subdomain_bandwidth exists (final schema)
        Database::query("
            CREATE TABLE IF NOT EXISTS hosting_subdomain_bandwidth (
                id              SERIAL PRIMARY KEY,
                subdomain_id    INTEGER NOT NULL REFERENCES hosting_subdomains(id) ON DELETE CASCADE,
                ts              TIMESTAMP NOT NULL,
                bytes_out       BIGINT NOT NULL DEFAULT 0,
                bytes_in        BIGINT NOT NULL DEFAULT 0,
                requests        INTEGER NOT NULL DEFAULT 0,
                updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        // Add missing columns (old installs may still have daily schema or no bytes_in)
        Database::query("ALTER TABLE hosting_bandwidth ADD COLUMN IF NOT EXISTS bytes_in BIGINT NOT NULL DEFAULT 0");
        Database::query("ALTER TABLE hosting_subdomain_bandwidth ADD COLUMN IF NOT EXISTS bytes_in BIGINT NOT NULL DEFAULT 0");
        Database::query("ALTER TABLE hosting_bandwidth ADD COLUMN IF NOT EXISTS ts TIMESTAMP");
        Database::query("ALTER TABLE hosting_subdomain_bandwidth ADD COLUMN IF NOT EXISTS ts TIMESTAMP");

        // Migrate legacy date -> ts if present
        Database::query("
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema='public' AND table_name='hosting_bandwidth' AND column_name='date'
                ) THEN
                    UPDATE hosting_bandwidth SET ts = COALESCE(ts, date::timestamp) WHERE ts IS NULL;
                    ALTER TABLE hosting_bandwidth DROP COLUMN date;
                END IF;
            END $$;
        ");

        Database::query("
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema='public' AND table_name='hosting_subdomain_bandwidth' AND column_name='date'
                ) THEN
                    UPDATE hosting_subdomain_bandwidth SET ts = COALESCE(ts, date::timestamp) WHERE ts IS NULL;
                    ALTER TABLE hosting_subdomain_bandwidth DROP COLUMN date;
                END IF;
            END $$;
        ");

        // Final normalize
        Database::query("UPDATE hosting_bandwidth SET ts = NOW() WHERE ts IS NULL");
        Database::query("UPDATE hosting_subdomain_bandwidth SET ts = NOW() WHERE ts IS NULL");
        Database::query("ALTER TABLE hosting_bandwidth ALTER COLUMN ts SET NOT NULL");
        Database::query("ALTER TABLE hosting_subdomain_bandwidth ALTER COLUMN ts SET NOT NULL");

        Database::query("CREATE UNIQUE INDEX IF NOT EXISTS idx_bandwidth_account_ts ON hosting_bandwidth(account_id, ts)");
        Database::query("CREATE INDEX IF NOT EXISTS idx_bandwidth_ts ON hosting_bandwidth(ts)");
        Database::query("CREATE UNIQUE INDEX IF NOT EXISTS idx_sub_bw_sub_ts ON hosting_subdomain_bandwidth(subdomain_id, ts)");

        Database::query("
            INSERT INTO panel_settings (key, value) VALUES ('bandwidth_log_offset', '0')
            ON CONFLICT (key) DO NOTHING
        ");
    }

    public function down(): void
    {
        // No-op: this migration repairs drifted production schemas.
    }
};
