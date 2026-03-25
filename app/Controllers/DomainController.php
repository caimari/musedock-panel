<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;
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

        // Domain aliases & redirects
        $aliasesAndRedirects = Database::fetchAll(
            "SELECT da.*, h.domain AS account_domain, h.username, h.status AS account_status,
                    c.name AS customer_name
             FROM hosting_domain_aliases da
             JOIN hosting_accounts h ON h.id = da.hosting_account_id
             LEFT JOIN customers c ON c.id = h.customer_id
             ORDER BY da.type, da.domain ASC"
        );

        View::render('domains/index', [
            'layout' => 'main',
            'pageTitle' => 'Domains',
            'domains' => $domains,
            'accountDomains' => $accountDomains,
            'aliasesAndRedirects' => $aliasesAndRedirects,
        ]);
    }

    public function checkDns(): void
    {
        $domain = trim($_POST['domain'] ?? '');
        if (empty($domain)) {
            echo json_encode(['error' => 'No domain']);
            return;
        }

        $records = @dns_get_record($domain, DNS_A);
        $serverIp = trim(shell_exec('curl -s ifconfig.me 2>/dev/null') ?: '');

        $pointsHere = false;
        $ips = [];
        if ($records) {
            foreach ($records as $r) {
                $ips[] = $r['ip'] ?? '';
                if (($r['ip'] ?? '') === $serverIp) {
                    $pointsHere = true;
                }
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'domain' => $domain,
            'records' => $ips,
            'server_ip' => $serverIp,
            'points_here' => $pointsHere,
        ]);
    }
}
