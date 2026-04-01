<?php
/**
 * Migration: Add php_overrides JSON column to hosting_subdomains.
 *
 * Allows per-subdomain PHP-FPM overrides (memory_limit, upload_max_filesize, etc.)
 * via a .user.ini file. When NULL, the subdomain inherits the parent account's settings.
 */

use MuseDockPanel\Database;

return new class {
    public function up(): void
    {
        Database::query("
            ALTER TABLE hosting_subdomains
            ADD COLUMN IF NOT EXISTS php_overrides JSONB DEFAULT NULL
        ");
    }

    public function down(): void
    {
        Database::query("ALTER TABLE hosting_subdomains DROP COLUMN IF EXISTS php_overrides");
    }
};
