-- MuseDock Panel - Database Schema
-- PostgreSQL 12+
-- This file is used by install.sh to create the initial database structure.

BEGIN;

-- Panel administrators
CREATE TABLE IF NOT EXISTS panel_admins (
    id SERIAL PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    role VARCHAR(20) NOT NULL DEFAULT 'admin',
    is_active BOOLEAN NOT NULL DEFAULT true,
    last_login_at TIMESTAMP,
    last_login_ip VARCHAR(45),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Customers (owners of hosting accounts)
CREATE TABLE IF NOT EXISTS customers (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    company VARCHAR(255),
    phone VARCHAR(50),
    password_hash VARCHAR(255),
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Hosting accounts (each = a Linux user + vhost)
CREATE TABLE IF NOT EXISTS hosting_accounts (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL,
    domain VARCHAR(255) NOT NULL UNIQUE,
    username VARCHAR(32) NOT NULL UNIQUE,
    system_uid INTEGER,
    home_dir VARCHAR(500) NOT NULL,
    document_root VARCHAR(500) NOT NULL,
    php_version VARCHAR(10) NOT NULL DEFAULT '8.3',
    fpm_socket VARCHAR(255),
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    disk_quota_mb INTEGER NOT NULL DEFAULT 1024,
    disk_used_mb INTEGER NOT NULL DEFAULT 0,
    description TEXT,
    caddy_route_id VARCHAR(255),
    shell VARCHAR(50) NOT NULL DEFAULT '/usr/sbin/nologin',
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Additional domains (aliases for hosting accounts)
CREATE TABLE IF NOT EXISTS hosting_domains (
    id SERIAL PRIMARY KEY,
    account_id INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
    domain VARCHAR(255) NOT NULL UNIQUE,
    is_primary BOOLEAN NOT NULL DEFAULT false,
    ssl_enabled BOOLEAN NOT NULL DEFAULT true,
    caddy_route_id VARCHAR(255),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Databases created for hosting accounts
CREATE TABLE IF NOT EXISTS hosting_databases (
    id SERIAL PRIMARY KEY,
    account_id INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
    db_name VARCHAR(100) NOT NULL UNIQUE,
    db_user VARCHAR(100) NOT NULL,
    db_type VARCHAR(20) NOT NULL DEFAULT 'pgsql',
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Activity log
CREATE TABLE IF NOT EXISTS panel_log (
    id SERIAL PRIMARY KEY,
    admin_id INTEGER REFERENCES panel_admins(id),
    action VARCHAR(100) NOT NULL,
    target VARCHAR(255),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- =====================================================
-- Cluster (Phase 2 — tables ready for future use)
-- =====================================================

-- Cluster nodes: master, slave, or standalone
CREATE TABLE IF NOT EXISTS cluster_nodes (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'standalone',  -- standalone, master, slave
    api_url VARCHAR(500) NOT NULL,                   -- https://10.10.70.156:8444/api/cluster
    auth_token VARCHAR(255) NOT NULL,                -- shared secret for API auth
    status VARCHAR(20) NOT NULL DEFAULT 'offline',   -- online, offline, syncing, error
    last_seen_at TIMESTAMP,
    last_sync_at TIMESTAMP,
    sync_lag_seconds INTEGER DEFAULT 0,
    metadata JSONB,                                  -- version, OS, IP, etc.
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Cluster action queue: events to sync between nodes
CREATE TABLE IF NOT EXISTS cluster_queue (
    id SERIAL PRIMARY KEY,
    node_id INTEGER REFERENCES cluster_nodes(id) ON DELETE CASCADE,
    action VARCHAR(100) NOT NULL,                    -- create_hosting, delete_hosting, suspend, sync_files, sync_db
    payload JSONB NOT NULL,                          -- full action data (domain, user, php, etc.)
    status VARCHAR(20) NOT NULL DEFAULT 'pending',   -- pending, processing, completed, failed, retry
    priority INTEGER NOT NULL DEFAULT 5,             -- 1=highest, 10=lowest
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    error_message TEXT,
    scheduled_at TIMESTAMP NOT NULL DEFAULT NOW(),
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- =====================================================
-- Migrations tracking
-- =====================================================
CREATE TABLE IF NOT EXISTS panel_migrations (
    id SERIAL PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    executed_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_customers_status ON customers(status);
CREATE INDEX IF NOT EXISTS idx_hosting_accounts_status ON hosting_accounts(status);
CREATE INDEX IF NOT EXISTS idx_hosting_accounts_customer ON hosting_accounts(customer_id);
CREATE INDEX IF NOT EXISTS idx_hosting_domains_account ON hosting_domains(account_id);
CREATE INDEX IF NOT EXISTS idx_panel_log_created ON panel_log(created_at);
CREATE INDEX IF NOT EXISTS idx_cluster_queue_status ON cluster_queue(status);
CREATE INDEX IF NOT EXISTS idx_cluster_queue_node ON cluster_queue(node_id);
CREATE INDEX IF NOT EXISTS idx_cluster_queue_scheduled ON cluster_queue(scheduled_at);

COMMIT;
