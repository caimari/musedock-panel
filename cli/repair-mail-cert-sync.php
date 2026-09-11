<?php
/** Repair Caddy -> Postfix/Dovecot certificate propagation after an update. */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Settings;
use MuseDockPanel\Services\MailService;

if (!is_file('/etc/postfix/main.cf') || !is_file('/etc/dovecot/dovecot.conf')) {
    echo "[repair-mail-cert] SKIP: this node is not a mail server\n";
    exit(0);
}

if (trim((string)shell_exec('systemctl is-active caddy 2>/dev/null')) !== 'active') {
    echo "[repair-mail-cert] SKIP: Caddy is not active (certbot-managed node)\n";
    exit(0);
}

$hostname = trim((string)(Settings::get('mail_local_hostname', '')
    ?: Settings::get('mail_hostname', '')
    ?: Settings::get('mail_setup_hostname', '')));

if ($hostname === '') {
    echo "[repair-mail-cert] SKIP: no mail hostname configured\n";
    exit(0);
}

$result = MailService::ensureMailCertViaCaddy($hostname, 15);
if (empty($result['ok'])) {
    fwrite(STDERR, '[repair-mail-cert] ERROR: ' . ($result['error'] ?? 'unknown error') . "\n");
    exit(1);
}

shell_exec('postconf -e ' . escapeshellarg('smtpd_tls_cert_file = ' . $result['cert']));
shell_exec('postconf -e ' . escapeshellarg('smtpd_tls_key_file = ' . $result['key']));
file_put_contents('/etc/dovecot/conf.d/10-ssl.conf',
    "ssl = required\nssl_cert = <{$result['cert']}\nssl_key = <{$result['key']}\n"
    . "ssl_min_protocol = TLSv1.2\nssl_prefer_server_ciphers = yes\n");
shell_exec('systemctl reload postfix dovecot 2>/dev/null');

echo "[repair-mail-cert] OK: {$hostname} -> {$result['cert']}\n";
