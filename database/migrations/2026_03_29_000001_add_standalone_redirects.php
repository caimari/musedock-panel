<?php
/**
 * Add support for standalone redirects (not tied to a hosting account).
 * - hosting_account_id becomes nullable
 * - Add customer_id for assigning redirects to clients
 * - Add target_url for custom redirect destinations
 */
return function (PDO $pdo): void {
    // Make hosting_account_id nullable (for standalone redirects)
    $pdo->exec("ALTER TABLE hosting_domain_aliases ALTER COLUMN hosting_account_id DROP NOT NULL");

    // Add customer_id (optional, for assigning to a client)
    $col = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'hosting_domain_aliases' AND column_name = 'customer_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE hosting_domain_aliases ADD COLUMN customer_id INTEGER DEFAULT NULL REFERENCES customers(id) ON DELETE SET NULL");
    }

    // Add target_url (for standalone redirects — where to redirect to)
    $col2 = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'hosting_domain_aliases' AND column_name = 'target_url'")->fetch();
    if (!$col2) {
        $pdo->exec("ALTER TABLE hosting_domain_aliases ADD COLUMN target_url VARCHAR(500) DEFAULT NULL");
    }
};
