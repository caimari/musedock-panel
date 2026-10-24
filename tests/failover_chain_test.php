#!/usr/bin/env php
<?php
/**
 * Cadena de caídas del relevo (hueco del 2026-10-19): los papeles de la lista siguen a quien manda de verdad y una réplica
 * atrasada no se promueve sola. Lógica pura: no toca ajustes, bases de datos ni nodos.
 * Usage: php tests/failover_chain_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

use MuseDockPanel\Services\FailoverService as F;
use MuseDockPanel\Services\FailoverSafetyService as S;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }

// A = titular (nodo de siempre), B = su relevo. Estado real tras un relevo por caída de A: manda B, A es réplica viva,
// pero la lista sigue como antes (principal = A).
$stale = [
    ['id' => 'A', 'ip' => '10.0.0.1', 'role' => 'primary', 'enabled' => true, 'failover_to' => 'B', 'failover_priority' => 99],
    ['id' => 'B', 'ip' => '10.0.0.2', 'role' => 'failover', 'enabled' => true, 'failover_to' => '', 'failover_priority' => 1, 'site_probes' => 'ping:10.0.0.254'],
];
$bDown = ['A' => ['ok' => true], 'B' => ['ok' => false]];

echo "\n\033[1mEl hueco: la lista sin ordenar no ve caer al que manda\033[0m\n";
ok('con la lista sin ordenar, si cae B (el que manda) el estado sale «normal»: A no vería ninguna caída', F::stateFor($stale, $bDown) === F::STATE_NORMAL);

echo "\n\033[1mAlinear los papeles con quien manda de verdad\033[0m\n";
ok('en A (réplica, vivo) el master real es B, el otro servidor', S::class !== '' && F::realMasterId($stale, 'A', 'slave', false) === 'B');
ok('en B (master) el master real es él mismo', F::realMasterId($stale, 'B', 'master', false) === 'B');
ok('un master apartado (stale) no toca la lista', F::realMasterId($stale, 'A', 'master', true) === null);
ok('un nodo standalone no toca la lista', F::realMasterId($stale, 'A', 'standalone', false) === null);
$three = array_merge($stale, [['id' => 'C', 'ip' => '10.0.0.3', 'role' => 'failover', 'enabled' => true]]);
ok('con 3 servidores una réplica no sabe cuál manda: no toca nada', F::realMasterId($three, 'A', 'slave', false) === null);
ok('un servidor desactivado no cuenta como «el otro»', F::realMasterId(array_merge($stale, [['id' => 'C', 'ip' => '10.0.0.3', 'role' => 'failover', 'enabled' => false]]), 'A', 'slave', false) === 'B');
ok('no se encuentra a sí mismo en la lista: no toca nada', F::realMasterId($stale, '', 'slave', false) === null);
$aligned = F::rolesForMaster($stale, 'B');
ok('B pasa a principal y A a relevo', $aligned[1]['role'] === 'primary' && $aligned[0]['role'] === 'failover');
ok('el principal apunta a su relevo y el relevo a nadie', $aligned[1]['failover_to'] === 'A' && $aligned[0]['failover_to'] === '');
ok('los demás datos de cada servidor no cambian (IP, vecinos, prioridad)', $aligned[1]['site_probes'] === 'ping:10.0.0.254' && $aligned[0]['ip'] === '10.0.0.1' && $aligned[0]['failover_priority'] === 99);
ok('alinear dos veces da lo mismo', F::rolesForMaster($aligned, 'B') === $aligned);

echo "\n\033[1mLa cadena entera, ya con la lista ordenada\033[0m\n";
ok('B manda y cae, A vivo: ahora SÍ es «principal caído» y A puede promoverse', F::stateFor($aligned, $bDown) === F::STATE_PRIMARY_DOWN);
ok('los dos vivos: normal', F::stateFor($aligned, ['A' => ['ok' => true], 'B' => ['ok' => true]]) === F::STATE_NORMAL);
ok('los dos caídos a la vez: emergencia (no hay quien promover; vuelve el que mandaba y sigue mandando)', F::stateFor($aligned, ['A' => ['ok' => false], 'B' => ['ok' => false]]) === F::STATE_EMERGENCY);
$back = F::rolesForMaster($aligned, 'A');
ok('A vuelve a mandar (paso final de la cadena): ahora el que vigila es B', F::stateFor($back, ['A' => ['ok' => false], 'B' => ['ok' => true]]) === F::STATE_PRIMARY_DOWN);

echo "\n\033[1mUna réplica atrasada no se promueve sola\033[0m\n";
$now = 1_800_000_000;
ok('al día hace 3 min: puede', S::freshnessVerdict($now - 180, $now, 30)['ok'] === true);
$v = S::freshnessVerdict($now - 90 * 60, $now, 30);
ok('al día por última vez hace 90 min (acaba de volver o su réplica estaba rota): NO, con el motivo', $v['ok'] === false && str_contains($v['reason'], '90 min'), json_encode($v));
ok('sin dato se bloquea la promoción automática', S::freshnessVerdict(null, $now, 30)['ok'] === false && S::freshnessVerdict(0, $now, 30)['ok'] === false);
ok('con la comprobación desactivada (failover_require_fresh = 0) siempre puede', S::freshnessVerdict($now - 999 * 60, $now, 30, false)['ok'] === true);
ok('la espera por los vecinos y testigos no atrasa la copia: con el master 40 min caído, un dato de hace 60 min vale (límite 30 + 40)', S::freshnessVerdict($now - 60 * 60, $now, 30, true, 40)['ok'] === true);
ok('pero un dato de hace 90 min con el master caído 40 min no (límite 70)', S::freshnessVerdict($now - 90 * 60, $now, 30, true, 40)['ok'] === false);
ok('justo en el límite sigue valiendo', S::freshnessVerdict($now - 30 * 60, $now, 30)['ok'] === true);

echo "\n\033[1mCableado\033[0m\n";
$w = file_get_contents(PANEL_ROOT . '/bin/failover-worker.php');
ok('el vigilante alinea los papeles en cada vuelta', str_contains($w, 'FailoverService::alignRolesWithRealMaster()'));
ok('el vigilante anota la copia al día solo en réplicas no apartadas', str_contains($w, "Settings::set('replica_fresh_at'") && str_contains($w, "=== 'slave'") && str_contains($w, 'replicaDbHealthyNow()'));
$pos = strpos($w, 'freshnessVerdict(');
$prom = strpos($w, 'promoviendo este servidor');
ok('la comprobación de copia al día va ANTES de promover', $pos !== false && $prom !== false && $pos < $prom);
ok('si la copia no está al día avisa y NO promueve', str_contains($w, 'relevo automático NO hecho (la copia no está al día)'));

echo "\n\033[1mReincorporación como copia: automática, con aviso, botón y correo (hubo que hacer «demote» a mano en dos nodos)\033[0m\n";
$pend = ['name' => 'B', 'host' => '10.10.70.9', 'reason' => 'PostgreSQL 14/main: tiene escrituras propias', 'since' => $now - 600, 'last_try' => $now - 400, 'last_mail' => $now - 7 * 3600];
ok('hace 400 s del último intento: toca reintentar (cada 5 min)', S::rejoinRetryDue($pend, $now) === true);
ok('hace 100 s: aún no', S::rejoinRetryDue(['last_try' => $now - 100] + $pend, $now) === false);
ok('sin nada pendiente no se reintenta', S::rejoinRetryDue(null, $now) === false);
ok('recordatorio por correo cada 6 h: a las 7 h toca', S::rejoinReminderDue($pend, $now) === true);
ok('a las 2 h no', S::rejoinReminderDue(['last_mail' => $now - 7200] + $pend, $now) === false);
[$subj, $body] = S::rejoinEmail('B', '10.10.70.9', 'PostgreSQL 14/main: tiene escrituras propias que el nuevo master no tiene');
ok('el correo dice que hay que reincorporarlo y quién decide', str_contains($subj, 'reincorporarlo') && str_contains($subj, 'decide tú'));
ok('el correo explica el botón del Dashboard y la orden de terminal', str_contains($body, 'Reincorporar como copia de B') && str_contains($body, 'cluster-switch.php demote 10.10.70.9'));
ok('si hay escrituras propias, avisa de que se descartan', str_contains($body, 'DESCARTA'));
[, $clean] = S::rejoinEmail('B', '10.10.70.9', 'no se pudo conectar con el nuevo master');
ok('si no hay escrituras propias, dice que solo trae lo que falta', str_contains($clean, 'solo trae lo que le falta') && !str_contains($clean, 'DESCARTA'));
ok('el recordatorio lo indica en el asunto', str_starts_with(S::rejoinEmail('B', '10.10.70.9', 'x', true)[0], 'Recordatorio:'));
ok('el correo cuenta que se reintenta solo cada 5 minutos y se recuerda cada 6 horas', str_contains($body, 'cada 5 minutos') && str_contains($body, 'cada 6 horas'));
$sf = file_get_contents(PANEL_ROOT . '/app/Services/FailoverSafetyService.php');
ok('al apartarse sin reincorporarse solo, queda anotado como pendiente y se envía el correo', str_contains($sf, 'self::markRejoinPending((string)$n[\'name\'], $host, $why)') && str_contains($sf, 'self::rejoinEmail('));
ok('al reincorporarse se borra lo pendiente', str_contains(file_get_contents(PANEL_ROOT . '/app/Services/ClusterService.php'), 'FailoverSafetyService::clearRejoinPending()'));
ok('el cron del clúster reintenta y recuerda', str_contains(file_get_contents(PANEL_ROOT . '/bin/cluster-worker.php'), 'rejoinRetryDue(') && str_contains(file_get_contents(PANEL_ROOT . '/bin/cluster-worker.php'), 'rejoinReminderDue('));
ok('la ruta del botón existe y está protegida con CSRF', str_contains(file_get_contents(PANEL_ROOT . '/public/index.php'), "'/cluster/rejoin', 'ClusterController@rejoinLocal'") && str_contains(file_get_contents(PANEL_ROOT . '/app/Controllers/ClusterController.php'), "function rejoinLocal(): void\n    {\n        View::verifyCsrf();"));
$lay = file_get_contents(PANEL_ROOT . '/resources/views/layouts/main.php');
ok('el aviso rojo (en todas las páginas, también el Dashboard) lleva el botón y el motivo', str_contains($lay, 'action="/cluster/rejoin"') && str_contains($lay, 'Reincorporar como copia de') && str_contains($lay, "(string)\$__rj['reason']"));
ok('el botón pide confirmación y avisa de lo que puede descartar', str_contains($lay, 'puede descartar lo escrito aquí'));

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
