<?php
/**
 * Create proxy_routes table for permanent SNI proxy routing.
 * Allows servers with a public IP to proxy domains to internal/NAT servers.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS proxy_routes (
            id          SERIAL PRIMARY KEY,
            name        VARCHAR(100) NOT NULL DEFAULT '',
            domain      VARCHAR(255) NOT NULL,
            target_ip   VARCHAR(45)  NOT NULL,
            target_port INTEGER      NOT NULL DEFAULT 443,
            enabled     BOOLEAN      NOT NULL DEFAULT TRUE,
            notes       TEXT         NOT NULL DEFAULT '',
            created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
            updated_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
            UNIQUE(domain)
        )
    ");
};
