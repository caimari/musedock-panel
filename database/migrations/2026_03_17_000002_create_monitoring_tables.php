<?php
/**
 * Create monitoring tables for network traffic, CPU, RAM metrics.
 * Includes raw metrics, hourly/daily aggregates, and alerts.
 */
return function (PDO $pdo): void {
    // Raw metrics — high resolution (30s intervals), retained 48h
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS monitor_metrics (
            ts TIMESTAMP NOT NULL DEFAULT NOW(),
            host TEXT NOT NULL DEFAULT 'localhost',
            metric TEXT NOT NULL,
            value DOUBLE PRECISION NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mm_ts ON monitor_metrics (ts)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mm_lookup ON monitor_metrics (host, metric, ts DESC)");

    // Hourly aggregates — retained 90 days
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS monitor_metrics_hourly (
            ts TIMESTAMP NOT NULL,
            host TEXT NOT NULL,
            metric TEXT NOT NULL,
            avg_val DOUBLE PRECISION,
            max_val DOUBLE PRECISION,
            min_val DOUBLE PRECISION,
            samples INT,
            UNIQUE(ts, host, metric)
        )
    ");

    // Daily aggregates — retained indefinitely (with optional limit)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS monitor_metrics_daily (
            ts DATE NOT NULL,
            host TEXT NOT NULL,
            metric TEXT NOT NULL,
            avg_val DOUBLE PRECISION,
            max_val DOUBLE PRECISION,
            min_val DOUBLE PRECISION,
            samples INT,
            UNIQUE(ts, host, metric)
        )
    ");

    // Alerts
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS monitor_alerts (
            id SERIAL PRIMARY KEY,
            ts TIMESTAMP NOT NULL DEFAULT NOW(),
            host TEXT NOT NULL DEFAULT 'localhost',
            type TEXT NOT NULL,
            message TEXT NOT NULL,
            value DOUBLE PRECISION,
            acknowledged BOOLEAN DEFAULT FALSE
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ma_ts ON monitor_alerts (ts)");

    // Default settings
    $defaults = [
        'monitor_interfaces'          => 'auto',
        'monitor_alert_cpu'           => '90',
        'monitor_alert_ram'           => '90',
        'monitor_alert_net_mbps'      => '800',
        'monitor_retention_raw_hours' => '48',
        'monitor_retention_hourly_days' => '90',
        'monitor_enabled'             => '1',
        'monitor_alert_gpu_temp'      => '85',
        'monitor_alert_gpu_util'      => '95',
    ];
    $stmt = $pdo->prepare("INSERT INTO panel_settings (key, value) VALUES (:key, :value) ON CONFLICT (key) DO NOTHING");
    foreach ($defaults as $k => $v) {
        $stmt->execute(['key' => $k, 'value' => $v]);
    }
};
