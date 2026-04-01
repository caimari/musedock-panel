<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Auth;
use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;
use MuseDockPanel\Services\FileAuditService;
use MuseDockPanel\Services\LogService;

class FileManagerController
{
    private const FILEOP_BIN = '/opt/musedock-panel/bin/musedock-fileop';

    private const EDITABLE_EXTENSIONS = [
        'php', 'html', 'htm', 'css', 'js', 'json', 'xml', 'txt', 'md',
        'env', 'htaccess', 'yml', 'yaml', 'toml', 'ini', 'conf', 'cfg',
        'sh', 'bash', 'py', 'rb', 'sql', 'log', 'csv', 'svg',
    ];

    // ----------------------------------------------------------------
    // File listing
    // ----------------------------------------------------------------

    public function index(array $params = []): void
    {
        $account = $this->getAccount($params);
        if (!$account) return;

        $path = $this->sanitizePath($_GET['path'] ?? '/');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(500, max(20, (int)($_GET['per_page'] ?? 100)));
        $sort = in_array($_GET['sort'] ?? '', ['name', 'size', 'modified', 'type', 'perms']) ? $_GET['sort'] : 'name';
        $order = ($_GET['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $search = trim($_GET['search'] ?? '');

        $result = $this->fileop('list', $account, $path);

        // Audit: log directory listing
        FileAuditService::log($account, 'list', $path, [
            'items_count' => $result['ok'] ? count($result['items'] ?? []) : 0,
        ]);

        if (!$result['ok']) {
            if ($this->isAjax()) {
                $this->json(['ok' => false, 'error' => $result['error'] ?? 'Error desconocido']);
                return;
            }
            View::render('files/index', [
                'layout' => 'main',
                'pageTitle' => $account['domain'] . ' — Files',
                'account' => $account,
                'currentPath' => $path,
                'items' => [],
                'pagination' => null,
                'error' => $result['error'] ?? 'Error desconocido',
                'writeMode' => $this->isWriteMode(),
            ]);
            return;
        }

        $items = $result['items'] ?? [];

        // Search filter
        if ($search !== '') {
            $searchLower = mb_strtolower($search);
            $items = array_values(array_filter($items, fn($item) =>
                str_contains(mb_strtolower($item['name']), $searchLower)
            ));
        }

        // Sort (dirs first, then by column)
        usort($items, function ($a, $b) use ($sort, $order) {
            $aDir = ($a['type'] === 'dir') ? 0 : 1;
            $bDir = ($b['type'] === 'dir') ? 0 : 1;
            if ($aDir !== $bDir) return $aDir - $bDir;
            $cmp = match ($sort) {
                'size' => $a['size'] <=> $b['size'],
                'modified' => $a['modified'] <=> $b['modified'],
                'type' => strcmp($a['type'], $b['type']),
                'perms' => strcmp($a['perms'], $b['perms']),
                default => strcasecmp($a['name'], $b['name']),
            };
            return $order === 'desc' ? -$cmp : $cmp;
        });

        $total = count($items);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $pageItems = array_slice($items, $offset, $perPage);

        $pagination = [
            'total' => $total, 'page' => $page, 'per_page' => $perPage,
            'pages' => $pages, 'sort' => $sort, 'order' => $order, 'search' => $search,
        ];

        if ($this->isAjax()) {
            $this->json([
                'ok' => true, 'items' => $pageItems, 'total' => $total,
                'page' => $page, 'per_page' => $perPage, 'pages' => $pages,
                'path' => $path, 'parent' => $this->parentPath($path),
            ]);
            return;
        }

        View::render('files/index', [
            'layout' => 'main',
            'pageTitle' => $account['domain'] . ' — Files',
            'account' => $account,
            'currentPath' => $path,
            'items' => $pageItems,
            'pagination' => $pagination,
            'error' => null,
            'writeMode' => $this->isWriteMode(),
        ]);
    }

    // ----------------------------------------------------------------
    // Read / Edit file
    // ----------------------------------------------------------------

    public function edit(array $params = []): void
    {
        $account = $this->getAccount($params);
        if (!$account) return;

        $path = $this->sanitizePath($_GET['path'] ?? '');
        if (!$this->isEditable($path)) {
            Flash::set('error', 'Este tipo de archivo no se puede editar.');
            Router::redirect('/accounts/' . $account['id'] . '/files?path=' . urlencode(dirname($path)));
            return;
        }

        $result = $this->fileop('read', $account, $path);

        FileAuditService::log($account, 'read', $path, [
            'size' => $result['size'] ?? 0,
        ]);

        View::render('files/edit', [
            'layout' => 'main',
            'pageTitle' => $account['domain'] . ' — Edit ' . basename($path),
            'account' => $account,
            'filePath' => $path,
            'fileName' => basename($path),
            'content' => $result['content'] ?? '',
            'error' => $result['ok'] ? null : ($result['error'] ?? 'Error leyendo archivo'),
            'writeMode' => $this->isWriteMode(),
        ]);
    }

    // ----------------------------------------------------------------
    // Write / Save file
    // ----------------------------------------------------------------

    public function save(array $params = []): void
    {
        $account = $this->getAccount($params);
        if (!$account) return;

        if (!$this->requireWriteMode($account['id'])) return;

        $path = $this->sanitizePath($_POST['path'] ?? '');
        $content = $_POST['content'] ?? '';

        if (!$this->isEditable($path)) {
            Flash::set('error', 'No se puede guardar este tipo de archivo.');
            Router::redirect('/accounts/' . $account['id'] . '/files?path=' . urlencode(dirname($path)));
            return;
        }

        $result = $this->fileop('write', $account, $path, ['content' => $content]);

        FileAuditService::log($account, 'write', $path, [
            'size_after' => strlen($content),
        ]);
        LogService::log('file.write', $account['domain'], $path);

        if ($result['ok']) {
            Flash::set('success', 'Archivo guardado.');
        } else {
            Flash::set('error', 'Error al guardar: ' . ($result['error'] ?? ''));
        }
        Router::redirect('/accounts/' . $account['id'] . '/files/edit?path=' . urlencode($path));
    }

    // ----------------------------------------------------------------
    // Mkdir
    // ----------------------------------------------------------------

    public function mkdir(array $params = []): void
    {
        $account = $this->getAccount($params);
        if (!$account) return;

        if (!$this->requireWriteMode($account['id'])) return;

        $parentPath = $this->sanitizePath($_POST['parent_path'] ?? '/');
        $dirName = trim($_POST['dir_name'] ?? '');

        if (empty($dirName) || preg_match('/[\/\0]/', $dirName)) {
            Flash::set('error', 'Nombre de carpeta no valido.');
            Router::redirect('/accounts/' . $account['id'] . '/files?path=' . urlencode($parentPath));
            return;
        }

        $newPath = rtrim($parentPath, '/') . '/' . $dirName;
        $result = $this->fileop('mkdir', $account, $newPath);

        FileAuditService::log($account, 'mkdir', $newPath);
        LogService::log('file.mkdir', $account['domain'], $newPath);

        if (!$result['ok']) {
            Flash::set('error', 'Error: ' . ($result['error'] ?? ''));
        }
        Router::redirect('/accounts/' . $account['id'] . '/files?path=' . urlencode($parentPath));
    }

    // ----------------------------------------------------------------
    // Delete
    // ----------------------------------------------------------------

    public function delete(array $params = []): void
    {
        $account = $this->getAccount($params);
        if (!$account) return;

        if (!$this->requireWriteMode($account['id'])) return;

        $path = $this->sanitizePath($_POST['path'] ?? '');
        $parentPath = dirname($path);

        $result = $this->fileop('delete', $account, $path);

        FileAuditService::log($account, 'delete', $path);
        LogService::log('file.delete', $account['domain'], $path);

        if (!$result['ok']) {
            Flash::set('error', 'Error: ' . ($result['error'] ?? ''));
        }
        Router::redirect('/accounts/' . $account['id'] . '/files?path=' . urlencode($parentPath));
    }

    // ----------------------------------------------------------------
    // Rename
    // ----------------------------------------------------------------

    public function rename(array $params = []): void
    {
        $account = $this->getAccount($params);
        if (!$account) return;

        if (!$this->requireWriteMode($account['id'])) return;

        $path = $this->sanitizePath($_POST['path'] ?? '');
        $newName = trim($_POST['new_name'] ?? '');
        $parentPath = dirname($path);

        if (empty($newName) || preg_match('/[\/\0]/', $newName)) {
            Flash::set('error', 'Nombre no valido.');
            Router::redirect('/accounts/' . $account['id'] . '/files?path=' . urlencode($parentPath));
            return;
        }

        $newPath = rtrim($parentPath, '/') . '/' . $newName;
        $result = $this->fileop('rename', $account, $path, ['newpath' => ltrim($newPath, '/')]);

        FileAuditService::log($account, 'rename', $path, [
            'old_name' => basename($path),
            'new_name' => $newName,
        ]);
        LogService::log('file.rename', $account['domain'], basename($path) . ' → ' . $newName);

        if (!$result['ok']) {
            Flash::set('error', 'Error: ' . ($result['error'] ?? ''));
        }
        Router::redirect('/accounts/' . $account['id'] . '/files?path=' . urlencode($parentPath));
    }

    // ----------------------------------------------------------------
    // Chmod
    // ----------------------------------------------------------------

    public function chmod(array $params = []): void
    {
        $account = $this->getAccount($params);
        if (!$account) return;

        if (!$this->requireWriteMode($account['id'])) return;

        $path = $this->sanitizePath($_POST['path'] ?? '');
        $perms = trim($_POST['perms'] ?? '');
        $parentPath = dirname($path);

        if (!preg_match('/^[0-7]{3}$/', $perms)) {
            Flash::set('error', 'Formato de permisos invalido (ej: 755).');
            Router::redirect('/accounts/' . $account['id'] . '/files?path=' . urlencode($parentPath));
            return;
        }

        $result = $this->fileop('chmod', $account, $path, ['perms' => $perms]);

        FileAuditService::log($account, 'chmod', $path, ['new_perms' => $perms]);
        LogService::log('file.chmod', $account['domain'], $path . ' → ' . $perms);

        if ($this->isAjax()) {
            $this->json($result);
            return;
        }

        if (!$result['ok']) {
            Flash::set('error', 'Error: ' . ($result['error'] ?? ''));
        }
        Router::redirect('/accounts/' . $account['id'] . '/files?path=' . urlencode($parentPath));
    }

    // ----------------------------------------------------------------
    // Upload
    // ----------------------------------------------------------------

    public function upload(array $params = []): void
    {
        $account = $this->getAccount($params);
        if (!$account) return;

        if (!$this->requireWriteMode($account['id'])) return;

        $uploadPath = $this->sanitizePath($_POST['upload_path'] ?? '/');

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Flash::set('error', 'Error en la subida del archivo.');
            Router::redirect('/accounts/' . $account['id'] . '/files?path=' . urlencode($uploadPath));
            return;
        }

        $fileName = basename($_FILES['file']['name']);
        $fileName = preg_replace('/[^\w\.\-]/', '_', $fileName);
        $targetPath = rtrim($uploadPath, '/') . '/' . $fileName;
        $fileSizeMb = round($_FILES['file']['size'] / 1048576, 1);

        // Check quota
        $available = (int)$account['disk_quota_mb'] - (int)$account['disk_used_mb'];
        if ((int)$account['disk_quota_mb'] > 0 && $fileSizeMb > $available) {
            Flash::set('error', "No hay espacio suficiente ({$fileSizeMb} MB necesarios, {$available} MB disponibles).");
            Router::redirect('/accounts/' . $account['id'] . '/files?path=' . urlencode($uploadPath));
            return;
        }

        $tmpDest = $account['home_dir'] . '/' . $targetPath;
        $tmpDir = dirname($tmpDest);
        $tmpFile = $_FILES['file']['tmp_name'];
        $user = escapeshellarg($account['username']);
        $escapedDest = escapeshellarg($tmpDest);
        $escapedDir = escapeshellarg($tmpDir);

        shell_exec("sudo -u {$user} mkdir -p {$escapedDir} 2>/dev/null");
        shell_exec("cp " . escapeshellarg($tmpFile) . " {$escapedDest} 2>/dev/null");
        shell_exec("chown {$user}:www-data {$escapedDest} 2>/dev/null");
        shell_exec("chmod 644 {$escapedDest} 2>/dev/null");

        FileAuditService::log($account, 'upload', $targetPath, [
            'size' => $_FILES['file']['size'],
            'original_name' => $_FILES['file']['name'],
        ]);
        LogService::log('file.upload', $account['domain'], $targetPath . " ({$fileSizeMb} MB)");

        Flash::set('success', "Archivo '{$fileName}' subido ({$fileSizeMb} MB).");
        Router::redirect('/accounts/' . $account['id'] . '/files?path=' . urlencode($uploadPath));
    }

    // ----------------------------------------------------------------
    // Download
    // ----------------------------------------------------------------

    public function download(array $params = []): void
    {
        $account = $this->getAccount($params);
        if (!$account) return;

        $path = $this->sanitizePath($_GET['path'] ?? '');
        $fullPath = $account['home_dir'] . '/' . $path;

        $resolved = realpath($fullPath);
        $resolvedBase = realpath($account['home_dir']);
        if (!$resolved || !$resolvedBase || !str_starts_with($resolved, $resolvedBase)) {
            http_response_code(403);
            echo 'Access denied';
            return;
        }

        if (!is_file($resolved)) {
            http_response_code(404);
            echo 'File not found';
            return;
        }

        FileAuditService::log($account, 'download', $path, [
            'size' => filesize($resolved),
        ]);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($resolved) . '"');
        header('Content-Length: ' . filesize($resolved));
        readfile($resolved);
        exit;
    }

    // ----------------------------------------------------------------
    // Write mode activation
    // ----------------------------------------------------------------

    public function activateWriteMode(array $params = []): void
    {
        $account = $this->getAccount($params);
        if (!$account) return;

        $reason = $_POST['reason'] ?? 'contract_execution';
        $validReasons = ['contract_execution', 'support_request', 'security_incident', 'maintenance'];
        if (!in_array($reason, $validReasons)) $reason = 'contract_execution';

        $description = trim($_POST['description'] ?? '');

        $_SESSION['fm_write_mode'] = [
            'account_id' => (int)$account['id'],
            'expires_at' => time() + 1800, // 30 minutes
            'legal_basis' => $reason,
        ];
        $_SESSION['fm_legal_basis'] = $reason;

        FileAuditService::log($account, 'write_mode_activate', '/', [
            'reason' => $reason,
            'description' => $description,
            'expires_at' => date('Y-m-d H:i:s', time() + 1800),
        ]);
        LogService::log('file.write_mode', $account['domain'], "Reason: {$reason}");

        if ($this->isAjax()) {
            $this->json(['ok' => true, 'expires_at' => time() + 1800]);
            return;
        }

        Flash::set('success', 'Modo edicion activado por 30 minutos.');
        Router::redirect('/accounts/' . $account['id'] . '/files');
    }

    // ----------------------------------------------------------------
    // Audit log views
    // ----------------------------------------------------------------

    public function auditLog(array $params = []): void
    {
        $account = $this->getAccount($params);
        if (!$account) return;

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 50;
        $filters = [
            'action' => $_GET['action'] ?? '',
            'admin_id' => $_GET['admin_id'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $filters = array_filter($filters);

        $total = FileAuditService::countForAccount((int)$account['id'], $filters);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $logs = FileAuditService::forAccount((int)$account['id'], $perPage, ($page - 1) * $perPage, $filters);

        // Get unique admins for filter dropdown
        $admins = Database::fetchAll(
            "SELECT DISTINCT admin_id, admin_username FROM file_audit_logs WHERE account_id = :id ORDER BY admin_username",
            ['id' => (int)$account['id']]
        );

        View::render('files/audit-log', [
            'layout' => 'main',
            'pageTitle' => $account['domain'] . ' — Audit Log',
            'account' => $account,
            'logs' => $logs,
            'admins' => $admins,
            'filters' => $filters + ['action' => '', 'admin_id' => '', 'date_from' => '', 'date_to' => ''],
            'pagination' => ['total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage],
        ]);
    }

    public function auditLogExport(array $params = []): void
    {
        $account = $this->getAccount($params);
        if (!$account) return;

        $csv = FileAuditService::exportCsv((int)$account['id']);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-log-account-' . $account['id'] . '-' . date('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
        echo $csv;
        exit;
    }

    public function globalAuditLog(): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 50;
        $filters = [
            'account_id' => $_GET['account_id'] ?? '',
            'action' => $_GET['action'] ?? '',
            'admin_id' => $_GET['admin_id'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $filters = array_filter($filters);

        $total = FileAuditService::countAll($filters);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $logs = FileAuditService::all($perPage, ($page - 1) * $perPage, $filters);

        // Get unique accounts and admins for filters
        $accounts = Database::fetchAll("SELECT DISTINCT account_id, account_domain FROM file_audit_logs ORDER BY account_domain");
        $admins = Database::fetchAll("SELECT DISTINCT admin_id, admin_username FROM file_audit_logs ORDER BY admin_username");

        View::render('files/audit-log-global', [
            'layout' => 'main',
            'pageTitle' => 'File Audit Log — Global',
            'logs' => $logs,
            'accounts' => $accounts,
            'admins' => $admins,
            'filters' => $filters + ['account_id' => '', 'action' => '', 'admin_id' => '', 'date_from' => '', 'date_to' => ''],
            'pagination' => ['total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage],
        ]);
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function getAccount(array $params): ?array
    {
        $id = (int)($params['id'] ?? 0);
        $account = Database::fetchOne("SELECT * FROM hosting_accounts WHERE id = :id", ['id' => $id]);
        if (!$account) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/accounts');
            return null;
        }
        return $account;
    }

    private function sanitizePath(string $path): string
    {
        $path = str_replace("\0", '', $path);
        $path = str_replace('\\', '/', $path);
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..' || $part === '' || $part === '.') continue;
            $parts[] = $part;
        }
        return '/' . implode('/', $parts);
    }

    private function isEditable(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $basename = basename($path);
        if (in_array($basename, ['.env', '.htaccess', '.gitignore'])) return true;
        return in_array($ext, self::EDITABLE_EXTENSIONS, true);
    }

    private function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    private function parentPath(string $path): string
    {
        $parent = dirname($path);
        return $parent === '.' ? '/' : $parent;
    }

    private function fileop(string $op, array $account, string $path, array $extra = []): array
    {
        $input = array_merge([
            'op' => $op,
            'user' => $account['username'],
            'base' => $account['home_dir'],
            'path' => ltrim($path, '/'),
        ], $extra);

        $json = json_encode($input);
        $escaped = escapeshellarg($json);
        $output = shell_exec("echo {$escaped} | " . self::FILEOP_BIN . " 2>&1");

        $result = json_decode($output ?? '', true);
        return is_array($result) ? $result : ['ok' => false, 'error' => 'fileop failed: ' . substr($output ?? '', 0, 200)];
    }

    /**
     * Check if write mode is active for the current account.
     */
    private function isWriteMode(): bool
    {
        $wm = $_SESSION['fm_write_mode'] ?? null;
        if (!$wm) return false;
        if ($wm['expires_at'] < time()) {
            unset($_SESSION['fm_write_mode'], $_SESSION['fm_legal_basis']);
            return false;
        }
        return true;
    }

    /**
     * Check write mode is active for the given account. Redirect if not.
     */
    private function requireWriteMode(int $accountId): bool
    {
        if (!$this->isWriteMode()) {
            Flash::set('error', 'Debes activar el modo edicion antes de modificar archivos.');
            Router::redirect('/accounts/' . $accountId . '/files');
            return false;
        }
        $wm = $_SESSION['fm_write_mode'];
        if ((int)$wm['account_id'] !== $accountId) {
            Flash::set('error', 'El modo edicion esta activo para otra cuenta.');
            Router::redirect('/accounts/' . $accountId . '/files');
            return false;
        }
        return true;
    }

    // Static helpers for views

    public static function fileIcon(string $ext, string $name): string
    {
        return match (true) {
            $ext === 'php' => 'bi-filetype-php',
            in_array($ext, ['html', 'htm']) => 'bi-filetype-html',
            $ext === 'css' => 'bi-filetype-css',
            $ext === 'js' => 'bi-filetype-js',
            $ext === 'json' => 'bi-filetype-json',
            in_array($ext, ['xml', 'svg']) => 'bi-filetype-xml',
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico']) => 'bi-file-image',
            $ext === 'pdf' => 'bi-file-pdf',
            in_array($ext, ['zip', 'tar', 'gz', 'rar']) => 'bi-file-zip',
            $ext === 'sql' => 'bi-database',
            in_array($ext, ['md', 'txt']) => 'bi-file-text',
            in_array($ext, ['sh', 'bash']) => 'bi-terminal',
            $ext === 'env' || $name === '.env' => 'bi-key',
            default => 'bi-file-earmark',
        };
    }

    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}
