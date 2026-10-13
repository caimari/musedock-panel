<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\View;
use MuseDockPanel\Router;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\CloudflareService;
use MuseDockPanel\Services\DnsGuardService;
use MuseDockPanel\Services\FailoverService;
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
            'guard'          => DnsGuardService::config(),
            'guardLog'       => DnsGuardService::recentLog(12),
            'guardSlave'     => Settings::get('cluster_role', 'standalone') === 'slave',
        ]);
    }

    /**
     * POST: guardar la protección de DNS (zonas y registros que este panel no puede tocar).
     * Pide la contraseña del administrador y no acepta reglas que impidan el propio relevo.
     */
    public function guardSave(): void
    {
        View::verifyCsrf();
        $back = '/settings/cloudflare-dns';
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es una copia (slave): la protección de DNS la recibe del master.');
            Router::redirect($back);
            return;
        }
        if (!$this->verifyAdminPassword((string)($_POST['admin_password'] ?? ''))) {
            Flash::set('error', 'Contraseña de administrador incorrecta: no se ha cambiado nada.');
            Router::redirect($back);
            return;
        }

        $cfg = DnsGuardService::normalize([
            'mode'        => (string)($_POST['mode'] ?? 'off'),
            'zones_allow' => (string)($_POST['zones_allow'] ?? ''),
            'zones_deny'  => (string)($_POST['zones_deny'] ?? ''),
            'rules'       => DnsGuardService::rulesFromText((string)($_POST['rules'] ?? '')),
        ]);

        $note = '';
        if ($cfg['mode'] === 'enforce') {
            $chk = DnsGuardService::planConflicts($cfg);
            if ($chk['blocked']) {
                $list = array_map(static fn($b) => $b['name'] . ' (' . $b['zone'] . ')', array_slice($chk['blocked'], 0, 8));
                Flash::set('error', 'No se guarda: con estas reglas el relevo de este servidor no podría mover '
                    . count($chk['blocked']) . ' registro(s): ' . implode(', ', $list) . (count($chk['blocked']) > 8 ? '…' : '')
                    . '. Quita esas zonas o registros de las reglas, o déjalo en modo «Solo avisar».');
                Router::redirect($back);
                return;
            }
            $note = $chk['error'] !== null
                ? ' No se ha podido comprobar contra el plan de relevo: ' . $chk['error']
                : " Comprobado: el relevo puede mover sus {$chk['checked']} registros.";
        }

        DnsGuardService::save($cfg);
        LogService::log('dns.guard', 'config', 'Protección de DNS guardada: modo ' . $cfg['mode']
            . ', ' . count($cfg['zones_allow']) . ' zonas permitidas, ' . count($cfg['zones_deny']) . ' prohibidas, '
            . count($cfg['rules']) . ' reglas de registros');

        $pushed = '';
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            $r = FailoverService::pushConfigToSlaves();
            $pushed = !empty($r['ok']) ? ' Enviado a las copias.' : ' No se pudo enviar a todas las copias (se reintenta).';
        }
        Flash::set('success', 'Protección de DNS guardada (modo: ' . $cfg['mode'] . ').' . $note . $pushed);
        Router::redirect($back);
    }

    /** GET (AJAX): ¿bloquearía la protección actual esta operación? No cambia nada. */
    public function guardCheck(): void
    {
        header('Content-Type: application/json');
        $cfg = DnsGuardService::config();
        if ($cfg['mode'] === 'off') {
            echo json_encode(['ok' => true, 'blocked' => false, 'reason' => 'La protección está apagada: no se bloquea nada.']);
            return;
        }
        $cfg['mode'] = 'enforce';
        $action = in_array($_GET['action'] ?? '', DnsGuardService::ACTIONS, true) ? $_GET['action'] : 'update';
        $name = strtolower(trim((string)($_GET['name'] ?? '')));
        $type = strtoupper(trim((string)($_GET['type'] ?? '')));
        $zone = strtolower(trim((string)($_GET['zone'] ?? '')));
        if ($zone === '' && $name !== '') {
            $zone = $name;
        }
        $reason = DnsGuardService::evaluate($cfg, $zone, $name !== '' ? $name : null, $type !== '' ? $type : null, $action);
        echo json_encode(['ok' => true, 'blocked' => $reason !== null, 'reason' => $reason ?? 'Se permitiría.']);
    }

    private function verifyAdminPassword(string $password): bool
    {
        if ($password === '') {
            return false;
        }
        $adminId = (int)($_SESSION['panel_user']['id'] ?? 0);
        if ($adminId <= 0) {
            return false;
        }
        $admin = Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $adminId]);
        return $admin && password_verify($password, (string)$admin['password_hash']);
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
        $result = CloudflareService::listAllZones($token);

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
     * AJAX: Bulk actions — delete or toggle proxy for multiple records.
     */
    public function bulkAction(): void
    {
        header('Content-Type: application/json');
        View::verifyCsrf();

        $zoneId    = trim($_POST['zone_id'] ?? '');
        $accIdx    = (int)($_POST['account'] ?? 0);
        $action    = trim($_POST['action'] ?? ''); // 'delete', 'proxy_on', 'proxy_off'
        $recordIds = json_decode($_POST['record_ids'] ?? '[]', true);

        $accounts = CloudflareService::getConfiguredAccounts();
        $token = $accounts[$accIdx]['token'] ?? '';

        if (!$token || !$zoneId || empty($recordIds) || !$action) {
            echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
            return;
        }

        if (!in_array($action, ['delete', 'proxy_on', 'proxy_off'], true)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid action']);
            return;
        }

        $success = 0;
        $errors = [];

        foreach ($recordIds as $recordId) {
            $recordId = trim($recordId);
            if (empty($recordId)) continue;

            if ($action === 'delete') {
                $result = CloudflareService::deleteRecord($token, $zoneId, $recordId);
            } else {
                $proxied = $action === 'proxy_on';
                $result = CloudflareService::apiRequest($token, 'PATCH', "/zones/{$zoneId}/dns_records/{$recordId}", [
                    'proxied' => $proxied,
                ]);
                $result['ok'] = !empty($result['ok']) || !empty($result['result']);
            }

            if (!empty($result['ok'])) {
                $success++;
            } else {
                $errors[] = $recordId . ': ' . ($result['error'] ?? 'unknown error');
            }
        }

        $total = count($recordIds);
        $actionLabel = match($action) {
            'delete'    => 'deleted',
            'proxy_on'  => 'proxy enabled',
            'proxy_off' => 'proxy disabled',
        };
        LogService::log('cloudflare', "Bulk {$actionLabel}: {$success}/{$total} records");

        echo json_encode([
            'ok'      => $success > 0,
            'success' => $success,
            'total'   => $total,
            'errors'  => $errors,
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
