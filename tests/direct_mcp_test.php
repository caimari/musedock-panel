<?php
/** Isolated registry, OAuth and network policy; no production DB, DNS or Caddy writes. */
$source=dirname(__DIR__);$tmp=sys_get_temp_dir().'/musedock-direct-unit-'.bin2hex(random_bytes(6));mkdir($tmp,0700);define('PANEL_ROOT',$tmp);
// Bootstrap autoload resolves the isolated root, so use the actual source explicitly.
spl_autoload_register(function($c)use($source){$p='MuseDockPanel\\';if(str_starts_with($c,$p)){$f=$source.'/app/'.str_replace('\\','/',substr($c,strlen($p))).'.php';if(is_file($f))require $f;}});
require $source.'/app/bootstrap.php';
use MuseDockPanel\DirectMcp\{Registry,NetworkPolicy,CaddyRoute,Gateway};
$count=0;
function check(bool $ok,string $label): void{global $count;if(!$ok)throw new RuntimeException('FAIL '.$label);$count++;}
function rejects(callable $f,string $label): void{try{$f();}catch(Throwable){check(true,$label);return;}check(false,$label);}
try{
    foreach(['0.0.0.0/0','::/0','127.0.0.1/999','invalid','192.0.2.1/-1','192.0.2.1/32x'] as $cidr)check(!NetworkPolicy::validCidr($cidr),'invalid CIDR '.$cidr);
    check(NetworkPolicy::contains('160.79.111.255','160.79.104.0/21'),'Anthropic boundary');check(!NetworkPolicy::contains('160.79.112.0','160.79.104.0/21'),'outside boundary');
    check(NetworkPolicy::contains('2607:6bc0::1','2607:6bc0::/48'),'IPv6 match');check(!NetworkPolicy::contains('160.79.104.1','2607:6bc0::/48'),'different families');
    rejects(fn()=>NetworkPolicy::parse('openai','{"creationTime":"today","prefixes":[]}'),'empty source');
    rejects(fn()=>NetworkPolicy::parse('openai','{"creationTime":"today","prefixes":[{"ipv4Prefix":"0.0.0.0/0"}]}'),'unsafe official CIDR');
    $claude="## Inbound IP addresses\n`160.79.104.0/23`\n## Outbound IP addresses\n### IPv4\n`160.79.104.0/21`\n### Phased out IP addresses\n`34.162.46.92/32`";
    check(NetworkPolicy::parse('anthropic',$claude)===['160.79.104.0/21'],'only current outbound Claude CIDR');
    rejects(fn()=>NetworkPolicy::parse('anthropic','changed format'),'unknown format fails closed');
    foreach(['http://claude.ai/callback','https://user:pass@claude.ai/callback','https://claude.ai/callback#frag','https://127.0.0.1/callback','https://claude.ai:8444/callback','/callback'] as $url)check(!Registry::callback($url),'callback denied '.$url);
    check(Registry::callback('https://claude.ai/callback?client=example'),'exact HTTPS callback with query');
    $r=new Registry();check(!$r->enabled(),'disabled by default');
    rejects(fn()=>$r->configure('https://mcp.example.com:8444','https://panel.example.com:8444'),'dedicated 443 only');
    $r->configure('https://mcp.example.com','https://panel.example.com:8444');
    foreach(['openai'=>['100.31.168.162/32'],'anthropic'=>['160.79.104.0/21']] as $provider=>$cidrs)$r->store->set('ranges_'.$provider,json_encode(['cidrs'=>$cidrs,'checked_at'=>time()]));
$r->activate();check($r->enabled()&&$r->clients()===[],'OAuth discovery can bootstrap without registered clients');check(!$r->allowed('unknown','100.31.168.162'),'bootstrap grants no client access');$r->disconnect();
$a=$r->register('ChatGPT','openai','https://chatgpt.com/connector_platform_oauth_redirect');
    $b=$r->register('Claude','anthropic','https://claude.ai/api/mcp/auth_callback');
    $other=$r->register('Client test','other','https://client.example.com/callback?client=1',['198.51.100.0/24']);
    foreach(['openai'=>['100.31.168.162/32'],'anthropic'=>['160.79.104.0/21']] as $provider=>$cidrs)$r->store->set('ranges_'.$provider,json_encode(['cidrs'=>$cidrs,'checked_at'=>time()]));
    $r->activate();check($r->allowed($a['id'],'100.31.168.162'),'OpenAI network');check(!$r->allowed($a['id'],'160.79.104.1'),'Claude cannot use OpenAI credentials');check($r->allowed($b['id'],'160.79.104.1'),'Claude network');
    $server=$r->server($a['id']);$verifier=str_repeat('V',64);$p=['client_id'=>$a['id'],'redirect_uri'=>$server->store->get('redirect_uri'),'resource'=>$server->resource(),'response_type'=>'code','state'=>'test-state','scope'=>'musedock:read','code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
    rejects(fn()=>$server->request(array_replace($p,['redirect_uri'=>'https://attacker.example.com/callback'])),'callback must be exact');
    rejects(fn()=>$server->request(array_replace($p,['scope'=>'musedock:write'])),'write scope denied');
    $url=$server->approve($server->request($p),7,true);parse_str(parse_url($url,PHP_URL_QUERY),$q);
    $tokens=$server->exchange(['grant_type'=>'authorization_code','code'=>$q['code'],'redirect_uri'=>$p['redirect_uri'],'resource'=>$p['resource'],'code_verifier'=>$verifier]);
    check($server->verify($tokens['access_token'])!==null,'valid token');check($r->server($b['id'])->verify($tokens['access_token'])===null,'token isolation');
    check(!$r->server($b['id'])->authenticateClient($a['id'],$a['secret']),'secret isolation');
    $r->setReadAccess($other['id'],false);
    check(!$r->allowed($other['id'],'198.51.100.1'),'read checkbox removes network access');
    check($r->clients()[$other['id']]['scopes']===[],'read checkbox removes read scope');
    check($r->clientStore($other['id'])->get('enabled')==='0','paused OAuth server disabled');
    $r->activate();check($r->clientStore($other['id'])->get('enabled')==='0','global activation preserves paused client');
    $r->setReadAccess($other['id'],true);
    check($r->allowed($other['id'],'198.51.100.1'),'read restored independently');
    check($server->verify($tokens['access_token'])!==null,'editing other client preserves active token');
    $r->disconnect($b['id']);check($server->verify($tokens['access_token'])!==null,'revoking Claude preserves OpenAI');check(!$r->allowed($b['id'],'160.79.104.1'),'revoked client blocked');rejects(fn()=>$r->setReadAccess($b['id'],true),'revoked client cannot regain read');
    $r->store->set('ranges_openai',json_encode(['cidrs'=>['100.31.168.162/32'],'checked_at'=>time()-604801]));check(!$r->allowed($a['id'],'100.31.168.162'),'expired ranges fail closed');
    $sealed=['REMOTE_ADDR'=>'127.0.0.1','HTTP_HOST'=>'mcp.example.com','HTTP_X_MUSEDOCK_MCP_INGRESS'=>$r->store->get('ingress_secret'),'HTTP_X_MUSEDOCK_MCP_IP'=>'198.51.100.1'];
    check($r->ingress($sealed),'sealed local proxy accepted');foreach(['REMOTE_ADDR'=>'198.51.100.1','HTTP_HOST'=>'panel.example.com:8444','HTTP_X_MUSEDOCK_MCP_INGRESS'=>'fake','HTTP_X_MUSEDOCK_MCP_IP'=>'198.51.100.1, 127.0.0.1'] as $k=>$v)check(!$r->ingress(array_replace($sealed,[$k=>$v])),'spoof denied '.$k);
    foreach(['/login','/settings/mcp','/api/cluster/action','/mcp/','/oauth/token/extra'] as $path)check(!Gateway::exempt($path,$sealed),'admin/nonexact path never exempt '.$path);
    $route=CaddyRoute::build($r);check($route['match'][0]['host']===['mcp.example.com'] && $route['terminal'],'dedicated terminal host');
    $encoded=json_encode($route);check(!str_contains($encoded,'/settings')&&!str_contains($encoded,'8444'),'no admin path or 8444 upstream');check(str_contains($encoded,'remote_ip')&&!str_contains($encoded,'Cf-Connecting-Ip'),'peer IP matching');
    check(!str_contains(json_encode($route),'100.31.168.162'),'expired network removed from Caddy configuration');
$blocked=\MuseDockPanel\DirectMcp\PermissionPolicy::dispatch(['scopes'=>[]],['scope'=>'musedock:read'],['jsonrpc'=>'2.0','id'=>9,'method'=>'tools/list'],$a['id']);check(($blocked['error']['code']??0)===-32001,'per-client permission gate');
$blocked=\MuseDockPanel\DirectMcp\PermissionPolicy::dispatch(['scopes'=>['musedock:read']],['scope'=>'musedock:write'],['jsonrpc'=>'2.0','id'=>9,'method'=>'tools/list'],$a['id']);check(($blocked['error']['code']??0)===-32001,'write grant cannot inherit read dispatcher');
$r->setReadAccess($a['id'],false);check(!$server->verify($tokens['access_token']),'removing read revokes existing access token');
$r->setReadAccess($a['id'],true);check(!$server->verify($tokens['access_token']),'restoring read does not restore old consent');
$r->disconnect();check(!$r->enabled() && !$server->verify($tokens['access_token']),'disconnect all revokes tokens');
    foreach($r->clients() as $id=>$client){$bytes=file_get_contents($tmp.'/storage/direct-mcp/'.$id.'.sqlite');check(!str_contains($bytes,$a['secret'])&&!str_contains($bytes,$b['secret']),'no plain OAuth secret');}
    echo "OK: $count direct registry/OAuth/network checks\n";
}finally{ $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($tmp); }
