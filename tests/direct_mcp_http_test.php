<?php
/** Real PHP ingress/controllers plus isolated Caddy. Never writes production Caddy or DNS. */
$source=dirname(__DIR__);$tmp=sys_get_temp_dir().'/musedock-direct-http-'.bin2hex(random_bytes(6));mkdir($tmp,0700);
foreach(['public','storage','storage/sessions','storage/logs','config'] as $d)mkdir($tmp.'/'.$d,0700);
symlink($source.'/app',$tmp.'/app');symlink($source.'/resources',$tmp.'/resources');copy($source.'/config/panel.php',$tmp.'/config/panel.php');foreach(['router.php','index.php'] as $f)copy($source.'/public/'.$f,$tmp.'/public/'.$f);
function freePort(): int{$s=stream_socket_server('tcp://127.0.0.1:0');$p=(int)substr(strrchr(stream_socket_get_name($s,false),':'),1);fclose($s);return $p;}
$backend=freePort();$edge=freePort();file_put_contents($tmp.'/.env',"ALLOWED_IPS=192.0.2.1\nPANEL_INTERNAL_PORT=$backend\n");
file_put_contents($tmp.'/harness.php', <<<'HARNESS'
<?php
namespace MuseDockPanel {
final class Settings { public static function get(string $k,string $d=''): string{return match($k){'mcp_enabled','mcp_allow_write'=>'1','mcp_token_hash'=>hash('sha256','legacy-test-token'),default=>$d};}public static function set(string $k,string $v):void{} }
final class Database { public static function fetchOne(string $s,array $p=[]): ?array{return str_contains($s,'panel_admins')&&($p['id']??7)===7?['id'=>7,'role'=>'superadmin']:null;}public static function fetchAll(string $s,array $p=[]):array{return [];}public static function insert(string $t,array $p):int{return 1;} }
}
namespace MuseDockPanel\Services {
final class MigrationService{public static function getPending():array{return [];}}
final class LicenseService{public const FEATURE_PORTAL='portal';public static function hasFeature(string $s):bool{return false;}}
final class LogService{public static function log(mixed ...$p):void{}}
}
namespace MuseDockPanel\DirectMcp { final class CaddyRoute { public static function ensure(mixed $r): void {} } }
namespace MuseDockPanel\Controllers { final class SetupController{public static function needsSetup():bool{return false;}} }
namespace {require __DIR__.'/public/router.php';}
HARNESS);
define('PANEL_ROOT',$tmp);spl_autoload_register(function($c)use($source){$p='MuseDockPanel\\';if(str_starts_with($c,$p)){$f=$source.'/app/'.str_replace('\\','/',substr($c,strlen($p))).'.php';if(is_file($f))require $f;}});require $source.'/app/bootstrap.php';
use MuseDockPanel\DirectMcp\{Registry,CaddyRoute};
$r=new Registry();$r->configure('https://mcp.example.com','https://panel.example.com:8444');
$a=$r->register('ChatGPT test','openai','https://chatgpt.com/connector_platform_oauth_redirect');$b=$r->register('Claude test','anthropic','https://claude.ai/api/mcp/auth_callback');$c=$r->register('Loopback test','other','https://client.example.com/callback',['127.0.0.0/8']);
foreach(['openai'=>['100.31.168.162/32'],'anthropic'=>['160.79.104.0/21']] as $p=>$ranges)$r->store->set('ranges_'.$p,json_encode(['cidrs'=>$ranges,'checked_at'=>time()]));$r->activate();
$php=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$backend,'-t',$tmp.'/public',$tmp.'/harness.php'],[0=>['pipe','r'],1=>['file',$tmp.'/php.log','a'],2=>['file',$tmp.'/php.log','a']],$pipes,$tmp);$caddy=null;$apiProcess=null;$count=0;
function ok(bool $v,string $label):void{global $count;if(!$v)throw new RuntimeException('FAIL '.$label);$count++;}
function mergedHeaders(array $base,array $extra):array{$map=[];foreach(array_merge($base,$extra) as $line){$key=strtolower(strtok($line,':'));$map[$key]=$line;}return array_values($map);}
function req(string $path, mixed $body=null,array $headers=[],bool $viaEdge=false,bool $sealed=true):array{global $backend,$edge,$r;$ch=curl_init('http://127.0.0.1:'.($viaEdge?$edge:$backend).$path);$base=['Host: mcp.example.com'];if($sealed&&!$viaEdge)$base=array_merge($base,['X-MuseDock-Mcp-Ingress: '.$r->store->get('ingress_secret'),'X-MuseDock-Mcp-IP: 100.31.168.162']);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>4,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>mergedHeaders($base,$headers)]);if($body!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>is_array($body)?http_build_query($body):$body]);$raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$n=curl_getinfo($ch,CURLINFO_HEADER_SIZE);curl_close($ch);return [$status,substr((string)$raw,$n),substr((string)$raw,0,$n)];}
function tokens(array $client,string $scope='musedock:read'):array{global $r;$s=$r->server($client['id']);$v=str_repeat('Z',64);$p=['client_id'=>$client['id'],'redirect_uri'=>$s->store->get('redirect_uri'),'resource'=>$s->resource(),'response_type'=>'code','state'=>'http-state','scope'=>$scope,'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'=')];$url=$s->approve($s->request($p),7,true);parse_str(parse_url($url,PHP_URL_QUERY),$q);return $s->exchange(['grant_type'=>'authorization_code','code'=>$q['code'],'redirect_uri'=>$p['redirect_uri'],'resource'=>$p['resource'],'code_verifier'=>$v]);}
try{
for($i=0;$i<50;$i++){usleep(20000);if(req('/.well-known/oauth-authorization-server')[0]===200)break;}
[$status,$body]=req('/.well-known/oauth-authorization-server');$meta=json_decode($body,true);ok($status===200&&$meta['token_endpoint']==='https://mcp.example.com/oauth/token','direct metadata');
[$status,$body]=req('/.well-known/oauth-protected-resource/mcp');ok($status===200&&json_decode($body,true)['resource']==='https://mcp.example.com/mcp','correct resource audience');
ok(req('/mcp',null,[],false,false)[0]===403,'missing ingress secret cannot bypass admin IP restriction');
ok(req('/mcp',null,['X-MuseDock-Mcp-Ingress: fake'])[0]===403,'forged secret denied');
ok(req('/oauth/token',[],['X-MuseDock-Mcp-IP: 100.31.168.162, 127.0.0.1'])[0]===403,'multiple claimed IPs denied');
foreach(['/login','/settings/mcp','/api/cluster/action','/api/mcp','/mcp/extra','/oauth/token/extra','/assets/app.css'] as $path)ok(req($path)[0]===403,'private paths not exempt '.$path);
foreach(['/login','/settings/mcp','/api/mcp','/assets/app.css'] as $path)ok(req($path,null,['X-Forwarded-For: 192.0.2.1'])[0]===404,'dedicated Host never falls through '.$path);
$ta=tokens($a);$tb=tokens($b);$tc=tokens($c);$msg=json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list']);
[$status,$body]=req('/mcp',$msg,['Authorization: Bearer '.$ta['access_token'],'Content-Type: application/json']);ok($status===200&&count(json_decode($body,true)['result']['tools'])>0,'OpenAI read-only tool listing');
ok(req('/mcp',$msg,['Authorization: Bearer '.$tb['access_token'],'Content-Type: application/json'])[0]===401,'Claude token rejected from OpenAI network');
ok(req('/mcp',$msg,['Authorization: Bearer '.$ta['access_token'],'Content-Type: application/json','X-MuseDock-Mcp-IP: 160.79.104.1'])[0]===401,'OpenAI token rejected from Claude network');
[$status,$body]=req('/mcp',$msg,['Authorization: Bearer '.$tb['access_token'],'Content-Type: application/json','X-MuseDock-Mcp-IP: 160.79.104.1']);ok($status===200,'Claude read-only tool listing');
$write=json_encode(['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/call','params'=>['name'=>'failover_configure','arguments'=>['apply'=>true]]]);[$status,$body]=req('/mcp',$write,['Authorization: Bearer '.$ta['access_token'],'Content-Type: application/json']);ok($status===200&&json_decode($body,true)['result']['isError'],'writes denied even when legacy writes enabled');
ok(req('/mcp',$msg,['Authorization: Bearer legacy-test-token','Content-Type: application/json'])[0]===401,'legacy bearer cannot authenticate direct MCP');
ok(req('/mcp',$msg,['Authorization: Bearer '.$ta['access_token'],'Origin: https://attacker.example','Content-Type: application/json'])[0]===403,'Origin denied');
ok(req('/mcp',null,['Authorization: Bearer '.$ta['access_token']])[0]===405,'authenticated GET is 405');
$s=$r->server($a['id']);$v=str_repeat('Q',64);$p=['client_id'=>$a['id'],'redirect_uri'=>$s->store->get('redirect_uri'),'resource'=>$s->resource(),'response_type'=>'code','state'=>'authorize-state','scope'=>'musedock:read','code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'=')];
[$status,$body,$headers]=req('/oauth/authorize?'.http_build_query($p));ok($status===302&&str_contains($headers,'https://panel.example.com:8444/settings/mcp/direct/approve?'),'consent uses private admin panel');
preg_match('/request=([a-f0-9]{64})/',$headers,$m);$url=$s->approve($m[1],7,true);parse_str(parse_url($url,PHP_URL_QUERY),$q);
$form=['grant_type'=>'authorization_code','code'=>$q['code'],'redirect_uri'=>$p['redirect_uri'],'resource'=>$p['resource'],'code_verifier'=>$v];$basic='Authorization: Basic '.base64_encode($a['id'].':'.$a['secret']);
[$status,$body,$headers]=req('/oauth/token',$form,[$basic]);$t=json_decode($body,true);ok($status===200&&isset($t['access_token'],$t['refresh_token'])&&str_contains($headers,'Cache-Control: no-store'),'actual HTTP code exchange');
ok(req('/oauth/token',$form,[$basic])[0]===400,'authorization code replay denied');
ok(req('/oauth/token',['grant_type'=>'refresh_token','refresh_token'=>$t['refresh_token'],'resource'=>'https://wrong.example/mcp'],[$basic])[0]===400,'refresh audience substitution denied');
[$status,$body]=req('/oauth/token',['grant_type'=>'refresh_token','refresh_token'=>$t['refresh_token'],'resource'=>$p['resource']],[$basic]);$rotated=json_decode($body,true);ok($status===200&&$rotated['refresh_token']!==$t['refresh_token'],'refresh rotation');
req('/oauth/token',['grant_type'=>'refresh_token','refresh_token'=>$t['refresh_token'],'resource'=>$p['resource']],[$basic]);ok(req('/mcp',$msg,['Authorization: Bearer '.$rotated['access_token']])[0]===401,'refresh replay revokes token family');
$inactive=$s->store->issue('access',['user_id'=>8,'client_id'=>$a['id'],'resource'=>$s->resource(),'scope'=>'musedock:read'],3600);ok(req('/mcp',$msg,['Authorization: Bearer '.$inactive])[0]===401,'inactive administrator denied');
// Start a completely separate Caddy, without TLS, admin API or public listeners.
$config=['admin'=>['disabled'=>true,'config'=>['persist'=>false]],'apps'=>['http'=>['servers'=>['isolated'=>['listen'=>['127.0.0.1:'.$edge],'automatic_https'=>['disable'=>true],'routes'=>[CaddyRoute::build($r)]]]]]];
file_put_contents($tmp.'/caddy.json',json_encode($config,JSON_UNESCAPED_SLASHES));
$caddy=proc_open(['/usr/bin/caddy','run','--config',$tmp.'/caddy.json'],[0=>['pipe','r'],1=>['file',$tmp.'/caddy.log','a'],2=>['file',$tmp.'/caddy.log','a']],$caddyPipes,$tmp);
for($i=0;$i<50;$i++){usleep(20000);if(req('/.well-known/oauth-authorization-server',null,[],true)[0]===200)break;}
ok(req('/.well-known/oauth-authorization-server',null,[],true)[0]===200,'actual isolated Caddy serves metadata');
foreach(['/','/login','/settings/mcp','/api/cluster/action','/api/mcp','/assets/app.css','/oauth/token/extra','/mcp/'] as $path)ok(req($path,null,[],true)[0]===404,'Caddy blocks admin/extra route '.$path);
ok(req('/mcp',$msg,['Authorization: Bearer '.$ta['access_token'],'X-MuseDock-Mcp-IP: 100.31.168.162','X-Forwarded-For: 100.31.168.162','CF-Connecting-IP: 100.31.168.162'],true)[0]===401,'Caddy overwrites fake provider IP headers');
[$status,$body]=req('/mcp',$msg,['Authorization: Bearer '.$tc['access_token']],true);ok($status===200&&isset(json_decode($body,true)['result']['tools']),'actual Caddy sealed proxy accepts authorized independent client');
ok(req('/mcp',str_repeat(' ',1048577),['Authorization: Bearer '.$tc['access_token'],'Content-Type: application/json'],true)[0]===413,'Caddy MCP request body capped at 1 MiB');
ok(req('/oauth/token','x='.str_repeat('x',16385),['Authorization: Basic '.base64_encode($c['id'].':'.$c['secret']),'Content-Type: application/x-www-form-urlencoded'],true)[0]===413,'Caddy OAuth request body capped at 16 KiB');
// Remove loopback from Caddy ranges while retaining it in application state: matcher must deny it.
$config['apps']['http']['servers']['isolated']['routes'][0]['handle'][1]['routes'][1]['match'][0]['remote_ip']['ranges']=['100.31.168.162/32'];
proc_terminate($caddy);proc_close($caddy);$caddy=null;file_put_contents($tmp.'/caddy.json',json_encode($config));
$caddy=proc_open(['/usr/bin/caddy','run','--config',$tmp.'/caddy.json'],[0=>['pipe','r'],1=>['file',$tmp.'/caddy.log','a'],2=>['file',$tmp.'/caddy.log','a']],$caddyPipes,$tmp);
for($i=0;$i<50;$i++){usleep(20000);if(req('/mcp',null,[],true)[0]===403)break;}
ok(req('/mcp',$msg,['Authorization: Bearer '.$tc['access_token'],'CF-Connecting-IP: 100.31.168.162'],true)[0]===403,'actual peer filter denies spoofed Cloudflare IP');
// Admin settings actions require session, current role and CSRF.
session_save_path($tmp.'/storage/sessions');session_name('musedock_panel_session');session_id(bin2hex(random_bytes(16)));session_start();$_SESSION=['panel_user'=>['id'=>7,'username'=>'fixture','role'=>'superadmin'],'_csrf_token'=>'csrf-direct'];$cookie='Cookie: musedock_panel_session='.session_id();session_write_close();
$ch=curl_init('http://127.0.0.1:'.$backend.'/settings/mcp/direct/save');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['action'=>'disconnect','client'=>$a['id'],'_csrf_token'=>'wrong']),CURLOPT_HTTPHEADER=>[$cookie,'Host: panel.example.com','X-Forwarded-For: 192.0.2.1','Accept: application/json']]);curl_exec($ch);ok(curl_getinfo($ch,CURLINFO_RESPONSE_CODE)===403&&$s->verify($ta['access_token'])!==null,'wrong CSRF cannot revoke client: HTTP '.curl_getinfo($ch,CURLINFO_RESPONSE_CODE).' token='.($s->verify($ta['access_token'])!==null?'valid':'invalid'));curl_close($ch);
// Exercise private consent with the real session and CSRF middleware.
$bs=$r->server($b['id']);$bp=array_replace($p,['client_id'=>$b['id'],'redirect_uri'=>$bs->store->get('redirect_uri')]);$pending=$bs->request($bp);
$ch=curl_init('http://127.0.0.1:'.$backend.'/settings/mcp/direct/approve?'.http_build_query(['client'=>$b['id'],'request'=>$pending]));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>[$cookie,'Host: panel.example.com','X-Forwarded-For: 192.0.2.1']]);$page=curl_exec($ch);ok(curl_getinfo($ch,CURLINFO_RESPONSE_CODE)===200&&str_contains($page,'Claude test'),'private consent identifies the individual client');
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HEADER=>true,CURLOPT_POSTFIELDS=>http_build_query(['client'=>$b['id'],'request'=>$pending,'decision'=>'approve','_csrf_token'=>'csrf-direct'])]);$response=curl_exec($ch);ok(curl_getinfo($ch,CURLINFO_RESPONSE_CODE)===302&&str_contains($response,'https://claude.ai/api/mcp/auth_callback?')&&str_contains($response,'state=authorize-state'),'private consent sends code to exact Claude callback');curl_close($ch);
$r->disconnect($a['id']);ok(req('/mcp',$msg,['Authorization: Bearer '.$ta['access_token']])[0]===401,'client revocation takes effect immediately');ok(req('/mcp',$msg,['Authorization: Bearer '.$tb['access_token'],'X-MuseDock-Mcp-IP: 160.79.104.1'])[0]===200,'other client remains usable');
// Scope changes and write approvals go through the same private session/CSRF gate.
$privateSave=function(array $body,bool $authenticated=true)use($backend,$cookie){$ch=curl_init('http://127.0.0.1:'.$backend.'/settings/mcp/direct/save');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($body),CURLOPT_HTTPHEADER=>array_merge(['Host: panel.example.com','X-Forwarded-For: 192.0.2.1','Accept: application/json'],$authenticated?[$cookie]:[])]);$text=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return $code;};
$change=['action'=>'permissions','client'=>$b['id'],'allow_read'=>'1','allow_write'=>'1','allow_dns'=>'1'];
ok($privateSave($change+['_csrf_token'=>'wrong'])===403&&!in_array('musedock:write',$r->clients()[$b['id']]['scopes'],true),'CSRF cannot expand scopes');
ok($privateSave($change+['_csrf_token'=>'csrf-direct'],false)===401,'unauthenticated scope upgrade rejected');
ok($privateSave($change+['_csrf_token'=>'csrf-direct'])===302&&in_array('musedock:dns',$r->clients()[$b['id']]['scopes'],true),'private admin can configure write and DNS');
ok($r->server($b['id'])->verify($tb['access_token'])===null,'HTTP scope upgrade revokes previous token');
$tw=tokens($b,'musedock:read musedock:write musedock:dns');
[$status,$body]=req('/mcp',$msg,['Authorization: Bearer '.$tw['access_token'],'X-MuseDock-Mcp-IP: 160.79.104.1']);$toolList=json_decode($body,true)['result']['tools'];
ok($status===200&&in_array('mail_domain_create',array_column($toolList,'name'),true)&&in_array('dns_record_set',array_column($toolList,'name'),true),'approved write grant advertises curated tools');
$onlyRead=tokens($b);[$status,$body]=req('/mcp',$msg,['Authorization: Bearer '.$onlyRead['access_token'],'X-MuseDock-Mcp-IP: 160.79.104.1']);ok(!in_array('mail_domain_create',array_column(json_decode($body,true)['result']['tools'],'name'),true),'read grant does not inherit client write permissions');
$writeRequest=['action'=>'approve_write','client'=>$b['id'],'request'=>str_repeat('a',64)];ok($privateSave($writeRequest+['_csrf_token'=>'wrong'])===403,'CSRF cannot approve writes');ok($privateSave($writeRequest+['_csrf_token'=>'csrf-direct'],false)===401,'unauthenticated write approval rejected');
$bs=$r->server($b['id']);$writePending=$bs->request(array_replace($bp,['scope'=>'musedock:read musedock:write musedock:dns']));
$ch=curl_init('http://127.0.0.1:'.$backend.'/settings/mcp/direct/approve?'.http_build_query(['client'=>$b['id'],'request'=>$writePending]));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>[$cookie,'Host: panel.example.com','X-Forwarded-For: 192.0.2.1']]);$page=curl_exec($ch);curl_close($ch);ok(str_contains($page,'También solicita escritura')&&str_contains($page,'musedock:dns'),'private consent clearly identifies write and DNS request');
// A fake Caddy API checks optimistic concurrency and route-only rollback.
$apiPort=freePort();file_put_contents($tmp.'/api-routes.json',json_encode([['@id'=>'existing-web','match'=>[['file'=>(object)[]]],'handle'=>[['handler'=>'static_response','status_code'=>200]]]]));
file_put_contents($tmp.'/api.php', <<<'API'
<?php
$path='/config/apps/http/servers/srv0/routes';if($_SERVER['REQUEST_URI']!==$path){http_response_code(404);exit;}
$file=__DIR__.'/api-routes.json';$bytes=file_get_contents($file);$etag='"'.$path.' '.hash('sha256',$bytes).'"';
if($_SERVER['REQUEST_METHOD']==='GET'){header('ETag: '.$etag);header('Content-Type: application/json');echo $bytes;exit;}
if($_SERVER['REQUEST_METHOD']!=='PATCH'){http_response_code(405);exit;}
file_put_contents(__DIR__.'/api-write-count',(string)((int)@file_get_contents(__DIR__.'/api-write-count')+1));
if(!is_file(__DIR__.'/api-raced')){$routes=json_decode($bytes);$routes[]=['@id'=>'concurrent-web','handle'=>[['handler'=>'static_response','status_code'=>200]]];file_put_contents($file,json_encode($routes));file_put_contents(__DIR__.'/api-raced','1');http_response_code(412);exit;}
if(($_SERVER['HTTP_IF_MATCH']??'')!==$etag){http_response_code(412);exit;}
file_put_contents($file,file_get_contents('php://input'));http_response_code(200);
API);
$apiProcess=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$apiPort,$tmp.'/api.php'],[0=>['pipe','r'],1=>['file',$tmp.'/api.log','a'],2=>['file',$tmp.'/api.log','a']],$apiPipes,$tmp);
for($i=0;$i<50;$i++){usleep(20000);$socket=@stream_socket_client('tcp://127.0.0.1:'.$apiPort,$errno,$error,0.1);if($socket){fclose($socket);break;}}
CaddyRoute::ensure($r,'http://127.0.0.1:'.$apiPort);$routes=json_decode(file_get_contents($tmp.'/api-routes.json'),true);$ids=array_column($routes,'@id');ok($ids[0]===CaddyRoute::ID&&in_array('existing-web',$ids,true)&&in_array('concurrent-web',$ids,true),'ETag retry preserves concurrent changes and other web routes');
$preserved=json_decode(file_get_contents($tmp.'/api-routes.json'));ok(is_object($preserved[1]->match[0]->file)&&get_object_vars($preserved[1]->match[0]->file)===[],'empty matcher objects preserved when updating existing production-style routes');
$writes=file_get_contents($tmp.'/api-write-count');CaddyRoute::ensure($r,'http://127.0.0.1:'.$apiPort);ok(file_get_contents($tmp.'/api-write-count')===$writes,'unchanged route does not trigger another Caddy reload');
$r->disconnect();CaddyRoute::ensure($r,'http://127.0.0.1:'.$apiPort);$routes=json_decode(file_get_contents($tmp.'/api-routes.json'),true);ok(array_column($routes,'@id')===['existing-web','concurrent-web'],'rollback removes only the dedicated route');
$r->disconnect();ok(req('/.well-known/oauth-authorization-server')[0]===404&&req('/mcp',$msg,['Authorization: Bearer '.$tc['access_token']])[0]===404,'disabled integration fails closed');
$logs=file_get_contents($tmp.'/php.log').file_get_contents($tmp.'/caddy.log');foreach([$a['secret'],$b['secret'],$ta['access_token'],$tb['access_token'],$r->store->get('ingress_secret')] as $secret)ok(!str_contains($logs,$secret),'no secret in test logs');
$directMcp=$r->state();$directMcpCredentials=null;ob_start();require $source.'/resources/views/settings/_direct-mcp.php';$card=ob_get_clean();ok(str_contains($card,'Conexión MCP directa')&&str_contains($card,'Desactivada')&&str_contains($card,'solo lectura'),'settings render mode, state and read-only permissions');ok(!str_contains($card,$a['secret'])&&!str_contains($card,$r->store->get('ingress_secret')),'settings never expose stored secrets');
echo "OK: $count isolated direct HTTP/Caddy checks\n";
}finally{if(is_resource($apiProcess)){proc_terminate($apiProcess);proc_close($apiProcess);}if(is_resource($caddy)){proc_terminate($caddy);proc_close($caddy);}if(is_resource($php)){proc_terminate($php);proc_close($php);}$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isLink()||$f->isFile())unlink($f->getPathname());else rmdir($f->getPathname());}rmdir($tmp);}
