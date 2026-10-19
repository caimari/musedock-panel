#!/usr/bin/env php
<?php
/**
 * Correo de las webs (PHP): captura, bloqueo y permitir. Sin red, sin tocar Postfix ni PHP-FPM:
 * lógica pura + el programa de captura contra una carpeta temporal.
 * Usage: php tests/mail_capture_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

use MuseDockPanel\Services\MailCaptureService as C;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }

echo "\n\033[1mDirectiva del pool\033[0m\n";
ok('permitir = sin directiva', C::sendmailPath('ejemplo.com', 'allow') === null);
ok('capturar apunta al programa con la web y el modo', C::sendmailPath('Ejemplo.com', 'capture') === '/usr/local/sbin/musedock-mail-capture --site=ejemplo.com --mode=capture');
ok('bloquear', str_ends_with((string)C::sendmailPath('a.b', 'block'), '--mode=block'));
ok('un dominio con caracteres raros se limpia (nada se cuela en el pool)', C::sendmailPath("x.com\nphp_admin_value[evil]=1;", 'capture') === '/usr/local/sbin/musedock-mail-capture --site=x.comphpadminvalueevil1 --mode=capture');
ok('un modo inventado no genera nada', C::sendmailPath('a.com', 'whatever') === null);

echo "\n\033[1mLeer y escribir el pool\033[0m\n";
$pool = "[web]\nuser = web\nphp_admin_value[open_basedir] = /x/\n\nsecurity.limit_extensions = .php\n";
ok('un pool sin directiva está en «permitir»', C::modeFromPool($pool) === 'allow');
[$cap, $e] = C::poolWithMode($pool, 'ejemplo.com', 'capture');
ok('capturar añade la línea antes de security.limit_extensions', $e === '' && strpos($cap, 'sendmail_path') < strpos($cap, 'security.limit_extensions') && C::modeFromPool($cap) === 'capture', (string)$cap);
[$blk] = C::poolWithMode($cap, 'ejemplo.com', 'block');
ok('de capturar a bloquear sustituye la línea (no la duplica)', C::modeFromPool($blk) === 'block' && substr_count($blk, 'sendmail_path') === 1);
[$back] = C::poolWithMode($blk, 'ejemplo.com', 'allow');
ok('volver a permitir quita la línea y deja el pool como el original', $back === $pool, (string)$back);
$custom = $pool . "php_admin_value[sendmail_path] = /usr/bin/msmtp -t\n";
ok('un sendmail_path propio (msmtp) se detecta', C::modeFromPool($custom) === 'custom');
[$null, $err] = C::poolWithMode($custom, 'ejemplo.com', 'capture');
ok('un sendmail_path propio NO se pisa y se explica', $null === null && str_contains($err, 'no se pisa'), $err);
ok('el resto del pool queda intacto', str_contains((string)$cap, 'php_admin_value[open_basedir] = /x/') && str_contains((string)$cap, 'user = web'));

echo "\n\033[1mPrograma de captura (carpeta temporal)\033[0m\n";
$tmp = sys_get_temp_dir() . '/mc-test-' . bin2hex(random_bytes(3));
mkdir($tmp, 0700);
$bin = escapeshellarg(PANEL_ROOT . '/bin/musedock-mail-capture.php');
$run = function (string $args, string $stdin) use ($tmp, $bin): int {
    $p = proc_open("MUSEDOCK_MAIL_CAPTURE_SPOOL=" . escapeshellarg($tmp) . " php {$bin} {$args}", [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin); fclose($pipes[0]);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    return proc_close($p);
};
$msg = "To: cliente@example.org\r\nSubject: Hola\r\nFrom: wp@ejemplo.com\r\n\r\nCuerpo del mensaje\r\n";
ok('capturar: devuelve éxito a PHP', $run('--site=ejemplo.com --mode=capture -t -i', $msg) === 0);
$files = glob($tmp . '/*.eml');
$raw = (string)file_get_contents($files[0] ?? '/nonexistent');
ok('se guarda un .eml con la web, el usuario y el modo', count($files) === 1 && str_contains($raw, 'X-MuseDock-Capture-Site: ejemplo.com') && str_contains($raw, 'X-MuseDock-Capture-Mode: capture') && str_contains($raw, 'X-MuseDock-Capture-User:'));
ok('lleva el mensaje entero', str_contains($raw, 'Cuerpo del mensaje') && str_contains($raw, 'To: cliente@example.org'));
$h = C::parseHead($raw);
ok('parseHead lee web, destinatario y asunto', ($h['x-musedock-capture-site'] ?? '') === 'ejemplo.com' && ($h['to'] ?? '') === 'cliente@example.org' && ($h['subject'] ?? '') === 'Hola');
ok('bloquear: devuelve ERROR a PHP', $run('--site=ejemplo.com --mode=block -t -i', $msg . str_repeat('a', 9000)) === 1);
$blocked = array_values(array_filter(glob($tmp . '/*.eml'), fn($f) => str_contains((string)file_get_contents($f), 'Mode: block')));
ok('bloquear guarda solo la cabecera y 4 KB', count($blocked) === 1 && filesize($blocked[0]) < 5200, (string)filesize($blocked[0] ?? ''));
ok('un mensaje vacío no deja nada', $run('--site=x.com --mode=capture', '') === 0 && count(glob($tmp . '/*.eml')) === 2);
ok('una cabecera con saltos de línea en --site no cuela cabeceras', $run("--site=a.com --mode=capture", $msg) === 0
    && !preg_match('/\nX-Injected/i', implode('', array_map('file_get_contents', glob($tmp . '/*.eml')))));
// tope por usuario
$uid = posix_geteuid();
file_put_contents($tmp . '/.rate-' . $uid, implode("\n", array_fill(0, 130, time())));
ok('pasado el tope por hora se descarta y PHP recibe error', $run('--site=ejemplo.com --mode=capture', $msg) === 1);
ok('safeName solo admite nombres de captura (nada de «../»)', C::safeName('1792397998-1000-f650106a.eml') && !C::safeName('../etc/passwd') && !C::safeName('a.eml'));
foreach (array_merge(glob($tmp . '/*') ?: [], glob($tmp . '/.rate-*') ?: []) as $f) { @unlink($f); }
@rmdir($tmp);

echo "\n\033[1mAuditoría\033[0m\n";
$log = [
    'Oct 19 08:00:00 h postfix/pickup[1]: AAAAAA11: uid=1006 from=<wp@mail.example>',
    'Oct 19 08:00:01 h postfix/smtp[2]: AAAAAA11: to=<x@gmail.com>, relay=gmail-smtp-in.l.google.com[1.2.3.4]:25, status=bounced (550 5.7.26)',
    'Oct 19 08:01:00 h postfix/pickup[1]: BBBBBB22: uid=1006 from=<wp@mail.example>',
    'Oct 19 08:01:01 h postfix/smtp[2]: BBBBBB22: to=<y@gmail.com>, relay=x, status=sent (250 ok)',
    'Oct 19 08:02:00 h postfix/pickup[1]: CCCCCC33: uid=0 from=<root>',
    'Oct 19 08:02:01 h postfix/local[3]: CCCCCC33: to=<root@host>, status=sent (delivered to mailbox)',
    'Oct 19 08:03:00 h postfix/smtpd[9]: DDDDDD44: client=unknown[1.1.1.1]',   // entra por SMTP, no por sendmail: no cuenta
];
$a = C::auditParse($log);
ok('cuenta por usuario local (uid del pickup)', isset($a[1006], $a[0]) && !isset($a[33]) && count($a) === 2);
ok('mensajes, destinatarios, enviados y rebotados del usuario', $a[1006]['messages'] === 2 && $a[1006]['recipients'] === 2 && $a[1006]['sent'] === 1 && $a[1006]['bounced'] === 1, json_encode($a[1006]));
ok('dominios de destino y remitente', ($a[1006]['domains']['gmail.com'] ?? 0) === 2 && ($a[1006]['froms']['wp@mail.example'] ?? 0) === 2);
ok('el de más mensajes sale primero', array_key_first($a) === 1006);

echo "\n\033[1mEl valor va entre comillas (filemon, 2026-10-19: sin ellas PHP-FPM rechazaba el pool)\033[0m\n";
ok('la línea escrita lleva el valor entre comillas dobles', str_contains((string)$cap, 'php_admin_value[sendmail_path] = "/usr/local/sbin/musedock-mail-capture --site=ejemplo.com --mode=capture"'), (string)$cap);
$ini = parse_ini_string("[web]\n" . trim(substr((string)$cap, strpos((string)$cap, 'php_admin_value[sendmail_path]'), 140)), true, INI_SCANNER_RAW);
$ini2 = @parse_ini_string("[web]\nphp_admin_value[sendmail_path] = \"/usr/local/sbin/musedock-mail-capture --site=ejemplo.com --mode=capture\"\n", true);
ok('el analizador de ficheros .ini lee el valor completo, con el «=» de --site=', is_array($ini2) && ($ini2['web']['php_admin_value']['sendmail_path'] ?? '') === '/usr/local/sbin/musedock-mail-capture --site=ejemplo.com --mode=capture', json_encode($ini2));
ok('el modo se sigue leyendo con comillas (y sin ellas, de pools antiguos)', C::modeFromPool($cap) === 'capture' && C::modeFromPool("php_admin_value[sendmail_path] = /usr/local/sbin/musedock-mail-capture --site=a.com --mode=block\n") === 'block');
[$again] = C::poolWithMode($cap, 'ejemplo.com', 'block');
ok('cambiar de modo con comillas sustituye la línea sin duplicarla', substr_count($again, 'sendmail_path') === 1 && C::modeFromPool($again) === 'block');

echo "\n\033[1mAuditoría: entregado a otro nodo no es entrega final\033[0m\n";
$logR = [
    'Oct 19 08:00:00 h postfix/pickup[1]: DDDDDD11: uid=1006 from=<filmsinfestcom>',
    'Oct 19 08:00:01 h postfix/smtp[2]: DDDDDD11: to=<x@gmail.com>, relay=10.10.70.2[10.10.70.2]:25, delay=1, status=sent (250 2.0.0 Ok: queued as ABC)',
    'Oct 19 08:01:00 h postfix/pickup[1]: EEEEEE22: uid=1006 from=<filmsinfestcom>',
    'Oct 19 08:01:01 h postfix/smtp[2]: EEEEEE22: to=<y@gmail.com>, relay=gmail-smtp-in.l.google.com[142.250.1.27]:25, status=sent (250 ok)',
];
$rr = C::auditParse($logR);
ok('un envío a una IP interna cuenta como «entregado al relé»; a Gmail directo, no', $rr[1006]['web_relayed'] === 1 && $rr[1006]['web_sent'] === 2, json_encode($rr[1006]));

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
