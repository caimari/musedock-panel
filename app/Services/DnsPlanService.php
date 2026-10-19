<?php
namespace MuseDockPanel\Services;

/**
 * Plan de DNS de un relevo o cambio de rol (solo lectura): qué registros cambiaría
 * de una IP pública a otra en las cuentas de Cloudflare de este panel, qué se mueve
 * con ellos por CNAME, qué nombres de máquina se quedan quietos y qué dominios
 * (hostings y todo lo que sirve Caddy) NO se moverían porque su DNS no está en esas
 * cuentas. Lo usan el MCP (failover_dns_plan), el botón de cambio de rol y su correo.
 *
 * Cada zona se lee UNA vez (todos sus registros) en vez de una consulta por tipo y
 * por IP: con muchas zonas el plan tardaba más que la espera del MCP.
 */
class DnsPlanService
{
    /**
     * @param array $pairs [['from' => ip, 'from_name' => .., 'to' => ip, 'to_name' => ..], …]
     */
    public static function plan(array $pairs): array
    {
        $cf = CloudflareService::class;
        $accounts = $cf::getConfiguredAccounts();
        if (!$pairs) {
            return ['ok' => false, 'error' => 'No hay servidor primario con servidor de relevo.'];
        }
        if (!$accounts) {
            return ['ok' => false, 'error' => 'Este panel no tiene cuentas de Cloudflare: no podría cambiar ningún DNS.'];
        }
        $fromIps = array_fill_keys(array_column($pairs, 'from'), true);
        $errors = [];
        $cnames = [];      // nombre → destino, de todas las zonas
        $zoneNames = [];
        $aByZone = [];     // registros A que apuntan a una IP "from", por zona
        foreach ($accounts as $acct) {
            foreach (($acct['zones'] ?? []) as $zone) {
                $zoneNames[strtolower((string)$zone['name'])] = true;
                $r = $cf::listRecordsAll((string)$acct['token'], (string)$zone['id'], []);
                if (!$r['ok']) {
                    $errors[] = "{$zone['name']} ({$acct['name']}): " . ($r['error'] ?? 'error');
                    continue;
                }
                foreach ($r['result'] ?? [] as $x) {
                    $type = (string)($x['type'] ?? '');
                    if ($type === 'CNAME') {
                        $cnames[strtolower((string)$x['name'])] = strtolower(rtrim((string)$x['content'], '.'));
                    } elseif ($type === 'A' && isset($fromIps[(string)$x['content']])) {
                        $aByZone[] = ['zone' => (string)$zone['name'], 'account' => (string)$acct['name'], 'record' => $x];
                    }
                }
            }
        }
        // La regla de nombres de máquina necesita los destinos de CNAME (uno al que
        // apuntan webs por CNAME sí se mueve).
        $cf::primeCnameTargets(array_fill_keys(array_values($cnames), true));

        $zones = [];
        $moved = [];
        $machineNames = [];
        $total = 0;
        foreach ($aByZone as $z) {
            $x = $z['record'];
            $name = (string)$x['name'];
            if ($cf::isFailoverExcluded($name)) {
                $machineNames[] = $name;
                continue;
            }
            $moved[strtolower($name)] = true;
            $zones[$z['zone']]['zone'] = $z['zone'];
            $zones[$z['zone']]['account'] = $z['account'];
            $zones[$z['zone']]['records'][] = $name . (!empty($x['proxied']) ? ' (proxy)' : '');
            $total++;
        }

        // Lo que va por CNAME a un nombre que cambia, también se mueve (aunque no se toque).
        $follows = static function (string $n) use ($cnames, $moved): bool {
            for ($i = 0; $i < 10 && isset($cnames[$n]); $i++) {
                $n = $cnames[$n];
                if (isset($moved[$n])) {
                    return true;
                }
            }
            return false;
        };
        $viaCname = array_keys(array_filter($cnames, static fn($t, $n) => $follows($n), ARRAY_FILTER_USE_BOTH));
        sort($viaCname);
        $inZones = static function (string $n) use ($zoneNames): bool {
            $parts = explode('.', ltrim($n, '*.'));
            for ($i = 0; $i < count($parts) - 1; $i++) {
                if (isset($zoneNames[implode('.', array_slice($parts, $i))])) {
                    return true;
                }
            }
            return false;
        };

        // Dominios de los hostings de este panel que NO se moverían.
        $notMoved = [];
        foreach (FailoverService::getLocalDomains() as $d) {
            $d = strtolower((string)$d);
            foreach ([$d, "www.{$d}"] as $n) {
                if (isset($moved[$n]) || $follows($n)) {
                    continue 2;
                }
            }
            $notMoved[] = ['name' => $d, 'why' => $inZones($d)
                ? 'su zona está en estas cuentas, pero no apunta a este servidor (¿otro servidor o registro distinto?)'
                : 'su DNS no está en las cuentas de Cloudflare del panel'];
        }
        // Todo lo que sirve Caddy aquí (hostings, Caddyfile y lo añadido por otras
        // aplicaciones, p. ej. tenants de un CMS). En un slave Caddy solo sirve lo suyo.
        $caddyNotMoved = [];
        $caddyNoDns = [];
        $machineLower = array_map('strtolower', $machineNames);
        $viaCnamePublic = [];
        $caddy = CaddyDomainsService::classify();
        foreach (($caddy['domains'] ?? []) as $cd) {
            $n = (string)$cd['host'];
            if (isset($moved[$n]) || $follows($n)) {
                continue;
            }
            // Nombre de máquina que se queda en su servidor a propósito: no es un aviso (ya sale en machine_names_kept).
            if (in_array(strtolower($n), $machineLower, true)) {
                continue;
            }
            // Zona de otra cuenta, pero con un CNAME visible en el DNS público (sin proxy) que acaba en un nombre que se mueve: se mueve solo.
            if (!$inZones($n) && self::publicCnameFollows($n, $moved)) {
                $viaCnamePublic[] = strtolower($n);
                continue;
            }
            // Sin DNS no hay nada que mover (dominio caducado, sin www…): aparte.
            if (!str_starts_with($n, '*.') && !@dns_get_record($n, DNS_A) && !@dns_get_record($n, DNS_CNAME)) {
                $caddyNoDns[] = ['name' => $n, 'why' => (string)$cd['group']];
                continue;
            }
            $hint = $inZones($n) ? ['kind' => 'other', 'text' => 'su zona está en estas cuentas, pero no apunta a este servidor']
                : self::outsideZoneHint(self::publicIps($n), array_keys($fromIps));
            $caddyNotMoved[] = ['name' => $n, 'why' => "{$cd['group']}: {$cd['detail']} — " . $hint['text'], 'kind' => $hint['kind']];
        }

        $viaCname = array_values(array_unique(array_merge($viaCname, $viaCnamePublic)));
        sort($viaCname);

        return [
            'ok' => !$errors,
            'switch' => array_map(static fn($p) => "{$p['from_name']} {$p['from']} → {$p['to_name']} {$p['to']}", $pairs),
            'records_that_would_change' => $total,
            'zones' => array_values(array_map(static fn($z) => $z + ['records' => []], $zones)),
            'moved_names' => array_keys($moved),
            'moved_via_cname' => $viaCname,
            'machine_names_kept' => array_values(array_unique($machineNames)),
            'machine_names_rule' => 'No se mueven nunca (nombres de máquina, deducidos de los hostnames y nodos del cluster; se pueden añadir en failover_dns_exclude), salvo que otras webs apunten a ellos por CNAME: '
                . implode(', ', $cf::machineNameLabels()),
            'hosting_domains_not_moved' => $notMoved,
            'caddy_domains_checked' => count($caddy['domains'] ?? []),
            'caddy_domains_not_moved' => $caddyNotMoved,
            'caddy_domains_without_dns' => $caddyNoDns,
            'zone_errors' => $errors,
            'note' => 'Cambian los registros A que apuntan exactamente a la IP de origen, en las zonas de las cuentas de Cloudflare de ESTE panel; lo que va por CNAME a esos nombres se mueve con ellos. Un dominio cuyo DNS esté en otra cuenta u otro proveedor no cambiaría.',
        ];
    }

    /**
     * ¿Este nombre es (en el DNS público) un CNAME que acaba, en pocos saltos, en un nombre que se mueve? Sirve para dominios cuya zona
     * está en otra cuenta de Cloudflare: si no llevan proxy su CNAME se ve desde fuera. Con proxy (nube naranja) el DNS público solo da IPs
     * de Cloudflare y no se puede saber. $resolver(nombre) devuelve el destino o null (inyectable para probar).
     */
    public static function publicCnameFollows(string $name, array $moved, ?callable $resolver = null): bool
    {
        $resolver ??= static function (string $n): ?string {
            $r = @dns_get_record($n, DNS_CNAME);
            return !empty($r[0]['target']) ? strtolower(rtrim((string)$r[0]['target'], '.')) : null;
        };
        $n = strtolower($name);
        for ($i = 0; $i < 10; $i++) {
            $t = $resolver($n);
            if ($t === null || $t === '') {
                return false;
            }
            if (isset($moved[$t])) {
                return true;
            }
            $n = $t;
        }
        return false;
    }

    /** Rangos de IPv4 de Cloudflare (https://www.cloudflare.com/ips-v4). */
    private const CF_RANGES = ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14',
        '172.64.0.0/13', '131.0.72.0/22'];

    /** ¿Es una IPv4 de Cloudflare? Pura. */
    public static function isCloudflareIp(string $ip): bool
    {
        $l = ip2long($ip);
        if ($l === false) {
            return false;
        }
        foreach (self::CF_RANGES as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = $bits == 0 ? 0 : (~0 << (32 - (int)$bits)) & 0xFFFFFFFF;
            if (($l & $mask) === (ip2long($net) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** IPv4 que da el DNS público para un nombre. */
    private static function publicIps(string $name): array
    {
        $out = [];
        foreach ((array)@dns_get_record($name, DNS_A) as $r) {
            if (!empty($r['ip'])) {
                $out[] = (string)$r['ip'];
            }
        }
        return $out;
    }

    /**
     * Qué decir de un dominio cuya zona NO está en las cuentas de Cloudflare del panel, según lo que da su DNS público.
     * apunta a la IP de origen = problema real (no se moverá); detrás de Cloudflare con proxy = su destino no se ve desde aquí
     * (si es un CNAME a un nombre que se mueve, irá con él). Pura. @return array{kind:string,text:string}
     */
    public static function outsideZoneHint(array $ips, array $originIps): array
    {
        foreach ($ips as $ip) {
            if (in_array($ip, $originIps, true)) {
                return ['kind' => 'points_here', 'text' => "apunta directamente a la IP de este servidor ({$ip}) y su zona no está en las cuentas del panel: NO se moverá solo. "
                    . 'Cambia su DNS a mano, o dale a este panel acceso a su zona'];
            }
        }
        if ($ips && count(array_filter($ips, [self::class, 'isCloudflareIp'])) === count($ips)) {
            return ['kind' => 'behind_cloudflare', 'text' => 'va por Cloudflare con proxy y su zona está en otra cuenta: desde aquí no se ve a qué apunta. '
                . 'Si es un CNAME a un nombre que se mueve (p. ej. el de un servicio de este servidor), irá con él; si es un A a la IP de este servidor, no'];
        }
        return ['kind' => 'unknown', 'text' => 'su DNS no está en las cuentas de Cloudflare del panel'];
    }

    /** ¿Se mueve este nombre (directamente o por CNAME) según un plan? */
    public static function nameMoves(array $plan, string $name): bool
    {
        $name = strtolower(rtrim($name, '.'));
        return in_array($name, $plan['moved_names'] ?? [], true) || in_array($name, $plan['moved_via_cname'] ?? [], true);
    }

    /** Texto llano del plan para un correo. */
    public static function reportText(array $plan, array $movedNow = [], array $failedNow = []): string
    {
        $out = [];
        if ($movedNow || $failedNow) {
            $out[] = 'REGISTROS CAMBIADOS (' . count($movedNow) . '):';
            foreach ($movedNow as $n) {
                $out[] = "  · {$n}";
            }
            if ($failedNow) {
                $out[] = '';
                $out[] = 'NO SE PUDIERON CAMBIAR (' . count($failedNow) . ') — revisar a mano:';
                foreach ($failedNow as $n) {
                    $out[] = "  · {$n}";
                }
            }
        } else {
            $out[] = 'REGISTROS QUE CAMBIAN (' . (int)($plan['records_that_would_change'] ?? 0) . '):';
            foreach ($plan['zones'] ?? [] as $z) {
                $out[] = "  · {$z['zone']}: " . implode(', ', $z['records']);
            }
        }
        $sec = static function (string $title, array $items) use (&$out): void {
            if (!$items) {
                return;
            }
            $out[] = '';
            $out[] = $title . ' (' . count($items) . '):';
            foreach ($items as $i) {
                $out[] = '  · ' . (is_array($i) ? $i['name'] . (($i['why'] ?? '') !== '' ? " — {$i['why']}" : '') : $i);
            }
        };
        $sec('SE MUEVEN CON ELLOS POR CNAME', $plan['moved_via_cname'] ?? []);
        $sec('NOMBRES DE MÁQUINA QUE SE QUEDAN EN SU SERVIDOR', $plan['machine_names_kept'] ?? []);
        $sec('HOSTINGS QUE NO SE HAN PODIDO MOVER (DNS fuera del alcance del panel)', $plan['hosting_domains_not_moved'] ?? []);
        $sec('OTROS DOMINIOS QUE SIRVE CADDY Y NO SE HAN PODIDO MOVER', $plan['caddy_domains_not_moved'] ?? []);
        $sec('DOMINIOS SIN DNS (nada que mover)', $plan['caddy_domains_without_dns'] ?? []);
        $sec('ZONAS QUE NO SE PUDIERON LEER', $plan['zone_errors'] ?? []);
        return implode("\n", $out);
    }
}
