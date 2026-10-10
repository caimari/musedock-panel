<?php
namespace MuseDockPanel\ChatGpt;
use MuseDockPanel\Mcp\McpTools;
use MuseDockPanel\Mcp\McpServer;

final class ReadOnlyMcp
{
    // Explicit allowlist: future tools never inherit ChatGPT access automatically.
    public const TOOLS = ['panel_info', 'list_nodes', 'node_status', 'services_status', 'failover_status',
        'hosting_accounts', 'mail_domains', 'mail_domain', 'caddy_hosts', 'caddy_domains', 'failover_preflight'];
    public static function allowed(string $name): bool
    {
        return in_array($name, self::TOOLS, true) && McpTools::exists($name) && !McpTools::isWrite($name);
    }
    public static function handle(mixed $msg, string $via = 'chatgpt-oauth'): ?array
    {
        if (!is_array($msg)) return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid request']];
        if (($msg['jsonrpc'] ?? '') !== '2.0' || !is_string($msg['method'] ?? null)) {
            return ['jsonrpc' => '2.0', 'id' => $msg['id'] ?? null, 'error' => ['code' => -32600, 'message' => 'Invalid request']];
        }
        if (!array_key_exists('id', $msg) || str_starts_with($msg['method'], 'notifications/')) return null;
        $method = $msg['method'];
        if ($method === 'tools/list') {
            $tools = array_values(array_filter(McpTools::listForMcp(), static fn($t) => self::allowed($t['name'])));
            foreach ($tools as &$tool) $tool['securitySchemes'] = [['type' => 'oauth2', 'scopes' => [OAuthServer::SCOPE]]];
            return ['jsonrpc' => '2.0', 'id' => $msg['id'] ?? null, 'result' => ['tools' => $tools]];
        }
        if ($method === 'tools/call' && !self::allowed((string)($msg['params']['name'] ?? ''))) {
            return ['jsonrpc' => '2.0', 'id' => $msg['id'] ?? null, 'result' => ['isError' => true,
                'content' => [['type' => 'text', 'text' => 'Este cliente MCP solo puede consultar servidores. Herramienta no autorizada.']]]];
        }
        $r = McpServer::handle($msg, $via);
        if ($method === 'initialize' && isset($r['result'])) $r['result']['instructions'] =
            'Acceso MuseDock de solo lectura. Consulta este servidor y los nodos autorizados con list_nodes y node. No puedes modificar configuraciones ni crear contenido.';
        return $r;
    }
}
