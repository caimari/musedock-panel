<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\LogService;

class DomainController
{
    public function index(): void
    {
        $domains = Database::fetchAll(
            "SELECT d.*, h.domain as account_domain, h.username, h.status as account_status,
                    c.name as customer_name
             FROM hosting_domains d
             JOIN hosting_accounts h ON h.id = d.account_id
             LEFT JOIN customers c ON c.id = h.customer_id
             ORDER BY d.domain ASC"
        );

        // Also get primary domains from accounts that might not be in hosting_domains
        $accountDomains = Database::fetchAll(
            "SELECT h.id, h.domain, h.username, h.status, h.caddy_route_id,
                    c.name as customer_name, h.created_at
             FROM hosting_accounts h
             LEFT JOIN customers c ON c.id = h.customer_id
             ORDER BY h.domain ASC"
        );

        // Subdomains grouped by account_id
        $subdomainsAll = Database::fetchAll(
            "SELECT s.*, h.domain AS account_domain
             FROM hosting_subdomains s
             JOIN hosting_accounts h ON h.id = s.account_id
             ORDER BY s.subdomain ASC"
        );
        $subdomainsByAccount = [];
        foreach ($subdomainsAll as $sub) {
            $subdomainsByAccount[(int)$sub['account_id']][] = $sub;
        }

        // Domain aliases & redirects (including standalone redirects)
        $aliasesAndRedirects = Database::fetchAll(
            "SELECT da.*, h.domain AS account_domain, h.username, h.status AS account_status,
                    COALESCE(c2.name, c.name) AS customer_name
             FROM hosting_domain_aliases da
             LEFT JOIN hosting_accounts h ON h.id = da.hosting_account_id
             LEFT JOIN customers c ON c.id = h.customer_id
             LEFT JOIN customers c2 ON c2.id = da.customer_id
             ORDER BY da.type, da.domain ASC"
        );

        // Customers list for the add redirect form
        $customers = Database::fetchAll("SELECT id, name, email FROM customers ORDER BY name ASC");

        // Dominios con correo en este panel (correo y hosting se unen por el nombre).
        $mailDomains = [];
        try {
            foreach (Database::fetchAll("SELECT id, lower(domain) AS d, status FROM mail_domains") as $m) {
                $mailDomains[$m['d']] = ['id' => (int)$m['id'], 'status' => $m['status']];
            }
        } catch (\Throwable) {
        }

        View::render('domains/index', [
            'layout' => 'main',
            'pageTitle' => 'Domains',
            'domains' => $domains,
            'accountDomains' => $accountDomains,
            'aliasesAndRedirects' => $aliasesAndRedirects,
            'subdomainsByAccount' => $subdomainsByAccount,
            'customers' => $customers,
            'dnsCache' => self::dnsCache(),
            'mailDomains' => $mailDomains,
        ]);
    }

    /**
     * POST: Create a standalone redirect (domain → target URL, no hosting account needed)
     */
    public function addRedirect(): void
    {
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $targetUrl = trim($_POST['target_url'] ?? '');
        $code = (int)($_POST['redirect_code'] ?? 301);
        $preservePath = !empty($_POST['preserve_path']);
        $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;

        if (empty($domain) || empty($targetUrl)) {
            Flash::set('error', 'Dominio y URL destino son obligatorios.');
            Router::redirect('/domains');
            return;
        }

        if (!in_array($code, [301, 302])) $code = 301;

        // Validate domain format
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/i', $domain)) {
            Flash::set('error', 'Formato de dominio no valido.');
            Router::redirect('/domains');
            return;
        }

        // Check domain is not already in use
        $exists = Database::fetchOne("SELECT id FROM hosting_accounts WHERE domain = :d", ['d' => $domain]);
        if ($exists) {
            Flash::set('error', "'{$domain}' ya existe como cuenta de hosting.");
            Router::redirect('/domains');
            return;
        }
        $exists2 = Database::fetchOne("SELECT id FROM hosting_domain_aliases WHERE domain = :d", ['d' => $domain]);
        if ($exists2) {
            Flash::set('error', "'{$domain}' ya existe como alias o redirect.");
            Router::redirect('/domains');
            return;
        }

        // Parse target to get the destination domain for Caddy
        $targetDomain = preg_replace('#^https?://#', '', rtrim($targetUrl, '/'));
        $targetDomain = explode('/', $targetDomain)[0]; // just the host part

        // Create Caddy redirect route
        $routeId = \MuseDockPanel\Services\SystemService::addCaddyRedirectRoute($domain, $targetDomain, $code, $preservePath);

        // Insert record
        $stmt = Database::query(
            "INSERT INTO hosting_domain_aliases (hosting_account_id, domain, type, redirect_code, preserve_path, caddy_route_id, customer_id, target_url)
             VALUES (:aid, :d, 'redirect', :code, :pp, :rid, :cid, :target)",
            [
                'aid' => null,
                'd' => $domain,
                'code' => $code,
                'pp' => $preservePath ? 't' : 'f',
                'rid' => $routeId,
                'cid' => $customerId,
                'target' => $targetUrl,
            ]
        );

        LogService::log('domain.redirect', $domain, "Standalone redirect created: {$domain} → {$targetUrl} ({$code})");

        // Replicate to slaves (standalone redirects were NOT synced before this).
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            self::syncStandaloneRedirectToCluster('sync', $domain, $targetUrl, $code, $preservePath, $customerId);
        }

        Flash::set('success', "Redirect creado: {$domain} → {$targetUrl}");
        Router::redirect('/domains');
    }

    /** Enqueue a standalone-redirect create/remove to every web node. */
    private static function syncStandaloneRedirectToCluster(string $op, string $domain, string $targetUrl = '', int $code = 301, bool $preservePath = true, ?int $customerId = null): void
    {
        $action = $op === 'remove' ? 'remove_standalone_redirect' : 'sync_standalone_redirect';
        foreach (\MuseDockPanel\Services\ClusterService::getWebNodes() as $node) {
            \MuseDockPanel\Services\ClusterService::enqueue((int)$node['id'], 'sync-hosting', [
                'hosting_action' => $action,
                'hosting_data'   => [
                    'domain'        => $domain,
                    'target_url'    => $targetUrl,
                    'redirect_code' => $code,
                    'preserve_path' => $preservePath,
                    'customer_id'   => $customerId,
                ],
            ], 6);
        }
    }

    /**
     * POST: Delete a standalone redirect
     */
    public function deleteRedirect(): void
    {
        // Verify admin password
        $password = $_POST['admin_password'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? $_SESSION['panel_user']['id'] ?? 0;
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Flash::set('error', 'Contraseña incorrecta.');
            Router::redirect('/domains');
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        $record = Database::fetchOne("SELECT * FROM hosting_domain_aliases WHERE id = :id", ['id' => $id]);

        if (!$record) {
            Flash::set('error', 'Redirect no encontrado.');
            Router::redirect('/domains');
            return;
        }

        // Remove Caddy route
        if (!empty($record['caddy_route_id'])) {
            $config = require PANEL_ROOT . '/config/panel.php';
            $caddyApi = $config['caddy']['api_url'];
            $ch = curl_init("{$caddyApi}/id/{$record['caddy_route_id']}");
            curl_setopt_array($ch, [\CURLOPT_CUSTOMREQUEST => 'DELETE', \CURLOPT_RETURNTRANSFER => true, \CURLOPT_TIMEOUT => 10]);
            curl_exec($ch);
            curl_close($ch);
        }

        Database::query("DELETE FROM hosting_domain_aliases WHERE id = :id", ['id' => $id]);
        LogService::log('domain.redirect', $record['domain'], "Redirect deleted: {$record['domain']}");

        // Propagate the removal to slaves (only for standalone redirects).
        if (empty($record['hosting_account_id']) && Settings::get('cluster_role', 'standalone') === 'master') {
            self::syncStandaloneRedirectToCluster('remove', (string)$record['domain']);
        }

        Flash::set('success', "Redirect '{$record['domain']}' eliminado.");
        Router::redirect('/domains');
    }

    /**
     * Comprueba el DNS (registro A) de un dominio y guarda el resultado, para que
     * la página cargue al momento con el último estado conocido. Antes cada
     * comprobación hacía además `curl ifconfig.me` para saber la IP propia: una
     * petición externa por dominio y por visita.
     */
    public function checkDns(): void
    {
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        header('Content-Type: application/json');
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+$/', $domain)) {
            echo json_encode(['error' => 'No domain']);
            return;
        }
        $entry = self::checkDnsNow($domain);
        echo json_encode(['domain' => $domain] + $entry + ['server_ips' => self::serverIps()]);
    }

    /** IPs de este servidor: las de sus interfaces + la pública guardada. */
    private static function serverIps(): array
    {
        static $ips = null;
        if ($ips !== null) {
            return $ips;
        }
        $ips = preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) ?: [];
        $pub = Settings::get('server_public_ip', '');
        if ($pub !== '') {
            $ips[] = $pub;
        }
        return $ips = array_values(array_unique(array_filter($ips, static fn($i) => filter_var($i, FILTER_VALIDATE_IP))));
    }

    private static function checkDnsNow(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_A) ?: [];
        $ips = array_values(array_filter(array_map(static fn($r) => (string)($r['ip'] ?? ''), $records)));
        $here = (bool)array_intersect($ips, self::serverIps());
        $cf = !$here && $ips && (bool)array_filter($ips, static fn($ip) => (bool)preg_match('/^(104\.(1[6-9]|2[0-9]|3[01])\.|172\.(6[4-9]|7[01])\.|188\.114\.(9[6-9]|1[01][0-9]|12[0-7])\.|162\.15[89]\.|141\.101\.|108\.162\.|190\.93\.|197\.234\.|198\.41\.|173\.245\.|103\.(21|22|31)\.)/', $ip));
        $entry = [
            'status' => $here ? 'ok' : ($cf ? 'cloudflare' : ($ips ? 'elsewhere' : 'none')),
            'records' => $ips,
            'points_here' => $here,
            'checked_at' => time(),
        ];
        $cache = self::dnsCache();
        $cache[$domain] = $entry;
        Settings::set('domains_dns_cache', json_encode($cache, JSON_UNESCAPED_SLASHES));
        return $entry;
    }

    /** Último estado de DNS guardado por dominio. */
    public static function dnsCache(): array
    {
        $c = json_decode(Settings::get('domains_dns_cache', '{}'), true);
        return is_array($c) ? $c : [];
    }
}
