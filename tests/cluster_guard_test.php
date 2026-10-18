#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — ClusterGuardService (standalone, sin PHPUnit).
 *
 * Solo lógica pura: no arranca ni para servicios, no escribe ajustes, no avisa a nadie.
 * Casos del relevo real del 2026-10-18 (Dovecot fallido horas, parámetros de PostgreSQL
 * distintos entre asterisk y obelix, un nodo registrado por un nombre que el relevo movió).
 *
 * Usage: php tests/cluster_guard_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

use MuseDockPanel\Services\ClusterGuardService as G;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }
function section(string $t) { echo "\n\033[1m{$t}\033[0m\n"; }

$now = 1_800_000_000;

section('1. Qué hacer con un servicio según su estado');
$d = G::serviceDecision('active', [], $now);
ok('activo: no se hace nada', !$d['restart'] && !$d['alert'] && !$d['recovered']);
$d = G::serviceDecision('active', ['since' => $now - 900, 'alerted' => $now - 100], $now);
ok('activo tras un aviso: se marca como recuperado', $d['recovered'] === true);
$d = G::serviceDecision('failed', [], $now);
ok('recién fallido: apunta la hora y espera', !$d['restart'] && !$d['alert'] && $d['since'] === $now);
$d = G::serviceDecision('failed', ['since' => $now - 130], $now);
ok('fallido 130 s: se reintenta', $d['restart'] === true);
$d = G::serviceDecision('failed', ['since' => $now - 130, 'tries' => 3, 'last_try' => $now - 1000], $now);
ok('ya reintentado 3 veces: no insiste', $d['restart'] === false);
$d = G::serviceDecision('failed', ['since' => $now - 400, 'tries' => 1, 'last_try' => $now - 100], $now);
ok('reintento hace 100 s: espera a los 5 min', $d['restart'] === false);
$d = G::serviceDecision('failed', ['since' => $now - 700, 'tries' => 3, 'last_try' => $now - 400], $now);
ok('fallido 11 min: avisa', $d['alert'] === true);
$d = G::serviceDecision('failed', ['since' => $now - 700, 'tries' => 3, 'alerted' => $now - 3600], $now);
ok('ya avisado hace 1 h: no repite (cada 6 h)', $d['alert'] === false);
$d = G::serviceDecision('failed', ['since' => $now - 700, 'alerted' => $now - 7 * 3600], $now);
ok('avisado hace 7 h: vuelve a avisar', $d['alert'] === true);
$d = G::serviceDecision('inactive', ['since' => $now - 5000], $now);
ok('parado a mano (inactive): NUNCA se arranca solo', $d['restart'] === false);
ok('parado a mano 83 min: sí se avisa', $d['alert'] === true);
$d = G::serviceDecision('inactive', ['since' => $now - 300], $now);
ok('parado 5 min: aún no se avisa (puede ser mantenimiento)', $d['alert'] === false);
foreach (['activating', 'deactivating', 'reloading'] as $s) {
    $d = G::serviceDecision($s, ['since' => $now - 9999], $now);
    ok("{$s}: se deja en paz", !$d['restart'] && !$d['alert']);
}

section('2. Parámetros de PostgreSQL entre dos nodos (caso asterisk/obelix)');
$ast = ['16/main' => ['max_wal_senders' => 5, 'max_connections' => 100]];
$obe = ['16/main' => ['max_wal_senders' => 10, 'max_connections' => 100]];
$m = G::pgParamMismatches($ast, $obe);
ok('detecta max_wal_senders 5 frente a 10', count($m) === 1 && $m[0]['param'] === 'max_wal_senders' && $m[0]['mine'] === 5 && $m[0]['theirs'] === 10, json_encode($m));
ok('iguales: nada que avisar', G::pgParamMismatches($obe, $obe) === []);
ok('un clúster que el otro no tiene se ignora', G::pgParamMismatches(['14/main' => ['max_wal_senders' => 5]], $obe) === []);
ok('parámetro que falta en un lado se ignora', G::pgParamMismatches(['16/main' => ['max_wal_senders' => 5]], ['16/main' => []]) === []);

section('3. Nombres que apuntan a este mismo servidor');
$mine = G::localIps();
ok('hay IPs locales', count($mine) > 0);
ok('una IP propia es este servidor', G::hostIsSelf($mine[0], $mine) === true);
ok('una IP ajena no lo es', G::hostIsSelf('203.0.113.50', $mine) === false);
ok('vacío no lo es', G::hostIsSelf('', $mine) === false);
ok('localhost se resuelve a este servidor', G::hostIsSelf('localhost', $mine) === true);
$e = G::explainNodeError('https://localhost:8444', 'SSL routines::tlsv1 alert internal error');
ok('el error de un nombre que apunta a sí mismo se explica', str_contains($e, 'ESTE servidor') && str_contains($e, 'tlsv1 alert'), $e);
ok('el error de una IP no se toca', G::explainNodeError('https://203.0.113.50:8444', 'timeout') === 'timeout');

section('4. Orden de arranque respecto a WireGuard');
$dc = G::wireguardDropinContent('wg-quick@wg0.service', true);
ok('espera a WireGuard (After + Wants)', str_contains($dc, "After=wg-quick@wg0.service\n") && str_contains($dc, "Wants=wg-quick@wg0.service\n"));
ok('Dovecot reintenta si falla', str_contains($dc, "Restart=on-failure\n") && str_contains($dc, "RestartSec=15\n"));
$dp = G::wireguardDropinContent('wg-quick@wg0.service', false);
ok('PostgreSQL/Postfix: solo orden, sin reintento propio', !str_contains($dp, 'Restart=') && str_contains($dp, 'After=wg-quick@wg0.service'));
ok('Wants y no Requires: sin WireGuard el servicio sigue arrancando', !str_contains($dc, 'Requires='));

section('5. Unidad del guardián de arranque');
$u = G::bootGuardUnitContent('/opt/musedock-panel/bin/boot-guard.php', 'postgresql@14-panel', ['postgresql@14-main', 'postgresql@16-musemind', 'caddy.service', 'dovecot.service'], 'wg-quick@wg0.service');
ok('espera a la red, a WireGuard y a la BD del panel', str_contains($u, 'After=network-online.target wg-quick@wg0.service postgresql@14-panel.service'), $u);
ok('las instancias llevan sufijo .service (systemd lo exige)', str_contains($u, 'postgresql@14-main.service') && str_contains($u, 'postgresql@16-musemind.service') && !preg_match('/postgresql@14-(main|panel)(\s|$)/', $u));
ok('va ANTES de las bases de datos de datos, Caddy y Dovecot', preg_match('/^Before=.*postgresql@14-main\.service.*caddy\.service.*dovecot\.service$/m', $u) === 1);
ok('no se ordena antes de la BD del panel (sería un ciclo)', !preg_match('/^Before=.*postgresql@14-panel/m', $u));
ok('falla siempre abierto: ExecStart con «-» y tiempo máximo', str_contains($u, 'ExecStart=-/usr/bin/php /opt/musedock-panel/bin/boot-guard.php') && str_contains($u, 'TimeoutStartSec=120'));
ok('sin WireGuard no añade dependencias vacías', !str_contains(G::bootGuardUnitContent('/x.php', '', ['caddy.service'], ''), 'wg-quick'));

use MuseDockPanel\Services\FailoverSafetyService as F;
use MuseDockPanel\Services\ConfigMirrorService as M;

section('6. Aislar a otro nodo y promover (caso 2026-10-18)');
ok('dice «estoy apartado» → confirmado aunque su panel siga (rescate) y su BBDD en solo lectura', F::fenceConfirmed(['cluster_fenced' => true], true, false, true) === true);
ok('no responde por ninguna parte → confirmado (cayó)', F::fenceConfirmed(null, false, false, false) === true);
ok('responde y NO está apartado → no confirmado', F::fenceConfirmed(['cluster_fenced' => false], true, true, true) === false);
ok('sin respuesta del estado pero la web sigue sirviendo → no confirmado', F::fenceConfirmed(null, true, true, true) === false);
ok('sin estado, panel caído pero su BBDD sigue → no confirmado', F::fenceConfirmed(null, false, false, true) === false);
ok('master sano y sin forzar: se rechaza la promoción', F::promoteBlockReason('10.10.70.154', true, false, false) !== null);
ok('el motivo manda a «Pasar el mando a…» y a --allow-alive', str_contains((string)F::promoteBlockReason('10.10.70.154', true, false, false), 'Pasar el mando a') && str_contains((string)F::promoteBlockReason('10.10.70.154', true, false, false), '--allow-alive'));
ok('master caído: se puede promover', F::promoteBlockReason('10.10.70.154', false, false, false) === null);
ok('master sano pero --allow-alive: se puede', F::promoteBlockReason('10.10.70.154', true, false, true) === null);
ok('master sano pero force (cambio planificado): se puede', F::promoteBlockReason('10.10.70.154', true, true, false) === null);
ok('sin master antiguo configurado: no hay nada que bloquear', F::promoteBlockReason('', true, false, false) === null);

section('7. Servicios en bucle de reinicios y scripts que faltan');
ok('4.500 reinicios en auto-restart: bucle', G::isRestartLoop(4500, 'auto-restart') === true);
ok('5 reinicios: no es un bucle', G::isRestartLoop(5, 'auto-restart') === false);
ok('muchos reinicios pero hoy funciona (running): no avisa', G::isRestartLoop(900, 'running') === false);
$mc = "[Service]\nExecStart=/usr/bin/python3 /usr/local/sbin/script-que-no-existe-xyz\nRestart=always\n";
ok('python existe pero falta el script: se detecta', M::execStartScriptProblems($mc) === ['no existe el script /usr/local/sbin/script-que-no-existe-xyz'], json_encode(M::execStartScriptProblems($mc)));
ok('con opciones del intérprete (-u) también', count(M::execStartScriptProblems("ExecStart=/usr/bin/python3 -u /nada/xyz.py\n")) === 1);
ok('si el script existe, no hay problema', M::execStartScriptProblems("ExecStart=/usr/bin/php " . __FILE__ . "\n") === []);
ok('un programa normal (no intérprete) no se juzga aquí', M::execStartScriptProblems("ExecStart=/usr/sbin/caddy run --config /nada/Caddyfile\n") === []);
ok('python -m modulo (sin ruta) no se juzga', M::execStartScriptProblems("ExecStart=/usr/bin/python3 -m http.server\n") === []);
ok('prefijo «-» de systemd se ignora', count(M::execStartScriptProblems("ExecStart=-/usr/bin/bash /nada/xyz.sh\n")) === 1);

use MuseDockPanel\Services\ClusterService as C;

section('8. Nunca apartar a otro nodo por error (2026-10-18: una prueba apartó a Filemon dos veces)');
putenv('MUSEDOCK_DISABLE_REMOTE_FENCE=1');
$nodes = C::getNodes();
$exactOk = true;
$prefixOk = true;
foreach ($nodes as $n) {
    $h = (string)(parse_url((string)$n['api_url'], PHP_URL_HOST) ?: '');
    if ($h === '') {
        continue;
    }
    $got = C::getNodeByHost($h);
    $exactOk = $exactOk && $got !== null && (int)$got['id'] === (int)$n['id'];
    // «10.10.70.15» (prefijo de 10.10.70.154 y .156) no es ningún nodo: antes un LIKE '%…%' lo habría casado
    $prefixOk = $prefixOk && C::getNodeByHost(substr($h, 0, -1)) === null;
}
ok('cada nodo se encuentra por su host exacto', $exactOk);
ok('un PREFIJO de la dirección de un nodo no casa con ninguno (antes: LIKE %…% casaba .154 y .156 con .1)', $prefixOk);
ok('una dirección sin nodo no casa con nadie', C::getNodeByHost('203.0.113.77') === null);
$mine = G::localIps();
$self = null;
foreach ($mine as $ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '127.')) {
        $self = $ip;
        break;
    }
}
if ($self !== null) {
    $r = F::fenceOldMaster($self, false);
    ok('«aislar al master antiguo» con una IP de ESTE servidor se rechaza sin tocar la red', $r['fenced'] === false && str_contains(strtolower($r['error'] ?? ''), 'este servidor') && str_contains(strtolower($r['error'] ?? ''), 'split-brain'), json_encode($r));
}
$rm = new ReflectionMethod(F::class, 'requestSelfFence');
$rm->setAccessible(true);
$sf = $rm->invoke(null, '10.10.70.154');
ok('con MUSEDOCK_DISABLE_REMOTE_FENCE=1 no se ordena aislar a nadie, ni siquiera a un nodo que existe', $sf['ok'] === false && str_contains($sf['message'], 'desactivado'), json_encode($sf));
$ru = F::requestUnfence('10.10.70.154');
ok('con el interruptor tampoco se ordena reactivar', $ru['ok'] === false && str_contains($ru['message'], 'desactivada'), json_encode($ru));

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
