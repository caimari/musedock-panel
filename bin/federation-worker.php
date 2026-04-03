#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — Federation Migration Worker
 *
 * Runs periodically via cron. Ensures the state machine is reentrant:
 * - Resumes 'running' migrations that stalled (e.g., after server restart)
 * - Releases stale step locks (older than 10 minutes)
 * - Completes migrations waiting for grace period to elapse
 * - Checks pending migrations that haven't started
 *
 * Cron: * * * * * /usr/bin/php /opt/musedock-panel/bin/federation-worker.php
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

// ── 1. Release stale step locks ──────────────────────────────
// If a lock is older than 10 minutes, the process that held it is dead.
$staleLockThreshold = 600; // 10 minutes
$lockedMigrations = Database::fetchAll("
    SELECT * FROM hosting_migrations
    WHERE step_lock IS NOT NULL AND step_lock != ''
");

foreach ($lockedMigrations as $m) {
    // Lock format: "migration_id:step:timestamp"
    $parts = explode(':', $m['step_lock']);
    $lockTime = (int)($parts[2] ?? 0);
    if ($lockTime > 0 && (time() - $lockTime) > $staleLockThreshold) {
        Database::query(
            "UPDATE hosting_migrations SET step_lock = NULL, updated_at = NOW() WHERE id = :id",
            ['id' => $m['id']]
        );
        FederationMigrationService::log(
            $m['migration_id'], $m['current_step'], 'warn',
            'Stale step lock released by worker (process died?)'
        );
    }
}

// ── 2. Resume stalled 'running' migrations ───────────────────
// A migration in 'running' status with no step lock means it was
// interrupted (server restart, PHP timeout, etc.). Safe to resume.
$stalledMigrations = Database::fetchAll("
    SELECT * FROM hosting_migrations
    WHERE status = 'running'
      AND (step_lock IS NULL OR step_lock = '')
    ORDER BY updated_at ASC
    LIMIT 5
");

foreach ($stalledMigrations as $m) {
    $migrationId = $m['migration_id'];
    $updatedAt = strtotime($m['updated_at']);

    // Only resume if stalled for > 2 minutes (avoid racing with active execution)
    if ((time() - $updatedAt) < 120) {
        continue;
    }

    FederationMigrationService::log($migrationId, $m['current_step'], 'info',
        'Worker resuming stalled migration');

    // Execute the current step (the step is idempotent — safe to re-execute)
    $result = FederationMigrationService::executeNextStep($migrationId);

    if ($result['ok']) {
        // If successful, continue running remaining steps
        FederationMigrationService::runAll($migrationId);
    }
}

// ── 3. Complete migrations waiting for grace period ──────────
// Migrations at step 'complete' waiting for grace period to elapse
$graceMigrations = Database::fetchAll("
    SELECT * FROM hosting_migrations
    WHERE status = 'running'
      AND current_step = 'complete'
");

foreach ($graceMigrations as $m) {
    $metadata = json_decode($m['step_results'] ?? '{}', true);
    $switchData = $metadata['switch_dns']['data'] ?? $metadata['switch_dns'] ?? [];
    $graceStart = $switchData['grace_start'] ?? null;
    $graceMinutes = $m['grace_period_minutes'] ?? 60;

    if ($graceStart) {
        $graceEnd = strtotime($graceStart) + ($graceMinutes * 60);
        if (time() >= $graceEnd) {
            FederationMigrationService::log($m['migration_id'], 'complete', 'info',
                'Grace period elapsed — worker completing migration');
            FederationMigrationService::executeNextStep($m['migration_id']);
        }
    }
}
