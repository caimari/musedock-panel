<?php
/**
 * Migration: Create federation_peers and hosting_migrations tables.
 *
 * federation_peers: Remote panels that participate in federation (bidirectional, peer-to-peer).
 * hosting_migrations: Tracks hosting migrations between federated masters (state machine).
 */

return function (PDO $pdo): void {
    // ── Federation Peers ──────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS federation_peers (
            id              SERIAL PRIMARY KEY,
            name            VARCHAR(100) NOT NULL,
            api_url         VARCHAR(500) NOT NULL,
            auth_token      TEXT NOT NULL,
            ssh_host        VARCHAR(255) NOT NULL DEFAULT '',
            ssh_port        INTEGER NOT NULL DEFAULT 22,
            ssh_user        VARCHAR(100) NOT NULL DEFAULT 'root',
            ssh_key_path    VARCHAR(500) NOT NULL DEFAULT '/root/.ssh/id_ed25519',
            status          VARCHAR(20) NOT NULL DEFAULT 'offline',
            last_seen_at    TIMESTAMP,
            metadata        JSONB DEFAULT '{}',
            created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_federation_peers_name ON federation_peers(name)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_federation_peers_api_url ON federation_peers(api_url)");

    // ── Hosting Migrations ────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hosting_migrations (
            id                  SERIAL PRIMARY KEY,
            migration_id        VARCHAR(36) NOT NULL UNIQUE,
            account_id          INTEGER NOT NULL REFERENCES hosting_accounts(id),
            peer_id             INTEGER NOT NULL REFERENCES federation_peers(id),
            mode                VARCHAR(20) NOT NULL DEFAULT 'migrate',
            direction           VARCHAR(10) NOT NULL DEFAULT 'outgoing',
            status              VARCHAR(30) NOT NULL DEFAULT 'pending',
            current_step        VARCHAR(30) NOT NULL DEFAULT 'health_check',
            step_lock           VARCHAR(80) DEFAULT NULL,
            dry_run             BOOLEAN NOT NULL DEFAULT FALSE,
            progress            JSONB NOT NULL DEFAULT '{}',
            step_results        JSONB NOT NULL DEFAULT '{}',
            error_message       TEXT,
            started_at          TIMESTAMP,
            completed_at        TIMESTAMP,
            grace_period_minutes INTEGER NOT NULL DEFAULT 60,
            metadata            JSONB NOT NULL DEFAULT '{}',
            created_by          INTEGER REFERENCES panel_admins(id),
            created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at          TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hosting_migrations_account ON hosting_migrations(account_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hosting_migrations_status ON hosting_migrations(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hosting_migrations_peer ON hosting_migrations(peer_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hosting_migrations_step_lock ON hosting_migrations(step_lock)");

    // ── Migration Log (per-step detailed logging) ─────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hosting_migration_logs (
            id              SERIAL PRIMARY KEY,
            migration_id    VARCHAR(36) NOT NULL,
            step            VARCHAR(30) NOT NULL,
            level           VARCHAR(10) NOT NULL DEFAULT 'info',
            message         TEXT NOT NULL,
            metadata        JSONB DEFAULT '{}',
            created_at      TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_migration_logs_mid ON hosting_migration_logs(migration_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_migration_logs_step ON hosting_migration_logs(migration_id, step)");
};
