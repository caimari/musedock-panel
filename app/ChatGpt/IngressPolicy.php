<?php
namespace MuseDockPanel\ChatGpt;

/** Exact paths only. Neither panel login nor legacy MCP is exempted. */
final class IngressPolicy
{
    public static function publicPath(string $path): bool
    {
        return in_array($path, ['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/api/mcp/chatgpt',
            '/.well-known/oauth-authorization-server', '/api/chatgpt/oauth/authorize', '/api/chatgpt/oauth/token', '/api/chatgpt/oauth/revoke'], true);
    }
    public static function localTunnel(string $path, array $server): bool
    {
        return $path === '/api/mcp/chatgpt' && in_array($server['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
            && empty($server['HTTP_X_FORWARDED_FOR']) && empty($server['HTTP_X_REAL_IP']);
    }
    public static function exempt(string $path, array $server): bool { return self::publicPath($path) || self::localTunnel($path, $server) || \MuseDockPanel\DirectMcp\Gateway::exempt($path, $server); }
}
