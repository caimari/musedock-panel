<?php
/**
 * Prueba de humo de las webs de ESTE servidor (WebSmokeService): la portada de cada web contra 127.0.0.1 y,
 * si redirige a otra web del servidor, esa página. Un 5xx o ninguna respuesta es un error y se dice la
 * causa (última línea de error de la web). Solo lee: no cambia nada.
 *
 * Uso:
 *   php bin/web-smoke.php                       todas las webs
 *   php bin/web-smoke.php --domain=ejemplo.com  una web
 *   php bin/web-smoke.php --notify              además, avisa por el canal de avisos si alguna falla
 * Sale con código 2 si alguna web da error.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\WebSmokeService as S;

$opts = [];
foreach (array_slice($argv ?? [], 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$rep = S::run(is_string($opts['domain'] ?? null) ? $opts['domain'] : null);
foreach ($rep['results'] as $r) {
    printf("%-30s %s %s%s%s\n", $r['domain'], $r['error'] ? 'ERROR' : 'ok   ', $r['final'] === 0 ? 'sin respuesta' : $r['final'], $r['note'] !== '' ? ' ' . $r['note'] : '', $r['reason'] !== '' ? "\n    {$r['reason']}" : '');
}
echo "\n{$rep['ok']} bien, {$rep['errors']} con error\n";
$fenced = is_file(\MuseDockPanel\Services\FailoverSafetyService::FENCE_FLAG) || \MuseDockPanel\Settings::get('cluster_fenced', '0') === '1';
if (isset($opts['notify']) && !$fenced) {   // un nodo apartado no sirve webs: avisar sería ruido
    S::notify($rep, is_string($opts['reason'] ?? null) ? $opts['reason'] : '');
}
exit($rep['errors'] > 0 ? 2 : 0);
