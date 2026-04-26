<?php
/**
 * Security baseline: MFA columns + admin login events + watcher settings.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_name = 'panel_admins' AND column_name = 'mfa_enabled'
            ) THEN
                ALTER TABLE panel_admins ADD COLUMN mfa_enabled BOOLEAN NOT NULL DEFAULT false;
            END IF;

            IF NOT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_name = 'panel_admins' AND column_name = 'mfa_secret'
            ) THEN
                ALTER TABLE panel_admins ADD COLUMN mfa_secret TEXT;
            END IF;
        END $$;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS panel_admin_login_events (
            id SERIAL PRIMARY KEY,
            admin_id INTEGER REFERENCES panel_admins(id) ON DELETE SET NULL,
            username VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            asn VARCHAR(64),
            country VARCHAR(64),
            city VARCHAR(128),
            provider VARCHAR(255),
            success BOOLEAN NOT NULL DEFAULT false,
            anomaly BOOLEAN NOT NULL DEFAULT false,
            reason VARCHAR(255),
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_admin_login_events_admin_created
        ON panel_admin_login_events (admin_id, created_at DESC)
    ");
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_admin_login_events_ip_created
        ON panel_admin_login_events (ip_address, created_at DESC)
    ");
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_admin_login_events_anomaly_created
        ON panel_admin_login_events (anomaly, created_at DESC)
    ");

    $defaults = [
        'security_mfa_required' => '0',
        'security_expected_public_tcp_ports' => '22,80,443,8444',
        'notify_event_hardening_enabled' => '1',
        'notify_event_config_drift_enabled' => '1',
        'notify_event_public_exposure_enabled' => '1',
        'notify_login_anomaly_enabled' => '1',
        'notify_event_hardening_email_cooldown_seconds' => '3600',
        'notify_event_config_drift_email_cooldown_seconds' => '1800',
        'notify_event_public_exposure_email_cooldown_seconds' => '1800',
        'notify_login_anomaly_email_cooldown_seconds' => '1800',
        'firewall_lockdown_until_ts' => '0',
        'firewall_lockdown_admin_ip' => '',
    ];

    $stmt = $pdo->prepare("
        INSERT INTO panel_settings (key, value)
        VALUES (:key, :value)
        ON CONFLICT (key) DO NOTHING
    ");
    foreach ($defaults as $key => $value) {
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
};

