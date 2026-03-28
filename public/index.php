<?php
/**
 * MuseDock Panel - Entry Point
 * Independent hosting panel on port 8444
 */

define('PANEL_ROOT', dirname(__DIR__));
define('PANEL_VERSION', '1.0.23');



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
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
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
\MuseDockPanel\View::share('currentUser', \MuseDockPanel\Auth::user());

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

// ===============================
// Routes
// ===============================

// Auth
\MuseDockPanel\Router::get('/login', 'AuthController@loginForm');
\MuseDockPanel\Router::post('/login/submit', 'AuthController@login');
\MuseDockPanel\Router::get('/logout', 'AuthController@logout');

// Dashboard
\MuseDockPanel\Router::get('/', 'DashboardController@index');
\MuseDockPanel\Router::get('/dashboard/processes', 'DashboardController@processes');
\MuseDockPanel\Router::get('/dashboard/process-detail', 'DashboardController@processDetail');
\MuseDockPanel\Router::post('/dashboard/process-kill', 'DashboardController@processKill');

// Monitoring
\MuseDockPanel\Router::get('/monitor', 'MonitorController@index');
\MuseDockPanel\Router::get('/monitor/api/metrics', 'MonitorController@apiMetrics');
\MuseDockPanel\Router::get('/monitor/api/status', 'MonitorController@apiStatus');
\MuseDockPanel\Router::get('/monitor/api/alerts', 'MonitorController@apiAlerts');
\MuseDockPanel\Router::post('/monitor/api/alerts/ack', 'MonitorController@apiAckAlert');
\MuseDockPanel\Router::post('/monitor/api/alerts/clear', 'MonitorController@apiClearAlerts');
\MuseDockPanel\Router::post('/monitor/settings', 'MonitorController@saveSettings');

// Hosting Accounts
\MuseDockPanel\Router::get('/accounts', 'AccountController@index');
\MuseDockPanel\Router::get('/accounts/create', 'AccountController@create');
\MuseDockPanel\Router::post('/accounts/store', 'AccountController@store');
\MuseDockPanel\Router::get('/accounts/import', 'AccountController@importList');
\MuseDockPanel\Router::post('/accounts/import', 'AccountController@importStore');
\MuseDockPanel\Router::get('/accounts/{id}', 'AccountController@show');
\MuseDockPanel\Router::get('/accounts/{id}/edit', 'AccountController@edit');
\MuseDockPanel\Router::post('/accounts/{id}/update', 'AccountController@update');
\MuseDockPanel\Router::post('/accounts/{id}/delete', 'AccountController@delete');
\MuseDockPanel\Router::post('/accounts/{id}/suspend', 'AccountController@suspend');
\MuseDockPanel\Router::post('/accounts/{id}/activate', 'AccountController@activate');
\MuseDockPanel\Router::post('/accounts/{id}/change-password', 'AccountController@changePassword');
\MuseDockPanel\Router::post('/accounts/{id}/renew-ssl', 'AccountController@renewSsl');
\MuseDockPanel\Router::post('/accounts/{id}/rename-user', 'AccountController@renameUser');
\MuseDockPanel\Router::post('/accounts/{id}/php', 'AccountController@updatePhp');

// Domain Aliases & Redirects
\MuseDockPanel\Router::post('/accounts/{id}/aliases/add', 'AccountController@addAlias');
\MuseDockPanel\Router::post('/accounts/{id}/aliases/{alias_id}/delete', 'AccountController@removeAlias');
\MuseDockPanel\Router::post('/accounts/{id}/redirects/add', 'AccountController@addRedirect');
\MuseDockPanel\Router::post('/accounts/{id}/redirects/{alias_id}/delete', 'AccountController@removeAlias');

// Subdomains
\MuseDockPanel\Router::post('/accounts/{id}/subdomains/add', 'AccountController@addSubdomain');
\MuseDockPanel\Router::post('/accounts/{id}/subdomains/adopt', 'AccountController@adoptSubdomain');
\MuseDockPanel\Router::post('/accounts/{id}/subdomains/{sub_id}/delete', 'AccountController@removeSubdomain');
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

// Domains
\MuseDockPanel\Router::get('/domains', 'DomainController@index');
\MuseDockPanel\Router::post('/domains/check-dns', 'DomainController@checkDns');

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
\MuseDockPanel\Router::get('/settings/security', 'SettingsController@security');
\MuseDockPanel\Router::post('/settings/security/save', 'SettingsController@securitySave');
\MuseDockPanel\Router::get('/settings/ssl', 'SettingsController@ssl');
\MuseDockPanel\Router::get('/settings/fail2ban', 'SettingsController@fail2ban');
\MuseDockPanel\Router::post('/settings/fail2ban/unban', 'SettingsController@fail2banUnban');
\MuseDockPanel\Router::get('/settings/logs', 'SettingsController@logs');
\MuseDockPanel\Router::post('/settings/logs/clear', 'SettingsController@logClear');

// System Health
\MuseDockPanel\Router::get('/settings/health', 'SettingsController@health');
\MuseDockPanel\Router::post('/settings/health/repair-cron', 'SettingsController@healthRepairCron');
\MuseDockPanel\Router::post('/settings/health/fix-timezone', 'SettingsController@healthFixTimezone');

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
