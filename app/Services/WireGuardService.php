<?php
namespace MuseDockPanel\Services;

class WireGuardService
{
    // ─── Detection ─────────────────────────────────────────────

    public static function isInstalled(): bool
    {
        $out = trim((string)shell_exec('which wg 2>/dev/null'));
        return $out !== '';
    }

    public static function isRunning(): bool
    {
        $out = trim((string)shell_exec('systemctl is-active wg-quick@wg0 2>/dev/null'));
        return $out === 'active';
    }

    public static function install(): array
    {
        $output = shell_exec('apt-get install -y wireguard 2>&1');
        $ok = static::isInstalled();
        return ['ok' => $ok, 'output' => trim((string)$output)];
    }

    // ─── Interface Info ────────────────────────────────────────

    public static function getInterfaceStatus(): ?array
    {
        $raw = trim((string)shell_exec('wg show wg0 2>/dev/null'));
        if ($raw === '') return null;

        $result = [
            'interface' => [
                'public_key'     => '',
                'listening_port' => '',
                'private_key'    => '(oculta)',
            ],
            'peers' => [],
        ];

        $currentPeer = null;
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (preg_match('/^interface:\s*(.+)/i', $line)) {
                $currentPeer = null;
                continue;
            }
            if (preg_match('/^peer:\s*(.+)/i', $line, $m)) {
                $currentPeer = [
                    'public_key'       => trim($m[1]),
                    'endpoint'         => '',
                    'allowed_ips'      => '',
                    'latest_handshake' => '',
                    'transfer_rx'      => '',
                    'transfer_tx'      => '',
                ];
                continue;
            }

            if (preg_match('/^public key:\s*(.+)/i', $line, $m)) {
                if ($currentPeer === null) {
                    $result['interface']['public_key'] = trim($m[1]);
                }
            } elseif (preg_match('/^listening port:\s*(.+)/i', $line, $m)) {
                $result['interface']['listening_port'] = trim($m[1]);
            } elseif (preg_match('/^endpoint:\s*(.+)/i', $line, $m) && $currentPeer !== null) {
                $currentPeer['endpoint'] = trim($m[1]);
            } elseif (preg_match('/^allowed ips:\s*(.+)/i', $line, $m) && $currentPeer !== null) {
                $currentPeer['allowed_ips'] = trim($m[1]);
            } elseif (preg_match('/^latest handshake:\s*(.+)/i', $line, $m) && $currentPeer !== null) {
                $currentPeer['latest_handshake'] = trim($m[1]);
            } elseif (preg_match('/^transfer:\s*(.+)\s+received,\s*(.+)\s+sent/i', $line, $m) && $currentPeer !== null) {
                $currentPeer['transfer_rx'] = trim($m[1]);
                $currentPeer['transfer_tx'] = trim($m[2]);
            }

            // When we hit a new peer or end, save previous
            if ($currentPeer !== null && $currentPeer['public_key'] !== '') {
                // Update last peer in array
                $key = $currentPeer['public_key'];
                $result['peers'][$key] = $currentPeer;
            }
        }

        // Convert peers to indexed array
        $result['peers'] = array_values($result['peers']);
        return $result;
    }

    public static function getInterfaceIp(): string
    {
        $out = trim((string)shell_exec('ip addr show wg0 2>/dev/null'));
        if (preg_match('/inet\s+([^\s]+)/', $out, $m)) {
            return $m[1];
        }
        return '';
    }

    public static function getConfig(): string
    {
        $path = '/etc/wireguard/wg0.conf';
        if (!file_exists($path)) return '';
        $content = file_get_contents($path);
        // Mask PrivateKey values
        $content = preg_replace('/^(PrivateKey\s*=\s*).+$/m', '$1(oculta)', $content);
        return $content;
    }

    // ─── Key Generation ────────────────────────────────────────

    public static function generateKeyPair(): array
    {
        $private = trim((string)shell_exec('wg genkey 2>/dev/null'));
        $public  = trim((string)shell_exec('echo ' . escapeshellarg($private) . ' | wg pubkey 2>/dev/null'));
        return ['private' => $private, 'public' => $public];
    }

    public static function generatePresharedKey(): string
    {
        return trim((string)shell_exec('wg genpsk 2>/dev/null'));
    }

    // ─── Peer CRUD ─────────────────────────────────────────────

    public static function addPeer(string $publicKey, string $allowedIps, string $endpoint = '', string $presharedKey = ''): array
    {
        $configPath = '/etc/wireguard/wg0.conf';
        if (!file_exists($configPath)) {
            return ['ok' => false, 'output' => 'No se encontro /etc/wireguard/wg0.conf'];
        }

        $section = "\n[Peer]\n";
        $section .= "PublicKey = {$publicKey}\n";
        $section .= "AllowedIPs = {$allowedIps}\n";
        if ($endpoint !== '') {
            $section .= "Endpoint = {$endpoint}\n";
        }
        if ($presharedKey !== '') {
            $section .= "PresharedKey = {$presharedKey}\n";
        }

        $ok = file_put_contents($configPath, $section, FILE_APPEND);
        if ($ok === false) {
            return ['ok' => false, 'output' => 'Error al escribir en wg0.conf'];
        }

        return static::syncConfig();
    }

    public static function removePeer(string $publicKey): array
    {
        $configPath = '/etc/wireguard/wg0.conf';
        if (!file_exists($configPath)) {
            return ['ok' => false, 'output' => 'No se encontro /etc/wireguard/wg0.conf'];
        }

        $content = file_get_contents($configPath);
        // Remove the [Peer] block that contains this public key
        $pattern = '/\[Peer\]\s*\n(?:[^\[]*?)?PublicKey\s*=\s*' . preg_quote($publicKey, '/') . '\s*\n[^\[]*/i';
        $newContent = preg_replace($pattern, '', $content);

        if ($newContent === null || $newContent === $content) {
            return ['ok' => false, 'output' => 'No se encontro el peer en la configuracion'];
        }

        // Clean up excessive blank lines
        $newContent = preg_replace('/\n{3,}/', "\n\n", $newContent);
        file_put_contents($configPath, $newContent);

        return static::syncConfig();
    }

    public static function updatePeer(string $publicKey, string $allowedIps, string $endpoint): array
    {
        $configPath = '/etc/wireguard/wg0.conf';
        if (!file_exists($configPath)) {
            return ['ok' => false, 'output' => 'No se encontro /etc/wireguard/wg0.conf'];
        }

        $content = file_get_contents($configPath);
        $blocks = preg_split('/(?=\[(?:Interface|Peer)\])/i', $content, -1, PREG_SPLIT_NO_EMPTY);

        $found = false;
        $newBlocks = [];
        foreach ($blocks as $block) {
            if (preg_match('/\[Peer\]/i', $block) && str_contains($block, $publicKey)) {
                $found = true;
                // Update AllowedIPs
                $block = preg_replace('/^AllowedIPs\s*=\s*.+$/mi', "AllowedIPs = {$allowedIps}", $block);
                // Update or add Endpoint
                if ($endpoint !== '') {
                    if (preg_match('/^Endpoint\s*=/mi', $block)) {
                        $block = preg_replace('/^Endpoint\s*=\s*.+$/mi', "Endpoint = {$endpoint}", $block);
                    } else {
                        $block = rtrim($block) . "\nEndpoint = {$endpoint}\n";
                    }
                } else {
                    // Remove Endpoint line if empty
                    $block = preg_replace('/^Endpoint\s*=\s*.+\n?/mi', '', $block);
                }
            }
            $newBlocks[] = $block;
        }

        if (!$found) {
            return ['ok' => false, 'output' => 'No se encontro el peer en la configuracion'];
        }

        file_put_contents($configPath, implode('', $newBlocks));
        return static::syncConfig();
    }

    public static function getPeers(): array
    {
        $configPath = '/etc/wireguard/wg0.conf';
        if (!file_exists($configPath)) return [];

        $content = file_get_contents($configPath);
        $blocks = preg_split('/(?=\[Peer\])/i', $content, -1, PREG_SPLIT_NO_EMPTY);

        $peers = [];
        foreach ($blocks as $block) {
            if (!preg_match('/\[Peer\]/i', $block)) continue;

            $peer = [
                'public_key'    => '',
                'endpoint'      => '',
                'allowed_ips'   => '',
                'preshared_key' => false,
            ];

            if (preg_match('/^PublicKey\s*=\s*(.+)$/mi', $block, $m)) {
                $peer['public_key'] = trim($m[1]);
            }
            if (preg_match('/^Endpoint\s*=\s*(.+)$/mi', $block, $m)) {
                $peer['endpoint'] = trim($m[1]);
            }
            if (preg_match('/^AllowedIPs\s*=\s*(.+)$/mi', $block, $m)) {
                $peer['allowed_ips'] = trim($m[1]);
            }
            if (preg_match('/^PresharedKey\s*=/mi', $block)) {
                $peer['preshared_key'] = true;
            }

            if ($peer['public_key'] !== '') {
                $peers[] = $peer;
            }
        }

        return $peers;
    }

    // ─── Remote Config Generator ───────────────────────────────

    public static function generatePeerConfig(
        string $serverPublicKey,
        string $serverEndpoint,
        int    $serverPort,
        string $peerPrivateKey,
        string $peerAddress,
        string $allowedIps = '10.10.70.0/24',
        string $presharedKey = ''
    ): string {
        $config  = "[Interface]\n";
        $config .= "PrivateKey = {$peerPrivateKey}\n";
        $config .= "Address = {$peerAddress}\n";
        $config .= "DNS = 1.1.1.1, 8.8.8.8\n\n";
        $config .= "[Peer]\n";
        $config .= "PublicKey = {$serverPublicKey}\n";
        if ($presharedKey !== '') {
            $config .= "PresharedKey = {$presharedKey}\n";
        }
        $config .= "Endpoint = {$serverEndpoint}:{$serverPort}\n";
        $config .= "AllowedIPs = {$allowedIps}\n";
        $config .= "PersistentKeepalive = 25\n";

        return $config;
    }

    // ─── Ping / Latency ───────────────────────────────────────

    public static function pingPeer(string $ip): ?float
    {
        $safeIp = escapeshellarg($ip);
        $output = trim((string)shell_exec("ping -c 1 -W 2 {$safeIp} 2>/dev/null"));
        if (preg_match('/time[=<]([\d.]+)\s*ms/i', $output, $m)) {
            return (float)$m[1];
        }
        return null;
    }

    // ─── Helpers ──────────────────────────────────────────────

    public static function syncConfig(): array
    {
        $output = trim((string)shell_exec('bash -c ' . escapeshellarg('wg syncconf wg0 <(wg-quick strip wg0)') . ' 2>&1'));
        $running = static::isRunning();
        return ['ok' => $running, 'output' => $output];
    }

    public static function restartInterface(): array
    {
        $output = trim((string)shell_exec('systemctl restart wg-quick@wg0 2>&1'));
        $running = static::isRunning();
        return ['ok' => $running, 'output' => $output];
    }
}
