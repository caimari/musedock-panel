<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;

/**
 * Informe del correo de las webs: por hosting, qué manda por el Postfix local (sendmail), con qué resultado y por qué
 * rebota, si ya tiene SMTP autenticado, en qué modo está su envío local y si su dominio tiene SPF/DMARC; y aparte el
 * correo del sistema (cron, root). Solo lectura. Fuentes: registro de Postfix (la hora es la del servidor de ese
 * nodo: en un servidor en UTC con rsyslog sin reiniciar puede ir en hora local), pools de PHP-FPM y ficheros de cada web.
 */
final class WebMailAuditService
{
    public const CACHE = '/storage/web-mail-audit.json';
    public const CACHE_TTL = 1800;

    /** Registros de Postfix a leer. */
    public static function logFiles(): array
    {
        return array_values(array_filter(['/var/log/mail.log.1', '/var/log/mail.log'], 'is_readable'));
    }

    /** Dominios que son de este servidor (su nombre y los de mydestination): el correo solo para ellos es del sistema. */
    public static function localDomains(): array
    {
        $out = [];
        foreach (['myhostname', 'myorigin', 'mydestination'] as $k) {
            $v = trim((string)@shell_exec('postconf -h -x ' . escapeshellarg($k) . ' 2>/dev/null'));
            if ($k === 'myorigin' && $v !== '' && $v[0] === '/') {
                $v = trim((string)strtok((string)@file_get_contents($v), "\n"));
            }
            foreach (preg_split('/[\s,]+/', strtolower($v)) ?: [] as $d) {
                if ($d !== '' && $d[0] !== '$') {
                    $out[$d] = true;
                }
            }
        }
        return array_keys($out);
    }

    /** Líneas de los registros (generador). */
    private static function lines(array $files): \Generator
    {
        foreach ($files as $f) {
            foreach (new \SplFileObject($f) as $line) {
                yield (string)$line;
            }
        }
    }

    /** SPF y DMARC del dominio (DKIM solo si es un dominio de correo del panel y se conoce su selector). Consulta DNS. */
    public static function dnsCheck(string $domain): array
    {
        $txt = static function (string $name): array {
            $r = @dns_get_record($name, DNS_TXT);
            return is_array($r) ? array_map(static fn($x) => (string)($x['txt'] ?? ''), $r) : [];
        };
        $spf = null;
        foreach ($txt($domain) as $t) {
            if (stripos($t, 'v=spf1') === 0) {
                $spf = $t;
            }
        }
        $dmarc = null;
        foreach ($txt('_dmarc.' . $domain) as $t) {
            if (stripos($t, 'v=DMARC1') === 0) {
                $dmarc = $t;
            }
        }
        $dkim = null;
        $md = MailService::getDomainByName($domain);
        if ($md) {
            $sel = (string)($md['dkim_selector'] ?: 'default');
            $dkim = $txt("{$sel}._domainkey.{$domain}") ? 'publicado' : 'no publicado';
        }
        return ['spf' => $spf, 'dmarc' => $dmarc, 'dkim' => $dkim];
    }

    /** Propuesta en llano para una web. Pura. */
    public static function propose(array $r): string
    {
        $sends = (int)($r['messages'] ?? 0) > 0;
        $bounced = (int)($r['bounced'] ?? 0);
        $managed = !empty($r['smtp']['managed']);
        $mode = (string)($r['mode'] ?? 'allow');
        $out = [];
        if ($managed) {
            $out[] = 'Ya envía por SMTP autenticado (gestionado por el panel).';
            if ($mode === 'allow') {
                $out[] = 'Pasa su envío local a «capturar» (o «bloquear») para que nada salga sin autenticar.';
            }
        } elseif (!empty($r['smtp']['smtp_plugin']) || !empty($r['smtp']['laravel_smtp'])) {
            $out[] = 'Parece tener SMTP propio configurado (' . ($r['smtp']['smtp_plugin'] ?: 'Laravel MAIL_MAILER=smtp') . '): comprueba que lo usa y, si es así, pasa el envío local a «bloquear».';
        } elseif ($sends) {
            $out[] = 'Manda correo sin autenticar' . ($bounced > 0 ? " y {$bounced} se han rechazado" : '') . ': configura su SMTP autenticado y pon el envío local en «capturar».';
        } else {
            $out[] = 'No ha mandado correo por sendmail en el periodo del registro. Sin acción; si no debe enviar, ponla en «capturar» para que quede registrado cualquier intento.';
        }
        if (!empty($r['dns']) && $sends) {
            if (empty($r['dns']['spf'])) {
                $out[] = 'Su dominio no tiene SPF.';
            }
            if (empty($r['dns']['dmarc'])) {
                $out[] = 'Su dominio no tiene DMARC.';
            }
        }
        return implode(' ', $out);
    }

    /** El informe completo. @return array{generated:string,hostings:list<array>,system:list<array>,logs:list<string>} */
    public static function report(bool $withDns = true): array
    {
        $files = self::logFiles();
        $audit = MailCaptureService::auditParse(self::lines($files), self::localDomains());
        $rows = [];
        $hostingUids = [];
        foreach (Database::fetchAll("SELECT * FROM hosting_accounts WHERE status = 'active' ORDER BY domain") as $acc) {
            $uid = function_exists('posix_getpwnam') ? (posix_getpwnam((string)$acc['username'])['uid'] ?? null) : null;
            $a = $uid !== null ? ($audit[$uid] ?? null) : null;
            if ($uid !== null) {
                $hostingUids[$uid] = true;
            }
            if ($a) {
                arsort($a['froms']);
                arsort($a['domains']);
            }
            $row = [
                'id' => (int)$acc['id'],
                'domain' => (string)$acc['domain'],
                'username' => (string)$acc['username'],
                'uid' => $uid,
                'mode' => MailCaptureService::modeOf($acc),
                'smtp' => WebSmtpService::detect($acc),
                'messages' => (int)($a['web_messages'] ?? 0),
                'recipients' => (int)($a['web_recipients'] ?? 0),
                'sent' => (int)($a['web_sent'] ?? 0),
                'bounced' => (int)($a['web_bounced'] ?? 0),
                'deferred' => (int)($a['web_deferred'] ?? 0),
                'system_messages' => (int)($a['sys_messages'] ?? 0),
                'froms' => array_slice(array_keys($a['froms'] ?? []), 0, 3),
                'domains' => array_slice(array_keys($a['domains'] ?? []), 0, 4),
                'reasons' => $a['web_reasons'] ?? [],
                'dns' => $withDns ? self::dnsCheck((string)$acc['domain']) : null,
            ];
            $row['proposal'] = self::propose($row);
            $rows[] = $row;
        }
        usort($rows, static fn($x, $y) => [$y['messages'], $x['domain']] <=> [$x['messages'], $y['domain']]);
        $system = [];
        foreach ($audit as $uid => $a) {
            $name = function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? (string)$uid) : (string)$uid;
            $isHosting = isset($hostingUids[$uid]);
            $msgs = $isHosting ? (int)$a['sys_messages'] : (int)$a['messages'];
            if ($msgs === 0) {
                continue;
            }
            $system[] = ['uid' => $uid, 'name' => $name, 'messages' => $msgs,
                'sent' => $isHosting ? max(0, (int)$a['sent'] - (int)$a['web_sent']) : (int)$a['sent'],
                'bounced' => $isHosting ? max(0, (int)$a['bounced'] - (int)$a['web_bounced']) : (int)$a['bounced'],
                'deferred' => $isHosting ? max(0, (int)$a['deferred'] - (int)$a['web_deferred']) : (int)$a['deferred']];
        }
        return ['generated' => gmdate('c'), 'hostings' => $rows, 'system' => $system, 'logs' => $files];
    }

    /** Informe con caché de 30 min (el DNS de decenas de dominios tarda). */
    public static function cached(bool $refresh = false): array
    {
        $file = (defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2)) . self::CACHE;
        if (!$refresh && is_file($file) && time() - (int)@filemtime($file) < self::CACHE_TTL) {
            $r = json_decode((string)@file_get_contents($file), true);
            if (is_array($r)) {
                return $r;
            }
        }
        $r = self::report(true);
        @file_put_contents($file, json_encode($r, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $r;
    }
}
