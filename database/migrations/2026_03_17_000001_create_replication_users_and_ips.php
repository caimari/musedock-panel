<?php
/**
 * Migration: Create replication_users and replication_authorized_ips tables
 * Supports the new 3-mode replication flow (Master manual, Slave manual, Auto)
 */
return new class {
    public function up(\PDO $pdo): void
    {
        // Table: replication_users — stores replication credentials created on this server
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS replication_users (
                id SERIAL PRIMARY KEY,
                engine VARCHAR(10) NOT NULL DEFAULT 'pg',
                username VARCHAR(100) NOT NULL,
                password_encrypted TEXT NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Table: replication_authorized_ips — IPs allowed for replication (pg_hba / MySQL GRANT)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS replication_authorized_ips (
                id SERIAL PRIMARY KEY,
                engine VARCHAR(10) NOT NULL DEFAULT 'pg',
                ip_address VARCHAR(45) NOT NULL,
                label VARCHAR(100) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
};
