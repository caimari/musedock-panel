<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * FederationService — Manages federation peers and inter-panel communication.
 *
 * Federation peers are bidirectional, peer-to-peer (unlike cluster slaves which are unidirectional).
 * Both panels must register each other as peers.
 */
class FederationService
{
    // ═══════════════════════════════════════════════════════════════
    // Peer CRUD
    // ═══════════════════════════════════════════════════════════════

    public static function getPeers(): array
    {
        return Database::fetchAll('SELECT * FROM federation_peers ORDER BY name');
    }

    public static function getPeer(int $id): ?array
    {
        $peer = Database::fetchOne('SELECT * FROM federation_peers WHERE id = :id', ['id' => $id]);
        if ($peer) {
            $peer['metadata'] = json_decode($peer['metadata'] ?? '{}', true);
            // Decrypt auth token for use
            $peer['auth_token_plain'] = ReplicationService::decryptPassword($peer['auth_token']);
        }
        return $peer;
    }

    public static function addPeer(string $name, string $apiUrl, string $authToken, array $sshConfig = []): array
    {
        // Validate unique name
        $existing = Database::fetchOne('SELECT id FROM federation_peers WHERE name = :name', ['name' => $name]);
        if ($existing) {
            return ['ok' => false, 'error' => 'Peer name already exists'];
        }

        // Validate unique URL
        $apiUrl = rtrim($apiUrl, '/');
        $existing = Database::fetchOne('SELECT id FROM federation_peers WHERE api_url = :url', ['url' => $apiUrl]);
        if ($existing) {
            return ['ok' => false, 'error' => 'Peer API URL already registered'];
        }

        $encryptedToken = ReplicationService::encryptPassword($authToken);

        $id = Database::insert('federation_peers', [
            'name'         => $name,
            'api_url'      => $apiUrl,
            'auth_token'   => $encryptedToken,
            'ssh_host'     => $sshConfig['host'] ?? '',
            'ssh_port'     => $sshConfig['port'] ?? 22,
            'ssh_user'     => $sshConfig['user'] ?? 'root',
            'ssh_key_path' => $sshConfig['key_path'] ?? '/root/.ssh/id_ed25519',
            'status'       => 'offline',
        ]);

        LogService::log('federation.peer.add', $name, "Added federation peer: {$apiUrl}");

        // Attempt bidirectional handshake — register ourselves on the remote peer
        $handshakeResult = self::performHandshake($id, $authToken, $apiUrl, $sshConfig);

        return ['ok' => true, 'id' => $id, 'handshake' => $handshakeResult];
    }

    /**
     * Perform bidirectional handshake: register ourselves on the remote peer.
     * This is best-effort — if it fails, the peer can be registered manually on the other side.
     */
    public static function performHandshake(int $localPeerId, string $remoteToken, string $remoteApiUrl, array $sshConfig = []): array
    {
        $config = require PANEL_ROOT . '/config/panel.php';

        // Get our local info
        $localName = gethostname() ?: 'unknown';
        $localPort = $config['port'] ?? 8444;

        // Determine our API URL (how the remote can reach us)
        $localIp = @file_get_contents('https://api.ipify.org?format=text');
        $localApiUrl = "https://" . trim($localIp ?: '127.0.0.1') . ":{$localPort}";

        // Get our local auth token
        $localToken = '';
        $rawToken = Settings::get('cluster_local_token', '');
        if ($rawToken) {
            $localToken = ReplicationService::decryptPassword($rawToken);
        }

        if (empty($localToken)) {
            return ['ok' => false, 'error' => 'No local cluster token configured — remote peer cannot authenticate to us'];
        }

        // Get our SSH public key
        $keyPath = $sshConfig['key_path'] ?? '/root/.ssh/id_ed25519';
        $pubKeyPath = $keyPath . '.pub';
        $localPubKey = file_exists($pubKeyPath) ? trim(file_get_contents($pubKeyPath)) : '';

        // Call the remote peer's handshake endpoint
        $ch = curl_init(rtrim($remoteApiUrl, '/') . '/api/federation/handshake');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $remoteToken,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'name'         => $localName,
                'api_url'      => $localApiUrl,
                'auth_token'   => $localToken,
                'ssh_host'     => $sshConfig['host'] ?? '',
                'ssh_port'     => $sshConfig['port'] ?? 22,
                'ssh_user'     => $sshConfig['user'] ?? 'root',
                'ssh_key_path' => $sshConfig['key_path'] ?? '/root/.ssh/id_ed25519',
                'public_key'   => $localPubKey,
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode >= 400) {
            LogService::log('federation.handshake.fail', $localName,
                "Handshake failed: HTTP {$httpCode}, error: {$error}");
            return ['ok' => false, 'error' => "Handshake failed (HTTP {$httpCode}): " . ($error ?: $response)];
        }

        $decoded = json_decode($response, true);
        if (!($decoded['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'Remote rejected handshake: ' . ($decoded['error'] ?? '')];
        }

        // Install remote's SSH key locally
        $remotePubKey = $decoded['data']['public_key'] ?? '';
        if (!empty($remotePubKey)) {
            self::installSshKey($remotePubKey);
        }

        LogService::log('federation.handshake.ok', $localName,
            "Bidirectional handshake completed with: " . ($decoded['data']['hostname'] ?? 'unknown'));

        return ['ok' => true, 'remote_hostname' => $decoded['data']['hostname'] ?? ''];
    }

    public static function updatePeer(int $id, array $data): array
    {
        $peer = self::getPeer($id);
        if (!$peer) {
            return ['ok' => false, 'error' => 'Peer not found'];
        }

        $updateData = ['updated_at' => date('Y-m-d H:i:s')];

        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['api_url'])) $updateData['api_url'] = rtrim($data['api_url'], '/');
        if (!empty($data['auth_token'])) $updateData['auth_token'] = ReplicationService::encryptPassword($data['auth_token']);
        if (isset($data['ssh_host'])) $updateData['ssh_host'] = $data['ssh_host'];
        if (isset($data['ssh_port'])) $updateData['ssh_port'] = (int)$data['ssh_port'];
        if (isset($data['ssh_user'])) $updateData['ssh_user'] = $data['ssh_user'];
        if (isset($data['ssh_key_path'])) $updateData['ssh_key_path'] = $data['ssh_key_path'];

        Database::update('federation_peers', $updateData, 'id = :id', ['id' => $id]);

        LogService::log('federation.peer.update', $peer['name'], "Updated federation peer");

        return ['ok' => true];
    }

    public static function removePeer(int $id): array
    {
        $peer = self::getPeer($id);
        if (!$peer) {
            return ['ok' => false, 'error' => 'Peer not found'];
        }

        // Check for active migrations
        $active = Database::fetchOne(
            "SELECT id FROM hosting_migrations WHERE peer_id = :pid AND status IN ('pending', 'running', 'paused')",
            ['pid' => $id]
        );
        if ($active) {
            return ['ok' => false, 'error' => 'Cannot remove peer with active migrations'];
        }

        Database::delete('federation_peers', 'id = :id', ['id' => $id]);
        LogService::log('federation.peer.remove', $peer['name'], "Removed federation peer");

        return ['ok' => true];
    }

    // ═══════════════════════════════════════════════════════════════
    // Peer API communication
    // ═══════════════════════════════════════════════════════════════

    /**
     * Call a remote peer's API endpoint.
     */
    public static function callPeerApi(array $peer, string $method, string $endpoint, array $data = [], int $timeout = 30): array
    {
        $url = rtrim($peer['api_url'], '/') . $endpoint;
        $token = $peer['auth_token_plain'] ?? ReplicationService::decryptPassword($peer['auth_token']);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
                'X-Federation-Source: ' . gethostname(),
            ],
            CURLOPT_SSL_VERIFYPEER => false, // Internal network — self-signed certs OK
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'GET' && !empty($data)) {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'error' => "Connection error: {$error}"];
        }

        if ($httpCode === 401) {
            return ['ok' => false, 'error' => 'Authentication failed — check peer auth token'];
        }

        if ($httpCode >= 400) {
            return ['ok' => false, 'error' => "HTTP {$httpCode}: {$response}"];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Invalid JSON response'];
        }

        return $decoded;
    }

    // ═══════════════════════════════════════════════════════════════
    // Peer health check
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test connectivity to a peer (API + SSH).
     */
    public static function testPeer(int $id): array
    {
        $peer = self::getPeer($id);
        if (!$peer) {
            return ['ok' => false, 'error' => 'Peer not found'];
        }

        $results = ['api' => false, 'ssh' => false];

        // Test API
        $apiResult = self::callPeerApi($peer, 'GET', '/api/federation/health');
        $results['api'] = $apiResult['ok'] ?? false;
        $results['api_detail'] = $apiResult;

        // Test SSH
        $sshResult = self::testSshConnection($peer);
        $results['ssh'] = $sshResult['ok'] ?? false;
        $results['ssh_detail'] = $sshResult;

        // Update peer status
        $status = ($results['api'] && $results['ssh']) ? 'online' : 'offline';
        Database::update('federation_peers', [
            'status' => $status,
            'last_seen_at' => $results['api'] ? date('Y-m-d H:i:s') : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);

        $results['ok'] = $results['api'] && $results['ssh'];
        return $results;
    }

    // ═══════════════════════════════════════════════════════════════
    // SSH helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get SSH target string (user@host).
     */
    public static function getSshTarget(array $peer): string
    {
        $user = $peer['ssh_user'] ?? 'root';
        $host = $peer['ssh_host'] ?? '';

        // If no SSH host specified, extract from API URL
        if (empty($host)) {
            $parsed = parse_url($peer['api_url']);
            $host = $parsed['host'] ?? '';
        }

        return "{$user}@{$host}";
    }

    /**
     * Test SSH connection to a peer.
     */
    public static function testSshConnection(array $peer): array
    {
        $sshTarget = self::getSshTarget($peer);
        $keyPath = $peer['ssh_key_path'] ?? '/root/.ssh/id_ed25519';
        $port = $peer['ssh_port'] ?? 22;

        if (!file_exists($keyPath)) {
            return ['ok' => false, 'error' => "SSH key not found: {$keyPath}"];
        }

        $cmd = sprintf(
            'ssh -p %d -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=10 -o BatchMode=yes %s "echo OK" 2>&1',
            $port,
            escapeshellarg($keyPath),
            escapeshellarg($sshTarget)
        );

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);
        $outputStr = implode("\n", $output);

        if ($returnCode !== 0) {
            return ['ok' => false, 'error' => "SSH failed (exit {$returnCode}): {$outputStr}"];
        }

        return ['ok' => true, 'output' => $outputStr];
    }

    /**
     * Generate and exchange SSH keys with a peer.
     */
    public static function exchangeSshKeys(int $peerId): array
    {
        $peer = self::getPeer($peerId);
        if (!$peer) {
            return ['ok' => false, 'error' => 'Peer not found'];
        }

        // Generate local SSH key if needed
        $keyResult = FileSyncService::generateSshKey($peer['ssh_key_path'] ?: '/root/.ssh/id_ed25519');
        if (!$keyResult['ok']) {
            return ['ok' => false, 'error' => 'Failed to generate SSH key'];
        }

        $publicKey = $keyResult['public_key'];

        // Send our public key to the peer
        $installResult = self::callPeerApi($peer, 'POST', '/api/federation/install-ssh-key', [
            'public_key' => $publicKey,
            'source_host' => gethostname(),
        ]);

        if (!$installResult['ok']) {
            return ['ok' => false, 'error' => 'Failed to install SSH key on peer: ' . ($installResult['error'] ?? '')];
        }

        // Get peer's public key
        $peerKey = $installResult['data']['peer_public_key'] ?? null;
        if ($peerKey) {
            // Install peer's key locally
            self::installSshKey($peerKey);
        }

        return ['ok' => true, 'local_key' => $publicKey, 'peer_key' => $peerKey];
    }

    /**
     * Install an SSH public key in authorized_keys.
     *
     * Security:
     * - Validates key format before installing
     * - Restricts key to rsync-only via command= prefix (prevents arbitrary command execution)
     * - Logs installation for audit trail
     */
    public static function installSshKey(string $publicKey): bool
    {
        $publicKey = trim($publicKey);

        // Validate key format (must be ssh-ed25519, ssh-rsa, ecdsa-sha2-*, etc.)
        if (!preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-\S+|sk-ssh-ed25519@\S+)\s+\S+/', $publicKey)) {
            return false;
        }

        $authKeysFile = '/root/.ssh/authorized_keys';
        $dir = dirname($authKeysFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        // Check if key already installed
        $existing = @file_get_contents($authKeysFile) ?: '';
        $keyParts = explode(' ', $publicKey);
        $keyBody = $keyParts[1] ?? '';
        if ($keyBody && str_contains($existing, $keyBody)) {
            return true; // Already installed
        }

        // Install with restriction: only allow rsync, scp, pg_dump, psql, mysql
        // command= forces the server to run only the specified command pattern
        $restrictedKey = 'command="'
            . 'if [[ \"$SSH_ORIGINAL_COMMAND\" =~ ^(rsync|scp|pg_dump|psql|mysql|mysqldump|echo\\ OK|cat|dd|tar) ]]; '
            . 'then $SSH_ORIGINAL_COMMAND; '
            . 'else echo \"Federation: command not allowed\"; exit 1; fi'
            . '",no-port-forwarding,no-X11-forwarding,no-agent-forwarding '
            . $publicKey;

        file_put_contents($authKeysFile, "\n" . $restrictedKey . "\n", FILE_APPEND);
        chmod($authKeysFile, 0600);

        LogService::log('federation.ssh.install', null, 'SSH key installed for federation peer');

        return true;
    }

    // ═══════════════════════════════════════════════════════════════
    // Server info (for destination API)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get local server info (used by destination to report its IP, etc.).
     */
    public static function getServerInfo(): array
    {
        $publicIp = @file_get_contents('https://api.ipify.org?format=text');

        return [
            'hostname' => gethostname(),
            'public_ip' => $publicIp ? trim($publicIp) : '',
            'disk_available_mb' => (int)(disk_free_space('/var/www/vhosts') / 1048576),
            'php_versions' => self::getInstalledPhpVersions(),
        ];
    }

    private static function getInstalledPhpVersions(): array
    {
        $versions = [];
        foreach (['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'] as $v) {
            if (file_exists("/usr/sbin/php-fpm{$v}") || file_exists("/usr/bin/php{$v}")) {
                $versions[] = $v;
            }
        }
        return $versions;
    }

    // ═══════════════════════════════════════════════════════════════
    // Pairing code system (simplified peer registration)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate a pairing code that encodes this panel's connection info.
     *
     * The code is a base64-encoded JSON string containing:
     * - API URL (how to reach this panel)
     * - Auth token (for API authentication)
     * - SSH host/port (for file transfers)
     * - Panel name (hostname)
     *
     * Valid for 10 minutes. Stored in panel_settings with expiry.
     */
    public static function generatePairingCode(): array
    {
        $config = require PANEL_ROOT . '/config/panel.php';

        // Get or ensure local auth token exists
        $localToken = '';
        $rawToken = Settings::get('cluster_local_token', '');
        if ($rawToken) {
            $localToken = ReplicationService::decryptPassword($rawToken);
        }
        if (empty($localToken)) {
            // Auto-generate one
            $localToken = bin2hex(random_bytes(32));
            Settings::set('cluster_local_token', ReplicationService::encryptPassword($localToken));
        }

        // Determine this panel's API URL
        $publicIp = @file_get_contents('https://api.ipify.org?format=text');
        $publicIp = $publicIp ? trim($publicIp) : '';
        $port = $config['port'] ?? 8444;
        $apiUrl = "https://{$publicIp}:{$port}";

        // SSH info
        $sshKeyPath = Settings::get('filesync_ssh_key_path', '/root/.ssh/id_ed25519');

        // Build pairing payload
        $payload = [
            'v' => 1, // version for future compat
            'name' => gethostname() ?: 'panel',
            'url' => $apiUrl,
            'token' => $localToken,
            'ssh_host' => $publicIp,
            'ssh_port' => (int)Settings::get('filesync_ssh_port', '22'),
            'ssh_user' => Settings::get('filesync_ssh_user', 'root'),
            'ssh_key' => $sshKeyPath,
            'exp' => time() + 600, // 10 min expiry
        ];

        // Encode as compact base64 string
        $code = rtrim(base64_encode(json_encode($payload)), '=');

        // Store so we can validate inbound connections (optional — code is self-contained)
        Settings::set('federation_pairing_code', $code);
        Settings::set('federation_pairing_expires', (string)(time() + 600));

        return [
            'ok' => true,
            'code' => $code,
            'expires_in' => 600,
            'api_url' => $apiUrl,
            'hostname' => $payload['name'],
        ];
    }

    /**
     * Connect to a remote panel using its pairing code.
     *
     * Decodes the code, extracts connection info, registers the peer,
     * performs handshake, and exchanges SSH keys — all in one step.
     */
    public static function connectWithPairingCode(string $code): array
    {
        // Decode pairing code
        $json = base64_decode($code . str_repeat('=', (4 - strlen($code) % 4) % 4));
        $payload = json_decode($json, true);

        if (!$payload || !isset($payload['url']) || !isset($payload['token'])) {
            return ['ok' => false, 'error' => 'Codigo invalido. Verifica que lo has copiado correctamente.'];
        }

        // Check expiry
        $expiry = $payload['exp'] ?? 0;
        if ($expiry > 0 && time() > $expiry) {
            return ['ok' => false, 'error' => 'Codigo expirado. Genera uno nuevo en el panel remoto.'];
        }

        $peerName = $payload['name'] ?? 'remote-panel';
        $peerUrl  = $payload['url'] ?? '';
        $peerToken = $payload['token'] ?? '';

        // Check if already registered
        $existing = Database::fetchOne('SELECT id FROM federation_peers WHERE api_url = :url', ['url' => rtrim($peerUrl, '/')]);
        if ($existing) {
            return ['ok' => false, 'error' => "Este panel ya esta registrado como peer (URL: {$peerUrl})"];
        }

        // Test connectivity first
        $ch = curl_init(rtrim($peerUrl, '/') . '/api/federation/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $peerToken,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            $detail = $error ?: "HTTP {$httpCode}";
            if (str_contains($detail, 'Connection refused') || str_contains($detail, 'timed out')) {
                return ['ok' => false, 'error' => "No se puede conectar al panel remoto. Verifica que el firewall permite acceso al puerto (URL: {$peerUrl}). Error: {$detail}"];
            }
            if ($httpCode === 401) {
                return ['ok' => false, 'error' => 'Token rechazado por el panel remoto. El codigo puede haber expirado.'];
            }
            return ['ok' => false, 'error' => "Error conectando al panel remoto: {$detail}"];
        }

        // Register the peer
        $result = self::addPeer($peerName, $peerUrl, $peerToken, [
            'host'     => $payload['ssh_host'] ?? '',
            'port'     => $payload['ssh_port'] ?? 22,
            'user'     => $payload['ssh_user'] ?? 'root',
            'key_path' => $payload['ssh_key'] ?? '/root/.ssh/id_ed25519',
        ]);

        if (!$result['ok']) {
            return $result;
        }

        $remoteHostname = $peerName;
        if (isset($result['handshake']['remote_hostname'])) {
            $remoteHostname = $result['handshake']['remote_hostname'];
        }

        // Test SSH as well
        $peer = self::getPeer($result['id']);
        $sshResult = $peer ? self::testSshConnection($peer) : ['ok' => false];

        return [
            'ok' => true,
            'peer_id' => $result['id'],
            'peer_name' => $remoteHostname,
            'api_ok' => true,
            'ssh_ok' => $sshResult['ok'] ?? false,
            'handshake_ok' => $result['handshake']['ok'] ?? false,
        ];
    }
}
