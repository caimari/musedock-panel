<?php
/** Isolated approval policy tests: fake executor, no DNS/mail/database/system changes. */
namespace MuseDockPanel {
final class Settings {public static array $values=['mcp_enabled'=>'1','mcp_allow_write'=>'1','mcp_allow_dns'=>'1'];public static function get(string $key,string $default=''):string{return self::$values[$key]??$default;}}
final class Database {public static bool $active=true;public static function fetchOne(string $sql,array $params=[]):?array{return self::$active?['id'=>$params['id']??7]:null;}}
}
namespace MuseDockPanel\Services {final class LogService{public static function log(mixed ...$args):void{}}}
namespace MuseDockPanel\Mcp {
final class McpTools {
    public static int $applied=0;public static int $revision=1;public static bool $fail=false;
    public static function exists(string $name):bool{return in_array($name,array_merge(['panel_info','cluster_pair_approve'],\MuseDockPanel\DirectMcp\WriteMcp::TOOLS),true);}
    public static function isWrite(string $name):bool{return $name!=='panel_info';}
    public static function listForMcp():array{return array_map(fn($name)=>['name'=>$name,'description'=>'test','inputSchema'=>['type'=>'object','properties'=>(object)['domain'=>['type'=>'string'],'apply'=>['type'=>'boolean'],'password'=>['type'=>'string']],'required'=>['domain']]],array_merge(['panel_info','cluster_pair_approve'],\MuseDockPanel\DirectMcp\WriteMcp::TOOLS));}
    public static function call(string $name,array $args,string $via):array{
        if(!empty($args['apply'])){self::$applied++;$data=self::$fail?['error'=>'simulated failure']:['status'=>'hecho'];}else $data=['status'=>'plan','apply'=>false,'domain'=>$args['domain'],'revision'=>self::$revision];
        return ['isError'=>self::$fail&&!empty($args['apply']),'content'=>[['type'=>'text','text'=>json_encode($data)]]];
    }
}
}
namespace {
$source=dirname(__DIR__);$tmp=sys_get_temp_dir().'/musedock-write-'.bin2hex(random_bytes(6));mkdir($tmp,0700);define('PANEL_ROOT',$tmp);
spl_autoload_register(function($class)use($source){$prefix='MuseDockPanel\\';if(str_starts_with($class,$prefix)){$path=$source.'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require $path;}});
use MuseDockPanel\DirectMcp\{Registry,PermissionPolicy,WriteMcp};
use MuseDockPanel\Mcp\McpTools;
$count=0;
function check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException('FAIL '.$label);$count++;}
function rejects(callable $callback,string $label):void{try{$callback();}catch(Throwable){check(true,$label);return;}check(false,$label);}
function token(Registry $r,string $id,string $scope):array{
    $s=$r->server($id);$v=str_repeat('V',64);$p=['client_id'=>$id,'redirect_uri'=>$s->store->get('redirect_uri'),'resource'=>$s->resource(),'response_type'=>'code','state'=>'test','scope'=>$scope,'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'=')];
    $url=$s->approve($s->request($p),7,true);parse_str(parse_url($url,PHP_URL_QUERY),$q);return $s->exchange(['grant_type'=>'authorization_code','code'=>$q['code'],'redirect_uri'=>$p['redirect_uri'],'resource'=>$p['resource'],'code_verifier'=>$v]);
}
try {
$r=new Registry();$r->configure('https://mcp.example.com','https://panel.example.com:8444');$c=$r->register('GPT','openai','https://chatgpt.com/connector_platform_oauth_redirect');$id=$c['id'];
foreach(['openai'=>['100.31.168.162/32'],'anthropic'=>['160.79.104.0/21']] as $p=>$cidrs)$r->store->set('ranges_'.$p,json_encode(['cidrs'=>$cidrs,'checked_at'=>time()]));$r->activate();
rejects(fn()=>token($r,$id,'musedock:read musedock:write'),'read client cannot request write');
$read=token($r,$id,'musedock:read');$readGrant=$r->server($id)->verify($read['access_token']);
rejects(fn()=>$r->setPermissions($id,false,true,false),'write requires read');rejects(fn()=>$r->setPermissions($id,true,false,true),'dns requires write');
$r->setPermissions($id,true,true,true);check(!$r->server($id)->verify($read['access_token']),'scope upgrade revokes old token');
rejects(fn()=>token($r,$id,'musedock:write'),'write-only grant denied');rejects(fn()=>token($r,$id,'musedock:read musedock:dns'),'dns without write denied');rejects(fn()=>token($r,$id,'musedock:read evil'),'unknown scope denied');
$read=token($r,$id,'musedock:read');$readGrant=$r->server($id)->verify($read['access_token']);check(!WriteMcp::permitted($r->clients()[$id],$readGrant,'mail_domain_create'),'read grant remains read despite expanded client');
$t=token($r,$id,'musedock:read musedock:write musedock:dns');$grant=$r->server($id)->verify($t['access_token']);check($grant!==null,'write consent accepted');check($t['scope']==='musedock:dns musedock:read musedock:write','exact approved scopes in token');
$refresh=$r->server($id)->exchange(['grant_type'=>'refresh_token','refresh_token'=>$t['refresh_token'],'resource'=>$r->issuer().'/mcp']);check($refresh['scope']===$t['scope'],'refresh preserves scopes');
rejects(fn()=>$r->server($id)->exchange(['grant_type'=>'refresh_token','refresh_token'=>$read['refresh_token'],'resource'=>$r->issuer().'/mcp','scope'=>'musedock:read musedock:write']),'refresh cannot elevate read');
$client=$r->clients()[$id];$args=['domain'=>'example.com'];
$tools=WriteMcp::descriptors($client,$grant);check(count($tools)===9,'closed write allowlist');check(!in_array('cluster_pair_approve',array_column($tools,'name'),true),'cluster writes never inherited');check(!property_exists($tools[0]['inputSchema']['properties'],'password'),'password absent from schema');
foreach([['password'=>'secret'],['node'=>'Nitro'],['apply'=>'true'],['domain'=>5],['unexpected'=>true]] as $bad)rejects(fn()=>WriteMcp::call($client,$grant,'mail_domain_create',array_replace($args,$bad),$id),'unsafe arguments denied');
rejects(fn()=>WriteMcp::call($client,$grant,'cluster_pair_approve',$args,$id),'unknown write denied');
$noDns=['scope'=>'musedock:read musedock:write'];rejects(fn()=>WriteMcp::call($client,$noDns,'dns_record_set',$args,$id),'dns needs explicit grant');
MuseDockPanel\Settings::$values['mcp_allow_dns']='0';rejects(fn()=>WriteMcp::call($client,$grant,'dns_record_set',$args,$id),'global DNS guard');MuseDockPanel\Settings::$values['mcp_allow_dns']='1';
MuseDockPanel\Settings::$values['mcp_allow_write']='0';rejects(fn()=>WriteMcp::call($client,$grant,'mail_domain_create',$args,$id),'global write guard');MuseDockPanel\Settings::$values['mcp_allow_write']='1';
$plan=WriteMcp::call($client,$grant,'mail_domain_create',$args,$id);check(McpTools::$applied===0 && count($r->clientStore($id)->pendingOperations())===0,'preview does not queue or execute');
$enqueue=function()use($r,$id,&$grant,$args){return json_decode(WriteMcp::call($r->clients()[$id],$grant,'mail_domain_create',$args+['apply'=>true],$id)['content'][0]['text'],true)['request_id'];};
$request=$enqueue();check(McpTools::$applied===0,'apply true cannot execute from MCP');check(count($r->clientStore($id)->pendingOperations())===1,'request visible in private queue');
WriteMcp::decide($r,$id,$request,true,7);check(McpTools::$applied===1,'private approval executes once');rejects(fn()=>WriteMcp::decide($r,$id,$request,true,7),'approval replay denied');
$request=$enqueue();WriteMcp::decide($r,$id,$request,false,7);check(McpTools::$applied===1,'reject does not execute');
$request=$enqueue();McpTools::$revision++;rejects(fn()=>WriteMcp::decide($r,$id,$request,true,7),'changed plan denied');check(McpTools::$applied===1,'changed plan cannot execute');
$request=$enqueue();MuseDockPanel\Database::$active=false;rejects(fn()=>WriteMcp::decide($r,$id,$request,true,7),'revoked originating admin denied');MuseDockPanel\Database::$active=true;
$request=$enqueue();$r->clientStore($id)->revokeFamily($grant['family']);rejects(fn()=>WriteMcp::decide($r,$id,$request,true,7),'revoked consent blocks pending approval');
$t=token($r,$id,'musedock:read musedock:write musedock:dns');$grant=$r->server($id)->verify($t['access_token']);$request=$enqueue();$r->setPermissions($id,true,false,false);check(!$r->clientStore($id)->pendingOperations(),'permission downgrade deletes queued requests');rejects(fn()=>WriteMcp::decide($r,$id,$request,true,7),'removed permission cannot execute');
$r->setPermissions($id,true,true,true);$t=token($r,$id,'musedock:read musedock:write musedock:dns');$grant=$r->server($id)->verify($t['access_token']);$request=$enqueue();$db=new PDO('sqlite:'.$tmp.'/storage/direct-mcp/'.$id.'.sqlite');$query=$db->prepare("UPDATE grants SET expires=? WHERE hash=? AND kind='write_operation'");$query->execute([time()-1,$request]);rejects(fn()=>WriteMcp::decide($r,$id,$request,true,7),'expired approval denied');
$request=$enqueue();MuseDockPanel\Settings::$values['mcp_allow_write']='0';rejects(fn()=>WriteMcp::decide($r,$id,$request,true,7),'global write disabled after enqueue prevents execution');MuseDockPanel\Settings::$values['mcp_allow_write']='1';
$other=$r->register('Other','anthropic','https://claude.ai/api/mcp/auth_callback');$request=$enqueue();rejects(fn()=>WriteMcp::decide($r,$other['id'],$request,true,7),'approval request isolated by client');WriteMcp::decide($r,$id,$request,false,7);
for($index=0;$index<20;$index++)$enqueue();rejects(fn()=>$enqueue(),'queue bounded at twenty pending requests');
foreach($r->clientStore($id)->pendingOperations() as $pending)WriteMcp::decide($r,$id,$pending['id'],false,7);
$request=$enqueue();McpTools::$fail=true;rejects(fn()=>WriteMcp::decide($r,$id,$request,true,7),'partial failure reported');rejects(fn()=>WriteMcp::decide($r,$id,$request,true,7),'failed operation cannot replay');check(McpTools::$applied===2,'failure attempted exactly once');
echo "OK: $count isolated write/consent/approval checks\n";
} finally { $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($tmp); }
}
