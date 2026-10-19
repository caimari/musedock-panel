#!/usr/bin/php
<?php
/**
 * MuseDock — captura del correo que manda PHP por el sendmail local (mail(), wp_mail()).
 *
 * Se usa como sendmail_path del pool de PHP-FPM de un hosting:
 *   php_admin_value[sendmail_path] = /usr/local/sbin/musedock-mail-capture --site=ejemplo.com --mode=capture
 * PHP le añade sus propias opciones (-t -i -f…) y le pasa el mensaje por la entrada estándar.
 *
 *   --mode=capture  guarda el mensaje entero en la carpeta de captura y devuelve «enviado» (la web sigue su curso);
 *   --mode=block    guarda solo la cabecera y 4 KB (para saber qué se intentó) y devuelve ERROR a PHP.
 *
 * No envía nada. Es autónomo (no usa ficheros del panel): lo copia el panel a /usr/local/sbin y lo ejecuta el
 * usuario de cada hosting. La carpeta de captura es 1733 (se puede crear en ella, no listar ni leer lo ajeno);
 * lee y borra el panel (root).
 */
define('SPOOL', getenv('MUSEDOCK_MAIL_CAPTURE_SPOOL') ?: '/var/spool/musedock-mail-capture');   // la variable solo la usan las pruebas
const MAX_BYTES = 10 * 1024 * 1024;
const MAX_PER_HOUR = 120;
const MAX_PER_DAY = 1500;

$site = '';
$mode = 'capture';
$envFrom = '';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--site=')) {
        $site = preg_replace('/[^a-z0-9.-]/', '', strtolower(substr($a, 7)));
    } elseif (str_starts_with($a, '--mode=')) {
        $mode = substr($a, 7) === 'block' ? 'block' : 'capture';
    } elseif (str_starts_with($a, '-f') && strlen($a) > 2) {
        $envFrom = substr($a, 2);
    }
}

$in = fopen('php://stdin', 'rb');
$raw = '';
while (!feof($in) && strlen($raw) <= MAX_BYTES) {
    $raw .= (string)fread($in, 65536);
}
if ($raw === '') {
    exit($mode === 'block' ? 1 : 0);
}
$truncated = strlen($raw) > MAX_BYTES;
$raw = substr($raw, 0, MAX_BYTES);

$uid = function_exists('posix_geteuid') ? posix_geteuid() : 0;
$pw = function_exists('posix_getpwuid') ? (posix_getpwuid($uid) ?: []) : [];
$user = (string)($pw['name'] ?? (string)$uid);

// Tope por usuario: una web hackeada no puede llenar el disco ni esconder el abuso (hora y día).
$rateFile = SPOOL . '/.rate-' . $uid;
$now = time();
$stamps = [];
if (is_file($rateFile)) {
    $stamps = array_values(array_filter(array_map('intval', explode("\n", (string)@file_get_contents($rateFile))), static fn($t) => $t > $now - 86400));
}
$lastHour = count(array_filter($stamps, static fn($t) => $t > $now - 3600));
if ($lastHour >= MAX_PER_HOUR || count($stamps) >= MAX_PER_DAY) {
    exit(1);    // se descarta y PHP lo sabe (mail() devuelve false)
}
$stamps[] = $now;
@file_put_contents($rateFile, implode("\n", $stamps), LOCK_EX);
@chmod($rateFile, 0600);

$sepPos = strpos($raw, "\r\n\r\n");
$sepLen = 4;
if ($sepPos === false) {
    $sepPos = strpos($raw, "\n\n");
    $sepLen = 2;
}
$head = $sepPos === false ? $raw : substr($raw, 0, $sepPos);
$body = $sepPos === false ? '' : substr($raw, $sepPos + $sepLen);
if ($mode === 'block') {
    $body = substr($body, 0, 4096);
}

// Cabeceras de captura: qué web, qué usuario, cuándo y en qué modo (sin saltos de línea para no colar cabeceras)
$clean = static fn(string $s): string => substr(preg_replace('/[\r\n]+/', ' ', $s), 0, 200);
$meta = "X-MuseDock-Capture-Site: " . $clean($site) . "\r\n"
    . "X-MuseDock-Capture-User: " . $clean($user) . "\r\n"
    . "X-MuseDock-Capture-Mode: {$mode}\r\n"
    . "X-MuseDock-Capture-Time: " . gmdate('c') . "\r\n"
    . ($envFrom !== '' ? "X-MuseDock-Capture-Envelope-From: " . $clean($envFrom) . "\r\n" : '')
    . ($truncated ? "X-MuseDock-Capture-Truncated: yes\r\n" : '');

$name = sprintf('%s/%d-%d-%s.eml', SPOOL, $now, $uid, bin2hex(random_bytes(4)));
$ok = @file_put_contents($name, $meta . $head . "\r\n\r\n" . $body, LOCK_EX) !== false;
if ($ok) {
    @chmod($name, 0640);
}
exit($mode === 'block' ? 1 : ($ok ? 0 : 1));
