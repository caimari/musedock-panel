<?php
/**
 * Migration: per-cluster PostgreSQL replication relationships.
 *
 * The legacy `replication_slaves` table has a SINGLE pg_port / pg config per
 * slave, so it cannot express a slave that replicates several PostgreSQL
 * clusters at once (Filemon ← 14/main:5432, 14/panel:5433, 16/musemind:5434).
 *
 * This child table holds one row per (slave, cluster) relationship. It is
 * additive and idempotent: `replication_slaves` is untouched and keeps working
 * for existing installs (Nitro). No existing node is modified by this migration.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS replication_pg_instances (
            id SERIAL PRIMARY KEY,
            slave_id INTEGER NOT NULL REFERENCES replication_slaves(id) ON DELETE CASCADE,
            pg_version VARCHAR(10) NOT NULL,              -- major version, e.g. '14', '16'
            cluster_name VARCHAR(100) NOT NULL,           -- e.g. 'main', 'panel', 'musemind'
            source_port INTEGER NOT NULL,                 -- master port for this cluster
            target_port INTEGER NOT NULL,                 -- slave port for this cluster
            replication_type VARCHAR(20) DEFAULT 'physical', -- physical | logical
            sync_mode VARCHAR(20) DEFAULT 'async',        -- async | sync | remote_apply
            replication_user VARCHAR(100) DEFAULT 'replicator',
            encrypted_password TEXT DEFAULT '',
            slot_name VARCHAR(100) DEFAULT '',            -- unique physical slot per slave+cluster
            application_name VARCHAR(100) DEFAULT '',     -- unique per slave+cluster
            enabled BOOLEAN DEFAULT false,
            status VARCHAR(20) DEFAULT 'pending',         -- pending | streaming | error | stopped
            last_error TEXT DEFAULT '',
            last_checked_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT uq_pg_instance UNIQUE (slave_id, pg_version, cluster_name)
        )
    ");

    // Helpful indexes (idempotent).
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pg_instances_slave ON replication_pg_instances(slave_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pg_instances_enabled ON replication_pg_instances(enabled)");

    // Advisory defaults for the WireGuard-scoped, loopback-only master config.
    $defaults = [
        'repl_pg_wg_subnet'   => '10.10.70.0/24',   // WireGuard subnet for pg_hba scoping
        'repl_pg_listen_extra'=> '',                 // extra listen addr (WG IP) filled at runtime
        'repl_dumps_keep'     => '1',                // never drop logical dumps just because streaming is on
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO panel_settings (key, value, updated_at) VALUES (:key, :value, NOW()) ON CONFLICT (key) DO NOTHING'
    );
    foreach ($defaults as $key => $value) {
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
};
