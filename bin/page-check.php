<?php
/**
 * Carga una página del panel desde la terminal, como el primer administrador, y enseña
 * el error real si falla (excepción, error fatal o aviso de PHP), con fichero y línea.
 * Para cuando una página da 500 y el registro de errores no dice nada. Solo lectura:
 * hace una petición GET, igual que el navegador.
 *
 *   php bin/page-check.php /domains
 */

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$path = (string)($argv[1] ?? '');
if ($path === '' || $path[0] !== '/') {
    fwrite(STDERR, "Uso: php bin/page-check.php /ruta   (p. ej. /domains)\n");
    exit(1);
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
$problems = [];
set_error_handler(static function ($no, $msg, $file, $line) use (&$problems) {
    // Avisos de la sesión que abre esta herramienta (no son de la página).
    if (preg_match('/^(ini_set\(\): Session ini|session_name\(\)|session_start\(\): Ignoring|Constant PANEL_ROOT already defined)/', $msg)) {
        return true;
    }
    $problems[] = "PHP [{$no}] {$msg} @ {$file}:{$line}";
    return true;
});
register_shutdown_function(static function () use (&$problems, $path) {
    $out = ob_get_level() ? ob_get_clean() : '';
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $problems[] = "FATAL {$e['message']} @ {$e['file']}:{$e['line']}";
    }
    $code = http_response_code() ?: 200;
    echo "\n== {$path}: HTTP {$code}, " . strlen((string)$out) . " bytes\n";
    echo $problems ? implode("\n", $problems) . "\n" : "Sin errores de PHP.\n";
});

require_once dirname(__DIR__) . '/app/bootstrap.php';
$admin = \MuseDockPanel\Database::fetchOne('SELECT id, username FROM panel_admins ORDER BY id LIMIT 1');
if (!$admin) {
    fwrite(STDERR, "No hay administradores en este panel.\n");
    exit(1);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$_SESSION['panel_user'] = ['id' => (int)$admin['id'], 'username' => (string)$admin['username'], 'role' => 'superadmin'];
$_SESSION['last_activity'] = time();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $path;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';

ob_start();
try {
    require dirname(__DIR__) . '/public/index.php';
} catch (\Throwable $e) {
    $problems[] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
        . "\n" . implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 8));
}
