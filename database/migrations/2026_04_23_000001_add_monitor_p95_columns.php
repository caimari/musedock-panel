<?php
/**
 * Add p95_val percentile column to monitor aggregate tables.
 */
return function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE monitor_metrics_hourly ADD COLUMN IF NOT EXISTS p95_val DOUBLE PRECISION");
    $pdo->exec("ALTER TABLE monitor_metrics_daily ADD COLUMN IF NOT EXISTS p95_val DOUBLE PRECISION");
};

