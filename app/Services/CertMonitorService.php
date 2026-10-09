<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;
use MuseDockPanel\Database;

/**
 * CertMonitorService — catch domains stuck in an ACME failure loop.
 *
 * When a domain no longer points at this server (DNS moved, domain expired) but
 * is still 'active', Caddy retries its certificate forever. Each failure counts
 * against Let's Encrypt's 5-failures-per-hour limit, and a single dead domain can
 * quietly exhaust that budget and block certs for everything else — including a
 * newly installed mail server. (This is exactly what gregorioevans.com did.)
 *
 * This service reads Caddy's ACME failures, tracks how many times each domain has
 * failed, and alerts the admin once a domain crosses a threshold — turning a
 * silent, fleet-wide outage into a single actionable email: "domain X keeps
 * failing its certificate; give it a look."
 *
 * On a slave, a domain that does not point here is expected to fail until the
 * master's certificate arrives through the cert sync, so it is only reported if
 * that copy has not arrived after SLAVE_WAIT_HOURS.
 *
 * Read-only: it never disables a domain on its own. Taking a tenant down is a
 * business decision, so it only reports.
 */
class CertMonitorService
{
    /** Alert after this many failures for the same domain within the window. */
    private const FAIL_THRESHOLD = 20;
    /** Only re-alert about the same domain every N hours (anti-spam). */
    private const REALERT_HOURS = 12;
    /** Slave: horas de margen para que llegue la copia del certificado del master. */
    private const SLAVE_WAIT_HOURS = 6;

    /**
     * Scan recent Caddy logs for ACME certificate failures, aggregate per domain,
     * and alert the admin for any domain over the threshold. Intended to run from
     * the cluster worker (cheap; just parses recent journal lines).
     */
    public static function checkAndAlert(): array
    {
        // La hora se guarda SIEMPRE: antes solo si había fallos, y sin fallos se volvía a
        // revisar el journal de Caddy cada minuto en vez de cada 30.
        Settings::set('cert_monitor_last_run', date('Y-m-d H:i:s'));
        $failures = static::recentAcmeFailures();
        static::pruneSlaveWait($failures);
        if (empty($failures)) {
            return ['ok' => true, 'checked' => 0, 'alerted' => []];
        }

        $alerted = [];
        foreach ($failures as $domain => $info) {
            if ($info['count'] < self::FAIL_THRESHOLD) continue;
            if (!static::shouldAlert($domain)) continue;
            // Si ya sirve un certificado válido (falló y luego lo consiguió, p. ej. un
            // emisor falla y el otro no), no hay bucle que cortar.
            if (!$info['rate_limited'] && !empty(CertWatchService::checkHost($domain)['ok'])) continue;

            $resolves = static::domainResolvesHere($domain);
            // En un slave, una web que no apunta aquí no puede sacar su certificado: lo
            // saca el master y le llega por la sincronización de certificados. Hasta que
            // llega la copia, Caddy reintenta y falla (pasa con cada hosting nuevo cuyo
            // DNS no está en Cloudflare); solo es un problema si la copia no llega.
            $waitingCopy = false;
            if ($resolves !== true && Settings::get('cluster_role', 'standalone') === 'slave') {
                if (static::waitingForMasterCopy($domain)) continue;
                $waitingCopy = true;
            }

            $rateLimited = $info['rate_limited'] ? ' (ya ha llegado al límite de intentos de Let\'s Encrypt para este dominio)' : '';
            $why = $waitingCopy
                ? 'Este servidor es un slave y el dominio no apunta a él: el certificado lo saca el master y aquí llega copiado, '
                  . 'pero la copia no ha llegado en ' . self::SLAVE_WAIT_HOURS . ' horas. Revisa que el master tenga el certificado '
                  . 'y que la sincronización de certificados SSL (Cluster → Archivos) esté activa.'
                : ($resolves === false
                    ? 'El dominio NO resuelve a la IP de este servidor (DNS movido o dominio caducado).'
                    : ($resolves === null ? 'No se pudo comprobar el DNS del dominio.'
                        : 'El dominio resuelve aquí, pero el reto ACME falla igualmente (revisar HTTP-01/DNS-01).'));

            $subject = "Certificado en bucle de fallo: {$domain}";
            $body = "El dominio {$domain} ha fallado la emisión de su certificado "
                  . "{$info['count']} veces en la última hora{$rateLimited}.\n\n"
                  . "Motivo probable: {$why}\n\n"
                  . "Let's Encrypt admite 5 fallos por hora para cada nombre: mientras siga fallando, "
                  . "este dominio no podrá obtener ni renovar su certificado, y los reintentos sin fin cargan a Caddy "
                  . "y a la cuenta de Let's Encrypt del servidor.\n\n"
                  . ($waitingCopy
                      ? "Acción recomendada: comprueba el certificado en el master (cert_status) y la sincronización de certificados."
                      : "Acción recomendada: si el dominio ya no se usa, márcalo como inactivo "
                        . "(Superadmin → Tenants) o elimínalo del hosting. Si debe funcionar, revisa su DNS.");

            NotificationService::send($subject, $body, 'cert');
            LogService::log('cert.monitor', 'alert', "Alerta: {$domain} en bucle ACME ({$info['count']} fallos){$rateLimited}");
            static::markAlerted($domain);
            $alerted[] = ['domain' => $domain, 'count' => $info['count'], 'rate_limited' => $info['rate_limited']];
        }

        // Persist the current offenders so the UI can show them without re-parsing.
        Settings::set('cert_monitor_offenders', json_encode(array_map(
            fn($d, $i) => ['domain' => $d, 'count' => $i['count'], 'rate_limited' => $i['rate_limited']],
            array_keys($failures), array_values($failures)
        )));
        Settings::set('cert_monitor_last_run', date('Y-m-d H:i:s'));

        return ['ok' => true, 'checked' => count($failures), 'alerted' => $alerted];
    }

    /**
     * Parse Caddy's ACME failures from the last hour into per-domain counts.
     * Uses journalctl (Caddy logs there); harmless if journal is unavailable.
     */
    private static function recentAcmeFailures(): array
    {
        $cmd = "journalctl -u caddy --since '1 hour ago' --no-pager 2>/dev/null "
             . "| grep -iE 'tls.obtain|could not get certificate|rateLimited' 2>/dev/null";
        $out = (string)shell_exec($cmd);
        if ($out === '') return [];

        $domains = [];
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if ($line === '') continue;
            if (!preg_match('/"identifier":"([^"]+)"/', $line, $m)) continue;
            // Solo errores: tls.obtain también registra los pasos de una emisión que sale
            // bien («obtaining certificate», «certificate obtained successfully»…) y una
            // web nueva contaba como 20+ «fallos».
            if (!preg_match('/"level":"(error|warn)"|\t(ERROR|WARN)\t|could not get certificate|rateLimited|too many failed/i', $line)) continue;
            $domain = $m[1];
            // Fold www. into the base domain so we don't double-alert.
            $base = preg_replace('/^www\./', '', $domain);
            if (!isset($domains[$base])) {
                $domains[$base] = ['count' => 0, 'rate_limited' => false];
            }
            $domains[$base]['count']++;
            if (stripos($line, 'rateLimited') !== false || stripos($line, 'too many failed') !== false) {
                $domains[$base]['rate_limited'] = true;
            }
        }
        arsort($domains);
        return $domains;
    }

    /** Does the domain resolve to this server's public IP? null if uncheckable. */
    private static function domainResolvesHere(string $domain): ?bool
    {
        $myIp = trim((string)shell_exec("curl -s --max-time 5 ifconfig.me 2>/dev/null"));
        if ($myIp === '' || !filter_var($myIp, FILTER_VALIDATE_IP)) {
            $myIp = trim((string)shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'"));
        }
        $resolved = trim((string)shell_exec('dig +short ' . escapeshellarg($domain) . ' A 2>/dev/null | head -1'));
        if ($resolved === '') return false;      // does not resolve at all
        if ($myIp === '') return null;           // cannot compare
        // Cloudflare-proxied domains resolve to CF IPs but still work via DNS-01,
        // so "not my IP" is not conclusive; only "does not resolve" is a hard no.
        return $resolved === $myIp ? true : null;
    }

    private static function shouldAlert(string $domain): bool
    {
        $key = 'cert_monitor_alerted_' . md5($domain);
        $last = Settings::get($key, '');
        if ($last === '') return true;
        return (time() - strtotime($last)) >= self::REALERT_HOURS * 3600;
    }

    /**
     * Slave: true mientras el dominio esté dentro del margen de espera a la copia del
     * certificado del master (contado desde la primera vez que se le vio fallar). Cuando
     * deja de fallar, el contador se borra solo en la siguiente pasada.
     */
    private static function waitingForMasterCopy(string $domain): bool
    {
        $wait = static::slaveWaitMap();
        if (!isset($wait[$domain])) {
            $wait[$domain] = date('Y-m-d H:i:s');
            Settings::set('cert_monitor_slave_wait', json_encode($wait));
            return true;
        }
        return (time() - strtotime($wait[$domain])) < self::SLAVE_WAIT_HOURS * 3600;
    }

    /** @return array<string,string> dominio => desde cuándo espera la copia del master */
    private static function slaveWaitMap(): array
    {
        $wait = json_decode((string)Settings::get('cert_monitor_slave_wait', '{}'), true);
        return is_array($wait) ? $wait : [];
    }

    /** Olvida las esperas de los dominios que ya no fallan. */
    private static function pruneSlaveWait(array $failing): void
    {
        $wait = static::slaveWaitMap();
        $kept = array_intersect_key($wait, $failing);
        if (count($kept) !== count($wait)) {
            Settings::set('cert_monitor_slave_wait', json_encode($kept));
        }
    }

    private static function markAlerted(string $domain): void
    {
        Settings::set('cert_monitor_alerted_' . md5($domain), date('Y-m-d H:i:s'));
    }

    /** Current offenders for the UI (from the last scan). */
    public static function currentOffenders(): array
    {
        $raw = Settings::get('cert_monitor_offenders', '[]');
        $list = json_decode($raw, true);
        return is_array($list) ? $list : [];
    }
}
