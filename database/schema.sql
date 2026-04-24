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

-- Servers (localhost by default, clustering later)
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
);

-- Insert localhost server
INSERT INTO servers (name, hostname, ip_address, role, is_local, status)
SELECT 'localhost', '', '', 'standalone', true, 'active'
WHERE NOT EXISTS (SELECT 1 FROM servers WHERE is_local = true);

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
    server_id INTEGER REFERENCES servers(id) ON DELETE SET NULL,
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

-- Panel settings (key-value store)
CREATE TABLE IF NOT EXISTS panel_settings (
    key VARCHAR(100) PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Default settings
INSERT INTO panel_settings (key, value) VALUES
    ('panel_hostname', ''),
    ('panel_protocol', 'http'),
    ('panel_timezone', 'UTC'),
    ('server_ip', ''),
    ('mail_webmail_enabled', '0'),
    ('mail_webmail_provider', 'roundcube'),
    ('mail_webmail_host', ''),
    ('mail_webmail_url', ''),
    ('mail_webmail_imap_host', ''),
    ('mail_webmail_smtp_host', ''),
    ('mail_webmail_doc_root', ''),
    ('mail_webmail_aliases', '[]'),
    ('mail_webmail_sieve_enabled', '0'),
    ('mail_webmail_install_task_id', ''),
    ('mail_webmail_install_status', ''),
    ('mail_webmail_installed_at', '')
ON CONFLICT (key) DO NOTHING;

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
    standby BOOLEAN NOT NULL DEFAULT false,          -- true = maintenance mode (no sync, no alerts)
    standby_since TIMESTAMP,                         -- when standby was activated
    standby_reason VARCHAR(255),                     -- optional reason (e.g. "hardware repair")
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

-- Hosting subdomains
CREATE TABLE IF NOT EXISTS hosting_subdomains (
    id SERIAL PRIMARY KEY,
    account_id INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
    subdomain VARCHAR(255) NOT NULL,
    document_root VARCHAR(500),
    php_version VARCHAR(10) DEFAULT '8.3',
    caddy_route_id VARCHAR(255),
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    php_overrides JSONB,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Bandwidth per hosting account (hourly granularity)
CREATE TABLE IF NOT EXISTS hosting_bandwidth (
    id SERIAL PRIMARY KEY,
    account_id INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
    ts TIMESTAMP NOT NULL,
    bytes_out BIGINT NOT NULL DEFAULT 0,
    bytes_in BIGINT NOT NULL DEFAULT 0,
    requests INTEGER NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_bandwidth_account_ts ON hosting_bandwidth(account_id, ts);
CREATE INDEX IF NOT EXISTS idx_bandwidth_date ON hosting_bandwidth(ts);

-- Bandwidth per hosting account (hourly rollup)
CREATE TABLE IF NOT EXISTS hosting_bandwidth_hourly (
    id SERIAL PRIMARY KEY,
    account_id INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
    hour TIMESTAMP NOT NULL,
    bytes_out BIGINT NOT NULL DEFAULT 0,
    bytes_in BIGINT NOT NULL DEFAULT 0,
    requests INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_bandwidth_hourly_account_hour ON hosting_bandwidth_hourly(account_id, hour);

-- Bandwidth per subdomain
CREATE TABLE IF NOT EXISTS hosting_subdomain_bandwidth (
    id SERIAL PRIMARY KEY,
    subdomain_id INTEGER NOT NULL REFERENCES hosting_subdomains(id) ON DELETE CASCADE,
    ts TIMESTAMP NOT NULL,
    bytes_out BIGINT NOT NULL DEFAULT 0,
    bytes_in BIGINT NOT NULL DEFAULT 0,
    requests INTEGER NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_sub_bw_sub_ts ON hosting_subdomain_bandwidth(subdomain_id, ts);

-- Domain aliases (redirects for hosting accounts)
CREATE TABLE IF NOT EXISTS hosting_domain_aliases (
    id SERIAL PRIMARY KEY,
    hosting_account_id INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
    domain VARCHAR(255) NOT NULL UNIQUE,
    type VARCHAR(10) NOT NULL DEFAULT 'alias' CHECK (type IN ('alias', 'redirect')),
    redirect_code INTEGER NOT NULL DEFAULT 301 CHECK (redirect_code IN (301, 302)),
    preserve_path BOOLEAN NOT NULL DEFAULT TRUE,
    caddy_route_id VARCHAR(255),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_domain_aliases_account ON hosting_domain_aliases(hosting_account_id);

-- Web stats per hosting account per day
CREATE TABLE IF NOT EXISTS hosting_web_stats (
    id SERIAL PRIMARY KEY,
    account_id INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
    date DATE NOT NULL,
    top_pages JSONB,
    top_ips JSONB,
    top_countries JSONB,
    top_referrers JSONB,
    top_user_agents JSONB,
    referrer_urls JSONB,
    status_codes JSONB,
    methods JSONB,
    unique_ips INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_web_stats_account_date ON hosting_web_stats(account_id, date);

-- Mail domains
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
);

-- Mail accounts
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
);

-- Mail aliases
CREATE TABLE IF NOT EXISTS mail_aliases (
    id SERIAL PRIMARY KEY,
    mail_domain_id INTEGER NOT NULL REFERENCES mail_domains(id) ON DELETE CASCADE,
    source VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    is_catchall BOOLEAN NOT NULL DEFAULT false,
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_mail_aliases_unique ON mail_aliases(source, destination);

-- Monitoring metrics
CREATE TABLE IF NOT EXISTS monitor_metrics (
    ts TIMESTAMP NOT NULL DEFAULT NOW(),
    host TEXT NOT NULL DEFAULT 'localhost',
    metric TEXT NOT NULL,
    value DOUBLE PRECISION NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_mm_ts ON monitor_metrics(ts);
CREATE INDEX IF NOT EXISTS idx_mm_lookup ON monitor_metrics(host, metric, ts DESC);

-- Monitoring metrics hourly rollup
CREATE TABLE IF NOT EXISTS monitor_metrics_hourly (
    ts TIMESTAMP NOT NULL,
    host TEXT NOT NULL DEFAULT 'localhost',
    metric TEXT NOT NULL,
    value DOUBLE PRECISION NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_mmh_lookup ON monitor_metrics_hourly(host, metric, ts);

-- Monitoring metrics daily rollup
CREATE TABLE IF NOT EXISTS monitor_metrics_daily (
    ts TIMESTAMP NOT NULL,
    host TEXT NOT NULL DEFAULT 'localhost',
    metric TEXT NOT NULL,
    value DOUBLE PRECISION NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_mmd_lookup ON monitor_metrics_daily(host, metric, ts);

-- Monitoring alerts
CREATE TABLE IF NOT EXISTS monitor_alerts (
    id SERIAL PRIMARY KEY,
    ts TIMESTAMP NOT NULL DEFAULT NOW(),
    host TEXT NOT NULL DEFAULT 'localhost',
    type TEXT NOT NULL,
    message TEXT NOT NULL,
    value DOUBLE PRECISION,
    acknowledged BOOLEAN DEFAULT FALSE,
    details TEXT
);
CREATE INDEX IF NOT EXISTS idx_ma_ts ON monitor_alerts(ts);

-- Proxy routes
CREATE TABLE IF NOT EXISTS proxy_routes (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL DEFAULT '',
    domain VARCHAR(255) NOT NULL UNIQUE,
    target_ip VARCHAR(45) NOT NULL,
    target_port INTEGER NOT NULL DEFAULT 443,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    notes TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Database backups
CREATE TABLE IF NOT EXISTS database_backups (
    id SERIAL PRIMARY KEY,
    db_name VARCHAR(100) NOT NULL,
    db_type VARCHAR(20) NOT NULL DEFAULT 'pgsql',
    filename VARCHAR(500) NOT NULL,
    file_size BIGINT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    notes TEXT NOT NULL DEFAULT '',
    created_by INTEGER REFERENCES panel_admins(id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- File audit logs
CREATE TABLE IF NOT EXISTS file_audit_logs (
    id BIGSERIAL PRIMARY KEY,
    admin_id INT NOT NULL,
    admin_username VARCHAR(100) NOT NULL,
    admin_ip INET NOT NULL,
    account_id INT NOT NULL,
    account_username VARCHAR(100) NOT NULL,
    account_domain VARCHAR(255),
    action VARCHAR(20) NOT NULL,
    path VARCHAR(1024) NOT NULL,
    details JSONB,
    legal_basis VARCHAR(50) DEFAULT 'contract_execution',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Replication slaves
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
);

-- Replication users
CREATE TABLE IF NOT EXISTS replication_users (
    id SERIAL PRIMARY KEY,
    engine VARCHAR(10) NOT NULL DEFAULT 'pg',
    username VARCHAR(100) NOT NULL,
    password_encrypted TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Replication authorized IPs
CREATE TABLE IF NOT EXISTS replication_authorized_ips (
    id SERIAL PRIMARY KEY,
    engine VARCHAR(10) NOT NULL DEFAULT 'pg',
    ip_address VARCHAR(45) NOT NULL,
    label VARCHAR(100) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
