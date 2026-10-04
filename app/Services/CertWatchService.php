<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Vigila que cada web que sirve Caddy en este servidor tenga un certificado VÁLIDO
 * para su nombre y que no se acerque a la caducidad sin renovarse.
 *
 * CertMonitorService solo veía un dominio que falla al pedir su certificado muchas
 * veces seguidas. No veía un certificado que se queda sin renovar (y caduca) ni una
 * web servida con un certificado que no es suyo. Con el proxy naranja en "Full
 * (strict)" eso es una web caída (error 526); en "Full" funciona pero sin comprobar
 * nada; con la nube gris el navegador muestra un error de seguridad.
 *
 * Se comprueba contra este mismo servidor (127.0.0.1 con el nombre de la web), así que
 * da igual que el DNS pase por Cloudflare. Solo lee.
 */
class CertWatchService
{
    /** Avisar si le quedan menos días (Caddy renueva con ~30 días; si llega a 14, no ha renovado). */
    public const WARN_DAYS = 14;

    /** Hosts que sirve Caddy en servidores HTTPS (con su puerto). */
    private static function hosts(): array
    {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $servers = json_decode((string)@file_get_contents('http://localhost:2019/config/apps/http/servers', false, $ctx), true);
        $ports = [];
        foreach ((array)$servers as $name => $srv) {
            foreach ((array)($srv['listen'] ?? []) as $l) {
                if (preg_match('/:(\d+)$/', (string)$l, $m) && (int)$m[1] !== 80) {
                    $ports[$name] = (int)$m[1];
                    break;
                }
            }
        }
        $c = CaddyDomainsService::classify();
        $out = [];
        foreach ((array)($c['domains'] ?? []) as $d) {
            $port = $ports[$d['server']] ?? null;
            if ($port === null) {
                continue;
            }
            $h = (string)$d['host'];
            // Comodín: se prueba con un nombre cualquiera que lo use.
            $probe = str_starts_with($h, '*.') ? 'zz-cert-check' . substr($h, 1) : $h;
            if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $probe)) {
                continue;
            }
            $out[$h] = ['probe' => $probe, 'port' => $port, 'group' => $d['group']];
        }
        return $out;
    }

    /** Un nombre: ¿certificado válido?, días que le quedan, emisor. */
    public static function checkHost(string $probe, int $port = 443): array
    {
        $opts = static fn(bool $verify) => ['ssl' => ['peer_name' => $probe, 'SNI_enabled' => true, 'verify_peer' => $verify,
            'verify_peer_name' => $verify, 'allow_self_signed' => !$verify, 'capture_peer_cert' => true]];
        $fp = @stream_socket_client("ssl://127.0.0.1:{$port}", $eno, $estr, 6, STREAM_CLIENT_CONNECT, stream_context_create($opts(true)));
        $valid = (bool)$fp;
        if (!$fp) {
            $verifyError = trim($estr);
            $fp = @stream_socket_client("ssl://127.0.0.1:{$port}", $eno, $estr, 6, STREAM_CLIENT_CONNECT, stream_context_create($opts(false)));
        }
        if (!$fp) {
            return ['ok' => false, 'problem' => 'no hay certificado (el servidor no completa el TLS para este nombre)', 'detail' => trim($estr)];
        }
        $cert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null;
        fclose($fp);
        $info = $cert ? openssl_x509_parse($cert) : [];
        $days = isset($info['validTo_time_t']) ? (int)floor(($info['validTo_time_t'] - time()) / 86400) : null;
        $issuer = (string)($info['issuer']['O'] ?? $info['issuer']['CN'] ?? '?');
        $cn = (string)($info['subject']['CN'] ?? '?');
        $r = ['ok' => $valid, 'days_left' => $days, 'issuer' => $issuer, 'cn' => $cn];
        if (!$valid) {
            $r['problem'] = $days !== null && $days < 0 ? "certificado CADUCADO hace " . abs($days) . ' días'
                : "certificado no válido para este nombre (sirve el de {$cn}, de {$issuer})";
            $r['detail'] = $verifyError ?? '';
        } elseif ($days !== null && $days < self::WARN_DAYS) {
            $r['ok'] = false;
            $r['problem'] = "caduca en {$days} días y no se ha renovado (Caddy renueva con ~30 días)";
        }
        return $r;
    }

    /** Todas las webs de este servidor. */
    public static function scan(): array
    {
        $problems = [];
        $okCount = 0;
        $soonest = [];
        foreach (self::hosts() as $h => $x) {
            $r = self::checkHost($x['probe'], $x['port']);
            if (empty($r['ok'])) {
                // Sin DNS en ninguna parte: dominio probablemente sin uso; se separa.
                $dns = @dns_get_record($x['probe'], DNS_A + DNS_AAAA + DNS_CNAME) ?: [];
                $problems[$h] = $r + ['group' => $x['group'], 'dns' => $dns ? 'sí' : 'NO (¿dominio sin uso?)'];
            } else {
                $okCount++;
                $soonest[$h] = $r['days_left'];
            }
        }
        asort($soonest);
        return ['ok' => true, 'host' => gethostname(), 'checked' => $okCount + count($problems), 'valid' => $okCount,
            'problems' => $problems, 'soonest_expiry' => array_slice($soonest, 0, 5, true)];
    }

    /**
     * Cada 6 h desde el cluster-worker: avisa si hay problemas nuevos (o, si alguno
     * caduca en menos de 7 días, como mucho una vez al día).
     */
    public static function checkAndAlert(): array
    {
        $s = self::scan();
        $real = array_filter($s['problems'], static fn($p) => $p['dns'] === 'sí');
        $hash = $real ? md5(json_encode(array_map(static fn($p) => $p['problem'], $real))) : '';
        $urgent = array_filter($real, static fn($p) => ($p['days_left'] ?? 99) < 7);
        $prev = Settings::get('cert_watch_alert_hash', '');
        $lastAt = (int)Settings::get('cert_watch_alert_at', '0');
        Settings::set('cert_watch_last', json_encode(['at' => date('Y-m-d H:i:s'), 'checked' => $s['checked'], 'problems' => count($real)]));
        if ($real && ($hash !== $prev || ($urgent && time() - $lastAt > 86400))) {
            $lines = [];
            foreach ($real as $h => $p) {
                $lines[] = "- {$h}: {$p['problem']}";
            }
            NotificationService::send('Certificados: ' . count($real) . ' web(s) sin certificado válido o sin renovar',
                "Estas webs de este servidor tienen un problema con su certificado:\n" . implode("\n", $lines)
                . "\n\nQué pasa según cómo llegue la web:\n"
                . "- Con el proxy naranja de Cloudflare en \"Full (strict)\": la web da error 526 (caída).\n"
                . "- En \"Full\": funciona, pero la conexión con el servidor no se comprueba.\n"
                . "- Sin proxy (nube gris): el navegador muestra un error de seguridad.\n\n"
                . "Revisa el registro de Caddy de ese dominio (journalctl -u caddy | grep dominio). Por MCP: cert_status.", 'cert');
            Settings::set('cert_watch_alert_at', (string)time());
        }
        Settings::set('cert_watch_alert_hash', $hash);
        return ['checked' => $s['checked'], 'problems' => count($real), 'no_dns' => count($s['problems']) - count($real)];
    }
}
