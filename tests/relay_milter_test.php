<?php
/** MailService::withRelayMilter: añade el milter de OpenDKIM a los bloques 587 de master.cf que no lo llevan. Función pura. */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\MailService as M;

$ok = 0; $bad = 0;
function check(string $n, bool $c): void { global $ok, $bad; echo ($c ? "  ✓ " : "  ✗ ") . $n . "\n"; $c ? $ok++ : $bad++; }
$m = 'unix:/var/spool/postfix/run/opendkim/opendkim.sock';

$master = "smtp      inet  n       -       y       -       -       smtpd\n"
    . "10.10.70.2:587 inet n - n - - smtpd\n"
    . "  -o syslog_name=postfix/submission\n"
    . "  -o smtpd_sasl_auth_enable=yes\n"
    . "  -o milter_macro_daemon_name=ORIGINATING\n"
    . "\n127.0.0.1:587 inet n - n - - smtpd\n"
    . "  -o syslog_name=postfix/submission-local\n"
    . "  -o milter_macro_daemon_name=ORIGINATING\n"
    . "pickup    unix  n       -       y       60      1       pickup\n";

echo "Reparación\n";
[$new, $ch] = M::withRelayMilter($master, ['127.0.0.1', '10.10.70.2'], $m);
check('cambia los dos bloques', $ch === ['10.10.70.2:587', '127.0.0.1:587']);
check('el milter queda dentro de cada bloque', substr_count($new, "  -o smtpd_milters={$m}") === 2);
check('no toca el servicio smtp ni pickup', str_contains($new, "smtp      inet  n       -       y       -       -       smtpd\n") && str_contains($new, "pickup    unix  n"));
check('el milter va justo antes de la línea siguiente al bloque', (bool)preg_match('/127\.0\.0\.1:587[^\n]*\n(?:[ \t]+[^\n]*\n)*  -o smtpd_milters=' . preg_quote($m, '/') . '\npickup/', $new));

echo "Idempotente\n";
[$new2, $ch2] = M::withRelayMilter($new, ['127.0.0.1', '10.10.70.2'], $m);
check('segunda pasada: no cambia nada', $ch2 === [] && $new2 === $new);

echo "No toca lo que no debe\n";
[$n3, $c3] = M::withRelayMilter($master, ['192.168.9.9'], $m);
check('si la IP no está en master.cf no cambia nada', $c3 === [] && $n3 === $master);
$con = "127.0.0.1:587 inet n - n - - smtpd\n  -o smtpd_milters=unix:/otro/socket\n";
[$n4, $c4] = M::withRelayMilter($con, ['127.0.0.1'], $m);
check('un bloque que ya tiene otro milter no se pisa', $c4 === [] && $n4 === $con);
[$n5, $c5] = M::withRelayMilter("127.0.0.1:2587 inet n - n - - smtpd\n  -o x=1\n", ['127.0.0.1'], $m);
check('otro puerto (2587) no se toca', $c5 === []);

echo "Ruta real del socket de OpenDKIM (distinta en cada servidor)\n";
check('asterisk: /etc/default/opendkim manda sobre opendkim.conf', M::parseOpendkimSocket("SOCKET=\"local:/var/spool/postfix/run/opendkim/opendkim.sock\"\n", "Socket local:/run/opendkim/opendkim.sock\n") === '/var/spool/postfix/run/opendkim/opendkim.sock');
check('obelix: solo opendkim.conf', M::parseOpendkimSocket('', "Syslog yes\nSocket   local:/run/opendkim/opendkim.sock\n") === '/run/opendkim/opendkim.sock');
check('expande $RUNDIR de /etc/default/opendkim', M::parseOpendkimSocket("RUNDIR=/var/run/opendkim\nSOCKET=local:\$RUNDIR/opendkim.sock\n", '') === '/var/run/opendkim/opendkim.sock');
check('un socket de red (inet:) no vale', M::parseOpendkimSocket("SOCKET=inet:8891@localhost\n", '') === null);
check('sin nada configurado = null', M::parseOpendkimSocket('', '') === null);
check('una línea comentada no cuenta', M::parseOpendkimSocket("#SOCKET=local:/x/y.sock\n", "Socket local:/run/opendkim/opendkim.sock\n") === '/run/opendkim/opendkim.sock');
check('los bloques heredan si main.cf ya apunta al socket real', !M::blocksNeedMilterOverride('unix:/run/opendkim/opendkim.sock', '/run/opendkim/opendkim.sock'));
check('hace falta la línea propia si main.cf apunta a otro', M::blocksNeedMilterOverride('unix:/run/opendkim/opendkim.sock', '/var/spool/postfix/run/opendkim/opendkim.sock'));

echo "Selector DKIM que firma un dominio y arreglos manuales\n";
$st = "*@otro.com default._domainkey.otro.com\n*@muserelay.com satelite._domainkey.muserelay.com\n";
check('lee el selector de signing.table', M::existingSigningSelector($st, 'muserelay.com') === 'satelite');
check('dominio que no está = null', M::existingSigningSelector($st, 'nuevo.com') === null);
check('no confunde dominios que terminan igual', M::existingSigningSelector("*@sub.muserelay.com default._domainkey.sub.muserelay.com\n", 'muserelay.com') === null);
check('selector distinto con clave presente → se conserva (arreglo manual)', M::keepManualDkimSelector('satelite', 'default', true));
check('mismo selector → se aplica normal', !M::keepManualDkimSelector('default', 'default', true));
check('selector distinto pero sin su clave → se aplica (estaría roto)', !M::keepManualDkimSelector('satelite', 'default', false));
check('dominio sin entrada → se aplica', !M::keepManualDkimSelector(null, 'default', false));

echo "\n" . ($bad ? "$bad fallo(s) de " . ($ok + $bad) : "Todo correcto: $ok comprobaciones") . "\n";
exit($bad ? 1 : 0);
