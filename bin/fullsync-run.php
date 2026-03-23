#!/usr/bin/env php
<?php
/**
 * Background full sync orchestrator — launched by ClusterController::fullSync()
 * Runs in sequence: 1) Hostings (API), 2) Files (rsync), 3) DB dumps, 4) SSL certs
 * Usage: php fullsync-run.php <node_id> <sync_id>
 */

$nodeId = (int)($argv[1] ?? 0);
$syncId = $argv[2] ?? '';

if ($nodeId < 1 || empty($syncId)) {
    file_put_contents('php://stderr', "Usage: php fullsync-run.php <node_id> <sync_id>\n");
    exit(1);
}

// Bootstrap panel (same as cluster-worker.php)
define('PANEL_ROOT', dirname(__DIR__));
define('PANEL_VERSION', '1.0.2');

spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');
$config = require PANEL_ROOT . '/config/panel.php';

use MuseDockPanel\Database;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\FileSyncService;
use MuseDockPanel\Services\ReplicationService;
use MuseDockPanel\Services\SystemService;
use MuseDockPanel\Services\LogService;

$progressFile = FileSyncService::progressFilePath($syncId);
$startTime = microtime(true);
$steps = [];
$totalSteps = 4;

function writeFullSyncProgress(string $file, string $status, string $phase, string $phaseLabel, array $steps, float $startTime, array $extra = []): void
{
    $data = array_merge([
        'status' => $status,
        'phase' => $phase,
        'phase_label' => $phaseLabel,
        'steps' => $steps,
        'elapsed' => round(microtime(true) - $startTime, 1),
    ], $extra);
    file_put_contents($file, json_encode($data));
}

try {
    $node = ClusterService::getNode($nodeId);
    if (!$node) {
        throw new \RuntimeException("Nodo #{$nodeId} no encontrado");
    }

    $fsConfig = FileSyncService::getConfig();
    $host = parse_url($node['api_url'], PHP_URL_HOST);

    // Detect SSH availability
    $sshAvailable = false;
    $sshKeyPath = $fsConfig['ssh_key_path'] ?? '/root/.ssh/id_ed25519';
    if (file_exists($sshKeyPath)) {
        $sshTest = FileSyncService::testSshConnection($host, $fsConfig['ssh_port'] ?? 22, $sshKeyPath);
        $sshAvailable = $sshTest['ok'] ?? false;
    }

    $sslEnabled = Settings::get('filesync_ssl_certs', '0') === '1';
    $dbDumpsEnabled = $fsConfig['db_dumps'] ?? false;
    $streamingStatus = ReplicationService::isStreamingActive();

    // ═══════════════════════════════════════════════════════════════
    // STEP 1: Sync Hostings (API — always runs)
    // ═══════════════════════════════════════════════════════════════
    $steps[] = ['name' => 'Hostings (API)', 'status' => 'running', 'detail' => 'Encolando cuentas...'];
    writeFullSyncProgress($progressFile, 'running', 'hostings', "Paso 1/{$totalSteps}: Sincronizando hostings via API...", $steps, $startTime);

    $accounts = Database::fetchAll("SELECT * FROM hosting_accounts WHERE status != 'deleted' ORDER BY id");
    $hostingCount = 0;

    foreach ($accounts as $acc) {
        $passwordHash = SystemService::getPasswordHash($acc['username']);
        ClusterService::enqueue($nodeId, 'sync-hosting', [
            'hosting_action' => 'create_hosting',
            'hosting_data' => [
                'username' => $acc['username'],
                'domain' => $acc['domain'],
                'home_dir' => $acc['home_dir'],
                'document_root' => $acc['document_root'],
                'php_version' => $acc['php_version'] ?? '8.3',
                'password' => '',
                'password_hash' => $passwordHash,
                'shell' => $acc['shell'] ?? '/usr/sbin/nologin',
                'system_uid' => $acc['system_uid'] ?? null,
                'caddy_route_id' => $acc['caddy_route_id'] ?? null,
                'customer_id' => $acc['customer_id'] ?? null,
                'disk_quota_mb' => $acc['disk_quota_mb'] ?? 1024,
                'description' => $acc['description'] ?? '',
            ],
        ], 5);
        $hostingCount++;
    }

    // Process the queue immediately
    $queueResults = ClusterService::processQueue();
    $queueOk = 0;
    $queueFail = 0;
    foreach ($queueResults as $r) {
        if ($r['ok']) $queueOk++;
        else $queueFail++;
    }

    $steps[0] = [
        'name' => 'Hostings (API)',
        'status' => $queueFail > 0 ? 'warning' : 'ok',
        'detail' => "{$queueOk}/{$hostingCount} sincronizados" . ($queueFail > 0 ? ", {$queueFail} fallidos" : ''),
    ];

    LogService::log('cluster.fullsync', 'hostings', "Paso 1: {$queueOk}/{$hostingCount} hostings OK, {$queueFail} fallidos");

    // ═══════════════════════════════════════════════════════════════
    // STEP 2: Sync Files (rsync — requires SSH)
    // ═══════════════════════════════════════════════════════════════
    if ($sshAvailable) {
        $steps[] = ['name' => 'Archivos (rsync)', 'status' => 'running', 'detail' => 'Copiando archivos...'];
        writeFullSyncProgress($progressFile, 'running', 'files', "Paso 2/{$totalSteps}: Sincronizando archivos via rsync...", $steps, $startTime, [
            'total' => count($accounts), 'current' => 0, 'ok' => 0, 'fail' => 0,
        ]);

        $fileOk = 0;
        $fileFail = 0;
        $i = 0;

        foreach ($accounts as $acc) {
            $i++;
            $sourcePath = ($acc['home_dir'] ?? '') . '/httpdocs/';
            $destPath = $sourcePath;

            writeFullSyncProgress($progressFile, 'running', 'files', "Paso 2/{$totalSteps}: Sincronizando archivos...", $steps, $startTime, [
                'total' => count($accounts), 'current' => $i, 'ok' => $fileOk, 'fail' => $fileFail,
                'current_domain' => $acc['domain'],
            ]);

            if (!is_dir($sourcePath)) {
                $fileFail++;
                continue;
            }

            $result = FileSyncService::rsyncHosting($sourcePath, $host, $destPath);
            if ($result['ok'] ?? false) {
                $fileOk++;
            } else {
                $fileFail++;
            }
        }

        $steps[1] = [
            'name' => 'Archivos (rsync)',
            'status' => $fileFail > 0 ? 'warning' : 'ok',
            'detail' => "{$fileOk}/" . count($accounts) . " copiados" . ($fileFail > 0 ? ", {$fileFail} fallidos" : ''),
        ];

        LogService::log('cluster.fullsync', 'files', "Paso 2: {$fileOk}/" . count($accounts) . " archivos OK, {$fileFail} fallidos");
    } else {
        $steps[] = [
            'name' => 'Archivos (rsync)',
            'status' => 'skipped',
            'detail' => 'SSH no disponible — configure la clave SSH en la pestaña Archivos',
        ];
        writeFullSyncProgress($progressFile, 'running', 'files_skipped', "Paso 2/{$totalSteps}: Archivos omitido (sin SSH)", $steps, $startTime);
        LogService::log('cluster.fullsync', 'files', 'Paso 2: Omitido — SSH no disponible');
    }

    // ═══════════════════════════════════════════════════════════════
    // STEP 3: Database Dumps (skip if streaming replication active)
    // ═══════════════════════════════════════════════════════════════
    if ($dbDumpsEnabled && !$streamingStatus['any_active'] && $sshAvailable) {
        $steps[] = ['name' => 'Bases de datos (dump)', 'status' => 'running', 'detail' => 'Exportando bases de datos...'];
        writeFullSyncProgress($progressFile, 'running', 'db_dumps', "Paso 3/{$totalSteps}: Exportando y sincronizando bases de datos...", $steps, $startTime);

        // Dump all databases on master
        $dumpResults = FileSyncService::dumpAllDatabases();
        $dumpOk = count(array_filter($dumpResults, fn($r) => $r['ok']));
        $dumpFail = count($dumpResults) - $dumpOk;
        $dumpTotal = count($dumpResults);

        $stepIdx = count($steps) - 1;

        if ($dumpTotal === 0) {
            $steps[$stepIdx] = [
                'name' => 'Bases de datos (dump)',
                'status' => 'skipped',
                'detail' => 'No hay bases de datos de hosting para exportar',
            ];
            LogService::log('cluster.fullsync', 'db_dumps', 'Paso 3: No hay bases de datos');
        } else {
            writeFullSyncProgress($progressFile, 'running', 'db_dumps', "Paso 3/{$totalSteps}: Sincronizando {$dumpOk} dumps al slave...", $steps, $startTime);

            // Sync dumps to slave and trigger restore
            $syncDbResult = FileSyncService::syncDatabaseDumps($node);

            $dbStatus = ($syncDbResult['ok'] ?? false) ? ($dumpFail > 0 ? 'warning' : 'ok') : 'error';
            $steps[$stepIdx] = [
                'name' => 'Bases de datos (dump)',
                'status' => $dbStatus,
                'detail' => "{$dumpOk}/{$dumpTotal} exportadas" .
                            (($syncDbResult['ok'] ?? false) ? ', restauradas en slave' : ': error al restaurar'),
            ];

            LogService::log('cluster.fullsync', 'db_dumps', "Paso 3: {$dumpOk}/{$dumpTotal} dumps OK, restore: " . (($syncDbResult['ok'] ?? false) ? 'OK' : 'FAIL'));
        }
    } elseif ($dbDumpsEnabled && $streamingStatus['any_active']) {
        $steps[] = [
            'name' => 'Bases de datos (dump)',
            'status' => 'skipped',
            'detail' => 'Replicación streaming activa — dump innecesario',
        ];
        LogService::log('cluster.fullsync', 'db_dumps', 'Paso 3: Omitido — streaming replication activa');
    } elseif (!$dbDumpsEnabled) {
        $steps[] = [
            'name' => 'Bases de datos (dump)',
            'status' => 'skipped',
            'detail' => 'Deshabilitado — active la opción en la pestaña Archivos',
        ];
        LogService::log('cluster.fullsync', 'db_dumps', 'Paso 3: Omitido — DB dumps deshabilitado');
    } else {
        $steps[] = [
            'name' => 'Bases de datos (dump)',
            'status' => 'skipped',
            'detail' => 'SSH no disponible',
        ];
        LogService::log('cluster.fullsync', 'db_dumps', 'Paso 3: Omitido — SSH no disponible');
    }

    // ═══════════════════════════════════════════════════════════════
    // STEP 4: Sync SSL Certs (rsync — requires SSH + enabled)
    // ═══════════════════════════════════════════════════════════════
    if ($sshAvailable && $sslEnabled) {
        $steps[] = ['name' => 'Certificados SSL', 'status' => 'running', 'detail' => 'Copiando certificados...'];
        writeFullSyncProgress($progressFile, 'running', 'ssl', "Paso 4/{$totalSteps}: Copiando certificados SSL...", $steps, $startTime);

        $sslResult = FileSyncService::syncSslCerts($node);

        $sslIdx = count($steps) - 1;
        $steps[$sslIdx] = [
            'name' => 'Certificados SSL',
            'status' => ($sslResult['ok'] ?? false) ? 'ok' : 'error',
            'detail' => ($sslResult['ok'] ?? false) ? 'Certificados copiados y Caddy recargado' : ($sslResult['error'] ?? 'Error desconocido'),
        ];

        LogService::log('cluster.fullsync', 'ssl', 'Paso 4: Certs ' . (($sslResult['ok'] ?? false) ? 'OK' : 'FAIL: ' . ($sslResult['error'] ?? '')));
    } elseif ($sshAvailable && !$sslEnabled) {
        $steps[] = [
            'name' => 'Certificados SSL',
            'status' => 'skipped',
            'detail' => 'Deshabilitado — active la opción en la pestaña Archivos',
        ];
        LogService::log('cluster.fullsync', 'ssl', 'Paso 4: Omitido — SSL sync deshabilitado');
    } else {
        $steps[] = [
            'name' => 'Certificados SSL',
            'status' => 'skipped',
            'detail' => 'SSH no disponible',
        ];
        LogService::log('cluster.fullsync', 'ssl', 'Paso 4: Omitido — SSH no disponible');
    }

    // ═══════════════════════════════════════════════════════════════
    // DONE
    // ═══════════════════════════════════════════════════════════════
    $hasErrors = false;
    $hasWarnings = false;
    $hasSkipped = false;
    foreach ($steps as $s) {
        if ($s['status'] === 'error') $hasErrors = true;
        if ($s['status'] === 'warning') $hasWarnings = true;
        if ($s['status'] === 'skipped') $hasSkipped = true;
    }

    $finalStatus = 'done';
    $summary = 'Sincronización completa finalizada';
    if ($hasErrors) {
        $summary .= ' con errores';
    } elseif ($hasWarnings) {
        $summary .= ' con advertencias';
    } elseif ($hasSkipped) {
        $summary .= ' (algunos pasos omitidos)';
    }

    writeFullSyncProgress($progressFile, $finalStatus, 'done', $summary, $steps, $startTime);

    LogService::log('cluster.fullsync', 'complete', sprintf(
        "Sync completa nodo #%d finalizada en %.1fs: %s",
        $nodeId, microtime(true) - $startTime, $summary
    ));

} catch (\Throwable $e) {
    file_put_contents($progressFile, json_encode([
        'status' => 'error',
        'phase' => 'error',
        'phase_label' => 'Error: ' . $e->getMessage(),
        'steps' => $steps,
        'error' => $e->getMessage(),
        'elapsed' => round(microtime(true) - $startTime, 1),
    ]));
    LogService::log('cluster.fullsync', 'error', "Sync completa error nodo #{$nodeId}: " . $e->getMessage());
}
