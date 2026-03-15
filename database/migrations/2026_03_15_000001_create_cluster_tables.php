<?php
/**
 * Create cluster_nodes and cluster_queue tables
 * For panels updating from pre-cluster versions.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cluster_nodes (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'standalone',
            api_url VARCHAR(500) NOT NULL,
            auth_token VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'offline',
            last_seen_at TIMESTAMP,
            last_sync_at TIMESTAMP,
            sync_lag_seconds INTEGER DEFAULT 0,
            metadata JSONB,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cluster_queue (
            id SERIAL PRIMARY KEY,
            node_id INTEGER REFERENCES cluster_nodes(id) ON DELETE CASCADE,
            action VARCHAR(100) NOT NULL,
            payload JSONB NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            priority INTEGER NOT NULL DEFAULT 5,
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 3,
            error_message TEXT,
            scheduled_at TIMESTAMP NOT NULL DEFAULT NOW(),
            started_at TIMESTAMP,
            completed_at TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cluster_queue_status ON cluster_queue(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cluster_queue_node ON cluster_queue(node_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cluster_queue_scheduled ON cluster_queue(scheduled_at)");
};
