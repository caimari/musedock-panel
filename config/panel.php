<?php
/**
 * MuseDock Panel - Configuration
 * Values are loaded from .env file. Fallbacks provided for backwards compatibility.
 */

// Load .env if not already loaded
\MuseDockPanel\Env::load(dirname(__DIR__) . '/.env');

// IMPORTANT: variables defined here leak into the calling function's scope
// (PHP `require` does NOT isolate scope). Use unique prefixed names to avoid
// silently overwriting local variables in callers (e.g. $phpVersion parameter
// in SystemService::addCaddyRoute() was being clobbered, causing all Caddy
// routes to be created with PHP 8.3 regardless of the hosting's real version).
$panelCfgPhpVersion = \MuseDockPanel\Env::get('FPM_PHP_VERSION', '8.3');
$panelCfgRoot = dirname(__DIR__);

return [
    'name' => \MuseDockPanel\Env::get('PANEL_NAME', 'MuseDock Panel'),
    'version' => '1.0.127',
    'port' => \MuseDockPanel\Env::int('PANEL_PORT', 8444),
    'debug' => \MuseDockPanel\Env::bool('PANEL_DEBUG', false),

    // Database (PostgreSQL)
    'db' => [
        'driver' => 'pgsql',
        'host' => \MuseDockPanel\Env::get('DB_HOST', '127.0.0.1'),
        'port' => \MuseDockPanel\Env::int('DB_PORT', 5432),
        'database' => \MuseDockPanel\Env::get('DB_NAME', 'musedock_panel'),
        'username' => \MuseDockPanel\Env::get('DB_USER', 'musedock_panel'),
        'password' => \MuseDockPanel\Env::get('DB_PASS', ''),
    ],

    // Session
    'session' => [
        'name' => 'musedock_panel_session',
        'lifetime' => \MuseDockPanel\Env::int('SESSION_LIFETIME', 7200),
        'path' => "{$panelCfgRoot}/storage/sessions",
    ],

    // Paths
    'paths' => [
        'vhosts' => \MuseDockPanel\Env::get('VHOSTS_DIR', '/var/www/vhosts'),
        'fpm_pools' => "/etc/php/{$panelCfgPhpVersion}/fpm/pool.d",
        'logs' => "{$panelCfgRoot}/storage/logs",
    ],

    // Caddy
    'caddy' => [
        'api_url' => \MuseDockPanel\Env::get('CADDY_API_URL', 'http://localhost:2019'),
    ],

    // PHP-FPM
    'fpm' => [
        'socket_dir' => \MuseDockPanel\Env::get('FPM_SOCKET_DIR', '/run/php'),
        'php_version' => $panelCfgPhpVersion,
    ],

    // Security
    'allowed_ips' => array_filter(
        array_map('trim', explode(',', \MuseDockPanel\Env::get('ALLOWED_IPS', '')))
    ),
];
