<?php
namespace MuseDockPanel\DirectMcp;
use MuseDockPanel\ChatGpt\{OAuthStore, OAuthServer};

/** Local credentials and grants, isolated from the tunnel and legacy Bearer. */
final class Registry
{
    public readonly OAuthStore $store;
    public function __construct(?string $root = null) { $this->root = $root ?? PANEL_ROOT.'/storage/direct-mcp'; $this->store = new OAuthStore($this->root.'/registry.sqlite'); }
    private string $root;
    public function clients(): array { return json_decode($this->store->get('clients','{}'), true, 32, JSON_THROW_ON_ERROR); }
    public function clientStore(string $id): OAuthStore
    {
        if (!preg_match('/^mdmcp_[a-f0-9]{32}$/D', $id) || !isset($this->clients()[$id])) throw new \RuntimeException('invalid_client');
        $path=$this->root.'/'.$id.'.sqlite';$store=new OAuthStore($path);
        // The panel runs as root; retain the local registry operator's ownership
        // so CLI activation/revocation can still access clients created in the UI.
        $owner=fileowner($this->root.'/registry.sqlite');
        if(function_exists('posix_geteuid') && posix_geteuid()===0 && $owner!==false && fileowner($path)!==$owner) {
            if(!chown($path,$owner))throw new \RuntimeException('No se pudo conservar el propietario del almacenamiento local');
        }
        return $store;
    }
    public function server(string $id): OAuthServer { return new OAuthServer($this->clientStore($id), true, $this->clients()[$id]['scopes']??[]); }
    public function enabled(): bool { return $this->store->get('enabled') === '1'; }
    public function issuer(): string { return $this->store->get('issuer'); }
    public static function callback(string $url): bool
    {
        $p = parse_url($url);
        return strlen($url) <= 2048 && is_array($p) && ($p['scheme']??'') === 'https' && !empty($p['host'])
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment']) && (!isset($p['port']) || $p['port']===443)
            && !filter_var($p['host'], FILTER_VALIDATE_IP) && str_contains($p['host'], '.') && !preg_match('/[\x00-\x20]/', $url);
    }
    public function configure(string $issuer, string $panel): void
    {
        if ($this->enabled()) throw new \RuntimeException('Desconecta antes de cambiar la configuración');
        if (!OAuthServer::httpsUrl($issuer,true) || !OAuthServer::httpsUrl($panel,true)) throw new \RuntimeException('Las URL deben ser orígenes HTTPS');
        $host = parse_url($issuer,PHP_URL_HOST);
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/D', $host) || parse_url($issuer,PHP_URL_PORT) !== null || $issuer === $panel)
            throw new \RuntimeException('Usa un subdominio dedicado en el puerto 443');
        $this->store->set('issuer',$issuer); $this->store->set('panel_url',$panel);
        if (!$this->store->get('ingress_secret')) $this->store->set('ingress_secret',bin2hex(random_bytes(32)));
        foreach ($this->clients() as $id=>$client) { $s=$this->clientStore($id); $s->set('issuer',$issuer); $s->set('resource',$issuer.'/mcp'); $s->set('panel_url',$panel); $s->revokeAll(); }
    }
    public function register(string $name, string $provider, string $callback, array $manualCidrs=[]): array
    {
        if (!$this->issuer()) throw new \RuntimeException('Configura primero el subdominio');
        if (!$name || strlen($name)>100 || !in_array($provider,['openai','anthropic','other'],true) || !self::callback($callback)) throw new \RuntimeException('Revisa el nombre, proveedor y devolución HTTPS exacta');
        if ($provider==='other') $manualCidrs=NetworkPolicy::validate($manualCidrs);
        $clients=$this->clients(); if(count($clients)>=20)throw new \RuntimeException('Máximo 20 clientes');
        $id='mdmcp_'.bin2hex(random_bytes(16)); $secret=bin2hex(random_bytes(32));
        $clients[$id]=['name'=>$name,'provider'=>$provider,'redirect_uri'=>$callback,'enabled'=>true,'cidrs'=>$manualCidrs,'scopes'=>[OAuthServer::SCOPE]];
        $this->store->set('clients',json_encode($clients,JSON_THROW_ON_ERROR));
        $s=$this->clientStore($id);
        foreach(['enabled'=>$this->enabled()?'1':'0','issuer'=>$this->issuer(),'resource'=>$this->issuer().'/mcp','panel_url'=>$this->store->get('panel_url'),'redirect_uri'=>$callback,'client_id'=>$id,'client_secret_hash'=>hash('sha256',$secret)] as $k=>$v)$s->set($k,$v);
        return ['id'=>$id,'secret'=>$secret];
    }
    public function setReadAccess(string $id, bool $allow): void { $this->setPermissions($id,$allow,false,false); }
    /** Any scope change invalidates all previous authorizations and pending operations. */
    public function setPermissions(string $id, bool $read, bool $write, bool $dns): void
    {
        $clients=$this->clients();
        if (!isset($clients[$id]) || !$clients[$id]['enabled']) throw new \RuntimeException('Cliente revocado o no válido');
        if (($write && !$read) || ($dns && !$write)) throw new OperationException('La escritura requiere lectura; DNS requiere escritura');
        $scopes=$read?[OAuthServer::SCOPE]:[];
        if ($write) $scopes[]=PermissionPolicy::WRITE;
        if ($dns) $scopes[]=PermissionPolicy::DNS;
        if (($clients[$id]['scopes']??[])===$scopes) return;
        $store=$this->clientStore($id);$store->set('enabled','0');$store->revokeAll();
        $clients[$id]['scopes']=$scopes;
        $this->store->set('clients',json_encode($clients,JSON_THROW_ON_ERROR));
        if ($read && $this->enabled()) $store->set('enabled','1');
    }
    public function disconnect(?string $id=null): void
    {
        $clients=$this->clients();
        if ($id!==null && !isset($clients[$id])) throw new \RuntimeException('Cliente no válido');
        foreach ($clients as $key=>&$client) if ($id===null || $id===$key) { $s=$this->clientStore($key); $s->set('enabled','0'); $s->revokeAll(); if($id!==null)$client['enabled']=false; }
        unset($client); $this->store->set('clients',json_encode($clients,JSON_THROW_ON_ERROR)); if($id===null)$this->store->set('enabled','0');
    }
    public function activate(): void
    {
        if (!$this->issuer() || !$this->store->get('ingress_secret')) throw new \RuntimeException('Falta configuración');
        foreach(array_keys(NetworkPolicy::SOURCES) as $provider)if(!$this->ranges(['provider'=>$provider]))throw new \RuntimeException('Faltan rangos oficiales actuales para el descubrimiento');
        foreach ($this->clients() as $id=>$client) if ($client['enabled'] && in_array(OAuthServer::SCOPE,$client['scopes']??[],true)) {
            if (!$this->ranges($client)) throw new \RuntimeException('Rangos ausentes o caducados');
            $this->clientStore($id)->set('enabled','1');
        }
        $this->store->set('enabled','1');
    }
    public function refresh(string $provider): void
    {
        $this->store->set('attempt_'.$provider,(string)time());
        $snapshot=NetworkPolicy::fetch($provider); // Validate fully before replacing the last good snapshot.
        $this->store->set('ranges_'.$provider,json_encode($snapshot,JSON_THROW_ON_ERROR));
    }
    public function ranges(array $client): array
    {
        if ($client['provider']==='other') return $client['cidrs'];
        $snapshot=json_decode($this->store->get('ranges_'.$client['provider'],'{}'),true);
        return !empty($snapshot['checked_at']) && $snapshot['checked_at']>time()-604800 ? ($snapshot['cidrs']??[]) : [];
    }
    public function allowed(string $id,string $ip): bool
    {
        $client=$this->clients()[$id]??null;
        if (!$this->enabled() || !$client || !$client['enabled'] || !in_array(OAuthServer::SCOPE,$client['scopes']??[],true)) return false;
        foreach($this->ranges($client) as $cidr)if(NetworkPolicy::contains($ip,$cidr))return true;
        return false;
    }
    public function ingress(array $server): bool
    {
        $secret=$this->store->get('ingress_secret');
        return $secret!=='' && in_array($server['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)
            && ($server['HTTP_HOST']??'')===parse_url($this->issuer(),PHP_URL_HOST)
            && hash_equals($secret,(string)($server['HTTP_X_MUSEDOCK_MCP_INGRESS']??''))
            && filter_var($server['HTTP_X_MUSEDOCK_MCP_IP']??'',FILTER_VALIDATE_IP)!==false;
    }
    public static function settings(): array
    {
        try { return (new self())->state(); } catch (\Throwable) { return ['enabled'=>false,'issuer'=>'','panel_url'=>'','clients'=>[],'ranges'=>[]]; }
    }
    public function state(): array
    {
        $ranges=[]; foreach(NetworkPolicy::SOURCES as $p=>$url){$d=json_decode($this->store->get('ranges_'.$p,'{}'),true);$ranges[$p]=['count'=>count($d['cidrs']??[]),'checked_at'=>$d['checked_at']??0,'source'=>$url];}
        return ['enabled'=>$this->enabled(),'issuer'=>$this->issuer(),'panel_url'=>$this->store->get('panel_url'),'clients'=>$this->clients(),'ranges'=>$ranges];
    }
}
