<?php
/**
 * Add mail_hostname column to cluster_nodes.
 * Stores the FQDN used for MX records and TLS certificates per mail node.
 * Must be unique across all nodes to avoid MX conflicts.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        DO \$\$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                           WHERE table_name = 'cluster_nodes' AND column_name = 'mail_hostname') THEN
                ALTER TABLE cluster_nodes ADD COLUMN mail_hostname VARCHAR(255) DEFAULT NULL;
            END IF;
        END \$\$
    ");

    // Unique index — only non-null values (partial index)
    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS idx_cluster_nodes_mail_hostname
        ON cluster_nodes (mail_hostname)
        WHERE mail_hostname IS NOT NULL
    ");
};
