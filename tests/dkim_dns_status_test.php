<?php
/** Estado DKIM-contra-DNS, veredicto de opendkim-testkey y ruta de la clave privada. Funciones puras. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
use MuseDockPanel\Services\MailService as M;
$ok = 0; $bad = 0;
function check(string $n, bool $c): void { global $ok, $bad; echo ($c ? "  ✓ " : "  ✗ ") . $n . "\n"; $c ? $ok++ : $bad++; }

$pub  = str_repeat('A', 120);
$otra = str_repeat('B', 120);
echo "Estado contra el DNS\n";
check('misma clave → ok', M::dkimDnsState($pub, "\"v=DKIM1; k=rsa; p={$pub}\"") === 'ok');
check('TXT partido en trozos con comillas → ok', M::dkimDnsState($pub, "\"v=DKIM1; k=rsa; p=" . substr($pub, 0, 60) . "\" \"" . substr($pub, 60) . "\"") === 'ok');
check('el panel guarda el TXT entero, no solo p= → ok', M::dkimDnsState("v=DKIM1; k=rsa; p={$pub}", "v=DKIM1; k=rsa; p={$pub}") === 'ok');
check('el DNS publica otra clave → mismatch (screenart.es)', M::dkimDnsState($pub, "v=DKIM1; k=rsa; p={$otra}") === 'mismatch');
check('el DNS no tiene nada → nodns', M::dkimDnsState($pub, '') === 'nodns');
check('el panel no tiene clave pública → nokey', M::dkimDnsState('', "v=DKIM1; p={$pub}") === 'nokey');

echo "Veredicto de opendkim-testkey\n";
check('con -k y todo bien → ok', M::testkeyVerdict("opendkim-testkey: key loaded from /x.private\nopendkim-testkey: checking key 'default._domainkey.a.com'\nopendkim-testkey: key not secure\nopendkim-testkey: key OK\n") === 'ok');
check('«keys do not match» → mismatch (aunque salga «key not secure»)', M::testkeyVerdict("opendkim-testkey: key not secure\nopendkim-testkey: keys do not match\n") === 'mismatch');
check('clave no encontrada en el DNS → error', M::testkeyVerdict("opendkim-testkey: 'default._domainkey.a.com' record not found\nopendkim-testkey: key not found") === 'error');
check('sin salida útil → unknown', M::testkeyVerdict('') === 'unknown');

echo "Ruta de la clave privada\n";
$kt = "default._domainkey.screenart.es screenart.es:default:/etc/opendkim/keys/screenart.es/default.private\nsatelite._domainkey.muserelay.com muserelay.com:satelite:/etc/opendkim/keys/muserelay.com/satelite.private\n";
check('lee la ruta de key.table', M::dkimKeyFile('screenart.es', 'default', $kt) === '/etc/opendkim/keys/screenart.es/default.private');
check('con un selector distinto (satelite)', M::dkimKeyFile('muserelay.com', 'satelite', $kt) === '/etc/opendkim/keys/muserelay.com/satelite.private');
check('si no está en la tabla usa la ruta habitual', M::dkimKeyFile('otro.com', 'default', $kt) === '/etc/opendkim/keys/otro.com/default.private');

echo "\n" . ($bad ? "$bad fallo(s) de " . ($ok + $bad) : "Todo correcto: $ok comprobaciones") . "\n";
exit($bad ? 1 : 0);
