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

section('Incidente — botón master legacy bloqueado + flujo por-cluster en UI');
$rc = file_get_contents(PANEL_ROOT . '/app/Controllers/ReplicationController.php');
ok('activatePgMaster legacy bloqueado (ya no reinicia el panel)', str_contains($repl, 'Bloqueado: usaba el clúster del panel'));
ok('nuevo setupMasterCluster por-cluster en el controller', str_contains($rc, 'function setupMasterCluster'));
ok('valida slaves contra nodos registrados (isKnownNodeIp)', str_contains($rc, 'ClusterService::isKnownNodeIp($ip)'));
ok('confirmación literal + dry-run en el master por-cluster', str_contains($rc, "'MASTER CLUSTER:'") && str_contains($rc, "\$dryRun"));
ok('setupPgMasterForCluster limita listen a loopback+WG (no *)', str_contains($repl, '$listen = "\'127.0.0.1" . ($wgIp'));
ok('ruta setup-master-cluster registrada', str_contains(file_get_contents(PANEL_ROOT.'/public/index.php'), 'setup-master-cluster'));

section('BUG real: standalone redirects NO se replicaban — arreglado');
$dctrl = file_get_contents(PANEL_ROOT . '/app/Controllers/DomainController.php');
$cctrl = file_get_contents(PANEL_ROOT . '/app/Controllers/ClusterController.php');
$clu2  = file_get_contents(PANEL_ROOT . '/app/Services/ClusterService.php');
ok('crear standalone redirect replica al cluster', str_contains($dctrl, "syncStandaloneRedirectToCluster('sync'"));
ok('borrar standalone redirect replica el borrado', str_contains($dctrl, "syncStandaloneRedirectToCluster('remove'"));
ok('slave: handler sync_standalone_redirect (crea ruta Caddy + upsert)', str_contains($clu2, "case 'sync_standalone_redirect':") && str_contains($clu2, 'addCaddyRedirectRoute'));
ok('slave: handler remove_standalone_redirect', str_contains($clu2, "case 'remove_standalone_redirect':"));
ok('Sincronizar Todo ahora incluye los standalone', str_contains($cctrl, "hosting_account_id IS NULL AND type = 'redirect'"));
ok('Sincronizar Todo limpia items muertos (banner se aclara)', str_contains($cctrl, "status = 'cancelled'") && str_contains($cctrl, 'attempts >= max_attempts'));
ok('Settings importado en DomainController (no fatal en runtime)', str_contains($dctrl, 'use MuseDockPanel\Settings;'));
// El OTRO botón: "Sincronización Completa" (fullsync-run.php) también debe incluir todo.
$fs = file_get_contents(PANEL_ROOT . '/bin/fullsync-run.php');
ok('fullsync (Sincronización Completa) sincroniza aliases/redirects attached', str_contains($fs, "'sync_domain_aliases'") && str_contains($fs, 'exportForSync'));
ok('fullsync incluye standalone redirects', str_contains($fs, "'sync_standalone_redirect'") && str_contains($fs, "hosting_account_id IS NULL AND type = 'redirect'"));
ok('fullsync limpia items muertos', str_contains($fs, "status = 'cancelled'"));

section('BUG latente: suspend/activate_hosting en slave (Too few arguments)');
ok('suspend_hosting pasa fpmSocket + maneja void (try/catch)', str_contains($clu2, "SystemService::suspendAccount(\$username, \$sock") && str_contains($clu2, 'suspend failed'));
ok('activate_hosting pasa fpmSocket + maneja void', str_contains($clu2, "SystemService::activateAccount(\$username, \$sock"));
ok('ya NO lee $result[success] sobre un void', !str_contains($clu2, "suspendAccount(\$username);"));

section('Aviso de desincronización en el dashboard (drift silencioso)');
$cs = file_get_contents(PANEL_ROOT . '/app/Services/ClusterService.php');
$dc = file_get_contents(PANEL_ROOT . '/app/Controllers/DashboardController.php');
$dv = file_get_contents(PANEL_ROOT . '/resources/views/dashboard/index.php');
ok('getSyncDriftSummary cuenta items muertos (attempts>=max)', str_contains($cs, 'function getSyncDriftSummary') && str_contains($cs, 'attempts >= q.max_attempts'));
ok('el dashboard recibe syncDrift (solo en master)', str_contains($dc, "'syncDrift' =>") && str_contains($dc, 'getSyncDriftSummary'));
ok('banner de aviso en la vista con enlace a Sincronizar Todo', str_contains($dv, "syncDrift['has_drift']") && str_contains($dv, 'Sincronizar Todo'));
ok('el banner explica que NO se cura solo', str_contains($dv, 'no se reintentan solas') || str_contains($dv, 'No se curan solas'));
ok('fecha en formato español dd/mm/aaaa HH:MM', str_contains($dv, "date('d/m/Y H:i'"));
ok('muestra "hace X días"', str_contains($dv, 'haceDias') && str_contains($dv, 'día'));

section('UX fullsync: falso "Error" en hostings grandes + robustez rsync');
$fss = file_get_contents(PANEL_ROOT . '/app/Services/FileSyncService.php');
$fsr = file_get_contents(PANEL_ROOT . '/bin/fullsync-run.php');
ok('rsync tiene --timeout (no se cuelga para siempre si se corta la red)', str_contains($fss, '--timeout=') && str_contains($fss, 'io_timeout'));
ok('rsyncHosting soporta heartbeat vía proc_open', str_contains($fss, "\$options['heartbeat']") && str_contains($fss, 'proc_open'));
ok('fullsync pasa un heartbeat que refresca el progreso', str_contains($fsr, "'heartbeat' => \$heartbeat") && str_contains($fsr, 'transferencia en curso'));

section('BUG CSRF: Igualar módulos / mail-replication mandaban campo mal nombrado');
$clv = file_get_contents(PANEL_ROOT . '/resources/views/settings/cluster.php');
ok('el token se envía como _csrf_token (con underscore, como espera verifyCsrf)', !str_contains($clv, "append('csrf_token'"));
ok('el token se lee del input _csrf_token correcto (no de uno inexistente)', !str_contains($clv, 'input[name="csrf_token"]'));
ok('verifyCsrf lee _csrf_token', str_contains(file_get_contents(PANEL_ROOT.'/app/View.php'), "\$_POST['_csrf_token']"));

section('Compilación de módulos Caddy: async (FPM mata a 120s) + modales bonitos');
$sys = file_get_contents(PANEL_ROOT . '/app/Services/SystemService.php');
$cbs = file_get_contents(PANEL_ROOT . '/app/Services/CaddyBinaryService.php');
$clv2 = file_get_contents(PANEL_ROOT . '/resources/views/settings/cluster.php');
ok('build async: startCaddyDnsProviderBuild lanza en background (setsid nohup)', str_contains($sys, 'function startCaddyDnsProviderBuild') && str_contains($sys, 'setsid nohup'));
ok('script CLI de build existe', is_file(PANEL_ROOT . '/bin/caddy-build-run.php'));
ok('nodo responde caddy-install-status', str_contains(file_get_contents(PANEL_ROOT.'/app/Controllers/ClusterApiController.php'), "'caddy-install-status'"));
ok('master hace polling (moduleBuildStatus) sin bloquear', str_contains($cbs, 'function moduleBuildStatus'));
ok('frontend hace polling (pollBuild) y NO usa alert/confirm feos', str_contains($clv2, 'function pollBuild') && str_contains($clv2, 'caddy-sync-status'));
ok('modales con SweetAlert (no alert/confirm nativo) en fix()', str_contains($clv2, "'Compilar módulos en '") && str_contains($clv2, 'Módulos compilados'));

echo "\n\033[1m─────────────────────────────────────────\033[0m\n";
echo "  \033[0;32m{$pass} passed\033[0m" . ($fail ? ", \033[0;31m{$fail} failed\033[0m" : '') . "\n\n";
exit($fail > 0 ? 1 : 0);
