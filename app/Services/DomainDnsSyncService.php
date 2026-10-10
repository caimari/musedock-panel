<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\DnsProviders\ProviderRegistry;

/** DNS provisioning for the panel UI. Independent of MCP permissions and tools. */
class DomainDnsSyncService
{
    public static function normalizeHost(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }

    public static function providerFromNameservers(array $nameservers): string
    {
        $patterns = ['cloudflare' => 'cloudflare.com', 'digitalocean' => 'digitalocean.com',
            'route53' => 'awsdns-', 'hetzner' => 'hetzner', 'ovh' => 'ovh.', 'vultr' => 'vultr.com',
            'linode' => 'linode.com', 'porkbun' => 'porkbun.com', 'namecheap' => 'registrar-servers.com',
            'gandi' => 'gandi.net', 'ionos' => 'ui-dns.', 'google' => 'googledomains.com'];
        foreach ($patterns as $provider => $needle) {
            foreach ($nameservers as $ns) {
                if (str_contains(self::normalizeHost($ns), $needle)) return $provider;
            }
        }
        return 'externo/desconocido';
    }

    /** Closest delegation, including delegated subdomains. Never assumes cached zone ownership. */
    public static function nameservers(string $domain): array
    {
        $parts = explode('.', $domain);
        while (count($parts) >= 2) {
            $records = @dns_get_record(implode('.', $parts), DNS_NS) ?: [];
            $ns = array_values(array_filter(array_map(static fn($r) => self::normalizeHost($r['target'] ?? ''), $records)));
            if ($ns) { sort($ns); return $ns; }
            array_shift($parts);
        }
        return [];
    }

    /** Select only the records belonging to this purpose; unrelated TXT values survive. */
    public static function relevant(array $expected, array $records): array
    {
        return array_values(array_filter($records, static function ($r) use ($expected) {
            if (self::normalizeHost($r['name'] ?? '') !== self::normalizeHost($expected['name'])) return false;
            $type = $r['type'] ?? '';
            if ($expected['type'] === 'TXT') {
                if ($type !== 'TXT') return false;
                $v = (string)($r['content'] ?? '');
                if (str_starts_with($expected['content'], 'v=spf1')) return (bool)preg_match('/^v=spf1(?:\s|$)/i', $v);
                // DKIM/DMARC owner names are reserved for their single purpose.
                return true;
            }
            if ($expected['type'] === 'MX') return $type === 'MX';
            return in_array($type, ['A', 'AAAA', 'CNAME'], true);
        }));
    }

    public static function diff(array $expected, array $records): array
    {
        $current = self::relevant($expected, $records);
        usort($current, static fn($a, $b) => strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? '')));
        $same = static function ($r) use ($expected): bool {
            $value = (string)($r['content'] ?? '');
            $want = (string)$expected['content'];
            if (in_array($expected['type'], ['CNAME', 'MX'], true)) {
                $value = self::normalizeHost($value); $want = self::normalizeHost($want);
            }
            return ($r['type'] ?? '') === $expected['type'] && $value === $want
                && (!isset($expected['priority']) || (int)($r['priority'] ?? 0) === (int)$expected['priority'])
                && (bool)($r['proxied'] ?? false) === (bool)($expected['proxied'] ?? false);
        };
        $matches = array_values(array_filter($current, $same));
        return ['expected' => $expected, 'current' => $current,
            'status' => count($current) === 1 && count($matches) === 1 ? 'ok' : ($current ? 'sustituir' : 'crear')];
    }

    public static function plan(string $domain, string $scope): array
    {
        $domain = self::normalizeHost($domain);
        if (strlen($domain) > 253 || !preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domain)
            || !in_array($scope, ['hosting', 'mail'], true)) throw new \RuntimeException('Dominio o servicio inválido.');
        $hosting = Database::fetchOne('SELECT * FROM hosting_accounts WHERE lower(domain) = :d', ['d' => $domain]);
        $mail = MailService::getDomainByName($domain);
        if (($scope === 'hosting' && !$hosting) || ($scope === 'mail' && !$mail)) {
            throw new \RuntimeException('Primero debes crear este dominio en el panel.');
        }
        $expected = []; $warnings = [];
        if ($scope === 'hosting') {
            $target = self::normalizeHost(Settings::get('dns_default_target', ''));
            $ip = trim(Settings::get('server_public_ip', ''));
            if ($target !== '' && $target !== $domain && !str_ends_with($target, '.' . $domain)) {
                if (!preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $target)) {
                    throw new \RuntimeException('El destino CNAME configurado no es válido.');
                }
                $targetIps = array_column(@dns_get_record($target, DNS_A) ?: [], 'ip');
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !in_array($ip, $targetIps, true)) {
                    throw new \RuntimeException('El destino CNAME configurado no resuelve a la IP pública de este servidor. Revisa dns_default_target y server_public_ip antes de publicar.');
                }
                $type = 'CNAME'; $value = $target;
            } else {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new \RuntimeException('Configura la IP pública del servidor antes de publicar DNS.');
                $type = 'A'; $value = $ip;
            }
            foreach (SystemService::hostsWithWww($domain) as $host) {
                $expected[] = ['type' => $type, 'name' => $host, 'content' => $value, 'ttl' => 300, 'proxied' => false];
            }
        }
        // Hosting with existing mail also aligns mail DNS; mail-only never moves the website.
        if ($mail) {
            foreach (self::mailRecords($mail) as $e) $expected[] = $e;
            if (empty($mail['dkim_public_key'])) $warnings[] = 'No hay clave DKIM: genera la clave y vuelve a revisar el plan antes de publicar.';
        }
        ProviderRegistry::locate($domain, true);
        $zones = []; $entries = []; $nsCache = [];
        foreach ($expected as $e) {
            $name = $e['name'];
            $located = ProviderRegistry::locate($name);
            $zone = $located['zone'] ?? null;
            $adapter = $located['provider'] ?? null;
            $ns = $nsCache[$name] ??= self::nameservers($name);
            $provider = self::providerFromNameservers($ns);
            $managed = false; $records = [];
            if ($zone) {
                $key = $adapter->id() . ':' . $zone['zone_id'];
                if (!isset($zones[$key])) {
                    $info = $adapter->getZone($zone);
                    $all = $adapter->listRecords($zone);
                    if (!$info['ok'] || !$all['ok']) throw new \RuntimeException('No se pudo consultar la zona DNS. Revisa las credenciales y vuelve a intentarlo.');
                    $zoneNs = array_map([self::class, 'normalizeHost'], $info['result']['name_servers'] ?? []); sort($zoneNs);
                    $zones[$key] = ['ns' => $zoneNs, 'status' => $info['result']['status'] ?? '', 'records' => $all['result'] ?? []];
                }
                $z = $zones[$key];
                $managed = $ns && $ns === $z['ns'] && $z['status'] === 'active';
                if ($managed) { $records = $z['records']; $provider = $adapter->id(); }
            }
            if (!$managed) {
                foreach (@dns_get_record($name, DNS_A | DNS_AAAA | DNS_CNAME | DNS_MX | DNS_TXT) ?: [] as $r) {
                    $records[] = ['type' => $r['type'], 'name' => $r['host'],
                        'content' => $r['ip'] ?? $r['ipv6'] ?? $r['target'] ?? $r['txt'] ?? '', 'priority' => $r['pri'] ?? 0];
                }
            }
            $entry = self::diff($e, $records);
            $entry += ['managed' => (bool)$managed, 'provider' => $provider, 'nameservers' => $ns,
                'zone_id' => $managed ? $zone['zone_id'] : null, 'zone' => $managed ? $zone['zone'] : null,
                'account' => $managed ? $zone['account'] : null];
            $entries[] = $entry;
        }
        // A configured mail host must not overwrite the new website's address.
        $addresses = [];
        foreach ($expected as $e) {
            if (!in_array($e['type'], ['A', 'AAAA', 'CNAME'], true)) continue;
            $key = $e['name'];
            $destination = $e['type'] . ' ' . $e['content'];
            if (isset($addresses[$key]) && $addresses[$key] !== $destination) {
                throw new \RuntimeException('El hostname de correo y el hosting tienen destinos incompatibles: ' . $key);
            }
            $addresses[$key] = $destination;
        }
        $manual = array_filter($entries, static fn($e) => !$e['managed'] && $e['status'] !== 'ok');
        if ($manual) $warnings[] = 'Hay registros fuera de las cuentas DNS gestionables del panel. Debes publicarlos en su proveedor; el panel no cambia nameservers.';
        foreach ($entries as $e) {
            foreach ($e['current'] as $r) {
                if (($r['type'] ?? '') === 'MX' && preg_match('/^route\d+\.mx\.cloudflare\.net\.?$/i', $r['content'] ?? '')) {
                    $warnings[] = 'Cloudflare Email Routing está activo: desactívalo en Cloudflare antes de sustituir sus MX.';
                }
            }
        }
        $plan = ['domain' => $domain, 'scope' => $scope, 'entries' => $entries, 'warnings' => array_values(array_unique($warnings)),
            'return_url' => $scope === 'hosting' ? '/accounts/' . $hosting['id'] : '/mail/domains/' . $mail['id']];
        $plan['fingerprint'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $plan;
    }

    private static function mailRecords(array $mail): array
    {
        $node = !empty($mail['mail_node_id']) ? ClusterService::getNode((int)$mail['mail_node_id']) : null;
        $host = self::normalizeHost($node ? ($node['mail_hostname'] ?? '') : Settings::get('mail_local_hostname', ''));
        if (!$host || !preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host)) {
            throw new \RuntimeException('Configura un hostname válido en el servidor de correo o en el nodo asignado antes de publicar MX.');
        }
        if (!empty($mail['mail_node_id']) && !$node) throw new \RuntimeException('No se encuentra el nodo de correo asignado.');
        // Never publish the cluster transport address (often a private WireGuard IP).
        $ip = $node ? (string)($node['metadata']['public_ip'] ?? '') : trim(Settings::get('server_public_ip', ''));
        if ($node && $ip === '') {
            $ips = array_column(@dns_get_record($host, DNS_A) ?: [], 'ip');
            $ip = (string)($ips[0] ?? '');
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \RuntimeException('No se conoce una IPv4 pública válida del servidor de correo. Revisa su hostname y su IP pública.');
        }
        $domain = $mail['domain'];
        $spf = trim((string)($mail['spf_record'] ?? '')) ?: 'v=spf1 mx ip4:' . $ip . ' ~all';
        $base = ['ttl' => 300, 'proxied' => false];
        $records = [
            ['type' => 'MX', 'name' => $domain, 'content' => $host, 'priority' => 10] + $base,
            ['type' => 'A', 'name' => $host, 'content' => $ip] + $base,
            ['type' => 'TXT', 'name' => $domain, 'content' => $spf] + $base,
            ['type' => 'TXT', 'name' => '_dmarc.' . $domain, 'content' => 'v=DMARC1; p=' . ($mail['dmarc_policy'] ?: 'quarantine') . '; rua=mailto:postmaster@' . $domain] + $base,
        ];
        if (!empty($mail['dkim_public_key'])) {
            $records[] = ['type' => 'TXT', 'name' => ($mail['dkim_selector'] ?: 'default') . '._domainkey.' . $domain,
                'content' => 'v=DKIM1; k=rsa; p=' . $mail['dkim_public_key']] + $base;
        }
        return $records;
    }

    /** Caddy already has the hosting route. Only the local mail hostname belongs here. */
    private static function prepareTls(array $plan): array
    {
        $messages = [];
        try {
            $config = require PANEL_ROOT . '/config/panel.php';
            SystemService::ensureTlsCatchAllPolicy($config['caddy']['api_url']);
            if ($plan['scope'] === 'hosting') $messages[] = 'Caddy: automatización TLS del hosting preparada.';
            $mail = MailService::getDomainByName($plan['domain']);
            if ($mail && empty($mail['mail_node_id']) && Settings::get('mail_local_configured', '') === '1') {
                $hostname = self::normalizeHost(Settings::get('mail_local_hostname', ''));
                if ($hostname && !Database::fetchOne('SELECT id FROM hosting_accounts WHERE lower(domain) = :d', ['d' => $hostname])) {
                    $result = MailService::ensureMailCertRoute($hostname);
                    $messages[] = $result['ok'] ? 'Caddy: ruta del certificado de correo preparada.' : ($result['error'] ?? 'No se pudo preparar el certificado de correo.');
                }
            } elseif ($mail && !empty($mail['mail_node_id'])) {
                $messages[] = 'Correo remoto: el certificado SMTP/IMAP se gestiona en el nodo de correo.';
            }
        } catch (\Throwable $e) { $messages[] = 'TLS pendiente: ' . $e->getMessage(); }
        return $messages;
    }

    /** Read-only propagation and local-origin HTTPS checks; no request goes to an arbitrary IP. */
    public static function verify(string $domain, string $scope): array
    {
        $plan = self::plan($domain, $scope);
        $dns = [];
        foreach ($plan['entries'] as $e) {
            $want = $e['expected'];
            $types = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX, 'TXT' => DNS_TXT];
            $records = [];
            foreach (@dns_get_record($want['name'], $types[$want['type']]) ?: [] as $r) {
                $records[] = ['type' => $r['type'], 'name' => $r['host'], 'content' => $r['ip'] ?? $r['ipv6'] ?? $r['target'] ?? $r['txt'] ?? '',
                    'priority' => $r['pri'] ?? 0];
            }
            $diff = self::diff($want, $records);
            // Cloudflare flattens an apex CNAME into A answers on public DNS.
            $aligned = $diff['status'] === 'ok';
            if (!$aligned && $want['type'] === 'CNAME' && $want['name'] === $e['zone'] && $e['provider'] === 'cloudflare') {
                $ips = array_column(@dns_get_record($want['name'], DNS_A) ?: [], 'ip');
                $targetIps = array_column(@dns_get_record($want['content'], DNS_A) ?: [], 'ip');
                $aligned = $ips && $targetIps && !array_diff($ips, $targetIps) && !array_diff($targetIps, $ips);
            }
            $localAligned = (bool)$aligned;
            $publicAligned = null;
            if (!$localAligned) {
                $public = DnsPropagationService::records($want['name'], $want['type']);
                if ($public !== null) $publicAligned = self::diff($want, $public)['status'] === 'ok';
                if (!$publicAligned && $want['type'] === 'CNAME' && $want['name'] === $e['zone'] && $e['provider'] === 'cloudflare') {
                    $actual = DnsPropagationService::records($want['name'], 'A');
                    $target = DnsPropagationService::records($want['content'], 'A');
                    if ($actual !== null && $target !== null) {
                        $ips = array_column($actual, 'content'); $targetIps = array_column($target, 'content');
                        $publicAligned = (bool)($ips && $targetIps && !array_diff($ips, $targetIps) && !array_diff($targetIps, $ips));
                    }
                }
            }
            $dns[] = ['name' => $want['name'], 'type' => $want['type'], 'aligned' => $localAligned || $publicAligned === true,
                'local_aligned' => $localAligned, 'public_aligned' => $publicAligned];
        }
        $hosts = $scope === 'hosting' ? SystemService::hostsWithWww($plan['domain']) : [];
        $mail = MailService::getDomainByName($domain);
        if ($mail && empty($mail['mail_node_id']) && Settings::get('mail_local_configured', '') === '1') {
            $hostname = self::normalizeHost(Settings::get('mail_local_hostname', ''));
            if ($hostname) $hosts[] = $hostname;
        }
        $tls = [];
        foreach (array_unique($hosts) as $host) {
            if (!preg_match('/^[a-z0-9.-]+$/', $host)) continue;
            $ch = curl_init('https://' . $host . '/');
            curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_RESOLVE => [$host . ':443:127.0.0.1'], CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            curl_exec($ch);
            $errno = curl_errno($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $tls[] = ['host' => $host, 'valid' => $errno === 0 && $code > 0, 'http_status' => $code, 'website_ok' => $errno === 0 && $code >= 200 && $code < 400];
        }
        return ['ok' => true, 'dns' => $dns, 'tls' => $tls,
            'note' => 'DNS comprobado con el resolvedor local y, si difiere, con el resolvedor público Cloudflare 1.1.1.1. HTTPS verifica el certificado del origen local; no comprueba buzones, SMTP/IMAP ni todas las cachés externas.'];
    }

    /** Keep API-only fields out of requests. */
    private static function payload(array $r): array
    {
        return array_intersect_key($r, array_flip(['type', 'name', 'content', 'ttl', 'proxied', 'priority']));
    }

    public static function apply(array $plan): array
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') throw new \RuntimeException('Publica DNS desde el master.');
        $fresh = self::plan($plan['domain'], $plan['scope']);
        if (!hash_equals($plan['fingerprint'], $fresh['fingerprint'])) throw new \RuntimeException('Los DNS o la configuración han cambiado. Revisa y confirma un plan nuevo.');
        LogService::log('domain.dns.confirmed', $plan['domain'], json_encode(['scope' => $plan['scope'], 'entries' => $fresh['entries']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $done = []; $errors = [];
        foreach ($fresh['entries'] as $entry) {
            if (!$entry['managed'] || $entry['status'] === 'ok') continue;
            $located = ProviderRegistry::locate($entry['expected']['name']);
            $zone = $located['zone'] ?? null;
            $adapter = $located['provider'] ?? null;
            if (!$zone || $zone['zone_id'] !== $entry['zone_id'] || $adapter->id() !== $entry['provider']) throw new \RuntimeException('La cuenta DNS ha cambiado. Revisa el plan.');
            $e = $entry['expected'];
            // Check immediately before each owner name: do not silently overwrite concurrent edits.
            $r = $adapter->listRecords($zone);
            if (!$r['ok'] || self::diff($e, $r['result'] ?? [])['current'] !== $entry['current']) {
                $errors[] = $e['name'] . ': los registros han cambiado; vuelve a revisar.'; break;
            }
            $deleted = []; $replacement = null;
            try {
                foreach ($entry['current'] as $old) {
                    if ($replacement === null && $old['type'] === $e['type']) { $replacement = $old; continue; }
                    // Blocking address records must be removed before changing A ↔ CNAME.
                    $res = $adapter->deleteRecord($zone, $old['id']);
                    if (!$res['ok']) throw new \RuntimeException($res['error'] ?? 'No se pudo retirar el registro anterior.');
                    $deleted[] = $old;
                }
                $res = $replacement ? $adapter->updateRecord($zone, $replacement['id'], $e)
                    : $adapter->createRecord($zone, $e);
                if (!$res['ok']) throw new \RuntimeException($res['error'] ?? 'No se pudo publicar el registro.');
                $done[] = $e['type'] . ' ' . $e['name'];
            } catch (\Throwable $ex) {
                $errors[] = $e['name'] . ': ' . $ex->getMessage();
                $observed = $adapter->listRecords($zone);
                if (!$observed['ok']) {
                    $errors[] = $e['name'] . ': estado remoto incierto; revisa la zona antes de restaurar.';
                    break;
                }
                if (self::diff($e, $observed['result'] ?? [])['status'] === 'ok') {
                    $errors[] = $e['name'] . ': el destino propuesto aparece publicado pese al error de API; vuelve a consultar.';
                    break;
                }
                foreach ($deleted as $old) {
                    $restore = $adapter->createRecord($zone, self::payload($old));
                    if (!$restore['ok']) $errors[] = $old['name'] . ': no se pudo restaurar el registro anterior; revisa la zona.';
                }
                break;
            }
        }
        LogService::log('domain.dns.sync', $plan['domain'], json_encode(['scope' => $plan['scope'], 'done' => $done, 'errors' => $errors], JSON_UNESCAPED_UNICODE));
        $tls = self::prepareTls($fresh);
        return ['tls' => $tls, 'ok' => !$errors, 'done' => $done, 'errors' => $errors,
            'manual_pending' => count(array_filter($fresh['entries'], static fn($e) => !$e['managed'] && $e['status'] !== 'ok')),
            'message' => 'Comprueba la propagación DNS y HTTPS. La publicación no garantiza que el certificado ya esté emitido.'];
    }
}
