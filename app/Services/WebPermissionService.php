<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * Dueños de los ficheros de cada web, y carpetas que Laravel necesita.
 *
 * Caso real (2026-10-18, tras el relevo a la réplica): el principal se edita con un usuario de
 * administración (musedockcomcalamar) y los ficheros quedaban con SU dueño y modo 660. En el principal
 * la web los leía por una ACL; lsyncd copia el dueño por nombre pero no las ACL, así que en la réplica
 * la web (otro usuario) no podía leerlos: picalias.com daba 500 con «Permission denied» sobre
 * security-block.php, y festgate/cineautor solo funcionaban porque sus ficheros eran 664.
 * Y las webs Laravel daban 500 («Please provide a valid cache path») porque lsyncd excluye
 * storage/framework/* enteras y esas carpetas nunca existían en la réplica.
 *
 * Esto se ejecuta en TODOS los nodos. Nada borra datos: solo cambia dueño/grupo (no los permisos) de lo
 * que no es de root ni del usuario de la web, y antes guarda la lista de dueños anteriores
 * (/var/lib/musedock/permisos-<fecha>.txt) para poder deshacerlo (bin/web-permissions.php --restore).
 */
class WebPermissionService
{
    public const BACKUP_DIR = '/var/lib/musedock';

    /** Carpetas que no se recorren: dependencias enormes, cachés y registros (los escribe la propia web). */
    public const PRUNE = [
        'vendor', 'node_modules', '.git',
        'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs',
        'wp-content/cache', 'wp-content/upgrade',
    ];

    /** Directorios de Laravel que deben existir aunque lsyncd no copie su contenido. */
    public const LARAVEL_DIRS = [
        'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data',
        'storage/logs', 'bootstrap/cache',
    ];

    // ─────────────────────────────────────────────────────────
    // Webs
    // ─────────────────────────────────────────────────────────

    /** Webs activas del panel: dominio, usuario, raíz (httpdocs). */
    public static function accounts(?string $onlyDomain = null): array
    {
        $out = [];
        foreach (Database::fetchAll("SELECT domain, username, home_dir FROM hosting_accounts WHERE status = 'active' ORDER BY domain") as $a) {
            if ($onlyDomain !== null && $onlyDomain !== '' && strcasecmp($a['domain'], $onlyDomain) !== 0) {
                continue;
            }
            $root = rtrim((string)$a['home_dir'], '/') . '/httpdocs';
            if ($a['username'] !== '' && is_dir($root)) {
                $out[] = ['domain' => $a['domain'], 'user' => $a['username'], 'root' => $root];
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────
    // Dueños ajenos
    // ─────────────────────────────────────────────────────────

    /** Argumentos de find que saltan las carpetas de PRUNE. Pura. */
    public static function pruneExpression(): string
    {
        $parts = [];
        foreach (self::PRUNE as $p) {
            $parts[] = '-path ' . escapeshellarg('*/' . $p);
        }
        return '\( ' . implode(' -o ', $parts) . ' \) -prune -o';
    }

    /** Interpreta la salida de find (-printf '%u\t%g\t%m\t%p\0'). Pura. */
    public static function parseFind(string $raw): array
    {
        $items = [];
        foreach (explode("\0", $raw) as $rec) {
            if ($rec === '') {
                continue;
            }
            $f = explode("\t", $rec, 4);
            if (count($f) === 4) {
                $items[] = ['owner' => $f[0], 'group' => $f[1], 'mode' => $f[2], 'path' => $f[3]];
            }
        }
        return $items;
    }

    /**
     * Ficheros y carpetas de una web cuyo dueño no es root ni el usuario de la web. Con ionice/nice y
     * tiempo máximo: un recorrido de un árbol enorme no debe dejar el servidor sin disco.
     */
    public static function scan(string $root, string $webUser, int $timeoutSec = 300): array
    {
        if (!is_dir($root) || !preg_match('/^[a-z_][a-z0-9_-]*$/i', $webUser)
            || (function_exists('posix_getpwnam') && !posix_getpwnam($webUser))) {
            return [];
        }
        $cmd = 'nice -n 19 ionice -c3 timeout ' . (int)$timeoutSec . ' find ' . escapeshellarg($root) . ' -xdev '
            . self::pruneExpression()
            . ' \( ! -user root ! -user ' . escapeshellarg($webUser) . ' \) -printf ' . escapeshellarg('%u\t%g\t%m\t%p\0') . ' 2>/dev/null';
        return self::parseFind((string)shell_exec($cmd));
    }

    /**
     * Qué cambiar: dueño = usuario de la web, grupo = el de la carpeta que lo contiene (el grupo con el que
     * la web ya comparte esa carpeta). $groupOf(path) devuelve el grupo de una carpeta (inyectable para probar).
     * Pura salvo $groupOf.
     */
    public static function plan(array $items, string $webUser, callable $groupOf): array
    {
        $plan = [];
        foreach ($items as $it) {
            $parent = dirname($it['path']);
            $group = (string)$groupOf($parent);
            $plan[] = $it + ['new_owner' => $webUser, 'new_group' => $group !== '' ? $group : $it['group']];
        }
        return $plan;
    }

    public static function groupOfDir(string $dir): string
    {
        $gid = @filegroup($dir);
        $g = $gid !== false ? @posix_getgrgid($gid) : false;
        return $g ? (string)$g['name'] : '';
    }

    /**
     * Revisa (y, con $fix, corrige) todas las webs. Devuelve por web: cuántos ficheros ajenos hay, ejemplos y
     * cuántos se han corregido. Con $fix guarda ANTES la lista de dueños anteriores.
     */
    public static function run(bool $fix, ?string $onlyDomain = null, string $reason = 'manual'): array
    {
        $report = ['fix' => $fix, 'reason' => $reason, 'webs' => [], 'total' => 0, 'fixed' => 0, 'backup' => null, 'errors' => []];
        $all = [];
        foreach (self::accounts($onlyDomain) as $acc) {
            // find ! -user <nombre> falla (sin salida) si ese usuario no existe en este servidor, y se tomaría por
            // «sin problemas». Una web registrada cuyo usuario no existe aquí ya es un problema (en una réplica,
            // la web no arrancaría): se dice.
            if (function_exists('posix_getpwnam') && !posix_getpwnam($acc['user'])) {
                $report['errors'][] = "{$acc['domain']}: el usuario {$acc['user']} no existe en este servidor (la web no podría correr aquí)";
                continue;
            }
            $items = self::scan($acc['root'], $acc['user']);
            if (!$items) {
                continue;
            }
            $plan = self::plan($items, $acc['user'], [self::class, 'groupOfDir']);
            $report['webs'][$acc['domain']] = [
                'user' => $acc['user'], 'count' => count($plan),
                'owners' => array_values(array_unique(array_column($plan, 'owner'))),
                'examples' => array_map(static fn($p) => $p['path'], array_slice($plan, 0, 5)),
                'fixed' => 0,
            ];
            $report['total'] += count($plan);
            foreach ($plan as $p) {
                $all[$acc['domain']][] = $p;
            }
        }
        if (!$fix || !$all) {
            return $report;
        }

        // 1) Guardar lo de antes (para deshacer) ANTES de cambiar nada
        @mkdir(self::BACKUP_DIR, 0755, true);
        $file = self::BACKUP_DIR . '/permisos-' . date('Ymd_His') . '.txt';
        $lines = ["# MuseDock — dueños anteriores (" . date('c') . ", motivo: {$reason}). Deshacer: php bin/web-permissions.php --restore {$file}"];
        foreach ($all as $domain => $plan) {
            foreach ($plan as $p) {
                $lines[] = implode("\t", [$p['owner'], $p['group'], $p['mode'], $p['path']]);
            }
        }
        if (@file_put_contents($file, implode("\n", $lines) . "\n") === false) {
            $report['errors'][] = "no se pudo guardar la lista de dueños anteriores en {$file}: no se cambia nada";
            return $report;
        }
        @chmod($file, 0600);
        $report['backup'] = $file;

        // 2) Cambiar dueño y grupo (los permisos no se tocan); enlaces simbólicos sin seguirlos
        foreach ($all as $domain => $plan) {
            foreach ($plan as $p) {
                if (self::chown($p['path'], $p['new_owner'], $p['new_group'])) {
                    $report['webs'][$domain]['fixed']++;
                    $report['fixed']++;
                }
            }
        }
        LogService::log('web.permissions', 'normalize', "Dueños corregidos: {$report['fixed']} de {$report['total']} (webs: " . implode(', ', array_keys($all)) . ") · copia de lo anterior en {$file}");
        return $report;
    }

    /** chown sin seguir enlaces. Devuelve true si cambió. */
    public static function chown(string $path, string $owner, string $group): bool
    {
        if (is_link($path)) {
            return @lchown($path, $owner) && ($group === '' || @lchgrp($path, $group));
        }
        return @chown($path, $owner) && ($group === '' || @chgrp($path, $group));
    }

    /** Deshace una corrección con el fichero guardado. Devuelve cuántos ficheros se restauraron. */
    public static function restore(string $file): array
    {
        if (!is_file($file) || !str_starts_with(realpath($file) ?: '', self::BACKUP_DIR . '/permisos-')) {
            return ['ok' => false, 'error' => 'solo se restauran ficheros de ' . self::BACKUP_DIR . '/permisos-*.txt', 'restored' => 0];
        }
        $n = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if ($l === '' || $l[0] === '#') {
                continue;
            }
            $f = explode("\t", $l, 4);
            if (count($f) === 4 && file_exists($f[3]) || (count($f) === 4 && is_link($f[3]))) {
                if (self::chown($f[3], $f[0], $f[1])) {
                    $n++;
                }
            }
        }
        LogService::log('web.permissions', 'restore', "Dueños restaurados desde {$file}: {$n}");
        return ['ok' => true, 'restored' => $n];
    }

    // ─────────────────────────────────────────────────────────
    // Carpetas de Laravel
    // ─────────────────────────────────────────────────────────

    /**
     * Para cada web Laravel (tiene artisan): crea las carpetas que lsyncd no copia vacías. Con el dueño de la
     * web y grupo el de la carpeta que las contiene. Solo crea; nunca toca lo que ya existe.
     */
    public static function ensureLaravelDirs(bool $apply = true, ?string $onlyDomain = null): array
    {
        $made = [];
        foreach (self::accounts($onlyDomain) as $acc) {
            if (!is_file($acc['root'] . '/artisan')) {
                continue;
            }
            foreach (self::LARAVEL_DIRS as $rel) {
                $dir = $acc['root'] . '/' . $rel;
                if (is_dir($dir)) {
                    continue;
                }
                // bootstrap/cache solo si existe bootstrap/ (no se inventa la estructura de una app rara)
                $top = explode('/', $rel)[0];
                if (!is_dir($acc['root'] . '/' . $top) && $top === 'bootstrap') {
                    continue;
                }
                $made[] = ['domain' => $acc['domain'], 'dir' => $dir];
                if ($apply) {
                    // de arriba abajo, para que cada nivel nuevo salga con el dueño de la web
                    $cur = $acc['root'];
                    foreach (explode('/', $rel) as $seg) {
                        $cur .= '/' . $seg;
                        if (!is_dir($cur) && @mkdir($cur, 0755)) {
                            self::chown($cur, $acc['user'], self::groupOfDir(dirname($cur)));
                        }
                    }
                }
            }
        }
        if ($apply && $made) {
            LogService::log('web.permissions', 'laravel-dirs', 'Carpetas de Laravel creadas: ' . count($made) . ' (' . implode(', ', array_unique(array_column($made, 'domain'))) . ')');
        }
        return $made;
    }

    // ─────────────────────────────────────────────────────────
    // Carpetas que exige cada pool de PHP-FPM (tmp, sesiones)
    // ─────────────────────────────────────────────────────────

    /**
     * Del texto de un pool de PHP-FPM: usuario, grupo y carpetas de trabajo que declara (upload_tmp_dir,
     * sys_temp_dir, session.save_path) con el modo con el que deben crearse. Pura.
     * Sin carpeta tmp, PHP solo recibe en memoria los primeros 16 KB del cuerpo de una petición y el resto
     * se pierde: el MCP de musedock.com rechazaba todo update_post de más de 16 KB (2026-10-18).
     * @return array{user:string,group:string,dirs:array<string,int>}
     */
    public static function poolDirs(string $conf): array
    {
        $out = ['user' => '', 'group' => '', 'dirs' => []];
        if (preg_match('/^\s*user\s*=\s*(\S+)/m', $conf, $m)) {
            $out['user'] = $m[1];
        }
        if (preg_match('/^\s*group\s*=\s*(\S+)/m', $conf, $m)) {
            $out['group'] = $m[1];
        }
        $modes = ['upload_tmp_dir' => 0755, 'sys_temp_dir' => 0755, 'session.save_path' => 0750];
        foreach ($modes as $key => $mode) {
            if (preg_match('/^\s*php_(?:admin_)?value\[' . preg_quote($key, '/') . '\]\s*=\s*"?([^"\s;]+)"?/m', $conf, $m)) {
                $out['dirs'][$m[1]] = $mode;
            }
        }
        return $out;
    }

    /** Solo se crean carpetas dentro de /var/www/vhosts/<web>/…, sin «..» y con la carpeta madre ya existente. */
    public static function poolDirAllowed(string $dir): bool
    {
        return (bool)preg_match('#^/var/www/vhosts/[A-Za-z0-9._-]+/[A-Za-z0-9._/-]+$#', $dir)
            && !str_contains($dir, '..') && !str_contains($dir, '//');
    }

    /**
     * Crea las carpetas de trabajo que declaran los pools de PHP-FPM y no existen, con el usuario y grupo del pool.
     * Solo crea; no toca nada que exista ni inventa la carpeta madre.
     * @return list<array{pool:string,dir:string}>
     */
    public static function ensurePoolDirs(bool $apply = true): array
    {
        $made = [];
        foreach (glob('/etc/php/*/fpm/pool.d/*.conf') ?: [] as $file) {
            $conf = (string)@file_get_contents($file);
            if ($conf === '') {
                continue;
            }
            $p = self::poolDirs($conf);
            foreach ($p['dirs'] as $dir => $mode) {
                if (is_dir($dir) || !self::poolDirAllowed($dir) || !is_dir(dirname($dir))) {
                    continue;
                }
                $made[] = ['pool' => $file, 'dir' => $dir];
                if ($apply && @mkdir($dir, $mode)) {
                    @chmod($dir, $mode);
                    if ($p['user'] !== '') {
                        self::chown($dir, $p['user'], $p['group'] !== '' ? $p['group'] : $p['user']);
                    }
                }
            }
        }
        if ($apply && $made) {
            LogService::log('web.permissions', 'pool-dirs', 'Carpetas de pools PHP creadas: ' . implode(', ', array_unique(array_column($made, 'dir'))));
        }
        return $made;
    }

    // ─────────────────────────────────────────────────────────
    // Automatismos (worker y promoción)
    // ─────────────────────────────────────────────────────────

    /** Lanza bin/web-permissions.php en segundo plano (un solo recorrido a la vez). */
    private static function spawn(string $args): void
    {
        $script = dirname(__DIR__, 2) . '/bin/web-permissions.php';
        shell_exec('nohup flock -n /run/musedock-web-permissions.lock php ' . escapeshellarg($script) . ' ' . $args . ' >/dev/null 2>&1 &');
    }

    /**
     * Al promocionar un nodo: corrige los dueños y crea las carpetas de Laravel, EN SEGUNDO PLANO (un
     * relevo no puede esperar a recorrer todas las webs). Ajuste perm_guard_on_promote=0 lo apaga.
     */
    public static function onPromote(): string
    {
        if (Settings::get('perm_guard_on_promote', '1') === '0') {
            return 'apagado (perm_guard_on_promote=0)';
        }
        self::spawn('--fix --laravel-dirs --notify --reason=promocion');
        return 'lanzado en segundo plano (dueños de las webs y carpetas de Laravel)';
    }

    /**
     * Usuarios «de trabajo» (VS Code, agentes) cuyos ficheros nuevos dentro de una web se pasan al usuario de la web
     * al momento. Ajuste perm_guard_instant_users (lista separada por comas); por defecto musedockcomcalamar.
     */
    public static function instantUsers(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string)Settings::get('perm_guard_instant_users', 'musedockcomcalamar')) ?: [] as $u) {
            if ($u !== '' && preg_match('/^[a-z_][a-z0-9_-]*$/i', $u) && (!function_exists('posix_getpwnam') || posix_getpwnam($u))) {
                $out[$u] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Cada minuto (worker): lo que ha creado o cambiado un usuario de trabajo dentro de /var/www/vhosts/<web>/httpdocs
     * pasa al usuario de la web (dueño y grupo de su carpeta; nunca los permisos), para que la web lo lea y escriba.
     * Solo avisa si algo no se pudo corregir. Se apaga con perm_guard_instant=0. Respeta PRUNE (.git, vendor…).
     */
    public static function instantFix(): string
    {
        if (Settings::get('perm_guard_mode', 'fix') !== 'fix' || Settings::get('perm_guard_instant', '1') === '0') {
            return '';
        }
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return '';
        }
        $agents = self::instantUsers();
        if (!$agents) {
            return '';
        }
        $stamp = dirname(__DIR__, 2) . '/storage/perm-instant.stamp';
        $since = is_file($stamp) ? (int)@filemtime($stamp) : time() - 120;
        @touch($stamp);   // antes de recorrer: lo que cambie durante el recorrido se coge en la pasada siguiente
        $userExpr = '\( ' . implode(' -o ', array_map(static fn($u) => '-user ' . escapeshellarg($u), $agents)) . ' \)';
        $fixed = 0;
        $failed = [];
        foreach (self::accounts() as $acc) {
            if (isset(array_flip($agents)[$acc['user']]) || !preg_match('/^[a-z_][a-z0-9_-]*$/i', $acc['user'])
                || (function_exists('posix_getpwnam') && !posix_getpwnam($acc['user']))) {
                continue;   // su propia web: ya es suya
            }
            $cmd = 'nice -n 19 ionice -c3 timeout 30 find ' . escapeshellarg($acc['root']) . ' -xdev ' . self::pruneExpression()
                . ' ' . $userExpr . ' -cnewer ' . escapeshellarg($stamp . '.prev') . ' -printf ' . escapeshellarg('%u\t%g\t%m\t%p\0') . ' 2>/dev/null';
            // -cnewer necesita una referencia con la hora de la pasada anterior
            @touch($stamp . '.prev', $since);
            $items = self::parseFind((string)shell_exec($cmd));
            foreach (self::plan($items, $acc['user'], [self::class, 'groupOfDir']) as $it) {
                if (self::chown($it['path'], $it['new_owner'], $it['new_group'])) {
                    $fixed++;
                } else {
                    $failed[] = $it['path'];
                }
            }
        }
        if ($failed) {
            try {
                $h = (string)gethostname();
                NotificationService::send("No se pudieron corregir ficheros de una web en {$h}",
                    count($failed) . " ficheros creados por un usuario de trabajo no se pudieron pasar al usuario de la web. Ej.: "
                    . implode(', ', array_slice($failed, 0, 3)) . "\n\nCorregir: php bin/web-permissions.php --fix (como root).", 'web_permissions');
            } catch (\Throwable) {
            }
        }
        return $fixed > 0 || $failed ? "corregidos al momento: {$fixed}" . ($failed ? ', sin poder: ' . count($failed) : '') : '';
    }

    /**
     * ACL por defecto en cada web: lo que cree un usuario de trabajo queda legible para el usuario de la web
     * (y escribible si el umask del que crea lo permite). Idempotente. Solo carpetas (los ficheros heredan).
     * @return array<string,string> dominio => resultado
     */
    public static function ensureDefaultAcl(?string $onlyDomain = null): array
    {
        $out = [];
        if (!trim((string)shell_exec('command -v setfacl 2>/dev/null'))) {
            return ['_' => 'setfacl no está instalado (apt install acl)'];
        }
        foreach (self::accounts($onlyDomain) as $acc) {
            if (!preg_match('/^[a-z_][a-z0-9_-]*$/i', $acc['user'])) {
                continue;
            }
            $cmd = 'nice -n 19 ionice -c3 timeout 120 find ' . escapeshellarg($acc['root']) . ' -xdev ' . self::pruneExpression()
                . ' -type d -exec setfacl -m ' . escapeshellarg('d:u:' . $acc['user'] . ':rwX') . ' -m ' . escapeshellarg('u:' . $acc['user'] . ':rwX') . ' {} + 2>&1';
            $o = trim((string)shell_exec($cmd));
            $out[$acc['domain']] = $o === '' ? 'ok' : mb_substr($o, 0, 120);
        }
        return $out;
    }

    /**
     * Cada pasada del worker, como mucho una vez al día (perm_guard_interval_h): revisa los dueños en TODOS los
     * nodos. Por defecto corrige (perm_guard_mode=fix); con perm_guard_mode=warn solo avisa.
     */
    public static function periodic(): string
    {
        $every = max(1, (int)Settings::get('perm_guard_interval_h', '24')) * 3600;
        if (time() - (int)Settings::get('perm_guard_run_at', '0') < $every) {
            return '';
        }
        Settings::set('perm_guard_run_at', (string)time());
        $fix = Settings::get('perm_guard_mode', 'fix') === 'fix';   // por defecto corrige (perm_guard_mode=warn: solo avisa)
        self::spawn(($fix ? '--fix ' : '') . '--laravel-dirs --acl --notify --reason=diario');
        return 'revisión diaria lanzada en segundo plano (' . ($fix ? 'corrige' : 'solo avisa') . ')';
    }

    /** Aviso (una vez por cambio) con las webs que tienen ficheros ajenos. */
    public static function notify(array $report): void
    {
        $left = [];
        foreach ($report['webs'] as $domain => $w) {
            $remaining = $w['count'] - $w['fixed'];
            if ($remaining > 0) {
                $left[$domain] = $w + ['remaining' => $remaining];
            }
        }
        $hash = $left ? md5(json_encode(array_map(static fn($w) => $w['remaining'], $left))) : '';
        if ($hash === (string)Settings::get('perm_guard_alert_hash', '')) {
            return;
        }
        Settings::set('perm_guard_alert_hash', $hash);
        $host = (string)gethostname();
        if (!$left) {
            if ($report['fixed'] > 0 && ($report['reason'] ?? '') !== 'diario') {   // la pasada diaria corrige sin molestar
                NotificationService::send("Dueños de ficheros corregidos en {$host}", "Se han corregido {$report['fixed']} ficheros/carpetas con dueño ajeno. Lista anterior: " . ($report['backup'] ?? '-'), 'web_permissions');
            }
            return;
        }
        $lines = [];
        foreach ($left as $domain => $w) {
            $lines[] = "• {$domain}: {$w['remaining']} con dueño " . implode('/', $w['owners']) . " (la web corre como {$w['user']}). Ej.: " . implode(', ', array_slice($w['examples'], 0, 2));
        }
        NotificationService::send("Ficheros con dueño ajeno en {$host}", "Estas webs tienen ficheros cuyo dueño no es root ni el usuario de la web. Si el servidor se replica o toma el mando, la web puede no poder leerlos (error 500).\n\n"
            . implode("\n", $lines) . "\n\nCorregir: php bin/web-permissions.php --fix   (guarda antes los dueños anteriores). Ajuste perm_guard_mode=fix para que lo haga cada día.", 'web_permissions');
    }
}
