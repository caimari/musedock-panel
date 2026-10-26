<?php
/**
 * «Suspender + quitar de Caddy» llega a las réplicas: la orden suspend_hosting lleva remove_caddy y la réplica
 * lo interpreta igual que el master (sin esto dejaba una página de mantenimiento en vez de quitar la ruta).
 * Solo funciones puras: no toca Caddy, ni la base de datos, ni los nodos.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\ClusterService as C;

$ok = 0; $bad = 0;
function check(string $n, bool $c): void { global $ok, $bad; echo ($c ? "  ✓ " : "  ✗ ") . $n . "\n"; $c ? $ok++ : $bad++; }

echo "Orden de la réplica\n";
check('remove_caddy true → quitar la ruta', C::wantsCaddyRemoval(['remove_caddy' => true]) === true);
check('«1» (formulario) → quitar', C::wantsCaddyRemoval(['remove_caddy' => '1']) === true);
check('«true» (JSON como texto) → quitar', C::wantsCaddyRemoval(['remove_caddy' => 'true']) === true);
check('1 numérico → quitar', C::wantsCaddyRemoval(['remove_caddy' => 1]) === true);
check('false → página de mantenimiento', C::wantsCaddyRemoval(['remove_caddy' => false]) === false);
check('«0» → página de mantenimiento', C::wantsCaddyRemoval(['remove_caddy' => '0']) === false);
check('vacío → página de mantenimiento', C::wantsCaddyRemoval(['remove_caddy' => '']) === false);
check('texto raro → página de mantenimiento (no quita por error)', C::wantsCaddyRemoval(['remove_caddy' => 'quizás']) === false);
check('sin el campo (orden de un master antiguo) → página de mantenimiento', C::wantsCaddyRemoval(['username' => 'x', 'domain' => 'a.com']) === false);

echo "\n" . ($bad ? "$bad fallo(s) de " . ($ok + $bad) : "Todo correcto: $ok comprobaciones") . "\n";
exit($bad ? 1 : 0);
