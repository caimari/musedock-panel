#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — DB replication SAFETY tests (audit fixes C1–C5).
 *
 * READ-ONLY / logic-only: verifies the source guards are present. Does NOT touch
 * any database, replication, or config. Run before activating replication with
 * real client data.
 *
 * Usage: php tests/replication_safety_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }
function section(string $t) { echo "\n\033[1m{$t}\033[0m\n"; }

$repl = file_get_contents(PANEL_ROOT . '/app/Services/ReplicationService.php');
$clu  = file_get_contents(PANEL_ROOT . '/app/Services/ClusterService.php');
$api  = file_get_contents(PANEL_ROOT . '/app/Controllers/ClusterApiController.php');
$wrk  = file_get_contents(PANEL_ROOT . '/bin/failover-worker.php');

section('C1 — MariaDB slave SIEMBRA los datos y usa sintaxis MariaDB');
ok('setupMysqlSlave tiene parámetro de siembra ($seed)', str_contains($repl, 'setupMysqlSlave(string $masterIp, int $port, string $replUser, string $replPass, bool $seed = true)'));
ok('existe seedMysqlSlaveFromMaster (dump+import desde el master)', str_contains($repl, 'function seedMysqlSlaveFromMaster'));
// Review adversarial: --master-data=1 (ejecuta la coord), NO =2 (comentada).
ok('siembra con --master-data=1 (ejecuta la posición, no la comenta)', str_contains($repl, '--master-data=1') && !str_contains($repl, '--master-data=2'));
ok('MariaDB usa MASTER_USE_GTID=slave_pos (no MASTER_AUTO_POSITION)', str_contains($repl, 'MASTER_USE_GTID=slave_pos'));
ok('detecta vendor real (detectDbVendor) para ramificar la sintaxis', str_contains($repl, "detectDbVendor()") && str_contains($repl, "=== 'mariadb'"));
ok('si la siembra falla, NO inicia la replicación', str_contains($repl, 'NO se inició la replicación'));
ok('credenciales del dump por defaults-file (no en argv)', str_contains($repl, '--defaults-extra-file='));
ok('el dump (PII de todos los clientes) se borra SIEMPRE (finally)', str_contains($repl, '} finally {') && str_contains($repl, '@unlink($dumpFile)'));
ok('import con read_only=0 + sql_log_bin=0 (permite escribir la siembra)', str_contains($repl, 'SET GLOBAL read_only=0') && str_contains($repl, 'sql_log_bin=0'));
ok('re-asienta read_only=1 tras la siembra (slave no acepta escrituras)', str_contains($repl, 'SET GLOBAL read_only=1'));
ok('password del defaults-file escapa backslash y comillas', str_contains($repl, "str_replace(['\\\\', '\"'], ['\\\\\\\\', '\\\\\"']"));

section('C2 — demote MariaDB fuerza rebuild completo (no CHANGE MASTER sobre datos divergentes)');
ok('demoteToSlave llama a setupMysqlSlave con seed=true explícito', str_contains($clu, 'setupMysqlSlave($newMasterIp, $mysqlPort, $mysqlUser, $mysqlPass, true)'));
ok('documentado el porqué (no hay pg_rewind en MySQL)', str_contains($clu, 'no pg_rewind') || str_contains($clu, 'silent corruption') || str_contains($clu, 'divergent'));

section('C4 — guard de lag antes de promocionar');
ok('promotePgSlaveForCluster acepta $maxLagSeconds (default 5)', str_contains($repl, 'promotePgSlaveForCluster(array $cluster, ?int $maxLagSeconds = 5)'));
ok('bloquea la promoción si lag > umbral', str_contains($repl, 'Promoción bloqueada') && str_contains($repl, 'lag'));
ok('permite forzar ($maxLagSeconds === null) y lo registra', str_contains($repl, '$maxLagSeconds === null') || (str_contains($repl, 'FORZADO') && str_contains($repl, 'promote-forced')));

section('C5 — API promote/demote endurecida');
ok('valida que new_master_ip sea un nodo registrado (isKnownNodeIp)', str_contains($api, 'isKnownNodeIp') && str_contains($clu, 'function isKnownNodeIp'));
ok('rechaza demote a IP no registrada', str_contains($api, 'no corresponde a ningún nodo registrado'));
ok('exige que el llamante sea un nodo del clúster reconocido', str_contains($api, 'llamante no es un nodo del clúster reconocido'));
ok('isKnownNodeIp valida contra IP (no acepta cualquier string)', str_contains($clu, 'FILTER_VALIDATE_IP'));

section('C3 — quorum guard en auto-promote (anti split-brain)');
ok('el default de failover es manual (no auto)', str_contains(file_get_contents(PANEL_ROOT.'/app/Services/FailoverService.php'), "'failover_mode'             => self::MODE_MANUAL"));
ok('antes de auto-promocionar consulta a nodos testigo', str_contains($wrk, 'witness') && str_contains($wrk, 'probe-host'));
ok('si un testigo ve el master VIVO, aborta (posible partición)', str_contains($wrk, 'ABORTED') && str_contains($wrk, 'partición'));
ok('si ningún testigo confirma, aplaza (no promueve a ciegas)', str_contains($wrk, 'DEFERRED') || str_contains($wrk, 'aplazado'));
ok('acción probe-host existe en el dispatcher', str_contains($api, "'probe-host'") && str_contains($api, 'reachable'));
ok('probe-host acotado: solo nodos conocidos + puerto permitido (anti-SSRF)', str_contains($api, 'isKnownNodeIp($ip)') && str_contains($api, 'anti-SSRF') || str_contains($api, 'Anti-SSRF'));
ok('isKnownNodeIp rechaza IPs peligrosas (0.0.0.0/loopback en metadata)', str_contains($clu, "'0.0.0.0', '127.0.0.1'") && str_contains($clu, 'dangerous'));

echo "\n\033[1m─────────────────────────────────────────\033[0m\n";
echo "  \033[0;32m{$pass} passed\033[0m" . ($fail ? ", \033[0;31m{$fail} failed\033[0m" : '') . "\n\n";
exit($fail > 0 ? 1 : 0);
