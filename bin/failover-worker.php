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
 *
 * ──────────────────────────────────────────────────────────────────
 * Fase 4: Election / Cadena de sucesión — IMPLEMENTADO
 * ──────────────────────────────────────────────────────────────────
 * - Campo "failover_priority" en cada servidor (1 = más prioritario)
 * - FailoverService::shouldPromote() determina si este slave debe promoverse
 * - Solo el slave de mayor prioridad (vivo) se promueve
 * - Los demás se reconfiguran vía 'reconfigure-replication' automáticamente
 * - Si el promovido también cae, el siguiente en prioridad asume
 * - Multi-slave es feature Pro (LicenseService::hasFeature('multi-slave'))
 *
 * ──────────────────────────────────────────────────────────────────
 * Fase 4: Rol "Replica Pasiva" — IMPLEMENTADO
 * ──────────────────────────────────────────────────────────────────
 * - Rol "replica" en failover_servers (ROLE_REPLICA)
 * - Solo replica BD, no sirve tráfico, no tiene Caddy, no aparece en DNS
 * - Nunca se promueve (excluida de election en shouldPromote())
 * - Se reconfigura vía 'reconfigure-replication' cuando el master cambia
 * - En la UI aparece con badge gris, sin opción "Failover a"
 * ──────────────────────────────────────────────────────────────────
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

    // If this server is in standby, skip everything
    $selfStandby = Settings::get('cluster_self_standby', '0') === '1';
    if ($selfStandby) {
        logMsg("Server in standby mode — skipping all failover checks");
        goto cleanup;
    }

    // Only run if mode is auto or semiauto
    if ($foMode === 'manual') {
        logMsg("Mode: manual — skipping automatic checks");
        goto cleanup;
    }

    if (empty($servers)) {
        logMsg("No servers configured — skipping");
        goto cleanup;
    }

    // ─── 0. Local interface self-check ────────────────────────
    // Detects if primary ethernet (e.g. ONO) is down, only NAT/backup remains
    $ifacePrimary = trim($foConfig['failover_iface_primary'] ?? '');
    if ($ifacePrimary) {
        $ifaceCheck = FailoverService::checkLocalInterfaces();
        $prevIfaceMode = Settings::get('failover_iface_mode', 'normal');
        logMsg("Interface check: {$ifaceCheck['details']} (prev mode: {$prevIfaceMode})");

        if ($ifaceCheck['mode'] === 'nat' && $prevIfaceMode === 'normal') {
            // Primary interface just went down → trigger interface failover
            logMsg("*** PRIMARY INTERFACE DOWN — triggering interface failover ***");
            $ifResult = FailoverService::handleIfaceFailover();
            logMsg("Interface failover (Camino {$ifResult['path']}): " . implode('; ', $ifResult['actions']));

        } elseif ($ifaceCheck['mode'] === 'normal' && $prevIfaceMode === 'nat') {
            // Primary interface recovered → restore
            logMsg("*** PRIMARY INTERFACE RECOVERED — restoring normal mode ***");
            $ifRecovery = FailoverService::handleIfaceRecovery();
            logMsg("Interface recovery: " . implode('; ', $ifRecovery['actions']));

        } elseif ($ifaceCheck['mode'] === 'isolated') {
            logMsg("WARNING: ALL interfaces down — server isolated, no action possible");
        }
    }

    // ─── 1. Health check all servers ───────────────────────────
    logMsg("Running health checks ({$foMode} mode)...");
    $checks = FailoverService::checkAllEndpoints();

    // ─── 2. Track consecutive failures/recoveries ──────────────
    // Stored as JSON: { "server_id": { "fail_count": N, "ok_count": N, "last_status": "ok"|"down" } }
    $countersJson = Settings::get('failover_health_counters', '{}');
    $counters = json_decode($countersJson, true) ?: [];

    $downThreshold = (int)($foConfig['failover_down_threshold'] ?: 5);
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

        // ─── VUELTA: el principal original ha vuelto ───────────────────
        // Ya no se "resincroniza" ni se devuelve el DNS solo: el antiguo master, al
        // volver, se aparta él mismo (checkStaleMaster) y se reincorpora como copia
        // del nuevo (autoRejoinAsSlave). Aquí, en el que manda, solo se ordenan los
        // papeles del relevo (este principal, el otro de relevo, estado normal). La
        // vuelta a mandar el original es un cambio de rol planificado (botón).
        // El resync antiguo (volcado del panel + rsync --delete) fallaba siempre y
        // avisaba cada 15 min.
        if ($isFailback) {
            $localRoleFb = Settings::get('cluster_role', '');
            if ($localRoleFb === 'master') {
                $norm = \MuseDockPanel\Services\RoleSwitchService::normalizeFailover();
                logMsg('Principal original de vuelta: relevo normalizado — ' . json_encode($norm));
                if (!empty($norm['ok'])) {
                    NotificationService::send(
                        'Failover: el servidor caído ha vuelto',
                        "Este servidor sigue mandando y ahora figura como principal del relevo; el que había caído queda de relevo.\n"
                        . "Al volver se aparta solo y se reincorpora como copia. Para que vuelva a mandar: Dashboard → Pasar el mando a…"
                    );
                }
            } else {
                logMsg('Principal original de vuelta; este nodo no es master: nada que hacer');
            }
            $isFailback = false;
            $stateChanged = false;
        }

        // ─── COOLDOWN: prevent re-failover right after failback ─────
        if ($isFailover) {
            $lastFailbackAt = Settings::get('failover_last_failback_at', '');
            $cooldownMin = (int)($foConfig['failover_cooldown_minutes'] ?? 15) ?: 15;
            if ($lastFailbackAt) {
                $cooldownRemaining = ($cooldownMin * 60) - (time() - strtotime($lastFailbackAt));
                if ($cooldownRemaining > 0) {
                    logMsg("COOLDOWN active — failback was " . round((time() - strtotime($lastFailbackAt)) / 60) . "min ago, " . ceil($cooldownRemaining / 60) . "min remaining. Skipping failover.");
                    $isFailover = false;
                    $stateChanged = false;
                }
            }
        }

        if ($isFailover) {
            // ─── RELEVO POR CAÍDA ─────────────────────────────
            // Solo actúa la réplica que debe tomar el mando, y en este orden:
            // 1) promoverse (con elección y testigo), 2) SOLO si se promovió, mover el DNS,
            // 3) dejar el relevo ordenado (ella principal, el caído de relevo).
            // Antes se movía el DNS primero (las webs iban a una copia en solo lectura si
            // el testigo paraba la promoción) y el propio master podía mover el DNS si no
            // se alcanzaba a sí mismo por su IP pública (NAT en casa).
            $localRoleFo = Settings::get('cluster_role', '');
            if ($localRoleFo !== 'slave') {
                logMsg("Relevo: este nodo es '{$localRoleFo}', no la réplica: no mueve DNS ni promueve (lo hace la réplica)");
            } elseif ($foMode === 'auto' || $foMode === 'semiauto') {
                $stateLabel = FailoverService::stateLabel($shouldBe);
                logMsg(strtoupper($foMode) . " — relevo hacia {$shouldBe}: primero promover, luego DNS");
                $promoted = autoPromoteIfNeeded($checks, $foConfig);
                if ($promoted) {
                    $result = FailoverService::transitionTo($shouldBe, $foMode . '-worker');
                    logMsg("DNS: " . implode('; ', $result['actions'] ?? []));
                    $norm = \MuseDockPanel\Services\RoleSwitchService::normalizeFailover();
                    logMsg('Relevo ordenado: ' . json_encode($norm));
                    NotificationService::send(
                        "Failover EJECUTADO ({$foMode}) → este servidor manda",
                        "El principal cayó y este servidor ha tomado el mando automáticamente:\n"
                        . "- Bases de datos promovidas\n- DNS movido a este servidor\n- Ahora figura como principal del relevo\n\n"
                        . "Caídos: " . implode(', ', array_map(fn($id) => $checks[$id]['name'] ?? $id, $newlyDown)) . "\n\n"
                        . "Cuando el otro vuelva se apartará solo y se reincorporará como copia. Para que vuelva a mandar: Dashboard → Pasar el mando a…"
                    );
                    LogService::log('failover.' . $foMode, $shouldBe, 'Relevo por caída ejecutado: promovido + DNS');
                } else {
                    logMsg('Relevo NO ejecutado (no se promovió): el DNS no se toca');
                }
            }
            // manual: solo se ve en el Dashboard y se avisa.

        }
    } else {
        $upCount = count(array_filter($confirmedChecks, fn($c) => !empty($c['ok'])));
        $totalCount = count($confirmedChecks);
        logMsg("No state change needed. Servers: {$upCount}/{$totalCount} UP");
    }

    // ─── 4. Vuelta al titular (modo auto) ───────────────────────
    autoReturnToPreferred($foMode, $foConfig, $counters);

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
 * Promueve ESTE nodo (réplica) a master si el principal del relevo está caído, le
 * toca a él y un testigo no lo contradice. Devuelve true solo si se promovió.
 *
 * IPs: el relevo (failover_servers) usa IPs públicas; el cluster, las de la VPN.
 * Antes se comparaba la del master del cluster (VPN) con las de las comprobaciones
 * (públicas): nunca coincidían y la promoción automática no se hacía nunca.
 */
function autoPromoteIfNeeded(array $checks, array $foConfig): bool
{
    $clusterRole = Settings::get('cluster_role', 'standalone');
    if ($clusterRole !== 'slave') {
        logMsg("Auto-promote: este nodo es '{$clusterRole}', no réplica — no se promueve");
        return false;
    }

    // Quién soy en el relevo (por mis IPs) y qué principal caído me toca.
    $mine = preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) ?: [];
    $servers = FailoverService::getServers();
    $me = null;
    foreach ($servers as $srv) {
        if (in_array((string)($srv['ip'] ?? ''), $mine, true)) {
            $me = $srv;
        }
    }
    if (!$me) {
        logMsg('Auto-promote: este servidor no está en Failover → Servidores — no se promueve');
        return false;
    }
    $downPrimary = null;
    $downIps = [];
    foreach ($servers as $srv) {
        $ok = !empty($checks[$srv['id']]['ok']);
        if (!$ok) {
            $downIps[] = (string)$srv['ip'];
        }
        if (($srv['role'] ?? '') === FailoverService::ROLE_PRIMARY && !$ok) {
            $downPrimary = $srv;
        }
    }
    if (!$downPrimary) {
        logMsg('Auto-promote: ningún principal caído — no se promueve');
        return false;
    }
    if (!FailoverService::shouldPromote((string)$me['ip'], $downIps)) {
        logMsg('Auto-promote: elección — otra réplica de más prioridad está viva; le toca a ella');
        return false;
    }

    // El master en el cluster (IP de la VPN), para el testigo y para apartarlo.
    $masterVpn = (string)Settings::get('cluster_master_ip', '');

    // ── Testigo: otro nodo confirma desde otro sitio que el master está caído ──
    // Una partición de red se ve igual que un master caído. Solo cuentan los testigos
    // que RESPONDEN: si un testigo está caído también (p. ej. en la misma línea que el
    // master), no aporta nada y no debe bloquear. Si alguno ve al master vivo, no se
    // promueve.
    $answered = 0;
    $sawAlive = false;
    foreach (\MuseDockPanel\Services\ClusterService::getNodes() as $wn) {
        $wHost = (string)(parse_url((string)($wn['api_url'] ?? ''), PHP_URL_HOST) ?: '');
        if ($wHost === '' || in_array($wHost, $mine, true) || $wHost === $masterVpn) {
            continue;
        }
        try {
            $probe = \MuseDockPanel\Services\ClusterService::callNode((int)$wn['id'], 'POST', 'api/cluster/action',
                ['action' => 'probe-host', 'payload' => ['ip' => $masterVpn !== '' ? $masterVpn : (string)$downPrimary['ip']]]);
            $res = $probe['data']['result'] ?? $probe['result'] ?? $probe['data'] ?? [];
            if (!empty($probe['ok']) && isset($res['reachable'])) {
                $answered++;
                if ($res['reachable'] === true) {
                    $sawAlive = true;
                }
                logMsg("Auto-promote: testigo {$wn['name']} ve el master " . ($res['reachable'] ? 'VIVO' : 'caído'));
            } else {
                logMsg("Auto-promote: testigo {$wn['name']} no responde (no cuenta)");
            }
        } catch (\Throwable $e) {
            logMsg("Auto-promote: testigo {$wn['name']} error: " . $e->getMessage() . ' (no cuenta)');
        }
    }
    if ($sawAlive) {
        logMsg("Auto-promote: ABORTADO — un testigo aún alcanza el master: probable corte de red entre nodos, no caída");
        NotificationService::send(
            'Failover: relevo automático ABORTADO (posible corte de red)',
            "Este nodo ve caído el principal ({$downPrimary['name']}), pero otro nodo SÍ lo alcanza.\n"
            . "No se ha tomado el mando para no tener dos masters. Revisa la red; si de verdad está caído: Dashboard → Tomar el mando."
        );
        return false;
    }
    if ($answered === 0) {
        logMsg('Auto-promote: sin testigo que responda — se decide con la vista de este nodo (configura un testigo en otro proveedor)');
    }

    logMsg("Auto-promote: principal {$downPrimary['name']} caído y me toca — promoviendo este servidor");
    Settings::set('failover_original_master_ip', $masterVpn);
    try {
        $result = \MuseDockPanel\Services\ClusterService::promoteToMaster(false, $masterVpn);
        if (!empty($result['ok'])) {
            logMsg('Auto-promote: OK — este servidor es master');
            LogService::log('failover.auto_promote', 'master', "Réplica promovida: el principal {$downPrimary['name']} no responde");
            return true;
        }
        $errors = implode(', ', $result['errors'] ?? ['error desconocido']);
        logMsg("Auto-promote: FALLÓ — {$errors}");
        LogService::log('failover.auto_promote', 'error', "Promoción automática fallida: {$errors}");
        NotificationService::send('Failover: la promoción automática FALLÓ', "El principal {$downPrimary['name']} no responde, pero este servidor no pudo tomar el mando:\n{$errors}\n\nEl DNS no se ha movido.");
    } catch (\Throwable $e) {
        logMsg('Auto-promote: EXCEPCIÓN — ' . $e->getMessage());
    }
    return false;
}

/**
 * Si este servidor manda como SUSTITUTO (el titular cayó y se hizo un relevo), y el
 * titular lleva failover_return_stable_minutes respondiendo bien y ya es su copia al
 * día, en modo auto se le devuelve el mando con el cambio de rol planificado (mismas
 * comprobaciones, apartado, promoción, DNS y copia que el botón). En semiauto se avisa.
 * El titular es quien recibió el mando en el último cambio planificado.
 */
function autoReturnToPreferred(string $foMode, array $foConfig, array $counters): void
{
    if (Settings::get('cluster_role', '') !== 'master' || Settings::get('cluster_fenced', '0') === '1') {
        return;
    }
    $pref = trim((string)($foConfig['failover_preferred_ip'] ?? ''));
    if ($pref === '') {
        return;
    }
    $mine = preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) ?: [];
    if (in_array($pref, $mine, true)) {
        return;   // el titular soy yo
    }
    $srv = null;
    foreach (FailoverService::getServers() as $s) {
        if ((string)($s['ip'] ?? '') === $pref) {
            $srv = $s;
        }
    }
    if (!$srv) {
        return;
    }
    $interval = max(30, (int)($foConfig['failover_check_interval'] ?? 60));
    $stableMin = max(5, (int)($foConfig['failover_return_stable_minutes'] ?? 15));
    $need = (int)ceil($stableMin * 60 / $interval);
    $c = $counters[$srv['id']] ?? [];
    if (($c['last_status'] ?? '') !== 'ok' || (int)($c['ok_count'] ?? 0) < $need) {
        logMsg("Titular {$srv['name']}: aún no estable (" . (int)($c['ok_count'] ?? 0) . "/{$need} comprobaciones bien)");
        return;
    }
    $active = \MuseDockPanel\Services\RoleSwitchService::activeTask(1);
    if ($active && ($active['state'] ?? '') === 'running') {
        return;
    }
    if (time() - (int)Settings::get('failover_return_started_at', '0') < 1800) {
        return;   // no reintentar en bucle
    }
    // Su nodo en el cluster (por sus IPs).
    $nodeId = 0;
    foreach (\MuseDockPanel\Services\ClusterService::getNodes() as $n) {
        $r = \MuseDockPanel\Services\ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'role-switch-health', 'payload' => []]);
        if (in_array($pref, (array)($r['data']['result']['local_ips'] ?? []), true)) {
            $nodeId = (int)$n['id'];
            break;
        }
    }
    if ($nodeId === 0) {
        logMsg("Titular {$srv['name']}: no se encuentra entre los nodos del cluster");
        return;
    }
    $pre = \MuseDockPanel\Services\RoleSwitchService::preflight($nodeId);
    if (empty($pre['ok'])) {
        $bad = array_map(fn($x) => $x['name'], array_filter($pre['checks'] ?? [], fn($x) => !$x['ok'] && $x['blocking']));
        logMsg("Titular {$srv['name']}: estable pero aún no listo para recibir el mando (" . implode('; ', $bad) . ')');
        return;
    }
    if ($foMode !== 'auto') {
        if (Settings::get('failover_return_notified', '') !== $pref . date('Y-m-d')) {
            NotificationService::send("Failover: {$srv['name']} está listo para volver a mandar",
                "{$srv['name']} lleva {$stableMin} min estable y es copia al día de este servidor.\n"
                . "Para devolverle el mando: Dashboard → Pasar el mando a… {$srv['name']} (en modo auto se haría solo).");
            Settings::set('failover_return_notified', $pref . date('Y-m-d'));
        }
        return;
    }
    Settings::set('failover_return_started_at', (string)time());
    $r = \MuseDockPanel\Services\RoleSwitchService::start($nodeId, 'vuelta automática al titular');
    logMsg("Titular {$srv['name']}: estable {$stableMin} min — devolviendo el mando: " . json_encode($r));
    LogService::log('failover.auto_return', $srv['name'], 'Vuelta automática al titular: ' . (!empty($r['ok']) ? 'iniciada' : ($r['error'] ?? '?')));
}

// (El resync y la vuelta automática antiguos se quitaron: el antiguo master se
// reincorpora como copia él solo y la vuelta a mandar es un cambio de rol planificado.)
