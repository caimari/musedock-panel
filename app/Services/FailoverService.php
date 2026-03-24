<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

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

        // caddy-l4
        'failover_caddy_l4_bin'     => '/usr/local/bin/caddy-l4',
        'failover_caddy_l4_conf'    => '/etc/caddy/caddy-l4.json',
        'failover_caddy_normal_port' => '443',
        'failover_caddy_backup_port' => '8443',
    ];

    // ═══════════════════════════════════════════════════════════
    // ─── Servers (dynamic JSON in panel_settings) ─────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Get all configured failover servers.
     * Stored as JSON array in failover_servers setting.
     * Each server: {id, name, ip, role, port, failover_to, dyndns, enabled}
     *
     * @return array<int, array{id:string, name:string, ip:string, role:string, port:int, failover_to:string, dyndns:bool, enabled:bool}>
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

    /**
     * Check if a host is reachable via /api/health endpoint (deep check).
     * Falls back to TCP connect if the panel port is not available.
     */
    public static function checkHost(string $ip, int $port = 443, int $timeout = 5): array
    {
        $start = microtime(true);
        $panelPort = (int)Settings::get('panel_port', '8444') ?: 8444;

        // Try deep health check via /api/health endpoint first
        $healthUrl = "https://{$ip}:{$panelPort}/api/health";
        $ch = curl_init($healthUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $elapsed = round((microtime(true) - $start) * 1000);

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

        // Fallback
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
                            'routes' => [[
                                'handle' => [['handler' => 'proxy', 'upstreams' => [['dial' => 'localhost:8080']]]],
                            ]],
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
        $raw = Settings::get('failover_remote_domains', '');
        return $raw ? array_filter(array_map('trim', explode("\n", $raw))) : [];
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
