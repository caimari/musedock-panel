#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — mail backup-replica tests (master-orchestrated).
 *
 * READ-ONLY / logic-only: never installs services, never opens a DB connection,
 * never touches Dovecot/Postfix. Verifies the slave state machine and the
 * master-orchestrated wiring that fixes the 3 critical review findings:
 *   A: per-node encryption key — master passes the mail password IN CLEAR.
 *   C: master pg_hba opened for a NORMAL musedock_mail connection from the slave.
 *   D: dsync secret shared to both ends + drop-in !includes the secret file.
 *
 * Usage: php tests/mail_slave_replica_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

use MuseDockPanel\Services\MailService;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }
function section(string $t) { echo "\n\033[1m{$t}\033[0m\n"; }

$mailSrc = file_get_contents(PANEL_ROOT . '/app/Services/MailService.php');
$replSrc = file_get_contents(PANEL_ROOT . '/app/Services/MailReplicationService.php');
$ccSrc   = file_get_contents(PANEL_ROOT . '/app/Controllers/ClusterController.php');
$apiSrc  = file_get_contents(PANEL_ROOT . '/app/Controllers/ClusterApiController.php');
$view    = file_get_contents(PANEL_ROOT . '/resources/views/mail/index.php');
$routes  = file_get_contents(PANEL_ROOT . '/public/index.php');

section('1. slaveMailReplicaStatus (máquina de estados de solo-lectura)');
$st = MailService::slaveMailReplicaStatus();
ok('claves esperadas', isset($st['services_installed'], $st['dsync_configured'], $st['next_step']));
ok('next_step válido', in_array($st['next_step'], ['install_services', 'configure_dsync', 'ready'], true));

section('2. Orquestación master→slave (el sentido correcto)');
ok('existe prepareMailReplicaOnNode en el master', str_contains($mailSrc, 'function prepareMailReplicaOnNode'));
ok('existe el handler nodeSetupBackupReplica en el slave', str_contains($mailSrc, 'function nodeSetupBackupReplica'));
ok('endpoint master prepare-mail-replica registrado',
    str_contains($routes, 'prepare-mail-replica') && str_contains($ccSrc, 'function prepareMailReplica'));
ok('acción de cluster mail_setup_backup_replica en el dispatcher',
    str_contains($apiSrc, 'mail_setup_backup_replica') && str_contains($apiSrc, 'nodeSetupBackupReplica'));
ok('prepareMailReplica bloquea si el nodo es slave (solo master orquesta)',
    str_contains($ccSrc, 'function prepareMailReplica') && str_contains($ccSrc, '$this->isSlaveNode()'));

section('3. Fix A — la contraseña del master va EN CLARO (no se desencripta en el slave)');
ok('el master obtiene la password en claro (ensureMasterMailPassword)',
    str_contains($mailSrc, 'function ensureMasterMailPassword'));
ok('el master la pasa como master_db_pass en el payload',
    str_contains($mailSrc, "'master_db_pass' => \$mailPass"));
ok('el slave usa master_db_pass del payload, NO settings locales',
    str_contains($mailSrc, "\$payload['master_db_pass']") && !str_contains($mailSrc, "nodeSetupBackupReplica") === false);
ok('setupMailLocal en modo master exige que la password la envíe la orquestación',
    str_contains($ccSrc, "\$_POST['master_db_pass']") && str_contains($ccSrc, 'debe enviarla la orquestación'));

section('4. Fix C — pg_hba del master abierto para conexión NORMAL de musedock_mail');
ok('existe openMasterHbaForMailUser', str_contains($mailSrc, 'function openMasterHbaForMailUser'));
ok('la línea es host <db> musedock_mail (NO replication)',
    str_contains($mailSrc, 'host    {$db}    musedock_mail') && str_contains($mailSrc, 'scram-sha-256'));
ok('recarga solo el clúster panel (no umbrella)',
    str_contains($mailSrc, 'pg_ctlcluster') && str_contains($mailSrc, 'reload'));
ok('es idempotente (no duplica la línea)', str_contains($mailSrc, '!str_contains($hba, $line)'));

section('5. Fix D — dsync: secreto compartido a ambos lados + !include del fichero');
ok('el drop-in incluye el fichero de secreto (!include)',
    str_contains($replSrc, '!include {$secretFile}') || str_contains($replSrc, '!include '));
ok('usa la constante SECRET_FILE', str_contains($replSrc, "const SECRET_FILE"));
ok('el master configura SU lado del dsync con el secreto compartido',
    str_contains($mailSrc, 'MailReplicationService::configureNode($slaveWgIp, false, $secret)'));
ok('el slave recibe el secreto y lo guarda para configurar dsync en fase 2',
    str_contains($mailSrc, '$dsyncSecret') && str_contains($mailSrc, 'mail_replica_dsync_secret_enc')
    && str_contains($mailSrc, 'configureNode($partner, false, $secret)'));

section('6. UI: botón en el MASTER, estado en el slave');
ok('la vista slave ya NO tiene botones de acción (solo estado)',
    !str_contains($view, 'slaveInstallMailBtn') && !str_contains($view, 'slaveConfigureDsyncBtn'));
ok('la vista slave indica que se lanza desde el master',
    str_contains($view, 'se lanza') && str_contains($view, 'desde el panel del master'));
ok('el master tiene botón "Instalar réplica de correo" por nodo slave',
    str_contains($view, 'prepare-mail-replica-btn') && str_contains($view, 'prepare-mail-replica'));
ok('usa _csrf_token', str_contains($view, "_csrf_token"));

section('7. Fixes de la 2ª revisión adversarial (H1-H4)');
ok('H1: los secretos se redactan antes de loguear (panel_log replicado)',
    str_contains($apiSrc, 'function redactSecrets') && str_contains($apiSrc, 'master_db_pass'));
ok('H1: el log usa redactSecrets sobre el payload',
    str_contains($apiSrc, 'redactSecrets($payload)'));
ok('H1: la lista de secretos cubre password/token/secret',
    str_contains($apiSrc, "'dsync_secret'") && str_contains($apiSrc, "'admin_password'") && str_contains($apiSrc, "'setup_token'"));
ok('H2: existe finalizeBackupReplica que hace el sync inicial',
    str_contains($mailSrc, 'function finalizeBackupReplica') && str_contains($mailSrc, 'initialSync'));
ok('H2/H4: la fase 2 (dsync+sync) se ENCOLA, no corre en la race de instalación',
    str_contains($mailSrc, "'mail_finalize_backup_replica'") && str_contains($mailSrc, 'ClusterService::enqueue'));
ok('H4: nodeSetupBackupReplica ya NO configura dsync en línea (defiere a fase 2)',
    str_contains($mailSrc, 'mail_replica_pending') && !str_contains($mailSrc, 'configureNode($dsyncPartner, false, $dsyncSecret)'));
ok('H4: acción mail_finalize_backup_replica en el dispatcher',
    str_contains($apiSrc, 'mail_finalize_backup_replica') && str_contains($apiSrc, 'finalizeBackupReplica'));
ok('H2: finalizeBackupReplica es idempotente (no-op si no hay réplica pendiente)',
    str_contains($mailSrc, "mail_replica_pending', '') !== '1'"));
ok('H3: configureNode abre el puerto dsync scoped al partner /32',
    str_contains($replSrc, 'iptables') && str_contains($replSrc, 'REPL_PORT') && str_contains($replSrc, 'ACCEPT'));

echo "\n\033[1m─────────────────────────────────────────\033[0m\n";
echo "  \033[0;32m{$pass} passed\033[0m" . ($fail ? ", \033[0;31m{$fail} failed\033[0m" : '') . "\n\n";
exit($fail > 0 ? 1 : 0);
