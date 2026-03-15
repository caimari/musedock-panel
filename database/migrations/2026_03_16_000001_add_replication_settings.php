<?php
/**
 * Migration: Add replication settings to panel_settings
 */
return function (PDO $pdo): void {
    $defaults = [
        'repl_role'          => 'standalone',
        'repl_remote_ip'     => '',
        'repl_pg_port'       => '5432',
        'repl_pg_user'       => 'replicator',
        'repl_pg_pass'       => '',
        'repl_mysql_port'    => '3306',
        'repl_mysql_user'    => 'repl_user',
        'repl_mysql_pass'    => '',
        'repl_pg_enabled'    => '0',
        'repl_mysql_enabled' => '0',
        'repl_configured_at' => '',
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO panel_settings (key, value, updated_at) VALUES (:key, :value, NOW()) ON CONFLICT (key) DO NOTHING'
    );

    foreach ($defaults as $key => $value) {
        $stmt->execute(['key' => $key, 'value' => $value]);
    }

    // Update localhost server IP and hostname if empty
    $pdo->exec("
        UPDATE servers
        SET ip_address = COALESCE(NULLIF(ip_address, ''), '127.0.0.1'),
            hostname   = COALESCE(NULLIF(hostname, ''), 'localhost')
        WHERE is_local = true
    ");
};
