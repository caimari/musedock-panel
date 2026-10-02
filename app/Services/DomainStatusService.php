<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * Estado de TODOS los dominios de este servidor en un sitio: registro (RDAP:
 * activo, en redención, libre, caducidad y registrador), DNS (apunta aquí, a
 * Cloudflare, a otro sitio o a nada) y dónde se usa (hosting del panel, CMS u
 * otra aplicación vía Caddy, Caddyfile, correo). Solo lectura.
 *
 * El registro se consulta por RDAP (el sustituto oficial de whois) y se guarda
 * 24 h en domains_rdap_cache; por llamada se hacen como mucho MAX_LOOKUPS
 * consultas nuevas para no tardar ni abusar del servicio.
 */
final class DomainStatusService
{
    private const CACHE = 'domains_rdap_cache';
    private const TTL = 86400;
    private const MAX_LOOKUPS = 40;
    /** Sufijos de dos niveles habituales (lista corta; el resto se trata como TLD de un nivel). */
    private const TWO_LEVEL = ['co.uk', 'org.uk', 'me.uk', 'com.es', 'org.es', 'com.mx', 'com.ar', 'com.br', 'com.au', 'co.nz', 'com.co'];

    public static function baseDomain(string $host): string
    {
        $h = strtolower(trim(ltrim($host, '*.'), '.'));
        $p = explode('.', $h);
        $n = count($p);
        if ($n <= 2) {
            return $h;
        }
        $last2 = $p[$n - 2] . '.' . $p[$n - 1];
        return in_array($last2, self::TWO_LEVEL, true) ? implode('.', array_slice($p, -3)) : $last2;
    }

    public static function report(bool $refresh = false): array
    {
        // ¿Dónde se usa cada dominio base?
        $uses = [];
        $add = static function (string $host, string $what) use (&$uses): void {
            $b = self::baseDomain($host);
            if ($b !== '' && !filter_var($b, FILTER_VALIDATE_IP) && $b !== 'localhost') {
                $uses[$b][$what] = true;
            }
        };
        $caddy = CaddyDomainsService::classify();
        $label = ['panel_hosting' => 'hosting del panel', 'panel_system' => 'servicio del panel', 'caddyfile' => 'Caddyfile', 'external' => 'otra aplicación vía Caddy (p. ej. CMS)'];
        foreach (($caddy['domains'] ?? []) as $d) {
            $add($d['host'], $label[$d['group']] ?? $d['group']);
        }
        try {
            foreach (Database::fetchAll("SELECT domain FROM hosting_accounts WHERE status != 'deleted'") as $r) {
                $add($r['domain'], 'hosting del panel');
            }
            foreach (Database::fetchAll("SELECT domain FROM mail_domains") as $r) {
                $add($r['domain'], 'correo');
            }
        } catch (\Throwable) {
        }
        ksort($uses);

        $cache = json_decode(Settings::get(self::CACHE, '{}'), true) ?: [];
        $now = time();
        $lookups = 0;
        $pending = 0;
        $serverIps = array_filter(preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) ?: []);
        $rows = [];
        foreach ($uses as $base => $u) {
            $c = $cache[$base] ?? null;
            if ($refresh || !$c || $now - (int)($c['at'] ?? 0) > self::TTL) {
                if ($lookups < self::MAX_LOOKUPS) {
                    $c = self::rdap($base) + ['at' => $now];
                    $cache[$base] = $c;
                    $lookups++;
                } elseif (!$c) {
                    $pending++;
                }
            }
            $ips = array_values(array_filter(array_map(static fn($r) => (string)($r['ip'] ?? ''), @dns_get_record($base, DNS_A) ?: [])));
            $dns = !$ips ? 'sin DNS' : (array_intersect($ips, $serverIps) ? 'apunta aquí' : 'apunta a ' . implode(', ', array_slice($ips, 0, 2)));
            $row = ['domain' => $base, 'used_by' => array_keys($u), 'dns' => $dns];
            if ($c) {
                $row += ['registration' => $c['state'], 'expires' => $c['expires'] ?? null, 'registrar' => $c['registrar'] ?? null];
                if (!empty($c['expires'])) {
                    $row['days_left'] = (int)floor((strtotime($c['expires']) - $now) / 86400);
                }
            } else {
                $row['registration'] = 'pendiente de consultar (vuelve a llamar)';
            }
            $rows[] = $row;
        }
        Settings::set(self::CACHE, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $alerts = [];
        foreach ($rows as $r) {
            $reg = (string)$r['registration'];
            if (str_starts_with($reg, 'libre')) {
                $alerts[] = "{$r['domain']}: NO está registrado (cualquiera puede registrarlo) y sigue en uso aquí (" . implode(', ', $r['used_by']) . ').';
            } elseif (str_starts_with($reg, 'en redención') || str_starts_with($reg, 'pendiente de borrado')) {
                $alerts[] = "{$r['domain']}: {$reg}. Solo su titular puede recuperarlo ya, por su registrador (" . ($r['registrar'] ?? '?') . ').';
            } elseif (isset($r['days_left']) && $r['days_left'] < 30) {
                $alerts[] = "{$r['domain']}: caduca en {$r['days_left']} días ({$r['expires']}).";
            } elseif ($r['dns'] === 'sin DNS' && $reg === 'activo') {
                $alerts[] = "{$r['domain']}: registrado pero sin DNS (no resuelve): revisa sus nameservers o su zona.";
            }
        }
        return [
            'total' => count($rows),
            'alerts' => $alerts,
            'domains' => $rows,
            'rdap_lookups_now' => $lookups,
            'pending_lookups' => $pending,
            'note' => 'Registro por RDAP, guardado 24 h (refresh=true para forzar). "used_by" dice dónde se usa en ESTE servidor; ejecútalo en el master para verlo todo.',
        ];
    }

    /** Servidor RDAP de cada TLD según IANA (guardado 7 días). */
    private static function rdapBase(string $tld): ?string
    {
        static $map = null;
        if ($map === null) {
            $cached = json_decode(Settings::get('rdap_bootstrap', '{}'), true) ?: [];
            if (empty($cached['at']) || time() - (int)$cached['at'] > 7 * 86400) {
                [$code, $body] = self::httpGet('https://data.iana.org/rdap/dns.json');
                $j = $code === 200 ? json_decode($body, true) : null;
                if (is_array($j['services'] ?? null)) {
                    $m = [];
                    foreach ($j['services'] as $svc) {
                        foreach ((array)($svc[0] ?? []) as $t) {
                            $m[strtolower($t)] = rtrim((string)($svc[1][0] ?? ''), '/') . '/';
                        }
                    }
                    $cached = ['at' => time(), 'map' => $m];
                    Settings::set('rdap_bootstrap', json_encode($cached, JSON_UNESCAPED_SLASHES));
                }
            }
            $map = (array)($cached['map'] ?? []);
        }
        return $map[strtolower($tld)] ?? null;
    }

    private static function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'MuseDock-Panel/RDAP (+https://musedock.com)',
            CURLOPT_HTTPHEADER => ['Accept: application/rdap+json, application/json']]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    }

    /** Consulta RDAP del dominio base, directamente al registro de su TLD. */
    private static function rdap(string $domain): array
    {
        $tld = substr($domain, strrpos($domain, '.') + 1);
        $base = self::rdapBase($tld);
        if ($base === null) {
            // Sin RDAP (p. ej. .es): un "no encontrado" no significaría "libre".
            return ['state' => "desconocido (el registro de .{$tld} no ofrece RDAP; revisa con tu registrador)"];
        }
        [$code, $body] = self::httpGet($base . 'domain/' . rawurlencode($domain));
        if ($code === 404) {
            return ['state' => 'libre (no registrado)'];
        }
        $d = json_decode($body, true);
        if ($code !== 200 || !is_array($d)) {
            return ['state' => 'desconocido (el registro no respondió: HTTP ' . $code . ')'];
        }
        $status = array_map('strtolower', (array)($d['status'] ?? []));
        $expires = null;
        foreach ((array)($d['events'] ?? []) as $e) {
            if (($e['eventAction'] ?? '') === 'expiration') {
                $expires = substr((string)$e['eventDate'], 0, 10);
            }
        }
        $registrar = null;
        foreach ((array)($d['entities'] ?? []) as $ent) {
            if (in_array('registrar', (array)($ent['roles'] ?? []), true)) {
                foreach ((array)($ent['vcardArray'][1] ?? []) as $v) {
                    if (($v[0] ?? '') === 'fn') {
                        $registrar = (string)$v[3];
                    }
                }
            }
        }
        $state = in_array('redemption period', $status, true) ? 'en redención (caducado)'
            : (in_array('pending delete', $status, true) ? 'pendiente de borrado (caducado)'
            : ($expires && strtotime($expires) < time() ? 'caducado (en periodo de gracia)' : 'activo'));
        return ['state' => $state, 'expires' => $expires, 'registrar' => $registrar, 'rdap_status' => $status];
    }
}
