<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\View;
use MuseDockPanel\Services\CloudflareService;
use MuseDockPanel\Services\LogService;

class CloudflareDnsController
{
    /**
     * Main DNS management page — shows all zones and their records.
     */
    public function index(): void
    {
        $accounts = CloudflareService::getConfiguredAccounts();
        $hasAccounts = !empty($accounts);

        // Get all hosting domains for quick-link
        $hostingDomains = Database::fetchAll(
            "SELECT h.domain FROM hosting_accounts h WHERE h.status = 'active' ORDER BY h.domain"
        );

        // Get aliases too
        $aliasDomains = Database::fetchAll(
            "SELECT da.domain FROM hosting_domain_aliases da ORDER BY da.domain"
        );

        View::render('settings/cloudflare-dns', [
            'layout'         => 'main',
            'pageTitle'      => 'Cloudflare DNS',
            'accounts'       => $accounts,
            'hasAccounts'    => $hasAccounts,
            'hostingDomains' => $hostingDomains,
            'aliasDomains'   => $aliasDomains,
        ]);
    }

    /**
     * AJAX: List zones for a CF account (by index).
     */
    public function listZones(): void
    {
        header('Content-Type: application/json');
        $idx = (int)($_GET['account'] ?? 0);
        $accounts = CloudflareService::getConfiguredAccounts();

        if (!isset($accounts[$idx])) {
            echo json_encode(['ok' => false, 'error' => 'Account not found']);
            return;
        }

        $token = $accounts[$idx]['token'] ?? '';
        $result = CloudflareService::listZones($token);

        if (!$result['ok']) {
            echo json_encode(['ok' => false, 'error' => $result['error']]);
            return;
        }

        $zones = array_map(fn($z) => [
            'id'     => $z['id'],
            'name'   => $z['name'],
            'status' => $z['status'],
            'plan'   => $z['plan']['name'] ?? 'Unknown',
        ], $result['result'] ?? []);

        echo json_encode(['ok' => true, 'zones' => $zones]);
    }

    /**
     * AJAX: List DNS records for a zone.
     */
    public function listRecords(): void
    {
        header('Content-Type: application/json');
        $zoneId = trim($_GET['zone_id'] ?? '');
        $token  = trim($_GET['token'] ?? '');

        // If token not provided directly, find from account index
        if (!$token) {
            $idx = (int)($_GET['account'] ?? 0);
            $accounts = CloudflareService::getConfiguredAccounts();
            $token = $accounts[$idx]['token'] ?? '';
        }

        if (!$token || !$zoneId) {
            echo json_encode(['ok' => false, 'error' => 'Missing token or zone_id']);
            return;
        }

        $typeFilter = trim($_GET['type'] ?? '');
        $filters = ['per_page' => 100];
        if ($typeFilter) {
            $filters['type'] = $typeFilter;
        }

        $result = CloudflareService::listRecords($token, $zoneId, $filters);

        if (!$result['ok']) {
            echo json_encode(['ok' => false, 'error' => $result['error']]);
            return;
        }

        $records = array_map(fn($r) => [
            'id'      => $r['id'],
            'type'    => $r['type'],
            'name'    => $r['name'],
            'content' => $r['content'],
            'ttl'     => $r['ttl'],
            'proxied' => $r['proxied'] ?? false,
        ], $result['result'] ?? []);

        echo json_encode(['ok' => true, 'records' => $records]);
    }

    /**
     * AJAX: Create a DNS record.
     */
    public function createRecord(): void
    {
        header('Content-Type: application/json');
        View::verifyCsrf();

        $zoneId  = trim($_POST['zone_id'] ?? '');
        $accIdx  = (int)($_POST['account'] ?? 0);
        $type    = strtoupper(trim($_POST['type'] ?? 'A'));
        $name    = trim($_POST['name'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $ttl     = (int)($_POST['ttl'] ?? 1);
        $proxied = !empty($_POST['proxied']);

        $accounts = CloudflareService::getConfiguredAccounts();
        $token = $accounts[$accIdx]['token'] ?? '';

        if (!$token || !$zoneId || !$name || !$content) {
            echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
            return;
        }

        $data = [
            'type'    => $type,
            'name'    => $name,
            'content' => $content,
            'ttl'     => $ttl,
        ];
        // Only A and AAAA support proxied
        if (in_array($type, ['A', 'AAAA', 'CNAME'])) {
            $data['proxied'] = $proxied;
        }

        $result = CloudflareService::createRecord($token, $zoneId, $data);

        if ($result['ok']) {
            LogService::log('cloudflare', "Created DNS record: {$type} {$name} -> {$content}" . ($proxied ? ' (proxied)' : ''));
        }

        echo json_encode([
            'ok'    => $result['ok'],
            'error' => $result['error'] ?? '',
        ]);
    }

    /**
     * AJAX: Update a DNS record.
     */
    public function updateRecord(): void
    {
        header('Content-Type: application/json');
        View::verifyCsrf();

        $zoneId   = trim($_POST['zone_id'] ?? '');
        $recordId = trim($_POST['record_id'] ?? '');
        $accIdx   = (int)($_POST['account'] ?? 0);
        $type     = strtoupper(trim($_POST['type'] ?? 'A'));
        $name     = trim($_POST['name'] ?? '');
        $content  = trim($_POST['content'] ?? '');
        $ttl      = (int)($_POST['ttl'] ?? 1);
        $proxied  = !empty($_POST['proxied']);

        $accounts = CloudflareService::getConfiguredAccounts();
        $token = $accounts[$accIdx]['token'] ?? '';

        if (!$token || !$zoneId || !$recordId) {
            echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
            return;
        }

        $data = [
            'type'    => $type,
            'name'    => $name,
            'content' => $content,
            'ttl'     => $ttl,
        ];
        if (in_array($type, ['A', 'AAAA', 'CNAME'])) {
            $data['proxied'] = $proxied;
        }

        $result = CloudflareService::updateRecord($token, $zoneId, $recordId, $data);

        if ($result['ok']) {
            LogService::log('cloudflare', "Updated DNS record: {$type} {$name} -> {$content}" . ($proxied ? ' (proxied)' : ' (DNS only)'));
        }

        echo json_encode([
            'ok'    => $result['ok'],
            'error' => $result['error'] ?? '',
        ]);
    }

    /**
     * AJAX: Delete a DNS record.
     */
    public function deleteRecord(): void
    {
        header('Content-Type: application/json');
        View::verifyCsrf();

        $zoneId   = trim($_POST['zone_id'] ?? '');
        $recordId = trim($_POST['record_id'] ?? '');
        $accIdx   = (int)($_POST['account'] ?? 0);

        $accounts = CloudflareService::getConfiguredAccounts();
        $token = $accounts[$accIdx]['token'] ?? '';

        if (!$token || !$zoneId || !$recordId) {
            echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
            return;
        }

        $result = CloudflareService::deleteRecord($token, $zoneId, $recordId);

        if ($result['ok']) {
            LogService::log('cloudflare', "Deleted DNS record: {$recordId}");
        }

        echo json_encode([
            'ok'    => $result['ok'],
            'error' => $result['error'] ?? '',
        ]);
    }

    /**
     * AJAX: Toggle proxy status (orange cloud on/off).
     */
    public function toggleProxy(): void
    {
        header('Content-Type: application/json');
        View::verifyCsrf();

        $zoneId   = trim($_POST['zone_id'] ?? '');
        $recordId = trim($_POST['record_id'] ?? '');
        $accIdx   = (int)($_POST['account'] ?? 0);
        $proxied  = !empty($_POST['proxied']);

        $accounts = CloudflareService::getConfiguredAccounts();
        $token = $accounts[$accIdx]['token'] ?? '';

        if (!$token || !$zoneId || !$recordId) {
            echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
            return;
        }

        // First get current record to preserve type/name/content
        $current = CloudflareService::listRecords($token, $zoneId, ['per_page' => 1]);
        // Use PATCH which only updates provided fields
        $result = CloudflareService::apiRequest($token, 'PATCH', "/zones/{$zoneId}/dns_records/{$recordId}", [
            'proxied' => $proxied,
        ]);

        $ok = !empty($result['ok']) || (!empty($result['result']));

        if ($ok) {
            $state = $proxied ? 'enabled' : 'disabled';
            LogService::log('cloudflare', "Proxy {$state} for record {$recordId}");
        }

        echo json_encode([
            'ok'    => $ok,
            'error' => $result['error'] ?? '',
        ]);
    }
}
