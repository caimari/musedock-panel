<?php
namespace MuseDockPanel\ChatGpt;

final class Integration
{
    public static function localMcp(): bool
    {
        return IngressPolicy::localTunnel('/api/mcp/chatgpt', $_SERVER);
    }
    public static function server(): OAuthServer { return new OAuthServer(new OAuthStore()); }
    public static function state(): array
    {
        $base = ['enabled' => false, 'issuer' => '', 'panel_url' => '', 'resource' => '', 'redirect_uri' => '',
            'client_id' => '', 'tunnel_id' => '', 'has_key' => false,
            'installed' => is_file('/etc/systemd/system/musedock-chatgpt-tunnel.service'), 'ready' => false,
            'last_used' => '', 'last_check' => '', 'last_error' => ''];
        try {
            $s = new OAuthStore();
            foreach (['issuer','panel_url','resource','redirect_uri','client_id','tunnel_id','last_used','last_check','last_error'] as $key) $base[$key] = $s->get($key);
            $base['enabled'] = $s->get('enabled') === '1';
            $base['has_key'] = is_file(PANEL_ROOT . '/storage/chatgpt/openai-key');
            $base['ready'] = $base['enabled'] && self::probe('readyz');
        } catch (\Throwable) { $base['last_error'] = 'El almacenamiento OAuth no está disponible. Comprueba PDO SQLite y los permisos del directorio privado.'; }
        return $base;
    }

    public static function probe(string $path): bool
    {
        $c = curl_init('http://127.0.0.1:18446/' . $path);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 2,
            CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($c); $ok = curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200; curl_close($c); return $ok;
    }
    public static function service(string $action): bool
    {
        if (!in_array($action, ['start','stop','restart','enable','disable'], true)) return false;
        exec('/usr/bin/systemctl ' . $action . ' musedock-chatgpt-tunnel.service >/dev/null 2>&1', $output, $exit);
        return $exit === 0;
    }
    public static function profile(OAuthStore $s): string
    {
        $p = (int)\MuseDockPanel\Env::get('PANEL_INTERNAL_PORT', '8445');
        // JSON strings are also valid YAML scalars; no shell interpolation.
        return "config_version: 1\ncontrol_plane:\n  base_url: https://api.openai.com\n  tunnel_id: " . json_encode($s->get('tunnel_id'), JSON_UNESCAPED_SLASHES)
            . "\n  api_key: file:" . PANEL_ROOT . "/storage/chatgpt/openai-key\nhealth:\n  listen_addr: 127.0.0.1:18446\nadmin_ui:\n  open_browser: false\n"
            . "mcp:\n  server_urls:\n    - channel: main\n      url: http://127.0.0.1:{$p}/api/mcp/chatgpt\n  oauth_trusted_origins:\n    - "
            . json_encode($s->get('issuer'), JSON_UNESCAPED_SLASHES) . "\nlog:\n  level: warn\n  format: json\n";
    }
    public static function secretFile(string $name, string $content): void
    {
        $dir = PANEL_ROOT . '/storage/chatgpt';
        if (!in_array($name, ['openai-key','profile.yaml'], true)) throw new \InvalidArgumentException('Nombre no autorizado');
        $tmp = tempnam($dir, '.pending-');
        if (!$tmp || !chmod($tmp, 0600) || file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $dir . '/' . $name))
            throw new \RuntimeException('No se pudo guardar el archivo protegido.');
        // Only the dedicated tunnel group receives access; OAuth database remains private.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && posix_getgrnam('musedock-tunnel')) {
            chgrp($dir, 'musedock-tunnel'); chmod($dir, 0710);
            chgrp($dir . '/' . $name, 'musedock-tunnel'); chmod($dir . '/' . $name, 0640);
        }
    }
}
