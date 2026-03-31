<?php
/**
 * MuseDock Portal — Customer Self-Service Entry Point
 *
 * This stub checks if the portal module is installed and licensed.
 * If yes, redirects to the portal. If not, shows the "not activated" page.
 */

define('PANEL_ROOT', dirname(__DIR__));

// Minimal autoloader (same as panel)
spl_autoload_register(function (string $class): void {
    $prefix = 'MuseDockPanel\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = PANEL_ROOT . '/app/' . $relative . '.php';
    if (file_exists($file)) require $file;
});

// Load env and config
require_once PANEL_ROOT . '/app/Env.php';
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');
$config = require PANEL_ROOT . '/config/panel.php';

define('PANEL_VERSION', $config['version'] ?? '');

// Check if portal module is installed and licensed
$portalInstalled = file_exists('/opt/musedock-portal/bootstrap.php');
$portalLicensed  = $portalInstalled && \MuseDockPanel\Services\LicenseService::hasFeature(
    \MuseDockPanel\Services\LicenseService::FEATURE_PORTAL
);

if ($portalLicensed) {
    // Portal is installed and licensed — the portal has its own PHP server on its own port.
    // If we got here, the user is hitting the panel's portal.php directly.
    // Redirect to the portal's actual URL.
    $portalPort = \MuseDockPanel\Env::get('PORTAL_PORT', '8446');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostWithoutPort = preg_replace('/:\d+$/', '', $host);
    header("Location: https://{$hostWithoutPort}:{$portalPort}/");
    exit;
}

// Portal not available — show stub
http_response_code(200);
include PANEL_ROOT . '/resources/views/portal-stub.php';
