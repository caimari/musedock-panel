<?php
/**
 * MuseDock Panel - Entry Point
 * Independent hosting panel on port 8444
 */

define('PANEL_ROOT', dirname(__DIR__));



// Autoloader
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

// Config
$config = require PANEL_ROOT . '/config/panel.php';
define('PANEL_VERSION', $config['version']);

// IP allowlist (ALLOWED_IPS in .env)
$requestPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
$allowedIps = array_values($config['allowed_ips'] ?? []);
if (!empty($allowedIps)) {
    $clientIp = (static function (): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        // Only trust X-Forwarded-For when the direct client is local proxy.
        if (in_array($remoteAddr, ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            foreach ($forwarded as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '';
    })();

    $cidrMatch = static function (string $ip, string $rule): bool {
        if (!str_contains($rule, '/')) {
            return false;
        }

        [$subnet, $prefix] = explode('/', $rule, 2);
        if (!is_numeric($prefix)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $prefix = (int)$prefix;
        $maxBits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    };

    $isAllowed = false;
    foreach ($allowedIps as $rule) {
        $rule = trim((string)$rule);
        if ($rule === '') {
            continue;
        }
        if ($clientIp !== '' && ($rule === $clientIp || $cidrMatch($clientIp, $rule))) {
            $isAllowed = true;
            break;
        }
    }

    if (!$isAllowed) {
        http_response_code(403);
        if (str_starts_with($requestPath, '/api/')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Access denied by IP allowlist']);
        } else {
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Access denied by IP allowlist.';
        }
        exit;
    }
}

// Session (hardened)
ini_set('session.save_path', $config['session']['path']);
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name($config['session']['name']);
session_start();

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// HSTS is intentionally disabled by default for the admin panel port (8444),
// to avoid browser lockouts when cert mode changes (ACME/internal fallback).
// Enable only when explicitly configured and only on canonical HTTPS/443.
$isHttpsReq = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$hstsEnabled = \MuseDockPanel\Env::bool('PANEL_HSTS_ENABLED', false);
$reqPort = (int)($_SERVER['HTTP_X_FORWARDED_PORT'] ?? 0);
if ($reqPort <= 0) {
    $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($hostHeader !== '' && str_contains($hostHeader, ':')) {
        $parts = explode(':', $hostHeader);
        $tail = (string)end($parts);
        $candidate = (int)$tail;
        if ($candidate > 0) {
            $reqPort = $candidate;
        }
    }
}
if ($reqPort <= 0) {
    $reqPort = (int)($_SERVER['SERVER_PORT'] ?? ($isHttpsReq ? 443 : 80));
}
if ($hstsEnabled && $isHttpsReq && $reqPort === 443) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Auto-run pending migrations (safe — uses transactions + IF NOT EXISTS)
try {
    $pendingMigrations = \MuseDockPanel\Services\MigrationService::getPending();
    if (!empty($pendingMigrations)) {
        \MuseDockPanel\Services\MigrationService::runPending();
    }
} catch (\Throwable) {
    // Database may not be ready yet (first-run) — skip silently
}

// Share config with views
\MuseDockPanel\View::share('panelVersion', PANEL_VERSION);
\MuseDockPanel\View::share('panelName', $config['name']);
\MuseDockPanel\View::share('panelHostname', gethostname() ?: php_uname('n'));
\MuseDockPanel\View::share('currentUser', \MuseDockPanel\Auth::user());

// ===============================
// Portal module discovery
// ===============================
// If the portal package is installed and licensed, let it register its routes.
// The portal runs on a separate port with its own entry point, but can also
// register routes under /portal/* prefix on the admin panel for cross-linking.
if (file_exists('/opt/musedock-portal/bootstrap.php')
    && \MuseDockPanel\Services\LicenseService::hasFeature(\MuseDockPanel\Services\LicenseService::FEATURE_PORTAL)) {
    require_once '/opt/musedock-portal/bootstrap.php';
}

// ===============================
// First-run setup (no admin exists)
// ===============================
if (\MuseDockPanel\Controllers\SetupController::needsSetup()) {
    \MuseDockPanel\Router::get('/setup', 'SetupController@index');
    \MuseDockPanel\Router::post('/setup/install', 'SetupController@install');
    // Redirect everything else to setup
    $setupUri = strtok($_SERVER['REQUEST_URI'], '?');
    $setupUri = rtrim($setupUri, '/') ?: '/';
    if (!in_array($setupUri, ['/setup', '/setup/install']) && !preg_match('/\.(css|js|png|svg|ico)$/', $setupUri)) {
        header('Location: /setup');
        exit;
    }
    \MuseDockPanel\Router::dispatch();
    return;
}

// Middleware
\MuseDockPanel\Router::middleware('ApiAuthMiddleware');
\MuseDockPanel\Router::middleware('AuthMiddleware');

// ===============================
// API Routes (token auth via ApiAuthMiddleware)
// ===============================
\MuseDockPanel\Router::get('/api/health', 'ClusterApiController@health');
\MuseDockPanel\Router::get('/api/domains', 'ClusterApiController@domains');
\MuseDockPanel\Router::get('/api/cluster/status', 'ClusterApiController@status');
\MuseDockPanel\Router::get('/api/cluster/heartbeat', 'ClusterApiController@heartbeat');
\MuseDockPanel\Router::post('/api/cluster/action', 'ClusterApiController@action');

// Federation API (token auth — called by remote peers)
\MuseDockPanel\Router::get('/api/federation/health', 'FederationApiController@health');
\MuseDockPanel\Router::post('/api/federation/check-space', 'FederationApiController@checkSpace');
\MuseDockPanel\Router::post('/api/federation/check-conflicts', 'FederationApiController@checkConflicts');
\MuseDockPanel\Router::post('/api/federation/prepare', 'FederationApiController@prepare');
\MuseDockPanel\Router::post('/api/federation/finalize', 'FederationApiController@finalize');
\MuseDockPanel\Router::post('/api/federation/verify', 'FederationApiController@verify');
\MuseDockPanel\Router::get('/api/federation/server-info', 'FederationApiController@serverInfo');
\MuseDockPanel\Router::post('/api/federation/install-ssh-key', 'FederationApiController@installSshKey');
\MuseDockPanel\Router::post('/api/federation/rollback', 'FederationApiController@rollback');
\MuseDockPanel\Router::post('/api/federation/complete', 'FederationApiController@complete');
\MuseDockPanel\Router::post('/api/federation/handshake', 'FederationApiController@handshake');
\MuseDockPanel\Router::post('/api/federation/pause-sync', 'FederationApiController@pauseSync');

// Federation Backup API (remote backup storage)
\MuseDockPanel\Router::get('/api/federation/backups/list', 'FederationApiController@backupsList');
\MuseDockPanel\Router::post('/api/federation/backups/receive', 'FederationApiController@backupsReceive');
\MuseDockPanel\Router::get('/api/federation/backups/download', 'FederationApiController@backupsDownload');
\MuseDockPanel\Router::post('/api/federation/backups/receive-upload', 'FederationApiController@backupsReceiveUpload');
\MuseDockPanel\Router::post('/api/federation/backups/delete', 'FederationApiController@backupsDelete');

// ===============================
// Routes
// ===============================

// Portal stub (shows "not activated" or redirects to portal if licensed)
\MuseDockPanel\Router::get('/portal', 'PortalStubController@index');

// Auth
\MuseDockPanel\Router::get('/login', 'AuthController@loginForm');
\MuseDockPanel\Router::post('/login/submit', 'AuthController@login');
\MuseDockPanel\Router::get('/logout', 'AuthController@logout');

// Dashboard
\MuseDockPanel\Router::get('/', 'DashboardController@index');
\MuseDockPanel\Router::get('/dashboard/processes', 'DashboardController@processes');
\MuseDockPanel\Router::get('/dashboard/process-detail', 'DashboardController@processDetail');
\MuseDockPanel\Router::post('/dashboard/process-kill', 'DashboardController@processKill');
\MuseDockPanel\Router::post('/settings/dismiss-alert', 'DashboardController@dismissAlert');

// Monitoring
\MuseDockPanel\Router::get('/monitor', 'MonitorController@index');
\MuseDockPanel\Router::get('/monitor/api/metrics', 'MonitorController@apiMetrics');
\MuseDockPanel\Router::get('/monitor/api/status', 'MonitorController@apiStatus');
\MuseDockPanel\Router::get('/monitor/api/realtime', 'MonitorController@apiRealtime');
\MuseDockPanel\Router::get('/monitor/api/network-detail', 'MonitorController@apiNetworkDetail');
\MuseDockPanel\Router::get('/monitor/api/disk-detail', 'MonitorController@apiDiskDetail');
\MuseDockPanel\Router::get('/monitor/api/bandwidth', 'MonitorController@apiBandwidth');
\MuseDockPanel\Router::get('/monitor/api/alerts', 'MonitorController@apiAlerts');
\MuseDockPanel\Router::post('/monitor/api/alerts/ack', 'MonitorController@apiAckAlert');
\MuseDockPanel\Router::post('/monitor/api/alerts/clear', 'MonitorController@apiClearAlerts');
\MuseDockPanel\Router::post('/monitor/settings', 'MonitorController@saveSettings');

// Hosting Accounts
\MuseDockPanel\Router::get('/accounts', 'AccountController@index');
\MuseDockPanel\Router::get('/accounts/create', 'AccountController@create');
\MuseDockPanel\Router::post('/accounts/store', 'AccountController@store');
\MuseDockPanel\Router::post('/accounts/store-async', 'AccountController@storeAsync');
\MuseDockPanel\Router::get('/accounts/provision-stream', 'AccountController@provisionStream');
\MuseDockPanel\Router::get('/accounts/provision-status', 'AccountController@provisionStatus');
\MuseDockPanel\Router::post('/accounts/bulk-disable-wp-cron', 'AccountController@bulkDisableWpCron');
\MuseDockPanel\Router::get('/accounts/import', 'AccountController@importList');
\MuseDockPanel\Router::post('/accounts/import', 'AccountController@importStore');
\MuseDockPanel\Router::get('/accounts/{id}', 'AccountController@show');
\MuseDockPanel\Router::get('/accounts/{id}/bandwidth', 'AccountController@apiBandwidth');
\MuseDockPanel\Router::get('/accounts/{id}/stats', 'AccountController@stats');
\MuseDockPanel\Router::get('/accounts/{id}/edit', 'AccountController@edit');
\MuseDockPanel\Router::post('/accounts/{id}/update', 'AccountController@update');
\MuseDockPanel\Router::post('/accounts/{id}/delete', 'AccountController@delete');
\MuseDockPanel\Router::post('/accounts/{id}/suspend', 'AccountController@suspend');
\MuseDockPanel\Router::post('/accounts/{id}/activate', 'AccountController@activate');
\MuseDockPanel\Router::post('/accounts/{id}/change-password', 'AccountController@changePassword');
\MuseDockPanel\Router::post('/accounts/{id}/renew-ssl', 'AccountController@renewSsl');
\MuseDockPanel\Router::post('/accounts/{id}/rename-user', 'AccountController@renameUser');
\MuseDockPanel\Router::post('/accounts/{id}/php', 'AccountController@updatePhp');
\MuseDockPanel\Router::post('/accounts/{id}/fpm-pool', 'AccountController@updateFpmPool');
\MuseDockPanel\Router::post('/accounts/{id}/hosting-type', 'AccountController@updateHostingType');

// File Manager
\MuseDockPanel\Router::get('/accounts/{id}/files', 'FileManagerController@index');
\MuseDockPanel\Router::get('/accounts/{id}/files/edit', 'FileManagerController@edit');
\MuseDockPanel\Router::post('/accounts/{id}/files/save', 'FileManagerController@save');
\MuseDockPanel\Router::post('/accounts/{id}/files/mkdir', 'FileManagerController@mkdir');
\MuseDockPanel\Router::post('/accounts/{id}/files/delete', 'FileManagerController@delete');
\MuseDockPanel\Router::post('/accounts/{id}/files/rename', 'FileManagerController@rename');
\MuseDockPanel\Router::post('/accounts/{id}/files/chmod', 'FileManagerController@chmod');
\MuseDockPanel\Router::post('/accounts/{id}/files/upload', 'FileManagerController@upload');
\MuseDockPanel\Router::get('/accounts/{id}/files/download', 'FileManagerController@download');
\MuseDockPanel\Router::post('/accounts/{id}/files/write-mode', 'FileManagerController@activateWriteMode');
\MuseDockPanel\Router::get('/accounts/{id}/audit-log', 'FileManagerController@auditLog');
\MuseDockPanel\Router::get('/accounts/{id}/audit-log/export', 'FileManagerController@auditLogExport');

// Global Audit Log
\MuseDockPanel\Router::get('/admin/file-audit-log', 'FileManagerController@globalAuditLog');

// Domain Aliases & Redirects
\MuseDockPanel\Router::post('/accounts/{id}/aliases/add', 'AccountController@addAlias');
\MuseDockPanel\Router::post('/accounts/{id}/aliases/{alias_id}/delete', 'AccountController@removeAlias');
\MuseDockPanel\Router::post('/accounts/{id}/redirects/add', 'AccountController@addRedirect');
\MuseDockPanel\Router::post('/accounts/{id}/redirects/{alias_id}/delete', 'AccountController@removeAlias');

// Subdomains
\MuseDockPanel\Router::get('/accounts/{id}/subdomains/{sub_id}', 'AccountController@showSubdomain');
\MuseDockPanel\Router::get('/accounts/{id}/subdomains/{sub_id}/edit', 'AccountController@editSubdomain');
\MuseDockPanel\Router::post('/accounts/{id}/subdomains/{sub_id}/update', 'AccountController@updateSubdomain');
\MuseDockPanel\Router::post('/accounts/{id}/subdomains/{sub_id}/php', 'AccountController@updateSubdomainPhp');
\MuseDockPanel\Router::post('/accounts/{id}/subdomains/{sub_id}/hosting-type', 'AccountController@updateSubdomainHostingType');
\MuseDockPanel\Router::post('/accounts/{id}/subdomains/{sub_id}/federation-migrate', 'AccountController@federateSubdomain');
\MuseDockPanel\Router::post('/accounts/{id}/subdomains/add', 'AccountController@addSubdomain');
\MuseDockPanel\Router::post('/accounts/{id}/subdomains/adopt', 'AccountController@adoptSubdomain');
\MuseDockPanel\Router::post('/accounts/{id}/subdomains/{sub_id}/toggle-status', 'AccountController@toggleSubdomainStatus');
\MuseDockPanel\Router::post('/accounts/{id}/subdomains/{sub_id}/delete', 'AccountController@removeSubdomain');
\MuseDockPanel\Router::post('/accounts/{id}/toggle-wp-cron', 'AccountController@toggleWpCron');
\MuseDockPanel\Router::post('/accounts/{id}/subdomains/{sub_id}/promote', 'AccountController@promoteSubdomain');

// Migration
\MuseDockPanel\Router::get('/accounts/{id}/migrate', 'MigrationController@index');
\MuseDockPanel\Router::post('/accounts/{id}/migrate/url', 'MigrationController@fromUrl');
\MuseDockPanel\Router::post('/accounts/{id}/migrate/ssh', 'MigrationController@fromSsh');
\MuseDockPanel\Router::post('/accounts/{id}/migrate/db', 'MigrationController@migrateDb');
\MuseDockPanel\Router::post('/accounts/{id}/migrate/test-ssh', 'MigrationController@testSsh');
\MuseDockPanel\Router::post('/accounts/{id}/migrate/check-local', 'MigrationController@checkLocal');
\MuseDockPanel\Router::post('/accounts/{id}/migrate/ssh-prepare', 'MigrationController@sshPrepare');
\MuseDockPanel\Router::get('/accounts/{id}/migrate/ssh-stream', 'MigrationController@sshStream');
\MuseDockPanel\Router::get('/accounts/{id}/migrate/ssh-status', 'MigrationController@sshStatus');
\MuseDockPanel\Router::post('/accounts/{id}/migrate/ssh-cancel', 'MigrationController@sshCancel');
\MuseDockPanel\Router::post('/accounts/{id}/migrate/ssh-resume', 'MigrationController@sshResume');
\MuseDockPanel\Router::post('/accounts/{id}/migrate/subdomain', 'MigrationController@migrateSubdomain');

// Federation Migration (master-to-master)
\MuseDockPanel\Router::get('/accounts/{id}/federation-migrate', 'FederationController@migrateForm');
\MuseDockPanel\Router::post('/accounts/{id}/federation-migrate/start', 'FederationController@migrateStart');
\MuseDockPanel\Router::post('/accounts/{id}/federation-migrate/execute', 'FederationController@migrateExecute');
\MuseDockPanel\Router::get('/accounts/{id}/federation-migrate/progress', 'FederationController@migrateProgress');
\MuseDockPanel\Router::post('/accounts/{id}/federation-migrate/pause', 'FederationController@migratePause');
\MuseDockPanel\Router::post('/accounts/{id}/federation-migrate/resume', 'FederationController@migrateResume');
\MuseDockPanel\Router::post('/accounts/{id}/federation-migrate/cancel', 'FederationController@migrateCancel');
\MuseDockPanel\Router::get('/accounts/{id}/federation-migrate/logs', 'FederationController@migrateLogs');

// Federation Clone Actions
\MuseDockPanel\Router::post('/accounts/{id}/federation-clone/update', 'FederationController@cloneUpdate');
\MuseDockPanel\Router::post('/accounts/{id}/federation-clone/reclone', 'FederationController@cloneReclone');
\MuseDockPanel\Router::post('/accounts/{id}/federation-clone/promote', 'FederationController@clonePromote');
\MuseDockPanel\Router::get('/accounts/{id}/federation-clone/status', 'FederationController@cloneStatus');

// Domains
\MuseDockPanel\Router::get('/domains', 'DomainController@index');
\MuseDockPanel\Router::post('/domains/check-dns', 'DomainController@checkDns');
\MuseDockPanel\Router::post('/domains/add-redirect', 'DomainController@addRedirect');
\MuseDockPanel\Router::post('/domains/delete-redirect', 'DomainController@deleteRedirect');

// Databases
\MuseDockPanel\Router::get('/databases', 'DatabaseController@index');
\MuseDockPanel\Router::get('/databases/create', 'DatabaseController@create');
\MuseDockPanel\Router::post('/databases/store', 'DatabaseController@store');
\MuseDockPanel\Router::post('/databases/{id}/delete', 'DatabaseController@delete');
\MuseDockPanel\Router::post('/databases/{id}/edit-credentials', 'DatabaseController@editCredentials');
\MuseDockPanel\Router::post('/databases/associate', 'DatabaseController@associate');
\MuseDockPanel\Router::get('/databases/accounts-json', 'DatabaseController@getAccounts');
// Database Backups
\MuseDockPanel\Router::post('/databases/backup', 'DatabaseController@backup');
\MuseDockPanel\Router::post('/databases/backup-all', 'DatabaseController@backupAll');
\MuseDockPanel\Router::post('/databases/backups/bulk-transfer', 'DatabaseController@bulkTransferBackups');
\MuseDockPanel\Router::post('/databases/backups/bulk-delete', 'DatabaseController@bulkDeleteBackups');
\MuseDockPanel\Router::post('/databases/backups/cleanup', 'DatabaseController@cleanupBackups');
\MuseDockPanel\Router::get('/databases/backups/{id}/download', 'DatabaseController@downloadBackup');
\MuseDockPanel\Router::post('/databases/backups/{id}/restore', 'DatabaseController@restoreBackup');
\MuseDockPanel\Router::post('/databases/backups/{id}/delete', 'DatabaseController@deleteBackup');
\MuseDockPanel\Router::post('/databases/backups/{id}/transfer', 'DatabaseController@transferBackup');
\MuseDockPanel\Router::post('/databases/backup-settings', 'DatabaseController@saveBackupSettings');

// Customers
\MuseDockPanel\Router::get('/customers', 'CustomerController@index');
\MuseDockPanel\Router::get('/customers/create', 'CustomerController@create');
\MuseDockPanel\Router::post('/customers/store', 'CustomerController@store');
\MuseDockPanel\Router::get('/customers/{id}', 'CustomerController@show');
\MuseDockPanel\Router::get('/customers/{id}/edit', 'CustomerController@edit');
\MuseDockPanel\Router::post('/customers/{id}/update', 'CustomerController@update');
\MuseDockPanel\Router::post('/customers/{id}/delete', 'CustomerController@delete');

// Mail
\MuseDockPanel\Router::get('/mail', 'MailController@index');
\MuseDockPanel\Router::get('/mail/domains/create', 'MailController@domainCreate');
\MuseDockPanel\Router::post('/mail/domains/store', 'MailController@domainStore');
\MuseDockPanel\Router::get('/mail/domains/{id}', 'MailController@domainShow');
\MuseDockPanel\Router::post('/mail/domains/{id}/delete', 'MailController@domainDelete');
\MuseDockPanel\Router::post('/mail/domains/{id}/regenerate-dkim', 'MailController@domainRegenerateDkim');
\MuseDockPanel\Router::get('/mail/domains/{id}/accounts/create', 'MailController@accountCreate');
\MuseDockPanel\Router::post('/mail/domains/{id}/accounts/store', 'MailController@accountStore');
\MuseDockPanel\Router::get('/mail/accounts/{account_id}/edit', 'MailController@accountEdit');
\MuseDockPanel\Router::post('/mail/accounts/{account_id}/update', 'MailController@accountUpdate');
\MuseDockPanel\Router::post('/mail/accounts/{account_id}/delete', 'MailController@accountDelete');
\MuseDockPanel\Router::post('/mail/domains/{id}/aliases/store', 'MailController@aliasStore');
\MuseDockPanel\Router::post('/mail/domains/{id}/aliases/{alias_id}/delete', 'MailController@aliasDelete');
\MuseDockPanel\Router::get('/mail/nodes/health', 'MailController@nodeHealth');

// Settings
\MuseDockPanel\Router::get('/settings/services', 'SettingsController@services');
\MuseDockPanel\Router::post('/settings/services/action', 'SettingsController@serviceAction');
\MuseDockPanel\Router::get('/settings/crons', 'SettingsController@crons');
\MuseDockPanel\Router::post('/settings/crons/save', 'SettingsController@cronSave');
\MuseDockPanel\Router::post('/settings/crons/update', 'SettingsController@cronUpdate');
\MuseDockPanel\Router::post('/settings/crons/delete', 'SettingsController@cronDelete');
\MuseDockPanel\Router::get('/settings/caddy', 'SettingsController@caddy');
\MuseDockPanel\Router::post('/settings/caddy/delete-route', 'SettingsController@caddyDeleteRoute');
\MuseDockPanel\Router::get('/settings/server', 'SettingsController@server');
\MuseDockPanel\Router::post('/settings/server/save', 'SettingsController@serverSave');
\MuseDockPanel\Router::get('/settings/php', 'SettingsController@php');
\MuseDockPanel\Router::post('/settings/php/ini-save', 'SettingsController@phpIniSave');
\MuseDockPanel\Router::post('/settings/php/opcache-save', 'SettingsController@phpOpcacheSave');
\MuseDockPanel\Router::get('/settings/security', 'SettingsController@security');
\MuseDockPanel\Router::post('/settings/security/save', 'SettingsController@securitySave');
\MuseDockPanel\Router::post('/settings/security/pg-ssl-enable', 'SettingsController@pgSslEnable');
\MuseDockPanel\Router::post('/settings/security/pg-ssl-disable', 'SettingsController@pgSslDisable');
\MuseDockPanel\Router::get('/settings/ssl', 'SettingsController@ssl');
\MuseDockPanel\Router::get('/settings/fail2ban', 'SettingsController@fail2ban');
\MuseDockPanel\Router::post('/settings/fail2ban/unban', 'SettingsController@fail2banUnban');
\MuseDockPanel\Router::post('/settings/fail2ban/ban', 'SettingsController@fail2banBan');
\MuseDockPanel\Router::post('/settings/fail2ban/whitelist', 'SettingsController@fail2banWhitelist');
\MuseDockPanel\Router::post('/settings/fail2ban/toggle-jail', 'SettingsController@fail2banToggleJail');
\MuseDockPanel\Router::post('/settings/fail2ban/install', 'SettingsController@fail2banInstall');
\MuseDockPanel\Router::post('/settings/fail2ban/setup-jails', 'SettingsController@fail2banSetupJails');
\MuseDockPanel\Router::get('/settings/logs', 'SettingsController@logs');
\MuseDockPanel\Router::post('/settings/logs/clear', 'SettingsController@logClear');

// System Health
\MuseDockPanel\Router::get('/settings/health', 'SettingsController@health');
\MuseDockPanel\Router::post('/settings/health/repair-cron', 'SettingsController@healthRepairCron');
\MuseDockPanel\Router::post('/settings/health/fix-timezone', 'SettingsController@healthFixTimezone');
\MuseDockPanel\Router::post('/settings/health/repair-db', 'SettingsController@healthRepairDb');
\MuseDockPanel\Router::post('/settings/health/install-package', 'SettingsController@healthInstallPackage');

// Portal Settings
\MuseDockPanel\Router::get('/settings/portal', 'PortalSettingsController@index');
\MuseDockPanel\Router::post('/settings/portal/save', 'PortalSettingsController@save');
\MuseDockPanel\Router::post('/settings/portal/send-invitation', 'PortalSettingsController@sendInvitation');
\MuseDockPanel\Router::post('/settings/portal/revoke-access', 'PortalSettingsController@revokeAccess');
\MuseDockPanel\Router::post('/settings/portal/activate', 'PortalSettingsController@activate');
\MuseDockPanel\Router::get('/settings/portal/install-status', 'PortalSettingsController@installStatus');

// Updates
\MuseDockPanel\Router::get('/settings/updates', 'UpdateController@index');
\MuseDockPanel\Router::post('/settings/updates/check', 'UpdateController@check');
\MuseDockPanel\Router::post('/settings/updates/run', 'UpdateController@run');
\MuseDockPanel\Router::get('/settings/updates/api/status', 'UpdateController@apiStatus');

// Notifications
\MuseDockPanel\Router::get('/settings/notifications', 'NotificationController@index');
\MuseDockPanel\Router::post('/settings/notifications/save', 'NotificationController@save');
\MuseDockPanel\Router::post('/settings/notifications/test-email', 'NotificationController@testEmail');
\MuseDockPanel\Router::post('/settings/notifications/test-telegram', 'NotificationController@testTelegram');

// Replication
\MuseDockPanel\Router::get('/settings/replication', 'ReplicationController@index');
\MuseDockPanel\Router::get('/settings/replication/status', 'ReplicationController@status');
\MuseDockPanel\Router::post('/settings/replication/test-connection', 'ReplicationController@testConnection');
// Mode 1: Master (activate + users + IPs)
\MuseDockPanel\Router::post('/settings/replication/activate-master', 'ReplicationController@activateMaster');
\MuseDockPanel\Router::post('/settings/replication/reset-standalone', 'ReplicationController@resetStandalone');
\MuseDockPanel\Router::post('/settings/replication/repl-user/create', 'ReplicationController@createReplUser');
\MuseDockPanel\Router::post('/settings/replication/repl-user/delete', 'ReplicationController@deleteReplUser');
\MuseDockPanel\Router::get('/settings/replication/repl-users', 'ReplicationController@listReplUsers');
\MuseDockPanel\Router::post('/settings/replication/authorized-ip/add', 'ReplicationController@addAuthorizedIp');
\MuseDockPanel\Router::post('/settings/replication/authorized-ip/remove', 'ReplicationController@removeAuthorizedIp');
\MuseDockPanel\Router::get('/settings/replication/authorized-ips', 'ReplicationController@listAuthorizedIps');
// Mode 2: Slave (manual)
\MuseDockPanel\Router::post('/settings/replication/convert-to-slave', 'ReplicationController@convertToSlave');
\MuseDockPanel\Router::post('/settings/replication/test-slave-master', 'ReplicationController@testSlaveMaster');
// Mode 3: Auto (cluster)
\MuseDockPanel\Router::post('/settings/replication/auto-configure', 'ReplicationController@autoConfigure');
// Switchover
\MuseDockPanel\Router::post('/settings/replication/promote', 'ReplicationController@promote');
\MuseDockPanel\Router::post('/settings/replication/demote', 'ReplicationController@demote');

// Cluster
\MuseDockPanel\Router::get('/settings/cluster', 'ClusterController@index');
\MuseDockPanel\Router::post('/settings/cluster/add-node', 'ClusterController@addNode');
\MuseDockPanel\Router::post('/settings/cluster/update-node', 'ClusterController@updateNode');
\MuseDockPanel\Router::post('/settings/cluster/remove-node/{id}', 'ClusterController@removeNode');
\MuseDockPanel\Router::post('/settings/cluster/test-node', 'ClusterController@testNode');
\MuseDockPanel\Router::get('/settings/cluster/node-status', 'ClusterController@nodeStatus');
\MuseDockPanel\Router::get('/settings/cluster/node-status-quick', 'ClusterController@nodeStatusQuick');
\MuseDockPanel\Router::get('/settings/cluster/ping-node', 'ClusterController@pingNode');
\MuseDockPanel\Router::post('/settings/cluster/process-queue', 'ClusterController@processQueue');
\MuseDockPanel\Router::post('/settings/cluster/promote', 'ClusterController@promoteLocal');
\MuseDockPanel\Router::post('/settings/cluster/demote', 'ClusterController@demoteLocal');
\MuseDockPanel\Router::post('/settings/cluster/generate-token', 'ClusterController@generateToken');
\MuseDockPanel\Router::post('/settings/cluster/save-settings', 'ClusterController@saveSettings');
\MuseDockPanel\Router::post('/settings/cluster/save-setting', 'ClusterController@saveSetting');
\MuseDockPanel\Router::post('/settings/cluster/verify-admin-password', 'ClusterController@verifyAdminPassword');
\MuseDockPanel\Router::post('/settings/cluster/clean-queue', 'ClusterController@cleanQueue');
\MuseDockPanel\Router::post('/settings/cluster/retry-queue', 'ClusterController@retryQueue');
\MuseDockPanel\Router::post('/settings/cluster/sync-all-hostings', 'ClusterController@syncAllHostings');
\MuseDockPanel\Router::post('/settings/cluster/filesync-settings', 'ClusterController@saveFileSyncSettings');
\MuseDockPanel\Router::post('/settings/cluster/generate-ssh-key', 'ClusterController@generateSshKey');
\MuseDockPanel\Router::post('/settings/cluster/install-ssh-key', 'ClusterController@installSshKey');
\MuseDockPanel\Router::post('/settings/cluster/test-ssh', 'ClusterController@testSshConnection');
\MuseDockPanel\Router::post('/settings/cluster/sync-files-now', 'ClusterController@syncFilesNow');
\MuseDockPanel\Router::get('/settings/cluster/sync-progress', 'ClusterController@syncProgress');
\MuseDockPanel\Router::post('/settings/cluster/check-dbhost', 'ClusterController@checkDbHost');
\MuseDockPanel\Router::post('/settings/cluster/full-sync', 'ClusterController@fullSync');
\MuseDockPanel\Router::post('/settings/cluster/lsyncd-install', 'ClusterController@lsyncdInstall');
\MuseDockPanel\Router::post('/settings/cluster/lsyncd-start', 'ClusterController@lsyncdStart');
\MuseDockPanel\Router::post('/settings/cluster/lsyncd-stop', 'ClusterController@lsyncdStop');
\MuseDockPanel\Router::post('/settings/cluster/lsyncd-reload', 'ClusterController@lsyncdReload');
\MuseDockPanel\Router::get('/settings/cluster/lsyncd-status', 'ClusterController@lsyncdStatus');
\MuseDockPanel\Router::post('/settings/cluster/node-standby', 'ClusterController@toggleNodeStandby');
\MuseDockPanel\Router::get('/settings/cluster/browse-vhosts', 'ClusterController@browseVhosts');
\MuseDockPanel\Router::post('/settings/cluster/save-exclusions', 'ClusterController@saveExclusions');
\MuseDockPanel\Router::post('/settings/cluster/mute-node-alerts', 'ClusterController@muteNodeAlerts');
\MuseDockPanel\Router::post('/settings/cluster/unmute-node-alerts', 'ClusterController@unmuteNodeAlerts');
\MuseDockPanel\Router::post('/settings/cluster/setup-mail-node', 'ClusterController@setupMailNode');
\MuseDockPanel\Router::post('/settings/cluster/setup-mail-local', 'ClusterController@setupMailLocal');
\MuseDockPanel\Router::get('/settings/cluster/mail-setup-progress', 'ClusterController@mailSetupProgress');
\MuseDockPanel\Router::get('/settings/cluster/mail-setup-progress-local', 'ClusterController@mailSetupProgressLocal');
\MuseDockPanel\Router::post('/settings/cluster/rotate-mail-db-password', 'ClusterController@rotateMailDbPassword');
\MuseDockPanel\Router::post('/settings/cluster/toggle-node-service', 'ClusterController@toggleNodeService');

// Federation
\MuseDockPanel\Router::get('/settings/federation', 'FederationController@index');
\MuseDockPanel\Router::post('/settings/federation/add-peer', 'FederationController@addPeer');
\MuseDockPanel\Router::post('/settings/federation/update-peer', 'FederationController@updatePeer');
\MuseDockPanel\Router::post('/settings/federation/remove-peer/{id}', 'FederationController@removePeer');
\MuseDockPanel\Router::post('/settings/federation/approve-peer/{id}', 'FederationController@approvePeer');
\MuseDockPanel\Router::post('/settings/federation/test-peer', 'FederationController@testPeer');
\MuseDockPanel\Router::post('/settings/federation/exchange-keys', 'FederationController@exchangeKeys');
\MuseDockPanel\Router::post('/settings/federation/generate-pairing-code', 'FederationController@generatePairingCode');
\MuseDockPanel\Router::post('/settings/federation/connect-with-code', 'FederationController@connectWithCode');

// Failover
\MuseDockPanel\Router::post('/settings/failover/save-config', 'FailoverController@saveConfig');
\MuseDockPanel\Router::post('/settings/failover/save-servers', 'FailoverController@saveServers');
\MuseDockPanel\Router::post('/settings/failover/save-cf-accounts', 'FailoverController@saveCfAccounts');
\MuseDockPanel\Router::post('/settings/failover/verify-cf-token', 'FailoverController@verifyCfToken');
\MuseDockPanel\Router::get('/settings/failover/check-health', 'FailoverController@checkHealth');
\MuseDockPanel\Router::post('/settings/failover/execute', 'FailoverController@execute');
\MuseDockPanel\Router::get('/settings/failover/caddy-l4-preview', 'FailoverController@caddyL4Preview');
\MuseDockPanel\Router::get('/settings/failover/status', 'FailoverController@status');
\MuseDockPanel\Router::get('/settings/failover/domains-not-cf', 'FailoverController@domainsNotCf');
\MuseDockPanel\Router::post('/settings/failover/install-caddy-l4', 'FailoverController@installCaddyL4');
\MuseDockPanel\Router::get('/settings/failover/caddy-l4-status', 'FailoverController@caddyL4Status');
\MuseDockPanel\Router::get('/settings/failover/test-ifaces', 'FailoverController@testIfaces');
\MuseDockPanel\Router::post('/settings/failover/test-remote-sources', 'FailoverController@testRemoteSources');

// Cloudflare DNS Manager
\MuseDockPanel\Router::get('/settings/cloudflare-dns', 'CloudflareDnsController@index');
\MuseDockPanel\Router::get('/settings/cloudflare-dns/zones', 'CloudflareDnsController@listZones');
\MuseDockPanel\Router::get('/settings/cloudflare-dns/records', 'CloudflareDnsController@listRecords');
\MuseDockPanel\Router::post('/settings/cloudflare-dns/create-record', 'CloudflareDnsController@createRecord');
\MuseDockPanel\Router::post('/settings/cloudflare-dns/update-record', 'CloudflareDnsController@updateRecord');
\MuseDockPanel\Router::post('/settings/cloudflare-dns/delete-record', 'CloudflareDnsController@deleteRecord');
\MuseDockPanel\Router::post('/settings/cloudflare-dns/toggle-proxy', 'CloudflareDnsController@toggleProxy');
\MuseDockPanel\Router::post('/settings/cloudflare-dns/bulk-action', 'CloudflareDnsController@bulkAction');

// Proxy Routes (permanent SNI proxy via caddy-l4)
\MuseDockPanel\Router::get('/settings/proxy-routes', 'ProxyRouteController@index');
\MuseDockPanel\Router::post('/settings/proxy-routes/save', 'ProxyRouteController@save');
\MuseDockPanel\Router::post('/settings/proxy-routes/delete', 'ProxyRouteController@delete');
\MuseDockPanel\Router::post('/settings/proxy-routes/toggle', 'ProxyRouteController@toggle');
\MuseDockPanel\Router::post('/settings/proxy-routes/test', 'ProxyRouteController@test');
\MuseDockPanel\Router::get('/settings/proxy-routes/preview', 'ProxyRouteController@preview');

// WireGuard
\MuseDockPanel\Router::get('/settings/wireguard', 'WireGuardController@index');
\MuseDockPanel\Router::post('/settings/wireguard/install', 'WireGuardController@install');
\MuseDockPanel\Router::post('/settings/wireguard/start', 'WireGuardController@start');
\MuseDockPanel\Router::post('/settings/wireguard/restart', 'WireGuardController@restart');
\MuseDockPanel\Router::post('/settings/wireguard/add-peer', 'WireGuardController@addPeer');
\MuseDockPanel\Router::post('/settings/wireguard/remove-peer', 'WireGuardController@removePeer');
\MuseDockPanel\Router::post('/settings/wireguard/update-peer', 'WireGuardController@updatePeer');
\MuseDockPanel\Router::post('/settings/wireguard/generate-keys', 'WireGuardController@generateKeys');
\MuseDockPanel\Router::post('/settings/wireguard/generate-config', 'WireGuardController@generateConfig');
\MuseDockPanel\Router::post('/settings/wireguard/ping', 'WireGuardController@pingPeer');
\MuseDockPanel\Router::get('/settings/wireguard/status', 'WireGuardController@status');

// Firewall
\MuseDockPanel\Router::get('/settings/firewall', 'FirewallController@index');
\MuseDockPanel\Router::post('/settings/firewall/add-rule', 'FirewallController@addRule');
\MuseDockPanel\Router::post('/settings/firewall/delete-rule', 'FirewallController@deleteRule');
\MuseDockPanel\Router::post('/settings/firewall/edit-rule', 'FirewallController@editRule');
\MuseDockPanel\Router::post('/settings/firewall/enable', 'FirewallController@enableFirewall');
\MuseDockPanel\Router::post('/settings/firewall/disable', 'FirewallController@disableFirewall');
\MuseDockPanel\Router::post('/settings/firewall/emergency', 'FirewallController@emergencyAllow');
\MuseDockPanel\Router::post('/settings/firewall/save', 'FirewallController@saveRules');
\MuseDockPanel\Router::get('/settings/firewall/suggest', 'FirewallController@suggestRules');

// Backups
\MuseDockPanel\Router::get('/backups', 'BackupController@index');
\MuseDockPanel\Router::get('/backups/create', 'BackupController@create');
\MuseDockPanel\Router::post('/backups/store', 'BackupController@store');
\MuseDockPanel\Router::get('/backups/status', 'BackupController@status');
\MuseDockPanel\Router::post('/backups/status/clear', 'BackupController@statusClear');
\MuseDockPanel\Router::get('/backups/download', 'BackupController@download');
\MuseDockPanel\Router::get('/backups/{id}/restore', 'BackupController@restore');
\MuseDockPanel\Router::post('/backups/{id}/restore', 'BackupController@restoreExecute');
\MuseDockPanel\Router::post('/backups/{id}/delete', 'BackupController@delete');
\MuseDockPanel\Router::post('/backups/{id}/notes', 'BackupController@updateNotes');
\MuseDockPanel\Router::post('/backups/auto-backup-settings', 'BackupController@saveAutoBackupSettings');
// Remote Backups (static routes before {id} wildcard)
\MuseDockPanel\Router::get('/backups/transfer/status', 'BackupController@transferStatus');
\MuseDockPanel\Router::post('/backups/transfer/clear', 'BackupController@transferClear');
\MuseDockPanel\Router::get('/backups/remote', 'BackupController@listRemoteBackups');
\MuseDockPanel\Router::post('/backups/remote/fetch', 'BackupController@fetchFromNode');
\MuseDockPanel\Router::post('/backups/remote/delete', 'BackupController@deleteRemoteBackup');
\MuseDockPanel\Router::post('/backups/{id}/transfer', 'BackupController@transferToNode');

// Changelog
\MuseDockPanel\Router::get('/changelog', 'ChangelogController@index');

// Profile
\MuseDockPanel\Router::get('/profile', 'ProfileController@index');
\MuseDockPanel\Router::post('/profile/username', 'ProfileController@updateUsername');
\MuseDockPanel\Router::post('/profile/email', 'ProfileController@updateEmail');
\MuseDockPanel\Router::post('/profile/password', 'ProfileController@updatePassword');

// System Users
\MuseDockPanel\Router::get('/system-users', 'SystemUserController@index');
\MuseDockPanel\Router::get('/system-users/:uid', 'SystemUserController@show');

// Activity Log
\MuseDockPanel\Router::get('/logs', 'LogController@index');
\MuseDockPanel\Router::post('/logs/clear', 'LogController@clear');
\MuseDockPanel\Router::post('/logs/clear-all', 'LogController@clearAll');

// Dispatch
\MuseDockPanel\Router::dispatch();
