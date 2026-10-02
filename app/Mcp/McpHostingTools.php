<?php
namespace MuseDockPanel\Mcp;

use MuseDockPanel\Database;
use MuseDockPanel\Services\DatabaseService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Settings;

/**
 * Herramientas MCP de hostings que CREAN credenciales (usuarios de base de datos,
 * acceso SFTP). Todas siguen la regla de McpCredentials: la contraseña se genera
 * en el servidor y solo se ve en Ajustes → MCP → Credenciales pendientes; jamás
 * en la respuesta de la herramienta.
 */
class McpHostingTools
{
    public static function definitions(): array
    {
        $apply = ['type' => 'boolean', 'description' => 'false (por defecto) = solo devuelve el plan. true = lo ejecuta. Muestra primero el plan al usuario y pide su confirmación.'];
        $o = static fn(array $props, array $req) => ['type' => 'object', 'properties' => (object)$props, 'required' => $req, 'additionalProperties' => false];

        return [
            'database_create' => [
                'write' => true,
                'title' => 'Crear una base de datos para un hosting',
                'description' => 'Crea una base de datos MySQL/MariaDB o PostgreSQL para un hosting del panel, con su usuario y permisos, igual que /databases. Nombre final: <usuario-del-hosting>_<name>. La contraseña del usuario se GENERA en el servidor y NO se devuelve: el usuario la ve una vez en Ajustes → MCP → Credenciales pendientes. Se registra y se sincroniza a los slaves. Primero sin apply. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'hosting' => ['type' => 'string', 'description' => 'Dominio principal o usuario del hosting (hosting_accounts)'],
                    'name' => ['type' => 'string', 'description' => 'Sufijo de la base (letras, números, _)'],
                    'type' => ['type' => 'string', 'enum' => ['mysql', 'pgsql'], 'description' => 'Por defecto mysql'],
                    'db_user' => ['type' => 'string', 'description' => 'Opcional: nombre de usuario propio (por defecto, igual que la base)'],
                    'apply' => $apply,
                ], ['hosting', 'name']),
            ],
            'mail_quota_request' => [
                'write' => true,
                'title' => 'Pedir el cambio de cuota de uno o varios buzones',
                'description' => 'NO cambia nada: deja una solicitud por buzón que un administrador aprueba o rechaza en Ajustes → MCP → Cambios por aprobar (puede aprobar varios a la vez). quota_mb = espacio de almacenamiento en MB (0 = sin límite); no limita envíos. Indica `emails` (lista) o `domain` (todos los buzones del dominio). Al aprobar se aplica aquí y en las réplicas de correo. Primero sin apply. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'emails' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Buzones'],
                    'domain' => ['type' => 'string', 'description' => 'O bien: todos los buzones de este dominio'],
                    'quota_mb' => ['type' => 'integer', 'description' => 'MB; 0 = sin límite'],
                    'reason' => ['type' => 'string'],
                    'apply' => $apply,
                ], ['quota_mb']),
            ],
            'password_change_request' => [
                'write' => true,
                'title' => 'Pedir el cambio de contraseña de un buzón, usuario de base de datos o acceso SFTP',
                'description' => 'NO cambia nada: deja una SOLICITUD que un administrador debe confirmar en Ajustes → MCP con su contraseña de administrador. Al confirmarla, el panel genera la contraseña nueva, la aplica (y la sincroniza a los slaves) y la deja en Credenciales pendientes; nunca pasa por el chat. kind: mail (target = buzón), database (target = usuario o base de un hosting), sftp (target = dominio o usuario del hosting). Prohibido siempre: root, cuentas del sistema, la base de datos del panel y administradores. Primero sin apply. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'kind' => ['type' => 'string', 'enum' => ['mail', 'database', 'sftp']],
                    'target' => ['type' => 'string'],
                    'reason' => ['type' => 'string', 'description' => 'Opcional: motivo, se muestra al administrador'],
                    'apply' => $apply,
                ], ['kind', 'target']),
            ],
        ];
    }

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::definitions());
    }

    public static function run(string $name, array $args): array
    {
        return match ($name) {
            'database_create'       => self::databaseCreate($args),
            'password_change_request' => self::passwordRequest($args),
            'mail_quota_request'      => self::quotaRequest($args),
        };
    }

    private static function guardMaster(): void
    {
        $role = Settings::get('cluster_role', '') ?: \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
        if ($role === 'slave') {
            throw new \RuntimeException('Este servidor es slave: los cambios de hostings se hacen en el master.');
        }
    }

    private static function hosting(array $args): array
    {
        $h = strtolower(trim((string)($args['hosting'] ?? '')));
        $row = $h === '' ? null : Database::fetchOne(
            "SELECT id, username, domain FROM hosting_accounts WHERE lower(domain) = :h OR username = :h", ['h' => $h]);
        if (!$row) {
            throw new \InvalidArgumentException("No hay ningún hosting con dominio o usuario '{$h}' (mira hosting_accounts).");
        }
        return $row;
    }

    private static function databaseCreate(array $args): array
    {
        self::guardMaster();
        $acc = self::hosting($args);
        $p = DatabaseService::prepare((int)$acc['id'], (string)($args['name'] ?? ''), (string)($args['type'] ?? 'mysql'), (string)($args['db_user'] ?? ''));
        if (empty($p['ok'])) {
            throw new \InvalidArgumentException($p['error']);
        }
        $plan = [
            'hosting' => $acc['domain'],
            'database' => $p['db_name'],
            'user' => $p['db_user'],
            'engine' => $p['db_type'] === 'pgsql' ? 'PostgreSQL' : 'MySQL/MariaDB',
            'password_mode' => 'se generará y se verá una vez en Ajustes → MCP → Credenciales pendientes',
        ];
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }
        $r = DatabaseService::createForAccount((int)$acc['id'], (string)$args['name'], $p['db_type'], (string)($args['db_user'] ?? ''));
        if (empty($r['ok'])) {
            throw new \RuntimeException($r['error'] ?? 'No se pudo crear la base de datos.');
        }
        McpCredentials::store('database', "{$r['db_user']} ({$r['db_name']})", $r['db_pass'], [
            'motor' => $plan['engine'], 'host' => $r['db_host'], 'base' => $r['db_name'], 'usuario' => $r['db_user'],
        ]);
        unset($r['db_pass']);
        return ['status' => 'creada', 'note' => McpCredentials::notice("{$r['db_user']}")] + $plan;
    }

    private static function quotaRequest(array $args): array
    {
        self::guardMaster();
        $q = (int)($args['quota_mb'] ?? -1);
        if ($q < 0 || $q > 10485760) {
            throw new \InvalidArgumentException('quota_mb debe ser 0 (sin límite) o un número de MB.');
        }
        $dom = strtolower(trim((string)($args['domain'] ?? '')));
        $emails = array_values(array_unique(array_filter(array_map(fn($e) => strtolower(trim((string)$e)), (array)($args['emails'] ?? [])))));
        $rows = $dom !== ''
            ? Database::fetchAll("SELECT a.email, a.quota_mb, a.used_mb FROM mail_accounts a JOIN mail_domains d ON d.id = a.mail_domain_id WHERE lower(d.domain) = :d ORDER BY a.email", ['d' => $dom])
            : array_values(array_filter(array_map(fn($e) => Database::fetchOne("SELECT email, quota_mb, used_mb FROM mail_accounts WHERE lower(email) = :e", ['e' => $e]), $emails)));
        if (!$rows) {
            throw new \InvalidArgumentException('No hay buzones que coincidan (indica emails o domain).');
        }
        if ($emails && count($rows) < count($emails)) {
            $found = array_map(fn($r) => strtolower($r['email']), $rows);
            throw new \InvalidArgumentException('No existen: ' . implode(', ', array_diff($emails, $found)));
        }
        $fmt = fn(int $mb) => $mb === 0 ? 'sin límite' : "{$mb} MB";
        $changes = [];
        foreach ($rows as $r) {
            if ((int)$r['quota_mb'] === $q) {
                continue;
            }
            $changes[] = ['email' => $r['email'], 'from' => $fmt((int)$r['quota_mb']), 'to' => $fmt($q), 'used' => (int)($r['used_mb'] ?? 0) . ' MB'];
        }
        if (!$changes) {
            return ['status' => 'nada_que_hacer', 'note' => 'Todos tienen ya esa cuota.'];
        }
        $plan = ['changes' => $changes, 'note' => 'Se dejarán como solicitudes; no se aplica nada hasta que un administrador las apruebe en Ajustes → MCP.'];
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }
        foreach ($changes as $c) {
            McpChangeRequests::add('mail_quota', $c['email'], ['quota_mb' => $q], "cuota de {$c['email']}: {$c['from']} → {$c['to']}", (string)($args['reason'] ?? ''));
        }
        return ['status' => 'pendiente_de_aprobar', 'requests' => count($changes),
            'next' => 'Pide al usuario que las apruebe en Ajustes → MCP → Cambios por aprobar.'] + $plan;
    }

    private static function passwordRequest(array $args): array
    {
        self::guardMaster();
        $kind = (string)($args['kind'] ?? '');
        [$target, $desc] = McpPasswordChanges::validate($kind, (string)($args['target'] ?? ''));
        $plan = ['target' => $desc, 'actions' => [
            'Dejar la solicitud pendiente (no se cambia nada todavía)',
            'Un administrador la confirma en Ajustes → MCP con su contraseña; entonces se genera la nueva, se aplica y aparece en Credenciales pendientes',
        ]];
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }
        $r = McpPasswordChanges::request($kind, $target, (string)($args['reason'] ?? ''));
        return ['status' => 'pendiente_de_confirmar', 'request_id' => $r['id'],
            'next' => 'Pide al usuario que la confirme en Ajustes → MCP (Cambios de contraseña por confirmar) con su contraseña de administrador. Caduca en 24 h.'] + $plan;
    }
}
