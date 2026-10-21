<?php
/** MailService::dkimPublicFromPrivate: la pública p= calculada de la privada PEM (sin tocar nada). */
require_once dirname(__DIR__) . '/app/bootstrap.php';
use MuseDockPanel\Services\MailService as M;
$ok = 0; $bad = 0;
function check(string $n, bool $c): void { global $ok, $bad; echo ($c ? "  ✓ " : "  ✗ ") . $n . "\n"; $c ? $ok++ : $bad++; }

$k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($k, $pem);
$expected = preg_replace('/\s+|-----[A-Z ]+-----/', '', openssl_pkey_get_details($k)['key']);
check('de una privada PEM sale la pública esperada', M::dkimPublicFromPrivate($pem) === $expected);
check('empieza como una clave RSA de DKIM (MIIBIjANBgkq…)', str_starts_with(M::dkimPublicFromPrivate($pem), 'MIIBIjANBgkq'));
check('sin saltos de línea ni cabeceras PEM', !preg_match('/\s|-----/', M::dkimPublicFromPrivate($pem)));
check('vacío → vacío', M::dkimPublicFromPrivate('') === '');
check('basura → vacío', M::dkimPublicFromPrivate('esto no es una clave') === '');
check('una PEM rota → vacío', M::dkimPublicFromPrivate("-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----") === '');
echo "\n" . ($bad ? "$bad fallo(s) de " . ($ok + $bad) : "Todo correcto: $ok comprobaciones") . "\n";
exit($bad ? 1 : 0);
