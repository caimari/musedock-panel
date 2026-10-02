<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * Todos los dominios que sirve Caddy en este servidor y DE DÓNDE vienen:
 *  - panel_hosting: hostings del panel (dominio, www, dominio extra, subdominio,
 *    alias y redirecciones del panel);
 *  - panel_system: rutas propias del panel (su dominio, webmail, CardDAV,
 *    certificado del correo);
 *  - caddyfile: webs escritas en /etc/caddy/Caddyfile;
 *  - external: añadidas por la API de Caddy por otra aplicación, fuera del
 *    panel (p. ej. el CMS MuseDock crea las de sus tenants con @id route_*).
 * Genérico: no depende de ningún CMS; el @id solo se usa como pista.
 * Solo lectura.
 */
final class CaddyDomainsService
{
    /** @return array{ok:bool,error?:string,domains?:array,duplicate_route_ids?:array} */
    public static function classify(): array
    {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $servers = json_decode((string)@file_get_contents('http://localhost:2019/config/apps/http/servers', false, $ctx), true);
        if (!is_array($servers)) {
            return ['ok' => false, 'error' => 'No se pudo leer la API de Caddy (localhost:2019).'];
        }

        // host → [server, @id] (recorriendo también las subrutas)
        $hosts = [];
        $idCount = [];
        $walk = static function (array $routes, string $srv) use (&$walk, &$hosts, &$idCount): void {
            foreach ($routes as $r) {
                $id = (string)($r['@id'] ?? '');
                if ($id !== '') {
                    $idCount[$id] = ($idCount[$id] ?? 0) + 1;
                }
                foreach (($r['match'] ?? []) as $m) {
                    foreach (($m['host'] ?? []) as $h) {
                        $h = strtolower((string)$h);
                        $hosts[$h] ??= ['server' => $srv, 'route_id' => $id];
                        if ($hosts[$h]['route_id'] === '' && $id !== '') {
                            $hosts[$h]['route_id'] = $id;
                        }
                    }
                }
                foreach (($r['handle'] ?? []) as $hd) {
                    if (($hd['handler'] ?? '') === 'subroute' && is_array($hd['routes'] ?? null)) {
                        $walk($hd['routes'], $srv);
                    }
                }
            }
        };
        foreach ($servers as $name => $srv) {
            $walk((array)($srv['routes'] ?? []), (string)$name);
        }

        $panel = self::panelDomains();
        $system = self::panelSystemHosts();
        $caddyfile = self::caddyfileLabels();

        $out = [];
        foreach ($hosts as $h => $info) {
            if (filter_var($h, FILTER_VALIDATE_IP) || $h === 'localhost') {
                continue;
            }
            $id = $info['route_id'];
            if (isset($panel[$h])) {
                $group = 'panel_hosting';
                $detail = $panel[$h];
            } elseif (isset($system[$h]) || preg_match('/^(panel-|mail-cert|webmail-|carddav-)/', $id)) {
                $group = 'panel_system';
                $detail = $system[$h] ?? 'ruta del panel';
            } elseif (isset($caddyfile[$h])) {
                $group = 'caddyfile';
                $detail = 'escrita en /etc/caddy/Caddyfile';
            } else {
                $group = 'external';
                $detail = str_starts_with($id, 'route_') ? 'por la API de Caddy (parece el CMS MuseDock: @id route_*)'
                    : ($id !== '' ? "por la API de Caddy (@id {$id})" : 'por la API de Caddy, sin @id');
            }
            $out[] = ['host' => $h, 'group' => $group, 'detail' => $detail, 'route_id' => $id, 'server' => $info['server']];
        }
        usort($out, static fn($a, $b) => [$a['group'], $a['host']] <=> [$b['group'], $b['host']]);

        return [
            'ok' => true,
            'domains' => $out,
            'duplicate_route_ids' => array_filter($idCount, static fn($n) => $n > 1),
        ];
    }

    /** Dominios que el panel gestiona como hostings → qué son. */
    private static function panelDomains(): array
    {
        $m = [];
        $add = static function (string $d, string $what) use (&$m): void {
            $d = strtolower(trim($d));
            if ($d !== '') {
                $m[$d] ??= $what;
            }
        };
        try {
            foreach (Database::fetchAll("SELECT domain FROM hosting_accounts WHERE status != 'deleted'") as $r) {
                $add($r['domain'], "hosting {$r['domain']}");
                $add('www.' . $r['domain'], "hosting {$r['domain']} (www)");
            }
            foreach (Database::fetchAll("SELECT hd.domain, h.domain AS acc FROM hosting_domains hd JOIN hosting_accounts h ON h.id = hd.account_id") as $r) {
                $add($r['domain'], "dominio extra del hosting {$r['acc']}");
                $add('www.' . $r['domain'], "dominio extra del hosting {$r['acc']} (www)");
            }
            foreach (Database::fetchAll("SELECT s.subdomain, h.domain AS acc FROM hosting_subdomains s JOIN hosting_accounts h ON h.id = s.account_id") as $r) {
                $add($r['subdomain'], "subdominio del hosting {$r['acc']}");
            }
            foreach (Database::fetchAll("SELECT da.domain, da.type, h.domain AS acc FROM hosting_domain_aliases da LEFT JOIN hosting_accounts h ON h.id = da.hosting_account_id") as $r) {
                $what = ($r['type'] === 'redirect' ? 'redirección' : 'alias') . ($r['acc'] ? " del hosting {$r['acc']}" : ' (independiente, del panel)');
                $add($r['domain'], $what);
                $add('www.' . $r['domain'], $what . ' (www)');
            }
        } catch (\Throwable) {
        }
        return $m;
    }

    /** Hosts propios del panel (su dominio, webmail, CardDAV, correo). */
    private static function panelSystemHosts(): array
    {
        $m = [];
        foreach ([
            'panel_hostname' => 'dominio del panel',
            'mail_hostname' => 'servidor de correo (certificado)',
            'mail_local_hostname' => 'servidor de correo (certificado)',
        ] as $k => $what) {
            $v = strtolower(trim(Settings::get($k, '')));
            if ($v !== '') {
                $m[$v] = $what;
            }
        }
        return $m;
    }

    /** Etiquetas de los bloques de webs del Caddyfile (sin puerto). */
    private static function caddyfileLabels(): array
    {
        $m = [];
        $depth = 0;
        foreach (@file('/etc/caddy/Caddyfile', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#')) {
                continue;
            }
            if ($depth === 0 && str_ends_with($t, '{') && !str_starts_with($t, '{') && !str_starts_with($t, '(')) {
                foreach (explode(',', rtrim(substr($t, 0, -1))) as $l) {
                    $l = strtolower(preg_replace('#^https?://#', '', preg_replace('/:\d+$/', '', trim($l))));
                    if ($l !== '') {
                        $m[$l] = true;
                    }
                }
            }
            $depth = max(0, $depth + substr_count($t, '{') - substr_count($t, '}'));
        }
        return $m;
    }
}
