<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Flash;
use MuseDockPanel\Mcp\McpServer;
use MuseDockPanel\RateLimiter;
use MuseDockPanel\Security\ClientIp;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Settings;
use MuseDockPanel\View;

/**
 * Servidor MCP de MuseDock Panel: endpoint HTTP /api/mcp + página de ajustes.
 *
 * Seguridad (por defecto APAGADO):
 *  - mcp_enabled != '1'  → 404, el endpoint "no existe".
 *  - Token Bearer obligatorio. Se guarda solo su SHA-256 (mcp_token_hash) y se
 *    compara con hash_equals. El token en claro se muestra UNA vez al generarlo.
 *  - Fallo de token → línea "FAIL login from <ip> user mcp" en
 *    /var/log/musedock-panel-auth.log: el jail fail2ban [musedock-panel] ya lo
 *    vigila (5 fallos → ban 1 h).
 *  - Rate limit por IP y rechazo de peticiones con cabecera Origin (navegador):
 *    protección contra DNS rebinding exigida por la especificación MCP.
 *  - Sin streaming (SSE): el panel corre en php -S monohilo; se responde JSON.
 *  - ALLOWED_IPS del .env se aplica antes de llegar aquí (public/index.php).
 */
class McpController
{
    // ── Endpoint /api/mcp ─────────────────────────────────────────────────

    public function handle(): void
    {
        if (Settings::get('mcp_enabled', '0') !== '1') {
            $this->json(404, ['error' => 'Not found']);
        }

        $ip = ClientIp::resolve();
        if (!RateLimiter::check($ip, 'mcp', 240)) {
            $this->json(429, ['error' => 'Too many requests']);
        }

        // Peticiones desde navegador (con Origin) no son clientes MCP legítimos.
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $this->json(403, ['error' => 'Origin not allowed']);
        }

        // Autenticación Bearer contra el hash guardado.
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $token = preg_match('/^Bearer\s+(\S+)$/i', trim($hdr), $m) ? $m[1] : '';
        $stored = Settings::get('mcp_token_hash', '');
        if ($token === '' || $stored === '' || !hash_equals($stored, hash('sha256', $token))) {
            @file_put_contents('/var/log/musedock-panel-auth.log',
                date('Y-m-d H:i:s') . " FAIL login from {$ip} user mcp\n", FILE_APPEND | LOCK_EX);
            header('WWW-Authenticate: Bearer realm="musedock-mcp"');
            $this->json(401, ['error' => 'Unauthorized']);
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            // Sin SSE: los clientes MCP aceptan 405 en GET (transporte Streamable HTTP).
            header('Allow: POST');
            $this->json(405, ['error' => 'Method not allowed']);
        }

        $raw = (string)file_get_contents('php://input', false, null, 0, 1048576);
        $msg = json_decode($raw, true);
        if (!is_array($msg)) {
            $this->json(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
        }

        Settings::set('mcp_last_used_at', gmdate('Y-m-d H:i:s') . " UTC ({$ip})");

        // Lote (clientes antiguos) o mensaje único.
        if (array_is_list($msg)) {
            $out = [];
            foreach (array_slice($msg, 0, 20) as $one) {
                $r = McpServer::handle($one, 'http');
                if ($r !== null) {
                    $out[] = $r;
                }
            }
            if (!$out) {
                http_response_code(202);
                exit;
            }
            $this->json(200, $out);
        }

        $resp = McpServer::handle($msg, 'http');
        if ($resp === null) {
            http_response_code(202); // notificación: aceptada, sin cuerpo
            exit;
        }
        $this->json(200, $resp);
    }

    private function json(int $code, array $data): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // ── Ajustes: /settings/mcp ────────────────────────────────────────────

    public function settings(): void
    {
        $newToken = $_SESSION['mcp_new_token'] ?? null;
        unset($_SESSION['mcp_new_token']);

        $port = (int)\MuseDockPanel\Env::get('PANEL_PORT', 8444);
        $host = Settings::get('panel_hostname', '') ?: (gethostname() ?: 'tu-servidor');

        View::render('settings/mcp', [
            'layout' => 'main',
            'pageTitle' => 'MCP',
            'enabled' => Settings::get('mcp_enabled', '0') === '1',
            'allowWrite' => Settings::get('mcp_allow_write', '0') === '1',
            'pendingCredentials' => \MuseDockPanel\Mcp\McpMailTools::pendingCredentials(),
            'hasToken' => Settings::get('mcp_token_hash', '') !== '',
            'tokenCreatedAt' => Settings::get('mcp_token_created_at', ''),
            'tokenHint' => Settings::get('mcp_token_hint', ''),
            'lastUsedAt' => Settings::get('mcp_last_used_at', ''),
            'newToken' => $newToken,
            'endpoint' => "https://{$host}:{$port}/api/mcp",
            'sshHost' => $host,
            'serverKey' => preg_replace('/[^a-z0-9]+/', '-', strtolower(explode('.', $host)[0])),
        ]);
    }

    public function save(): void
    {
        View::verifyCsrf();
        $enable = !empty($_POST['mcp_enabled']);
        if ($enable && Settings::get('mcp_token_hash', '') === '') {
            Flash::set('error', 'Genera primero un token: el MCP no se puede activar sin autenticación.');
            header('Location: /settings/mcp');
            exit;
        }
        $write = $enable && !empty($_POST['mcp_allow_write']);
        Settings::set('mcp_enabled', $enable ? '1' : '0');
        Settings::set('mcp_allow_write', $write ? '1' : '0');
        LogService::log('mcp.settings', $enable ? 'enabled' : 'disabled',
            'Servidor MCP ' . ($enable ? 'activado' : 'desactivado') . '; acciones que modifican: ' . ($write ? 'PERMITIDAS' : 'no'));
        Flash::set('success', 'Servidor MCP ' . ($enable ? 'activado' : 'desactivado')
            . ($enable ? ($write ? ' con acciones que modifican permitidas.' : ' en solo lectura.') : '.'));
        header('Location: /settings/mcp');
        exit;
    }

    public function clearCredentials(): void
    {
        View::verifyCsrf();
        Settings::set('mcp_pending_credentials', '[]');
        LogService::log('mcp.credentials', 'cleared', 'Credenciales pendientes del MCP borradas');
        Flash::set('success', 'Credenciales pendientes borradas.');
        header('Location: /settings/mcp');
        exit;
    }

    public function token(): void
    {
        View::verifyCsrf();
        $action = $_POST['token_action'] ?? 'generate';
        if ($action === 'revoke') {
            Settings::set('mcp_token_hash', '');
            Settings::set('mcp_token_hint', '');
            Settings::set('mcp_enabled', '0');
            Settings::set('mcp_allow_write', '0');
            LogService::log('mcp.token', 'revoked', 'Token MCP revocado y servidor MCP desactivado');
            Flash::set('success', 'Token revocado. El servidor MCP queda desactivado.');
        } else {
            $token = 'mdmcp_' . bin2hex(random_bytes(32));
            Settings::set('mcp_token_hash', hash('sha256', $token));
            Settings::set('mcp_token_hint', '…' . substr($token, -4));
            Settings::set('mcp_token_created_at', gmdate('Y-m-d H:i') . ' UTC');
            $_SESSION['mcp_new_token'] = $token;
            LogService::log('mcp.token', 'generated', 'Nuevo token MCP generado (el anterior deja de valer)');
            Flash::set('success', 'Token generado. Cópialo ahora: no se volverá a mostrar.');
        }
        header('Location: /settings/mcp');
        exit;
    }
}
