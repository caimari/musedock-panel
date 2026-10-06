<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Settings;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Services\FailoverService;
use MuseDockPanel\Services\CloudflareService;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Security\TlsClient;

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
            'failover_cooldown_minutes',
            'failover_caddy_l4_bin', 'failover_caddy_l4_conf',
            'failover_caddy_normal_port', 'failover_caddy_backup_port',
            'failover_iface_primary', 'failover_iface_backup', 'failover_iface_primary_ip',
            'failover_disk_critical_pct', 'failover_disk_warning_pct',
            'failover_load_critical_mult', 'failover_load_warning_mult',
            'failover_pg_panel_severity', 'failover_pg_hosting_severity',
            'failover_mysql_severity', 'failover_caddy_severity',
            'failover_site_probes', 'failover_site_alive_wait_minutes',
        ];

        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                $data[$f] = trim($_POST[$f]);
            }
        }

        if (isset($_POST['failover_remote_domains'])) {
            Settings::set('failover_remote_domains', trim($_POST['failover_remote_domains']));
        }

        // Save remote domain sources (servers exposing /api/domains)
        if (isset($_POST['rds_name'])) {
            $sources = [];
            $rdsNames  = $_POST['rds_name'] ?? [];
            $rdsUrls   = $_POST['rds_url'] ?? [];
            $rdsTokens = $_POST['rds_token'] ?? [];
            for ($i = 0; $i < count($rdsNames); $i++) {
                $name  = trim($rdsNames[$i] ?? '');
                $url   = trim($rdsUrls[$i] ?? '');
                $token = trim($rdsTokens[$i] ?? '');
                if (!$url) continue;
                $sources[] = ['name' => $name ?: "Server-" . ($i + 1), 'url' => $url, 'token' => $token];
            }
            FailoverService::saveRemoteDomainSources($sources);
        }

        FailoverService::saveConfig($data);
        FailoverService::pushConfigToSlaves();
        LogService::log('failover.config', null, 'Failover settings updated');
        Flash::set('success', 'Configuración de failover guardada y sincronizada con slaves.');
        Router::redirect('/settings/cluster#failover');
    }

    // ─── Save servers (dynamic list) ─────────────────────────

    /**
     * POST /settings/failover/save-servers
     */
    public function saveServers(): void
    {
        $servers = [];
        $ids       = $_POST['srv_id'] ?? [];
        $names     = $_POST['srv_name'] ?? [];
        $ips       = $_POST['srv_ip'] ?? [];
        $roles     = $_POST['srv_role'] ?? [];
        $ports     = $_POST['srv_port'] ?? [];
        $failTo    = $_POST['srv_failover_to'] ?? [];
        $dyndns    = $_POST['srv_dyndns'] ?? [];
        $priorities = $_POST['srv_priority'] ?? [];

        for ($i = 0; $i < count($names); $i++) {
            $name = trim($names[$i] ?? '');
            $ip   = trim($ips[$i] ?? '');
            if (!$name) continue;

            $servers[] = [
                'id'                => trim($ids[$i] ?? '') ?: substr(md5(uniqid('', true)), 0, 8),
                'name'              => $name,
                'ip'                => $ip,
                'role'              => trim($roles[$i] ?? 'primary'),
                'port'              => (int)($ports[$i] ?? 443) ?: 443,
                'failover_to'       => trim($failTo[$i] ?? ''),
                'dyndns'            => ($dyndns[$i] ?? '0') === '1',
                'failover_priority' => (int)($priorities[$i] ?? 99) ?: 99,
                'enabled'           => true,
            ];
        }

        FailoverService::saveServers($servers);
        FailoverService::pushConfigToSlaves();
        LogService::log('failover.servers', null, count($servers) . ' servers saved');
        Flash::set('success', count($servers) . ' servidor(es) guardado(s) y sincronizado(s) con slaves.');
        Router::redirect('/settings/cluster#failover');
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
        // La página ya no recibe los tokens: un campo vacío en una cuenta existente
        // significa "conservar el token guardado" (cf_existing = su posición).
        $existing = $_POST['cf_existing'] ?? [];
        $stored = CloudflareService::getConfiguredAccounts();
        $rejected = [];

        for ($i = 0; $i < count($names); $i++) {
            $name  = trim($names[$i] ?? '');
            $token = trim($tokens[$i] ?? '');
            $ex = (string)($existing[$i] ?? '');
            $prev = ($ex !== '' && ctype_digit($ex)) ? (string)($stored[(int)$ex]['token'] ?? '') : '';
            if ($token === '' && $prev !== '') {
                $token = $prev;
            } elseif ($token !== '' && $prev !== '' && $token !== $prev && empty(CloudflareService::verifyToken($token)['ok'])) {
                // Un token nuevo que Cloudflare no acepta (p. ej. una contraseña que el
                // navegador rellenó solo) no sustituye al que había.
                $rejected[] = $name;
                $token = $prev;
            }
            if (!$name || !$token) continue;

            $zones = [];
            $resp = CloudflareService::listAllZones($token);
            if ($resp['ok'] && !empty($resp['result'])) {
                foreach ($resp['result'] as $z) {
                    $zones[] = ['id' => $z['id'], 'name' => $z['name']];
                }
            }

            $accounts[] = [
                'name'  => $name,
                'token' => \MuseDockPanel\Services\ReplicationService::encryptPassword($token),
                'zones' => $zones,
            ];
        }

        CloudflareService::saveAccounts($accounts);

        // Token de Caddy (CLOUDFLARE_API_TOKEN, certificados DNS-01): el de la cuenta
        // que contiene la zona del panel, no "la primera". Se aplica SIEMPRE que haya
        // cambiado, aquí y en cada slave (cada nodo compara con el suyo y solo reinicia
        // Caddy si es distinto). La casilla ahora solo FUERZA reescribir y reiniciar.
        $forceCaddyToken = !empty($_POST['update_caddy_token']) && !empty($accounts);
        $caddyToken = CloudflareService::caddyTokenFor(CloudflareService::accountsForTransfer());
        [$caddyTokenUpdated, $caddyLocalErr] = $caddyToken !== ''
            ? CloudflareService::syncCaddyToken($caddyToken, $forceCaddyToken)
            : [false, null];
        if ($caddyTokenUpdated) {
            \MuseDockPanel\Settings::set('failover_cf_token_propagated_at', date('Y-m-d H:i:s'));
            LogService::log('failover.cloudflare', null, 'Caddy CLOUDFLARE_API_TOKEN updated in /etc/default/caddy');
        }

        $pushResults = FailoverService::pushConfigToSlaves($forceCaddyToken, $caddyToken !== '' ? $caddyToken : null);
        $updateCaddyToken = $caddyToken !== '';
        LogService::log('failover.cloudflare', null, count($accounts) . ' CF accounts saved');

        $msg = count($accounts) . ' cuenta(s) Cloudflare guardada(s) y sincronizada(s) con slaves.';
        if ($rejected) {
            $msg .= ' AVISO: Cloudflare no aceptó el token nuevo de ' . implode(', ', $rejected) . '; se ha conservado el anterior.';
        }
        // Resultado del token de Caddy nodo a nodo, sin dar por bueno lo que no se comprobó
        // (antes un nodo en cola o con error salía como "sincronizado").
        if ($updateCaddyToken) {
            $done = []; $same = []; $failed = [];
            $local = 'master';
            if ($caddyTokenUpdated)  { $done[] = $local; }
            elseif ($caddyLocalErr)  { $failed[] = "{$local} ({$caddyLocalErr})"; }
            else                     { $same[] = $local; }
            foreach (($pushResults['results'] ?? []) as $r) {
                $n = (string)($r['node'] ?? '?');
                if (empty($r['ok'])) {
                    $failed[] = "{$n} (no responde; queda en cola y se aplicará al reconectar)";
                } elseif (!empty($r['caddy_token_updated'])) {
                    $done[] = $n;
                } elseif (!empty($r['caddy_token_error'])) {
                    $failed[] = "{$n} ({$r['caddy_token_error']})";
                } else {
                    $same[] = $n;
                }
            }
            if ($done) { $msg .= ' Token de Caddy actualizado (Caddy se reinicia en unos segundos) en: ' . implode(', ', $done) . '.'; }
            if ($same) { $msg .= ' Ya lo tenían bien: ' . implode(', ', $same) . '.'; }
            if ($failed) {
                Flash::set('error', 'Token de Caddy NO aplicado en: ' . implode(' | ', $failed));
            }
        }

        Flash::set('success', $msg);
        Router::redirect('/settings/cluster#failover');
    }

    /**
     * POST /settings/failover/verify-cf-token (AJAX)
     */
    public function verifyCfToken(): void
    {
        header('Content-Type: application/json');
        $token = trim($_POST['token'] ?? '');
        $ex = (string)($_POST['existing'] ?? '');
        if ($token === '' && $ex !== '' && ctype_digit($ex)) {
            $token = (string)(CloudflareService::getConfiguredAccounts()[(int)$ex]['token'] ?? '');
        }
        if (!$token) { echo json_encode(['ok' => false, 'error' => 'Token vacío']); return; }

        $verify = CloudflareService::verifyToken($token);
        if (!$verify['ok']) { echo json_encode(['ok' => false, 'error' => $verify['error'] ?? 'Token inválido']); return; }

        $zones = CloudflareService::listAllZones($token);
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
     *
     * Unified failover action: DNS change + promote/demote in one click.
     * - failover_degraded/failover_primary/failover_emergency: DNS switch + promote slave
     * - failback: DNS revert + demote back to slave
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

        // Step 1: DNS transition
        $result = match ($action) {
            'failover_degraded'  => FailoverService::transitionTo(FailoverService::STATE_DEGRADED, 'manual'),
            'failover_primary'   => FailoverService::transitionTo(FailoverService::STATE_PRIMARY_DOWN, 'manual'),
            'failover_emergency' => FailoverService::transitionTo(FailoverService::STATE_EMERGENCY, 'manual'),
            'failback'           => FailoverService::transitionTo(FailoverService::STATE_NORMAL, 'manual'),
            default              => ['ok' => false, 'error' => 'Acción no válida'],
        };

        if (!($result['ok'] ?? false)) {
            echo json_encode($result);
            return;
        }

        $actions = $result['actions'] ?? [];

        // Step 2: Cluster promote/demote (atomic with DNS change)
        if (in_array($action, ['failover_primary', 'failover_emergency'])) {
            // Find the highest-priority failover server to promote (election)
            $failoverServers = FailoverService::getFailoverServersByPriority();
            $nodes = ClusterService::getActiveNodes();
            $promoted = false;

            foreach ($failoverServers as $foSrv) {
                $foIp = $foSrv['ip'] ?? '';
                if (!$foIp) continue;

                // Find matching cluster node by IP
                foreach ($nodes as $node) {
                    $nodeUrl = $node['api_url'] ?? '';
                    if (str_contains($nodeUrl, $foIp)) {
                        try {
                            $promoteResult = ClusterService::callNode((int)$node['id'], 'POST', 'api/cluster/action', [
                                'action' => 'promote',
                                'payload' => [],
                            ]);
                            if ($promoteResult['ok'] ?? false) {
                                $prio = $foSrv['failover_priority'] ?? 99;
                                $actions[] = "Promote: {$node['name']} ({$foIp}, prio {$prio}) promovido a master";
                                LogService::log('failover.promote', $node['name'], "Slave {$foIp} promoted to master via failover (priority {$prio})");
                                $promoted = true;
                            } else {
                                $actions[] = "Promote WARNING: {$node['name']} — " . ($promoteResult['error'] ?? json_encode($promoteResult['errors'] ?? []));
                            }
                        } catch (\Throwable $e) {
                            $actions[] = "Promote ERROR: {$node['name']} — " . $e->getMessage();
                            // Enqueue for retry
                            ClusterService::enqueue((int)$node['id'], 'promote', [], 1);
                            $actions[] = "Promote enqueued for retry: {$node['name']}";
                        }
                        break;
                    }
                }
                // Only promote the highest-priority server that's reachable
                if ($promoted) break;
            }
        } elseif ($action === 'failback') {
            // Demote: tell promoted servers to go back to slave
            // The original master IP is stored when failover was activated
            $originalMasterIp = Settings::get('failover_original_master_ip', '');
            $nodes = ClusterService::getActiveNodes();

            if ($originalMasterIp) {
                foreach ($nodes as $node) {
                    $nodeUrl = $node['api_url'] ?? '';
                    // Find nodes that are NOT the original master — they were promoted
                    if (!str_contains($nodeUrl, $originalMasterIp)) {
                        try {
                            $demoteResult = ClusterService::callNode((int)$node['id'], 'POST', 'api/cluster/action', [
                                'action' => 'demote',
                                'payload' => ['new_master_ip' => $originalMasterIp],
                            ]);
                            if ($demoteResult['ok'] ?? false) {
                                $actions[] = "Demote: {$node['name']} degradado a slave (master: {$originalMasterIp})";
                                LogService::log('failover.demote', $node['name'], "Demoted back to slave, master: {$originalMasterIp}");
                            } else {
                                $actions[] = "Demote WARNING: {$node['name']} — " . ($demoteResult['error'] ?? json_encode($demoteResult['errors'] ?? []));
                            }
                        } catch (\Throwable $e) {
                            $actions[] = "Demote ERROR: {$node['name']} — " . $e->getMessage();
                            ClusterService::enqueue((int)$node['id'], 'demote', ['new_master_ip' => $originalMasterIp], 1);
                            $actions[] = "Demote enqueued for retry: {$node['name']}";
                        }
                    }
                }
                // Clear the stored original master IP
                Settings::set('failover_original_master_ip', '');
            } else {
                $actions[] = "Demote SKIP: no original master IP stored (promote was manual?)";
            }
        }

        // Set cooldown timestamp on failback (prevents immediate re-failover)
        if ($action === 'failback') {
            Settings::set('failover_last_failback_at', date('Y-m-d H:i:s'));
        }

        // Save original master IP when entering failover (for failback later)
        if (in_array($action, ['failover_primary', 'failover_emergency'])) {
            $primaryServers = FailoverService::getServersByRole(FailoverService::ROLE_PRIMARY);
            if (!empty($primaryServers)) {
                $masterIp = $primaryServers[0]['ip'] ?? '';
                if ($masterIp) {
                    Settings::set('failover_original_master_ip', $masterIp);
                    Settings::set('failover_activated_at', date('Y-m-d H:i:s'));
                }
            }
        }

        $result['actions'] = $actions;
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

    /** POST (AJAX): traer de otros nodos las cuentas de Cloudflare que aquí faltan. Solo en el que manda. */
    public function pullCfAccounts(): void
    {
        header('Content-Type: application/json');
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            echo json_encode(['ok' => false, 'error' => 'Este servidor es una copia: las cuentas se gestionan en el que manda.']);
            return;
        }
        try {
            echo json_encode(\MuseDockPanel\Services\FailoverService::pullCfAccountsFromPeers(!empty($_POST['apply'])), JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // ─── caddy-l4 installation ─────────────────────────────

    /**
     * POST /settings/failover/install-caddy-l4  (AJAX)
     * Runs bin/install-caddy-l4.sh and streams output.
     */
    public function installCaddyL4(): void
    {
        header('Content-Type: application/json');

        $script = (defined('PANEL_ROOT') ? PANEL_ROOT : '/opt/musedock-panel') . '/bin/install-caddy-l4.sh';
        if (!file_exists($script)) {
            echo json_encode(['ok' => false, 'error' => 'Script no encontrado: ' . $script]);
            return;
        }

        $output = shell_exec("bash " . escapeshellarg($script) . " 2>&1");
        $bin = Settings::get('failover_caddy_l4_bin', '/usr/local/bin/caddy-l4');
        $installed = file_exists($bin);

        LogService::log('failover.caddy_l4', $installed ? 'installed' : 'install_failed',
            'caddy-l4 installation ' . ($installed ? 'completed' : 'failed'));

        echo json_encode([
            'ok'        => $installed,
            'installed' => $installed,
            'output'    => $output,
        ]);
    }

    /**
     * GET /settings/failover/caddy-l4-status  (AJAX)
     * Quick check if caddy-l4 is installed + version.
     */
    public function caddyL4Status(): void
    {
        header('Content-Type: application/json');
        $bin = Settings::get('failover_caddy_l4_bin', '/usr/local/bin/caddy-l4');
        $installed = file_exists($bin);
        $version = '';
        $hasLayer4 = false;

        if ($installed) {
            $version = trim((string)shell_exec(escapeshellarg($bin) . " version 2>/dev/null"));
            $modules = (string)shell_exec(escapeshellarg($bin) . " list-modules 2>/dev/null");
            $hasLayer4 = str_contains($modules, 'layer4');
        }

        echo json_encode([
            'ok'         => true,
            'installed'  => $installed,
            'version'    => $version,
            'has_layer4' => $hasLayer4,
            'bin'        => $bin,
        ]);
    }

    /**
     * GET /settings/failover/test-ifaces  (AJAX)
     * Test local network interfaces and return their status.
     */
    public function testIfaces(): void
    {
        header('Content-Type: application/json');
        $result = FailoverService::checkLocalInterfaces();

        // Also return detected interfaces for reference
        $ifaces = [];
        $ifaceDir = '/sys/class/net';
        if (is_dir($ifaceDir)) {
            foreach (scandir($ifaceDir) as $iface) {
                if ($iface === '.' || $iface === '..' || $iface === 'lo') continue;
                $state = trim(@file_get_contents("{$ifaceDir}/{$iface}/operstate") ?: 'unknown');
                $ipAddr = trim((string)shell_exec("ip -4 addr show " . escapeshellarg($iface) . " 2>/dev/null | grep -oP 'inet \\K[\\d.]+'"));
                $ifaces[$iface] = ['state' => $state, 'ip' => $ipAddr];
            }
        }

        $result['interfaces'] = $ifaces;
        $result['ok'] = true;
        echo json_encode($result);
    }

    /**
     * POST /settings/failover/test-remote-sources  (AJAX JSON)
     * Tests connectivity to remote domain sources (/api/domains endpoints).
     */
    public function testRemoteSources(): void
    {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $sources = $input['sources'] ?? [];
        $results = [];

        foreach ($sources as $src) {
            $name = trim($src['name'] ?? '');
            $url  = rtrim(trim($src['url'] ?? ''), '/');
            $token = trim($src['token'] ?? '');

            if (!$url) {
                $results[] = ['name' => $name, 'ok' => false, 'error' => 'URL vacía', 'count' => 0];
                continue;
            }

            if (!preg_match('#^https?://[^\s/]+#i', $url)) {
                $results[] = ['name' => $name, 'ok' => false, 'error' => 'La URL debe empezar por http:// o https://', 'count' => 0];
                continue;
            }
            $endpoint = $url . '/api/domains';
            $ch = curl_init($endpoint);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => array_filter([
                    'Accept: application/json',
                    $token ? "Authorization: Bearer {$token}" : null,
                ]),
            ];
            $opts = array_replace($opts, TlsClient::forUrl($endpoint));
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                $results[] = ['name' => $name, 'ok' => false, 'error' => "Conexión fallida: {$curlErr}", 'count' => 0];
                continue;
            }
            if ($httpCode !== 200) {
                $results[] = ['name' => $name, 'ok' => false, 'error' => "HTTP {$httpCode}", 'count' => 0];
                continue;
            }

            $data = json_decode($body, true);
            if (!$data || empty($data['ok'])) {
                $results[] = ['name' => $name, 'ok' => false, 'error' => 'Respuesta inválida', 'count' => 0];
                continue;
            }

            $results[] = [
                'name'    => $name,
                'ok'      => true,
                'count'   => (int)($data['count'] ?? 0),
                'server'  => $data['server'] ?? '',
                'updated' => $data['updated'] ?? '',
            ];
        }

        echo json_encode(['ok' => true, 'results' => $results]);
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
