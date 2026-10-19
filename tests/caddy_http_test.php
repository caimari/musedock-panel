#!/usr/bin/env php
<?php
/**
 * Servidor :80 de Caddy con la redirección http → https (Obelix 2026-10-19). Lógica pura: no toca Caddy.
 * Usage: php tests/caddy_http_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

use MuseDockPanel\Services\SystemService as S;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }

echo "\n\033[1mConfiguración de Caddy\033[0m\n";
$obelix = ['srv0' => ['listen' => [':8444', ':443', ':8443'], 'routes' => []]];
ok('caso Obelix (solo :8444, :443, :8443): nadie escucha en el 80', S::serversListenOnPort($obelix, 80) === false);
ok('«:80» cuenta', S::serversListenOnPort(['srv1' => ['listen' => [':80']]], 80));
ok('«0.0.0.0:80» cuenta', S::serversListenOnPort(['a' => ['listen' => ['0.0.0.0:80']]], 80));
ok('«tcp/:80» cuenta', S::serversListenOnPort(['a' => ['listen' => ['tcp/:80']]], 80));
ok(':8080 y :180 NO son el 80', !S::serversListenOnPort(['a' => ['listen' => [':8080', ':180']]], 80));
ok('sin servidores no escucha nadie', !S::serversListenOnPort([], 80));
ok('caddy-l4 en el :80 también cuenta', S::serversListenOnPort(['http_redirect' => ['listen' => [':80']]], 80));

echo "\n\033[1mSalida de «ss -ltnH»\033[0m\n";
$ss = "LISTEN 0 4096 0.0.0.0:443 0.0.0.0:*\nLISTEN 0 4096 *:8444 *:*\nLISTEN 0 128 0.0.0.0:22 0.0.0.0:*\n";
ok('sin el 80 en la lista', S::ssListensOn($ss, 80) === false);
ok('con 0.0.0.0:80', S::ssListensOn($ss . "LISTEN 0 4096 0.0.0.0:80 0.0.0.0:*\n", 80));
ok('con [::]:80', S::ssListensOn($ss . "LISTEN 0 4096 [::]:80 [::]:*\n", 80));
ok('con *:80', S::ssListensOn($ss . "LISTEN 0 4096 *:80 *:*\n", 80));
ok('el :8080 y el :180 no se confunden con el 80', !S::ssListensOn("LISTEN 0 4096 0.0.0.0:8080 0.0.0.0:*\nLISTEN 0 1 0.0.0.0:180 0.0.0.0:*\n", 80));

echo "\n\033[1mSeguridad del código\033[0m\n";
$src = file_get_contents(PANEL_ROOT . '/app/Services/SystemService.php');
$i = strpos($src, 'public static function ensureCaddyHttpRedirectServer');
$body = substr($src, $i, 3500);
ok('solo CREA su propia clave (caddyCreatePath), sin PATCH/PUT/DELETE ni /load', str_contains($body, 'caddyCreatePath') && !preg_match("/'(PATCH|PUT|DELETE)'/", $body) && !str_contains($body, '/load'));
ok('no hace nada si ya hay un servidor en el :80 (http o layer4)', str_contains($body, "serversListenOnPort(\$servers, 80)") && str_contains($body, 'layer4'));
ok('deja pasar el desafío HTTP-01 de los certificados', str_contains($body, '/.well-known/acme-challenge/*'));
ok('redirige con 308 a https conservando host y ruta', str_contains($body, '308') && str_contains($body, 'https://{http.request.host}{http.request.uri}'));

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
