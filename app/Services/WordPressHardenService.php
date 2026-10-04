<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * Blindar WordPress, por hosting (columna hosting_accounts.wp_harden):
 *
 *  off       nada.
 *  standard  (por defecto) reglas en Caddy que no limitan al cliente: xmlrpc.php cerrado
 *            (salvo Jetpack o permiso), sin PHP en uploads/cache/upgrade, ficheros sensibles
 *            (wp-config, readme, *.sql, copias .bak) y ?author= (saca nombres de usuario) en 403.
 *  strict    lo anterior + xmlrpc siempre cerrado + el código pasa a ser de root: PHP (el
 *            usuario del hosting) solo escribe en uploads, cache y similares, y un mu-plugin
 *            del panel pone DISALLOW_FILE_MODS/EDIT. Aunque roben la contraseña de un
 *            administrador o un plugin tenga un fallo, no pueden escribir PHP. Para actualizar
 *            se desbloquea un rato (unlock) y el panel lo vuelve a cerrar solo.
 *
 * Además: lista de baneos en Caddy por la IP real que manda Cloudflare (Cf-Connecting-Ip),
 * que alimenta fail2ban (jaula musedock-wordpress). Con el proxy de Cloudflare el ban de
 * iptables no sirve: las conexiones llegan desde IPs de Cloudflare.
 *
 * Análisis (scan) y reparación (cuarentena, reinstalar núcleo/plugin/tema desde
 * wordpress.org, regenerar claves) sin ejecutar nunca el PHP del sitio. Nada se borra:
 * lo que se quita va a /var/lib/musedock/wp-quarantine/<dominio>/<fecha>/.
 */
class WordPressHardenService
{
    public const LEVELS = ['off', 'standard', 'strict'];
    public const QUARANTINE_DIR = '/var/lib/musedock/wp-quarantine';
    private const BANLIST_FILE = '/var/lib/musedock/wp-banlist.json';
    private const BANLIST_ID = 'musedock-wp-banlist';
    private const LOCK_DIR = '/etc/musedock/wp-lock';
    private const MU_FILE = '000-musedock-harden.php';
    private const MU_MARK = 'MuseDock Panel: Blindar WordPress';

    /** Carpetas que PHP debe poder escribir aunque el código esté cerrado. */
    private const WRITABLE = ['wp-content/uploads', 'wp-content/cache', 'wp-content/upgrade', 'wp-content/upgrade-temp-backup',
        'wp-content/wflogs', 'wp-content/et-cache', 'wp-content/litespeed', 'wp-content/w3tc-config', 'wp-content/wpo-cache',
        'wp-content/updraft', 'wp-content/ai1wm-backups', 'wp-content/backups-dup-lite', 'wp-content/webp-express'];

    /** Nombres de plugins/carpetas que son malware conocido (vistos en este servidor). */
    private const KNOWN_BAD = ['wp-link-helper', 'wp-classic-editor', 'wordfence-spam', 'micro-relay', 'micro-relay-evo'];

    // ───────────────────────── Detección y ajustes ─────────────────────────

    public static function isWordPress(string $docRoot): bool
    {
        $d = rtrim($docRoot, '/');
        return $d !== '' && is_file("{$d}/wp-includes/version.php") && is_dir("{$d}/wp-content");
    }

    /** Ajustes de un hosting por usuario del sistema (también lo usan sus subdominios). */
    public static function settingsFor(string $username): array
    {
        try {
            $r = Database::fetchOne('SELECT wp_harden, wp_allow_xmlrpc, wp_unlock_until FROM hosting_accounts WHERE username = :u ORDER BY id LIMIT 1', ['u' => $username]);
        } catch (\Throwable) {
            $r = null; // columnas aún sin migrar
        }
        $level = in_array($r['wp_harden'] ?? '', self::LEVELS, true) ? $r['wp_harden'] : 'standard';
        $x = $r['wp_allow_xmlrpc'] ?? null;
        return [
            'level' => $level,
            'allow_xmlrpc' => $x === null ? null : in_array($x, [true, 't', '1', 1], true),
            'unlock_until' => !empty($r['wp_unlock_until']) ? strtotime((string)$r['wp_unlock_until']) : 0,
        ];
    }

    private static function xmlrpcOpen(string $docRoot, array $s): bool
    {
        if ($s['level'] === 'strict') {
            return false;
        }
        // Automático: solo si tiene Jetpack (lo necesita para conectar con WordPress.com).
        return $s['allow_xmlrpc'] ?? is_dir(rtrim($docRoot, '/') . '/wp-content/plugins/jetpack');
    }

    /** Firma de las reglas que tocan a esa raíz; va en la ruta (vars) para saber si está al día. */
    public static function marker(string $docRoot, string $username): string
    {
        if (!self::isWordPress($docRoot)) {
            return '';
        }
        $s = self::settingsFor($username);
        return $s['level'] === 'off' ? 'off' : $s['level'] . ':xmlrpc' . (self::xmlrpcOpen($docRoot, $s) ? '1' : '0') . ':v1';
    }

    /** Rutas de Caddy (dentro del subroute del hosting) para una raíz WordPress. */
    public static function caddyRoutes(string $docRoot, string $username): array
    {
        if (!self::isWordPress($docRoot)) {
            return [];
        }
        $s = self::settingsFor($username);
        if ($s['level'] === 'off') {
            return [];
        }
        $deny = static fn(array $match, string $why) => ['match' => [$match], 'handle' => [[
            'handler' => 'static_response', 'status_code' => 403, 'body' => "403 - {$why}\n", 'close' => true]], 'terminal' => true];
        $routes = [];
        if (!self::xmlrpcOpen($docRoot, $s)) {
            $routes[] = $deny(['path' => ['*/xmlrpc.php']], 'xmlrpc.php desactivado');
        }
        $routes[] = $deny(['path_regexp' => ['name' => 'wpnophp', 'pattern' => '(?i)/wp-content/(uploads|cache|upgrade|upgrade-temp-backup)/.*\.(php[0-9]?|phtml|phar|pht|phps)$']],
            'no se ejecuta PHP en esta carpeta');
        $routes[] = $deny(['path' => ['*/wp-config.php', '*/wp-config-sample.php', '/readme.html', '/license.txt', '*/wp-content/debug.log',
            '*/.user.ini', '*.sql', '*.sql.gz', '*.php.bak', '*.php.old', '*.php.orig', '*.php.save', '*.php.swp', '*.php~', '*/wp-config.bak']],
            'fichero protegido');
        $routes[] = $deny(['path' => ['/', '/index.php'], 'query' => ['author' => ['*']]], 'listado de autores desactivado');
        return $routes;
    }

    // ───────────────────────── Rutas de Caddy ─────────────────────────

    private static function caddy(string $method, string $path, $body = null): array
    {
        $api = rtrim((string)((require PANEL_ROOT . '/config/panel.php')['caddy']['api_url'] ?? 'http://localhost:2019'), '/');
        $ch = curl_init($api . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }
        $out = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $out];
    }

    /**
     * Rehace las reglas de todas las rutas de Caddy que sirven esa raíz (dominio, www,
     * alias, subdominios con la misma raíz). Solo toca rutas normales de hosting (las de
     * mantenimiento/suspensión no tienen subroute con raíz) y solo si la firma cambió.
     */
    public static function applyRoutes(string $docRoot, string $username, bool $force = false): array
    {
        $docRoot = rtrim($docRoot, '/');
        [$c, $j] = self::caddy('GET', '/config/apps/http/servers/srv0/routes');
        if ($c !== 200) {
            return ['ok' => false, 'error' => "Caddy no responde (HTTP {$c})"];
        }
        $want = self::marker($docRoot, $username);
        $done = [];
        $errors = [];
        foreach ((array)json_decode($j, true) as $r) {
            $id = (string)($r['@id'] ?? '');
            $sub = $r['handle'][0] ?? [];
            $first = $sub['routes'][0]['handle'][0] ?? [];
            if ($id === '' || ($sub['handler'] ?? '') !== 'subroute' || ($first['handler'] ?? '') !== 'vars' || rtrim((string)($first['root'] ?? ''), '/') !== $docRoot) {
                continue;
            }
            if (!$force && (string)($first['wp_harden'] ?? '') === $want) {
                continue;
            }
            // Versión de PHP y usuario de esa ruta (un subdominio puede tener otra versión).
            $dial = '';
            array_walk_recursive($sub, static function ($v, $k) use (&$dial) {
                if ($k === 'dial' && str_contains((string)$v, 'fpm-')) {
                    $dial = (string)$v;
                }
            });
            if (!preg_match('#php([0-9.]+)-fpm-([a-z0-9_.-]+)\.sock#i', $dial, $m)) {
                continue; // no es una web PHP del panel
            }
            $routes = SystemService::buildCaddySubroutes($docRoot, $m[2], $m[1], 'php');
            [$pc, $po] = self::caddy('PATCH', '/id/' . rawurlencode($id) . '/handle/0/routes', $routes);
            if ($pc >= 200 && $pc < 300) {
                $done[] = $id;
            } else {
                $errors[] = "{$id}: HTTP {$pc} " . trim($po);
            }
        }
        return ['ok' => !$errors, 'routes' => $done, 'error' => implode(' | ', $errors)];
    }

    // ───────────────────────── Nivel por hosting ─────────────────────────

    public static function account(string $hosting): ?array
    {
        $h = strtolower(trim($hosting));
        return $h === '' ? null : (Database::fetchOne('SELECT * FROM hosting_accounts WHERE lower(domain) = :h OR username = :h', ['h' => $h]) ?: null);
    }

    public static function docRoot(array $acc): string
    {
        return rtrim(!empty($acc['document_root']) ? (string)$acc['document_root'] : rtrim((string)$acc['home_dir'], '/') . '/httpdocs', '/');
    }

    /** Raíces de un hosting que son WordPress (la principal y las de sus subdominios). */
    public static function wpRoots(array $acc): array
    {
        $roots = [self::docRoot($acc)];
        try {
            foreach (Database::fetchAll('SELECT document_root FROM hosting_subdomains WHERE account_id = :id', ['id' => (int)$acc['id']]) as $s) {
                if (!empty($s['document_root'])) {
                    $roots[] = rtrim((string)$s['document_root'], '/');
                }
            }
        } catch (\Throwable) {
        }
        return array_values(array_filter(array_unique($roots), [self::class, 'isWordPress']));
    }

    /**
     * Cambia el nivel (y xmlrpc: null = automático) de un hosting y lo aplica aquí. En el
     * master, además, cierra/abre el código (strict) y lo manda a los nodos web.
     */
    public static function setLevel(array $acc, string $level, $allowXmlrpc = 'keep', bool $propagate = true): array
    {
        if (!in_array($level, self::LEVELS, true)) {
            return ['ok' => false, 'error' => 'Nivel no válido (off, standard, strict).'];
        }
        $fields = ['wp_harden' => $level];
        if ($allowXmlrpc !== 'keep') {
            $fields['wp_allow_xmlrpc'] = $allowXmlrpc === null ? null : ($allowXmlrpc ? 'true' : 'false');
        }
        if ($level !== 'strict') {
            $fields['wp_unlock_until'] = null;
        }
        $sets = implode(', ', array_map(static fn($k) => "{$k} = :{$k}", array_keys($fields)));
        Database::query("UPDATE hosting_accounts SET {$sets} WHERE id = :id", $fields + ['id' => (int)$acc['id']]);
        $acc = array_merge($acc, $fields);

        $out = ['ok' => true, 'domain' => $acc['domain'], 'level' => $level, 'roots' => []];
        foreach (self::wpRoots($acc) as $root) {
            $r = self::applyRoutes($root, (string)$acc['username'], true);
            $out['roots'][$root] = ['routes' => $r['routes'] ?? [], 'error' => $r['error'] ?? ''];
            if (empty($r['ok'])) {
                $out['ok'] = false;
            }
        }
        if (!self::isSlave()) {
            foreach (self::wpRoots($acc) as $root) {
                $out['roots'][$root]['code'] = $level === 'strict' ? self::lock($root, (string)$acc['username']) : self::unlockFiles($root, (string)$acc['username']);
            }
            if ($propagate) {
                foreach (ClusterService::getWebNodes() as $node) {
                    ClusterService::enqueue((int)$node['id'], 'sync-hosting', ['hosting_action' => 'wp_harden', 'hosting_data' => [
                        'domain' => $acc['domain'], 'wp_harden' => $level,
                        'wp_allow_xmlrpc' => array_key_exists('wp_allow_xmlrpc', $fields) ? $fields['wp_allow_xmlrpc'] : 'keep']], 6);
                }
            }
        }
        LogService::log('wp.harden', (string)$acc['domain'], "Blindar WordPress: {$level}" . ($allowXmlrpc !== 'keep' ? ', xmlrpc ' . ($allowXmlrpc === null ? 'auto' : ($allowXmlrpc ? 'permitido' : 'cerrado')) : ''));
        return $out;
    }

    private static function isSlave(): bool
    {
        return Settings::get('cluster_role', 'standalone') === 'slave';
    }

    // ───────────────────────── Código de solo lectura (strict) ─────────────────────────

    private static function safeRoot(string $root): bool
    {
        $real = realpath($root) ?: '';
        return $real !== '' && str_starts_with($real, '/var/www/vhosts/') && substr_count(trim($real, '/'), '/') >= 3 && self::isWordPress($real);
    }

    public static function isLocked(string $root): bool
    {
        return is_dir($root) && fileowner($root) === 0;
    }

    /** El código pasa a root (se puede leer, no escribir); las carpetas de datos siguen del usuario. */
    public static function lock(string $root, string $user): string
    {
        if (!self::safeRoot($root) || !posix_getpwnam($user)) {
            return 'no se cierra: raíz o usuario no válidos';
        }
        $grp = posix_getgrgid(filegroup($root))['name'] ?? $user;
        @mkdir(self::LOCK_DIR, 0700, true);
        if (!is_file(self::LOCK_DIR . "/{$user}.json")) {
            file_put_contents(self::LOCK_DIR . "/{$user}.json", json_encode(['group' => $grp, 'root' => $root, 'since' => date('c')]));
        }
        $r = escapeshellarg($root);
        $u = escapeshellarg($user);
        $g = escapeshellarg($grp);
        self::writeMuPlugin($root);
        shell_exec("chown -R root:{$g} {$r} 2>&1; chmod -R go-w {$r} 2>&1");
        // Lo que no era legible por todos (p. ej. wp-config.php 600) lo sigue leyendo el usuario por ACL.
        shell_exec("find {$r} -type f ! -perm -o=r -exec setfacl -m u:{$u}:r {} + 2>&1");
        shell_exec("find {$r} -type d ! -perm -o=rx -exec setfacl -m u:{$u}:rx {} + 2>&1");
        $open = [];
        foreach (self::WRITABLE as $w) {
            if (is_dir("{$root}/{$w}")) {
                shell_exec('chown -R ' . escapeshellarg("{$user}:{$grp}") . ' ' . escapeshellarg("{$root}/{$w}") . ' 2>&1');
                $open[] = $w;
            }
        }
        @file_put_contents("{$root}/wp-content/mu-plugins/.musedock-unlock-until", '0');
        return 'cerrado (escribible: ' . implode(', ', $open) . ')';
    }

    /** Devuelve el código al usuario (para actualizar o al bajar de strict). */
    public static function unlockFiles(string $root, string $user, int $minutes = 0): string
    {
        if (!self::safeRoot($root) || !posix_getpwnam($user)) {
            return 'sin cambios: raíz o usuario no válidos';
        }
        if (!self::isLocked($root)) {
            return 'ya estaba abierto';
        }
        $state = json_decode((string)@file_get_contents(self::LOCK_DIR . "/{$user}.json"), true) ?: [];
        $grp = (string)($state['group'] ?? (posix_getgrgid(filegroup($root))['name'] ?? $user));
        // Mientras dure el desbloqueo, el mu-plugin no pone DISALLOW_FILE_MODS.
        @file_put_contents("{$root}/wp-content/mu-plugins/.musedock-unlock-until", (string)($minutes > 0 ? time() + $minutes * 60 : PHP_INT_MAX));
        shell_exec('chown -R ' . escapeshellarg("{$user}:{$grp}") . ' ' . escapeshellarg($root) . ' 2>&1');
        return $minutes > 0 ? "abierto {$minutes} min" : 'abierto';
    }

    public static function unlock(array $acc, int $minutes): array
    {
        $minutes = max(5, min(240, $minutes));
        Database::query('UPDATE hosting_accounts SET wp_unlock_until = :t WHERE id = :id', ['t' => date('Y-m-d H:i:s', time() + $minutes * 60), 'id' => (int)$acc['id']]);
        $res = [];
        foreach (self::wpRoots($acc) as $root) {
            $res[$root] = self::unlockFiles($root, (string)$acc['username'], $minutes);
        }
        LogService::log('wp.harden', (string)$acc['domain'], "Código desbloqueado {$minutes} min para actualizar");
        return ['ok' => true, 'until' => date('Y-m-d H:i', time() + $minutes * 60), 'roots' => $res];
    }

    private static function writeMuPlugin(string $root): void
    {
        $dir = "{$root}/wp-content/mu-plugins";
        @mkdir($dir, 0755, true);
        $php = "<?php\n/**\n * " . self::MU_MARK . " (nivel estricto). Lo gestiona el panel: no lo edites.\n"
            . " * Impide instalar/editar plugins y temas desde el admin. Para actualizar, desbloquea el\n"
            . " * código un rato desde el panel (Hosting → WordPress → Desbloquear).\n */\n"
            . "if ((int)@file_get_contents(__DIR__ . '/.musedock-unlock-until') < time()) {\n"
            . "    defined('DISALLOW_FILE_MODS') || define('DISALLOW_FILE_MODS', true);\n"
            . "    defined('DISALLOW_FILE_EDIT') || define('DISALLOW_FILE_EDIT', true);\n}\n";
        if ((string)@file_get_contents("{$dir}/" . self::MU_FILE) !== $php) {
            file_put_contents("{$dir}/" . self::MU_FILE, $php);
        }
        @chmod("{$dir}/" . self::MU_FILE, 0644);
    }

    // ───────────────────────── Mantenimiento periódico ─────────────────────────

    /**
     * Cada 30 min (cluster-worker), en todos los nodos: reglas de Caddy al día. En el
     * master (o standalone): vuelve a cerrar el código de los strict cuyo desbloqueo caducó
     * o que alguien abrió. Devuelve un resumen corto.
     */
    public static function ensureAll(): array
    {
        $fixed = [];
        $locked = [];
        try {
            $accs = Database::fetchAll("SELECT * FROM hosting_accounts WHERE status = 'active'");
        } catch (\Throwable) {
            return ['fixed' => [], 'locked' => []];
        }
        foreach ($accs as $acc) {
            foreach (self::wpRoots($acc) as $root) {
                $r = self::applyRoutes($root, (string)$acc['username']);
                if (!empty($r['routes'])) {
                    $fixed[] = $acc['domain'];
                }
                $s = self::settingsFor((string)$acc['username']);
                if (!self::isSlave() && $s['level'] === 'strict' && $s['unlock_until'] < time() && !self::isLocked($root)) {
                    self::lock($root, (string)$acc['username']);
                    $locked[] = $acc['domain'];
                }
            }
        }
        self::syncBanRoute();
        return ['fixed' => array_values(array_unique($fixed)), 'locked' => array_values(array_unique($locked))];
    }

    // ───────────────────────── Baneos detrás de Cloudflare ─────────────────────────

    private static function banlist(callable $fn): array
    {
        @mkdir(dirname(self::BANLIST_FILE), 0755, true);
        $fh = fopen(self::BANLIST_FILE, 'c+');
        flock($fh, LOCK_EX);
        $list = json_decode((string)stream_get_contents($fh), true) ?: [];
        $list = array_filter($list, static fn($exp) => (int)$exp > time()); // caducados fuera
        $list = $fn($list);
        if (count($list) > 5000) {
            asort($list);
            $list = array_slice($list, -5000, null, true);
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($list));
        flock($fh, LOCK_UN);
        fclose($fh);
        return $list;
    }

    /** fail2ban: banea una IP (la del visitante real, que llega en Cf-Connecting-Ip). */
    public static function ban(string $ip, int $seconds = 7200): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        self::banlist(static function ($l) use ($ip, $seconds) {
            $l[$ip] = time() + max(60, $seconds);
            return $l;
        });
        return self::syncBanRoute();
    }

    public static function unban(string $ip): bool
    {
        self::banlist(static function ($l) use ($ip) {
            unset($l[$ip]);
            return $l;
        });
        return self::syncBanRoute();
    }

    /** Ruta al principio de srv0: IPs baneadas (por cabecera de Cloudflare) → 403. */
    public static function syncBanRoute(): bool
    {
        $ips = array_keys(self::banlist(static fn($l) => $l));
        [$c] = self::caddy('GET', '/id/' . self::BANLIST_ID);
        if (!$ips) {
            if ($c === 200) {
                self::caddy('DELETE', '/id/' . self::BANLIST_ID);
            }
            return true;
        }
        $route = ['@id' => self::BANLIST_ID, 'match' => [['header' => ['Cf-Connecting-Ip' => array_values($ips)]]],
            'handle' => [['handler' => 'static_response', 'status_code' => 403, 'body' => "403 - acceso bloqueado temporalmente\n", 'close' => true]],
            'terminal' => true];
        [$pc] = $c === 200 ? self::caddy('PATCH', '/id/' . self::BANLIST_ID, $route)
            : self::caddy('PUT', '/config/apps/http/servers/srv0/routes/0', $route);
        return $pc >= 200 && $pc < 300;
    }

    public static function bannedCount(): int
    {
        return count(json_decode((string)@file_get_contents(self::BANLIST_FILE), true) ?: []);
    }

    // ───────────────────────── Análisis (no ejecuta el PHP del sitio) ─────────────────────────

    private static function wpVersion(string $root): array
    {
        $v = (string)@file_get_contents("{$root}/wp-includes/version.php");
        preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)/', $v, $m);
        preg_match('/\$wp_local_package\s*=\s*[\'"]([^\'"]+)/', $v, $l);
        return [$m[1] ?? '', $l[1] ?? 'en_US'];
    }

    private static function http(string $url, int $timeout = 20): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'MuseDock-Panel/WordPressHarden']);
        $b = (string)curl_exec($ch);
        $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$c, $b];
    }

    private static function headerValue(string $file, string $name): string
    {
        $h = (string)@file_get_contents($file, false, null, 0, 8192);
        return preg_match('/^[\s*#\/]*' . preg_quote($name, '/') . ':\s*(.+)$/mi', $h, $m) ? trim($m[1]) : '';
    }

    /** Ficheros del núcleo cambiados o que sobran (wp-admin, wp-includes y raíz). */
    private static function coreCheck(string $root, int $limit = 50): array
    {
        [$ver, $loc] = self::wpVersion($root);
        if ($ver === '') {
            return ['error' => 'sin versión'];
        }
        $sums = null;
        foreach (array_unique([$loc, 'en_US']) as $l) {
            [$c, $b] = self::http("https://api.wordpress.org/core/checksums/1.0/?version={$ver}&locale={$l}");
            $j = json_decode($b, true);
            if ($c === 200 && !empty($j['checksums'])) {
                $sums = $j['checksums'];
                break;
            }
        }
        if (!$sums) {
            return ['version' => $ver, 'error' => 'wordpress.org no dio las sumas de esta versión'];
        }
        $changed = [];
        foreach ($sums as $f => $md5) {
            if (!preg_match('#^(wp-admin/|wp-includes/|[^/]+\.php$)#', $f) || $f === 'wp-config-sample.php') {
                continue;
            }
            if (is_file("{$root}/{$f}") && md5_file("{$root}/{$f}") !== $md5) {
                $changed[] = $f;
            }
        }
        $extra = [];
        foreach (['wp-admin', 'wp-includes'] as $d) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$d}", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = substr($f->getPathname(), strlen($root) + 1);
                if ($f->isFile() && preg_match('/\.(php[0-9]?|phtml|phar|ico|js)$/i', $rel) && !isset($sums[$rel])) {
                    $extra[] = $rel;
                }
            }
        }
        foreach (glob("{$root}/*.php") ?: [] as $f) {
            $rel = basename($f);
            if (!isset($sums[$rel]) && !in_array($rel, ['wp-config.php'], true)) {
                $extra[] = $rel;
            }
        }
        return ['version' => $ver, 'locale' => $loc, 'changed' => array_slice($changed, 0, $limit), 'extra' => array_slice($extra, 0, $limit),
            'changed_count' => count($changed), 'extra_count' => count($extra)];
    }

    /** Cada plugin contra wordpress.org: versión, si existe allí, ficheros cambiados o que sobran. */
    private static function pluginsCheck(string $root): array
    {
        $out = [];
        foreach (glob("{$root}/wp-content/plugins/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            $ver = '';
            $name = '';
            foreach (glob("{$dir}/*.php") ?: [] as $f) {
                if (($n = self::headerValue($f, 'Plugin Name')) !== '') {
                    $name = $n;
                    $ver = self::headerValue($f, 'Version');
                    break;
                }
            }
            $p = ['slug' => $slug, 'name' => $name, 'version' => $ver, 'modified' => date('Y-m-d', (int)filemtime($dir))];
            if (in_array($slug, self::KNOWN_BAD, true) || str_starts_with($slug, 'micro-relay')) {
                $p['verdict'] = 'MALWARE CONOCIDO';
            } elseif ($name === '') {
                $p['verdict'] = 'sin cabecera de plugin (carpeta vacía o extraña)';
            } elseif ($ver !== '') {
                [$c, $b] = self::http("https://downloads.wordpress.org/plugin-checksums/{$slug}/{$ver}.json", 15);
                $j = json_decode($b, true);
                if ($c !== 200 || empty($j['files'])) {
                    $p['verdict'] = 'no está en wordpress.org (o esa versión): revisar a mano';
                } else {
                    $changed = [];
                    $extra = [];
                    foreach ($j['files'] as $f => $h) {
                        $md5 = is_array($h['md5'] ?? null) ? $h['md5'] : [(string)($h['md5'] ?? '')];
                        if (is_file("{$dir}/{$f}") && !in_array(md5_file("{$dir}/{$f}"), $md5, true)) {
                            $changed[] = $f;
                        }
                    }
                    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $f) {
                        $rel = substr($f->getPathname(), strlen($dir) + 1);
                        if ($f->isFile() && preg_match('/\.(php[0-9]?|phtml|phar)$/i', $rel) && !isset($j['files'][$rel])) {
                            $extra[] = $rel;
                        }
                    }
                    $p['verdict'] = ($changed || $extra) ? 'MODIFICADO respecto a wordpress.org' : 'igual que wordpress.org';
                    $p['changed'] = array_slice($changed, 0, 20);
                    $p['extra_php'] = array_slice($extra, 0, 20);
                }
            }
            $out[] = $p;
        }
        return $out;
    }

    /** Ficheros PHP de wp-content y de la raíz con trozos típicos de puertas traseras e inyecciones. */
    private static function signatureHits(string $root): array
    {
        $pat = 'eval\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|strrev|\$_(POST|GET|REQUEST|COOKIE))'
            . '|assert\s*\(\s*\$_(POST|GET|REQUEST|COOKIE)|\$_(POST|GET|REQUEST|COOKIE)\s*\[[^\]]+\]\s*\('
            . '|fp_embed_check|WLH_REMOTE_URL|create_function\s*\(|base64_decode\s*\(\s*[\'"][A-Za-z0-9+\/=]{300,}'
            . '|(\\\\x[0-9a-fA-F]{2}){12,}|\[\'at\'\s*\+\s*\'ob\'\]';
        $cmd = 'timeout 120 grep -rlP --include=*.php --include=*.phtml --include=*.inc ' . escapeshellarg($pat) . ' '
            . escapeshellarg("{$root}/wp-content") . ' 2>/dev/null | head -80'; // el núcleo se comprueba con las sumas de wordpress.org
        $hits = array_filter(explode("\n", trim((string)shell_exec($cmd))));
        foreach (glob("{$root}/*.php") ?: [] as $f) {
            if (preg_match('#' . $pat . '#', (string)@file_get_contents($f))) {
                $hits[] = $f;
            }
        }
        return array_values(array_map(static fn($f) => substr($f, strlen($root) + 1), $hits));
    }

    /** Lee las credenciales de la base de wp-config.php sin ejecutarlo. */
    private static function wpDb(string $root): ?array
    {
        $cfg = (string)@file_get_contents("{$root}/wp-config.php");
        if ($cfg === '') {
            $cfg = (string)@file_get_contents(dirname($root) . '/wp-config.php');
        }
        $get = static fn($k) => preg_match('/define\s*\(\s*[\'"]' . $k . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/', $cfg, $m) ? $m[1] : null;
        preg_match('/\$table_prefix\s*=\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $cfg, $p);
        if (!$get('DB_NAME') || !$get('DB_USER')) {
            return null;
        }
        $host = (string)$get('DB_HOST');
        $dsn = 'mysql:dbname=' . $get('DB_NAME') . ';charset=utf8mb4;';
        if ($host === '' || $host === 'localhost') {
            $dsn .= 'host=localhost';
        } elseif (str_contains($host, ':') && !is_numeric(substr($host, strrpos($host, ':') + 1))) {
            $dsn .= 'unix_socket=' . substr($host, strrpos($host, ':') + 1);
        } else {
            [$h, $port] = array_pad(explode(':', $host, 2), 2, '3306');
            $dsn .= "host={$h};port={$port}";
        }
        try {
            $pdo = new \PDO($dsn, $get('DB_USER'), (string)$get('DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]);
        } catch (\Throwable) {
            return null;
        }
        return ['pdo' => $pdo, 'prefix' => $p[1] ?? 'wp_'];
    }

    private static function dbCheck(string $root): array
    {
        $db = self::wpDb($root);
        if (!$db) {
            return ['error' => 'no se pudo leer la base de datos de wp-config.php'];
        }
        [$pdo, $p] = [$db['pdo'], $db['prefix']];
        $q = static function (string $sql) use ($pdo) {
            try {
                return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                return [['error' => $e->getMessage()]];
            }
        };
        return [
            'administrators' => $q("SELECT u.user_login, u.user_email, u.user_registered FROM {$p}users u JOIN {$p}usermeta m ON m.user_id = u.ID
                                    AND m.meta_key = '{$p}capabilities' WHERE m.meta_value LIKE '%administrator%' ORDER BY u.user_registered"),
            'suspicious_options' => array_column($q("SELECT option_name FROM {$p}options WHERE option_value LIKE '%<script%'
                                    AND (option_value LIKE '%atob%' OR option_value LIKE '%fromCharCode%' OR option_value LIKE '%eval(%' OR option_value LIKE '%document.write%') LIMIT 30"), 'option_name'),
            'posts_with_external_script' => $q("SELECT ID, post_title, post_date FROM {$p}posts WHERE post_status = 'publish'
                                    AND post_content LIKE '%<script%src=%' ORDER BY post_date DESC LIMIT 20"),
            'posts_last_30_days' => (int)($q("SELECT count(*) AS n FROM {$p}posts WHERE post_status = 'publish' AND post_type = 'post'
                                    AND post_date > NOW() - INTERVAL 30 DAY")[0]['n'] ?? 0),
            'active_plugins' => (string)($q("SELECT option_value FROM {$p}options WHERE option_name = 'active_plugins'")[0]['option_value'] ?? ''),
        ];
    }

    /** Indicios rápidos (segundos): PHP en uploads, carpetas raras, mu-plugins, plugins de malware conocido. */
    public static function quickScan(string $root): array
    {
        $root = rtrim($root, '/');
        $find = static fn(string $args) => array_values(array_filter(explode("\n", trim((string)shell_exec(
            'find ' . escapeshellarg($root) . ' ' . $args . ' 2>/dev/null | head -30')))));
        $rel = static fn(array $l) => array_map(static fn($f) => substr($f, strlen($root) + 1), $l);
        $bad = array_values(array_filter(array_map('basename', glob("{$root}/wp-content/plugins/*", GLOB_ONLYDIR) ?: []),
            static fn($p) => in_array($p, self::KNOWN_BAD, true) || str_starts_with($p, 'micro-relay')));
        return [
            'php_in_uploads' => $rel($find("-regextype posix-extended -path '*/wp-content/uploads/*' -type f -iregex '.*\\.(php[0-9]?|phtml|phar|pht)'")),
            'odd_in_wp_content' => $rel($find("-maxdepth 2 -regextype posix-extended -regex '.*/wp-content/(wp-[0-9a-f]{12}-prev|\\.[0-9a-f]{16})'")),
            'known_bad_plugins' => $bad,
            'mu_plugins' => array_map('basename', glob("{$root}/wp-content/mu-plugins/*.php") ?: []),
            'php_changed_14d' => count($find("-name '*.php' -mtime -14 -not -path '*/cache/*'")),
        ];
    }

    /** Análisis completo de una raíz WordPress. Solo lee. */
    public static function scan(string $root, bool $online = true): array
    {
        $root = rtrim($root, '/');
        if (!self::isWordPress($root)) {
            return ['ok' => false, 'error' => 'No es una instalación de WordPress.'];
        }
        $find = static fn(string $args) => array_values(array_filter(explode("\n", trim((string)shell_exec(
            'find ' . escapeshellarg($root) . ' ' . $args . ' 2>/dev/null | head -60')))));
        $rel = static fn(array $l) => array_map(static fn($f) => substr($f, strlen($root) + 1), $l);
        $mu = [];
        foreach (glob("{$root}/wp-content/mu-plugins/*") ?: [] as $f) {
            $own = str_contains((string)@file_get_contents($f, false, null, 0, 300), self::MU_MARK);
            $mu[] = basename($f) . ' (' . date('Y-m-d', (int)filemtime($f)) . ($own ? ', del panel' : '') . ')';
        }
        $wpc = (string)@file_get_contents("{$root}/wp-config.php");
        $r = [
            'ok' => true,
            'root' => $root,
            'level' => null,
            'locked' => self::isLocked($root),
            'php_in_uploads' => $rel($find("-regextype posix-extended -path '*/wp-content/uploads/*' -type f -iregex '.*\\.(php[0-9]?|phtml|phar|pht)'")),
            'zips_in_uploads_60d' => $rel($find("-path '*/wp-content/uploads/*' -name '*.zip' -mtime -60")),
            'mu_plugins' => $mu,
            'odd_in_wp_content' => $rel($find("-maxdepth 2 -regextype posix-extended -regex '.*/wp-content/(wp-[0-9a-f]{12}-prev|\\.[0-9a-f]{16})'")),
            'php_changed_14d' => $rel($find("-name '*.php' -mtime -14 -not -path '*/cache/*'")),
            'signature_hits' => self::signatureHits($root),
            'wp_config' => [
                'DISALLOW_FILE_MODS' => (bool)preg_match("/define\s*\(\s*['\"]DISALLOW_FILE_MODS['\"]\s*,\s*true/i", $wpc),
                'DISALLOW_FILE_EDIT' => (bool)preg_match("/define\s*\(\s*['\"]DISALLOW_FILE_EDIT['\"]\s*,\s*true/i", $wpc),
            ],
        ];
        if ($online) {
            $r['core'] = self::coreCheck($root);
            $r['plugins'] = self::pluginsCheck($root);
        }
        $r['database'] = self::dbCheck($root);
        $bad = count($r['php_in_uploads']) + count($r['signature_hits']) + count($r['odd_in_wp_content'])
            + count(array_filter($r['plugins'] ?? [], static fn($p) => str_starts_with((string)($p['verdict'] ?? ''), 'MALWARE') || str_starts_with((string)($p['verdict'] ?? ''), 'MODIFICADO')))
            + (int)($r['core']['changed_count'] ?? 0) + (int)($r['core']['extra_count'] ?? 0);
        $r['verdict'] = $bad ? "{$bad} indicios: revisar y limpiar (quarantine / reinstall)" : 'sin indicios';
        return $r;
    }

    // ───────────────────────── Reparación (mueve, no borra) ─────────────────────────

    private static function quarantineBase(string $domain): string
    {
        static $stamp = null;
        $stamp ??= date('Ymd_His');
        $d = self::QUARANTINE_DIR . '/' . preg_replace('/[^a-z0-9.-]/i', '_', $domain) . "/{$stamp}";
        @mkdir($d, 0700, true);
        @chmod(self::QUARANTINE_DIR, 0700);
        return $d;
    }

    /** Mueve rutas (relativas a la raíz) a cuarentena, conservando su ruta. Nada se borra. */
    public static function quarantine(string $root, string $domain, array $paths): array
    {
        $root = rtrim($root, '/');
        $base = self::quarantineBase($domain);
        $moved = [];
        $errors = [];
        foreach ($paths as $p) {
            $p = ltrim(trim((string)$p), '/');
            $src = realpath("{$root}/{$p}");
            if ($p === '' || $src === false || !str_starts_with($src, $root . '/') || $src === "{$root}/wp-config.php") {
                $errors[] = "{$p}: no existe o está fuera de la web (wp-config.php no se mueve)";
                continue;
            }
            $dst = $base . '/' . substr($src, strlen($root) + 1);
            @mkdir(dirname($dst), 0700, true);
            if (@rename($src, $dst)) {
                $moved[] = substr($src, strlen($root) + 1);
                file_put_contents("{$base}/MANIFEST.txt", date('c') . "  {$src}\n", FILE_APPEND);
            } else {
                $errors[] = "{$p}: no se pudo mover";
            }
        }
        if ($moved) {
            LogService::log('wp.quarantine', $domain, count($moved) . ' ruta(s) a cuarentena: ' . implode(', ', array_slice($moved, 0, 10)));
        }
        return ['ok' => !$errors, 'moved' => $moved, 'quarantine' => $base, 'errors' => $errors];
    }

    private static function downloadZip(string $url): ?string
    {
        [$c, $b] = self::http($url, 120);
        if ($c !== 200 || strlen($b) < 1000) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'wpzip');
        file_put_contents($tmp, $b);
        $z = new \ZipArchive();
        if ($z->open($tmp) !== true) {
            @unlink($tmp);
            return null;
        }
        $dir = $tmp . '.d';
        @mkdir($dir, 0700);
        $z->extractTo($dir);
        $z->close();
        @unlink($tmp);
        return $dir;
    }

    private static function owner(string $root, string $user): string
    {
        $state = json_decode((string)@file_get_contents(self::LOCK_DIR . "/{$user}.json"), true) ?: [];
        $grp = (string)($state['group'] ?? (posix_getgrgid(filegroup($root))['name'] ?? $user));
        return self::isLocked($root) ? "root:{$grp}" : "{$user}:{$grp}";
    }

    /** Reinstala el núcleo de su misma versión: los ficheros cambiados o de más van a cuarentena. */
    public static function reinstallCore(string $root, string $domain, string $user): array
    {
        $root = rtrim($root, '/');
        $chk = self::coreCheck($root);
        if (!empty($chk['error'])) {
            return ['ok' => false, 'error' => $chk['error']];
        }
        $ver = $chk['version'];
        $loc = $chk['locale'];
        $dir = self::downloadZip($loc !== 'en_US' ? "https://downloads.wordpress.org/release/{$loc}/wordpress-{$ver}.zip" : "https://downloads.wordpress.org/release/wordpress-{$ver}.zip")
            ?? self::downloadZip("https://downloads.wordpress.org/release/wordpress-{$ver}.zip");
        if (!$dir || !is_dir("{$dir}/wordpress/wp-includes")) {
            return ['ok' => false, 'error' => "No se pudo descargar WordPress {$ver}"];
        }
        $full = self::coreCheck($root, PHP_INT_MAX); // lista completa, sin recortar
        $q = self::quarantine($root, $domain, array_merge($full['changed'], $full['extra']));
        $src = escapeshellarg("{$dir}/wordpress/");
        shell_exec("cp -a {$src}wp-admin {$src}wp-includes " . escapeshellarg($root) . '/ 2>&1');
        shell_exec("find {$src} -maxdepth 1 -type f -name '*.php' ! -name 'wp-config-sample.php' -exec cp -a {} " . escapeshellarg($root) . '/ \\; 2>&1');
        shell_exec('chown -R ' . escapeshellarg(self::owner($root, $user)) . ' ' . escapeshellarg("{$root}/wp-admin") . ' ' . escapeshellarg("{$root}/wp-includes") . ' 2>&1');
        foreach (glob("{$dir}/wordpress/*.php") ?: [] as $f) {
            @chown("{$root}/" . basename($f), explode(':', self::owner($root, $user))[0]);
        }
        shell_exec('rm -rf ' . escapeshellarg($dir));
        $after = self::coreCheck($root);
        LogService::log('wp.repair', $domain, "Núcleo WordPress {$ver} reinstalado; " . count($q['moved']) . ' fichero(s) a cuarentena');
        return ['ok' => true, 'version' => $ver, 'quarantined' => $q['moved'], 'quarantine' => $q['quarantine'],
            'now' => ['changed' => $after['changed_count'] ?? null, 'extra' => $after['extra_count'] ?? null]];
    }

    /** Reinstala un plugin o tema de wordpress.org en su versión; el viejo va entero a cuarentena. */
    public static function reinstallPackage(string $root, string $domain, string $user, string $type, string $slug): array
    {
        $root = rtrim($root, '/');
        if (!preg_match('/^[a-z0-9._-]+$/i', $slug) || !in_array($type, ['plugin', 'theme'], true)) {
            return ['ok' => false, 'error' => 'Nombre no válido.'];
        }
        $path = $type === 'plugin' ? "wp-content/plugins/{$slug}" : "wp-content/themes/{$slug}";
        if (!is_dir("{$root}/{$path}")) {
            return ['ok' => false, 'error' => "No existe {$path}."];
        }
        $ver = '';
        if ($type === 'plugin') {
            foreach (glob("{$root}/{$path}/*.php") ?: [] as $f) {
                if (self::headerValue($f, 'Plugin Name') !== '') {
                    $ver = self::headerValue($f, 'Version');
                    break;
                }
            }
        } else {
            $ver = self::headerValue("{$root}/{$path}/style.css", 'Version');
        }
        $base = $type === 'plugin' ? 'https://downloads.wordpress.org/plugin/' : 'https://downloads.wordpress.org/theme/';
        $dir = ($ver !== '' ? self::downloadZip("{$base}{$slug}.{$ver}.zip") : null) ?? self::downloadZip("{$base}{$slug}.zip");
        if (!$dir || !is_dir("{$dir}/{$slug}")) {
            return ['ok' => false, 'error' => "{$slug} no está en wordpress.org (o esa versión). Si es malware o no lo usas, muévelo a cuarentena."];
        }
        $q = self::quarantine($root, $domain, [$path]);
        if (!$q['moved']) {
            shell_exec('rm -rf ' . escapeshellarg($dir));
            return ['ok' => false, 'error' => 'No se pudo apartar la versión actual: ' . implode('; ', $q['errors'])];
        }
        shell_exec('mv ' . escapeshellarg("{$dir}/{$slug}") . ' ' . escapeshellarg("{$root}/{$path}") . ' 2>&1');
        shell_exec('chown -R ' . escapeshellarg(self::owner($root, $user)) . ' ' . escapeshellarg("{$root}/{$path}") . ' 2>&1');
        shell_exec('rm -rf ' . escapeshellarg($dir));
        LogService::log('wp.repair', $domain, "{$type} {$slug} reinstalado desde wordpress.org" . ($ver ? " ({$ver})" : ''));
        return ['ok' => true, 'type' => $type, 'slug' => $slug, 'version' => $ver ?: 'la última', 'quarantine' => $q['quarantine']];
    }

    /** Nuevas claves y salts en wp-config.php (cierra todas las sesiones). Copia antes a cuarentena. */
    public static function rotateSalts(string $root, string $domain): array
    {
        $file = rtrim($root, '/') . '/wp-config.php';
        $cfg = (string)@file_get_contents($file);
        if ($cfg === '') {
            return ['ok' => false, 'error' => 'No se puede leer wp-config.php.'];
        }
        [$c, $b] = self::http('https://api.wordpress.org/secret-key/1.1/salt/');
        if ($c !== 200 || substr_count($b, 'define(') < 8) {
            return ['ok' => false, 'error' => 'wordpress.org no dio claves nuevas.'];
        }
        $new = [];
        foreach (explode("\n", trim($b)) as $line) {
            if (preg_match("/define\('([A-Z_]+)'/", $line, $m)) {
                $new[$m[1]] = trim($line);
            }
        }
        $n = 0;
        foreach ($new as $k => $line) {
            $cfg = preg_replace_callback("/define\s*\(\s*['\"]{$k}['\"]\s*,\s*(['\"]).*?\\1\s*\)\s*;/s", static function () use ($line, &$n) {
                $n++;
                return $line;
            }, $cfg, 1);
        }
        if ($n < 8) {
            return ['ok' => false, 'error' => "Solo se encontraron {$n} de 8 claves en wp-config.php; no se cambia nada."];
        }
        $base = self::quarantineBase($domain);
        copy($file, "{$base}/wp-config.php.antes-de-claves");
        @chmod("{$base}/wp-config.php.antes-de-claves", 0600);
        file_put_contents($file, $cfg); // mismo fichero: conserva dueño y permisos
        LogService::log('wp.repair', $domain, 'Claves y salts de wp-config.php regeneradas (sesiones cerradas)');
        return ['ok' => true, 'keys' => $n, 'backup' => "{$base}/wp-config.php.antes-de-claves", 'note' => 'Todas las sesiones se han cerrado: hay que volver a entrar.'];
    }
}
