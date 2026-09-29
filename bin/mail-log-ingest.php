<?php
/**
 * Importa mail.log a la tabla mail_relay_events en segundo plano (cron cada minuto).
 *
 * Antes lo hacía la propia página Mail → general en CADA visita, releyendo las
 * últimas 20.000 líneas del log: ~3 s de espera. Ahora la página solo lee de BD
 * y esta tarea importa de forma incremental (solo las líneas nuevas).
 * En nodos sin log de correo termina en silencio.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\MailService;

$lock = fopen(PANEL_ROOT . '/storage/mail-log-ingest.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // otra importación en curso
}

$t = microtime(true);
$n = MailService::ingestDeliveryLogIncremental();
if (in_array('-v', $argv ?? [], true)) {
    printf("[mail-log-ingest] %d evento(s) nuevos/actualizados en %.2fs\n", $n, microtime(true) - $t);
}
