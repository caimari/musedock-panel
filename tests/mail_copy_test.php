<?php
/** «Enviar copia a…»: partes puras (consulta, conf, valor de recipient_bcc_maps, validación). No toca Postfix ni la base. */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\MailPolicyService as P;

$ok = 0; $bad = 0;
function check(string $n, bool $c): void { global $ok, $bad; echo ($c ? "  ✓ " : "  ✗ ") . $n . "\n"; $c ? $ok++ : $bad++; }

echo "Validación de la dirección de copia\n";
check('una dirección válida pasa (y en minúsculas)', P::normalizeCopyTo(' Master@MuseDock.com ', 'support@muserelay.com') === 'master@musedock.com');
check('vacío = sin copia', P::normalizeCopyTo('', 'a@b.com') === '');
check('no válida = sin copia', P::normalizeCopyTo('esto no es un correo', 'a@b.com') === '');
check('copiarse a sí mismo no vale', P::normalizeCopyTo('A@B.com', 'a@b.com') === '');

echo "Consulta del mapa\n";
$q = P::copyMapQuery();
check('consulta por el correo exacto y solo buzones activos', str_contains($q, "email = '%s'") && str_contains($q, "status = 'active'"));
check('ignora los vacíos (sin copia)', str_contains($q, "COALESCE(copy_to, '') <> ''"));
check('devuelve una sola fila', str_contains($q, 'LIMIT 1'));

echo "Fichero .cf (credenciales del mapa de remitentes)\n";
$sender = "hosts = 127.0.0.1:5433\nuser = musedock_mail\npassword = secreto\ndbname = musedock_panel\nquery = SELECT 1\n";
$conf = P::copyMapConf($sender);
check('copia hosts, user, password y dbname', $conf !== null && str_contains($conf, 'hosts = 127.0.0.1:5433') && str_contains($conf, 'password = secreto') && str_contains($conf, 'dbname = musedock_panel'));
check('la consulta es la de copias, no la de remitentes', $conf !== null && str_contains($conf, 'copy_to') && !str_contains($conf, 'SELECT 1'));
check('sin contraseña no se escribe nada (rompería la entrega)', P::copyMapConf("hosts = 127.0.0.1\nuser = x\npassword =\ndbname = d\n") === null);
check('sin base de datos no se escribe nada', P::copyMapConf("hosts = 127.0.0.1\nuser = x\npassword = p\n") === null);

echo "Valor de recipient_bcc_maps\n";
$ours = 'pgsql:' . P::COPY_MAP_CF;
check('vacío → solo el nuestro', P::withCopyMap('') === $ours);
check('con otro mapa → se añade sin pisarlo', P::withCopyMap('hash:/etc/postfix/bcc') === 'hash:/etc/postfix/bcc, ' . $ours);
check('idempotente: si ya está no se repite', P::withCopyMap(P::withCopyMap('hash:/etc/postfix/bcc')) === P::withCopyMap('hash:/etc/postfix/bcc'));

echo "Prueba postmap del mapa\n";
check('código 0 = bien', P::copyProbeOk(0, 'algo'));
check('código 1 sin salida = clave no encontrada = bien', P::copyProbeOk(1, ''));
check('código 1 con texto de error = mal', !P::copyProbeOk(1, 'postmap: fatal: pgsql: connection failed'));
check('código 2 (error) = mal', !P::copyProbeOk(2, ''));

echo "Mapa conectado\n";
check('conectado cuando el fichero existe y Postfix lo lleva', P::copyMapInstalled('hash:/x, pgsql:' . P::COPY_MAP_CF, true));
check('no conectado si falta el fichero', !P::copyMapInstalled('pgsql:' . P::COPY_MAP_CF, false));
check('no conectado si Postfix no lo lleva', !P::copyMapInstalled('hash:/x', true));

echo "\n" . ($bad ? "$bad fallo(s) de " . ($ok + $bad) : "Todo correcto: $ok comprobaciones") . "\n";
exit($bad ? 1 : 0);
