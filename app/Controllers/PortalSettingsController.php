<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Settings;
use MuseDockPanel\View;
use MuseDockPanel\Services\LicenseService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\PortalService;

class PortalSettingsController
{
    public function index(): void
    {
        $portalInstalled = file_exists('/opt/musedock-portal/bootstrap.php');
        $portalVersion = '';
        if ($portalInstalled && file_exists('/opt/musedock-portal/bootstrap.php')) {
            // Read version from bootstrap
            $content = file_get_contents('/opt/musedock-portal/bootstrap.php');
            if (preg_match("/PORTAL_VERSION['\"],\s*['\"]([^'\"]+)/", $content, $m)) {
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
                    (c.password_hash LIKE '!%') as portal_blocked,
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
            'portalState' => $portalInstalled ? PortalService::status() : [],
        ]);
    }

    /**
     * POST: Nombre público y puerto del portal. Se aplica al momento en este nodo
     * (servicio + ruta de Caddy si manda; apagado si es una copia) y las copias lo
     * reciben del principal en su siguiente pasada (cluster-worker, 5 min).
     */
    public function saveAddress(): void
    {
        $host = PortalService::normalizeHostname((string)($_POST['portal_hostname'] ?? ''));
        $port = (int)($_POST['portal_port'] ?? 443);
        if ($host !== '' && !PortalService::validHostname($host)) {
            Flash::set('error', 'El nombre público no es válido (ejemplo: portal.tudominio.com).');
            Router::redirect('/settings/portal');
            return;
        }
        $panelPort = (int)(Settings::get('panel_port', '8444') ?: 8444);
        // 443 vale (recomendado: sin puerto en la dirección, ya abierto en el principal y
        // compatible con el proxy de Cloudflare); la ruta va en el servidor de las webs.
        if ($port < 1 || $port > 65534 || in_array($port, [80, $panelPort, $panelPort + 1], true)) {
            Flash::set('error', 'Puerto no válido para el portal.');
            Router::redirect('/settings/portal');
            return;
        }
        if (Settings::get('cluster_role', '') === 'slave') {
            Flash::set('error', 'Este servidor es una copia: cambia la dirección del portal en el principal (se copia aquí sola).');
            Router::redirect('/settings/portal');
            return;
        }

        $oldHost = PortalService::hostname();
        Settings::set('portal_hostname', $host);
        Settings::set('portal_port', (string)$port);
        if ($oldHost !== $host || $host === '') {
            PortalService::removeRoute();
        }
        $done = PortalService::apply();
        LogService::log('settings.portal', $host ?: '(sin nombre)', "Dirección del portal: {$host}:{$port}" . ($done ? ' — ' . implode('; ', $done) : ''));

        $failed = array_filter($done, static fn($l) => str_contains($l, 'NO '));
        if ($failed) {
            Flash::set('error', 'Guardado, pero: ' . implode(' · ', $failed));
        } else {
            Flash::set('success', $host !== ''
                ? 'Dirección guardada. El portal queda en ' . PortalService::url() . ' (el nombre debe apuntar a este servidor).'
                : 'Dirección quitada: el portal no se publica con nombre propio.');
        }
        Router::redirect('/settings/portal');
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
        // "Mantener la sesión iniciada" del portal: días (0 = sin caducidad), igual que el panel.
        $rememberDays = (int)($_POST['portal_session_remember_days'] ?? 30);
        Settings::set('portal_session_remember_days', (string)(in_array($rememberDays, [0, 1, 7, 30, 60, 90, 180, 365], true) ? $rememberDays : 30));

        LogService::log('settings.portal', null, "Portal theme: {$theme}, sidebar: {$sidebarColor}, recordar sesión: {$rememberDays} días");
        Flash::set('success', 'Configuracion del portal guardada.');
        Router::redirect('/settings/portal');
    }

    /**
     * POST: favicon del portal (subir uno propio o volver al de por defecto). Se valida el
     * contenido real (SVG/PNG/ICO, 64 KB) y se guarda como ajuste: llega solo a las réplicas.
     */
    public function saveFavicon(): void
    {
        $back = '/settings/portal?tab=appearance';
        if (Settings::get('cluster_role', '') === 'slave') {
            Flash::set('error', 'Este servidor es una copia: cambia el favicon en el principal (se copia aquí solo).');
            Router::redirect($back);
            return;
        }
        if (!empty($_POST['reset'])) {
            PortalService::saveFavicon('', '');
            LogService::log('settings.portal', null, 'Favicon del portal: el de por defecto');
            Flash::set('success', 'El portal vuelve a usar el favicon por defecto.');
            Router::redirect($back);
            return;
        }
        $f = $_FILES['favicon'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            Flash::set('error', 'No se ha recibido ningún fichero.');
            Router::redirect($back);
            return;
        }
        if ((int)$f['size'] > PortalService::FAVICON_MAX_BYTES) {
            Flash::set('error', 'El fichero es demasiado grande (máximo 64 KB).');
            Router::redirect($back);
            return;
        }
        $bytes = (string)file_get_contents((string)$f['tmp_name']);
        [$mime, $err] = PortalService::validateFavicon($bytes);
        if ($mime === null) {
            Flash::set('error', $err);
            Router::redirect($back);
            return;
        }
        PortalService::saveFavicon($bytes, $mime);
        LogService::log('settings.portal', null, "Favicon del portal: {$mime}, " . strlen($bytes) . ' bytes');
        Flash::set('success', 'Favicon guardado. Puede tardar un poco en verse por la caché del navegador.');
        Router::redirect($back);
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
        // En una copia no: los clientes los manda el master (lo de aquí se pisaría en la
        // siguiente copia) y el portal lo sirve el master (un enlace creado aquí no valdría).
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es una copia: el acceso de los clientes al portal se gestiona en el servidor que manda.');
            Router::redirect(parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?: '/customers');
            return;
        }
        $customerId = (int)($_POST['customer_id'] ?? 0);

        $customer = Database::fetchOne("SELECT * FROM customers WHERE id = :id", ['id' => $customerId]);
        if (!$customer) {
            Flash::set('error', 'Cliente no encontrado.');
            Router::redirect('/settings/portal?tab=access');
            return;
        }

        if (str_starts_with((string)($customer['password_hash'] ?? ''), '!')) {
            Flash::set('error', 'El acceso al portal de este cliente está bloqueado: permítelo antes desde su ficha.');
            Router::redirect('/customers/' . (int)$customer['id']);
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
        // Con el nombre propio del portal (sigue valiendo tras un relevo); si no hay, el del panel.
        $portalPort = Settings::get('portal_port', '8446');
        $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = PortalService::url() ?: "https://{$host}:{$portalPort}";
        $setupUrl = "{$base}/setup-password?token={$token}&email=" . urlencode($customer['email']);

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

        // Por el SMTP de avisos del panel (p. ej. Sweego, con el secundario de reserva) y con su
        // remitente: antes salía con mail() por el Postfix local y desde noreply@<servidor>, sin
        // SPF/DKIM ni DNS inverso, y Gmail lo descartaba sin dejarlo ni en spam.
        $sent = \MuseDockPanel\Services\NotificationService::sendToAddress($customer['email'], $subject, $body, 'Portal de clientes');

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
        // En una copia no: los clientes los manda el master (lo de aquí se pisaría en la
        // siguiente copia) y el portal lo sirve el master (un enlace creado aquí no valdría).
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es una copia: el acceso de los clientes al portal se gestiona en el servidor que manda.');
            Router::redirect(parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?: '/customers');
            return;
        }
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


    /** POST: renovar la licencia del portal ahora, o activar/reactivar una clave en este servidor (sin reinstalar). */
    public function license(): void
    {
        $key = trim((string)($_POST['license_key'] ?? ''));
        $r = ($_POST['op'] ?? '') === 'renew' ? LicenseService::refreshPortalLicense() : LicenseService::activatePortalKey($key);
        LogService::log('portal.license', null, (($_POST['op'] ?? '') === 'renew' ? 'Renovar' : "Activar {$key}") . ': ' . $r['message']);
        Flash::set($r['ok'] ? 'success' : 'error', $r['message']);
        Router::redirect('/settings/portal');
    }

    /**
     * POST: Activate Portal with a license key.
     * Calls license.musedock.com API, downloads and installs the Portal.
     * Returns JSON for the AJAX progress UI.
     */
    public function activate(): void
    {
        header('Content-Type: application/json');

        $licenseKey = trim($_POST['license_key'] ?? '');
        if (!preg_match('/^MDCK-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $licenseKey)) {
            echo json_encode(['ok' => false, 'error' => 'Formato de clave invalido. Esperado: MDCK-XXXX-XXXX-XXXX']);
            return;
        }

        $logFile = '/tmp/portal-install-' . time() . '.log';
        $panelDir = PANEL_ROOT;

        // Run portal-install.sh in background, capture output
        $cmd = "sudo bash {$panelDir}/bin/portal-install.sh " . escapeshellarg($licenseKey) . " > " . escapeshellarg($logFile) . " 2>&1 &";
        exec($cmd);

        // Store install state
        Settings::set('portal_install_status', 'running');
        Settings::set('portal_install_log', $logFile);
        Settings::set('portal_install_key', $licenseKey);
        Settings::set('portal_install_started', (string)time());

        LogService::log('portal.activate', null, "Portal activation started with key {$licenseKey}");

        echo json_encode([
            'ok' => true,
            'message' => 'Instalacion iniciada...',
            'log_file' => $logFile,
        ]);
    }

    /**
     * GET: Check portal installation progress (AJAX polling).
     * Returns JSON with current status and log output.
     */
    public function installStatus(): void
    {
        header('Content-Type: application/json');

        $status = Settings::get('portal_install_status', 'idle');
        $logFile = Settings::get('portal_install_log', '');
        $started = (int)Settings::get('portal_install_started', '0');

        $log = '';
        if ($logFile && file_exists($logFile)) {
            $log = file_get_contents($logFile);
        }

        // Detect completion
        $portalInstalled = file_exists('/opt/musedock-portal/bootstrap.php');
        $portalService = trim(shell_exec('systemctl is-active musedock-portal 2>/dev/null') ?? '') === 'active';

        if ($status === 'running') {
            // Check if process finished (log contains "installed successfully" or error)
            if (str_contains($log, 'installed successfully') || str_contains($log, 'Portal installed')) {
                Settings::set('portal_install_status', 'done');
                $status = 'done';
            } elseif (str_contains($log, 'ERROR') || str_contains($log, 'FAIL') || str_contains($log, 'fail()')) {
                Settings::set('portal_install_status', 'error');
                $status = 'error';
            } elseif (time() - $started > 300) {
                // Timeout after 5 minutes
                Settings::set('portal_install_status', 'timeout');
                $status = 'timeout';
            }
        }

        echo json_encode([
            'status' => $status,
            'log' => $log,
            'portal_installed' => $portalInstalled,
            'portal_service' => $portalService,
            'elapsed' => $started > 0 ? time() - $started : 0,
        ]);
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
