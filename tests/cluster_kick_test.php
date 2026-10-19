#!/usr/bin/env php
<?php
/**
 * Replicación inmediata: lo que se encola en el master (hostings, usuarios, buzones, alias, configuración del relevo y de la guarda de
 * Cloudflare) se procesa al momento y no en la siguiente vuelta del cron. Sin tocar nodos ni cola.
 * Usage: php tests/cluster_kick_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }

$cs = file_get_contents(PANEL_ROOT . '/app/Services/ClusterService.php');
$kick = file_get_contents(PANEL_ROOT . '/bin/cluster-queue-kick.php');
$worker = file_get_contents(PANEL_ROOT . '/bin/cluster-worker.php');

echo "\n\033[1mSe pide procesar al encolar\033[0m\n";
$enq = substr($cs, strpos($cs, 'public static function enqueue('), 900);
ok('enqueue() pide el procesado inmediato (también cuando solo actualiza una operación ya pendiente)', str_contains($enq, 'self::kickQueue();'));
$k = substr($cs, strpos($cs, 'private static function kickQueue()'), 1500);
ok('una sola vez por petición (no una por cada operación encolada)', str_contains($k, 'self::$kickRegistered') && str_contains($k, 'register_shutdown_function'));
ok('solo en el master y sin apartar', str_contains($k, "Settings::get('cluster_role', '') !== 'master'") && str_contains($k, "'cluster_fenced', '0') === '1'"));
ok('en segundo plano y sin esperar (nohup … &)', str_contains($k, 'nohup /usr/bin/php') && str_contains($k, '>/dev/null 2>&1 &'));
ok('lo que encola el propio cron o el procesador no lanza otro', str_contains($k, "defined('MUSEDOCK_QUEUE_WORKER')") && str_contains($worker, "define('MUSEDOCK_QUEUE_WORKER', true)") && str_contains($kick, "define('MUSEDOCK_QUEUE_WORKER', true)"));

echo "\n\033[1mEl procesador inmediato es seguro\033[0m\n";
ok('usa el mismo cerrojo que el cron (nunca dos procesando la cola a la vez)', str_contains($kick, 'storage/cluster-worker.lock') && str_contains($kick, 'LOCK_EX | LOCK_NB'));
ok('si el cron está procesando, no hace nada', str_contains($kick, 'exit(0);    // el cron del clúster está procesando'));
ok('solo actúa en el master sin apartar', str_contains($kick, "Settings::get('cluster_role', '') !== 'master' || Settings::get('cluster_fenced', '0') === '1'"));
ok('hace el mismo paso que el cron: ClusterService::processQueue()', str_contains($kick, 'ClusterService::processQueue()'));
exec('php ' . escapeshellarg(PANEL_ROOT . '/bin/cluster-queue-kick.php') . ' 2>&1', $out, $rc);
ok('ejecutado de verdad en este nodo (no es master o no hay nada): termina bien y sin ruido', $rc === 0 && $out === [], implode(' | ', $out));

echo "\n\033[1mQué viaja por la cola (y por tanto llega al momento)\033[0m\n";
$m = file_get_contents(PANEL_ROOT . '/app/Services/MailService.php');
$f = file_get_contents(PANEL_ROOT . '/app/Services/FailoverService.php');
ok('hostings y usuarios del sistema (sync-hosting)', str_contains($cs, "'sync-hosting'"));
ok('buzones, alias y dominios de correo (mail_*)', str_contains($m, 'replicateMailOp(') && str_contains($cs, "str_starts_with(\$action, 'mail_')"));
ok('configuración del relevo y de la guarda de Cloudflare (sync-failover-config)', str_contains($f, 'sync-failover-config'));
ok('al guardar la guarda de Cloudflare o cambiar sus nombres extra se envía a las réplicas', str_contains(file_get_contents(PANEL_ROOT . '/app/Services/CfGuardService.php'), 'FailoverService::pushConfigToSlaves()'));

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
