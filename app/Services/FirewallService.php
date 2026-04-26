<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

class FirewallService
{
    private static ?string $cachedType = null;
    private const IPTABLES_DISABLED_FLAG = __DIR__ . '/../../storage/firewall/iptables.disabled.flag';
    private const IPTABLES_BACKUP_FILE = __DIR__ . '/../../storage/firewall/iptables.before-disable.v4';
    private const RULE_PRESETS_KEY = 'firewall_rule_presets';
    private const FULL_SNAPSHOTS_KEY = 'firewall_full_snapshots';
    private const MAX_FULL_SNAPSHOTS = 20;

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
            if ($path === '') {
                return false;
            }

            // If panel explicitly disabled iptables, reflect inactive in UI.
            if (is_file(self::IPTABLES_DISABLED_FLAG)) {
                return false;
            }

            return true;
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
        // Use -v to get interface info (in/out columns)
        $output = (string)shell_exec('iptables -L INPUT -nv --line-numbers 2>/dev/null');
        $lines = explode("\n", $output);
        $rules = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip headers
            if ($line === '' || str_starts_with($line, 'Chain') || str_starts_with($line, 'num')) {
                continue;
            }

            // -v format: num pkts bytes target prot opt in out source destination [extra]
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 9) continue;

            $num = (int)$parts[0];
            if ($num <= 0) continue;

            $target      = $parts[3] ?? '';
            $protocolRaw = $parts[4] ?? '';
            $inIface     = $parts[6] ?? '*';
            $outIface    = $parts[7] ?? '*';
            $source      = $parts[8] ?? '';
            $destination = $parts[9] ?? '';
            $extra       = implode(' ', array_slice($parts, 10));

            // Translate protocol numbers to names
            $protocol = self::$protocolNames[$protocolRaw] ?? $protocolRaw;

            // Extract port from extra info
            $port = '';
            if (preg_match('/dpt:(\d+)/', $extra, $pm)) {
                $port = (int)$pm[1] > 0 ? $pm[1] : '';
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
                'in'          => $inIface !== '*' ? $inIface : '',
                'out'         => $outIface !== '*' ? $outIface : '',
            ];
        }

        return $rules;
    }

    /**
     * Get iptables INPUT rules that are NOT managed by UFW.
     * These are "ghost" rules added manually that UFW doesn't know about.
     */
    public static function getManualIptablesRules(): array
    {
        if (self::getType() !== 'ufw') return [];

        $output = (string)shell_exec('iptables -L INPUT -nv --line-numbers 2>/dev/null');
        $lines = explode("\n", $output);
        $manual = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'Chain') || str_starts_with($line, 'num')) continue;

            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 9) continue;

            $num    = (int)$parts[0];
            $target = $parts[3] ?? '';
            $prot   = $parts[4] ?? '';
            $inIf   = $parts[6] ?? '*';
            $source = $parts[8] ?? '';
            $dest   = $parts[9] ?? '';
            $extra  = implode(' ', array_slice($parts, 10));

            // Skip UFW chains, loopback, and f2b chains
            if (str_starts_with($target, 'ufw-') || $target === 'f2b-sshd') continue;
            // Skip loopback
            if ($inIf === 'lo') continue;
            // Skip ctstate RELATED,ESTABLISHED (standard)
            if (str_contains($extra, 'RELATED,ESTABLISHED')) continue;
            // Skip ICMP
            if ($prot === 'icmp' || $prot === '1') continue;
            // Skip rules that reference ports 80/443 (already in UFW)
            $port = '';
            if (preg_match('/dpt:(\d+)/', $extra, $pm)) $port = $pm[1];

            // These are manual rules outside UFW
            if (in_array($target, ['ACCEPT', 'DROP', 'REJECT'])) {
                // Try reverse DNS for readable source
                $sourceDisplay = $source;
                if ($source !== '0.0.0.0/0') {
                    $host = @gethostbyaddr(explode('/', $source)[0]);
                    if ($host && $host !== $source) $sourceDisplay = $host . " ({$source})";
                }

                $manual[] = [
                    'num'     => $num,
                    'target'  => $target,
                    'protocol' => $prot === 'all' ? 'all' : $prot,
                    'source'  => $sourceDisplay,
                    'source_raw' => $source,
                    'port'    => $port ?: 'all',
                    'extra'   => $extra,
                ];
            }
        }

        return $manual;
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

    public static function iptablesEnable(): array
    {
        $iptables = trim((string)shell_exec('which iptables 2>/dev/null'));
        if ($iptables === '') {
            return ['ok' => false, 'output' => 'iptables no esta disponible en este servidor'];
        }

        $restoreOutput = '';
        $ok = true;

        // Restore previous rules snapshot if available.
        if (is_file(self::IPTABLES_BACKUP_FILE) && filesize(self::IPTABLES_BACKUP_FILE) > 0) {
            $restoreCmd = 'iptables-restore < ' . escapeshellarg(self::IPTABLES_BACKUP_FILE) . ' 2>&1';
            $restoreOutput = trim((string)shell_exec($restoreCmd));
            if ($restoreOutput !== '' && stripos($restoreOutput, 'error') !== false) {
                $ok = false;
            }
        }

        if ($ok) {
            if (is_file(self::IPTABLES_DISABLED_FLAG)) {
                @unlink(self::IPTABLES_DISABLED_FLAG);
            }
            self::iptablesSave();
        }

        return [
            'ok' => $ok,
            'output' => $ok
                ? ($restoreOutput !== '' ? "Restaurado desde backup: {$restoreOutput}" : 'Firewall iptables activado')
                : ("Error al restaurar iptables: {$restoreOutput}"),
        ];
    }

    public static function iptablesDisable(): array
    {
        $iptables = trim((string)shell_exec('which iptables 2>/dev/null'));
        if ($iptables === '') {
            return ['ok' => false, 'output' => 'iptables no esta disponible en este servidor'];
        }

        @mkdir(dirname(self::IPTABLES_DISABLED_FLAG), 0750, true);

        // Snapshot current rules to allow safe restore.
        $backupCmd = 'iptables-save > ' . escapeshellarg(self::IPTABLES_BACKUP_FILE) . ' 2>&1';
        $backupOut = trim((string)shell_exec($backupCmd));
        if ($backupOut !== '' && stripos($backupOut, 'error') !== false) {
            return ['ok' => false, 'output' => "No se pudo crear backup de iptables: {$backupOut}"];
        }

        $commands = [
            'iptables -P INPUT ACCEPT 2>&1',
            'iptables -P FORWARD ACCEPT 2>&1',
            'iptables -P OUTPUT ACCEPT 2>&1',
            'iptables -F INPUT 2>&1',
            'iptables -F FORWARD 2>&1',
            'iptables -F OUTPUT 2>&1',
        ];

        $errors = [];
        foreach ($commands as $cmd) {
            $out = trim((string)shell_exec($cmd));
            if ($out !== '' && stripos($out, 'error') !== false) {
                $errors[] = $out;
            }
        }

        if (!empty($errors)) {
            return ['ok' => false, 'output' => 'Error al desactivar iptables: ' . implode(' | ', $errors)];
        }

        @file_put_contents(self::IPTABLES_DISABLED_FLAG, (string)time());
        self::iptablesSave();

        return ['ok' => true, 'output' => 'Firewall iptables desactivado (backup guardado para restaurar)'];
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

    // ─── Security Audit ────────────────────────────────────

    /**
     * Audit firewall rules for security issues
     * Returns array of warnings with severity (danger, warning, info)
     */
    public static function auditRules(array $rules, string $policy): array
    {
        $warnings = [];
        $type = self::getType();
        $policyUpper = strtoupper($policy);

        // 1. Policy ACCEPT = everything open
        if (str_contains($policyUpper, 'ACCEPT')) {
            $warnings[] = [
                'severity' => 'danger',
                'icon'     => 'bi-shield-fill-x',
                'title'    => 'Politica por defecto ACCEPT',
                'message'  => 'La politica por defecto permite todo el trafico. Cualquier IP puede acceder a todos los puertos del servidor. Se recomienda cambiar a DROP y crear reglas ACCEPT solo para las IPs y puertos necesarios.',
            ];
        }

        if ($type === 'iptables') {
            foreach ($rules as $rule) {
                // 2. ACCEPT all from 0.0.0.0/0 without ctstate = open to everyone
                // BUT skip loopback rules (interface = lo) — those are safe
                if ($rule['target'] === 'ACCEPT'
                    && $rule['source'] === '0.0.0.0/0'
                    && $rule['protocol'] === 'all'
                    && empty($rule['state'])
                    && empty($rule['port'])
                    && ($rule['in'] ?? '') !== 'lo') {
                    $warnings[] = [
                        'severity' => 'danger',
                        'icon'     => 'bi-exclamation-octagon-fill',
                        'title'    => "Regla #{$rule['num']}: Acceso total desde cualquier IP",
                        'message'  => "La regla #{$rule['num']} permite TODO el trafico desde CUALQUIER IP (0.0.0.0/0) sin restriccion de puerto ni protocolo. Esto anula la politica DROP y deja el servidor completamente abierto.",
                        'rule_num' => $rule['num'],
                        'fix'      => 'delete',
                    ];
                }

                // 3. ACCEPT with port 0
                if ($rule['target'] === 'ACCEPT' && $rule['port'] === '0') {
                    $warnings[] = [
                        'severity' => 'warning',
                        'icon'     => 'bi-exclamation-triangle-fill',
                        'title'    => "Regla #{$rule['num']}: Puerto 0 (invalido)",
                        'message'  => "La regla #{$rule['num']} tiene puerto 0, que no es un puerto valido. Esto puede ser un error de configuracion.",
                        'rule_num' => $rule['num'],
                        'fix'      => 'delete',
                    ];
                }

                // 4. Duplicate rules (same source, protocol, port, target)
                foreach ($rules as $other) {
                    if ($other['num'] <= $rule['num']) continue;
                    if ($other['target'] === $rule['target']
                        && $other['source'] === $rule['source']
                        && $other['protocol'] === $rule['protocol']
                        && $other['port'] === $rule['port']
                        && $other['state'] === $rule['state']) {
                        $warnings[] = [
                            'severity' => 'warning',
                            'icon'     => 'bi-files',
                            'title'    => "Reglas #{$rule['num']} y #{$other['num']}: Duplicadas",
                            'message'  => "Las reglas #{$rule['num']} y #{$other['num']} son identicas. La regla #{$other['num']} es redundante y se puede eliminar.",
                            'rule_num' => $other['num'],
                            'fix'      => 'delete',
                        ];
                    }
                }
            }
        } elseif ($type === 'ufw') {
            foreach ($rules as $rule) {
                // UFW: ALLOW from Anywhere (full access)
                if (stripos($rule['action'], 'ALLOW') !== false
                    && ($rule['from'] === 'Anywhere' || $rule['from'] === 'Anywhere (v6)')
                    && ($rule['to'] === 'Anywhere' || $rule['to'] === 'Anywhere (v6)')) {
                    $warnings[] = [
                        'severity' => 'danger',
                        'icon'     => 'bi-exclamation-octagon-fill',
                        'title'    => "Regla #{$rule['num']}: Acceso total desde cualquier IP",
                        'message'  => "La regla #{$rule['num']} permite TODO el trafico desde cualquier IP sin restriccion. Esto deja el servidor completamente abierto.",
                        'rule_num' => $rule['num'],
                        'fix'      => 'delete',
                    ];
                }
            }
        }

        // 5. No rules at all
        if (empty($rules) && str_contains($policyUpper, 'DROP')) {
            $warnings[] = [
                'severity' => 'danger',
                'icon'     => 'bi-lock-fill',
                'title'    => 'Sin reglas con politica DROP',
                'message'  => 'No hay ninguna regla ACCEPT pero la politica es DROP. Todo el trafico esta bloqueado, incluido tu acceso. Usa el boton de emergencia para permitir tu IP.',
            ];
        }

        // 6. Sensitive ports open to Anywhere (SSH, panel, portal, DB)
        $sensitivePorts = [
            '22'   => ['name' => 'SSH', 'risk' => 'Permite ataques de fuerza bruta desde cualquier IP del mundo'],
            'ssh'  => ['name' => 'SSH', 'risk' => 'Permite ataques de fuerza bruta desde cualquier IP del mundo'],
            '8444' => ['name' => 'Panel Admin', 'risk' => 'Expone el panel de administracion a internet'],
            '8446' => ['name' => 'Portal Clientes', 'risk' => 'Expone el portal de clientes a internet sin restriccion'],
            '3306' => ['name' => 'MySQL', 'risk' => 'Expone la base de datos MySQL a internet'],
            '5432' => ['name' => 'PostgreSQL', 'risk' => 'Expone la base de datos PostgreSQL a internet'],
            '5433' => ['name' => 'PostgreSQL (alt)', 'risk' => 'Expone la base de datos PostgreSQL a internet'],
            '6379' => ['name' => 'Redis', 'risk' => 'Expone Redis sin autenticacion a internet'],
        ];

        if ($type === 'ufw') {
            foreach ($rules as $rule) {
                if (stripos($rule['action'], 'ALLOW') === false) continue;
                $from = $rule['from'] ?? '';
                if ($from !== 'Anywhere' && $from !== 'Anywhere (v6)') continue;
                $port = strtolower(trim(explode('/', $rule['to'] ?? '')[0]));
                if (isset($sensitivePorts[$port])) {
                    $sp = $sensitivePorts[$port];
                    $ruleNum = (int)($rule['num'] ?? 0);
                    $warnings[] = [
                        'severity' => $port === '22' || $port === 'ssh' ? 'danger' : 'warning',
                        'icon'     => 'bi-unlock-fill',
                        'title'    => $ruleNum > 0
                            ? "Regla #{$ruleNum}: Puerto {$sp['name']} ({$port}) abierto a todo internet"
                            : "Puerto {$sp['name']} ({$port}) abierto a todo internet",
                        'message'  => "{$sp['risk']}. Se recomienda restringir a IPs de confianza.",
                        'rule_num' => $ruleNum,
                        'fix'      => 'delete',
                        'delete_backend' => 'ufw',
                    ];
                }
            }
        } elseif ($type === 'iptables') {
            foreach ($rules as $rule) {
                if ($rule['target'] !== 'ACCEPT') continue;
                if ($rule['source'] !== '0.0.0.0/0') continue;
                $port = $rule['port'] ?? '';
                if (isset($sensitivePorts[$port])) {
                    $sp = $sensitivePorts[$port];
                    $ruleNum = (int)($rule['num'] ?? 0);
                    $warnings[] = [
                        'severity' => $port === '22' ? 'danger' : 'warning',
                        'icon'     => 'bi-unlock-fill',
                        'title'    => $ruleNum > 0
                            ? "Regla #{$ruleNum}: Puerto {$sp['name']} ({$port}) abierto a todo internet"
                            : "Puerto {$sp['name']} ({$port}) abierto a todo internet",
                        'message'  => "{$sp['risk']}. Se recomienda restringir a IPs de confianza.",
                        'rule_num' => $ruleNum,
                        'fix'      => 'delete',
                        'delete_backend' => 'iptables',
                    ];
                }
            }
        }

        return $warnings;
    }

    /**
     * Audit manual iptables rules that exist outside UFW management.
     * These rules execute before UFW and can silently bypass it.
     */
    public static function auditManualIptablesRules(array $manualRules): array
    {
        $warnings = [];
        $sensitivePorts = [
            '22'   => ['name' => 'SSH', 'risk' => 'Permite ataques de fuerza bruta desde cualquier IP del mundo'],
            '8444' => ['name' => 'Panel Admin', 'risk' => 'Expone el panel de administracion a internet'],
            '8446' => ['name' => 'Portal Clientes', 'risk' => 'Expone el portal de clientes a internet sin restriccion'],
            '3306' => ['name' => 'MySQL', 'risk' => 'Expone la base de datos MySQL a internet'],
            '5432' => ['name' => 'PostgreSQL', 'risk' => 'Expone la base de datos PostgreSQL a internet'],
            '5433' => ['name' => 'PostgreSQL (alt)', 'risk' => 'Expone la base de datos PostgreSQL a internet'],
            '6379' => ['name' => 'Redis', 'risk' => 'Expone Redis sin autenticacion a internet'],
        ];

        foreach ($manualRules as $rule) {
            $target = strtoupper((string)($rule['target'] ?? ''));
            $sourceRaw = (string)($rule['source_raw'] ?? '');
            $protocol = strtolower((string)($rule['protocol'] ?? ''));
            $port = (string)($rule['port'] ?? '');
            $ruleNum = (int)($rule['num'] ?? 0);
            if ($ruleNum <= 0 || $target !== 'ACCEPT') {
                continue;
            }

            // Full allow rule from any IP: bypasses UFW effectively.
            if ($sourceRaw === '0.0.0.0/0' && ($protocol === 'all' || $protocol === '') && ($port === 'all' || $port === '')) {
                $warnings[] = [
                    'severity'       => 'danger',
                    'icon'           => 'bi-exclamation-octagon-fill',
                    'title'          => "Regla iptables manual #{$ruleNum}: Acceso total desde cualquier IP",
                    'message'        => "La regla manual #{$ruleNum} permite TODO el trafico desde 0.0.0.0/0. Aunque UFW este activo, esta regla se evalua antes y deja el servidor abierto.",
                    'rule_num'       => $ruleNum,
                    'fix'            => 'delete',
                    'delete_backend' => 'iptables',
                ];
                continue;
            }

            // Sensitive explicit ports open from anywhere.
            if ($sourceRaw === '0.0.0.0/0' && isset($sensitivePorts[$port])) {
                $sp = $sensitivePorts[$port];
                $warnings[] = [
                    'severity'       => $port === '22' ? 'danger' : 'warning',
                    'icon'           => 'bi-unlock-fill',
                    'title'          => "Regla iptables manual #{$ruleNum}: Puerto {$sp['name']} ({$port}) abierto a todo internet",
                    'message'        => "{$sp['risk']}. Al ser regla manual de iptables, UFW no la controla.",
                    'rule_num'       => $ruleNum,
                    'fix'            => 'delete',
                    'delete_backend' => 'iptables',
                ];
            }
        }

        return $warnings;
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

    /**
     * Firewall presets (single rule per preset) stored in panel_settings as JSON.
     */
    public static function getRulePresets(): array
    {
        $raw = json_decode(Settings::get(self::RULE_PRESETS_KEY, '[]'), true);
        if (!is_array($raw)) return [];

        $presets = [];
        foreach ($raw as $item) {
            if (!is_array($item)) continue;

            $id   = trim((string)($item['id'] ?? ''));
            $name = trim((string)($item['name'] ?? ''));
            if ($id === '' || $name === '') continue;

            $action = strtolower(trim((string)($item['action'] ?? 'allow')));
            $action = in_array($action, ['allow', 'deny'], true) ? $action : 'allow';

            $protocol = strtolower(trim((string)($item['protocol'] ?? 'tcp')));
            if (!in_array($protocol, ['tcp', 'udp', 'both', 'all'], true)) {
                $protocol = 'tcp';
            }

            $from = trim((string)($item['from'] ?? '0.0.0.0/0'));
            if ($from === '') $from = '0.0.0.0/0';

            $port = trim((string)($item['port'] ?? ''));
            if ($protocol === 'all') $port = '';

            $presets[] = [
                'id'         => $id,
                'name'       => $name,
                'action'     => $action,
                'from'       => $from,
                'port'       => $port,
                'protocol'   => $protocol,
                'comment'    => trim((string)($item['comment'] ?? '')),
                'created_at' => (string)($item['created_at'] ?? ''),
                'updated_at' => (string)($item['updated_at'] ?? ''),
            ];
        }

        usort($presets, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        return $presets;
    }

    public static function findRulePreset(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') return null;
        foreach (self::getRulePresets() as $preset) {
            if (($preset['id'] ?? '') === $id) {
                return $preset;
            }
        }
        return null;
    }

    public static function saveRulePreset(array $data): array
    {
        $presets = self::getRulePresets();
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            try {
                $id = 'fwpreset_' . bin2hex(random_bytes(6));
            } catch (\Throwable) {
                $id = 'fwpreset_' . str_replace('.', '', uniqid('', true));
            }
        }

        $record = [
            'id'         => $id,
            'name'       => trim((string)($data['name'] ?? 'Preset sin nombre')),
            'action'     => strtolower(trim((string)($data['action'] ?? 'allow'))) === 'deny' ? 'deny' : 'allow',
            'from'       => trim((string)($data['from'] ?? '0.0.0.0/0')) ?: '0.0.0.0/0',
            'port'       => trim((string)($data['port'] ?? '')),
            'protocol'   => strtolower(trim((string)($data['protocol'] ?? 'tcp'))),
            'comment'    => trim((string)($data['comment'] ?? '')),
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];

        if (!in_array($record['protocol'], ['tcp', 'udp', 'both', 'all'], true)) {
            $record['protocol'] = 'tcp';
        }
        if ($record['protocol'] === 'all') {
            $record['port'] = '';
        }

        $updated = false;
        foreach ($presets as $i => $preset) {
            if (($preset['id'] ?? '') !== $id) continue;
            $record['created_at'] = (string)($preset['created_at'] ?? gmdate('c'));
            $presets[$i] = $record;
            $updated = true;
            break;
        }

        if (!$updated) {
            $presets[] = $record;
        }

        self::storeRulePresets($presets);
        return $record;
    }

    public static function deleteRulePreset(string $id): bool
    {
        $id = trim($id);
        if ($id === '') return false;

        $presets = self::getRulePresets();
        $before = count($presets);
        $presets = array_values(array_filter($presets, static function (array $preset) use ($id): bool {
            return ($preset['id'] ?? '') !== $id;
        }));

        if (count($presets) === $before) return false;

        self::storeRulePresets($presets);
        return true;
    }

    private static function storeRulePresets(array $presets): void
    {
        Settings::set(
            self::RULE_PRESETS_KEY,
            json_encode(array_values($presets), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Full snapshots store/restore the effective firewall state via iptables-save.
     */
    public static function getFullSnapshots(): array
    {
        $raw = json_decode(Settings::get(self::FULL_SNAPSHOTS_KEY, '[]'), true);
        if (!is_array($raw)) return [];

        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item)) continue;
            $id = trim((string)($item['id'] ?? ''));
            $name = trim((string)($item['name'] ?? ''));
            $rulesV4 = (string)($item['rules_v4'] ?? '');
            if ($id === '' || $name === '' || $rulesV4 === '') continue;

            $items[] = [
                'id'          => $id,
                'name'        => $name,
                'note'        => trim((string)($item['note'] ?? '')),
                'source_type' => trim((string)($item['source_type'] ?? 'unknown')),
                'hash'        => trim((string)($item['hash'] ?? sha1($rulesV4))),
                'created_at'  => trim((string)($item['created_at'] ?? '')),
                'rules_v4'    => $rulesV4,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string)$b['created_at'], (string)$a['created_at']);
        });

        return $items;
    }

    public static function createFullSnapshot(string $name, string $note = ''): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Nombre de snapshot requerido'];
        }

        $rulesV4 = trim((string)shell_exec('iptables-save 2>/dev/null'));
        if ($rulesV4 === '') {
            return ['ok' => false, 'error' => 'No se pudo capturar el estado real con iptables-save'];
        }

        try {
            $id = 'fwsnap_' . bin2hex(random_bytes(6));
        } catch (\Throwable) {
            $id = 'fwsnap_' . str_replace('.', '', uniqid('', true));
        }

        $snapshot = [
            'id'          => $id,
            'name'        => $name,
            'note'        => trim($note),
            'source_type' => self::getType(),
            'hash'        => sha1($rulesV4),
            'created_at'  => gmdate('c'),
            'rules_v4'    => $rulesV4,
        ];

        $all = self::getFullSnapshots();
        array_unshift($all, $snapshot);
        if (count($all) > self::MAX_FULL_SNAPSHOTS) {
            $all = array_slice($all, 0, self::MAX_FULL_SNAPSHOTS);
        }
        self::storeFullSnapshots($all);

        return ['ok' => true, 'snapshot' => $snapshot];
    }

    public static function findFullSnapshot(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') return null;
        foreach (self::getFullSnapshots() as $item) {
            if (($item['id'] ?? '') === $id) return $item;
        }
        return null;
    }

    public static function deleteFullSnapshot(string $id): bool
    {
        $id = trim($id);
        if ($id === '') return false;

        $all = self::getFullSnapshots();
        $before = count($all);
        $all = array_values(array_filter($all, static function (array $item) use ($id): bool {
            return ($item['id'] ?? '') !== $id;
        }));
        if (count($all) === $before) return false;

        self::storeFullSnapshots($all);
        return true;
    }

    public static function applyFullSnapshot(string $id): array
    {
        $snapshot = self::findFullSnapshot($id);
        if (!$snapshot) {
            return ['ok' => false, 'error' => 'Snapshot no encontrado'];
        }

        $rulesV4 = (string)($snapshot['rules_v4'] ?? '');
        if (trim($rulesV4) === '') {
            return ['ok' => false, 'error' => 'Snapshot invalido: contenido vacio'];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'fwsnap_');
        if ($tmp === false) {
            return ['ok' => false, 'error' => 'No se pudo crear archivo temporal'];
        }

        file_put_contents($tmp, $rulesV4);
        $output = trim((string)shell_exec('iptables-restore < ' . escapeshellarg($tmp) . ' 2>&1'));
        @unlink($tmp);

        $ok = $output === '' || stripos($output, 'error') === false;
        if (!$ok) {
            return ['ok' => false, 'error' => $output ?: 'Error al restaurar snapshot completo'];
        }

        // Persist best effort for reboot.
        self::iptablesSave();

        return ['ok' => true, 'output' => 'Snapshot completo aplicado correctamente'];
    }

    private static function storeFullSnapshots(array $snapshots): void
    {
        Settings::set(
            self::FULL_SNAPSHOTS_KEY,
            json_encode(array_values($snapshots), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Export current firewall configuration in a portable JSON payload.
     */
    public static function exportConfiguration(): array
    {
        $type = self::getType();
        if ($type === 'none') {
            return ['ok' => false, 'error' => 'No se detecto firewall activo para exportar'];
        }

        $rules = $type === 'ufw' ? self::ufwGetRules() : self::iptablesGetRules();
        $policy = $type === 'ufw' ? self::ufwGetDefault() : self::iptablesGetPolicy();

        $normalizedRules = [];
        if ($type === 'ufw') {
            foreach ($rules as $rule) {
                $direction = strtoupper((string)($rule['direction'] ?? 'IN'));
                if ($direction !== 'IN') continue;

                $actionRaw = strtoupper((string)($rule['action'] ?? 'ALLOW'));
                $action = str_contains($actionRaw, 'ALLOW') ? 'allow' : 'deny';

                $from = trim((string)($rule['from'] ?? 'Anywhere'));
                if ($from === '' || stripos($from, 'Anywhere') !== false) {
                    $from = '0.0.0.0/0';
                }

                $to = trim((string)($rule['to'] ?? ''));
                $port = '';
                $protocol = 'all';
                if (preg_match('/^(\d+)(?:\/(tcp|udp))?/i', $to, $m)) {
                    $port = (string)$m[1];
                    $protocol = strtolower((string)($m[2] ?? 'both'));
                }

                $normalizedRules[] = [
                    'action'   => $action,
                    'from'     => $from,
                    'port'     => $port,
                    'protocol' => $protocol,
                    'comment'  => trim((string)($rule['comment'] ?? '')),
                ];
            }
        } else {
            foreach ($rules as $rule) {
                $target = strtoupper((string)($rule['target'] ?? ''));
                if (!in_array($target, ['ACCEPT', 'DROP', 'REJECT'], true)) continue;

                $action = $target === 'ACCEPT' ? 'allow' : 'deny';
                $from = trim((string)($rule['source'] ?? '0.0.0.0/0'));
                if ($from === '') $from = '0.0.0.0/0';

                $protocol = strtolower((string)($rule['protocol'] ?? 'all'));
                if (!in_array($protocol, ['tcp', 'udp', 'all', 'both'], true)) {
                    $protocol = 'all';
                }

                $normalizedRules[] = [
                    'action'   => $action,
                    'from'     => $from,
                    'port'     => trim((string)($rule['port'] ?? '')),
                    'protocol' => $protocol,
                    'comment'  => '',
                ];
            }
        }

        $incomingPolicy = 'unknown';
        $policyUpper = strtoupper($policy);
        if (str_contains($policyUpper, 'DROP') || str_contains($policyUpper, 'DENY')) {
            $incomingPolicy = 'deny';
        } elseif (str_contains($policyUpper, 'ACCEPT') || str_contains($policyUpper, 'ALLOW')) {
            $incomingPolicy = 'allow';
        }

        $payload = [
            'format'            => 'musedock-firewall-export-v1',
            'generated_at'      => gmdate('c'),
            'source_type'       => $type,
            'source_policy'     => $policy,
            'normalized'        => [
                'incoming_policy' => $incomingPolicy,
                'rules'           => $normalizedRules,
            ],
            'raw'               => [
                'iptables_save' => $type === 'iptables' ? (string)shell_exec('iptables-save 2>/dev/null') : '',
            ],
        ];

        return ['ok' => true, 'data' => $payload];
    }

    /**
     * Import a portable firewall configuration payload.
     *
     * Replace mode:
     * - iptables: removes simple ACCEPT/DROP/REJECT rules, keeps loopback/state/jump rules.
     * - ufw: deletes current numbered rules.
     */
    public static function importConfiguration(array $payload, bool $replaceExisting): array
    {
        $type = self::getType();
        if ($type === 'none') {
            return ['ok' => false, 'error' => 'No se detecto firewall activo para importar'];
        }

        $format = (string)($payload['format'] ?? '');
        if ($format !== 'musedock-firewall-export-v1') {
            return ['ok' => false, 'error' => 'Formato de archivo no soportado'];
        }

        $normalized = $payload['normalized'] ?? null;
        if (!is_array($normalized)) {
            return ['ok' => false, 'error' => 'Archivo invalido: falta bloque normalized'];
        }

        $rules = $normalized['rules'] ?? [];
        if (!is_array($rules)) {
            return ['ok' => false, 'error' => 'Archivo invalido: reglas malformed'];
        }

        // Fast path: exact restore on iptables when replacing and raw payload exists.
        $rawIptables = trim((string)($payload['raw']['iptables_save'] ?? ''));
        if ($replaceExisting && $type === 'iptables' && $rawIptables !== '' && (string)($payload['source_type'] ?? '') === 'iptables') {
            $tmp = tempnam(sys_get_temp_dir(), 'fwimp_');
            if ($tmp === false) {
                return ['ok' => false, 'error' => 'No se pudo crear archivo temporal para import'];
            }
            file_put_contents($tmp, $rawIptables);
            $output = trim((string)shell_exec('iptables-restore < ' . escapeshellarg($tmp) . ' 2>&1'));
            @unlink($tmp);
            $ok = $output === '' || stripos($output, 'error') === false;
            if (!$ok) {
                return ['ok' => false, 'error' => $output ?: 'Error al aplicar iptables-restore'];
            }
            self::iptablesSave();
            return ['ok' => true, 'message' => 'Import completo aplicado con iptables-restore'];
        }

        // Optional cleanup before importing normalized rules.
        if ($replaceExisting) {
            if ($type === 'ufw') {
                $existing = self::ufwGetRules();
                foreach (array_reverse($existing) as $rule) {
                    $num = (int)($rule['num'] ?? 0);
                    if ($num > 0) self::ufwDeleteRule($num);
                }
            } else {
                $existing = self::iptablesGetRules();
                foreach (array_reverse($existing) as $rule) {
                    $target = strtoupper((string)($rule['target'] ?? ''));
                    if (!in_array($target, ['ACCEPT', 'DROP', 'REJECT'], true)) continue;
                    $state = strtoupper((string)($rule['state'] ?? ''));
                    if (str_contains($state, 'RELATED') || str_contains($state, 'ESTABLISHED')) continue;
                    if ((string)($rule['in'] ?? '') === 'lo') continue;
                    $num = (int)($rule['num'] ?? 0);
                    if ($num > 0) self::iptablesDeleteRule($num);
                }
            }
        }

        // Apply incoming policy when possible.
        $incomingPolicy = strtolower((string)($normalized['incoming_policy'] ?? 'unknown'));
        if ($incomingPolicy === 'allow' || $incomingPolicy === 'deny') {
            if ($type === 'ufw') {
                shell_exec('ufw default ' . escapeshellarg($incomingPolicy) . ' incoming 2>&1');
            } else {
                shell_exec('iptables -P INPUT ' . ($incomingPolicy === 'allow' ? 'ACCEPT' : 'DROP') . ' 2>&1');
            }
        }

        $applied = 0;
        foreach ($rules as $item) {
            if (!is_array($item)) continue;
            $action = strtolower(trim((string)($item['action'] ?? 'allow')));
            if (!in_array($action, ['allow', 'deny'], true)) continue;

            $from = trim((string)($item['from'] ?? '0.0.0.0/0'));
            if ($from === '') $from = '0.0.0.0/0';

            $port = trim((string)($item['port'] ?? ''));
            $protocol = strtolower(trim((string)($item['protocol'] ?? 'all')));
            if (!in_array($protocol, ['tcp', 'udp', 'both', 'all'], true)) $protocol = 'all';
            $comment = trim((string)($item['comment'] ?? ''));

            if ($type === 'ufw') {
                $r = self::ufwAddRule($action, $from, $port, $protocol, $comment);
                if ($r['ok']) $applied++;
                continue;
            }

            if ($protocol === 'both') {
                $r1 = self::iptablesAddRule($action, $from, (int)$port, 'tcp');
                $r2 = self::iptablesAddRule($action, $from, (int)$port, 'udp');
                if ($r1['ok']) $applied++;
                if ($r2['ok']) $applied++;
            } else {
                $r = self::iptablesAddRule($action, $from, (int)$port, $protocol);
                if ($r['ok']) $applied++;
            }
        }

        if ($type === 'iptables') {
            self::iptablesSave();
        }

        return ['ok' => true, 'message' => 'Import completado. Reglas aplicadas: ' . $applied];
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

    // ─── Failover: open/close public ports ───────────────────

    /**
     * Open HTTP/HTTPS ports to the public (when slave becomes master)
     * Adds rules with a comment marker so we can identify and remove them later
     */
    public static function openPublicPorts(): array
    {
        $type = self::getType();
        $results = [];

        $ports = [80, 443];

        if ($type === 'iptables') {
            foreach ($ports as $port) {
                // Check if port is already open
                if (self::isPortOpen($port)) {
                    $results[] = ['port' => $port, 'ok' => true, 'output' => 'Ya abierto'];
                    continue;
                }
                // Add rule with comment for identification
                $cmd = sprintf(
                    'iptables -A INPUT -p tcp --dport %d -j ACCEPT -m comment --comment "musedock-failover" 2>&1',
                    $port
                );
                $output = trim((string)shell_exec($cmd));
                $ok = $output === '' || stripos($output, 'error') === false;
                $results[] = ['port' => $port, 'ok' => $ok, 'output' => $output ?: 'Abierto'];
            }
            // Save rules
            self::iptablesSave();

        } elseif ($type === 'ufw') {
            foreach ($ports as $port) {
                $result = self::ufwAddRule('allow', '0.0.0.0/0', (string)$port, 'tcp', 'musedock-failover');
                $results[] = ['port' => $port, 'ok' => $result['ok'], 'output' => $result['output']];
            }
        }

        return $results;
    }

    /**
     * Close HTTP/HTTPS ports (when promoted slave goes back to slave role)
     * Only removes rules tagged with "musedock-failover" comment
     */
    public static function closePublicPorts(): array
    {
        $type = self::getType();
        $results = [];

        if ($type === 'iptables') {
            // Remove all rules with musedock-failover comment (in reverse order to keep numbering)
            $output = (string)shell_exec('iptables -L INPUT -n --line-numbers -v 2>/dev/null');
            $lines = array_reverse(explode("\n", $output));
            $removed = 0;

            foreach ($lines as $line) {
                if (stripos($line, 'musedock-failover') !== false) {
                    // Extract rule number
                    if (preg_match('/^\s*(\d+)\s/', trim($line), $m)) {
                        $ruleNum = (int)$m[1];
                        $delOutput = trim((string)shell_exec("iptables -D INPUT {$ruleNum} 2>&1"));
                        $removed++;
                    }
                }
            }

            if ($removed > 0) {
                self::iptablesSave();
                $results[] = ['ok' => true, 'output' => "{$removed} regla(s) de failover eliminadas"];
            } else {
                $results[] = ['ok' => true, 'output' => 'No habia reglas de failover que eliminar'];
            }

        } elseif ($type === 'ufw') {
            // Delete rules with musedock-failover comment (reverse order)
            $rules = self::ufwGetRules();
            $removed = 0;
            foreach (array_reverse($rules) as $rule) {
                if (stripos($rule['comment'] ?? '', 'musedock-failover') !== false) {
                    self::ufwDeleteRule($rule['num']);
                    $removed++;
                }
            }
            $results[] = ['ok' => true, 'output' => "{$removed} regla(s) de failover eliminadas"];
        }

        return $results;
    }

    /**
     * Check if public ports (80/443) are open — used by failover logic
     */
    public static function arePublicPortsOpen(): bool
    {
        return self::isPortOpen(80) && self::isPortOpen(443);
    }
}
