#!/usr/bin/env php
<?php
/**
 * Lightweight Caddy repair for MuseDock Panel nodes.
 * Ensures srv0/listeners exist and reapplies panel domain route/TLS policies.
 */

define('PANEL_ROOT', dirname(__DIR__));

spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');
$config = require PANEL_ROOT . '/config/panel.php';
$caddyApi = $config['caddy']['api_url'] ?? 'http://localhost:2019';

echo "[repair-caddy] API: {$caddyApi}\n";

if (!\MuseDockPanel\Services\SystemService::ensureCaddyHttpServerReady($caddyApi)) {
    fwrite(STDERR, "[repair-caddy] ERROR: no se pudo preparar srv0/listeners.\n");
    exit(1);
}
echo "[repair-caddy] OK: srv0/listeners activos.\n";

try {
    \MuseDockPanel\Services\SystemService::ensureTlsCatchAllPolicy($caddyApi);
    echo "[repair-caddy] OK: politicas TLS verificadas.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "[repair-caddy] WARNING TLS: " . $e->getMessage() . "\n");
}

$panelHostname = trim((string)\MuseDockPanel\Settings::get('panel_hostname', ''));
if ($panelHostname !== '') {
    $result = \MuseDockPanel\Services\SystemService::configurePanelDomainRoute($panelHostname);
    if ($result['ok'] ?? false) {
        echo "[repair-caddy] OK: ruta del panel aplicada para {$panelHostname}.\n";
    } else {
        fwrite(STDERR, "[repair-caddy] WARNING route: " . ($result['error'] ?? 'error desconocido') . "\n");
    }
} else {
    echo "[repair-caddy] INFO: panel_hostname vacio, no se aplica ruta dedicada.\n";
}

echo "[repair-caddy] DONE\n";
