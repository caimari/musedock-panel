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

        View::render('accounts/migrate', [
            'layout' => 'main',
            'pageTitle' => 'Migrate: ' . $account['domain'],
            'account' => $account,
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

    private function sshExec(string $password, string $user, string $host, int $port, string $remoteCmd): string
    {
        $cmd = sprintf(
            'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 -p %d %s@%s %s 2>&1',
            escapeshellarg($password), $port, escapeshellarg($user), escapeshellarg($host), escapeshellarg($remoteCmd)
        );
        return shell_exec($cmd) ?? '';
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
        return [
            'host' => $values['DB_HOST'] ?? '127.0.0.1',
            'port' => $values['DB_PORT'] ?? '3306',
            'database' => $db,
            'username' => $user,
            'password' => $values['DB_PASSWORD'] ?? '',
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

        if (strpos($pathCheck, 'PATH_OK') === false) {
            echo json_encode(['ok' => false, 'message' => "Ruta remota no existe: {$fullRemotePath}"]);
            return;
        }

        $statsOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
            "cd " . escapeshellarg($fullRemotePath) . " && echo FILES:\$(find . -type f | wc -l) && echo SIZE:\$(du -sh . 2>/dev/null | cut -f1)"
        );

        $fileCount = 0;
        $dirSize = '?';
        if (preg_match('/FILES:(\d+)/', $statsOutput, $m)) $fileCount = (int) $m[1];
        if (preg_match('/SIZE:(\S+)/', $statsOutput, $m)) $dirSize = $m[1];

        $projectInfo = 'Desconocido';
        $envCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "test -f " . escapeshellarg($fullRemotePath . '/.env') . " && echo 'LARAVEL'");
        $wpCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "test -f " . escapeshellarg($fullRemotePath . '/wp-config.php') . " && echo 'WORDPRESS'");

        if (strpos($envCheck, 'LARAVEL') !== false) $projectInfo = 'Laravel (detectado .env)';
        elseif (strpos($wpCheck, 'WORDPRESS') !== false) $projectInfo = 'WordPress (detectado wp-config.php)';

        echo json_encode([
            'ok' => true, 'message' => 'Conexion SSH OK',
            'files' => $fileCount, 'size' => $dirSize,
            'project' => $projectInfo, 'path' => $fullRemotePath,
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

        $targetDir = $account['document_root'];
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
            'include_db' => isset($_POST['include_db']),
            'exclude_vendor' => isset($_POST['exclude_vendor']),
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
        $status = json_decode(file_get_contents($statusFile), true);
        echo json_encode($status ?: ['active' => false]);
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
        $targetDir = $account['document_root'];

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

        // --- STEP 3: Detect project ---
        $projectType = 'unknown';
        $dbCredentials = null;
        $hasComposer = false;

        $composerCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort,
            "test -f " . escapeshellarg($fullRemotePath . '/composer.json') . " && echo 'COMPOSER_EXISTS'"
        );
        $hasComposer = strpos($composerCheck, 'COMPOSER_EXISTS') !== false;

        if ($includeDb) {
            $this->sendSSE('log', 'Detectando tipo de proyecto...', $st);
            $this->sendSSE('step', 'Detectando proyecto', $st);

            $envCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "test -f " . escapeshellarg($fullRemotePath . '/.env') . " && echo 'ENV_EXISTS'");
            $wpCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "test -f " . escapeshellarg($fullRemotePath . '/wp-config.php') . " && echo 'WP_EXISTS'");

            if (strpos($envCheck, 'ENV_EXISTS') !== false) {
                $projectType = 'laravel';
                $this->sendSSE('log', 'Proyecto detectado: Laravel (.env)', $st);
                $envContent = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "cat " . escapeshellarg($fullRemotePath . '/.env'));
                $dbCredentials = $this->parseLaravelEnv($envContent);
                if ($dbCredentials) {
                    $this->sendSSE('log', 'Credenciales BD: DB=' . $dbCredentials['database'] . ', User=' . $dbCredentials['username'], $st);
                } else {
                    $this->sendSSE('log', 'No se pudieron extraer credenciales del .env.', $st);
                    $includeDb = false;
                }
            } elseif (strpos($wpCheck, 'WP_EXISTS') !== false) {
                $projectType = 'wordpress';
                $this->sendSSE('log', 'Proyecto detectado: WordPress (wp-config.php)', $st);
                $wpContent = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "cat " . escapeshellarg($fullRemotePath . '/wp-config.php'));
                $dbCredentials = $this->parseWpConfig($wpContent);
                if ($dbCredentials) {
                    $this->sendSSE('log', 'Credenciales BD: DB=' . $dbCredentials['database'] . ', User=' . $dbCredentials['username'], $st);
                } else {
                    $this->sendSSE('log', 'No se pudieron extraer credenciales del wp-config.php.', $st);
                    $includeDb = false;
                }
            } else {
                $this->sendSSE('log', 'No se detecto Laravel ni WordPress. Solo archivos.', $st);
                $includeDb = false;
            }
        } else {
            $envCheck = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "test -f " . escapeshellarg($fullRemotePath . '/.env') . " && echo 'ENV_EXISTS'");
            if (strpos($envCheck, 'ENV_EXISTS') !== false) $projectType = 'laravel';
        }

        if ($excludeVendor && !$hasComposer) {
            $this->sendSSE('log', 'AVISO: Se pidio omitir vendor/ pero no hay composer.json. Se copiara todo.', $st);
            $excludeVendor = false;
        }

        // --- STEP 4: Create backup ---
        $this->sendSSE('log', 'Creando backup en servidor remoto...', $st);
        $this->sendSSE('step', 'Creando backup', $st);

        $excludeArgs = "--exclude=" . escapeshellarg($backupName);
        if ($excludeVendor) {
            $excludeArgs .= " --exclude='./vendor'";
            $this->sendSSE('log', 'Excluyendo vendor/ del backup.', $st);
        }

        $tarCmd = "cd " . escapeshellarg($fullRemotePath) . " && tar czf " . escapeshellarg($remoteBackupPath) . " {$excludeArgs} . 2>&1; echo TAR_EXIT_\$?";
        $tarOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, $tarCmd);

        // tar exit code 1 = "file changed as we read it" — this is a warning, not an error
        // Only exit code 2+ is a real failure
        $tarExitCode = 2; // assume failure
        if (preg_match('/TAR_EXIT_(\d+)/', $tarOutput, $tarM)) {
            $tarExitCode = (int) $tarM[1];
        }

        if ($tarExitCode === 1) {
            $this->sendSSE('log', 'AVISO: Algunos archivos cambiaron durante la compresion (normal en sitios activos). Backup creado correctamente.', $st);
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
            // File exists despite exit code — proceed with warning
            $this->sendSSE('log', 'AVISO: tar reporto errores pero el backup se creo. Continuando...', $st);
        }

        $remoteSizeOutput = $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "stat -c%s " . escapeshellarg($remoteBackupPath) . " 2>/dev/null || echo 0");
        $remoteSizeBytes = (int) trim($remoteSizeOutput);
        $remoteSize = round($remoteSizeBytes / 1048576, 1);
        $this->sendSSE('log', 'Backup creado: ' . $remoteSize . ' MB', $st);

        // --- STEP 5: Download via HTTPS (with progress) ---
        $downloadUrl = "https://{$remoteDomain}/{$backupName}";
        $this->sendSSE('log', 'Descargando por HTTPS: ' . $downloadUrl, $st);
        $this->sendSSE('step', 'Descargando (' . $remoteSize . ' MB)', $st);
        $this->sendSSE('progress', json_encode(['type' => 'download', 'total' => $remoteSizeBytes, 'current' => 0]), $st);

        // Download with progress tracking via proc_open
        $downloaded = $this->downloadWithProgress($downloadUrl, $localBackup, $remoteSizeBytes, $st);

        if (!$downloaded) {
            // Try HTTP fallback
            $downloadUrlHttp = "http://{$remoteDomain}/{$backupName}";
            $this->sendSSE('log', 'HTTPS fallo, intentando HTTP...', $st);
            $downloaded = $this->downloadWithProgress($downloadUrlHttp, $localBackup, $remoteSizeBytes, $st);
        }

        if (!$downloaded) {
            $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "rm -f " . escapeshellarg($remoteBackupPath));
            @unlink($localBackup);
            $this->sendSSE('error', 'Error descargando backup. Verifica que el dominio remoto tenga servidor web.', $st);
            $this->sendSSE('done', json_encode(['ok' => false]), $st);
            return;
        }

        $this->sendSSE('progress', json_encode(['type' => 'download', 'total' => $remoteSizeBytes, 'current' => $remoteSizeBytes, 'percent' => 100]), $st);
        $localSize = round(filesize($localBackup) / 1048576, 1);
        $this->sendSSE('log', 'Backup descargado: ' . $localSize . ' MB', $st);

        // Delete from remote immediately
        $this->sshExec($sshPassword, $sshUser, $sshHost, $sshPort, "rm -f " . escapeshellarg($remoteBackupPath));
        $this->sendSSE('log', 'Backup eliminado del servidor remoto.', $st);

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

            $this->sendSSE('log', 'Ejecutando mysqldump en servidor remoto...', $st);
            $this->sendSSE('progress', json_encode(['type' => 'mysqldump', 'indeterminate' => true]), $st);

            $remoteMysqldumpCmd = sprintf('mysqldump -h %s -P %s -u %s -p%s %s 2>/dev/null',
                escapeshellarg($dbCredentials['host']), escapeshellarg($dbCredentials['port']),
                escapeshellarg($dbCredentials['username']), escapeshellarg($dbCredentials['password']),
                escapeshellarg($dbCredentials['database'])
            );

            shell_exec(sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 -p %d %s@%s %s > %s 2>&1',
                escapeshellarg($sshPassword), $sshPort, escapeshellarg($sshUser), escapeshellarg($sshHost),
                escapeshellarg($remoteMysqldumpCmd), escapeshellarg($dumpFile)
            ));

            if (!file_exists($dumpFile) || filesize($dumpFile) < 50) {
                $errors[] = 'mysqldump fallo';
                $this->sendSSE('error', 'mysqldump fallo. Se migraron solo los archivos.', $st);
                @unlink($dumpFile);
            } else {
                $dumpSize = round(filesize($dumpFile) / 1048576, 1);
                $this->sendSSE('log', 'Dump descargado: ' . $dumpSize . ' MB', $st);

                $localDbName = str_replace(['.', '-'], '_', $account['username']) . '_db';
                $localDbUser = $account['username'];
                $localDbPass = bin2hex(random_bytes(12));

                $this->sendSSE('log', 'Creando base de datos local: ' . $localDbName, $st);

                $sqlSetup = sprintf(
                    "CREATE DATABASE IF NOT EXISTS `%s`;\nCREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s';\nGRANT ALL ON `%s`.* TO '%s'@'localhost';\nFLUSH PRIVILEGES;\n",
                    str_replace('`', '``', $localDbName),
                    str_replace("'", "''", $localDbUser),
                    str_replace("'", "''", $localDbPass),
                    str_replace('`', '``', $localDbName),
                    str_replace("'", "''", $localDbUser)
                );
                $sqlTmp = tempnam('/tmp', 'mdp_sql_');
                file_put_contents($sqlTmp, $sqlSetup);
                shell_exec(sprintf('mysql < %s 2>&1', escapeshellarg($sqlTmp)));
                @unlink($sqlTmp);
                $this->sendSSE('log', 'Base de datos y usuario creados.', $st);

                // Save DB record and update config files BEFORE import
                // (so if the process dies during import, credentials are already saved)
                Database::insert('hosting_databases', [
                    'account_id' => (int)$data['account_id'],
                    'db_name' => $localDbName,
                    'db_user' => $localDbUser,
                    'db_type' => 'mysql',
                ]);

                // Update config files immediately (critical — must happen before potential crash)
                if ($projectType === 'laravel' && file_exists($targetDir . '/.env')) {
                    $envContent = file_get_contents($targetDir . '/.env');
                    $envContent = preg_replace('/DB_HOST=.*/', 'DB_HOST=127.0.0.1', $envContent);
                    $envContent = preg_replace('/DB_PORT=.*/', 'DB_PORT=3306', $envContent);
                    $envContent = preg_replace('/DB_DATABASE=.*/', "DB_DATABASE={$localDbName}", $envContent);
                    $envContent = preg_replace('/DB_USERNAME=.*/', "DB_USERNAME={$localDbUser}", $envContent);
                    $envContent = preg_replace('/DB_PASSWORD=.*/', "DB_PASSWORD={$localDbPass}", $envContent);
                    file_put_contents($targetDir . '/.env', $envContent);
                    $this->sendSSE('log', 'Actualizado .env con credenciales locales.', $st);
                } elseif ($projectType === 'wordpress' && file_exists($targetDir . '/wp-config.php')) {
                    $wpContent = file_get_contents($targetDir . '/wp-config.php');
                    $wpContent = preg_replace("/define\(\s*'DB_NAME'\s*,\s*'[^']*'\)/", "define('DB_NAME', '{$localDbName}')", $wpContent);
                    $wpContent = preg_replace("/define\(\s*'DB_USER'\s*,\s*'[^']*'\)/", "define('DB_USER', '{$localDbUser}')", $wpContent);
                    $wpContent = preg_replace("/define\(\s*'DB_PASSWORD'\s*,\s*'[^']*'\)/", "define('DB_PASSWORD', '{$localDbPass}')", $wpContent);
                    $wpContent = preg_replace("/define\(\s*'DB_HOST'\s*,\s*'[^']*'\)/", "define('DB_HOST', 'localhost')", $wpContent);
                    file_put_contents($targetDir . '/wp-config.php', $wpContent);
                    $this->sendSSE('log', 'Actualizado wp-config.php con credenciales locales.', $st);
                }

                // Persist db_pass to status file immediately (in case process dies during import)
                $this->appendStatus($st, 'db_pass_saved', $localDbPass);

                $this->sendSSE('log', 'Importando dump SQL...', $st);
                $this->sendSSE('step', 'Importando BD (' . $dumpSize . ' MB)', $st);
                $this->sendSSE('progress', json_encode(['type' => 'import', 'indeterminate' => true]), $st);

                shell_exec(sprintf('mysql %s < %s 2>&1', escapeshellarg($localDbName), escapeshellarg($dumpFile)));
                $this->sendSSE('log', 'Dump importado (' . $dumpSize . ' MB).', $st);

                @unlink($dumpFile);
            }
        }

        // --- STEP 8: Cleanup ---
        @unlink($localBackup);

        // --- Done ---
        $summary = "Migracion completada desde {$sshUser}@{$sshHost} ({$localSize} MB via HTTPS)";
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
        $cmd = sprintf('wget --no-check-certificate -O %s %s 2>&1', escapeshellarg($dest), escapeshellarg($url));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            // Fallback to simple shell_exec
            shell_exec($cmd);
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
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) break;

            // Check file size for progress
            clearstatcache(true, $dest);
            if (file_exists($dest) && $expectedSize > 0) {
                $current = filesize($dest);
                $now = microtime(true);
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
                    $elapsed = $now - $startTime;
                    $eta = ($speed > 0 && $pct < 99) ? (int) round(($expectedSize - $current) / $speed) : 0;
                    $this->sendSSE('progress', json_encode([
                        'type' => 'download', 'total' => $expectedSize,
                        'current' => $current, 'percent' => $pct,
                        'speed' => (int) $speed, 'eta' => $eta,
                    ]), $statusToken);
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

        if ($dbSource === 'laravel') {
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
        } else {
            $remoteHost = trim($_POST['db_host'] ?? '');
            $remotePort = trim($_POST['db_port'] ?? '3306');
            $remoteDb = trim($_POST['db_name'] ?? '');
            $remoteUser = trim($_POST['db_user'] ?? '');
            $remotePass = $_POST['db_password'] ?? '';
        }

        if (empty($remoteDb) || empty($remoteUser)) {
            Flash::set('error', 'Nombre de BD y usuario son obligatorios.');
            Router::redirect('/accounts/' . $params['id'] . '/migrate');
            return;
        }

        $dumpToken = bin2hex(random_bytes(8));
        $dumpFile = "/tmp/mdpdb_{$dumpToken}.sql";

        shell_exec(sprintf('mysqldump -h %s -P %s -u %s -p%s %s > %s 2>&1',
            escapeshellarg($remoteHost), escapeshellarg($remotePort),
            escapeshellarg($remoteUser), escapeshellarg($remotePass),
            escapeshellarg($remoteDb), escapeshellarg($dumpFile)
        ));

        if (!file_exists($dumpFile) || filesize($dumpFile) < 50) {
            Flash::set('error', 'mysqldump fallo. Verifica credenciales.');
            @unlink($dumpFile);
            Router::redirect('/accounts/' . $params['id'] . '/migrate');
            return;
        }

        $localDbName = str_replace(['.', '-'], '_', $account['username']) . '_db';
        $localDbUser = $account['username'];
        $localDbPass = bin2hex(random_bytes(12));

        $sqlSetup = sprintf(
            "CREATE DATABASE IF NOT EXISTS `%s`;\nCREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s';\nGRANT ALL ON `%s`.* TO '%s'@'localhost';\nFLUSH PRIVILEGES;\n",
            str_replace('`', '``', $localDbName),
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

        $dumpSize = round(filesize($dumpFile) / 1048576, 1);

        Database::insert('hosting_databases', [
            'account_id' => (int)$params['id'],
            'db_name' => $localDbName, 'db_user' => $localDbUser, 'db_type' => 'mysql',
        ]);

        if ($dbSource === 'laravel' && file_exists($targetDir . '/.env')) {
            $envContent = file_get_contents($targetDir . '/.env');
            $envContent = preg_replace('/DB_HOST=.*/', 'DB_HOST=127.0.0.1', $envContent);
            $envContent = preg_replace('/DB_DATABASE=.*/', "DB_DATABASE={$localDbName}", $envContent);
            $envContent = preg_replace('/DB_USERNAME=.*/', "DB_USERNAME={$localDbUser}", $envContent);
            $envContent = preg_replace('/DB_PASSWORD=.*/', "DB_PASSWORD={$localDbPass}", $envContent);
            file_put_contents($targetDir . '/.env', $envContent);
        } elseif ($dbSource === 'wordpress' && file_exists($targetDir . '/wp-config.php')) {
            $wpContent = file_get_contents($targetDir . '/wp-config.php');
            $wpContent = preg_replace("/define\(\s*'DB_NAME'\s*,\s*'[^']+'\)/", "define('DB_NAME', '{$localDbName}')", $wpContent);
            $wpContent = preg_replace("/define\(\s*'DB_USER'\s*,\s*'[^']+'\)/", "define('DB_USER', '{$localDbUser}')", $wpContent);
            $wpContent = preg_replace("/define\(\s*'DB_PASSWORD'\s*,\s*'[^']+'\)/", "define('DB_PASSWORD', '{$localDbPass}')", $wpContent);
            $wpContent = preg_replace("/define\(\s*'DB_HOST'\s*,\s*'[^']+'\)/", "define('DB_HOST', 'localhost')", $wpContent);
            file_put_contents($targetDir . '/wp-config.php', $wpContent);
        }

        @unlink($dumpFile);

        LogService::log('migration.db', $account['domain'], "DB migrated: {$remoteDb} -> {$localDbName} ({$dumpSize} MB)");
        Flash::set('success', "BD migrada: {$remoteDb} -> {$localDbName} ({$dumpSize} MB). Credenciales: user={$localDbUser}, pass={$localDbPass}");
        Router::redirect('/accounts/' . $params['id'] . '/migrate');
    }
}
