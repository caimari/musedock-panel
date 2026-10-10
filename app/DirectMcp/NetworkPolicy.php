<?php
namespace MuseDockPanel\DirectMcp;

/** Provider IPs are an additional restriction, never an authentication method. */
final class NetworkPolicy
{
    public const SOURCES = [
        'openai' => 'https://openai.com/chatgpt-connectors.json',
        'anthropic' => 'https://platform.claude.com/docs/en/api/ip-addresses.md',
    ];
    public static function validCidr(string $cidr): bool
    {
        $p = explode('/', $cidr);
        if (count($p) !== 2 || !ctype_digit($p[1]) || !filter_var($p[0], FILTER_VALIDATE_IP)) return false;
        $bits = strlen(inet_pton($p[0])) * 8;
        // A provider list must not accidentally authorize the whole Internet.
        return (int)$p[1] >= ($bits === 32 ? 8 : 16) && (int)$p[1] <= $bits;
    }
    public static function contains(string $ip, string $cidr): bool
    {
        if (!self::validCidr($cidr) || !filter_var($ip, FILTER_VALIDATE_IP)) return false;
        [$net, $bits] = explode('/', $cidr); $a = inet_pton($ip); $b = inet_pton($net);
        if (strlen($a) !== strlen($b)) return false;
        $bytes = intdiv((int)$bits, 8); $rest = (int)$bits % 8;
        return substr($a, 0, $bytes) === substr($b, 0, $bytes)
            && (!$rest || (ord($a[$bytes]) & (255 << (8-$rest))) === (ord($b[$bytes]) & (255 << (8-$rest))));
    }
    public static function parse(string $provider, string $body): array
    {
        $cidrs = [];
        if ($provider === 'openai') {
            $d = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (empty($d['creationTime']) || !is_array($d['prefixes'] ?? null)) throw new \RuntimeException('Formato IP OpenAI no válido');
            foreach ($d['prefixes'] as $p) foreach (['ipv4Prefix','ipv6Prefix'] as $k) if (isset($p[$k])) $cidrs[] = $p[$k];
        } elseif ($provider === 'anthropic') {
            // Only the outbound section; deliberately exclude inbound and retired addresses.
            if (!preg_match('/^## Outbound IP addresses\s*\n(.*?)(?=^### Phased out|^## |\z)/ms', $body, $m)) throw new \RuntimeException('Formato IP Anthropic no válido');
            preg_match_all('/`([a-fA-F0-9:.]+\/\d{1,3})`/', $m[1], $matches); $cidrs = $matches[1];
        } else throw new \RuntimeException('Proveedor desconocido');
        return self::validate($cidrs);
    }
    public static function validate(array $cidrs): array
    {
        if (!$cidrs || count($cidrs) > 4096) throw new \RuntimeException('Lista IP vacía o demasiado grande');
        foreach ($cidrs as $cidr) if (!is_string($cidr) || !self::validCidr($cidr)) throw new \RuntimeException('Rango IP no válido');
        $cidrs = array_values(array_unique($cidrs)); sort($cidrs); return $cidrs;
    }
    public static function fetch(string $provider): array
    {
        if (!isset(self::SOURCES[$provider])) throw new \RuntimeException('Proveedor desconocido');
        $body = ''; $c = curl_init(self::SOURCES[$provider]);
        curl_setopt_array($c, [CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_PROXY=>'', CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_WRITEFUNCTION=>static function($c, $data) use (&$body) { if (strlen($body)+strlen($data)>1048576) return 0; $body.=$data; return strlen($data); }]);
        $ok = curl_exec($c); $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
        if (!$ok || $status !== 200) throw new \RuntimeException('No se pudo actualizar la fuente oficial');
        return ['cidrs'=>self::parse($provider, $body), 'source'=>self::SOURCES[$provider], 'checked_at'=>time(), 'sha256'=>hash('sha256',$body)];
    }
}
