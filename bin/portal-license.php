<?php
/**
 * Licencia del portal de clientes en ESTE nodo: estado y renovación manual (LicenseService).
 *
 * La licencia está ligada al nombre de máquina del nodo que la activó, pero sigue al clúster: el servidor de
 * licencias la pasa a otro nodo que renueve con el mismo identificador de clúster (portal_instance_id), SIEMPRE
 * que ya conozca ese identificador. Eso solo ocurre cuando el nodo ligado ha renovado al menos una vez. Si el
 * nodo ligado cae antes, el nuevo master recibe «Server changed» y el portal se para (relevo 2026-10-18).
 *
 * Uso (como root para renovar):
 *   php bin/portal-license.php            estado: a qué nodo está ligada, hasta cuándo, si el clúster está registrado
 *   php bin/portal-license.php --renew    renueva ahora en este nodo (en el ligado, registra el clúster; en otro
 *                                         nodo del mismo clúster, le pasa la licencia). Nunca borra nada.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\LicenseService;
use MuseDockPanel\Services\PortalService;
use MuseDockPanel\Settings;

$st = LicenseService::getPortalStatus();
$bound = (string)($st['hostname'] ?? '');
$me = trim((string)gethostname());
$registered = (string)Settings::get('portal_license_instance_registered', '') === LicenseService::instanceId();
echo 'Portal instalado:           ' . (PortalService::installed() ? 'sí' : 'no') . "\n";
echo 'Licencia activa:            ' . (!empty($st['active']) ? 'sí' : 'no') . (isset($st['status']) ? " ({$st['status']})" : '') . "\n";
echo 'Caduca:                     ' . (!empty($st['expires']) ? gmdate('Y-m-d H:i', (int)$st['expires']) . ' UTC' : '-') . "\n";
echo 'Ligada al nodo:             ' . ($bound !== '' ? $bound : '(sin nombre)') . ($bound === $me ? '  ← este' : '') . "\n";
echo 'Este nodo:                  ' . $me . ' · rol ' . (Settings::get('cluster_role', '') ?: 'standalone') . "\n";
echo 'Clúster registrado en el servidor de licencias: ' . ($registered ? 'sí' : 'NO (la licencia no podría pasar sola a otro nodo)') . "\n";

if (!in_array('--renew', $argv ?? [], true)) {
    if (!$registered) {
        echo "\nPara registrarlo: en el nodo al que está ligada la licencia, como root: php bin/portal-license.php --renew\n";
    }
    exit(0);
}
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Para renovar hay que ejecutarlo como root.\n");
    exit(1);
}
$r = LicenseService::refreshPortalLicense();
echo "\n" . ($r['ok'] ? 'OK: ' : 'NO: ') . $r['message'] . "\n";
$registered = (string)Settings::get('portal_license_instance_registered', '') === LicenseService::instanceId();
echo 'Clúster registrado ahora: ' . ($registered ? 'sí' : 'no') . "\n";
exit($r['ok'] ? 0 : 1);
