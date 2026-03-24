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

    $warningServers = [];

    foreach ($checks as $serverId => $check) {
        if (!isset($counters[$serverId])) {
            $counters[$serverId] = ['fail_count' => 0, 'ok_count' => 0, 'last_status' => 'unknown'];
        }

        $c = &$counters[$serverId];
        $wasDown = ($c['last_status'] === 'down');
        $severity = $check['severity'] ?? ($check['ok'] ? 'ok' : 'critical');

        if ($severity === 'warning') {
            // ─── WARNING: notify but do NOT count as failure ───
            $c['fail_count'] = 0; // reset fail counter — warnings don't trigger failover
            $warningServers[] = $serverId;
            logMsg("Server {$check['name']} ({$serverId}) WARNING: degraded but operational");

            // If it was down, warnings count towards recovery
            if ($wasDown) {
                $c['ok_count']++;
                if ($c['ok_count'] >= $upThreshold) {
                    $c['last_status'] = 'ok';
                    $newlyUp[] = $serverId;
                    logMsg("Server {$check['name']} ({$serverId}) RECOVERED (warning-level, but services reachable)");
                }
            } elseif ($c['last_status'] !== 'ok') {
                $c['last_status'] = 'ok'; // warning is still "up" for failover purposes
            }

        } elseif ($severity === 'ok' || (!empty($check['ok']) && $severity !== 'critical')) {
            // ─── OK: server is healthy ────────────────────────
            $c['fail_count'] = 0;
            $c['ok_count']++;

            if ($wasDown && $c['ok_count'] >= $upThreshold) {
                $c['last_status'] = 'ok';
                $newlyUp[] = $serverId;
                logMsg("Server {$check['name']} ({$serverId}) RECOVERED after {$upThreshold} consecutive OK checks");
            } elseif (!$wasDown && $c['last_status'] !== 'ok') {
                $c['last_status'] = 'ok';
            }

        } else {
            // ─── CRITICAL: server is down for failover purposes ─
            $c['ok_count'] = 0;
            $c['fail_count']++;

            if (!$wasDown && $c['fail_count'] >= $downThreshold) {
                $c['last_status'] = 'down';
                $newlyDown[] = $serverId;
                logMsg("Server {$check['name']} ({$serverId}) DOWN after {$downThreshold} consecutive CRITICAL failures: " . ($check['error'] ?? 'unknown'));
            } elseif ($wasDown) {
                logMsg("Server {$check['name']} ({$serverId}) still DOWN (fail #{$c['fail_count']})");
            } else {
                logMsg("Server {$check['name']} ({$serverId}) CRITICAL (#{$c['fail_count']}/{$downThreshold})");
            }
        }
        unset($c);
    }

    // Send warning notifications (any mode — always notify for warnings)
    if (!empty($warningServers)) {
        $lastWarningNotif = Settings::get('failover_last_warning_notif', '');
        $warningInterval = 900; // notify at most every 15 minutes for warnings
        if (!$lastWarningNotif || (time() - strtotime($lastWarningNotif)) >= $warningInterval) {
            $warningNames = array_map(fn($id) => ($checks[$id]['name'] ?? $id) . ' (' . implode(', ', array_keys(array_filter($checks[$id]['checks'] ?? [], fn($c) => ($c['status'] ?? 'ok') === 'warning'))) . ')', $warningServers);
            NotificationService::send(
                "Failover: servidores con warnings",
                "Los siguientes servidores tienen alertas (NO se ha disparado failover):\n\n" .
                implode("\n", $warningNames) . "\n\n" .
                "Revisa el panel para más detalles."
            );
            Settings::set('failover_last_warning_notif', date('Y-m-d H:i:s'));
            logMsg("Warning notification sent for " . count($warningServers) . " servers");
        }
    }

    // Save updated counters
    Settings::set('failover_health_counters', json_encode($counters));

    // ─── 3. Evaluate what state we should be in ────────────────
    // Build a "virtual" check result using confirmed status (not raw checks)
    $confirmedChecks = [];
    foreach ($checks as $serverId => $check) {
        $confirmedChecks[$serverId] = $check;
        $status = $counters[$serverId]['last_status'] ?? 'unknown';
        if ($status === 'down') {
            $confirmedChecks[$serverId]['ok'] = false;
        } elseif ($status === 'ok') {
            $confirmedChecks[$serverId]['ok'] = true;
        }
    }

    $currentState = FailoverService::getState();
    $shouldBe = FailoverService::evaluateState($confirmedChecks);

    logMsg("Current state: {$currentState} | Should be: {$shouldBe}");

    if ($shouldBe !== $currentState) {
        $stateChanged = true;
        $isFailback = ($shouldBe === FailoverService::STATE_NORMAL && $currentState !== FailoverService::STATE_NORMAL);
        $isFailover = in_array($shouldBe, [FailoverService::STATE_DEGRADED, FailoverService::STATE_PRIMARY_DOWN, FailoverService::STATE_EMERGENCY]);

        // ─── RESYNC: always runs automatically when server recovers, regardless of mode ───
        if ($isFailback) {
            $resyncStatus = Settings::get('failover_resync_status', '');
            if ($resyncStatus !== 'completed') {
                logMsg("Server recovered — running mandatory resync before failback (any mode)");
                $resyncResult = autoFailbackResync($foConfig, false); // false = don't demote yet
                if ($resyncResult) {
                    Settings::set('failover_resync_status', 'completed');
                    logMsg("Resync completed — data is ready for failback");
                } else {
                    Settings::set('failover_resync_status', 'failed');
                    logMsg("Resync FAILED — failback blocked until resync succeeds");
                    // Don't proceed with failback if resync failed
                    $isFailback = false;
                    $stateChanged = false;
                }
            }
        }

        if ($isFailover) {
            // ─── FAILOVER (down transition) ──────────────────
            if ($foMode === 'auto') {
                logMsg("AUTO mode — transitioning to {$shouldBe}");
                $result = FailoverService::transitionTo($shouldBe, 'auto-worker');
                logMsg("Transition result: " . implode('; ', $result['actions'] ?? []));

                if ($currentState === FailoverService::STATE_NORMAL) {
                    Settings::set('failover_activated_at', date('Y-m-d H:i:s'));
                    Settings::set('failover_resync_status', '');
                    logMsg("Failover activated — timestamp saved for resync");
                }

                autoPromoteIfNeeded($checks, $foConfig);

            } elseif ($foMode === 'semiauto') {
                $stateLabel = FailoverService::stateLabel($shouldBe);
                logMsg("SEMIAUTO mode — state should change to {$shouldBe}, notifying admin");

                NotificationService::send(
                    "Failover: acción requerida → {$stateLabel}",
                    "El estado del failover debería cambiar a: {$stateLabel}\n" .
                    "Estado actual: " . FailoverService::stateLabel($currentState) . "\n\n" .
                    "Servidores caídos: " . implode(', ', array_map(fn($id) => $checks[$id]['name'] ?? $id, $newlyDown)) . "\n\n" .
                    "Accede al panel para ejecutar el failover manualmente."
                );
                LogService::log('failover.semiauto', $shouldBe, "Estado recomendado: {$shouldBe}. Admin notificado.");
            }
            // manual mode: worker detects but does nothing, dashboard shows the state

        } elseif ($isFailback && $stateChanged) {
            // ─── FAILBACK (recovery transition) ──────────────
            // Resync already completed above. Now handle DNS revert according to mode.
            if ($foMode === 'auto') {
                logMsg("AUTO mode — failback: resync done, reverting DNS + demote");
                $result = FailoverService::transitionTo($shouldBe, 'auto-failback');
                logMsg("Failback result: " . implode('; ', $result['actions'] ?? []));
                autoFailbackDemote($foConfig);
                Settings::set('failover_resync_status', '');
                Settings::set('failover_activated_at', '');

            } elseif ($foMode === 'semiauto') {
                logMsg("SEMIAUTO mode — resync done, notifying admin to confirm failback");
                NotificationService::send(
                    "Failover: servidor recuperado — resync completado",
                    "El servidor principal se ha recuperado y los datos han sido sincronizados.\n\n" .
                    "Resync: COMPLETADO (pg_dump + rsync ejecutados)\n" .
                    "Servidores recuperados: " . implode(', ', array_map(fn($id) => $checks[$id]['name'] ?? $id, $newlyUp)) . "\n\n" .
                    "Accede al panel y pulsa 'Revertir Failover' para cambiar DNS de vuelta.\n" .
                    "El resync ya está hecho — solo falta confirmar el cambio DNS."
                );
                LogService::log('failover.semiauto', 'failback_ready',
                    "Resync completado. Esperando confirmación del admin para failback DNS.");

            } else {
                // manual: resync done automatically, dashboard shows "Revertir failover" button
                logMsg("MANUAL mode — resync done, waiting for admin to click failback button");
                Settings::set('failover_failback_ready', '1');
                LogService::log('failover.manual', 'failback_ready',
                    "Resync completado. Botón 'Revertir Failover' disponible en el dashboard.");
            }
        }
    } else {
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
 * Handle failback resync ONLY (no demote, no DNS change).
 * Resync runs automatically in ANY mode — it's a data integrity precaution, not a decision.
 * Returns true if resync succeeded, false if it failed.
 */
function autoFailbackResync(array $foConfig, bool $alsoDemote = false): bool
{
    $activatedAt = Settings::get('failover_activated_at', '');
    if (!$activatedAt) {
        logMsg("Failback resync: no activation timestamp — no data to sync");
        return true; // nothing to sync is not a failure
    }

    $clusterRole = Settings::get('cluster_role', 'standalone');
    $envRole = \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
    $localRole = ($clusterRole !== '' && $clusterRole !== 'standalone') ? $clusterRole : $envRole;

    if ($localRole !== 'master') {
        logMsg("Failback resync: local role is '{$localRole}', not temporary master — no resync needed");
        return true;
    }

    logMsg("Failback resync: we are temporary master since {$activatedAt}");

    $originalMasterIp = Settings::get('failover_original_master_ip', '');
    if (!$originalMasterIp) {
        logMsg("Failback resync: no original master IP stored — manual resync needed");
        NotificationService::send(
            "Failover: Resync manual necesario",
            "El servidor original ha vuelto a estar disponible, pero no se puede hacer resync automático.\n" .
            "No hay IP del master original guardada.\n" .
            "Ejecute el resync manualmente antes de hacer failback."
        );
        return false;
    }

    logMsg("Failback resync: syncing data to original master ({$originalMasterIp})...");
    $resyncLog = [];
    $hasErrors = false;

    // 1. PostgreSQL: dump data that may have changed
    try {
        $dumpFile = PANEL_ROOT . '/storage/failover-resync-' . date('YmdHis') . '.sql.gz';
        $cmd = "sudo -u postgres pg_dump -p 5433 musedock_panel --data-only " .
               "--table=hosting_accounts --table=panel_settings --table=dns_records " .
               "--table=email_accounts --table=databases " .
               "2>/dev/null | gzip > {$dumpFile}";
        shell_exec($cmd);

        if (file_exists($dumpFile) && filesize($dumpFile) > 20) {
            $resyncLog[] = "PostgreSQL dump created: {$dumpFile}";

            $panelPort = (int)Settings::get('panel_port', '8444') ?: 8444;
            $token = Settings::get('cluster_api_token', '');
            if ($token) {
                $pushCmd = "curl -sk -X POST 'https://{$originalMasterIp}:{$panelPort}/api/cluster/action' " .
                           "-H 'X-Panel-Token: {$token}' " .
                           "-F 'action=restore-panel-dump' " .
                           "-F 'dump=@{$dumpFile}' " .
                           "-F 'since={$activatedAt}' 2>/dev/null";
                $pushResult = shell_exec($pushCmd);
                $resyncLog[] = "PostgreSQL push: " . ($pushResult ?: 'no response');
            } else {
                $resyncLog[] = "WARNING: No cluster API token — PostgreSQL dump created but not pushed";
                $hasErrors = true;
            }
        }
    } catch (\Throwable $e) {
        $resyncLog[] = "PostgreSQL resync error: " . $e->getMessage();
        $hasErrors = true;
    }

    // 2. Files: rsync vhosts + certificates to original master
    try {
        $sshKey = PANEL_ROOT . '/storage/.ssh/cluster_key';
        if (file_exists($sshKey)) {
            $rsyncCmd = "rsync -az --delete -e 'ssh -i {$sshKey} -o StrictHostKeyChecking=no -p 22' " .
                        "/var/www/vhosts/ root@{$originalMasterIp}:/var/www/vhosts/ 2>&1";
            $rsyncResult = shell_exec($rsyncCmd);
            $vhostsOk = !str_contains($rsyncResult ?? '', 'error');
            $resyncLog[] = "Vhosts rsync: " . ($vhostsOk ? "OK" : "ERROR: {$rsyncResult}");
            if (!$vhostsOk) $hasErrors = true;

            $certCmd = "rsync -az -e 'ssh -i {$sshKey} -o StrictHostKeyChecking=no -p 22' " .
                       "/var/lib/caddy/.local/share/caddy/ root@{$originalMasterIp}:/var/lib/caddy/.local/share/caddy/ 2>&1";
            $certResult = shell_exec($certCmd);
            $certsOk = !str_contains($certResult ?? '', 'error');
            $resyncLog[] = "Certificates rsync: " . ($certsOk ? "OK" : "ERROR: {$certResult}");
            if (!$certsOk) $hasErrors = true;
        } else {
            $resyncLog[] = "WARNING: No SSH key — manual file sync needed";
            $hasErrors = true;
        }
    } catch (\Throwable $e) {
        $resyncLog[] = "File rsync error: " . $e->getMessage();
        $hasErrors = true;
    }

    // Log resync
    $resyncSummary = implode("\n", $resyncLog);
    logMsg("Failback resync " . ($hasErrors ? "completed WITH ERRORS" : "completed OK") . ":\n" . $resyncSummary);
    LogService::log('failover.resync', $hasErrors ? 'partial' : 'complete', $resyncSummary);

    // Optionally demote (only in auto mode, called separately)
    if ($alsoDemote) {
        autoFailbackDemote($foConfig);
    }

    return !$hasErrors;
}

/**
 * Demote this server back to slave after failback.
 * Called separately from resync so that mode logic controls when this happens.
 */
function autoFailbackDemote(array $foConfig): void
{
    $originalMasterIp = Settings::get('failover_original_master_ip', '');
    if (!$originalMasterIp) {
        logMsg("Failback demote: no original master IP — skipping");
        return;
    }

    logMsg("Failback demote: demoting self back to slave, master: {$originalMasterIp}");
    try {
        $demoteResult = \MuseDockPanel\Services\ClusterService::demoteToSlave($originalMasterIp);
        if (!empty($demoteResult['ok'])) {
            logMsg("Failback demote: SUCCESS — back to slave");
            Settings::set('failover_activated_at', '');
            Settings::set('failover_original_master_ip', '');
            Settings::set('failover_resync_status', '');
            Settings::set('failover_failback_ready', '');
            LogService::log('failover.auto_demote', 'slave', "Auto-demoted back to slave after failback");
            NotificationService::send(
                "Failover: Failback completado",
                "Este servidor vuelve a ser slave.\n" .
                "Master original ({$originalMasterIp}) restaurado.\n" .
                "Timestamp: " . date('Y-m-d H:i:s')
            );
        } else {
            $errors = implode(', ', $demoteResult['errors'] ?? []);
            logMsg("Failback demote: FAILED — {$errors}");
        }
    } catch (\Throwable $e) {
        logMsg("Failback demote: EXCEPTION — " . $e->getMessage());
    }
}
