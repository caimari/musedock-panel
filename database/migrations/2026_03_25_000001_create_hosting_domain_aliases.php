<?php
/**
 * Create hosting_domain_aliases table for domain aliases and redirects.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hosting_domain_aliases (
            id                  SERIAL PRIMARY KEY,
            hosting_account_id  INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
            domain              VARCHAR(255) NOT NULL,
            type                VARCHAR(10) NOT NULL DEFAULT 'alias' CHECK (type IN ('alias', 'redirect')),
            redirect_code       INTEGER NOT NULL DEFAULT 301 CHECK (redirect_code IN (301, 302)),
            preserve_path       BOOLEAN NOT NULL DEFAULT TRUE,
            caddy_route_id      VARCHAR(255) DEFAULT NULL,
            created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE(domain)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_domain_aliases_account ON hosting_domain_aliases(hosting_account_id)");
};
