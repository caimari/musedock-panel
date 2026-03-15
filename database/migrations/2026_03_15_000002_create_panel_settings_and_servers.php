<?php
/**
 * Create panel_settings table (key-value store) and servers table.
 * Add server_id to hosting_accounts for future clustering.
 */
return function (PDO $pdo): void {
    // Key-value settings store
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS panel_settings (
            key VARCHAR(100) PRIMARY KEY,
            value TEXT NOT NULL DEFAULT '',
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    // Servers table (localhost by default, clustering later)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS servers (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            hostname VARCHAR(255),
            ip_address VARCHAR(45),
            role VARCHAR(20) NOT NULL DEFAULT 'standalone',
            is_local BOOLEAN NOT NULL DEFAULT true,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            metadata JSONB,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    // Insert localhost server if not exists
    $pdo->exec("
        INSERT INTO servers (name, hostname, ip_address, role, is_local, status)
        SELECT 'localhost', '', '', 'standalone', true, 'active'
        WHERE NOT EXISTS (SELECT 1 FROM servers WHERE is_local = true)
    ");

    // Add server_id to hosting_accounts (nullable for backwards compat)
    $col = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='hosting_accounts' AND column_name='server_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE hosting_accounts ADD COLUMN server_id INTEGER REFERENCES servers(id) ON DELETE SET NULL");
        // Set existing accounts to localhost
        $pdo->exec("UPDATE hosting_accounts SET server_id = (SELECT id FROM servers WHERE is_local = true LIMIT 1)");
    }

    // Insert default settings
    $defaults = [
        'panel_hostname' => '',
        'panel_protocol' => 'http',
        'panel_timezone' => 'UTC',
        'server_ip' => '',
    ];
    $stmt = $pdo->prepare("INSERT INTO panel_settings (key, value) VALUES (:key, :value) ON CONFLICT (key) DO NOTHING");
    foreach ($defaults as $k => $v) {
        $stmt->execute(['key' => $k, 'value' => $v]);
    }
};
