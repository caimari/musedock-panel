<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Env;
use MuseDockPanel\Services\LicenseService;
use MuseDockPanel\View;

/**
 * Stub controller for when the portal module is not installed.
 * Shows "Portal no activado" page or redirects to portal if active.
 */
class PortalStubController
{
    public function index(): void
    {
        $portalInstalled = file_exists('/opt/musedock-portal/bootstrap.php');
        $portalLicensed = $portalInstalled && LicenseService::hasFeature(LicenseService::FEATURE_PORTAL);

        if ($portalLicensed) {
            $portalPort = Env::get('PORTAL_PORT', '8446');
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $hostWithoutPort = preg_replace('/:\d+$/', '', $host);
            header("Location: https://{$hostWithoutPort}:{$portalPort}/");
            exit;
        }

        include PANEL_ROOT . '/resources/views/portal-stub.php';
    }
}
