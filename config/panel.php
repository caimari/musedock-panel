<?php
/**
 * MuseDock Panel - Configuration
 * Values are loaded from .env file. Fallbacks provided for backwards compatibility.
 */

// Load .env if not already loaded
\MuseDockPanel\Env::load(dirname(__DIR__) . '/.env');

$phpVersion = \MuseDockPanel\Env::get('FPM_PHP_VERSION', '8.3');
$panelRoot = dirname(__DIR__);

return [
    'name' => \MuseDockPanel\Env::get('PANEL_NAME', 'MuseDock Panel'),
    'version' => '1.0.42',
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
        'path' => "{$panelRoot}/storage/sessions",
    ],

    // Paths
    'paths' => [
        'vhosts' => \MuseDockPanel\Env::get('VHOSTS_DIR', '/var/www/vhosts'),
        'fpm_pools' => "/etc/php/{$phpVersion}/fpm/pool.d",
        'logs' => "{$panelRoot}/storage/logs",
    ],

    // Caddy
    'caddy' => [
        'api_url' => \MuseDockPanel\Env::get('CADDY_API_URL', 'http://localhost:2019'),
    ],

    // PHP-FPM
    'fpm' => [
        'socket_dir' => \MuseDockPanel\Env::get('FPM_SOCKET_DIR', '/run/php'),
        'php_version' => $phpVersion,
    ],

    // Security
    'allowed_ips' => array_filter(
        array_map('trim', explode(',', \MuseDockPanel\Env::get('ALLOWED_IPS', '')))
    ),
];
