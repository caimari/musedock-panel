<?php
/**
 * Migration: Add hosting_type column to hosting_accounts.
 *
 * Supports: 'php' (default), 'spa', 'static'
 * - php: try_files → index.php + php_fastcgi (WordPress, Laravel, etc.)
 * - spa: try_files → /index.html + file_server (React, Vue, Angular, Vite)
 * - static: file_server only, no fallback (plain HTML)
 */

return function (PDO $pdo): void {
    // Add column with default 'php' for existing accounts
    $pdo->exec("ALTER TABLE hosting_accounts ADD COLUMN IF NOT EXISTS hosting_type VARCHAR(20) NOT NULL DEFAULT 'php'");
};
