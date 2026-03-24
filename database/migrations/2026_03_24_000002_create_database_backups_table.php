<?php
/**
 * Create database_backups table for tracking database backup files.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS database_backups (
            id          SERIAL PRIMARY KEY,
            db_name     VARCHAR(100) NOT NULL,
            db_type     VARCHAR(20)  NOT NULL DEFAULT 'pgsql',
            filename    VARCHAR(500) NOT NULL,
            file_size   BIGINT       NOT NULL DEFAULT 0,
            status      VARCHAR(20)  NOT NULL DEFAULT 'completed',
            notes       TEXT         NOT NULL DEFAULT '',
            created_by  INTEGER      REFERENCES panel_admins(id),
            created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_database_backups_db_name ON database_backups(db_name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_database_backups_created ON database_backups(created_at DESC)");
};
