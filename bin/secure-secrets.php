<?php
/**
 * Cierra los permisos de los ficheros secretos de los hostings (.env y wp-config.php)
 * para que ningún otro hosting pueda leerlos (HostingSecretsService). Solo cambia
 * permisos y grupo; nada se borra.
 *
 *   php bin/secure-secrets.php            muestra qué cambiaría (no toca nada)
 *   php bin/secure-secrets.php --apply    lo aplica
 *
 * Hazlo en el master: los cambios llegan a las copias con la sincronización de ficheros.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\HostingSecretsService;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$apply = in_array('--apply', $argv, true);
$r = HostingSecretsService::scan($apply);
foreach ($r['changes'] as $c) {
    printf("%-26s %-48s %-28s %s → %s%s\n", $c['domain'], $c['file'], $c['risk'], $c['from'], $c['to'], isset($c['applied']) ? "  [{$c['applied']}]" : '');
}
foreach ($r['review'] as $line) {
    echo "REVISAR: {$line}\n";
}
echo count($r['changes']) . ' fichero(s) ' . ($apply ? 'corregidos' : 'a corregir (repite con --apply)') . ", {$r['ok_count']} ya bien, " . count($r['review']) . " para revisar a mano.\n";
