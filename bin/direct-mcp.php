#!/usr/bin/env php
<?php
require dirname(__DIR__).'/app/bootstrap.php';
use MuseDockPanel\DirectMcp\{Registry,CaddyRoute};
use MuseDockPanel\Services\CloudflareService;
$r=new Registry();$arg=$argv[1]??'--status';
try {
    if($arg==='--status')echo json_encode($r->state(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    elseif($arg==='--refresh'){foreach(['openai','anthropic'] as $p)$r->refresh($p);echo "Fuentes oficiales actualizadas.\n";}
    elseif($arg==='--export')echo json_encode(CaddyRoute::build($r),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    elseif($arg==='--disconnect'){$r->disconnect();CaddyRoute::ensure($r);echo "Acceso revocado y ruta retirada.\n";}
    elseif($arg==='--activate'){
        // Operator must explicitly supply approval and the verified public origin IP.
        if(($argv[2]??'')!=='--approved')throw new RuntimeException('La activación requiere confirmación previa del propietario y --approved');
        if(\MuseDockPanel\Settings::get('mcp_enabled','0')!=='1')throw new RuntimeException('El MCP está desactivado');
        $ip=$argv[3]??'';if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Indica la IPv4 pública verificada del servidor');
        $host=parse_url($r->issuer(),PHP_URL_HOST);$zone=CloudflareService::findZoneForDomain($host);if(!$zone)throw new RuntimeException('Zona no disponible en Cloudflare');
        $result=CloudflareService::listRecords($zone['token'],$zone['zone_id'],['name'=>$host]);
        if(!($result['ok']??false))throw new RuntimeException('No se pudo revisar el DNS');
        $records=$result['result']??[];
        if($records){foreach($records as $record)if($record['type']!=='A' || $record['content']!==$ip || !empty($record['proxied']))throw new RuntimeException('El DNS existente debe ser A, apuntar a este servidor y estar sin proxy naranja');}
        else {
            $result=CloudflareService::createRecord($zone['token'],$zone['zone_id'],['type'=>'A','name'=>$host,'content'=>$ip,'ttl'=>300,'proxied'=>false]);
            if(!($result['ok']??false))throw new RuntimeException('Cloudflare no pudo crear el registro DNS');
        }
        foreach(['openai','anthropic'] as $p)$r->refresh($p);
        try{$r->activate();CaddyRoute::ensure($r);}catch(Throwable $e){$r->disconnect();CaddyRoute::ensure($r);throw $e;}
        echo "Ruta MCP activada; comprueba el certificado HTTPS y conecta el cliente OAuth.\n";
    }else throw new RuntimeException('Usa --status, --refresh, --export, --disconnect o --activate --approved IPv4');
}catch(Throwable $e){fwrite(STDERR,'No se completó la operación. '.$e->getMessage().PHP_EOL);exit(1);}
