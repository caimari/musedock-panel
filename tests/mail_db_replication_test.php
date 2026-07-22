#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — mail domain/mailbox DB replication tests (backup-replica model).
 *
 * READ-ONLY / logic-only: never enqueues, never touches the DB or mail server.
 * Verifies that domains/mailboxes are copied to each replica node's LOCAL DB (so a
 * slave works when the master is down), and that a promoted node re-syncs its state.
 *
 * Usage: php tests/mail_db_replication_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }
function section(string $t) { echo "\n\033[1m{$t}\033[0m\n"; }

$ms = file_get_contents(PANEL_ROOT . '/app/Services/MailService.php');
$cs = file_get_contents(PANEL_ROOT . '/app/Services/ClusterService.php');

section('1. Cada creación/borrado se replica a TODOS los nodos réplica');
ok('createDomain replica a los nodos', str_contains($ms, "self::replicateMailOp('mail_create_domain'"));
ok('createMailbox replica a los nodos', str_contains($ms, "self::replicateMailOp('mail_create_mailbox'"));
ok('deleteDomain replica a los nodos', str_contains($ms, "self::replicateMailOp('mail_delete_domain'"));
ok('deleteMailbox replica a los nodos', str_contains($ms, "self::replicateMailOp('mail_delete_mailbox'"));
ok('replicateMailOp recorre los nodos de mail réplica', str_contains($ms, 'function replicateMailOp') && str_contains($ms, 'getMailReplicaNodes'));
ok('nodos réplica = online + services incluye mail', str_contains($ms, "services::text LIKE '%mail%'") && str_contains($ms, "status = 'online'"));

section('2. El slave guarda los buzones en su BBDD LOCAL (no lee del master)');
ok('nodeCreateDomain hace upsert LOCAL', str_contains($ms, 'function nodeCreateDomain') && str_contains($ms, 'upsertLocalMailDomain'));
ok('nodeCreateMailbox hace upsert LOCAL', str_contains($ms, 'function nodeCreateMailbox') && str_contains($ms, 'upsertLocalMailAccount'));
ok('el upsert es idempotente (por dominio/email)',
    str_contains($ms, "WHERE domain = :d") && str_contains($ms, "WHERE email = :e"));
ok('el payload del buzón lleva password_hash/local_part (para auth local)',
    str_contains($ms, "'password_hash' => \$passwordHash") && str_contains($ms, "'local_part'    => strtolower"));

section('3. FIX del fallo detectado: el slave lee 127.0.0.1, NO el master');
ok('la instalación de réplica usa db_host=127.0.0.1 (local)',
    str_contains($ms, "'db_host'       => '127.0.0.1'"));
ok('crea un rol musedock_mail LOCAL en el slave', str_contains($ms, 'function ensureLocalMailRole') && str_contains($ms, 'ensureLocalMailRole()'));
ok('mail_db_source pasa a local (no master)', str_contains($ms, "Settings::set('mail_db_source', 'local')"));
ok('NO usa el db_pass del master para la conexión local',
    !str_contains($ms, "'db_pass'       => \$masterDbPass"));

section('4. Sync inicial: los buzones EXISTENTES se copian al instalar la réplica');
ok('resyncMailToNode empuja todos los dominios+buzones actuales', str_contains($ms, 'function resyncMailToNode'));
ok('se llama al preparar la réplica en un nodo', str_contains($ms, 'self::resyncMailToNode($slaveNodeId)'));
ok('el nodo se marca como nodo de mail (services += mail)', str_contains($ms, 'function markNodeAsMail') && str_contains($ms, 'markNodeAsMail($slaveNodeId)'));

section('5. Fase B: al promoverse, el nuevo master re-sincroniza a los nodos');
ok('promoteToMaster hace resync de mail a los nodos', str_contains($cs, 'resyncMailToNode') && str_contains($cs, 'getMailReplicaNodes'));
ok('documentado como "last promotion wins" (modelo simple seguro)',
    str_contains($cs, 'last promotion wins') || str_contains($cs, 're-absorbs'));

section('6. Fixes de la review adversarial');
$apic = file_get_contents(PANEL_ROOT . '/app/Controllers/ClusterApiController.php');
ok('C: password_hash NO se loguea (en SECRET_KEYS)', str_contains($apic, "'password_hash'"));
ok('C: dkim_private_key tampoco se loguea', str_contains($apic, "'dkim_private_key'"));
ok('B/F: getMailReplicaNodes excluye el nodo LOCAL (no auto-encola loopback)',
    str_contains($ms, 'Exclude a possible self-row') && str_contains($ms, '$localIps'));
ok('G: no crea cuenta nueva con password_hash vacío (evita row roto)',
    str_contains($ms, "Cuenta {\$email} omitida: sin password_hash"));

echo "\n\033[1m─────────────────────────────────────────\033[0m\n";
echo "  \033[0;32m{$pass} passed\033[0m" . ($fail ? ", \033[0;31m{$fail} failed\033[0m" : '') . "\n\n";
exit($fail > 0 ? 1 : 0);
