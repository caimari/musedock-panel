<?php
/** Aviso de PHP sin parchear: lectura de dpkg, comparación de versiones y mínimos. Funciones puras (no manda avisos). */
require_once dirname(__DIR__) . '/app/bootstrap.php';
use MuseDockPanel\Services\PhpPatchService as P;
$ok = 0; $bad = 0;
function check(string $n, bool $c): void { global $ok, $bad; echo ($c ? "  ✓ " : "  ✗ ") . $n . "\n"; $c ? $ok++ : $bad++; }

echo "Lectura de dpkg\n";
$out = "php8.3-cli install ok installed 8.3.30-3+ubuntu24.04.1+deb.sury.org+1\nphp8.3-fpm install ok installed 8.3.36-1+ubuntu24.04.1+deb.sury.org+1\n"
     . "php8.1-fpm install ok installed 8.1.34-8+ubuntu22.04.1+deb.sury.org+1\nphp8.4-cli deinstall ok config-files 8.4.20-1\n";
$i = P::parseInstalled($out);
check('rama 8.3 con el valor más bajo entre cli y fpm', ($i['8.3'] ?? '') === '8.3.30-3+ubuntu24.04.1+deb.sury.org+1');
check('rama 8.1 leída', isset($i['8.1']));
check('paquete desinstalado (config-files) no cuenta', !isset($i['8.4']));
check('sin salida → vacío', P::parseInstalled('') === []);

echo "Comparación\n";
check('8.3.30 < 8.3.36', P::lessThan('8.3.30-3+ubuntu24.04.1+deb.sury.org+1', '8.3.36'));
check('8.3.36-1 no es menor que 8.3.36', !P::lessThan('8.3.36-1+ubuntu24.04.1+deb.sury.org+1', '8.3.36'));
check('8.1.34-8 < 8.1.34-9', P::lessThan('8.1.34-8+ubuntu22.04.1+deb.sury.org+1', '8.1.34-9'));
check('8.1.34-9+ubuntu no es menor que 8.1.34-9', !P::lessThan('8.1.34-9+ubuntu22.04.1+deb.sury.org+1', '8.1.34-9'));
check('8.4.27 no es menor que 8.4.27', !P::lessThan('8.4.27-1+ubuntu24.04.1+deb.sury.org+1', '8.4.27'));

echo "Atrasadas\n";
$min = P::MINIMUM;
$b = P::behind(['8.1' => '8.1.34-8+ubuntu22.04.1+deb.sury.org+1', '8.3' => '8.3.36-1+ubuntu24.04.1+deb.sury.org+1', '8.4' => '8.4.20-1+deb.sury.org+1'], $min);
check('detecta 8.1 y 8.4, no 8.3', array_keys($b) === ['8.1', '8.4']);
check('guarda instalada y mínima', ($b['8.1'][1] ?? '') === '8.1.34-9');
check('rama sin mínimo conocida se ignora', P::behind(['7.4' => '7.4.33-1+deb.sury.org+1'], $min) === []);
check('paquete de la distribución (sin sury) no se compara', P::behind(['8.3' => '8.3.6-0ubuntu0.24.04.5'], $min) === []);
check('todo al día → vacío', P::behind(['8.3' => '8.3.36-1+ubuntu24.04.1+deb.sury.org+1'], $min) === []);

echo "\n" . ($bad ? "$bad fallo(s) de " . ($ok + $bad) : "Todo correcto: $ok comprobaciones") . "\n";
