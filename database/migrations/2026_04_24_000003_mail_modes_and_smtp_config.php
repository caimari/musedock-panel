<?php
/**
 * Mail modes: full, satellite and external relay.
 */
return function (PDO $pdo): void {
    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('mail_mode', 'full') ON CONFLICT (key) DO NOTHING");
    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('mail_outbound_hostname', '') ON CONFLICT (key) DO NOTHING");
    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('mail_outbound_domain', '') ON CONFLICT (key) DO NOTHING");
    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('mail_smtp_host', '') ON CONFLICT (key) DO NOTHING");
    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('mail_smtp_port', '587') ON CONFLICT (key) DO NOTHING");
    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('mail_smtp_user', '') ON CONFLICT (key) DO NOTHING");
    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('mail_smtp_password_enc', '') ON CONFLICT (key) DO NOTHING");
    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('mail_smtp_encryption', 'tls') ON CONFLICT (key) DO NOTHING");
    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('mail_from_address', '') ON CONFLICT (key) DO NOTHING");
    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('mail_from_name', 'MuseDock') ON CONFLICT (key) DO NOTHING");
    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('internal_smtp_token', '') ON CONFLICT (key) DO NOTHING");

    $pdo->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'cluster_nodes' AND column_name = 'mail_mode') THEN
                ALTER TABLE cluster_nodes ADD COLUMN mail_mode VARCHAR(20) NOT NULL DEFAULT 'full';
            END IF;
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'mail_domains' AND column_name = 'mail_mode') THEN
                ALTER TABLE mail_domains ADD COLUMN mail_mode VARCHAR(20) NOT NULL DEFAULT 'full';
            END IF;
        END $$
    ");
};
