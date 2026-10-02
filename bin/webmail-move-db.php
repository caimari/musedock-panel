<?php
/**
 * Mueve la base de datos de Roundcube al cluster de PostgreSQL que se replica al
 * nodo de relevo (por defecto el principal, puerto 5432). El instalador la crea en
 * el PostgreSQL del panel (5433), que es propio de cada nodo y NO se replica: tras
 * un relevo el slave no tendría contactos, ajustes ni usuarios del webmail.
 *
 * Copia (pg_dump/pg_restore), comprueba que el usuario de Roundcube entra en la
 * copia, y solo entonces cambia el puerto en config.inc.php (con copia previa).
 * NO borra la base antigua: queda en 5433 por si hay que volver atrás.
 *
 * Uso (como root, en el MASTER): php bin/webmail-move-db.php [--to-port=5432] [--dry-run]
 */

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$dry = in_array('--dry-run', $argv, true);
$toPort = 5432;
foreach ($argv as $a) {
    if (preg_match('/^--to-port=(\d+)$/', $a, $m)) {
        $toPort = (int)$m[1];
    }
}
$conf = realpath('/opt/musedock-webmail/roundcube/current') . '/config/config.inc.php';
if (!is_file($conf)) {
    fwrite(STDERR, "No hay Roundcube instalado (no existe {$conf}).\n");
    exit(1);
}
$c = (string)file_get_contents($conf);
if (!preg_match("/^\\\$config\\['db_dsnw'\\]\\s*=\\s*'pgsql:\\/\\/([^:]+):([^@]*)@([^:\\/]+):(\\d+)\\/([^']+)';/m", $c, $m)) {
    fwrite(STDERR, "No entiendo db_dsnw de {$conf} (se esperaba pgsql://usuario:clave@host:puerto/base).\n");
    exit(1);
}
[, $user, $pass, $host, $fromPort, $db] = $m;
$pass = rawurldecode($pass);
if ((int)$fromPort === $toPort) {
    echo "OK: Roundcube ya usa el puerto {$toPort}.\n";
    exit(0);
}
$pg = static fn(int $port, string $sql) => trim((string)shell_exec(
    'cd /tmp && runuser -u postgres -- psql -p ' . $port . ' -X -At -v ON_ERROR_STOP=1 -c ' . escapeshellarg($sql) . ' 2>&1'));
$q = static fn(string $s) => str_replace("'", "''", $s);

$inRecovery = $pg($toPort, 'SELECT pg_is_in_recovery()');
if ($inRecovery !== 'f') {
    fwrite(STDERR, "El PostgreSQL del puerto {$toPort} no está disponible como primario ({$inRecovery}).\n");
    exit(1);
}
$roleExists = $pg($toPort, "SELECT 1 FROM pg_roles WHERE rolname = '{$q($user)}'") === '1';
$dbExists = $pg($toPort, "SELECT 1 FROM pg_database WHERE datname = '{$q($db)}'") === '1';
$tables = $dbExists ? (int)trim((string)shell_exec('cd /tmp && runuser -u postgres -- psql -p ' . $toPort . ' -X -At -d ' . escapeshellarg($db)
    . " -c \"SELECT count(*) FROM information_schema.tables WHERE table_schema='public'\" 2>&1")) : 0;
if ($dbExists && $tables > 0) {
    fwrite(STDERR, "Ya existe la base {$db} en el puerto {$toPort} con {$tables} tablas: no la piso. Revísala a mano.\n");
    exit(1);
}
echo "Plan:\n";
echo " - copiar la base {$db} del puerto {$fromPort} al {$toPort} (se replica al nodo de relevo)\n";
echo $roleExists ? " - el usuario {$user} ya existe en {$toPort}: se le pone la misma contraseña\n" : " - crear el usuario {$user} en {$toPort} con la misma contraseña\n";
echo " - comprobar que {$user} entra en la copia\n";
echo " - cambiar el puerto en {$conf} (copia previa)\n";
echo " - la base antigua del puerto {$fromPort} NO se borra\n";
if ($dry) {
    echo "(--dry-run: no se ha cambiado nada)\n";
    exit(0);
}

$sqlRole = $roleExists
    ? "ALTER ROLE \"{$user}\" WITH LOGIN PASSWORD '{$q($pass)}'"
    : "CREATE ROLE \"{$user}\" WITH LOGIN PASSWORD '{$q($pass)}'";
$o = $pg($toPort, $sqlRole);
if (stripos($o, 'ERROR') !== false) {
    fwrite(STDERR, "No se pudo preparar el usuario: {$o}\n");
    exit(1);
}
if (!$dbExists) {
    $o = $pg($toPort, "CREATE DATABASE \"{$db}\" OWNER \"{$user}\" ENCODING 'UTF8' TEMPLATE template0");
    if (stripos($o, 'ERROR') !== false) {
        fwrite(STDERR, "No se pudo crear la base: {$o}\n");
        exit(1);
    }
}
$dump = '/var/backups/musedock/roundcube-' . date('Ymd_His') . '.dump';
@mkdir(dirname($dump), 0750, true);
$o = trim((string)shell_exec('cd /tmp && runuser -u postgres -- pg_dump -p ' . (int)$fromPort . ' -Fc ' . escapeshellarg($db)
    . ' > ' . escapeshellarg($dump) . ' 2>&1'));
if (!is_file($dump) || filesize($dump) < 100) {
    fwrite(STDERR, "pg_dump falló: {$o}\n");
    exit(1);
}
chmod($dump, 0600);
// La copia es de root (0600): pg_restore (usuario postgres) la lee por la entrada
// estándar, que abre root. cd /tmp evita el aviso "could not change directory".
$o = trim((string)shell_exec('cd /tmp && runuser -u postgres -- pg_restore -p ' . $toPort . ' -d ' . escapeshellarg($db)
    . ' --no-owner --role=' . escapeshellarg($user) . ' < ' . escapeshellarg($dump) . ' 2>&1'));
if (stripos($o, 'error') !== false) {
    fwrite(STDERR, "pg_restore dio errores (la configuración NO se ha cambiado):\n{$o}\n");
    exit(1);
}
try {
    $pdo = new PDO("pgsql:host={$host};port={$toPort};dbname={$db}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $n = (int)$pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_schema='public'")->fetchColumn();
    $users = (int)$pdo->query('SELECT count(*) FROM users')->fetchColumn();
} catch (\Throwable $e) {
    fwrite(STDERR, "El usuario {$user} no puede entrar en la copia ({$e->getMessage()}). La configuración NO se ha cambiado.\n");
    exit(1);
}
copy($conf, $conf . '.bak.' . date('Ymd_His'));
$new = preg_replace("/(\\\$config\\['db_dsnw'\\]\\s*=\\s*'pgsql:\\/\\/[^@]*@[^:\\/]+:)" . preg_quote($fromPort, '/') . "(\\/)/", '${1}' . $toPort . '${2}', $c, 1);
file_put_contents($conf, $new);
echo "Hecho: {$n} tablas y {$users} usuarios de Roundcube copiados; ahora usa el puerto {$toPort}.\n";
echo "Copia de la base en {$dump}. La base antigua sigue en el puerto {$fromPort}.\n";
