#!/usr/bin/env php
<?php
/**
 * Correo del sistema (cron, root…) entregado en el propio servidor.
 *
 *   php bin/mail-system-local.php            simula: dice qué haría y por qué (no cambia nada)
 *   php bin/mail-system-local.php --apply    lo aplica (como root): copia de main.cf, postfix check y marcha atrás si falla
 *   php bin/mail-system-local.php --mode=auto|warn|off   fija el modo (auto = el cron del clúster lo mantiene solo)
 */
declare(strict_types=1);

define('PANEL_ROOT', dirname(__DIR__));
require_once PANEL_ROOT . '/app/bootstrap.php';

use MuseDockPanel\Services\SystemMailService as S;
use MuseDockPanel\Settings;

$opts = getopt('', ['apply', 'mode:']);

if (isset($opts['mode'])) {
    $m = (string)$opts['mode'];
    if (!in_array($m, ['off', 'warn', 'auto'], true)) {
        fwrite(STDERR, "Modo no válido: use off, warn o auto.\n");
        exit(2);
    }
    Settings::set('mail_system_local_mode', $m);
    echo "Modo del correo del sistema: {$m}\n";
}

$pf = S::inspect();
$plan = S::plan($pf);
echo "Origen del correo del sistema: " . ($pf['origin'] ?: '(desconocido)') . "\n";
echo "mydestination actual:          " . ($pf['mydestination'] ?: '(vacío)') . "\n";
echo "Dominio virtual/relay/transp.: " . ($pf['origin_virtual'] === null ? 'no se pudo saber' : ($pf['origin_virtual'] ? 'sí' : 'no')) . "\n";
echo "Modo:                          " . S::mode() . "\n";
echo "Decisión: {$plan['action']} — {$plan['reason']}\n";
if ($plan['action'] === 'add') {
    echo "   mydestination pasaría a: {$plan['mydestination']}\n";
}
if (isset($opts['apply'])) {
    $r = S::apply(true);
    echo ($r['ok'] ? 'OK: ' : 'ERROR: ') . $r['message'] . "\n";
    exit($r['ok'] ? 0 : 1);
}
if ($plan['action'] === 'add') {
    echo "\nSolo se ha simulado. Para aplicarlo (como root): php bin/mail-system-local.php --apply\n";
}
