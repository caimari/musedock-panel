#!/usr/bin/env php
<?php
/**
 * Quién manda correo por el Postfix local (sendmail): solo lectura del registro de Postfix.
 *
 *   php bin/mail-audit.php [/var/log/mail.log ...]     por defecto, mail.log y mail.log.1
 *
 * Por cada usuario local (un hosting, www-data, root…): mensajes, destinatarios, resultado (enviado / rebotado / aplazado),
 * remitentes y dominios de destino. Sirve para saber qué webs mandan correo antes de ponerlas en «capturar» o «bloquear».
 */
declare(strict_types=1);

define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

use MuseDockPanel\Services\MailCaptureService as C;

$files = array_slice($argv, 1) ?: ['/var/log/mail.log.1', '/var/log/mail.log'];
$gen = (static function () use ($files) {
    foreach ($files as $f) {
        if (is_readable($f)) {
            foreach (new SplFileObject($f) as $line) {
                yield (string)$line;
            }
        } else {
            fwrite(STDERR, "No se puede leer {$f}\n");
        }
    }
})();

$rows = C::auditParse($gen);
if (!$rows) {
    echo "No hay correo entrando por sendmail en los registros leídos.\n";
    exit(0);
}
printf("%-22s %6s %6s %6s %6s %6s  %s\n", 'Usuario (uid)', 'Msgs', 'Dest.', 'Env.', 'Reb.', 'Apl.', 'Remitentes / destinos principales');
foreach ($rows as $r) {
    $name = function_exists('posix_getpwuid') ? (posix_getpwuid($r['uid'])['name'] ?? '?') : '?';
    arsort($r['froms']);
    arsort($r['domains']);
    printf("%-22s %6d %6d %6d %6d %6d  %s → %s\n", "{$name} ({$r['uid']})", $r['messages'], $r['recipients'], $r['sent'], $r['bounced'], $r['deferred'],
        implode(', ', array_slice(array_keys($r['froms']), 0, 2)), implode(', ', array_slice(array_keys($r['domains']), 0, 3)));
}
echo "\nUsuarios con muchos «Reb.» (rebotados) y destinos como gmail.com son los que Gmail rechaza por falta de SPF/DKIM.\n";
