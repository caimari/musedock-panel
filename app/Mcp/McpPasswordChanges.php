<?php
namespace MuseDockPanel\Mcp;

use MuseDockPanel\Database;
use MuseDockPanel\Env;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\DatabaseService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\MailService;
use MuseDockPanel\Services\SystemService;
use MuseDockPanel\Settings;

/**
 * Cambios de contraseña pedidos por el MCP. La IA NUNCA cambia una contraseña:
 * solo deja una SOLICITUD; un administrador la confirma en Ajustes → MCP con su
 * propia contraseña, y entonces el panel genera la nueva, la aplica y la deja en
 * Credenciales pendientes. Solo cuentas de clientes: buzones, usuarios de base de
 * datos de hostings y usuarios SFTP de hostings. Nunca root, cuentas del sistema,
 * la base de datos del propio panel ni administradores del panel.
 */
class McpPasswordChanges
{
    private const KEY = 'mcp_password_requests';
    private const TTL = 86400;   // una solicitud sin confirmar caduca en 24 h

    public const KINDS = ['mail' => 'Buzón de correo', 'database' => 'Usuario de base de datos', 'sftp' => 'Acceso SFTP/SSH del hosting'];

    /** Comprueba que el objetivo existe y se puede tocar. Devuelve [target normalizado, descripción]. */
    public static function validate(string $kind, string $target): array
    {
        $target = strtolower(trim($target));
        $forbidden = ['root', 'postgres', 'mysql', 'debian-sys-maint', 'mariadb.sys', 'vmail', 'caddy', 'www-data',
                      strtolower((string)Env::get('DB_USER', 'musedock_panel')), strtolower((string)Env::get('DB_NAME', 'musedock_panel'))];
        if ($target === '' || in_array($target, $forbidden, true)) {
            throw new \InvalidArgumentException("'{$target}' está protegido: el MCP nunca cambia contraseñas de root, del sistema ni del propio panel.");
        }
        switch ($kind) {
            case 'mail':
                $a = Database::fetchOne("SELECT id, email FROM mail_accounts WHERE lower(email) = :e", ['e' => $target]);
                if (!$a) {
                    throw new \InvalidArgumentException("No existe el buzón {$target}.");
                }
                return [$a['email'], "buzón {$a['email']}"];
            case 'database':
                $d = Database::fetchOne(
                    "SELECT d.db_user, d.db_name, d.db_type, a.domain FROM hosting_databases d JOIN hosting_accounts a ON a.id = d.account_id
                     WHERE lower(d.db_user) = :t OR lower(d.db_name) = :t ORDER BY d.id LIMIT 1", ['t' => $target]);
                if (!$d) {
                    throw new \InvalidArgumentException("{$target} no es un usuario ni una base de datos de un hosting del panel.");
                }
                return [$d['db_user'], "usuario {$d['db_user']} ({$d['db_type']}, base {$d['db_name']}, hosting {$d['domain']})"];
            case 'sftp':
                $h = Database::fetchOne("SELECT username, domain FROM hosting_accounts WHERE lower(domain) = :t OR username = :t", ['t' => $target]);
                if (!$h) {
                    throw new \InvalidArgumentException("No hay ningún hosting con dominio o usuario '{$target}'.");
                }
                $uid = (int)trim((string)shell_exec('id -u ' . escapeshellarg($h['username']) . ' 2>/dev/null'));
                if ($uid < 1000) {
                    throw new \InvalidArgumentException("{$h['username']} es una cuenta del sistema: protegida.");
                }
                return [$h['username'], "usuario SFTP {$h['username']} (hosting {$h['domain']})"];
        }
        throw new \InvalidArgumentException('Tipo no válido: mail, database o sftp.');
    }

    /** La deja pendiente de confirmar. No cambia nada. */
    public static function request(string $kind, string $target, string $reason = ''): array
    {
        [$t, $desc] = self::validate($kind, $target);
        $list = self::raw();
        foreach ($list as $r) {
            if ($r['kind'] === $kind && $r['target'] === $t) {
                return $r;   // ya pedida
            }
        }
        $r = ['id' => bin2hex(random_bytes(6)), 'kind' => $kind, 'target' => $t, 'desc' => $desc,
              'reason' => mb_substr(trim($reason), 0, 200), 'at' => time()];
        $list[] = $r;
        self::save($list);
        LogService::log('mcp.password.request', $t, "Solicitud MCP de cambio de contraseña: {$desc}");
        return $r;
    }

    public static function pending(): array
    {
        return array_map(fn($r) => $r + ['kind_label' => self::KINDS[$r['kind']] ?? $r['kind'],
            'at_label' => gmdate('Y-m-d H:i', (int)$r['at']) . ' UTC'], self::raw());
    }

    public static function reject(string $id): void
    {
        self::save(array_values(array_filter(self::raw(), fn($r) => $r['id'] !== $id)));
    }

    /**
     * Ejecuta una solicitud ya confirmada por un administrador (la contraseña del
     * admin la comprueba el controlador). Genera la contraseña, la aplica y la deja
     * en Credenciales pendientes. Devuelve el texto del resultado.
     */
    public static function approve(string $id): string
    {
        $req = null;
        foreach (self::raw() as $r) {
            if ($r['id'] === $id) {
                $req = $r;
            }
        }
        if (!$req) {
            throw new \RuntimeException('La solicitud ya no existe (caducada o ya resuelta).');
        }
        [$t, $desc] = self::validate($req['kind'], $req['target']);   // se revalida al aplicar
        $pass = McpCredentials::generate();
        match ($req['kind']) {
            'mail'     => self::applyMail($t, $pass),
            'database' => self::applyDatabase($t, $pass),
            'sftp'     => self::applySftp($t, $pass),
        };
        McpCredentials::store($req['kind'], $t, $pass, ['cambio' => 'contraseña nueva (la anterior ya no vale)']);
        unset($pass);
        self::reject($id);
        LogService::log('mcp.password.approved', $t, "Contraseña cambiada (solicitud MCP confirmada por un administrador): {$desc}");
        return "Contraseña de {$desc} cambiada. La nueva está en Credenciales pendientes.";
    }

    private static function applyMail(string $email, string $pass): void
    {
        $a = Database::fetchOne("SELECT id FROM mail_accounts WHERE lower(email) = :e", ['e' => strtolower($email)]);
        MailService::updateAccount((int)$a['id'], ['password' => $pass]);
    }

    private static function applyDatabase(string $user, string $pass): void
    {
        $d = Database::fetchOne("SELECT db_type FROM hosting_databases WHERE db_user = :u LIMIT 1", ['u' => $user]);
        if (($d['db_type'] ?? 'mysql') === 'pgsql') {
            $sql = sprintf("ALTER USER \"%s\" WITH PASSWORD '%s';", str_replace('"', '""', $user), $pass);
            $out = (string)shell_exec('sudo -u postgres psql -v ON_ERROR_STOP=1 -c ' . escapeshellarg($sql) . ' 2>&1');
        } else {
            $mysql = DatabaseService::mysqlCommand();
            if ($mysql === null) {
                throw new \RuntimeException('No se pudo determinar el acceso root a MySQL (MYSQL_AUTH_METHOD).');
            }
            $lit = static fn(string $v) => "'" . str_replace("'", "\\'", $v) . "'";
            $sql = "ALTER USER {$lit($user)}@'localhost' IDENTIFIED BY {$lit($pass)}; FLUSH PRIVILEGES;";
            $out = (string)shell_exec($mysql . ' -e ' . escapeshellarg($sql) . ' 2>&1');
        }
        if (stripos($out, 'ERROR') !== false) {
            throw new \RuntimeException('El motor de base de datos rechazó el cambio: ' . trim($out));
        }
        // Los slaves reciben el cambio por la replicación del propio motor (binlog / WAL).
    }

    private static function applySftp(string $user, string $pass): void
    {
        SystemService::setUserPassword($user, $pass);
        $hash = trim((string)shell_exec(sprintf('getent shadow %s 2>/dev/null | cut -d: -f2', escapeshellarg($user))));
        if ($hash === '' || $hash === '!' || $hash === '*') {
            throw new \RuntimeException('No se pudo comprobar la contraseña nueva en /etc/shadow.');
        }
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            foreach (ClusterService::getWebNodes() as $node) {
                ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                    'hosting_action' => 'change_password',
                    'hosting_data'   => ['username' => $user, 'password_hash' => $hash],
                ]);
            }
        }
    }

    private static function raw(): array
    {
        $list = json_decode(Settings::get(self::KEY, '[]'), true) ?: [];
        return array_values(array_filter($list, fn($r) => (int)($r['at'] ?? 0) > time() - self::TTL));
    }

    private static function save(array $list): void
    {
        Settings::set(self::KEY, json_encode(array_slice(array_values($list), -30)));
    }
}
