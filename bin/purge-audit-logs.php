<?php
/**
 * Purge file audit logs older than 2 years.
 * Run via cron: 0 4 * * 0 root php /opt/musedock-panel/bin/purge-audit-logs.php
 */

define('PANEL_ROOT', dirname(__DIR__));

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require $file;
});

// Config
$config = require PANEL_ROOT . '/config/panel.php';

try {
    $days = 730; // 2 years
    $stmt = \MuseDockPanel\Database::query(
        "DELETE FROM file_audit_logs WHERE created_at < NOW() - CAST(:days || ' days' AS INTERVAL)",
        ['days' => $days]
    );
    $deleted = $stmt->rowCount();

    $msg = date('[Y-m-d H:i:s]') . " Purged {$deleted} audit log entries older than {$days} days.\n";
    echo $msg;
} catch (\Throwable $e) {
    $msg = date('[Y-m-d H:i:s]') . " ERROR: " . $e->getMessage() . "\n";
    echo $msg;
    exit(1);
}
