<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;
use MuseDockPanel\Services\LogService;

class MigrationController
{
    public function index(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Account not found.');
            Router::redirect('/accounts');
            return;
        }

        // Get subdomains for subdomain selector in DB migration step
        $subdomains = Database::fetchAll(
            'SELECT * FROM hosting_subdomains WHERE account_id = :aid ORDER BY subdomain',
            ['aid' => $params['id']]
        );

        View::render('accounts/migrate', [
            'layout' => 'main',
            'pageTitle' => 'Migrate: ' . $account['domain'],
            'account' => $account,
            'subdomains' => $subdomains,
        ]);
    }

    // ================================================================
    // Option 1: Download from URL
    // ================================================================

    public function fromUrl(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Account not found.');
            Router::redirect('/accounts');
            return;
        }

        $url = trim($_POST['url'] ?? '');
        $decompress = isset($_POST['decompress']);
        $targetDir = $account['document_root'];

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            Flash::set('error', 'URL invalida.');
            Router::redirect('/accounts/' . $params['id'] . '/migrate');
            return;
        }

        $filename = basename(parse_url($url, PHP_URL_PATH));
        $tmpFile = "/tmp/migration_{$account['username']}_{$filename}";

        shell_exec(sprintf('wget -q -O %s %s 2>&1', escapeshellarg($tmpFile), escapeshellarg($url)));

        if (!file_exists($tmpFile) || filesize($tmpFile) === 0) {
            Flash::set('error', 'Descarga fallida. Verifica la URL.');
            @unlink($tmpFile);
            Router::redirect('/accounts/' . $params['id'] . '/migrate');
            return;
        }

        $fileSize = round(filesize($tmpFile) / 1048576, 1);

        if ($decompress) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext === 'gz' || str_ends_with($filename, '.tar.gz') || str_ends_with($filename, '.tgz')) {
                shell_exec(sprintf('tar xzf %s -C %s 2>&1', escapeshellarg($tmpFile), escapeshellarg($targetDir)));
            } elseif ($ext === 'zip') {
                shell_exec(sprintf('unzip -o %s -d %s 2>&1', escapeshellarg($tmpFile), escapeshellarg($targetDir)));
            } else {
                shell_exec(sprintf('cp %s %s/ 2>&1', escapeshellarg($tmpFile), escapeshellarg($targetDir)));
            }
            shell_exec(sprintf('chown -R %s:www-data %s 2>&1', escapeshellarg($account['username']), escapeshellarg($targetDir)));
            @unlink($tmpFile);
            LogService::log('migration.url', $account['domain'], "Downloaded and extracted {$filename} ({$fileSize} MB)");
            Flash::set('success', "Descargado y extraido {$filename} ({$fileSize} MB) en {$targetDir}");
        } else {
            shell_exec(sprintf('mv %s %s/%s 2>&1', escapeshellarg($tmpFile), escapeshellarg($targetDir), escapeshellarg($filename)));
            shell_exec(sprintf('chown %s:www-data %s/%s 2>&1', escapeshellarg($account['username']), escapeshellarg($targetDir), escapeshellarg($filename)));
            LogService::log('migration.url', $account['domain'], "Downloaded {$filename} ({$fileSize} MB)");
            Flash::set('success', "Descargado {$filename} ({$fileSize} MB) en {$targetDir}");
        }

        Router::redirect('/accounts/' . $params['id'] . '/migrate');
    }

    // ================================================================
    // SSH Helpers
    // ================================================================

    private function sshExec(string $password, string $user, string $host, int $port, string $remoteCmd, int $timeout = 30): string
    {
        $cmd = sprintf(
            'timeout %d sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 -o ServerAliveInterval=5 -p %d %s@%s %s 2>&1',
            $timeout, escapeshellarg($password), $port, escapeshellarg($user), escapeshellarg($host), escapeshellarg($remoteCmd)
        );
        return shell_exec($cmd) ?? '';
    }

    /**
     * Create PostgreSQL user + database, import dump, fix ownership and grants.
     * Returns ['ok' => bool, 'logs' => [...], 'password' => string]
     */
    private function setupPostgresDb(string $dbName, string $dbUser, string $dbPass, string $dumpFile): array
    {
        $logs = [];
        $port = '5432'; // Hosting DBs go to cluster main

        // Create user (ignore "already exists")
        shell_exec(sprintf("sudo -u postgres psql -p %s -c %s 2>&1", $port,
            escapeshellarg("CREATE USER \"{$dbUser}\" WITH PASSWORD '{$dbPass}'")));
        // Always update password (in case user already existed with different password)
        shell_exec(sprintf("sudo -u postgres psql -p %s -c %s 2>&1", $port,
            escapeshellarg("ALTER USER \"{$dbUser}\" WITH PASSWORD '{$dbPass}'")));

        // Create database (ignore "already exists")
        shell_exec(sprintf("sudo -u postgres psql -p %s -c %s 2>&1", $port,
            escapeshellarg("CREATE DATABASE \"{$dbName}\" OWNER \"{$dbUser}\"")));

        // Grant connect
        shell_exec(sprintf("sudo -u postgres psql -p %s -c %s 2>&1", $port,
            escapeshellarg("GRANT ALL PRIVILEGES ON DATABASE \"{$dbName}\" TO \"{$dbUser}\"")));

        $logs[] = "BD {$dbName} y usuario {$dbUser} creados.";

        // Import dump (suppress "already exists" and "does not exist" noise)
        if (file_exists($dumpFile) && filesize($dumpFile) > 50) {
            $importOut = shell_exec(sprintf(
                'sudo -u postgres psql -p %s -d %s < %s 2>&1',
                $port, escapeshellarg($dbName), escapeshellarg($dumpFile)
            ));
            // Count real errors (not "already exists" or "does not exist")
            $realErrors = 0;
            if ($importOut) {
                foreach (explode("\n", $importOut) as $line) {
                    if (stripos($line, 'ERROR') !== false
                        && stripos($line, 'already exists') === false
                        && stripos($line, 'does not exist') === false) {
                        $realErrors++;
                        if ($realErrors <= 3) $logs[] = 'WARN: ' . trim(substr($line, 0, 200));
                    }
                }
            }
            $dumpSize = round(filesize($dumpFile) / 1048576, 1);
            $logs[] = "Dump importado ({$dumpSize} MB)" . ($realErrors > 0 ? " con {$realErrors} warning(s)." : '.');
        }

        // Fix ownership: transfer ALL tables, sequences, views to the local user
        $fixOwnership = "
            DO \$\$
            DECLARE r RECORD;
            BEGIN
                FOR r IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
                    EXECUTE 'ALTER TABLE public.' || quote_ident(r.tablename) || ' OWNER TO \"{$dbUser}\"';
                END LOOP;
                FOR r IN SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema = 'public' LOOP
                    EXECUTE 'ALTER SEQUENCE public.' || quote_ident(r.sequence_name) || ' OWNER TO \"{$dbUser}\"';
                END LOOP;
            END \$\$;
        ";
        shell_exec(sprintf("sudo -u postgres psql -p %s -d %s -c %s 2>&1",
            $port, escapeshellarg($dbName), escapeshellarg($fixOwnership)));

        // Grant all privileges on existing objects
        shell_exec(sprintf("sudo -u postgres psql -p %s -d %s -c %s 2>&1", $port, escapeshellarg($dbName),
            escapeshellarg("GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO \"{$dbUser}\"; GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO \"{$dbUser}\"; GRANT USAGE ON SCHEMA public TO \"{$dbUser}\";")));

        // Set default privileges for future objects
        shell_exec(sprintf("sudo -u postgres psql -p %s -d %s -c %s 2>&1", $port, escapeshellarg($dbName),
            escapeshellarg("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO \"{$dbUser}\"; ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO \"{$dbUser}\";")));

        $logs[] = 'Ownership y permisos corregidos.';

        // Verify connection with the actual user/password
        $verifyCmd = sprintf(
            'PGPASSWORD=%s psql -h 127.0.0.1 -p %s -U %s -d %s -c "SELECT COUNT(*) FROM pg_tables WHERE schemaname=\'public\';" -t 2>&1',
            escapeshellarg($dbPass), $port, escapeshellarg($dbUser), escapeshellarg($dbName)
        );
        $verifyOut = trim(shell_exec($verifyCmd) ?? '');
        $tableCount = (int)$verifyOut;

        if ($tableCount > 0) {
            $logs[] = "Verificacion OK: {$tableCount} tablas accesibles por {$dbUser}.";
        } else {
            $logs[] = "WARN: Verificacion de conexion fallo. Output: " . substr($verifyOut, 0, 200);
        }

        return ['ok' => $tableCount > 0, 'logs' => $logs, 'password' => $dbPass];
    }

    private function parseLaravelEnv(string $content): ?array
    {
        $values = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$key, $val] = explode('=', $line, 2);
            $values[trim($key)] = trim($val, " \t\n\r\0\x0B\"'");
        }
        $db = $values['DB_DATABASE'] ?? '';
        $user = $values['DB_USERNAME'] ?? '';
        if (empty($db) || empty($user)) return null;
        $driver = $values['DB_CONNECTION'] ?? 'mysql';
        return [
            'host' => $values['DB_HOST'] ?? '127.0.0.1',
            'port' => $values['DB_PORT'] ?? ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => $db,
            'username' => $user,
            'password' => $values['DB_PASSWORD'] ?? '',
            'driver' => $driver,
        ];
    }

    private function parseMuseDockEnv(string $content): ?array
    {
        $values = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$key, $val] = explode('=', $line, 2);
            $values[trim($key)] = trim($val, " \t\n\r\0\x0B\"'");
        }
        $db = $values['DB_NAME'] ?? '';
        $user = $values['DB_USER'] ?? '';
        if (empty($db) || empty($user)) return null;
        $driver = $values['DB_DRIVER'] ?? 'mysql';
        return [
            'host' => $values['DB_HOST'] ?? '127.0.0.1',
            'port' => $values['DB_PORT'] ?? ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => $db,
            'username' => $user,
            'password' => $values['DB_PASS'] ?? '',
            'driver' => $driver,
        ];
    }

    private function parseZendDbConfig(string $content): ?array
    {
        $extract = function (string $key) use ($content): string {
            if (preg_match("/'" . preg_quote($key) . "'\s*=>\s*'([^']*)'/", $content, $m)) return $m[1];
            return '';
        };
        $db = $extract('dbname');
        $user = $extract('username');
        if (empty($db) || empty($user)) return null;
        $port = $extract('port');
        return [
            'host' => $extract('host') ?: 'localhost',
            'port' => $port ?: '3306',
            'database' => $db,
            'username' => $user,
            'password' => $extract('password'),
        ];
    }

    /**
     * Parse WebTV CMS Config.inc.php (variables like $DB_NAME, $DB_USERNAME, $DB_PASSWORD)
     */
    private function parseWebTvConfig(string $content): ?array
    {
        $extract = function (string $varName) use ($content): string {
            // Match: $VAR_NAME = "value"; or $VAR_NAME = 'value';
            if (preg_match('/\$' . preg_quote($varName) . '\s*=\s*["\']([^"\']*)["\']/', $content, $m)) {
                return $m[1];
            }
            return '';
        };

        $db = $extract('DB_NAME');
        $user = $extract('DB_USERNAME');
        if (empty($db) || empty($user)) return null;

        $port = $extract('DB_PORT');
        // WebTV uses -1 to mean "default port"
        if (empty($port) || $port === '-1') $port = '3306';

        return [
            'host' => $extract('DB_HOST') ?: 'localhost',
            'port' => $port,
            'database' => $db,
            'username' => $user,
            'password' => $extract('DB_PASSWORD'),
        ];
    }

    private function parseWpConfig(string $content): ?array
    {
        $extract = function (string $name) use ($content): string {
            if (preg_match("/define\(\s*'" . preg_quote($name) . "'\s*,\s*'([^']*)'\s*\)/", $content, $m)) return $m[1];
            return '';
        };
        $db = $extract('DB_NAME');
        $user = $extract('DB_USER');
        if (empty($db) || empty($user)) return null;
        return [
            'host' => $extract('DB_HOST') ?: 'localhost',
            'port' => '3306',
            'database' => $db,
            'username' => $user,
            'password' => $extract('DB_PASSWORD'),
        ];
    }

    // ================================================================
    // Option 2: SSH Migration — AJAX endpoints
    // ================================================================

    /**
     * AJAX: Test SSH connection
     */
    public function testSsh(array $params): void
    {
        header('Content-Type: application/json');

        $sshHost = trim($_POST['ssh_host'] ?? '');
        $sshUser = trim($_POST['ssh_user'] ?? '');
        $sshPassword = $_POST['ssh_password'] ?? '';
        $sshPort = (int) ($_POST['ssh_port'] ?? 22);
        $remotePath = rtrim(trim($_POST['remote_path'] ?? ''), '/');
        $remoteDocRoot = trim($_POST['remote_docroot'] ?? '/httpdocs');

        if (empty($sshHost) || empty($sshUser) || empty($sshPassword)) {
            echo json_encode(['ok' => false, 'message' => 'Rellena host, usuario y password.']);
            return;
        }

        $testOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, 'echo "SSH_OK"');

        if (strpos($testOutput, 'SSH_OK') === false) {
            if (strpos($testOutput, 'Permission denied') !== false) {
                $msg = 'Credenciales incorrectas (Permission denied). Verifica usuario y password.';
            } elseif (strpos($testOutput, 'Connection refused') !== false) {
                $msg = "Puerto SSH {$sshPort} cerrado o no accesible en {$sshHost}.";
            } elseif (strpos($testOutput, 'Connection timed out') !== false || strpos($testOutput, 'timed out') !== false) {
                $msg = "Timeout conectando a {$sshHost}:{$sshPort}. El host no responde o hay firewall.";
            } elseif (strpos($testOutput, 'Could not resolve') !== false || strpos($testOutput, 'Name or service not known') !== false) {
                $msg = "No se puede resolver el host: {$sshHost}.";
            } else {
                $msg = 'Error SSH: ' . substr(trim($testOutput), 0, 200);
            }
            echo json_encode(['ok' => false, 'message' => $msg]);
            return;
        }

        $fullRemotePath = $remotePath . '/' . ltrim($remoteDocRoot, '/');
        $pathCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
            "test -d " . escapeshellarg($fullRemotePath) . " && echo 'PATH_OK' || echo 'PATH_FAIL'"
        );

        $pathResolved = false;
        $triedPaths = [$fullRemotePath];

        if (strpos($pathCheck, 'PATH_OK') !== false) {
            $pathResolved = true;
        } else {
            // Auto-detect: if domain is a subdomain (e.g. develop.laraflex.org),
            // try Plesk structure: /var/www/vhosts/laraflex.org/develop.laraflex.org/
            $domain = basename($remotePath);
            $parts = explode('.', $domain);
            if (count($parts) > 2) {
                // Try parent domain path with subdomain as subfolder
                $parentDomain = implode('.', array_slice($parts, 1));
                $altPaths = [];

                // Plesk style: /vhosts/parent.com/sub.parent.com/ (no httpdocs for subdomains)
                $altPaths[] = "/var/www/vhosts/{$parentDomain}/{$domain}";
                // Plesk style with httpdocs: /vhosts/parent.com/sub.parent.com/httpdocs/
                $altPaths[] = "/var/www/vhosts/{$parentDomain}/{$domain}/httpdocs";
                // Direct vhost without docroot subfolder
                if ($remoteDocRoot !== '') {
                    $altPaths[] = $remotePath;
                }

                foreach ($altPaths as $altPath) {
                    if (in_array($altPath, $triedPaths)) continue;
                    $triedPaths[] = $altPath;
                    $altCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
                        "test -d " . escapeshellarg($altPath) . " && echo 'PATH_OK' || echo 'PATH_FAIL'"
                    );
                    if (strpos($altCheck, 'PATH_OK') !== false) {
                        $fullRemotePath = $altPath;
                        // Update remotePath for vhost folder scanning
                        // If we found it inside parent, the vhost root is the parent
                        $remotePath = "/var/www/vhosts/{$parentDomain}";
                        $pathResolved = true;
                        break;
                    }
                }
            }
        }

        if (!$pathResolved) {
            $triedList = implode(', ', $triedPaths);
            echo json_encode(['ok' => false, 'message' => "Ruta remota no encontrada. Rutas probadas: {$triedList}. Ajusta la ruta manualmente en 'Ajustar'."]);
            return;
        }

        $statsOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
            "cd " . escapeshellarg($fullRemotePath) . " && echo FILES:\$(ls -1qA 2>/dev/null | wc -l) && echo SIZE:\$(du -sh . 2>/dev/null | cut -f1)"
        );

        $fileCount = 0;
        $dirSize = '?';
        if (preg_match('/FILES:(\d+)/', $statsOutput, $m)) $fileCount = (int) $m[1];
        if (preg_match('/SIZE:(\S+)/', $statsOutput, $m)) $dirSize = $m[1];

        // Detect project type + list vhost folders in ONE SSH call
        // This avoids N+1 SSH connections per subfolder
        // Detection order: MuseDock > WordPress > Zend > Laravel (last because .env can be non-Laravel)
        $scanScript = 'P=' . escapeshellarg($fullRemotePath) . '; '
            . 'if [ -f "$P/muse" ] && [ -f "$P/.env" ]; then echo "MAIN_PROJECT:MuseDock"; '
            . 'elif [ -f "$P/wp-config.php" ]; then echo "MAIN_PROJECT:WordPress"; '
            . 'elif [ -f "$P/application/settings/database.php" ]; then echo "MAIN_PROJECT:Zend"; '
            . 'elif [ -f "$P/config/Config.inc.php" ]; then echo "MAIN_PROJECT:WebTV"; '
            . 'elif [ -f "$P/.env" ] && grep -q "DB_DATABASE" "$P/.env" 2>/dev/null; then echo "MAIN_PROJECT:Laravel"; '
            . 'elif [ -f "$P/.env" ]; then echo "MAIN_PROJECT:EnvNoDb"; '
            . 'else echo "MAIN_PROJECT:Unknown"; fi; '
            . 'R=' . escapeshellarg($remotePath) . '; '
            . 'for d in "$R"/*/ "$R"/.*/; do '
            . '  [ -d "$d" ] || continue; '
            . '  name=$(basename "$d"); '
            . '  [ "$name" = "." ] || [ "$name" = ".." ] && continue; '
            . '  sz=$(du -sh "$d" 2>/dev/null | cut -f1); '
            . '  ht="no"; [ -d "$d/httpdocs" ] && ht="yes"; '
            . '  proj="none"; '
            . '  dp="$d"; [ "$ht" = "yes" ] && dp="$d/httpdocs"; '
            . '  if [ -f "$dp/muse" ] && [ -f "$dp/.env" ]; then proj="MuseDock"; '
            . '  elif [ -f "$dp/wp-config.php" ]; then proj="WordPress"; '
            . '  elif [ -f "$dp/application/settings/database.php" ]; then proj="Zend"; '
            . '  elif [ -f "$dp/config/Config.inc.php" ]; then proj="WebTV"; '
            . '  elif [ -f "$dp/.env" ] && grep -q "DB_DATABASE" "$dp/.env" 2>/dev/null; then proj="Laravel"; fi; '
            . '  echo "VFOLDER:${name}|${sz}|${ht}|${proj}"; '
            . 'done';
        $scanOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, $scanScript, 60);

        $projectInfo = 'Desconocido';
        if (preg_match('/MAIN_PROJECT:(\S+)/', $scanOutput, $mp)) {
            $pType = $mp[1];
            if ($pType === 'MuseDock') $projectInfo = 'MuseDock CMS (detectado muse + .env)';
            elseif ($pType === 'Laravel') $projectInfo = 'Laravel (detectado .env)';
            elseif ($pType === 'WordPress') $projectInfo = 'WordPress (detectado wp-config.php)';
            elseif ($pType === 'Zend') $projectInfo = 'Zend/SocialEngine (detectado application/settings/database.php)';
            elseif ($pType === 'WebTV') $projectInfo = 'WebTV CMS (detectado config/Config.inc.php)';
            elseif ($pType === 'EnvNoDb') $projectInfo = '.env sin credenciales BD (no es Laravel)';
        }

        // Parse vhost folders from scan output
        $vhostFolders = [];
        $systemFolders = ['httpdocs', 'httpsdocs', 'error_docs', 'statistics', 'cgi-bin', 'anon_ftp', 'conf', 'pd', 'web_users'];
        $accountRow = Database::fetchOne("SELECT domain FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        $accountDomain = $accountRow ? strtolower($accountRow['domain']) : '';
        if (preg_match_all('/VFOLDER:(.+)/', $scanOutput, $matches)) {
            foreach ($matches[1] as $line) {
                $parts = explode('|', trim($line));
                if (count($parts) < 4) continue;
                [$folder, $folderSize, $hasHt, $subProject] = $parts;

                $isSubdomain = str_contains($folder, '.') && preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/i', $folder);
                $isSystem = in_array($folder, $systemFolders, true);

                // Exclude the account's own domain from the subdomain list
                $isDomainItself = $accountDomain && strtolower($folder) === $accountDomain;

                if ((!$isSystem || $isSubdomain) && !$isDomainItself) {
                    $vhostFolders[] = [
                        'name'         => $folder,
                        'is_subdomain' => $isSubdomain,
                        'size'         => $folderSize ?: '?',
                        'has_httpdocs' => $hasHt === 'yes',
                        'project'      => $subProject !== 'none' ? $subProject : null,
                    ];
                }
            }
        }

        echo json_encode([
            'ok' => true, 'message' => 'Conexion SSH OK',
            'files' => $fileCount, 'size' => $dirSize,
            'project' => $projectInfo, 'path' => $fullRemotePath,
            'remote_path' => $remotePath,
            'vhost_folders' => $vhostFolders,
        ]);
    }

    /**
     * AJAX: Check local httpdocs content
     */
    public function checkLocal(array $params): void
    {
        header('Content-Type: application/json');
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) { echo json_encode(['has_content' => false]); return; }

        $targetDir = trim($_POST['local_target'] ?? '') ?: $account['document_root'];

        // Security: ensure target is within the hosting home directory
        $homeDir = $account['home_dir'];
        if (strpos(realpath($targetDir) ?: $targetDir, $homeDir) !== 0) {
            $targetDir = $account['document_root'];
        }

        $fileCount = 0;
        $dirSize = '0';
        if (is_dir($targetDir)) {
            $fileCount = (int) trim(shell_exec("find " . escapeshellarg($targetDir) . " -type f ! -name 'index.html' | wc -l 2>/dev/null") ?? '0');
            $dirSize = trim(shell_exec("du -sh " . escapeshellarg($targetDir) . " 2>/dev/null | cut -f1") ?? '0');
        }
        echo json_encode(['has_content' => $fileCount > 0, 'file_count' => $fileCount, 'size' => $dirSize]);
    }

    /**
     * POST: Save SSH migration params to session, return token for SSE stream
     */
    public function sshPrepare(array $params): void
    {
        header('Content-Type: application/json');

        $data = [
            'account_id' => (int) $params['id'],
            'ssh_host' => trim($_POST['ssh_host'] ?? ''),
            'ssh_user' => trim($_POST['ssh_user'] ?? ''),
            'ssh_password' => $_POST['ssh_password'] ?? '',
            'ssh_port' => (int) ($_POST['ssh_port'] ?? 22),
            'remote_path' => rtrim(trim($_POST['remote_path'] ?? ''), '/'),
            'remote_docroot' => trim($_POST['remote_docroot'] ?? '/httpdocs'),
            'remote_domain' => trim($_POST['remote_domain'] ?? ''),
            'local_target' => trim($_POST['local_target'] ?? ''),
            'include_db' => isset($_POST['include_db']),
            'exclude_vendor' => isset($_POST['exclude_vendor']),
            'copy_everything' => isset($_POST['copy_everything']),
            'migrate_subdomains' => $_POST['migrate_subdomains'] ?? [],
            'migrate_folders' => $_POST['migrate_folders'] ?? [],
        ];

        $streamToken = bin2hex(random_bytes(16));
        $_SESSION['migration_stream_' . $streamToken] = $data;

        echo json_encode(['ok' => true, 'token' => $streamToken]);
    }

    /**
     * Status file path for a migration
     */
    private function statusFilePath(string $token): string
    {
        return "/tmp/migration_status_{$token}.json";
    }

    /**
     * Write migration status to file (for resilience / reconnection)
     */
    private function writeStatus(string $token, array $status): void
    {
        file_put_contents($this->statusFilePath($token), json_encode($status, JSON_UNESCAPED_UNICODE));
    }

    /**
     * GET: Check migration status (for reconnection after page reload)
     */
    public function sshStatus(array $params): void
    {
        header('Content-Type: application/json');
        $token = $_GET['token'] ?? '';
        $statusFile = $this->statusFilePath($token);
        if (empty($token) || !file_exists($statusFile)) {
            echo json_encode(['active' => false]);
            return;
        }
        $status = json_decode(file_get_contents($statusFile), true) ?: ['active' => false];

        // Check if there's a pending DB dump that can be resumed
        if (!empty($status['active']) && empty($status['done'])) {
            $pendingDumps = glob('/tmp/mdpdb_*.sql');
            $status['has_pending_dump'] = !empty($pendingDumps);
            if ($status['has_pending_dump']) {
                $status['pending_dump_file'] = $pendingDumps[0];
                $status['pending_dump_size'] = round(filesize($pendingDumps[0]) / 1048576, 1);
            }
        }

        echo json_encode($status);
    }

    /**
     * POST: Cancel/dismiss a stale migration status (clears the status file)
     */
    public function sshCancel(array $params): void
    {
        header('Content-Type: application/json');
        $token = $_POST['token'] ?? '';
        if (!empty($token)) {
            $statusFile = $this->statusFilePath($token);
            if (file_exists($statusFile)) {
                @unlink($statusFile);
            }
        }
        echo json_encode(['ok' => true]);
    }

    /**
     * POST: Resume a stalled migration — imports pending DB dump if available
     * Returns JSON with result
     */
    public function sshResume(array $params): void
    {
        header('Content-Type: application/json');

        $token = $_POST['token'] ?? '';
        $accountId = (int)($params['id'] ?? 0);
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $accountId]);
        $logs = [];

        if (!$account) {
            echo json_encode(['ok' => false, 'error' => 'Cuenta no encontrada', 'logs' => []]);
            return;
        }

        $statusFile = $this->statusFilePath($token);
        $status = file_exists($statusFile) ? (json_decode(file_get_contents($statusFile), true) ?: []) : [];

        // Find pending dump (use most recent one)
        $pendingDumps = glob('/tmp/mdpdb_*.sql');
        if (empty($pendingDumps)) {
            @unlink($statusFile);
            echo json_encode(['ok' => false, 'error' => 'No hay dump SQL pendiente.', 'logs' => []]);
            return;
        }

        // Sort by modification time, newest first
        usort($pendingDumps, fn($a, $b) => filemtime($b) - filemtime($a));
        $dumpFile = $pendingDumps[0];
        $dumpSize = round(filesize($dumpFile) / 1048576, 1);
        $localDbName = str_replace(['.', '-'], '_', $account['username']) . '_db';
        $localDbUser = $account['username'];
        $localDbPass = $status['db_pass'] ?? bin2hex(random_bytes(12));

        $logs[] = "Retomando — dump pendiente: {$dumpSize} MB";

        // Detect DB type
        $head = file_get_contents($dumpFile, false, null, 0, 200);
        $isPostgres = str_contains($head, 'PostgreSQL') || str_contains($head, 'pg_dump');
        $dbType = $isPostgres ? 'pgsql' : 'mysql';

        if ($isPostgres) {
            $pgResult = $this->setupPostgresDb($localDbName, $localDbUser, $localDbPass, $dumpFile);
            $logs = array_merge($logs, $pgResult['logs']);
        } else {
            $sqlSetup = sprintf(
                "CREATE DATABASE IF NOT EXISTS `%s`; CREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s'; ALTER USER '%s'@'localhost' IDENTIFIED BY '%s'; GRANT ALL ON `%s`.* TO '%s'@'localhost'; FLUSH PRIVILEGES;",
                $localDbName, $localDbUser, $localDbPass, $localDbUser, $localDbPass, $localDbName, $localDbUser
            );
            $sqlTmp = tempnam('/tmp', 'mdp_sql_');
            file_put_contents($sqlTmp, $sqlSetup);
            $out = shell_exec(sprintf('mysql < %s 2>&1', escapeshellarg($sqlTmp)));
            @unlink($sqlTmp);
            if ($out && stripos($out, 'ERROR') !== false) {
                $logs[] = 'ERROR MySQL setup: ' . trim($out);
            }
            $logs[] = "BD {$localDbName} y usuario listos.";
            $logs[] = 'Importando dump SQL...';
            $importOut = shell_exec(sprintf('mysql %s < %s 2>&1', escapeshellarg($localDbName), escapeshellarg($dumpFile)));
            if ($importOut && stripos($importOut, 'ERROR') !== false) {
                $logs[] = 'WARN: ' . substr(trim($importOut), 0, 300);
            }
            $logs[] = "Dump importado ({$dumpSize} MB).";
        }
        @unlink($dumpFile);

        // Update .env / wp-config
        // If a subdomain was selected, find its local document_root for config update
        $targetSubdomain = trim($_POST['target_subdomain'] ?? '');
        $targetDir = $account['document_root'];

        if (!empty($targetSubdomain)) {
            // Find the local subdomain by FQDN
            $subRecord = Database::fetchOne(
                'SELECT document_root FROM hosting_subdomains WHERE account_id = :aid AND subdomain = :sub',
                ['aid' => $account['id'], 'sub' => $targetSubdomain]
            );
            if ($subRecord && !empty($subRecord['document_root'])) {
                $targetDir = $subRecord['document_root'];
                $logs[] = "Subdomain seleccionado: {$targetSubdomain} (local: {$targetDir})";
            }
        }

        if (str_ends_with($targetDir, '/public') && file_exists(dirname($targetDir) . '/.env')) {
            $targetDir = dirname($targetDir);
        }

        $configUpdated = false;
        if (file_exists($targetDir . '/.env')) {
            $env = file_get_contents($targetDir . '/.env');
            if (str_contains($env, 'DB_DATABASE')) {
                $dbHost = $isPostgresDb ? '127.0.0.1' : '127.0.0.1';
                $dbPort = $isPostgresDb ? '5432' : '3306';
                $env = preg_replace('/^DB_HOST=.*/m', "DB_HOST={$dbHost}", $env);
                if (preg_match('/^DB_PORT=.*/m', $env)) {
                    $env = preg_replace('/^DB_PORT=.*/m', "DB_PORT={$dbPort}", $env);
                }
                $env = preg_replace('/^DB_DATABASE=.*/m', "DB_DATABASE={$localDbName}", $env);
                $env = preg_replace('/^DB_USERNAME=.*/m', "DB_USERNAME={$localDbUser}", $env);
                $env = preg_replace('/^DB_PASSWORD=.*/m', "DB_PASSWORD={$localDbPass}", $env);
                // For PostgreSQL: update SSLMODE to disable (local connection doesn't need SSL)
                if ($isPostgresDb && preg_match('/^DB_SSLMODE=.*/m', $env)) {
                    $env = preg_replace('/^DB_SSLMODE=.*/m', 'DB_SSLMODE=prefer', $env);
                }
                $configUpdated = true;
            } elseif (str_contains($env, 'DB_NAME')) {
                $env = preg_replace('/^DB_HOST=.*/m', 'DB_HOST=localhost', $env);
                $env = preg_replace('/^DB_NAME=.*/m', "DB_NAME={$localDbName}", $env);
                $env = preg_replace('/^DB_USER=.*/m', "DB_USER={$localDbUser}", $env);
                $env = preg_replace('/^DB_PASS=.*/m', "DB_PASS={$localDbPass}", $env);
                $configUpdated = true;
            }
            if ($configUpdated) {
                file_put_contents($targetDir . '/.env', $env);
                $logs[] = ".env actualizado con credenciales locales en: {$targetDir}/.env";
            }
        }
        if (!$configUpdated && file_exists($targetDir . '/wp-config.php')) {
            $wp = file_get_contents($targetDir . '/wp-config.php');
            $wp = preg_replace("/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/", "define('DB_NAME', '{$localDbName}')", $wp);
            $wp = preg_replace("/define\(\s*['\"]DB_USER['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/", "define('DB_USER', '{$localDbUser}')", $wp);
            $wp = preg_replace("/define\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/", "define('DB_PASSWORD', '{$localDbPass}')", $wp);
            $wp = preg_replace("/define\(\s*['\"]DB_HOST['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/", "define('DB_HOST', 'localhost')", $wp);
            file_put_contents($targetDir . '/wp-config.php', $wp);
            $logs[] = 'wp-config.php actualizado.';
        }

        // Save to hosting_databases
        $existing = Database::fetchOne("SELECT id FROM hosting_databases WHERE account_id = :aid AND db_name = :db", ['aid' => $accountId, 'db' => $localDbName]);
        if (!$existing) {
            Database::insert('hosting_databases', [
                'account_id' => $accountId,
                'db_name' => $localDbName,
                'db_user' => $localDbUser,
                'db_type' => $dbType,
            ]);
        }

        $logs[] = 'Migracion de BD completada!';

        // Clean status file
        @unlink($statusFile);

        echo json_encode([
            'ok' => true,
            'logs' => $logs,
            'summary' => "BD importada: {$localDbName} ({$dumpSize} MB)",
            'db_pass' => $localDbPass,
        ]);
    }

    /**
     * GET: SSE stream — executes migration and sends real-time log lines
     */
    public function sshStream(array $params): void
    {
        $token = $_GET['token'] ?? '';
        $sessionKey = 'migration_stream_' . $token;

        if (empty($token) || empty($_SESSION[$sessionKey])) {
            header('Content-Type: text/plain');
            echo 'Invalid token';
            return;
        }

        $data = $_SESSION[$sessionKey];
        unset($_SESSION[$sessionKey]);

        // Close session early so it doesn't block other requests
        session_write_close();

        // Keep running even if client disconnects
        ignore_user_abort(true);
        set_time_limit(1800); // 30 min max

        // SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Disable output buffering
        while (ob_get_level()) ob_end_flush();
        ini_set('output_buffering', 'off');
        ini_set('zlib.output_compression', false);

        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $data['account_id']]);
        if (!$account) {
            $this->sendSSE('error', 'Cuenta no encontrada.', $_GET['token'] ?? null);
            $this->sendSSE('done', json_encode(['ok' => false]), $_GET['token'] ?? null);
            return;
        }

        $sshHost = $data['ssh_host'];
        $sshUser = $data['ssh_user'];
        $sshPassword = $data['ssh_password'];
        $sshPort = $data['ssh_port'];
        $remotePath = $data['remote_path'];
        $remoteDocRoot = $data['remote_docroot'];
        $remoteDomain = $data['remote_domain'];
        $includeDb = $data['include_db'];
        $excludeVendor = $data['exclude_vendor'];
        $copyEverything = $data['copy_everything'] ?? false;
        $targetDir = !empty($data['local_target']) ? $data['local_target'] : $account['document_root'];

        // Security: ensure target is within the hosting home directory
        $homeDir = $account['home_dir'];
        if (strpos(realpath($targetDir) ?: $targetDir, $homeDir) !== 0) {
            $targetDir = $account['document_root'];
        }

        // Create target directory if it doesn't exist
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
            @chown($targetDir, $account['username']);
        }

        $fullRemotePath = $remotePath . '/' . ltrim($remoteDocRoot, '/');

        if (empty($remoteDomain)) {
            if (preg_match('#/vhosts/([^/]+)#', $remotePath, $m)) {
                $remoteDomain = $m[1];
            } else {
                $remoteDomain = $sshHost;
            }
        }

        $fileToken = bin2hex(random_bytes(16));
        $backupName = "mdp_{$fileToken}.tar.gz";
        $remoteBackupPath = $fullRemotePath . '/' . $backupName;
        $localBackup = "/tmp/{$backupName}";
        $errors = [];
        $localDbPass = null;
        $localDbName = null;
        $localSize = 0;

        // Status token for resilience (stored in cookie via JS)
        $statusToken = $_GET['token'] ?? $fileToken;
        $this->writeStatus($statusToken, [
            'active' => true, 'step' => 'Iniciando', 'logs' => [],
            'progress' => 0, 'done' => false, 'result' => null,
            'account_id' => $data['account_id'],
        ]);

        $st = $statusToken; // shorthand

        // --- STEP 1: SSH connection ---
        $this->sendSSE('log', 'Conectando a ' . $sshUser . '@' . $sshHost . ':' . $sshPort . '...', $st);
        $testOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, 'echo "SSH_OK"');
        if (strpos($testOutput, 'SSH_OK') === false) {
            $this->sendSSE('error', 'No se pudo conectar por SSH: ' . substr($testOutput, 0, 200), $st);
            $this->sendSSE('done', json_encode(['ok' => false]), $st);
            return;
        }
        $this->sendSSE('log', 'SSH conectado correctamente.', $st);
        $this->sendSSE('step', 'Conexion SSH', $st);

        // --- STEP 2: Verify path ---
        $this->sendSSE('log', 'Verificando ruta remota: ' . $fullRemotePath, $st);
        $pathCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
            "test -d " . escapeshellarg($fullRemotePath) . " && echo 'PATH_OK' || echo 'PATH_FAIL'"
        );
        if (strpos($pathCheck, 'PATH_OK') === false) {
            $this->sendSSE('error', 'La ruta remota no existe: ' . $fullRemotePath, $st);
            $this->sendSSE('done', json_encode(['ok' => false]), $st);
            return;
        }
        $this->sendSSE('log', 'Ruta verificada.', $st);

        // --- STEP 2b: Detect if this is a vhost root (contains httpdocs/) or the project dir itself ---
        // If the remote path doesn't end in httpdocs and has an httpdocs/ subfolder, it's the vhost root.
        // The project config files (.env, wp-config.php) will be inside httpdocs/.
        $isVhostRoot = false;
        $projectSearchPath = $fullRemotePath;

        if (!preg_match('#/httpdocs/?$#', $fullRemotePath)) {
            $htCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
                "test -d " . escapeshellarg($fullRemotePath . '/httpdocs') . " && echo 'HAS_HTTPDOCS'"
            );
            if (str_contains($htCheck, 'HAS_HTTPDOCS')) {
                $isVhostRoot = true;
                $projectSearchPath = rtrim($fullRemotePath, '/') . '/httpdocs';
                $this->sendSSE('log', 'Ruta es vhost root. Proyecto buscado en httpdocs/.', $st);
            }
        }

        // --- STEP 3: Detect project ---
        $projectType = 'unknown';
        $dbCredentials = null;
        $hasComposer = false;

        $composerCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
            "test -f " . escapeshellarg($projectSearchPath . '/composer.json') . " && echo 'COMPOSER_EXISTS'"
        );
        $hasComposer = strpos($composerCheck, 'COMPOSER_EXISTS') !== false;

        if ($includeDb) {
            $this->sendSSE('log', 'Detectando tipo de proyecto...', $st);
            $this->sendSSE('step', 'Detectando proyecto', $st);

            // Detect project type — check all config files in one batch
            $detectScript = 'P=' . escapeshellarg($projectSearchPath) . '; '
                . 'test -f "$P/.env" && echo "ENV_EXISTS"; '
                . 'test -f "$P/muse" && echo "MUSE_EXISTS"; '
                . 'test -f "$P/wp-config.php" && echo "WP_EXISTS"; '
                . 'test -f "$P/application/settings/database.php" && echo "ZEND_EXISTS"; '
                . 'test -f "$P/config/Config.inc.php" && echo "WEBTV_EXISTS"';
            $detectOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, $detectScript);

            $hasEnv = strpos($detectOutput, 'ENV_EXISTS') !== false;
            $hasMuse = strpos($detectOutput, 'MUSE_EXISTS') !== false;
            $hasWp = strpos($detectOutput, 'WP_EXISTS') !== false;
            $hasZend = strpos($detectOutput, 'ZEND_EXISTS') !== false;
            $hasWebTv = strpos($detectOutput, 'WEBTV_EXISTS') !== false;

            // Try each project type in priority order, with fallback if credentials fail
            $envContent = null;
            if ($hasEnv) {
                $envContent = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "cat " . escapeshellarg($projectSearchPath . '/.env'));
            }

            if ($hasEnv && $hasMuse) {
                $projectType = 'musedock';
                $dbCredentials = $this->parseMuseDockEnv($envContent);
                if ($dbCredentials) {
                    $driver = $dbCredentials['driver'] ?? 'mysql';
                    $this->sendSSE('log', "Proyecto detectado: MuseDock CMS. BD: Driver={$driver}, DB={$dbCredentials['database']}, User={$dbCredentials['username']}", $st);
                } else {
                    $this->sendSSE('log', 'MuseDock detectado pero sin credenciales BD validas en .env.', $st);
                }
            }

            if (!$dbCredentials && $hasEnv) {
                // Try Laravel .env (DB_DATABASE, DB_USERNAME, DB_PASSWORD)
                $dbCredentials = $this->parseLaravelEnv($envContent);
                if ($dbCredentials) {
                    $projectType = 'laravel';
                    $this->sendSSE('log', "Proyecto detectado: Laravel. BD: DB={$dbCredentials['database']}, User={$dbCredentials['username']}", $st);
                }
                // If .env exists but has no DB credentials, fall through to other types
            }

            if (!$dbCredentials && $hasWp) {
                $wpContent = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "cat " . escapeshellarg($projectSearchPath . '/wp-config.php'));
                $dbCredentials = $this->parseWpConfig($wpContent);
                if ($dbCredentials) {
                    $projectType = 'wordpress';
                    $this->sendSSE('log', "Proyecto detectado: WordPress. BD: DB={$dbCredentials['database']}, User={$dbCredentials['username']}", $st);
                }
            }

            if (!$dbCredentials && $hasZend) {
                $zendContent = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "cat " . escapeshellarg($projectSearchPath . '/application/settings/database.php'));
                $dbCredentials = $this->parseZendDbConfig($zendContent);
                if ($dbCredentials) {
                    $projectType = 'zend';
                    $this->sendSSE('log', "Proyecto detectado: Zend/SocialEngine. BD: DB={$dbCredentials['database']}, User={$dbCredentials['username']}", $st);
                }
            }

            if (!$dbCredentials && $hasWebTv) {
                $webtvContent = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "cat " . escapeshellarg($projectSearchPath . '/config/Config.inc.php'));
                $dbCredentials = $this->parseWebTvConfig($webtvContent);
                if ($dbCredentials) {
                    $projectType = 'webtv';
                    $this->sendSSE('log', "Proyecto detectado: WebTV CMS. BD: DB={$dbCredentials['database']}, User={$dbCredentials['username']}", $st);
                }
            }

            if (!$dbCredentials) {
                if ($projectType === 'unknown') {
                    $this->sendSSE('log', 'No se detecto proyecto conocido. Solo archivos.', $st);
                } else {
                    $this->sendSSE('log', 'Proyecto detectado pero sin credenciales BD validas. Solo archivos.', $st);
                }
                $includeDb = false;
            }
        } else {
            $detectScript = 'P=' . escapeshellarg($fullRemotePath) . '; '
                . 'test -f "$P/.env" && echo "ENV_EXISTS"; '
                . 'test -f "$P/muse" && echo "MUSE_EXISTS"; '
                . 'test -f "$P/wp-config.php" && echo "WP_EXISTS"; '
                . 'test -f "$P/application/settings/database.php" && echo "ZEND_EXISTS"; '
                . 'test -f "$P/config/Config.inc.php" && echo "WEBTV_EXISTS"';
            $detectOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, $detectScript);
            if (strpos($detectOutput, 'MUSE_EXISTS') !== false && strpos($detectOutput, 'ENV_EXISTS') !== false) {
                $projectType = 'musedock';
            } elseif (strpos($detectOutput, 'WP_EXISTS') !== false) {
                $projectType = 'wordpress';
            } elseif (strpos($detectOutput, 'ZEND_EXISTS') !== false) {
                $projectType = 'zend';
            } elseif (strpos($detectOutput, 'ENV_EXISTS') !== false) {
                $projectType = 'laravel';
            }
        }

        if ($excludeVendor && !$hasComposer) {
            $this->sendSSE('log', 'AVISO: Se pidio omitir vendor/ pero no hay composer.json. Se copiara todo.', $st);
            $excludeVendor = false;
        }

        // --- STEP 4: Create backup ---
        // If vhost root mode, adjust the local target to home_dir so httpdocs/ extracts correctly
        if ($isVhostRoot) {
            $targetDir = $homeDir;
            $this->sendSSE('log', 'Modo vhost root: extraccion en ' . $targetDir, $st);
        }

        $this->sendSSE('log', 'Creando backup en servidor remoto...', $st);
        $this->sendSSE('step', 'Creando backup', $st);

        $excludeArgs = "--exclude=" . escapeshellarg($backupName);
        if ($copyEverything) {
            $this->sendSSE('log', 'Modo copia completa: sin exclusiones (todo se copiara).', $st);
        } else {
            // Exclude dev/IDE directories by default
            $excludeArgs .= " --exclude='./.claude' --exclude='./.vscode-server' --exclude='./.codex' --exclude='./.cline' --exclude='./.copilot'";
            if ($excludeVendor) {
                $excludeArgs .= " --exclude='./vendor' --exclude='./httpdocs/vendor'";
                $this->sendSSE('log', 'Excluyendo vendor/ del backup.', $st);
            }
        }

        $tarCmd = "cd " . escapeshellarg($fullRemotePath) . " && tar czf " . escapeshellarg($remoteBackupPath) . " {$excludeArgs} . 2>&1; echo TAR_EXIT_\$?";
        $tarOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, $tarCmd, 900);

        // tar exit code 1 = "file changed as we read it" — this is a warning, not an error
        // Only exit code 2+ is a real failure
        $tarExitCode = 2; // assume failure
        if (preg_match('/TAR_EXIT_(\d+)/', $tarOutput, $tarM)) {
            $tarExitCode = (int) $tarM[1];
        }

        if ($tarExitCode === 1) {
            // Log specific tar warnings (permission denied, cannot read, etc.)
            $cleanTarOutput = preg_replace('/TAR_EXIT_\d+/', '', $tarOutput);
            $tarLines = array_filter(array_map('trim', explode("\n", $cleanTarOutput)));
            $permDenied = array_filter($tarLines, fn($l) => stripos($l, 'Permission denied') !== false || stripos($l, 'Cannot open') !== false);
            if (!empty($permDenied)) {
                $skippedCount = count($permDenied);
                $this->sendSSE('log', "AVISO: {$skippedCount} archivo(s) no se pudieron leer por permisos en el servidor remoto.", $st);
                // Show first few for diagnosis
                foreach (array_slice($permDenied, 0, 5) as $line) {
                    $this->sendSSE('log', '  > ' . substr($line, 0, 200), $st);
                }
                if ($skippedCount > 5) {
                    $this->sendSSE('log', "  > ... y " . ($skippedCount - 5) . " mas.", $st);
                }
            } else {
                $this->sendSSE('log', 'AVISO: Algunos archivos cambiaron durante la compresion (normal en sitios activos). Backup creado correctamente.', $st);
            }
        } elseif ($tarExitCode >= 2) {
            // Verify backup file exists and has size on remote
            $checkBackup = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
                "test -f " . escapeshellarg($remoteBackupPath) . " && stat -c%s " . escapeshellarg($remoteBackupPath) . " 2>/dev/null || echo 'NO_FILE'"
            );
            if (strpos($checkBackup, 'NO_FILE') !== false || (int) trim($checkBackup) < 100) {
                $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "rm -f " . escapeshellarg($remoteBackupPath));
                $cleanOutput = preg_replace('/TAR_EXIT_\d+/', '', $tarOutput);
                $this->sendSSE('error', 'Error creando backup: ' . substr(trim($cleanOutput), 0, 300), $st);
                $this->sendSSE('done', json_encode(['ok' => false]), $st);
                return;
            }
            // File exists despite exit code — proceed with warning, log details
            $cleanTarOutput2 = preg_replace('/TAR_EXIT_\d+/', '', $tarOutput);
            $tarErrLines = array_filter(array_map('trim', explode("\n", $cleanTarOutput2)));
            $this->sendSSE('log', 'AVISO: tar reporto errores (exit ' . $tarExitCode . ') pero el backup se creo. Continuando...', $st);
            foreach (array_slice($tarErrLines, 0, 5) as $errLine) {
                if (!empty($errLine)) $this->sendSSE('log', '  > ' . substr($errLine, 0, 200), $st);
            }
        }

        $remoteSizeOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "stat -c%s " . escapeshellarg($remoteBackupPath) . " 2>/dev/null || echo 0");
        $remoteSizeBytes = (int) trim($remoteSizeOutput);
        $remoteSize = round($remoteSizeBytes / 1048576, 1);
        $this->sendSSE('log', 'Backup creado: ' . $remoteSize . ' MB', $st);

        // --- STEP 5: Download via SCP (priority) then HTTP fallback ---
        $downloaded = false;

        // Try SCP first (most reliable for large files and non-public paths)
        $this->sendSSE('log', 'Descargando por SCP...', $st);
        $this->sendSSE('step', 'Descargando por SCP (' . $remoteSize . ' MB)', $st);
        $this->sendSSE('progress', json_encode(['type' => 'download', 'total' => $remoteSizeBytes, 'current' => 0]), $st);

        $scpCmd = sprintf(
            'sshpass -p %s scp -o StrictHostKeyChecking=no -o ConnectTimeout=30 -P %d %s@%s:%s %s 2>&1',
            escapeshellarg($sshPassword), $sshPort,
            escapeshellarg($sshUser), escapeshellarg($sshHost),
            escapeshellarg($remoteBackupPath), escapeshellarg($localBackup)
        );
        shell_exec("timeout 900 {$scpCmd}");
        clearstatcache(true, $localBackup);
        $downloaded = file_exists($localBackup) && filesize($localBackup) > 0;
        if ($downloaded) {
            $localScp = round(filesize($localBackup) / 1048576, 1);
            $this->sendSSE('log', "SCP completado: {$localScp} MB.", $st);
        }

        // HTTP fallback if SCP failed
        if (!$downloaded) {
            $this->sendSSE('log', 'SCP fallo. Intentando descarga HTTP...', $st);
            $downloadUrls = [
                "https://{$remoteDomain}/{$backupName}",
            ];
            if ($sshHost !== $remoteDomain) {
                $downloadUrls[] = "https://{$sshHost}/{$backupName}";
            }
            $downloadUrls[] = "http://{$remoteDomain}/{$backupName}";
            if ($sshHost !== $remoteDomain) {
                $downloadUrls[] = "http://{$sshHost}/{$backupName}";
            }

            foreach ($downloadUrls as $i => $downloadUrl) {
                $this->sendSSE('log', 'Intentando: ' . $downloadUrl, $st);
                $this->sendSSE('step', 'Descargando (' . $remoteSize . ' MB)', $st);
                $this->sendSSE('progress', json_encode(['type' => 'download', 'total' => $remoteSizeBytes, 'current' => 0]), $st);

                @unlink($localBackup);
                $downloaded = $this->downloadWithProgress($downloadUrl, $localBackup, $remoteSizeBytes, $st);
                if ($downloaded) break;

                $this->sendSSE('log', 'Fallo descarga desde ' . parse_url($downloadUrl, PHP_URL_HOST) . '. Probando siguiente...', $st);
            }
        }

        if (!$downloaded) {
            $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "rm -f " . escapeshellarg($remoteBackupPath));
            @unlink($localBackup);
            $this->sendSSE('error', 'Error descargando backup. Intentados: HTTPS, HTTP y SCP. Verifica que el servidor remoto sea accesible.', $st);
            $this->sendSSE('done', json_encode(['ok' => false]), $st);
            return;
        }

        $this->sendSSE('progress', json_encode(['type' => 'download', 'total' => $remoteSizeBytes, 'current' => $remoteSizeBytes, 'percent' => 100]), $st);
        clearstatcache(true, $localBackup);
        $localSizeBytes = filesize($localBackup);
        $localSize = round($localSizeBytes / 1048576, 1);
        $this->sendSSE('log', 'Backup descargado: ' . $localSize . ' MB', $st);

        // Verify download integrity: compare size with remote
        if ($remoteSizeBytes > 0 && $localSizeBytes < $remoteSizeBytes * 0.95) {
            $pct = round($localSizeBytes / $remoteSizeBytes * 100, 1);
            $this->sendSSE('error', "Descarga incompleta: {$localSize}MB de {$remoteSize}MB ({$pct}%). Reintentando por SCP...", $st);
            @unlink($localBackup);

            // Force SCP retry for incomplete downloads
            $scpCmd = sprintf(
                'sshpass -p %s scp -o StrictHostKeyChecking=no -o ConnectTimeout=30 -P %d %s@%s:%s %s 2>&1',
                escapeshellarg($sshPassword), $sshPort,
                escapeshellarg($sshUser), escapeshellarg($sshHost),
                escapeshellarg($remoteBackupPath), escapeshellarg($localBackup)
            );
            shell_exec("timeout 900 {$scpCmd}");
            clearstatcache(true, $localBackup);

            if (file_exists($localBackup) && filesize($localBackup) >= $remoteSizeBytes * 0.95) {
                $localSizeBytes = filesize($localBackup);
                $localSize = round($localSizeBytes / 1048576, 1);
                $this->sendSSE('log', "SCP completado: {$localSize} MB. Descarga verificada.", $st);
            } else {
                $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "rm -f " . escapeshellarg($remoteBackupPath));
                @unlink($localBackup);
                $this->sendSSE('error', 'Descarga incompleta incluso por SCP. Verifica conexion y espacio.', $st);
                $this->sendSSE('done', json_encode(['ok' => false]), $st);
                return;
            }
        }

        // Delete from remote
        $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "rm -f " . escapeshellarg($remoteBackupPath));
        $this->sendSSE('log', 'Backup eliminado del servidor remoto.', $st);

        // Verify tar integrity before extracting
        $tarTestOutput = shell_exec(sprintf('tar tzf %s > /dev/null 2>&1; echo $?', escapeshellarg($localBackup)));
        $tarTestExit = (int) trim($tarTestOutput ?? '1');
        if ($tarTestExit !== 0) {
            @unlink($localBackup);
            $this->sendSSE('error', 'El archivo tar.gz esta corrupto (descarga incompleta o error de compresion). Intenta migrar de nuevo.', $st);
            $this->sendSSE('done', json_encode(['ok' => false]), $st);
            return;
        }

        // --- STEP 6: Extract ---
        $this->sendSSE('log', 'Descomprimiendo en ' . $targetDir . '...', $st);
        $this->sendSSE('step', 'Descomprimiendo', $st);
        $this->sendSSE('progress', json_encode(['type' => 'extract', 'indeterminate' => true]), $st);

        // Extract as root (panel user may not have write perms to target dir), then chown
        $extractOutput = shell_exec(sprintf('tar xzf %s -C %s 2>&1',
            escapeshellarg($localBackup), escapeshellarg($targetDir)));
        if (!empty(trim($extractOutput ?? ''))) {
            $this->sendSSE('log', 'tar: ' . substr(trim($extractOutput), 0, 300), $st);
        }

        // Fix ownership
        shell_exec(sprintf('chown -R %s:www-data %s 2>&1',
            escapeshellarg($account['username']), escapeshellarg($targetDir)));

        // Verify extraction worked
        clearstatcache();
        $extractedCount = (int) trim(shell_exec("find " . escapeshellarg($targetDir) . " -type f ! -name 'index.html' | wc -l 2>/dev/null") ?? '0');

        $this->sendSSE('log', 'Archivos descomprimidos: ' . $extractedCount . ' archivos.', $st);
        $this->sendSSE('progress', json_encode(['type' => 'extract', 'percent' => 100]), $st);

        if ($extractedCount === 0) {
            $errors[] = 'Extraccion posiblemente fallida (0 archivos nuevos)';
            $this->sendSSE('error', 'AVISO: No se detectaron archivos nuevos tras la extraccion. Revisa manualmente.', $st);
        }

        // --- STEP 6a: WordPress core integrity check ---
        if ($projectType === 'wordpress' && file_exists($targetDir . '/wp-config.php')) {
            $wpCoreFiles = ['index.php', 'wp-load.php', 'wp-login.php', 'wp-settings.php', 'wp-blog-header.php', 'wp-cron.php'];
            $wpCoreDirs = ['wp-includes', 'wp-admin'];
            $missingFiles = [];
            $missingDirs = [];

            foreach ($wpCoreFiles as $f) {
                if (!file_exists($targetDir . '/' . $f)) $missingFiles[] = $f;
            }
            foreach ($wpCoreDirs as $d) {
                if (!is_dir($targetDir . '/' . $d)) $missingDirs[] = $d . '/';
            }

            if (!empty($missingFiles) || !empty($missingDirs)) {
                $allMissing = array_merge($missingFiles, $missingDirs);
                $this->sendSSE('log', 'AVISO: Faltan archivos core de WordPress: ' . implode(', ', $allMissing), $st);
                $this->sendSSE('step', 'Reparando WordPress core', $st);

                // Download fresh WordPress and restore missing core files
                $wpTmpDir = '/tmp/wp_core_' . bin2hex(random_bytes(4));
                $wpTarball = $wpTmpDir . '.tar.gz';
                $wpDownloaded = false;

                shell_exec(sprintf('wget -q --timeout=30 -O %s https://wordpress.org/latest.tar.gz 2>/dev/null', escapeshellarg($wpTarball)));
                if (file_exists($wpTarball) && filesize($wpTarball) > 1000000) {
                    shell_exec(sprintf('mkdir -p %s && tar xzf %s -C %s 2>/dev/null', escapeshellarg($wpTmpDir), escapeshellarg($wpTarball), escapeshellarg($wpTmpDir)));
                    $wpSrc = $wpTmpDir . '/wordpress';
                    if (is_dir($wpSrc)) {
                        $wpDownloaded = true;
                        // rsync core files (excluding wp-config.php and wp-content)
                        shell_exec(sprintf('rsync -a --exclude=wp-config.php --exclude=wp-content %s/ %s/ 2>&1',
                            escapeshellarg($wpSrc), escapeshellarg($targetDir)));
                        shell_exec(sprintf('chown -R %s:www-data %s 2>&1',
                            escapeshellarg($account['username']), escapeshellarg($targetDir)));

                        // Verify repair
                        $stillMissing = [];
                        foreach ($wpCoreFiles as $f) {
                            if (!file_exists($targetDir . '/' . $f)) $stillMissing[] = $f;
                        }
                        foreach ($wpCoreDirs as $d) {
                            if (!is_dir($targetDir . '/' . $d)) $stillMissing[] = $d . '/';
                        }

                        if (empty($stillMissing)) {
                            $this->sendSSE('log', 'WordPress core reparado automaticamente. Archivos restaurados: ' . implode(', ', $allMissing), $st);
                        } else {
                            $errors[] = 'WordPress core incompleto tras reparacion';
                            $this->sendSSE('error', 'No se pudieron restaurar todos los archivos core. Faltan: ' . implode(', ', $stillMissing), $st);
                        }
                    }
                }

                if (!$wpDownloaded) {
                    $errors[] = 'WordPress core incompleto y no se pudo descargar WP para reparar';
                    $this->sendSSE('error', 'No se pudo descargar WordPress para reparar archivos core faltantes. Repara manualmente.', $st);
                }

                // Cleanup
                @unlink($wpTarball);
                shell_exec(sprintf('rm -rf %s 2>/dev/null', escapeshellarg($wpTmpDir)));
            } else {
                $this->sendSSE('log', 'WordPress core verificado: todos los archivos esenciales presentes.', $st);
            }
        }

        // --- STEP 6b: Composer install ---
        if ($excludeVendor && file_exists($targetDir . '/composer.json')) {
            $this->sendSSE('log', 'Ejecutando composer install --no-dev...', $st);
            $this->sendSSE('step', 'composer install', $st);
            $this->sendSSE('progress', json_encode(['type' => 'composer', 'indeterminate' => true]), $st);

            $composerBin = trim(shell_exec('which composer 2>/dev/null') ?? '');
            if (empty($composerBin)) $composerBin = '/usr/local/bin/composer';

            if (file_exists($composerBin)) {
                $composerCmd = sprintf('cd %s && su -s /bin/bash %s -c %s 2>&1',
                    escapeshellarg($targetDir), escapeshellarg($account['username']),
                    escapeshellarg("{$composerBin} install --no-dev --no-interaction --optimize-autoloader"));
                shell_exec($composerCmd);

                if (is_dir($targetDir . '/vendor')) {
                    $this->sendSSE('log', 'composer install completado.', $st);
                } else {
                    $errors[] = 'composer install fallo';
                    $this->sendSSE('error', 'composer install fallo. Revisa manualmente.', $st);
                }
                shell_exec(sprintf('chown -R %s:www-data %s 2>&1', escapeshellarg($account['username']), escapeshellarg($targetDir)));
            } else {
                $errors[] = 'composer no instalado';
                $this->sendSSE('error', 'composer no esta instalado en este servidor.', $st);
            }
        }

        // --- STEP 7: Database ---
        if ($includeDb && $dbCredentials) {
            $this->sendSSE('log', 'Iniciando migracion de base de datos...', $st);
            $this->sendSSE('step', 'Migrando base de datos', $st);

            $dumpToken = bin2hex(random_bytes(8));
            $dumpFile = "/tmp/mdpdb_{$dumpToken}.sql";

            $isPostgres = ($dbCredentials['driver'] ?? 'mysql') === 'pgsql';
            $dumpToolName = $isPostgres ? 'pg_dump' : 'mysqldump';
            $this->sendSSE('log', "Ejecutando {$dumpToolName} en servidor remoto...", $st);
            $this->sendSSE('progress', json_encode(['type' => 'mysqldump', 'indeterminate' => true]), $st);

            $dumpErrFile = "/tmp/mdpdb_{$dumpToken}.err";

            // DB_HOST can be "localhost:3306" — split host and port
            $dumpHost = $dbCredentials['host'];
            $dumpPort = $dbCredentials['port'];
            if (str_contains($dumpHost, ':')) {
                [$dumpHost, $parsedPort] = explode(':', $dumpHost, 2);
                if (is_numeric($parsedPort)) $dumpPort = $parsedPort;
            }

            if ($isPostgres) {
                $remoteDumpCmd = sprintf('PGPASSWORD=%s pg_dump -h %s -p %s -U %s %s',
                    escapeshellarg($dbCredentials['password']),
                    escapeshellarg($dumpHost), escapeshellarg($dumpPort),
                    escapeshellarg($dbCredentials['username']),
                    escapeshellarg($dbCredentials['database'])
                );
            } else {
                $remoteDumpCmd = sprintf('mysqldump -h %s -P %s -u %s -p%s %s',
                    escapeshellarg($dumpHost), escapeshellarg($dumpPort),
                    escapeshellarg($dbCredentials['username']), escapeshellarg($dbCredentials['password']),
                    escapeshellarg($dbCredentials['database'])
                );
            }

            // Execute dump remotely: dump SQL to stdout, stderr to temp file on remote
            $remoteErrFile = '/tmp/mdp_dump_err_' . bin2hex(random_bytes(4));
            $fullRemoteCmd = $remoteDumpCmd . ' 2>' . escapeshellarg($remoteErrFile);

            shell_exec(sprintf(
                'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 -o ServerAliveInterval=30 -p %d %s@%s %s > %s 2>%s',
                escapeshellarg($sshPassword), $sshPort, escapeshellarg($sshUser), escapeshellarg($sshHost),
                escapeshellarg($fullRemoteCmd),
                escapeshellarg($dumpFile), escapeshellarg($dumpErrFile)
            ));

            // Fetch remote error log if dump failed
            if (!file_exists($dumpFile) || filesize($dumpFile) < 50) {
                $remoteErr = shell_exec(sprintf(
                    'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -p %d %s@%s %s 2>/dev/null',
                    escapeshellarg($sshPassword), $sshPort, escapeshellarg($sshUser), escapeshellarg($sshHost),
                    escapeshellarg('cat ' . escapeshellarg($remoteErrFile) . ' 2>/dev/null; rm -f ' . escapeshellarg($remoteErrFile))
                ));
                if ($remoteErr) {
                    file_put_contents($dumpErrFile, trim($remoteErr));
                }
            } else {
                // Cleanup remote error file
                shell_exec(sprintf(
                    'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 -p %d %s@%s %s 2>/dev/null &',
                    escapeshellarg($sshPassword), $sshPort, escapeshellarg($sshUser), escapeshellarg($sshHost),
                    escapeshellarg('rm -f ' . escapeshellarg($remoteErrFile))
                ));
            }

            // Check for errors — also detect dump files that contain error text instead of SQL
            $dumpOk = file_exists($dumpFile) && filesize($dumpFile) >= 50;
            if ($dumpOk) {
                // Quick check: if the dump starts with an error message instead of SQL
                $head = file_get_contents($dumpFile, false, null, 0, 200);
                if (preg_match('/^(mysqldump:|pg_dump:|error:|Access denied|FATAL:)/i', trim($head))) {
                    $dumpOk = false;
                }
            }

            $dumpErrMsg = '';
            if (file_exists($dumpErrFile) && filesize($dumpErrFile) > 0) {
                $dumpErrMsg = trim(file_get_contents($dumpErrFile, false, null, 0, 500));
            }
            @unlink($dumpErrFile);

            if (!$dumpOk) {
                $errDetail = $dumpErrMsg ?: (isset($head) ? trim($head) : 'sin detalles');
                $errors[] = "{$dumpToolName} fallo";
                $this->sendSSE('error', "ERROR: {$dumpToolName} fallo: {$errDetail}", $st);
                $this->sendSSE('log', 'Se migraron solo los archivos. Usa "Opcion 3: Solo Base de Datos" para reintentar.', $st);

                // Save SSH credentials to status so the retry UI can pre-fill them
                $this->appendStatus($st, 'db_retry', json_encode([
                    'host' => $dbCredentials['host'],
                    'port' => $dbCredentials['port'],
                    'database' => $dbCredentials['database'],
                    'username' => $dbCredentials['username'],
                    'project_type' => $projectType,
                    'ssh_host' => $sshHost,
                    'ssh_port' => $sshPort,
                    'ssh_user' => $sshUser,
                ]));

                @unlink($dumpFile);
            } else {
                $dumpSize = round(filesize($dumpFile) / 1048576, 1);
                $this->sendSSE('log', 'Dump descargado: ' . $dumpSize . ' MB', $st);

                $localDbName = str_replace(['.', '-'], '_', $account['username']) . '_db';
                $localDbUser = $account['username'];
                $localDbPass = bin2hex(random_bytes(12));
                $localDbType = $isPostgres ? 'pgsql' : 'mysql';

                $this->sendSSE('log', "Creando base de datos local ({$localDbType}): {$localDbName}", $st);

                if ($isPostgres) {
                    // PostgreSQL: create user, database, import, fix ownership — all in one
                    // Don't import yet — just setup user/db. Import happens below.
                    $pgSetup = $this->setupPostgresDb($localDbName, $localDbUser, $localDbPass, '');
                    foreach ($pgSetup['logs'] as $pgLog) {
                        $this->sendSSE('log', $pgLog, $st);
                    }
                } else {
                    // MySQL: create user and database
                    $sqlSetup = sprintf(
                        "CREATE DATABASE IF NOT EXISTS `%s`;\nCREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s';\nALTER USER '%s'@'localhost' IDENTIFIED BY '%s';\nGRANT ALL ON `%s`.* TO '%s'@'localhost';\nFLUSH PRIVILEGES;\n",
                        str_replace('`', '``', $localDbName),
                        str_replace("'", "''", $localDbUser),
                        str_replace("'", "''", $localDbPass),
                        str_replace("'", "''", $localDbUser),
                        str_replace("'", "''", $localDbPass),
                        str_replace('`', '``', $localDbName),
                        str_replace("'", "''", $localDbUser)
                    );
                    $sqlTmp = tempnam('/tmp', 'mdp_sql_');
                    file_put_contents($sqlTmp, $sqlSetup);
                    $mysqlOutput = shell_exec(sprintf('mysql < %s 2>&1', escapeshellarg($sqlTmp)));
                    @unlink($sqlTmp);

                    if ($mysqlOutput !== null && stripos($mysqlOutput, 'ERROR') !== false) {
                        $this->sendSSE('log', 'ERROR al crear BD/usuario MySQL: ' . trim($mysqlOutput), $st);
                        $this->sendSSE('error', 'Error creando base de datos MySQL. Revisa los logs.', $st);
                        @unlink($dumpFile);
                        return;
                    }

                    // Verify the user can actually connect with the new password
                    $verifyCmd = sprintf(
                        'mysql -u %s -p%s -e "SELECT 1" %s 2>&1',
                        escapeshellarg($localDbUser),
                        escapeshellarg($localDbPass),
                        escapeshellarg($localDbName)
                    );
                    $verifyOutput = shell_exec($verifyCmd);
                    if ($verifyOutput !== null && (stripos($verifyOutput, 'ERROR') !== false || stripos($verifyOutput, 'Access denied') !== false)) {
                        $this->sendSSE('log', 'WARN: Verificacion de credenciales fallo, reintentando ALTER USER...', $st);
                        $retryCmd = sprintf(
                            "mysql -e %s 2>&1",
                            escapeshellarg(sprintf(
                                "ALTER USER '%s'@'localhost' IDENTIFIED BY '%s'; FLUSH PRIVILEGES;",
                                str_replace("'", "''", $localDbUser),
                                str_replace("'", "''", $localDbPass)
                            ))
                        );
                        shell_exec($retryCmd);
                    }
                }

                $this->sendSSE('log', 'Base de datos y usuario creados.', $st);

                // Save DB record and update config files BEFORE import
                // (so if the process dies during import, credentials are already saved)
                Database::insert('hosting_databases', [
                    'account_id' => (int)$data['account_id'],
                    'db_name' => $localDbName,
                    'db_user' => $localDbUser,
                    'db_type' => $localDbType,
                ]);

                // Update config files immediately (critical — must happen before potential crash)
                // In vhost root mode, config files are in httpdocs/ subfolder
                $cfgDir = $targetDir;
                if ($isVhostRoot && is_dir($targetDir . '/httpdocs')) {
                    $cfgDir = $targetDir . '/httpdocs';
                }
                if ($projectType === 'musedock' && file_exists($cfgDir . '/.env')) {
                    $envContent = file_get_contents($cfgDir . '/.env');
                    $envContent = preg_replace('/^DB_DRIVER=.*/m', "DB_DRIVER=" . ($isPostgres ? 'pgsql' : 'mysql'), $envContent);
                    $envContent = preg_replace('/^DB_HOST=.*/m', 'DB_HOST=localhost', $envContent);
                    $envContent = preg_replace('/^DB_PORT=.*/m', "DB_PORT=" . ($isPostgres ? '5432' : '3306'), $envContent);
                    $envContent = preg_replace('/^DB_NAME=.*/m', "DB_NAME={$localDbName}", $envContent);
                    $envContent = preg_replace('/^DB_USER=.*/m', "DB_USER={$localDbUser}", $envContent);
                    $envContent = preg_replace('/^DB_PASS=.*/m', "DB_PASS={$localDbPass}", $envContent);
                    file_put_contents($cfgDir . '/.env', $envContent);
                    $this->sendSSE('log', 'Actualizado .env (MuseDock) con credenciales locales.', $st);
                } elseif ($projectType === 'laravel' && file_exists($cfgDir . '/.env')) {
                    $envContent = file_get_contents($cfgDir . '/.env');
                    $envContent = preg_replace('/^DB_HOST=.*/m', 'DB_HOST=127.0.0.1', $envContent);
                    $envContent = preg_replace('/^DB_PORT=.*/m', 'DB_PORT=' . ($isPostgres ? '5432' : '3306'), $envContent);
                    $envContent = preg_replace('/^DB_DATABASE=.*/m', "DB_DATABASE={$localDbName}", $envContent);
                    $envContent = preg_replace('/^DB_USERNAME=.*/m', "DB_USERNAME={$localDbUser}", $envContent);
                    $envContent = preg_replace('/^DB_PASSWORD=.*/m', "DB_PASSWORD={$localDbPass}", $envContent);
                    file_put_contents($cfgDir . '/.env', $envContent);
                    $this->sendSSE('log', 'Actualizado .env con credenciales locales.', $st);
                } elseif ($projectType === 'wordpress' && file_exists($cfgDir . '/wp-config.php')) {
                    $wpContent = file_get_contents($cfgDir . '/wp-config.php');
                    $wpContent = preg_replace("/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/", "define('DB_NAME', '{$localDbName}')", $wpContent);
                    $wpContent = preg_replace("/define\(\s*['\"]DB_USER['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/", "define('DB_USER', '{$localDbUser}')", $wpContent);
                    $wpContent = preg_replace("/define\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/", "define('DB_PASSWORD', '{$localDbPass}')", $wpContent);
                    $wpContent = preg_replace("/define\(\s*['\"]DB_HOST['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/", "define('DB_HOST', 'localhost')", $wpContent);
                    file_put_contents($cfgDir . '/wp-config.php', $wpContent);

                    $wpCheck = file_get_contents($cfgDir . '/wp-config.php');
                    if (strpos($wpCheck, $localDbName) === false) {
                        $this->sendSSE('log', 'WARN: wp-config.php no contiene el nombre de BD esperado. Posible formato no reconocido.', $st);
                    }
                    $this->sendSSE('log', 'Actualizado wp-config.php con credenciales locales.', $st);
                } elseif ($projectType === 'zend' && file_exists($cfgDir . '/application/settings/database.php')) {
                    $zendFile = $cfgDir . '/application/settings/database.php';
                    $zendContent = file_get_contents($zendFile);
                    $zendContent = preg_replace("/'host'\s*=>\s*'[^']*'/", "'host' => 'localhost'", $zendContent);
                    $zendContent = preg_replace("/'username'\s*=>\s*'[^']*'/", "'username' => '{$localDbUser}'", $zendContent);
                    $zendContent = preg_replace("/'password'\s*=>\s*'[^']*'/", "'password' => '{$localDbPass}'", $zendContent);
                    $zendContent = preg_replace("/'dbname'\s*=>\s*'[^']*'/", "'dbname' => '{$localDbName}'", $zendContent);
                    file_put_contents($zendFile, $zendContent);
                    $this->sendSSE('log', 'Actualizado application/settings/database.php con credenciales locales.', $st);
                } elseif ($projectType === 'webtv' && file_exists($cfgDir . '/config/Config.inc.php')) {
                    $webtvFile = $cfgDir . '/config/Config.inc.php';
                    $webtvContent = file_get_contents($webtvFile);
                    $webtvContent = preg_replace('/(\$DB_HOST\s*=\s*)["\'][^"\']*["\']/', '$1"localhost"', $webtvContent);
                    $webtvContent = preg_replace('/(\$DB_NAME\s*=\s*)["\'][^"\']*["\']/', '$1"' . $localDbName . '"', $webtvContent);
                    $webtvContent = preg_replace('/(\$DB_USERNAME\s*=\s*)["\'][^"\']*["\']/', '$1"' . $localDbUser . '"', $webtvContent);
                    $webtvContent = preg_replace('/(\$DB_PASSWORD\s*=\s*)["\'][^"\']*["\']/', '$1"' . $localDbPass . '"', $webtvContent);
                    file_put_contents($webtvFile, $webtvContent);
                    $this->sendSSE('log', 'Actualizado config/Config.inc.php con credenciales locales.', $st);
                }

                // Persist db_pass to status file immediately (in case process dies during import)
                $this->appendStatus($st, 'db_pass_saved', $localDbPass);

                $this->sendSSE('log', 'Importando dump SQL...', $st);
                $this->sendSSE('step', 'Importando BD (' . $dumpSize . ' MB)', $st);
                $this->sendSSE('progress', json_encode(['type' => 'import', 'indeterminate' => true]), $st);

                if ($isPostgres) {
                    // Import + fix ownership + grants + verify (all in setupPostgresDb)
                    $pgImport = $this->setupPostgresDb($localDbName, $localDbUser, $localDbPass, $dumpFile);
                    foreach ($pgImport['logs'] as $pgLog) {
                        $this->sendSSE('log', $pgLog, $st);
                    }
                    if (!$pgImport['ok']) {
                        $this->sendSSE('error', 'La importacion de PostgreSQL tuvo problemas. Revisa los logs.', $st);
                        $errors[] = 'PostgreSQL import issues';
                    }
                } else {
                    $importOutput = shell_exec(sprintf('mysql %s < %s 2>&1', escapeshellarg($localDbName), escapeshellarg($dumpFile)));
                    if ($importOutput !== null && stripos($importOutput, 'ERROR') !== false) {
                        $this->sendSSE('log', 'WARN al importar dump: ' . trim(substr($importOutput, 0, 500)), $st);
                    }
                    $this->sendSSE('log', 'Dump importado (' . $dumpSize . ' MB).', $st);
                }

                @unlink($dumpFile);
            }
        }

        // --- STEP 8: Cleanup ---
        @unlink($localBackup);

        // --- STEP 8b: Auto-detect public/ directory for Laravel/MuseDock ---
        // When vhost root mode, project files are in targetDir/httpdocs/
        $projectLocalDir = $targetDir;
        if ($isVhostRoot && is_dir($targetDir . '/httpdocs')) {
            $projectLocalDir = $targetDir . '/httpdocs';
        }
        $publicIndex = $projectLocalDir . '/public/index.php';
        if (in_array($projectType, ['laravel', 'musedock']) && file_exists($publicIndex) && filesize($publicIndex) > 50) {
            $publicDocRoot = $projectLocalDir . '/public';
            $this->sendSSE('log', "Proyecto {$projectType} detectado con public/. Cambiando document root a {$publicDocRoot}", $st);

            $updated = \MuseDockPanel\Services\SystemService::updateCaddyDocumentRoot(
                $account['domain'], $publicDocRoot, $account['username'], $account['php_version'] ?? '8.3'
            );

            if ($updated) {
                Database::query(
                    "UPDATE hosting_accounts SET document_root = :dr WHERE id = :id",
                    ['dr' => $publicDocRoot, 'id' => $account['id']]
                );
                $this->sendSSE('log', 'Document root actualizado a public/. Archivos sensibles (.env, config, vendor) ya no son accesibles.', $st);
            } else {
                $this->sendSSE('error', 'No se pudo actualizar el document root a public/. Cambialo manualmente desde el panel.', $st);
                $errors[] = 'Document root update to public/ failed';
            }
        }

        // --- STEP 9: Migrate subdomains ---
        $migrateSubdomains = $data['migrate_subdomains'] ?? [];
        $subdomainResults = [];
        if (!empty($migrateSubdomains)) {
            $this->sendSSE('step', 'Migrando subdominios (' . count($migrateSubdomains) . ')', $st);
            $this->sendSSE('log', '═══ Migrando ' . count($migrateSubdomains) . ' subdominios ═══', $st);

            foreach ($migrateSubdomains as $subName) {
                $subName = basename(trim($subName)); // Safety
                if (empty($subName)) continue;

                $this->sendSSE('log', "── Subdominio: {$subName} ──", $st);

                // Determine remote path for this subdomain
                $subRemotePath = $remotePath . '/' . $subName;
                // Check if subdomain has httpdocs subfolder
                $subHtCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
                    "test -d " . escapeshellarg($subRemotePath . '/httpdocs') . " && echo 'HAS_HT' || echo 'NO_HT'"
                );
                $subDocRoot = str_contains($subHtCheck, 'HAS_HT') ? '/httpdocs' : '';
                $subFullPath = $subRemotePath . $subDocRoot;

                // Create local subdomain via SubdomainService (or reuse if already exists)
                $subResult = \MuseDockPanel\Services\SubdomainService::create((int)$account['id'], $subName);
                if (!$subResult['ok']) {
                    // Check if it already exists — reuse it for re-migration
                    $existingSub = \MuseDockPanel\Services\SubdomainService::getByDomain($subName);
                    if ($existingSub && !empty($existingSub['document_root'])) {
                        $subTargetDir = $existingSub['document_root'];
                        $this->sendSSE('log', "Subdominio {$subName} ya existe, reutilizando. Document root: {$subTargetDir}", $st);
                    } else {
                        $this->sendSSE('error', "No se pudo crear subdominio {$subName}: " . ($subResult['error'] ?? 'error'), $st);
                        $errors[] = "Subdomain {$subName}: " . ($subResult['error'] ?? 'error');
                        $subdomainResults[] = ['subdomain' => $subName, 'ok' => false, 'error' => $subResult['error']];
                        continue;
                    }
                } else {
                    $subTargetDir = $subResult['document_root'];
                    $this->sendSSE('log', "Subdominio creado. Document root: {$subTargetDir}", $st);
                }

                // Compress remote subdomain files
                $subFileToken = bin2hex(random_bytes(8));
                $subBackupName = "mdp_sub_{$subFileToken}.tar.gz";
                $subRemoteBackupPath = $subFullPath . '/' . $subBackupName;
                $subLocalBackup = "/tmp/{$subBackupName}";

                $this->sendSSE('log', "Comprimiendo archivos de {$subName}...", $st);
                $subExcludeArgs = "--exclude=" . escapeshellarg($subBackupName);
                if ($excludeVendor) {
                    $subExcludeArgs .= " --exclude='vendor'";
                }
                $subTarCmd = "cd " . escapeshellarg($subFullPath) . " && tar czf " . escapeshellarg($subRemoteBackupPath) . " {$subExcludeArgs} . 2>&1; echo TAR_EXIT_\$?";
                $subTarOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, $subTarCmd, 600);

                if (preg_match('/TAR_EXIT_(\d+)/', $subTarOutput, $tarM)) {
                    $tarExit = (int) $tarM[1];
                } else {
                    $tarExit = 99;
                }
                if ($tarExit === 1) {
                    $this->sendSSE('log', "AVISO: Algunos archivos de {$subName} cambiaron durante la compresion (normal en sitios activos).", $st);
                } elseif ($tarExit > 1) {
                    $this->sendSSE('error', "Error comprimiendo {$subName}: " . substr($subTarOutput, 0, 200), $st);
                    $errors[] = "Subdomain {$subName}: tar failed";
                    $subdomainResults[] = ['subdomain' => $subName, 'ok' => false, 'error' => 'tar failed'];
                    continue;
                }

                // Download via SCP (most reliable for subdomains)
                $this->sendSSE('log', "Descargando archivos de {$subName}...", $st);
                $subScpCmd = sprintf(
                    'sshpass -p %s scp -o StrictHostKeyChecking=no -o ConnectTimeout=30 -P %d %s@%s:%s %s 2>&1',
                    escapeshellarg($sshPassword), $sshPort,
                    escapeshellarg($sshUser), escapeshellarg($sshHost),
                    escapeshellarg($subRemoteBackupPath), escapeshellarg($subLocalBackup)
                );
                shell_exec("timeout 600 {$subScpCmd}");

                // Clean remote backup
                $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
                    "rm -f " . escapeshellarg($subRemoteBackupPath)
                );

                if (!file_exists($subLocalBackup) || filesize($subLocalBackup) < 100) {
                    // Try HTTPS fallback
                    $subHttpsUrl = "https://{$subName}/{$subBackupName}";
                    $this->sendSSE('log', "SCP fallo, intentando HTTPS: {$subHttpsUrl}", $st);
                    $this->downloadWithProgress($subHttpsUrl, $subLocalBackup, 0, $st);
                }

                if (!file_exists($subLocalBackup) || filesize($subLocalBackup) < 100) {
                    $this->sendSSE('error', "No se pudo descargar {$subName}.", $st);
                    $errors[] = "Subdomain {$subName}: download failed";
                    $subdomainResults[] = ['subdomain' => $subName, 'ok' => false, 'error' => 'download failed'];
                    continue;
                }

                // Extract
                $this->sendSSE('log', "Descomprimiendo {$subName}...", $st);
                shell_exec(sprintf('tar xzf %s -C %s 2>&1', escapeshellarg($subLocalBackup), escapeshellarg($subTargetDir)));
                shell_exec(sprintf('chown -R %s:www-data %s 2>&1', escapeshellarg($account['username']), escapeshellarg($subTargetDir)));
                @unlink($subLocalBackup);
                $this->sendSSE('log', "Archivos de {$subName} extraidos.", $st);

                // Detect and migrate database for this subdomain
                $subDbResult = null;
                $subProjectType = 'unknown';
                if ($includeDb) {
                    // Check for MuseDock, Laravel, WordPress or Zend
                    $subEnvCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
                        "if [ -f " . escapeshellarg($subFullPath . '/muse') . " ] && [ -f " . escapeshellarg($subFullPath . '/.env') . " ]; then echo 'MUSEDOCK'; "
                        . "elif [ -f " . escapeshellarg($subFullPath . '/.env') . " ]; then echo 'LARAVEL'; "
                        . "elif [ -f " . escapeshellarg($subFullPath . '/wp-config.php') . " ]; then echo 'WORDPRESS'; "
                        . "elif [ -f " . escapeshellarg($subFullPath . '/application/settings/database.php') . " ]; then echo 'ZEND'; fi"
                    );

                    $subDbCreds = null;
                    $subIsPostgres = false;
                    if (str_contains($subEnvCheck, 'MUSEDOCK')) {
                        $subProjectType = 'musedock';
                        $envContent = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
                            "cat " . escapeshellarg($subFullPath . '/.env')
                        );
                        $subDbCreds = $this->parseMuseDockEnv($envContent);
                        $subIsPostgres = ($subDbCreds['driver'] ?? 'mysql') === 'pgsql';
                        $this->sendSSE('log', "MuseDock detectado en {$subName}. BD: " . ($subDbCreds['database'] ?? '?') . " ({$subDbCreds['driver']})", $st);
                    } elseif (str_contains($subEnvCheck, 'LARAVEL')) {
                        $subProjectType = 'laravel';
                        $envContent = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
                            "cat " . escapeshellarg($subFullPath . '/.env')
                        );
                        $subDbCreds = $this->parseLaravelEnv($envContent);
                        $this->sendSSE('log', "Laravel detectado en {$subName}. BD: " . ($subDbCreds['database'] ?? '?'), $st);
                    } elseif (str_contains($subEnvCheck, 'WORDPRESS')) {
                        $subProjectType = 'wordpress';
                        $wpContent = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
                            "cat " . escapeshellarg($subFullPath . '/wp-config.php')
                        );
                        $subDbCreds = $this->parseWpConfig($wpContent);
                        $this->sendSSE('log', "WordPress detectado en {$subName}. BD: " . ($subDbCreds['database'] ?? '?'), $st);
                    } elseif (str_contains($subEnvCheck, 'ZEND')) {
                        $subProjectType = 'zend';
                        $zendContent = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
                            "cat " . escapeshellarg($subFullPath . '/application/settings/database.php')
                        );
                        $subDbCreds = $this->parseZendDbConfig($zendContent);
                        $this->sendSSE('log', "Zend/SocialEngine detectado en {$subName}. BD: " . ($subDbCreds['database'] ?? '?'), $st);
                    }

                    if ($subDbCreds && !empty($subDbCreds['database'])) {
                        $this->sendSSE('log', "Volcando BD de {$subName}: {$subDbCreds['database']}...", $st);

                        // Remote dump (pg_dump or mysqldump)
                        $subDumpFile = "/tmp/mdp_sub_db_{$subFileToken}.sql";
                        if ($subIsPostgres) {
                            $subDumpCmd = sprintf(
                                'PGPASSWORD=%s pg_dump -h %s -p %s -U %s %s',
                                escapeshellarg($subDbCreds['password'] ?? ''),
                                escapeshellarg($subDbCreds['host'] ?? '127.0.0.1'),
                                escapeshellarg($subDbCreds['port'] ?? '5432'),
                                escapeshellarg($subDbCreds['username'] ?? ''),
                                escapeshellarg($subDbCreds['database'])
                            );
                        } else {
                            $subDumpCmd = sprintf(
                                'mysqldump -h%s -u%s -p%s %s',
                                escapeshellarg($subDbCreds['host'] ?? '127.0.0.1'),
                                escapeshellarg($subDbCreds['username'] ?? ''),
                                escapeshellarg($subDbCreds['password'] ?? ''),
                                escapeshellarg($subDbCreds['database'])
                            );
                        }
                        $subDumpOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
                            "{$subDumpCmd} > /tmp/{$subBackupName}.sql 2>&1; echo DUMP_EXIT_\$?; wc -c < /tmp/{$subBackupName}.sql"
                        );

                        if (str_contains($subDumpOutput, 'DUMP_EXIT_0')) {
                            // Download dump
                            $subDumpScpCmd = sprintf(
                                'sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s@%s:/tmp/%s.sql %s 2>&1',
                                escapeshellarg($sshPassword), $sshPort,
                                escapeshellarg($sshUser), escapeshellarg($sshHost),
                                escapeshellarg($subBackupName), escapeshellarg($subDumpFile)
                            );
                            shell_exec("timeout 300 {$subDumpScpCmd}");

                            // Clean remote dump
                            $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
                                "rm -f /tmp/" . escapeshellarg($subBackupName) . ".sql"
                            );

                            if (file_exists($subDumpFile) && filesize($subDumpFile) > 100) {
                                // Create local database
                                $safeSubName = preg_replace('/[^a-z0-9_]/', '_', strtolower(str_replace(['.', '-'], '_', $subName)));
                                $subLocalDbName = substr($safeSubName, 0, 60) . '_db';
                                $subLocalDbUser = substr($safeSubName, 0, 28) . '_usr';
                                $subLocalDbPass = bin2hex(random_bytes(12));
                                $subLocalDbType = $subIsPostgres ? 'pgsql' : 'mysql';

                                $this->sendSSE('log', "Creando BD local ({$subLocalDbType}): {$subLocalDbName}...", $st);

                                if ($subIsPostgres) {
                                    shell_exec(sprintf("sudo -u postgres psql -c %s 2>&1", escapeshellarg("CREATE USER \"{$subLocalDbUser}\" WITH PASSWORD '{$subLocalDbPass}'")));
                                    shell_exec(sprintf("sudo -u postgres psql -c %s 2>&1", escapeshellarg("ALTER USER \"{$subLocalDbUser}\" WITH PASSWORD '{$subLocalDbPass}'")));
                                    shell_exec(sprintf("sudo -u postgres psql -c %s 2>&1", escapeshellarg("CREATE DATABASE \"{$subLocalDbName}\" OWNER \"{$subLocalDbUser}\"")));
                                    shell_exec(sprintf("sudo -u postgres psql -c %s 2>&1", escapeshellarg("GRANT ALL PRIVILEGES ON DATABASE \"{$subLocalDbName}\" TO \"{$subLocalDbUser}\"")));
                                } else {
                                    shell_exec("mysql -u root -e " . escapeshellarg("CREATE DATABASE IF NOT EXISTS `{$subLocalDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci") . " 2>&1");
                                    shell_exec("mysql -u root -e " . escapeshellarg("CREATE USER IF NOT EXISTS '{$subLocalDbUser}'@'localhost' IDENTIFIED BY '{$subLocalDbPass}'") . " 2>&1");
                                    shell_exec("mysql -u root -e " . escapeshellarg("GRANT ALL PRIVILEGES ON `{$subLocalDbName}`.* TO '{$subLocalDbUser}'@'localhost'") . " 2>&1");
                                    shell_exec("mysql -u root -e 'FLUSH PRIVILEGES' 2>&1");
                                }

                                // Import dump
                                $this->sendSSE('log', "Importando BD de {$subName}...", $st);
                                if ($subIsPostgres) {
                                    shell_exec(sprintf('sudo -u postgres psql -d %s < %s 2>&1', escapeshellarg($subLocalDbName), escapeshellarg($subDumpFile)));
                                } else {
                                    shell_exec(sprintf('mysql %s < %s 2>&1', escapeshellarg($subLocalDbName), escapeshellarg($subDumpFile)));
                                }
                                @unlink($subDumpFile);

                                // Register in hosting_databases
                                Database::insert('hosting_databases', [
                                    'account_id' => (int)$account['id'],
                                    'db_name'    => $subLocalDbName,
                                    'db_user'    => $subLocalDbUser,
                                    'db_type'    => $subLocalDbType,
                                ]);

                                // Update local config file
                                if ($subProjectType === 'musedock' && file_exists($subTargetDir . '/.env')) {
                                    $localEnv = file_get_contents($subTargetDir . '/.env');
                                    $localEnv = preg_replace('/^DB_DRIVER=.*/m', "DB_DRIVER=" . ($subIsPostgres ? 'pgsql' : 'mysql'), $localEnv);
                                    $localEnv = preg_replace('/^DB_HOST=.*/m', 'DB_HOST=localhost', $localEnv);
                                    $localEnv = preg_replace('/^DB_PORT=.*/m', "DB_PORT=" . ($subIsPostgres ? '5432' : '3306'), $localEnv);
                                    $localEnv = preg_replace('/^DB_NAME=.*/m', "DB_NAME={$subLocalDbName}", $localEnv);
                                    $localEnv = preg_replace('/^DB_USER=.*/m', "DB_USER={$subLocalDbUser}", $localEnv);
                                    $localEnv = preg_replace('/^DB_PASS=.*/m', "DB_PASS={$subLocalDbPass}", $localEnv);
                                    file_put_contents($subTargetDir . '/.env', $localEnv);
                                    $this->sendSSE('log', ".env (MuseDock) de {$subName} actualizado con credenciales locales.", $st);
                                } elseif ($subProjectType === 'laravel' && file_exists($subTargetDir . '/.env')) {
                                    $localEnv = file_get_contents($subTargetDir . '/.env');
                                    $localEnv = preg_replace('/^DB_HOST=.*/m', 'DB_HOST=127.0.0.1', $localEnv);
                                    $localEnv = preg_replace('/^DB_DATABASE=.*/m', "DB_DATABASE={$subLocalDbName}", $localEnv);
                                    $localEnv = preg_replace('/^DB_USERNAME=.*/m', "DB_USERNAME={$subLocalDbUser}", $localEnv);
                                    $localEnv = preg_replace('/^DB_PASSWORD=.*/m', "DB_PASSWORD={$subLocalDbPass}", $localEnv);
                                    file_put_contents($subTargetDir . '/.env', $localEnv);
                                    $this->sendSSE('log', ".env de {$subName} actualizado con credenciales locales.", $st);
                                } elseif ($subProjectType === 'wordpress' && file_exists($subTargetDir . '/wp-config.php')) {
                                    $wpConfig = file_get_contents($subTargetDir . '/wp-config.php');
                                    $wpConfig = preg_replace("/define\s*\(\s*'DB_NAME'\s*,.*/", "define('DB_NAME', '{$subLocalDbName}');", $wpConfig);
                                    $wpConfig = preg_replace("/define\s*\(\s*'DB_USER'\s*,.*/", "define('DB_USER', '{$subLocalDbUser}');", $wpConfig);
                                    $wpConfig = preg_replace("/define\s*\(\s*'DB_PASSWORD'\s*,.*/", "define('DB_PASSWORD', '{$subLocalDbPass}');", $wpConfig);
                                    $wpConfig = preg_replace("/define\s*\(\s*'DB_HOST'\s*,.*/", "define('DB_HOST', 'localhost');", $wpConfig);
                                    file_put_contents($subTargetDir . '/wp-config.php', $wpConfig);
                                    $this->sendSSE('log', "wp-config.php de {$subName} actualizado con credenciales locales.", $st);
                                } elseif ($subProjectType === 'zend' && file_exists($subTargetDir . '/application/settings/database.php')) {
                                    $zendFile = $subTargetDir . '/application/settings/database.php';
                                    $zendContent = file_get_contents($zendFile);
                                    $zendContent = preg_replace("/'host'\s*=>\s*'[^']*'/", "'host' => 'localhost'", $zendContent);
                                    $zendContent = preg_replace("/'username'\s*=>\s*'[^']*'/", "'username' => '{$subLocalDbUser}'", $zendContent);
                                    $zendContent = preg_replace("/'password'\s*=>\s*'[^']*'/", "'password' => '{$subLocalDbPass}'", $zendContent);
                                    $zendContent = preg_replace("/'dbname'\s*=>\s*'[^']*'/", "'dbname' => '{$subLocalDbName}'", $zendContent);
                                    file_put_contents($zendFile, $zendContent);
                                    $this->sendSSE('log', "database.php de {$subName} actualizado con credenciales locales.", $st);
                                }

                                $subDbResult = ['db_name' => $subLocalDbName, 'db_pass' => $subLocalDbPass];
                                $this->sendSSE('log', "BD de {$subName} migrada: {$subDbCreds['database']} → {$subLocalDbName}", $st);
                            } else {
                                $this->sendSSE('error', "No se pudo descargar el dump de BD de {$subName}.", $st);
                                $errors[] = "Subdomain {$subName}: DB dump download failed";
                            }
                        } else {
                            $dumpTool = $subIsPostgres ? 'pg_dump' : 'mysqldump';
                            $this->sendSSE('error', "{$dumpTool} fallo para {$subName}.", $st);
                            $errors[] = "Subdomain {$subName}: {$dumpTool} failed";
                        }
                    }
                }

                // Composer install if needed
                if ($excludeVendor && file_exists($subTargetDir . '/composer.json') && !is_dir($subTargetDir . '/vendor')) {
                    $this->sendSSE('log', "Ejecutando composer install en {$subName}...", $st);
                    shell_exec(sprintf('cd %s && composer install --no-dev --no-interaction 2>&1', escapeshellarg($subTargetDir)));
                    $this->sendSSE('log', "composer install completado para {$subName}.", $st);
                }

                $subdomainResults[] = [
                    'subdomain' => $subName,
                    'ok' => true,
                    'document_root' => $subTargetDir,
                    'db' => $subDbResult,
                ];
                $this->sendSSE('log', "✓ Subdominio {$subName} migrado correctamente.", $st);
            }

            $this->sendSSE('log', '═══ Migracion de subdominios finalizada ═══', $st);
        }

        // --- Done ---
        $summary = "Migracion completada desde {$sshUser}@{$sshHost} ({$localSize} MB via HTTPS)";
        if (!empty($migrateSubdomains)) {
            $okSubs = count(array_filter($subdomainResults, fn($r) => $r['ok']));
            $summary .= " | Subdominios: {$okSubs}/" . count($migrateSubdomains);
        }
        if ($includeDb && $localDbName) {
            $summary .= " | BD: {$dbCredentials['database']} -> {$localDbName}";
        }

        LogService::log('migration.ssh', $account['domain'], $summary);

        // Write final status to file FIRST (before SSE) — so if process dies, status is saved
        $doneResult = [
            'ok' => empty($errors),
            'summary' => $summary,
            'db_pass' => $localDbPass,
            'errors' => $errors,
        ];
        $this->appendStatus($st, 'log', 'Archivos temporales eliminados.');
        $this->appendStatus($st, 'log', 'Migracion completada!');
        $this->appendStatus($st, 'done', json_encode($doneResult));

        // Now try to send SSE events (may fail if client disconnected, that's OK)
        $this->sendSSEDirect('log', 'Archivos temporales eliminados.');
        $this->sendSSEDirect('step', 'Completado');
        $this->sendSSEDirect('log', 'Migracion completada!');
        $this->sendSSEDirect('done', json_encode($doneResult));
    }

    /**
     * Send SSE event to stream only (no status file)
     */
    private function sendSSEDirect(string $event, string $data): void
    {
        echo "event: {$event}\n";
        echo "data: {$data}\n\n";
        if (ob_get_level()) ob_flush();
        @flush();
    }

    /**
     * Send a Server-Sent Event and persist to status file
     */
    private function sendSSE(string $event, string $data, ?string $statusToken = null): void
    {
        echo "event: {$event}\n";
        echo "data: {$data}\n\n";
        if (ob_get_level()) ob_flush();
        @flush();

        // Persist to status file for reconnection
        if ($statusToken) {
            $this->appendStatus($statusToken, $event, $data);
        }
    }

    /**
     * Download file with progress tracking via proc_open
     */
    private function downloadWithProgress(string $url, string $dest, int $expectedSize, string $statusToken): bool
    {
        // Timeout: 30s connect, 60s read stall, max 3 tries
        $cmd = sprintf(
            'wget --no-check-certificate --connect-timeout=30 --read-timeout=60 --tries=2 -O %s %s 2>&1',
            escapeshellarg($dest), escapeshellarg($url)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            // Fallback to simple shell_exec with timeout
            shell_exec("timeout 600 {$cmd}");
            return file_exists($dest) && filesize($dest) > 0;
        }

        fclose($pipes[0]);

        // Read output non-blocking while polling file size
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $lastPct = -1;
        $startTime = microtime(true);
        $lastCurrent = 0;
        $lastSpeedTime = $startTime;
        $speed = 0;
        $maxTime = 600; // 10 minutes max for download
        $stallTimeout = 60; // Kill if no progress for 60 seconds
        $lastProgressTime = $startTime;

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) break;

            $now = microtime(true);

            // Global timeout
            if (($now - $startTime) > $maxTime) {
                $this->sendSSE('log', 'AVISO: Descarga abortada por timeout (' . $maxTime . 's).', $statusToken);
                proc_terminate($process, 9);
                usleep(100000);
                break;
            }

            // Check file size for progress
            clearstatcache(true, $dest);
            if (file_exists($dest) && $expectedSize > 0) {
                $current = filesize($dest);

                // Stall detection
                if ($current > $lastCurrent) {
                    $lastProgressTime = $now;
                } elseif (($now - $lastProgressTime) > $stallTimeout) {
                    $this->sendSSE('log', 'AVISO: Descarga detenida (sin progreso en ' . $stallTimeout . 's). Abortando.', $statusToken);
                    proc_terminate($process, 9);
                    usleep(100000);
                    break;
                }

                $pct = min(99, (int) round(($current / $expectedSize) * 100));

                // Calculate speed every update (bytes/sec over last interval)
                $timeDelta = $now - $lastSpeedTime;
                if ($timeDelta > 0.1) {
                    $speed = ($current - $lastCurrent) / $timeDelta;
                    $lastCurrent = $current;
                    $lastSpeedTime = $now;
                }

                if ($pct > $lastPct && ($pct - $lastPct) >= 2) {
                    $lastPct = $pct;
                    $eta = ($speed > 0 && $pct < 99) ? (int) round(($expectedSize - $current) / $speed) : 0;
                    $this->sendSSE('progress', json_encode([
                        'type' => 'download', 'total' => $expectedSize,
                        'current' => $current, 'percent' => $pct,
                        'speed' => (int) $speed, 'eta' => $eta,
                    ]), $statusToken);
                }
            } else {
                // File doesn't exist yet — stall detection from start
                if (($now - $lastProgressTime) > $stallTimeout) {
                    $this->sendSSE('log', 'AVISO: Descarga no ha comenzado en ' . $stallTimeout . 's. Abortando.', $statusToken);
                    proc_terminate($process, 9);
                    usleep(100000);
                    break;
                }
            }

            // Drain pipes to prevent blocking
            fread($pipes[1], 4096);
            fread($pipes[2], 4096);

            usleep(500000); // 0.5s
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        clearstatcache(true, $dest);
        return file_exists($dest) && filesize($dest) > 0;
    }

    private function appendStatus(string $statusToken, string $event, string $data): void
    {
        $file = $this->statusFilePath($statusToken);
        $status = [];
        if (file_exists($file)) {
            $status = json_decode(file_get_contents($file), true) ?: [];
        }
        if (!isset($status['logs'])) $status['logs'] = [];

        if ($event === 'log') {
            $status['logs'][] = $data;
        } elseif ($event === 'step') {
            $status['step'] = $data;
        } elseif ($event === 'progress') {
            $decoded = json_decode($data, true);
            if ($decoded) $status['progress'] = $decoded;
        } elseif ($event === 'error') {
            $status['logs'][] = 'ERROR: ' . $data;
        } elseif ($event === 'done') {
            $status['done'] = true;
            $status['active'] = false;
            $status['result'] = json_decode($data, true);
        } elseif ($event === 'db_pass_saved') {
            $status['db_pass'] = $data;
        } elseif ($event === 'db_retry') {
            $status['db_retry'] = json_decode($data, true);
        }

        file_put_contents($file, json_encode($status, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Legacy POST fallback (kept for non-JS browsers)
     */
    public function fromSsh(array $params): void
    {
        // Redirect to the migration page — JS handles it now via SSE
        Flash::set('error', 'Usa el boton de migracion con JavaScript habilitado.');
        Router::redirect('/accounts/' . $params['id'] . '/migrate');
    }

    // ================================================================
    // Option 3: Database migration (standalone)
    // ================================================================

    public function migrateDb(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Account not found.');
            Router::redirect('/accounts');
            return;
        }

        $dbSource = $_POST['db_source'] ?? 'manual';
        $targetDir = $account['document_root'];

        // If a subdomain was selected, use its local document_root
        $targetSubdomain = trim($_POST['target_subdomain'] ?? '');
        if (!empty($targetSubdomain)) {
            $subRecord = Database::fetchOne(
                'SELECT document_root FROM hosting_subdomains WHERE account_id = :aid AND subdomain = :sub',
                ['aid' => $params['id'], 'sub' => $targetSubdomain]
            );
            if ($subRecord && !empty($subRecord['document_root'])) {
                $targetDir = $subRecord['document_root'];
                // Strip /public for Laravel-style apps
                if (str_ends_with($targetDir, '/public') && file_exists(dirname($targetDir) . '/.env')) {
                    $targetDir = dirname($targetDir);
                }
            }
        }

        // SSH mode: execute mysqldump remotely (needed when MySQL only listens on localhost)
        $sshHost = trim($_POST['ssh_host'] ?? '');
        $sshUser = trim($_POST['ssh_user'] ?? '');
        $sshPass = $_POST['ssh_password'] ?? '';
        $sshPort = (int) ($_POST['ssh_port'] ?? 22) ?: 22;
        $useSSH = !empty($sshHost) && !empty($sshUser) && !empty($sshPass);

        // Determine remote path for reading config files via SSH
        $sshRemotePath = trim($_POST['ssh_remote_path'] ?? '');
        if (!$sshRemotePath) {
            // Default: same vhosts structure as local
            $sshRemotePath = $targetDir;
        }

        $remoteDriver = 'mysql'; // Default; may be overridden by MuseDock detection

        // Auto-detect: resolve 'auto' to the actual project type
        if ($dbSource === 'auto') {
            if ($useSSH) {
                $autoDetect = $this->sshExec($sshPass, $sshUser, $sshHost, $sshPort,
                    'P=' . escapeshellarg(rtrim($sshRemotePath, '/')) . '; '
                    . 'if [ -f "$P/muse" ] && [ -f "$P/.env" ]; then echo "musedock"; '
                    . 'elif [ -f "$P/.env" ]; then echo "laravel"; '
                    . 'elif [ -f "$P/wp-config.php" ]; then echo "wordpress"; '
                    . 'elif [ -f "$P/application/settings/database.php" ]; then echo "zend"; '
                    . 'elif [ -f "$P/config/Config.inc.php" ]; then echo "webtv"; '
                    . 'else echo "unknown"; fi'
                );
            } else {
                if (file_exists($targetDir . '/muse') && file_exists($targetDir . '/.env')) $autoDetect = 'musedock';
                elseif (file_exists($targetDir . '/.env')) $autoDetect = 'laravel';
                elseif (file_exists($targetDir . '/wp-config.php')) $autoDetect = 'wordpress';
                elseif (file_exists($targetDir . '/application/settings/database.php')) $autoDetect = 'zend';
                else $autoDetect = 'unknown';
            }
            $dbSource = trim($autoDetect);
            if ($dbSource === 'unknown') {
                Flash::set('error', 'No se detecto ningun proyecto conocido (MuseDock, Laravel, WordPress, Zend). Usa la opcion Manual.');
                Router::redirect('/accounts/' . $params['id'] . '/migrate');
                return;
            }
        }

        if (($dbSource === 'laravel' || $dbSource === 'wordpress' || $dbSource === 'musedock' || $dbSource === 'zend' || $dbSource === 'webtv') && $useSSH) {
            // Read config file from REMOTE server via SSH (the local copy may already be modified)
            $configFileNames = ['musedock' => '.env', 'laravel' => '.env', 'wordpress' => 'wp-config.php', 'zend' => 'application/settings/database.php', 'webtv' => 'config/Config.inc.php'];
            $configFileName = $configFileNames[$dbSource] ?? '.env';
            $remoteConfigPath = rtrim($sshRemotePath, '/') . '/' . $configFileName;

            $content = shell_exec(sprintf(
                'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 -p %d %s@%s %s 2>/dev/null',
                escapeshellarg($sshPass), $sshPort, escapeshellarg($sshUser), escapeshellarg($sshHost),
                escapeshellarg('cat ' . escapeshellarg($remoteConfigPath))
            ));

            if (empty($content)) {
                Flash::set('error', "No se pudo leer {$configFileName} del servidor remoto ({$remoteConfigPath}). Verifica la ruta y credenciales SSH.");
                Router::redirect('/accounts/' . $params['id'] . '/migrate');
                return;
            }

            if ($dbSource === 'musedock') {
                $parsed = $this->parseMuseDockEnv($content);
                if (!$parsed) {
                    Flash::set('error', 'No se pudieron extraer credenciales MuseDock del .env remoto.');
                    Router::redirect('/accounts/' . $params['id'] . '/migrate');
                    return;
                }
                $remoteHost = $parsed['host'];
                $remotePort = $parsed['port'];
                $remoteDb = $parsed['database'];
                $remoteUser = $parsed['username'];
                $remotePass = $parsed['password'];
                $remoteDriver = $parsed['driver'] ?? 'mysql';
            } elseif ($dbSource === 'laravel') {
                $parsed = $this->parseLaravelEnv($content);
                if (!$parsed) {
                    Flash::set('error', 'No se pudieron extraer credenciales Laravel del .env remoto.');
                    Router::redirect('/accounts/' . $params['id'] . '/migrate');
                    return;
                }
                $remoteHost = $parsed['host'];
                $remotePort = $parsed['port'];
                $remoteDb = $parsed['database'];
                $remoteUser = $parsed['username'];
                $remotePass = $parsed['password'];
                $remoteDriver = $parsed['driver'] ?? 'mysql';
            } elseif ($dbSource === 'zend') {
                $parsed = $this->parseZendDbConfig($content);
                if (!$parsed) {
                    Flash::set('error', 'No se pudieron extraer credenciales Zend del database.php remoto.');
                    Router::redirect('/accounts/' . $params['id'] . '/migrate');
                    return;
                }
                $remoteHost = $parsed['host'];
                $remotePort = $parsed['port'];
                $remoteDb = $parsed['database'];
                $remoteUser = $parsed['username'];
                $remotePass = $parsed['password'];
            } elseif ($dbSource === 'webtv') {
                $parsed = $this->parseWebTvConfig($content);
                if (!$parsed) {
                    Flash::set('error', 'No se pudieron extraer credenciales WebTV del Config.inc.php remoto.');
                    Router::redirect('/accounts/' . $params['id'] . '/migrate');
                    return;
                }
                $remoteHost = $parsed['host'];
                $remotePort = $parsed['port'];
                $remoteDb = $parsed['database'];
                $remoteUser = $parsed['username'];
                $remotePass = $parsed['password'];
            } else {
                preg_match("/define\(\s*'DB_NAME'\s*,\s*'([^']+)'/", $content, $m); $remoteDb = $m[1] ?? '';
                preg_match("/define\(\s*'DB_USER'\s*,\s*'([^']+)'/", $content, $m); $remoteUser = $m[1] ?? '';
                preg_match("/define\(\s*'DB_PASSWORD'\s*,\s*'([^']+)'/", $content, $m); $remotePass = $m[1] ?? '';
                preg_match("/define\(\s*'DB_HOST'\s*,\s*'([^']+)'/", $content, $m); $remoteHost = $m[1] ?? 'localhost';
                $remotePort = '3306';
            }
        } elseif ($dbSource === 'musedock') {
            // Read from local .env (MuseDock format)
            $envFile = $targetDir . '/.env';
            if (!file_exists($envFile)) {
                Flash::set('error', 'No se encontro .env en ' . $targetDir);
                Router::redirect('/accounts/' . $params['id'] . '/migrate');
                return;
            }
            $parsed = $this->parseMuseDockEnv(file_get_contents($envFile));
            if (!$parsed) {
                Flash::set('error', 'No se pudieron extraer credenciales MuseDock del .env local.');
                Router::redirect('/accounts/' . $params['id'] . '/migrate');
                return;
            }
            $remoteHost = $parsed['host'];
            $remotePort = $parsed['port'];
            $remoteDb = $parsed['database'];
            $remoteUser = $parsed['username'];
            $remotePass = $parsed['password'];
            $remoteDriver = $parsed['driver'] ?? 'mysql';
        } elseif ($dbSource === 'laravel') {
            // Read from local .env
            $envFile = $targetDir . '/.env';
            if (!file_exists($envFile)) {
                Flash::set('error', 'No se encontro .env en ' . $targetDir);
                Router::redirect('/accounts/' . $params['id'] . '/migrate');
                return;
            }
            $env = parse_ini_file($envFile);
            $remoteHost = $env['DB_HOST'] ?? '127.0.0.1';
            $remotePort = $env['DB_PORT'] ?? '3306';
            $remoteDb = $env['DB_DATABASE'] ?? '';
            $remoteUser = $env['DB_USERNAME'] ?? '';
            $remotePass = $env['DB_PASSWORD'] ?? '';
        } elseif ($dbSource === 'wordpress') {
            // Read from local wp-config.php
            $wpConfig = $targetDir . '/wp-config.php';
            if (!file_exists($wpConfig)) {
                Flash::set('error', 'No se encontro wp-config.php en ' . $targetDir);
                Router::redirect('/accounts/' . $params['id'] . '/migrate');
                return;
            }
            $content = file_get_contents($wpConfig);
            preg_match("/define\(\s*'DB_NAME'\s*,\s*'([^']+)'/", $content, $m); $remoteDb = $m[1] ?? '';
            preg_match("/define\(\s*'DB_USER'\s*,\s*'([^']+)'/", $content, $m); $remoteUser = $m[1] ?? '';
            preg_match("/define\(\s*'DB_PASSWORD'\s*,\s*'([^']+)'/", $content, $m); $remotePass = $m[1] ?? '';
            preg_match("/define\(\s*'DB_HOST'\s*,\s*'([^']+)'/", $content, $m); $remoteHost = $m[1] ?? 'localhost';
            $remotePort = '3306';
        } elseif ($dbSource === 'zend') {
            // Read from local application/settings/database.php
            $zendFile = $targetDir . '/application/settings/database.php';
            if (!file_exists($zendFile)) {
                Flash::set('error', 'No se encontro application/settings/database.php en ' . $targetDir);
                Router::redirect('/accounts/' . $params['id'] . '/migrate');
                return;
            }
            $parsed = $this->parseZendDbConfig(file_get_contents($zendFile));
            if (!$parsed) {
                Flash::set('error', 'No se pudieron extraer credenciales Zend del database.php local.');
                Router::redirect('/accounts/' . $params['id'] . '/migrate');
                return;
            }
            $remoteHost = $parsed['host'];
            $remotePort = $parsed['port'];
            $remoteDb = $parsed['database'];
            $remoteUser = $parsed['username'];
            $remotePass = $parsed['password'];
        } else {
            $remoteHost = trim($_POST['db_host'] ?? '');
            $remotePort = trim($_POST['db_port'] ?? '3306');
            $remoteDb = trim($_POST['db_name'] ?? '');
            $remoteUser = trim($_POST['db_user'] ?? '');
            $remotePass = $_POST['db_password'] ?? '';
        }

        // DB_HOST can be "localhost:3306" or "127.0.0.1:3307" — split host and port
        if (str_contains($remoteHost, ':')) {
            [$remoteHost, $parsedPort] = explode(':', $remoteHost, 2);
            if (is_numeric($parsedPort)) {
                $remotePort = $parsedPort;
            }
        }

        if (empty($remoteDb) || empty($remoteUser)) {
            Flash::set('error', 'Nombre de BD y usuario son obligatorios.');
            Router::redirect('/accounts/' . $params['id'] . '/migrate');
            return;
        }

        $isPostgresDb = $remoteDriver === 'pgsql';
        $dumpToken = bin2hex(random_bytes(8));
        $dumpFile = "/tmp/mdpdb_{$dumpToken}.sql";
        $dumpErrFile = "/tmp/mdpdb_{$dumpToken}.err";

        if ($isPostgresDb) {
            $remoteDumpCmd = sprintf('PGPASSWORD=%s pg_dump -h %s -p %s -U %s %s',
                escapeshellarg($remotePass),
                escapeshellarg($remoteHost), escapeshellarg($remotePort),
                escapeshellarg($remoteUser),
                escapeshellarg($remoteDb)
            );
        } else {
            $remoteDumpCmd = sprintf('mysqldump -h %s -P %s -u %s -p%s %s',
                escapeshellarg($remoteHost), escapeshellarg($remotePort),
                escapeshellarg($remoteUser), escapeshellarg($remotePass),
                escapeshellarg($remoteDb)
            );
        }

        if ($useSSH) {
            // Execute dump remotely via SSH — stderr goes to remote temp file
            $remoteErrFile = '/tmp/mdp_dump_err_' . bin2hex(random_bytes(4));
            $fullRemoteCmd = $remoteDumpCmd . ' 2>' . escapeshellarg($remoteErrFile);

            shell_exec(sprintf(
                'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 -o ServerAliveInterval=30 -p %d %s@%s %s > %s 2>%s',
                escapeshellarg($sshPass), $sshPort, escapeshellarg($sshUser), escapeshellarg($sshHost),
                escapeshellarg($fullRemoteCmd),
                escapeshellarg($dumpFile), escapeshellarg($dumpErrFile)
            ));

            // Fetch remote error log if dump failed
            if (!file_exists($dumpFile) || filesize($dumpFile) < 50) {
                $remoteErr = shell_exec(sprintf(
                    'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -p %d %s@%s %s 2>/dev/null',
                    escapeshellarg($sshPass), $sshPort, escapeshellarg($sshUser), escapeshellarg($sshHost),
                    escapeshellarg('cat ' . escapeshellarg($remoteErrFile) . ' 2>/dev/null; rm -f ' . escapeshellarg($remoteErrFile))
                ));
                if ($remoteErr) {
                    file_put_contents($dumpErrFile, trim($remoteErr));
                }
            } else {
                // Cleanup remote error file in background
                shell_exec(sprintf(
                    'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 -p %d %s@%s %s 2>/dev/null &',
                    escapeshellarg($sshPass), $sshPort, escapeshellarg($sshUser), escapeshellarg($sshHost),
                    escapeshellarg('rm -f ' . escapeshellarg($remoteErrFile))
                ));
            }
        } else {
            // Direct dump connection (only works if remote DB allows external connections)
            shell_exec(sprintf('%s > %s 2>%s', $remoteDumpCmd, escapeshellarg($dumpFile), escapeshellarg($dumpErrFile)));
        }

        // Validate dump
        $dumpToolName = $isPostgresDb ? 'pg_dump' : 'mysqldump';
        $dumpOk = file_exists($dumpFile) && filesize($dumpFile) >= 50;
        if ($dumpOk) {
            $head = file_get_contents($dumpFile, false, null, 0, 200);
            if (preg_match('/^(mysqldump:|pg_dump:|error:|Access denied|FATAL:)/i', trim($head))) {
                $dumpOk = false;
            }
        }

        $errMsg = '';
        if (file_exists($dumpErrFile) && filesize($dumpErrFile) > 0) {
            $errMsg = trim(file_get_contents($dumpErrFile, false, null, 0, 500));
        }
        @unlink($dumpErrFile);

        if (!$dumpOk) {
            $detail = $errMsg ?: (isset($head) ? trim($head) : 'sin detalles');
            Flash::set('error', "{$dumpToolName} fallo: {$detail}");
            @unlink($dumpFile);
            Router::redirect('/accounts/' . $params['id'] . '/migrate');
            return;
        }

        $localDbName = str_replace(['.', '-'], '_', $account['username']) . '_db';
        $localDbUser = $account['username'];
        $localDbPass = bin2hex(random_bytes(12));
        $localDbType = $isPostgresDb ? 'pgsql' : 'mysql';

        if ($isPostgresDb) {
            $safeUser = str_replace('"', '""', $localDbUser);
            $safePass = str_replace("'", "''", $localDbPass);
            $safeDbName = str_replace('"', '""', $localDbName);

            shell_exec(sprintf("sudo -u postgres psql -c %s 2>&1", escapeshellarg("CREATE USER \"{$safeUser}\" WITH PASSWORD '{$safePass}'")));
            shell_exec(sprintf("sudo -u postgres psql -c %s 2>&1", escapeshellarg("ALTER USER \"{$safeUser}\" WITH PASSWORD '{$safePass}'")));
            shell_exec(sprintf("sudo -u postgres psql -c %s 2>&1", escapeshellarg("CREATE DATABASE \"{$safeDbName}\" OWNER \"{$safeUser}\"")));
            shell_exec(sprintf("sudo -u postgres psql -c %s 2>&1", escapeshellarg("GRANT ALL PRIVILEGES ON DATABASE \"{$safeDbName}\" TO \"{$safeUser}\"")));
            shell_exec(sprintf('sudo -u postgres psql -d %s < %s 2>&1', escapeshellarg($localDbName), escapeshellarg($dumpFile)));

            // Transfer ownership of ALL tables, sequences, views to the app user
            // pg_dump imports as postgres, so objects are owned by postgres — fix that
            shell_exec(sprintf("sudo -u postgres psql -d %s -c %s 2>&1",
                escapeshellarg($localDbName),
                escapeshellarg("REASSIGN OWNED BY postgres TO \"{$safeUser}\"")
            ));
            // Grant schema usage + all privileges
            shell_exec(sprintf("sudo -u postgres psql -d %s -c %s 2>&1",
                escapeshellarg($localDbName),
                escapeshellarg("GRANT ALL ON SCHEMA public TO \"{$safeUser}\"; GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO \"{$safeUser}\"; GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO \"{$safeUser}\"; GRANT ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA public TO \"{$safeUser}\";")
            ));
            // Set default privileges for future objects
            shell_exec(sprintf("sudo -u postgres psql -d %s -c %s 2>&1",
                escapeshellarg($localDbName),
                escapeshellarg("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO \"{$safeUser}\"; ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO \"{$safeUser}\";")
            ));
        } else {
            $sqlSetup = sprintf(
                "CREATE DATABASE IF NOT EXISTS `%s`;\nCREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s';\nALTER USER '%s'@'localhost' IDENTIFIED BY '%s';\nGRANT ALL ON `%s`.* TO '%s'@'localhost';\nFLUSH PRIVILEGES;\n",
                str_replace('`', '``', $localDbName),
                str_replace("'", "''", $localDbUser),
                str_replace("'", "''", $localDbPass),
                str_replace("'", "''", $localDbUser),
                str_replace("'", "''", $localDbPass),
                str_replace('`', '``', $localDbName),
                str_replace("'", "''", $localDbUser)
            );
            $sqlTmp = tempnam('/tmp', 'mdp_sql_');
            file_put_contents($sqlTmp, $sqlSetup);
            shell_exec(sprintf('mysql < %s 2>&1', escapeshellarg($sqlTmp)));
            @unlink($sqlTmp);
            shell_exec(sprintf('mysql %s < %s 2>&1', escapeshellarg($localDbName), escapeshellarg($dumpFile)));
        }

        $dumpSize = round(filesize($dumpFile) / 1048576, 1);

        // Only insert DB record if not already created by a previous migration attempt
        $existingDb = Database::fetchOne(
            "SELECT id FROM hosting_databases WHERE account_id = :aid AND db_name = :db",
            ['aid' => (int)$params['id'], 'db' => $localDbName]
        );
        if (!$existingDb) {
            Database::insert('hosting_databases', [
                'account_id' => (int)$params['id'],
                'db_name' => $localDbName, 'db_user' => $localDbUser, 'db_type' => $localDbType,
            ]);
        }

        if ($dbSource === 'musedock' && file_exists($targetDir . '/.env')) {
            $envContent = file_get_contents($targetDir . '/.env');
            $envContent = preg_replace('/^DB_DRIVER=.*/m', "DB_DRIVER=" . ($isPostgresDb ? 'pgsql' : 'mysql'), $envContent);
            $envContent = preg_replace('/^DB_HOST=.*/m', 'DB_HOST=localhost', $envContent);
            $envContent = preg_replace('/^DB_PORT=.*/m', "DB_PORT=" . ($isPostgresDb ? '5432' : '3306'), $envContent);
            $envContent = preg_replace('/^DB_NAME=.*/m', "DB_NAME={$localDbName}", $envContent);
            $envContent = preg_replace('/^DB_USER=.*/m', "DB_USER={$localDbUser}", $envContent);
            $envContent = preg_replace('/^DB_PASS=.*/m', "DB_PASS={$localDbPass}", $envContent);
            file_put_contents($targetDir . '/.env', $envContent);
        } elseif ($dbSource === 'laravel' && file_exists($targetDir . '/.env')) {
            $envContent = file_get_contents($targetDir . '/.env');
            $envContent = preg_replace('/^DB_HOST=.*/m', 'DB_HOST=127.0.0.1', $envContent);
            $envContent = preg_replace('/^DB_DATABASE=.*/m', "DB_DATABASE={$localDbName}", $envContent);
            $envContent = preg_replace('/^DB_USERNAME=.*/m', "DB_USERNAME={$localDbUser}", $envContent);
            $envContent = preg_replace('/^DB_PASSWORD=.*/m', "DB_PASSWORD={$localDbPass}", $envContent);
            file_put_contents($targetDir . '/.env', $envContent);
        } elseif ($dbSource === 'wordpress' && file_exists($targetDir . '/wp-config.php')) {
            $wpContent = file_get_contents($targetDir . '/wp-config.php');
            $wpContent = preg_replace("/define\(\s*'DB_NAME'\s*,\s*'[^']+'\)/", "define('DB_NAME', '{$localDbName}')", $wpContent);
            $wpContent = preg_replace("/define\(\s*'DB_USER'\s*,\s*'[^']+'\)/", "define('DB_USER', '{$localDbUser}')", $wpContent);
            $wpContent = preg_replace("/define\(\s*'DB_PASSWORD'\s*,\s*'[^']+'\)/", "define('DB_PASSWORD', '{$localDbPass}')", $wpContent);
            $wpContent = preg_replace("/define\(\s*'DB_HOST'\s*,\s*'[^']+'\)/", "define('DB_HOST', 'localhost')", $wpContent);
            file_put_contents($targetDir . '/wp-config.php', $wpContent);
        } elseif ($dbSource === 'zend' && file_exists($targetDir . '/application/settings/database.php')) {
            $zendFile = $targetDir . '/application/settings/database.php';
            $zendContent = file_get_contents($zendFile);
            $zendContent = preg_replace("/'host'\s*=>\s*'[^']*'/", "'host' => 'localhost'", $zendContent);
            $zendContent = preg_replace("/'username'\s*=>\s*'[^']*'/", "'username' => '{$localDbUser}'", $zendContent);
            $zendContent = preg_replace("/'password'\s*=>\s*'[^']*'/", "'password' => '{$localDbPass}'", $zendContent);
            $zendContent = preg_replace("/'dbname'\s*=>\s*'[^']*'/", "'dbname' => '{$localDbName}'", $zendContent);
            file_put_contents($zendFile, $zendContent);
        }

        @unlink($dumpFile);

        LogService::log('migration.db', $account['domain'], "DB migrated: {$remoteDb} -> {$localDbName} ({$dumpSize} MB)");
        Flash::set('success', "BD migrada: {$remoteDb} -> {$localDbName} ({$dumpSize} MB)");

        // Store credentials in session for the modal display
        $_SESSION['migration_db_pass'] = $localDbPass;
        $_SESSION['migration_db_name'] = $localDbName;
        $_SESSION['migration_db_user'] = $localDbUser;
        $_SESSION['migration_db_type'] = $localDbType;

        Router::redirect('/accounts/' . $params['id'] . '/migrate');
    }

    // ================================================================
    // Option 4: Subdomain individual migration (files + DB)
    // ================================================================

    /**
     * POST /accounts/{id}/migrate/subdomain
     * Migrates a single subdomain: rsync files via SSH + DB migration.
     */
    public function migrateSubdomain(array $params): void
    {
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $params['id']]);
        if (!$account) {
            Flash::set('error', 'Account not found.');
            Router::redirect('/accounts');
            return;
        }

        $subdomainId = (int)($_POST['subdomain_id'] ?? 0);
        $subdomain = Database::fetchOne(
            "SELECT * FROM hosting_subdomains WHERE id = :id AND account_id = :aid",
            ['id' => $subdomainId, 'aid' => $params['id']]
        );
        if (!$subdomain) {
            Flash::set('error', 'Subdominio no encontrado.');
            Router::redirect('/accounts/' . $params['id'] . '/migrate');
            return;
        }

        $scope = $_POST['sub_migrate_scope'] ?? 'all'; // all, files, db
        $sshHost = trim($_POST['ssh_host'] ?? '');
        $sshUser = trim($_POST['ssh_user'] ?? '');
        $sshPass = $_POST['ssh_password'] ?? '';
        $sshPort = (int)($_POST['ssh_port'] ?? 22) ?: 22;
        $remotePath = rtrim(trim($_POST['remote_subdomain_path'] ?? ''), '/');
        $localPath = $subdomain['document_root'];
        $autoDetectDb = !empty($_POST['auto_detect_db']);
        $subFqdn = $subdomain['subdomain'];

        if (empty($sshHost) || empty($sshUser) || empty($sshPass)) {
            Flash::set('error', 'Credenciales SSH obligatorias.');
            Router::redirect('/accounts/' . $params['id'] . '/migrate');
            return;
        }

        if (empty($remotePath)) {
            Flash::set('error', 'Ruta remota del subdominio obligatoria.');
            Router::redirect('/accounts/' . $params['id'] . '/migrate');
            return;
        }

        $logs = [];
        $logs[] = "Migrando subdominio: {$subFqdn}";
        $logs[] = "Scope: {$scope} | Remote: {$remotePath} | Local: {$localPath}";

        // ── Step 1: rsync files ──────────────────────────────
        if ($scope === 'all' || $scope === 'files') {
            $logs[] = "rsync: {$sshUser}@{$sshHost}:{$remotePath}/ → {$localPath}/";

            // Strip /public for rsync (sync the project root, not just public)
            $rsyncLocal = $localPath;
            $rsyncRemote = $remotePath;
            if (str_ends_with($localPath, '/public')) {
                $rsyncLocal = dirname($localPath);
                // Also strip /public from remote if it has it
                if (str_ends_with($remotePath, '/public')) {
                    $rsyncRemote = dirname($remotePath);
                }
            }

            $cmd = sprintf(
                'sshpass -p %s rsync -azP --partial -e "ssh -o StrictHostKeyChecking=no -p %d" %s@%s:%s/ %s/ 2>&1',
                escapeshellarg($sshPass),
                $sshPort,
                escapeshellarg($sshUser),
                escapeshellarg($sshHost),
                escapeshellarg($rsyncRemote),
                escapeshellarg($rsyncLocal)
            );

            $output = [];
            exec($cmd, $output, $rc);

            if ($rc !== 0 && $rc !== 24) {
                $logs[] = "ERROR rsync (exit {$rc}): " . implode("\n", array_slice($output, -5));
                Flash::set('error', "rsync fallo para {$subFqdn} (exit {$rc})");
                $_SESSION['migration_log'] = $logs;
                Router::redirect('/accounts/' . $params['id'] . '/migrate');
                return;
            }

            // Fix ownership
            exec(sprintf('chown -R %s:www-data %s 2>&1', escapeshellarg($account['username']), escapeshellarg($rsyncLocal)));
            $logs[] = "Archivos sincronizados correctamente.";
        }

        // ── Step 2: DB migration ─────────────────────────────
        $localDbPass = null;
        $localDbName = null;
        $localDbUser = null;
        $localDbType = null;

        if ($scope === 'all' || $scope === 'db') {
            $logs[] = "Migrando base de datos...";

            // Find .env in the project
            $envPath = $localPath;
            if (str_ends_with($envPath, '/public')) $envPath = dirname($envPath);

            // Read config from REMOTE server
            $remoteEnvPath = $remotePath;
            if (str_ends_with($remoteEnvPath, '/public')) $remoteEnvPath = dirname($remoteEnvPath);

            if ($autoDetectDb) {
                // Read .env from remote
                $remoteEnvContent = shell_exec(sprintf(
                    'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 -p %d %s@%s %s 2>/dev/null',
                    escapeshellarg($sshPass), $sshPort, escapeshellarg($sshUser), escapeshellarg($sshHost),
                    escapeshellarg('cat ' . escapeshellarg($remoteEnvPath . '/.env'))
                ));

                if (empty($remoteEnvContent)) {
                    $logs[] = "AVISO: No se pudo leer .env remoto. Intentando con .env local...";
                    $remoteEnvContent = @file_get_contents($envPath . '/.env');
                }

                if (!empty($remoteEnvContent)) {
                    // Parse DB credentials
                    $dbVars = [];
                    foreach (explode("\n", $remoteEnvContent) as $line) {
                        $line = trim($line);
                        if (empty($line) || $line[0] === '#') continue;
                        if (str_contains($line, '=')) {
                            [$k, $v] = explode('=', $line, 2);
                            $dbVars[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
                        }
                    }

                    $remoteDb = $dbVars['DB_DATABASE'] ?? $dbVars['DB_NAME'] ?? '';
                    $remoteUser = $dbVars['DB_USERNAME'] ?? $dbVars['DB_USER'] ?? '';
                    $remotePass = $dbVars['DB_PASSWORD'] ?? $dbVars['DB_PASS'] ?? '';
                    $remoteHost = $dbVars['DB_HOST'] ?? 'localhost';
                    $remotePort = $dbVars['DB_PORT'] ?? '3306';
                    $remoteDriver = $dbVars['DB_CONNECTION'] ?? $dbVars['DB_DRIVER'] ?? 'mysql';
                    $isPostgres = $remoteDriver === 'pgsql';

                    if (!empty($remoteDb) && !empty($remoteUser)) {
                        $logs[] = "BD detectada: {$remoteDb} ({$remoteDriver}) user={$remoteUser}";

                        // Dump from remote
                        $dumpFile = "/tmp/subdomain_db_" . bin2hex(random_bytes(4)) . ".sql";

                        if ($isPostgres) {
                            $dumpCmd = sprintf('PGPASSWORD=%s pg_dump -h %s -p %s -U %s %s',
                                escapeshellarg($remotePass), escapeshellarg($remoteHost),
                                escapeshellarg($remotePort), escapeshellarg($remoteUser), escapeshellarg($remoteDb));
                        } else {
                            $dumpCmd = sprintf('mysqldump -h %s -P %s -u %s -p%s %s',
                                escapeshellarg($remoteHost), escapeshellarg($remotePort),
                                escapeshellarg($remoteUser), escapeshellarg($remotePass), escapeshellarg($remoteDb));
                        }

                        shell_exec(sprintf(
                            'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 -p %d %s@%s %s > %s 2>/dev/null',
                            escapeshellarg($sshPass), $sshPort, escapeshellarg($sshUser), escapeshellarg($sshHost),
                            escapeshellarg($dumpCmd), escapeshellarg($dumpFile)
                        ));

                        if (file_exists($dumpFile) && filesize($dumpFile) > 50) {
                            $dumpSize = round(filesize($dumpFile) / 1048576, 1);
                            $logs[] = "Dump descargado: {$dumpSize} MB";

                            // Create local DB + user
                            $baseUser = preg_replace('/[^a-z0-9_]/i', '', (string)$account['username']);
                            $subPart = preg_replace('/[^a-z0-9_]/i', '_', (string)explode('.', $subFqdn)[0]);
                            $localDbName = substr($baseUser . '_' . $subPart . '_db', 0, 63);
                            $localDbUser = substr($baseUser, 0, 32);
                            if ($localDbName === '' || $localDbUser === '') {
                                $logs[] = 'ERROR: No se pudo construir nombre de BD/usuario local seguro.';
                                @unlink($dumpFile);
                                $_SESSION['migration_log'] = $logs;
                                Flash::set('error', 'No se pudo generar credenciales locales seguras para la BD del subdominio.');
                                Router::redirect('/accounts/' . $params['id'] . '/migrate');
                                return;
                            }
                            $localDbPass = bin2hex(random_bytes(12));
                            $localDbType = $isPostgres ? 'pgsql' : 'mysql';

                            if ($isPostgres) {
                                $safeUser = str_replace('"', '""', $localDbUser);
                                $safePass = str_replace("'", "''", $localDbPass);
                                $safeDbName = str_replace('"', '""', $localDbName);
                                shell_exec(sprintf("sudo -u postgres psql -c %s 2>&1", escapeshellarg("CREATE USER \"{$safeUser}\" WITH PASSWORD '{$safePass}'")));
                                shell_exec(sprintf("sudo -u postgres psql -c %s 2>&1", escapeshellarg("ALTER USER \"{$safeUser}\" WITH PASSWORD '{$safePass}'")));
                                shell_exec(sprintf("sudo -u postgres psql -c %s 2>&1", escapeshellarg("CREATE DATABASE \"{$safeDbName}\" OWNER \"{$safeUser}\"")));
                                shell_exec(sprintf("sudo -u postgres psql -c %s 2>&1", escapeshellarg("GRANT ALL PRIVILEGES ON DATABASE \"{$safeDbName}\" TO \"{$safeUser}\"")));
                                shell_exec(sprintf('sudo -u postgres psql -d %s < %s 2>&1', escapeshellarg($localDbName), escapeshellarg($dumpFile)));
                                // Fix ownership
                                shell_exec(sprintf("sudo -u postgres psql -d %s -c %s 2>&1", escapeshellarg($localDbName),
                                    escapeshellarg("REASSIGN OWNED BY postgres TO \"{$safeUser}\"; GRANT ALL ON SCHEMA public TO \"{$safeUser}\"; GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO \"{$safeUser}\"; GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO \"{$safeUser}\";")));
                            } else {
                                $safeDbNameSql = str_replace('`', '``', $localDbName);
                                $safeDbUserSql = str_replace("'", "''", $localDbUser);
                                $safeDbPassSql = str_replace("'", "''", $localDbPass);
                                $setupSql = "CREATE DATABASE IF NOT EXISTS `{$safeDbNameSql}`;\n"
                                    . "CREATE USER IF NOT EXISTS '{$safeDbUserSql}'@'localhost' IDENTIFIED BY '{$safeDbPassSql}';\n"
                                    . "ALTER USER '{$safeDbUserSql}'@'localhost' IDENTIFIED BY '{$safeDbPassSql}';\n"
                                    . "GRANT ALL ON `{$safeDbNameSql}`.* TO '{$safeDbUserSql}'@'localhost';\nFLUSH PRIVILEGES;\n";
                                $tmpSql = tempnam('/tmp', 'dbsetup');
                                file_put_contents($tmpSql, $setupSql);
                                shell_exec("mysql < " . escapeshellarg($tmpSql) . " 2>&1");
                                @unlink($tmpSql);
                                shell_exec('mysql ' . escapeshellarg($localDbName) . ' < ' . escapeshellarg($dumpFile) . ' 2>&1');
                            }

                            $logs[] = "BD creada: {$localDbName} (user: {$localDbUser})";

                            // Register in hosting_databases
                            $existingDb = Database::fetchOne(
                                "SELECT id FROM hosting_databases WHERE account_id = :aid AND db_name = :db",
                                ['aid' => (int)$params['id'], 'db' => $localDbName]
                            );
                            if (!$existingDb) {
                                Database::insert('hosting_databases', [
                                    'account_id' => (int)$params['id'],
                                    'db_name' => $localDbName, 'db_user' => $localDbUser, 'db_type' => $localDbType,
                                ]);
                            }

                            // Update local .env
                            if (file_exists($envPath . '/.env')) {
                                $env = file_get_contents($envPath . '/.env');
                                $dbHost = $isPostgres ? '127.0.0.1' : '127.0.0.1';
                                $dbPort = $isPostgres ? '5432' : '3306';
                                $env = preg_replace('/^DB_HOST=.*/m', "DB_HOST={$dbHost}", $env);
                                if (preg_match('/^DB_PORT=.*/m', $env)) {
                                    $env = preg_replace('/^DB_PORT=.*/m', "DB_PORT={$dbPort}", $env);
                                }
                                if (str_contains($env, 'DB_DATABASE')) {
                                    $env = preg_replace('/^DB_DATABASE=.*/m', "DB_DATABASE={$localDbName}", $env);
                                    $env = preg_replace('/^DB_USERNAME=.*/m', "DB_USERNAME={$localDbUser}", $env);
                                    $env = preg_replace('/^DB_PASSWORD=.*/m', "DB_PASSWORD={$localDbPass}", $env);
                                } elseif (str_contains($env, 'DB_NAME')) {
                                    $env = preg_replace('/^DB_NAME=.*/m', "DB_NAME={$localDbName}", $env);
                                    $env = preg_replace('/^DB_USER=.*/m', "DB_USER={$localDbUser}", $env);
                                    $env = preg_replace('/^DB_PASS=.*/m', "DB_PASS={$localDbPass}", $env);
                                }
                                if ($isPostgres && preg_match('/^DB_SSLMODE=.*/m', $env)) {
                                    $env = preg_replace('/^DB_SSLMODE=.*/m', 'DB_SSLMODE=prefer', $env);
                                }
                                file_put_contents($envPath . '/.env', $env);
                                $logs[] = ".env actualizado: {$envPath}/.env";
                            }
                        } else {
                            $logs[] = "AVISO: Dump vacio o fallo. BD no migrada.";
                        }
                        @unlink($dumpFile);
                    } else {
                        $logs[] = "AVISO: No se detectaron credenciales de BD en .env";
                    }
                } else {
                    $logs[] = "AVISO: No se encontro .env para auto-deteccion de BD";
                }
            }
        }

        $logs[] = "Migracion de subdominio completada!";

        LogService::log('migration.subdomain', $subFqdn, "Subdomain migrated: scope={$scope}");
        Flash::set('success', "Subdominio {$subFqdn} migrado correctamente.");

        $_SESSION['migration_log'] = $logs;
        if ($localDbPass) {
            $_SESSION['migration_db_pass'] = $localDbPass;
            $_SESSION['migration_db_name'] = $localDbName;
            $_SESSION['migration_db_user'] = $localDbUser;
            $_SESSION['migration_db_type'] = $localDbType;
        }

        Router::redirect('/accounts/' . $params['id'] . '/migrate');
    }
}
