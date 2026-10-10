<?php
namespace MuseDockPanel\DirectMcp;

/** A distinct host and Caddy-generated credentials prevent bypass via :8444. */
final class Gateway
{
    public const PUBLIC_PATHS = ['/.well-known/oauth-authorization-server','/.well-known/oauth-protected-resource','/.well-known/oauth-protected-resource/mcp','/oauth/authorize','/oauth/token','/oauth/revoke'];
    public static function path(string $path): bool { return $path==='/mcp' || in_array($path,self::PUBLIC_PATHS,true); }
    public static function host(array $server): bool
    {
        if(!is_file(PANEL_ROOT.'/storage/direct-mcp/registry.sqlite'))return false;
        try { $r=new Registry(); return $r->issuer()!=='' && ($server['HTTP_HOST']??'')===parse_url($r->issuer(),PHP_URL_HOST); } catch(\Throwable){return false;}
    }
    public static function exempt(string $path,array $server): bool
    {
        if(!self::path($path))return false;
        try{return (new Registry())->ingress($server);}catch(\Throwable){return false;}
    }
}
