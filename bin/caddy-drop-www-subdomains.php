<?php
/**
 * Quita de las rutas del panel en Caddy los "www." que se añadían a SUBDOMINIOS sin
 * tener DNS (www.develop.ejemplo.org, www.webmail.cliente.com…). Desde 1.0.283 las
 * rutas nuevas ya no los llevan; esto limpia las que ya existían.
 *
 * Solo toca rutas del panel (hostings "hosting-…" y redirecciones "redirect-…") y solo
 * quita un "www.X" si X está en la misma ruta y SystemService::hostsWithWww(X) dice
 * que no le corresponde (no es raíz de zona y www.X no tiene DNS). No reconstruye
 * nada: cambia solo la lista de nombres de esa ruta. Las rutas de otras aplicaciones
 * (p. ej. un CMS) solo con --all-routes (con la misma regla).
 *
 * Uso (como root): php bin/caddy-drop-www-subdomains.php [--all-routes]            (solo enseña)
 *                  php bin/caddy-drop-www-subdomains.php [--all-routes] --apply    (aplica)
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\SystemService;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$apply = in_array('--apply', $argv, true);
$allRoutes = in_array('--all-routes', $argv, true);
$api = (string)((require PANEL_ROOT . '/config/panel.php')['caddy']['api_url'] ?? 'http://localhost:2019');

$call = static function (string $method, string $path, $body = null) use ($api): array {
    $ch = curl_init($api . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $out = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($out, true), $out];
};

[$code, $servers] = $call('GET', '/config/apps/http/servers');
if ($code !== 200 || !is_array($servers)) {
    fwrite(STDERR, "No se pudo leer la configuración de Caddy ({$api}).\n");
    exit(1);
}

$changes = 0;
foreach ($servers as $srvName => $srv) {
    foreach (($srv['routes'] ?? []) as $route) {
        $id = (string)($route['@id'] ?? '');
        if ($id === '' || (!$allRoutes && !preg_match('/^(hosting|redirect)-/', $id))) {
            continue;   // solo rutas del panel, salvo --all-routes (y siempre con @id, para poder cambiarlas)
        }
        foreach (($route['match'] ?? []) as $mi => $m) {
            $hosts = array_values(array_map('strval', $m['host'] ?? []));
            if (!$hosts) {
                continue;
            }
            $keep = [];
            $drop = [];
            foreach ($hosts as $h) {
                $base = str_starts_with($h, 'www.') ? substr($h, 4) : '';
                if ($base !== '' && in_array($base, $hosts, true) && !in_array($h, SystemService::hostsWithWww($base), true)) {
                    $drop[] = $h;
                } else {
                    $keep[] = $h;
                }
            }
            if (!$drop) {
                continue;
            }
            $changes++;
            echo "{$id}: quitar " . implode(', ', $drop) . "\n";
            if ($apply) {
                [$c, , $raw] = $call('PATCH', "/id/{$id}/match/{$mi}/host", $keep);
                echo $c >= 200 && $c < 300 ? "  ✓ hecho\n" : "  ✗ error {$c}: " . trim($raw) . "\n";
            }
        }
    }
}
echo $changes === 0 ? "Nada que quitar.\n" : ($apply ? "Listo.\n" : "\nSolo se ha enseñado. Para aplicarlo: --apply\n");
