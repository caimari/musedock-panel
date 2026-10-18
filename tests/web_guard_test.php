#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — dueños de ficheros de las webs, prueba de humo de webs y opciones de lsyncd
 * (standalone, sin PHPUnit). Solo lógica pura y carpetas temporales: no cambia dueños, no toca /etc
 * ni pide ninguna web.
 *
 * Casos del relevo del 2026-10-18: ficheros con dueño de administración que la web de la réplica no podía leer
 * (picalias, festgate, cineautor), carpetas de Laravel que no existían en la réplica (mycellup, relaybuild)
 * y webs que daban 500 sin que nadie lo supiera.
 *
 * Usage: php tests/web_guard_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

use MuseDockPanel\Services\FileSyncService as FS;
use MuseDockPanel\Services\WebPermissionService as W;
use MuseDockPanel\Services\WebSmokeService as S;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }
function section(string $t) { echo "\n\033[1m{$t}\033[0m\n"; }

section('1. Salida de find');
$raw = "musedockcomcalamar\tmusedockcomcalamar\t660\t/var/www/vhosts/x.com/httpdocs/security-block.php\0"
     . "musedockcomcalamar\twww-data\t775\t/var/www/vhosts/x.com/httpdocs/app/con espacio.php\0";
$items = W::parseFind($raw);
ok('lee dos registros separados por NUL', count($items) === 2);
ok('campos: dueño, grupo, modo y ruta (con espacios)', $items[1] === ['owner' => 'musedockcomcalamar', 'group' => 'www-data', 'mode' => '775', 'path' => '/var/www/vhosts/x.com/httpdocs/app/con espacio.php'], json_encode($items[1]));
ok('salida vacía: nada', W::parseFind('') === []);

section('2. Qué se cambia (dueño de la web, grupo de la carpeta que lo contiene)');
$plan = W::plan($items, 'xcomcalamar', static fn($dir) => str_ends_with($dir, '/app') ? 'www-data' : 'xcomcalamar');
ok('el dueño pasa al usuario de la web', $plan[0]['new_owner'] === 'xcomcalamar' && $plan[1]['new_owner'] === 'xcomcalamar');
ok('el grupo es el de la carpeta que lo contiene', $plan[0]['new_group'] === 'xcomcalamar' && $plan[1]['new_group'] === 'www-data', json_encode(array_column($plan, 'new_group')));
ok('si no se sabe el grupo de la carpeta, se deja el que tenía', W::plan([$items[0]], 'x', static fn($d) => '')[0]['new_group'] === 'musedockcomcalamar');
ok('lo anterior se conserva para poder deshacer', $plan[0]['owner'] === 'musedockcomcalamar' && $plan[0]['mode'] === '660');

section('3. Recorrido real de una carpeta temporal');
$tmp = sys_get_temp_dir() . '/web-guard-test-' . getmypid() . '/httpdocs';
foreach (['vendor/pkg', 'node_modules/m', 'storage/logs', 'storage/framework/cache', 'wp-content/cache', 'sub'] as $d) {
    mkdir("{$tmp}/{$d}", 0755, true);
}
foreach (['a.php', 'vendor/pkg/x.php', 'node_modules/m/y.js', 'storage/logs/l.log', 'storage/framework/cache/c', 'wp-content/cache/w', 'sub/b.php'] as $f) {
    file_put_contents("{$tmp}/{$f}", 'x');
}
$me = posix_getpwuid(posix_geteuid())['name'];
// «www-data» existe en el sistema y no soy yo: mis ficheros cuentan como ajenos
$found = array_column(W::scan($tmp, 'www-data'), 'path');
sort($found);
ok('hay resultados (si no, las comprobaciones de «no entra en…» pasarían en falso)', count($found) >= 3, json_encode($found));
ok('lo mío cuenta como ajeno para otra web (a.php, sub, sub/b.php)', in_array("{$tmp}/a.php", $found, true) && in_array("{$tmp}/sub/b.php", $found, true));
ok('no entra en vendor, node_modules, .git, storage/logs, storage/framework/cache ni wp-content/cache',
    !array_filter($found, static fn($p) => preg_match('#/(vendor|node_modules|\.git|wp-content/cache)(/|$)|/storage/(logs|framework/cache)(/|$)#', $p)), json_encode($found));
ok('los ficheros del usuario de la web NO se listan', W::scan($tmp, $me) === []);
ok('un nombre de usuario raro no ejecuta nada', W::scan($tmp, 'a;rm -rf /') === []);
ok('un usuario que no existe aquí no da «sin problemas» por error: devuelve vacío y run() lo avisa', W::scan($tmp, 'usuario-que-no-existe-xyz') === []);
// limpieza
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($tmp), FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir(dirname($tmp));

section('4. Deshacer solo con ficheros propios');
ok('no restaura un fichero cualquiera (/etc/passwd)', W::restore('/etc/passwd')['ok'] === false);
ok('ni uno que no existe', W::restore('/var/lib/musedock/permisos-no-existe.txt')['ok'] === false);

section('5. Prueba de humo de las webs');
ok('curl: 200 sin redirección', S::parseCurl("200\t") === ['code' => 200, 'redirect' => '']);
ok('curl: 302 con destino', S::parseCurl("302\thttps://www.x.com/inicio") === ['code' => 302, 'redirect' => 'https://www.x.com/inicio']);
ok('curl sin salida = sin respuesta (0)', S::parseCurl('')['code'] === 0);
ok('500 y 503 son error; sin respuesta también', S::isError(500) && S::isError(503) && S::isError(0));
ok('200, 301, 401, 403 y 404 en la portada NO son una web rota', !S::isError(200) && !S::isError(301) && !S::isError(401) && !S::isError(403) && !S::isError(404));
$lara = "[2026-10-18 12:00:00] production.ERROR: Please provide a valid cache path. {\"exception\":\"[object] (InvalidArgumentException(code: 0): Please provide a valid cache path. at /v/Compiler.php:50)\n"
      . "[stacktrace]\n#0 /var/www/x/vendor/laravel/framework/src/View/Compilers/Compiler.php(32): Illuminate\\View\\Compilers\\Compiler->__construct()\n#1 {main}\n\"}\n";
$line = S::lastErrorLine($lara);
ok('Laravel: devuelve la línea del error real, no «"}»', str_contains($line, 'Please provide a valid cache path') && $line !== '"}', $line);
$php = "[18-Oct-2026 15:08:23 America/New_York] PHP Fatal error:  Uncaught mysqli_sql_exception: The MariaDB server is running with the --read-only option\n";
ok('PHP: la línea de Fatal error', str_contains(S::lastErrorLine($php), 'MariaDB server is running with the --read-only'));
ok('un registro vacío no devuelve nada', S::lastErrorLine('') === '');
ok('la línea se corta a 300 caracteres', mb_strlen(S::lastErrorLine('x.ERROR: ' . str_repeat('a', 900))) <= 300);

section('6. Opciones de lsyncd (apagadas por defecto)');
$def = ['.git', 'node_modules', 'sessions', 'logs', 'storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', '*.tmp.*'];
$kept = FS::keepDirExcludes($def);
ok('storage/framework/cache pasa a storage/framework/cache/* (carpeta vacía sí, contenido no)', in_array('storage/framework/cache/*', $kept, true) && !in_array('storage/framework/cache', $kept, true));
ok('también sessions, views, logs y storage/logs', in_array('storage/framework/sessions/*', $kept, true) && in_array('storage/framework/views/*', $kept, true) && in_array('storage/logs/*', $kept, true) && in_array('logs/*', $kept, true) && in_array('sessions/*', $kept, true));
ok('el resto de patrones no se toca (.git, node_modules, *.tmp.*)', in_array('.git', $kept, true) && in_array('node_modules', $kept, true) && in_array('*.tmp.*', $kept, true));
ok('no cambia la cantidad de exclusiones', count($kept) === count($def));
ok('idempotente (aplicarlo dos veces no añade /*/*)', FS::keepDirExcludes($kept) === $kept);
ok('con ACL: acls y xattrs en el bloque rsync', str_contains(FS::rsyncPreserveLines(true), 'acls     = true') && str_contains(FS::rsyncPreserveLines(true), 'xattrs   = true'));
ok('sin ACL (por defecto): nada que añadir', FS::rsyncPreserveLines(false) === '');
ok('por defecto las dos opciones están apagadas', FS::getConfig()['lsyncd_acls'] === false && FS::getConfig()['lsyncd_keep_dirs'] === false);

section('7. Ajustes de la copia de ficheros que no pueden quedar vacíos (Obelix: exclusiones y llave SSH vacías)');
ok('un valor vacío cae al por defecto (Settings::get solo lo hace si falta del todo)', FS::valueOrDefault('', '/root/.ssh/id_ed25519') === '/root/.ssh/id_ed25519');
ok('solo espacios también', FS::valueOrDefault("  \n ", 'root') === 'root');
ok('un valor real se respeta', FS::valueOrDefault('/root/.ssh/otra', '/root/.ssh/id_ed25519') === '/root/.ssh/otra');
ok('las 5 claves con valor por defecto están protegidas', array_diff(['filesync_ssh_key_path', 'filesync_ssh_user', 'filesync_ssh_port', 'filesync_rsync_default_excludes', 'filesync_lsyncd_default_excludes'], FS::NEVER_EMPTY) === []);
ok('las exclusiones por defecto de lsyncd nunca salen vacías', count(FS::getLsyncdDefaultExcludes()) > 5 && in_array('storage/framework/cache', FS::getLsyncdDefaultExcludes(), true));
ok('las de rsync tampoco', count(FS::getRsyncDefaultExcludes()) > 0);
ok('la llave SSH, el usuario y el puerto nunca salen vacíos', FS::getConfig()['ssh_key_path'] !== '' && FS::getConfig()['ssh_user'] !== '' && FS::getConfig()['ssh_port'] > 0);
$snapPeer = FS::snapshotForPeer();
$emptyBad = array_filter(FS::NEVER_EMPTY, static fn($k) => array_key_exists($k, $snapPeer) && trim((string)$snapPeer[$k]) === '');
ok('la copia que se manda al otro nodo NUNCA lleva vacío lo que tiene valor por defecto', $emptyBad === [], json_encode($emptyBad));

section('8. Carpetas que exige cada pool de PHP-FPM (sin tmp, PHP solo recibía los primeros 16 KB: MCP de musedock.com, 2026-10-18)');
$pool = "[musedockcomcalamar]\nuser = musedockcomcalamar\ngroup = www-data\nphp_admin_value[open_basedir] = /var/www/vhosts/musedock.com/:/tmp/\nphp_admin_value[upload_tmp_dir] = /var/www/vhosts/musedock.com/tmp\nphp_admin_value[session.save_path] = /var/www/vhosts/musedock.com/sessions\nphp_admin_value[sys_temp_dir] = /var/www/vhosts/musedock.com/tmp\n";
$pd = W::poolDirs($pool);
ok('lee el usuario y el grupo del pool', $pd['user'] === 'musedockcomcalamar' && $pd['group'] === 'www-data');
ok('encuentra tmp y sesiones (y no repite tmp)', array_keys($pd['dirs']) === ['/var/www/vhosts/musedock.com/tmp', '/var/www/vhosts/musedock.com/sessions'], json_encode($pd['dirs']));
ok('las sesiones se crean más cerradas (0750) que tmp (0755)', $pd['dirs']['/var/www/vhosts/musedock.com/sessions'] === 0750 && $pd['dirs']['/var/www/vhosts/musedock.com/tmp'] === 0755);
ok('un pool sin esas líneas no pide nada', W::poolDirs("[www]\nuser = www-data\n")['dirs'] === []);
ok('open_basedir no se confunde con una carpeta de trabajo', !isset($pd['dirs']['/var/www/vhosts/musedock.com/:/tmp/']));
ok('se permite una carpeta dentro de una web', W::poolDirAllowed('/var/www/vhosts/musedock.com/tmp'));
foreach (['/tmp/x', '/etc/cron.d/x', '/var/www/vhosts/a.com/../../etc/x', '/var/www/vhosts//x', '/var/www/vhosts/x', '/root/tmp'] as $bad) {
    ok("se niega crear {$bad}", !W::poolDirAllowed($bad));
}

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
