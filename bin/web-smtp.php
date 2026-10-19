#!/usr/bin/env php
<?php
/**
 * SMTP autenticado para las webs (que la aplicación mande identificándose en el servidor de correo del panel).
 *
 *   php bin/web-smtp.php --domain=ejemplo.com             plan: qué se haría (no cambia nada)
 *   php bin/web-smtp.php --domain=ejemplo.com --apply     lo aplica (como root): cuenta de correo, credenciales y configuración de la app
 *   php bin/web-smtp.php --domain=ejemplo.com --apply --then=capture|block|keep   qué pasa después con el envío local sin autenticar (por defecto: capture)
 *   php bin/web-smtp.php --domain=ejemplo.com --remove    quita el mu-plugin y las credenciales (la cuenta de correo se deja)
 *   php bin/web-smtp.php --report [--json]                informe de todas las webs (lo mismo que /mail/web-audit)
 *   php bin/web-smtp.php --default-domain=musedock.com    dominio de correo con el que firman las webs que no tienen el suyo
 */
declare(strict_types=1);

define('PANEL_ROOT', dirname(__DIR__));
require_once PANEL_ROOT . '/app/bootstrap.php';

use MuseDockPanel\Database;
use MuseDockPanel\Services\WebMailAuditService;
use MuseDockPanel\Services\WebSmtpService;

$o = getopt('', ['domain:', 'apply', 'remove', 'then:', 'report', 'json', 'default-domain:']);

if (isset($o['default-domain'])) {
    $d = strtolower(trim((string)$o['default-domain']));
    if (!\MuseDockPanel\Services\MailService::getDomainByName($d)) {
        fwrite(STDERR, "{$d} no es un dominio de correo de este panel.\n");
        exit(1);
    }
    \MuseDockPanel\Settings::set('web_smtp_default_domain', $d);
    echo "Dominio de correo por defecto para el SMTP de las webs: {$d}\n";
    if (!isset($o['domain']) && !isset($o['report'])) {
        exit(0);
    }
}

if (isset($o['report'])) {
    $r = WebMailAuditService::report(true);
    if (isset($o['json'])) {
        echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    }
    printf("%-30s %5s %5s %5s  %-10s %-9s %s\n", 'Web', 'Msgs', 'Env.', 'Reb.', 'SMTP', 'Local', 'Propuesta');
    foreach ($r['hostings'] as $h) {
        $smtp = $h['smtp']['managed'] ? 'panel' : ($h['smtp']['smtp_plugin'] ?: ($h['smtp']['laravel_smtp'] ? '.env' : 'no'));
        printf("%-30s %5d %5d %5d  %-10s %-9s %s\n", $h['domain'], $h['messages'], $h['sent'], $h['bounced'], $smtp, $h['mode'], $h['proposal']);
    }
    echo "\nCorreo del sistema (no webs):\n";
    foreach ($r['system'] as $s) {
        printf("  %-20s %5d msgs, %d enviados, %d rebotados, %d aplazados\n", "{$s['name']} ({$s['uid']})", $s['messages'], $s['sent'], $s['bounced'], $s['deferred']);
    }
    exit(0);
}

$domain = strtolower(trim((string)($o['domain'] ?? '')));
if ($domain === '') {
    fwrite(STDERR, "Falta --domain=ejemplo.com (o usa --report).\n");
    exit(2);
}
$acc = Database::fetchOne("SELECT * FROM hosting_accounts WHERE lower(domain) = :d AND status = 'active'", ['d' => $domain]);
if (!$acc) {
    fwrite(STDERR, "No hay un hosting activo con el dominio {$domain}.\n");
    exit(1);
}
if (isset($o['remove'])) {
    $r = WebSmtpService::remove($acc);
    echo "Quitados {$r['removed']} ficheros. {$r['note']}\n";
    exit(0);
}
$then = in_array($o['then'] ?? 'capture', ['capture', 'block', 'keep'], true) ? (string)($o['then'] ?? 'capture') : 'capture';
$r = WebSmtpService::provision($acc, isset($o['apply']), $then);
foreach ($r['steps'] as $s) {
    echo " - {$s}\n";
}
if (!$r['ok']) {
    fwrite(STDERR, "ERROR: {$r['error']}\n");
    exit(1);
}
echo isset($o['apply']) ? "\nHecho. La contraseña solo está en el fichero de credenciales del hosting.\n" : "\nSolo se ha simulado. Para aplicarlo (como root): php bin/web-smtp.php --domain={$domain} --apply\n";
