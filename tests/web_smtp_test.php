#!/usr/bin/env php
<?php
/**
 * SMTP autenticado de las webs (WebSmtpService) y propuesta de la auditoría. Lógica pura: no crea cuentas ni toca webs.
 * Usage: php tests/web_smtp_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

use MuseDockPanel\Services\WebSmtpService as W;
use MuseDockPanel\Services\WebMailAuditService as A;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }

echo "\n\033[1mCuenta y credenciales\033[0m\n";
ok('la cuenta se llama web-<usuario> sin caracteres raros', W::localPart('filmsinfestcom') === 'web-filmsinfestcom' && W::localPart('A_b-c.d') === 'web-abcd' && W::localPart('') === 'web-site');
ok('el nombre no pasa de 44 caracteres', strlen(W::localPart(str_repeat('a', 80))) === 44);
$p1 = W::generatePassword(); $p2 = W::generatePassword();
ok('la contraseña es larga, distinta cada vez y apta para un .env', strlen($p1) >= 30 && $p1 !== $p2 && preg_match('/^[A-Za-z0-9]+$/', $p1) === 1, $p1);

echo "\n\033[1mmu-plugin de WordPress\033[0m\n";
$src = W::muPluginSource('/var/www/vhosts/ejemplo.com/.musedock-smtp.php');
$tmp = tempnam(sys_get_temp_dir(), 'mu') . '.php';
file_put_contents($tmp, $src);
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
ok('el código generado es PHP válido', $rc === 0, implode(' ', $o));
ok('lee las credenciales de un fichero fuera de la web, no las lleva dentro', str_contains($src, ".musedock-smtp.php") && !str_contains($src, 'password'));

// Se ejecuta con un PHPMailer de mentira y un fichero de credenciales de prueba
class FakeMailer {
    public $From = 'wordpress@filmsinfest.com'; public $FromName = 'Web'; public $Host, $Port, $SMTPAuth, $Username, $Password, $SMTPSecure, $SMTPAutoTLS, $Timeout, $Sender, $smtp = false; public $reply = [];
    public function isSMTP() { $this->smtp = true; }
    public function getReplyToAddresses() { return $this->reply; }
    public function addReplyTo($a, $n = '') { $this->reply[$a] = [$a, $n]; }
    public function setFrom($a, $n = '', $auto = true) { $this->From = $a; $this->FromName = $n; }
}
$cred = tempnam(sys_get_temp_dir(), 'cr') . '.php';
file_put_contents($cred, "<?php return " . var_export(['host' => 'mail.musedock.com', 'port' => 587, 'user' => 'web-x@musedock.com', 'pass' => 'secreto'], true) . ";");
$hook = null;
function add_action($n, $cb) { global $hook; $hook = $cb; }
file_put_contents($tmp, W::muPluginSource($cred));
require $tmp;
$m = new FakeMailer();
$hook($m);
ok('configura SMTP con STARTTLS, usuario y contraseña de la cuenta', $m->smtp && $m->Host === 'mail.musedock.com' && $m->Port === 587 && $m->SMTPAuth === true && $m->SMTPSecure === 'tls' && $m->Username === 'web-x@musedock.com' && $m->Password === 'secreto');
ok('el remitente del sobre es la cuenta autenticada', $m->Sender === 'web-x@musedock.com');
ok('un origen de otro dominio se reescribe a la cuenta y el original va en Reply-To (el DKIM alinea)', $m->From === 'web-x@musedock.com' && isset($m->reply['wordpress@filmsinfest.com']));
$m2 = new FakeMailer(); $m2->From = 'hola@musedock.com';
$hook($m2);
ok('un origen del mismo dominio se deja como está', $m2->From === 'hola@musedock.com' && $m2->reply === []);
file_put_contents($cred, '<?php return [];');
$m3 = new FakeMailer(); $hook($m3);
ok('sin credenciales válidas WordPress sigue como estaba (no rompe el envío)', $m3->smtp === false);
@unlink($tmp); @unlink($cred);

echo "\n\033[1m.env de Laravel\033[0m\n";
$c = ['host' => 'mail.musedock.com', 'port' => 587, 'user' => 'web-x@musedock.com', 'pass' => 'a$b'];
$env = "APP_NAME=App\nMAIL_MAILER=log\nMAIL_HOST=127.0.0.1\nMAIL_PORT=2525\n";
$out = W::applyEnv($env, $c);
ok('sustituye las claves que hay y añade las que faltan', str_contains($out, 'MAIL_MAILER="smtp"') && str_contains($out, 'MAIL_HOST="mail.musedock.com"') && str_contains($out, 'MAIL_PORT="587"') && str_contains($out, 'MAIL_USERNAME="web-x@musedock.com"') && str_contains($out, 'MAIL_ENCRYPTION="tls"') && !str_contains($out, 'MAIL_MAILER=log'), $out);
ok('una contraseña con $ no se rompe', str_contains($out, 'MAIL_PASSWORD="a$b"'), $out);
ok('respeta el resto del .env y se marca como gestionado', str_contains($out, 'APP_NAME=App') && str_contains($out, '# musedock-smtp'));
ok('aplicarlo dos veces no duplica nada', substr_count(W::applyEnv($out, $c), 'MAIL_HOST=') === 1 && substr_count(W::applyEnv($out, $c), '# musedock-smtp') === 1);

echo "\n\033[1mPropuesta de la auditoría\033[0m\n";
$base = ['messages' => 0, 'bounced' => 0, 'mode' => 'allow', 'smtp' => ['managed' => false, 'smtp_plugin' => null, 'laravel_smtp' => false], 'dns' => ['spf' => 'v=spf1', 'dmarc' => 'v=DMARC1']];
ok('web que no manda: sin acción', str_contains(A::propose($base), 'Sin acción'));
ok('web que manda sin autenticar y rebota: configurar SMTP', str_contains(A::propose(['messages' => 90, 'bounced' => 90] + $base), 'configura su SMTP autenticado') && str_contains(A::propose(['messages' => 90, 'bounced' => 90] + $base), '90 se han rechazado'));
ok('web con SMTP del panel y envío local en permitir: cerrar el local', str_contains(A::propose(['messages' => 3, 'smtp' => ['managed' => true] + $base['smtp']] + $base), 'capturar'));
ok('web con plugin SMTP propio: comprobar y bloquear', str_contains(A::propose(['messages' => 3, 'smtp' => ['smtp_plugin' => 'wp-mail-smtp'] + $base['smtp']] + $base), 'wp-mail-smtp'));
ok('avisa si el dominio no tiene SPF ni DMARC', str_contains(A::propose(['messages' => 5, 'dns' => ['spf' => null, 'dmarc' => null]] + $base), 'no tiene SPF') && str_contains(A::propose(['messages' => 5, 'dns' => ['spf' => null, 'dmarc' => null]] + $base), 'no tiene DMARC'));

echo "\n\033[1mMotivos de rechazo en el registro\033[0m\n";
$log = [
    'Oct 19 08:00:00 h postfix/pickup[1]: AAAAAA11: uid=1006 from=<wp@mail.example>',
    'Oct 19 08:00:01 h postfix/smtp[2]: AAAAAA11: to=<x@gmail.com>, relay=gmail-smtp-in.l.google.com[1.2.3.4]:25, delay=1, status=bounced (host gmail-smtp-in.l.google.com said: 550-5.7.26 Your email has been blocked because the sender is unauthenticated. (in reply to end of DATA command))',
];
$a = \MuseDockPanel\Services\MailCaptureService::auditParse($log);
ok('extrae el código 5.7.26 de Gmail', ($a[1006]['reasons']['5.7.26'] ?? 0) === 1, json_encode($a[1006]['reasons'] ?? []));

echo "\n\033[1mCorreo del sistema frente a correo de webs\033[0m\n";
$log2 = [
    'Oct 19 08:00:00 h postfix/pickup[1]: C0000001: uid=1006 from=<filmsinfestcom>',
    'Oct 19 08:00:01 h postfix/local[2]: C0000001: to=<filmsinfestcom@mail.musedock.com>, relay=local, status=sent (delivered)',
    'Oct 19 08:01:00 h postfix/pickup[1]: C0000002: uid=1006 from=<filmsinfestcom>',
    'Oct 19 08:01:01 h postfix/smtp[2]: C0000002: to=<x@gmail.com>, relay=gmail-smtp-in.l.google.com[1.2.3.4]:25, status=bounced (host gmail-smtp-in.l.google.com said: 550-5.7.26 unauthenticated)',
];
$b = \MuseDockPanel\Services\MailCaptureService::auditParse($log2, ['mail.musedock.com']);
ok('el mensaje solo para este servidor es del sistema y el de gmail.com es de la web', $b[1006]['sys_messages'] === 1 && $b[1006]['web_messages'] === 1 && $b[1006]['web_bounced'] === 1 && $b[1006]['web_sent'] === 0, json_encode($b[1006]));
ok('los motivos de rechazo de la web no incluyen los del sistema', ($b[1006]['web_reasons']['5.7.26'] ?? 0) === 1);
$c = \MuseDockPanel\Services\MailCaptureService::auditParse($log2, []);
ok('sin lista de dominios locales, solo «local» puro cuenta como sistema', $c[1006]['sys_messages'] === 0);

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
