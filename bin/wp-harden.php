<?php
/**
 * Blindar WordPress (WordPressHardenService). Nada se borra: lo que se quita va a
 * /var/lib/musedock/wp-quarantine/<dominio>/<fecha>/ (con MANIFEST.txt).
 *
 *   php bin/wp-harden.php list                                   hostings WordPress, nivel y estado
 *   php bin/wp-harden.php scan <dominio> [--offline]             análisis (no ejecuta el PHP del sitio)
 *   php bin/wp-harden.php set <dominio> <off|standard|strict> [--xmlrpc=auto|on|off]
 *   php bin/wp-harden.php set-all standard                      todos los WordPress a standard (no toca los strict)
 *   php bin/wp-harden.php unlock <dominio> [minutos]             strict: abre el código para actualizar (30 por defecto)
 *   php bin/wp-harden.php lock <dominio>                         strict: lo vuelve a cerrar ya
 *   php bin/wp-harden.php ensure                                 reglas de Caddy al día y strict cerrados (lo hace el cluster-worker)
 *   php bin/wp-harden.php quarantine <dominio> <ruta> [ruta…]    mueve rutas (relativas a la web) a cuarentena
 *   php bin/wp-harden.php reinstall-core <dominio>               núcleo de su versión desde wordpress.org
 *   php bin/wp-harden.php reinstall-plugin <dominio> <slug>      plugin de wordpress.org (el actual, a cuarentena)
 *   php bin/wp-harden.php reinstall-theme <dominio> <slug>       tema de wordpress.org (el actual, a cuarentena)
 *   php bin/wp-harden.php rotate-salts <dominio>                 claves nuevas en wp-config.php (cierra todas las sesiones)
 *   php bin/wp-harden.php ban <ip> [segundos] | unban <ip> | bans    (lo usa fail2ban)
 *
 * En un hosting con subdominios WordPress, <dominio> puede ser el subdominio para scan,
 * quarantine y reinstall (se usa su raíz).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Database;
use MuseDockPanel\Services\WordPressHardenService as W;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$print = static fn($d) => print(json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => !str_starts_with($a, '--')));
$opt = static function (string $name) use ($argv): ?string {
    foreach ($argv as $a) {
        if ($a === "--{$name}") {
            return '1';
        }
        if (str_starts_with($a, "--{$name}=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return null;
};

/** Hosting y raíz WordPress de un dominio (principal o subdominio). */
$target = static function (string $domain): array {
    $acc = W::account($domain);
    $root = $acc ? W::docRoot($acc) : '';
    if (!$acc) {
        $sub = Database::fetchOne('SELECT s.document_root, a.* FROM hosting_subdomains s JOIN hosting_accounts a ON a.id = s.account_id WHERE lower(s.subdomain) = :d',
            ['d' => strtolower($domain)]);
        if ($sub) {
            $root = rtrim((string)$sub['document_root'], '/');
            $acc = W::account((string)$sub['domain']);
        }
    }
    if (!$acc) {
        fwrite(STDERR, "No hay ningún hosting ni subdominio '{$domain}'.\n");
        exit(1);
    }
    if (!W::isWordPress($root)) {
        fwrite(STDERR, "{$root} no es una instalación de WordPress.\n");
        exit(1);
    }
    return [$acc, $root];
};

switch ($args[0] ?? '') {
    case 'list':
        foreach (Database::fetchAll('SELECT * FROM hosting_accounts ORDER BY domain') as $acc) {
            foreach (W::wpRoots($acc) as $root) {
                $s = W::settingsFor((string)$acc['username']);
                printf("%-32s %-9s xmlrpc:%-5s %-9s %s\n", $acc['domain'], $s['level'], $s['allow_xmlrpc'] === null ? 'auto' : ($s['allow_xmlrpc'] ? 'on' : 'off'),
                    W::isLocked($root) ? 'cerrado' : 'abierto', $root);
            }
        }
        echo 'IPs baneadas en Caddy ahora: ' . W::bannedCount() . "\n";
        break;

    case 'scan':
        [$acc, $root] = $target((string)($args[1] ?? ''));
        $r = W::scan($root, $opt('offline') === null);
        $r['level'] = W::settingsFor((string)$acc['username'])['level'];
        $print($r);
        break;

    case 'set':
        $acc = W::account((string)($args[1] ?? ''));
        if (!$acc || !in_array($args[2] ?? '', W::LEVELS, true)) {
            fwrite(STDERR, "Uso: set <dominio> <off|standard|strict> [--xmlrpc=auto|on|off]\n");
            exit(1);
        }
        $x = $opt('xmlrpc');
        $print(W::setLevel($acc, $args[2], $x === null ? 'keep' : ($x === 'auto' ? null : $x === 'on')));
        break;

    case 'set-all':
        if (($args[1] ?? '') !== 'standard') {
            fwrite(STDERR, "Uso: set-all standard   (los strict se dejan como están)\n");
            exit(1);
        }
        foreach (Database::fetchAll("SELECT * FROM hosting_accounts WHERE status = 'active' ORDER BY domain") as $acc) {
            if (!W::wpRoots($acc) || W::settingsFor((string)$acc['username'])['level'] === 'strict') {
                continue;
            }
            $r = W::setLevel($acc, 'standard');
            echo str_pad((string)$acc['domain'], 32) . (!empty($r['ok']) ? 'standard' : 'ERROR ' . json_encode($r['roots'] ?? [])) . "\n";
        }
        break;

    case 'unlock':
        [$acc] = $target((string)($args[1] ?? ''));
        $print(W::unlock($acc, (int)($args[2] ?? 30)));
        break;

    case 'lock':
        [$acc, $root] = $target((string)($args[1] ?? ''));
        if (W::settingsFor((string)$acc['username'])['level'] !== 'strict') {
            fwrite(STDERR, "Ese hosting no está en strict (usa: set <dominio> strict).\n");
            exit(1);
        }
        Database::query('UPDATE hosting_accounts SET wp_unlock_until = NULL WHERE id = :id', ['id' => (int)$acc['id']]);
        echo W::lock($root, (string)$acc['username']) . "\n";
        break;

    case 'ensure':
        $print(W::ensureAll());
        break;

    case 'quarantine':
        [$acc, $root] = $target((string)($args[1] ?? ''));
        $paths = array_slice($args, 2);
        if (!$paths) {
            fwrite(STDERR, "Uso: quarantine <dominio> <ruta relativa> [ruta…]\n");
            exit(1);
        }
        $print(W::quarantine($root, (string)$args[1], $paths));
        break;

    case 'reinstall-core':
        [$acc, $root] = $target((string)($args[1] ?? ''));
        $print(W::reinstallCore($root, (string)$args[1], (string)$acc['username']));
        break;

    case 'reinstall-plugin':
    case 'reinstall-theme':
        [$acc, $root] = $target((string)($args[1] ?? ''));
        $print(W::reinstallPackage($root, (string)$args[1], (string)$acc['username'], $args[0] === 'reinstall-plugin' ? 'plugin' : 'theme', (string)($args[2] ?? '')));
        break;

    case 'rotate-salts':
        [$acc, $root] = $target((string)($args[1] ?? ''));
        $print(W::rotateSalts($root, (string)$args[1]));
        break;

    case 'ban':
        exit(W::ban((string)($args[1] ?? ''), (int)($args[2] ?? 7200)) ? 0 : 1);

    case 'unban':
        exit(W::unban((string)($args[1] ?? '')) ? 0 : 1);

    case 'bans':
        echo W::bannedCount() . " IP(s) baneadas en Caddy\n";
        break;

    default:
        fwrite(STDERR, "Uso: list | scan | set | set-all | unlock | lock | ensure | quarantine | reinstall-core | reinstall-plugin | reinstall-theme | rotate-salts | ban | unban | bans\n");
        exit(1);
}
