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
            'sync_mode'        => Settings::get('filesync_sync_mode', 'periodic'), // 'periodic' or 'lsyncd'
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
            'filesync_enabled', 'filesync_sync_mode', 'filesync_method',
            'filesync_ssh_port', 'filesync_ssh_key_path', 'filesync_ssh_user',
            'filesync_interval', 'filesync_bwlimit', 'filesync_exclude',
            'filesync_ssl_certs', 'filesync_ssl_cert_path', 'filesync_rewrite_dbhost',
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

        // Exclude patterns (glob-style from config)
        foreach ($excludes as $pattern) {
            $cmd .= ' --exclude=' . escapeshellarg($pattern);
        }

        // Specific path exclusions (from visual browser)
        $specificExclusions = array_filter(array_map('trim', explode("\n", Settings::get('filesync_exclusions_list', ''))));
        foreach ($specificExclusions as $excPath) {
            $cmd .= ' --exclude=' . escapeshellarg($excPath);
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
            $localPath = rtrim($acc['home_dir'] ?? '', '/');
            $remotePath = $localPath; // Same path on slave — full vhost root

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
        $specificExclusions = array_filter(array_map('trim', explode("\n", Settings::get('filesync_exclusions_list', ''))));

        $excludeArgs = '';
        foreach ($excludes as $pattern) {
            $excludeArgs .= ' --exclude=' . escapeshellarg($pattern);
        }
        foreach ($specificExclusions as $excPath) {
            $excludeArgs .= ' --exclude=' . escapeshellarg($excPath);
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
        // Sync entire vhost root — not just /httpdocs — for a faithful mirror
        $localPath = rtrim($homeDir, '/');
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
     * Sync entire /var/www/vhosts/ to a node — one rsync for everything.
     * This is the preferred method: mirrors ALL vhosts, not just panel-registered ones.
     */
    public static function syncVhostsToNode(array $node): array
    {
        $config = self::getConfig();
        $vhostsPath = '/var/www/vhosts';

        if (!is_dir($vhostsPath)) {
            return ['ok' => false, 'error' => 'Directorio /var/www/vhosts no existe'];
        }

        $host = self::extractHostFromUrl($node['api_url']);
        return self::rsyncHosting($vhostsPath, $host, $vhostsPath, []);
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
        // Check configured path first — trust it even without is_dir() since rsync runs as root
        $configured = Settings::get('filesync_ssl_cert_path', '');
        if ($configured) {
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

        // If no path is accessible (permission denied), but Caddy is running,
        // return the default path — rsync runs as root via SSH and CAN access it
        if (trim((string)shell_exec('pgrep -x caddy 2>/dev/null'))) {
            $caddyHome = '/var/lib/caddy';
            if (isset($caddyPasswd) && $caddyPasswd) {
                $parts = explode(':', $caddyPasswd);
                $caddyHome = $parts[5] ?? '/var/lib/caddy';
            }
            return $caddyHome . '/.local/share/caddy/certificates';
        }

        return '';
    }

    /**
     * Sync SSL certificates to a slave node.
     */
    public static function syncSslCerts(array $node): array
    {
        $certDir = self::findCaddyCertDir();
        if (!$certDir) {
            return ['ok' => false, 'error' => 'No se encontro el directorio de certificados de Caddy'];
        }

        $config = self::getConfig();
        $host = self::extractHostFromUrl($node['api_url']);

        if ($config['method'] === 'https') {
            $token = ReplicationService::decryptPassword($node['auth_token'] ?? '');
            // Send certs, slave will fix ownership to caddy:caddy
            $result = self::httpsSyncHosting($certDir, $node['api_url'], $token, $certDir, 'caddy');
        } else {
            // Direct rsync for SSL certs (panel runs as root, can read caddy dirs)
            $port = $config['ssh_port'] ?? 22;
            $keyPath = $config['ssh_key_path'] ?? '/root/.ssh/id_ed25519';
            $user = $config['ssh_user'] ?? 'root';
            $bwLimit = (int)($config['bandwidth_limit'] ?? 0);

            $cmd = 'rsync -avz --chown=caddy:caddy';
            if ($bwLimit > 0) {
                $cmd .= ' --bwlimit=' . $bwLimit;
            }
            $sshCmd = sprintf(
                'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=30 -p %d -i %s',
                $port, escapeshellarg($keyPath)
            );
            $cmd .= ' -e ' . escapeshellarg($sshCmd);
            $cmd .= sprintf(
                ' %s/ %s@%s:%s/ 2>&1',
                escapeshellarg(rtrim($certDir, '/')),
                escapeshellarg($user),
                escapeshellarg($host),
                escapeshellarg(rtrim($certDir, '/'))
            );

            $startTime = microtime(true);
            $output = trim((string)shell_exec($cmd));
            $elapsed = round(microtime(true) - $startTime, 2);

            $ok = (stripos($output, 'error') === false && stripos($output, 'rsync error') === false);
            $result = [
                'ok' => $ok,
                'elapsed_seconds' => $elapsed,
                'output' => $output,
            ];
            if (!$ok) {
                $result['error'] = $output;
            }
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

                // 1. Ensure DB and user exist
                file_put_contents($sqlOps, "CREATE DATABASE IF NOT EXISTS `{$dbName}`;\nGRANT ALL ON `{$dbName}`.* TO '{$dbUser}'@'localhost';\n");
                shell_exec(sprintf('%s < %s 2>/dev/null', $mysqlCmd, escapeshellarg($sqlOps)));

                // 2. Clean up any leftover temp DB from previous failed sync
                file_put_contents($sqlOps, "DROP DATABASE IF EXISTS `{$tempDb}`;\n");
                shell_exec(sprintf('%s < %s 2>/dev/null', $mysqlCmd, escapeshellarg($sqlOps)));

                // 3. Import directly into DB — dump already has DROP TABLE IF EXISTS per table
                // --force skips errors (e.g. GENERATED column inserts) and continues
                // sed: remove NO_AUTO_CREATE_USER + fix MySQL 8.0 collation
                $importCmd = sprintf(
                    'gunzip -c %s | sed "s/NO_AUTO_CREATE_USER,\\?//g; s/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g" | %s --force %s 2>&1',
                    escapeshellarg($file),
                    $mysqlCmd,
                    escapeshellarg($dbName)
                );
                $output = trim((string)shell_exec($importCmd));

                // 4. Verify import: check table count
                file_put_contents($sqlOps, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$dbName}' AND table_type = 'BASE TABLE';\n");
                $tableCount = (int)trim((string)shell_exec(sprintf('%s -N < %s 2>/dev/null', $mysqlCmd, escapeshellarg($sqlOps))));

                @unlink($sqlOps);
                $ok = ($tableCount > 0);
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

    /**
     * Recalculate disk_used_mb on a remote slave for all hosting accounts.
     * Runs a single SSH command that calculates `du -sm` for each home_dir
     * and returns the results, then updates the slave DB via its API.
     */
    public static function updateRemoteDiskUsage(array $node, array $accounts): array
    {
        $config = self::getConfig();
        $host = self::extractHostFromUrl($node['api_url']);
        $port = $config['ssh_port'] ?? 22;
        $keyPath = $config['ssh_key_path'] ?? '/root/.ssh/id_ed25519';
        $user = $config['ssh_user'] ?? 'root';

        // Build a single SSH command that runs du -sm on all home dirs
        $dirs = [];
        foreach ($accounts as $acc) {
            $homeDir = $acc['home_dir'] ?? '';
            if ($homeDir) {
                $dirs[] = escapeshellarg($homeDir);
            }
        }
        if (empty($dirs)) {
            return ['ok' => true, 'updated' => 0];
        }

        $duCmd = 'du -sm ' . implode(' ', $dirs) . ' 2>/dev/null';
        $sshCmd = sprintf(
            'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -p %d -i %s %s@%s %s 2>/dev/null',
            $port, escapeshellarg($keyPath), escapeshellarg($user),
            escapeshellarg($host), escapeshellarg($duCmd)
        );

        $output = trim((string)shell_exec($sshCmd));
        if (!$output) {
            return ['ok' => false, 'error' => 'SSH du command returned empty'];
        }

        // Parse du output: "765\t/var/www/vhosts/festgate.com"
        $diskMap = [];
        foreach (explode("\n", $output) as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);
            if (count($parts) === 2 && is_numeric($parts[0])) {
                $diskMap[rtrim($parts[1], '/')] = (int)$parts[0];
            }
        }

        // Update the slave's hosting_accounts via SSH + psql
        $updated = 0;
        $sqlParts = [];
        foreach ($accounts as $acc) {
            $homeDir = rtrim($acc['home_dir'] ?? '', '/');
            if (isset($diskMap[$homeDir])) {
                $mb = $diskMap[$homeDir];
                $domain = $acc['domain'];
                // Domain is safe — only alphanumeric, dots and hyphens
                $safeDomain = preg_replace('/[^a-zA-Z0-9.\-]/', '', $domain);
                $sqlParts[] = sprintf(
                    "UPDATE hosting_accounts SET disk_used_mb = %d WHERE domain = '%s';",
                    $mb, $safeDomain
                );
                $updated++;
            }
        }

        if (!empty($sqlParts)) {
            $sql = implode(' ', $sqlParts);
            $remotePsql = sprintf(
                'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -p %d -i %s %s@%s %s 2>/dev/null',
                $port, escapeshellarg($keyPath), escapeshellarg($user),
                escapeshellarg($host),
                escapeshellarg("psql -U musedock_panel -d musedock_panel -c " . escapeshellarg($sql))
            );
            shell_exec($remotePsql);
        }

        // Also update local (master) disk usage
        foreach ($accounts as $acc) {
            $homeDir = rtrim($acc['home_dir'] ?? '', '/');
            if ($homeDir && is_dir($homeDir)) {
                $localMb = (int)trim((string)shell_exec(sprintf('du -sm %s 2>/dev/null | cut -f1', escapeshellarg($homeDir))));
                if ($localMb > 0) {
                    Database::query(
                        "UPDATE hosting_accounts SET disk_used_mb = :mb WHERE id = :id",
                        ['mb' => $localMb, 'id' => (int)$acc['id']]
                    );
                }
            }
        }

        return ['ok' => true, 'updated' => $updated, 'disk_map' => $diskMap];
    }

    // ═══════════════════════════════════════════════════════════════
    // lsyncd — Real-time file sync
    // ═══════════════════════════════════════════════════════════════

    public static function isLsyncdInstalled(): bool
    {
        $path = trim((string)shell_exec('which lsyncd 2>/dev/null'));
        return $path !== '' && file_exists($path);
    }

    public static function installLsyncd(): array
    {
        $output = shell_exec('apt-get update -qq 2>&1 && apt-get install -y -qq lsyncd 2>&1');
        $installed = self::isLsyncdInstalled();
        if ($installed) {
            @mkdir('/etc/lsyncd', 0755, true);
        }
        return ['ok' => $installed, 'output' => $output];
    }

    public static function getLsyncdStatus(): array
    {
        if (!self::isLsyncdInstalled()) {
            return ['installed' => false, 'running' => false, 'enabled' => false];
        }
        $active = trim((string)shell_exec('systemctl is-active lsyncd 2>/dev/null')) === 'active';
        $enabled = trim((string)shell_exec('systemctl is-enabled lsyncd 2>/dev/null')) === 'enabled';
        $pid = $active ? trim((string)shell_exec('pgrep -x lsyncd 2>/dev/null')) : '';
        $logTail = '';
        if (file_exists('/var/log/lsyncd/lsyncd.log')) {
            $logTail = trim((string)shell_exec('tail -30 /var/log/lsyncd/lsyncd.log 2>/dev/null'));
        }
        return [
            'installed' => true,
            'running'   => $active,
            'enabled'   => $enabled,
            'pid'       => $pid,
            'log_tail'  => $logTail,
        ];
    }

    /**
     * Generate lsyncd.conf.lua that watches /var/www/vhosts/ and syncs to all slave nodes.
     */
    /**
     * Default exclusion patterns that are always applied to lsyncd/rsync.
     * These are volatile or non-essential paths that cause excessive CPU
     * when synced in real-time (IDE files, conversation logs, caches, etc.).
     */
    private const LSYNCD_DEFAULT_EXCLUDES = [
        '.vscode-server',
        '.claude',
        '.git',
        'node_modules',
        'storage/logs',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
    ];

    public static function generateLsyncdConfig(): array
    {
        $config = self::getConfig();
        $nodes = ClusterService::getNodes();
        // Filter out standby nodes — they should not receive any sync
        $nodes = array_filter($nodes, fn($n) => empty($n['standby']));
        if (empty($nodes)) {
            return ['ok' => false, 'error' => 'No hay nodos slave activos (todos en standby o ninguno configurado)'];
        }

        $port = $config['ssh_port'] ?? 22;
        $keyPath = $config['ssh_key_path'] ?? '/root/.ssh/id_ed25519';
        $user = $config['ssh_user'] ?? 'root';
        $bwLimit = $config['bandwidth_limit'] ?? 0;
        $userExcludes = array_filter(array_map('trim', explode(',', $config['exclude_patterns'] ?? '')));

        $lua = "-- lsyncd configuration — auto-generated by MuseDock Panel\n";
        $lua .= "-- Do not edit manually; changes will be overwritten.\n\n";
        $lua .= "settings {\n";
        $lua .= "    logfile    = \"/var/log/lsyncd/lsyncd.log\",\n";
        $lua .= "    statusFile = \"/var/log/lsyncd/lsyncd.status\",\n";
        $lua .= "    nodaemon   = false,\n";
        $lua .= "    maxProcesses = 2,\n";
        $lua .= "    insist     = true,\n";
        $lua .= "}\n\n";

        foreach ($nodes as $node) {
            $host = self::extractHostFromUrl($node['api_url']);
            $nodeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $node['name'] ?? 'node');

            $lua .= "-- Node: {$node['name']} ({$host})\n";
            $lua .= "sync {\n";
            $lua .= "    default.rsync,\n";
            $lua .= "    source = \"/var/www/vhosts/\",\n";
            $lua .= "    target = \"{$user}@{$host}:/var/www/vhosts/\",\n";
            $lua .= "    delay  = 15,\n";
            $lua .= "    delete = true,\n";

            // Merge built-in excludes + user pattern excludes + specific path exclusions
            $specificExclusions = array_filter(array_map('trim', explode("\n", Settings::get('filesync_exclusions_list', ''))));
            $allExcludes = array_unique(array_merge(self::LSYNCD_DEFAULT_EXCLUDES, $userExcludes, $specificExclusions));
            if (!empty($allExcludes)) {
                $lua .= "    exclude = {\n";
                foreach ($allExcludes as $ex) {
                    $lua .= "        \"" . addcslashes($ex, '"') . "\",\n";
                }
                $lua .= "    },\n";
            }

            $lua .= "    rsync = {\n";
            $lua .= "        binary   = \"/opt/musedock-panel/bin/rsync-nice\",\n";
            $lua .= "        archive  = true,\n";
            $lua .= "        compress = true,\n";
            $lua .= "        rsh      = \"ssh -o StrictHostKeyChecking=no -p {$port} -i {$keyPath}\",\n";
            if ($bwLimit > 0) {
                $lua .= "        bwlimit  = {$bwLimit},\n";
            }
            $lua .= "    },\n";
            $lua .= "}\n\n";
        }

        @mkdir('/etc/lsyncd', 0755, true);
        @mkdir('/var/log/lsyncd', 0755, true);
        $confPath = '/etc/lsyncd/lsyncd.conf.lua';
        $written = file_put_contents($confPath, $lua);

        if ($written === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir ' . $confPath];
        }

        return ['ok' => true, 'path' => $confPath, 'config' => $lua];
    }

    public static function startLsyncd(): array
    {
        // Regenerate config before starting
        $genResult = self::generateLsyncdConfig();
        if (!$genResult['ok']) {
            return $genResult;
        }

        shell_exec('systemctl enable lsyncd 2>&1');
        $output = shell_exec('systemctl restart lsyncd 2>&1');
        sleep(1);
        $status = self::getLsyncdStatus();

        return [
            'ok' => $status['running'],
            'output' => $output,
            'status' => $status,
        ];
    }

    public static function stopLsyncd(): array
    {
        shell_exec('systemctl stop lsyncd 2>&1');
        shell_exec('systemctl disable lsyncd 2>&1');
        sleep(1);
        $status = self::getLsyncdStatus();

        return [
            'ok' => !$status['running'],
            'status' => $status,
        ];
    }

    /**
     * Reload lsyncd config without stopping (e.g. after adding a new hosting/node).
     */
    public static function reloadLsyncd(): array
    {
        $genResult = self::generateLsyncdConfig();
        if (!$genResult['ok']) {
            return $genResult;
        }

        $output = shell_exec('systemctl restart lsyncd 2>&1');
        sleep(1);
        $status = self::getLsyncdStatus();

        return ['ok' => $status['running'], 'output' => $output, 'status' => $status];
    }
}
