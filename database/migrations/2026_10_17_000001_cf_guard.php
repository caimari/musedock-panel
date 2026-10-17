<?php
/**
 * Conmutación automática ante bloqueos de Cloudflare (CfGuardService): inventario de registros DNS de los
 * dominios de hosting del panel, mediciones de las sondas, cambios hechos (para deshacerlos respetando lo
 * que había) y registro de eventos. Solo crea tablas nuevas; no toca nada existente.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_guard_records (
        id SERIAL PRIMARY KEY,
        account_name VARCHAR(255) NOT NULL,
        zone_id VARCHAR(64) NOT NULL,
        zone_name VARCHAR(255) NOT NULL,
        record_id VARCHAR(64) NOT NULL UNIQUE,
        name VARCHAR(255) NOT NULL,
        type VARCHAR(10) NOT NULL,
        content VARCHAR(255) NOT NULL,
        proxied SMALLINT NOT NULL DEFAULT 0,
        ttl INT NOT NULL DEFAULT 1,
        edge_ips TEXT NULL,
        edge_ips_probe TEXT NULL,
        origin_ip VARCHAR(64) NULL,
        refreshed_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_guard_observations (
        id SERIAL PRIMARY KEY,
        probe VARCHAR(60) NOT NULL,
        path VARCHAR(40) NOT NULL,
        target VARCHAR(120) NOT NULL,
        ok SMALLINT NOT NULL,
        code VARCHAR(10) NULL,
        ms INT NULL,
        public_ip VARCHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cf_guard_obs ON cf_guard_observations (target, created_at)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_guard_changes (
        id SERIAL PRIMARY KEY,
        account_name VARCHAR(255) NOT NULL,
        zone_id VARCHAR(64) NOT NULL,
        zone_name VARCHAR(255) NOT NULL,
        record_id VARCHAR(64) NOT NULL,
        name VARCHAR(255) NOT NULL,
        type VARCHAR(10) NOT NULL,
        content VARCHAR(255) NOT NULL,
        original_ttl INT NOT NULL DEFAULT 1,
        edge_ips TEXT NOT NULL,
        simulated SMALLINT NOT NULL DEFAULT 0,
        cause VARCHAR(40) NOT NULL,
        status VARCHAR(20) NOT NULL,
        changed_at TIMESTAMP NOT NULL DEFAULT NOW(),
        restored_at TIMESTAMP NULL,
        note TEXT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_guard_events (
        id SERIAL PRIMARY KEY,
        level VARCHAR(10) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
};
