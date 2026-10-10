<?php
namespace MuseDockPanel\DirectMcp;
use MuseDockPanel\ChatGpt\{OAuthServer,ReadOnlyMcp};

final class PermissionPolicy
{
    public const WRITE='musedock:write';
    public const DNS='musedock:dns';
    public const SCOPES=[OAuthServer::SCOPE,self::WRITE,self::DNS];
    public static function dispatch(array $client,array $grant,mixed $message,string $clientId): ?array
    {
        $granted=explode(' ',$grant['scope']??'');
        if(!in_array(OAuthServer::SCOPE,$client['scopes']??[],true) || !in_array(OAuthServer::SCOPE,$granted,true) || array_diff($granted,$client['scopes']??[]) || array_diff($granted,self::SCOPES))
            return ['jsonrpc'=>'2.0','id'=>is_array($message)?($message['id']??null):null,'error'=>['code'=>-32001,'message'=>'Permiso insuficiente']];
        if(is_array($message) && ($message['jsonrpc']??'')==='2.0' && array_key_exists('id',$message)) {
            $method=$message['method']??'';
            if($method==='tools/call' && in_array($message['params']['name']??'',WriteMcp::TOOLS,true)) {
                try {
                    $args=$message['params']['arguments']??[];
                    if(!is_array($args) || ($args && array_is_list($args)))throw new OperationException('Argumentos no válidos');
                    $result=WriteMcp::call($client,$grant,$message['params']['name'],$args,$clientId);
                }catch(\Throwable $e){$result=WriteMcp::result(['error'=>$e instanceof OperationException?$e->getMessage():'No se pudo preparar la operación'],true);}
                return ['jsonrpc'=>'2.0','id'=>$message['id'],'result'=>$result];
            }
        }
        $result=ReadOnlyMcp::handle($message,'direct-oauth:'.$clientId);
        if(($message['method']??'')==='tools/list' && isset($result['result']['tools']))$result['result']['tools']=array_merge($result['result']['tools'],WriteMcp::descriptors($client,$grant));
        if(($message['method']??'')==='initialize' && isset($result['result']) && in_array(self::WRITE,$granted,true))$result['result']['instructions']='MuseDock permite consultas y cambios autorizados. Las herramientas de escritura solo preparan planes y solicitudes: apply=true nunca ejecuta el cambio desde el chat. Un administrador debe aprobarlo en el panel privado. No pidas ni envíes contraseñas. Las escrituras se ejecutan en el servidor conectado, sin reenvío entre nodos.';
        if(($result['error']['code']??null)===-32603)$result['error']['message']='Error interno al consultar el servidor';
        return $result;
    }
}
