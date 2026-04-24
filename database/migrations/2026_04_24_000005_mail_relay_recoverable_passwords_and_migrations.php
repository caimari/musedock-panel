<?php
/**
 * Mail relay migration foundation: recoverable relay passwords and migration jobs.
 */
return function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE mail_relay_users ADD COLUMN IF NOT EXISTS password_encrypted TEXT NOT NULL DEFAULT ''");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_migrations (
        id SERIAL PRIMARY KEY,
        mode VARCHAR(20) NOT NULL,
        source_node_id INTEGER NULL REFERENCES cluster_nodes(id) ON DELETE SET NULL,
        target_node_id INTEGER NULL REFERENCES cluster_nodes(id) ON DELETE SET NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        stage VARCHAR(60) NOT NULL DEFAULT 'created',
        dry_run BOOLEAN NOT NULL DEFAULT true,
        switch_routing BOOLEAN NOT NULL DEFAULT false,
        domains_json JSONB NOT NULL DEFAULT '[]'::jsonb,
        progress_json JSONB NOT NULL DEFAULT '{}'::jsonb,
        error_message TEXT,
        created_by INTEGER NULL,
        started_at TIMESTAMP NULL,
        completed_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_migrations_status ON mail_migrations(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_migrations_created_at ON mail_migrations(created_at DESC)");
};
