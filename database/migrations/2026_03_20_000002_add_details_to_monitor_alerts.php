<?php
/**
 * Add 'details' column to monitor_alerts for storing full process context.
 */
return function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE monitor_alerts ADD COLUMN IF NOT EXISTS details TEXT DEFAULT NULL");
};
