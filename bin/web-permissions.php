<?php
/**
 * Dueños de los ficheros de cada web y carpetas de Laravel (WebPermissionService).
 *
 * Por defecto SOLO INFORMA: lista, por web, los ficheros y carpetas cuyo dueño no es root ni el usuario de la
 * web (los que una réplica no podría leer y darían error 500). Con --fix cambia dueño y grupo (nunca los
 * permisos) y antes guarda la lista de dueños anteriores en /var/lib/musedock/permisos-<fecha>.txt.
 *
 * Uso (como root para cambiar algo):
 *   php bin/web-permissions.php                      informa de todas las webs
 *   php bin/web-permissions.php --domain=ejemplo.com informa de una web
 *   php bin/web-permissions.php --fix                corrige los dueños (guardando antes lo anterior)
 *   php bin/web-permissions.php --laravel-dirs       crea las carpetas de Laravel que falten (storage/framework/…)
 *   php bin/web-permissions.php --restore /var/lib/musedock/permisos-20261018_130000.txt   deshace una corrección
 * Extras que usa el panel: --notify (avisa por el canal de avisos) y --reason=<texto>.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\WebPermissionService as W;

$opts = [];
foreach (array_slice($argv ?? [], 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } elseif (isset($opts['restore']) && $opts['restore'] === true) {
        $opts['restore'] = $a;
    }
}
$isRoot = !function_exists('posix_geteuid') || posix_geteuid() === 0;
$mutates = isset($opts['fix']) || isset($opts['restore']) || isset($opts['laravel-dirs']);
if ($mutates && !$isRoot) {
    fwrite(STDERR, "Para cambiar algo hay que ejecutarlo como root (sin --fix, --laravel-dirs ni --restore solo informa).\n");
    exit(1);
}

if (isset($opts['restore'])) {
    $r = W::restore((string)$opts['restore']);
    echo $r['ok'] ? "Restaurados {$r['restored']} ficheros/carpetas.\n" : "ERROR: {$r['error']}\n";
    exit($r['ok'] ? 0 : 1);
}

$domain = is_string($opts['domain'] ?? null) ? $opts['domain'] : null;
$reason = is_string($opts['reason'] ?? null) ? $opts['reason'] : 'manual';
$fix = isset($opts['fix']);

$rep = W::run($fix, $domain, $reason);
if (!$rep['webs']) {
    echo "Ninguna web tiene ficheros con dueño ajeno.\n";
}
foreach ($rep['webs'] as $d => $w) {
    echo sprintf("%-28s %5d con dueño ajeno (%s) · la web corre como %s%s\n", $d, $w['count'], implode('/', $w['owners']), $w['user'], $fix ? " · corregidos {$w['fixed']}" : '');
    foreach ($w['examples'] as $e) {
        echo "    {$e}\n";
    }
}
if ($rep['backup']) {
    echo "Dueños anteriores guardados en {$rep['backup']} (deshacer: php bin/web-permissions.php --restore {$rep['backup']})\n";
}
foreach ($rep['errors'] as $e) {
    echo "ERROR: {$e}\n";
}
if (!$fix && $rep['total'] > 0) {
    echo "\nSolo se ha informado. Para corregirlo: php bin/web-permissions.php --fix\n";
}

if (isset($opts['laravel-dirs'])) {
    $made = W::ensureLaravelDirs(true, $domain);
    echo $made ? 'Carpetas de Laravel creadas: ' . count($made) . "\n" : "Carpetas de Laravel: no faltaba ninguna.\n";
    foreach ($made as $m) {
        echo "    {$m['domain']}: {$m['dir']}\n";
    }
}
if (isset($opts['notify'])) {
    W::notify($rep);
}
