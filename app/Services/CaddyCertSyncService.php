<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Copia de los certificados de Caddy del master a los nodos de relevo.
 *
 * Al tomar el mando, el relevo pone las webs del master (Caddyfile y rutas de hostings),
 * pero el certificado solo se puede sacar cuando el DNS ya apunta a él: unos minutos de
 * error de certificado tras cada relevo. Con la copia, Caddy los encuentra en su
 * almacenamiento y los usa al momento; cuando el master renueva, la copia siguiente trae
 * el nuevo (Caddy comprueba el almacenamiento antes de intentar renovar).
 *
 * Solo se copian certificados de nombres que el master sirve. En el nodo que recibe:
 * solo si es slave, nunca sustituye un certificado por otro que caduque antes, comprueba
 * que la clave casa con el certificado y no borra nada. No recarga Caddy.
 */
class CaddyCertSyncService
{
    private const STORAGE_CANDIDATES = [
        '/var/lib/caddy/.local/share/caddy',
        '/root/.local/share/caddy',
        '/home/caddy/.local/share/caddy',
    ];
    private const BACKUP_DIR = '/var/backups/musedock-caddy-certs';

    public static function storageDir(): string
    {
        foreach (self::STORAGE_CANDIDATES as $d) {
            if (is_dir($d . '/certificates')) {
                return $d;
            }
        }
        return '';
    }

    /** Nombres que sirve el Caddy local (hosts de todas las rutas), sin IPs ni localhost. */
    private static function servedHosts(): array
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $api = $config['caddy']['api_url'] ?? 'http://localhost:2019';
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $servers = json_decode((string)@file_get_contents("{$api}/config/apps/http/servers", false, $ctx), true);
        $hosts = [];
        foreach (is_array($servers) ? $servers : [] as $srv) {
            foreach ((is_array($srv) ? ($srv['routes'] ?? []) : []) as $route) {
                foreach (($route['match'] ?? []) as $m) {
                    foreach (($m['host'] ?? []) as $h) {
                        $h = strtolower((string)$h);
                        if ($h !== 'localhost' && !filter_var($h, FILTER_VALIDATE_IP) && str_contains($h, '.')) {
                            $hosts[$h] = true;
                        }
                    }
                }
            }
        }
        return $hosts;
    }

    /** Certificados ACME del almacenamiento local de nombres que se sirven aquí. */
    public static function export(): array
    {
        $dir = self::storageDir();
        if ($dir === '') {
            return [];
        }
        $served = self::servedHosts();
        $out = [];
        foreach (glob($dir . '/certificates/*', GLOB_ONLYDIR) ?: [] as $issuerDir) {
            $issuer = basename($issuerDir);
            if ($issuer === 'local') {
                continue; // emisor interno de cada Caddy (IPs, localhost): no se copia
            }
            foreach (glob($issuerDir . '/*', GLOB_ONLYDIR) ?: [] as $domDir) {
                $domain = basename($domDir);
                if (!isset($served[$domain])) {
                    continue;
                }
                $crt = (string)@file_get_contents("{$domDir}/{$domain}.crt");
                $key = (string)@file_get_contents("{$domDir}/{$domain}.key");
                if ($crt === '' || $key === '') {
                    continue;
                }
                $out[] = [
                    'issuer' => $issuer,
                    'domain' => $domain,
                    'crt'    => $crt,
                    'key'    => $key,
                    'json'   => (string)@file_get_contents("{$domDir}/{$domain}.json"),
                ];
            }
        }
        return $out;
    }

    private static function validTo(string $pem): int
    {
        $x = @openssl_x509_parse($pem);
        return is_array($x) ? (int)($x['validTo_time_t'] ?? 0) : 0;
    }

    /** En el nodo que recibe (acción del cluster caddy_certs_import). */
    public static function nodeImport(array $payload): array
    {
        if (Settings::get('cluster_role', 'standalone') !== 'slave') {
            return ['ok' => false, 'error' => 'este nodo no es slave: no recibe certificados'];
        }
        $dir = self::storageDir();
        if ($dir === '') {
            return ['ok' => false, 'error' => 'no se encuentra el almacenamiento de Caddy'];
        }
        $owner = posix_getpwnam('caddy') ?: null;
        $out = ['ok' => true, 'written' => 0, 'current' => 0, 'errors' => []];
        foreach ((array)($payload['certs'] ?? []) as $c) {
            $issuer = (string)($c['issuer'] ?? '');
            $domain = strtolower((string)($c['domain'] ?? ''));
            $crt = (string)($c['crt'] ?? '');
            $key = (string)($c['key'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9.\-]{0,200}$/', $issuer) || $issuer === 'local'
                || !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $domain)) {
                $out['errors'][] = "{$domain}: nombre no válido";
                continue;
            }
            $newTo = self::validTo($crt);
            if ($newTo <= time() || !@openssl_x509_check_private_key($crt, $key)) {
                $out['errors'][] = "{$domain}: certificado caducado o la clave no casa";
                continue;
            }
            $domDir = "{$dir}/certificates/{$issuer}/{$domain}";
            $curCrt = (string)@file_get_contents("{$domDir}/{$domain}.crt");
            if ($curCrt === $crt || ($curCrt !== '' && self::validTo($curCrt) >= $newTo)) {
                $out['current']++;
                continue;
            }
            if ($curCrt !== '') {
                @mkdir(self::BACKUP_DIR, 0700, true);
                $stamp = date('Ymd-His');
                foreach (['crt', 'key', 'json'] as $ext) {
                    @copy("{$domDir}/{$domain}.{$ext}", self::BACKUP_DIR . "/{$domain}.{$ext}.{$stamp}");
                }
            }
            foreach ([dirname($domDir), $domDir] as $d) {
                if (!is_dir($d)) {
                    @mkdir($d, 0700, true);
                    if ($owner) {
                        @chown($d, $owner['uid']);
                        @chgrp($d, $owner['gid']);
                    }
                }
            }
            $files = ['key' => $key, 'crt' => $crt];
            if (($c['json'] ?? '') !== '') {
                $files['json'] = (string)$c['json'];
            }
            $ok = true;
            foreach ($files as $ext => $content) {
                $path = "{$domDir}/{$domain}.{$ext}";
                $tmp = $path . '.tmp-' . getmypid();
                if (@file_put_contents($tmp, $content) === false) {
                    $ok = false;
                    break;
                }
                @chmod($tmp, 0600);
                if ($owner) {
                    @chown($tmp, $owner['uid']);
                    @chgrp($tmp, $owner['gid']);
                }
                $ok = @rename($tmp, $path) && $ok;
            }
            if ($ok) {
                $out['written']++;
            } else {
                $out['errors'][] = "{$domain}: no se pudo escribir";
            }
        }
        if ($out['written'] > 0) {
            try {
                LogService::log('caddy.certs', 'import', "Certificados del master copiados: {$out['written']} nuevos, {$out['current']} ya al día");
            } catch (\Throwable) {
            }
        }
        $out['ok'] = !$out['errors'];
        return $out;
    }

    /**
     * En el master (cluster-worker cada 6 h, o cluster-switch certs-sync): envía a cada
     * nodo los certificados, solo si han cambiado desde el último envío correcto.
     */
    public static function syncToNodes(bool $force = false): array
    {
        if (Settings::get('cluster_role', 'standalone') !== 'master' || Settings::get('cluster_fenced', '0') === '1') {
            return [];
        }
        $certs = self::export();
        if (!$certs) {
            return [];
        }
        $hash = hash('sha256', json_encode($certs));
        $sent = json_decode(Settings::get('caddy_certs_sync_sent', '{}'), true) ?: [];
        $result = [];
        foreach (ClusterService::getNodes() as $n) {
            $nid = (string)$n['id'];
            if (!$force && ($sent[$nid] ?? '') === $hash) {
                continue;
            }
            $r = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'caddy_certs_import', 'payload' => ['certs' => $certs]]);
            $d = $r['data'] ?? [];
            if (!empty($r['ok']) && !empty($d['ok'])) {
                $sent[$nid] = $hash;
                $result[(string)$n['name']] = count($certs) . " certificados: {$d['written']} copiados, {$d['current']} ya al día";
            } else {
                $result[(string)$n['name']] = 'ERROR ' . ($d['error'] ?? $r['error'] ?? implode('; ', (array)($d['errors'] ?? [])));
            }
        }
        Settings::set('caddy_certs_sync_sent', json_encode($sent));
        return $result;
    }
}
