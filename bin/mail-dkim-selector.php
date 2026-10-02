<?php
/**
 * Cambia el selector DKIM de dominios de correo (misma clave), para convivir con
 * otro remitente que ya publica su clave en default._domainkey. Reinstala en
 * OpenDKIM aquí y en las réplicas. No toca el DNS (después: mail_dns_publish).
 * Equivale a la herramienta MCP mail_dkim_selector.
 *
 * Uso (como root):
 *   php /opt/musedock-panel/bin/mail-dkim-selector.php musedock dominio1.com [dominio2.com ...]
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\MailService;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$selector = $argv[1] ?? '';
$domains = array_slice($argv, 2);
if ($selector === '' || !$domains) {
    fwrite(STDERR, "Uso: php bin/mail-dkim-selector.php <selector> dominio1.com [dominio2.com ...]\n");
    exit(1);
}
$rc = 0;
foreach ($domains as $dom) {
    $r = MailService::setDkimSelector($dom, $selector);
    if (empty($r['ok'])) {
        echo "ERROR {$dom}: {$r['error']}\n";
        $rc = 1;
        continue;
    }
    echo "OK    {$r['domain']}: selector {$r['old']} → {$r['new']} (réplicas avisadas: {$r['replicas']})\n";
}
exit($rc);
