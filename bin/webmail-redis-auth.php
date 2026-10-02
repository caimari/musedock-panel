<?php
/**
 * Pone la contraseña de Redis (requirepass de /etc/redis/redis.conf) en la
 * configuración de Roundcube ya instalada. Hace falta cuando Redis pasó a exigir
 * contraseña después de instalar webmail: sin ella webmail da error 500
 * ("NOAUTH Authentication required"). Idempotente; guarda copia antes de escribir.
 * La contraseña no se muestra.
 *
 * Uso (como root): php /opt/musedock-panel/bin/webmail-redis-auth.php
 */

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$pass = '';
foreach (@file('/etc/redis/redis.conf', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    if (preg_match('/^\s*requirepass\s+(\S+)/', $l, $m)) {
        $pass = trim($m[1], '"');
    }
}
$host = $pass !== '' ? '127.0.0.1:6379:0:' . $pass : '127.0.0.1:6379';
$files = glob('/opt/musedock-webmail/roundcube/releases/*/roundcubemail-*/config/config.inc.php') ?: [];
if (!$files) {
    echo "No hay webmail (Roundcube) instalado aquí: nada que hacer.\n";
    exit(0);
}
$rc = 0;
foreach ($files as $f) {
    $c = (string)file_get_contents($f);
    $line = '$config[\'redis_hosts\'] = [' . var_export($host, true) . '];';
    if (!preg_match('/^\$config\[\'redis_hosts\'\]\s*=.*$/m', $c)) {
        echo "SIN CAMBIOS {$f}: no usa Redis\n";
        continue;
    }
    $new = preg_replace('/^\$config\[\'redis_hosts\'\]\s*=.*$/m', $line, $c, 1);
    if ($new === $c) {
        echo "OK         {$f}: ya estaba bien\n";
        continue;
    }
    copy($f, $f . '.bak.' . date('Ymd_His'));
    if (file_put_contents($f, $new) === false) {
        echo "ERROR      {$f}: no se pudo escribir\n";
        $rc = 1;
        continue;
    }
    echo "ARREGLADO  {$f}: " . ($pass !== '' ? 'ahora usa la contraseña de Redis' : 'Redis sin contraseña') . "\n";
}
exit($rc);
