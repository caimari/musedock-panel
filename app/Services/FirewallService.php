<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

class FirewallService
{
    private static ?string $cachedType = null;

    // ─── Detection ────────────────────────────────────────────

    public static function detectType(): string
    {
        // Check UFW first
        $ufwPath = trim((string)shell_exec('which ufw 2>/dev/null'));
        if ($ufwPath !== '') {
            $status = (string)shell_exec('ufw status 2>/dev/null');
            if (stripos($status, 'active') !== false && stripos($status, 'inactive') === false) {
                return 'ufw';
            }
        }

        // Check iptables
        $iptPath = trim((string)shell_exec('which iptables 2>/dev/null'));
        if ($iptPath !== '') {
            return 'iptables';
        }

        return 'none';
    }

    public static function getType(): string
    {
        if (self::$cachedType === null) {
            self::$cachedType = self::detectType();
        }
        return self::$cachedType;
    }

    public static function isActive(): bool
    {
        $type = self::getType();
        if ($type === 'ufw') {
            $status = (string)shell_exec('ufw status 2>/dev/null');
            return stripos($status, 'active') !== false && stripos($status, 'inactive') === false;
        }
        if ($type === 'iptables') {
            $path = trim((string)shell_exec('which iptables 2>/dev/null'));
            return $path !== '';
        }
        return false;
    }

    // ─── UFW Methods ──────────────────────────────────────────

    public static function ufwGetRules(): array
    {
        $output = (string)shell_exec('ufw status numbered 2>/dev/null');
        $lines = explode("\n", $output);
        $rules = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Match lines like: [ 1] 80/tcp ALLOW IN Anywhere
            // Also matches: [ 2] 443 DENY IN 192.168.1.0/24  # comment
            // Also matches: [ 3] Anywhere DENY IN 10.0.0.1
            if (preg_match('/^\[\s*(\d+)\]\s+(.+?)\s+(ALLOW|DENY|REJECT|LIMIT)\s+(IN|OUT|FWD)?\s*(.*)/i', $line, $m)) {
                $comment = '';
                $direction = trim($m[4] ?? '');
                $from = trim($m[5]);
                // Check for comment after #
                if (preg_match('/^(.*?)\s*#\s*(.+)$/', $from, $cm)) {
                    $from = trim($cm[1]);
                    $comment = trim($cm[2]);
                }
                $rules[] = [
                    'num'       => (int)$m[1],
                    'to'        => trim($m[2]),
                    'action'    => trim($m[3]),
                    'direction' => $direction ?: 'IN',
                    'from'      => $from ?: 'Anywhere',
                    'comment'   => $comment,
                ];
            }
        }

        return $rules;
    }

    public static function ufwAddRule(string $action, string $from, string $to, string $protocol, string $comment = ''): array
    {
        $action = strtolower($action) === 'allow' ? 'allow' : 'deny';

        $cmd = 'ufw ' . $action;

        if ($from !== '' && $from !== '0.0.0.0/0' && $from !== 'any') {
            $cmd .= ' from ' . escapeshellarg($from);
        }

        $cmd .= ' to any';

        if ($to !== '' && $to !== 'any') {
            if ($protocol === 'both') {
                $cmd .= ' port ' . escapeshellarg($to);
            } else {
                $cmd .= ' port ' . escapeshellarg($to) . ' proto ' . escapeshellarg($protocol);
            }
        }

        if ($comment !== '') {
            $cmd .= ' comment ' . escapeshellarg($comment);
        }

        $output = trim((string)shell_exec($cmd . ' 2>&1'));
        $ok = stripos($output, 'Rule added') !== false || stripos($output, 'Regla') !== false || stripos($output, 'added') !== false;

        return ['ok' => $ok, 'output' => $output, 'cmd' => $cmd];
    }

    public static function ufwDeleteRule(int $number): array
    {
        $cmd = sprintf('echo "y" | ufw delete %d 2>&1', $number);
        $output = trim((string)shell_exec($cmd));
        $ok = stripos($output, 'deleted') !== false || stripos($output, 'Rule') !== false;

        return ['ok' => $ok, 'output' => $output];
    }

    /**
     * Edit a UFW rule: delete old + add new (UFW doesn't support in-place edit)
     */
    public static function ufwEditRule(int $ruleNumber, string $action, string $from, string $to, string $protocol, string $comment = ''): array
    {
        // First delete the old rule
        $deleteResult = self::ufwDeleteRule($ruleNumber);
        if (!$deleteResult['ok']) {
            return ['ok' => false, 'output' => 'Error al eliminar regla original: ' . $deleteResult['output']];
        }

        // Then add the new rule
        $addResult = self::ufwAddRule($action, $from, $to, $protocol, $comment);
        if (!$addResult['ok']) {
            return ['ok' => false, 'output' => 'Regla eliminada pero error al crear nueva: ' . $addResult['output']];
        }

        return ['ok' => true, 'output' => 'Regla editada correctamente'];
    }

    /**
     * Edit an iptables rule: delete old + add new
     */
    public static function iptablesEditRule(int $ruleNumber, string $action, string $source, int $port, string $protocol): array
    {
        // First delete
        $deleteResult = self::iptablesDeleteRule($ruleNumber);
        if (!$deleteResult['ok']) {
            return ['ok' => false, 'output' => 'Error al eliminar regla original: ' . $deleteResult['output']];
        }

        // Then add
        $addResult = self::iptablesAddRule($action, $source, $port, $protocol);
        if (!$addResult['ok']) {
            return ['ok' => false, 'output' => 'Regla eliminada pero error al crear nueva: ' . $addResult['output']];
        }

        return ['ok' => true, 'output' => 'Regla editada correctamente'];
    }

    public static function ufwEnable(): array
    {
        $output = trim((string)shell_exec('echo "y" | ufw enable 2>&1'));
        $ok = stripos($output, 'active') !== false || stripos($output, 'enabled') !== false;

        return ['ok' => $ok, 'output' => $output];
    }

    public static function ufwDisable(): array
    {
        $output = trim((string)shell_exec('ufw disable 2>&1'));
        $ok = stripos($output, 'disabled') !== false || stripos($output, 'stopped') !== false;

        return ['ok' => $ok, 'output' => $output];
    }

    public static function ufwGetDefault(): string
    {
        $output = (string)shell_exec('ufw status verbose 2>/dev/null');
        if (preg_match('/Default:\s*(.+)/i', $output, $m)) {
            return trim($m[1]);
        }
        return 'desconocida';
    }

    // ─── iptables Methods ─────────────────────────────────────

    private static array $protocolNames = [
        '0' => 'all', '1' => 'icmp', '6' => 'tcp', '17' => 'udp',
        '47' => 'gre', '50' => 'esp', '51' => 'ah', '58' => 'icmpv6',
    ];

    public static function iptablesGetRules(): array
    {
        $output = (string)shell_exec('iptables -L INPUT -n --line-numbers 2>/dev/null');
        $lines = explode("\n", $output);
        $rules = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip headers
            if ($line === '' || str_starts_with($line, 'Chain') || str_starts_with($line, 'num')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 5) continue;

            $num = (int)$parts[0];
            if ($num <= 0) continue;

            $target      = $parts[1] ?? '';
            $protocolRaw = $parts[2] ?? '';
            $source      = $parts[4] ?? '';
            $destination = $parts[5] ?? '';
            $extra       = implode(' ', array_slice($parts, 6));

            // Translate protocol numbers to names
            $protocol = self::$protocolNames[$protocolRaw] ?? $protocolRaw;

            // Extract port from extra info
            $port = '';
            if (preg_match('/dpt:(\d+)/', $extra, $pm)) {
                $port = $pm[1];
            } elseif (preg_match('/dpts:(\d+:\d+)/', $extra, $pm)) {
                $port = $pm[1];
            }

            // Extract state/ctstate info
            $state = '';
            if (preg_match('/ctstate\s+(\S+)/i', $extra, $sm)) {
                $state = $sm[1];
            } elseif (preg_match('/state\s+(\S+)/i', $extra, $sm)) {
                $state = $sm[1];
            }

            $rules[] = [
                'num'         => $num,
                'target'      => $target,
                'protocol'    => $protocol,
                'source'      => $source,
                'destination' => $destination,
                'port'        => $port,
                'extra'       => $extra,
                'state'       => $state,
            ];
        }

        return $rules;
    }

    public static function iptablesAddRule(string $action, string $source, int $port, string $protocol): array
    {
        $target = strtolower($action) === 'allow' ? 'ACCEPT' : 'DROP';
        $proto  = strtolower($protocol);

        $cmd = 'iptables -A INPUT';
        if ($source !== '' && $source !== '0.0.0.0/0') {
            $cmd .= ' -s ' . escapeshellarg($source);
        }

        if ($proto === 'all' || $port <= 0) {
            // Allow/deny all traffic (no protocol/port filter)
            if ($proto !== 'all' && in_array($proto, ['tcp', 'udp'])) {
                $cmd .= ' -p ' . escapeshellarg($proto);
            }
        } else {
            if (!in_array($proto, ['tcp', 'udp'])) $proto = 'tcp';
            $cmd .= ' -p ' . escapeshellarg($proto);
            $cmd .= ' --dport ' . escapeshellarg((string)$port);
        }

        $cmd .= ' -j ' . escapeshellarg($target);

        $output = trim((string)shell_exec($cmd . ' 2>&1'));
        $ok = $output === '' || stripos($output, 'error') === false;

        return ['ok' => $ok, 'output' => $output ?: 'Regla agregada', 'cmd' => $cmd];
    }

    public static function iptablesDeleteRule(int $number): array
    {
        $cmd = sprintf('iptables -D INPUT %d 2>&1', $number);
        $output = trim((string)shell_exec($cmd));
        $ok = $output === '' || stripos($output, 'error') === false;

        return ['ok' => $ok, 'output' => $output ?: 'Regla eliminada'];
    }

    public static function iptablesSave(): array
    {
        // Try iptables-save to file
        $output = trim((string)shell_exec('iptables-save > /etc/iptables/rules.v4 2>&1'));
        if ($output !== '' && stripos($output, 'No such file') !== false) {
            // Create directory and retry
            shell_exec('mkdir -p /etc/iptables 2>/dev/null');
            $output = trim((string)shell_exec('iptables-save > /etc/iptables/rules.v4 2>&1'));
        }

        // Also try netfilter-persistent
        $nfOutput = trim((string)shell_exec('netfilter-persistent save 2>&1'));
        if ($nfOutput !== '' && stripos($nfOutput, 'not found') === false) {
            $output .= "\n" . $nfOutput;
        }

        $ok = true;
        return ['ok' => $ok, 'output' => trim($output) ?: 'Reglas guardadas'];
    }

    public static function iptablesGetPolicy(): string
    {
        $output = (string)shell_exec('iptables -L INPUT 2>/dev/null');
        $firstLine = strtok($output, "\n");
        if (preg_match('/policy\s+(\w+)/i', $firstLine, $m)) {
            return $m[1];
        }
        return 'desconocida';
    }

    // ─── Common Methods ───────────────────────────────────────

    public static function getAdminIp(): string
    {
        // Try to get real IP behind reverse proxy
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            return $ips[0];
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Get all network interfaces with their IPs
     */
    public static function getNetworkInterfaces(): array
    {
        $output = trim((string)shell_exec("ip -o addr show 2>/dev/null"));
        if ($output === '') return [];

        $interfaces = [];
        foreach (explode("\n", $output) as $line) {
            // Format: 1: lo    inet 127.0.0.1/8 ...
            if (preg_match('/^\d+:\s+(\S+)\s+(inet6?)\s+(\S+)/', $line, $m)) {
                $iface = $m[1];
                $family = $m[2];
                $addr = $m[3]; // includes CIDR like 10.10.70.1/24

                // Skip link-local IPv6
                if ($family === 'inet6' && str_starts_with($addr, 'fe80:')) continue;

                $interfaces[] = [
                    'interface' => $iface,
                    'family'    => $family === 'inet' ? 'IPv4' : 'IPv6',
                    'address'   => $addr,
                ];
            }
        }

        return $interfaces;
    }

    public static function emergencyAllowIp(string $ip): array
    {
        $type = self::getType();
        if ($type === 'ufw') {
            $cmd = 'ufw allow from ' . escapeshellarg($ip) . ' 2>&1';
            $output = trim((string)shell_exec($cmd));
            $ok = stripos($output, 'added') !== false || stripos($output, 'Rule') !== false || stripos($output, 'existing') !== false;
            return ['ok' => $ok, 'output' => $output];
        }

        if ($type === 'iptables') {
            $cmd = 'iptables -I INPUT 1 -s ' . escapeshellarg($ip) . ' -j ACCEPT 2>&1';
            $output = trim((string)shell_exec($cmd));
            $ok = $output === '' || stripos($output, 'error') === false;
            return ['ok' => $ok, 'output' => $output ?: 'Regla de emergencia agregada'];
        }

        return ['ok' => false, 'output' => 'No se detecto firewall'];
    }

    public static function suggestRulesForReplication(string $slaveIp): array
    {
        $suggestions = [];
        if ($slaveIp === '') return $suggestions;

        $suggestions[] = [
            'label'    => "PostgreSQL desde {$slaveIp}",
            'action'   => 'allow',
            'from'     => $slaveIp,
            'port'     => '5432',
            'protocol' => 'tcp',
            'comment'  => 'Replicacion PostgreSQL',
        ];
        $suggestions[] = [
            'label'    => "MySQL desde {$slaveIp}",
            'action'   => 'allow',
            'from'     => $slaveIp,
            'port'     => '3306',
            'protocol' => 'tcp',
            'comment'  => 'Replicacion MySQL',
        ];

        return $suggestions;
    }

    public static function suggestRulesForHosting(string $domain = ''): array
    {
        return [
            [
                'label'    => 'HTTP (puerto 80)',
                'action'   => 'allow',
                'from'     => '0.0.0.0/0',
                'port'     => '80',
                'protocol' => 'tcp',
                'comment'  => 'HTTP',
            ],
            [
                'label'    => 'HTTPS (puerto 443)',
                'action'   => 'allow',
                'from'     => '0.0.0.0/0',
                'port'     => '443',
                'protocol' => 'tcp',
                'comment'  => 'HTTPS',
            ],
            [
                'label'    => 'Panel MuseDock (puerto 8444)',
                'action'   => 'allow',
                'from'     => '0.0.0.0/0',
                'port'     => '8444',
                'protocol' => 'tcp',
                'comment'  => 'MuseDock Panel',
            ],
        ];
    }

    public static function isPortOpen(int $port, string $fromIp = ''): bool
    {
        $type = self::getType();

        if ($type === 'ufw') {
            $rules = self::ufwGetRules();
            foreach ($rules as $rule) {
                if (stripos($rule['action'], 'ALLOW') === false) continue;
                // Check if port matches
                if (str_contains($rule['to'], (string)$port) || str_contains($rule['to'], 'Anywhere')) {
                    if ($fromIp === '' || $rule['from'] === 'Anywhere' || str_contains($rule['from'], $fromIp)) {
                        return true;
                    }
                }
            }
            return false;
        }

        if ($type === 'iptables') {
            $rules = self::iptablesGetRules();
            foreach ($rules as $rule) {
                if ($rule['target'] !== 'ACCEPT') continue;
                if ($rule['port'] === (string)$port || $rule['port'] === '') {
                    if ($fromIp === '' || $rule['source'] === '0.0.0.0/0' || $rule['source'] === $fromIp) {
                        return true;
                    }
                }
            }
            return false;
        }

        return false;
    }
}
