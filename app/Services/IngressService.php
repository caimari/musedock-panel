<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Entrada alternativa por un proxy de SNI (p. ej. una segunda línea con IP dinámica):
 * el proxy recibe el 443, mira el nombre (SNI) y reenvía la conexión TAL CUAL a este
 * servidor, a un puerto aparte, anteponiendo la cabecera PROXY protocol v2 con la IP
 * real del cliente. Este servidor no cambia nada de lo que entra por su IP normal.
 *
 * Aquí:
 *  - el servidor de Caddy de las webs escucha también en ingress_port (8443);
 *  - lee la cabecera PROXY SOLO de la IP del proxy (ingress_source_ip); cualquier otra
 *    conexión se trata como siempre (fallback_policy "skip"), también la del 443;
 *  - un nombre de comprobación (ingress_health_name) responde "ok-<servidor>";
 *  - el cortafuegos abre ingress_port solo a la IP del proxy;
 *  - /api/ingress/domains da la lista de dominios que sirve este Caddy, con clave
 *    propia (Authorization: Bearer), para que el proxy sepa a quién mandar cada uno.
 * ensure() lo vuelve a poner si una recarga de Caddy lo quita (cluster-worker, cada minuto).
 */
class IngressService
{
    private const SERVER = 'srv0';
    private const ROUTE_ID = 'ingress-health';
    private const FW_TAG = 'musedock-ingress';

    public static function config(): array
    {
        return [
            'enabled'     => Settings::get('ingress_enabled', '0') === '1',
            'source_ip'   => (string)Settings::get('ingress_source_ip', ''),
            'port'        => (int)Settings::get('ingress_port', '8443') ?: 8443,
            'health_name' => (string)Settings::get('ingress_health_name', ''),
        ];
    }

    /** Activa (o actualiza) la entrada. Devuelve el estado; la clave solo si se acaba de crear. */
    public static function enable(string $sourceIp, string $healthName, int $port = 8443): array
    {
        if (!filter_var($sourceIp, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'error' => 'IP del proxy no válida'];
        }
        $healthName = strtolower(trim($healthName));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $healthName)) {
            return ['ok' => false, 'error' => 'nombre de comprobación no válido'];
        }
        if ($port < 1024 || $port > 65535 || in_array($port, [8444, 8445, 2019], true)) {
            return ['ok' => false, 'error' => 'puerto no válido'];
        }
        Settings::set('ingress_enabled', '1');
        Settings::set('ingress_source_ip', $sourceIp);
        Settings::set('ingress_port', (string)$port);
        Settings::set('ingress_health_name', $healthName);
        $newKey = null;
        if (Settings::get('ingress_map_key', '') === '') {
            $newKey = bin2hex(random_bytes(24));
            Settings::set('ingress_map_key', ReplicationService::encryptPassword($newKey));
        }
        $fw = FirewallService::ufwAddRule('allow', $sourceIp, (string)$port, 'tcp', self::FW_TAG);
        $r = self::ensure();
        LogService::log('ingress', 'enable', "Entrada por proxy: :{$port} desde {$sourceIp}, comprobación {$healthName}");
        return $r + ['firewall' => $fw['ok'] ?? false, 'new_key' => $newKey];
    }

    public static function disable(): array
    {
        $c = self::config();
        Settings::set('ingress_enabled', '0');
        $steps = [];
        $srv = self::get("/config/apps/http/servers/" . self::SERVER) ?? [];
        $listen = array_values(array_filter((array)($srv['listen'] ?? []), static fn($l) => $l !== ":{$c['port']}"));
        if ($listen !== ($srv['listen'] ?? [])) {
            $steps['listen'] = self::send('PATCH', "/config/apps/http/servers/" . self::SERVER . "/listen", $listen);
        }
        if (!empty($srv['listener_wrappers'])) {
            $steps['wrappers'] = self::send('DELETE', "/config/apps/http/servers/" . self::SERVER . "/listener_wrappers");
        }
        $steps['route'] = self::send('DELETE', '/id/' . self::ROUTE_ID);
        if ($c['source_ip'] !== '') {
            foreach (FirewallService::ufwGetRules() as $rule) {
                if (str_contains((string)($rule['comment'] ?? ''), self::FW_TAG)) {
                    shell_exec('ufw --force delete ' . (int)$rule['num'] . ' 2>&1');
                    break;
                }
            }
        }
        LogService::log('ingress', 'disable', 'Entrada por proxy desactivada');
        return ['ok' => true, 'steps' => $steps];
    }

    /** Deja Caddy como tiene que estar (idempotente: solo toca lo que falte). */
    public static function ensure(): array
    {
        $c = self::config();
        if (!$c['enabled'] || $c['source_ip'] === '' || $c['health_name'] === '') {
            return ['ok' => true, 'skipped' => 'desactivada'];
        }
        $base = "/config/apps/http/servers/" . self::SERVER;
        $srv = self::get($base);
        if (!is_array($srv)) {
            return ['ok' => false, 'error' => 'no se pudo leer el servidor de Caddy ' . self::SERVER];
        }
        $done = [];

        // 1) Escuchar también en el puerto de entrada.
        $listen = (array)($srv['listen'] ?? [':443']);
        if (!in_array(":{$c['port']}", $listen, true)) {
            $listen[] = ":{$c['port']}";
            $done['listen'] = self::send('PATCH', "{$base}/listen", array_values($listen));
        }

        // 2) PROXY protocol ANTES del TLS, solo desde el proxy; lo demás, como siempre.
        $want = [
            ['wrapper' => 'proxy_protocol', 'timeout' => '5s', 'allow' => [$c['source_ip'] . (str_contains($c['source_ip'], ':') ? '/128' : '/32')], 'fallback_policy' => 'skip'],
            ['wrapper' => 'tls'],
        ];
        if (($srv['listener_wrappers'] ?? null) != $want) {
            $done['wrappers'] = empty($srv['listener_wrappers'])
                ? self::send('PUT', "{$base}/listener_wrappers", $want)
                : self::send('PATCH', "{$base}/listener_wrappers", $want);
        }

        // 3) Nombre de comprobación: respuesta fija, la primera ruta.
        $route = [
            '@id' => self::ROUTE_ID,
            'match' => [['host' => [$c['health_name']]]],
            'handle' => [['handler' => 'static_response', 'status_code' => 200, 'body' => 'ok-' . self::healthLabel($c['health_name'])]],
            'terminal' => true,
        ];
        $cur = self::get('/id/' . self::ROUTE_ID);
        if (!is_array($cur)) {
            $done['route'] = self::send('PUT', "{$base}/routes/0", $route);
        } elseif (($cur['match'][0]['host'] ?? []) !== [$c['health_name']] || ($cur['handle'] ?? []) != $route['handle']) {
            $done['route'] = self::send('PATCH', '/id/' . self::ROUTE_ID, $route);
        }
        try {
            SystemService::ensureTlsCatchAllPolicy(self::api());
        } catch (\Throwable) {
        }
        // 4) Certificado del nombre de comprobación SIEMPRE por DNS: por HTTP/TLS-ALPN
        // solo sale si el proxy ya está abierto, y si no, Let's Encrypt bloquea el nombre
        // una hora tras 5 fallos (pasó con obelix).
        $tls = self::ensureDnsPolicy($c['health_name']);
        if ($tls !== null) {
            $done['tls'] = $tls;
        }
        $errors = array_filter($done, static fn($r) => empty($r['ok']));
        return ['ok' => !$errors, 'changed' => array_keys($done), 'errors' => array_map(static fn($r) => $r['error'] ?? '', $errors)];
    }

    /**
     * Lo que responde la comprobación: "ok-<nombre>" sacado del nombre de comprobación
     * (health-filemon.ejemplo.com → filemon). El hostname del sistema puede ser algo como
     * "155" y no dice nada.
     */
    public static function healthLabel(string $healthName): string
    {
        $first = explode('.', strtolower($healthName))[0];
        $label = preg_replace('/^(health|check|hc)[-_]/', '', $first);
        return $label !== '' ? $label : strtolower(explode('.', (string)gethostname())[0]);
    }

    /**
     * Política TLS propia para el nombre de comprobación con reto DNS (Cloudflare, el
     * token del entorno de Caddy). null si ya está; ['ok'=>false] si este Caddy no tiene
     * el módulo o el token (entonces se queda con la política general).
     */
    private static function ensureDnsPolicy(string $name): ?array
    {
        $policies = self::get('/config/apps/tls/automation/policies');
        $policies = is_array($policies) && array_is_list($policies) ? $policies : [];
        foreach ($policies as $p) {
            if (($p['subjects'] ?? []) === [$name] && isset($p['issuers'][0]['challenges']['dns'])) {
                return null;
            }
        }
        if (!SystemService::isDnsProviderInstalled('cloudflare')) {
            return ['ok' => false, 'error' => 'este Caddy no tiene el módulo dns.providers.cloudflare: el certificado de ' . $name . ' irá por HTTP (necesita el proxy abierto)'];
        }
        if (!str_contains((string)@file_get_contents('/etc/default/caddy'), 'CLOUDFLARE_API_TOKEN')) {
            return ['ok' => false, 'error' => 'falta CLOUDFLARE_API_TOKEN en /etc/default/caddy: el certificado de ' . $name . ' irá por HTTP'];
        }
        $mine = [
            'subjects' => [$name],
            'issuers' => [[
                'module' => 'acme',
                'challenges' => ['dns' => [
                    'provider' => ['name' => 'cloudflare', 'api_token' => '{env.CLOUDFLARE_API_TOKEN}'],
                    'resolvers' => ['1.1.1.1', '8.8.8.8'],
                ]],
            ]],
        ];
        $keep = array_values(array_filter($policies, static fn($p) => ($p['subjects'] ?? []) !== [$name]));
        array_unshift($keep, $mine);
        return self::send('PATCH', '/config/apps/tls/automation/policies', $keep);
    }

    /** Dominios que sirve este Caddy (los de las webs; comodines incluidos) + el de comprobación. */
    public static function domains(): array
    {
        $c = self::config();
        $out = [];
        $cl = CaddyDomainsService::classify();
        foreach (($cl['domains'] ?? []) as $d) {
            $h = strtolower((string)$d['host']);
            if ($h !== '' && preg_match('/^(\*\.)?[a-z0-9.-]+\.[a-z]{2,}$/', $h)) {
                $out[$h] = true;
            }
        }
        if ($c['health_name'] !== '') {
            $out[$c['health_name']] = true;
        }
        $list = array_keys($out);
        sort($list);
        return $list;
    }

    public static function checkKey(string $given): bool
    {
        $enc = (string)Settings::get('ingress_map_key', '');
        if ($enc === '' || $given === '') {
            return false;
        }
        $key = ReplicationService::decryptPassword($enc);
        return $key !== '' && hash_equals($key, $given);
    }

    public static function key(): string
    {
        $enc = (string)Settings::get('ingress_map_key', '');
        return $enc === '' ? '' : ReplicationService::decryptPassword($enc);
    }

    public static function rotateKey(): string
    {
        $k = bin2hex(random_bytes(24));
        Settings::set('ingress_map_key', ReplicationService::encryptPassword($k));
        LogService::log('ingress', 'rotate-key', 'Clave del mapa de dominios cambiada');
        return $k;
    }

    // ── API de Caddy ──────────────────────────────────────────────────────

    private static function api(): string
    {
        return (string)((require PANEL_ROOT . '/config/panel.php')['caddy']['api_url'] ?? 'http://localhost:2019');
    }

    private static function get(string $path)
    {
        $ch = curl_init(self::api() . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $out = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200 ? json_decode($out, true) : null;
    }

    private static function send(string $method, string $path, $body = null): array
    {
        $ch = curl_init(self::api() . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }
        $out = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => $code >= 200 && $code < 300, 'error' => $code >= 200 && $code < 300 ? '' : "HTTP {$code}: " . trim($out)];
    }
}
