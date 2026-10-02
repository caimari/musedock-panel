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
                $out = (string)shell_exec('sudo -u postgres psql -v ON_ERROR_STOP=1 -c ' . escapeshellarg($sql) . ' 2>&1');
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
            $out = (string)shell_exec($mysql . ' -e ' . escapeshellarg($sql) . ' 2>&1');
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

    public static function mysqlCommand(): ?string
    {
        $auth = Env::get('MYSQL_AUTH_METHOD', 'socket');
        if ($auth === 'socket') {
            return 'mysql -u root';
        }
        if ($auth === 'password') {
            $pass = Env::get('MYSQL_ROOT_PASS', '');
            return $pass === '' ? null : 'mysql -u root -p' . escapeshellarg($pass);
        }
        return null;
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
