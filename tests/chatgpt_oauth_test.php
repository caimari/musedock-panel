<?php
/** Isolated security tests; never loads production .env or database. */
namespace MuseDockPanel { final class Settings { public static function get(string $k, string $d=''): string { return $k === 'mcp_allow_write' ? '1' : $d; } } }
namespace {
    define('PANEL_ROOT', dirname(__DIR__));
    spl_autoload_register(function($c) { $prefix='MuseDockPanel\\'; if (str_starts_with($c,$prefix)) { $p=PANEL_ROOT.'/app/'.str_replace('\\','/',substr($c,strlen($prefix))).'.php'; if (is_file($p)) require $p; } });
    use MuseDockPanel\ChatGpt\{OAuthStore, OAuthServer, ReadOnlyMcp, IngressPolicy};
    $dir=sys_get_temp_dir().'/musedock-oauth-test-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
    $store=new OAuthStore($dir.'/oauth.sqlite'); $s=new OAuthServer($store); $count=0;
    function check(bool $ok,string $label): void { global $count; if (!$ok) throw new \RuntimeException('FAIL: '.$label); $count++; }
    function fails(callable $f,string $label): void { try { $f(); } catch (\RuntimeException $e) { check(true,$label); return; } check(false,$label); }
    try {
        foreach (['enabled'=>'1','client_id'=>'test-client','client_secret_hash'=>hash('sha256','test-secret'), 'issuer'=>'https://oauth.example.com',
            'resource'=>'https://mcp.example.com/api/mcp/chatgpt','redirect_uri'=>'https://chatgpt.com/connector_platform_oauth_redirect'] as $k=>$v) $store->set($k,$v);
        $verifier=str_repeat('A',64); $challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
        $p=['client_id'=>'test-client','response_type'=>'code','redirect_uri'=>$store->get('redirect_uri'),'resource'=>$s->resource(),
            'state'=>'random-client-state','code_challenge'=>$challenge,'code_challenge_method'=>'S256','scope'=>'musedock:read'];
        check($s->authenticateClient('test-client','test-secret'),'client credentials');
        check($store->rateLimit('test-ip','test-action',2) && $store->rateLimit('test-ip','test-action',2) && !$store->rateLimit('test-ip','test-action',2),'OAuth rate limit fails closed');
        check(!$s->authenticateClient('test-client','bad'),'wrong secret');
        check(!OAuthServer::validRedirect('https://chatgpt.com.evil.test/connector_platform_oauth_redirect'),'redirect host confusion');
        check(!OAuthServer::httpsUrl('https://user:pass@example.com',true),'URL credentials rejected');
        check(!OAuthServer::httpsUrl('http://example.com',true),'TLS required');
        check(!OAuthServer::httpsUrl('https://example.com/path',true),'issuer must be origin');
        foreach (['code_challenge_method'=>'plain','redirect_uri'=>'https://evil.test','resource'=>'https://wrong.test','scope'=>'musedock:write','client_id'=>'bad'] as $k=>$v) fails(fn()=>$s->request(array_replace($p,[$k=>$v])),'reject '.$k);
        $id=$s->request($p); check($store->find($id,'request')!==null,'pending consent');
        $url=$s->approve($id,7,true); parse_str(parse_url($url,PHP_URL_QUERY),$q);
        check($q['state']===$p['state'] && $q['iss']===$s->issuer(),'state and issuer preserved');
        fails(fn()=>$s->approve($id,7,true),'consent single use');
        $exchange=['grant_type'=>'authorization_code','code'=>$q['code'],'redirect_uri'=>$p['redirect_uri'],'resource'=>$p['resource'],'code_verifier'=>$verifier];
        fails(fn()=>$s->exchange(array_replace($exchange,['code_verifier'=>str_repeat('B',64)])),'bad PKCE');
        fails(fn()=>$s->exchange(array_replace($exchange,['resource'=>'https://evil.test'])),'audience mismatch');
        $t=$s->exchange($exchange); check($s->verify($t['access_token'])['user_id']===7,'authenticated access');
        fails(fn()=>$s->exchange($exchange),'code replay');
        check($s->verify('static-mcp-bearer')===null,'legacy token cannot enter ChatGPT');
        $refresh=['grant_type'=>'refresh_token','refresh_token'=>$t['refresh_token'],'resource'=>$p['resource']];
        fails(fn()=>$s->exchange(array_replace($refresh,['scope'=>'musedock:admin'])),'refresh scope escalation');
        $next=$s->exchange($refresh); check($s->verify($next['access_token'])!==null,'rotating refresh');
        fails(fn()=>$s->exchange($refresh),'refresh replay');
        check($s->verify($next['access_token'])===null && $s->verify($t['access_token'])===null,'replay revokes entire family');
        $denied=$s->approve($s->request($p),7,false); check(str_contains($denied,'error=access_denied'),'explicit denial');
        $expired=$store->issue('access',['user_id'=>7,'scope'=>'musedock:read','resource'=>$p['resource'],'client_id'=>'test-client'], -1);
        check($s->verify($expired)===null,'expired access');
        $expCode=$store->issue('code',[], -1); check($store->consume($expCode,'code')===null,'expired code');
        $store->set('enabled','0'); check($s->verify($next['access_token'])===null,'disabled integration');
        fails(fn()=>$s->request($p),'disabled authorization'); $store->set('enabled','1');
        foreach (['mail_domain_create','failover_configure','config_mirror','fail2ban_manage','request_password_change','unknown_tool'] as $name) {
            $r=ReadOnlyMcp::handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>['apply'=>true]]]);
            check(($r['result']['isError']??false)===true,'server blocks '.$name.' despite legacy write=1');
        }
        $list=ReadOnlyMcp::handle(['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/list']);
        check(count($list['result']['tools'])>0,'read tool catalog');
        foreach ($list['result']['tools'] as $tool) check(!$tool['annotations']['destructiveHint'] && $tool['annotations']['readOnlyHint'] && $tool['securitySchemes'][0]['scopes']===['musedock:read'],'scoped read-only descriptor');
        foreach (['/login','/settings/mcp','/api/mcp','/api/cluster/action','/api/chatgpt/oauth/token/','/api/chatgpt/oauth/token/foo'] as $path) check(!IngressPolicy::exempt($path,['REMOTE_ADDR'=>'198.51.100.8']),'admin ingress preserved: '.$path);
        check(IngressPolicy::exempt('/api/mcp/chatgpt',['REMOTE_ADDR'=>'127.0.0.1']),'loopback tunnel');
        check(!IngressPolicy::exempt('/api/mcp/chatgpt',['REMOTE_ADDR'=>'127.0.0.1','HTTP_X_FORWARDED_FOR'=>'198.51.100.8']),'public proxy cannot reach private MCP');
        check(!IngressPolicy::exempt('/api/mcp/chatgpt',['REMOTE_ADDR'=>'198.51.100.8']),'direct external MCP denied');
        check(IngressPolicy::publicPath('/api/chatgpt/oauth/token'),'public OAuth exact route');
        $store->revokeAll(); check($store->find($next['refresh_token'],'refresh')===null,'disconnect revokes grants');
        check((fileperms($dir.'/oauth.sqlite') & 0777)===0600,'private state file');
        $raw=file_get_contents($dir.'/oauth.sqlite');
        check(!str_contains($raw,$t['access_token']) && !str_contains($raw,'test-secret') && !str_contains($raw,$q['code']),'no plaintext tokens codes or client secret');
        echo "OK: $count OAuth/permissions/ingress checks\n";
    } finally { unset($s,$store); foreach(glob($dir.'/*') as $file) unlink($file); rmdir($dir); }
}
