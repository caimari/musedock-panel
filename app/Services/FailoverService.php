<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;
use MuseDockPanel\Security\TlsClient;

/**
 * FailoverService — Multi-ISP failover orchestration for MuseDock Panel.
 *
 * Dynamic server architecture: users create servers with any name/IP/role.
 * Servers are grouped by role:
 *   - primary:  Main servers (VPS, datacenter) — normal DNS target
 *   - failover: Local/backup servers that take over when primary fails
 *   - backup:   Last-resort server (e.g. dynamic IP behind NAT + caddy-l4)
 *
 * Each primary has a "failover_to" mapping to a failover server.
 * If all primary + failover are down, traffic goes to the backup via caddy-l4.
 *
 * States: NORMAL → DEGRADED → PRIMARY_DOWN → EMERGENCY
 */
class FailoverService
{
    // ─── Failover states ─────────────────────────────────────
    public const STATE_NORMAL       = 'normal';        // All primaries up
    public const STATE_DEGRADED     = 'degraded';      // Some primaries down, failover active
    public const STATE_PRIMARY_DOWN = 'primary_down';  // All primaries down, failover servers active
    public const STATE_EMERGENCY    = 'emergency';     // Everything down, backup active (caddy-l4)

    // ─── Server roles ────────────────────────────────────────
    public const ROLE_PRIMARY  = 'primary';
    public const ROLE_FAILOVER = 'failover';
    public const ROLE_BACKUP   = 'backup';
    public const ROLE_REPLICA  = 'replica';   // Passive DB-only replica, never promotes

    // ─── Failover modes ──────────────────────────────────────
    public const MODE_MANUAL   = 'manual';
    public const MODE_SEMIAUTO = 'semiauto';
    public const MODE_AUTO     = 'auto';

    // ─── Default settings (scalar keys in panel_settings) ────
    private static array $defaultSettings = [
        'failover_mode'             => self::MODE_MANUAL,
        'failover_state'            => self::STATE_NORMAL,
        'failover_state_since'      => '',
        'failover_last_action'      => '',
        'failover_last_action_at'   => '',

        // DynDNS for backup server with dynamic IP
        'failover_dyndns_provider'  => '',
        'failover_dyndns_hostname'  => '',
        'failover_dyndns_last_ip'   => '',

        // TTL strategy
        'failover_ttl_normal'       => '300',
        'failover_ttl_alert'        => '60',
        'failover_ttl_failover'     => '60',

        // Health check thresholds
        'failover_check_interval'   => '60',
        'failover_down_threshold'   => '3',
        'failover_up_threshold'     => '5',
        'failover_check_timeout'    => '10',
        'failover_cooldown_minutes' => '15',  // min after failback before allowing re-failover

        // Health severity thresholds (configurable by admin)
        'failover_disk_critical_pct'  => '5',   // <5% free → critical (failover)
        'failover_disk_warning_pct'   => '10',  // <10% free → warning (notify only)
        'failover_load_critical_mult' => '3',   // load > 3x cores → critical
        'failover_load_warning_mult'  => '2',   // load > 2x cores → warning
        'failover_pg_panel_severity'  => 'warning', // pg_panel down = warning (not critical)
        'failover_pg_hosting_severity' => 'critical', // pg_hosting down = critical (failover)
        'failover_mysql_severity'     => 'warning',   // mysql down = warning (not critical)
        'failover_caddy_severity'     => 'critical',  // caddy down = critical (failover)

        // caddy-l4
        'failover_caddy_l4_bin'     => '/usr/local/bin/caddy-l4',
        'failover_caddy_l4_conf'    => '/etc/caddy/caddy-l4.json',
        'failover_caddy_normal_port' => '443',
        'failover_caddy_backup_port' => '8443',

        // Local interface self-check (per-node, NOT synced to slaves)
        // Allows detecting when primary ethernet fails and only NAT/backup remains
        'failover_iface_primary'    => '',      // e.g. "eth0", "enp3s0" — main ethernet iface
        'failover_iface_backup'     => '',      // e.g. "eth1", "wlan0" — backup iface (NAT/DynDNS)
        'failover_iface_primary_ip' => '',      // expected IP on primary iface (for validation)
        'failover_iface_mode'       => 'normal', // normal | nat — current detected state
        'failover_dns_changed_locally' => '0',  // flag: this node changed DNS autonomously (Camino B)
        'failover_dns_changed_at'   => '',      // timestamp of autonomous DNS change
    ];

    // ═══════════════════════════════════════════════════════════
    // ─── Servers (dynamic JSON in panel_settings) ─────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Get all configured failover servers.
     * Stored as JSON array in failover_servers setting.
     * Each server: {id, name, ip, role, port, failover_to, dyndns, enabled, failover_priority}
     *
     * failover_priority: 1 = highest priority (promotes first). Only relevant for 'failover' role.
     * Servers with role 'replica' never promote — they only replicate DB.
     *
     * @return array<int, array{id:string, name:string, ip:string, role:string, port:int, failover_to:string, dyndns:bool, enabled:bool, failover_priority:int}>
     */
    public static function getServers(): array
    {
        $raw = Settings::get('failover_servers', '[]');
        $servers = json_decode($raw, true);
        return is_array($servers) ? $servers : [];
    }

    /**
     * Save servers configuration.
     */
    public static function saveServers(array $servers): void
    {
        // Ensure each server has an id
        foreach ($servers as &$s) {
            if (empty($s['id'])) {
                $s['id'] = substr(md5(uniqid('', true)), 0, 8);
            }
        }
        unset($s);
        Settings::set('failover_servers', json_encode($servers));
    }

    /**
     * Get server by id.
     */
    public static function getServer(string $id): ?array
    {
        foreach (self::getServers() as $s) {
            if ($s['id'] === $id) return $s;
        }
        return null;
    }

    /**
     * Get servers filtered by role.
     */
    public static function getServersByRole(string $role): array
    {
        return array_values(array_filter(self::getServers(), fn($s) => ($s['role'] ?? '') === $role && ($s['enabled'] ?? true)));
    }

    /**
     * Get the failover server that should promote, sorted by priority (1 = highest).
     * Used for election when multiple failover slaves exist.
     *
     * @return array Ordered list of failover servers, highest priority first
     */
    public static function getFailoverServersByPriority(): array
    {
        $servers = self::getServersByRole(self::ROLE_FAILOVER);
        usort($servers, fn($a, $b) => ((int)($a['failover_priority'] ?? 99)) - ((int)($b['failover_priority'] ?? 99)));
        return $servers;
    }

    /**
     * Should this server (by IP) promote given the election rules?
     * Only the highest-priority live failover server should promote.
     *
     * @param string $myIp  This server's IP
     * @param array  $downIps  IPs that are confirmed down
     * @return bool
     */
    public static function shouldPromote(string $myIp, array $downIps): bool
    {
        $candidates = self::getFailoverServersByPriority();

        foreach ($candidates as $srv) {
            $srvIp = $srv['ip'] ?? '';
            if (!$srvIp) continue;

            // Skip servers that are down
            if (in_array($srvIp, $downIps, true)) continue;

            // First alive server in priority order: is it me?
            return $srvIp === $myIp;
        }

        // No candidates alive — shouldn't happen but don't promote
        return false;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Configuration ────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Get scalar failover settings (merges defaults with stored values).
     */
    public static function getConfig(): array
    {
        $config = [];
        foreach (self::$defaultSettings as $key => $default) {
            $config[$key] = Settings::get($key, $default);
        }
        return $config;
    }

    /**
     * Save scalar failover settings.
     */
    public static function saveConfig(array $data): void
    {
        foreach ($data as $key => $value) {
            if (array_key_exists($key, self::$defaultSettings)) {
                Settings::set($key, (string)$value);
            }
        }
    }

    public static function getState(): string
    {
        return Settings::get('failover_state', self::STATE_NORMAL);
    }

    /**
     * Check if failover is configured (at least one primary + one failover server).
     */
    public static function isConfigured(): bool
    {
        $servers = self::getServers();
        $hasPrimary  = !empty(array_filter($servers, fn($s) => ($s['role'] ?? '') === self::ROLE_PRIMARY));
        $hasFailover = !empty(array_filter($servers, fn($s) => ($s['role'] ?? '') === self::ROLE_FAILOVER));
        return $hasPrimary && $hasFailover;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Health Checks ────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════
    // ─── Local Interface Self-Check ─────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Check local network interfaces to detect if primary ethernet is down.
     * Returns the current interface mode: 'normal' or 'nat'.
     *
     * @return array{mode:string, primary_up:bool, backup_up:bool, primary_ip:string, details:string}
     */
    public static function checkLocalInterfaces(): array
    {
        $c = self::getConfig();
        $primaryIface  = trim($c['failover_iface_primary'] ?? '');
        $backupIface   = trim($c['failover_iface_backup'] ?? '');
        $expectedIp    = trim($c['failover_iface_primary_ip'] ?? '');

        if (!$primaryIface) {
            return ['mode' => 'normal', 'primary_up' => true, 'backup_up' => true,
                    'primary_ip' => '', 'details' => 'No primary interface configured'];
        }

        // Check if primary interface is UP and has the expected IP
        $primaryUp = false;
        $primaryIp = '';
        $ifaceState = trim((string)shell_exec("cat /sys/class/net/" . escapeshellarg($primaryIface) . "/operstate 2>/dev/null"));

        if ($ifaceState === 'up') {
            // Get IP from the interface
            $ipOutput = trim((string)shell_exec("ip -4 addr show " . escapeshellarg($primaryIface) . " 2>/dev/null | grep -oP 'inet \\K[\\d.]+'"));
            $primaryIp = $ipOutput;

            if ($expectedIp) {
                $primaryUp = ($primaryIp === $expectedIp);
            } else {
                $primaryUp = !empty($primaryIp);
            }
        }

        // Check backup interface if configured
        $backupUp = true;
        if ($backupIface) {
            $backupState = trim((string)shell_exec("cat /sys/class/net/" . escapeshellarg($backupIface) . "/operstate 2>/dev/null"));
            $backupUp = ($backupState === 'up');
        }

        $mode = $primaryUp ? 'normal' : ($backupUp ? 'nat' : 'isolated');

        return [
            'mode'       => $mode,
            'primary_up' => $primaryUp,
            'backup_up'  => $backupUp,
            'primary_ip' => $primaryIp,
            'primary_iface' => $primaryIface,
            'backup_iface'  => $backupIface,
            'details'    => match ($mode) {
                'normal'   => "Primary iface {$primaryIface} UP ({$primaryIp})",
                'nat'      => "Primary iface {$primaryIface} DOWN — using backup {$backupIface} (NAT mode)",
                'isolated' => "ALL interfaces DOWN — server isolated",
            },
        ];
    }

    /**
     * Handle interface failover: called when primary iface is detected down.
     * Camino A: notify master if reachable.
     * Camino B: act autonomously if master is unreachable.
     *
     * @return array{ok:bool, path:string, actions:array}
     */
    public static function handleIfaceFailover(): array
    {
        $c = self::getConfig();
        $actions = [];
        $path = 'unknown';

        // Try to reach the master (Camino A)
        $masterReachable = false;
        $masterIp = Settings::get('cluster_master_ip', '');

        if ($masterIp) {
            $panelPort = (int)Settings::get('panel_port', '8444') ?: 8444;
            $ch = curl_init("https://{$masterIp}:{$panelPort}/api/health");
            $headers = ['Accept: application/json'];
            $masterToken = self::resolveApiTokenForIp($masterIp);
            if ($masterToken !== '') {
                $headers[] = 'Authorization: Bearer ' . $masterToken;
            }
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => $headers,
            ];
            $opts = array_replace($opts, TlsClient::forUrl("https://{$masterIp}:{$panelPort}/api/health"));
            curl_setopt_array($ch, $opts);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $masterReachable = ($code >= 200 && $code < 500);
        }

        if ($masterReachable) {
            // ── Camino A: Master alive → notify it to handle DNS ──
            $path = 'A';
            $myIp = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));

            try {
                $nodes = ClusterService::getActiveNodes();
                foreach ($nodes as $node) {
                    $nodeUrl = $node['api_url'] ?? '';
                    if (str_contains($nodeUrl, $masterIp)) {
                        $result = ClusterService::callNode((int)$node['id'], 'POST', 'api/cluster/action', [
                            'action'  => 'notify-iface-down',
                            'payload' => [
                                'slave_ip'  => $myIp,
                                'mode'      => 'nat',
                                'dyndns_ip' => self::resolveDynDns(),
                            ],
                        ]);
                        $actions[] = "Notificado a master: " . ($result['ok'] ? 'OK' : ($result['error'] ?? 'error'));
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $actions[] = "Error notificando a master: " . $e->getMessage();
                // Fall through to Camino B
                $masterReachable = false;
            }
        }

        if (!$masterReachable) {
            // ── Camino B: Master unreachable → act autonomously ──
            $path = 'B';

            // 1. Activate caddy-l4 locally
            $l4Result = self::activateCaddyL4($c);
            $actions = array_merge($actions, $l4Result);

            // 2. Switch Caddy to backup port
            $portResult = self::switchCaddyPort($c);
            $actions = array_merge($actions, $portResult);

            // 3. Change DNS directly via Cloudflare (we have synced credentials)
            $backupIp = self::resolveDynDns();
            if (!$backupIp) {
                $backupServers = self::getServersByRole(self::ROLE_BACKUP);
                $backupIp = !empty($backupServers) ? ($backupServers[0]['ip'] ?? '') : '';
            }

            if ($backupIp) {
                $accounts = CloudflareService::getConfiguredAccounts();
                $ttl = (int)($c['failover_ttl_failover'] ?: 60);

                // Get all IPs that should be redirected to backup
                $sourceIps = [];
                foreach (self::getServers() as $srv) {
                    if (($srv['role'] ?? '') === self::ROLE_BACKUP) continue;
                    if ($srv['ip'] && !in_array($srv['ip'], $sourceIps)) {
                        $sourceIps[] = $srv['ip'];
                    }
                }

                foreach ($sourceIps as $srcIp) {
                    foreach ($accounts as $acct) {
                        foreach ($acct['zones'] ?? [] as $zone) {
                            $r = CloudflareService::batchUpdateIp($acct['token'], $zone['id'], $srcIp, $backupIp, $ttl);
                            if ($r['updated'] > 0) {
                                $actions[] = "DNS {$srcIp}→{$backupIp}: zone {$zone['name']} — {$r['updated']} records";
                            }
                        }
                    }
                }

                Settings::set('failover_state', self::STATE_EMERGENCY);
                Settings::set('failover_state_since', date('Y-m-d H:i:s'));
                $actions[] = "Estado cambiado a EMERGENCY (actuación autónoma)";

                // Flag: this node changed DNS autonomously — master must check before acting
                Settings::set('failover_dns_changed_locally', '1');
                Settings::set('failover_dns_changed_at', date('Y-m-d H:i:s'));
            } else {
                $actions[] = "ERROR: No hay IP de backup disponible (sin DynDNS ni backup estático)";
            }
        }

        // Update local interface mode
        Settings::set('failover_iface_mode', 'nat');

        LogService::log('failover.iface', $path === 'A' ? 'notify-master' : 'autonomous',
            "Interface failover (Camino {$path}): " . implode('; ', $actions));

        NotificationService::send(
            "Failover: Interfaz principal caída",
            "La interfaz principal ha caído. Modo: NAT.\n" .
            "Camino: {$path}\n" .
            "Acciones: " . implode("\n", $actions)
        );

        return ['ok' => true, 'path' => $path, 'actions' => $actions];
    }

    /**
     * Handle interface recovery: primary interface came back up.
     */
    public static function handleIfaceRecovery(): array
    {
        $c = self::getConfig();
        $actions = [];

        // Notify master if reachable
        $masterIp = Settings::get('cluster_master_ip', '');
        if ($masterIp) {
            try {
                $myIp = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));
                $nodes = ClusterService::getActiveNodes();
                foreach ($nodes as $node) {
                    if (str_contains($node['api_url'] ?? '', $masterIp)) {
                        ClusterService::callNode((int)$node['id'], 'POST', 'api/cluster/action', [
                            'action'  => 'notify-iface-up',
                            'payload' => ['slave_ip' => $myIp, 'mode' => 'normal'],
                        ]);
                        $actions[] = "Master notificado: interfaz recuperada";
                        break;
                    }
                }
            } catch (\Throwable) {
                $actions[] = "No se pudo notificar al master (se revertirá por health checks)";
            }
        }

        // Deactivate caddy-l4 if it was activated autonomously
        $l4Running = trim((string)shell_exec('systemctl is-active caddy-l4 2>/dev/null')) === 'active';
        if ($l4Running) {
            $actions = array_merge($actions, self::deactivateCaddyL4());
            $actions = array_merge($actions, self::restoreCaddyPort($c));
        }

        Settings::set('failover_iface_mode', 'normal');
        Settings::set('failover_dns_changed_locally', '0');
        Settings::set('failover_dns_changed_at', '');
        LogService::log('failover.iface', 'recovery', "Interface recovered: " . implode('; ', $actions));

        return ['ok' => true, 'actions' => $actions];
    }

    /**
     * Check if a host is reachable via /api/health endpoint (deep check).
     * Falls back to TCP connect if the panel port is not available.
     */
    public static function checkHost(string $ip, int $port = 443, int $timeout = 5): array
    {
        $start = microtime(true);
        $panelPort = (int)Settings::get('panel_port', '8444') ?: 8444;
        $apiToken = self::resolveApiTokenForIp($ip);

        // Try deep health check via /api/health endpoint first
        $healthUrl = "https://{$ip}:{$panelPort}/api/health";
        $ch = curl_init($healthUrl);
        $headers = ['Accept: application/json'];
        if ($apiToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiToken;
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ];
        $opts = array_replace($opts, TlsClient::forUrl($healthUrl));
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $elapsed = round((microtime(true) - $start) * 1000);

        // Auth failed or endpoint hidden: treat as "no deep health available"
        // and fall back to TCP reachability instead of counting as CRITICAL.
        if (in_array($httpCode, [401, 403], true)) {
            $response = null;
        }

        if ($response && $httpCode > 0) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                return [
                    'ok' => !empty($data['ok']),
                    'severity' => $data['severity'] ?? ($data['ok'] ? 'ok' : 'critical'),
                    'status' => $data['status'] ?? 'unknown',
                    'ms' => $elapsed,
                    'ip' => $ip,
                    'http_code' => $httpCode,
                    'checks' => $data['checks'] ?? [],
                    'role' => $data['role'] ?? '',
                    'version' => $data['version'] ?? '',
                    'method' => 'health_endpoint',
                ];
            }
        }

        // Fallback: TCP connect to the service port (443)
        $start2 = microtime(true);
        $conn = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        $elapsed2 = round((microtime(true) - $start2) * 1000);

        if ($conn) {
            fclose($conn);
            return ['ok' => true, 'severity' => 'ok', 'ms' => $elapsed2, 'ip' => $ip, 'method' => 'tcp_fallback'];
        }
        // No response at all = critical (server completely unreachable)
        return [
            'ok' => false,
            'severity' => 'critical',
            'ms' => $elapsed + $elapsed2,
            'ip' => $ip,
            'error' => $curlError ?: ($errstr ?: 'Connection refused'),
            'method' => 'tcp_fallback',
        ];
    }

    /**
     * Resolve a Bearer token for a peer IP using known cluster node definitions.
     * Returns empty string when no token can be resolved.
     */
    private static function resolveApiTokenForIp(string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }

        try {
            $nodes = Database::fetchAll('SELECT api_url, auth_token FROM cluster_nodes');
            foreach ($nodes as $node) {
                $host = parse_url((string)($node['api_url'] ?? ''), PHP_URL_HOST);
                if (!$host) {
                    continue;
                }

                if ($host === $ip || gethostbyname($host) === $ip) {
                    return ReplicationService::decryptPassword((string)($node['auth_token'] ?? ''));
                }
            }

            $sources = json_decode(Settings::get('failover_remote_domain_sources', '[]'), true) ?: [];
            foreach ($sources as $source) {
                $urlHost = parse_url((string)($source['url'] ?? ''), PHP_URL_HOST);
                $token = trim((string)($source['token'] ?? ''));
                if ($urlHost === '' || $token === '') {
                    continue;
                }

                if ($urlHost === $ip || gethostbyname($urlHost) === $ip) {
                    return $token;
                }
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    /**
     * Check all configured servers.
     * @return array keyed by server id
     */
    public static function checkAllEndpoints(): array
    {
        $c = self::getConfig();
        $timeout = (int)($c['failover_check_timeout'] ?: 10);
        $results = [];

        foreach (self::getServers() as $server) {
            if (empty($server['enabled'] ?? true)) continue;

            $ip = $server['ip'] ?? '';
            // For backup servers with dyndns, resolve first
            if (($server['dyndns'] ?? false) && ($server['role'] ?? '') === self::ROLE_BACKUP) {
                $ip = self::resolveDynDns() ?: $ip;
            }
            if (!$ip) continue;

            $port = (int)($server['port'] ?? 443) ?: 443;
            $result = self::checkHost($ip, $port, $timeout);
            $result['name'] = $server['name'] ?? '';
            $result['role'] = $server['role'] ?? '';
            $result['server_id'] = $server['id'] ?? '';
            $results[$server['id']] = $result;
        }

        return $results;
    }

    /**
     * Resolve dynamic DNS hostname to IP.
     */
    public static function resolveDynDns(): string
    {
        $hostname = Settings::get('failover_dyndns_hostname', '');
        if (!$hostname) return '';

        $resolved = gethostbyname($hostname);
        if ($resolved !== $hostname) {
            Settings::set('failover_dyndns_last_ip', $resolved);
            return $resolved;
        }
        return Settings::get('failover_dyndns_last_ip', '');
    }

    // ═══════════════════════════════════════════════════════════
    // ─── State Machine ────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Evaluate what state we SHOULD be in based on health checks.
     */
    public static function evaluateState(array $checks): string
    {
        $servers = self::getServers();
        $primaries = array_filter($servers, fn($s) => ($s['role'] ?? '') === self::ROLE_PRIMARY && ($s['enabled'] ?? true));
        $failovers = array_filter($servers, fn($s) => ($s['role'] ?? '') === self::ROLE_FAILOVER && ($s['enabled'] ?? true));

        if (empty($primaries)) return self::STATE_NORMAL;

        $primaryUp = 0;
        $primaryTotal = 0;
        foreach ($primaries as $s) {
            $primaryTotal++;
            if (!empty($checks[$s['id']]['ok'])) $primaryUp++;
        }

        // All primaries up → normal
        if ($primaryUp === $primaryTotal) return self::STATE_NORMAL;

        // Some primaries up → degraded
        if ($primaryUp > 0) return self::STATE_DEGRADED;

        // All primaries down — check failovers
        $failoverUp = 0;
        foreach ($failovers as $s) {
            if (!empty($checks[$s['id']]['ok'])) $failoverUp++;
        }

        if ($failoverUp > 0) return self::STATE_PRIMARY_DOWN;

        // Everything down
        return self::STATE_EMERGENCY;
    }

    /**
     * Transition to a new failover state.
     */
    public static function transitionTo(string $newState, string $trigger = 'manual'): array
    {
        $currentState = self::getState();
        $c = self::getConfig();
        $log = [];

        if ($newState === $currentState) {
            return ['ok' => true, 'message' => 'Already in state: ' . $newState, 'actions' => []];
        }

        $log[] = "Transition: {$currentState} → {$newState} (trigger: {$trigger})";

        switch ($newState) {
            case self::STATE_NORMAL:
                $log = array_merge($log, self::actionFailback($c));
                break;
            case self::STATE_DEGRADED:
                $log = array_merge($log, self::actionDegraded($c));
                break;
            case self::STATE_PRIMARY_DOWN:
                $log = array_merge($log, self::actionPrimaryDown($c));
                break;
            case self::STATE_EMERGENCY:
                $log = array_merge($log, self::actionEmergency($c));
                break;
        }

        Settings::set('failover_state', $newState);
        Settings::set('failover_state_since', date('Y-m-d H:i:s'));
        Settings::set('failover_last_action', $trigger);
        Settings::set('failover_last_action_at', date('Y-m-d H:i:s'));

        LogService::log('failover.transition', $newState, implode("\n", $log));

        $stateLabel = self::stateLabel($newState);
        NotificationService::send(
            "Failover: {$stateLabel}",
            "Estado: {$stateLabel}\nTrigger: {$trigger}\n\n" . implode("\n", $log)
        );

        return ['ok' => true, 'state' => $newState, 'actions' => $log];
    }

    // ─── Failover actions ────────────────────────────────────

    /**
     * Failback to normal: restore DNS to primary IPs, stop caddy-l4.
     */
    private static function actionFailback(array $c): array
    {
        $log = [];
        $servers = self::getServers();
        $accounts = CloudflareService::getConfiguredAccounts();
        $ttl = (int)($c['failover_ttl_normal'] ?: 300);

        // For each primary server, find what its failover IP was and revert
        foreach ($servers as $srv) {
            if ($srv['role'] !== self::ROLE_PRIMARY) continue;
            $failoverSrv = self::getServer($srv['failover_to'] ?? '');
            if (!$failoverSrv) continue;

            foreach ($accounts as $acct) {
                foreach ($acct['zones'] ?? [] as $zone) {
                    $r = CloudflareService::batchUpdateIp($acct['token'], $zone['id'], $failoverSrv['ip'], $srv['ip'], $ttl);
                    if ($r['updated'] > 0) $log[] = "DNS restore {$failoverSrv['name']}→{$srv['name']}: {$r['updated']} records";
                }
            }
        }

        // Revert backup IP records
        $backupIp = Settings::get('failover_dyndns_last_ip', '');
        if ($backupIp) {
            $firstPrimary = self::getServersByRole(self::ROLE_PRIMARY)[0] ?? null;
            if ($firstPrimary) {
                foreach ($accounts as $acct) {
                    foreach ($acct['zones'] ?? [] as $zone) {
                        $r = CloudflareService::batchUpdateIp($acct['token'], $zone['id'], $backupIp, $firstPrimary['ip'], $ttl);
                        if ($r['updated'] > 0) $log[] = "DNS restore backup→{$firstPrimary['name']}: {$r['updated']} records";
                    }
                }
            }
        }

        $log = array_merge($log, self::deactivateCaddyL4());
        $log = array_merge($log, self::restoreCaddyPort($c));

        return $log;
    }

    /**
     * Degraded: some primaries down → redirect their DNS to failover servers.
     */
    private static function actionDegraded(array $c): array
    {
        $log = [];
        $checks = self::checkAllEndpoints();
        $accounts = CloudflareService::getConfiguredAccounts();
        $ttl = (int)($c['failover_ttl_failover'] ?: 60);

        foreach (self::getServers() as $srv) {
            if ($srv['role'] !== self::ROLE_PRIMARY) continue;
            if (!empty($checks[$srv['id']]['ok'])) continue; // this primary is up, skip

            $failoverSrv = self::getServer($srv['failover_to'] ?? '');
            if (!$failoverSrv) continue;

            foreach ($accounts as $acct) {
                foreach ($acct['zones'] ?? [] as $zone) {
                    $r = CloudflareService::batchUpdateIp($acct['token'], $zone['id'], $srv['ip'], $failoverSrv['ip'], $ttl);
                    $log[] = "DNS {$srv['name']}→{$failoverSrv['name']}: zone {$zone['name']} — {$r['updated']} updated, {$r['failed']} failed";
                }
            }
        }

        $log = array_merge($log, self::lowerTtl($c));
        return $log;
    }

    /**
     * All primaries down → all DNS to failover servers.
     */
    private static function actionPrimaryDown(array $c): array
    {
        $log = [];
        $accounts = CloudflareService::getConfiguredAccounts();
        $ttl = (int)($c['failover_ttl_failover'] ?: 60);

        foreach (self::getServers() as $srv) {
            if ($srv['role'] !== self::ROLE_PRIMARY) continue;
            $failoverSrv = self::getServer($srv['failover_to'] ?? '');
            if (!$failoverSrv) continue;

            foreach ($accounts as $acct) {
                foreach ($acct['zones'] ?? [] as $zone) {
                    $r = CloudflareService::batchUpdateIp($acct['token'], $zone['id'], $srv['ip'], $failoverSrv['ip'], $ttl);
                    $log[] = "DNS {$srv['name']}→{$failoverSrv['name']}: zone {$zone['name']} — {$r['updated']} updated";
                }
            }
        }

        return $log;
    }

    /**
     * Emergency: everything down → DNS to backup, activate caddy-l4.
     */
    private static function actionEmergency(array $c): array
    {
        $log = [];
        $backupIp = self::resolveDynDns();
        $backupServers = self::getServersByRole(self::ROLE_BACKUP);

        if (!$backupIp && !empty($backupServers)) {
            $backupIp = $backupServers[0]['ip'] ?? '';
        }
        if (!$backupIp) {
            $log[] = "ERROR: No backup IP available (no DynDNS or static backup)";
            return $log;
        }
        $log[] = "Backup IP: {$backupIp}";

        // Activate caddy-l4
        $log = array_merge($log, self::activateCaddyL4($c));

        // Switch Caddy to backup port
        $log = array_merge($log, self::switchCaddyPort($c));

        // DNS: all primary + failover IPs → backup IP
        $accounts = CloudflareService::getConfiguredAccounts();
        $ttl = (int)($c['failover_ttl_failover'] ?: 60);
        $allSourceIps = [];

        foreach (self::getServers() as $srv) {
            if ($srv['role'] === self::ROLE_BACKUP) continue;
            if ($srv['ip'] && !in_array($srv['ip'], $allSourceIps)) {
                $allSourceIps[] = $srv['ip'];
            }
        }

        foreach ($allSourceIps as $srcIp) {
            foreach ($accounts as $acct) {
                foreach ($acct['zones'] ?? [] as $zone) {
                    $r = CloudflareService::batchUpdateIp($acct['token'], $zone['id'], $srcIp, $backupIp, $ttl);
                    if ($r['updated'] > 0) $log[] = "DNS {$srcIp}→backup: zone {$zone['name']} — {$r['updated']} records";
                }
            }
        }

        return $log;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── caddy-l4 Management ──────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Generate caddy-l4 JSON config for SNI-based TCP proxy.
     */
    public static function generateCaddyL4Config(array $c): array
    {
        $localDomains = self::getLocalDomains();
        $remoteDomains = self::getRemoteDomains();
        $backupPort = $c['failover_caddy_backup_port'] ?: '8443';

        // Find the second failover server for remote routing
        $failovers = self::getServersByRole(self::ROLE_FAILOVER);
        $remoteIp = count($failovers) > 1 ? ($failovers[1]['ip'] ?? '') : '';

        $routes = [];

        // ── 1. Permanent proxy routes (always active, highest priority) ──
        $proxyRoutes = ProxyRouteService::getCaddyL4Routes();
        foreach ($proxyRoutes as $pr) {
            $routes[] = $pr;
        }

        // ── 2. Emergency failover routes (active only during failover) ──
        if (!empty($localDomains)) {
            $routes[] = [
                'match' => [['tls' => ['sni' => $localDomains]]],
                'handle' => [['handler' => 'proxy', 'upstreams' => [['dial' => "localhost:{$backupPort}"]]]],
            ];
        }

        if (!empty($remoteDomains) && $remoteIp) {
            $routes[] = [
                'match' => [['tls' => ['sni' => $remoteDomains]]],
                'handle' => [['handler' => 'proxy', 'upstreams' => [['dial' => "{$remoteIp}:443"]]]],
            ];
        }

        // ── 3. Fallback ──
        $routes[] = [
            'handle' => [['handler' => 'proxy', 'upstreams' => [['dial' => "localhost:{$backupPort}"]]]],
        ];

        return [
            'apps' => [
                'layer4' => [
                    'servers' => [
                        'sni_proxy' => [
                            'listen' => [':443'],
                            'routes' => $routes,
                        ],
                        'http_redirect' => [
                            'listen' => [':80'],
                            'routes' => array_merge(
                                // Proxy routes port 80: Let's Encrypt HTTP-01 + plain HTTP
                                ProxyRouteService::getCaddyL4HttpRoutes(),
                                // Fallback: local HTTP
                                [['handle' => [['handler' => 'proxy', 'upstreams' => [['dial' => 'localhost:8080']]]]]]
                            ),
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function activateCaddyL4(array $c): array
    {
        $log = [];
        $confPath = $c['failover_caddy_l4_conf'] ?: '/etc/caddy/caddy-l4.json';

        $config = self::generateCaddyL4Config($c);
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($confPath, $json) === false) {
            $log[] = "ERROR: Failed to write caddy-l4 config to {$confPath}";
            return $log;
        }
        $log[] = "caddy-l4 config written to {$confPath}";

        $isRunning = trim(shell_exec('systemctl is-active caddy-l4 2>/dev/null') ?? '') === 'active';
        if ($isRunning) {
            exec('systemctl reload caddy-l4 2>&1', $out, $code);
            $log[] = $code === 0 ? "caddy-l4 reloaded" : "ERROR reloading caddy-l4: " . implode(' ', $out);
        } else {
            exec('systemctl start caddy-l4 2>&1', $out, $code);
            $log[] = $code === 0 ? "caddy-l4 started" : "ERROR starting caddy-l4: " . implode(' ', $out);
        }

        return $log;
    }

    private static function deactivateCaddyL4(): array
    {
        $log = [];
        $isRunning = trim(shell_exec('systemctl is-active caddy-l4 2>/dev/null') ?? '') === 'active';
        if ($isRunning) {
            exec('systemctl stop caddy-l4 2>&1', $out, $code);
            $log[] = $code === 0 ? "caddy-l4 stopped" : "ERROR stopping caddy-l4: " . implode(' ', $out);
        }
        return $log;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Caddy port switching ─────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    private static function switchCaddyPort(array $c): array
    {
        $log = [];
        $backupPort = $c['failover_caddy_backup_port'] ?: '8443';
        file_put_contents('/etc/caddy/failover-port-override', $backupPort);
        exec("systemctl reload caddy 2>&1", $out, $code);
        $log[] = $code === 0 ? "Caddy → port {$backupPort}" : "WARNING: Caddy reload: " . implode(' ', $out);
        return $log;
    }

    private static function restoreCaddyPort(array $c): array
    {
        $log = [];
        if (file_exists('/etc/caddy/failover-port-override')) {
            unlink('/etc/caddy/failover-port-override');
            exec("systemctl reload caddy 2>&1", $out, $code);
            $log[] = $code === 0 ? "Caddy restored to :443" : "WARNING: Caddy reload: " . implode(' ', $out);
        }
        return $log;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── TTL Management ───────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    private static function lowerTtl(array $c): array
    {
        $log = [];
        $ttl = (int)($c['failover_ttl_alert'] ?: 60);
        $accounts = CloudflareService::getConfiguredAccounts();

        foreach (self::getServersByRole(self::ROLE_PRIMARY) as $srv) {
            if (!$srv['ip']) continue;
            foreach ($accounts as $acct) {
                foreach ($acct['zones'] ?? [] as $zone) {
                    $r = CloudflareService::batchUpdateTtl($acct['token'], $zone['id'], $srv['ip'], $ttl);
                    if (($r['updated'] ?? 0) > 0) $log[] = "TTL→{$ttl}s for {$srv['name']}: {$r['updated']} records";
                }
            }
        }

        return $log;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Domain Inventory ─────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function getLocalDomains(): array
    {
        try {
            $accounts = Database::fetchAll("SELECT domain FROM hosting_accounts WHERE status = 'active' ORDER BY domain");
            $domains = array_column($accounts, 'domain');
            $extras = Database::fetchAll(
                "SELECT d.domain FROM hosting_domains d JOIN hosting_accounts a ON a.id = d.account_id WHERE a.status = 'active'"
            );
            return array_unique(array_merge($domains, array_column($extras, 'domain')));
        } catch (\Throwable) {
            return [];
        }
    }

    public static function getRemoteDomains(): array
    {
        $domains = [];

        // 1. Try fetching from remote servers via /api/domains
        $remoteSources = self::getRemoteDomainSources();
        foreach ($remoteSources as $source) {
            $url = rtrim($source['url'] ?? '', '/') . '/api/domains';
            $token = $source['token'] ?? '';
            if (!$url || !$token) continue;

            $fetched = self::fetchRemoteDomains($url, $token);
            if (!empty($fetched)) {
                $domains = array_merge($domains, $fetched);
            }
        }

        // 2. Merge with manual textarea (always, as fallback/supplement)
        $raw = Settings::get('failover_remote_domains', '');
        if ($raw) {
            $manual = array_filter(array_map('trim', explode("\n", $raw)));
            $domains = array_merge($domains, $manual);
        }

        return array_values(array_unique($domains));
    }

    /**
     * Fetch domain list from a remote server's /api/domains endpoint.
     */
    public static function fetchRemoteDomains(string $url, string $token): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ];
        $opts = array_replace($opts, TlsClient::forUrl($url));
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (is_array($data) && ($data['ok'] ?? false) && !empty($data['domains'])) {
                // Cache the result for when the remote server is unreachable
                Settings::set('failover_remote_domains_cache_' . md5($url), json_encode($data['domains']));
                Settings::set('failover_remote_domains_cache_at_' . md5($url), date('Y-m-d H:i:s'));
                return $data['domains'];
            }
        }

        // Fallback: use cached domains if remote is unreachable
        $cached = Settings::get('failover_remote_domains_cache_' . md5($url), '');
        if ($cached) {
            $cachedDomains = json_decode($cached, true);
            return is_array($cachedDomains) ? $cachedDomains : [];
        }

        return [];
    }

    /**
     * Get configured remote domain sources (servers that expose /api/domains).
     * Stored as JSON array: [{url, token, name}]
     */
    public static function getRemoteDomainSources(): array
    {
        $raw = Settings::get('failover_remote_domain_sources', '[]');
        $sources = json_decode($raw, true);
        return is_array($sources) ? $sources : [];
    }

    public static function saveRemoteDomainSources(array $sources): void
    {
        Settings::set('failover_remote_domain_sources', json_encode($sources));
    }

    public static function getDomainsNotInCloudflare(): array
    {
        $allDomains = self::getLocalDomains();
        $cfDomains = [];

        foreach (CloudflareService::getConfiguredAccounts() as $acct) {
            foreach ($acct['zones'] ?? [] as $zone) {
                $records = CloudflareService::getAllDomainRecords($acct['token'], $zone['id']);
                if ($records['ok']) {
                    foreach ($records['result'] ?? [] as $r) $cfDomains[] = $r['name'];
                }
            }
        }
        $cfDomains = array_unique($cfDomains);

        return array_filter($allDomains, function ($domain) use ($cfDomains) {
            foreach ($cfDomains as $cf) {
                if ($domain === $cf || str_ends_with($domain, '.' . $cf)) return false;
            }
            return true;
        });
    }

    // ═══════════════════════════════════════════════════════════
    // ─── History & Status ─────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function getHistory(int $limit = 20): array
    {
        try {
            return Database::fetchAll(
                "SELECT * FROM panel_log WHERE action LIKE 'failover.%' ORDER BY created_at DESC LIMIT :limit",
                ['limit' => $limit]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    public static function getStatusSummary(): array
    {
        $c = self::getConfig();
        $state = self::getState();
        $servers = self::getServers();

        return [
            'configured'     => self::isConfigured(),
            'state'          => $state,
            'state_label'    => self::stateLabel($state),
            'state_since'    => $c['failover_state_since'],
            'mode'           => $c['failover_mode'],
            'last_action'    => $c['failover_last_action'],
            'last_action_at' => $c['failover_last_action_at'],
            'servers'        => $servers,
            'dyndns'         => $c['failover_dyndns_hostname'],
            'dyndns_ip'      => $c['failover_dyndns_last_ip'],
            'caddy_l4_installed' => file_exists($c['failover_caddy_l4_bin']),
        ];
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_NORMAL       => 'Normal',
            self::STATE_DEGRADED     => 'Degradado — Failover parcial',
            self::STATE_PRIMARY_DOWN => 'Primarios caídos — Failover activo',
            self::STATE_EMERGENCY    => 'Emergencia — Backup activo',
            default                  => 'Desconocido',
        };
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Config Sync (Master → Slaves) ───────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Keys that are "config" (set by admin, synced to slaves).
     * Excludes runtime state: failover_state, failover_state_since,
     * failover_last_action, failover_last_action_at, failover_dyndns_last_ip.
     */
    public static function getSyncableConfigKeys(): array
    {
        return [
            'failover_mode',
            'failover_dyndns_provider', 'failover_dyndns_hostname',
            'failover_ttl_normal', 'failover_ttl_alert', 'failover_ttl_failover',
            'failover_check_interval', 'failover_down_threshold',
            'failover_up_threshold', 'failover_check_timeout',
            'failover_cooldown_minutes',
            'failover_caddy_l4_bin', 'failover_caddy_l4_conf',
            'failover_caddy_normal_port', 'failover_caddy_backup_port',
            'failover_disk_critical_pct', 'failover_disk_warning_pct',
            'failover_load_critical_mult', 'failover_load_warning_mult',
            'failover_pg_panel_severity', 'failover_pg_hosting_severity',
            'failover_mysql_severity', 'failover_caddy_severity',
        ];
    }

    /**
     * Push full failover config to all slave nodes.
     * Called after any config save on the master.
     * If a slave is unreachable, the action is enqueued with 3 retries.
     */
    public static function pushConfigToSlaves(bool $updateCaddyToken = false): array
    {
        $clusterRole = Settings::get('cluster_role', '');
        if ($clusterRole !== 'master') {
            return ['ok' => false, 'error' => 'Only master can push config'];
        }

        // Build the payload
        $configKeys = self::getSyncableConfigKeys();
        $config = [];
        foreach ($configKeys as $key) {
            $val = Settings::get($key, '');
            if ($val !== '') {
                $config[$key] = $val;
            }
        }

        $payload = [
            'config'            => $config,
            'servers'           => self::getServers(),
            'cf_accounts'       => CloudflareService::getConfiguredAccounts(),
            'remote_domains'    => Settings::get('failover_remote_domains', ''),
            'update_caddy_token' => $updateCaddyToken,
        ];

        // Push to all active cluster nodes
        $nodes = ClusterService::getActiveNodes();
        $results = [];

        foreach ($nodes as $node) {
            $nodeId = (int)($node['id'] ?? 0);
            if (!$nodeId) continue;

            // Try direct push first
            try {
                $response = ClusterService::callNode($nodeId, 'POST', 'api/cluster/action', [
                    'action'  => 'sync-failover-config',
                    'payload' => $payload,
                ]);

                if ($response['ok'] ?? false) {
                    $results[] = ['node' => $node['name'], 'ok' => true, 'method' => 'direct'];
                    LogService::log('failover.sync', 'push', "Config pushed to {$node['name']}");
                    continue;
                }
            } catch (\Throwable $e) {
                // Direct push failed, fall through to enqueue
            }

            // Direct push failed — enqueue for retry
            ClusterService::enqueue($nodeId, 'sync-failover-config', $payload, 3);
            $results[] = ['node' => $node['name'], 'ok' => false, 'method' => 'queued'];
            LogService::log('failover.sync', 'queued', "Config push queued for {$node['name']} (unreachable)");
        }

        return ['ok' => true, 'results' => $results];
    }

    /**
     * Pull failover config from master (called on slave boot/reconnect).
     * Returns true if config was successfully pulled and applied.
     */
    public static function pullConfigFromMaster(): bool
    {
        $clusterRole = Settings::get('cluster_role', '');
        if ($clusterRole !== 'slave') {
            return false;
        }

        $masterIp = Settings::get('cluster_master_ip', '');
        if (!$masterIp) return false;

        // Find master node in cluster_nodes
        $nodes = ClusterService::getNodes();
        $masterNode = null;
        foreach ($nodes as $node) {
            if (($node['role'] ?? '') === 'master' || str_contains($node['api_url'] ?? '', $masterIp)) {
                $masterNode = $node;
                break;
            }
        }

        // If no node found, try direct call with local token
        $panelPort = (int)(Settings::get('panel_port', '8444') ?: 8444);
        $localToken = Settings::get('cluster_local_token', '');

        if ($masterNode) {
            try {
                $response = ClusterService::callNode((int)$masterNode['id'], 'POST', 'api/cluster/action', [
                    'action' => 'pull-failover-config',
                    'payload' => [],
                ]);
            } catch (\Throwable $e) {
                LogService::log('failover.sync', 'pull-failed', "Cannot reach master: {$e->getMessage()}");
                return false;
            }
        } elseif ($localToken && $masterIp) {
            try {
                $response = ClusterService::callNodeDirect(
                    "https://{$masterIp}:{$panelPort}",
                    $localToken,
                    'POST',
                    'api/cluster/action',
                    ['action' => 'pull-failover-config', 'payload' => []]
                );
            } catch (\Throwable $e) {
                LogService::log('failover.sync', 'pull-failed', "Cannot reach master (direct): {$e->getMessage()}");
                return false;
            }
        } else {
            return false;
        }

        if (empty($response['ok'])) return false;

        // Apply received config
        $configKeys = self::getSyncableConfigKeys();
        foreach (($response['config'] ?? []) as $key => $value) {
            if (in_array($key, $configKeys, true)) {
                Settings::set($key, (string)$value);
            }
        }

        if (isset($response['servers']) && is_array($response['servers'])) {
            Settings::set('failover_servers', json_encode($response['servers']));
        }

        if (isset($response['cf_accounts']) && is_array($response['cf_accounts'])) {
            Settings::set('failover_cf_accounts', json_encode($response['cf_accounts']));
        }

        if (isset($response['remote_domains'])) {
            Settings::set('failover_remote_domains', (string)$response['remote_domains']);
        }

        Settings::set('failover_config_synced_at', date('Y-m-d H:i:s'));
        LogService::log('failover.sync', 'pull-ok', 'Failover config pulled from master');
        return true;
    }

    public static function stateBadgeClass(string $state): string
    {
        return match ($state) {
            self::STATE_NORMAL       => 'bg-success',
            self::STATE_DEGRADED     => 'bg-warning text-dark',
            self::STATE_PRIMARY_DOWN => 'bg-danger',
            self::STATE_EMERGENCY    => 'bg-danger',
            default                  => 'bg-secondary',
        };
    }
}
