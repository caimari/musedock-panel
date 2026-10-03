<?php
/**
 * Arregla réplicas de PostgreSQL que no pueden conectar con su master por
 * "fe_sendauth: no password supplied": su primary_conninfo apunta a un .pgpass
 * temporal (mdpgpass_pg_*) que ya no existe (lo dejaba así pg_rewind hasta la
 * 1.0.276). Pone la contraseña de réplica en /var/lib/postgresql/.pgpass y apunta
 * ahí; recarga el cluster. No toca datos. Idempotente.
 *
 * Uso (como root): php bin/pg-replica-passfile.php
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\PgClusterService;
use MuseDockPanel\Services\ReplicationService;
use MuseDockPanel\Settings;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$pass = ReplicationService::decryptPassword(Settings::get('repl_pg_password', Settings::get('repl_pg_pass', '')));
if ($pass === '') {
    fwrite(STDERR, "No hay contraseña de réplica guardada en el panel (repl_pg_password).\n");
    exit(1);
}
$done = 0;
foreach (PgClusterService::listClusters() as $c) {
    $dir = (string)($c['data_dir'] ?? "/var/lib/postgresql/{$c['version']}/{$c['cluster']}");
    if (!is_file("{$dir}/standby.signal")) {
        echo "{$c['key']}: no es réplica, se deja\n";
        continue;
    }
    $auto = (string)@file_get_contents("{$dir}/postgresql.auto.conf");
    if (!preg_match_all("/primary_conninfo\\s*=\\s*'([^\\n]*)'/", $auto, $mm)) {
        echo "{$c['key']}: sin primary_conninfo\n";
        continue;
    }
    $ci = end($mm[1]);   // la última línea es la que vale
    preg_match('/\bhost=(\S+)/', $ci, $h);
    preg_match('/\bport=(\d+)/', $ci, $p);
    preg_match('/\buser=(\S+)/', $ci, $u);
    if (empty($h[1]) || empty($u[1])) {
        echo "{$c['key']}: no se entiende primary_conninfo\n";
        continue;
    }
    ReplicationService::ensurePostgresPgpass($h[1], (int)($p[1] ?? $c['port']), $u[1], $pass);
    $fixed = ReplicationService::pointPassfileToPermanent("{$dir}/postgresql.auto.conf");
    shell_exec('pg_ctlcluster ' . escapeshellarg((string)$c['version']) . ' ' . escapeshellarg((string)$c['cluster']) . ' reload 2>&1');
    echo "{$c['key']}: contraseña de réplica para {$h[1]}:" . ($p[1] ?? $c['port']) . ' en ' . ReplicationService::POSTGRES_PGPASS
        . ($fixed ? '; passfile corregido' : '; passfile ya estaba bien') . "; recargado\n";
    $done++;
}
echo $done ? "Hecho. En unos segundos debería conectar (mira pg_lsclusters: online,recovery).\n" : "Nada que arreglar.\n";
