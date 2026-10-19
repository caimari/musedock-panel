#!/usr/bin/env php
<?php
/**
 * Reglas de iptables repetidas: solo cuentan las de cadenas que se usan (caso asterisk, 2026-10-19: duplicados en cadenas
 * ufw-* que ninguna regla de INPUT usa). Lógica pura sobre la salida de «iptables -S».
 * Usage: php tests/firewall_dups_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

use MuseDockPanel\Services\FirewallAuditService as F;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }

echo "\n\033[1mCaso asterisk: duplicados solo en cadenas ufw que nadie usa\033[0m\n";
$asterisk = [
    '-P INPUT DROP', '-N ufw-before-input', '-N ufw-user-input', '-N f2b-x',
    '-A INPUT -j f2b-x', '-A INPUT -p tcp -m tcp --dport 80 -j ACCEPT',
    '-A ufw-before-input -j ufw-user-input', '-A ufw-before-input -j ufw-user-input',
    '-A ufw-before-forward -j ufw-user-forward', '-A ufw-before-forward -j ufw-user-forward',
    '-A ufw-user-input -p tcp -m tcp --dport 443 -j ACCEPT',
];
$r = F::duplicateRules($asterisk);
ok('los duplicados de cadenas sin uso NO son «vivos»', $r['live'] === [], json_encode($r['live']));
ok('se reconocen como huérfanos', count($r['orphan']) === 2 && isset($r['orphan']['-A ufw-before-input -j ufw-user-input']));
ok('se detecta que ufw no está enlazado a INPUT', $r['ufw_wired'] === false);

echo "\n\033[1mCaso mortadelo: duplicado en una cadena que sí se usa\033[0m\n";
$mort = ['-P INPUT DROP', '-A INPUT -p tcp -m multiport --dports 80,443 -j f2b-wp', '-A INPUT -p tcp -m multiport --dports 80,443 -j f2b-wp', '-A f2b-wp -j RETURN'];
$m = F::duplicateRules($mort);
ok('una regla repetida en INPUT sí cuenta', count($m['live']) === 1 && isset($m['live']['-A INPUT -p tcp -m multiport --dports 80,443 -j f2b-wp']), json_encode($m['live']));

echo "\n\033[1mCadenas alcanzables por saltos encadenados\033[0m\n";
$chain = ['-A INPUT -j a', '-A a -j b', '-A b -j ACCEPT', '-A b -j ACCEPT', '-A zzz -j ACCEPT', '-A zzz -j ACCEPT'];
$c = F::duplicateRules($chain);
ok('un duplicado dos saltos más abajo (INPUT → a → b) cuenta como vivo; el de «zzz» (sin uso), como huérfano', count($c['live']) === 1 && isset($c['live']['-A b -j ACCEPT']) && isset($c['orphan']['-A zzz -j ACCEPT']), json_encode($c));
ok('con ufw enlazado desde INPUT se ve como enlazado', F::duplicateRules(['-A INPUT -j ufw-before-input', '-A ufw-before-input -j ACCEPT'])['ufw_wired'] === true);
ok('sin reglas no hay nada', F::duplicateRules([]) === ['live' => [], 'orphan' => [], 'ufw_wired' => false]);

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
