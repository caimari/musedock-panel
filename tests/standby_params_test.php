#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — Parámetros de réplica PostgreSQL (standalone, sin PHPUnit).
 *
 * Solo lógica y ficheros temporales: no toca /etc ni arranca nada.
 * Caso real: 2026-10-18, asterisk (max_wal_senders 5) no pudo seguir a obelix (10) y el demote
 * se deshizo dos veces. Aquí se comprueba que ahora se calcula, se escribe fuera del directorio
 * de datos y se aprende del log si el primario exige algo más.
 *
 * Usage: php tests/standby_params_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

use MuseDockPanel\Services\ReplicationService as R;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }
function section(string $t) { echo "\n\033[1m{$t}\033[0m\n"; }

section('1. Qué hay que subir');
$c = R::standbyParamChanges(['max_wal_senders' => 10, 'max_connections' => 100], ['max_wal_senders' => 5, 'max_connections' => 100]);
ok('solo sube lo que es menor (max_wal_senders 5 → 10)', $c === ['max_wal_senders' => 10], json_encode($c));
ok('si el local es mayor no baja nada', R::standbyParamChanges(['max_wal_senders' => 10], ['max_wal_senders' => 20]) === []);
ok('si no se conoce el local, se pone el del primario', R::standbyParamChanges(['max_wal_senders' => 10], []) === ['max_wal_senders' => 10]);
ok('ignora parámetros que no son de la lista', R::standbyParamChanges(['shared_buffers' => 999], []) === []);
ok('ignora valores 0', R::standbyParamChanges(['max_prepared_transactions' => 0], ['max_prepared_transactions' => 0]) === []);

section('2. Lo que dice el log de PostgreSQL');
$en = "2026-10-18 FATAL:  recovery aborted because of insufficient parameter settings\n"
    . "2026-10-18 DETAIL:  max_wal_senders = 5 is a lower setting than on the primary server, where its value was 10.\n"
    . "2026-10-18 HINT:  You can restart the server after making the necessary configuration changes.\n";
ok('log en inglés', R::standbyParamsFromLog($en) === ['max_wal_senders' => 10], json_encode(R::standbyParamsFromLog($en)));
$es = "FATAL:  la recuperación abortó por configuración insuficiente\nDETAIL:  max_connections = 100 es un valor más bajo que en el servidor primario, donde su valor era 200.\n";
ok('log en español', R::standbyParamsFromLog($es) === ['max_connections' => 200], json_encode(R::standbyParamsFromLog($es)));
$two = "DETAIL:  max_connections = 100 is a lower setting than on the primary server, where its value was 200.\nDETAIL:  max_worker_processes = 8 is a lower setting than on the primary server, where its value was 16.\n";
ok('varios parámetros a la vez', R::standbyParamsFromLog($two) === ['max_connections' => 200, 'max_worker_processes' => 16]);
ok('un log sin ese error no devuelve nada', R::standbyParamsFromLog("LOG:  database system is ready to accept read-only connections\n") === []);
ok('no se deja engañar por otro max_* que no es de la lista', R::standbyParamsFromLog("max_stack_depth = 2 is a lower setting than on the primary server, where its value was 9") === []);

section('3. Escritura en conf.d (fichero temporal)');
$tmp = sys_get_temp_dir() . '/standby-params-test-' . getmypid();
mkdir("{$tmp}/conf.d", 0755, true);
file_put_contents("{$tmp}/postgresql.conf", "max_wal_senders = 5\n");
$cl = ['key' => '16/main', 'config_file' => "{$tmp}/postgresql.conf"];
ok('escribe el fichero en conf.d', R::writeStandbyParams($cl, ['max_wal_senders' => 10]) === true);
$f = "{$tmp}/conf.d/" . R::STANDBY_PARAMS_FILE;
$txt = (string)@file_get_contents($f);
ok('contiene max_wal_senders = 10', str_contains($txt, "max_wal_senders = 10\n"), $txt);
ok('no toca postgresql.conf', trim((string)file_get_contents("{$tmp}/postgresql.conf")) === 'max_wal_senders = 5');
R::writeStandbyParams($cl, ['max_wal_senders' => 7, 'max_connections' => 150]);
$txt = (string)file_get_contents($f);
ok('no baja un valor ya escrito (10 se queda aunque llegue 7)', str_contains($txt, "max_wal_senders = 10\n"));
ok('suma parámetros nuevos', str_contains($txt, "max_connections = 150\n"));
ok('sin cambios no escribe', R::writeStandbyParams($cl, []) === false);
// Sin conf.d: lo pone en postgresql.conf
$tmp2 = sys_get_temp_dir() . '/standby-params-test2-' . getmypid();
mkdir($tmp2, 0755, true);
file_put_contents("{$tmp2}/postgresql.conf", "#max_wal_senders = 10\nport = 5432\n");
ok('sin conf.d usa postgresql.conf', R::writeStandbyParams(['key' => 'x', 'config_file' => "{$tmp2}/postgresql.conf"], ['max_wal_senders' => 12]) === true
    && str_contains((string)file_get_contents("{$tmp2}/postgresql.conf"), 'max_wal_senders = 12'));
// Limpieza
foreach ([$f, "{$tmp}/conf.d", "{$tmp}/postgresql.conf", $tmp, "{$tmp2}/postgresql.conf", $tmp2] as $p) {
    is_dir($p) ? @rmdir($p) : @unlink($p);
}

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
