<?php
/**
 * Migration: Create replication_slaves table for multi-slave support
 * and add advanced replication settings.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS replication_slaves (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            primary_ip VARCHAR(45) NOT NULL,
            fallback_ip VARCHAR(45) DEFAULT '',
            pg_port INTEGER DEFAULT 5432,
            pg_user VARCHAR(100) DEFAULT 'replicator',
            pg_pass TEXT DEFAULT '',
            mysql_port INTEGER DEFAULT 3306,
            mysql_user VARCHAR(100) DEFAULT 'repl_user',
            mysql_pass TEXT DEFAULT '',
            pg_enabled BOOLEAN DEFAULT false,
            mysql_enabled BOOLEAN DEFAULT false,
            pg_sync_mode VARCHAR(20) DEFAULT 'async',
            pg_repl_type VARCHAR(20) DEFAULT 'physical',
            pg_logical_databases TEXT DEFAULT '',
            mysql_gtid_enabled BOOLEAN DEFAULT true,
            status VARCHAR(20) DEFAULT 'pending',
            active_connection VARCHAR(20) DEFAULT 'primary',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Add advanced replication settings
    $defaults = [
        'repl_pg_sync_names' => '',
        'repl_pg_wal_level'  => 'replica',
        'repl_mysql_gtid_mode' => '0',
        'repl_mysql_binlog_format' => 'ROW',
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO panel_settings (key, value, updated_at) VALUES (:key, :value, NOW()) ON CONFLICT (key) DO NOTHING'
    );

    foreach ($defaults as $key => $value) {
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
};
