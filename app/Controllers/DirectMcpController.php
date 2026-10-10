<?php
namespace MuseDockPanel\Controllers;
use MuseDockPanel\DirectMcp\{Registry,NetworkPolicy,Gateway,CaddyRoute,PermissionPolicy,WriteMcp,OperationException};
use MuseDockPanel\ChatGpt\{OAuthServer,ReadOnlyMcp};
use MuseDockPanel\{Auth,Database,Flash,View,Settings};
use MuseDockPanel\Services\LogService;

final class DirectMcpController
{
    private function json(int $status,array $data): never
    {
        http_response_code($status);header('Content-Type: application/json');header('Cache-Control: no-store');header('Pragma: no-cache');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
        echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;
    }
    private function activeAdmin(int $id): bool { return (bool)Database::fetchOne("SELECT id FROM panel_admins WHERE id=:id AND is_active=true AND role IN ('admin','superadmin')",['id'=>$id]); }
    private function admin(): void
    {
        if(!Auth::check() || !in_array(Auth::user()['role']??'',['admin','superadmin'],true) || !$this->activeAdmin((int)Auth::user()['id'])){http_response_code(403);exit;}
    }
    public function endpoint(): never
    {
        try {
            $r=new Registry();$path=strtok($_SERVER['REQUEST_URI']??'/','?');$method=$_SERVER['REQUEST_METHOD']??'GET';
            if(!$r->ingress($_SERVER) || !Gateway::path($path) || !$r->enabled() || Settings::get('mcp_enabled','0')!=='1')$this->json(404,['error'=>'Not found']);
            $ip=$_SERVER['HTTP_X_MUSEDOCK_MCP_IP'];
            if(!$r->store->rateLimit($ip,'direct-mcp',240))$this->json(429,['error'=>'temporarily_unavailable']);
            if($method==='GET' && str_starts_with($path,'/.well-known/oauth-protected-resource'))$this->json(200,['resource'=>$r->issuer().'/mcp','authorization_servers'=>[$r->issuer()],'scopes_supported'=>PermissionPolicy::SCOPES,'bearer_methods_supported'=>['header']]);
            if($method==='GET' && $path==='/.well-known/oauth-authorization-server')$this->json(200,['issuer'=>$r->issuer(),'authorization_endpoint'=>$r->issuer().'/oauth/authorize','token_endpoint'=>$r->issuer().'/oauth/token','revocation_endpoint'=>$r->issuer().'/oauth/revoke','response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],'token_endpoint_auth_methods_supported'=>['client_secret_basic'],'code_challenge_methods_supported'=>['S256'],'scopes_supported'=>PermissionPolicy::SCOPES,'authorization_response_iss_parameter_supported'=>true]);
            if($method==='GET' && $path==='/oauth/authorize'){
                $id=(string)($_GET['client_id']??'');$server=$r->server($id);
                if(!$server->enabled())$this->json(400,['error'=>'access_denied']);
                $request=$server->request($_GET);
                header('Location: '.$r->store->get('panel_url').'/settings/mcp/direct/approve?'.http_build_query(['client'=>$id,'request'=>$request]),true,302);exit;
            }
            if($path==='/mcp')$this->mcp($r,$ip);
            if($method!=='POST'){header('Allow: POST');$this->json(405,['error'=>'invalid_request']);}
            if((int)($_SERVER['CONTENT_LENGTH']??0)>16384 || !str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/x-www-form-urlencoded'))$this->json(400,['error'=>'invalid_request']);
            $basic=$_SERVER['HTTP_AUTHORIZATION']??'';$creds=str_starts_with($basic,'Basic ')?base64_decode(substr($basic,6),true):false;
            [$id,$secret]=$creds!==false?array_pad(explode(':',$creds,2),2,''):['',''];$id=urldecode($id);$secret=urldecode($secret);
            if(!isset($r->clients()[$id]))$this->json(401,['error'=>'invalid_client']);
            $s=$r->server($id);
            if(!$r->allowed($id,$ip))$this->json(403,['error'=>'access_denied']);
            if(!$s->enabled() || !$s->authenticateClient($id,$secret)){header('WWW-Authenticate: Basic realm="musedock-oauth"');$this->json(401,['error'=>'invalid_client']);}
            if($path==='/oauth/revoke'){
                $value=(string)($_POST['token']??'');$g=$s->store->find($value,'access')??$s->store->find($value,'refresh')??$s->store->usedRefresh($value);
                if($g && ($g['client_id']??'')===$id)$s->store->revokeFamily($g['family']);$this->json(200,[]);
            }
            if($path!=='/oauth/token')$this->json(404,['error'=>'Not found']);
            $refresh=($_POST['grant_type']??'')==='refresh_token';$value=(string)($_POST[$refresh?'refresh_token':'code']??'');
            if($refresh && ($g=$s->store->usedRefresh($value))){$s->store->revokeFamily($g['family']);$this->json(400,['error'=>'invalid_grant']);}
            $g=$s->store->find($value,$refresh?'refresh':'code');
            if(!$g || !$this->activeAdmin((int)$g['user_id']))$this->json(400,['error'=>'invalid_grant']);
            $this->json(200,$s->exchange($_POST));
        }catch(\Throwable $e){$error=in_array($e->getMessage(),['invalid_client','invalid_request','invalid_scope','invalid_grant','access_denied','unsupported_grant_type'],true)?$e->getMessage():'server_error';$this->json($error==='server_error'?503:400,['error'=>$error]);}
    }
    private function mcp(Registry $r,string $ip): never
    {
        if(!empty($_SERVER['HTTP_ORIGIN']))$this->json(403,['error'=>'Origin not allowed']);
        $header=$_SERVER['HTTP_AUTHORIZATION']??'';$token=preg_match('/^Bearer\s+(\S+)$/i',$header,$m)?$m[1]:'';$grant=null;$clientId='';
        foreach($r->clients() as $id=>$client) if($client['enabled'] && $r->allowed($id,$ip)) { $g=$r->server($id)->verify($token);if($g){$grant=$g;$clientId=$id;break;} }
        if(!$grant || !$this->activeAdmin((int)$grant['user_id'])){header('WWW-Authenticate: Bearer resource_metadata="'.$r->issuer().'/.well-known/oauth-protected-resource/mcp", scope="musedock:read"');$this->json(401,['error'=>'Unauthorized']);}
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');$this->json(405,['error'=>'Method not allowed']);}
        if((int)($_SERVER['CONTENT_LENGTH']??0)>1048576)$this->json(413,['error'=>'Request too large']);
        $raw=file_get_contents('php://input',false,null,0,1048577);if(strlen($raw)>1048576)$this->json(413,['error'=>'Request too large']);
        $msg=json_decode($raw,true);if(!is_array($msg) || array_is_list($msg))$this->json(400,['error'=>'Invalid JSON-RPC request']);
        // Every write is scope-gated and queued for private administrator approval.
        $response=PermissionPolicy::dispatch($r->clients()[$clientId],$grant,$msg,$clientId);$r->clientStore($clientId)->set('last_used',gmdate('c'));
        if($response===null){http_response_code(202);exit;}$this->json(200,$response);
    }
    public function approve(): void
    {
        $this->admin();$r=new Registry();$id=(string)($_GET['client']??$_POST['client']??'');$request=(string)($_GET['request']??$_POST['request']??'');
        try{$s=$r->server($id);$g=$s->store->find($request,'request');if(!$r->enabled() || !$s->enabled() || !$g)throw new \RuntimeException();}catch(\Throwable){http_response_code(400);echo 'Solicitud OAuth caducada o no válida.';return;}
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
            if(!View::verifyCsrf()){http_response_code(403);exit;}
            $url=$s->approve($request,(int)Auth::user()['id'],($_POST['decision']??'')==='approve');
            LogService::log('mcp.oauth','consent','Consentimiento OAuth para cliente '.$id.' ámbitos '.($g['scope']??OAuthServer::SCOPE));header('Referrer-Policy: no-referrer');header('Location: '.$url,true,302);exit;
        }
        View::render('settings/direct-mcp-consent',['layout'=>'main','pageTitle'=>'Autorizar cliente MCP','client'=>$r->clients()[$id],'clientId'=>$id,'requestId'=>$request,'requestedScopes'=>explode(' ',$g['scope']??OAuthServer::SCOPE)]);
    }
    public function save(): void
    {
        $this->admin();if(!View::verifyCsrf()){http_response_code(403);exit;}
        try{
            $r=new Registry();$action=(string)($_POST['action']??'');
            if($action==='configure')$r->configure(rtrim(trim((string)($_POST['issuer']??'')),'/'),rtrim(trim((string)($_POST['panel_url']??'')),'/'));
            elseif($action==='register'){
                $cidrs=preg_split('/\s+/',trim((string)($_POST['cidrs']??'')),-1,PREG_SPLIT_NO_EMPTY);
                $_SESSION['direct_mcp_credentials']=$r->register(trim((string)($_POST['name']??'')),(string)($_POST['provider']??''),trim((string)($_POST['redirect_uri']??'')),$cidrs);
            }elseif($action==='permissions'){
                $r->setPermissions((string)($_POST['client']??''),($_POST['allow_read']??'')==='1',($_POST['allow_write']??'')==='1',($_POST['allow_dns']??'')==='1');
            }elseif($action==='approve_write' || $action==='reject_write'){
                WriteMcp::decide($r,(string)($_POST['client']??''),(string)($_POST['request']??''),$action==='approve_write',(int)Auth::user()['id']);
            }elseif($action==='refresh'){
                $r->refresh((string)($_POST['provider']??''));
                if($r->enabled())CaddyRoute::ensure($r);
            }elseif($action==='disconnect'){
                $id=(string)($_POST['client']??'');$r->disconnect($id===''?null:$id);CaddyRoute::ensure($r);
            }else throw new \RuntimeException('Acción no válida');
            LogService::log('mcp.direct','settings','Configuración MCP directa actualizada: '.$action);Flash::set('success',in_array($action,['approve_write','reject_write'],true)?'Solicitud procesada.':($action==='permissions'?'Permisos guardados. Si cambiaron, vuelve a autorizar el conector con los ámbitos seleccionados.':'Configuración guardada.')); 
        }catch(\Throwable $e){Flash::set('error',$e instanceof OperationException?$e->getMessage():'No se pudo completar la acción. Revisa las URL, los rangos y la disponibilidad de las fuentes oficiales.');}
        header('Location: /settings/mcp');exit;
    }
}
