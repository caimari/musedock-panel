<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Env;
use MuseDockPanel\Settings;

/**
 * Alta de bases de datos de un hosting (MySQL/MariaDB o PostgreSQL). La usan el
 * formulario de /databases y la herramienta MCP database_create, así las dos
 * crean lo mismo. La contraseña se genera aquí y se DEVUELVE al llamante, que
 * decide cómo enseñarla (en la página una vez, o en Credenciales pendientes).
 */
class DatabaseService
{
    /**
     * Valida sin crear nada. Devuelve ['ok'=>true, 'account'=>..., 'db_name', 'db_user', 'db_type']
     * o ['ok'=>false, 'error'=>...].
     */
    public static function prepare(int $accountId, string $suffix, string $type = 'mysql', string $customUser = ''): array
    {
        $type = in_array($type, ['mysql', 'pgsql'], true) ? $type : 'mysql';
        $suffix = trim($suffix);
        if ($accountId <= 0 || $suffix === '') {
            return ['ok' => false, 'error' => 'Todos los campos son obligatorios.'];
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $suffix)) {
            return ['ok' => false, 'error' => 'El nombre de la base de datos solo puede contener letras, numeros y guion bajo.'];
        }
        $account = Database::fetchOne("SELECT id, username, domain FROM hosting_accounts WHERE id = :id", ['id' => $accountId]);
        if (!$account) {
            return ['ok' => false, 'error' => 'Cuenta de hosting no encontrada.'];
        }
        $dbName = $account['username'] . '_' . $suffix;
        $customUser = trim($customUser);
        if ($customUser !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $customUser)) {
            return ['ok' => false, 'error' => 'El usuario solo puede contener letras, numeros y guion bajo.'];
        }
        $dbUser = $customUser !== '' ? $customUser : $dbName;
        if (strlen($dbName) > 64) {
            return ['ok' => false, 'error' => 'El nombre completo de la base de datos no puede exceder 64 caracteres. Actual: ' . strlen($dbName)];
        }
        if (strlen($dbUser) > 32) {
            return ['ok' => false, 'error' => 'El nombre de usuario de la base de datos no puede exceder 32 caracteres. Actual: ' . strlen($dbUser)];
        }
        if (Database::fetchOne("SELECT id FROM hosting_databases WHERE db_name = :n", ['n' => $dbName])) {
            return ['ok' => false, 'error' => "La base de datos '{$dbName}' ya existe."];
        }
        return ['ok' => true, 'account' => $account, 'db_name' => $dbName, 'db_user' => $dbUser, 'db_type' => $type];
    }

    /**
     * Crea la base, su usuario con contraseña generada y los permisos; la registra
     * y la sincroniza a los slaves. Devuelve ['ok'=>true, 'db_name','db_user','db_pass','db_host','db_type']
     * o ['ok'=>false, 'error'=>...].
     */
    public static function createForAccount(int $accountId, string $suffix, string $type = 'mysql', string $customUser = ''): array
    {
        $p = self::prepare($accountId, $suffix, $type, $customUser);
        if (empty($p['ok'])) {
            return $p;
        }
        [$account, $dbName, $dbUser, $type] = [$p['account'], $p['db_name'], $p['db_user'], $p['db_type']];
        $pass = bin2hex(random_bytes(12));

        if ($type === 'pgsql') {
            // Identificadores entre comillas dobles y literal entre simples (antes se
            // usaba escapeshellarg dentro del SQL, que da 'nombre' = literal, no identificador).
            $id = static fn(string $s) => '"' . str_replace('"', '""', $s) . '"';
            foreach ([
                'crear el usuario' => sprintf("CREATE USER %s WITH PASSWORD '%s';", $id($dbUser), $pass),
                'crear la base de datos' => sprintf('CREATE DATABASE %s OWNER %s;', $id($dbName), $id($dbUser)),
                'asignar privilegios' => sprintf('GRANT ALL PRIVILEGES ON DATABASE %s TO %s;', $id($dbName), $id($dbUser)),
            ] as $what => $sql) {
                $out = (string)self::runWithStdin('sudo -u postgres psql -v ON_ERROR_STOP=1 2>&1', $sql . "\n");
                if (stripos($out, 'ERROR') !== false) {
                    LogService::log('database.create.error', $dbName, "PostgreSQL error ({$what}): {$out}");
                    return ['ok' => false, 'error' => "Error al {$what} en PostgreSQL: {$out}"];
                }
            }
            $label = 'PostgreSQL';
        } else {
            $mysql = self::mysqlCommand();
            if ($mysql === null) {
                return ['ok' => false, 'error' => 'No se pudo determinar el metodo de autenticacion de MySQL. Verifica MYSQL_AUTH_METHOD en .env'];
            }
            $ident = '`' . str_replace('`', '``', $dbName) . '`';
            $lit = static fn(string $v) => "'" . str_replace("'", "\\'", $v) . "'";
            $sql = "CREATE DATABASE IF NOT EXISTS {$ident} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
                 . "CREATE USER IF NOT EXISTS {$lit($dbUser)}@'localhost' IDENTIFIED BY {$lit($pass)}; "
                 . "GRANT ALL PRIVILEGES ON {$ident}.* TO {$lit($dbUser)}@'localhost'; FLUSH PRIVILEGES;";
            $out = (string)self::runWithStdin($mysql . ' 2>&1', $sql . "\n");
            if (stripos($out, 'ERROR') !== false) {
                LogService::log('database.create.error', $dbName, "MySQL error: {$out}");
                return ['ok' => false, 'error' => 'Error al crear la base de datos en MySQL: ' . $out];
            }
            $label = 'MySQL';
        }

        Database::insert('hosting_databases', [
            'account_id' => $accountId,
            'db_name'    => $dbName,
            'db_user'    => $dbUser,
            'db_type'    => $type,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        LogService::log('database.create', $dbName, "Created {$label} database: {$dbName}, user: {$dbUser} for account {$account['username']}");
        self::syncToSlaves($accountId, (string)$account['domain']);

        return ['ok' => true, 'db_name' => $dbName, 'db_user' => $dbUser, 'db_pass' => $pass, 'db_host' => 'localhost', 'db_type' => $type];
    }

    /**
     * Contraseña nueva (generada aquí) para el usuario de una base de un hosting. La
     * devuelve al llamante para enseñarla UNA vez. Las réplicas la reciben por la
     * replicación del propio motor. $db = fila de hosting_databases.
     * Devuelve ['ok'=>true, 'db_user', 'db_pass'] o ['ok'=>false, 'error'].
     */
    public static function changePassword(array $db): array
    {
        $user = (string)($db['db_user'] ?? '');
        if ($user === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $user)) {
            return ['ok' => false, 'error' => 'Usuario de base de datos no válido.'];
        }
        $pass = bin2hex(random_bytes(12));
        if (($db['db_type'] ?? 'mysql') === 'pgsql') {
            $sql = sprintf("ALTER USER \"%s\" WITH PASSWORD '%s';", str_replace('"', '""', $user), $pass);
            $out = (string)self::runWithStdin('sudo -u postgres psql -v ON_ERROR_STOP=1 2>&1', $sql . "\n");
        } else {
            $mysql = self::mysqlCommand();
            if ($mysql === null) {
                return ['ok' => false, 'error' => 'No se pudo determinar el acceso root a MySQL (MYSQL_AUTH_METHOD).'];
            }
            $lit = static fn(string $v) => "'" . str_replace("'", "\\'", $v) . "'";
            $sql = "ALTER USER {$lit($user)}@'localhost' IDENTIFIED BY {$lit($pass)}; FLUSH PRIVILEGES;";
            $out = (string)self::runWithStdin($mysql . ' 2>&1', $sql . "\n");
        }
        if (stripos($out, 'ERROR') !== false) {
            LogService::log('database.password.error', (string)($db['db_name'] ?? $user), 'Cambio de contraseña rechazado por el motor');
            return ['ok' => false, 'error' => 'El servidor de bases de datos rechazó el cambio.'];
        }
        LogService::log('database.password', (string)($db['db_name'] ?? $user), "Contraseña nueva para el usuario {$user}");
        return ['ok' => true, 'db_user' => $user, 'db_pass' => $pass];
    }

    public static function mysqlCommand(): ?string
    {
        $auth = Env::get('MYSQL_AUTH_METHOD', 'socket');
        if ($auth === 'socket') {
            return 'mysql -u root';
        }
        if ($auth === 'password') {
            $pass = Env::get('MYSQL_ROOT_PASS', '');
            return $pass === '' ? null : 'mysql ' . self::mysqlDefaultsFileArg($pass) . ' -u root';
        }
        return null;
    }

    /**
     * Como shell_exec, pero la entrada (SQL con contraseñas) va por stdin y no por argv, para que no
     * sea visible con `ps`. Devuelve la salida estándar o null si está vacía (igual que shell_exec).
     */
    public static function runWithStdin(string $cmd, string $stdin): ?string
    {
        $r = self::runWithStdinEx($cmd, $stdin);
        return $r['out'] === '' ? null : $r['out'];
    }

    /** Igual que runWithStdin, pero devuelve también el código de salida: ['code' => int, 'out' => string]. */
    public static function runWithStdinEx(string $cmd, string $stdin): array
    {
        $proc = @proc_open(['/bin/sh', '-c', $cmd], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => ''];
        }
        @fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($proc);
        return ['code' => (int)$code, 'out' => $out === false ? '' : $out];
    }

    /**
     * Argumento `--defaults-extra-file=...` (ya escapado para shell) con la contraseña de root
     * de MySQL, para NO pasarla en la línea de comandos (visible con `ps`).
     * El fichero se crea con permisos 0600 (umask 077), se reutiliza dentro del mismo proceso
     * y se borra al terminar el proceso. Debe ir SIEMPRE como primera opción del cliente.
     * Los clientes que lo reciban deben ejecutarse mientras el proceso PHP esté vivo.
     */
    public static function mysqlDefaultsFileArg(string $pass): string
    {
        static $files = [];
        $key = hash('sha256', $pass);
        if (!isset($files[$key]) || !is_file($files[$key])) {
            $old = umask(077);
            $path = tempnam(sys_get_temp_dir(), 'mdp_my_');
            umask($old);
            if ($path === false) {
                throw new \RuntimeException('No se pudo crear el fichero temporal de credenciales MySQL.');
            }
            @chmod($path, 0600);
            $escaped = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $pass);
            file_put_contents($path, "[client]\npassword=\"" . $escaped . "\"\n");
            @chmod($path, 0600);
            $files[$key] = $path;
            register_shutdown_function(static function () use ($path): void {
                @unlink($path);
            });
        }
        return '--defaults-extra-file=' . escapeshellarg($files[$key]);
    }

    /** Registra en los slaves las bases de un hosting (sin contraseñas). */
    public static function syncToSlaves(int $accountId, string $domain): void
    {
        if (Settings::get('cluster_role', 'standalone') !== 'master') {
            return;
        }
        $databases = Database::fetchAll(
            "SELECT db_name, db_user, db_type, created_at FROM hosting_databases WHERE account_id = :aid",
            ['aid' => $accountId]
        );
        foreach (ClusterService::getNodes() as $node) {
            if (($node['role'] ?? '') !== 'slave') {
                continue;
            }
            ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                'hosting_action' => 'sync_databases',
                'hosting_data'   => ['main_domain' => $domain, 'databases' => $databases],
            ]);
        }
    }
}
