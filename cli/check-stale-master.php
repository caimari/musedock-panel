<?php
/**
 * Comprobación de "master caducado" al ARRANCAR el servidor (anti split-brain).
 *
 * La lanza musedock-stale-master-check.service antes que Caddy y supervisor: si
 * este nodo vuelve (caída, reinicio del proveedor) creyéndose master y otro nodo se
 * promovió mientras tanto, se aísla ANTES de servir webs o arrancar la app. La misma
 * comprobación la repite bin/cluster-worker.php cada minuto.
 *
 * Nunca debe impedir el arranque: sale siempre con 0 (el servicio además lleva el
 * prefijo "-"). Si no es master, o no llega a ningún nodo, no hace nada.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\FailoverSafetyService;

try {
    $r = FailoverSafetyService::checkStaleMaster();
    if (!empty($r['stale']) && empty($r['skipped'])) {
        echo '[stale-master] AISLADO: ' . ($r['reason'] ?? '') . "\n";
    } else {
        echo '[stale-master] ok: ' . ($r['skipped'] ?? ('nodos consultados: ' . ($r['nodes_asked'] ?? 0))) . "\n";
    }
} catch (\Throwable $e) {
    echo '[stale-master] error (se continúa el arranque): ' . $e->getMessage() . "\n";
}
exit(0);
