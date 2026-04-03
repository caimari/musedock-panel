<?php
/**
 * Migration: Add partial unique index to prevent duplicate active migrations.
 *
 * Ensures only ONE migration per account can be in an active state
 * (pending, running, paused) at any time. Prevents race condition
 * where two concurrent requests could create duplicate migrations.
 */

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS idx_hosting_migrations_active_account
        ON hosting_migrations(account_id)
        WHERE status IN ('pending', 'running', 'paused')
    ");
};
