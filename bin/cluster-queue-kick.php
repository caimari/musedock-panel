#!/usr/bin/env php
<?php
/**
 * Procesa YA la cola de operaciones hacia las réplicas (hostings, usuarios, buzones y alias de correo, configuración del relevo…), sin esperar a
 * la siguiente vuelta del cron del clúster (hasta un minuto). Lo lanza en segundo plano ClusterService::kickQueue() al encolar algo.
 *
 * Seguro: usa el mismo cerrojo que cluster-worker (si el cron está procesando, no hace nada: él lo procesará), solo actúa en el master sin
 * apartar, y es exactamente el mismo paso «procesar cola» del cron.
 */
declare(strict_types=1);

define('PANEL_ROOT', dirname(__DIR__));
define('MUSEDOCK_QUEUE_WORKER', true);   // los encolados desde aquí no vuelven a lanzar otro
require_once PANEL_ROOT . '/app/bootstrap.php';

use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Settings;

if (Settings::get('cluster_role', '') !== 'master' || Settings::get('cluster_fenced', '0') === '1') {
    exit(0);
}
$lock = fopen(PANEL_ROOT . '/storage/cluster-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);    // el cron del clúster está procesando
}
$results = ClusterService::processQueue();
if ($results) {
    $ok = count(array_filter($results, static fn($r) => !empty($r['ok'])));
    @file_put_contents(PANEL_ROOT . '/storage/logs/cluster-worker.log',
        '[' . date('Y-m-d H:i:s') . '] Cola procesada al momento: ' . $ok . ' bien, ' . (count($results) - $ok) . " fallidas.\n", FILE_APPEND);
}
flock($lock, LOCK_UN);
exit(0);
