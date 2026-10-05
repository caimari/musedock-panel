<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * Portal de clientes (/opt/musedock-portal) dentro del cluster.
 *
 * - Se publica con un nombre propio (ajuste `portal_hostname`, p. ej. portal.<dominio>),
 *   nunca con el nombre de una máquina: en un relevo el DNS de ese nombre se mueve
 *   con el resto y el portal sigue en la misma dirección.
 * - Encendido (servicio musedock-portal + ruta de Caddy `portal-domain-route` en el
 *   puerto del portal) SOLO en el nodo que manda; apagado en las copias y en un nodo
 *   apartado. apply() lo deja como toca y se llama al promover, al degradar y cada
 *   5 min desde el cluster-worker (repone la ruta tras una recarga de Caddy).
 * - La base del panel es de cada nodo: los clientes del portal (tabla customers) y a
 *   quién pertenece cada hosting se copian del master a las copias (pullFromMaster),
 *   emparejando por email y por dominio (los ids no coinciden entre nodos).
 *
 * El panel no depende del portal: si no está instalado, todo esto no hace nada.
 */
final class PortalService
{
    public const DIR = '/opt/musedock-portal';
    public const UNIT = 'musedock-portal.service';
    public const ROUTE_ID = 'portal-domain-route';
    /** Servidor de Caddy que crea el panel si nadie escucha aún en el puerto del portal. */
    private const SERVER = 'srv_portal';
    /** Emails de clientes recibidos del master (los únicos que la copia puede desactivar). */
    private const SYNCED_KEY = 'portal_sync_customer_emails';
    /** Ajustes del portal que valen igual en todo el cluster. */
    // portal_instance_id: identificador del clúster ante el servidor de licencias (igual en todos
    // los nodos, para que la licencia siga al que manda sin transferirla).
    private const SHARED_SETTINGS = ['portal_hostname', 'portal_port', 'portal_theme', 'portal_sidebar_color', 'portal_license_jwt', 'portal_instance_id', 'portal_session_remember_days', 'portal_favicon', 'portal_favicon_type'];
    private const CUSTOMER_COLS = ['uid', 'name', 'email', 'company', 'phone', 'password_hash', 'status', 'notes',
        'created_at', 'updated_at', 'password_token', 'password_token_expires'];

    /** Favicon del portal: como máximo 64 KB, SVG, PNG o ICO (se mira el contenido, no la extensión). */
    public const FAVICON_MAX_BYTES = 65536;

    /**
     * Comprueba un favicon subido. Devuelve [tipo MIME, null] o [null, motivo].
     * SVG: XML con raíz <svg>, sin scripts, eventos, enlaces externos ni contenido incrustado.
     */
    public static function validateFavicon(string $bytes): array
    {
        $len = strlen($bytes);
        if ($len === 0) {
            return [null, 'El fichero está vacío.'];
        }
        if ($len > self::FAVICON_MAX_BYTES) {
            return [null, 'El fichero es demasiado grande (máximo 64 KB).'];
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            $info = @getimagesizefromstring($bytes);
            if (!$info || ($info['mime'] ?? '') !== 'image/png' || $info[0] < 16 || $info[0] > 1024 || $info[1] < 16 || $info[1] > 1024) {
                return [null, 'El PNG no es válido (entre 16 y 1024 píxeles de lado).'];
            }
            return ['image/png', null];
        }
        if (str_starts_with($bytes, "\x00\x00\x01\x00") && $len >= 22) {
            $count = unpack('v', substr($bytes, 4, 2))[1] ?? 0;
            if ($count < 1 || $count > 32 || $len < 6 + 16 * $count) {
                return [null, 'El ICO no es válido.'];
            }
            return ['image/x-icon', null];
        }
        $text = ltrim($bytes, "\xEF\xBB\xBF \t\r\n");
        if (stripos($text, '<svg') !== false && (str_starts_with($text, '<svg') || str_starts_with($text, '<?xml') || str_starts_with($text, '<!--'))) {
            if (preg_match('/<!DOCTYPE|<!ENTITY|<script|<foreignObject|<iframe|<embed|<object|\son[a-z]+\s*=|javascript:|data:text|(?:xlink:)?href\s*=\s*["\']?\s*(?!#)/i', $text)) {
                return [null, 'El SVG lleva scripts, eventos o enlaces: no se acepta por seguridad.'];
            }
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            if (!$xml || strtolower($xml->getName()) !== 'svg') {
                return [null, 'El SVG no es válido.'];
            }
            return ['image/svg+xml', null];
        }
        return [null, 'Formato no admitido: sube un SVG, PNG o ICO.'];
    }

    /** Guarda el favicon (ya validado) en los ajustes; se copia a las réplicas. '' = el de por defecto. */
    public static function saveFavicon(string $bytes, string $mime): void
    {
        Settings::set('portal_favicon', $bytes === '' ? '' : base64_encode($bytes));
        Settings::set('portal_favicon_type', $bytes === '' ? '' : $mime);
    }

    public static function installed(): bool
    {
        return is_file(self::DIR . '/bootstrap.php');
    }

    /** Nombre público del portal (sin esquema ni puerto), o '' si no se ha configurado. */
    public static function hostname(): string
    {
        return self::normalizeHostname((string)Settings::get('portal_hostname', ''));
    }

    public static function normalizeHostname(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string)preg_replace('#^https?://#', '', $value);
        $value = explode('/', $value, 2)[0];
        $value = explode(':', $value, 2)[0];
        return trim($value, ". \t");
    }

    public static function validHostname(string $host): bool
    {
        return strlen($host) <= 253 && str_contains($host, '.')
            && (bool)preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $host);
    }

    public static function publicPort(): int
    {
        $p = (int)(Settings::get('portal_port', '') ?: \MuseDockPanel\Env::get('PORTAL_PORT', '8446'));
        return ($p > 0 && $p < 65535) ? $p : 8446;
    }

    /**
     * Puerto interno del proceso PHP del portal: el que escucha de verdad su unidad
     * (`-S 127.0.0.1:NNNN`). NUNCA se deduce del puerto público elegido para la ruta
     * (con 443 daba 444 y Caddy respondía 502). Sin unidad: PORTAL_INTERNAL_PORT o el
     * del instalador (PORTAL_PORT + 1 = 8447).
     */
    public static function internalPort(): int
    {
        $unit = (string)@file_get_contents('/etc/systemd/system/' . self::UNIT);
        if (preg_match('/-S\s+127\.0\.0\.1:(\d{2,5})\b/', $unit, $m) && (int)$m[1] > 0 && (int)$m[1] < 65536) {
            return (int)$m[1];
        }
        $p = (int)\MuseDockPanel\Env::get('PORTAL_INTERNAL_PORT', 0);
        if ($p > 0) {
            return $p;
        }
        $installerPort = (int)\MuseDockPanel\Env::get('PORTAL_PORT', '8446');
        return ($installerPort > 0 ? $installerPort : 8446) + 1;
    }

    /** URL pública del portal, o '' si no tiene nombre propio. */
    public static function url(): string
    {
        $host = self::hostname();
        if ($host === '') {
            return '';
        }
        $port = self::publicPort();
        return "https://{$host}" . ($port === 443 ? '' : ":{$port}");
    }

    private static function role(): string
    {
        return Settings::get('cluster_role', '') ?: \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
    }

    private static function fenced(): bool
    {
        return Settings::get('cluster_fenced', '0') === '1' || is_file(FailoverSafetyService::FENCE_FLAG);
    }

    /** ¿Debe servir el portal este nodo? Solo el que manda (master o sin cluster) y no apartado. */
    public static function shouldServe(): bool
    {
        return self::installed() && self::role() !== 'slave' && !self::fenced();
    }

    // ── Encender / apagar ────────────────────────────────────────────────

    /**
     * Deja el portal como le toca a este nodo. Devuelve lo hecho (líneas legibles).
     * Idempotente: si ya está bien, no toca nada.
     */
    public static function apply(): array
    {
        if (!self::installed()) {
            return [];
        }
        return self::shouldServe() ? self::start() : self::stop();
    }

    public static function start(): array
    {
        $done = [];
        if (self::ensureUnitFile()) {
            $done[] = 'portal: unidad ' . self::UNIT . ' creada';
        }
        if (self::unitActive() !== 'active' || !self::unitEnabled()) {
            shell_exec('systemctl enable --now ' . escapeshellarg(self::UNIT) . ' >/dev/null 2>&1');
            $done[] = 'portal: servicio ' . (self::unitActive() === 'active' ? 'arrancado' : 'NO arranca (systemctl status ' . self::UNIT . ')');
        }
        $r = self::ensureRoute();
        if (empty($r['ok'])) {
            $done[] = 'portal: ruta de Caddy NO puesta: ' . ($r['error'] ?? '?');
        } elseif (empty($r['idempotent']) && empty($r['skipped'])) {
            $done[] = 'portal: ruta de Caddy puesta para ' . self::hostname() . ':' . self::publicPort();
        }
        return $done;
    }

    public static function stop(): array
    {
        $done = [];
        if (is_file('/etc/systemd/system/' . self::UNIT) && (self::unitActive() === 'active' || self::unitEnabled())) {
            shell_exec('systemctl disable --now ' . escapeshellarg(self::UNIT) . ' >/dev/null 2>&1');
            $done[] = 'portal: servicio parado y deshabilitado';
        }
        if (self::removeRoute()) {
            $done[] = 'portal: ruta de Caddy quitada';
        }
        return $done;
    }

    private static function unitActive(): string
    {
        return trim((string)shell_exec('systemctl is-active ' . escapeshellarg(self::UNIT) . ' 2>/dev/null'));
    }

    private static function unitEnabled(): bool
    {
        return trim((string)shell_exec('systemctl is-enabled ' . escapeshellarg(self::UNIT) . ' 2>/dev/null')) === 'enabled';
    }

    /** Crea la unidad desde la plantilla del panel si falta (no pisa una existente). */
    private static function ensureUnitFile(): bool
    {
        $path = '/etc/systemd/system/' . self::UNIT;
        $tpl = PANEL_ROOT . '/bin/musedock-portal.service';
        if (is_file($path) || !is_file($tpl)) {
            return false;
        }
        $content = str_replace(['__PORTAL_DIR__', '__PORTAL_INTERNAL_PORT__'], [self::DIR, (string)self::internalPort()], (string)file_get_contents($tpl));
        if (@file_put_contents($path, $content) === false) {
            return false;
        }
        @chmod($path, 0644);
        shell_exec('systemctl daemon-reload 2>&1');
        return true;
    }

    // ── Caddy ────────────────────────────────────────────────────────────

    private static function api(): string
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        return rtrim((string)($config['caddy']['api_url'] ?? 'http://localhost:2019'), '/');
    }

    /** @return array{0:int,1:string} */
    private static function caddy(string $method, string $path, $body = null): array
    {
        $ch = curl_init(self::api() . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }
        $out = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $out];
    }

    /** Servidor de Caddy que escucha en el puerto del portal, o null. */
    private static function portServer(): ?string
    {
        [$code, $raw] = self::caddy('GET', '/config/apps/http/servers');
        $servers = $code === 200 ? json_decode($raw, true) : null;
        if (!is_array($servers)) {
            return null;
        }
        $port = self::publicPort();
        foreach ($servers as $name => $srv) {
            foreach ((array)($srv['listen'] ?? []) as $l) {
                if (preg_match('/:(\d+)(-(\d+))?$/', (string)$l, $m)
                    && $port >= (int)$m[1] && $port <= (int)($m[3] ?? $m[1])) {
                    return (string)$name;
                }
            }
        }
        return null;
    }

    private static function buildRoute(string $host): array
    {
        return [
            '@id' => self::ROUTE_ID,
            'match' => [['host' => [$host]]],
            'handle' => [
                ['handler' => 'headers', 'response' => ['deferred' => true, 'delete' => ['Server'], 'set' => [
                    'Strict-Transport-Security' => ['max-age=31536000'],
                    'X-Content-Type-Options' => ['nosniff'],
                    'X-Frame-Options' => ['DENY'],
                ]]],
                ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => '127.0.0.1:' . self::internalPort()]],
                    'headers' => ['request' => ['set' => [
                        'X-Forwarded-Proto' => ['https'],
                        'X-Forwarded-Host' => ['{http.request.host}'],
                        'X-Real-IP' => ['{remote_host}'],
                    ]]]],
            ],
            'terminal' => true,
        ];
    }

    /** Pone (o deja como está) la ruta del portal en Caddy. */
    public static function ensureRoute(): array
    {
        $host = self::hostname();
        if ($host === '') {
            return ['ok' => true, 'skipped' => true, 'reason' => 'sin nombre público (Ajustes → Portal Clientes)'];
        }
        if (!self::validHostname($host)) {
            return ['ok' => false, 'error' => "nombre público no válido: {$host}"];
        }
        [$c] = self::caddy('GET', '/config/');
        if ($c !== 200) {
            return ['ok' => false, 'error' => 'la API de Caddy no responde'];
        }

        $server = self::portServer();
        if ($server === null) {
            $server = self::SERVER;
            [$code, $out] = self::caddy('PUT', '/config/apps/http/servers/' . $server, [
                'listen' => [':' . self::publicPort()],
                'automatic_https' => ['disable_redirects' => true],
                'tls_connection_policies' => [new \stdClass()],
                'routes' => [],
            ]);
            if ($code < 200 || $code >= 300) {
                return ['ok' => false, 'error' => "no se pudo crear el servidor de Caddy del puerto " . self::publicPort() . " (HTTP {$code}) " . mb_substr($out, 0, 200)];
            }
        }

        [$code, $raw] = self::caddy('GET', "/config/apps/http/servers/{$server}/routes");
        $routes = json_decode($raw, true);
        if (!is_array($routes)) {
            $routes = [];
        }
        $want = self::buildRoute($host);
        foreach ($routes as $r) {
            $id = (string)($r['@id'] ?? '');
            $hosts = [];
            foreach ((array)($r['match'] ?? []) as $m) {
                $hosts = array_merge($hosts, (array)($m['host'] ?? []));
            }
            if ($id !== self::ROUTE_ID && in_array($host, array_map('strtolower', $hosts), true)) {
                return ['ok' => false, 'error' => "el nombre {$host} ya lo usa otra ruta de Caddy en el puerto " . self::publicPort()];
            }
            if ($id === self::ROUTE_ID && json_encode($r['match'] ?? null) === json_encode($want['match'])
                && str_contains(json_encode($r['handle'] ?? []), '127.0.0.1:' . self::internalPort())) {
                self::ensureTls($host);
                return ['ok' => true, 'idempotent' => true, 'server' => $server];
            }
        }

        self::caddy('DELETE', '/id/' . self::ROUTE_ID);
        // Al principio: antes de cualquier ruta sin nombre (comodín) que hubiera en ese servidor.
        [$code, $out] = self::caddy('PUT', "/config/apps/http/servers/{$server}/routes/0", $want);
        if ($code < 200 || $code >= 300) {
            [$code, $out] = self::caddy('POST', "/config/apps/http/servers/{$server}/routes", $want);
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => "Caddy rechazó la ruta (HTTP {$code}) " . mb_substr($out, 0, 200)];
        }
        self::ensureTls($host);
        return ['ok' => true, 'server' => $server];
    }

    /** Certificado del nombre del portal: misma política que el nombre del panel. */
    private static function ensureTls(string $host): void
    {
        try {
            SystemService::ensurePanelTlsPolicyFromSettings(self::api(), $host);
        } catch (\Throwable) {
        }
    }

    /** Quita la ruta del portal (y el servidor propio si queda vacío). true si había algo. */
    public static function removeRoute(): bool
    {
        [$code] = self::caddy('DELETE', '/id/' . self::ROUTE_ID);
        $removed = $code >= 200 && $code < 300;
        [$c, $raw] = self::caddy('GET', '/config/apps/http/servers/' . self::SERVER . '/routes');
        if ($c === 200 && json_decode($raw, true) === []) {
            self::caddy('DELETE', '/config/apps/http/servers/' . self::SERVER);
            $removed = true;
        }
        return $removed;
    }

    /** Estado para Ajustes → Portal Clientes y el MCP. */
    public static function status(): array
    {
        $routeOk = false;
        [$code, $raw] = self::caddy('GET', '/id/' . self::ROUTE_ID);
        if ($code === 200) {
            $routeOk = str_contains($raw, '"' . self::hostname() . '"');
        }
        return [
            'installed' => self::installed(),
            'hostname' => self::hostname(),
            'port' => self::publicPort(),
            'url' => self::url(),
            'role' => self::role(),
            'fenced' => self::fenced(),
            'should_serve' => self::shouldServe(),
            'service' => self::installed() ? (self::unitActive() ?: 'desconocido') : 'no instalado',
            'route' => $routeOk,
            'last_sync' => json_decode((string)Settings::get('portal_sync_last', ''), true) ?: null,
        ];
    }

    // ── Clientes: del master a las copias ─────────────────────────────────

    /**
     * Acción de cluster export-portal-state (la pide la copia al master).
     * $ownOnly: solo los clientes NACIDOS en este nodo (no los que le copió un master), para
     * que el principal actual los recupere aunque este nodo ahora sea una copia
     * (mergeFromPeers). Se permite también en una copia.
     */
    public static function exportState(bool $ownOnly = false): array
    {
        if (self::role() === 'slave' && !$ownOnly) {
            return ['ok' => false, 'error' => 'este nodo es una copia: los clientes se piden al master'];
        }
        $have = array_column(Database::fetchAll(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'customers'"
        ), 'column_name');
        $cols = array_values(array_intersect(self::CUSTOMER_COLS, $have));
        $customers = Database::fetchAll('SELECT ' . implode(', ', $cols) . ' FROM customers ORDER BY id');
        if ($ownOnly) {
            $received = json_decode((string)Settings::get(self::SYNCED_KEY, '[]'), true) ?: [];
            $customers = array_values(array_filter($customers, static fn($c) => !in_array(strtolower((string)$c['email']), $received, true)));
        }
        $links = [];
        foreach (Database::fetchAll(
            'SELECT h.domain, c.email FROM hosting_accounts h LEFT JOIN customers c ON c.id = h.customer_id'
        ) as $row) {
            $links[strtolower((string)$row['domain'])] = $row['email'] !== null ? strtolower((string)$row['email']) : null;
        }
        $settings = [];
        foreach (self::SHARED_SETTINGS as $k) {
            $settings[$k] = (string)Settings::get($k, '');
        }
        return ['ok' => true, 'customers' => $customers, 'links' => $links, 'settings' => $settings] + self::exportTickets();
    }

    private static function tableExists(string $table): bool
    {
        return (bool)Database::fetchOne("SELECT to_regclass(:t) AS r", ['t' => 'public.' . $table])['r'];
    }

    /** Tickets de soporte del portal (si el portal los tiene), con cliente por email y hosting por dominio. */
    private static function exportTickets(): array
    {
        if (!self::tableExists('portal_tickets')) {
            return [];
        }
        $tickets = Database::fetchAll(
            "SELECT t.id, lower(c.email) AS customer_email, lower(h.domain) AS account_domain, t.subject, t.category,
                    t.status, t.last_author, t.created_at, t.updated_at
             FROM portal_tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             LEFT JOIN hosting_accounts h ON h.id = t.account_id
             ORDER BY t.id"
        );
        $messages = Database::fetchAll(
            "SELECT id, ticket_id, author_type, author_name, body, created_at FROM portal_ticket_messages ORDER BY id"
        );
        return ['tickets' => $tickets, 'ticket_messages' => $messages];
    }

    /** En la copia: tickets del master (mismos ids; no se borra nada). */
    private static function importTickets(array $data, array &$counts, array &$issues): void
    {
        if (!array_key_exists('tickets', $data)) {
            return;
        }
        if (!self::tableExists('portal_tickets')) {
            $mig = self::DIR . '/database/migrations/0003_create_portal_tickets.php';
            if (!is_file($mig)) {
                $issues[] = 'tickets: falta la migración del portal en este nodo';
                return;
            }
            try {
                $m = require $mig;
                ($m['up'])(Database::connect());
            } catch (\Throwable $e) {
                $issues[] = 'tickets: no se pudieron crear las tablas: ' . $e->getMessage();
                return;
            }
        }
        $counts['tickets'] = 0;
        foreach ((array)$data['tickets'] as $t) {
            $cid = $t['customer_email'] ? Database::fetchOne('SELECT id FROM customers WHERE lower(email) = :e', ['e' => $t['customer_email']]) : null;
            $aid = $t['account_domain'] ? Database::fetchOne('SELECT id FROM hosting_accounts WHERE lower(domain) = :d', ['d' => $t['account_domain']]) : null;
            try {
                $n = Database::execute(
                    "INSERT INTO portal_tickets (id, customer_id, account_id, subject, category, status, last_author, created_at, updated_at)
                     VALUES (:id, :cid, :aid, :subject, :category, :status, :last_author, :created_at, :updated_at)
                     ON CONFLICT (id) DO UPDATE SET customer_id = EXCLUDED.customer_id, account_id = EXCLUDED.account_id,
                        subject = EXCLUDED.subject, category = EXCLUDED.category, status = EXCLUDED.status,
                        last_author = EXCLUDED.last_author, updated_at = EXCLUDED.updated_at
                     WHERE portal_tickets.updated_at IS DISTINCT FROM EXCLUDED.updated_at
                        OR portal_tickets.status IS DISTINCT FROM EXCLUDED.status",
                    ['id' => (int)$t['id'], 'cid' => $cid['id'] ?? null, 'aid' => $aid['id'] ?? null, 'subject' => (string)$t['subject'],
                     'category' => (string)$t['category'], 'status' => (string)$t['status'], 'last_author' => (string)$t['last_author'],
                     'created_at' => $t['created_at'], 'updated_at' => $t['updated_at']]
                );
                $counts['tickets'] += $n;
            } catch (\Throwable $e) {
                $issues[] = "ticket #{$t['id']}: " . $e->getMessage();
            }
        }
        foreach ((array)($data['ticket_messages'] ?? []) as $m) {
            try {
                $counts['tickets'] += Database::execute(
                    "INSERT INTO portal_ticket_messages (id, ticket_id, author_type, author_name, body, created_at)
                     VALUES (:id, :tid, :type, :name, :body, :created_at) ON CONFLICT (id) DO NOTHING",
                    ['id' => (int)$m['id'], 'tid' => (int)$m['ticket_id'], 'type' => (string)$m['author_type'],
                     'name' => (string)$m['author_name'], 'body' => (string)$m['body'], 'created_at' => $m['created_at']]
                );
            } catch (\Throwable $e) {
                $issues[] = "mensaje #{$m['id']}: " . $e->getMessage();
            }
        }
        // Si esta copia pasa a mandar, sus tickets nuevos no deben chocar con los ids copiados.
        foreach (['portal_tickets', 'portal_ticket_messages'] as $tbl) {
            Database::query("SELECT setval(pg_get_serial_sequence('{$tbl}', 'id'), GREATEST((SELECT COALESCE(MAX(id), 0) FROM {$tbl}), 1))");
        }
    }

    /**
     * En una copia: aplica lo que manda el master. No borra nada:
     *  - clientes: se crean o actualizan por email; los que antes vinieron del master
     *    y ya no están allí se desactivan (no pueden entrar al portal);
     *  - hostings: se enlazan al cliente del master (por dominio). Solo se desenlaza
     *    si el enlace local apunta a un cliente que vino del master;
     *  - los clientes propios de esta copia (no recibidos) no se tocan.
     */
    public static function importState(array $data): array
    {
        $counts = ['customers_new' => 0, 'customers_updated' => 0, 'customers_deactivated' => 0, 'links_changed' => 0];
        $issues = [];
        $have = array_column(Database::fetchAll(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'customers'"
        ), 'column_name');

        $masterEmails = [];
        foreach ((array)($data['customers'] ?? []) as $c) {
            $email = strtolower(trim((string)($c['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $masterEmails[] = $email;
            $row = [];
            foreach (self::CUSTOMER_COLS as $col) {
                if (in_array($col, $have, true) && array_key_exists($col, $c)) {
                    $row[$col] = $c[$col];
                }
            }
            $row['email'] = $email;
            try {
                // Primero por uid (estable aunque cambie el correo); si no, por correo, y el local
                // adopta el uid del master.
                $local = !empty($row['uid']) ? Database::fetchOne('SELECT id FROM customers WHERE uid = :u', ['u' => (string)$row['uid']]) : null;
                $local = $local ?: Database::fetchOne('SELECT id FROM customers WHERE lower(email) = :e', ['e' => $email]);
                if ($local) {
                    $changed = Database::fetchOne('SELECT id FROM customers WHERE id = :id AND ('
                        . implode(' OR ', array_map(static fn($k) => "{$k} IS DISTINCT FROM :{$k}", array_keys($row))) . ')',
                        $row + ['id' => (int)$local['id']]);
                    if ($changed) {
                        Database::update('customers', $row, 'id = :wid', ['wid' => (int)$local['id']]);
                        $counts['customers_updated']++;
                    }
                } else {
                    Database::insert('customers', $row);
                    $counts['customers_new']++;
                }
            } catch (\Throwable $e) {
                $issues[] = "cliente {$email}: " . $e->getMessage();
            }
        }

        $prevSynced = json_decode((string)Settings::get(self::SYNCED_KEY, '[]'), true) ?: [];
        foreach (array_diff($prevSynced, $masterEmails) as $gone) {
            $n = Database::execute("UPDATE customers SET status = 'inactive', updated_at = NOW() WHERE lower(email) = :e AND status <> 'inactive'", ['e' => $gone]);
            $counts['customers_deactivated'] += $n;
        }
        // Una vez recibido del master, un cliente ya no es "propio" de esta copia (aunque el
        // master lo renombre o lo quite): así mergeFromPeers no lo devuelve como nuevo.
        $synced = array_values(array_unique(array_merge($prevSynced, $masterEmails)));
        // Clientes que vinieron del master (ahora o antes): sus enlaces los decide el master.
        $fromMaster = array_values(array_unique(array_merge($prevSynced, $masterEmails)));

        $idByEmail = [];
        foreach (Database::fetchAll('SELECT id, lower(email) AS email FROM customers') as $r) {
            $idByEmail[$r['email']] = (int)$r['id'];
        }
        $emailById = array_flip($idByEmail);
        foreach ((array)($data['links'] ?? []) as $domain => $email) {
            $h = Database::fetchOne('SELECT id, customer_id FROM hosting_accounts WHERE lower(domain) = :d', ['d' => strtolower((string)$domain)]);
            if (!$h) {
                continue; // ese hosting aún no está en esta copia
            }
            $current = $h['customer_id'] !== null ? (int)$h['customer_id'] : null;
            if ($email !== null) {
                $want = $idByEmail[strtolower((string)$email)] ?? $current; // si no se pudo crear, no se toca
            } else {
                // El master no lo asigna a nadie: solo se quita si el enlace local vino del master.
                $want = ($current !== null && in_array($emailById[$current] ?? '', $fromMaster, true)) ? null : $current;
            }
            if ($want !== $current) {
                Database::update('hosting_accounts', ['customer_id' => $want], 'id = :wid', ['wid' => (int)$h['id']]);
                $counts['links_changed']++;
            }
        }

        self::importTickets($data, $counts, $issues);

        foreach (self::SHARED_SETTINGS as $k) {
            if (!array_key_exists($k, (array)($data['settings'] ?? []))) {
                continue;
            }
            $v = (string)$data['settings'][$k];
            // Vacío no pisa (un master que aún no tiene el ajuste), salvo el favicon: vacío =
            // volver al de por defecto, y eso también debe llegar a las copias.
            if ($v !== '' || in_array($k, ['portal_favicon', 'portal_favicon_type'], true)) {
                if (Settings::get($k, '') !== $v) {
                    Settings::set($k, $v);
                }
            }
        }
        Settings::set(self::SYNCED_KEY, json_encode($synced));
        $result = ['ok' => !$issues, 'at' => date('Y-m-d H:i:s')] + $counts + ['issues' => $issues];
        Settings::set('portal_sync_last', json_encode($result, JSON_UNESCAPED_UNICODE));
        if (array_sum($counts) > 0 || $issues) {
            LogService::log('portal.sync', 'from-master', json_encode($counts) . ($issues ? ' avisos: ' . count($issues) : ''));
        }
        return $result;
    }

    /**
     * En el principal: recupera los clientes que nacieron en otro nodo del cluster (p. ej. el
     * principal anterior, antes de un cambio de rol: la base del panel es de cada nodo y nadie
     * los traía de vuelta). Solo AÑADE: crea los clientes que aquí no existen (con su acceso
     * al portal) y enlaza sus hostings si aquí no tienen cliente. Nunca cambia ni borra nada.
     * Sin $apply solo dice qué haría.
     */
    public static function mergeFromPeers(bool $apply = true): array
    {
        if (self::role() === 'slave' || self::fenced()) {
            return ['ok' => false, 'error' => 'solo en el servidor que manda'];
        }
        $have = array_column(Database::fetchAll(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'customers'"
        ), 'column_name');
        $out = ['ok' => true, 'apply' => $apply, 'customers' => [], 'links' => [], 'nodes' => []];
        foreach (ClusterService::getNodes() as $n) {
            $r = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'export-portal-state', 'payload' => ['own_only' => true]]);
            $data = $r['data'] ?? null;
            if (empty($r['ok']) || empty($data['ok'])) {
                $out['nodes'][(string)$n['name']] = 'sin respuesta: ' . ($data['error'] ?? $r['error'] ?? '?');
                continue;
            }
            $out['nodes'][(string)$n['name']] = count((array)$data['customers']) . ' cliente(s) propios';
            $theirs = [];
            foreach ((array)$data['customers'] as $c) {
                $email = strtolower(trim((string)($c['email'] ?? '')));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $theirs[$email] = true;
                $uid = in_array('uid', $have, true) ? (string)($c['uid'] ?? '') : '';
                if (($uid !== '' && Database::fetchOne('SELECT id FROM customers WHERE uid = :u', ['u' => $uid]))
                    || Database::fetchOne('SELECT id FROM customers WHERE lower(email) = :e', ['e' => $email])) {
                    continue; // ya está aquí (quizá con otro correo): no se duplica
                }
                $row = ['email' => $email];
                foreach (self::CUSTOMER_COLS as $col) {
                    if ($col !== 'email' && in_array($col, $have, true) && array_key_exists($col, $c)) {
                        $row[$col] = $c[$col];
                    }
                }
                $out['customers'][] = "{$email} (de {$n['name']})";
                if ($apply) {
                    Database::insert('customers', $row);
                }
            }
            foreach ((array)($data['links'] ?? []) as $domain => $email) {
                if ($email === null || !isset($theirs[strtolower((string)$email)])) {
                    continue;
                }
                $h = Database::fetchOne('SELECT id, customer_id FROM hosting_accounts WHERE lower(domain) = :d', ['d' => strtolower((string)$domain)]);
                if (!$h || $h['customer_id'] !== null) {
                    continue; // no está aquí, o ya tiene cliente: no se toca
                }
                $out['links'][] = "{$domain} → {$email}";
                if ($apply) {
                    $cid = Database::fetchOne('SELECT id FROM customers WHERE lower(email) = :e', ['e' => strtolower((string)$email)]);
                    if ($cid) {
                        Database::update('hosting_accounts', ['customer_id' => (int)$cid['id']], 'id = :wid', ['wid' => (int)$h['id']]);
                    }
                }
            }
        }
        if ($apply && ($out['customers'] || $out['links'])) {
            LogService::log('portal.merge', 'from-peers', 'Clientes recuperados: ' . implode(', ', $out['customers']) . '; hostings: ' . implode(', ', $out['links']));
        }
        return $out;
    }

    /** En una copia: pide al master sus clientes del portal y los aplica. */
    public static function pullFromMaster(): array
    {
        if (self::role() !== 'slave') {
            return ['ok' => false, 'error' => 'solo en una copia'];
        }
        if (self::fenced()) {
            return ['ok' => false, 'error' => 'nodo apartado: no se copia nada'];
        }
        $master = ConfigMirrorService::masterNode();
        if (!$master) {
            return ['ok' => false, 'error' => 'no hay master registrado en este nodo'];
        }
        $r = ClusterService::callNode((int)$master['id'], 'POST', 'api/cluster/action', ['action' => 'export-portal-state', 'payload' => []]);
        $data = $r['data'] ?? null;
        if (empty($r['ok']) || empty($data['ok'])) {
            return ['ok' => false, 'error' => 'el master no devolvió los clientes del portal: ' . ($data['error'] ?? $r['error'] ?? '?')];
        }
        return self::importState($data);
    }
}
