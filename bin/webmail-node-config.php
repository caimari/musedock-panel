<?php
/**
 * Deja el webmail (Roundcube) listo en ESTE nodo, también en un slave de relevo.
 *
 * Los ficheros de Roundcube (/opt/musedock-webmail) se copian del master al slave
 * con lsyncd, incluido config.inc.php. Lo que cambia de un nodo a otro va aparte,
 * en /etc/musedock/webmail-local.inc.php (no se copia):
 *   - password_db_dsn: la base del panel de ESTE nodo (para cambiar la contraseña
 *     del buzón desde el webmail);
 *   - redis_hosts: el Redis local con su contraseña (requirepass).
 * config.inc.php incluye ese fichero al final.
 *
 * Con --enable-route --host=webmail.ejemplo.com [--alias=otro.ejemplo.com]: activa
 * el webmail en los ajustes de este panel y crea su ruta en Caddy (lo necesario en
 * el slave; en el master ya lo hizo el instalador).
 *
 * Uso (como root): php bin/webmail-node-config.php [--enable-route --host=...]
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Env;
use MuseDockPanel\Services\WebmailService;
use MuseDockPanel\Settings;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$opt = ['host' => '', 'alias' => []];
foreach ($argv as $a) {
    if (preg_match('/^--host=(.+)$/', $a, $m)) {
        $opt['host'] = strtolower(trim($m[1]));
    } elseif (preg_match('/^--alias=(.+)$/', $a, $m)) {
        $opt['alias'][] = strtolower(trim($m[1]));
    }
}
$current = realpath('/opt/musedock-webmail/roundcube/current');
$conf = $current ? $current . '/config/config.inc.php' : '';
if (!$conf || !is_file($conf)) {
    fwrite(STDERR, "Roundcube no está aquí (falta /opt/musedock-webmail/roundcube/current). En un slave, espera a que lsyncd copie /opt/musedock-webmail.\n");
    exit(1);
}

// 1) Fichero local con lo propio de este nodo.
$redis = '127.0.0.1:6379';
foreach (@file('/etc/redis/redis.conf', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    if (preg_match('/^\s*requirepass\s+(\S+)/', $l, $m)) {
        $redis = '127.0.0.1:6379:0:' . trim($m[1], '"');
    }
}
// En un slave de relevo Redis es réplica de SOLO LECTURA: Roundcube no podría guardar
// la sesión y la página daría 500. Ahí las sesiones van a ficheros locales; tras un
// relevo siguen funcionando igual (las sesiones no hace falta replicarlas).
$redisPass = str_contains($redis, ':0:') ? substr($redis, strpos($redis, ':0:') + 3) : '';
$role = (string)shell_exec(($redisPass !== '' ? 'REDISCLI_AUTH=' . escapeshellarg($redisPass) . ' ' : '')
    . 'redis-cli -h 127.0.0.1 info replication 2>/dev/null');
$redisIsReplica = (bool)preg_match('/^role:slave/m', $role);

// Carpetas de datos (temp_dir y log_dir de config.inc.php): no van en /opt, así que
// lsyncd no las copia; se crean aquí, escribibles por PHP-FPM (www-data).
foreach (['/var/lib/musedock-webmail' => ['root', 'www-data', 0750],
          '/var/lib/musedock-webmail/roundcube' => ['www-data', 'www-data', 0770],
          '/var/lib/musedock-webmail/roundcube/logs' => ['www-data', 'www-data', 0770],
          '/var/lib/musedock-webmail/roundcube/temp' => ['www-data', 'www-data', 0770]] as $dir => [$u, $g, $mode]) {
    if (!is_dir($dir)) {
        mkdir($dir, $mode, true);
        echo "Creada {$dir}\n";
    }
    chown($dir, $u);
    chgrp($dir, $g);
    chmod($dir, $mode);
}

$dsn = sprintf('pgsql://%s:%s@%s:%s/%s',
    rawurlencode((string)Env::get('DB_USER', 'musedock_panel')), rawurlencode((string)Env::get('DB_PASS', '')),
    Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '5433'), Env::get('DB_NAME', 'musedock_panel'));
$local = "<?php\n// Generado por MuseDock Panel (bin/webmail-node-config.php): propio de ESTE nodo, no se copia.\n"
    . '$config[\'password_db_dsn\'] = ' . var_export($dsn, true) . ";\n"
    . '$config[\'redis_hosts\'] = [' . var_export($redis, true) . "];\n"
    . ($redisIsReplica ? "// Redis es réplica (solo lectura) en este nodo: sesiones en ficheros.\n\$config['session_storage'] = 'php';\n" : '');
@mkdir('/etc/musedock', 0755, true);
$localFile = '/etc/musedock/webmail-local.inc.php';
if (!is_file($localFile) || file_get_contents($localFile) !== $local) {
    file_put_contents($localFile, $local);
    echo "Escrito {$localFile}\n";
} else {
    echo "OK {$localFile} ya estaba bien\n";
}
chown($localFile, 'root');
chgrp($localFile, 'www-data');
chmod($localFile, 0640);

// 2) config.inc.php incluye el fichero local (al final, para que mande).
$c = (string)file_get_contents($conf);
$inc = "\n// Ajustes propios de cada nodo (MuseDock): BD del panel y Redis locales.\n"
    . "if (is_file('/etc/musedock/webmail-local.inc.php')) { include '/etc/musedock/webmail-local.inc.php'; }\n";
if (!str_contains($c, '/etc/musedock/webmail-local.inc.php')) {
    copy($conf, $conf . '.bak.' . date('Ymd_His'));
    file_put_contents($conf, rtrim($c) . "\n" . $inc);
    echo "config.inc.php ahora incluye el fichero local\n";
} else {
    echo "OK config.inc.php ya incluye el fichero local\n";
}

// 3) Opcional: activar el webmail en este panel y su ruta en Caddy.
if (in_array('--enable-route', $argv, true)) {
    if ($opt['host'] === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $opt['host'])) {
        fwrite(STDERR, "Falta --host=webmail.ejemplo.com\n");
        exit(1);
    }
    $docRoot = is_file($current . '/public_html/index.php') ? $current . '/public_html' : $current;
    Settings::set('mail_webmail_enabled', '1');
    Settings::set('mail_webmail_provider', 'roundcube');
    Settings::set('mail_webmail_host', $opt['host']);
    Settings::set('mail_webmail_url', 'https://' . $opt['host']);
    Settings::set('mail_webmail_doc_root', $docRoot);
    Settings::set('mail_webmail_aliases', json_encode(array_values(array_unique($opt['alias']))));
    if (Settings::get('mail_webmail_install_status', '') === '') {
        Settings::set('mail_webmail_install_status', 'completed');
    }
    $r = WebmailService::repairConfiguredRoute();
    echo !empty($r['ok']) ? 'Ruta de Caddy lista: ' . implode(', ', $r['applied'] ?? []) . "\n"
        : 'ERROR ruta de Caddy: ' . ($r['error'] ?? '?') . "\n";
}
echo "Hecho.\n";
