#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — pg_rewind (rewindPgClusterFrom) tests.
 *
 * READ-ONLY / logic-only: only exercises guards and the dry-run planner. Never
 * stops a cluster, never runs pg_rewind, never writes to a data dir.
 *
 * Usage: php tests/pg_rewind_test.php
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

// A realistic panel-cluster descriptor (mirrors PgClusterService::get output).
$panelCluster = [
    'version' => '14', 'cluster' => 'panel', 'port' => 5433,
    'data_dir' => '/var/lib/postgresql/14/panel',
    'config_file' => '/etc/postgresql/14/panel/postgresql.conf',
    'hba_file' => '/etc/postgresql/14/panel/pg_hba.conf',
    'unit' => 'postgresql@14-panel', 'key' => '14/panel',
];

section('1. Guards de seguridad (rechazan antes de tocar nada)');

$r = R::rewindPgClusterFrom(['version'=>'14','cluster'=>'panel','port'=>5433,'unit'=>'x'], '10.10.70.154', 5433, 'repl', 'p');
ok('rechaza descriptor sin data_dir', ($r['ok'] === false) && str_contains($r['error'], "falta 'data_dir'"));

$bad = $panelCluster; $bad['data_dir'] = '/';
$r = R::rewindPgClusterFrom($bad, '10.10.70.154', 5433, 'repl', 'p');
ok('rechaza data_dir = /', $r['ok'] === false && str_contains($r['error'], 'no es un directorio'));

$bad = $panelCluster; $bad['data_dir'] = '/etc/passwd';
$r = R::rewindPgClusterFrom($bad, '10.10.70.154', 5433, 'repl', 'p');
ok('rechaza data_dir fuera de /var/lib/postgresql', $r['ok'] === false && str_contains($r['error'], 'no es un directorio'));

$r = R::rewindPgClusterFrom($panelCluster, 'no-es-ip', 5433, 'repl', 'p');
ok('rechaza IP de nuevo master inválida', $r['ok'] === false && str_contains($r['error'], 'IP del nuevo master'));

section('2. Dry-run: el plan describe pg_rewind correctamente');
$r = R::rewindPgClusterFrom($panelCluster, '10.10.70.154', 5433, 'repl_panel', 'secret', /*dryRun*/ true);
ok('dry-run ok + no ejecuta', ($r['ok'] === true) && ($r['dry_run'] ?? false) === true);
$planTxt = implode("\n", $r['plan'] ?? []);
ok('verifica wal_log_hints/checksums antes', str_contains($planTxt, 'wal_log_hints') && str_contains($planTxt, 'checksums'));
ok('verifica que el origen es PRIMARY', str_contains($planTxt, 'PRIMARY') || str_contains($planTxt, 'in-recovery'));
ok('parada limpia antes del rewind', str_contains($planTxt, 'stop') && str_contains($planTxt, 'requisito'));
ok('hace copia de seguridad completa (no borra)', str_contains($planTxt, 'NO se borra') || str_contains($planTxt, 'pre-rewind'));
ok('usa --write-recovery-conf', str_contains($planTxt, '--write-recovery-conf'));
ok('primary_conninfo apunta al nuevo master', str_contains($planTxt, '10.10.70.154'));
ok('menciona rollback', str_contains($planTxt, 'rollback'));

section('3. El código fuente respeta el modelo de seguridad');
$src = file_get_contents(PANEL_ROOT . '/app/Services/ReplicationService.php');
ok('password vía .pgpass, no en argv', str_contains($src, 'writeTempPgpass') && str_contains($src, 'PGPASSFILE'));
ok('bloquea si wal_log_hints=off y sin checksums', str_contains($src, "wal_log_hints=off y sin data checksums") || str_contains($src, "wal_log_hints (G2)"));
ok('rechaza rewind desde un standby', str_contains($src, 'no es PRIMARY') && str_contains($src, 'pg_is_in_recovery'));
ok('restaura desde la copia si el rewind falla', str_contains($src, "mv ' . escapeshellarg(\$asideDir)") || str_contains($src, '$asideDir'));
ok('confirma que arranca in-recovery (standby, no primary)', str_contains($src, 'pg_is_in_recovery') && str_contains($src, 'REVISAR'));

section('3b. Regresión: bugs de la review adversarial');
ok('#3 .pgpass legible por postgres (chown/chgrp)',
    str_contains($src, 'writeTempPgpassForPostgres') && str_contains($src, "chown(\$file, 'postgres')"));
ok('#3 pg_rewind usa el .pgpass de postgres, no el del panel',
    str_contains($src, 'writeTempPgpassForPostgres($newMasterIp')
    && preg_match('/sudo -u postgres env PGPASSFILE=.*pg_rewind/s', $src) === 0 || str_contains($src, 'env PGPASSFILE='));
ok('#3 sin PGPASSFILE redundante antes de sudo (saneado por sudo)',
    !str_contains($src, "'PGPASSFILE=' . escapeshellarg(\$pgpass) . ' sudo"));
ok('Guard1 usa pg_controldata (funciona con cluster parado)',
    str_contains($src, 'function pgControlData') && str_contains($src, 'pg_controldata'));
ok('Guard1 bloquea si el prerrequisito es indeterminado (fail-safe)',
    str_contains($src, 'no se pudo determinar') && str_contains($src, '!$hintsOn && !$sumsOn'));
ok('#4 primary_conninfo de fallback incluye password',
    str_contains($src, 'password=') && str_contains($src, '$sqlPass'));
ok('#4 lee auto.conf como postgres (evita dedup roto)',
    str_contains($src, 'sudo -u postgres cat ' ));
ok('#2 copia con --reflink=auto (CoW, menos downtime)',
    str_contains($src, 'cp -a --reflink=auto'));
ok('sleep fijo sustituido por polling con reintentos',
    str_contains($src, 'for ($i = 0; $i < 10') && str_contains($src, 'isRunning'));

section('4. planRebuildAsSlave ofrece pg_rewind con fallback');
$plan = \MuseDockPanel\Services\FailoverSafetyService::planRebuildAsSlave('10.10.70.154');
ok('devuelve mapa de métodos por cluster', isset($plan['methods']) && is_array($plan['methods']));
ok('cada método es pg_rewind o pg_basebackup', empty($plan['methods']) || !array_diff($plan['methods'], ['pg_rewind','pg_basebackup']));

echo "\n\033[1m─────────────────────────────────────────\033[0m\n";
echo "  \033[0;32m{$pass} passed\033[0m" . ($fail ? ", \033[0;31m{$fail} failed\033[0m" : '') . "\n\n";
exit($fail > 0 ? 1 : 0);
