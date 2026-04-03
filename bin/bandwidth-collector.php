#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — Bandwidth Collector
 *
 * Parses new entries from Caddy's hosting-access.log and aggregates
 * bandwidth per hosting account per day into hosting_bandwidth table.
 *
 * Designed to run every 5-10 minutes via cron.
 * Uses byte-offset cursor to resume from last position (survives log rotation).
 *
 * Usage:
 *   php bin/bandwidth-collector.php           Normal run
 *   php bin/bandwidth-collector.php --reset   Reset offset to 0 (re-parse everything)
 */

define('PANEL_ROOT', dirname(__DIR__));

spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

// Handle --reset flag
if (in_array('--reset', $argv ?? [])) {
    \MuseDockPanel\Settings::set('bandwidth_log_offset', '0');
    \MuseDockPanel\Settings::set('webstats_log_offset', '0');
    echo "Offsets reset to 0.\n";
}

$result = \MuseDockPanel\Services\BandwidthService::collectFromLog();
if ($result['ok'] && $result['lines'] > 0) {
    echo "BW: {$result['lines']} entries for {$result['accounts']} account(s).\n";
}

$statsResult = \MuseDockPanel\Services\WebStatsService::collectFromLog();
if ($statsResult['ok'] && $statsResult['lines'] > 0) {
    echo "Stats: {$statsResult['lines']} entries for {$statsResult['accounts']} account(s).\n";
}
