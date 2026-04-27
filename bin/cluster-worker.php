#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — Cluster Worker
 *
 * Runs as cron every minute. Performs:
 * 1. Process pending queue items
 * 2. Send heartbeat to all nodes
 * 3. Check for unreachable nodes and send alerts
 * 4. Log results
 *
 * Usage:
 *   php bin/cluster-worker.php
 *   (or via cron: * * * * * /usr/bin/php /opt/musedock-panel/bin/cluster-worker.php >> /opt/musedock-panel/storage/logs/cluster-worker.log 2>&1)
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
$lockFile = PANEL_ROOT . '/storage/cluster-worker.lock';
$lockFp = fopen($lockFile, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Another instance is running
    fclose($lockFp);
    exit(0);
}

// Write PID
ftruncate($lockFp, 0);
fwrite($lockFp, (string)getmypid());

$startTime = microtime(true);
$logLines = [];

function logMsg(string $msg): void
{
    global $logLines;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $logLines[] = $line;
}

function pushMonitorAlert(string $type, string $message, float $value = 0.0, ?string $details = null, int $cooldownSeconds = 900): void
{
    try {
        $host = gethostname() ?: 'localhost';
        $cooldownSeconds = max(60, min(86400, $cooldownSeconds));
        $recent = \MuseDockPanel\Database::fetchOne(
            "SELECT id FROM monitor_alerts
             WHERE host = :host
               AND type = :type
               AND ts > NOW() - (CAST(:cooldown_seconds AS integer) * INTERVAL '1 second')",
            [
                'host' => $host,
                'type' => $type,
                'cooldown_seconds' => $cooldownSeconds,
            ]
        );
        if ($recent) {
            return;
        }

        \MuseDockPanel\Database::insert('monitor_alerts', [
            'host' => $host,
            'type' => $type,
            'message' => $message,
            'value' => $value,
            'details' => $details,
        ]);
    } catch (\Throwable $e) {
        logMsg("  Monitor alert insert error ({$type}): " . $e->getMessage());
    }
}

try {
    // Verify database connectivity
    \MuseDockPanel\Database::connect();
} catch (\Throwable $e) {
    logMsg("ERROR: Database connection failed: " . $e->getMessage());
    goto cleanup;
}

use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\FileSyncService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Settings;

// ─── Step 1: Process pending queue items ──────────────────────
logMsg("Processing queue...");
try {
    $queueResults = ClusterService::processQueue();
    $ok = 0;
    $fail = 0;
    foreach ($queueResults as $r) {
        if ($r['ok']) {
            $ok++;
            logMsg("  Queue item #{$r['id']} ({$r['action']}): OK");
        } else {
            $fail++;
            logMsg("  Queue item #{$r['id']} ({$r['action']}): FAILED - " . ($r['error'] ?? '?'));
        }
    }
    if (empty($queueResults)) {
        logMsg("  No pending items.");
    } else {
        logMsg("  Queue processed: {$ok} OK, {$fail} failed.");
    }
} catch (\Throwable $e) {
    logMsg("ERROR processing queue: " . $e->getMessage());
}

// ─── Step 2: Send heartbeat to all nodes ──────────────────────
logMsg("Sending heartbeats...");
try {
    $heartbeatResults = ClusterService::checkAllNodes();
    foreach ($heartbeatResults as $hb) {
        $icon = $hb['ok'] ? 'OK' : 'FAIL';
        logMsg("  Node #{$hb['id']} ({$hb['name']}): {$icon}" . ($hb['error'] ? " - {$hb['error']}" : ''));
    }
    if (empty($heartbeatResults)) {
        logMsg("  No nodes configured.");
    }
} catch (\Throwable $e) {
    logMsg("ERROR sending heartbeats: " . $e->getMessage());
}

// ─── Step 2a: Reconciliation — query slaves that may have acted autonomously ──
// Only runs on master. When a slave reconnects after being on its own,
// check if it changed DNS locally (Camino B) before the master takes any action.
$myClusterRole = Settings::get('cluster_role', 'standalone');
if ($myClusterRole === 'master') {
    try {
        $nodes = ClusterService::getActiveNodes();
        foreach ($nodes as $node) {
            // Only check nodes that just came back online (status was 'offline' or 'error')
            $prevStatus = $node['status'] ?? '';
            if (!in_array($prevStatus, ['offline', 'error'])) continue;

            // Skip if heartbeat just failed (still offline)
            $justOnline = false;
            foreach ($heartbeatResults ?? [] as $hb) {
                if (($hb['id'] ?? 0) == $node['id'] && ($hb['ok'] ?? false)) {
                    $justOnline = true;
                    break;
                }
            }
            if (!$justOnline) continue;

            logMsg("  Reconciliation: node #{$node['id']} ({$node['name']}) just reconnected — querying local state...");
            try {
                $stateResp = ClusterService::callNode((int)$node['id'], 'POST', 'api/cluster/action', [
                    'action' => 'query-local-state',
                    'payload' => [],
                ]);

                if (($stateResp['ok'] ?? false) && !empty($stateResp['state'])) {
                    $remoteState = $stateResp['state'];
                    $dnsChanged = $remoteState['failover_dns_changed_locally'] ?? false;
                    $ifaceMode = $remoteState['failover_iface_mode'] ?? 'normal';
                    $remoteRole = $remoteState['cluster_role'] ?? 'slave';

                    logMsg("    Remote state: iface={$ifaceMode}, dns_changed={$dnsChanged}, role={$remoteRole}");

                    if ($dnsChanged) {
                        // Slave changed DNS autonomously — DON'T touch DNS until resolved
                        logMsg("    *** SLAVE CHANGED DNS LOCALLY (Camino B) — master will NOT modify DNS ***");
                        logMsg("    DNS was changed at: " . ($remoteState['failover_dns_changed_at'] ?? '?'));
                        \MuseDockPanel\Services\LogService::log('cluster.reconcile', $node['name'],
                            "Slave changed DNS autonomously while master was down. " .
                            "DNS state deferred to slave. Manual review recommended.");
                        \MuseDockPanel\Services\NotificationService::send(
                            "Reconciliación: {$node['name']} cambió DNS autónomamente",
                            "El nodo {$node['name']} cambió los DNS mientras el master estaba caído (Camino B).\n\n" .
                            "Estado interfaz: {$ifaceMode}\n" .
                            "Rol actual: {$remoteRole}\n" .
                            "DNS cambiado: " . ($remoteState['failover_dns_changed_at'] ?? '?') . "\n\n" .
                            "Revisa el estado del failover antes de tomar acciones."
                        );
                    } elseif ($ifaceMode === 'nat') {
                        logMsg("    Slave in NAT mode (Camino A already handled or in progress)");
                    }
                }
            } catch (\Throwable $re) {
                logMsg("    Reconciliation query failed: " . $re->getMessage());
            }
        }
    } catch (\Throwable $e) {
        logMsg("ERROR in reconciliation: " . $e->getMessage());
    }
}

// ─── Step 2b: Mail node health check ────────────────────────────
if (Settings::get('mail_enabled', '0') === '1') {
    logMsg("Checking mail nodes...");
    try {
        $mailNodes = \MuseDockPanel\Services\MailService::getMailNodes();
        foreach ($mailNodes as $mn) {
            if ($mn['standby'] ?? false) continue; // skip standby nodes

            $health = \MuseDockPanel\Services\MailService::checkMailNodeHealth((int)$mn['id']);
            if ($health['ok']) {
                logMsg("  Mail node #{$mn['id']} ({$mn['name']}): ALL OK");
            } else {
                $failedSvcs = [];
                foreach ($health['services'] ?? [] as $svc => $info) {
                    if (!$info['ok']) {
                        $portLabel = isset($info['port']) ? " (port {$info['port']})" : '';
                        $failedSvcs[] = "{$svc}{$portLabel}: " . ($info['error'] ?? '');
                    }
                }
                $dbHealth = $health['db_health'] ?? [];
                if (!empty($dbHealth['message'])) {
                    $failedSvcs[] = 'db: ' . $dbHealth['message'];
                }
                $failMsg = implode(', ', $failedSvcs) ?: 'unknown mail health failure';
                logMsg("  Mail node #{$mn['id']} ({$mn['name']}): DEGRADED - {$failMsg}");

                $alertKey = 'mail_node_degraded_alert_' . (int)$mn['id'];
                $lastAlert = (int)Settings::get($alertKey, '0');
                if ($lastAlert < time() - 3600) {
                    try {
                        \MuseDockPanel\Services\NotificationService::send(
                            "Mail Node Degraded: {$mn['name']}",
                            "Mail node {$mn['name']} has degraded services:\n{$failMsg}\n\nCheck mail services and local PostgreSQL replica on this node.",
                            'warning'
                        );
                        Settings::set($alertKey, (string)time());
                    } catch (\Throwable $ne) {
                        logMsg("  Failed to send mail node alert: " . $ne->getMessage());
                    }
                }
            }
        }
        if (empty($mailNodes)) {
            logMsg("  No mail nodes configured.");
        }

        $oldPaused = ClusterService::getOldPausedMailQueueItems(24);
        if (!empty($oldPaused)) {
            $lastAlert = (int)Settings::get('mail_queue_paused_alert_last', '0');
            if ($lastAlert < time() - 21600) {
                $first = $oldPaused[0];
                $count = count($oldPaused);
                \MuseDockPanel\Services\NotificationService::send(
                    "Cola mail pausada >24h",
                    "{$count} accion(es) de mail llevan mas de 24h pausadas. Primer nodo: " .
                    ($first['node_name'] ?? ('#' . ($first['node_id'] ?? '?'))) .
                    ". Motivo: " . ($first['paused_reason'] ?? '?'),
                    'warning'
                );
                Settings::set('mail_queue_paused_alert_last', (string)time());
            }
            logMsg("  Mail queue: " . count($oldPaused) . " item(s) paused for more than 24h.");
        }
    } catch (\Throwable $e) {
        logMsg("ERROR checking mail nodes: " . $e->getMessage());
    }
}

// ─── Step 2c: TLS trust / expiry alerts ───────────────────────
logMsg("Checking TLS trust/expiry...");
try {
    $tlsNodes = ClusterService::getNodes();
    $tlsAlertStateRaw = Settings::get('cluster_tls_alert_state', '{}');
    $tlsAlertState = json_decode($tlsAlertStateRaw, true);
    if (!is_array($tlsAlertState)) {
        $tlsAlertState = [];
    }

    $now = time();
    $seenTlsNodes = [];

    foreach ($tlsNodes as $node) {
        $nid = (string)($node['id'] ?? '0');
        if ($nid === '0') {
            continue;
        }
        $seenTlsNodes[] = $nid;

        if (!empty($node['standby'])) {
            logMsg("  Node #{$nid} ({$node['name']}): standby, skipping TLS alerts");
            continue;
        }

        $mutedUntil = Settings::get("cluster_node_{$nid}_muted_until", '');
        if ($mutedUntil && strtotime($mutedUntil) > $now) {
            logMsg("  Node #{$nid} ({$node['name']}): TLS alerts muted until {$mutedUntil}");
            continue;
        }

        $tls = ClusterService::getNodeTlsSummary($node);
        $tlsStatus = (string)($tls['status'] ?? 'info');
        $tlsMode = (string)($tls['mode'] ?? 'none');
        $daysLeft = $tls['days_left'] ?? null;
        $isExpiringCa = ($tlsMode === 'ca')
            && in_array($tlsStatus, ['warning', 'critical'], true)
            && is_numeric($daysLeft);

        $prev = $tlsAlertState[$nid] ?? null;

        if (!$isExpiringCa) {
            if (is_array($prev) && in_array((string)($prev['status'] ?? ''), ['warning', 'critical'], true)) {
                $nodeName = (string)($node['name'] ?? "#{$nid}");
                ClusterService::sendAlert(
                    "[MuseDock Cluster] TLS recuperado: {$nodeName}",
                    "El estado TLS del nodo {$nodeName} ha vuelto a normal.\n"
                    . "Estado actual: " . ($tls['summary'] ?? 'OK') . "\n"
                    . "Fecha: " . date('Y-m-d H:i:s')
                );
                LogService::log('cluster.tls', 'recovered', "Nodo {$nodeName} recuperado TLS");
                logMsg("  Node #{$nid} ({$nodeName}): TLS recovered, notification sent.");
            }
            unset($tlsAlertState[$nid]);
            continue;
        }

        $repeatEvery = ($tlsStatus === 'critical') ? 21600 : 86400; // critical: 6h, warning: 24h
        $lastAlert = (int)($prev['last_alert'] ?? 0);
        $prevStatus = (string)($prev['status'] ?? '');
        $statusChanged = ($prevStatus !== $tlsStatus);
        $dueByTime = ($lastAlert === 0) || (($now - $lastAlert) >= $repeatEvery);

        if ($statusChanged || $dueByTime) {
            $nodeName = (string)($node['name'] ?? "#{$nid}");
            $severity = $tlsStatus === 'critical' ? 'CRITICA' : 'WARNING';
            $subject = "[MuseDock Cluster] ALERTA TLS {$severity}: {$nodeName}";
            $body = "Nodo: {$nodeName}\n"
                . "URL: " . ($node['api_url'] ?? '') . "\n"
                . "Estado TLS: " . ($tls['summary'] ?? $tlsStatus) . "\n"
                . "Modo: {$tlsMode}\n"
                . "Dias restantes: " . (string)($daysLeft ?? '?') . "\n"
                . "Expira: " . (string)($tls['not_after'] ?? '?') . "\n"
                . "CA local: " . (string)($tls['ca_file'] ?? '-') . "\n"
                . "Detalle: " . (string)($tls['detail'] ?? '-') . "\n"
                . "Fecha: " . date('Y-m-d H:i:s') . "\n\n"
                . "Revise el nodo en /settings/cluster.";

            ClusterService::sendAlert($subject, $body);
            LogService::log('cluster.tls', 'expiry-alert', "Nodo {$nodeName} {$tlsStatus} ({$daysLeft} dias)");
            logMsg("  Node #{$nid} ({$nodeName}): TLS {$tlsStatus} alert sent.");

            $tlsAlertState[$nid] = [
                'status' => $tlsStatus,
                'last_alert' => $now,
                'summary' => (string)($tls['summary'] ?? ''),
            ];
        } else {
            $remaining = max(0, $repeatEvery - ($now - $lastAlert));
            logMsg("  Node #{$nid} ({$node['name']}): TLS {$tlsStatus} throttled, next alert in {$remaining}s.");
            $tlsAlertState[$nid] = [
                'status' => $tlsStatus,
                'last_alert' => $lastAlert,
                'summary' => (string)($tls['summary'] ?? ''),
            ];
        }
    }

    foreach (array_keys($tlsAlertState) as $nid) {
        if (!in_array((string)$nid, $seenTlsNodes, true)) {
            unset($tlsAlertState[$nid]);
        }
    }

    Settings::set('cluster_tls_alert_state', json_encode($tlsAlertState));
} catch (\Throwable $e) {
    logMsg("ERROR checking TLS expiry: " . $e->getMessage());
}

// ─── Step 2d: lsyncd health (sync congestion / SSH refused) ─────────────
logMsg("Checking lsyncd health...");
try {
    $fsCfg = FileSyncService::getConfig();
    $lsyncdMode = (string)($fsCfg['sync_mode'] ?? 'periodic');
    $lsyncdEnabled = (bool)($fsCfg['enabled'] ?? false);

    if ($lsyncdEnabled && $lsyncdMode === 'lsyncd') {
        $ls = FileSyncService::getLsyncdStatus();
        $now = time();
        $stateRaw = Settings::get('cluster_lsyncd_alert_state', '{}');
        $state = json_decode($stateRaw, true);
        if (!is_array($state)) $state = [];

        $isCritical = (($ls['health'] ?? 'ok') === 'critical');
        $hasConnRefused = !empty($ls['connection_refused_recent']);
        $hasIssue = $isCritical || $hasConnRefused;

        if ($hasIssue) {
            $badNodes = [];
            foreach (($ls['ssh_unreachable'] ?? []) as $n) {
                $badNodes[] = (string)($n['node_name'] ?? ($n['host'] ?? 'nodo'));
            }
            $badNodesTxt = !empty($badNodes) ? implode(', ', $badNodes) : 'sin identificar';
            $rssMb = (int)($ls['rss_mb'] ?? 0);
            $activeEvents = (int)($ls['active_events'] ?? 0);
            $logMb = (int)($ls['log_size_mb'] ?? 0);
            $recentErrors = trim((string)($ls['recent_errors'] ?? ''));
            $severity = $isCritical ? 'CRITICA' : 'WARNING';

            $lastAlert = (int)($state['last_alert'] ?? 0);
            $repeatEvery = $isCritical ? 900 : 1800; // 15m / 30m
            if ($lastAlert === 0 || ($now - $lastAlert) >= $repeatEvery) {
                $subject = "[MuseDock Cluster] ALERTA SYNC {$severity}: lsyncd congestionado";
                $body = "Se detectó un problema en sincronización de archivos (lsyncd).\n\n"
                    . "Modo: lsyncd\n"
                    . "Health: " . strtoupper((string)($ls['health'] ?? 'unknown')) . "\n"
                    . "SSH no accesible en: {$badNodesTxt}\n"
                    . "RSS lsyncd: {$rssMb} MB\n"
                    . "Eventos activos en cola: {$activeEvents}\n"
                    . "Tamaño log lsyncd: {$logMb} MB\n"
                    . "Errores recientes:\n" . ($recentErrors !== '' ? $recentErrors : '(sin detalle)') . "\n\n"
                    . "Acción recomendada: Cluster > Archivos > botón 'Autocorregir (contener)'.\n"
                    . "Fecha: " . date('Y-m-d H:i:s');

                \MuseDockPanel\Services\NotificationService::send($subject, $body);
                LogService::log('cluster.lsyncd', 'alert', "lsyncd issue: rss={$rssMb}MB queue={$activeEvents} bad_nodes={$badNodesTxt}");
                logMsg("  lsyncd alert sent ({$severity}): rss={$rssMb}MB, queue={$activeEvents}, bad_nodes={$badNodesTxt}");
                pushMonitorAlert(
                    'LSYNCD_SYNC_DEGRADED',
                    "lsyncd degradado: SSH no accesible hacia {$badNodesTxt}. Ir a /settings/cluster#archivos",
                    (float)$rssMb,
                    "Health: " . strtoupper((string)($ls['health'] ?? 'unknown'))
                    . "\nSSH no accesible: {$badNodesTxt}"
                    . "\nRSS: {$rssMb} MB"
                    . "\nCola activa: {$activeEvents}"
                    . "\nLog lsyncd: {$logMb} MB"
                    . "\n\nAcción recomendada: Cluster > Archivos > Autocorregir (contener).",
                    $repeatEvery
                );

                $state['active'] = 1;
                $state['last_alert'] = $now;
                $state['last_health'] = (string)($ls['health'] ?? 'unknown');
                $state['last_summary'] = "rss={$rssMb}MB queue={$activeEvents} nodes={$badNodesTxt}";
            } else {
                $remaining = $repeatEvery - ($now - $lastAlert);
                logMsg("  lsyncd issue throttled, next alert in {$remaining}s.");

                // Keep /monitor visibility even during throttling windows.
                // This avoids "silent" degraded states after deploy/restart.
                $host = gethostname() ?: 'localhost';
                $recentMonitor = \MuseDockPanel\Database::fetchOne(
                    "SELECT id FROM monitor_alerts
                     WHERE host = :host
                       AND type = 'LSYNCD_SYNC_DEGRADED'
                       AND ts > NOW() - INTERVAL '2 hours'
                     LIMIT 1",
                    ['host' => $host]
                );
                if (!$recentMonitor) {
                    pushMonitorAlert(
                        'LSYNCD_SYNC_DEGRADED',
                        "lsyncd degradado: SSH no accesible hacia {$badNodesTxt}. Ir a /settings/cluster#archivos",
                        (float)$rssMb,
                        "Health: " . strtoupper((string)($ls['health'] ?? 'unknown'))
                        . "\nSSH no accesible: {$badNodesTxt}"
                        . "\nRSS: {$rssMb} MB"
                        . "\nCola activa: {$activeEvents}"
                        . "\nLog lsyncd: {$logMb} MB"
                        . "\n\nAcción recomendada: Cluster > Archivos > Autocorregir (contener).",
                        300
                    );
                    logMsg("  lsyncd monitor alert inserted during throttling window.");
                }
            }

            // Optional auto-heal (disabled by default to avoid unexpected mode switches)
            $autoHeal = Settings::get('filesync_lsyncd_auto_heal', '0') === '1';
            if ($autoHeal) {
                $lastAutoHeal = (int)($state['last_autoheal'] ?? 0);
                if ($lastAutoHeal === 0 || ($now - $lastAutoHeal) >= 1800) {
                    $heal = FileSyncService::autoHealLsyncd();
                    $state['last_autoheal'] = $now;
                    $actions = implode(' | ', (array)($heal['actions'] ?? []));
                    LogService::log('cluster.lsyncd', 'autofix-auto', $actions !== '' ? $actions : 'autoheal executed');
                    logMsg("  lsyncd auto-heal executed: " . ($actions !== '' ? $actions : 'ok'));
                    \MuseDockPanel\Services\NotificationService::send(
                        '[MuseDock Cluster] lsyncd auto-heal ejecutado',
                        "Se ejecutó auto-heal en lsyncd.\nAcciones: " . ($actions !== '' ? $actions : 'N/A') . "\nFecha: " . date('Y-m-d H:i:s')
                    );
                }
            }
        } else {
            if (!empty($state['active'])) {
                \MuseDockPanel\Services\NotificationService::send(
                    '[MuseDock Cluster] lsyncd recuperado',
                    "El estado de lsyncd volvió a normal.\nFecha: " . date('Y-m-d H:i:s')
                );
                LogService::log('cluster.lsyncd', 'recovered', 'lsyncd back to normal');
                logMsg("  lsyncd recovered notification sent.");
                pushMonitorAlert(
                    'LSYNCD_SYNC_RECOVERED',
                    'lsyncd recuperado: sincronización en estado normal. Ver /settings/cluster#archivos',
                    0.0,
                    "Estado de lsyncd normalizado.\nFecha: " . date('Y-m-d H:i:s'),
                    3600
                );
            } else {
                logMsg("  lsyncd health OK.");
            }
            $state = [];
        }

        Settings::set('cluster_lsyncd_alert_state', json_encode($state));
    } else {
        logMsg("  lsyncd health check skipped (filesync disabled or mode={$lsyncdMode}).");
    }
} catch (\Throwable $e) {
    logMsg("ERROR checking lsyncd health: " . $e->getMessage());
}

// ─── Step 3: Check for unreachable nodes (escalating alerts) ──
logMsg("Checking unreachable nodes...");
try {
    $timeoutMinutes = (int)(Settings::get('cluster_unreachable_timeout', '300')) / 60;
    if ($timeoutMinutes < 1) $timeoutMinutes = 5;

    $unreachable = ClusterService::getUnreachableNodes((int)$timeoutMinutes);

    // Load per-node alert state: JSON { nodeId: { "first_seen": ts, "last_alert": ts, "alert_count": n } }
    $alertStateRaw = Settings::get('cluster_node_alert_state', '{}');
    $alertState = json_decode($alertStateRaw, true) ?: [];

    // Escalating intervals in seconds: 1min, 5min, 15min, 30min, 45min, 60min, 2h, 4h, 8h, 12h
    $escalationIntervals = [60, 300, 900, 1800, 2700, 3600, 7200, 14400, 28800, 43200];

    $now = time();
    $unreachableIds = [];

    if (!empty($unreachable)) {
        logMsg("  Unreachable nodes: " . implode(', ', array_column($unreachable, 'name')));

        foreach ($unreachable as $node) {
            $nid = (string)$node['id'];
            $unreachableIds[] = $nid;

            // Skip standby nodes — they're intentionally offline
            if (!empty($node['standby'])) {
                logMsg("  Node #{$nid} ({$node['name']}): in standby, skipping alerts");
                continue;
            }

            // Check if alerts are muted for this node
            $mutedUntil = Settings::get("cluster_node_{$nid}_muted_until", '');
            if ($mutedUntil && strtotime($mutedUntil) > $now) {
                logMsg("  Node #{$nid} ({$node['name']}): alerts muted until {$mutedUntil}");
                continue;
            }

            // Initialize state for newly detected offline nodes
            if (!isset($alertState[$nid])) {
                $alertState[$nid] = [
                    'first_seen' => $now,
                    'last_alert' => 0,
                    'alert_count' => 0,
                ];
            }

            $state = &$alertState[$nid];
            $alertCount = (int)$state['alert_count'];
            $lastAlert = (int)$state['last_alert'];

            // Determine the required interval for the next alert
            $intervalIdx = min($alertCount, count($escalationIntervals) - 1);
            $requiredInterval = $escalationIntervals[$intervalIdx];

            $elapsed = $now - $lastAlert;

            if ($elapsed >= $requiredInterval) {
                // Time to send alert
                $downSince = date('Y-m-d H:i:s', (int)$state['first_seen']);
                $downMinutes = round(($now - (int)$state['first_seen']) / 60);
                $nextIdx = min($alertCount + 1, count($escalationIntervals) - 1);
                $nextMinutes = round($escalationIntervals[$nextIdx] / 60);

                $alertMsg = "Nodo caido: {$node['name']} ({$node['api_url']})\n"
                    . "Caido desde: {$downSince} ({$downMinutes} min)\n"
                    . "Alerta #{$state['alert_count']}+1\n"
                    . "Proxima alerta en: {$nextMinutes} min\n\n"
                    . "Silenciar alertas desde el dashboard:\n"
                    . "https://" . (\MuseDockPanel\Env::get('PANEL_DOMAIN', gethostname())) . ":8444/\n\n"
                    . "Fecha: " . date('Y-m-d H:i:s');

                ClusterService::sendAlert(
                    "[MuseDock Cluster] Nodo caido: {$node['name']}",
                    $alertMsg
                );

                $state['last_alert'] = $now;
                $state['alert_count']++;

                LogService::log('cluster.alert', 'unreachable', "Nodo {$node['name']} caido (alerta #{$state['alert_count']}, proxima en {$nextMinutes}min)");
                logMsg("  Node #{$nid} ({$node['name']}): ALERT #{$state['alert_count']} sent. Next in {$nextMinutes}min.");
            } else {
                $remaining = $requiredInterval - $elapsed;
                logMsg("  Node #{$nid} ({$node['name']}): throttled, next alert in {$remaining}s.");
            }

            unset($state);
        }
    } else {
        logMsg("  All nodes reachable.");
    }

    // Clear state for nodes that came back online
    $cleared = false;
    foreach (array_keys($alertState) as $nid) {
        if (!in_array($nid, $unreachableIds)) {
            // Node recovered — send recovery notification
            $recoveredNode = null;
            foreach (ClusterService::getNodes() as $n) {
                if ((string)$n['id'] === $nid) { $recoveredNode = $n; break; }
            }
            $nodeName = $recoveredNode ? $recoveredNode['name'] : "#{$nid}";
            $downMinutes = round(($now - (int)$alertState[$nid]['first_seen']) / 60);

            ClusterService::sendAlert(
                "[MuseDock Cluster] Nodo recuperado: {$nodeName}",
                "El nodo {$nodeName} ha vuelto a estar online.\n"
                . "Estuvo caido {$downMinutes} minutos.\n"
                . "Fecha: " . date('Y-m-d H:i:s')
            );

            // Clear mute if was muted
            Settings::set("cluster_node_{$nid}_muted_until", '');

            LogService::log('cluster.recovery', 'online', "Nodo {$nodeName} recuperado tras {$downMinutes}min caido");
            logMsg("  Node #{$nid} ({$nodeName}): RECOVERED after {$downMinutes}min. Notification sent.");

            unset($alertState[$nid]);
            $cleared = true;
        }
    }

    // Persist alert state
    Settings::set('cluster_node_alert_state', json_encode($alertState));

} catch (\Throwable $e) {
    logMsg("ERROR checking unreachable: " . $e->getMessage());
}

// ─── Step 3b: Slave — pull failover config from master ───────
//     On boot or if config hasn't been synced in 1 hour, pull from master.
//     This ensures the slave always has the latest failover config.
$clusterRoleForSync = Settings::get('cluster_role', 'standalone');
$selfStandbyForSync = Settings::get('cluster_self_standby', '0') === '1';
if ($clusterRoleForSync === 'slave' && !$selfStandbyForSync) {
    $lastSync = Settings::get('failover_config_synced_at', '');
    $syncAge = $lastSync ? (time() - strtotime($lastSync)) : 99999;
    // Pull every hour, or on first boot (never synced)
    if ($syncAge > 3600) {
        logMsg("Pulling failover config from master (last sync: " . ($lastSync ?: 'never') . ")...");
        try {
            $pulled = \MuseDockPanel\Services\FailoverService::pullConfigFromMaster();
            logMsg($pulled ? "  Failover config synced from master." : "  Could not pull config (master unreachable or not configured).");
        } catch (\Throwable $e) {
            logMsg("  ERROR pulling failover config: " . $e->getMessage());
        }
    }
}

// ─── Step 4: Slave — detect master down ──────────────────────
$clusterRole = Settings::get('cluster_role', 'standalone');
if ($clusterRole === 'slave') {

    // If this slave is in standby mode, skip master-down checks entirely
    $selfStandby = Settings::get('cluster_self_standby', '0') === '1';
    if ($selfStandby) {
        logMsg("Slave in standby mode — skipping master heartbeat check.");
    } else {

    logMsg("Checking master heartbeat (slave mode)...");
    try {
        $masterLastHb = Settings::get('cluster_master_last_heartbeat', '');
        $masterIp = Settings::get('cluster_master_ip', '');
        $timeoutSec = (int)Settings::get('cluster_unreachable_timeout', '300');

        if ($masterLastHb && $masterIp) {
            $age = time() - strtotime($masterLastHb);

            if ($age > $timeoutSec) {
                // If this slave local admin endpoint is down, "master down" alerts are often
                // false positives (master cannot deliver heartbeat here). Suppress email spam.
                $localPanelPort = (int)\MuseDockPanel\Env::get('PANEL_PORT', 8444);
                if ($localPanelPort <= 0) $localPanelPort = 8444;
                $localHttpsUrl = "https://127.0.0.1:{$localPanelPort}/";
                $localHttpsCode = trim((string)shell_exec('curl -sk -o /dev/null -w "%{http_code}" --max-time 3 ' . escapeshellarg($localHttpsUrl) . ' 2>/dev/null'));
                $localHttpsOk = in_array($localHttpsCode, ['200', '301', '302', '403'], true);
                if (!$localHttpsOk) {
                    logMsg("  [POSSIBLE-FALSE-POSITIVE] local panel HTTPS check failed on {$localHttpsUrl} (HTTP {$localHttpsCode}); suppressing master-down alert.");
                    LogService::log('cluster.alert', 'master-down-suppressed', "Master-down suprimido: HTTPS local {$localHttpsUrl} fallo (HTTP {$localHttpsCode})");
                } else {

                // Master is down — escalating re-alert intervals to avoid spam
                $lastAlert = Settings::get('cluster_master_down_alerted', '');
                $alertAge = $lastAlert ? (time() - strtotime($lastAlert)) : 99999;
                $alertCount = (int)Settings::get('cluster_master_down_alert_count', '0');

                // Escalation: 1st immediate, then 1h x6, then 6h x4, then daily
                if ($alertCount === 0) {
                    $reAlertInterval = 0; // first alert: immediate
                } elseif ($alertCount <= 6) {
                    $reAlertInterval = 3600; // 1 hour
                } elseif ($alertCount <= 10) {
                    $reAlertInterval = 21600; // 6 hours
                } else {
                    $reAlertInterval = 86400; // daily
                }

                if ($alertAge > $reAlertInterval) {
                    // Send alert via configured channels
                    $notifyEmail = Settings::get('cluster_slave_notify_email', '1') === '1';
                    $notifyTelegram = Settings::get('cluster_slave_notify_telegram', '1') === '1';

                    $subject = '[MuseDock Cluster] ALERTA: Master caido';
                    $body = "El servidor Master ({$masterIp}) no envia heartbeat desde hace " . round($age / 60) . " minutos.\n\n"
                          . "Ultimo heartbeat: {$masterLastHb}\n"
                          . "Timeout configurado: {$timeoutSec}s\n"
                          . "Servidor slave: " . gethostname() . "\n"
                          . "Fecha: " . date('Y-m-d H:i:s') . "\n\n"
                          . "Puede promover este servidor a Master desde:\n"
                          . "https://" . (\MuseDockPanel\Env::get('PANEL_DOMAIN', gethostname())) . ":8444/settings/cluster";

                    if ($notifyEmail || $notifyTelegram) {
                        if ($notifyEmail && $notifyTelegram) {
                            \MuseDockPanel\Services\NotificationService::send($subject, $body);
                        } elseif ($notifyEmail) {
                            \MuseDockPanel\Services\NotificationService::sendEmail($subject, $body);
                        } elseif ($notifyTelegram) {
                            \MuseDockPanel\Services\NotificationService::sendTelegram($subject . "\n\n" . $body);
                        }
                    }

                    Settings::set('cluster_master_down_alerted', date('Y-m-d H:i:s'));
                    Settings::set('cluster_master_down_alert_count', (string)($alertCount + 1));
                    LogService::log('cluster.alert', 'master-down', "Master {$masterIp} sin heartbeat desde hace " . round($age / 60) . " min (alerta #{$alertCount})");
                    logMsg("  ALERT #{$alertCount}: Master {$masterIp} down for " . round($age / 60) . " min. Notification sent.");

                    // Auto-failover if enabled
                    $autoFailover = Settings::get('cluster_auto_failover', '0') === '1';
                    if ($autoFailover) {
                        logMsg("  AUTO-FAILOVER: Promoting to master...");
                        $result = ClusterService::promoteToMaster();
                        if ($result['ok']) {
                            logMsg("  AUTO-FAILOVER: Success — this server is now Master.");
                            LogService::log('cluster.failover', 'auto-promote', "Auto-failover ejecutado: promovido a master");
                            \MuseDockPanel\Services\NotificationService::send(
                                '[MuseDock Cluster] Auto-Failover ejecutado',
                                "Este servidor ha sido promovido automaticamente a Master tras detectar que {$masterIp} no responde.\n\nFecha: " . date('Y-m-d H:i:s')
                            );
                        } else {
                            logMsg("  AUTO-FAILOVER: FAILED — " . implode(', ', $result['errors'] ?? []));
                            LogService::log('cluster.failover', 'auto-promote-failed', implode(', ', $result['errors'] ?? []));
                        }
                    }
                } else {
                    logMsg("  Master down (already alerted " . round($alertAge / 60) . " min ago).");
                }
                }
            } else {
                // Master is alive — clear alert flags
                if (Settings::get('cluster_master_down_alerted', '')) {
                    Settings::set('cluster_master_down_alerted', '');
                    Settings::set('cluster_master_down_alert_count', '0');
                }
                logMsg("  Master {$masterIp} alive (last heartbeat {$age}s ago).");
            }
        } else {
            logMsg("  No master heartbeat recorded yet.");
        }
    } catch (\Throwable $e) {
        logMsg("ERROR checking master: " . $e->getMessage());
    }

    } // end if (!$selfStandby)
}

// ─── Step 5: Clean old completed items (once a day check) ─────
$hour = (int)date('H');
$minute = (int)date('i');
if ($hour === 3 && $minute < 2) {
    logMsg("Running daily cleanup...");
    try {
        $cleaned = ClusterService::cleanOldItems(30);
        logMsg("  Cleaned {$cleaned} old completed queue items.");
    } catch (\Throwable $e) {
        logMsg("ERROR cleaning: " . $e->getMessage());
    }
}

// ─── Step 6: Regenerate lsyncd config if flag file exists ─────
$regenFlag = PANEL_ROOT . '/storage/lsyncd-regen.flag';
if (file_exists($regenFlag)) {
    logMsg("Lsyncd regen flag detected, regenerating config...");
    try {
        $syncMode = \MuseDockPanel\Services\FileSyncService::getConfig()['sync_mode'] ?? 'periodic';
        if ($syncMode === 'lsyncd') {
            $result = \MuseDockPanel\Services\FileSyncService::reloadLsyncd();
            if ($result['ok']) {
                logMsg("  Lsyncd config regenerated and reloaded.");
            } else {
                logMsg("  Lsyncd reload failed: " . ($result['error'] ?? '?'));
            }
        } else {
            $result = \MuseDockPanel\Services\FileSyncService::generateLsyncdConfig();
            if ($result['ok']) {
                shell_exec('systemctl restart lsyncd 2>&1');
                logMsg("  Lsyncd config regenerated and service restarted.");
            } else {
                logMsg("  Lsyncd config generation failed: " . ($result['error'] ?? '?'));
            }
        }
        @unlink($regenFlag);
    } catch (\Throwable $e) {
        logMsg("ERROR regenerating lsyncd: " . $e->getMessage());
    }
}

// ─── Step 7: Ensure TLS policies are correct (auto-heal after caddy reload) ─────
try {
    $config = require PANEL_ROOT . '/config/panel.php';
    $caddyApi = $config['caddy']['api_url'] ?? 'http://localhost:2019';
    $panelPort = (int)\MuseDockPanel\Env::get('PANEL_PORT', 8444);
    $caddyfile = @file_get_contents('/etc/caddy/Caddyfile') ?: '';
    $staticPanelTls = preg_match('/(^|\n)\s*(https?:\/\/[^\s{]+:' . preg_quote((string)$panelPort, '/') . '|:' . preg_quote((string)$panelPort, '/') . ')\b/', $caddyfile)
        && preg_match('/\btls\s+internal\b/', $caddyfile);
    $panelManaged = \MuseDockPanel\Services\SystemService::panelRuntimeManagedByPanel($caddyApi);
    if ($staticPanelTls || !$panelManaged) {
        $owner = $staticPanelTls ? 'Caddyfile static TLS' : (\MuseDockPanel\Services\SystemService::panelPortOwner($caddyApi) ?? 'unknown');
        logMsg("INFO: PANEL_PORT gestionado por {$owner}; se intentara reponer ruta runtime del dominio del panel si el listener apunta al panel interno.");
    }

    if (!\MuseDockPanel\Services\SystemService::ensureCaddyHttpServerReady($caddyApi, true)) {
        logMsg("WARNING: Caddy srv0/listeners not ready — skipping TLS policy check.");
    } else {
        $panelRouteResult = \MuseDockPanel\Services\SystemService::ensurePanelDomainRouteFromSettings();
        if (!($panelRouteResult['ok'] ?? false)) {
            logMsg("WARNING: panel-domain route not ready — " . ($panelRouteResult['error'] ?? 'unknown error'));
        } elseif (!empty($panelRouteResult['warning'])) {
            logMsg("WARNING: panel-domain route — " . $panelRouteResult['warning']);
        }

        $policies = json_decode(@file_get_contents("{$caddyApi}/config/apps/tls/automation/policies") ?: '[]', true) ?: [];

        // Catch-all must include at least one ACME issuer without explicit DNS challenge.
        // Internal-only policies are invalid for public panel hostnames.
        $hasCorrectCatchAll = false;
        foreach ($policies as $p) {
            if (empty($p['subjects'])) {
                foreach ($p['issuers'] ?? [] as $iss) {
                    $module = (string)($iss['module'] ?? '');
                    $hasDnsChallenge = isset($iss['challenges']['dns']);
                    if ($module === 'acme' && !$hasDnsChallenge) {
                        $hasCorrectCatchAll = true;
                        break;
                    }
                }
                break;
            }
        }

        if (!$hasCorrectCatchAll) {
            logMsg("TLS policies missing or incorrect — regenerating...");
            \MuseDockPanel\Services\SystemService::ensureTlsCatchAllPolicy($caddyApi);
            logMsg("  TLS policies regenerated.");
        }
    }
} catch (\Throwable $e) {
    logMsg("ERROR checking TLS policies: " . $e->getMessage());
}

cleanup:

$elapsed = round((microtime(true) - $startTime) * 1000, 1);
logMsg("Done in {$elapsed}ms.");

// Write log
$logDir = PANEL_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/cluster-worker.log';

// Rotate if > 5MB
if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
    @rename($logFile, $logFile . '.' . date('Ymd-His'));
}

file_put_contents($logFile, implode("\n", $logLines) . "\n", FILE_APPEND | LOCK_EX);

// Release lock
flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink($lockFile);
