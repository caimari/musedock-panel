<?php
namespace MuseDockPanel\Mcp;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\FailoverService;
use MuseDockPanel\Services\MailService;

/**
 * Catálogo de herramientas del servidor MCP de MuseDock Panel.
 *
 * FASE 1: todas las herramientas son de SOLO LECTURA. Ninguna cambia nada en el
 * servidor. Todas aceptan el argumento opcional `node` (id o nombre de un nodo del
 * cluster) para ejecutarse en ese nodo a través de la API del cluster (acción
 * `mcp-call`), de modo que desde el master se puede consultar cualquier slave.
 *
 * Todo lo que sale pasa por redact(): nunca se devuelven contraseñas, tokens,
 * claves privadas ni hashes. Recordatorio: panel_log se REPLICA a todos los nodos,
 * así que los argumentos tampoco se registran sin redactar.
 */
final class McpTools
{
    /** Superset de ClusterApiController::SECRET_KEYS + tokens de terceros. */
    private const SECRET_KEYS = [
        'master_db_pass', 'db_pass', 'dsync_secret', 'shared_secret', 'password', 'admin_password',
        'setup_token', 'db_password', 'secret', 'password_hash', 'dkim_private_key', 'enc_key',
        'token', 'auth_token', 'api_token', 'api_key', 'apikey', 'cf_token', 'private_key',
        'passphrase', 'client_secret', 'access_key', 'secret_key',
    ];
    private const MAX_OUTPUT = 60000;

    // ─────────────────────────────────────────────────────────────────────
    // Definiciones
    // ─────────────────────────────────────────────────────────────────────

    public static function definitions(): array
    {
        $node = ['type' => 'string', 'description' => 'Opcional. Id o nombre de un nodo del cluster para ejecutar la consulta en él (vía API del cluster). Omitir = este servidor.'];
        $obj = static fn(array $props = [], array $required = []) => array_filter([
            'type' => 'object',
            'properties' => (object)($props + ['node' => $node]),
            'required' => $required ?: null,
            'additionalProperties' => false,
        ], static fn($v) => $v !== null);

        return [
            'panel_info' => [
                'title' => 'Información del panel',
                'description' => 'Versión de MuseDock Panel, hostname, rol en el cluster (master/slave/standalone), carga y uptime del servidor.',
                'inputSchema' => $obj(),
            ],
            'list_nodes' => [
                'title' => 'Nodos del cluster',
                'description' => 'Lista los nodos registrados en el cluster de este servidor (id, nombre, rol, estado, último contacto, servicios, versión). Usa el id o nombre en el argumento `node` de otras herramientas.',
                'inputSchema' => $obj(),
            ],
            'node_status' => [
                'title' => 'Estado del nodo',
                'description' => 'Estado detallado del servidor: roles de replicación, disco, RAM, CPU, nº de hostings, estado de PostgreSQL/MySQL.',
                'inputSchema' => $obj(),
            ],
            'services_status' => [
                'title' => 'Estado de servicios',
                'description' => 'Estado systemd (active/inactive/failed) de los servicios relevantes: Caddy, panel, PHP-FPM, PostgreSQL, MariaDB/MySQL, Redis, Postfix, Dovecot, OpenDKIM, fail2ban, cron, supervisor.',
                'inputSchema' => $obj(),
            ],
            'failover_status' => [
                'title' => 'Estado del failover',
                'description' => 'Modo (manual/semiauto/auto), estado actual, última acción y servidores configurados del failover DNS. Sin tokens.',
                'inputSchema' => $obj(),
            ],
            'hosting_accounts' => [
                'title' => 'Hostings',
                'description' => 'Hostings gestionados por el panel: dominio, usuario, versión PHP, tipo, estado, disco usado.',
                'inputSchema' => $obj(),
            ],
            'mail_domains' => [
                'title' => 'Dominios de correo',
                'description' => 'Dominios de correo del panel con nº de buzones y alias.',
                'inputSchema' => $obj(),
            ],
            'mail_domain' => [
                'title' => 'Detalle de dominio de correo',
                'description' => 'Buzones (sin contraseñas) y alias/catch-all de un dominio de correo.',
                'inputSchema' => $obj(['domain' => ['type' => 'string', 'description' => 'Dominio, p. ej. screenart.es']], ['domain']),
            ],
            'tls_check' => [
                'title' => 'Comprobar certificado TLS',
                'description' => 'Hace un handshake TLS con SNI y devuelve el certificado servido (sujeto, emisor, SANs, caducidad y días restantes). `target`=local prueba contra este servidor (127.0.0.1), `public` contra la IP pública que resuelva el DNS.',
                'inputSchema' => $obj([
                    'host' => ['type' => 'string', 'description' => 'Hostname, p. ej. muserelay.com'],
                    'target' => ['type' => 'string', 'enum' => ['local', 'public'], 'description' => 'local (por defecto) o public'],
                    'port' => ['type' => 'integer', 'description' => 'Puerto (por defecto 443)'],
                ], ['host']),
            ],
            'domains_status' => [
                'title' => 'Estado de todos los dominios (registro, DNS y uso)',
                'description' => 'Todos los dominios de este servidor (hostings del panel, los que sirve Caddy por otras aplicaciones como el CMS, Caddyfile y correo), cada uno con: registro (activo, caducado, en redención, libre; fecha de caducidad y registrador, por RDAP), DNS (apunta aquí, a otro sitio o sin DNS) y dónde se usa. Avisa de dominios libres o en redención que siguen en uso, de los que caducan en menos de 30 días y de los registrados sin DNS. El registro se guarda 24 h (refresh=true para forzar). Solo lectura.',
                'inputSchema' => $obj(['refresh' => ['type' => 'boolean', 'description' => 'Volver a consultar el registro aunque esté guardado']]),
            ],
            'caddy_domains' => [
                'title' => 'Dominios de Caddy y de dónde vienen',
                'description' => 'Todos los dominios que sirve Caddy en este servidor, clasificados: panel_hosting (hostings del panel: dominio, www, dominio extra, subdominio, alias y redirecciones), panel_system (dominio del panel, webmail, CardDAV, certificado del correo), caddyfile (escritos en /etc/caddy/Caddyfile) y external (añadidos por la API de Caddy por otra aplicación, p. ej. los tenants del CMS MuseDock con @id route_*). Avisa de rutas con el mismo @id repetidas. Solo lectura.',
                'inputSchema' => $obj(),
            ],
            'caddy_hosts' => [
                'title' => 'Hosts servidos por Caddy',
                'description' => 'Hosts que Caddy sirve ahora mismo (config en ejecución), agrupados por servidor HTTP.',
                'inputSchema' => $obj(),
            ],
            'clone_inventory' => [
                'title' => 'Inventario para clonar el servidor',
                'description' => 'Inventario de todo lo que habría que clonar para montar un slave EXACTO de este servidor, sobre todo lo que el panel NO gestiona. Incluye un "checklist" en lenguaje llano. Secciones: sites (Caddyfile con root/reverse_proxy), processes (puertos y procesos de apps con QUIÉN los arranca: supervisor/systemd/pm2/cron/manual), services (unidades propias, drop-ins, supervisor, servicios en marcha/fallidos), apps (proyectos: git, lo que no está en git, variables del .env que dependen del servidor —solo nombres—, composer/package.json), databases (PostgreSQL con deriva listen y pg_hba, MySQL, Redis), cron (contenido enmascarado), runtime (PHP+extensiones por versión, Node, apt manual), network (WireGuard, firewall, ficheros de /etc que citan IPs locales), caddy (opciones globales, certificados). Para comparar dos servidores pide la misma sección en ambos. Solo lectura; nunca devuelve valores de .env ni secretos.',
                'inputSchema' => $obj([
                    'section' => ['type' => 'string', 'enum' => array_merge(['all'], McpInventory::SECTIONS),
                        'description' => 'Sección a devolver (por defecto all). Útil si la salida completa es grande.'],
                ]),
            ],
        ];
    }

    /** Todas las herramientas: las de lectura de arriba + correo + cluster/failover. */
    public static function all(): array
    {
        return self::definitions() + McpMailTools::definitions() + McpClusterTools::definitions();
    }

    /** Formato tools/list de MCP. */
    public static function listForMcp(): array
    {
        $out = [];
        foreach (self::all() as $name => $d) {
            $write = !empty($d['write']);
            $out[] = [
                'name' => $name,
                'title' => $d['title'],
                'description' => $d['description'],
                'inputSchema' => $d['inputSchema'],
                // Las de escritura se anuncian como tal: el cliente MCP pide permiso al
                // usuario en cada llamada.
                'annotations' => [
                    'readOnlyHint' => !$write,
                    'destructiveHint' => !empty($d['destructive']),
                    'idempotentHint' => true,
                    'openWorldHint' => $name === 'mail_dns_publish',
                ],
            ];
        }
        return $out;
    }

    public static function exists(string $name): bool
    {
        return array_key_exists($name, self::all());
    }

    public static function isWrite(string $name): bool
    {
        return !empty(self::all()[$name]['write']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ejecución
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Ejecuta una herramienta y devuelve el resultado en formato MCP (tools/call).
     * $via: 'http' | 'stdio' (solo para el registro de auditoría).
     */
    public static function call(string $name, array $args, string $via): array
    {
        $node = trim((string)($args['node'] ?? ''));
        unset($args['node']);
        $isLocal = $node === '' || strtolower($node) === 'local';
        $where = $isLocal ? 'local' : "nodo {$node}";

        // Candados de escritura (aplican a HTTP y a stdio por igual).
        if (self::isWrite($name)) {
            if (Settings::get('mcp_allow_write', '0') !== '1') {
                return self::result(['error' => 'Las acciones que modifican están desactivadas en este servidor. '
                    . 'Actívalas en Ajustes → MCP → "Permitir acciones que modifican".'], true);
            }
            if (!$isLocal) {
                return self::result(['error' => 'Las acciones que modifican solo se ejecutan en el panel al que estás conectado, no se reenvían a otros nodos.'], true);
            }
        }

        // La etiqueta de auditoría lleva "APPLY" cuando se ejecuta de verdad; la
        // decisión local/remoto va aparte ($isLocal). Antes se comparaba $where con
        // 'local' DESPUÉS de añadirle ", APPLY", y toda escritura con apply=true se
        // intentaba reenviar a un nodo vacío ("Nodo '' no encontrado").
        $auditWhere = $where . (self::isWrite($name) && !empty($args['apply']) ? ', APPLY' : '');
        try {
            \MuseDockPanel\Services\LogService::log('mcp.call', $name, "via {$via}, {$auditWhere}");
        } catch (\Throwable) {
            // auditoría best-effort
        }

        try {
            if ($isLocal) {
                $data = self::runLocal($name, $args);
            } else {
                $data = self::runOnNode($node, $name, $args);
            }
            return self::result($data, false);
        } catch (\Throwable $e) {
            return self::result(['error' => $e->getMessage()], true);
        }
    }

    /** Ejecuta en ESTE servidor. También lo usa la acción de cluster `mcp-call`. */
    public static function runLocal(string $name, array $args): array
    {
        return match ($name) {
            'panel_info'       => self::panelInfo(),
            'list_nodes'       => self::listNodes(),
            'node_status'      => ClusterService::getLocalStatus(),
            'services_status'  => self::servicesStatus(),
            'failover_status'  => FailoverService::getStatusSummary(),
            'hosting_accounts' => self::hostingAccounts(),
            'mail_domains'     => self::mailDomains(),
            'mail_domain'      => self::mailDomain((string)($args['domain'] ?? '')),
            'tls_check'        => self::tlsCheck((string)($args['host'] ?? ''), (string)($args['target'] ?? 'local'), (int)($args['port'] ?? 443)),
            'caddy_hosts'      => self::caddyHosts(),
            'caddy_domains'    => self::caddyDomains(),
            'domains_status'   => \MuseDockPanel\Services\DomainStatusService::report(!empty($args['refresh'])),
            'clone_inventory'  => McpInventory::build((string)($args['section'] ?? 'all')),
            default            => match (true) {
                McpMailTools::has($name)    => McpMailTools::run($name, $args),
                McpClusterTools::has($name) => McpClusterTools::run($name, $args),
                default                     => throw new \InvalidArgumentException("Herramienta desconocida: {$name}"),
            },
        };
    }

    private static function runOnNode(string $node, string $name, array $args): array
    {
        // Primero id o nombre exactos; solo si no hay, coincidencia parcial del nombre
        // (antes "1" cogía "Filemon (154)" en vez del nodo con id 1).
        $target = null;
        $nodes = ClusterService::getNodes();
        foreach ($nodes as $n) {
            if ((string)$n['id'] === $node || strcasecmp((string)$n['name'], $node) === 0) {
                $target = $n;
                break;
            }
        }
        if (!$target && !ctype_digit($node)) {
            $partial = array_values(array_filter($nodes, static fn($n) => stripos((string)$n['name'], $node) !== false));
            if (count($partial) > 1) {
                throw new \RuntimeException("'{$node}' coincide con varios nodos: " . implode(', ', array_column($partial, 'name')) . '. Usa el id.');
            }
            $target = $partial[0] ?? null;
        }
        if (!$target) {
            throw new \RuntimeException("Nodo '{$node}' no encontrado. Usa list_nodes para ver los nodos.");
        }
        $r = ClusterService::callNode((int)$target['id'], 'POST', '/api/cluster/action', [
            'action' => 'mcp-call',
            'payload' => ['tool' => $name, 'arguments' => $args],
        ]);
        $body = $r['data'] ?? [];
        if (empty($r['ok']) || empty($body['ok'])) {
            $err = (string)($body['error'] ?? $r['error'] ?? ('HTTP ' . ($r['status'] ?? '?')));
            throw new \RuntimeException("El nodo {$target['name']} respondió con error: {$err}");
        }
        return ['node' => $target['name'], 'result' => $body['data'] ?? null];
    }

    private static function result(array $data, bool $isError): array
    {
        $json = json_encode(self::redact($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = '{"error":"no se pudo serializar el resultado"}';
            $isError = true;
        }
        if (strlen($json) > self::MAX_OUTPUT) {
            $json = substr($json, 0, self::MAX_OUTPUT) . "\n… [salida truncada a " . self::MAX_OUTPUT . " bytes]";
        }
        return ['content' => [['type' => 'text', 'text' => $json]], 'isError' => $isError];
    }

    public static function redact(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::SECRET_KEYS, true)) {
                $data[$k] = ($v === null || $v === '') ? $v : '[REDACTED]';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            }
        }
        return $data;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Herramientas
    // ─────────────────────────────────────────────────────────────────────

    private static function sh(string $cmd, int $timeout = 5): string
    {
        return trim((string)shell_exec('timeout ' . (int)$timeout . ' sh -c ' . escapeshellarg($cmd) . ' 2>/dev/null'));
    }

    private static function panelVersion(): string
    {
        $cfg = @file_get_contents(PANEL_ROOT . '/config/panel.php') ?: '';
        return preg_match("/'version'\s*=>\s*'([^']+)'/", $cfg, $m) ? $m[1] : 'desconocida';
    }

    private static function panelInfo(): array
    {
        $role = Settings::get('cluster_role', '');
        if ($role === '') {
            $role = \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
        }
        return [
            'hostname' => gethostname(),
            'panel_version' => self::panelVersion(),
            'cluster_role' => $role,
            'panel_hostname' => Settings::get('panel_hostname', ''),
            'uptime' => self::sh('uptime -p'),
            'load_avg' => sys_getloadavg(),
            'cpus' => (int)self::sh('nproc'),
            'os' => self::sh('. /etc/os-release && echo "$PRETTY_NAME"'),
            'php' => PHP_VERSION,
            'time_utc' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private static function listNodes(): array
    {
        $out = [];
        foreach (ClusterService::getNodes() as $n) {
            $meta = json_decode((string)($n['metadata'] ?? '{}'), true) ?: [];
            $seen = $n['last_seen_at'] ? strtotime((string)$n['last_seen_at']) : 0;
            $out[] = [
                'id' => (int)$n['id'],
                'name' => $n['name'],
                'role' => $n['role'],
                'status' => $n['status'],
                'host' => parse_url((string)$n['api_url'], PHP_URL_HOST),
                'services' => json_decode((string)($n['services'] ?? '[]'), true),
                'standby' => (bool)$n['standby'],
                'last_seen_at' => $n['last_seen_at'],
                'seconds_since_seen' => $seen ? time() - $seen : null,
                'panel_version' => $meta['panel_version'] ?? null,
                'mail_mode' => $n['mail_mode'] ?? null,
            ];
        }
        return ['count' => count($out), 'nodes' => $out,
            'note' => $out ? null : 'Este servidor no tiene nodos registrados (standalone o slave sin vista del cluster).'];
    }

    private static function servicesStatus(): array
    {
        $candidates = ['caddy', 'musedock-panel', 'postgresql', 'mariadb', 'mysql', 'redis-server', 'redis',
            'postfix', 'dovecot', 'opendkim', 'fail2ban', 'cron', 'supervisor', 'docker', 'wg-quick@wg0'];
        foreach (glob('/etc/php/*/fpm') ?: [] as $d) {
            $candidates[] = 'php' . basename(dirname($d)) . '-fpm';
        }
        // Una sola llamada a `systemctl show` (rápida): LoadState=not-found descarta
        // lo que no existe, e Id resuelve alias (mysql→mariadb, redis→redis-server)
        // para no listar el mismo servicio dos veces. (list-unit-files tardaba ~2,5 s
        // y el panel es monohilo: bloqueaba todo lo demás mientras tanto.)
        $raw = self::sh('systemctl show --no-pager --property=Id,LoadState,ActiveState '
            . implode(' ', array_map(static fn($s) => escapeshellarg($s . '.service'), $candidates)));
        $out = [];
        foreach (preg_split('/\n\s*\n/', $raw) ?: [] as $block) {
            $p = [];
            foreach (explode("\n", trim($block)) as $l) {
                [$k, $v] = array_pad(explode('=', $l, 2), 2, '');
                $p[$k] = $v;
            }
            if (($p['LoadState'] ?? '') !== 'loaded' || empty($p['Id'])) {
                continue;
            }
            $out[preg_replace('/\.service$/', '', $p['Id'])] = $p['ActiveState'] ?? 'unknown';
        }
        ksort($out);
        $failed = array_keys(array_filter($out, static fn($s) => $s !== 'active'));
        return ['services' => $out, 'not_active' => $failed];
    }

    private static function hostingAccounts(): array
    {
        $rows = Database::fetchAll(
            "SELECT id, domain, username, php_version, hosting_type, status, disk_used_mb, disk_quota_mb, document_root, created_at
             FROM hosting_accounts ORDER BY domain"
        );
        return ['count' => count($rows), 'hostings' => $rows];
    }

    private static function mailDomains(): array
    {
        $rows = Database::fetchAll(
            "SELECT d.id, d.domain, d.status, d.mail_mode, d.dkim_selector, d.created_at,
                    (SELECT COUNT(*) FROM mail_accounts a WHERE a.mail_domain_id = d.id) AS mailboxes,
                    (SELECT COUNT(*) FROM mail_aliases al WHERE al.mail_domain_id = d.id) AS aliases
             FROM mail_domains d ORDER BY d.domain"
        );
        return ['count' => count($rows), 'domains' => $rows];
    }

    private static function mailDomain(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            throw new \InvalidArgumentException('Dominio no válido.');
        }
        $d = Database::fetchOne("SELECT id, domain, status, mail_mode, dkim_selector, created_at FROM mail_domains WHERE domain = :d", ['d' => $domain]);
        if (!$d) {
            throw new \RuntimeException("El dominio de correo {$domain} no existe en este panel.");
        }
        $accounts = Database::fetchAll(
            "SELECT email, display_name, status, quota_mb, used_mb, send_mode, can_send, rate_limit_per_hour, last_login_at
             FROM mail_accounts WHERE mail_domain_id = :i ORDER BY email", ['i' => $d['id']]);
        $aliases = Database::fetchAll(
            "SELECT source, destination, is_catchall FROM mail_aliases WHERE mail_domain_id = :i ORDER BY source", ['i' => $d['id']]);
        return ['domain' => $d, 'mailboxes' => $accounts, 'aliases' => $aliases];
    }

    private static function tlsCheck(string $host, string $target, int $port): array
    {
        $host = strtolower(trim($host));
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9-]{1,63}\.)+[a-z]{2,63}$/', $host)) {
            throw new \InvalidArgumentException('Host no válido.');
        }
        $port = ($port > 0 && $port < 65536) ? $port : 443;
        $addr = $target === 'public' ? gethostbyname($host) : '127.0.0.1';
        if ($target === 'public' && $addr === $host) {
            throw new \RuntimeException("No se pudo resolver {$host}.");
        }
        $ctx = stream_context_create(['ssl' => [
            'SNI_enabled' => true, 'peer_name' => $host, 'verify_peer' => false,
            'verify_peer_name' => false, 'capture_peer_cert' => true,
        ]]);
        $t = microtime(true);
        $cli = @stream_socket_client("ssl://{$addr}:{$port}", $errno, $errstr, 6, STREAM_CLIENT_CONNECT, $ctx);
        if ($cli === false) {
            return ['host' => $host, 'target' => $target, 'address' => $addr, 'ok' => false,
                'error' => "Handshake TLS fallido: {$errstr} ({$errno}). Si es 'no peer certificate', Caddy no sirve certificado para este host."];
        }
        $cert = stream_context_get_params($cli)['options']['ssl']['peer_certificate'] ?? null;
        fclose($cli);
        if (!$cert) {
            return ['host' => $host, 'target' => $target, 'address' => $addr, 'ok' => false, 'error' => 'Sin certificado del servidor.'];
        }
        $p = openssl_x509_parse($cert);
        $sans = array_map(static fn($s) => trim(str_replace('DNS:', '', $s)), explode(',', (string)($p['extensions']['subjectAltName'] ?? '')));
        $covers = false;
        foreach ($sans as $s) {
            if ($s === $host || (str_starts_with($s, '*.') && str_ends_with($host, substr($s, 1)) && substr_count($host, '.') === substr_count($s, '.'))) {
                $covers = true;
            }
        }
        $until = (int)($p['validTo_time_t'] ?? 0);
        return [
            'host' => $host, 'target' => $target, 'address' => $addr, 'port' => $port, 'ok' => $covers && $until > time(),
            'subject_cn' => $p['subject']['CN'] ?? null,
            'issuer' => trim(($p['issuer']['O'] ?? '') . ' ' . ($p['issuer']['CN'] ?? '')),
            'sans' => array_values(array_filter($sans)),
            'covers_host' => $covers,
            'valid_until' => $until ? gmdate('Y-m-d H:i', $until) . ' UTC' : null,
            'days_left' => $until ? (int)floor(($until - time()) / 86400) : null,
            'handshake_ms' => (int)round((microtime(true) - $t) * 1000),
        ];
    }

    private static function caddyServers(): array
    {
        $api = 'http://localhost:2019';
        $ctx = stream_context_create(['http' => ['timeout' => 4]]);
        $servers = json_decode((string)@file_get_contents("{$api}/config/apps/http/servers", false, $ctx), true);
        return is_array($servers) ? $servers : [];
    }

    private static function caddyDomains(): array
    {
        $r = \MuseDockPanel\Services\CaddyDomainsService::classify();
        if (empty($r['ok'])) {
            return $r;
        }
        $labels = [
            'panel_hosting' => 'Hostings del panel',
            'panel_system' => 'Propios del panel',
            'caddyfile' => 'En el Caddyfile',
            'external' => 'Fuera del panel (API de Caddy por otra aplicación)',
        ];
        $groups = [];
        foreach ($r['domains'] as $d) {
            $groups[$d['group']][] = ['host' => $d['host'], 'detail' => $d['detail']];
        }
        $summary = [];
        foreach ($labels as $k => $label) {
            $summary[$label] = count($groups[$k] ?? []);
        }
        $out = ['total' => count($r['domains']), 'summary' => $summary];
        foreach ($labels as $k => $label) {
            $out[$k] = $groups[$k] ?? [];
        }
        if (!empty($r['duplicate_route_ids'])) {
            $out['warnings'] = array_map(static fn($id, $n) => "La ruta @id «{$id}» está repetida {$n} veces en la configuración de Caddy (solo cuenta la primera; las demás sobran).",
                array_keys($r['duplicate_route_ids']), $r['duplicate_route_ids']);
        }
        return $out;
    }

    private static function caddyHosts(): array
    {
        $servers = self::caddyServers();
        if (!$servers) {
            return ['ok' => false, 'error' => 'No se pudo leer la API de Caddy (localhost:2019).'];
        }
        $by = [];
        $all = [];
        foreach ($servers as $name => $srv) {
            $hosts = [];
            foreach (($srv['routes'] ?? []) as $route) {
                foreach (($route['match'] ?? []) as $m) {
                    foreach (($m['host'] ?? []) as $h) {
                        $hosts[$h] = true;
                        $all[$h] = true;
                    }
                }
            }
            ksort($hosts);
            $by[$name] = ['listen' => $srv['listen'] ?? [], 'routes' => count($srv['routes'] ?? []), 'hosts' => array_keys($hosts)];
        }
        return ['total_hosts' => count($all), 'servers' => $by];
    }
}
