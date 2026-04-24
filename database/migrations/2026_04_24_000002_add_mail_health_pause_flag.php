<?php
/**
 * Persist whether latest mail DB health should pause mail queue actions.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'mail_node_health' AND column_name = 'pause_queue') THEN
                ALTER TABLE mail_node_health ADD COLUMN pause_queue BOOLEAN NOT NULL DEFAULT false;
            END IF;
        END $$
    ");
};
