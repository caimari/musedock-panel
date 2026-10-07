<?php
/**
 * Reparación automática de réplicas rotas (ReplicationAutoRepairService), fuera del
 * cluster-worker porque una copia completa puede tardar. Lo lanza el worker; también se
 * puede ejecutar a mano como root:
 *
 *   php bin/replication-auto-repair.php            repara lo que toca ahora
 *   php bin/replication-auto-repair.php --check    solo dice qué repararía
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\ReplicationAutoRepairService as R;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$stamp = static fn() => '[' . date('Y-m-d H:i:s') . '] ';

if (in_array('--check', $argv, true)) {
    $due = R::due();
    echo $due ? "Repararía:\n  " . implode("\n  ", array_map(static fn($k, $v) => "{$k}: {$v}", array_keys($due), $due)) . "\n" : "Nada que reparar.\n";
    exit(0);
}

$fp = fopen(R::LOCK, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    echo $stamp() . "Otra reparación en marcha: no se hace nada.\n";
    exit(0);
}
foreach (R::repairDue() as $component => $result) {
    echo $stamp() . "{$component}: {$result}\n";
}
flock($fp, LOCK_UN);
fclose($fp);
