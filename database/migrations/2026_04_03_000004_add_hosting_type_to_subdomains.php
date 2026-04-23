<?php
/**
 * Migration: Add hosting_type to hosting_subdomains.
 * Each subdomain can have its own hosting type (php/spa/static).
 */

return function (PDO $pdo): void {
    $pdo->exec("
        DO $$
        BEGIN
            IF to_regclass('public.hosting_subdomains') IS NOT NULL THEN
                ALTER TABLE hosting_subdomains
                    ADD COLUMN IF NOT EXISTS hosting_type VARCHAR(20) NOT NULL DEFAULT 'php';
            END IF;
        END
        $$;
    ");
};
