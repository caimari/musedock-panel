<?php
/**
 * Cabecera Authentication-Results con spf=, dkim= y dmarc= en el correo RECIBIDO.
 *
 * - rspamd (que ya comprueba SPF, DKIM y DMARC para puntuar spam) pasa a escribir
 *   su resultado en Authentication-Results, firmada con el nombre del servidor de
 *   correo (myhostname de Postfix, p. ej. mail.ejemplo.com).
 * - Quita las Authentication-Results que traiga el correo de fuera (nadie puede
 *   colar una inventada).
 * - OpenDKIM pasa a modo "s" (solo firma lo que sale): rspamd ya verifica DKIM, y así
 *   la cabecera de arriba es la única y lleva los tres resultados.
 *
 * Guarda copia de lo que cambia; si rspamd no valida la configuración nueva, deja
 * todo como estaba. Idempotente.
 *
 * Uso (como root): php /opt/musedock-panel/bin/mail-auth-results.php [--dry-run]
 */

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$dry = in_array('--dry-run', $argv, true);
$stamp = date('Ymd_His');

$rspamdFile = '/etc/rspamd/local.d/milter_headers.conf';
$rspamdConf = <<<'CONF'
# MuseDock Panel: resultados SPF/DKIM/DMARC del correo entrante en
# Authentication-Results (authserv-id = myhostname de Postfix).
use = ["authentication-results"];
# El correo que envían nuestros propios usuarios (autenticados) no la necesita.
skip_local = false;
skip_authenticated = true;
routines {
  authentication-results {
    header = "Authentication-Results";
    # 0 = quitar TODAS las Authentication-Results que traiga el mensaje.
    remove = 0;
    add_smtp_user = false;
  }
}

CONF;

$dkimFile = '/etc/opendkim.conf';

if (!is_dir('/etc/rspamd/local.d')) {
    fwrite(STDERR, "rspamd no está instalado aquí (/etc/rspamd/local.d no existe).\n");
    exit(1);
}
$dkim = is_file($dkimFile) ? (string)file_get_contents($dkimFile) : '';
$dkimNew = preg_replace('/^(\s*Mode\s+)sv\s*$/mi', '${1}s', $dkim);

$plan = [];
if (!is_file($rspamdFile) || (string)file_get_contents($rspamdFile) !== $rspamdConf) {
    $plan[] = "escribir {$rspamdFile}";
}
if ($dkim !== '' && $dkimNew !== $dkim) {
    $plan[] = "OpenDKIM: Mode sv → s en {$dkimFile} (solo firma; rspamd verifica)";
}
if (!$plan) {
    echo "OK: ya estaba aplicado.\n";
    exit(0);
}
echo "Cambios:\n - " . implode("\n - ", $plan) . "\n";
if ($dry) {
    echo "(--dry-run: no se ha cambiado nada)\n";
    exit(0);
}

$rspamdOld = is_file($rspamdFile) ? (string)file_get_contents($rspamdFile) : null;
if ($rspamdOld !== null) {
    copy($rspamdFile, "{$rspamdFile}.bak.{$stamp}");
}
file_put_contents($rspamdFile, $rspamdConf);

$test = trim((string)shell_exec('rspamadm configtest 2>&1'));
if (stripos($test, 'syntax OK') === false) {
    $rspamdOld === null ? @unlink($rspamdFile) : file_put_contents($rspamdFile, $rspamdOld);
    fwrite(STDERR, "rspamd no valida la configuración nueva; se deja como estaba:\n{$test}\n");
    exit(1);
}
echo trim((string)shell_exec('systemctl reload rspamd 2>&1')) ?: "rspamd recargado\n";

if ($dkim !== '' && $dkimNew !== $dkim) {
    copy($dkimFile, "{$dkimFile}.bak.{$stamp}");
    file_put_contents($dkimFile, $dkimNew);
    // milter_default_action=accept: unos segundos sin OpenDKIM no rechazan correo.
    $o = trim((string)shell_exec('systemctl restart opendkim 2>&1'));
    $active = trim((string)shell_exec('systemctl is-active opendkim 2>/dev/null')) === 'active';
    if (!$active) {
        copy("{$dkimFile}.bak.{$stamp}", $dkimFile);
        shell_exec('systemctl restart opendkim 2>&1');
        fwrite(STDERR, "OpenDKIM no arrancó con Mode s; restaurado. {$o}\n");
        exit(1);
    }
    echo "OpenDKIM reiniciado (modo s)\n";
}
$host = trim((string)shell_exec('postconf -h myhostname 2>/dev/null'));
echo "Hecho. Authentication-Results firmadas como: {$host}\n";
