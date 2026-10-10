<?php
/** Real router + controllers over loopback; fake DB, isolated .env and OAuth state. */
$source=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/musedock-http-test-'.bin2hex(random_bytes(6)); mkdir($fixture,0700);
foreach(['public','storage','storage/sessions','storage/logs'] as $d) mkdir($fixture.'/'.$d,0700);
symlink($source.'/app',$fixture.'/app'); mkdir($fixture.'/config',0700);copy($source.'/config/panel.php',$fixture.'/config/panel.php'); symlink($source.'/resources',$fixture.'/resources');
foreach(['index.php','router.php'] as $f) copy($source.'/public/'.$f,$fixture.'/public/'.$f);
file_put_contents($fixture.'/.env',"ALLOWED_IPS=192.0.2.1\n");
$harness= <<<'HARNESS'
<?php
namespace MuseDockPanel {
final class Settings { public static function get(string $k,string $d=''): string { return match($k) { 'mcp_enabled','mcp_allow_write'=>'1', 'mcp_token_hash'=>hash('sha256','legacy-test-token'),default=>$d }; } public static function set(string $k,string $v): void {} }
final class Database { public static function fetchOne(string $s,array $p=[]): ?array { return str_contains($s,'panel_admins') && ($p['id']??7)===7 ? ['id'=>7,'role'=>'superadmin'] : null; } public static function fetchAll(string $s,array $p=[]): array { return []; } public static function insert(string $t,array $p): int {return 1;} }
}
namespace MuseDockPanel\Services {
final class MigrationService { public static function getPending(): array {return [];} }
final class LicenseService { public const FEATURE_PORTAL='portal'; public static function hasFeature(string $s): bool {return false;} }
final class LogService { public static function log(mixed ...$p): void {} }
}
namespace MuseDockPanel\Controllers { final class SetupController { public static function needsSetup(): bool {return false;} } }
namespace { require __DIR__.'/public/router.php'; }
HARNESS;
file_put_contents($fixture.'/harness.php',$harness);
define('PANEL_ROOT',$fixture);
spl_autoload_register(function($c)use($source){$prefix='MuseDockPanel\\';if(str_starts_with($c,$prefix)){$f=$source.'/app/'.str_replace('\\','/',substr($c,strlen($prefix))).'.php';if(is_file($f))require $f;}});
use MuseDockPanel\ChatGpt\{OAuthStore,OAuthServer};
$store=new OAuthStore();
foreach(['enabled'=>'1','client_id'=>'client-http','client_secret_hash'=>hash('sha256','secret-http'),'issuer'=>'https://oauth.example.com',
'resource'=>'https://mcp.example.com/api/mcp/chatgpt','redirect_uri'=>'https://chatgpt.com/connector_platform_oauth_redirect','panel_url'=>'https://panel.example.com:8444'] as $k=>$v)$store->set($k,$v);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$log=$fixture.'/server.log';
$process=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$fixture.'/public',$fixture.'/harness.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$fixture);
$count=0; $tlsProcess=null; $tunnelProcess=null;
function assertHttp(bool $ok,string $label): void {global $count;if(!$ok)throw new RuntimeException('FAIL '.$label);$count++;}
function requestHttp(string $path,?array $body=null,array $headers=[]): array {global $port;$c=curl_init('http://127.0.0.1:'.$port.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>$headers]);if($body!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($body)]);$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$size=curl_getinfo($c,CURLINFO_HEADER_SIZE);curl_close($c);return [$status,substr((string)$raw,$size),substr((string)$raw,0,$size)];}
function mcpHttp(array $msg,string $token,array $headers=[]): array {global $port;$c=curl_init('http://127.0.0.1:'.$port.'/api/mcp/chatgpt');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_PROXY=>'',CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($msg),CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json','Authorization: Bearer '.$token],$headers)]);$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,json_decode((string)$raw,true)];}
try {
for($i=0;$i<50;$i++){usleep(20000);if(requestHttp('/.well-known/oauth-authorization-server')[0]===200)break;}
[$status,$body]=requestHttp('/.well-known/oauth-authorization-server',null,['X-Forwarded-For: 198.51.100.99']);$meta=json_decode($body,true);
assertHttp($status===200 && $meta['code_challenge_methods_supported']===['S256'],'public metadata through restricted router');
[$status,$body]=requestHttp('/.well-known/oauth-protected-resource/api/mcp/chatgpt');assertHttp($status===200&&json_decode($body,true)['scopes_supported']===['musedock:read'],'resource metadata');
foreach(['/login','/settings/mcp','/api/mcp','/api/cluster/action','/api/mcp/chatgpt','/api/chatgpt/oauth/token/extra'] as $path)assertHttp(requestHttp($path,null,['X-Forwarded-For: 198.51.100.99'])[0]===403,'IP denial '.$path);
[$status,$body]=requestHttp('/api/mcp',null,['X-Forwarded-For: 192.0.2.1','Authorization: Bearer invalid']);assertHttp($status===401,'legacy MCP invalid bearer remains 401');
[$status,$body]=requestHttp('/api/mcp',null,['X-Forwarded-For: 192.0.2.1','Authorization: Bearer legacy-test-token']);assertHttp($status===405,'legacy bearer still accepted');
assertHttp(requestHttp('/api/chatgpt/oauth/token',['grant_type'=>'authorization_code'])[0]===401,'token endpoint rejects missing client auth');
[$status,$body,$headers]=requestHttp('/api/mcp/chatgpt');assertHttp($status===401&&str_contains($headers,'resource_metadata='),'private MCP OAuth challenge');
$verifier=str_repeat('X',64);$p=['client_id'=>'client-http','response_type'=>'code','redirect_uri'=>$store->get('redirect_uri'),'resource'=>$store->get('resource'),'state'=>'state-http','scope'=>'musedock:read','code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
[$status,$body,$headers]=requestHttp('/api/chatgpt/oauth/authorize?'.http_build_query($p),null,['X-Forwarded-For: 198.51.100.99']);assertHttp($status===302&&str_contains($headers,'https://panel.example.com:8444/settings/mcp/chatgpt/approve?request='),'authorize redirects only to private panel');
preg_match('/request=([a-f0-9]{64})/',$headers,$match);$server=new OAuthServer($store);$url=$server->approve($match[1],7,true);parse_str(parse_url($url,PHP_URL_QUERY),$q);
$form=['grant_type'=>'authorization_code','code'=>$q['code'],'redirect_uri'=>$p['redirect_uri'],'resource'=>$p['resource'],'code_verifier'=>$verifier];
[$status,$body,$headers]=requestHttp('/api/chatgpt/oauth/token',$form,['Authorization: Basic '.base64_encode('client-http:secret-http')]);$tokens=json_decode($body,true);
assertHttp($status===200&&isset($tokens['access_token'],$tokens['refresh_token']),'HTTP token exchange');assertHttp(str_contains($headers,'Cache-Control: no-store'),'tokens are not cached');
assertHttp(requestHttp('/api/chatgpt/oauth/token',$form,['Authorization: Basic '.base64_encode('client-http:secret-http')])[0]===400,'HTTP code replay denied');
assertHttp(mcpHttp(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],'legacy-test-token')[0]===401,'traditional bearer rejected on ChatGPT path');
$inactive=$store->issue('access',['user_id'=>8,'client_id'=>'client-http','resource'=>$store->get('resource'),'scope'=>'musedock:read'],3600);
assertHttp(mcpHttp(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],$inactive)[0]===401,'deactivated or downgraded admin denied');
[$status,$r]=mcpHttp(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],$tokens['access_token']);assertHttp($status===200&&count($r['result']['tools'])>0,'OAuth tool discovery');
[$status,$r]=mcpHttp(['jsonrpc'=>'2.0','id'=>2,'method'=>'initialize','params'=>['protocolVersion'=>'2025-03-26']],$tokens['access_token']);assertHttp($status===200&&str_contains($r['result']['instructions'],'solo lectura'),'OAuth initialize');
[$status,$r]=mcpHttp(['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call','params'=>['name'=>'failover_configure','arguments'=>['apply'=>true]]],$tokens['access_token']);assertHttp($status===200&&$r['result']['isError'],'HTTP writes blocked with legacy writes enabled');
assertHttp(mcpHttp(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],$tokens['access_token'],['Origin: https://evil.test'])[0]===403,'browser origin denied');
[$status,$body]=requestHttp('/api/chatgpt/oauth/token',['grant_type'=>'refresh_token','resource'=>$p['resource'],'refresh_token'=>$tokens['refresh_token']],['Authorization: Basic '.base64_encode('client-http:secret-http')]);$next=json_decode($body,true);assertHttp($status===200&&isset($next['access_token']),'HTTP refresh');
assertHttp(requestHttp('/api/chatgpt/oauth/revoke',['token'=>$next['refresh_token']],['Authorization: Basic '.base64_encode('client-http:secret-http')])[0]===200,'HTTP revocation');
assertHttp(mcpHttp(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],$next['access_token'])[0]===401,'revocation takes effect');
if ($binary = getenv('MUSEDOCK_TEST_TUNNEL_CLIENT')) {
    if (!is_executable($binary)) throw new RuntimeException('Official tunnel-client binary not executable');
    $tlsSocket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$tlsPort=(int)substr(strrchr(stream_socket_get_name($tlsSocket,false),':'),1);fclose($tlsSocket);
    $certProcess=proc_open(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1','-keyout',$fixture.'/tls.key','-out',$fixture.'/tls.crt'],[0=>['pipe','r'],1=>['file',$fixture.'/cert.log','a'],2=>['file',$fixture.'/cert.log','a']],$certPipes);
    if(proc_close($certProcess)!==0)throw new RuntimeException('Cannot create test CA');
    $python= <<<'TLS'
import http.server, ssl, urllib.request, sys
class Proxy(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        if not self.path.startswith('/.well-known/'):
            self.send_error(404); return
        try:
            response=urllib.request.urlopen('http://127.0.0.1:'+sys.argv[2]+self.path)
            self.send_response(response.status)
            self.send_header('Content-Type','application/json'); self.end_headers(); self.wfile.write(response.read())
        except Exception:
            self.send_error(503)
    def log_message(self, *args): pass
server=http.server.HTTPServer(('127.0.0.1',int(sys.argv[1])),Proxy)
context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); context.load_cert_chain(sys.argv[3],sys.argv[4]); server.socket=context.wrap_socket(server.socket,server_side=True); server.serve_forever()
TLS;
    file_put_contents($fixture.'/tls.py',$python);
    $tlsProcess=proc_open(['python3',$fixture.'/tls.py',(string)$tlsPort,(string)$port,$fixture.'/tls.crt',$fixture.'/tls.key'],[0=>['pipe','r'],1=>['file',$fixture.'/tls.log','a'],2=>['file',$fixture.'/tls.log','a']],$tlsPipes);
    $store->set('issuer','https://localhost:'.$tlsPort);$store->set('tunnel_id','tunnel_22222222222222222222222222222222');
    $_ENV['PANEL_INTERNAL_PORT']=(string)$port;
    file_put_contents($fixture.'/storage/chatgpt/openai-key','sk-test-local-only');
    $profile=\MuseDockPanel\ChatGpt\Integration::profile($store);
    $profile=str_replace('listen_addr: 127.0.0.1:18446','listen_addr: 127.0.0.1:0',$profile);
    $profile.="ca_bundle: ".json_encode($fixture.'/tls.crt',JSON_UNESCAPED_SLASHES)."\n";
    file_put_contents($fixture.'/profile.yaml',$profile);
    $tunnelProcess=proc_open([$binary,'dev','proxy','--profile-file',$fixture.'/profile.yaml','--backend','go','--print-json','--url-file',$fixture.'/tunnel.json','--duration','45s','--readiness-timeout','10s'],[0=>['pipe','r'],1=>['file',$fixture.'/tunnel.out','a'],2=>['file',$fixture.'/tunnel.log','a']],$tunnelPipes);
    for($i=0;$i<150;$i++){usleep(100000);if(is_file($fixture.'/tunnel.json'))break;if(!proc_get_status($tunnelProcess)['running'])break;}
    if(!is_file($fixture.'/tunnel.json'))throw new RuntimeException('Official client failed: '.file_get_contents($fixture.'/tunnel.log'));
    $info=json_decode(file_get_contents($fixture.'/tunnel.json'),true);
    $tunnelUrl=$info['mcp_url'];
    $parsed=parse_url($tunnelUrl);$discovery=$parsed['scheme'].'://'.$parsed['host'].':'.$parsed['port'].'/.well-known/oauth-protected-resource'.$parsed['path'];
    $c=curl_init($discovery);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);$prmd=json_decode($raw,true);
    assertHttp($status===200&&isset($prmd['resource'],$prmd['authorization_servers']),'official tunnel forwards OAuth discovery');
    assertHttp($prmd['resource']===$tunnelUrl,'tunnel rewrites canonical resource');
    $store->set('resource',$prmd['resource']);
    $authorization=$p;$authorization['resource']=$prmd['resource'];
    $url=$server->approve($server->request($authorization),7,true);parse_str(parse_url($url,PHP_URL_QUERY),$tunnelCode);
    $issued=$server->exchange(['grant_type'=>'authorization_code','code'=>$tunnelCode['code'],'redirect_uri'=>$authorization['redirect_uri'],'resource'=>$prmd['resource'],'code_verifier'=>$verifier]);
    assertHttp($server->verify($issued['access_token'])!==null,'OAuth tokens bound to discovered tunnel resource');

    $valid=$issued['access_token'];
    foreach(['tools/list','initialize'] as $method){
        $c=curl_init($tunnelUrl);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>8,'method'=>$method,'params'=>['protocolVersion'=>'2025-03-26']]),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','Authorization: Bearer '.$valid]]);
        $raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
        assertHttp($status===200&&isset(json_decode($raw,true)['result']),'official tunnel forwards authenticated '.$method);
    }
    $c=curl_init($tunnelUrl);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>9,'method'=>'tools/call','params'=>['name'=>'failover_configure','arguments'=>['apply'=>true]]]),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$valid]]);
    $raw=curl_exec($c);curl_close($c);assertHttp(json_decode($raw,true)['result']['isError']??false,'official tunnel still blocks writes');
}
$store->set('enabled','0');assertHttp(requestHttp('/.well-known/oauth-authorization-server')[0]===404,'disabled metadata hidden');assertHttp(requestHttp('/api/mcp/chatgpt')[0]===404,'disabled MCP hidden');
$raw=file_get_contents($log);assertHttp(!str_contains($raw,$tokens['access_token'])&&!str_contains($raw,$q['code'])&&!str_contains($raw,'secret-http'),'no secrets in HTTP process log');
$chatGpt=\MuseDockPanel\ChatGpt\Integration::state();$chatGptNewSecret=null;
ob_start();require $source.'/resources/views/settings/_chatgpt.php';$html=ob_get_clean();
assertHttp(str_contains($html,'Conectar con ChatGPT')&&str_contains($html,'Desconectado')&&str_contains($html,'solo lectura'),'connection settings render');
assertHttp(!str_contains($html,'secret-http')&&!str_contains($html,'sk-test-local-only'),'settings do not expose stored secrets');
session_save_path($fixture.'/storage/sessions');session_name('musedock_panel_session');session_id(bin2hex(random_bytes(16)));session_start();
$_SESSION=['panel_user'=>['id'=>7,'username'=>'test-superadmin','role'=>'superadmin'],'_csrf_token'=>'csrf-test'];$cookie='Cookie: musedock_panel_session='.session_id();session_write_close();
[$status,$body,$actionHeaders]=requestHttp('/settings/mcp/chatgpt/action',['action'=>'connect','_csrf_token'=>'csrf-test'],[$cookie,'X-Forwarded-For: 192.0.2.1']);
assertHttp($status===302 && str_contains($actionHeaders,'Location: /settings/mcp'),'superadmin connection action redirects to settings instead of 403');
assertHttp($store->get('enabled')==='0','missing setup cannot enable integration');
$form=['issuer'=>'https://oauth.example.com','panel_url'=>'https://panel.example.com:8444','resource'=>'https://mcp.example.com/api/mcp/chatgpt','redirect_uri'=>'https://chatgpt.com/connector_platform_oauth_redirect','tunnel_id'=>'tunnel_22222222222222222222222222222222','openai_key'=>'sk-test-runtime-key','_csrf_token'=>'csrf-test'];
[$status]=requestHttp('/settings/mcp/chatgpt/save',$form,[$cookie,'X-Forwarded-For: 192.0.2.1']);
assertHttp($status===302 && file_get_contents($fixture.'/storage/chatgpt/openai-key')==='sk-test-runtime-key','superadmin saves exact runtime key');
$form['openai_key']='';requestHttp('/settings/mcp/chatgpt/save',$form,[$cookie,'X-Forwarded-For: 192.0.2.1']);
assertHttp(file_get_contents($fixture.'/storage/chatgpt/openai-key')==='sk-test-runtime-key','blank key preserves stored credential');
requestHttp('/settings/mcp/chatgpt/save',array_replace($form,['openai_key'=>'sk-unauthorized-change','_csrf_token'=>'invalid']),[$cookie,'X-Forwarded-For: 192.0.2.1']);
assertHttp(file_get_contents($fixture.'/storage/chatgpt/openai-key')==='sk-test-runtime-key','invalid CSRF cannot replace the runtime key');
echo "OK: $count isolated HTTP checks\n";
} finally {
if(is_resource($tunnelProcess)){proc_terminate($tunnelProcess);proc_close($tunnelProcess);}
if(is_resource($tlsProcess)){proc_terminate($tlsProcess);proc_close($tlsProcess);}
proc_terminate($process);proc_close($process);unset($server,$store);
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($iterator as $file){$path=$file->getPathname();if($file->isLink()||$file->isFile())unlink($path);else rmdir($path);}rmdir($fixture);
}
