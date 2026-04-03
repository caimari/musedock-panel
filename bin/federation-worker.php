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

// Global flock — prevent concurrent execution
$lockFile = PANEL_ROOT . '/storage/federation-worker.lock';
$fp = fopen($lockFile, 'w');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    exit(0); // Another instance is running
}
fwrite($fp, (string)getmypid());

use MuseDockPanel\Database;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\FederationMigrationService;
use MuseDockPanel\Services\FederationService;
use MuseDockPanel\Services\FileSyncService;

// ── 0. Auto-expire stale sync pauses ─────────────────────────
// If a domain sync was paused and the expiry time has passed, auto-resume.
// This prevents permanent sync blockage if the system dies between pause and resume.
try {
    $allSettings = Settings::getAll();
    foreach ($allSettings as $key => $value) {
        if (str_starts_with($key, 'federation_sync_paused_') && str_ends_with($key, '_expires') && $value) {
            $expiresAt = (int)$value;
            if ($expiresAt > 0 && time() > $expiresAt) {
                // Extract domain from key: federation_sync_paused_{domain}_expires
                $domain = substr($key, strlen('federation_sync_paused_'), -strlen('_expires'));
                if ($domain && Settings::get("federation_sync_paused_{$domain}", '0') === '1') {
                    // Auto-resume: clear flag, remove exclusion, reload lsyncd
                    Settings::set("federation_sync_paused_{$domain}", '0');
                    Settings::set("federation_sync_paused_{$domain}_expires", '');

                    $exclusionsList = Settings::get('filesync_exclusions_list', '');
                    $exclusions = array_filter(array_map('trim', explode("\n", $exclusionsList)));
                    $domainPath = "/var/www/vhosts/{$domain}";
                    $exclusions = array_filter($exclusions, fn($e) => $e !== $domainPath);
                    Settings::set('filesync_exclusions_list', implode("\n", $exclusions));

                    exec('systemctl is-active lsyncd 2>/dev/null', $out, $rc);
                    if ($rc === 0) {
                        FileSyncService::generateLsyncdConfig();
                        exec('systemctl reload lsyncd 2>&1');
                    }

                    FederationMigrationService::log('', 'watchdog', 'warn',
                        "Auto-resumed expired sync pause for: {$domain} (was paused > 2h)");
                }
            }
        }
    }
} catch (\Throwable $e) {
    // Settings table may not exist yet
}

// ── DB health check ──────────────────────────────────────────
try {
    Database::connect();
} catch (\Throwable $e) {
    // DB down — nothing we can do. Exit silently, try again next minute.
    exit(0);
}

// ── 1. Release stale step locks ──────────────────────────────
// Threshold must be >= longest step timeout (sync_files = 30min).
// Use 35 minutes to avoid releasing active locks.
$staleLockThreshold = 2100; // 35 minutes
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

// ── 4. DNS monitoring for manual DNS migrations ──────────────
// Check if DNS still points to origin after grace period started.
// Notify admin if DNS hasn't been updated.
$dnsPendingMigrations = Database::fetchAll("
    SELECT m.*, a.domain FROM hosting_migrations m
    LEFT JOIN hosting_accounts a ON a.id = m.account_id
    WHERE m.status = 'running'
      AND m.current_step IN ('switch_dns', 'complete')
      AND m.metadata::text LIKE '%dns_manual_required%'
");

foreach ($dnsPendingMigrations as $m) {
    $mMeta = json_decode($m['metadata'] ?? '{}', true);
    if (!($mMeta['dns_manual_required'] ?? false)) continue;
    if ($mMeta['dns_warning_sent'] ?? false) continue; // Already notified

    $domain = $m['domain'] ?? '';
    $targetIp = $mMeta['dns_target_ip'] ?? '';
    if (empty($domain) || empty($targetIp)) continue;

    // Check current DNS resolution
    $currentIps = gethostbynamel($domain) ?: [];

    if (!in_array($targetIp, $currentIps)) {
        // DNS still points to origin — warn admin
        $stepResults = json_decode($m['step_results'] ?? '{}', true);
        $graceStart = $stepResults['switch_dns']['data']['grace_start'] ?? null;
        $elapsedMinutes = $graceStart ? (int)((time() - strtotime($graceStart)) / 60) : 0;

        if ($elapsedMinutes >= 30) {
            // 30+ minutes without DNS update — send notification
            FederationMigrationService::log($m['migration_id'], 'dns_monitor', 'warn',
                "DNS for {$domain} still points to origin after {$elapsedMinutes}m. Target IP: {$targetIp}. Current: " . implode(', ', $currentIps));

            // Try to send notification via NotificationService
            try {
                \MuseDockPanel\Services\NotificationService::send(
                    'federation_dns_pending',
                    "Migration DNS pendiente: {$domain}",
                    "El hosting {$domain} se migro hace {$elapsedMinutes} minutos pero el DNS sigue apuntando al origen.\n" .
                    "Actualiza el registro A a: {$targetIp}\n" .
                    "Migration ID: {$m['migration_id']}"
                );
            } catch (\Throwable) {}

            // Mark as notified (don't spam)
            $mMeta['dns_warning_sent'] = true;
            Database::update('hosting_migrations', [
                'metadata' => json_encode($mMeta),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'migration_id = :mid', ['mid' => $m['migration_id']]);
        }
    }
}
