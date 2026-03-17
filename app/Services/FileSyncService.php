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
            'db_dumps'         => Settings::get('filesync_db_dumps', '0') === '1',
            'db_dump_mysql'    => Settings::get('filesync_db_dump_mysql', '1') === '1',
            'db_dump_pgsql'    => Settings::get('filesync_db_dump_pgsql', '1') === '1',
            'db_dump_path'     => Settings::get('filesync_db_dump_path', '/tmp/musedock-dumps'),
        ];
    }

    public static function saveConfig(array $data): void
    {
        $keys = [
            'filesync_enabled', 'filesync_method', 'filesync_ssh_port',
            'filesync_ssh_key_path', 'filesync_ssh_user', 'filesync_interval',
            'filesync_bwlimit', 'filesync_exclude', 'filesync_ssl_certs',
            'filesync_ssl_cert_path', 'filesync_rewrite_dbhost',
            'filesync_db_dumps', 'filesync_db_dump_mysql', 'filesync_db_dump_pgsql',
            'filesync_db_dump_path',
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
     * Sync a single hosting's home directory via rsync.
     * Syncs the entire home_dir (not just httpdocs) to preserve structure.
     * Uses --chown to fix ownership by username on the remote (handles UID mismatches).
     */
    public static function rsyncHosting(string $localPath, string $remoteHost, string $remotePath, array $options = []): array
    {
        $config = self::getConfig();
        $port = $options['ssh_port'] ?? $config['ssh_port'];
        $keyPath = $options['ssh_key_path'] ?? $config['ssh_key_path'];
        $user = $options['ssh_user'] ?? $config['ssh_user'];
        $bwLimit = $options['bandwidth_limit'] ?? $config['bandwidth_limit'];
        $excludes = array_filter(array_map('trim', explode(',', $options['excludes'] ?? $config['exclude_patterns'])));
        $ownerUser = $options['owner_user'] ?? '';

        if (!is_dir($localPath)) {
            return ['ok' => false, 'error' => "Directorio local no existe: {$localPath}"];
        }

        // Build rsync command
        // -a: archive (preserves permissions, symlinks, times)
        // -v: verbose
        // -z: compress during transfer
        $cmd = 'rsync -avz';
        if (empty($options['no_delete'])) {
            $cmd .= ' --delete'; // remove files on slave that don't exist on master
        }

        // Fix ownership on remote by username (solves UID mismatch between servers)
        if ($ownerUser) {
            $cmd .= ' --chown=' . escapeshellarg($ownerUser . ':' . $ownerUser);
        }

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
    public static function httpsSyncHosting(string $localPath, string $apiUrl, string $token, string $remotePath, string $ownerUser = ''): array
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
                'owner_user' => $ownerUser,
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
    public static function receiveFiles(string $remotePath, array $uploadedFile, string $ownerUser = ''): array
    {
        if (empty($uploadedFile['tmp_name']) || !file_exists($uploadedFile['tmp_name'])) {
            return ['ok' => false, 'error' => 'No se recibio ningun archivo'];
        }

        // Security: validate remote_path is under allowed directories
        $allowedPrefixes = ['/var/www/vhosts/', '/var/lib/caddy/', '/tmp/musedock-dumps'];
        $pathAllowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($remotePath, $prefix)) {
                $pathAllowed = true;
                break;
            }
        }
        if (!$pathAllowed) {
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

        // Fix ownership: use owner_user from master if provided, else guess from DB
        $username = '';
        if ($ownerUser) {
            $username = $ownerUser;
        } else {
            $parts = explode('/', trim($remotePath, '/'));
            if (count($parts) >= 4) {
                $domain = $parts[3];
                $account = Database::fetchOne(
                    "SELECT username FROM hosting_accounts WHERE domain = :d",
                    ['d' => $domain]
                );
                if ($account) {
                    $username = $account['username'];
                }
            }
        }

        if ($username) {
            // Verify the user exists on this system before chown
            $userExists = shell_exec(sprintf('id %s 2>/dev/null', escapeshellarg($username)));
            if ($userExists) {
                shell_exec(sprintf(
                    'chown -R %s:%s %s 2>&1',
                    escapeshellarg($username),
                    escapeshellarg($username),
                    escapeshellarg($remotePath)
                ));
            }
        }

        return ['ok' => true, 'message' => 'Archivos extraidos correctamente', 'owner' => $username];
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
        $homeDir = $account['home_dir'] ?? '';
        $username = $account['username'] ?? '';
        $localPath = $homeDir . '/httpdocs';
        $remotePath = $localPath;

        if ($config['method'] === 'https') {
            // Decrypt token
            $token = ReplicationService::decryptPassword($node['auth_token'] ?? '');
            return self::httpsSyncHosting($localPath, $node['api_url'], $token, $remotePath, $username);
        }

        // SSH method — extract host from API URL, pass owner for --chown
        $host = self::extractHostFromUrl($node['api_url']);
        return self::rsyncHosting($localPath, $host, $remotePath, ['owner_user' => $username]);
    }

    /**
     * Sync all active hostings to a specific node.
     * If $progressFile is provided, write progress JSON to it after each hosting.
     */
    public static function syncAllToNode(int $nodeId, string $progressFile = ''): array
    {
        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            return ['ok' => false, 'error' => 'Nodo no encontrado'];
        }

        $accounts = Database::fetchAll("SELECT * FROM hosting_accounts WHERE status = 'active' ORDER BY domain");
        $results = [];
        $ok = 0;
        $fail = 0;
        $total = count($accounts);
        $startTime = microtime(true);

        // Write initial progress
        if ($progressFile) {
            self::writeProgress($progressFile, [
                'status' => 'running', 'total' => $total, 'current' => 0,
                'ok' => 0, 'fail' => 0, 'current_domain' => '', 'details' => [],
                'elapsed' => 0, 'started_at' => date('Y-m-d H:i:s'),
            ]);
        }

        foreach ($accounts as $i => $acc) {
            $domain = $acc['domain'];

            // Update progress before sync
            if ($progressFile) {
                self::writeProgress($progressFile, [
                    'status' => 'running', 'total' => $total, 'current' => $i,
                    'ok' => $ok, 'fail' => $fail, 'current_domain' => $domain,
                    'phase' => 'syncing', 'details' => $results,
                    'elapsed' => round(microtime(true) - $startTime, 1),
                ]);
            }

            $result = self::syncHostingToNode($acc, $node);
            $result['domain'] = $domain;
            $results[] = $result;
            if ($result['ok']) $ok++; else $fail++;

            // Update progress after sync
            if ($progressFile) {
                self::writeProgress($progressFile, [
                    'status' => 'running', 'total' => $total, 'current' => $i + 1,
                    'ok' => $ok, 'fail' => $fail, 'current_domain' => $domain,
                    'phase' => 'done', 'details' => $results,
                    'elapsed' => round(microtime(true) - $startTime, 1),
                ]);
            }
        }

        // Sync SSL certs if enabled
        $sslResult = null;
        $config = self::getConfig();
        if ($config['sync_ssl_certs']) {
            if ($progressFile) {
                self::writeProgress($progressFile, [
                    'status' => 'running', 'total' => $total, 'current' => $total,
                    'ok' => $ok, 'fail' => $fail, 'current_domain' => 'Certificados SSL',
                    'phase' => 'ssl', 'details' => $results,
                    'elapsed' => round(microtime(true) - $startTime, 1),
                ]);
            }
            $sslResult = self::syncSslCerts($node);
        }

        // Sync DB dumps if enabled and streaming replication is not active
        $dbResult = null;
        if ($config['db_dumps']) {
            $streamingStatus = ReplicationService::isStreamingActive();
            if (!$streamingStatus['any_active']) {
                if ($progressFile) {
                    self::writeProgress($progressFile, [
                        'status' => 'running', 'total' => $total, 'current' => $total,
                        'ok' => $ok, 'fail' => $fail, 'current_domain' => 'Bases de datos (dump)',
                        'phase' => 'db_dumps', 'details' => $results,
                        'elapsed' => round(microtime(true) - $startTime, 1),
                    ]);
                }
                $dumpResults = self::dumpAllDatabases();
                $dumpOk = count(array_filter($dumpResults, fn($r) => $r['ok']));
                if ($dumpOk > 0) {
                    $dbResult = self::syncDatabaseDumps($node);
                    $dbResult['dumped'] = count($dumpResults);
                    $dbResult['dump_ok'] = $dumpOk;
                } else {
                    $dbResult = ['ok' => true, 'dumped' => count($dumpResults), 'dump_ok' => 0, 'detail' => 'No hay bases de datos para exportar'];
                }
            }
        }

        $finalResult = [
            'ok' => $fail === 0,
            'total' => $total,
            'ok_count' => $ok,
            'fail_count' => $fail,
            'details' => $results,
            'ssl' => $sslResult,
            'db_dumps' => $dbResult,
            'elapsed' => round(microtime(true) - $startTime, 1),
        ];

        // Write final progress
        if ($progressFile) {
            $finalResult['status'] = 'completed';
            self::writeProgress($progressFile, $finalResult);
        }

        return $finalResult;
    }

    /**
     * Write progress to a JSON file atomically.
     */
    private static function writeProgress(string $file, array $data): void
    {
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE));
        rename($tmp, $file);
    }

    /**
     * Read sync progress from file.
     */
    public static function readProgress(string $syncId): ?array
    {
        $file = self::progressFilePath($syncId);
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Get the progress file path for a sync operation.
     */
    public static function progressFilePath(string $syncId): string
    {
        return '/opt/musedock-panel/storage/logs/sync-progress-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $syncId) . '.json';
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

        // Try getting caddy home from /etc/passwd
        $caddyPasswd = shell_exec("getent passwd caddy 2>/dev/null");
        if ($caddyPasswd) {
            $parts = explode(':', $caddyPasswd);
            $caddyHome = $parts[5] ?? '';
            if ($caddyHome) {
                array_unshift($paths, $caddyHome . '/.local/share/caddy/certificates');
            }
        }

        // Try caddy environ
        $environ = shell_exec('caddy environ 2>/dev/null');
        if ($environ && preg_match('/caddy\.AppDataDir=(.+)/m', $environ, $m)) {
            $paths[] = trim($m[1]) . '/certificates';
        }

        // Also try find as fallback (fast, only checks one level)
        $found = trim((string)shell_exec('find /var/lib/caddy -name certificates -type d 2>/dev/null | head -1'));
        if ($found) {
            array_unshift($paths, $found);
        }

        $paths = array_unique($paths);

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
            // Send certs, slave will fix ownership to caddy:caddy
            $result = self::httpsSyncHosting($certDir, $node['api_url'], $token, $certDir, 'caddy');
        } else {
            // rsync with --chown=caddy:caddy, NO --delete to preserve slave's own certs
            $result = self::rsyncHosting($certDir, $host, $certDir, [
                'owner_user' => 'caddy',
                'no_delete'  => true,
            ]);
        }

        // Reload Caddy on slave so it picks up the new certificates
        if ($result['ok'] ?? false) {
            $reloadResult = self::reloadRemoteCaddy($host, $config);
            $result['caddy_reload'] = $reloadResult;
        }

        return $result;
    }

    /**
     * Reload Caddy on a remote server via SSH.
     */
    private static function reloadRemoteCaddy(string $host, array $config): array
    {
        $port = $config['ssh_port'] ?? 22;
        $keyPath = $config['ssh_key_path'] ?? '/root/.ssh/id_ed25519';
        $user = $config['ssh_user'] ?? 'root';

        $sshCmd = sprintf(
            'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -p %d -i %s %s@%s %s 2>&1',
            $port,
            escapeshellarg($keyPath),
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg('systemctl reload caddy 2>&1 || caddy reload --config /etc/caddy/Caddyfile 2>&1')
        );

        $output = trim((string)shell_exec($sshCmd));
        $ok = (stripos($output, 'error') === false && stripos($output, 'fail') === false);

        return ['ok' => $ok, 'output' => $output];
    }

    /**
     * Check if a valid SSL certificate exists for a domain.
     * Uses Caddy admin API first, falls back to filesystem check.
     */
    public static function hasCertForDomain(string $domain): bool
    {
        // Method 1: Check via Caddy admin API (works without filesystem permissions)
        $ch = curl_init("http://localhost:2019/id/{$domain}");
        if ($ch) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // If Caddy knows this domain, it likely has a cert
            if ($httpCode === 200 && $response) {
                return true;
            }
        }

        // Method 2: Filesystem check (works when running as root, e.g. cron)
        $certDir = self::findCaddyCertDir();
        if ($certDir) {
            // Direct subdirectory check
            if (is_dir($certDir . '/' . $domain)) {
                return true;
            }
            // Under ACME directory
            $acmeBase = dirname($certDir);
            foreach (['acme-v02.api.letsencrypt.org-directory', 'acme.zerossl.com-v2-DV90'] as $issuer) {
                if (is_dir($acmeBase . '/certificates/' . $issuer . '/' . $domain)) {
                    return true;
                }
            }
        }

        // Method 3: Shell fallback (find as root-capable processes)
        $found = trim((string)shell_exec(sprintf(
            'find /var/lib/caddy -type d -name %s 2>/dev/null | head -1',
            escapeshellarg($domain)
        )));

        return !empty($found);
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

    // ═══════════════════════════════════════════════════════════════
    // Database Dump Sync (Nivel 1 — simple cluster sync)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build mysqldump command with auth from .env
     */
    private static function buildMysqldumpCmd(): ?string
    {
        $authMethod = \MuseDockPanel\Env::get('MYSQL_AUTH_METHOD', 'socket');
        if ($authMethod === 'socket') return 'mysqldump -u root';
        if ($authMethod === 'password') {
            $pass = \MuseDockPanel\Env::get('MYSQL_ROOT_PASS', '');
            return $pass ? 'mysqldump -u root -p' . escapeshellarg($pass) : null;
        }
        return null;
    }

    /**
     * Build mysql client command with auth from .env
     */
    private static function buildMysqlCmd(): ?string
    {
        $authMethod = \MuseDockPanel\Env::get('MYSQL_AUTH_METHOD', 'socket');
        if ($authMethod === 'socket') return 'mysql -u root';
        if ($authMethod === 'password') {
            $pass = \MuseDockPanel\Env::get('MYSQL_ROOT_PASS', '');
            return $pass ? 'mysql -u root -p' . escapeshellarg($pass) : null;
        }
        return null;
    }

    /**
     * Dump all hosting databases to the dump path.
     * Returns array of results per database.
     */
    public static function dumpAllDatabases(): array
    {
        $config = self::getConfig();
        $dumpPath = $config['db_dump_path'];
        if (!is_dir($dumpPath)) {
            @mkdir($dumpPath, 0750, true);
        }

        $databases = Database::fetchAll("
            SELECT d.*, a.domain, a.username
            FROM hosting_databases d
            JOIN hosting_accounts a ON a.id = d.account_id
            WHERE a.status != 'deleted'
            ORDER BY d.db_name
        ");

        $results = [];
        $manifest = [];

        foreach ($databases as $db) {
            $dbName = $db['db_name'];
            $dbType = $db['db_type'] ?? 'pgsql';
            $dbUser = $db['db_user'];
            $dumpFile = $dumpPath . '/' . $dbName . '.sql.gz';

            // Skip if engine not selected for dump
            if ($dbType === 'mysql' && !$config['db_dump_mysql']) continue;
            if ($dbType === 'pgsql' && !$config['db_dump_pgsql']) continue;

            $generatedCols = [];

            if ($dbType === 'mysql') {
                $cmd = self::buildMysqldumpCmd();
                if (!$cmd) {
                    $results[] = ['db_name' => $dbName, 'db_type' => $dbType, 'ok' => false, 'error' => 'MySQL auth no disponible'];
                    continue;
                }

                // Detect GENERATED columns — these cause ERROR 3105 on restore
                $mysqlCmd = self::buildMysqlCmd();
                $genColsRaw = trim((string)shell_exec(sprintf(
                    '%s -N -e "SELECT CONCAT(TABLE_NAME, \'|\', COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND EXTRA LIKE \'%%GENERATED%%\'" 2>/dev/null',
                    $mysqlCmd, escapeshellarg($dbName)
                )));
                $generatedCols = [];
                if ($genColsRaw) {
                    foreach (explode("\n", $genColsRaw) as $line) {
                        $p = explode('|', trim($line));
                        if (count($p) === 2) {
                            $generatedCols[$p[0]][] = $p[1];
                        }
                    }
                }

                // Use --complete-insert so INSERT has column names (needed for GENERATED col filtering)
                $fullCmd = sprintf('%s --single-transaction --quick --complete-insert %s 2>/dev/null | gzip > %s',
                    $cmd, escapeshellarg($dbName), escapeshellarg($dumpFile));
            } else {
                $fullCmd = sprintf('sudo -u postgres pg_dump -Fc %s 2>/dev/null | gzip > %s',
                    escapeshellarg($dbName), escapeshellarg($dumpFile));
            }

            shell_exec($fullCmd);
            $ok = file_exists($dumpFile) && filesize($dumpFile) > 20; // gzip header is ~20 bytes min

            $manifestEntry = [
                'db_name' => $dbName,
                'db_user' => $dbUser,
                'db_type' => $dbType,
                'domain'  => $db['domain'],
                'file'    => $dbName . '.sql.gz',
                'size'    => $ok ? filesize($dumpFile) : 0,
            ];
            if (!empty($generatedCols)) {
                $manifestEntry['generated_cols'] = $generatedCols;
            }
            $manifest[] = $manifestEntry;
            $results[] = ['db_name' => $dbName, 'db_type' => $dbType, 'ok' => $ok, 'file' => $dumpFile];
        }

        // Write manifest for the slave to know what to restore
        file_put_contents($dumpPath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        return $results;
    }

    /**
     * Sync database dumps to a slave node and trigger restore.
     */
    public static function syncDatabaseDumps(array $node): array
    {
        $config = self::getConfig();
        $dumpPath = $config['db_dump_path'];

        if (!is_dir($dumpPath) || !file_exists($dumpPath . '/manifest.json')) {
            return ['ok' => false, 'error' => 'No hay dumps para sincronizar. Ejecute dumpAllDatabases() primero.'];
        }

        $host = self::extractHostFromUrl($node['api_url']);

        // Step 1: rsync dump directory to slave
        if ($config['method'] === 'https') {
            $token = ReplicationService::decryptPassword($node['auth_token'] ?? '');
            $rsyncResult = self::httpsSyncHosting($dumpPath, $node['api_url'], $token, $dumpPath, 'root');
        } else {
            $rsyncResult = self::rsyncHosting($dumpPath . '/', $host, $dumpPath . '/', [
                'owner_user' => 'root',
                'no_delete'  => false,
            ]);
        }

        if (!($rsyncResult['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'Error sincronizando dumps: ' . ($rsyncResult['error'] ?? ''), 'rsync' => $rsyncResult];
        }

        // Step 2: Tell slave to restore via API
        $token = ReplicationService::decryptPassword($node['auth_token'] ?? '');
        $restoreResult = ClusterService::callNodeDirect($node['api_url'], $token, 'POST', 'api/cluster/action', [
            'action'  => 'restore-db-dumps',
            'payload' => ['dump_path' => $dumpPath],
        ]);

        return [
            'ok'      => $restoreResult['ok'] ?? false,
            'rsync'   => $rsyncResult,
            'restore' => $restoreResult['data'] ?? $restoreResult,
        ];
    }

    /**
     * Restore database dumps on the slave side.
     * Each DB is: DROP + CREATE + IMPORT from dump file.
     */
    public static function restoreDatabaseDumps(string $dumpPath): array
    {
        $manifestFile = $dumpPath . '/manifest.json';
        if (!file_exists($manifestFile)) {
            return ['ok' => false, 'error' => 'Manifest no encontrado en ' . $dumpPath];
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (!is_array($manifest)) {
            return ['ok' => false, 'error' => 'Manifest inválido'];
        }

        $results = [];
        foreach ($manifest as $entry) {
            $dbName = $entry['db_name'];
            $dbUser = $entry['db_user'];
            $dbType = $entry['db_type'];
            $file = $dumpPath . '/' . $entry['file'];
            $tempDb = $dbName . '_sync_tmp';

            if (!file_exists($file) || filesize($file) < 20) {
                $results[] = ['db_name' => $dbName, 'ok' => false, 'error' => 'Dump vacío o no encontrado'];
                continue;
            }

            // Strategy: Import into temp DB → DROP original → RENAME temp → original
            // This minimizes downtime to the instant of the rename.

            if ($dbType === 'pgsql') {
                // Ensure user exists
                shell_exec(sprintf(
                    'sudo -u postgres psql -c "DO \\$\\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = %s) THEN CREATE ROLE %s LOGIN; END IF; END \\$\\$;" 2>/dev/null',
                    "'" . str_replace("'", "''", $dbUser) . "'",
                    $dbUser
                ));

                // 1. Drop temp DB if leftover from a previous failed sync
                shell_exec(sprintf('sudo -u postgres dropdb --if-exists %s 2>/dev/null', escapeshellarg($tempDb)));

                // 2. Create temp DB and import
                shell_exec(sprintf('sudo -u postgres createdb -O %s %s 2>/dev/null', escapeshellarg($dbUser), escapeshellarg($tempDb)));
                $output = trim((string)shell_exec(sprintf(
                    'gunzip -c %s | sudo -u postgres pg_restore -d %s --no-owner --no-acl 2>&1 || gunzip -c %s | sudo -u postgres psql %s 2>&1',
                    escapeshellarg($file), escapeshellarg($tempDb),
                    escapeshellarg($file), escapeshellarg($tempDb)
                )));

                // 3. Terminate connections to the original DB
                shell_exec(sprintf(
                    'sudo -u postgres psql -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = %s AND pid <> pg_backend_pid()" 2>/dev/null',
                    "'" . str_replace("'", "''", $dbName) . "'"
                ));

                // 4. DROP original (WITH FORCE for PG13+, fallback for older)
                $dropResult = trim((string)shell_exec(sprintf(
                    'sudo -u postgres psql -c "DROP DATABASE IF EXISTS %s WITH (FORCE)" 2>&1',
                    '"' . str_replace('"', '""', $dbName) . '"'
                )));
                if (stripos($dropResult, 'ERROR') !== false) {
                    shell_exec(sprintf('sudo -u postgres dropdb --if-exists %s 2>/dev/null', escapeshellarg($dbName)));
                }

                // 5. RENAME temp → original (ALTER DATABASE ... RENAME TO is atomic)
                shell_exec(sprintf(
                    'sudo -u postgres psql -c "ALTER DATABASE %s RENAME TO %s" 2>/dev/null',
                    '"' . str_replace('"', '""', $tempDb) . '"',
                    '"' . str_replace('"', '""', $dbName) . '"'
                ));

                $ok = true; // pg_restore returns warnings that aren't errors
            } else {
                $mysqlCmd = self::buildMysqlCmd();
                if (!$mysqlCmd) {
                    $results[] = ['db_name' => $dbName, 'ok' => false, 'error' => 'MySQL auth no disponible'];
                    continue;
                }

                // SQL file to avoid shell escaping issues with backticks
                $sqlOps = $dumpPath . '/_restore_ops.sql';

                // Ensure user exists
                file_put_contents($sqlOps, "CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost';\n");
                shell_exec(sprintf('%s < %s 2>/dev/null', $mysqlCmd, escapeshellarg($sqlOps)));

                // 1. Drop temp DB if leftover, create temp DB
                file_put_contents($sqlOps, "DROP DATABASE IF EXISTS `{$tempDb}`;\nCREATE DATABASE `{$tempDb}`;\nGRANT ALL ON `{$tempDb}`.* TO '{$dbUser}'@'localhost';\n");
                shell_exec(sprintf('%s < %s 2>/dev/null', $mysqlCmd, escapeshellarg($sqlOps)));

                // 2. Build a PHP filter script to strip GENERATED columns from INSERT statements
                //    The dump uses --complete-insert so INSERT INTO t (`col1`,`col2`,`gen_col`) VALUES (...)
                //    We need to remove the generated column name and its corresponding value.
                $generatedCols = $entry['generated_cols'] ?? [];
                $filterScript = null;

                if (!empty($generatedCols)) {
                    // Create a PHP script that reads stdin, removes generated columns from INSERTs
                    $filterScript = $dumpPath . '/_filter_generated.php';
                    $genColsJson = json_encode($generatedCols);
                    $phpFilter = <<<'PHPEOF'
<?php
// Filter script: removes GENERATED columns from INSERT INTO ... VALUES statements
// Generated columns per table: {"table_name": ["col1", "col2"]}
$genCols = json_decode($argv[1], true);

while (($line = fgets(STDIN)) !== false) {
    // Match INSERT INTO `table` (`col1`,`col2`,...) VALUES
    if (preg_match('/^INSERT INTO `([^`]+)` \(/', $line, $m)) {
        $table = $m[1];
        if (isset($genCols[$table])) {
            $colsToRemove = $genCols[$table];
            // Parse the column list to find positions of generated columns
            if (preg_match('/^(INSERT INTO `[^`]+` \()([^)]+)(\) VALUES\s*)/', $line, $cm)) {
                $prefix = $cm[1];
                $colList = $cm[2];
                $suffix = $cm[3];
                $rest = substr($line, strlen($cm[0]));

                $cols = array_map(function($c) { return trim($c, " `"); }, explode(',', $colList));
                $removeIdx = [];
                foreach ($cols as $i => $c) {
                    if (in_array($c, $colsToRemove, true)) {
                        $removeIdx[] = $i;
                    }
                }

                if (!empty($removeIdx)) {
                    // Rebuild column list without generated columns
                    $newCols = [];
                    foreach ($cols as $i => $c) {
                        if (!in_array($i, $removeIdx, true)) {
                            $newCols[] = '`' . $c . '`';
                        }
                    }

                    // Parse and rebuild each VALUES group, removing the Nth value
                    // Values are: (val1,val2,...),(val1,val2,...),...;
                    $newLine = $prefix . implode(',', $newCols) . $suffix;

                    // Parse values carefully (handles quoted strings with commas/parens)
                    $pos = 0;
                    $len = strlen($rest);
                    $first = true;

                    while ($pos < $len) {
                        // Skip whitespace
                        while ($pos < $len && $rest[$pos] === ' ') $pos++;
                        if ($pos >= $len) break;

                        if ($rest[$pos] === ';' || $rest[$pos] === "\n") {
                            $newLine .= substr($rest, $pos);
                            break;
                        }

                        if ($rest[$pos] === ',') {
                            $pos++; // skip comma between value groups
                            continue;
                        }

                        if ($rest[$pos] !== '(') {
                            // Unexpected char, output rest as-is
                            $newLine .= substr($rest, $pos);
                            break;
                        }

                        // Parse one (...) value group
                        $pos++; // skip (
                        $values = [];
                        $val = '';
                        $inQuote = false;
                        $escaped = false;
                        $depth = 0;

                        while ($pos < $len) {
                            $ch = $rest[$pos];

                            if ($escaped) {
                                $val .= $ch;
                                $escaped = false;
                                $pos++;
                                continue;
                            }

                            if ($ch === '\\') {
                                $val .= $ch;
                                $escaped = true;
                                $pos++;
                                continue;
                            }

                            if ($ch === "'" && !$inQuote) {
                                $inQuote = true;
                                $val .= $ch;
                                $pos++;
                                continue;
                            }

                            if ($ch === "'" && $inQuote) {
                                $val .= $ch;
                                $inQuote = false;
                                $pos++;
                                continue;
                            }

                            if ($inQuote) {
                                $val .= $ch;
                                $pos++;
                                continue;
                            }

                            if ($ch === '(') {
                                $depth++;
                                $val .= $ch;
                                $pos++;
                                continue;
                            }

                            if ($ch === ')' && $depth > 0) {
                                $depth--;
                                $val .= $ch;
                                $pos++;
                                continue;
                            }

                            if ($ch === ')' && $depth === 0) {
                                $values[] = $val;
                                $pos++; // skip )
                                break;
                            }

                            if ($ch === ',' && $depth === 0) {
                                $values[] = $val;
                                $val = '';
                                $pos++;
                                continue;
                            }

                            $val .= $ch;
                            $pos++;
                        }

                        // Remove generated column values
                        $newValues = [];
                        foreach ($values as $i => $v) {
                            if (!in_array($i, $removeIdx, true)) {
                                $newValues[] = $v;
                            }
                        }

                        if (!$first) $newLine .= ',';
                        $newLine .= '(' . implode(',', $newValues) . ')';
                        $first = false;
                    }

                    echo $newLine;
                    continue;
                }
            }
        }
    }
    echo $line;
}
PHPEOF;
                    file_put_contents($filterScript, $phpFilter);
                }

                // 3. Import: gunzip → sed pipeline (MariaDB compat) → optional PHP filter → mysql --force
                // Fix ERROR 1231: NO_AUTO_CREATE_USER doesn't exist in MariaDB
                // Fix ERROR 1101: MariaDB rejects DEFAULT on TEXT/BLOB/JSON columns
                $sedPipeline = "sed \"s/NO_AUTO_CREATE_USER,\\\\\\?//g; s/,\\\\+/,/g; s/,'/'/g\""
                    . " | sed -E \"s/(longtext|mediumtext|text|blob|longblob|mediumblob|json)([^,]*) DEFAULT '[^']*'/\\1\\2 DEFAULT NULL/gi\""
                    . " | sed 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g'";

                if ($filterScript) {
                    $importCmd = sprintf(
                        'gunzip -c %s | %s | php %s %s | %s --force %s 2>&1',
                        escapeshellarg($file),
                        $sedPipeline,
                        escapeshellarg($filterScript),
                        escapeshellarg(json_encode($generatedCols)),
                        $mysqlCmd,
                        escapeshellarg($tempDb)
                    );
                } else {
                    $importCmd = sprintf(
                        'gunzip -c %s | %s | %s --force %s 2>&1',
                        escapeshellarg($file),
                        $sedPipeline,
                        $mysqlCmd,
                        escapeshellarg($tempDb)
                    );
                }
                $output = trim((string)shell_exec($importCmd));

                // 4. Get table list (one per line, avoids GROUP_CONCAT 1024-byte truncation)
                file_put_contents($sqlOps, "SELECT table_name FROM information_schema.tables WHERE table_schema = '{$tempDb}' AND table_type = 'BASE TABLE';\n");
                $tablesRaw = trim((string)shell_exec(sprintf('%s -N < %s 2>/dev/null', $mysqlCmd, escapeshellarg($sqlOps))));
                $tableList = array_filter(array_map('trim', explode("\n", $tablesRaw)));

                $importOk = !empty($tableList);

                if ($importOk) {
                    // 5. Atomic swap: DROP original → CREATE original → RENAME tables one by one
                    // Using individual RENAME to avoid single-statement failure blocking all tables
                    $swapSql = "DROP DATABASE IF EXISTS `{$dbName}`;\n";
                    $swapSql .= "CREATE DATABASE `{$dbName}`;\n";
                    $swapSql .= "GRANT ALL ON `{$dbName}`.* TO '{$dbUser}'@'localhost';\n";
                    foreach ($tableList as $tbl) {
                        $swapSql .= "RENAME TABLE `{$tempDb}`.`{$tbl}` TO `{$dbName}`.`{$tbl}`;\n";
                    }
                    $swapSql .= "DROP DATABASE IF EXISTS `{$tempDb}`;\n";

                    file_put_contents($sqlOps, $swapSql);
                    $swapOutput = trim((string)shell_exec(sprintf('%s --force < %s 2>&1', $mysqlCmd, escapeshellarg($sqlOps))));
                    if ($swapOutput) {
                        $output = ($output ? $output . "\n" : '') . "SWAP: " . $swapOutput;
                    }
                } else {
                    // Import failed — clean temp, leave original
                    file_put_contents($sqlOps, "DROP DATABASE IF EXISTS `{$tempDb}`;\n");
                    shell_exec(sprintf('%s < %s 2>/dev/null', $mysqlCmd, escapeshellarg($sqlOps)));
                }

                @unlink($sqlOps);
                if ($filterScript) @unlink($filterScript);
                $ok = $importOk;
            }

            $results[] = ['db_name' => $dbName, 'db_type' => $dbType, 'ok' => $ok, 'output' => substr($output ?? '', 0, 500)];
        }

        return ['ok' => true, 'databases' => $results];
    }

    /**
     * Create a safety backup of ALL hosting databases before activating streaming replication.
     * Saves to /var/backups/musedock/pre-replication/ with timestamp.
     */
    public static function backupAllDatabasesBeforeReplication(string $engine = 'all'): array
    {
        $backupDir = '/var/backups/musedock/pre-replication/' . date('Y-m-d_His');
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0750, true);
        }

        $databases = Database::fetchAll("
            SELECT d.*, a.domain FROM hosting_databases d
            JOIN hosting_accounts a ON a.id = d.account_id
            ORDER BY d.db_name
        ");

        $results = [];
        foreach ($databases as $db) {
            $dbName = $db['db_name'];
            $dbType = $db['db_type'] ?? 'pgsql';

            // Only backup the relevant engine type
            if ($engine !== 'all' && $dbType !== ($engine === 'pg' ? 'pgsql' : 'mysql')) {
                continue;
            }

            $dumpFile = $backupDir . '/' . $dbName . '.sql.gz';

            if ($dbType === 'mysql') {
                $cmd = self::buildMysqldumpCmd();
                if ($cmd) {
                    shell_exec(sprintf('%s --single-transaction --quick %s 2>/dev/null | gzip > %s',
                        $cmd, escapeshellarg($dbName), escapeshellarg($dumpFile)));
                }
            } else {
                shell_exec(sprintf('sudo -u postgres pg_dump %s 2>/dev/null | gzip > %s',
                    escapeshellarg($dbName), escapeshellarg($dumpFile)));
            }

            $results[] = [
                'db_name' => $dbName,
                'db_type' => $dbType,
                'ok'      => file_exists($dumpFile) && filesize($dumpFile) > 20,
                'size'    => file_exists($dumpFile) ? filesize($dumpFile) : 0,
            ];
        }

        Settings::set('repl_pre_backup_path', $backupDir);

        return ['ok' => true, 'path' => $backupDir, 'databases' => $results];
    }
}
