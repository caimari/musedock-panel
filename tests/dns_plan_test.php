#!/usr/bin/env php
<?php
/**
 * Plan DNS de un cambio de mando: cómo se explican los dominios cuya zona NO está en las cuentas de Cloudflare del panel
 * (caso app.agent8ai.com, 2026-10-19: un CNAME con proxy a un nombre que sí se mueve se mostraba como «no se puede mover»).
 * Lógica pura. Usage: php tests/dns_plan_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

use MuseDockPanel\Services\DnsPlanService as P;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }

echo "\n\033[1mIPs de Cloudflare\033[0m\n";
ok('188.114.97.3 y 188.114.96.3 (las de app.agent8ai.com) son de Cloudflare', P::isCloudflareIp('188.114.97.3') && P::isCloudflareIp('188.114.96.3'));
ok('104.16.0.1 y 172.67.209.160 también', P::isCloudflareIp('104.16.0.1') && P::isCloudflareIp('172.67.209.160'));
ok('una IP de la oficina o de Contabo no', !P::isCloudflareIp('213.201.21.155') && !P::isCloudflareIp('207.180.244.219') && !P::isCloudflareIp('1.1.1.1'));
ok('basura no es IP', !P::isCloudflareIp('no-es-ip') && !P::isCloudflareIp(''));

echo "\n\033[1mQué decir de un dominio fuera de las cuentas del panel\033[0m\n";
$origin = ['213.201.21.155'];
$h = P::outsideZoneHint(['188.114.97.3', '188.114.96.3'], $origin);
ok('detrás de Cloudflare con proxy: «destino no visible», NO un fallo', $h['kind'] === 'behind_cloudflare' && str_contains($h['text'], 'irá con él'), json_encode($h));
$h = P::outsideZoneHint(['213.201.21.155'], $origin);
ok('apunta directo a la IP de este servidor: problema REAL, con la solución', $h['kind'] === 'points_here' && str_contains($h['text'], 'NO se moverá solo') && str_contains($h['text'], 'acceso a su zona'), json_encode($h));
ok('si da la IP de origen y además IPs de Cloudflare, gana el problema real', P::outsideZoneHint(['188.114.97.3', '213.201.21.155'], $origin)['kind'] === 'points_here');
ok('IP de otro sitio: mensaje genérico', P::outsideZoneHint(['8.8.8.8'], $origin)['kind'] === 'unknown');
ok('mezcla de Cloudflare y otra IP: genérico (no se afirma nada)', P::outsideZoneHint(['188.114.97.3', '8.8.8.8'], $origin)['kind'] === 'unknown');
ok('sin DNS público: genérico', P::outsideZoneHint([], $origin)['kind'] === 'unknown');

echo "\n\033[1mCNAME visible en el DNS público (dominio de otra cuenta, sin proxy)\033[0m\n";
$moved = ['asterisk.muserelay.com' => true];
$map = ['app.cliente.com' => 'live.muserelay.com', 'live.muserelay.com' => 'asterisk.muserelay.com', 'otro.cliente.com' => 'sitio.externo.net'];
$res = static fn(string $n): ?string => $map[$n] ?? null;
ok('un CNAME que llega a un nombre que se mueve (dos saltos) va con él', P::publicCnameFollows('app.cliente.com', $moved, $res) === true);
ok('un CNAME a otro sitio no', P::publicCnameFollows('otro.cliente.com', $moved, $res) === false);
ok('un nombre sin CNAME no', P::publicCnameFollows('sin.cname.com', $moved, $res) === false);
ok('un bucle de CNAME no cuelga (corta a los 10 saltos)', P::publicCnameFollows('a.x', $moved, static fn($n) => $n === 'a.x' ? 'b.x' : 'a.x') === false);

echo "\n\033[1mCableado\033[0m\n";
$src = file_get_contents(PANEL_ROOT . '/app/Services/DnsPlanService.php');
ok('un nombre de máquina que se queda no se lista como «no se puede mover»', str_contains($src, 'in_array(strtolower($n), $machineLower, true)'));
$dash = file_get_contents(PANEL_ROOT . '/resources/views/dashboard/index.php');
ok('el Dashboard separa «destino no visible» (informativo) de lo que de verdad no se puede mover', str_contains($dash, "'behind_cloudflare'") && str_contains($dash, 'Destino no visible desde aquí'));

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
