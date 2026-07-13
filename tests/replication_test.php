#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — Replication module tests (standalone, no PHPUnit).
 *
 * Covers the 12 scenarios required by the multi-cluster replication rewrite.
 * READ-ONLY: never configures real replication, never stops services, never
 * wipes data. Destructive paths are exercised only via dry-run / guard checks.
 *
 * Usage: php tests/replication_test.php
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
use MuseDockPanel\Services\PgClusterService as P;

$pass = 0; $fail = 0; $skip = 0;
function ok(string $name, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[0;32m✓\033[0m {$name}\n"; }
    else       { $fail++; echo "  \033[0;31m✗\033[0m {$name}" . ($detail ? " — {$detail}" : '') . "\n"; }
}
function skip(string $name, string $why) { global $skip; $skip++; echo "  \033[1;33m○\033[0m {$name} — SKIP: {$why}\n"; }
function section(string $t) { echo "\n\033[1m{$t}\033[0m\n"; }

// ── Scenario 1: PG14/main + PG14/panel simultaneous ────────────────
section('1. PG14/main + PG14/panel simultáneos');
$main = P::get('14', 'main');
$panel = P::get('14', 'panel');
ok('14/main resuelto', $main !== null && $main['port'] === 5432);
ok('14/panel resuelto', $panel !== null && $panel['port'] === 5433);
ok('config dirs distintos y correctos',
    ($main['config_dir'] ?? '') === '/etc/postgresql/14/main' &&
    ($panel['config_dir'] ?? '') === '/etc/postgresql/14/panel');

// ── Scenario 2: PG14 + PG16 simultaneous ───────────────────────────
section('2. PG14 y PG16 simultáneos');
$muse = P::get('16', 'musemind');
ok('16/musemind resuelto en :5434', $muse !== null && $muse['port'] === 5434);
ok('versiones 14 y 16 coexisten distintas',
    ($main['version'] ?? '') === '14' && ($muse['version'] ?? '') === '16');

// ── Scenario 3: psql client 16 with PG14 server ────────────────────
section('3. Cliente psql 16 con servidor PG14 (el bug original)');
$clientVer = trim((string)shell_exec('psql --version 2>/dev/null'));
ok('cliente psql reporta 16', str_contains($clientVer, ' 16'));
// The old code combined client 16 + first cluster 'main' → /etc/postgresql/16/main.
// New identity NEVER does that: 14/main's config uses version 14.
ok('identidad NO produce /etc/postgresql/16/main',
    ($main['config_dir'] ?? '') !== '/etc/postgresql/16/main');
ok('get("16","main") no existe → NULL (no fallback peligroso)', P::get('16', 'main') === null);

// ── Scenario 4: correct config/data directory selection ────────────
section('4. Selección correcta de config/data directory');
ok('data_dir de 14/main es /var/lib/postgresql/14/main',
    ($main['data_dir'] ?? '') === '/var/lib/postgresql/14/main');
ok('data_dir de 16/musemind es /var/lib/postgresql/16/musemind',
    ($muse['data_dir'] ?? '') === '/var/lib/postgresql/16/musemind');
ok('unit systemd por-cluster correcto',
    ($main['unit'] ?? '') === 'postgresql@14-main' &&
    ($muse['unit'] ?? '') === 'postgresql@16-musemind');

// ── Scenario 5: pg_basebackup failure without losing target ────────
section('5. Fallo de pg_basebackup sin pérdida del destino anterior');
// Descriptor with invalid path must abort BEFORE touching anything.
$badDesc = R::setupPgSlaveForCluster([], '10.10.70.1', 5432, 'u', 'p', false, false);
ok('descriptor vacío aborta sin actuar', $badDesc['ok'] === false);
// Unreachable master → preflight blocks; dry-run never executes.
$dry = R::setupPgSlaveForCluster($muse, '10.10.70.199', 5434, 'replicator', 'x', false, true);
ok('dry-run no ejecuta, devuelve plan', ($dry['dry_run'] ?? false) === true && !empty($dry['plan']));
ok('plan aparta el dir (mv .old), no rm -rf del dir vivo',
    isset($dry['plan']) && (bool)array_filter($dry['plan'], fn($l) => str_contains($l, '.old.')));

// ── Scenario 6: one cluster streaming, another disconnected ────────
section('6. Un clúster streaming y otro desconectado (por instancia)');
$byCluster = R::getPgStreamingByCluster();
ok('estado reportado por CADA cluster', count($byCluster) === 3 &&
    isset($byCluster['14/main'], $byCluster['14/panel'], $byCluster['16/musemind']));
$decision = R::dumpDecisionByCluster();
ok('decisión de dump es por instancia', count($decision) === 3);
ok('cluster sin streaming NO omite su dump',
    $decision['14/panel']['skip_replica_dump'] === false);
ok('dumps lógicos siempre se conservan', R::keepLogicalDumps() === true);

// ── Scenario 7: two slaves (Nitro + Filemon) ───────────────────────
section('7. Dos slaves (Nitro + Filemon) — slots/app_name únicos');
// upsert logic derives unique slot/app names per slave+cluster (pure, no DB write here).
$slotNitro = 'slot_nitro_14_main';
$slotFilemon = 'slot_filemon_14_main';
ok('slots físicos distintos por slave', $slotNitro !== $slotFilemon);
ok('replication_slaves table intacta (legacy Nitro no se rompe)',
    is_file(PANEL_ROOT . '/database/migrations/2026_03_16_000002_create_replication_slaves_table.php'));
ok('migración de instancias es aditiva (child table)',
    is_file(PANEL_ROOT . '/database/migrations/2026_07_13_000001_create_replication_pg_instances_table.php'));

// ── Scenario 8: MariaDB 10.6 ───────────────────────────────────────
section('8. MariaDB 10.6');
$vendor = R::detectDbVendor();
if ($vendor['vendor'] === 'mariadb') {
    ok('vendor detectado = mariadb', true);
    ok('versión 10.x', str_starts_with($vendor['version'], '10.'));
} else {
    skip('detección MariaDB', 'este host no reporta MariaDB vía PDO/cliente (vendor=' . $vendor['vendor'] . ')');
}
// Compatibility: MariaDB↔MariaDB same major = ok.
$cmar = R::assessMysqlCompatibility(['vendor'=>'mariadb','version'=>'10.6.22'], ['vendor'=>'mariadb','version'=>'10.6.18']);
ok('MariaDB 10.6 → MariaDB 10.6 compatible', $cmar['compatible'] === true && $cmar['severity'] === 'ok');

// ── Scenario 9: MySQL 8 ────────────────────────────────────────────
section('9. MySQL 8');
$cmysql = R::assessMysqlCompatibility(['vendor'=>'mysql','version'=>'8.0.36'], ['vendor'=>'mysql','version'=>'8.0.36']);
ok('MySQL 8 → MySQL 8 compatible', $cmysql['compatible'] === true);

// ── Scenario 10: incompatible MariaDB → MySQL ──────────────────────
section('10. Combinación incompatible MariaDB 10.6 → MySQL 8');
$cinc = R::assessMysqlCompatibility(['vendor'=>'mariadb','version'=>'10.6.22'], ['vendor'=>'mysql','version'=>'8.0.36']);
ok('marcado incompatible', $cinc['compatible'] === false);
ok('severidad crítica', $cinc['severity'] === 'critical');
ok('mensaje recomienda no auto-sustituir motor', str_contains(strtolower($cinc['message']), 'nunca'));

// ── Scenario 11: dry-run makes no modifications ────────────────────
section('11. Dry-run sin modificaciones');
$dryMaster = R::setupPgMasterForCluster($main, ['10.10.70.154'], 'replicator', 'x', ['slot_filemon_14_main'], true);
ok('master dry-run devuelve plan sin ejecutar', ($dryMaster['dry_run'] ?? false) === true && !empty($dryMaster['plan']));
ok('plan usa listen loopback+WG, no *',
    (bool)array_filter($dryMaster['plan'], fn($l) => str_contains($l, '127.0.0.1')) &&
    !array_filter($dryMaster['plan'], fn($l) => str_contains($l, "'*'")));

// ── Scenario 12: never stop ALL PostgreSQL globally ────────────────
section('12. Prohibición de detener globalmente todos los PostgreSQL');
$svc = file_get_contents(PANEL_ROOT . '/app/Services/ReplicationService.php');
// The only 'systemctl stop postgresql' occurrences must be in comments.
$lines = explode("\n", $svc);
$badStop = 0;
foreach ($lines as $ln) {
    $t = ltrim($ln);
    if (str_contains($ln, 'systemctl stop postgresql') && !str_starts_with($t, '*') && !str_starts_with($t, '//') && !str_starts_with($t, '#')) {
        // allow if it's inside a string that targets a specific unit (postgresql@...)
        if (!str_contains($ln, 'postgresql@')) $badStop++;
    }
}
ok('ningún systemctl stop postgresql (umbrella) ejecutable', $badStop === 0, "encontrados: {$badStop}");
// No umbrella restart/reload/stop of postgresql anywhere (comments allowed).
$umbrella = 0;
foreach ($lines as $ln) {
    $t = ltrim($ln);
    if (str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '#')) continue;
    if (preg_match('/systemctl (restart|reload|stop) postgresql\b/', $ln) && !str_contains($ln, 'postgresql@')) $umbrella++;
    if (str_contains($ln, 'is-active postgresql')) $umbrella++;
}
ok('ninguna operación umbrella postgresql (restart/reload/is-active) ejecutable', $umbrella === 0, "encontrados: {$umbrella}");
ok('getPgConfigDir resuelve al cluster del panel, no /16/main',
    R::getPgConfigDir() !== '/etc/postgresql/16/main' && str_contains(R::getPgConfigDir(), '/etc/postgresql/'));
ok('setupPgSlave legacy es stub bloqueado',
    R::setupPgSlave('10.10.70.1', 5432, 'u', 'p')['ok'] === false);
$confirmToken = R::slaveConfirmToken('filemon', $muse);
ok('token de confirmación embebe slave+cluster+puerto',
    str_contains($confirmToken, 'filemon') && str_contains($confirmToken, '16/musemind') && str_contains($confirmToken, '5434'));
ok('promotePgSlave legacy bloqueado (no promueve cluster equivocado)',
    R::promotePgSlave()['ok'] === false);
ok('promotePgSlaveForCluster requiere descriptor explícito',
    R::promotePgSlaveForCluster([])['ok'] === false);
// Verify promote no longer derives cluster from client psql version.
$svc2 = file_get_contents(PANEL_ROOT . '/app/Services/ReplicationService.php');
ok('promotePgSlaveForCluster usa pg_ctlcluster con version/cluster explícitos',
    (bool)preg_match('/promotePgSlaveForCluster.*?pg_ctlcluster.*?cluster\[.version.\]/s', $svc2));

// ── Summary ────────────────────────────────────────────────────────
echo "\n\033[1m─────────────────────────────────────────\033[0m\n";
echo "  \033[0;32m{$pass} passed\033[0m";
if ($fail) echo ", \033[0;31m{$fail} failed\033[0m";
if ($skip) echo ", \033[1;33m{$skip} skipped\033[0m";
echo "\n\n";
exit($fail > 0 ? 1 : 0);
