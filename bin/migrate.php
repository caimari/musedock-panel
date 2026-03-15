#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — Migration Runner
 *
 * Usage:
 *   php bin/migrate.php              Run all pending migrations
 *   php bin/migrate.php --status     Show migration status
 *   php bin/migrate.php --pending    List pending migrations only
 */

define('PANEL_ROOT', dirname(__DIR__));

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

// Load .env
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

// Colors
$green  = "\033[0;32m";
$red    = "\033[0;31m";
$yellow = "\033[1;33m";
$cyan   = "\033[0;36m";
$bold   = "\033[1m";
$nc     = "\033[0m";

$arg = $argv[1] ?? '';

use MuseDockPanel\Services\MigrationService;

try {
    MigrationService::ensureTable();
} catch (\Throwable $e) {
    echo "{$red}Database connection failed:{$nc} {$e->getMessage()}\n";
    exit(1);
}

// --status: show all migrations with their status
if ($arg === '--status') {
    $executed = MigrationService::getExecuted();
    $available = MigrationService::getAvailable();

    echo "\n{$bold}Migration Status{$nc}\n\n";

    if (empty($available)) {
        echo "  No migration files found.\n\n";
        exit(0);
    }

    foreach ($available as $file) {
        $name = basename($file, '.php');
        $isExecuted = in_array($name, $executed, true);
        $icon = $isExecuted ? "{$green}✓{$nc}" : "{$yellow}○{$nc}";
        $status = $isExecuted ? "{$green}executed{$nc}" : "{$yellow}pending{$nc}";
        echo "  {$icon} {$name}  [{$status}]\n";
    }

    // Check for orphan records (executed but file no longer exists)
    $fileNames = array_map(fn($f) => basename($f, '.php'), $available);
    foreach ($executed as $name) {
        if (!in_array($name, $fileNames, true)) {
            echo "  {$red}?{$nc} {$name}  [{$red}file missing{$nc}]\n";
        }
    }

    echo "\n";
    exit(0);
}

// --pending: list only pending
if ($arg === '--pending') {
    $pending = MigrationService::getPending();

    if (empty($pending)) {
        echo "{$green}No pending migrations.{$nc}\n";
        exit(0);
    }

    echo "\n{$bold}Pending migrations:{$nc}\n\n";
    foreach ($pending as $file) {
        echo "  {$yellow}○{$nc} " . basename($file, '.php') . "\n";
    }
    echo "\n  Run {$cyan}php bin/migrate.php{$nc} to execute them.\n\n";
    exit(0);
}

// Default: run pending migrations
$pending = MigrationService::getPending();

if (empty($pending)) {
    echo "{$green}Nothing to migrate. All migrations are up to date.{$nc}\n";
    exit(0);
}

echo "\n{$bold}Running " . count($pending) . " pending migration(s)...{$nc}\n\n";

$results = MigrationService::runPending();
$failed = 0;

foreach ($results as $r) {
    if ($r['ok']) {
        echo "  {$green}✓{$nc} {$r['name']}\n";
    } else {
        echo "  {$red}✗{$nc} {$r['name']} — {$r['error']}\n";
        $failed++;
    }
}

echo "\n";
if ($failed > 0) {
    echo "{$red}{$failed} migration(s) failed.{$nc}\n";
    exit(1);
} else {
    echo "{$green}All migrations completed successfully.{$nc}\n";
}
