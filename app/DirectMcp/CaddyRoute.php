<?php
namespace MuseDockPanel\DirectMcp;
use MuseDockPanel\Env;

final class CaddyRoute
{
    public const ID='musedock-direct-mcp';
    public static function build(Registry $r): array
    {
        // Permit a provider to discover OAuth before its exact callback is registered.
        // No client or token is authorized by this network union.
        $cidrs=[];foreach(array_keys(NetworkPolicy::SOURCES) as $provider)$cidrs=array_merge($cidrs,$r->ranges(['provider'=>$provider]));
        foreach($r->clients() as $client)if($client['enabled'])$cidrs=array_merge($cidrs,$r->ranges($client));
        $cidrs=array_values(array_unique($cidrs));
        $proxy=['handler'=>'reverse_proxy','upstreams'=>[['dial'=>'127.0.0.1:'.(int)Env::get('PANEL_INTERNAL_PORT','8445')]],
            'headers'=>['request'=>['set'=>[
                'Host'=>[parse_url($r->issuer(),PHP_URL_HOST)],'X-MuseDock-Mcp-Ingress'=>[$r->store->get('ingress_secret')],
                'X-MuseDock-Mcp-Ip'=>['{http.request.remote.host}'],'X-Real-IP'=>['{http.request.remote.host}'],
                'X-Forwarded-For'=>['{http.request.remote.host}'],'X-Forwarded-Proto'=>['https'],
            ]]]];
        $routes=[['match'=>[['path'=>Gateway::PUBLIC_PATHS]],'handle'=>[['handler'=>'request_body','max_size'=>16384],$proxy],'terminal'=>true]];
        if($cidrs)$routes[]=['match'=>[['path'=>['/mcp'],'remote_ip'=>['ranges'=>$cidrs]]],'handle'=>[['handler'=>'request_body','max_size'=>1048576],$proxy],'terminal'=>true];
        $routes[]=['match'=>[['path'=>['/mcp']]],'handle'=>[['handler'=>'static_response','status_code'=>403]],'terminal'=>true];
        $routes[]=['handle'=>[['handler'=>'static_response','status_code'=>404]],'terminal'=>true];
        return ['@id'=>self::ID,'match'=>[['host'=>[parse_url($r->issuer(),PHP_URL_HOST)]]],
            'handle'=>[['handler'=>'headers','response'=>['set'=>['Cache-Control'=>['no-store'],'Referrer-Policy'=>['no-referrer'],'X-Content-Type-Options'=>['nosniff']]]],['handler'=>'subroute','routes'=>$routes]],'terminal'=>true];
    }
    private static function request(string $method,string $path,?array $body=null,string $etag='',string $api='http://127.0.0.1:2019'): array
    {
        $c=curl_init($api.$path);$received='';$headers=['Content-Type: application/json'];if($etag!=='')$headers[]='If-Match: '.$etag;
        curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$received){if(stripos($line,'ETag:')===0)$received=trim(substr($line,5));return strlen($line);}]);
        if($body!==null)curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        $raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
        return ['status'=>$status,'data'=>json_decode((string)$raw),'etag'=>$received];
    }
    private static function canonical(mixed $value): mixed
    {
        // Preserve JSON objects, especially existing empty matcher objects ({}).
        // PHP associative decoding would turn those into [] and break other sites.
        if(is_object($value)){
            $properties=get_object_vars($value);ksort($properties);
            foreach($properties as $key=>$item)$properties[$key]=self::canonical($item);
            return (object)$properties;
        }
        if(is_array($value)){
            if(array_is_list($value))return array_map([self::class,'canonical'],$value);
            ksort($value);foreach($value as $key=>$item)$value[$key]=self::canonical($item);
            return (object)$value;
        }
        return $value;
    }
    public static function ensure(Registry $r,string $api='http://127.0.0.1:2019'): void
    {
        // Scope is only srv0 routes. Other listeners, including :8444, are never written.
        if(!preg_match('#^http://127\.0\.0\.1:[0-9]{1,5}$#D',$api))throw new \RuntimeException('La API de Caddy debe ser local');
        $path='/config/apps/http/servers/srv0/routes';
        for($attempt=0;$attempt<3;$attempt++){
            $read=self::request('GET',$path,null,'',$api);
            if($read['status']!==200 || !is_array($read['data']) || !$read['etag'])throw new \RuntimeException('No se pudo leer Caddy con ETag');
            $before=$read['data'];$after=array_values(array_filter($before,static fn($route)=>(is_object($route)?($route->{'@id'}??''):($route['@id']??''))!==self::ID));
            if($r->enabled())array_unshift($after,self::build($r));
            if(json_encode(self::canonical($before),JSON_THROW_ON_ERROR)===json_encode(self::canonical($after),JSON_THROW_ON_ERROR))return;
            $write=self::request('PATCH',$path,$after,$read['etag'],$api);
            if($write['status']===412)continue;
            if($write['status']<200 || $write['status']>=300)throw new \RuntimeException('Caddy rechazó la ruta MCP');
            return;
        }
        throw new \RuntimeException('Caddy cambió durante la actualización; vuelve a intentarlo');
    }
    public static function maintain(): void
    {
        if(!is_file(PANEL_ROOT.'/storage/direct-mcp/registry.sqlite'))return;
        $r=new Registry();if(!$r->enabled())return;
        foreach(NetworkPolicy::SOURCES as $provider=>$url){
            $snapshot=json_decode($r->store->get('ranges_'.$provider,'{}'),true);
            if(($snapshot['checked_at']??0)<time()-86400 && (int)$r->store->get('attempt_'.$provider,'0')<time()-3600){try{$r->refresh($provider);$r->store->set('error_'.$provider,'');}catch(\Throwable){$r->store->set('error_'.$provider,'Falló la actualización; se conserva la última lista válida durante un máximo de siete días.');}}
        }
        self::ensure($r); // Restore after reload; expired ranges are omitted (fail closed).
    }
}
