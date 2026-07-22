#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — webmail (Roundcube) tests.
 *
 * READ-ONLY / logic-only: never installs, never touches Caddy/DB. Verifies the
 * installer's performance/correctness fixes and the master→slave install wiring.
 *
 * Usage: php tests/webmail_test.php
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

$inst = file_get_contents(PANEL_ROOT . '/bin/webmail-setup-run.php');
$svc  = file_get_contents(PANEL_ROOT . '/app/Services/WebmailService.php');
$api  = file_get_contents(PANEL_ROOT . '/app/Controllers/ClusterApiController.php');
$ctrl = file_get_contents(PANEL_ROOT . '/app/Controllers/MailController.php');
$view = file_get_contents(PANEL_ROOT . '/resources/views/mail/index.php');
$routes = file_get_contents(PANEL_ROOT . '/public/index.php');

section('1. Rendimiento: conexión local por 127.0.0.1 (no IP pública)');
ok('detecta si el correo es local (webmail_mail_is_local)', str_contains($inst, 'function webmail_mail_is_local'));
ok('usa tls://127.0.0.1 cuando es local (evita rodeo + timeout IPv6)', str_contains($inst, 'tls://127.0.0.1'));
ok('managesieve también a 127.0.0.1 local', str_contains($inst, '$sieveConnHost') && str_contains($inst, 'managesieve_host'));
ok('no verifica cert en conexión local (cert es *.dominio, no 127.0.0.1)', str_contains($inst, 'imap_conn_options') && str_contains($inst, "verify_peer' => false"));

section('2. Rendimiento: caché + Redis');
ok('caché de mensajes en db', str_contains($inst, "messages_cache'] = 'db'"));
ok('imap_cache en db', str_contains($inst, "imap_cache'] = 'db'"));
ok('sesiones en Redis (RAM, no ficheros)', str_contains($inst, "session_storage'] = 'redis'"));
ok('php-redis en los paquetes', str_contains($inst, 'redis'));

section('3. CRÍTICO: permisos de la BBDD roundcube (caché no rota)');
ok('el rol roundcube pasa a ser DUEÑO de las tablas (ALTER TABLE OWNER)',
    str_contains($inst, 'OWNER TO {$rcUser}') || str_contains($inst, 'ALTER TABLE'));
ok('además GRANT ALL sobre tablas/secuencias', str_contains($inst, 'GRANT ALL ON ALL TABLES'));
ok('usa PostgreSQL (no sqlite, replicable para HA)', str_contains($inst, "pgsql://") && !str_contains($inst, "sqlite:///"));

section('4. Estructura Roundcube 1.7 (docroot + assets)');
ok('docroot = public_html', str_contains($inst, 'public_html'));
ok('assets servidos vía static.php (split_path)', str_contains($svc, 'static.php') && str_contains($svc, "split_path' => ['static.php']"));
ok('ruta Caddy insertada al principio (antes del wildcard)', str_contains($svc, 'routes/0'));
ok('socket PHP-FPM detectado como socket (no is_file)', str_contains($svc, "filetype(\$p) === 'socket'"));

section('5. Webmail-en-slave revertido (choca con réplica 5433 read-only)');
ok('NO existe prepareWebmailOnNode (revertido)', !str_contains($svc, 'function prepareWebmailOnNode'));
ok('NO existe la acción cluster webmail_install (revertido)', !str_contains($api, "'webmail_install'"));
ok('NO existe el botón webmail-slave en la UI (revertido)', !str_contains($view, 'prepare-webmail-node-btn'));

echo "\n\033[1m─────────────────────────────────────────\033[0m\n";
echo "  \033[0;32m{$pass} passed\033[0m" . ($fail ? ", \033[0;31m{$fail} failed\033[0m" : '') . "\n\n";
exit($fail > 0 ? 1 : 0);
