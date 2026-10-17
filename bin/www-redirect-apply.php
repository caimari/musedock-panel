#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — www → sin www (301) en los hostings que ya existen.
 *
 * Los hostings nuevos ya lo llevan por defecto (SystemService::addCaddyRoute). Este script lo aplica a los que
 * ya estaban: quita www.X de la ruta del hosting y añade la ruta 301 www.X → https://X{ruta}.
 * Si la web de X ya manda al www (p. ej. WordPress configurado con www), redirigir al revés haría un bucle:
 * ese dominio se deja como está y se añade a la lista de los que conservan www (hosting_www_keep).
 *
 * Uso:  php bin/www-redirect-apply.php            simulación (dice qué haría)
 *       php bin/www-redirect-apply.php --apply    lo aplica
 */

define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

use MuseDockPanel\Database;
use MuseDockPanel\Services\SystemService;

$apply = in_array('--apply', $argv, true);
$config = require PANEL_ROOT . '/config/panel.php';
$api = $config['caddy']['api_url'];

$http = function (string $method, string $path, ?array $body = null) use ($api): array {
    $ch = curl_init($api . $path);
    $o = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15];
    if ($body !== null) {
        $o[CURLOPT_POSTFIELDS] = json_encode($body);
        $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $o);
    $out = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, $out];
};

/** ¿La web de $apex ya redirige al www? (se prueba en local, sin seguir redirecciones) */
$redirectsToWww = function (string $apex): bool {
    $ch = curl_init("https://{$apex}/");
    curl_setopt_array($ch, [CURLOPT_RESOLVE => ["{$apex}:443:127.0.0.1"], CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 12, CURLOPT_HEADER => true]);
    $head = (string) curl_exec($ch);
    curl_close($ch);

    return (bool) preg_match('#^location:\s*https?://www\.' . preg_quote($apex, '#') . '([/:?]|\s|$)#mi', $head);
};

[$code, $raw] = $http('GET', '/config/apps/http/servers/srv0/routes');
$routes = json_decode((string) $raw, true);
if ($code !== 200 || !is_array($routes)) {
    fwrite(STDERR, "No se pudo leer la configuración de Caddy (HTTP {$code})\n");
    exit(1);
}
// www.X → [@id de su ruta de hosting, índice del match]
$where = [];
foreach ($routes as $r) {
    $id = (string) ($r['@id'] ?? '');
    if (!str_starts_with($id, 'hosting-')) {
        continue;
    }
    foreach (($r['match'] ?? []) as $mi => $m) {
        foreach (($m['host'] ?? []) as $h) {
            $where[strtolower($h)] = [$id, $mi, $m['host']];
        }
    }
}

$names = [];
foreach (Database::fetchAll("SELECT domain FROM hosting_accounts WHERE status = 'active'") as $a) {
    $names[] = strtolower($a['domain']);
}
foreach (Database::fetchAll("SELECT domain FROM hosting_domain_aliases WHERE type = 'alias'") as $a) {
    $names[] = strtolower($a['domain']);
}

$done = $kept = $skipped = 0;
foreach (array_unique($names) as $apex) {
    $www = 'www.' . $apex;
    if (!isset($where[$www])) {
        continue; // el www no lo sirve una ruta de hosting (ya redirigido, o no existe)
    }
    if (!SystemService::wwwRedirectEnabled($apex)) {
        echo "  = {$apex}: en la lista de los que conservan www; no se toca\n";
        $skipped++;
        continue;
    }
    if ($redirectsToWww($apex)) {
        echo "  ! {$apex}: su web ya manda al www (bucle si se redirige al revés) → se deja con www\n";
        if ($apply) {
            SystemService::wwwKeep($apex);
        }
        $kept++;
        continue;
    }
    echo "  → {$www} → https://{$apex}/ (301)\n";
    if ($apply) {
        [$rid, $mi, $hosts] = $where[$www];
        $new = array_values(array_filter($hosts, fn ($h) => strtolower($h) !== $www));
        [$c] = $http('PATCH', "/id/{$rid}/match/{$mi}/host", $new);
        if ($c < 200 || $c >= 300) {
            echo "    ERROR al quitar {$www} de {$rid} (HTTP {$c}); no se sigue con este dominio\n";
            continue;
        }
        SystemService::applyWwwRedirects($api, [$www => $apex]);
        // refrescar el mapa (los índices de host de esa ruta cambian)
        foreach ($new as $h) {
            $where[strtolower($h)][2] = $new;
        }
    }
    $done++;
}
echo ($apply ? 'Aplicado' : 'Simulación') . ": {$done} con 301, {$kept} conservan www por su propia web, {$skipped} excluidos a mano.\n";
if (!$apply) {
    echo "Añade --apply para aplicarlo.\n";
}
