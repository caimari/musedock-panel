<?php
namespace MuseDockPanel\DirectMcp;
use MuseDockPanel\Mcp\McpTools;
use MuseDockPanel\{Settings,Database};
use MuseDockPanel\Services\LogService;

/** Closed write allowlist. Public calls prepare requests; only private admin approval executes. */
final class WriteMcp
{
    public const TOOLS=['mail_domain_create','mail_mailbox_create','mail_alias_create','mail_domain_alias','mail_dkim_selector','database_create','domain_redirect_create','mail_dns_publish','dns_record_set'];
    public const DNS_TOOLS=['mail_dns_publish','dns_record_set'];
    public static function scopes(string $name): array
    {
        return array_merge(['musedock:read',PermissionPolicy::WRITE],in_array($name,self::DNS_TOOLS,true)?[PermissionPolicy::DNS]:[]);
    }
    public static function permitted(array $client,array $grant,string $name): bool
    {
        return in_array($name,self::TOOLS,true) && !array_diff(self::scopes($name),$client['scopes']??[])
            && !array_diff(self::scopes($name),explode(' ',$grant['scope']??''));
    }
    public static function descriptors(array $client,array $grant): array
    {
        $tools=[];
        foreach(McpTools::listForMcp() as $t) if(self::permitted($client,$grant,$t['name'])) {
            $t['annotations']['idempotentHint']=false;
            $t['securitySchemes']=[['type'=>'oauth2','scopes'=>self::scopes($t['name'])]];
            $t['description'].=' En OAuth directo, apply=true solo deja una solicitud: un administrador debe aprobar el plan en Ajustes → MCP → Conexión directa. Nunca se ejecuta desde el chat.';
            $properties=(array)$t['inputSchema']['properties'];unset($properties['password']);
            $properties['apply']['description']='false: mostrar plan. true: solicitar aprobación en el panel privado, sin ejecutar cambios.';
            $t['inputSchema']['properties']=(object)$properties;$tools[]=$t;
        }
        return $tools;
    }
    private static function guard(array $client,array $grant,string $name,array $args): void
    {
        if(!self::permitted($client,$grant,$name))throw new OperationException('Permiso de escritura insuficiente');
        if(Settings::get('mcp_enabled','0')!=='1' || Settings::get('mcp_allow_write','0')!=='1')throw new OperationException('Las acciones que modifican están desactivadas en el servidor');
        if(in_array($name,self::DNS_TOOLS,true) && Settings::get('mcp_allow_dns','0')!=='1')throw new OperationException('La edición DNS por MCP está desactivada en el servidor');
        $definition=null;foreach(McpTools::listForMcp() as $t)if($t['name']===$name)$definition=$t;
        if(!$definition || !McpTools::isWrite($name))throw new OperationException('Herramienta no autorizada');
        $schema=$definition['inputSchema'];$properties=(array)$schema['properties'];unset($properties['password']);
        foreach($args as $key=>$value){
            if(!isset($properties[$key]))throw new OperationException('Argumento no permitido');
            $type=$properties[$key]['type']??'';
            if(($type==='string' && (!is_string($value)||strlen($value)>4096)) || ($type==='boolean' && !is_bool($value)) || ($type==='integer' && !is_int($value)))throw new OperationException('Tipo de argumento no válido');
            if(isset($properties[$key]['enum']) && !in_array($value,$properties[$key]['enum'],true))throw new OperationException('Valor de argumento no válido');
        }
        foreach($schema['required']??[] as $key)if(!array_key_exists($key,$args))throw new OperationException('Falta un argumento obligatorio');
        if(strlen(json_encode($args,JSON_THROW_ON_ERROR))>16384)throw new OperationException('Solicitud demasiado grande');
    }
    private static function plan(string $name,array $args,string $via): array
    {
        $result=McpTools::call($name,array_replace($args,['apply'=>false]),$via);
        $data=json_decode($result['content'][0]['text']??'',true,32,JSON_THROW_ON_ERROR);
        if(!empty($result['isError']) || !is_array($data) || isset($data['error']) || ($data['status']??'')!=='plan')throw new OperationException('No se pudo preparar un plan válido. Revisa los datos o el estado del recurso.');
        return $data;
    }
    private static function fingerprint(array $plan): string
    {
        $sort=function(&$value)use(&$sort){if(!is_array($value))return;if(!array_is_list($value))ksort($value);foreach($value as &$v)$sort($v);};$sort($plan);
        return hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR));
    }
    public static function call(array $client,array $grant,string $name,array $args,string $clientId): array
    {
        self::guard($client,$grant,$name,$args);
        $plan=self::plan($name,$args,'direct-oauth:'.$clientId);
        if(empty($args['apply']))return self::result($plan);
        $registry=new Registry();$store=$registry->clientStore($clientId);
        if(count($store->pendingOperations())>=20)throw new OperationException('Máximo 20 cambios pendientes por cliente');
        unset($args['apply']);
        $payload=['tool'=>$name,'args'=>$args,'plan'=>$plan,'family'=>$grant['family'],'user_id'=>$grant['user_id'],'scope'=>$grant['scope'],'created_at'=>time()];
        $id=hash('sha256',$store->issue('write_operation',$payload,600));
        LogService::log('mcp.direct.write.request',$name,'Cambio pendiente para cliente '.$clientId.' solicitud '.$id);
        return self::result(['status'=>'pendiente_de_aprobacion','request_id'=>$id,'plan'=>$plan,'next'=>'Aprueba o rechaza el plan en el panel privado: Ajustes → MCP → Conexión directa. Caduca en 10 minutos.']);
    }
    public static function decide(Registry $r,string $clientId,string $requestId,bool $approve,int $adminId): void
    {
        // Consume before execution: a retry cannot apply twice, even after partial failure.
        $store=$r->clientStore($clientId);$p=$store->consumeOperation($requestId);
        if(!$p)throw new OperationException('Solicitud caducada o ya procesada');
        if(!$approve){LogService::log('mcp.direct.write.rejected',$p['tool'],'Cliente '.$clientId.' solicitud '.$requestId.' administrador '.$adminId);return;}
        $client=$r->clients()[$clientId];
        if(!$r->enabled() || !$client['enabled'] || !$store->activeFamily($p['family'],(int)$p['user_id'],$p['scope'])
            || !Database::fetchOne("SELECT id FROM panel_admins WHERE id=:id AND is_active=true AND role IN ('admin','superadmin')",['id'=>(int)$p['user_id']]))throw new OperationException('El consentimiento ya no es válido. Conecta y solicita el cambio otra vez.');
        self::guard($client,$p,$p['tool'],$p['args']);
        $plan=self::plan($p['tool'],$p['args'],'direct-oauth-review:'.$clientId);
        if(!hash_equals(self::fingerprint($p['plan']),self::fingerprint($plan)))throw new OperationException('El plan ha cambiado. Solicita un plan nuevo antes de aprobar.');
        LogService::log('mcp.direct.write.approved',$p['tool'],'Cliente '.$clientId.' solicitud '.$requestId.' administrador '.$adminId);
        $result=McpTools::call($p['tool'],$p['args']+['apply'=>true],'direct-oauth-approved:'.$clientId.':'.$adminId);
        $data=json_decode($result['content'][0]['text']??'',true);
        if(!empty($result['isError']) || !is_array($data) || isset($data['error'])){LogService::log('mcp.direct.write.failed',$p['tool'],'Solicitud '.$requestId);throw new OperationException('La operación falló y puede haberse aplicado parcialmente. Revisa el recurso y el log antes de solicitar otro cambio.');}
        LogService::log('mcp.direct.write.completed',$p['tool'],'Solicitud '.$requestId);
    }
    public static function result(array $data,bool $error=false): array {return ['isError'=>$error,'content'=>[['type'=>'text','text'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]]];}
}
