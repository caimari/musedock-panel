<?php
namespace MuseDockPanel\Controllers;
use MuseDockPanel\ChatGpt\{Integration, OAuthServer, OAuthStore, ReadOnlyMcp};
use MuseDockPanel\{Auth, Database, Flash, Settings, View};
use MuseDockPanel\Security\ClientIp;
use MuseDockPanel\Services\LogService;

final class ChatGptController
{
    private function json(int $status, array $body): never
    {
        http_response_code($status); header('Content-Type: application/json'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store'); header('Pragma: no-cache');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
    }
    public function publicEndpoint(): never
    {
        try {
            $server = Integration::server();
            if (!$server->enabled() || Settings::get('mcp_enabled', '0') !== '1') $this->json(404, ['error' => 'Not found']);
            $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?'); $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if (!$server->store->rateLimit(ClientIp::resolve(), 'chatgpt-oauth', 60)) $this->json(429, ['error' => 'temporarily_unavailable']);
            if ($method === 'GET' && str_starts_with($path, '/.well-known/oauth-protected-resource')) $this->json(200, $server->protectedResource());
            if ($method === 'GET' && $path === '/.well-known/oauth-authorization-server') $this->json(200, $server->metadata());
            if ($method === 'GET' && $path === '/api/chatgpt/oauth/authorize') {
                $id = $server->request($_GET);
                header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store');
                header('Location: ' . $server->store->get('panel_url') . '/settings/mcp/chatgpt/approve?request=' . $id, true, 302); exit;
            }
            if ($method !== 'POST') { header('Allow: POST'); $this->json(405, ['error' => 'invalid_request']); }
            if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded')
                || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) $this->json(400, ['error' => 'invalid_request']);
            $basic = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            $credentials = str_starts_with($basic, 'Basic ') ? base64_decode(substr($basic, 6), true) : false;
            [$id, $secret] = $credentials !== false ? array_pad(explode(':', $credentials, 2), 2, '') : ['', ''];
            if (!$server->authenticateClient(urldecode($id), urldecode($secret))) {
                @file_put_contents('/var/log/musedock-panel-auth.log', date('Y-m-d H:i:s') . ' FAIL login from ' . ClientIp::resolve() . " user mcp\n", FILE_APPEND | LOCK_EX);
                header('WWW-Authenticate: Basic realm="musedock-oauth"'); $this->json(401, ['error' => 'invalid_client']);
            }
            if ($path === '/api/chatgpt/oauth/revoke') {
                $value = (string)($_POST['token'] ?? '');
                $grant = $server->store->find($value, 'access') ?? $server->store->find($value, 'refresh') ?? $server->store->usedRefresh($value);
                if ($grant && ($grant['client_id'] ?? '') === $id) $server->store->revokeFamily($grant['family']);
                $this->json(200, []);
            }
            if ($path !== '/api/chatgpt/oauth/token') $this->json(404, ['error' => 'Not found']);
            $codeGrant = $server->store->find((string)($_POST['code'] ?? $_POST['refresh_token'] ?? ''), ($_POST['grant_type'] ?? '') === 'refresh_token' ? 'refresh' : 'code');
            if (($_POST['grant_type'] ?? '') === 'refresh_token' && ($replayed = $server->store->usedRefresh((string)($_POST['refresh_token'] ?? '')))) {
                $server->store->revokeFamily($replayed['family']); $this->json(400, ['error' => 'invalid_grant']);
            }
            if (!$codeGrant || !Database::fetchOne("SELECT id FROM panel_admins WHERE id=:id AND is_active=true AND role IN ('admin','superadmin')", ['id' => $codeGrant['user_id']])) $this->json(400, ['error' => 'invalid_grant']);
            $this->json(200, $server->exchange($_POST));
        } catch (\RuntimeException $e) {
            $error = in_array($e->getMessage(), ['invalid_request','invalid_scope','invalid_grant','access_denied','unsupported_grant_type'], true)
                ? $e->getMessage() : 'server_error';
            $this->json($error === 'server_error' ? 503 : 400, ['error' => $error]);
        } catch (\Throwable) { $this->json(503, ['error' => 'server_error']); }
    }
    public function mcp(): never
    {
        try {
            if (!Integration::localMcp()) $this->json(404, ['error' => 'Not found']);
            $server = Integration::server();
            if (!$server->enabled() || Settings::get('mcp_enabled', '0') !== '1') $this->json(404, ['error' => 'Not found']);
            if (!$server->store->rateLimit('chatgpt-tunnel', 'mcp-chatgpt', 240)) $this->json(429, ['error' => 'Too many requests']);
            if (!empty($_SERVER['HTTP_ORIGIN'])) $this->json(403, ['error' => 'Origin not allowed']);
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            $token = preg_match('/^Bearer\s+(\S+)$/i', $auth, $match) ? $match[1] : '';
            $grant = $server->verify($token);
            if (!$grant || !Database::fetchOne("SELECT id FROM panel_admins WHERE id=:id AND is_active=true AND role IN ('admin','superadmin')", ['id' => $grant['user_id']])) {
                // Discovery points to the public OAuth origin, explicitly trusted by the client.
                header('WWW-Authenticate: Bearer resource_metadata="' . $server->issuer() . '/.well-known/oauth-protected-resource/api/mcp/chatgpt", scope="musedock:read"');
                $this->json(401, ['error' => 'Unauthorized']);
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); $this->json(405, ['error' => 'Method not allowed']); }
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) $this->json(413, ['error' => 'Request too large']);
            $raw = file_get_contents('php://input', false, null, 0, 1048577);
            if (strlen($raw) > 1048576) $this->json(413, ['error' => 'Request too large']);
            $msg = json_decode($raw, true);
            if (!is_array($msg) || array_is_list($msg)) $this->json(400, ['error' => 'Invalid JSON-RPC request']);
            $response = ReadOnlyMcp::handle($msg);
            $server->store->set('last_used', gmdate('c'));
            if ($response === null) { http_response_code(202); exit; }
            $this->json(200, $response);
        } catch (\Throwable) { $this->json(503, ['error' => 'Service unavailable']); }
    }
    private function admin(): void
    {
        if (!Auth::check() || !in_array(Auth::user()['role'] ?? '', ['admin', 'superadmin'], true)
            || !Database::fetchOne("SELECT id FROM panel_admins WHERE id=:id AND is_active=true AND role IN ('admin','superadmin')", ['id' => Auth::user()['id']])) { http_response_code(403); exit; }
    }
    public function approve(): void
    {
        $this->admin(); $s = Integration::server(); $id = (string)($_GET['request'] ?? $_POST['request'] ?? '');
        if (!$s->enabled() || !$s->store->find($id, 'request')) { http_response_code(400); echo 'Solicitud OAuth caducada o no válida.'; return; }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            if (!View::verifyCsrf()) { http_response_code(403); exit; }
            $url = $s->approve($id, (int)Auth::user()['id'], ($_POST['decision'] ?? '') === 'approve');
            LogService::log('chatgpt.oauth', 'consent', 'Autorización ChatGPT de solo lectura revisada por el administrador');
            header('Referrer-Policy: no-referrer'); header('Location: ' . $url, true, 302); exit;
        }
        View::render('settings/chatgpt-consent', ['layout' => 'main', 'pageTitle' => 'Autorizar ChatGPT', 'requestId' => $id]);
    }
    public function save(): void
    {
        $this->admin(); if (!View::verifyCsrf()) { http_response_code(403); exit; } $s = new OAuthStore();
        try {
            $issuer = rtrim(trim((string)($_POST['issuer'] ?? '')), '/');
            $panel = rtrim(trim((string)($_POST['panel_url'] ?? '')), '/');
            $resource = trim((string)($_POST['resource'] ?? '')); $redirect = trim((string)($_POST['redirect_uri'] ?? ''));
            $tunnel = trim((string)($_POST['tunnel_id'] ?? ''));
            if (!OAuthServer::httpsUrl($issuer, true) || !OAuthServer::httpsUrl($panel, true) || !OAuthServer::httpsUrl($resource)
                || !OAuthServer::validRedirect($redirect) || !preg_match('/^tunnel_[A-Za-z0-9_-]{16,128}$/D', $tunnel))
                throw new \RuntimeException('Revisa las URL HTTPS, la devolución de llamada de ChatGPT y el tunnel_id.');
            if ($s->get('enabled') === '1') throw new \RuntimeException('Desconecta ChatGPT antes de cambiar su configuración.');
            $runtimeKey = trim((string)($_POST['openai_key'] ?? ''));
            if ($runtimeKey !== '' && (strlen($runtimeKey) > 512 || !preg_match('/^sk-[A-Za-z0-9_-]+$/D', $runtimeKey))) throw new \RuntimeException('Clave de ejecución OpenAI no válida.');
            foreach (['issuer' => $issuer, 'panel_url' => $panel, 'resource' => $resource, 'redirect_uri' => $redirect, 'tunnel_id' => $tunnel] as $key => $value) $s->set($key, $value);
            if ($s->get('client_id') === '' || !empty($_POST['rotate_client'])) {
                $secret = bin2hex(random_bytes(32)); $s->set('client_id', 'mdgpt_' . bin2hex(random_bytes(16)));
                $s->set('client_secret_hash', hash('sha256', $secret)); $_SESSION['chatgpt_new_secret'] = $secret;
            }
            $s->revokeAll();
            if ($runtimeKey !== '') Integration::secretFile('openai-key', $runtimeKey);
            Integration::secretFile('profile.yaml', Integration::profile($s));
            $s->set('last_error', ''); $s->set('last_used', '');
            LogService::log('chatgpt.settings', 'saved', 'Configuración local ChatGPT guardada; solo lectura');
            Flash::set('success', 'Configuración guardada. Copia las credenciales OAuth en ChatGPT; el acceso sigue desconectado.');
        } catch (\Throwable $e) {
            $safe = ['Revisa las URL HTTPS, la devolución de llamada de ChatGPT y el tunnel_id.',
                'Desconecta ChatGPT antes de cambiar su configuración.', 'Clave de ejecución OpenAI no válida.',
                'No se pudo guardar el archivo protegido.'];
            Flash::set('error', in_array($e->getMessage(), $safe, true) ? $e->getMessage() : 'No se pudo guardar la configuración.');
        }
        header('Location: /settings/mcp'); exit;
    }
    public function action(): void
    {
        $this->admin(); if (!View::verifyCsrf()) { http_response_code(403); exit; } $s = new OAuthStore(); $action = (string)($_POST['action'] ?? '');
        if ($action === 'disconnect') {
            $s->set('enabled', '0'); $s->revokeAll(); Integration::service('disable'); Integration::service('stop');
            LogService::log('chatgpt.settings', 'disconnected', 'Acceso ChatGPT desactivado; concesiones OAuth revocadas');
            Flash::set('success', 'ChatGPT desconectado. Sus tokens ya no dan acceso.');
        } elseif ($action === 'connect') {
            if (Settings::get('mcp_enabled', '0') !== '1' || !$s->get('client_id') || !$s->get('client_secret_hash')
                || !is_file(PANEL_ROOT . '/storage/chatgpt/openai-key') || !is_file('/etc/systemd/system/musedock-chatgpt-tunnel.service')) {
                Flash::set('error', 'Activa el MCP, guarda la configuración e instala el servicio del túnel antes de conectar.');
            } else {
                $s->set('enabled', '1');
                if (!Integration::service('enable') || !Integration::service('start')) { $s->set('enabled', '0'); Integration::service('disable'); $s->set('last_error', 'No se pudo iniciar el servicio del túnel.'); Flash::set('error', $s->get('last_error')); }
                else Flash::set('success', 'Acceso de solo lectura habilitado. Comprueba el túnel y crea el conector en ChatGPT.');
            }
        } elseif ($action === 'test') {
            $ok = Integration::probe('readyz'); $s->set('last_check', gmdate('c'));
            $s->set('last_error', $ok ? '' : 'El cliente del túnel no está listo. Revisa su servicio y los permisos en Platform.');
            Flash::set($ok ? 'success' : 'error', $ok ? 'Túnel listo. La prueba con tu cuenta se realiza desde ChatGPT.' : $s->get('last_error'));
        }
        header('Location: /settings/mcp'); exit;
    }
}
