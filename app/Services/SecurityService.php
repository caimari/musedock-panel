<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

class SecurityService
{
    private const SSHD_CONFIG = '/etc/ssh/sshd_config';
    private const HARDENING_SYSCTL_FILE = '/etc/sysctl.d/99-musedock-hardening.conf';

    public static function getHardeningAudit(): array
    {
        $checks = [];

        $sshdContent = @file_get_contents(self::SSHD_CONFIG);
        $permitRoot = self::readSshdOption((string)$sshdContent, 'PermitRootLogin', 'unknown');
        $passwordAuth = self::readSshdOption((string)$sshdContent, 'PasswordAuthentication', 'unknown');
        $pubkeyAuth = self::readSshdOption((string)$sshdContent, 'PubkeyAuthentication', 'unknown');

        $permitOk = in_array(strtolower($permitRoot), ['no', 'prohibit-password', 'forced-commands-only'], true);
        $checks[] = [
            'key' => 'sshd_permit_root_login',
            'title' => 'SSHD PermitRootLogin',
            'ok' => $permitOk,
            'current' => $permitRoot,
            'recommended' => 'prohibit-password',
        ];

        $passwordOk = strtolower($passwordAuth) === 'no';
        $checks[] = [
            'key' => 'sshd_password_auth',
            'title' => 'SSHD PasswordAuthentication',
            'ok' => $passwordOk,
            'current' => $passwordAuth,
            'recommended' => 'no',
        ];

        $pubkeyOk = strtolower($pubkeyAuth) === 'yes';
        $checks[] = [
            'key' => 'sshd_pubkey_auth',
            'title' => 'SSHD PubkeyAuthentication',
            'ok' => $pubkeyOk,
            'current' => $pubkeyAuth,
            'recommended' => 'yes',
        ];

        $fail2banInstalled = trim((string)shell_exec('command -v fail2ban-client 2>/dev/null')) !== '';
        $fail2banActive = trim((string)shell_exec('systemctl is-active fail2ban 2>/dev/null')) === 'active';
        $checks[] = [
            'key' => 'fail2ban_service',
            'title' => 'Fail2Ban activo',
            'ok' => $fail2banInstalled && $fail2banActive,
            'current' => $fail2banInstalled ? ($fail2banActive ? 'active' : 'inactive') : 'not-installed',
            'recommended' => 'active',
        ];

        $sshDir = '/root/.ssh';
        $authKeys = '/root/.ssh/authorized_keys';
        $dirPerm = is_dir($sshDir) ? (fileperms($sshDir) & 0777) : 0;
        $authPerm = is_file($authKeys) ? (fileperms($authKeys) & 0777) : 0;
        $sshPermOk = is_dir($sshDir) && $dirPerm === 0700 && (!is_file($authKeys) || $authPerm <= 0600);
        $checks[] = [
            'key' => 'root_ssh_permissions',
            'title' => 'Permisos /root/.ssh',
            'ok' => $sshPermOk,
            'current' => sprintf('dir=%s auth=%s', self::permText($dirPerm), self::permText($authPerm)),
            'recommended' => 'dir=700 auth<=600',
        ];

        $sysctlTargets = [
            'net.ipv4.tcp_syncookies' => '1',
            'net.ipv4.conf.all.accept_redirects' => '0',
            'net.ipv4.conf.default.accept_redirects' => '0',
            'net.ipv4.conf.all.send_redirects' => '0',
            'net.ipv4.conf.default.send_redirects' => '0',
            'net.ipv4.conf.all.rp_filter' => '1',
            'net.ipv4.conf.default.rp_filter' => '1',
            'net.ipv6.conf.all.accept_redirects' => '0',
            'net.ipv6.conf.default.accept_redirects' => '0',
        ];
        $sysctlBad = [];
        foreach ($sysctlTargets as $k => $expected) {
            $current = trim((string)shell_exec('sysctl -n ' . escapeshellarg($k) . ' 2>/dev/null'));
            if ($current === '') {
                continue;
            }
            if ($current !== $expected) {
                $sysctlBad[] = "{$k}={$current}";
            }
        }
        $checks[] = [
            'key' => 'sysctl_network_hardening',
            'title' => 'Sysctl hardening de red',
            'ok' => empty($sysctlBad),
            'current' => empty($sysctlBad) ? 'ok' : implode(', ', array_slice($sysctlBad, 0, 4)),
            'recommended' => 'baseline MuseDock',
        ];

        $okCount = 0;
        foreach ($checks as $c) {
            if (!empty($c['ok'])) {
                $okCount++;
            }
        }
        $score = (int)round(($okCount / max(1, count($checks))) * 100);

        return [
            'score' => $score,
            'checks' => $checks,
            'ok_count' => $okCount,
            'total' => count($checks),
        ];
    }

    public static function applyRecommendedHardening(): array
    {
        $steps = [];

        if (!is_dir('/root/.ssh')) {
            @mkdir('/root/.ssh', 0700, true);
        }
        @chmod('/root/.ssh', 0700);
        if (is_file('/root/.ssh/authorized_keys')) {
            @chmod('/root/.ssh/authorized_keys', 0600);
        }
        $steps[] = ['name' => 'Permisos /root/.ssh', 'ok' => true, 'output' => 'chmod 700 /root/.ssh y 600 authorized_keys'];

        $sshOk = self::setSshdOption(self::SSHD_CONFIG, 'PermitRootLogin', 'prohibit-password');
        $sshOk = self::setSshdOption(self::SSHD_CONFIG, 'PasswordAuthentication', 'no') && $sshOk;
        $sshOk = self::setSshdOption(self::SSHD_CONFIG, 'PubkeyAuthentication', 'yes') && $sshOk;
        $steps[] = ['name' => 'Actualizar sshd_config', 'ok' => $sshOk, 'output' => $sshOk ? 'Opciones aplicadas' : 'No se pudo editar sshd_config'];

        $restartOut = trim((string)shell_exec('systemctl restart ssh 2>&1 || systemctl restart sshd 2>&1'));
        $restartOk = trim((string)shell_exec('systemctl is-active ssh 2>/dev/null || systemctl is-active sshd 2>/dev/null')) === 'active';
        $steps[] = ['name' => 'Reiniciar servicio SSH', 'ok' => $restartOk, 'output' => $restartOk ? 'active' : ($restartOut !== '' ? $restartOut : 'error')];

        $sysctlBaseline = [
            'net.ipv4.tcp_syncookies' => '1',
            'net.ipv4.conf.all.accept_redirects' => '0',
            'net.ipv4.conf.default.accept_redirects' => '0',
            'net.ipv4.conf.all.send_redirects' => '0',
            'net.ipv4.conf.default.send_redirects' => '0',
            'net.ipv4.conf.all.rp_filter' => '1',
            'net.ipv4.conf.default.rp_filter' => '1',
            'net.ipv6.conf.all.accept_redirects' => '0',
            'net.ipv6.conf.default.accept_redirects' => '0',
        ];
        $content = "# MuseDock Panel - Security hardening baseline\n";
        foreach ($sysctlBaseline as $k => $v) {
            $content .= $k . ' = ' . $v . "\n";
        }
        $writeOk = @file_put_contents(self::HARDENING_SYSCTL_FILE, $content) !== false;
        $sysOut = trim((string)shell_exec('sysctl -p ' . escapeshellarg(self::HARDENING_SYSCTL_FILE) . ' 2>&1'));
        $sysOk = $writeOk && stripos($sysOut, 'error') === false;
        $steps[] = ['name' => 'Aplicar baseline sysctl', 'ok' => $sysOk, 'output' => $sysOut !== '' ? $sysOut : ($writeOk ? 'ok' : 'error escribiendo archivo')];

        $f2bInstalled = trim((string)shell_exec('command -v fail2ban-client 2>/dev/null')) !== '';
        if ($f2bInstalled) {
            $f2bOut = trim((string)shell_exec('systemctl enable fail2ban 2>&1 && systemctl restart fail2ban 2>&1'));
            $f2bOk = trim((string)shell_exec('systemctl is-active fail2ban 2>/dev/null')) === 'active';
            $steps[] = ['name' => 'Fail2Ban activo', 'ok' => $f2bOk, 'output' => $f2bOk ? 'active' : ($f2bOut !== '' ? $f2bOut : 'error')];
        } else {
            $steps[] = ['name' => 'Fail2Ban activo', 'ok' => false, 'output' => 'fail2ban no instalado'];
        }

        $ok = true;
        foreach ($steps as $s) {
            if (empty($s['ok'])) {
                $ok = false;
                break;
            }
        }

        return ['ok' => $ok, 'steps' => $steps];
    }

    public static function parseExpectedPublicPorts(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        $ports = [];
        foreach ($parts as $p) {
            $n = (int)$p;
            if ($n < 1 || $n > 65535) {
                continue;
            }
            $ports[$n] = true;
        }
        $list = array_keys($ports);
        sort($list, SORT_NUMERIC);
        return $list;
    }

    public static function getExpectedPublicPorts(): array
    {
        $raw = Settings::get('security_expected_public_tcp_ports', '22,80,443,8444');
        return self::parseExpectedPublicPorts($raw);
    }

    public static function lookupIpContext(string $ip): array
    {
        $empty = ['country' => '', 'city' => '', 'asn' => '', 'provider' => ''];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $empty;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['country' => 'PRIVATE', 'city' => '', 'asn' => 'PRIVATE', 'provider' => 'local'];
        }

        $cacheDir = PANEL_ROOT . '/storage/cache/ip-context';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/' . sha1($ip) . '.json';
        if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < 86400 * 7) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return array_merge($empty, $cached);
            }
        }

        $ctx = stream_context_create([
            'http' => ['timeout' => 3],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $url = 'https://ipwho.is/' . rawurlencode($ip);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || trim($raw) === '') {
            return $empty;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || (($data['success'] ?? true) === false)) {
            return $empty;
        }

        $result = [
            'country' => trim((string)($data['country_code'] ?? $data['country'] ?? '')),
            'city' => trim((string)($data['city'] ?? '')),
            'asn' => trim((string)($data['connection']['asn'] ?? '')),
            'provider' => trim((string)($data['connection']['org'] ?? $data['connection']['isp'] ?? '')),
        ];

        @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $result;
    }

    public static function analyzeLoginAnomaly(int $adminId, string $ip, array $ctx): array
    {
        $reasons = [];
        try {
            $rows = Database::fetchAll(
                "SELECT ip_address, country, asn
                 FROM panel_admin_login_events
                 WHERE admin_id = :admin_id AND success = true
                 ORDER BY created_at DESC
                 LIMIT 100",
                ['admin_id' => $adminId]
            );
        } catch (\Throwable) {
            return ['anomaly' => false, 'reason' => ''];
        }

        if (empty($rows)) {
            return ['anomaly' => false, 'reason' => 'first_login'];
        }

        $knownIps = [];
        $knownCountries = [];
        $knownAsn = [];
        foreach ($rows as $row) {
            $knownIps[(string)($row['ip_address'] ?? '')] = true;
            $country = strtoupper(trim((string)($row['country'] ?? '')));
            $asn = strtoupper(trim((string)($row['asn'] ?? '')));
            if ($country !== '') {
                $knownCountries[$country] = true;
            }
            if ($asn !== '') {
                $knownAsn[$asn] = true;
            }
        }

        if (!isset($knownIps[$ip])) {
            $reasons[] = 'IP nueva';
        }

        $country = strtoupper(trim((string)($ctx['country'] ?? '')));
        if ($country !== '' && $country !== 'PRIVATE' && !isset($knownCountries[$country])) {
            $reasons[] = 'Pais nuevo';
        }

        $asn = strtoupper(trim((string)($ctx['asn'] ?? '')));
        if ($asn !== '' && $asn !== 'PRIVATE' && !isset($knownAsn[$asn])) {
            $reasons[] = 'ASN nuevo';
        }

        return [
            'anomaly' => !empty($reasons),
            'reason' => implode(', ', $reasons),
        ];
    }

    public static function recordAdminLoginEvent(
        ?int $adminId,
        string $username,
        string $ip,
        bool $success,
        array $ctx,
        bool $anomaly,
        string $reason,
        string $userAgent = ''
    ): void {
        try {
            Database::insert('panel_admin_login_events', [
                'admin_id' => $adminId,
                'username' => $username,
                'ip_address' => $ip,
                'user_agent' => $userAgent !== '' ? $userAgent : null,
                'asn' => (string)($ctx['asn'] ?? ''),
                'country' => (string)($ctx['country'] ?? ''),
                'city' => (string)($ctx['city'] ?? ''),
                'provider' => (string)($ctx['provider'] ?? ''),
                'success' => $success,
                'anomaly' => $anomaly,
                'reason' => $reason !== '' ? $reason : null,
            ]);
        } catch (\Throwable) {
            // best effort
        }
    }

    public static function notifyLoginAnomaly(string $host, string $username, string $ip, array $ctx, string $reason, string $userAgent = ''): void
    {
        if (Settings::get('notify_login_anomaly_enabled', '1') !== '1') {
            return;
        }

        $cooldown = (int)Settings::get('notify_login_anomaly_email_cooldown_seconds', '1800');
        $cooldown = max(300, min(86400, $cooldown));

        $message = "Login anomalo detectado para {$username} desde {$ip}";
        $details =
            "Host: {$host}\n" .
            "Usuario: {$username}\n" .
            "IP: {$ip}\n" .
            "Motivo: {$reason}\n" .
            "Pais: " . ((string)($ctx['country'] ?? '') ?: 'desconocido') . "\n" .
            "Ciudad: " . ((string)($ctx['city'] ?? '') ?: 'desconocida') . "\n" .
            "ASN: " . ((string)($ctx['asn'] ?? '') ?: 'desconocido') . "\n" .
            "Proveedor: " . ((string)($ctx['provider'] ?? '') ?: 'desconocido') . "\n" .
            "User-Agent: " . ($userAgent !== '' ? $userAgent : 'desconocido') . "\n" .
            "Hora: " . gmdate('Y-m-d H:i:s') . " UTC";

        try {
            $recent = Database::fetchOne(
                "SELECT id FROM monitor_alerts
                 WHERE host = :host
                   AND type = 'LOGIN_ANOMALY'
                   AND ts > NOW() - (CAST(:cooldown AS integer) * INTERVAL '1 second')
                 ORDER BY ts DESC
                 LIMIT 1",
                ['host' => $host, 'cooldown' => $cooldown]
            );
            if (!$recent) {
                Database::insert('monitor_alerts', [
                    'host' => $host,
                    'type' => 'LOGIN_ANOMALY',
                    'message' => $message,
                    'value' => 1.0,
                    'details' => $details,
                ]);
            }
        } catch (\Throwable) {
            // ignore DB issues here
        }

        NotificationService::sendEventEmail(
            'login_anomaly',
            "[MuseDock Security] Login anomalo en {$host}",
            $details,
            $cooldown
        );
    }

    private static function readSshdOption(string $content, string $key, string $default = ''): string
    {
        if ($content === '') {
            return $default;
        }
        $pattern = '/^\s*#?\s*' . preg_quote($key, '/') . '\s+(.+)$/mi';
        if (!preg_match_all($pattern, $content, $matches)) {
            return $default;
        }
        $value = trim((string)end($matches[1]));
        return $value !== '' ? $value : $default;
    }

    private static function setSshdOption(string $path, string $key, string $value): bool
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return false;
        }
        $updated = false;
        $pattern = '/^\s*#?\s*' . preg_quote($key, '/') . '\b/i';
        foreach ($lines as $i => $line) {
            if (preg_match($pattern, (string)$line) === 1) {
                if (!$updated) {
                    $lines[$i] = $key . ' ' . $value;
                    $updated = true;
                } else {
                    $lines[$i] = '# ' . ltrim((string)$line, "# \t");
                }
            }
        }
        if (!$updated) {
            $lines[] = $key . ' ' . $value;
        }
        return @file_put_contents($path, implode("\n", $lines) . "\n") !== false;
    }

    private static function permText(int $perm): string
    {
        if ($perm <= 0) {
            return 'none';
        }
        return substr(sprintf('%o', $perm), -4);
    }
}

