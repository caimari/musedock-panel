<?php
/**
 * Mail node DB health and queue pause metadata.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_node_health (
            id SERIAL PRIMARY KEY,
            node_id INTEGER NOT NULL REFERENCES cluster_nodes(id) ON DELETE CASCADE,
            pg_alive BOOLEAN NOT NULL DEFAULT false,
            pg_read_ok BOOLEAN NOT NULL DEFAULT false,
            is_replica BOOLEAN NOT NULL DEFAULT false,
            replication_lag_seconds NUMERIC(10,2),
            maildir_ok BOOLEAN NOT NULL DEFAULT true,
            mail_domains_count INTEGER DEFAULT 0,
            ptr_ok BOOLEAN,
            ptr_value VARCHAR(255),
            expected_hostname VARCHAR(255),
            status VARCHAR(20) NOT NULL DEFAULT 'unknown',
            message TEXT,
            checked_at TIMESTAMP NOT NULL DEFAULT NOW(),
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_mail_node_health_node_latest
        ON mail_node_health (node_id, checked_at DESC)
    ");

    $pdo->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'cluster_queue' AND column_name = 'paused_reason') THEN
                ALTER TABLE cluster_queue ADD COLUMN paused_reason VARCHAR(255);
            END IF;
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'cluster_queue' AND column_name = 'paused_at') THEN
                ALTER TABLE cluster_queue ADD COLUMN paused_at TIMESTAMP;
            END IF;
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'cluster_queue' AND column_name = 'idempotency_key') THEN
                ALTER TABLE cluster_queue ADD COLUMN idempotency_key VARCHAR(255);
            END IF;
        END $$
    ");

    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS idx_cluster_queue_idempotency_pending
        ON cluster_queue (idempotency_key)
        WHERE status = 'pending' AND idempotency_key IS NOT NULL
    ");

    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('mail_db_lag_warn_seconds', '30') ON CONFLICT (key) DO NOTHING");
    $pdo->exec("INSERT INTO panel_settings (key, value) VALUES ('mail_db_lag_pause_seconds', '120') ON CONFLICT (key) DO NOTHING");
};
