#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — CardDAV/CalDAV (Baïkal) integration tests.
 *
 * READ-ONLY / logic-only: never installs Baïkal, never touches the DB or Caddy.
 * Verifies the design invariants that make the service correct + failover-safe:
 *  - auth is validated against Dovecot via IMAP (single source of truth);
 *  - each user only ever reaches their OWN principal (no cross-mailbox access);
 *  - contacts/calendars replicate master→slave by role (last promotion wins);
 *  - bulk snapshot payloads never get dumped into the replicated panel_log.
 *
 * Usage: php tests/carddav_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }
function section(string $t) { echo "\n\033[1m{$t}\033[0m\n"; }

$auth = file_get_contents(PANEL_ROOT . '/resources/carddav/IMAPBasicAuth.php');
$svc  = file_get_contents(PANEL_ROOT . '/app/Services/CardDavService.php');
$inst = file_get_contents(PANEL_ROOT . '/bin/carddav-setup-run.php');
$wrk  = file_get_contents(PANEL_ROOT . '/bin/carddav-sync-worker.php');
$api  = file_get_contents(PANEL_ROOT . '/app/Controllers/ClusterApiController.php');
$clu  = file_get_contents(PANEL_ROOT . '/app/Services/ClusterService.php');
$wm   = file_get_contents(PANEL_ROOT . '/bin/webmail-setup-run.php');

section('1. Auth contra Dovecot por IMAP (una sola fuente de verdad)');
ok('el backend extiende AbstractBasic', str_contains($auth, 'extends \Sabre\DAV\Auth\Backend\AbstractBasic'));
ok('valida por imap_open contra Dovecot', str_contains($auth, 'imap_open(') && str_contains($auth, 'validateUserPass'));
ok('fuerza TLS y no valida cert en loopback', str_contains($auth, '/imap/tls/novalidate-cert'));
ok('rechaza usuario sin @ (debe ser email de buzón)', str_contains($auth, "strpos(\$username, '@') === false"));
ok('rechaza password vacío', str_contains($auth, "\$password === ''"));
// No propia verificación de contraseña: la única comprobación de credenciales
// es imap_open. El digest que se escribe en `users` es aleatorio e inservible
// para login (nunca se compara), solo satisface el NOT NULL de la tabla.
ok('la ÚNICA verificación de credenciales es imap_open (no compara hash propio)',
    substr_count($auth, 'imap_open(') >= 1
    && !preg_match('/digesta1\s*===/', $auth)
    && !str_contains($auth, 'password_verify('));

section('2. Aislamiento por buzón: cada usuario solo ve SU principal (no IDOR)');
ok('el principal se deriva del username autenticado', str_contains($auth, "\$principalUri = 'principals/' . \$username"));
ok('el preset de Roundcube usa %u en la URL (solo su libreta)', str_contains($inst, 'addressbooks/%u/default'));
ok('username/password del preset son FIXED (el usuario no los cambia)', str_contains($inst, "'fixed'        => ['username', 'password', 'url']"));
ok('el aprovisionamiento inserta principals/<username> exacto', str_contains($auth, "INSERT INTO principals"));

section('3. Aprovisionamiento idempotente + race-safe en el primer login');
ok('los UNIQUE que faltan en el schema se crean en el instalador',
    str_contains($inst, 'principals_uri_uniq') && str_contains($inst, 'addressbooks_principal_uri_uniq'));
ok('principals usa ON CONFLICT (uri) DO NOTHING', str_contains($auth, 'ON CONFLICT (uri) DO NOTHING'));
ok('users usa ON CONFLICT (username) DO NOTHING', str_contains($auth, 'ON CONFLICT (username) DO NOTHING'));
ok('libreta creada vía el backend de sabre (no SQL a mano frágil)', str_contains($auth, 'Sabre\CardDAV\Backend\PDO') && str_contains($auth, 'createAddressBook'));
ok('calendario creado vía el backend de sabre', str_contains($auth, 'Sabre\CalDAV\Backend\PDO') && str_contains($auth, 'createCalendar'));

section('4. Almacenamiento en PostgreSQL (5433) para poder replicar');
ok('el instalador crea DB baikal en el cluster', str_contains($inst, "\$dbName = 'baikal'"));
ok('carga el schema PgSQL de Baïkal', str_contains($inst, 'Db/PgSQL/db.sql'));
ok('el DSN pliega el puerto 5433 en el host (Flake no acepta port)', str_contains($inst, "\$pgHost = \$dbHost . ';port=' . \$dbPort"));
ok('la config baikal.yaml usa backend pgsql', str_contains($inst, "backend: 'pgsql'"));
ok('auth type = IMAP en la config', str_contains($inst, "dav_auth_type: 'IMAP'"));
ok('Server.php se parchea para la rama IMAP', str_contains($inst, "IMAPBasicAuth("));

section('5. Failover: el MASTER empuja; direccion segun rol (last promotion wins)');
ok('el worker solo actua si cluster_role = master', str_contains($wrk, "\$role !== 'master'") && str_contains($wrk, "cluster_role"));
ok('el worker no hace nada si CardDAV no esta instalado', str_contains($wrk, 'CardDavService::isInstalled()'));
ok('replicaNodes excluye el nodo local (no auto-push)', str_contains($svc, 'Exclude self') || str_contains($svc, 'hostname -I'));
ok('replicaNodes = online + mail', str_contains($svc, "status = 'online'") && str_contains($svc, "services::text LIKE '%mail%'"));
ok('el receptor reemplaza (TRUNCATE+insert) de forma autoritativa', str_contains($svc, 'TRUNCATE TABLE') && str_contains($svc, 'RESTART IDENTITY'));
ok('realinea la secuencia SERIAL tras copiar (evita colision con auto-provision)', str_contains($svc, 'setval(pg_get_serial_sequence'));
ok('NO replica tablas de change-log/locks (bookkeeping por nodo)',
    !str_contains($svc, "'addressbookchanges'") && !str_contains($svc, "'locks'"));

section('5b. Fixes CRÍTICOS de la review adversarial (pérdida de datos)');
// C1: snapshot parcial/vacío no debe borrar los datos del receptor.
ok('C1: exportSnapshot ABORTA (return null) si una tabla no se puede leer', str_contains($svc, 'exportSnapshot ABORT') && str_contains($svc, 'return null'));
ok('C1: applySnapshot exige snapshot completo (flag complete)', str_contains($svc, "empty(\$snapshot['complete'])"));
ok('C1: applySnapshot exige tablas de identidad (principals/addressbooks)', str_contains($svc, "['principals', 'addressbooks'] as \$must"));
ok('C1: rechaza snapshot vacío sobre datos existentes', str_contains($svc, 'snapshot vacío sobre datos existentes'));
// C2: el receptor valida rol — un master no acepta reemplazo (anti split-brain).
ok('C2: applySnapshot rechaza si este nodo es master', str_contains($svc, "cluster_role', 'standalone') === 'master'") && str_contains($svc, 'este nodo es master'));
ok('C2: el snapshot lleva promoted_at (last promotion wins)', str_contains($svc, "'promoted_at'") && str_contains($clu, 'cluster_promoted_at'));
// M5: inyección SQL por nombres de columna.
ok('M5: nombres de columna filtrados contra el schema real (information_schema)', str_contains($svc, 'information_schema.columns') && str_contains($svc, 'allowedCols'));
// M4: realineo de secuencia correcto (is_called según haya filas).
ok('M4: setval usa is_called condicional (no pierde id=1 en tabla vacía)', str_contains($svc, 'COUNT(*) FROM') && str_contains($svc, "GREATEST"));
// M3: sin falso SET CONSTRAINTS (no hay FKs en Baïkal).
ok('M3: no usa SET CONSTRAINTS ALL DEFERRED (Baïkal no tiene FKs)', !str_contains($svc, 'SET CONSTRAINTS ALL DEFERRED'));
// BAJO: lockfile fuera de /tmp.
ok('lockfile en storage/ (no /tmp DoS-able)', !str_contains($wrk, "/tmp/musedock-carddav-sync") && str_contains($wrk, 'carddav-sync.lock'));

section('5c. Réplica en el slave orquestada por el master (botón Infra)');
ok('prepareReplicaOnNode existe y exige CardDAV instalado en master', str_contains($svc, 'function prepareReplicaOnNode') && str_contains($svc, 'CardDAV no está instalado en este master'));
ok('envía las MISMAS credenciales de DB al slave (misma role pass)', str_contains($svc, "'db_pass'       => \$dbPass") && str_contains($svc, "'action'  => 'carddav_setup_replica'"));
ok('el slave persiste las credenciales y lanza el MISMO instalador', str_contains($svc, 'function nodeSetupReplica') && str_contains($svc, 'carddav-setup-run.php'));
ok('el slave reutiliza carddav_db_pass del master (snapshot aplica limpio)', str_contains($svc, "Settings::set('carddav_db_pass', \$dbPass)"));
ok('acción carddav_setup_replica en el dispatcher', str_contains($api, "'carddav_setup_replica'") && str_contains($api, 'nodeSetupReplica'));
ok('las credenciales DAV no se loguean (db_pass/enc_key en SECRET_KEYS)', str_contains($api, "'db_pass'") && str_contains($api, "'enc_key'"));
ok('tras preparar, empuja un primer snapshot inmediato', str_contains($svc, 'self::syncToNode($slaveNodeId)') && str_contains($svc, 'primer sync'));

section('6. promoteToMaster: el nodo promovido empuja su snapshot al instante');
ok('promoteToMaster hace resync CardDAV', str_contains($clu, 'CardDavService::syncToNode') && str_contains($clu, 'CardDavService::replicaNodes'));
ok('solo si CardDAV esta instalado', str_contains($clu, 'CardDavService::isInstalled()'));

section('7. Privacidad: el snapshot (PII) NO se vuelca al panel_log replicado');
ok('carddav_apply_snapshot esta en las acciones "bulk" no logueadas',
    str_contains($api, "'carddav_apply_snapshot'") && str_contains($api, 'bulkActions'));
ok('la accion existe en el dispatcher del cluster', str_contains($api, "'carddav_apply_snapshot' => \\MuseDockPanel\\Services\\CardDavService::applySnapshot"));

section('8. Integracion Roundcube (SSO) + instalador webmail');
ok('el plugin carddav se instala por composer', str_contains($inst, 'composer require roundcube/carddav'));
ok('preset con %u/%p (credenciales de sesion, sin re-login)', str_contains($inst, "'username'     => '%u'") && str_contains($inst, "'password'     => '%p'"));
ok('el instalador webmail añade carddav a plugins si esta instalado',
    str_contains($wm, "carddav_installed") && str_contains($wm, "'carddav'"));

section('9. Ruta Caddy + autodiscovery movil');
ok('ruta insertada en index 0 (gana al wildcard)', str_contains($svc, 'routes/0'));
ok('bloquea Core/Specific/config del acceso web', str_contains($svc, '/Core/*') && str_contains($svc, '/Specific/*'));
ok('.well-known/carddav y caldav redirigen a dav.php', str_contains($svc, '/.well-known/carddav') && str_contains($svc, '/.well-known/caldav'));
ok('todo lo demas entra por dav.php', str_contains($svc, "/dav.php{http.request.uri}"));

echo "\n\033[1m─────────────────────────────────────────\033[0m\n";
echo "  \033[0;32m{$pass} passed\033[0m" . ($fail ? ", \033[0;31m{$fail} failed\033[0m" : '') . "\n\n";
exit($fail > 0 ? 1 : 0);
