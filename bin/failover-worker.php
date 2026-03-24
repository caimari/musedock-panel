#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — Failover Worker
 *
 * Runs as cron every minute. Performs:
 * 1. Health check all configured servers via /api/health endpoint
 * 2. Track consecutive failures/recoveries per server
 * 3. Evaluate state and auto-transition if mode is 'auto' or 'semiauto'
 * 4. In 'semiauto' mode: only notify, don't transition
 * 5. In 'auto' mode: transition automatically + integrate with cluster promote/demote
 *
 * Usage:
 *   php bin/failover-worker.php
 */

define('PANEL_ROOT', dirname(__DIR__));
define('PANEL_VERSION', '1.0.4');

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

// Load .env and config
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');
$config = require PANEL_ROOT . '/config/panel.php';

// Lock file to prevent overlapping runs
$lockFile = PANEL_ROOT . '/storage/failover-worker.lock';
$lockFp = fopen($lockFile, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    exit(0);
}
ftruncate($lockFp, 0);
fwrite($lockFp, (string)getmypid());

$startTime = microtime(true);
$logLines = [];

function logMsg(string $msg): void
{
    global $logLines;
    $logLines[] = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
}

use MuseDockPanel\Settings;
use MuseDockPanel\Services\FailoverService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\NotificationService;

try {
    $foConfig = FailoverService::getConfig();
    $foMode = $foConfig['failover_mode'] ?? 'manual';
    $servers = FailoverService::getServers();

    // Only run if mode is auto or semiauto
    if ($foMode === 'manual') {
        logMsg("Mode: manual — skipping automatic checks");
        goto cleanup;
    }

    if (empty($servers)) {
        logMsg("No servers configured — skipping");
        goto cleanup;
    }

    // ─── 1. Health check all servers ───────────────────────────
    logMsg("Running health checks ({$foMode} mode)...");
    $checks = FailoverService::checkAllEndpoints();

    // ─── 2. Track consecutive failures/recoveries ──────────────
    // Stored as JSON: { "server_id": { "fail_count": N, "ok_count": N, "last_status": "ok"|"down" } }
    $countersJson = Settings::get('failover_health_counters', '{}');
    $counters = json_decode($countersJson, true) ?: [];

    $downThreshold = (int)($foConfig['failover_down_threshold'] ?: 3);
    $upThreshold = (int)($foConfig['failover_up_threshold'] ?: 5);

    $stateChanged = false;
    $newlyDown = [];
    $newlyUp = [];

    foreach ($checks as $serverId => $check) {
        if (!isset($counters[$serverId])) {
            $counters[$serverId] = ['fail_count' => 0, 'ok_count' => 0, 'last_status' => 'unknown'];
        }

        $c = &$counters[$serverId];
        $wasDown = ($c['last_status'] === 'down');

        if (!empty($check['ok'])) {
            // Server is UP
            $c['fail_count'] = 0;
            $c['ok_count']++;

            if ($wasDown && $c['ok_count'] >= $upThreshold) {
                // Server recovered after N consecutive OK checks
                $c['last_status'] = 'ok';
                $newlyUp[] = $serverId;
                logMsg("Server {$check['name']} ({$serverId}) RECOVERED after {$upThreshold} consecutive OK checks");
            } elseif (!$wasDown && $c['last_status'] !== 'ok') {
                $c['last_status'] = 'ok';
            }
        } else {
            // Server is DOWN
            $c['ok_count'] = 0;
            $c['fail_count']++;

            if (!$wasDown && $c['fail_count'] >= $downThreshold) {
                // Server confirmed DOWN after N consecutive failures
                $c['last_status'] = 'down';
                $newlyDown[] = $serverId;
                logMsg("Server {$check['name']} ({$serverId}) DOWN after {$downThreshold} consecutive failures: " . ($check['error'] ?? 'unknown'));
            } elseif ($wasDown) {
                logMsg("Server {$check['name']} ({$serverId}) still DOWN (fail #{$c['fail_count']})");
            } else {
                logMsg("Server {$check['name']} ({$serverId}) failing (#{$c['fail_count']}/{$downThreshold})");
            }
        }
        unset($c);
    }

    // Save updated counters
    Settings::set('failover_health_counters', json_encode($counters));

    // ─── 3. Evaluate what state we should be in ────────────────
    // Build a "virtual" check result using confirmed status (not raw checks)
    $confirmedChecks = [];
    foreach ($checks as $serverId => $check) {
        $confirmedChecks[$serverId] = $check;
        $status = $counters[$serverId]['last_status'] ?? 'unknown';
        // Override: if counter says "down" but threshold not yet met for recovery, keep as down
        if ($status === 'down') {
            $confirmedChecks[$serverId]['ok'] = false;
        } elseif ($status === 'ok') {
            $confirmedChecks[$serverId]['ok'] = true;
        }
        // If unknown, use raw check result
    }

    $currentState = FailoverService::getState();
    $shouldBe = FailoverService::evaluateState($confirmedChecks);

    logMsg("Current state: {$currentState} | Should be: {$shouldBe}");

    if ($shouldBe !== $currentState) {
        $stateChanged = true;

        if ($foMode === 'auto') {
            // ─── AUTO: transition immediately ──────────────────
            logMsg("AUTO mode — transitioning to {$shouldBe}");
            $result = FailoverService::transitionTo($shouldBe, 'auto-worker');
            logMsg("Transition result: " . implode('; ', $result['actions'] ?? []));

            // ─── Integrate with cluster: auto promote/demote ───
            if (in_array($shouldBe, [FailoverService::STATE_DEGRADED, FailoverService::STATE_PRIMARY_DOWN, FailoverService::STATE_EMERGENCY])) {
                // Save failover activation timestamp for resync later
                if ($currentState === FailoverService::STATE_NORMAL) {
                    Settings::set('failover_activated_at', date('Y-m-d H:i:s'));
                    logMsg("Failover activated — timestamp saved for resync");
                }

                // Auto-promote: if a failover server is now receiving traffic, promote it to master
                autoPromoteIfNeeded($checks, $foConfig);

            } elseif ($shouldBe === FailoverService::STATE_NORMAL && $currentState !== FailoverService::STATE_NORMAL) {
                // Failback — handle resync before completing
                logMsg("Failback detected — initiating resync check");
                autoFailbackResync($foConfig);
            }

        } elseif ($foMode === 'semiauto') {
            // ─── SEMIAUTO: notify only, don't transition ───────
            $stateLabel = FailoverService::stateLabel($shouldBe);
            logMsg("SEMIAUTO mode — state should change to {$shouldBe}, notifying admin");

            NotificationService::send(
                "Failover: acción requerida → {$stateLabel}",
                "El estado del failover debería cambiar a: {$stateLabel}\n" .
                "Estado actual: " . FailoverService::stateLabel($currentState) . "\n\n" .
                "Servidores caídos: " . implode(', ', array_map(fn($id) => $checks[$id]['name'] ?? $id, $newlyDown)) . "\n" .
                "Servidores recuperados: " . implode(', ', array_map(fn($id) => $checks[$id]['name'] ?? $id, $newlyUp)) . "\n\n" .
                "Accede al panel para ejecutar el failover manualmente."
            );

            LogService::log('failover.semiauto', $shouldBe,
                "Estado recomendado: {$shouldBe}. Admin notificado.");
        }
    } else {
        // State hasn't changed — log summary
        $upCount = count(array_filter($confirmedChecks, fn($c) => !empty($c['ok'])));
        $totalCount = count($confirmedChecks);
        logMsg("No state change needed. Servers: {$upCount}/{$totalCount} UP");
    }

} catch (\Throwable $e) {
    logMsg("ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
}

cleanup:

// Log output
$elapsed = round((microtime(true) - $startTime) * 1000);
logMsg("Worker completed in {$elapsed}ms");

if (!empty($logLines)) {
    echo implode("\n", $logLines) . "\n";
}

// Release lock
flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink($lockFile);

exit(0);

// ═══════════════════════════════════════════════════════════
// ─── Helper Functions ──────────────────────────────────────
// ═══════════════════════════════════════════════════════════

/**
 * Auto-promote a failover server to master if it's now the active receiver.
 */
function autoPromoteIfNeeded(array $checks, array $foConfig): void
{
    $clusterRole = Settings::get('cluster_role', 'standalone');
    $envRole = \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
    $localRole = ($clusterRole !== '' && $clusterRole !== 'standalone') ? $clusterRole : $envRole;

    // Only promote if THIS server is a slave that should become master
    if ($localRole !== 'slave') {
        logMsg("Auto-promote: local role is '{$localRole}', not slave — skipping");
        return;
    }

    // Check if the current master is among the servers that are down
    $masterIp = Settings::get('cluster_master_ip', '');
    if (!$masterIp) {
        logMsg("Auto-promote: no master IP known — skipping");
        return;
    }

    // Is the master down in our health checks?
    $masterDown = false;
    foreach ($checks as $check) {
        if (($check['ip'] ?? '') === $masterIp && empty($check['ok'])) {
            $masterDown = true;
            break;
        }
    }

    if (!$masterDown) {
        logMsg("Auto-promote: master ({$masterIp}) is not in failed checks — skipping");
        return;
    }

    // Master is down and we're slave — promote ourselves
    logMsg("Auto-promote: master ({$masterIp}) is DOWN, promoting this slave to master");

    // Save original master IP for resync during failback
    Settings::set('failover_original_master_ip', $masterIp);

    try {
        $result = \MuseDockPanel\Services\ClusterService::promoteToMaster();
        if (!empty($result['ok'])) {
            logMsg("Auto-promote: SUCCESS — this server is now master");
            LogService::log('failover.auto_promote', 'master', "Slave auto-promoted to master because master ({$masterIp}) is down");
            NotificationService::send(
                "Failover: Auto-Promote ejecutado",
                "Este servidor ha sido promovido automáticamente a Master.\n" .
                "Master anterior ({$masterIp}) no responde.\n" .
                "Timestamp: " . date('Y-m-d H:i:s')
            );
        } else {
            $errors = implode(', ', $result['errors'] ?? ['Unknown error']);
            logMsg("Auto-promote: FAILED — {$errors}");
            LogService::log('failover.auto_promote', 'error', "Auto-promote failed: {$errors}");
        }
    } catch (\Throwable $e) {
        logMsg("Auto-promote: EXCEPTION — " . $e->getMessage());
    }
}

/**
 * Handle failback resync: sync data from temporary master back to original master.
 */
function autoFailbackResync(array $foConfig): void
{
    $activatedAt = Settings::get('failover_activated_at', '');
    if (!$activatedAt) {
        logMsg("Failback resync: no activation timestamp — skipping resync");
        return;
    }

    $clusterRole = Settings::get('cluster_role', 'standalone');
    $envRole = \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
    $localRole = ($clusterRole !== '' && $clusterRole !== 'standalone') ? $clusterRole : $envRole;

    // If we're master (promoted during failover), we need to push data to the recovering original master
    if ($localRole === 'master') {
        logMsg("Failback resync: we are temporary master since {$activatedAt}");

        // Get the original master IP (stored when we were promoted)
        $originalMasterIp = Settings::get('failover_original_master_ip', '');
        if (!$originalMasterIp) {
            logMsg("Failback resync: no original master IP stored — manual resync needed");
            NotificationService::send(
                "Failover: Resync manual necesario",
                "El servidor original ha vuelto a estar disponible, pero no se puede hacer resync automático.\n" .
                "No hay IP del master original guardada.\n" .
                "Ejecute el resync manualmente antes de hacer failback."
            );
            return;
        }

        logMsg("Failback resync: syncing data to original master ({$originalMasterIp})...");

        $resyncLog = [];

        // 1. PostgreSQL: use pg_dump of changes since activation
        try {
            // Export tables that might have changed (hosting_accounts, panel settings, etc.)
            $dumpFile = PANEL_ROOT . '/storage/failover-resync-' . date('YmdHis') . '.sql.gz';
            $cmd = "sudo -u postgres pg_dump -p 5433 musedock_panel --data-only " .
                   "--table=hosting_accounts --table=panel_settings --table=dns_records " .
                   "--table=email_accounts --table=databases " .
                   "2>/dev/null | gzip > {$dumpFile}";
            shell_exec($cmd);

            if (file_exists($dumpFile) && filesize($dumpFile) > 20) {
                $resyncLog[] = "PostgreSQL dump created: {$dumpFile}";

                // Push to original master via cluster API
                $panelPort = (int)Settings::get('panel_port', '8444') ?: 8444;
                $token = Settings::get('cluster_api_token', '');
                if ($token) {
                    $pushCmd = "curl -sk -X POST 'https://{$originalMasterIp}:{$panelPort}/api/cluster/action' " .
                               "-H 'X-Panel-Token: {$token}' " .
                               "-F 'action=restore-panel-dump' " .
                               "-F 'dump=@{$dumpFile}' " .
                               "-F 'since={$activatedAt}' 2>/dev/null";
                    $pushResult = shell_exec($pushCmd);
                    $resyncLog[] = "PostgreSQL push result: " . ($pushResult ?: 'no response');
                } else {
                    $resyncLog[] = "No cluster API token — cannot push PostgreSQL dump";
                }
            }
        } catch (\Throwable $e) {
            $resyncLog[] = "PostgreSQL resync error: " . $e->getMessage();
        }

        // 2. Files: rsync uploads/vhosts to original master
        try {
            $sshKey = PANEL_ROOT . '/storage/.ssh/cluster_key';
            if (file_exists($sshKey)) {
                // Sync vhosts (websites data)
                $rsyncCmd = "rsync -az --delete -e 'ssh -i {$sshKey} -o StrictHostKeyChecking=no -p 22' " .
                            "/var/www/vhosts/ root@{$originalMasterIp}:/var/www/vhosts/ 2>&1";
                $rsyncResult = shell_exec($rsyncCmd);
                $resyncLog[] = "Vhosts rsync: " . (str_contains($rsyncResult, 'error') ? "ERROR: {$rsyncResult}" : "OK");

                // Sync SSL certificates
                $certCmd = "rsync -az -e 'ssh -i {$sshKey} -o StrictHostKeyChecking=no -p 22' " .
                           "/var/lib/caddy/.local/share/caddy/ root@{$originalMasterIp}:/var/lib/caddy/.local/share/caddy/ 2>&1";
                $certResult = shell_exec($certCmd);
                $resyncLog[] = "Certificates rsync: " . (str_contains($certResult, 'error') ? "ERROR: {$certResult}" : "OK");
            } else {
                $resyncLog[] = "No SSH key for file sync — manual file sync needed";
            }
        } catch (\Throwable $e) {
            $resyncLog[] = "File rsync error: " . $e->getMessage();
        }

        // Log resync results
        $resyncSummary = implode("\n", $resyncLog);
        logMsg("Failback resync completed:\n" . $resyncSummary);
        LogService::log('failover.resync', 'complete', $resyncSummary);

        // Now demote ourselves back to slave
        logMsg("Failback: demoting self back to slave, master: {$originalMasterIp}");
        try {
            $demoteResult = \MuseDockPanel\Services\ClusterService::demoteToSlave($originalMasterIp);
            if (!empty($demoteResult['ok'])) {
                logMsg("Failback demote: SUCCESS — back to slave");
                Settings::set('failover_activated_at', '');
                Settings::set('failover_original_master_ip', '');
                LogService::log('failover.auto_demote', 'slave', "Auto-demoted back to slave after failback resync");
            } else {
                $errors = implode(', ', $demoteResult['errors'] ?? []);
                logMsg("Failback demote: FAILED — {$errors}");
            }
        } catch (\Throwable $e) {
            logMsg("Failback demote: EXCEPTION — " . $e->getMessage());
        }

        NotificationService::send(
            "Failover: Failback + Resync completado",
            "Resync:\n{$resyncSummary}\n\n" .
            "Este servidor vuelve a ser slave.\n" .
            "Master original ({$originalMasterIp}) restaurado."
        );
    } else {
        logMsg("Failback resync: local role is '{$localRole}', not temporary master — no resync needed");
        // Clear activation timestamp since we're returning to normal
        Settings::set('failover_activated_at', '');
    }
}
