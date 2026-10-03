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
        $caddy = CaddyDomainsService::classify();
        foreach (($caddy['domains'] ?? []) as $cd) {
            $n = (string)$cd['host'];
            if (isset($moved[$n]) || $follows($n)) {
                continue;
            }
            // Sin DNS no hay nada que mover (dominio caducado, sin www…): aparte.
            if (!str_starts_with($n, '*.') && !@dns_get_record($n, DNS_A) && !@dns_get_record($n, DNS_CNAME)) {
                $caddyNoDns[] = ['name' => $n, 'why' => (string)$cd['group']];
                continue;
            }
            $caddyNotMoved[] = ['name' => $n, 'why' => "{$cd['group']}: {$cd['detail']} — " . ($inZones($n)
                ? 'su zona está en estas cuentas, pero no apunta a este servidor'
                : 'su DNS no está en las cuentas de Cloudflare del panel')];
        }

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
