<?php
namespace MuseDockPanel\Mcp;

use MuseDockPanel\Database;
use MuseDockPanel\Services\DatabaseService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\WordPressHardenService as WP;
use MuseDockPanel\Services\AlertPolicyService as AP;
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
            'domain_redirects' => [
                'title' => 'Ver las redirecciones de dominio',
                'description' => 'Solo lectura. Lista las redirecciones del panel: las sueltas (un dominio que solo redirige a una URL, como en /domains) y las de los hostings (un dominio que redirige al dominio del hosting).',
                'inputSchema' => $o(['domain' => ['type' => 'string', 'description' => 'Opcional: filtrar por dominio']], []),
            ],
            'domain_redirect_create' => [
                'write' => true,
                'title' => 'Crear una redirección de dominio',
                'description' => 'Crea una redirección suelta, igual que /domains → Redirect: el dominio (y su www) responde con un 301/302 hacia la URL destino, con certificado propio. Útil p. ej. para webmail.cliente.com → https://webmail.servidor.com. Comprueba antes que el dominio no sea ya un hosting, alias, redirección ni lo sirva Caddy, y dice a dónde apunta su DNS (para el certificado debe apuntar a este servidor: si no, crea antes el DNS con dns_record_set). Se copia a los nodos web. No borra nada. Primero sin apply. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'domain' => ['type' => 'string', 'description' => 'Dominio que redirige (p. ej. webmail.cliente.com)'],
                    'target_url' => ['type' => 'string', 'description' => 'URL destino (p. ej. https://webmail.servidor.com)'],
                    'code' => ['type' => 'integer', 'enum' => [301, 302], 'description' => '301 permanente (por defecto) o 302 temporal'],
                    'preserve_path' => ['type' => 'boolean', 'description' => 'true (por defecto) = conserva la ruta (/x → destino/x); false = siempre a la portada del destino'],
                    'apply' => $apply,
                ], ['domain', 'target_url']),
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
            'wordpress_status' => [
                'title' => 'Estado de "Blindar WordPress" de los hostings',
                'description' => 'Solo lectura. Cada WordPress del panel: nivel (off, standard, strict), si xmlrpc.php está abierto, si el código está cerrado (strict) y, si se pide `quick`, indicios rápidos de infección (PHP en uploads, carpetas raras, mu-plugins, plugins de malware conocido). Además, cuántas IPs tiene baneadas Caddy (fail2ban detrás de Cloudflare). Con `node`, en otro nodo.',
                'inputSchema' => $o([
                    'quick' => ['type' => 'boolean', 'description' => 'true = añade indicios rápidos por sitio (sin consultar wordpress.org)'],
                    'node' => ['type' => 'string', 'description' => 'Opcional. Id o nombre de un nodo del cluster para ejecutarlo en él. Omitir = este servidor.'],
                ], []),
            ],
            'wordpress_scan' => [
                'title' => 'Analizar un WordPress en busca de infección',
                'description' => 'Solo lectura, no ejecuta el PHP del sitio. Compara el núcleo, cada plugin y cada tema con wordpress.org (ficheros cambiados o que sobran, los que no existen allí o de malware conocido; marca el tema activo), revisa los drop-ins y PHP sueltos de wp-content, busca ofuscación (marcas SC_*_BEGIN y líneas de más de 3000 caracteres fuera de ficheros originales) y trozos típicos de puertas traseras, PHP en uploads, zips subidos, mu-plugins, carpetas raras, y en la base de datos: administradores, opciones con scripts inyectados y entradas recientes. Devuelve un veredicto. Siguiente paso: wordpress_repair. `domain` puede ser un subdominio con WordPress. Con `node`, en otro nodo.',
                'inputSchema' => $o([
                    'domain' => ['type' => 'string', 'description' => 'Dominio del hosting o subdominio'],
                    'offline' => ['type' => 'boolean', 'description' => 'true = sin comparar con wordpress.org (más rápido)'],
                    'node' => ['type' => 'string', 'description' => 'Opcional. Id o nombre de un nodo del cluster para ejecutarlo en él. Omitir = este servidor.'],
                ], ['domain']),
            ],
            'wordpress_harden' => [
                'write' => true,
                'title' => 'Cambiar el nivel de "Blindar WordPress" de un hosting',
                'description' => 'standard (por defecto, no limita al cliente): xmlrpc.php cerrado salvo Jetpack o permiso, sin PHP en uploads, ficheros sensibles y ?author= en 403. strict (para webs propias): además el código pasa a ser de solo lectura para PHP (solo escribe en uploads/cache) y se impide instalar o editar plugins/temas desde el admin, aunque roben la contraseña; para actualizar se desbloquea un rato (`unlock_minutes`). off: nada. xmlrpc: auto (abierto solo con Jetpack), on, off. Se aplica aquí y en los nodos web. Solo en el master. Primero sin apply. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'domain' => ['type' => 'string', 'description' => 'Dominio o usuario del hosting'],
                    'level' => ['type' => 'string', 'enum' => ['off', 'standard', 'strict']],
                    'xmlrpc' => ['type' => 'string', 'enum' => ['auto', 'on', 'off'], 'description' => 'Opcional; si se omite, se deja como está'],
                    'unlock_minutes' => ['type' => 'integer', 'description' => 'Solo strict: abre el código N minutos (5-240) para actualizar; luego se cierra solo. No cambia el nivel.'],
                    'apply' => $apply,
                ], ['domain']),
            ],
            'wordpress_repair' => [
                'write' => true,
                'title' => 'Limpiar un WordPress infectado',
                'description' => 'Nada se borra: lo que se quita va a /var/lib/musedock/wp-quarantine/<dominio>/<fecha>/ con su lista. Acciones: quarantine (mueve `paths`, relativas a la web, p. ej. "wp-content/plugins/wp-link-helper" o un fichero), reinstall_core (núcleo de su misma versión desde wordpress.org; lo cambiado o de más va a cuarentena), reinstall_plugin / reinstall_theme (`slug`, desde wordpress.org en su versión; el actual a cuarentena), rotate_salts (claves nuevas en wp-config.php: cierra todas las sesiones; copia antes). Haz antes wordpress_scan. Solo en el master. Primero sin apply. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'domain' => ['type' => 'string', 'description' => 'Dominio del hosting o subdominio'],
                    'action' => ['type' => 'string', 'enum' => ['quarantine', 'reinstall_core', 'reinstall_plugin', 'reinstall_theme', 'rotate_salts']],
                    'paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'quarantine: rutas relativas a la raíz de la web'],
                    'slug' => ['type' => 'string', 'description' => 'reinstall_plugin / reinstall_theme: carpeta del plugin o tema'],
                    'apply' => $apply,
                ], ['domain', 'action']),
            ],
            'monitor_stats' => [
                'title' => 'Estadísticas de un servidor en un periodo',
                'description' => 'Solo lectura. Para el periodo pedido (1h, 6h, 24h, 7d, 30d): cada métrica del monitor (CPU, RAM, discos, red, GPU, tráfico web, peticiones/s) con media, pico, p95 y último valor, y el tráfico web por hosting (bytes y peticiones) en ese periodo y en el mes. Si el tráfico web sale vacío, el registro de accesos de Caddy no se escribe en ese nodo (el panel lo repara solo cada 30 min). Con `node`, en otro nodo del cluster.',
                'inputSchema' => $o([
                    'range' => ['type' => 'string', 'enum' => ['1h', '6h', '24h', '7d', '30d'], 'description' => 'Por defecto 24h'],
                    'metric' => ['type' => 'string', 'description' => 'Opcional: solo las métricas que contengan este texto (p. ej. "disk", "cpu", "bw")'],
                    'node' => ['type' => 'string', 'description' => 'Opcional. Id o nombre de un nodo del cluster para ejecutarlo en él. Omitir = este servidor.'],
                ], []),
            ],
            'cert_status' => [
                'title' => 'Certificados de todas las webs de un servidor',
                'description' => 'Solo lectura. Comprueba en este servidor (contra él mismo, da igual el proxy de Cloudflare) cada web que sirve Caddy: si tiene un certificado válido para su nombre y cuántos días le quedan. Lista los problemas (sin certificado, de otro nombre, caducado, o a menos de 14 días sin renovar), separando los dominios sin DNS, y las que antes caducan. Con proxy naranja en Full (strict), un certificado no válido es error 526. Con `node`, en otro nodo.',
                'inputSchema' => $o([
                    'node' => ['type' => 'string', 'description' => 'Opcional. Id o nombre de un nodo del cluster para ejecutarlo en él. Omitir = este servidor.'],
                ], []),
            ],
            'alerts_status' => [
                'title' => 'Reglas de avisos (silenciados, hardening aceptado, discos)',
                'description' => 'Solo lectura. Qué tipos de aviso están silenciados, qué controles de hardening se dan por buenos, umbrales propios o silencio por disco y servidor, minutos que debe fallar un nodo de correo antes de avisar, y los controles de hardening que fallan ahora en este servidor. Con `node`, en otro nodo.',
                'inputSchema' => $o([
                    'node' => ['type' => 'string', 'description' => 'Opcional. Id o nombre de un nodo del cluster para ejecutarlo en él. Omitir = este servidor.'],
                ], []),
            ],
            'alerts_configure' => [
                'write' => true,
                'title' => 'Cambiar las reglas de avisos',
                'description' => 'Silencia o reactiva tipos de aviso (solo quita el correo/Telegram; siguen en el monitor), da por buenos controles de hardening (por su título, ver alerts_status), pone umbral propio o silencia un disco de un servidor (host corto p. ej. "servidor2" o "*"; threshold 0 = sin aviso, null = quitar la regla) y fija cuántos minutos debe fallar un nodo de correo antes de avisar. Lo que no se indique se deja igual. Se guarda en el master y se copia a todos sus nodos (desde una copia, se envía al master). Primero sin apply. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'mute' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tipos a silenciar en todos los servidores ("DISK_HIGH") o solo en uno ("servidor2:DISK_HIGH"; nombre corto del servidor). Ver alerts_status → types'],
                    'unmute' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tipos a reactivar'],
                    'accept_hardening' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Títulos de controles a dar por buenos'],
                    'unaccept_hardening' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'disk_rules' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => (object)[
                        'host' => ['type' => 'string'], 'mount' => ['type' => 'string'], 'threshold' => ['type' => ['number', 'null']]]],
                        'description' => 'p. ej. [{"host":"servidor2","mount":"/datos","threshold":0}]'],
                    'mail_node_after_minutes' => ['type' => 'integer'],
                    'maintenance_minutes' => ['type' => 'integer', 'description' => 'Mantenimiento programado: durante N minutos (máx. 720) no se envían avisos de "algo no responde" (réplica, nodo caído, correo, testigos…); se apuntan igual. 0 = terminarlo ya. Úsalo antes de reinicios, pruebas de relevo o mudanzas de VM.'],
                    'maintenance_reason' => ['type' => 'string', 'description' => 'Motivo del mantenimiento (se guarda en el registro)'],
                    'outage_after_minutes' => ['type' => 'integer', 'description' => 'Minutos que debe durar una caída de nodo o réplica antes de avisar (por defecto 5)'],
                    'apply' => $apply,
                ], []),
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
            'domain_redirects'      => self::redirectsList($args),
            'domain_redirect_create' => self::redirectCreate($args),
            'password_change_request' => self::passwordRequest($args),
            'mail_quota_request'      => self::quotaRequest($args),
            'monitor_stats'           => self::monitorStats($args),
            'cert_status'             => \MuseDockPanel\Services\CertWatchService::scan(),
            'alerts_status'           => self::alertsStatus(),
            'alerts_configure'        => self::alertsConfigure($args),
            'wordpress_status'        => self::wpStatus($args),
            'wordpress_scan'          => self::wpScan($args),
            'wordpress_harden'        => self::wpHarden($args),
            'wordpress_repair'        => self::wpRepair($args),
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

    private static function redirectsList(array $args): array
    {
        $f = strtolower(trim((string)($args['domain'] ?? '')));
        $rows = Database::fetchAll(
            "SELECT a.domain, a.redirect_code, a.preserve_path, a.target_url, h.domain AS hosting
               FROM hosting_domain_aliases a LEFT JOIN hosting_accounts h ON h.id = a.hosting_account_id
              WHERE a.type = 'redirect'" . ($f !== '' ? ' AND lower(a.domain) LIKE :f' : '') . ' ORDER BY a.domain',
            $f !== '' ? ['f' => "%{$f}%"] : []);
        return ['count' => count($rows), 'redirects' => array_map(static fn($r) => [
            'domain' => $r['domain'],
            'to' => $r['hosting'] ? 'https://' . $r['hosting'] . ' (hosting)' : (string)$r['target_url'],
            'code' => (int)$r['redirect_code'],
            'preserve_path' => in_array($r['preserve_path'], [true, 't', '1', 1], true),
        ], $rows)];
    }

    private static function redirectCreate(array $args): array
    {
        self::guardMaster();
        $domain = strtolower(trim((string)($args['domain'] ?? '')));
        $target = trim((string)($args['target_url'] ?? ''));
        if ($target !== '' && !preg_match('#^https?://#i', $target)) {
            $target = 'https://' . $target;
        }
        $code = (int)($args['code'] ?? 301) === 302 ? 302 : 301;
        $preserve = !array_key_exists('preserve_path', $args) || !empty($args['preserve_path']);
        $chk = \MuseDockPanel\Services\DomainAliasService::checkStandaloneRedirect($domain, $target);
        if (empty($chk['ok'])) {
            throw new \InvalidArgumentException($chk['error']);
        }
        // Que no lo sirva ya Caddy por otro lado (Caddyfile, otra aplicación…): se pisarían.
        $caddy = \MuseDockPanel\Services\CaddyDomainsService::classify();
        foreach (($caddy['domains'] ?? []) as $cd) {
            if (in_array((string)$cd['host'], \MuseDockPanel\Services\SystemService::hostsWithWww($domain), true)) {
                throw new \InvalidArgumentException("Caddy ya sirve {$cd['host']} ({$cd['group']}: {$cd['detail']}). No se crea para no pisarlo.");
            }
        }
        // A dónde apunta su DNS: el certificado solo sale si llega a este servidor.
        $mine = preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) ?: [];
        foreach (\MuseDockPanel\Services\FailoverService::getServers() as $s) {
            $mine[] = (string)($s['ip'] ?? '');
        }
        $ips = @gethostbynamel($domain) ?: [];
        $here = (bool)array_intersect($ips, array_filter($mine));
        $plan = [
            'redirect' => implode(' y ', \MuseDockPanel\Services\SystemService::hostsWithWww($domain)) . " → {$target}",
            'code' => $code === 301 ? '301 permanente' : '302 temporal',
            'path' => $preserve ? 'conserva la ruta (/x → destino/x)' : 'siempre a la portada del destino',
            'dns' => $ips ? implode(', ', $ips) . ($here ? ' — llega a este servidor (o al cluster): el certificado podrá emitirse'
                : ' — NO llega a este servidor: el certificado no saldrá hasta que el DNS apunte aquí (o el proxy de Cloudflare lo traiga)') : 'sin DNS: créalo antes (dns_record_set) o no funcionará',
            'cluster' => 'se copia a los nodos web',
        ];
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }
        $r = \MuseDockPanel\Services\DomainAliasService::createStandaloneRedirect($domain, $target, $code, $preserve);
        if (empty($r['ok'])) {
            throw new \RuntimeException($r['error'] ?? 'No se pudo crear la redirección.');
        }
        LogService::log('mcp.redirect', $domain, "Redirección creada por MCP: {$domain} → {$target} ({$code})");
        return ['status' => 'creada', 'caddy_route' => $r['caddy'] ? 'activa' : 'NO se pudo crear la ruta en Caddy (revisa /domains)'] + $plan;
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

    // ───────────────────────── Blindar WordPress ─────────────────────────

    /** Hosting y raíz WordPress de un dominio (principal o subdominio). */
    private static function wpTarget(array $args): array
    {
        $d = strtolower(trim((string)($args['domain'] ?? '')));
        $acc = WP::account($d);
        $root = $acc ? WP::docRoot($acc) : '';
        if (!$acc) {
            $sub = Database::fetchOne('SELECT s.document_root, a.domain FROM hosting_subdomains s JOIN hosting_accounts a ON a.id = s.account_id WHERE lower(s.subdomain) = :d', ['d' => $d]);
            if ($sub) {
                $acc = WP::account((string)$sub['domain']);
                $root = rtrim((string)$sub['document_root'], '/');
            }
        }
        if (!$acc) {
            throw new \InvalidArgumentException("No hay ningún hosting ni subdominio '{$d}'.");
        }
        if (!WP::isWordPress($root)) {
            throw new \InvalidArgumentException("{$d} no es un WordPress ({$root}).");
        }
        return [$acc, $root, $d];
    }

    private static function wpStatus(array $args): array
    {
        $sites = [];
        foreach (Database::fetchAll('SELECT * FROM hosting_accounts ORDER BY domain') as $acc) {
            foreach (WP::wpRoots($acc) as $root) {
                $s = WP::settingsFor((string)$acc['username']);
                $row = ['domain' => $acc['domain'], 'root' => $root, 'level' => $s['level'],
                    'xmlrpc' => $s['allow_xmlrpc'] === null ? 'auto' : ($s['allow_xmlrpc'] ? 'on' : 'off'),
                    'code' => WP::isLocked($root) ? 'cerrado (solo lectura)' : 'abierto',
                    'unlocked_until' => $s['unlock_until'] > time() ? date('Y-m-d H:i', $s['unlock_until']) : null];
                if (!empty($args['quick'])) {
                    $row['quick'] = WP::quickScan($root);
                }
                $sites[] = $row;
            }
        }
        return ['count' => count($sites), 'banned_ips_in_caddy' => WP::bannedCount(), 'sites' => $sites];
    }

    private static function wpScan(array $args): array
    {
        [$acc, $root] = self::wpTarget($args);
        $r = WP::scan($root, empty($args['offline']));
        $r['level'] = WP::settingsFor((string)$acc['username'])['level'];
        return $r;
    }

    private static function wpHarden(array $args): array
    {
        self::guardMaster();
        [$acc, $root, $d] = self::wpTarget($args);
        $cur = WP::settingsFor((string)$acc['username']);
        if (!empty($args['unlock_minutes'])) {
            if ($cur['level'] !== 'strict') {
                throw new \InvalidArgumentException("{$d} no está en strict: su código ya es editable.");
            }
            $min = max(5, min(240, (int)$args['unlock_minutes']));
            if (empty($args['apply'])) {
                return ['status' => 'plan', 'apply' => false, 'domain' => $d, 'unlock' => "el código se abre {$min} min para actualizar y luego se cierra solo"];
            }
            return ['status' => 'abierto'] + WP::unlock($acc, $min);
        }
        $level = (string)($args['level'] ?? $cur['level']);
        $x = $args['xmlrpc'] ?? null;
        $plan = ['domain' => $acc['domain'], 'from' => $cur['level'], 'to' => $level,
            'xmlrpc' => $x === null ? 'sin cambios' : $x,
            'effect' => match ($level) {
                'off' => 'sin reglas de WordPress en Caddy; si estaba en strict, el código vuelve al usuario del hosting',
                'standard' => 'xmlrpc.php cerrado (salvo Jetpack o permiso), sin PHP en uploads, ficheros sensibles y ?author= en 403. El cliente sigue instalando plugins.',
                'strict' => 'lo de standard + xmlrpc siempre cerrado + código de solo lectura para PHP (escribe solo en uploads/cache) + sin instalar/editar plugins ni temas desde el admin. Para actualizar: unlock_minutes.',
                default => throw new \InvalidArgumentException('level: off, standard o strict'),
            },
            'cluster' => 'las reglas se copian a los nodos web; el código cerrado llega con los ficheros'];
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }
        $r = WP::setLevel($acc, $level, $x === null ? 'keep' : ($x === 'auto' ? null : $x === 'on'));
        return ['status' => !empty($r['ok']) ? 'aplicado' : 'con errores', 'detail' => $r['roots']] + $plan;
    }

    private static function wpRepair(array $args): array
    {
        self::guardMaster();
        [$acc, $root, $d] = self::wpTarget($args);
        $action = (string)($args['action'] ?? '');
        $paths = array_values(array_filter(array_map('strval', (array)($args['paths'] ?? []))));
        $slug = (string)($args['slug'] ?? '');
        $plan = ['domain' => $d, 'root' => $root, 'action' => $action, 'quarantine' => WP::QUARANTINE_DIR . "/{$d}/<fecha>/ (nada se borra)"];
        $plan += match ($action) {
            'quarantine' => $paths ? ['paths' => $paths] : throw new \InvalidArgumentException('Indica `paths`.'),
            'reinstall_core' => ['what' => 'núcleo de WordPress de su misma versión desde wordpress.org; lo cambiado o de más, a cuarentena'],
            'reinstall_plugin', 'reinstall_theme' => $slug !== '' ? ['slug' => $slug, 'what' => 'copia de wordpress.org en su versión; la actual entera, a cuarentena']
                : throw new \InvalidArgumentException('Indica `slug`.'),
            'rotate_salts' => ['what' => 'claves y salts nuevas en wp-config.php (copia antes a cuarentena); se cierran TODAS las sesiones'],
            default => throw new \InvalidArgumentException('action no válida'),
        };
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }
        $user = (string)$acc['username'];
        $r = match ($action) {
            'quarantine' => WP::quarantine($root, $d, $paths),
            'reinstall_core' => WP::reinstallCore($root, $d, $user),
            'reinstall_plugin' => WP::reinstallPackage($root, $d, $user, 'plugin', $slug),
            'reinstall_theme' => WP::reinstallPackage($root, $d, $user, 'theme', $slug),
            'rotate_salts' => WP::rotateSalts($root, $d),
        };
        return ['status' => !empty($r['ok']) ? 'hecho' : 'con errores', 'result' => $r] + $plan;
    }

    // ───────────────────────── Reglas de avisos ─────────────────────────

    private static function alertsStatus(): array
    {
        $failed = [];
        try {
            foreach ((array)(\MuseDockPanel\Services\SecurityService::getHardeningAudit()['checks'] ?? []) as $c) {
                if (empty($c['ok'])) {
                    $failed[] = (string)($c['title'] ?? '') . ' (ahora: ' . (string)($c['current'] ?? '') . ')';
                }
            }
        } catch (\Throwable) {
        }
        return AP::export() + [
            'this_host' => AP::shortHost(),
            'hardening_failing_here' => $failed,
            'types' => array_map(static fn($t) => $t[0], AP::TYPES),
            'not_mutable' => 'los avisos del relevo (failover, cambio de rol) no se pueden silenciar',
        ];
    }

    private static function alertsConfigure(array $args): array
    {
        $cur = AP::export();
        $typeOf = static fn($e) => str_contains((string)$e, ':') ? explode(':', (string)$e, 2)[1] : (string)$e;
        $bad = array_diff(array_map($typeOf, array_merge((array)($args['mute'] ?? []), (array)($args['unmute'] ?? []))), array_keys(AP::TYPES));
        if ($bad) {
            throw new \InvalidArgumentException('Tipos desconocidos: ' . implode(', ', $bad) . '. Válidos: ' . implode(', ', array_keys(AP::TYPES)));
        }
        $new = $cur;
        $new['muted'] = array_values(array_diff(array_unique(array_merge($cur['muted'], (array)($args['mute'] ?? []))), (array)($args['unmute'] ?? [])));
        $new['hardening_accepted'] = array_values(array_diff(array_unique(array_merge($cur['hardening_accepted'], (array)($args['accept_hardening'] ?? []))),
            (array)($args['unaccept_hardening'] ?? [])));
        foreach ((array)($args['disk_rules'] ?? []) as $r) {
            $h = AP::shortHost((string)($r['host'] ?? ''));
            $m = (string)($r['mount'] ?? '');
            if ($h === '' || $m === '' || $m[0] !== '/') {
                throw new \InvalidArgumentException('Cada regla de disco necesita host y mount (empieza por /).');
            }
            if (!array_key_exists('threshold', $r) || $r['threshold'] === null) {
                unset($new['disk_overrides'][$h][$m]);
            } else {
                $new['disk_overrides'][$h][$m] = (float)$r['threshold'];
            }
        }
        if (isset($args['mail_node_after_minutes'])) {
            $new['mail_node_after_minutes'] = (int)$args['mail_node_after_minutes'];
        }
        if (isset($args['maintenance_minutes'])) {
            unset($new['maintenance_until_ts']);
            $new['maintenance_minutes'] = (int)$args['maintenance_minutes'];
            $new['maintenance_reason'] = (string)($args['maintenance_reason'] ?? '');
        }
        if (isset($args['outage_after_minutes'])) {
            $new['outage_after_minutes'] = (int)$args['outage_after_minutes'];
        }
        $plan = ['before' => $cur, 'after' => $new, 'nodes' => array_map(static fn($n) => (string)$n['name'], \MuseDockPanel\Services\ClusterService::getNodes())];
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            // Desde una copia: lo guarda el master y lo reparte a todos.
            $r = AP::saveViaMaster($new);
            if (empty($r['ok'])) {
                throw new \RuntimeException($r['error']);
            }
            return ['status' => 'guardado en el master', 'policy' => $r['policy'], 'copied' => $r['copied']];
        }
        $saved = AP::save($new);
        LogService::log('alerts.policy', 'mcp', 'Reglas de avisos cambiadas por MCP');
        return ['status' => 'guardado', 'policy' => $saved, 'copied' => AP::pushToNodes()];
    }

    // ───────────────────────── Estadísticas del monitor ─────────────────────────

    private static function monitorStats(array $args): array
    {
        $range = in_array($args['range'] ?? '', ['1h', '6h', '24h', '7d', '30d'], true) ? $args['range'] : '24h';
        $interval = ['1h' => '1 hour', '6h' => '6 hours', '24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'][$range];
        $host = gethostname() ?: 'localhost';
        $filter = strtolower(trim((string)($args['metric'] ?? '')));
        $fmtBytes = static function (float $b): string {
            foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
                if (abs($b) < 1024 || $u === 'TB') {
                    return round($b, 1) . " {$u}";
                }
                $b /= 1024;
            }
            return (string)$b;
        };
        if (in_array($range, ['1h', '6h', '24h'], true)) {
            $rows = Database::fetchAll("SELECT metric, avg(value) AS avg, max(value) AS max, percentile_cont(0.95) WITHIN GROUP (ORDER BY value) AS p95,
                    (array_agg(value ORDER BY ts DESC))[1] AS last, count(*) AS samples
                FROM monitor_metrics WHERE host = :h AND ts >= NOW() - INTERVAL '{$interval}' GROUP BY metric ORDER BY metric", ['h' => $host]);
        } else {
            $table = $range === '7d' ? 'monitor_metrics_hourly' : 'monitor_metrics_daily';
            $rows = Database::fetchAll("SELECT metric, avg(avg_val) AS avg, max(max_val) AS max, avg(p95_val) AS p95,
                    (array_agg(avg_val ORDER BY ts DESC))[1] AS last, sum(samples) AS samples
                FROM {$table} WHERE host = :h AND ts >= NOW() - INTERVAL '{$interval}' GROUP BY metric ORDER BY metric", ['h' => $host]);
        }
        $metrics = [];
        foreach ($rows as $r) {
            $m = (string)$r['metric'];
            if ($filter !== '' && !str_contains(strtolower($m), $filter)) {
                continue;
            }
            $isBytes = (bool)preg_match('/(_rx|_tx|_read|_write|bytes_sec)$/', $m);
            $f = static fn($v) => $v === null ? null : ($isBytes ? $fmtBytes((float)$v) . '/s' : round((float)$v, 2));
            $metrics[$m] = ['avg' => $f($r['avg']), 'max' => $f($r['max']), 'p95' => $f($r['p95']), 'last' => $f($r['last']), 'samples' => (int)$r['samples']];
        }
        $web = [];
        try {
            $per = Database::fetchAll("SELECT a.domain, COALESCE(SUM(b.bytes_out), 0) AS bytes, COALESCE(SUM(b.requests), 0) AS req
                FROM hosting_bandwidth b JOIN hosting_accounts a ON a.id = b.account_id
                WHERE b.ts >= NOW() - INTERVAL '{$interval}' GROUP BY a.domain ORDER BY bytes DESC LIMIT 25");
            $month = \MuseDockPanel\Services\BandwidthService::getAllMonthlyTotals();
            uasort($month, static fn($a, $b) => (float)$b['bytes_out'] <=> (float)$a['bytes_out']);
            $dom = array_column(Database::fetchAll('SELECT id, domain FROM hosting_accounts'), 'domain', 'id');
            $web = [
                'by_hosting_in_range' => array_map(static fn($r) => ['domain' => $r['domain'], 'sent' => $fmtBytes((float)$r['bytes']), 'requests' => (int)$r['req']], $per),
                'month_top' => array_slice(array_map(static fn($id, $r) => ['domain' => $dom[$id] ?? "#{$id}", 'sent' => $fmtBytes((float)$r['bytes_out']), 'requests' => (int)$r['requests']],
                    array_keys($month), $month), 0, 25),
            ];
        } catch (\Throwable $e) {
            $web = ['error' => 'sin datos de ancho de banda por hosting: ' . $e->getMessage()];
        }
        $note = null;
        if (!isset($metrics['bw_bytes_sec']) && ($filter === '' || str_contains('bw_bytes_sec', $filter))) {
            $note = 'Sin tráfico web en el monitor: Caddy no escribe /var/log/caddy/hosting-access.log en este nodo (o es una copia sin tráfico). El panel lo repara solo cada 30 min.';
        }
        return ['host' => $host, 'range' => $range, 'metrics' => $metrics, 'web' => $web, 'note' => $note];
    }
}
