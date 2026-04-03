<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Auth;

/**
 * FederationMigrationService — Stateful orchestrator for hosting migrations between federated masters.
 *
 * Golden Rules:
 *  1. Origin always orchestrates — source of truth until step COMPLETE
 *  2. Nothing is official until COMPLETE — hosting belongs to origin
 *  3. If it fails, origin stays intact — rollback cleans destination only
 *
 * Invariants:
 *  - Each step acquires a lock (migration_id + step) to prevent concurrent execution
 *  - Each step persists its result (success/fail + metadata) in step_results
 *  - All steps are idempotent
 *  - migration_id in all log entries
 *  - SSH for data, API for coordination
 */
class FederationMigrationService
{
    // ═══════════════════════════════════════════════════════════════
    // Migration states
    // ═══════════════════════════════════════════════════════════════

    public const STATUS_PENDING       = 'pending';
    public const STATUS_RUNNING       = 'running';
    public const STATUS_PAUSED        = 'paused';
    public const STATUS_COMPLETED     = 'completed';
    public const STATUS_FAILED        = 'failed';
    public const STATUS_ROLLED_BACK   = 'rolled_back';
    public const STATUS_CANCELLED     = 'cancelled';

    // ═══════════════════════════════════════════════════════════════
    // Migration steps (ordered)
    // ═══════════════════════════════════════════════════════════════

    public const STEP_HEALTH_CHECK  = 'health_check';
    public const STEP_LOCK          = 'lock';
    public const STEP_PREPARE       = 'prepare';
    public const STEP_SYNC_FILES    = 'sync_files';
    public const STEP_SYNC_DB       = 'sync_db';
    public const STEP_FREEZE        = 'freeze';
    public const STEP_FINAL_SYNC    = 'final_sync';
    public const STEP_FINALIZE      = 'finalize';
    public const STEP_VERIFY        = 'verify';
    public const STEP_SWITCH_DNS    = 'switch_dns';
    public const STEP_COMPLETE      = 'complete';

    /** Ordered list of all steps */
    public const STEPS = [
        self::STEP_HEALTH_CHECK,
        self::STEP_LOCK,
        self::STEP_PREPARE,
        self::STEP_SYNC_FILES,
        self::STEP_SYNC_DB,
        self::STEP_FREEZE,
        self::STEP_FINAL_SYNC,
        self::STEP_FINALIZE,
        self::STEP_VERIFY,
        self::STEP_SWITCH_DNS,
        self::STEP_COMPLETE,
    ];

    /** Steps skipped in clone mode */
    public const CLONE_SKIP_STEPS = [
        self::STEP_SWITCH_DNS,
        self::STEP_COMPLETE,
    ];

    /** Step timeouts in seconds */
    public const STEP_TIMEOUTS = [
        self::STEP_HEALTH_CHECK => 30,
        self::STEP_LOCK         => 10,
        self::STEP_PREPARE      => 60,
        self::STEP_SYNC_FILES   => 1800,  // 30 min
        self::STEP_SYNC_DB      => 600,   // 10 min
        self::STEP_FREEZE       => 30,
        self::STEP_FINAL_SYNC   => 300,   // 5 min
        self::STEP_FINALIZE     => 120,
        self::STEP_VERIFY       => 60,
        self::STEP_SWITCH_DNS   => 60,
        self::STEP_COMPLETE     => 60,
    ];

    /** Max retries per step before pausing */
    public const MAX_RETRIES = 3;

    /** Max seconds to wait for FPM connections to drain before force-kill */
    public const FPM_DRAIN_TIMEOUT = 30;

    // ═══════════════════════════════════════════════════════════════
    // Mode constants
    // ═══════════════════════════════════════════════════════════════

    public const MODE_MIGRATE     = 'migrate';
    public const MODE_CLONE       = 'clone';
    public const MODE_UPDATE_CLONE = 'update_clone';
    public const MODE_PROMOTE     = 'promote';

    // ═══════════════════════════════════════════════════════════════
    // CRUD
    // ═══════════════════════════════════════════════════════════════

    /**
     * Create a new migration.
     */
    public static function create(int $accountId, int $peerId, string $mode = self::MODE_MIGRATE, bool $dryRun = false, int $gracePeriodMinutes = 60, string $dnsMode = 'auto', ?array $includeSubdomains = null, ?array $includeAliases = null): array
    {
        $migrationId = self::generateMigrationId();

        // Check no active migration for this account
        $existing = Database::fetchOne(
            "SELECT id FROM hosting_migrations WHERE account_id = :aid AND status IN ('pending', 'running', 'paused')",
            ['aid' => $accountId]
        );
        if ($existing) {
            return ['ok' => false, 'error' => 'Account already has an active migration'];
        }

        $user = Auth::user();
        $id = Database::insert('hosting_migrations', [
            'migration_id'         => $migrationId,
            'account_id'           => $accountId,
            'peer_id'              => $peerId,
            'mode'                 => $mode,
            'direction'            => 'outgoing',
            'status'               => self::STATUS_PENDING,
            'current_step'         => self::STEP_HEALTH_CHECK,
            'dry_run'              => $dryRun ? 'true' : 'false',
            'grace_period_minutes' => $gracePeriodMinutes,
            'metadata'             => json_encode(array_filter([
                'dns_mode' => $dnsMode,
                'include_subdomains' => $includeSubdomains, // null = all, array of IDs = selected
                'include_aliases' => $includeAliases,       // null = all, array of IDs = selected
            ], fn($v) => $v !== null)),
            'created_by'           => $user['id'] ?? null,
        ]);

        self::log($migrationId, self::STEP_HEALTH_CHECK, 'info', 'Migration created', [
            'mode' => $mode,
            'dry_run' => $dryRun,
            'peer_id' => $peerId,
            'account_id' => $accountId,
        ]);

        return ['ok' => true, 'id' => $id, 'migration_id' => $migrationId];
    }

    /**
     * Get migration by ID.
     */
    public static function get(int $id): ?array
    {
        $row = Database::fetchOne('SELECT * FROM hosting_migrations WHERE id = :id', ['id' => $id]);
        if ($row) {
            $row['progress'] = json_decode($row['progress'] ?? '{}', true);
            $row['step_results'] = json_decode($row['step_results'] ?? '{}', true);
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
        }
        return $row;
    }

    /**
     * Get migration by migration_id (UUID).
     */
    public static function getByMigrationId(string $migrationId): ?array
    {
        $row = Database::fetchOne('SELECT * FROM hosting_migrations WHERE migration_id = :mid', ['mid' => $migrationId]);
        if ($row) {
            $row['progress'] = json_decode($row['progress'] ?? '{}', true);
            $row['step_results'] = json_decode($row['step_results'] ?? '{}', true);
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
        }
        return $row;
    }

    /**
     * List migrations with optional filters.
     */
    public static function list(array $filters = []): array
    {
        $where = '1=1';
        $params = [];

        if (!empty($filters['account_id'])) {
            $where .= ' AND m.account_id = :aid';
            $params['aid'] = $filters['account_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND m.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['peer_id'])) {
            $where .= ' AND m.peer_id = :pid';
            $params['pid'] = $filters['peer_id'];
        }

        $rows = Database::fetchAll("
            SELECT m.*, a.domain, a.username, p.name as peer_name
            FROM hosting_migrations m
            LEFT JOIN hosting_accounts a ON a.id = m.account_id
            LEFT JOIN federation_peers p ON p.id = m.peer_id
            WHERE {$where}
            ORDER BY m.created_at DESC
            LIMIT 100
        ", $params);

        foreach ($rows as &$row) {
            $row['progress'] = json_decode($row['progress'] ?? '{}', true);
            $row['step_results'] = json_decode($row['step_results'] ?? '{}', true);
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
        }

        return $rows;
    }

    // ═══════════════════════════════════════════════════════════════
    // State machine — step execution
    // ═══════════════════════════════════════════════════════════════

    /**
     * Execute the next step in the migration.
     * Returns the result of the step execution.
     */
    public static function executeNextStep(string $migrationId): array
    {
        $migration = self::getByMigrationId($migrationId);
        if (!$migration) {
            return ['ok' => false, 'error' => 'Migration not found'];
        }

        if ($migration['status'] === self::STATUS_COMPLETED) {
            return ['ok' => false, 'error' => 'Migration already completed'];
        }
        if ($migration['status'] === self::STATUS_CANCELLED) {
            return ['ok' => false, 'error' => 'Migration was cancelled'];
        }
        if ($migration['status'] === self::STATUS_ROLLED_BACK) {
            return ['ok' => false, 'error' => 'Migration was rolled back'];
        }

        $step = $migration['current_step'];

        // In clone mode, skip DNS switch + complete
        if ($migration['mode'] === self::MODE_CLONE && in_array($step, self::CLONE_SKIP_STEPS)) {
            self::updateStatus($migrationId, self::STATUS_COMPLETED, [
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            self::log($migrationId, $step, 'info', 'Clone mode — migration completed (DNS switch + cleanup skipped)');
            return ['ok' => true, 'step' => $step, 'status' => 'completed', 'message' => 'Clone completed'];
        }

        // Acquire step lock
        if (!self::acquireStepLock($migrationId, $step)) {
            return ['ok' => false, 'error' => "Step {$step} is already locked (concurrent execution prevented)"];
        }

        // Mark as running
        if ($migration['status'] !== self::STATUS_RUNNING) {
            self::updateStatus($migrationId, self::STATUS_RUNNING, [
                'started_at' => $migration['started_at'] ?: date('Y-m-d H:i:s'),
            ]);
        }

        self::log($migrationId, $step, 'info', "Executing step: {$step}");
        $stepStartTime = microtime(true);

        try {
            // Execute the step
            $result = self::executeStep($migrationId, $migration, $step);

            // Add timing metrics to result
            $stepDuration = round(microtime(true) - $stepStartTime, 2);
            $result['metrics'] = [
                'duration_seconds' => $stepDuration,
                'started_at' => date('Y-m-d H:i:s', (int)$stepStartTime),
                'finished_at' => date('Y-m-d H:i:s'),
            ];

            // Persist step result (with metrics)
            self::saveStepResult($migrationId, $step, $result);

            if ($result['ok']) {
                self::log($migrationId, $step, 'info', "Step completed: {$step} ({$stepDuration}s)", $result['data'] ?? []);

                // Advance to next step
                $nextStep = self::getNextStep($step, $migration['mode']);
                if ($nextStep) {
                    self::updateCurrentStep($migrationId, $nextStep);
                } else {
                    // All steps done
                    self::updateStatus($migrationId, self::STATUS_COMPLETED, [
                        'completed_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            } else {
                self::log($migrationId, $step, 'error', "Step failed: {$step}", [
                    'error' => $result['error'] ?? 'Unknown error',
                ]);

                // Re-read migration to get fresh retry count (avoid stale state)
                $freshMigration = self::getByMigrationId($migrationId);
                $retries = $freshMigration['step_results'][$step]['retries'] ?? 0;
                if ($retries >= self::MAX_RETRIES) {
                    // Exhausted retries → pause (admin can resume or cancel)
                    self::updateStatus($migrationId, self::STATUS_PAUSED, [
                        'error_message' => "Step {$step} failed after " . self::MAX_RETRIES . " retries: " . ($result['error'] ?? ''),
                    ]);
                    self::log($migrationId, $step, 'warn', 'Max retries exhausted — migration paused');
                } else {
                    // Increment retry counter but keep running
                    self::incrementRetry($migrationId, $step, $retries + 1);
                }
            }
        } catch (\Throwable $e) {
            self::log($migrationId, $step, 'error', "Step exception: {$e->getMessage()}");
            self::saveStepResult($migrationId, $step, ['ok' => false, 'error' => $e->getMessage()]);
            self::updateStatus($migrationId, self::STATUS_PAUSED, [
                'error_message' => "Exception in step {$step}: {$e->getMessage()}",
            ]);
        } finally {
            self::releaseStepLock($migrationId);
        }

        return self::getByMigrationId($migrationId) ? [
            'ok' => true,
            'step' => $step,
            'migration' => self::getByMigrationId($migrationId),
        ] : ['ok' => false, 'error' => 'Migration not found after step execution'];
    }

    /**
     * Run all remaining steps sequentially until completion, pause, or failure.
     */
    public static function runAll(string $migrationId): array
    {
        $maxIterations = count(self::STEPS) + 1; // safety limit
        $iteration = 0;

        while ($iteration < $maxIterations) {
            $iteration++;
            $migration = self::getByMigrationId($migrationId);
            if (!$migration) {
                return ['ok' => false, 'error' => 'Migration not found'];
            }

            // Stop conditions
            if (in_array($migration['status'], [self::STATUS_COMPLETED, self::STATUS_PAUSED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_ROLLED_BACK])) {
                return ['ok' => true, 'status' => $migration['status'], 'migration' => $migration];
            }

            // Grace period check — if in switch_dns and grace period hasn't elapsed, don't auto-advance
            if ($migration['current_step'] === self::STEP_COMPLETE && $migration['status'] === self::STATUS_RUNNING) {
                $switchResult = $migration['step_results'][self::STEP_SWITCH_DNS] ?? [];
                $graceStart = $switchResult['grace_start'] ?? null;
                if ($graceStart) {
                    $graceEnd = strtotime($graceStart) + ($migration['grace_period_minutes'] * 60);
                    if (time() < $graceEnd) {
                        return [
                            'ok' => true,
                            'status' => 'grace_period',
                            'grace_remaining' => $graceEnd - time(),
                            'migration' => $migration,
                        ];
                    }
                }
            }

            $result = self::executeNextStep($migrationId);
            if (!$result['ok']) {
                return $result;
            }
        }

        return ['ok' => false, 'error' => 'Max iterations reached'];
    }

    // ═══════════════════════════════════════════════════════════════
    // Step execution dispatcher
    // ═══════════════════════════════════════════════════════════════

    /**
     * Dispatch step execution to the appropriate handler.
     */
    private static function executeStep(string $migrationId, array $migration, string $step): array
    {
        $account = Database::fetchOne('SELECT * FROM hosting_accounts WHERE id = :id', ['id' => $migration['account_id']]);
        if (!$account) {
            return ['ok' => false, 'error' => 'Hosting account not found'];
        }

        $peer = FederationService::getPeer($migration['peer_id']);
        if (!$peer) {
            return ['ok' => false, 'error' => 'Federation peer not found'];
        }

        // Dry-run mode: validate only, no real execution
        if ($migration['dry_run']) {
            return self::executeDryRunStep($migrationId, $migration, $step, $account, $peer);
        }

        return match ($step) {
            self::STEP_HEALTH_CHECK => self::stepHealthCheck($migrationId, $account, $peer),
            self::STEP_LOCK         => self::stepLock($migrationId, $account, $peer),
            self::STEP_PREPARE      => self::stepPrepare($migrationId, $migration, $account, $peer),
            self::STEP_SYNC_FILES   => self::stepSyncFiles($migrationId, $account, $peer),
            self::STEP_SYNC_DB      => self::stepSyncDb($migrationId, $account, $peer),
            self::STEP_FREEZE       => self::stepFreeze($migrationId, $account, $peer),
            self::STEP_FINAL_SYNC   => self::stepFinalSync($migrationId, $account, $peer),
            self::STEP_FINALIZE     => self::stepFinalize($migrationId, $migration, $account, $peer),
            self::STEP_VERIFY       => self::stepVerify($migrationId, $account, $peer),
            self::STEP_SWITCH_DNS   => self::stepSwitchDns($migrationId, $migration, $account, $peer),
            self::STEP_COMPLETE     => self::stepComplete($migrationId, $migration, $account, $peer),
            default                 => ['ok' => false, 'error' => "Unknown step: {$step}"],
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // Step implementations
    // ═══════════════════════════════════════════════════════════════

    /**
     * Step 0: HEALTH CHECK — Verify destination reachable, SSH working, disk space available.
     */
    private static function stepHealthCheck(string $migrationId, array $account, array $peer): array
    {
        $checks = [];

        // 1. Check API reachable (tests firewall on port 8444)
        $healthResult = FederationService::callPeerApi($peer, 'GET', '/api/federation/health');
        if (!$healthResult['ok']) {
            $error = $healthResult['error'] ?? '';
            // Provide specific firewall hint
            if (str_contains($error, 'Connection refused') || str_contains($error, 'Connection timed out') || str_contains($error, 'couldn\'t connect')) {
                return ['ok' => false, 'error' => "API unreachable — FIREWALL? Check that port 8444 is open on destination. Error: {$error}"];
            }
            return ['ok' => false, 'error' => 'Destination panel unreachable: ' . $error];
        }
        $checks['api_reachable'] = true;
        $checks['dest_panel_version'] = $healthResult['data']['panel_version'] ?? 'unknown';

        // 2. Check SSH connectivity (tests firewall on SSH port)
        $sshResult = FederationService::testSshConnection($peer);
        if (!$sshResult['ok']) {
            $error = $sshResult['error'] ?? '';
            $sshPort = $peer['ssh_port'] ?? 22;
            if (str_contains($error, 'Connection refused') || str_contains($error, 'Connection timed out') || str_contains($error, 'No route to host')) {
                return ['ok' => false, 'error' => "SSH unreachable — FIREWALL? Check that port {$sshPort} is open on destination. Error: {$error}"];
            }
            return ['ok' => false, 'error' => 'SSH connection failed: ' . $error];
        }
        $checks['ssh_ok'] = true;

        // 3. Check disk space on destination
        $spaceResult = FederationService::callPeerApi($peer, 'POST', '/api/federation/check-space', [
            'required_mb' => $account['disk_used_mb'] ?? 500,
        ]);
        if (!$spaceResult['ok']) {
            return ['ok' => false, 'error' => 'Disk space check failed: ' . ($spaceResult['error'] ?? '')];
        }
        $checks['disk_available_mb'] = $spaceResult['data']['available_mb'] ?? 0;

        // 4. Verify rsync is available on destination
        $sshTarget = FederationService::getSshTarget($peer);
        $cmd = sprintf(
            'ssh -p %d -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=5 %s "which rsync" 2>&1',
            $peer['ssh_port'] ?? 22,
            escapeshellarg($peer['ssh_key_path']),
            escapeshellarg($sshTarget)
        );
        exec($cmd, $out, $rc);
        $checks['rsync_available'] = ($rc === 0);
        if (!$checks['rsync_available']) {
            self::log($migrationId, self::STEP_HEALTH_CHECK, 'warn', 'rsync not found on destination — install it before proceeding');
        }

        return ['ok' => true, 'data' => $checks];
    }

    /**
     * Step 1: LOCK — Block hosting in panel (not editable, shows lock icon).
     */
    private static function stepLock(string $migrationId, array $account, array $peer): array
    {
        // Update account status to 'migrating' (prevents edits in panel)
        Database::update('hosting_accounts', [
            'status' => 'migrating',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $account['id']]);

        self::log($migrationId, self::STEP_LOCK, 'info', "Account locked for migration: {$account['domain']}");

        return ['ok' => true, 'data' => ['domain' => $account['domain']]];
    }

    /**
     * Step 2: PREPARE — API to destination: validate UID, domain free, create tentative user + DB.
     */
    private static function stepPrepare(string $migrationId, array $migration, array $account, array $peer): array
    {
        // Collect all databases for this account
        $databases = Database::fetchAll(
            'SELECT * FROM hosting_databases WHERE account_id = :aid',
            ['aid' => $account['id']]
        );

        // Collect subdomains (filtered by selection if specified)
        $subdomains = Database::fetchAll(
            'SELECT * FROM hosting_subdomains WHERE account_id = :aid',
            ['aid' => $account['id']]
        );
        $selectedSubIds = $migration['metadata']['include_subdomains'] ?? null;
        if (is_array($selectedSubIds)) {
            $selectedSubIds = array_map('intval', $selectedSubIds);
            $subdomains = array_values(array_filter($subdomains, fn($s) => in_array((int)$s['id'], $selectedSubIds)));
        }

        // Collect domain aliases (filtered by selection if specified)
        $aliases = Database::fetchAll(
            'SELECT * FROM hosting_domain_aliases WHERE account_id = :aid',
            ['aid' => $account['id']]
        );
        $selectedAliasIds = $migration['metadata']['include_aliases'] ?? null;
        if (is_array($selectedAliasIds)) {
            $selectedAliasIds = array_map('intval', $selectedAliasIds);
            $aliases = array_values(array_filter($aliases, fn($a) => in_array((int)$a['id'], $selectedAliasIds)));
        }

        self::log($migrationId, self::STEP_PREPARE, 'info', 'Including ' . count($subdomains) . ' subdomains, ' . count($aliases) . ' aliases');

        // Pause slave sync on destination to avoid replicating partial data
        FederationService::callPeerApi($peer, 'POST', '/api/federation/pause-sync', [
            'migration_id' => $migrationId,
            'domain'       => $account['domain'],
            'action'       => 'pause',
        ]);
        self::log($migrationId, self::STEP_PREPARE, 'info', 'Slave sync paused on destination');

        $result = FederationService::callPeerApi($peer, 'POST', '/api/federation/prepare', [
            'migration_id'  => $migrationId,
            'domain'        => $account['domain'],
            'username'      => $account['username'],
            'system_uid'    => $account['system_uid'],
            'home_dir'      => $account['home_dir'],
            'document_root' => $account['document_root'],
            'php_version'   => $account['php_version'],
            'disk_quota_mb' => $account['disk_quota_mb'],
            'shell'         => $account['shell'] ?? '/usr/sbin/nologin',
            'databases'     => $databases,
            'subdomains'    => $subdomains,
            'aliases'       => $aliases,
        ]);

        if (!$result['ok']) {
            return ['ok' => false, 'error' => 'Prepare failed: ' . ($result['error'] ?? '')];
        }

        // Store destination info (UID assigned, etc.) in metadata
        self::updateMetadata($migrationId, [
            'dest_uid' => $result['data']['uid'] ?? null,
            'dest_username' => $result['data']['username'] ?? $account['username'],
        ]);

        return ['ok' => true, 'data' => $result['data'] ?? []];
    }

    /**
     * Step 3: SYNC FILES — rsync via SSH (full copy, --partial for resume).
     */
    private static function stepSyncFiles(string $migrationId, array $account, array $peer): array
    {
        $sshTarget = FederationService::getSshTarget($peer);
        $homeDir = rtrim($account['home_dir'], '/') . '/';

        // rsync with --partial for resume, --delete for exact mirror
        $cmd = sprintf(
            'rsync -azP --partial --delete -e "ssh -p %d -i %s -o StrictHostKeyChecking=no" %s %s:%s 2>&1',
            $peer['ssh_port'] ?? 22,
            escapeshellarg($peer['ssh_key_path']),
            escapeshellarg($homeDir),
            escapeshellarg($sshTarget),
            escapeshellarg($homeDir)
        );

        self::log($migrationId, self::STEP_SYNC_FILES, 'info', 'Starting rsync', ['cmd' => $cmd]);

        $rsyncStart = microtime(true);
        $outputLines = [];
        $returnCode = 0;
        exec($cmd, $outputLines, $returnCode);
        $output = implode("\n", $outputLines);
        $rsyncDuration = round(microtime(true) - $rsyncStart, 2);

        if ($returnCode !== 0 && $returnCode !== 24) { // 24 = vanished files (OK)
            return ['ok' => false, 'error' => "rsync failed (exit code {$returnCode})", 'data' => ['output' => substr($output, -2000)]];
        }

        // Parse rsync stats from output (last lines contain transfer summary)
        $bytesTransferred = 0;
        $filesTransferred = 0;
        foreach ($outputLines as $line) {
            if (preg_match('/sent ([\d,]+) bytes/', $line, $m)) {
                $bytesTransferred = (int)str_replace(',', '', $m[1]);
            }
            if (preg_match('/Number of files transferred: ([\d,]+)/', $line, $m)) {
                $filesTransferred = (int)str_replace(',', '', $m[1]);
            }
            // rsync 3.x format
            if (preg_match('/Number of regular files transferred: ([\d,]+)/', $line, $m)) {
                $filesTransferred = (int)str_replace(',', '', $m[1]);
            }
        }

        $speedMbps = $rsyncDuration > 0 ? round(($bytesTransferred / 1048576) / $rsyncDuration, 2) : 0;

        return ['ok' => true, 'data' => [
            'bytes_transferred' => $bytesTransferred,
            'files_transferred' => $filesTransferred,
            'duration_seconds' => $rsyncDuration,
            'speed_mbps' => $speedMbps,
        ]];
    }

    /**
     * Step 4: SYNC DB — pg_dump/mysqldump via SSH pipe (never via API).
     */
    private static function stepSyncDb(string $migrationId, array $account, array $peer): array
    {
        $databases = Database::fetchAll(
            'SELECT * FROM hosting_databases WHERE account_id = :aid',
            ['aid' => $account['id']]
        );

        if (empty($databases)) {
            self::log($migrationId, self::STEP_SYNC_DB, 'info', 'No databases to sync');
            return ['ok' => true, 'data' => ['databases_synced' => 0]];
        }

        $sshTarget = FederationService::getSshTarget($peer);
        $sshOpts = sprintf('-p %d -i %s -o StrictHostKeyChecking=no', $peer['ssh_port'] ?? 22, $peer['ssh_key_path']);
        $errors = [];
        $synced = 0;

        foreach ($databases as $db) {
            $dbName = $db['db_name'];
            $dbUser = $db['db_user'];
            $dbType = $db['db_type'] ?? 'pgsql';

            if ($dbType === 'pgsql') {
                // PostgreSQL: pg_dump | ssh dest psql
                $cmd = sprintf(
                    'pg_dump -U %s %s | ssh %s %s "psql -U %s %s" 2>&1',
                    escapeshellarg($dbUser),
                    escapeshellarg($dbName),
                    $sshOpts,
                    escapeshellarg($sshTarget),
                    escapeshellarg($dbUser),
                    escapeshellarg($dbName)
                );
            } else {
                // MySQL: mysqldump | ssh dest mysql
                $cmd = sprintf(
                    'mysqldump -u %s %s | ssh %s %s "mysql -u %s %s" 2>&1',
                    escapeshellarg($dbUser),
                    escapeshellarg($dbName),
                    $sshOpts,
                    escapeshellarg($sshTarget),
                    escapeshellarg($dbUser),
                    escapeshellarg($dbName)
                );
            }

            self::log($migrationId, self::STEP_SYNC_DB, 'info', "Syncing database: {$dbName} ({$dbType})");

            $returnCode = 0;
            exec($cmd, $out, $returnCode);

            if ($returnCode !== 0) {
                $errors[] = "{$dbName}: exit code {$returnCode}";
                self::log($migrationId, self::STEP_SYNC_DB, 'error', "Database sync failed: {$dbName}", ['exit_code' => $returnCode]);
            } else {
                $synced++;
            }
        }

        if (!empty($errors)) {
            return ['ok' => false, 'error' => 'Some databases failed: ' . implode(', ', $errors), 'data' => ['synced' => $synced]];
        }

        return ['ok' => true, 'data' => ['databases_synced' => $synced]];
    }

    /**
     * Step 5: FREEZE — Total write isolation:
     *   1. Replace Caddy route with maintenance page (no new HTTP requests)
     *   2. Wait for in-flight requests to drain (poll FPM connections)
     *   3. STOP the FPM pool (guarantees zero PHP execution = zero writes)
     *
     * After this step, the hosting CANNOT write to disk or DB under any circumstance.
     */
    private static function stepFreeze(string $migrationId, array $account, array $peer): array
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy_api'] ?? 'http://localhost:2019';
        $username = $account['username'];
        $phpVersion = $account['php_version'] ?? '8.3';

        // 1. Replace hosting route with maintenance page
        $routeId = SystemService::caddyRouteId($account['domain']);

        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true]);
        curl_exec($ch);
        curl_close($ch);

        $maintenanceRoute = [
            '@id' => $routeId,
            'match' => [['host' => [$account['domain']]]],
            'handle' => [[
                'handler' => 'static_response',
                'status_code' => '503',
                'headers' => ['Content-Type' => ['text/html; charset=utf-8']],
                'body' => '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Maintenance</title><style>body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#1a1a2e;color:#e0e0e0}div{text-align:center;max-width:400px;padding:2rem}.icon{font-size:3rem;margin-bottom:1rem}h1{font-size:1.3rem;margin:0 0 .5rem}p{color:#888;font-size:.9rem;margin:0}</style></head><body><div><div class="icon">&#128295;</div><h1>Site under maintenance</h1><p>We are migrating this site to a new server. This usually takes a few minutes. Please try again shortly.</p></div></body></html>',
            ]],
        ];

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($maintenanceRoute),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            return ['ok' => false, 'error' => "Caddy maintenance route failed (HTTP {$httpCode}): {$response}"];
        }

        self::log($migrationId, self::STEP_FREEZE, 'info', 'Maintenance page active, draining connections...');

        // 2. Wait for in-flight FPM requests to drain (poll active connections)
        $fpmSocket = "/run/php/php{$phpVersion}-fpm-{$username}.sock";
        $maxWait = self::FPM_DRAIN_TIMEOUT;
        for ($i = 0; $i < $maxWait; $i++) {
            // Check if any process is using the FPM socket
            $output = [];
            exec("ss -xp | grep " . escapeshellarg($fpmSocket) . " | wc -l", $output);
            $activeConns = (int)($output[0] ?? 0);
            if ($activeConns === 0) {
                self::log($migrationId, self::STEP_FREEZE, 'info', "FPM connections drained after {$i}s");
                break;
            }
            sleep(1);
        }

        // 3. STOP FPM pool — guarantees ZERO PHP execution
        //    This kills any remaining workers for this user
        exec("pkill -f 'php-fpm:.*{$username}' 2>&1", $out, $rc);

        // Remove pool config so FPM reload doesn't restart it
        $poolFile = "/etc/php/{$phpVersion}/fpm/pool.d/{$username}.conf";
        $poolBackup = "/tmp/fpm-migration-{$username}.conf";
        if (file_exists($poolFile)) {
            copy($poolFile, $poolBackup);  // backup for rollback
            unlink($poolFile);
            exec("systemctl reload php{$phpVersion}-fpm 2>&1");
        }

        // Store pool backup path in metadata for rollback
        self::updateMetadata($migrationId, [
            'fpm_pool_backup' => $poolBackup,
            'fpm_php_version' => $phpVersion,
        ]);

        self::log($migrationId, self::STEP_FREEZE, 'info', "FPM pool stopped: {$username}. Total write isolation achieved.");

        return ['ok' => true, 'data' => [
            'maintenance_active' => true,
            'fpm_stopped' => true,
            'domain' => $account['domain'],
        ]];
    }

    /**
     * Step 6: FINAL SYNC — rsync incremental + final DB dump (consistent, no writes happening).
     */
    private static function stepFinalSync(string $migrationId, array $account, array $peer): array
    {
        // Incremental rsync (only deltas — should be very fast)
        $filesResult = self::stepSyncFiles($migrationId, $account, $peer);
        if (!$filesResult['ok']) {
            return $filesResult;
        }

        // Final DB dump
        $dbResult = self::stepSyncDb($migrationId, $account, $peer);
        if (!$dbResult['ok']) {
            return $dbResult;
        }

        return ['ok' => true, 'data' => ['files' => $filesResult['data'], 'db' => $dbResult['data']]];
    }

    /**
     * Step 7: FINALIZE — API to destination: create Linux user, FPM pool, Caddy route, subdomains, aliases.
     */
    private static function stepFinalize(string $migrationId, array $migration, array $account, array $peer): array
    {
        // Collect related data (filtered by selection from metadata)
        $databases = Database::fetchAll('SELECT * FROM hosting_databases WHERE account_id = :aid', ['aid' => $account['id']]);

        $subdomains = Database::fetchAll('SELECT * FROM hosting_subdomains WHERE account_id = :aid', ['aid' => $account['id']]);
        $selectedSubIds = $migration['metadata']['include_subdomains'] ?? null;
        if (is_array($selectedSubIds)) {
            $selectedSubIds = array_map('intval', $selectedSubIds);
            $subdomains = array_values(array_filter($subdomains, fn($s) => in_array((int)$s['id'], $selectedSubIds)));
        }

        $aliases = Database::fetchAll('SELECT * FROM hosting_domain_aliases WHERE account_id = :aid', ['aid' => $account['id']]);
        $selectedAliasIds = $migration['metadata']['include_aliases'] ?? null;
        if (is_array($selectedAliasIds)) {
            $selectedAliasIds = array_map('intval', $selectedAliasIds);
            $aliases = array_values(array_filter($aliases, fn($a) => in_array((int)$a['id'], $selectedAliasIds)));
        }

        $result = FederationService::callPeerApi($peer, 'POST', '/api/federation/finalize', [
            'migration_id'  => $migrationId,
            'domain'        => $account['domain'],
            'username'      => $account['username'],
            'php_version'   => $account['php_version'] ?? '8.3',
            'disk_quota_mb' => $account['disk_quota_mb'] ?? 0,
            'databases'     => $databases,
            'subdomains'    => $subdomains,
            'aliases'       => $aliases,
        ]);

        if (!$result['ok']) {
            return ['ok' => false, 'error' => 'Finalize failed: ' . ($result['error'] ?? '')];
        }

        return ['ok' => true, 'data' => $result['data'] ?? []];
    }

    /**
     * Step 8: VERIFY — API to destination: HTTP 200 + PHP works + DB connects + response size + no fatal errors.
     */
    private static function stepVerify(string $migrationId, array $account, array $peer): array
    {
        $result = FederationService::callPeerApi($peer, 'POST', '/api/federation/verify', [
            'migration_id' => $migrationId,
            'domain'       => $account['domain'],
        ]);

        if (!$result['ok']) {
            return ['ok' => false, 'error' => 'Verification failed: ' . ($result['error'] ?? '')];
        }

        $checks = $result['data'] ?? [];
        $passed = ($checks['http_ok'] ?? false) && ($checks['no_fatal'] ?? false);

        if (!$passed) {
            return ['ok' => false, 'error' => 'Verification checks failed', 'data' => $checks];
        }

        return ['ok' => true, 'data' => $checks];
    }

    /**
     * Step 9: SWITCH DNS — Cloudflare API: change A record, origin → read-only, grace period starts.
     */
    private static function stepSwitchDns(string $migrationId, array $migration, array $account, array $peer): array
    {
        // Get destination IP from peer
        $destInfo = FederationService::callPeerApi($peer, 'GET', '/api/federation/server-info');
        $destIp = $destInfo['data']['public_ip'] ?? '';
        if (empty($destIp)) {
            return ['ok' => false, 'error' => 'Could not determine destination public IP'];
        }

        // DNS mode: auto (Cloudflare API) or manual (admin does it)
        $dnsMode = $migration['metadata']['dns_mode'] ?? 'auto';

        $cfToken = \MuseDockPanel\Settings::get('cloudflare_api_token', '');
        if (empty($cfToken)) {
            $cfToken = \MuseDockPanel\Env::get('CLOUDFLARE_API_TOKEN', '');
        }

        $dnsAutomatic = false;
        if ($dnsMode === 'manual') {
            // Admin chose manual DNS — skip Cloudflare, just log the target IP
            self::log($migrationId, self::STEP_SWITCH_DNS, 'info',
                "DNS mode: MANUAL. Update A record for {$account['domain']} to: {$destIp}");
            self::updateMetadata($migrationId, [
                'dns_manual_required' => true,
                'dns_target_ip' => $destIp,
            ]);
        } elseif (!empty($cfToken)) {
            $dnsResult = self::updateCloudflareRecord($cfToken, $account['domain'], $destIp);
            if (!$dnsResult['ok']) {
                // DNS failure is NOT fatal — log the error and continue.
                // The migration is valid, the site works on destination.
                // Admin can update DNS manually. We still enter grace period.
                self::log($migrationId, self::STEP_SWITCH_DNS, 'warn',
                    'Cloudflare DNS update failed (continuing with grace period): ' . ($dnsResult['error'] ?? ''));
                self::updateMetadata($migrationId, [
                    'dns_manual_required' => true,
                    'dns_error' => $dnsResult['error'] ?? '',
                    'dns_target_ip' => $destIp,
                ]);
            } else {
                $dnsAutomatic = true;
            }
        } else {
            self::log($migrationId, self::STEP_SWITCH_DNS, 'warn',
                "No Cloudflare token — DNS must be updated manually to: {$destIp}");
            self::updateMetadata($migrationId, [
                'dns_manual_required' => true,
                'dns_target_ip' => $destIp,
            ]);
        }

        // Switch origin from maintenance to read-only mode
        self::setCaddyReadOnly($account['domain']);

        $graceStartTs = time();
        $graceStart = date('Y-m-d H:i:s', $graceStartTs);

        // Store grace period start in step results (both unix ts and human-readable)
        self::saveStepResult($migrationId, self::STEP_SWITCH_DNS, [
            'ok' => true,
            'data' => [
                'dest_ip' => $destIp,
                'grace_start' => $graceStart,
                'grace_start_ts' => $graceStartTs,
                'grace_minutes' => $migration['grace_period_minutes'],
                'dns_automatic' => $dnsAutomatic,
            ],
        ]);

        self::log($migrationId, self::STEP_SWITCH_DNS, 'info', 'DNS switched, grace period started', [
            'dest_ip' => $destIp,
            'grace_start' => $graceStart,
            'grace_minutes' => $migration['grace_period_minutes'],
        ]);

        return ['ok' => true, 'data' => [
            'dest_ip' => $destIp,
            'grace_start' => $graceStart,
            'grace_minutes' => $migration['grace_period_minutes'],
        ]];
    }

    /**
     * Step 10: COMPLETE — After grace period: deactivate origin, mark as migrated, keep files 48h.
     */
    private static function stepComplete(string $migrationId, array $migration, array $account, array $peer): array
    {
        // Check grace period elapsed (prefer unix timestamp, fallback to text)
        $switchResult = $migration['step_results'][self::STEP_SWITCH_DNS] ?? [];
        $graceStartTs = $switchResult['data']['grace_start_ts'] ?? null;
        if (!$graceStartTs) {
            $graceStartText = $switchResult['data']['grace_start'] ?? $switchResult['grace_start'] ?? null;
            $graceStartTs = $graceStartText ? strtotime($graceStartText) : null;
        }

        if ($graceStartTs) {
            $graceEnd = (int)$graceStartTs + ($migration['grace_period_minutes'] * 60);
            if (time() < $graceEnd) {
                $remaining = $graceEnd - time();
                return ['ok' => false, 'error' => "Grace period not elapsed ({$remaining}s remaining)"];
            }
        }

        // Deactivate Caddy route on origin
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy_api'] ?? 'http://localhost:2019';
        $routeId = SystemService::caddyRouteId($account['domain']);

        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true]);
        curl_exec($ch);
        curl_close($ch);

        // Mark account as migrated_away
        Database::update('hosting_accounts', [
            'status' => 'migrated_away',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $account['id']]);

        // Store cleanup timestamp (files kept 48h as safety net)
        self::updateMetadata($migrationId, [
            'files_cleanup_after' => date('Y-m-d H:i:s', time() + 48 * 3600),
        ]);

        // Resume slave sync on destination (was paused during prepare)
        FederationService::callPeerApi($peer, 'POST', '/api/federation/pause-sync', [
            'migration_id' => $migrationId,
            'domain'       => $account['domain'],
            'action'       => 'resume',
        ]);

        // Notify destination that migration is complete
        // This triggers slave sync (enqueues create_hosting to web nodes)
        FederationService::callPeerApi($peer, 'POST', '/api/federation/complete', [
            'migration_id' => $migrationId,
            'domain'       => $account['domain'],
        ]);

        // Compute overall migration metrics
        $totalDuration = 0;
        $totalBytes = 0;
        $stepTimings = [];
        $stepResults = $migration['step_results'] ?? [];
        foreach ($stepResults as $stepName => $sr) {
            $stepDur = $sr['metrics']['duration_seconds'] ?? 0;
            $totalDuration += $stepDur;
            $stepTimings[$stepName] = $stepDur;
            // Sum bytes from sync steps
            if (isset($sr['data']['bytes_transferred'])) {
                $totalBytes += (int)$sr['data']['bytes_transferred'];
            }
        }

        $startedAt = $migration['started_at'] ?? $migration['created_at'];
        $wallClockSeconds = $startedAt ? (time() - strtotime($startedAt)) : 0;

        $metrics = [
            'total_step_duration_seconds' => round($totalDuration, 2),
            'wall_clock_seconds' => $wallClockSeconds,
            'wall_clock_human' => sprintf('%dh %dm %ds', $wallClockSeconds / 3600, ($wallClockSeconds % 3600) / 60, $wallClockSeconds % 60),
            'total_bytes_transferred' => $totalBytes,
            'total_mb_transferred' => round($totalBytes / 1048576, 2),
            'step_timings' => $stepTimings,
            'disk_used_mb' => $account['disk_used_mb'] ?? 0,
        ];

        self::updateMetadata($migrationId, ['metrics' => $metrics]);

        self::log($migrationId, self::STEP_COMPLETE, 'info', 'Migration completed', [
            'domain' => $account['domain'],
            'wall_clock' => $metrics['wall_clock_human'],
            'total_mb' => $metrics['total_mb_transferred'],
            'files_cleanup_after' => date('Y-m-d H:i:s', time() + 48 * 3600),
        ]);

        return ['ok' => true, 'data' => ['domain' => $account['domain'], 'status' => 'completed', 'metrics' => $metrics]];
    }

    // ═══════════════════════════════════════════════════════════════
    // Dry-run step handler
    // ═══════════════════════════════════════════════════════════════

    private static function executeDryRunStep(string $migrationId, array $migration, string $step, array $account, array $peer): array
    {
        return match ($step) {
            self::STEP_HEALTH_CHECK => self::stepHealthCheck($migrationId, $account, $peer), // real check
            self::STEP_PREPARE => FederationService::callPeerApi($peer, 'POST', '/api/federation/check-conflicts', [
                'domain'     => $account['domain'],
                'username'   => $account['username'],
                'system_uid' => $account['system_uid'],
            ]),
            default => ['ok' => true, 'data' => ['dry_run' => true, 'step' => $step, 'skipped' => true]],
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // Pause / Resume / Cancel / Rollback
    // ═══════════════════════════════════════════════════════════════

    /**
     * Pause a running migration.
     */
    public static function pause(string $migrationId): array
    {
        $migration = self::getByMigrationId($migrationId);
        if (!$migration || $migration['status'] !== self::STATUS_RUNNING) {
            return ['ok' => false, 'error' => 'Migration not running'];
        }

        self::updateStatus($migrationId, self::STATUS_PAUSED);
        self::log($migrationId, $migration['current_step'], 'info', 'Migration paused by admin');

        return ['ok' => true];
    }

    /**
     * Resume a paused migration.
     */
    public static function resume(string $migrationId): array
    {
        $migration = self::getByMigrationId($migrationId);
        if (!$migration || $migration['status'] !== self::STATUS_PAUSED) {
            return ['ok' => false, 'error' => 'Migration not paused'];
        }

        self::updateStatus($migrationId, self::STATUS_RUNNING);
        self::log($migrationId, $migration['current_step'], 'info', 'Migration resumed by admin');

        return ['ok' => true];
    }

    /**
     * Cancel a migration → triggers rollback.
     */
    public static function cancel(string $migrationId): array
    {
        $migration = self::getByMigrationId($migrationId);
        if (!$migration) {
            return ['ok' => false, 'error' => 'Migration not found'];
        }

        if (in_array($migration['status'], [self::STATUS_COMPLETED, self::STATUS_ROLLED_BACK, self::STATUS_CANCELLED])) {
            return ['ok' => false, 'error' => 'Migration cannot be cancelled in current state'];
        }

        self::log($migrationId, $migration['current_step'], 'info', 'Migration cancelled by admin — starting rollback');

        return self::rollback($migrationId);
    }

    /**
     * Rollback: clean destination, restore origin.
     *
     * - Steps 0-8: destination cleans everything (files, DB, user), origin removes maintenance + unlocks
     * - Step 9 (DNS changed): revert DNS to origin IP + clean destination + origin removes read-only
     * - Origin is NEVER modified until step 10
     */
    public static function rollback(string $migrationId): array
    {
        $migration = self::getByMigrationId($migrationId);
        if (!$migration) {
            return ['ok' => false, 'error' => 'Migration not found'];
        }

        $account = Database::fetchOne('SELECT * FROM hosting_accounts WHERE id = :id', ['id' => $migration['account_id']]);
        $peer = FederationService::getPeer($migration['peer_id']);

        if (!$account || !$peer) {
            return ['ok' => false, 'error' => 'Account or peer not found'];
        }

        $currentStep = $migration['current_step'];
        $stepIndex = array_search($currentStep, self::STEPS);

        self::log($migrationId, $currentStep, 'info', "Starting rollback from step: {$currentStep}");

        // 0. Resume slave sync on destination (in case it was paused)
        FederationService::callPeerApi($peer, 'POST', '/api/federation/pause-sync', [
            'migration_id' => $migrationId,
            'domain'       => $account['domain'],
            'action'       => 'resume',
        ]);

        // 1. Tell destination to clean up (always safe — idempotent)
        FederationService::callPeerApi($peer, 'POST', '/api/federation/rollback', [
            'migration_id' => $migrationId,
            'domain'       => $account['domain'],
            'username'     => $account['username'],
        ]);

        // 2. If DNS was switched (step >= switch_dns), revert it
        $switchDnsIndex = array_search(self::STEP_SWITCH_DNS, self::STEPS);
        if ($stepIndex >= $switchDnsIndex) {
            // Revert DNS to origin
            $cfToken = \MuseDockPanel\Settings::get('cloudflare_api_token', '');
            if (empty($cfToken)) {
                $cfToken = \MuseDockPanel\Env::get('CLOUDFLARE_API_TOKEN', '');
            }
            if (!empty($cfToken)) {
                $originIp = self::getLocalPublicIp();
                if ($originIp) {
                    self::updateCloudflareRecord($cfToken, $account['domain'], $originIp);
                }
            }
        }

        // 3. Restore FPM pool (stopped during freeze)
        $freezeIndex = array_search(self::STEP_FREEZE, self::STEPS);
        if ($stepIndex >= $freezeIndex) {
            $metadata = $migration['metadata'] ?? [];
            $poolBackup = $metadata['fpm_pool_backup'] ?? '';
            $fpmVersion = $metadata['fpm_php_version'] ?? ($account['php_version'] ?? '8.3');
            $poolFile = "/etc/php/{$fpmVersion}/fpm/pool.d/{$account['username']}.conf";

            if (!empty($poolBackup) && file_exists($poolBackup) && !file_exists($poolFile)) {
                copy($poolBackup, $poolFile);
                exec("systemctl reload php{$fpmVersion}-fpm 2>&1");
                @unlink($poolBackup);
                self::log($migrationId, $currentStep, 'info', 'FPM pool restored from backup');
            } elseif (!file_exists($poolFile)) {
                // No backup — recreate pool from scratch
                SystemService::createFpmPool($account['username'], $fpmVersion, $account['home_dir']);
                exec("systemctl reload php{$fpmVersion}-fpm 2>&1");
                self::log($migrationId, $currentStep, 'info', 'FPM pool recreated (no backup found)');
            }

            // Restore original Caddy route
            self::restoreOriginalCaddyRoute($account);
        }

        // 4. Unlock account on origin
        $lockIndex = array_search(self::STEP_LOCK, self::STEPS);
        if ($stepIndex >= $lockIndex) {
            Database::update('hosting_accounts', [
                'status' => 'active',
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $account['id']]);
        }

        self::updateStatus($migrationId, self::STATUS_ROLLED_BACK, [
            'completed_at' => date('Y-m-d H:i:s'),
            'error_message' => 'Migration rolled back',
        ]);

        self::log($migrationId, $currentStep, 'info', 'Rollback completed');
        LogService::log('federation.rollback', $account['domain'], "Migration {$migrationId} rolled back from step {$currentStep}");

        return ['ok' => true];
    }

    // ═══════════════════════════════════════════════════════════════
    // Caddy helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Set Caddy to read-only mode during grace period.
     *
     * CRITICAL DESIGN DECISION:
     * We do NOT restart FPM for "read-only GET". PHP execution with GET can still
     * trigger writes (session_start(), log writes, wp-cron, etc.).
     * Instead, we keep FPM STOPPED and serve only static files via Caddy's file_server.
     * POST/PUT/DELETE/PATCH → 503. GET for static files → served normally.
     * This guarantees zero writes on origin during the entire grace period.
     */
    private static function setCaddyReadOnly(string $domain): void
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy_api'] ?? 'http://localhost:2019';
        $routeId = SystemService::caddyRouteId($domain);

        // Delete existing maintenance route
        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true]);
        curl_exec($ch);
        curl_close($ch);

        // Grace period route: static files only, NO PHP execution
        // - Block all write methods → 503
        // - Serve static assets (CSS, JS, images, HTML) via file_server
        // - PHP requests → 503 (FPM is stopped anyway, but belt+suspenders)
        $account = Database::fetchOne('SELECT * FROM hosting_accounts WHERE domain = :d', ['d' => $domain]);
        $docRoot = $account ? $account['document_root'] : "/var/www/vhosts/{$domain}/httpdocs";

        $readOnlyRoute = [
            '@id' => $routeId,
            'match' => [['host' => [$domain]]],
            'handle' => [
                [
                    'handler' => 'subroute',
                    'routes' => [
                        // Block all write methods
                        [
                            'match' => [['method' => ['POST', 'PUT', 'DELETE', 'PATCH']]],
                            'handle' => [[
                                'handler' => 'static_response',
                                'status_code' => '503',
                                'headers' => ['Content-Type' => ['text/html; charset=utf-8']],
                                'body' => '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Read-only</title><style>body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#1a1a2e;color:#e0e0e0}div{text-align:center;max-width:400px;padding:2rem}h1{font-size:1.2rem;margin:0 0 .5rem}p{color:#888;font-size:.9rem;margin:0}</style></head><body><div><h1>Site is in read-only mode</h1><p>This site is being migrated to a new server. Write operations are temporarily disabled. Please try again in a few minutes.</p></div></body></html>',
                            ]],
                        ],
                        // Block PHP execution (even if someone tries GET on .php)
                        [
                            'match' => [['path' => ['*.php']]],
                            'handle' => [[
                                'handler' => 'static_response',
                                'status_code' => '503',
                                'headers' => ['Content-Type' => ['text/html; charset=utf-8']],
                                'body' => '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Read-only</title><style>body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#1a1a2e;color:#e0e0e0}div{text-align:center;max-width:400px;padding:2rem}h1{font-size:1.2rem;margin:0 0 .5rem}p{color:#888;font-size:.9rem;margin:0}</style></head><body><div><h1>Temporarily unavailable</h1><p>This site is being migrated. Dynamic content is temporarily unavailable. Please try again in a few minutes.</p></div></body></html>',
                            ]],
                        ],
                        // Serve static files (images, CSS, JS, etc.)
                        [
                            'handle' => [[
                                'handler' => 'file_server',
                                'root' => $docRoot,
                            ]],
                        ],
                    ],
                ],
            ],
        ];

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($readOnlyRoute),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Restore original Caddy route for an account (after rollback).
     */
    private static function restoreOriginalCaddyRoute(array $account): void
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy_api'] ?? 'http://localhost:2019';
        $routeId = SystemService::caddyRouteId($account['domain']);

        // Delete maintenance/read-only route
        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true]);
        curl_exec($ch);
        curl_close($ch);

        // Re-add normal route via SystemService
        SystemService::addCaddyRoute(
            $account['domain'],
            $account['document_root'],
            $account['username'],
            $account['php_version'] ?? '8.3'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Cloudflare helper
    // ═══════════════════════════════════════════════════════════════

    private static function updateCloudflareRecord(string $token, string $domain, string $ip): array
    {
        // Find zone for domain
        $rootDomain = implode('.', array_slice(explode('.', $domain), -2));
        $zones = CloudflareService::apiRequest($token, 'GET', '/zones', ['name' => $rootDomain]);

        if (empty($zones['result'])) {
            return ['ok' => false, 'error' => "Zone not found for: {$rootDomain}"];
        }

        $zoneId = $zones['result'][0]['id'];

        // Find A record
        $records = CloudflareService::apiRequest($token, 'GET', "/zones/{$zoneId}/dns_records", [
            'type' => 'A',
            'name' => $domain,
        ]);

        if (empty($records['result'])) {
            return ['ok' => false, 'error' => "A record not found for: {$domain}"];
        }

        $recordId = $records['result'][0]['id'];
        $proxied = $records['result'][0]['proxied'] ?? false;

        // Update A record
        $updateResult = CloudflareService::apiRequest($token, 'PUT', "/zones/{$zoneId}/dns_records/{$recordId}", [
            'type' => 'A',
            'name' => $domain,
            'content' => $ip,
            'proxied' => $proxied,
        ]);

        return ['ok' => $updateResult['success'] ?? false, 'error' => $updateResult['errors'][0]['message'] ?? null];
    }

    // ═══════════════════════════════════════════════════════════════
    // Internal helpers
    // ═══════════════════════════════════════════════════════════════

    private static function generateMigrationId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private static function getNextStep(string $currentStep, string $mode): ?string
    {
        $steps = self::STEPS;
        $index = array_search($currentStep, $steps);
        if ($index === false) return null;

        for ($i = $index + 1; $i < count($steps); $i++) {
            $nextStep = $steps[$i];
            // Skip clone-only steps
            if ($mode === self::MODE_CLONE && in_array($nextStep, self::CLONE_SKIP_STEPS)) {
                continue;
            }
            return $nextStep;
        }

        return null; // all done
    }

    private static function acquireStepLock(string $migrationId, string $step): bool
    {
        $lockValue = "{$migrationId}:{$step}:" . time();
        $result = Database::query(
            "UPDATE hosting_migrations SET step_lock = :lock, updated_at = NOW() WHERE migration_id = :mid AND (step_lock IS NULL OR step_lock = '')",
            ['lock' => $lockValue, 'mid' => $migrationId]
        );
        return $result->rowCount() > 0;
    }

    private static function releaseStepLock(string $migrationId): void
    {
        Database::query(
            "UPDATE hosting_migrations SET step_lock = NULL, updated_at = NOW() WHERE migration_id = :mid",
            ['mid' => $migrationId]
        );
    }

    private static function updateStatus(string $migrationId, string $status, array $extra = []): void
    {
        $data = array_merge(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], $extra);
        Database::update('hosting_migrations', $data, 'migration_id = :mid', ['mid' => $migrationId]);
    }

    private static function updateCurrentStep(string $migrationId, string $step): void
    {
        Database::update('hosting_migrations', [
            'current_step' => $step,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'migration_id = :mid', ['mid' => $migrationId]);
    }

    private static function saveStepResult(string $migrationId, string $step, array $result): void
    {
        $migration = self::getByMigrationId($migrationId);
        $stepResults = $migration['step_results'] ?? [];
        $stepResults[$step] = array_merge($result, ['completed_at' => date('Y-m-d H:i:s')]);

        Database::update('hosting_migrations', [
            'step_results' => json_encode($stepResults),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'migration_id = :mid', ['mid' => $migrationId]);
    }

    private static function incrementRetry(string $migrationId, string $step, int $retryCount): void
    {
        $migration = self::getByMigrationId($migrationId);
        $stepResults = $migration['step_results'] ?? [];
        $stepResults[$step] = array_merge($stepResults[$step] ?? [], ['retries' => $retryCount]);

        Database::update('hosting_migrations', [
            'step_results' => json_encode($stepResults),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'migration_id = :mid', ['mid' => $migrationId]);
    }

    private static function updateMetadata(string $migrationId, array $newData): void
    {
        $migration = self::getByMigrationId($migrationId);
        $metadata = $migration['metadata'] ?? [];
        $metadata = array_merge($metadata, $newData);

        Database::update('hosting_migrations', [
            'metadata' => json_encode($metadata),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'migration_id = :mid', ['mid' => $migrationId]);
    }

    private static function getLocalPublicIp(): ?string
    {
        $ip = @file_get_contents('https://api.ipify.org?format=text');
        return $ip ? trim($ip) : null;
    }

    // ═══════════════════════════════════════════════════════════════
    // Logging
    // ═══════════════════════════════════════════════════════════════

    public static function log(string $migrationId, string $step, string $level, string $message, array $metadata = []): void
    {
        Database::insert('hosting_migration_logs', [
            'migration_id' => $migrationId,
            'step'         => $step,
            'level'        => $level,
            'message'      => $message,
            'metadata'     => json_encode($metadata),
        ]);
    }

    /**
     * Get logs for a migration.
     */
    public static function getLogs(string $migrationId, ?string $step = null, int $limit = 200): array
    {
        $where = 'migration_id = :mid';
        $params = ['mid' => $migrationId];

        if ($step) {
            $where .= ' AND step = :step';
            $params['step'] = $step;
        }

        return Database::fetchAll(
            "SELECT * FROM hosting_migration_logs WHERE {$where} ORDER BY created_at ASC LIMIT {$limit}",
            $params
        );
    }

    /**
     * Get migration progress summary (for real-time UI).
     */
    public static function getProgress(string $migrationId): array
    {
        $migration = self::getByMigrationId($migrationId);
        if (!$migration) {
            return ['ok' => false, 'error' => 'Migration not found'];
        }

        $steps = self::STEPS;
        if ($migration['mode'] === self::MODE_CLONE) {
            $steps = array_values(array_diff($steps, self::CLONE_SKIP_STEPS));
        }

        $currentIndex = array_search($migration['current_step'], $steps);
        $totalSteps = count($steps);

        $stepStatuses = [];
        foreach ($steps as $i => $step) {
            $result = $migration['step_results'][$step] ?? null;
            if ($result) {
                $stepStatuses[$step] = $result['ok'] ? 'completed' : 'failed';
            } elseif ($i === $currentIndex && $migration['status'] === self::STATUS_RUNNING) {
                $stepStatuses[$step] = 'running';
            } elseif ($i < $currentIndex) {
                $stepStatuses[$step] = 'completed';
            } else {
                $stepStatuses[$step] = 'pending';
            }
        }

        // Check grace period
        $graceRemaining = null;
        if ($migration['current_step'] === self::STEP_COMPLETE) {
            $switchResult = $migration['step_results'][self::STEP_SWITCH_DNS] ?? [];
            $graceStart = $switchResult['data']['grace_start'] ?? $switchResult['grace_start'] ?? null;
            if ($graceStart) {
                $graceEnd = strtotime($graceStart) + ($migration['grace_period_minutes'] * 60);
                $graceRemaining = max(0, $graceEnd - time());
            }
        }

        // Collect per-step metrics for real-time display
        $stepMetrics = [];
        $elapsedTotal = 0;
        foreach ($migration['step_results'] as $stepName => $sr) {
            $dur = $sr['metrics']['duration_seconds'] ?? 0;
            $elapsedTotal += $dur;
            $stepMetrics[$stepName] = [
                'duration' => $dur,
                'bytes' => $sr['data']['bytes_transferred'] ?? null,
                'speed_mbps' => $sr['data']['speed_mbps'] ?? null,
            ];
        }

        // Wall clock time so far
        $wallClock = 0;
        if ($migration['started_at']) {
            $wallClock = ($migration['completed_at'] ? strtotime($migration['completed_at']) : time()) - strtotime($migration['started_at']);
        }

        // DNS manual warning
        $dnsManual = $migration['metadata']['dns_manual_required'] ?? false;
        $dnsTargetIp = $migration['metadata']['dns_target_ip'] ?? null;

        return [
            'ok' => true,
            'migration_id' => $migrationId,
            'status' => $migration['status'],
            'mode' => $migration['mode'],
            'dry_run' => (bool)$migration['dry_run'],
            'current_step' => $migration['current_step'],
            'current_step_index' => $currentIndex,
            'total_steps' => $totalSteps,
            'percent' => $totalSteps > 0 ? round(($currentIndex / $totalSteps) * 100) : 0,
            'step_statuses' => $stepStatuses,
            'step_metrics' => $stepMetrics,
            'error_message' => $migration['error_message'],
            'grace_remaining' => $graceRemaining,
            'dns_manual_required' => $dnsManual,
            'dns_target_ip' => $dnsTargetIp,
            'started_at' => $migration['started_at'],
            'completed_at' => $migration['completed_at'],
            'elapsed_seconds' => $elapsedTotal,
            'wall_clock_seconds' => $wallClock,
            'final_metrics' => $migration['metadata']['metrics'] ?? null,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Clone actions (update, re-clone, promote)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Find a completed clone for an account on a specific peer.
     */
    public static function findCompletedClone(int $accountId, ?int $peerId = null): ?array
    {
        $where = "account_id = :aid AND mode = 'clone' AND status = 'completed'";
        $params = ['aid' => $accountId];
        if ($peerId) {
            $where .= ' AND peer_id = :pid';
            $params['pid'] = $peerId;
        }
        $row = Database::fetchOne(
            "SELECT * FROM hosting_migrations WHERE {$where} ORDER BY completed_at DESC LIMIT 1",
            $params
        );
        if ($row) {
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
            $row['step_results'] = json_decode($row['step_results'] ?? '{}', true);
        }
        return $row;
    }

    /**
     * Get all completed clones for an account.
     */
    public static function getCompletedClones(int $accountId): array
    {
        $rows = Database::fetchAll("
            SELECT m.*, p.name as peer_name
            FROM hosting_migrations m
            LEFT JOIN federation_peers p ON p.id = m.peer_id
            WHERE m.account_id = :aid AND m.mode = 'clone' AND m.status = 'completed'
            ORDER BY m.completed_at DESC
        ", ['aid' => $accountId]);
        foreach ($rows as &$row) {
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
            $row['step_results'] = json_decode($row['step_results'] ?? '{}', true);
        }
        return $rows;
    }

    /**
     * Update clone: incremental rsync (NO --delete) + DB sync.
     * Adds/modifies files without removing extra files on destination.
     */
    /**
     * @param string $syncScope 'all' = files+db, 'files' = files only, 'db' = databases only
     */
    public static function updateClone(int $accountId, int $peerId, string $syncScope = 'all'): array
    {
        // Validate
        $account = Database::fetchOne('SELECT * FROM hosting_accounts WHERE id = :id', ['id' => $accountId]);
        if (!$account) return ['ok' => false, 'error' => 'Account not found'];

        $peer = FederationService::getPeer($peerId);
        if (!$peer) return ['ok' => false, 'error' => 'Peer not found'];

        $clone = self::findCompletedClone($accountId, $peerId);
        if (!$clone) return ['ok' => false, 'error' => 'No completed clone found for this account on this peer'];

        // Check no active migration
        $active = Database::fetchOne(
            "SELECT id FROM hosting_migrations WHERE account_id = :aid AND status IN ('pending', 'running', 'paused')",
            ['aid' => $accountId]
        );
        if ($active) return ['ok' => false, 'error' => 'Account has an active migration'];

        $migrationId = self::generateMigrationId();
        $user = \MuseDockPanel\Auth::user();

        // Create a migration record for tracking
        $id = Database::insert('hosting_migrations', [
            'migration_id'         => $migrationId,
            'account_id'           => $accountId,
            'peer_id'              => $peerId,
            'mode'                 => 'update_clone',
            'direction'            => 'outgoing',
            'status'               => self::STATUS_RUNNING,
            'current_step'         => 'sync_files',
            'dry_run'              => 'false',
            'grace_period_minutes' => 0,
            'metadata'             => json_encode(['parent_clone_id' => $clone['migration_id']]),
            'started_at'           => date('Y-m-d H:i:s'),
            'created_by'           => $user['id'] ?? null,
        ]);

        self::log($migrationId, 'update_clone', 'info', "Starting clone update for {$account['domain']}", [
            'peer' => $peer['name'], 'parent_clone' => $clone['migration_id'],
        ]);

        // 1. Pause slave sync on destination
        FederationService::callPeerApi($peer, 'POST', '/api/federation/pause-sync', [
            'migration_id' => $migrationId, 'domain' => $account['domain'], 'action' => 'pause',
        ]);

        $sshTarget = FederationService::getSshTarget($peer);
        $bytesTransferred = 0;
        $rsyncDuration = 0;
        $dbSynced = 0;

        // 2. Sync files (if scope includes files)
        if ($syncScope === 'all' || $syncScope === 'files') {
            $homeDir = rtrim($account['home_dir'], '/') . '/';
            $cmd = sprintf(
                'rsync -azP --partial -e "ssh -p %d -i %s -o StrictHostKeyChecking=no" %s %s:%s 2>&1',
                $peer['ssh_port'] ?? 22,
                escapeshellarg($peer['ssh_key_path']),
                escapeshellarg($homeDir),
                escapeshellarg($sshTarget),
                escapeshellarg($homeDir)
            );

            self::log($migrationId, 'sync_files', 'info', 'rsync incremental (no --delete)');
            $rsyncStart = microtime(true);
            $outputLines = [];
            exec($cmd, $outputLines, $rc);
            $rsyncDuration = round(microtime(true) - $rsyncStart, 2);

            if ($rc !== 0 && $rc !== 24) {
                self::updateStatus($migrationId, self::STATUS_FAILED, [
                    'error_message' => "rsync failed (exit code {$rc})",
                    'completed_at' => date('Y-m-d H:i:s'),
                ]);
                FederationService::callPeerApi($peer, 'POST', '/api/federation/pause-sync', [
                    'migration_id' => $migrationId, 'domain' => $account['domain'], 'action' => 'resume',
                ]);
                return ['ok' => false, 'error' => "rsync failed (exit code {$rc})"];
            }

            foreach ($outputLines as $line) {
                if (preg_match('/sent ([\d,]+) bytes/', $line, $m)) {
                    $bytesTransferred = (int)str_replace(',', '', $m[1]);
                }
            }
        } else {
            self::log($migrationId, 'sync_files', 'info', 'File sync skipped (scope: db only)');
        }

        // 3. Sync databases (if scope includes db)
        if ($syncScope === 'all' || $syncScope === 'db') {
            self::log($migrationId, 'sync_db', 'info', 'Syncing databases');
            $databases = Database::fetchAll('SELECT * FROM hosting_databases WHERE account_id = :aid', ['aid' => $accountId]);
            $sshOpts = sprintf('-p %d -i %s -o StrictHostKeyChecking=no', $peer['ssh_port'] ?? 22, $peer['ssh_key_path']);

            foreach ($databases as $db) {
                $dbName = $db['db_name'];
                $dbUser = $db['db_user'];
                $dbType = $db['db_type'] ?? 'pgsql';

                if ($dbType === 'pgsql') {
                    $dbCmd = sprintf('pg_dump -U %s %s | ssh %s %s "psql -U %s %s" 2>&1',
                        escapeshellarg($dbUser), escapeshellarg($dbName), $sshOpts, escapeshellarg($sshTarget),
                        escapeshellarg($dbUser), escapeshellarg($dbName));
                } else {
                    $dbCmd = sprintf('mysqldump -u %s %s | ssh %s %s "mysql -u %s %s" 2>&1',
                        escapeshellarg($dbUser), escapeshellarg($dbName), $sshOpts, escapeshellarg($sshTarget),
                        escapeshellarg($dbUser), escapeshellarg($dbName));
                }
                exec($dbCmd, $out, $dbRc);
                if ($dbRc === 0) $dbSynced++;
            }
        } else {
            self::log($migrationId, 'sync_db', 'info', 'DB sync skipped (scope: files only)');
        }

        // 4. Resume slave sync
        FederationService::callPeerApi($peer, 'POST', '/api/federation/pause-sync', [
            'migration_id' => $migrationId, 'domain' => $account['domain'], 'action' => 'resume',
        ]);

        // 5. Mark completed
        self::updateStatus($migrationId, self::STATUS_COMPLETED, [
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        self::saveStepResult($migrationId, 'update_clone', [
            'ok' => true, 'data' => [
                'bytes_transferred' => $bytesTransferred,
                'rsync_duration' => $rsyncDuration,
                'databases_synced' => $dbSynced,
            ],
        ]);

        self::log($migrationId, 'update_clone', 'info', "Clone updated: {$account['domain']}", [
            'bytes' => $bytesTransferred, 'duration' => $rsyncDuration, 'dbs' => $dbSynced,
        ]);

        LogService::log('federation.clone.update', $account['domain'],
            "Clone updated on {$peer['name']} ({$bytesTransferred} bytes, {$rsyncDuration}s)");

        return ['ok' => true, 'migration_id' => $migrationId, 'data' => [
            'bytes_transferred' => $bytesTransferred,
            'rsync_duration' => $rsyncDuration,
            'databases_synced' => $dbSynced,
        ]];
    }

    /**
     * Force re-clone: delete everything on destination, then full clone.
     * Destination becomes an exact mirror of origin.
     */
    public static function forceReclone(int $accountId, int $peerId): array
    {
        $account = Database::fetchOne('SELECT * FROM hosting_accounts WHERE id = :id', ['id' => $accountId]);
        if (!$account) return ['ok' => false, 'error' => 'Account not found'];

        $peer = FederationService::getPeer($peerId);
        if (!$peer) return ['ok' => false, 'error' => 'Peer not found'];

        // Check no active migration
        $active = Database::fetchOne(
            "SELECT id FROM hosting_migrations WHERE account_id = :aid AND status IN ('pending', 'running', 'paused')",
            ['aid' => $accountId]
        );
        if ($active) return ['ok' => false, 'error' => 'Account has an active migration'];

        // 1. Tell destination to clean up the existing hosting
        self::log('', 'force_reclone', 'info', "Force re-clone: cleaning destination for {$account['domain']}");

        $recloneId = 'reclone-' . time();
        $rollbackResult = FederationService::callPeerApi($peer, 'POST', '/api/federation/rollback', [
            'migration_id' => $recloneId,
            'domain'       => $account['domain'],
            'username'     => $account['username'],
        ]);

        if (!$rollbackResult['ok']) {
            self::log($recloneId, 'force_reclone', 'warn',
                'Destination cleanup returned error: ' . ($rollbackResult['error'] ?? 'unknown'));
        }

        // Verify the cleanup: check domain no longer exists on destination
        $checkResult = FederationService::callPeerApi($peer, 'POST', '/api/federation/check-conflicts', [
            'domain'   => $account['domain'],
            'username' => $account['username'],
        ]);
        // If domain still exists after rollback, the cleanup failed
        if ($checkResult['ok'] === false || !empty(array_filter($checkResult['data']['conflicts'] ?? [], fn($c) => str_contains($c, 'already exists')))) {
            self::log($recloneId, 'force_reclone', 'warn',
                'Destination still has remnants after cleanup — clone creation will handle IF NOT EXISTS');
        }

        // Mark old clones as superseded
        Database::query(
            "UPDATE hosting_migrations SET metadata = jsonb_set(COALESCE(metadata, '{}'), '{superseded}', 'true')
             WHERE account_id = :aid AND peer_id = :pid AND mode = 'clone' AND status = 'completed'",
            ['aid' => $accountId, 'pid' => $peerId]
        );

        // 2. Create a new full clone (reuse the standard clone flow)
        $result = self::create($accountId, $peerId, self::MODE_CLONE, false, 0);
        if (!$result['ok']) return $result;

        // 3. Run all steps
        $runResult = self::runAll($result['migration_id']);

        LogService::log('federation.clone.reclone', $account['domain'],
            "Force re-clone to {$peer['name']}: " . ($runResult['status'] ?? 'unknown'));

        return $runResult;
    }

    /**
     * Promote clone to primary: optional final sync, verify, switch DNS, deactivate origin.
     * Effectively converts a clone into a full migration.
     *
     * Flow:
     *   syncFirst=true:  updateClone() → LOCK → FREEZE → FINAL_SYNC → VERIFY → SWITCH_DNS → COMPLETE
     *   syncFirst=false: VERIFY → SWITCH_DNS → COMPLETE (no sync, just verify existing clone works)
     *
     * VERIFY always runs before DNS switch — never promote without verifying the clone works.
     */
    public static function promoteClone(int $accountId, int $peerId, string $dnsMode = 'auto', int $gracePeriodMinutes = 60, bool $syncFirst = true): array
    {
        $account = Database::fetchOne('SELECT * FROM hosting_accounts WHERE id = :id', ['id' => $accountId]);
        if (!$account) return ['ok' => false, 'error' => 'Account not found'];

        $peer = FederationService::getPeer($peerId);
        if (!$peer) return ['ok' => false, 'error' => 'Peer not found'];

        $clone = self::findCompletedClone($accountId, $peerId);
        if (!$clone) return ['ok' => false, 'error' => 'No completed clone found to promote'];

        // Check no active migration
        $active = Database::fetchOne(
            "SELECT id FROM hosting_migrations WHERE account_id = :aid AND status IN ('pending', 'running', 'paused')",
            ['aid' => $accountId]
        );
        if ($active) return ['ok' => false, 'error' => 'Account has an active migration'];

        $migrationId = self::generateMigrationId();
        $user = \MuseDockPanel\Auth::user();

        // Always start at LOCK if syncing, VERIFY if not
        // VERIFY always runs before DNS switch — this is a hard invariant
        $startStep = $syncFirst ? self::STEP_LOCK : self::STEP_VERIFY;

        $id = Database::insert('hosting_migrations', [
            'migration_id'         => $migrationId,
            'account_id'           => $accountId,
            'peer_id'              => $peerId,
            'mode'                 => 'promote',
            'direction'            => 'outgoing',
            'status'               => self::STATUS_PENDING,
            'current_step'         => $startStep,
            'dry_run'              => 'false',
            'grace_period_minutes' => $gracePeriodMinutes,
            'metadata'             => json_encode([
                'dns_mode' => $dnsMode,
                'parent_clone_id' => $clone['migration_id'],
                'sync_first' => $syncFirst,
            ]),
            'created_by'           => $user['id'] ?? null,
        ]);

        self::log($migrationId, $startStep, 'info', "Promoting clone to primary for {$account['domain']}", [
            'peer' => $peer['name'], 'dns_mode' => $dnsMode, 'sync_first' => $syncFirst,
        ]);

        // If syncFirst: do an incremental sync before the formal promote flow
        if ($syncFirst) {
            $updateResult = self::updateClone($accountId, $peerId);
            if (!$updateResult['ok']) {
                self::updateStatus($migrationId, self::STATUS_FAILED, [
                    'error_message' => 'Pre-promote sync failed: ' . ($updateResult['error'] ?? ''),
                    'completed_at' => date('Y-m-d H:i:s'),
                ]);
                return ['ok' => false, 'error' => 'Pre-promote sync failed: ' . ($updateResult['error'] ?? '')];
            }
            self::log($migrationId, 'sync', 'info', 'Pre-promote sync completed');
        }

        // Run the promote flow: LOCK → FREEZE → FINAL_SYNC → VERIFY → SWITCH_DNS → COMPLETE
        // (or VERIFY → SWITCH_DNS → COMPLETE if no sync)
        self::updateStatus($migrationId, self::STATUS_RUNNING, [
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        $result = self::runAll($migrationId);

        LogService::log('federation.clone.promote', $account['domain'],
            "Clone promoted on {$peer['name']}: " . ($result['status'] ?? 'unknown'));

        return $result;
    }

    /**
     * Verify admin password for sensitive clone actions.
     */
    public static function verifyAdminPassword(string $password): bool
    {
        $user = \MuseDockPanel\Auth::user();
        if (!$user) return false;

        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $user['id']]);
        if (!$admin) return false;

        return password_verify($password, $admin['password_hash']);
    }
}
