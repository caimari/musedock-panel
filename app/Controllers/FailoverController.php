<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Settings;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Services\FailoverService;
use MuseDockPanel\Services\CloudflareService;
use MuseDockPanel\Services\LogService;

class FailoverController
{
    // ─── Save scalar failover settings ───────────────────────

    /**
     * POST /settings/failover/save-config
     */
    public function saveConfig(): void
    {
        $data = [];
        $fields = [
            'failover_mode',
            'failover_dyndns_provider', 'failover_dyndns_hostname',
            'failover_ttl_normal', 'failover_ttl_alert', 'failover_ttl_failover',
            'failover_check_interval', 'failover_down_threshold',
            'failover_up_threshold', 'failover_check_timeout',
            'failover_caddy_l4_bin', 'failover_caddy_l4_conf',
            'failover_caddy_normal_port', 'failover_caddy_backup_port',
        ];

        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                $data[$f] = trim($_POST[$f]);
            }
        }

        if (isset($_POST['failover_remote_domains'])) {
            Settings::set('failover_remote_domains', trim($_POST['failover_remote_domains']));
        }

        FailoverService::saveConfig($data);
        LogService::log('failover.config', null, 'Failover settings updated');
        Flash::set('success', 'Configuración de failover guardada.');
        Router::redirect('/settings/cluster#tab-failover');
    }

    // ─── Save servers (dynamic list) ─────────────────────────

    /**
     * POST /settings/failover/save-servers
     */
    public function saveServers(): void
    {
        $servers = [];
        $ids    = $_POST['srv_id'] ?? [];
        $names  = $_POST['srv_name'] ?? [];
        $ips    = $_POST['srv_ip'] ?? [];
        $roles  = $_POST['srv_role'] ?? [];
        $ports  = $_POST['srv_port'] ?? [];
        $failTo = $_POST['srv_failover_to'] ?? [];
        $dyndns = $_POST['srv_dyndns'] ?? [];

        for ($i = 0; $i < count($names); $i++) {
            $name = trim($names[$i] ?? '');
            $ip   = trim($ips[$i] ?? '');
            if (!$name) continue;

            $servers[] = [
                'id'          => trim($ids[$i] ?? '') ?: substr(md5(uniqid('', true)), 0, 8),
                'name'        => $name,
                'ip'          => $ip,
                'role'        => trim($roles[$i] ?? 'primary'),
                'port'        => (int)($ports[$i] ?? 443) ?: 443,
                'failover_to' => trim($failTo[$i] ?? ''),
                'dyndns'      => ($dyndns[$i] ?? '0') === '1',
                'enabled'     => true,
            ];
        }

        FailoverService::saveServers($servers);
        LogService::log('failover.servers', null, count($servers) . ' servers saved');
        Flash::set('success', count($servers) . ' servidor(es) guardado(s).');
        Router::redirect('/settings/cluster#tab-failover');
    }

    // ─── Cloudflare accounts ─────────────────────────────────

    /**
     * POST /settings/failover/save-cf-accounts
     */
    public function saveCfAccounts(): void
    {
        $accounts = [];
        $names  = $_POST['cf_name'] ?? [];
        $tokens = $_POST['cf_token'] ?? [];

        for ($i = 0; $i < count($names); $i++) {
            $name  = trim($names[$i] ?? '');
            $token = trim($tokens[$i] ?? '');
            if (!$name || !$token) continue;

            $zones = [];
            $resp = CloudflareService::listZones($token);
            if ($resp['ok'] && !empty($resp['result'])) {
                foreach ($resp['result'] as $z) {
                    $zones[] = ['id' => $z['id'], 'name' => $z['name']];
                }
            }

            $accounts[] = ['name' => $name, 'token' => $token, 'zones' => $zones];
        }

        CloudflareService::saveAccounts($accounts);
        LogService::log('failover.cloudflare', null, count($accounts) . ' CF accounts saved');
        Flash::set('success', count($accounts) . ' cuenta(s) Cloudflare guardada(s).');
        Router::redirect('/settings/cluster#tab-failover');
    }

    /**
     * POST /settings/failover/verify-cf-token (AJAX)
     */
    public function verifyCfToken(): void
    {
        header('Content-Type: application/json');
        $token = trim($_POST['token'] ?? '');
        if (!$token) { echo json_encode(['ok' => false, 'error' => 'Token vacío']); return; }

        $verify = CloudflareService::verifyToken($token);
        if (!$verify['ok']) { echo json_encode(['ok' => false, 'error' => $verify['error'] ?? 'Token inválido']); return; }

        $zones = CloudflareService::listZones($token);
        $zoneList = [];
        if ($zones['ok']) {
            foreach ($zones['result'] ?? [] as $z) $zoneList[] = ['id' => $z['id'], 'name' => $z['name']];
        }

        echo json_encode(['ok' => true, 'zones' => $zoneList]);
    }

    // ─── AJAX endpoints ──────────────────────────────────────

    /**
     * GET /settings/failover/check-health
     */
    public function checkHealth(): void
    {
        header('Content-Type: application/json');
        $checks = FailoverService::checkAllEndpoints();
        $recommended = FailoverService::evaluateState($checks);
        $current = FailoverService::getState();

        echo json_encode([
            'ok'          => true,
            'checks'      => $checks,
            'current'     => $current,
            'recommended' => $recommended,
            'mismatch'    => $current !== $recommended,
        ]);
    }

    /**
     * POST /settings/failover/execute
     */
    public function execute(): void
    {
        header('Content-Type: application/json');

        $action   = $_POST['action'] ?? '';
        $password = $_POST['password'] ?? '';

        if (!$this->verifyPassword($password)) {
            echo json_encode(['ok' => false, 'error' => 'Contraseña incorrecta']);
            return;
        }

        $result = match ($action) {
            'failover_degraded'  => FailoverService::transitionTo(FailoverService::STATE_DEGRADED, 'manual'),
            'failover_primary'   => FailoverService::transitionTo(FailoverService::STATE_PRIMARY_DOWN, 'manual'),
            'failover_emergency' => FailoverService::transitionTo(FailoverService::STATE_EMERGENCY, 'manual'),
            'failback'           => FailoverService::transitionTo(FailoverService::STATE_NORMAL, 'manual'),
            default              => ['ok' => false, 'error' => 'Acción no válida'],
        };

        echo json_encode($result);
    }

    /**
     * GET /settings/failover/caddy-l4-preview
     */
    public function caddyL4Preview(): void
    {
        header('Content-Type: application/json');
        $c = FailoverService::getConfig();
        $config = FailoverService::generateCaddyL4Config($c);

        echo json_encode([
            'ok'     => true,
            'config' => json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'local'  => count(FailoverService::getLocalDomains()),
            'remote' => count(FailoverService::getRemoteDomains()),
        ]);
    }

    /**
     * GET /settings/failover/status
     */
    public function status(): void
    {
        header('Content-Type: application/json');
        echo json_encode(FailoverService::getStatusSummary());
    }

    /**
     * GET /settings/failover/domains-not-cf
     */
    public function domainsNotCf(): void
    {
        header('Content-Type: application/json');
        $domains = FailoverService::getDomainsNotInCloudflare();
        echo json_encode(['ok' => true, 'domains' => $domains, 'count' => count($domains)]);
    }

    // ─── Private ─────────────────────────────────────────────

    private function verifyPassword(string $password): bool
    {
        $userId = $_SESSION['admin_id'] ?? $_SESSION['panel_user']['id'] ?? 0;
        if (!$userId) return false;

        $user = \MuseDockPanel\Database::fetchOne(
            'SELECT password FROM panel_admins WHERE id = :id', ['id' => $userId]
        );
        return $user && password_verify($password, $user['password']);
    }
}
