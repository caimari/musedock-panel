<?php
/**
 * Backfill standby columns for cluster_nodes on legacy upgraded nodes.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_name = 'cluster_nodes' AND column_name = 'standby'
            ) THEN
                ALTER TABLE cluster_nodes ADD COLUMN standby BOOLEAN NOT NULL DEFAULT false;
            END IF;
        END $$;
    ");

    $pdo->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_name = 'cluster_nodes' AND column_name = 'standby_since'
            ) THEN
                ALTER TABLE cluster_nodes ADD COLUMN standby_since TIMESTAMP;
            END IF;
        END $$;
    ");

    $pdo->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_name = 'cluster_nodes' AND column_name = 'standby_reason'
            ) THEN
                ALTER TABLE cluster_nodes ADD COLUMN standby_reason VARCHAR(255);
            END IF;
        END $$;
    ");
};

