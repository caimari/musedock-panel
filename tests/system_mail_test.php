#!/usr/bin/env php
<?php
/**
 * Correo del sistema entregado en el propio servidor (SystemMailService). Solo lógica pura: no toca Postfix.
 * Usage: php tests/system_mail_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

use MuseDockPanel\Services\SystemMailService as S;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }

$real = ['origin' => 'mail.musedock.com', 'myhostname' => 'mail.musedock.com', 'mydestination' => 'localhost', 'origin_virtual' => false, 'relayhost' => '[10.10.70.2]:25'];

echo "\n\033[1mCaso real (2026-10-18)\033[0m\n";
$p = S::plan($real);
ok('origen no local y no virtual: se añade a mydestination', $p['action'] === 'add' && $p['mydestination'] === 'localhost, mail.musedock.com', json_encode($p));

echo "\n\033[1mNo se toca\033[0m\n";
ok('ya es local: nada', S::plan(['mydestination' => 'localhost, mail.musedock.com'] + $real)['action'] === 'none');
ok('$myhostname en mydestination y origen = hostname: nada', S::plan(['mydestination' => '$myhostname, localhost.$mydomain, localhost'] + $real)['action'] === 'none');
$v = S::plan(['origin_virtual' => true] + $real);
ok('dominio de buzones virtuales/relay/transporte: se salta y se explica (rompería el correo)', $v['action'] === 'skip' && str_contains($v['reason'], 'rompería'), json_encode($v));
$u = S::plan(['origin_virtual' => null] + $real);
ok('si no se pudo comprobar si es virtual: se salta (nunca a ciegas)', $u['action'] === 'skip' && str_contains($u['reason'], 'no se toca nada'));
$c = S::plan(['mydestination' => 'localhost, example.org, otro.net'] + $real);
ok('mydestination con configuración propia del administrador: no se pisa', $c['action'] === 'skip' && str_contains($c['reason'], 'propia'), json_encode($c));
ok('sin origen conocido: nada', S::plan(['origin' => ''] + $real)['action'] === 'none');
ok('origen con caracteres raros: nada (no se mete en main.cf)', S::plan(['origin' => 'a b;rm -rf /'] + $real)['action'] === 'none');
ok('el valor por defecto de Postfix sí se amplía', S::plan(['mydestination' => 'localhost.localdomain, localhost'] + $real)['action'] === 'add');
ok('mydestination vacío (modo relay del instalador) se amplía sin coma sobrante', S::plan(['mydestination' => ''] + $real)['mydestination'] === 'mail.musedock.com');

echo "\n\033[1mDominio con correo real (asterisk: origen muserelay.com, 2026-10-19)\033[0m\n";
$relay = ['origin' => 'muserelay.com', 'myhostname' => 'asterisk.muserelay.com', 'mydestination' => '', 'origin_virtual' => false];
$r = S::plan($relay);
ok('origen = un dominio y no el nombre del servidor: NO se añade (capturaría el correo hacia ese dominio)', $r['action'] === 'skip' && str_contains($r['reason'], 'capturaría'), json_encode($r));
ok('sin saber el nombre del servidor tampoco se añade', S::plan(['myhostname' => ''] + $real)['action'] === 'skip');
ok('con origen = nombre del servidor (mortadelo, caso seguro) sí', S::plan($real)['action'] === 'add');

ok('si el nombre tiene MX público se trata como dominio de correo y no se toca', str_contains(file_get_contents(PANEL_ROOT . '/app/Services/SystemMailService.php'), 'self::hasPublicMx($origin)'));
ok('un dominio de correo dado de alta en el panel tampoco', str_contains(file_get_contents(PANEL_ROOT . '/app/Services/SystemMailService.php'), 'self::panelHasMailDomain($origin)'));

echo "\n\033[1mModo y seguridad\033[0m\n";
ok('el modo por defecto es avisar, no cambiar solo', S::mode() === 'warn');
$src = file_get_contents(PANEL_ROOT . '/app/Services/SystemMailService.php');
ok('se hace copia de main.cf antes de cambiar', str_contains($src, "copy(\$mainCf, \$backup)") || str_contains($src, '@copy($mainCf, $backup)'));
ok('se valida con postfix check y se restaura si falla', str_contains($src, 'postfix check') && str_contains($src, '@copy($backup, $mainCf)'));
ok('un error de lectura de postmap se trata como «no se sabe» (no como «no está»)', str_contains($src, 'postmap: (fatal|error|warning)'));

echo "\n\033[1mMapas de Postfix sin contraseña (filemon, 2026-10-19: 43 envíos autenticados rechazados)\033[0m\n";
use MuseDockPanel\Services\MailPolicyService as MP;
ok('password vacío con la base por red: se detecta', MP::cfHasEmptyPassword("hosts = 127.0.0.1:5433\nuser = musedock_mail\npassword = \ndbname = x\n"));
ok('con contraseña: bien', !MP::cfHasEmptyPassword("hosts = 127.0.0.1:5433\nuser = u\npassword = s3cr3t\ndbname = x\n"));
ok('base por socket Unix sin contraseña (válido): no se avisa', !MP::cfHasEmptyPassword("hosts = /var/run/postgresql\nuser = u\npassword =\ndbname = x\n"));
ok('fichero sin línea password: no se avisa', !MP::cfHasEmptyPassword("hosts = 127.0.0.1\nuser = u\ndbname = x\n"));
$r = MP::installSenderPolicy(['host' => '127.0.0.1', 'port' => 5433, 'dbname' => 'x', 'user' => 'u', 'password' => '']);
ok('instalar el mapa de remitentes con contraseña vacía se RECHAZA (no se escribe nada)', $r['ok'] === false && str_contains((string)$r['error'], 'contraseña'), json_encode($r));

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
