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

class MailController
{
    private static function isSlave(): bool
    {
        $role = Settings::get('cluster_role', '');
        if ($role === '' || $role === 'standalone') $role = \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
        return $role === 'slave';
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

        // Slaves cannot setup mail — only show read-only data
        $showSetup = false;
        $clusterNodes = [];
        if ($clusterRole !== 'slave') {
            $showSetup = ($_GET['setup'] ?? '') === '1';
            if ($showSetup || empty($mailNodes)) {
                $clusterNodes = ClusterService::getNodes();
            }
        }

        $mailLocalConfigured = Settings::get('mail_local_configured', '') === '1';
        $mailLocalHostname   = Settings::get('mail_local_hostname', '');

        View::render('mail/index', [
            'layout'              => 'main',
            'pageTitle'           => 'Mail',
            'stats'               => $stats,
            'domains'             => $domains,
            'mailNodes'           => $mailNodes,
            'showSetup'           => $showSetup,
            'clusterNodes'        => $clusterNodes,
            'mailLocalConfigured' => $mailLocalConfigured,
            'mailLocalHostname'   => $mailLocalHostname,
            'clusterRole'         => $clusterRole,
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

        $customers = Database::fetchAll("SELECT id, name, email FROM customers WHERE status = 'active' ORDER BY name");
        $mailNodes = MailService::getMailNodes();

        View::render('mail/domain-create', [
            'layout'    => 'main',
            'pageTitle' => 'Mail - New Domain',
            'customers' => $customers,
            'mailNodes' => $mailNodes,
        ]);
    }

    public function domainStore(): void
    {
        if (self::isSlave()) {
            Flash::set('error', 'Este servidor es Slave.');
            Router::redirect('/mail');
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
}
