<?php
/**
 * Private WireGuard relay mode: multi-domain DKIM + SASL users.
 */
return function (PDO $pdo): void {
    foreach ([
        'mail_relay_wireguard_ip' => '',
        'mail_relay_wireguard_cidr' => '10.10.70.0/24',
        'mail_relay_primary_domain' => '',
        'mail_relay_public_ip' => '',
        'mail_relay_fallback_enabled' => '0',
        'mail_relay_fallback_host' => '',
        'mail_relay_fallback_port' => '2525',
        'mail_relay_fallback_user' => '',
        'mail_relay_fallback_password_enc' => '',
        'mail_relay_host' => '',
        'mail_relay_port' => '587',
        'mail_relay_user' => '',
        'mail_relay_password_enc' => '',
    ] as $key => $value) {
        $stmt = $pdo->prepare("INSERT INTO panel_settings (key, value) VALUES (:k, :v) ON CONFLICT (key) DO NOTHING");
        $stmt->execute(['k' => $key, 'v' => $value]);
    }

    $pdo->exec("
        DO $$
        BEGIN
            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'cluster_nodes' AND column_name = 'mail_mode') THEN
                ALTER TABLE cluster_nodes DROP CONSTRAINT IF EXISTS cluster_nodes_mail_mode_check;
                ALTER TABLE cluster_nodes
                    ADD CONSTRAINT cluster_nodes_mail_mode_check
                    CHECK (mail_mode IN ('full', 'satellite', 'relay', 'external'));
            END IF;

            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'mail_domains' AND column_name = 'mail_mode') THEN
                ALTER TABLE mail_domains DROP CONSTRAINT IF EXISTS mail_domains_mail_mode_check;
                ALTER TABLE mail_domains
                    ADD CONSTRAINT mail_domains_mail_mode_check
                    CHECK (mail_mode IN ('full', 'satellite', 'relay', 'external'));
            END IF;
        END $$
    ");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_relay_domains (
        id SERIAL PRIMARY KEY,
        domain VARCHAR(255) NOT NULL UNIQUE,
        dkim_selector VARCHAR(63) NOT NULL DEFAULT 'default',
        dkim_private_key TEXT,
        dkim_public_key TEXT,
        spf_verified BOOLEAN NOT NULL DEFAULT false,
        dkim_verified BOOLEAN NOT NULL DEFAULT false,
        dmarc_verified BOOLEAN NOT NULL DEFAULT false,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        last_dns_check_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_relay_users (
        id SERIAL PRIMARY KEY,
        username VARCHAR(255) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        description VARCHAR(255),
        enabled BOOLEAN NOT NULL DEFAULT true,
        rate_limit_per_hour INTEGER NOT NULL DEFAULT 200,
        allowed_from_domains TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_relay_domains_status ON mail_relay_domains(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_relay_users_enabled ON mail_relay_users(enabled)");
};
