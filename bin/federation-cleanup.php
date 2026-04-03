#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — Federation Migration Cleanup
 *
 * Runs periodically via cron to clean up files from completed migrations.
 * After a migration completes, origin files are kept for 48h as safety net.
 * This worker deletes them once the retention period has elapsed.
 *
 * Cron: 0 * * * * /usr/bin/php /opt/musedock-panel/bin/federation-cleanup.php
 */

define('PANEL_ROOT', dirname(__DIR__));

spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

use MuseDockPanel\Database;
use MuseDockPanel\Services\FederationMigrationService;

$now = date('Y-m-d H:i:s');

// Find completed migrations with elapsed cleanup time
$migrations = Database::fetchAll("
    SELECT * FROM hosting_migrations
    WHERE status = 'completed'
      AND metadata::text LIKE '%files_cleanup_after%'
");

$cleaned = 0;
foreach ($migrations as $m) {
    $metadata = json_decode($m['metadata'] ?? '{}', true);
    $cleanupAfter = $metadata['files_cleanup_after'] ?? null;

    if (!$cleanupAfter || $cleanupAfter > $now) {
        continue; // Not yet time
    }

    // Already cleaned?
    if (!empty($metadata['files_cleaned'])) {
        continue;
    }

    $account = Database::fetchOne('SELECT * FROM hosting_accounts WHERE id = :id', ['id' => $m['account_id']]);
    if (!$account) continue;

    $homeDir = $account['home_dir'];
    $username = $account['username'];
    $migrationId = $m['migration_id'];

    // Safety checks before deletion
    if (empty($homeDir) || $homeDir === '/' || !str_starts_with($homeDir, '/var/www/vhosts/')) {
        FederationMigrationService::log($migrationId, 'cleanup', 'error', "Refusing to delete suspicious path: {$homeDir}");
        continue;
    }

    if ($account['status'] !== 'migrated_away') {
        FederationMigrationService::log($migrationId, 'cleanup', 'warn', "Account not in migrated_away status — skipping cleanup");
        continue;
    }

    FederationMigrationService::log($migrationId, 'cleanup', 'info', "Cleaning up origin files: {$homeDir}");

    // Remove home directory
    if (is_dir($homeDir)) {
        exec('rm -rf ' . escapeshellarg($homeDir) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            FederationMigrationService::log($migrationId, 'cleanup', 'error', "Failed to delete {$homeDir}: " . implode("\n", $out));
            continue;
        }
    }

    // Remove system user
    exec('userdel ' . escapeshellarg($username) . ' 2>&1', $out, $rc);

    // Remove FPM pool files
    foreach (['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'] as $v) {
        $poolFile = "/etc/php/{$v}/fpm/pool.d/{$username}.conf";
        if (file_exists($poolFile)) {
            @unlink($poolFile);
            exec("systemctl reload php{$v}-fpm 2>&1");
        }
    }

    // Remove FPM backup if still around
    $poolBackup = $metadata['fpm_pool_backup'] ?? '';
    if ($poolBackup && file_exists($poolBackup)) {
        @unlink($poolBackup);
    }

    // Mark as cleaned in metadata
    $metadata['files_cleaned'] = true;
    $metadata['files_cleaned_at'] = $now;
    Database::update('hosting_migrations', [
        'metadata' => json_encode($metadata),
        'updated_at' => $now,
    ], 'migration_id = :mid', ['mid' => $migrationId]);

    FederationMigrationService::log($migrationId, 'cleanup', 'info', "Origin files cleaned successfully");
    $cleaned++;
}

if ($cleaned > 0) {
    echo "Cleaned {$cleaned} completed migration(s).\n";
}
