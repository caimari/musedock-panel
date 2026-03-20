<?php
/**
 * Create mail system tables.
 *
 * Design decisions:
 * - mail_domains is independent of hosting_accounts (supports mail-only customers)
 * - mail_node_id per domain (not global) for granular node routing
 * - Postfix/Dovecot will query these tables directly via SQL lookups
 *   (no postmap, no flat files — changes are instant)
 * - account_id in mail_accounts is nullable (mail without hosting)
 */
return function (PDO $pdo): void {

    // ── Mail domains ─────────────────────────────────────────
    // Each domain can be routed to a specific mail node.
    // Postfix virtual_mailbox_domains map: SELECT domain FROM mail_domains WHERE status='active'
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_domains (
            id SERIAL PRIMARY KEY,
            domain VARCHAR(255) NOT NULL UNIQUE,
            customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL,
            mail_node_id INTEGER REFERENCES cluster_nodes(id) ON DELETE SET NULL,
            dkim_selector VARCHAR(50) NOT NULL DEFAULT 'default',
            dkim_private_key TEXT,
            dkim_public_key TEXT,
            spf_record VARCHAR(500) DEFAULT 'v=spf1 mx ~all',
            dmarc_policy VARCHAR(20) DEFAULT 'quarantine',
            max_accounts INTEGER DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_domains_status ON mail_domains(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_domains_node ON mail_domains(mail_node_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_domains_customer ON mail_domains(customer_id)");

    // ── Mail accounts (mailboxes) ────────────────────────────
    // Linked to mail_domains. Optionally linked to hosting_accounts.
    // Dovecot userdb/passdb query: SELECT email, password_hash, quota_mb, home_dir FROM mail_accounts WHERE ...
    // Postfix virtual_mailbox_maps: SELECT 1 FROM mail_accounts WHERE email = '%s' AND status = 'active'
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_accounts (
            id SERIAL PRIMARY KEY,
            mail_domain_id INTEGER NOT NULL REFERENCES mail_domains(id) ON DELETE CASCADE,
            account_id INTEGER REFERENCES hosting_accounts(id) ON DELETE SET NULL,
            customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            local_part VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(255),
            quota_mb INTEGER NOT NULL DEFAULT 1024,
            used_mb INTEGER NOT NULL DEFAULT 0,
            home_dir VARCHAR(500),
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            last_login_at TIMESTAMP,
            last_login_ip VARCHAR(45),
            autoresponder_enabled BOOLEAN NOT NULL DEFAULT false,
            autoresponder_subject VARCHAR(255),
            autoresponder_body TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_accounts_domain ON mail_accounts(mail_domain_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_accounts_status ON mail_accounts(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_accounts_email ON mail_accounts(email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_accounts_customer ON mail_accounts(customer_id)");

    // ── Mail aliases & forwards ──────────────────────────────
    // Postfix virtual_alias_maps: SELECT destination FROM mail_aliases WHERE source = '%s' AND is_active = true
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_aliases (
            id SERIAL PRIMARY KEY,
            mail_domain_id INTEGER NOT NULL REFERENCES mail_domains(id) ON DELETE CASCADE,
            source VARCHAR(255) NOT NULL,
            destination VARCHAR(255) NOT NULL,
            is_catchall BOOLEAN NOT NULL DEFAULT false,
            is_active BOOLEAN NOT NULL DEFAULT true,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_aliases_source ON mail_aliases(source)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_aliases_domain ON mail_aliases(mail_domain_id)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_mail_aliases_unique ON mail_aliases(source, destination)");

    // ── mail_queue_log removed — Postfix logs are authoritative ──

    // ── Add services column to cluster_nodes ─────────────────
    // Allows tagging nodes with their capabilities: ["web"], ["mail"], ["web","mail"]
    $pdo->exec("
        DO \$\$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                           WHERE table_name = 'cluster_nodes' AND column_name = 'services') THEN
                ALTER TABLE cluster_nodes ADD COLUMN services JSONB NOT NULL DEFAULT '[\"web\"]';
            END IF;
        END \$\$
    ");

    // ── Default mail settings ────────────────────────────────
    $defaults = [
        'mail_enabled'           => '0',
        'mail_default_node_id'   => '',
        'mail_default_quota_mb'  => '1024',
        'mail_max_message_size'  => '25',
        'mail_spam_filter'       => 'rspamd',
        'mail_dkim_auto'         => '1',
        'mail_greylisting'       => '0',
        'mail_ratelimit_hour'    => '100',
    ];
    $stmt = $pdo->prepare("INSERT INTO panel_settings (key, value) VALUES (:key, :value) ON CONFLICT (key) DO NOTHING");
    foreach ($defaults as $k => $v) {
        $stmt->execute(['key' => $k, 'value' => $v]);
    }
};
