<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * FileSyncService — Handles file synchronization between master and slave nodes.
 *
 * Supports two methods:
 *   - SSH (rsync): Classic rsync over SSH, works well over WireGuard (~5 MB/s)
 *   - HTTPS (API): Tar + POST via panel API, faster between VPS (~24 MB/s), no SSH needed
 */
class FileSyncService
{
    // ═══════════════════════════════════════════════════════════════
    // Configuration helpers
    // ═══════════════════════════════════════════════════════════════

    public static function getConfig(): array
    {
        return [
            'enabled'          => Settings::get('filesync_enabled', '0') === '1',
            'method'           => Settings::get('filesync_method', 'ssh'),     // 'ssh' or 'https'
            'ssh_port'         => (int)Settings::get('filesync_ssh_port', '22'),
            'ssh_key_path'     => Settings::get('filesync_ssh_key_path', '/root/.ssh/id_ed25519'),
            'ssh_user'         => Settings::get('filesync_ssh_user', 'root'),
            'interval_minutes' => (int)Settings::get('filesync_interval', '15'),
            'bandwidth_limit'  => (int)Settings::get('filesync_bwlimit', '0'), // KB/s, 0=unlimited
            'exclude_patterns' => Settings::get('filesync_exclude', '.cache,*.log,*.tmp,node_modules'),
            'sync_ssl_certs'   => Settings::get('filesync_ssl_certs', '0') === '1',
            'ssl_cert_path'    => Settings::get('filesync_ssl_cert_path', ''),
            'rewrite_db_host'  => Settings::get('filesync_rewrite_dbhost', '1') === '1',
        ];
    }

    public static function saveConfig(array $data): void
    {
        $keys = [
            'filesync_enabled', 'filesync_method', 'filesync_ssh_port',
            'filesync_ssh_key_path', 'filesync_ssh_user', 'filesync_interval',
            'filesync_bwlimit', 'filesync_exclude', 'filesync_ssl_certs',
            'filesync_ssl_cert_path', 'filesync_rewrite_dbhost',
        ];
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                Settings::set($key, $data[$key]);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // SSH Key Management
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate an SSH key pair if it doesn't exist.
     */
    public static function generateSshKey(string $keyPath = ''): array
    {
        if ($keyPath === '') {
            $keyPath = Settings::get('filesync_ssh_key_path', '/root/.ssh/id_ed25519');
        }

        if (file_exists($keyPath)) {
            $pubKey = @file_get_contents($keyPath . '.pub');
            return ['ok' => true, 'exists' => true, 'public_key' => trim($pubKey ?: ''), 'path' => $keyPath];
        }

        $dir = dirname($keyPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $cmd = sprintf(
            'ssh-keygen -t ed25519 -f %s -N "" -C "musedock-panel-sync" 2>&1',
            escapeshellarg($keyPath)
        );
        $output = shell_exec($cmd);

        if (file_exists($keyPath . '.pub')) {
            $pubKey = trim(file_get_contents($keyPath . '.pub'));
            return ['ok' => true, 'exists' => false, 'public_key' => $pubKey, 'path' => $keyPath];
        }

        return ['ok' => false, 'error' => 'No se pudo generar la clave SSH: ' . $output];
    }

    /**
     * Get the public key content.
     */
    public static function getPublicKey(string $keyPath = ''): string
    {
        if ($keyPath === '') {
            $keyPath = Settings::get('filesync_ssh_key_path', '/root/.ssh/id_ed25519');
        }
        $pubFile = $keyPath . '.pub';
        return file_exists($pubFile) ? trim(file_get_contents($pubFile)) : '';
    }

    /**
     * Install a public key in authorized_keys (called on slave via API).
     */
    public static function installPublicKey(string $publicKey): array
    {
        $publicKey = trim($publicKey);
        if (empty($publicKey) || !str_starts_with($publicKey, 'ssh-')) {
            return ['ok' => false, 'error' => 'Clave publica invalida'];
        }

        $authFile = '/root/.ssh/authorized_keys';
        $dir = dirname($authFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        // Check if already installed
        $existing = file_exists($authFile) ? file_get_contents($authFile) : '';
        if (str_contains($existing, $publicKey)) {
            return ['ok' => true, 'message' => 'La clave ya esta instalada'];
        }

        // Append
        file_put_contents($authFile, $publicKey . "\n", FILE_APPEND);
        chmod($authFile, 0600);

        return ['ok' => true, 'message' => 'Clave publica instalada correctamente'];
    }

    /**
     * Test SSH connection to a remote host.
     */
    public static function testSshConnection(string $host, int $port = 22, string $keyPath = ''): array
    {
        if ($keyPath === '') {
            $keyPath = Settings::get('filesync_ssh_key_path', '/root/.ssh/id_ed25519');
        }

        $cmd = sprintf(
            'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -o BatchMode=yes -p %d -i %s root@%s "echo OK" 2>&1',
            $port,
            escapeshellarg($keyPath),
            escapeshellarg($host)
        );

        $output = trim((string)shell_exec($cmd));

        if ($output === 'OK') {
            return ['ok' => true, 'message' => 'Conexion SSH exitosa'];
        }

        return ['ok' => false, 'error' => 'SSH falló: ' . $output];
    }

    // ═══════════════════════════════════════════════════════════════
    // Method A: Rsync over SSH
    // ═══════════════════════════════════════════════════════════════

    /**
     * Sync a single hosting's httpdocs via rsync.
     */
    public static function rsyncHosting(string $localPath, string $remoteHost, string $remotePath, array $options = []): array
    {
        $config = self::getConfig();
        $port = $options['ssh_port'] ?? $config['ssh_port'];
        $keyPath = $options['ssh_key_path'] ?? $config['ssh_key_path'];
        $user = $options['ssh_user'] ?? $config['ssh_user'];
        $bwLimit = $options['bandwidth_limit'] ?? $config['bandwidth_limit'];
        $excludes = array_filter(array_map('trim', explode(',', $options['excludes'] ?? $config['exclude_patterns'])));

        if (!is_dir($localPath)) {
            return ['ok' => false, 'error' => "Directorio local no existe: {$localPath}"];
        }

        // Build rsync command
        $cmd = 'rsync -avz --delete';

        // Bandwidth limit
        if ($bwLimit > 0) {
            $cmd .= ' --bwlimit=' . (int)$bwLimit;
        }

        // Exclude patterns
        foreach ($excludes as $pattern) {
            $cmd .= ' --exclude=' . escapeshellarg($pattern);
        }

        // SSH options
        $sshCmd = sprintf(
            'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=30 -p %d -i %s',
            $port,
            escapeshellarg($keyPath)
        );
        $cmd .= ' -e ' . escapeshellarg($sshCmd);

        // Source and destination (ensure trailing slash on source)
        $cmd .= sprintf(
            ' %s/ %s@%s:%s/',
            escapeshellarg(rtrim($localPath, '/')),
            escapeshellarg($user),
            escapeshellarg($remoteHost),
            escapeshellarg(rtrim($remotePath, '/'))
        );

        $cmd .= ' 2>&1';

        $startTime = microtime(true);
        $output = shell_exec($cmd);
        $elapsed = round(microtime(true) - $startTime, 2);

        // Parse rsync exit code by checking output
        $success = $output !== null && !str_contains($output, 'rsync error');

        return [
            'ok' => $success,
            'output' => $output ?? '',
            'elapsed_seconds' => $elapsed,
            'method' => 'ssh',
        ];
    }

    /**
     * Sync all hostings via rsync to a remote host.
     */
    public static function rsyncAllHostings(string $remoteHost, array $options = []): array
    {
        $accounts = Database::fetchAll("SELECT * FROM hosting_accounts WHERE status = 'active' ORDER BY domain");
        $results = [];

        foreach ($accounts as $acc) {
            $localPath = ($acc['home_dir'] ?? '') . '/httpdocs';
            $remotePath = $localPath; // Same path on slave

            $result = self::rsyncHosting($localPath, $remoteHost, $remotePath, $options);
            $result['domain'] = $acc['domain'];
            $result['username'] = $acc['username'];
            $results[] = $result;
        }

        return $results;
    }

    // ═══════════════════════════════════════════════════════════════
    // Method B: HTTPS via Panel API
    // ═══════════════════════════════════════════════════════════════

    /**
     * Sync a hosting's files via HTTPS API (tar + POST).
     * Master packs files, sends to slave's API endpoint.
     */
    public static function httpsSyncHosting(string $localPath, string $apiUrl, string $token, string $remotePath): array
    {
        if (!is_dir($localPath)) {
            return ['ok' => false, 'error' => "Directorio local no existe: {$localPath}"];
        }

        // Create a temporary tar.gz of the directory
        $tmpFile = sys_get_temp_dir() . '/filesync_' . md5($localPath) . '_' . time() . '.tar.gz';
        $excludes = array_filter(array_map('trim', explode(',', self::getConfig()['exclude_patterns'])));

        $excludeArgs = '';
        foreach ($excludes as $pattern) {
            $excludeArgs .= ' --exclude=' . escapeshellarg($pattern);
        }

        $tarCmd = sprintf(
            'tar czf %s -C %s %s . 2>&1',
            escapeshellarg($tmpFile),
            escapeshellarg($localPath),
            $excludeArgs
        );
        shell_exec($tarCmd);

        if (!file_exists($tmpFile) || filesize($tmpFile) === 0) {
            @unlink($tmpFile);
            return ['ok' => false, 'error' => 'No se pudo crear el archivo tar'];
        }

        $fileSize = filesize($tmpFile);

        // Send via CURL multipart POST
        $url = rtrim($apiUrl, '/') . '/api/cluster/action';
        $startTime = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 600, // 10 min for large files
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => [
                'action' => 'receive-files',
                'remote_path' => $remotePath,
                'archive' => new \CURLFile($tmpFile, 'application/gzip', 'files.tar.gz'),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $elapsed = round(microtime(true) - $startTime, 2);
        @unlink($tmpFile);

        if ($error) {
            return ['ok' => false, 'error' => "CURL error: {$error}", 'elapsed_seconds' => $elapsed];
        }

        $data = @json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && ($data['ok'] ?? false)) {
            return [
                'ok' => true,
                'elapsed_seconds' => $elapsed,
                'file_size' => $fileSize,
                'method' => 'https',
            ];
        }

        return [
            'ok' => false,
            'error' => $data['error'] ?? "HTTP {$httpCode}: {$response}",
            'elapsed_seconds' => $elapsed,
        ];
    }

    /**
     * Receive files on the slave side (called by API).
     * Extracts uploaded tar.gz to the target directory.
     */
    public static function receiveFiles(string $remotePath, array $uploadedFile): array
    {
        if (empty($uploadedFile['tmp_name']) || !file_exists($uploadedFile['tmp_name'])) {
            return ['ok' => false, 'error' => 'No se recibio ningun archivo'];
        }

        // Security: validate remote_path is under /var/www/vhosts/
        $realPath = $remotePath;
        if (!str_starts_with($realPath, '/var/www/vhosts/')) {
            return ['ok' => false, 'error' => 'Ruta destino no permitida'];
        }

        // Ensure target directory exists
        if (!is_dir($remotePath)) {
            @mkdir($remotePath, 0755, true);
        }

        // Extract tar.gz to the directory
        $cmd = sprintf(
            'tar xzf %s -C %s 2>&1',
            escapeshellarg($uploadedFile['tmp_name']),
            escapeshellarg($remotePath)
        );
        $output = shell_exec($cmd);

        // Fix ownership based on the hosting account
        $parts = explode('/', trim($remotePath, '/'));
        // Path: /var/www/vhosts/domain.com/httpdocs
        if (count($parts) >= 4) {
            $domain = $parts[3]; // domain.com
            $account = Database::fetchOne(
                "SELECT username FROM hosting_accounts WHERE domain = :d",
                ['d' => $domain]
            );
            if ($account) {
                $username = $account['username'];
                shell_exec(sprintf(
                    'chown -R %s:%s %s 2>&1',
                    escapeshellarg($username),
                    escapeshellarg($username),
                    escapeshellarg($remotePath)
                ));
            }
        }

        return ['ok' => true, 'message' => 'Archivos extraidos correctamente'];
    }

    // ═══════════════════════════════════════════════════════════════
    // Unified sync method (picks SSH or HTTPS based on config)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Sync a single hosting to a node using the configured method.
     */
    public static function syncHostingToNode(array $account, array $node): array
    {
        $config = self::getConfig();
        $localPath = ($account['home_dir'] ?? '') . '/httpdocs';
        $remotePath = $localPath;

        if ($config['method'] === 'https') {
            // Decrypt token
            $token = ReplicationService::decryptPassword($node['auth_token'] ?? '');
            return self::httpsSyncHosting($localPath, $node['api_url'], $token, $remotePath);
        }

        // SSH method — extract host from API URL
        $host = self::extractHostFromUrl($node['api_url']);
        return self::rsyncHosting($localPath, $host, $remotePath);
    }

    /**
     * Sync all active hostings to a specific node.
     */
    public static function syncAllToNode(int $nodeId): array
    {
        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            return ['ok' => false, 'error' => 'Nodo no encontrado'];
        }

        $accounts = Database::fetchAll("SELECT * FROM hosting_accounts WHERE status = 'active' ORDER BY domain");
        $results = [];
        $ok = 0;
        $fail = 0;

        foreach ($accounts as $acc) {
            $result = self::syncHostingToNode($acc, $node);
            $result['domain'] = $acc['domain'];
            $results[] = $result;
            if ($result['ok']) $ok++; else $fail++;
        }

        return [
            'ok' => $fail === 0,
            'total' => count($accounts),
            'synced' => $ok,
            'failed' => $fail,
            'results' => $results,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // SSL Certificate Sync
    // ═══════════════════════════════════════════════════════════════

    /**
     * Find the Caddy data directory where certificates are stored.
     */
    public static function findCaddyCertDir(): string
    {
        // Check configured path first
        $configured = Settings::get('filesync_ssl_cert_path', '');
        if ($configured && is_dir($configured)) {
            return $configured;
        }

        // Common Caddy cert locations
        $paths = [
            '/var/lib/caddy/.local/share/caddy/certificates',
            '/root/.local/share/caddy/certificates',
            '/home/caddy/.local/share/caddy/certificates',
        ];

        // Try caddy environ
        $environ = shell_exec('caddy environ 2>/dev/null');
        if ($environ && preg_match('/caddy\.AppDataDir=(.+)/m', $environ, $m)) {
            $paths[] = trim($m[1]) . '/certificates';
        }

        foreach ($paths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     * Sync SSL certificates to a slave node.
     */
    public static function syncSslCerts(array $node): array
    {
        $certDir = self::findCaddyCertDir();
        if (!$certDir || !is_dir($certDir)) {
            return ['ok' => false, 'error' => 'No se encontro el directorio de certificados de Caddy'];
        }

        $config = self::getConfig();
        $host = self::extractHostFromUrl($node['api_url']);

        if ($config['method'] === 'https') {
            $token = ReplicationService::decryptPassword($node['auth_token'] ?? '');
            return self::httpsSyncHosting($certDir, $node['api_url'], $token, $certDir);
        }

        return self::rsyncHosting($certDir, $host, $certDir);
    }

    // ═══════════════════════════════════════════════════════════════
    // .env / wp-config.php DB_HOST Validation & Rewrite
    // ═══════════════════════════════════════════════════════════════

    /**
     * Scan a hosting's files for DB_HOST settings and check if they're localhost.
     * Returns warnings for any non-localhost DB_HOST values found.
     */
    public static function checkDbHostConfig(string $httpdocsPath): array
    {
        $warnings = [];
        $files = [];

        // Check .env files (Laravel, generic)
        $envFiles = glob($httpdocsPath . '/{.env,.env.production}', GLOB_BRACE) ?: [];
        foreach ($envFiles as $f) {
            $content = @file_get_contents($f);
            if ($content && preg_match('/^DB_HOST\s*=\s*(.+)$/m', $content, $m)) {
                $host = trim($m[1], " \t\n\r\"'");
                if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    $warnings[] = [
                        'file' => basename($f),
                        'path' => $f,
                        'current_value' => $host,
                        'type' => 'env',
                    ];
                }
                $files[] = $f;
            }
        }

        // Check wp-config.php (WordPress)
        $wpConfig = $httpdocsPath . '/wp-config.php';
        if (file_exists($wpConfig)) {
            $content = @file_get_contents($wpConfig);
            if ($content && preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"](.+?)['\"]/", $content, $m)) {
                $host = $m[1];
                if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    $warnings[] = [
                        'file' => 'wp-config.php',
                        'path' => $wpConfig,
                        'current_value' => $host,
                        'type' => 'wp',
                    ];
                }
                $files[] = $wpConfig;
            }
        }

        return ['files_checked' => $files, 'warnings' => $warnings];
    }

    /**
     * Rewrite DB_HOST to localhost in .env and wp-config.php files.
     * Called on the slave after receiving files.
     */
    public static function rewriteDbHost(string $httpdocsPath): array
    {
        $changes = [];

        // .env files
        $envFiles = glob($httpdocsPath . '/{.env,.env.production}', GLOB_BRACE) ?: [];
        foreach ($envFiles as $f) {
            $content = @file_get_contents($f);
            if ($content && preg_match('/^DB_HOST\s*=\s*(.+)$/m', $content, $m)) {
                $host = trim($m[1], " \t\n\r\"'");
                if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    $newContent = preg_replace('/^DB_HOST\s*=\s*.+$/m', 'DB_HOST=localhost', $content);
                    file_put_contents($f, $newContent);
                    $changes[] = ['file' => basename($f), 'old' => $host, 'new' => 'localhost'];
                }
            }
        }

        // wp-config.php
        $wpConfig = $httpdocsPath . '/wp-config.php';
        if (file_exists($wpConfig)) {
            $content = @file_get_contents($wpConfig);
            if ($content && preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"](.+?)['\"]/", $content, $m)) {
                $host = $m[1];
                if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    $newContent = preg_replace(
                        "/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"].+?['\"]\s*\)/",
                        "define('DB_HOST', 'localhost')",
                        $content
                    );
                    file_put_contents($wpConfig, $newContent);
                    $changes[] = ['file' => 'wp-config.php', 'old' => $host, 'new' => 'localhost'];
                }
            }
        }

        return $changes;
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Extract hostname/IP from an API URL like https://10.10.70.156:8444
     */
    public static function extractHostFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        return $parsed['host'] ?? '';
    }
}
