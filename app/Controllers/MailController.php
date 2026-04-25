<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Settings;
use MuseDockPanel\View;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\MailService;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\WebmailService;

class MailController
{
    private static function isSlave(): bool
    {
        $role = Settings::get('cluster_role', '');
        if ($role === '' || $role === 'standalone') $role = \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
        return $role === 'slave';
    }

    private function relayLogPerPageFromRequest(): int
    {
        $value = (int)($_POST['relay_log_per_page'] ?? $_GET['relay_log_per_page'] ?? 25);
        return in_array($value, [25, 100, 200, 500, 1000], true) ? $value : 25;
    }

    private function relayLogPageFromRequest(): int
    {
        return max(1, (int)($_POST['relay_log_page'] ?? $_GET['relay_log_page'] ?? 1));
    }

    private function redirectQueueWithRelayLogState(?int $forcedPage = null): void
    {
        $query = [
            'tab' => 'queue',
            'relay_log_page' => $forcedPage !== null ? max(1, $forcedPage) : $this->relayLogPageFromRequest(),
            'relay_log_per_page' => $this->relayLogPerPageFromRequest(),
        ];
        Router::redirect('/mail?' . http_build_query($query));
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Dashboard / Overview ────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public function index(): void
    {
        $clusterRole = Settings::get('cluster_role', '');
        if ($clusterRole === '') $clusterRole = \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');

        $stats = MailService::getStats();
        $domains = MailService::getDomains();
        $mailNodes = MailService::getMailNodes();
        $mailHealthByNode = MailService::getLatestMailHealthByNode();
        $mailHealthAlerts = MailService::getMailHealthAlerts();
        $mailMode = MailService::getCurrentMailMode();
        $smtpConfig = MailService::getSmtpConfig(false);
        $deliverabilityRows = MailService::getDeliverabilityRows();
        $internalSmtpToken = MailService::getInternalSmtpToken(true);
        $relayDomains = MailService::getRelayDomains();
        $relayUsers = MailService::getRelayUsers();
        $relayLogPerPage = (int)($_GET['relay_log_per_page'] ?? 25);
        $relayLogPerPageAllowed = [25, 100, 200, 500, 1000];
        if (!in_array($relayLogPerPage, $relayLogPerPageAllowed, true)) {
            $relayLogPerPage = 25;
        }
        $relayLogPage = ($mailMode === 'relay')
            ? MailService::getRelayLogPage((int)($_GET['relay_log_page'] ?? 1), $relayLogPerPage)
            : ['entries' => [], 'total' => 0, 'page' => 1, 'per_page' => $relayLogPerPage, 'pages' => 1];
        $relayLogs = $relayLogPage['entries'];
        $relayQueue = ($mailMode === 'relay') ? MailService::getMailQueueEntries(200) : [];
        $mailMigrations = MailService::getMailMigrations(8);
        $webmailConfig = WebmailService::config();
        $webmailProviders = WebmailService::providers();
        $webmailInstallStatus = WebmailService::installStatus();
        $relayNewCredentials = $_SESSION['relay_new_credentials'] ?? null;
        unset($_SESSION['relay_new_credentials']);

        // Slaves cannot setup mail — only show read-only data
        $showSetup = false;
        $clusterNodes = [];
        if ($clusterRole !== 'slave') {
            $showSetup = ($_GET['setup'] ?? '') === '1';
            $clusterNodes = ClusterService::getNodes();
        }

        $mailLocalConfigured = Settings::get('mail_local_configured', '') === '1';
        $mailLocalHostname   = Settings::get('mail_local_hostname', '');
        $localMailRepairStatus = $clusterRole !== 'slave' ? $this->getLocalMailRepairStatus() : [];
        $mailSetupPrefill = $clusterRole !== 'slave' ? $this->buildMailSetupPrefill($clusterNodes) : [];

        View::render('mail/index', [
            'layout'              => 'main',
            'pageTitle'           => 'Mail',
            'stats'               => $stats,
            'domains'             => $domains,
            'mailNodes'           => $mailNodes,
            'mailHealthByNode'    => $mailHealthByNode,
            'mailHealthAlerts'    => $mailHealthAlerts,
            'showSetup'           => $showSetup,
            'clusterNodes'        => $clusterNodes,
            'mailLocalConfigured' => $mailLocalConfigured,
            'mailLocalHostname'   => $mailLocalHostname,
            'localMailRepairStatus' => $localMailRepairStatus,
            'clusterRole'         => $clusterRole,
            'mailMode'            => $mailMode,
            'smtpConfig'          => $smtpConfig,
            'deliverabilityRows'  => $deliverabilityRows,
            'internalSmtpToken'   => $internalSmtpToken,
            'relayDomains'        => $relayDomains,
            'relayUsers'          => $relayUsers,
            'relayLogs'           => $relayLogs,
            'relayLogPage'        => $relayLogPage,
            'relayQueue'          => $relayQueue,
            'mailMigrations'      => $mailMigrations,
            'webmailConfig'       => $webmailConfig,
            'webmailProviders'    => $webmailProviders,
            'webmailInstallStatus'=> $webmailInstallStatus,
            'relayNewCredentials' => $relayNewCredentials,
            'mailSetupPrefill'    => $mailSetupPrefill,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Mail Domains ────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public function domainCreate(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave. Solo el Master puede crear dominios de mail.');
            Router::redirect('/mail');
            return;
        }

        if (MailService::getCurrentMailMode() !== 'full') {
            Flash::set('warning', 'Los Mail Domains son solo para buzones en modo Correo Completo. En Relay Privado usa Mail > Relay > Dominios autorizados.');
            Router::redirect('/mail?tab=relay');
            return;
        }

        $customers = Database::fetchAll("SELECT id, name, email FROM customers WHERE status = 'active' ORDER BY name");
        $mailNodes = MailService::getMailNodes();
        $mailCreateAvailable = $this->hasMailBackendAvailable();

        View::render('mail/domain-create', [
            'layout'    => 'main',
            'pageTitle' => 'Mail - New Domain',
            'customers' => $customers,
            'mailNodes' => $mailNodes,
            'mailCreateAvailable' => $mailCreateAvailable,
            'mailCreateBlockedReason' => $mailCreateAvailable
                ? ''
                : 'No hay servidor de correo operativo (ni local ni nodo remoto online). Configuralo primero en Mail > Infra.',
        ]);
    }

    public function domainStore(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail');
            return;
        }

        if (MailService::getCurrentMailMode() !== 'full') {
            Flash::set('warning', 'No se crean buzones en este modo. En Relay Privado autoriza el dominio desde Mail > Relay.');
            Router::redirect('/mail?tab=relay');
            return;
        }

        if (!$this->hasMailBackendAvailable()) {
            Flash::set('error', 'No puedes crear dominios de mail: no hay servidor de correo operativo (ni local ni nodo remoto online).');
            Router::redirect('/mail/domains/create');
            return;
        }

        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $mailNodeId = !empty($_POST['mail_node_id']) ? (int)$_POST['mail_node_id'] : null;
        $maxAccounts = (int)($_POST['max_accounts'] ?? 0);

        if (empty($domain)) {
            Flash::set('error', 'El dominio es obligatorio.');
            Router::redirect('/mail/domains/create');
            return;
        }

        // Validate domain format
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,}$/', $domain)) {
            Flash::set('error', 'Formato de dominio no valido.');
            Router::redirect('/mail/domains/create');
            return;
        }

        $existing = MailService::getDomainByName($domain);
        if ($existing) {
            Flash::set('error', "El dominio {$domain} ya existe.");
            Router::redirect('/mail/domains/create');
            return;
        }

        try {
            $id = MailService::createDomain($domain, $customerId, $mailNodeId, [
                'max_accounts' => $maxAccounts,
            ]);
            LogService::log('mail.domain.create', $domain, "Mail domain created" . ($mailNodeId ? " on node #{$mailNodeId}" : ''));
            Flash::set('success', "Dominio de mail {$domain} creado.");
            Router::redirect('/mail/domains/' . $id);
        } catch (\Throwable $e) {
            Flash::set('error', 'Error: ' . $e->getMessage());
            Router::redirect('/mail/domains/create');
        }
    }

    private function hasMailBackendAvailable(): bool
    {
        if (Settings::get('mail_local_configured', '') === '1') {
            return true;
        }

        foreach (MailService::getMailNodes() as $node) {
            if ((string)($node['status'] ?? '') === 'online') {
                return true;
            }
        }

        return false;
    }

    private function buildMailSetupPrefill(array $clusterNodes): array
    {
        $mailMode = MailService::getCurrentMailMode();
        $setupNodeIdRaw = trim((string)Settings::get('mail_setup_node_id', 'local'));
        $setupNodeId = ctype_digit($setupNodeIdRaw) ? (int)$setupNodeIdRaw : 0;
        $setupMode = $setupNodeId > 0 ? 'remote' : 'local';

        $nodeById = [];
        foreach ($clusterNodes as $node) {
            $id = (int)($node['id'] ?? 0);
            if ($id > 0) {
                $nodeById[$id] = $node;
            }
        }
        if ($setupMode === 'remote' && !isset($nodeById[$setupNodeId])) {
            $setupMode = 'local';
            $setupNodeId = 0;
        }

        $targetLabel = 'Este servidor (local)';
        if ($setupMode === 'remote' && isset($nodeById[$setupNodeId])) {
            $targetLabel = (string)($nodeById[$setupNodeId]['name'] ?? ('Nodo #' . $setupNodeId));
        }

        return [
            'setup_mode' => $setupMode,
            'node_id' => $setupNodeId,
            'target_label' => $targetLabel,
            'mail_mode' => $mailMode,
            'mail_hostname' => trim((string)(
                Settings::get('mail_local_hostname', '')
                ?: Settings::get('mail_hostname', '')
                ?: Settings::get('mail_setup_hostname', '')
            )),
            'outbound_domain' => trim((string)Settings::get('mail_outbound_domain', '')),
            'wireguard_ip' => trim((string)Settings::get('mail_relay_wireguard_ip', '')),
            'wireguard_cidr' => trim((string)Settings::get('mail_relay_wireguard_cidr', '10.10.70.0/24')),
            'relay_public_ip' => trim((string)Settings::get('mail_relay_public_ip', '')),
            'ssl_mode' => trim((string)Settings::get('mail_ssl_mode', 'letsencrypt')) ?: 'letsencrypt',
            'smtp_host' => trim((string)Settings::get('mail_smtp_host', '')),
            'smtp_port' => trim((string)Settings::get('mail_smtp_port', '587')) ?: '587',
            'smtp_encryption' => trim((string)Settings::get('mail_smtp_encryption', 'tls')) ?: 'tls',
            'smtp_user' => trim((string)Settings::get('mail_smtp_user', '')),
            'smtp_password' => $this->decryptSetting('mail_smtp_password_enc'),
            'from_address' => trim((string)Settings::get('mail_from_address', '')),
            'from_name' => trim((string)Settings::get('mail_from_name', '')),
            'relay_host' => trim((string)Settings::get('mail_relay_host', '')),
            'relay_port' => trim((string)Settings::get('mail_relay_port', '587')) ?: '587',
            'relay_user' => trim((string)Settings::get('mail_relay_user', '')),
            'relay_password' => $this->decryptSetting('mail_relay_password_enc'),
            'fallback_smtp_host' => trim((string)Settings::get('mail_relay_fallback_host', '')),
            'fallback_smtp_port' => trim((string)Settings::get('mail_relay_fallback_port', '587')) ?: '587',
            'fallback_smtp_user' => trim((string)Settings::get('mail_relay_fallback_user', '')),
            'fallback_smtp_password' => $this->decryptSetting('mail_relay_fallback_password_enc'),
        ];
    }

    private function decryptSetting(string $key): string
    {
        $enc = trim((string)Settings::get($key, ''));
        if ($enc === '') {
            return '';
        }

        try {
            return (string)\MuseDockPanel\Services\ReplicationService::decryptPassword($enc);
        } catch (\Throwable) {
            return '';
        }
    }

    public function domainShow(array $params): void
    {
        $domain = MailService::getDomain((int)$params['id']);
        if (!$domain) {
            Flash::set('error', 'Dominio no encontrado.');
            Router::redirect('/mail');
            return;
        }

        $accounts = MailService::getAccounts((int)$params['id']);
        $aliases = MailService::getAliases((int)$params['id']);
        $dnsRecords = MailService::getDnsRecords((int)$params['id']);

        View::render('mail/domain-show', [
            'layout'     => 'main',
            'pageTitle'  => 'Mail - ' . $domain['domain'],
            'domain'     => $domain,
            'accounts'   => $accounts,
            'aliases'    => $aliases,
            'dnsRecords' => $dnsRecords,
            'readOnly'   => self::isSlave(),
        ]);
    }

    public function domainDelete(array $params): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail');
            return;
        }

        $domain = MailService::getDomain((int)$params['id']);
        if (!$domain) {
            Flash::set('error', 'Dominio no encontrado.');
            Router::redirect('/mail');
            return;
        }

        MailService::deleteDomain((int)$params['id']);
        LogService::log('mail.domain.delete', $domain['domain'], 'Mail domain deleted');
        Flash::set('success', "Dominio {$domain['domain']} eliminado.");
        Router::redirect('/mail');
    }

    public function domainRegenerateDkim(array $params): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave. La gestion de mail se realiza desde el master.');
            Router::redirect('/mail');
            return;
        }

        $domain = MailService::getDomain((int)$params['id']);
        if (!$domain) {
            Flash::set('error', 'Dominio no encontrado.');
            Router::redirect('/mail');
            return;
        }

        $result = MailService::generateDkim((int)$params['id']);
        if ($result['ok']) {
            // Enqueue DKIM key update on mail node or execute locally
            $dkimPayload = [
                'domain'           => $domain['domain'],
                'dkim_selector'    => $result['selector'],
                'dkim_private_key' => MailService::getDkimPrivateKey((int)$params['id']),
            ];
            if ($domain['mail_node_id']) {
                ClusterService::enqueue((int)$domain['mail_node_id'], 'mail_create_domain', $dkimPayload);
            } elseif (Settings::get('mail_local_configured', '') === '1') {
                MailService::nodeCreateDomain($dkimPayload);
            }
            LogService::log('mail.dkim.regenerate', $domain['domain'], 'DKIM key regenerated');
            Flash::set('success', 'Clave DKIM regenerada. Actualiza el registro DNS.');
        } else {
            Flash::set('error', 'Error: ' . ($result['error'] ?? 'Unknown'));
        }
        Router::redirect('/mail/domains/' . $params['id']);
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Mail Accounts (Mailboxes) ──────────────────────────
    // ═══════════════════════════════════════════════════════════

    public function accountCreate(array $params): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail');
            return;
        }

        $domain = MailService::getDomain((int)$params['id']);
        if (!$domain) {
            Flash::set('error', 'Dominio no encontrado.');
            Router::redirect('/mail');
            return;
        }

        $customers = Database::fetchAll("SELECT id, name, email FROM customers WHERE status = 'active' ORDER BY name");
        $hostingAccounts = Database::fetchAll("SELECT id, domain, username FROM hosting_accounts WHERE status = 'active' ORDER BY domain");

        View::render('mail/account-create', [
            'layout'          => 'main',
            'pageTitle'       => 'Mail - New Account',
            'domain'          => $domain,
            'customers'       => $customers,
            'hostingAccounts' => $hostingAccounts,
        ]);
    }

    public function accountStore(array $params): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail');
            return;
        }

        $domainId = (int)$params['id'];
        $localPart = strtolower(trim($_POST['local_part'] ?? ''));
        $password = $_POST['password'] ?? '';
        $displayName = trim($_POST['display_name'] ?? '');
        $quotaMb = (int)($_POST['quota_mb'] ?? Settings::get('mail_default_quota_mb', '1024'));
        $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;

        if (empty($localPart) || empty($password)) {
            Flash::set('error', 'Usuario y contraseña son obligatorios.');
            Router::redirect("/mail/domains/{$domainId}/accounts/create");
            return;
        }

        // Validate local part
        if (!preg_match('/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/', $localPart)) {
            Flash::set('error', 'Formato de usuario no valido. Solo letras, numeros, puntos, guiones.');
            Router::redirect("/mail/domains/{$domainId}/accounts/create");
            return;
        }

        if (strlen($password) < 8) {
            Flash::set('error', 'La contraseña debe tener al menos 8 caracteres.');
            Router::redirect("/mail/domains/{$domainId}/accounts/create");
            return;
        }

        try {
            $id = MailService::createAccount($domainId, $localPart, $password, [
                'display_name' => $displayName,
                'quota_mb'     => $quotaMb,
                'customer_id'  => $customerId,
                'account_id'   => $accountId,
            ]);

            $domain = MailService::getDomain($domainId);
            $email = $localPart . '@' . ($domain['domain'] ?? '');
            LogService::log('mail.account.create', $email, "Mailbox created (quota: {$quotaMb}MB)");
            Flash::set('success', "Buzon {$email} creado.");
            Router::redirect("/mail/domains/{$domainId}");
        } catch (\Throwable $e) {
            Flash::set('error', 'Error: ' . $e->getMessage());
            Router::redirect("/mail/domains/{$domainId}/accounts/create");
        }
    }

    public function accountEdit(array $params): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave. La gestion de mail se realiza desde el master.');
            Router::redirect('/mail');
            return;
        }

        $account = MailService::getAccount((int)$params['account_id']);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/mail');
            return;
        }

        $customers = Database::fetchAll("SELECT id, name, email FROM customers WHERE status = 'active' ORDER BY name");

        View::render('mail/account-edit', [
            'layout'    => 'main',
            'pageTitle' => 'Mail - Edit ' . $account['email'],
            'account'   => $account,
            'customers' => $customers,
        ]);
    }

    public function accountUpdate(array $params): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail');
            return;
        }

        $accountId = (int)$params['account_id'];
        $account = MailService::getAccount($accountId);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/mail');
            return;
        }

        $data = [
            'display_name' => trim($_POST['display_name'] ?? ''),
            'quota_mb'     => (int)($_POST['quota_mb'] ?? $account['quota_mb']),
            'status'       => $_POST['status'] ?? $account['status'],
            'autoresponder_enabled' => !empty($_POST['autoresponder_enabled']),
            'autoresponder_subject' => trim((string)($_POST['autoresponder_subject'] ?? '')),
            'autoresponder_body' => trim((string)($_POST['autoresponder_body'] ?? '')),
        ];

        if (!empty($_POST['password'])) {
            if (strlen($_POST['password']) < 8) {
                Flash::set('error', 'La contraseña debe tener al menos 8 caracteres.');
                Router::redirect("/mail/accounts/{$accountId}/edit");
                return;
            }
            $data['password'] = $_POST['password'];
        }

        MailService::updateAccount($accountId, $data);
        LogService::log('mail.account.update', $account['email'], 'Mailbox updated');
        Flash::set('success', "Buzon {$account['email']} actualizado.");
        Router::redirect("/mail/domains/{$account['mail_domain_id']}");
    }

    public function accountDelete(array $params): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail');
            return;
        }

        $accountId = (int)$params['account_id'];
        $account = MailService::getAccount($accountId);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/mail');
            return;
        }

        $domainId = $account['mail_domain_id'];
        MailService::deleteAccount($accountId);
        LogService::log('mail.account.delete', $account['email'], 'Mailbox deleted');
        Flash::set('success', "Buzon {$account['email']} eliminado.");
        Router::redirect("/mail/domains/{$domainId}");
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Mail Aliases ────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public function aliasStore(array $params): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail');
            return;
        }

        $domainId = (int)$params['id'];
        $source = strtolower(trim($_POST['source'] ?? ''));
        $destination = strtolower(trim($_POST['destination'] ?? ''));
        $isCatchall = isset($_POST['is_catchall']);

        if (empty($source) || empty($destination)) {
            Flash::set('error', 'Origen y destino son obligatorios.');
            Router::redirect("/mail/domains/{$domainId}");
            return;
        }

        try {
            MailService::createAlias($domainId, $source, $destination, $isCatchall);
            LogService::log('mail.alias.create', "{$source} -> {$destination}", 'Mail alias created');
            Flash::set('success', "Alias {$source} -> {$destination} creado.");
        } catch (\Throwable $e) {
            Flash::set('error', 'Error: ' . $e->getMessage());
        }
        Router::redirect("/mail/domains/{$domainId}");
    }

    public function aliasDelete(array $params): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail');
            return;
        }

        $domainId = (int)$params['id'];
        MailService::deleteAlias((int)$params['alias_id']);
        LogService::log('mail.alias.delete', "Alias #{$params['alias_id']}", 'Mail alias deleted');
        Flash::set('success', 'Alias eliminado.');
        Router::redirect("/mail/domains/{$domainId}");
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Mail Node Health ────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public function nodeHealth(): void
    {
        header('Content-Type: application/json');

        $mailNodes = MailService::getMailNodes();
        $results = [];

        foreach ($mailNodes as $node) {
            $results[] = MailService::checkMailNodeHealth((int)$node['id']);
        }

        echo json_encode(['ok' => true, 'nodes' => $results]);
        exit;
    }

    public function repairLocal(): void
    {
        $ajax = $this->isJsonRequest();

        if (self::isSlave()) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Este servidor es Slave. Repara el mail desde el nodo donde esta instalado o desde el master.'], 403);
                return;
            }
            Flash::set('error', 'Este servidor es Slave. Repara el mail desde el nodo donde esta instalado o desde el master.');
            Router::redirect('/mail?tab=infra');
            return;
        }

        if (!View::verifyCsrf()) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Token CSRF invalido. Recarga la pagina e intentalo de nuevo.'], 419);
                return;
            }
            Flash::set('error', 'Token CSRF invalido.');
            Router::redirect('/mail?tab=infra');
            return;
        }

        $adminPassword = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPassword($adminPassword)) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Password de admin incorrecta.'], 403);
                return;
            }
            Flash::set('error', 'Password de admin incorrecta.');
            Router::redirect('/mail?tab=infra');
            return;
        }

        try {
            $result = $this->repairLocalMailInstall();
        } catch (\Throwable $e) {
            $result = [
                'ok' => false,
                'messages' => ['Excepcion interna: ' . $e->getMessage()],
            ];
        }

        if ($ajax) {
            $this->jsonResponse([
                'ok' => (bool)($result['ok'] ?? false),
                'message' => ($result['ok'] ?? false)
                    ? 'Instalacion local de mail reparada.'
                    : 'No se pudo reparar mail local.',
                'messages' => $result['messages'] ?? [],
                'status' => $this->getLocalMailRepairStatus(),
            ], ($result['ok'] ?? false) ? 200 : 500);
            return;
        }

        if ($result['ok']) {
            Flash::set('success', 'Instalacion local de mail reparada: ' . implode('; ', $result['messages']));
        } else {
            Flash::set('error', 'No se pudo reparar mail local: ' . implode('; ', $result['messages']));
        }

        Router::redirect('/mail?tab=infra');
    }

    private function getLocalMailRepairStatus(): array
    {
        $postfixActive = $this->systemctlIsActive('postfix');
        $opendkimActive = $this->systemctlIsActive('opendkim');
        $postfixInstalled = trim((string)shell_exec('command -v postfix 2>/dev/null')) !== ''
            || is_file('/etc/postfix/main.cf');
        $opendkimInstalled = trim((string)shell_exec('command -v opendkim 2>/dev/null')) !== ''
            || is_file('/etc/opendkim.conf');
        $configured = Settings::get('mail_local_configured', '') === '1';
        $enabled = Settings::get('mail_enabled', '') === '1';
        $mailMode = MailService::getCurrentMailMode();
        $relayIp = trim((string)Settings::get('mail_relay_wireguard_ip', ''));
        $relayIpAssigned = ($mailMode === 'relay' && $relayIp !== '') ? $this->localIpv4IsAssigned($relayIp) : null;

        $partial = !$configured && (
            ($postfixInstalled && $opendkimInstalled)
            || $opendkimInstalled
            || $enabled
            || trim((string)Settings::get('mail_setup_hostname', '')) !== ''
            || trim((string)Settings::get('mail_hostname', '')) !== ''
        );

        $needsRepair = $partial
            || ($configured && (!$postfixActive || !$opendkimActive))
            || ($mailMode === 'relay' && $relayIpAssigned === false);

        return [
            'configured' => $configured,
            'partial' => $partial,
            'needs_repair' => $needsRepair,
            'postfix_installed' => $postfixInstalled,
            'opendkim_installed' => $opendkimInstalled,
            'postfix_active' => $postfixActive,
            'opendkim_active' => $opendkimActive,
            'relay_ip' => $relayIp,
            'relay_ip_assigned' => $relayIpAssigned,
            'hostname' => trim((string)(Settings::get('mail_local_hostname', '') ?: Settings::get('mail_hostname', '') ?: Settings::get('mail_setup_hostname', ''))),
        ];
    }

    private function repairLocalMailInstall(): array
    {
        $messages = [];
        $errors = [];
        $run = function (string $label, string $cmd) use (&$messages, &$errors): bool {
            $output = [];
            $code = 0;
            exec($cmd . ' 2>&1', $output, $code);
            $text = trim(implode("\n", $output));
            if ($code === 0) {
                $messages[] = "{$label}: OK";
                return true;
            }
            $errors[] = "{$label}: " . ($text !== '' ? $text : "exit {$code}");
            return false;
        };

        $run('paquetes', 'DEBIAN_FRONTEND=noninteractive apt-get install -y postfix opendkim opendkim-tools sasl2-bin libsasl2-modules ca-certificates openssl');
        $run('stop opendkim', 'systemctl stop opendkim || true');

        @mkdir('/run/opendkim', 0755, true);
        @chown('/run/opendkim', 'opendkim');
        @chgrp('/run/opendkim', 'opendkim');
        @chmod('/run/opendkim', 0755);

        @mkdir('/etc/tmpfiles.d', 0755, true);
        file_put_contents('/etc/tmpfiles.d/opendkim.conf', "d /run/opendkim 0755 opendkim opendkim -\n");

        @mkdir('/etc/systemd/system/opendkim.service.d', 0755, true);
        file_put_contents(
            '/etc/systemd/system/opendkim.service.d/runtime.conf',
            "[Service]\nType=simple\nUser=opendkim\nGroup=opendkim\nRuntimeDirectory=opendkim\nRuntimeDirectoryMode=0755\nPIDFile=\nExecStart=\nExecStart=/usr/sbin/opendkim -f -x /etc/opendkim.conf\n"
        );

        @mkdir('/etc/opendkim/keys', 0700, true);
        foreach (['/etc/opendkim/key.table', '/etc/opendkim/signing.table'] as $file) {
            if (!is_file($file)) {
                @touch($file);
            }
        }
        if (!is_file('/etc/opendkim/trusted.hosts')) {
            $cidr = trim((string)Settings::get('mail_relay_wireguard_cidr', '10.10.70.0/24'));
            file_put_contents('/etc/opendkim/trusted.hosts', "127.0.0.1\n::1\nlocalhost\n{$cidr}\n");
        }

        $conf = is_file('/etc/opendkim.conf') ? (string)file_get_contents('/etc/opendkim.conf') : '';
        if ($conf === '') {
            $conf = "Syslog yes\nUMask 007\nMode s\nCanonicalization relaxed/simple\nKeyTable /etc/opendkim/key.table\nSigningTable refile:/etc/opendkim/signing.table\nExternalIgnoreList /etc/opendkim/trusted.hosts\nInternalHosts /etc/opendkim/trusted.hosts\nSocket local:/run/opendkim/opendkim.sock\nOversignHeaders From\n";
        }
        if (preg_match('/^Socket\s+.*/m', $conf)) {
            $conf = preg_replace('/^Socket\s+.*/m', 'Socket local:/run/opendkim/opendkim.sock', $conf);
        } else {
            $conf .= "\nSocket local:/run/opendkim/opendkim.sock\n";
        }
        $conf = preg_replace('/^UserID\s+.*\n?/m', '', $conf);
        file_put_contents('/etc/opendkim.conf', $conf);
        file_put_contents('/etc/default/opendkim', "SOCKET=\"local:/run/opendkim/opendkim.sock\"\n");

        $run('permisos opendkim', 'chown -R opendkim:opendkim /etc/opendkim /run/opendkim');
        $run('postfix en grupo opendkim', 'usermod -aG opendkim postfix || true');
        if (MailService::getCurrentMailMode() === 'relay') {
            $run('limpiar relayhost antiguo', "postconf -e 'relayhost ='");
            $run('limpiar transport antiguo', "postconf -e 'transport_maps ='");
            $run('limpiar smtp_sasl saliente', "postconf -e 'smtp_sasl_auth_enable = no'");
            $run('limpiar smtp_sasl_password_maps', "postconf -e 'smtp_sasl_password_maps ='");
            $run('limpiar smtp_sasl_security_options', "postconf -e 'smtp_sasl_security_options ='");
            $run('eliminar mapas relay antiguos', 'rm -f /etc/postfix/transport /etc/postfix/transport.db /etc/postfix/sasl_passwords /etc/postfix/sasl_passwords.db /etc/cron.d/musedock-relay-health');
        }
        $run('systemd reload', 'systemctl daemon-reload');
        $run('reset opendkim', 'systemctl reset-failed opendkim || true');
        $run('restart opendkim', 'systemctl restart opendkim');
        $run('restart postfix', 'systemctl restart postfix');

        $postfixActive = $this->systemctlIsActive('postfix');
        $opendkimActive = $this->systemctlIsActive('opendkim');
        if ($postfixActive && $opendkimActive) {
            $hostname = trim((string)(Settings::get('mail_local_hostname', '') ?: Settings::get('mail_hostname', '') ?: Settings::get('mail_setup_hostname', '')));
            if ($hostname === '') {
                $hostname = trim((string)shell_exec('hostname -f 2>/dev/null')) ?: trim((string)shell_exec('hostname 2>/dev/null'));
            }
            Settings::set('mail_local_configured', '1');
            Settings::set('mail_node_configured', '1');
            Settings::set('mail_enabled', '1');
            if ($hostname !== '') {
                Settings::set('mail_local_hostname', $hostname);
                Settings::set('mail_hostname', $hostname);
            }
            LogService::log('mail.repair_local', 'local', 'Mail local repair completed');
            return ['ok' => true, 'messages' => array_merge($messages, ['postfix activo', 'opendkim activo'])];
        }

        if (!$postfixActive) $errors[] = 'postfix no quedo activo';
        if (!$opendkimActive) $errors[] = 'opendkim no quedo activo';
        LogService::log('mail.repair_local_failed', 'local', implode('; ', $errors));
        return ['ok' => false, 'messages' => array_merge($messages, $errors)];
    }

    private function systemctlIsActive(string $service): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_.@-]+$/', $service)) {
            return false;
        }

        exec('systemctl is-active --quiet ' . escapeshellarg($service), $out, $code);
        return $code === 0;
    }

    private function localIpv4IsAssigned(string $ip): bool
    {
        $output = (string)shell_exec('ip -o -4 addr show 2>/dev/null');
        foreach (explode("\n", $output) as $line) {
            if (preg_match_all('/\binet\s+(\d{1,3}(?:\.\d{1,3}){3})\//', $line, $matches)) {
                foreach ($matches[1] as $assignedIp) {
                    if ($assignedIp === $ip) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function isJsonRequest(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function relayDomainStore(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=relay');
            return;
        }

        try {
            $result = MailService::createRelayDomain((string)($_POST['domain'] ?? ''));
        } catch (\Throwable $e) {
            LogService::log('mail.relay_domain.create_failed', (string)($_POST['domain'] ?? ''), $e->getMessage());
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($result['ok'] ?? false) {
            Flash::set('success', 'Dominio relay creado. Revisa los TXT DKIM/SPF/DMARC en Entregabilidad.');
        } else {
            Flash::set('error', $result['error'] ?? 'No se pudo crear el dominio relay.');
        }
        Router::redirect('/mail?tab=relay');
    }

    public function relayDomainRefresh(array $params): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=relay');
            return;
        }

        $result = MailService::refreshRelayDomain((int)$params['id']);
        Flash::set(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false) ? 'DNS del dominio relay actualizado.' : ($result['error'] ?? 'Error verificando DNS.'));
        $tab = (string)($_POST['tab'] ?? 'relay');
        Router::redirect('/mail?tab=' . (in_array($tab, ['relay', 'deliverability'], true) ? $tab : 'relay'));
    }

    public function relayDomainsRefreshAll(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=relay');
            return;
        }

        $result = MailService::refreshAllRelayDomains();
        if ($result['ok'] ?? false) {
            Flash::set(
                'success',
                sprintf(
                    'DNS sincronizado en BD: %d dominio(s) actualizado(s), %d active, %d pending.',
                    (int)($result['updated'] ?? 0),
                    (int)($result['active'] ?? 0),
                    (int)($result['pending'] ?? 0)
                )
            );
        } else {
            $firstError = (string)(($result['errors'][0]['error'] ?? '') ?: 'Error desconocido');
            Flash::set(
                'warning',
                sprintf(
                    'Sincronizacion parcial: %d actualizados, %d errores. Primer error: %s',
                    (int)($result['updated'] ?? 0),
                    count($result['errors'] ?? []),
                    $firstError
                )
            );
        }

        $tab = (string)($_POST['tab'] ?? 'relay');
        $anchor = in_array($tab, ['relay', 'deliverability'], true) ? $tab : 'relay';
        Router::redirect('/mail?tab=' . $anchor);
    }

    public function relayDomainDelete(array $params): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=relay');
            return;
        }

        $result = MailService::deleteRelayDomain((int)$params['id']);
        Flash::set(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false) ? 'Dominio relay eliminado.' : ($result['error'] ?? 'Error eliminando dominio relay.'));
        Router::redirect('/mail?tab=relay');
    }

    public function relayUserStore(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=relay');
            return;
        }

        try {
            $result = MailService::createRelayUser(
                (string)($_POST['username'] ?? ''),
                (string)($_POST['description'] ?? ''),
                (int)($_POST['rate_limit_per_hour'] ?? 200),
                (string)($_POST['allowed_from_domains'] ?? '')
            );
        } catch (\Throwable $e) {
            LogService::log('mail.relay_user.create_failed', (string)($_POST['username'] ?? ''), $e->getMessage());
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($result['ok'] ?? false) {
            $relayHost = \MuseDockPanel\Settings::get('mail_relay_wireguard_ip', '');
            if ($relayHost === '') {
                $relayHost = \MuseDockPanel\Settings::get('mail_relay_host', '');
            }
            $_SESSION['relay_new_credentials'] = [
                'username' => $result['username'],
                'password' => $result['password'],
                'host' => $relayHost,
                'port' => \MuseDockPanel\Settings::get('mail_relay_port', '587'),
            ];
            Flash::set('success', 'Usuario relay creado. Copia la contrasena ahora; no se volvera a mostrar.');
        } else {
            Flash::set('error', $result['error'] ?? 'No se pudo crear el usuario relay.');
        }
        Router::redirect('/mail?tab=relay');
    }

    public function relayUserDelete(array $params): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=relay');
            return;
        }

        $result = MailService::deleteRelayUser((int)$params['id']);
        Flash::set(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false) ? 'Usuario relay eliminado.' : ($result['error'] ?? 'Error eliminando usuario relay.'));
        Router::redirect('/mail?tab=relay');
    }

    public function relayQueueFlush(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            $this->redirectQueueWithRelayLogState();
            return;
        }

        $result = MailService::flushMailQueue();
        Flash::set(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false) ? 'Cola reintentada con postqueue -f.' : ($result['error'] ?? 'No se pudo reintentar la cola.'));
        $this->redirectQueueWithRelayLogState();
    }

    public function relayQueueDelete(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            $this->redirectQueueWithRelayLogState();
            return;
        }

        $scope = (string)($_POST['scope'] ?? 'deferred');
        $scope = $scope === 'all' ? 'all' : 'deferred';
        $result = MailService::deleteMailQueue($scope);
        $label = $scope === 'all' ? 'toda la cola' : 'mensajes deferred';
        Flash::set(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false) ? "Eliminados {$label}." : ($result['error'] ?? 'No se pudo limpiar la cola.'));
        $this->redirectQueueWithRelayLogState();
    }

    public function relayQueueDeleteMessage(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            $this->redirectQueueWithRelayLogState();
            return;
        }

        $queueId = (string)($_POST['queue_id'] ?? '');
        $result = MailService::deleteMailQueueMessage($queueId);
        Flash::set(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false) ? 'Mensaje eliminado de la cola.' : ($result['error'] ?? 'No se pudo eliminar el mensaje.'));
        $this->redirectQueueWithRelayLogState();
    }

    public function relayLogClear(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            $this->redirectQueueWithRelayLogState(1);
            return;
        }

        $result = MailService::clearRelayLog();
        if ($result['ok'] ?? false) {
            Flash::set('success', 'Historico del relay vaciado.');
        } else {
            Flash::set('error', $result['error'] ?? 'No se pudo vaciar el historico del relay.');
        }

        $this->redirectQueueWithRelayLogState(1);
    }

    public function migrationPreflight(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=migration');
            return;
        }

        $result = MailService::createMailMigrationPreflight(
            (string)($_POST['mode'] ?? 'relay'),
            (int)($_POST['target_node_id'] ?? 0),
            (int)($_SESSION['panel_user']['id'] ?? 0) ?: null
        );

        Flash::set(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false)
            ? ('Preflight de migracion creado: ' . ($result['status'] ?? 'ok'))
            : ($result['error'] ?? 'No se pudo ejecutar el preflight.'));
        Router::redirect('/mail?tab=migration');
    }

    public function migrationRelayExecute(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=migration');
            return;
        }

        $adminPassword = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPassword($adminPassword)) {
            Flash::set('error', 'Password de admin incorrecta.');
            Router::redirect('/mail?tab=migration');
            return;
        }

        $result = MailService::migrateRelayToNode(
            (int)($_POST['target_node_id'] ?? 0),
            !empty($_POST['switch_routing']),
            (int)($_SESSION['panel_user']['id'] ?? 0) ?: null
        );

        Flash::set(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false)
            ? 'Migracion de relay completada.'
            : ($result['error'] ?? 'No se pudo migrar el relay.'));
        Router::redirect('/mail?tab=migration');
    }

    public function webmailSave(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=webmail#webmail');
            return;
        }

        $result = WebmailService::saveConfig(
            (string)($_POST['provider'] ?? 'roundcube'),
            (string)($_POST['host'] ?? ''),
            (string)($_POST['imap_host'] ?? ''),
            (string)($_POST['smtp_host'] ?? '')
        );

        Flash::set(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false)
            ? 'Configuracion de webmail guardada.'
            : ($result['error'] ?? 'No se pudo guardar webmail.'));
        Router::redirect('/mail?tab=webmail#webmail');
    }

    public function webmailInstall(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=webmail#webmail');
            return;
        }

        $adminPassword = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPassword($adminPassword)) {
            Flash::set('error', 'Password de admin incorrecta.');
            Router::redirect('/mail?tab=webmail#webmail');
            return;
        }

        $result = WebmailService::startInstall(
            (string)($_POST['provider'] ?? 'roundcube'),
            (string)($_POST['host'] ?? ''),
            (string)($_POST['imap_host'] ?? ''),
            (string)($_POST['smtp_host'] ?? '')
        );

        Flash::set(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false)
            ? 'Instalacion de Roundcube iniciada. Puedes refrescar esta pagina para ver el estado.'
            : ($result['error'] ?? 'No se pudo iniciar la instalacion de webmail.'));
        Router::redirect('/mail?tab=webmail#webmail');
    }

    public function webmailStatus(): void
    {
        header('Content-Type: application/json');
        echo json_encode(WebmailService::installStatus(), JSON_UNESCAPED_SLASHES);
    }

    public function webmailAliasStore(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=webmail#webmail');
            return;
        }
        $result = WebmailService::addAlias((string)($_POST['host'] ?? ''));
        Flash::set(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false)
            ? 'Hostname adicional de webmail publicado.'
            : ($result['error'] ?? 'No se pudo añadir el hostname webmail.'));
        Router::redirect('/mail?tab=webmail#webmail');
    }

    public function webmailAliasDelete(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=webmail#webmail');
            return;
        }
        $result = WebmailService::deleteAlias((string)($_POST['host'] ?? ''));
        Flash::set(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false)
            ? 'Hostname adicional de webmail eliminado.'
            : ($result['error'] ?? 'No se pudo eliminar el hostname webmail.'));
        Router::redirect('/mail?tab=webmail#webmail');
    }

    public function webmailEnableSieve(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=webmail#webmail');
            return;
        }
        $adminPassword = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPassword($adminPassword)) {
            Flash::set('error', 'Password de admin incorrecta.');
            Router::redirect('/mail?tab=webmail#webmail');
            return;
        }

        $messages = [];
        if (Settings::get('mail_local_configured', '') === '1') {
            $result = MailService::nodeEnableSieve([]);
            $messages[] = ($result['ok'] ?? false) ? 'local OK' : ('local ERROR: ' . ($result['error'] ?? 'unknown'));
        }
        foreach (MailService::getMailNodes() as $node) {
            ClusterService::enqueue((int)$node['id'], 'mail_enable_sieve', []);
            $messages[] = 'nodo ' . ($node['name'] ?? ('#' . $node['id'])) . ' encolado';
        }
        if (!$messages) {
            Flash::set('warning', 'No hay servidor de correo completo configurado donde activar Sieve.');
        } else {
            Settings::set('mail_webmail_sieve_enabled', '1');
            Flash::set('success', 'Activacion Sieve/ManageSieve: ' . implode('; ', $messages));
        }
        Router::redirect('/mail?tab=webmail#webmail');
    }

    private function verifyAdminPassword(string $password): bool
    {
        if ($password === '') return false;
        $adminId = (int)($_SESSION['panel_user']['id'] ?? 0);
        if ($adminId <= 0) return false;
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        return $admin && password_verify($password, (string)$admin['password_hash']);
    }

    public function testSend(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail?tab=deliverability');
            return;
        }

        $to = trim((string)($_POST['test_email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Email de destino no valido.');
            Router::redirect('/mail?tab=deliverability');
            return;
        }

        $cfg = MailService::getSmtpConfig(false);
        $from = $cfg['from_address'] ?: ('noreply@' . (gethostname() ?: 'localhost'));
        $marker = 'MuseDock-Test-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $subject = "Test de envio MuseDock {$marker}";
        $body = "Este es un email de prueba de MuseDock Panel.\n\nSi lo ves, el envio funciona.\n\nMarker: {$marker}\n";
        $headers = [
            'From: ' . $from,
            'X-MuseDock-Test: ' . $marker,
        ];

        $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
        usleep(400000);

        $log = '';
        foreach (['/var/log/mail.log', '/var/log/maillog'] as $file) {
            if (is_readable($file)) {
                $cmd = sprintf("grep %s %s 2>/dev/null | tail -5", escapeshellarg($marker), escapeshellarg($file));
                $log = trim((string)shell_exec($cmd));
                if ($log !== '') {
                    break;
                }
            }
        }

        if (!$sent) {
            Flash::set('error', 'PHP no pudo entregar el mensaje a Postfix/SMTP local. Revisa configuracion de mail().');
        } elseif (stripos($log, 'status=bounced') !== false) {
            Flash::set('error', "Test enviado pero rebotado. Log: {$log}");
        } elseif (stripos($log, 'status=deferred') !== false) {
            Flash::set('warning', "Test en cola/deferred. Log: {$log}");
        } elseif (stripos($log, 'status=sent') !== false) {
            Flash::set('success', "Test enviado correctamente a {$to}.");
        } else {
            Flash::set('success', "Test entregado a la cola local para {$to}. Si no llega, revisa /var/log/mail.log. Marker: {$marker}");
        }

        Router::redirect('/mail?tab=deliverability');
    }

    public function internalSmtpConfig(): void
    {
        header('Content-Type: application/json');

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $client = $remote;
        if (in_array($remote, ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidate = trim($parts[0] ?? '');
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $client = $candidate;
            }
        }
        if (!in_array($client, ['127.0.0.1', '::1'], true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'localhost only']);
            return;
        }

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Authorization header missing']);
            return;
        }

        $token = MailService::getInternalSmtpToken(false);
        if ($token === '' || !hash_equals($token, trim($m[1]))) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Invalid token']);
            return;
        }

        echo json_encode(MailService::getSmtpConfig(true), JSON_UNESCAPED_SLASHES);
    }
}
