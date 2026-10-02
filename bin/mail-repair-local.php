<?php
/**
 * Rehace en este servidor los ficheros de dominios de correo a partir de la BD
 * (carpeta del dominio, clave DKIM + tablas de OpenDKIM, Maildir y cuota de cada
 * buzón). No toca la BD ni el DNS. Idempotente: se puede repetir sin riesgo.
 *
 * Uso (como root):
 *   php /opt/musedock-panel/bin/mail-repair-local.php dominio1.com [dominio2.com ...]
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\MailService;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$domains = array_slice($argv, 1);
if (!$domains) {
    fwrite(STDERR, "Uso: php bin/mail-repair-local.php dominio1.com [dominio2.com ...]\n");
    exit(1);
}
$rc = 0;
foreach ($domains as $dom) {
    $r = MailService::repairLocalFiles($dom);
    if (empty($r['ok'])) {
        echo "ERROR {$dom}: {$r['error']}\n";
        $rc = 1;
        continue;
    }
    echo "OK    {$r['domain']}: " . ($r['repaired'] ? 'rehecho ' . implode(', ', $r['repaired']) : 'ya estaba bien') . "\n";
}
exit($rc);
