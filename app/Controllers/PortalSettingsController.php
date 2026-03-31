<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Settings;
use MuseDockPanel\View;
use MuseDockPanel\Services\LicenseService;
use MuseDockPanel\Services\LogService;

class PortalSettingsController
{
    public function index(): void
    {
        $portalInstalled = file_exists('/opt/musedock-portal/bootstrap.php');
        $portalVersion = '';
        if ($portalInstalled && file_exists('/opt/musedock-portal/bootstrap.php')) {
            // Read version from bootstrap
            $content = file_get_contents('/opt/musedock-portal/bootstrap.php');
            if (preg_match("/PORTAL_VERSION.*?'([^']+)'/", $content, $m)) {
                $portalVersion = $m[1];
            }
        }

        $licenseStatus = LicenseService::getPortalStatus();
        $portalTheme = Settings::get('portal_theme', 'light');
        $portalPort = Settings::get('portal_port', '8446');
        $sidebarColor = Settings::get('portal_sidebar_color', '#4f46e5');

        // Available themes
        $themes = $this->getAvailableThemes();

        // Customers with portal access (have password_hash set)
        $customers = Database::fetchAll(
            "SELECT c.id, c.name, c.email, c.status, c.company,
                    (c.password_hash IS NOT NULL AND c.password_hash != '') as has_portal_access,
                    COUNT(h.id) as account_count
             FROM customers c
             LEFT JOIN hosting_accounts h ON h.customer_id = c.id
             GROUP BY c.id
             ORDER BY c.name ASC"
        );

        // Portal service status
        $portalServiceActive = false;
        if ($portalInstalled) {
            $status = trim(shell_exec('systemctl is-active musedock-portal 2>/dev/null') ?? '');
            $portalServiceActive = ($status === 'active');
        }

        View::render('settings/portal', [
            'layout' => 'main',
            'pageTitle' => 'Portal Clientes',
            'portalInstalled' => $portalInstalled,
            'portalVersion' => $portalVersion,
            'licenseStatus' => $licenseStatus,
            'portalTheme' => $portalTheme,
            'portalPort' => $portalPort,
            'themes' => $themes,
            'customers' => $customers,
            'portalServiceActive' => $portalServiceActive,
            'sidebarColor' => $sidebarColor,
        ]);
    }

    /**
     * POST: Save portal settings (theme, port, etc.)
     */
    public function save(): void
    {
        $theme = trim($_POST['portal_theme'] ?? 'light');
        $sidebarColor = trim($_POST['portal_sidebar_color'] ?? '#4f46e5');

        // Validate color format
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $sidebarColor)) {
            $sidebarColor = '#4f46e5';
        }

        Settings::set('portal_theme', $theme);
        Settings::set('portal_sidebar_color', $sidebarColor);

        LogService::log('settings.portal', null, "Portal theme: {$theme}, sidebar: {$sidebarColor}");
        Flash::set('success', 'Configuracion del portal guardada.');
        Router::redirect('/settings/portal');
    }

    /**
     * POST: Set/reset a customer's portal password
     */
    /**
     * POST: Send invitation / password reset link to customer.
     * Generates a secure token, stores it, and emails the link.
     * The customer creates their own password — admin never knows it.
     */
    public function sendInvitation(): void
    {
        $customerId = (int)($_POST['customer_id'] ?? 0);

        $customer = Database::fetchOne("SELECT * FROM customers WHERE id = :id", ['id' => $customerId]);
        if (!$customer) {
            Flash::set('error', 'Cliente no encontrado.');
            Router::redirect('/settings/portal?tab=access');
            return;
        }

        if (empty($customer['email'])) {
            Flash::set('error', 'El cliente no tiene email configurado.');
            Router::redirect('/settings/portal?tab=access');
            return;
        }

        // Generate secure token (64 chars hex = 256 bits)
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));

        Database::query(
            "UPDATE customers SET password_token = :t, password_token_expires = :e, updated_at = NOW() WHERE id = :id",
            ['t' => hash('sha256', $token), 'e' => $expires, 'id' => $customerId]
        );

        // Build the setup URL
        $portalPort = Settings::get('portal_port', '8446');
        $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $setupUrl = "https://{$host}:{$portalPort}/setup-password?token={$token}&email=" . urlencode($customer['email']);

        // Send email
        $isNew = empty($customer['password_hash']);
        $subject = $isNew ? 'Invitacion al Portal de Clientes' : 'Restablecer contraseña del Portal';
        $body = "Hola {$customer['name']},\n\n";
        if ($isNew) {
            $body .= "Se te ha dado acceso al portal de clientes.\n\n";
            $body .= "Haz clic en el siguiente enlace para crear tu contraseña:\n";
        } else {
            $body .= "Se ha solicitado un cambio de contraseña para tu cuenta del portal.\n\n";
            $body .= "Haz clic en el siguiente enlace para crear una nueva contraseña:\n";
        }
        $body .= "{$setupUrl}\n\n";
        $body .= "Este enlace caduca en 48 horas.\n\n";
        $body .= "Si no solicitaste esto, ignora este mensaje.\n\n";
        $body .= "— MuseDock Panel";

        $headers = "From: noreply@{$host}\r\nContent-Type: text/plain; charset=UTF-8";
        $sent = @mail($customer['email'], $subject, $body, $headers);

        LogService::log('portal.invitation', $customer['email'],
            ($isNew ? 'Invitation' : 'Password reset') . " sent to: {$customer['name']}");

        if ($sent) {
            Flash::set('success', ($isNew ? 'Invitacion' : 'Link de reset') . " enviado a {$customer['email']}.");
        } else {
            Flash::set('error', "No se pudo enviar el email. Link de setup: <br><code style='font-size:0.7rem;word-break:break-all;'>{$setupUrl}</code>");
        }
        Router::redirect('/settings/portal?tab=access');
    }

    /**
     * POST: Revoke portal access for a customer
     */
    public function revokeAccess(): void
    {
        // Verify admin password
        $adminPassword = $_POST['admin_password'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? $_SESSION['panel_user']['id'] ?? 0;
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        if (!$admin || !password_verify($adminPassword, $admin['password_hash'])) {
            Flash::set('error', 'Contraseña de administrador incorrecta.');
            Router::redirect('/settings/portal?tab=access');
            return;
        }

        $customerId = (int)($_POST['customer_id'] ?? 0);
        $customer = Database::fetchOne("SELECT * FROM customers WHERE id = :id", ['id' => $customerId]);
        if (!$customer) {
            Flash::set('error', 'Cliente no encontrado.');
            Router::redirect('/settings/portal?tab=access');
            return;
        }

        Database::query("UPDATE customers SET password_hash = NULL, updated_at = NOW() WHERE id = :id", ['id' => $customerId]);
        LogService::log('portal.customer_revoke', $customer['email'], "Portal access revoked for: {$customer['name']}");
        Flash::set('success', "Acceso al portal revocado para {$customer['name']}.");
        Router::redirect('/settings/portal?tab=access');
    }


    private function getAvailableThemes(): array
    {
        $themes = [
            'light' => ['name' => 'Light', 'description' => 'Fondo claro, sidebar con color', 'preview' => 'bi-sun'],
            'default' => ['name' => 'Dark', 'description' => 'Fondo oscuro, sidebar con color', 'preview' => 'bi-moon-stars'],
        ];

        // Scan for additional themes in the portal
        $themesDir = '/opt/musedock-portal/resources/views/layouts';
        if (is_dir($themesDir)) {
            $dirs = glob($themesDir . '/*/portal.php');
            foreach ($dirs as $dir) {
                $themeName = basename(dirname($dir));
                if ($themeName !== 'default' && !isset($themes[$themeName])) {
                    $themes[$themeName] = [
                        'name' => ucfirst($themeName),
                        'description' => 'Custom theme',
                        'preview' => 'bi-palette',
                    ];
                }
            }
        }

        return $themes;
    }
}
