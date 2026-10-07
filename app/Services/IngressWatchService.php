<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Vigilante de ENTRADA: si la entrada normal de un servidor (su IP, p. ej. la línea ONO)
 * falla o va extremadamente lenta, pero el servidor sigue alcanzable por su entrada
 * alternativa (un proxy de SNI con otra IP, p. ej. la fibra Orange — IngressService en
 * el servidor), mueve en Cloudflare los registros A de la IP normal a la alternativa. Lo
 * devuelve cuando la entrada normal lleva un rato estable. NO cambia de servidor: eso es
 * el relevo (failover).
 *
 * Se ejecuta en un panel de FUERA de ese servidor (el otro nodo del cluster), cada minuto
 * desde el cluster-worker. Decide con su propia comprobación + los testigos externos
 * (WitnessService). Agnóstico: se configura por servidor vigilado.
 *
 * Settings ingress_watch = {"servers": [{"ip": "<IP normal>", "health": "<nombre de
 *   comprobación>", "alt_host": "<nombre DNS de la entrada alternativa>"}],
 *   "fail_minutes": 3, "recover_minutes": 10, "max_latency_ms": 1500, "max_loss_pct": 30}
 *
 * No se mueven: nombres de máquina, destinos de MX (el proxy solo encamina 80/443).
 */
class IngressWatchService
{
    public const JOURNAL = 'ingress_watch_journal';

    public static function config(): array
    {
        $c = json_decode((string)Settings::get('ingress_watch', ''), true);
        $c = is_array($c) ? $c : [];
        return $c + ['servers' => [], 'fail_minutes' => 3, 'recover_minutes' => 10, 'max_latency_ms' => 1500, 'max_loss_pct' => 30];
    }

    public static function saveConfig(array $c): void
    {
        Settings::set('ingress_watch', json_encode($c, JSON_UNESCAPED_SLASHES));
    }

    private static function state(): array
    {
        $s = json_decode((string)Settings::get('ingress_watch_state', '{}'), true);
        return is_array($s) ? $s : [];
    }

    private static function saveState(array $s): void
    {
        Settings::set('ingress_watch_state', json_encode($s, JSON_UNESCAPED_SLASHES));
    }

    public static function status(): array
    {
        return ['config' => self::config(), 'state' => self::state(), 'moved' => count(CloudflareService::journal(self::JOURNAL))];
    }

    /** ¿Ese servidor (por su IP normal) se alcanza por su entrada alternativa? (para el relevo: no promover si sí) */
    public static function reachableViaAlternate(string $serverIp, ?array $answers = null): bool
    {
        foreach (self::config()['servers'] as $srv) {
            if (($srv['ip'] ?? '') !== $serverIp) {
                continue;
            }
            $altIp = self::resolve((string)$srv['alt_host']);
            if ($altIp !== '' && self::probe((string)$srv['health'], $altIp)['ok']) {
                return true;
            }
            if (in_array('up', self::altVerdicts((string)$srv['health'], $altIp, $answers), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cómo ven los testigos la entrada alternativa: comprobaciones del nombre de salud
     * hacia la IP alternativa actual (agente v3, "resolve_host") o sin IP fija (addr =
     * nombre: van por el CNAME del nombre de salud, que lleva a la alternativa).
     */
    private static function altVerdicts(string $health, string $altIp, ?array $answers): array
    {
        $v = $altIp !== '' ? WitnessService::verdicts($altIp, $health, 0, 0, $answers) : [];
        foreach (WitnessService::verdicts($health, $health, 0, 0, $answers) as $w => $x) {
            $v[$w] ??= $x;
        }
        return $v;
    }

    /** Una pasada (cluster-worker, cada minuto). */
    public static function run(): array
    {
        $c = self::config();
        if (!$c['servers']) {
            return ['skipped' => 'sin servidores vigilados'];
        }
        $mine = preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) ?: [];
        $state = self::state();
        $answers = WitnessService::queryAll();
        $log = [];
        foreach ($c['servers'] as $srv) {
            $ip = (string)($srv['ip'] ?? '');
            $health = (string)($srv['health'] ?? '');
            $altHost = (string)($srv['alt_host'] ?? '');
            if ($ip === '' || $health === '' || $altHost === '' || in_array($ip, $mine, true)) {
                continue;   // a uno mismo no se le vigila la entrada desde dentro
            }
            $st = $state[$ip] ?? ['mode' => 'primary', 'bad' => 0, 'good' => 0, 'alt_ip' => '', 'since' => ''];

            // Entrada normal: comprobación propia + testigos.
            $own = self::probe($health, $ip);
            $ownBad = !$own['ok'] || ($c['max_latency_ms'] > 0 && $own['ms'] > $c['max_latency_ms']);
            $wv = WitnessService::verdicts($ip, $health, (int)$c['max_latency_ms'], (int)$c['max_loss_pct'], $answers);
            $wBad = count(array_filter($wv, static fn($x) => $x !== 'up'));
            $wUp = count(array_filter($wv, static fn($x) => $x === 'up'));
            // Mala: la veo mal yo Y (algún testigo también, o no hay testigos que respondan).
            // Buena: la veo bien yo y ningún testigo la ve caída.
            $primaryBad = $ownBad && ($wBad > 0 || !$wv);
            $primaryGood = !$ownBad && !in_array('down', $wv, true);

            // Entrada alternativa.
            $altIp = self::resolve($altHost);
            $altOk = $altIp !== '' && (self::probe($health, $altIp)['ok'] || in_array('up', self::altVerdicts($health, $altIp, $answers), true));

            // Correo: cómo se presenta este servidor por su entrada normal (saludo SMTP),
            // para reconocerlo por la alternativa. Se apunta mientras la normal va bien.
            if ($primaryGood && time() - (int)($st['smtp_at'] ?? 0) >= 3600) {
                $st['smtp_banner'] = self::smtpBanner($ip);
                $st['smtp_at'] = time();
            }

            $st['bad'] = $primaryBad ? ($st['bad'] ?? 0) + 1 : 0;
            $st['good'] = $primaryGood ? ($st['good'] ?? 0) + 1 : 0;
            $line = "{$health} ({$ip}): normal " . ($primaryBad ? 'MAL' : ($primaryGood ? 'bien' : 'dudosa'))
                . " [yo: " . ($own['ok'] ? $own['ms'] . ' ms' : 'no llega') . '; testigos: ' . ($wv ? json_encode($wv) : 'sin respuesta') . ']'
                . ", alternativa " . ($altOk ? "bien ({$altIp})" : 'MAL') . ", modo {$st['mode']}";

            if ($st['mode'] === 'primary' && $st['bad'] >= (int)$c['fail_minutes'] && $altOk) {
                $mailAlt = ($st['smtp_banner'] ?? '') !== '' && self::smtpBanner($altIp) === $st['smtp_banner'];
                $r = self::moveAll($ip, $altIp, false, $mailAlt);
                if ($r['moved'] === 0 && !$r['failed']) {
                    // Nada apunta a esa IP (p. ej. el servidor es copia y no sirve webs): no hay entrada que cambiar.
                    $state[$ip] = $st;
                    $log[] = $line . ' → nada que mover (ningún registro apunta a esa IP)';
                    continue;
                }
                $st = ['mode' => 'alternate', 'bad' => 0, 'good' => 0, 'alt_ip' => $altIp, 'since' => date('Y-m-d H:i:s'),
                    'smtp_banner' => $st['smtp_banner'] ?? '', 'smtp_at' => $st['smtp_at'] ?? 0, 'mail_moved' => $mailAlt];
                $line .= " → ENTRADA CAMBIADA a la alternativa: {$r['moved']} registros";
                LogService::log('ingress.watch', 'to-alternate', "{$health}: entrada {$ip} → {$altIp} ({$r['moved']} registros)");
                self::notify("Entrada cambiada: {$health} por la alternativa",
                    "La entrada normal ({$ip}) no responde o va muy lenta" . ($wv ? ' (testigos: ' . json_encode($wv) . ')' : '') . ".\n"
                    . "El servidor sigue bien por la entrada alternativa ({$altHost} = {$altIp}), así que se han movido {$r['moved']} registros DNS allí.\n"
                    . ($mailAlt ? "El correo entrante (MX) también va por la alternativa: allí responde el mismo servidor de correo.\n"
                        : "El correo entrante (MX) NO se ha movido: por la alternativa no responde el mismo servidor de correo (puerto 25). Mientras dure, el correo espera en los servidores que lo envían y llega al volver.\n")
                    . "No se ha cambiado de servidor. Se devolverá sola cuando la entrada normal lleve {$c['recover_minutes']} min estable."
                    . ($r['failed'] ? "\n\nNo se pudieron mover: " . implode(', ', $r['failed']) : ''));
            } elseif ($st['mode'] === 'alternate') {
                // La IP alternativa puede cambiar (línea dinámica): seguirla.
                if ($altIp !== '' && $altIp !== ($st['alt_ip'] ?? '')) {
                    $r = self::moveAll((string)$st['alt_ip'], $altIp, true, true);
                    $line .= " → IP alternativa cambiada {$st['alt_ip']} → {$altIp} ({$r['moved']} registros)";
                    $st['alt_ip'] = $altIp;
                }
                if ($st['good'] >= (int)$c['recover_minutes']) {
                    $r = self::revert((string)$st['alt_ip']);
                    $line .= " → ENTRADA DEVUELTA a la normal: {$r['updated']} registros";
                    LogService::log('ingress.watch', 'to-primary', "{$health}: entrada devuelta a {$ip} ({$r['updated']} registros)");
                    self::notify("Entrada devuelta: {$health} por la normal",
                        "La entrada normal ({$ip}) lleva {$c['recover_minutes']} min estable: se han devuelto {$r['updated']} registros DNS.");
                    $st = ['mode' => 'primary', 'bad' => 0, 'good' => 0, 'alt_ip' => '', 'since' => date('Y-m-d H:i:s'),
                        'smtp_banner' => $st['smtp_banner'] ?? '', 'smtp_at' => $st['smtp_at'] ?? 0];
                }
            }
            $state[$ip] = $st;
            $log[] = $line;
        }
        self::saveState($state);
        return ['log' => $log];
    }

    /** Vuelve a dejar la entrada normal ya (orden manual). */
    public static function forcePrimary(): array
    {
        $out = [];
        foreach (self::state() as $ip => $st) {
            if (($st['mode'] ?? '') === 'alternate') {
                $out[$ip] = self::revert((string)$st['alt_ip']);
            }
        }
        $state = self::state();
        foreach ($state as $ip => $st) {
            $state[$ip] = ['mode' => 'primary', 'bad' => 0, 'good' => 0, 'alt_ip' => '', 'since' => date('Y-m-d H:i:s')];
        }
        self::saveState($state);
        return $out;
    }

    // ── DNS ──────────────────────────────────────────────────────────────

    /**
     * Mueve los registros A de $from a $to en todas las zonas, menos nombres de máquina y
     * destinos de MX ($withMail: también los MX, porque por la alternativa responde el
     * mismo servidor de correo). $followJournal: cambio de la IP alternativa (también se
     * actualiza el diario, para que la vuelta los encuentre).
     */
    private static function moveAll(string $from, string $to, bool $followJournal, bool $withMail = false): array
    {
        $mx = $withMail ? [] : self::mxTargets();
        $moved = 0;
        $failed = [];
        $ttl = 60;
        foreach (CloudflareService::getConfiguredAccounts() as $acct) {
            foreach (($acct['zones'] ?? []) as $zone) {
                $r = CloudflareService::listARecordsByIp((string)$acct['token'], (string)$zone['id'], $from);
                foreach (($r['ok'] ? ($r['result'] ?? []) : []) as $rec) {
                    $name = strtolower((string)$rec['name']);
                    if (CloudflareService::isFailoverExcluded($name) || isset($mx[$name])) {
                        continue;
                    }
                    $u = CloudflareService::updateRecord((string)$acct['token'], (string)$zone['id'], (string)$rec['id'], [
                        'type' => 'A', 'name' => $rec['name'], 'content' => $to,
                        'ttl' => !empty($rec['proxied']) ? 1 : $ttl, 'proxied' => $rec['proxied'] ?? false,
                    ]);
                    if (!empty($u['ok'])) {
                        $moved++;
                        if (!$followJournal) {
                            CloudflareService::journalAdd(self::JOURNAL, (string)$zone['id'], $rec, $from, $to);
                        }
                    } else {
                        $failed[] = $name;
                    }
                }
            }
        }
        if ($followJournal) {
            $list = json_decode((string)Settings::get(self::JOURNAL, '[]'), true) ?: [];
            foreach ($list as &$e) {
                if (($e['to'] ?? '') === $from) {
                    $e['to'] = $to;
                }
            }
            unset($e);
            Settings::set(self::JOURNAL, json_encode(array_values($list), JSON_UNESCAPED_SLASHES));
        }
        return ['moved' => $moved, 'failed' => $failed];
    }

    private static function revert(string $altIp): array
    {
        $tot = ['updated' => 0, 'failed' => 0];
        foreach (CloudflareService::getConfiguredAccounts() as $acct) {
            foreach (($acct['zones'] ?? []) as $zone) {
                $r = CloudflareService::revertJournal(self::JOURNAL, (string)$acct['token'], (string)$zone['id'], $altIp, 300);
                $tot['updated'] += (int)($r['updated'] ?? 0);
                $tot['failed'] += (int)($r['failed'] ?? 0);
            }
        }
        return $tot;
    }

    /** Nombres a los que apunta algún MX (correo): no se mueven, el proxy solo lleva 80/443. */
    private static function mxTargets(): array
    {
        $t = [];
        foreach (CloudflareService::getConfiguredAccounts() as $acct) {
            foreach (($acct['zones'] ?? []) as $zone) {
                $r = CloudflareService::listRecordsAll((string)$acct['token'], (string)$zone['id'], ['type' => 'MX']);
                foreach (($r['ok'] ? ($r['result'] ?? []) : []) as $x) {
                    $t[strtolower(rtrim((string)$x['content'], '.'))] = true;
                }
            }
        }
        return $t;
    }

    // ── Comprobaciones ───────────────────────────────────────────────────

    /** GET https://<nombre>/ conectando a una IP concreta (SNI = nombre), certificado verificado. */
    private static function probe(string $name, string $ip): array
    {
        $ch = curl_init("https://{$name}/");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RESOLVE => ["{$name}:443:{$ip}"], CURLOPT_NOSIGNAL => true]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ms = (int)round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        curl_close($ch);
        return ['ok' => $code >= 200 && $code < 400 && str_starts_with($body, 'ok-'), 'ms' => $ms];
    }

    /**
     * Saludo SMTP (puerto 25) de una IP, sin la parte variable: «220 mail.ejemplo.com ESMTP».
     * Vacío si no responde. Sirve para saber que por otra IP contesta el MISMO servidor de
     * correo (si contestara otro, el correo se rebotaría: mejor no mover el MX).
     */
    private static function smtpBanner(string $ip): string
    {
        $fp = @fsockopen($ip, 25, $e, $s, 6);
        if (!$fp) {
            return '';
        }
        stream_set_timeout($fp, 8);
        $line = (string)fgets($fp, 512);
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        if (!preg_match('/^220[ -](\S+)/', $line, $m)) {
            return '';
        }
        return '220 ' . strtolower($m[1]);
    }

    private static function resolve(string $host): string
    {
        $r = @dns_get_record($host, DNS_A);
        return (string)($r[0]['ip'] ?? '');
    }

    private static function notify(string $subject, string $msg): void
    {
        try {
            NotificationService::send($subject, $msg);
        } catch (\Throwable) {
        }
    }
}
