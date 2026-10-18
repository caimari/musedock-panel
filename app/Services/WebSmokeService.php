<?php
namespace MuseDockPanel\Services;

/**
 * Prueba de humo de las webs de un servidor: pide la portada de cada una contra 127.0.0.1 (como si el DNS
 * ya apuntara aquí) y, si redirige, la página a la que va. Un 5xx o ninguna respuesta es un error, y se
 * dice la causa leyendo la última línea de error de la propia web.
 *
 * Nace del relevo del 2026-10-18: varias webs de la réplica daban 500 (ficheros que su usuario no podía
 * leer, carpetas de Laravel que no existían) y nadie lo supo hasta que alguien las abrió.
 * Solo LEE: no cambia nada. Se lanza al promocionar un nodo (en segundo plano) y a mano (bin/web-smoke.php,
 * herramienta MCP webs_smoke_check).
 */
class WebSmokeService
{
    /** Resultado de una petición a partir de lo que imprime curl. Pura. */
    public static function parseCurl(string $out): array
    {
        $f = explode("\t", trim($out), 2);
        return ['code' => (int)($f[0] ?? 0), 'redirect' => trim((string)($f[1] ?? ''))];
    }

    /** ¿Es un error? 5xx o sin respuesta (0). Un 4xx en la portada no es una web rota (puede pedir login). Pura. */
    public static function isError(int $code): bool
    {
        return $code === 0 || $code >= 500;
    }

    /** Última línea que explica un error en un registro (la última que habla de error; si no, la última). Pura. */
    public static function lastErrorLine(string $tail): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $tail) ?: []), static fn($l) => $l !== ''));
        // 1) El formato de registro de Laravel/Monolog («[fecha] production.ERROR: mensaje»): la causa real, que
        //    queda MÁS ARRIBA que las muchas líneas de traza («#12 {main}», «"}»).
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match('/\.(ERROR|CRITICAL|EMERGENCY|ALERT):/', $lines[$i])) {
                return mb_substr($lines[$i], 0, 300);
            }
        }
        // 2) Cualquier línea que hable de error (PHP, Apache, Caddy…)
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match('/error|exception|fatal|permission denied|denegado|not found|failed/i', $lines[$i])) {
                return mb_substr($lines[$i], 0, 300);
            }
        }
        return $lines ? mb_substr(end($lines), 0, 300) : '';
    }

    private static function request(string $host, string $path = '/'): array
    {
        $cmd = 'curl -sk -o /dev/null -m 10 --connect-timeout 4 -w ' . escapeshellarg("%{http_code}\t%{redirect_url}")
            . ' --resolve ' . escapeshellarg("{$host}:443:127.0.0.1") . ' --resolve ' . escapeshellarg("{$host}:80:127.0.0.1")
            . ' ' . escapeshellarg("https://{$host}{$path}") . ' 2>/dev/null';
        return self::parseCurl((string)shell_exec($cmd));
    }

    /** La causa más probable de un error en una web: último error de su registro. */
    public static function reasonFor(string $homeDir): string
    {
        $cands = [$homeDir . '/httpdocs/storage/logs/laravel.log', $homeDir . '/logs/php-error.log', $homeDir . '/logs/error.log', $homeDir . '/httpdocs/php-error.log'];
        $best = '';
        $bestTime = 0;
        foreach ($cands as $f) {
            $t = @filemtime($f);
            if ($t !== false && @is_readable($f) && $t > $bestTime) {
                $best = $f;
                $bestTime = $t;
            }
        }
        if ($best === '') {
            return '';
        }
        $line = self::lastErrorLine((string)shell_exec('tail -n 400 ' . escapeshellarg($best) . ' 2>/dev/null'));
        return $line !== '' ? basename($best) . ': ' . $line : '';
    }

    /**
     * Prueba cada web activa. Devuelve ['results' => [...], 'errors' => n, 'ok' => n]. Cada resultado:
     * domain, code, redirect, final (código tras seguir UNA redirección a otra web de este servidor), error, reason.
     */
    public static function run(?string $onlyDomain = null): array
    {
        $accounts = \MuseDockPanel\Database::fetchAll("SELECT domain, home_dir FROM hosting_accounts WHERE status = 'active' ORDER BY domain");
        $ours = [];
        foreach ($accounts as $a) {
            $ours[strtolower($a['domain'])] = true;
            $ours['www.' . strtolower($a['domain'])] = true;
        }
        $results = [];
        foreach ($accounts as $a) {
            if ($onlyDomain !== null && $onlyDomain !== '' && strcasecmp($a['domain'], $onlyDomain) !== 0) {
                continue;
            }
            $r = self::request($a['domain']);
            $final = $r['code'];
            $note = '';
            if ($r['code'] >= 300 && $r['code'] < 400 && $r['redirect'] !== '') {
                $u = parse_url($r['redirect']);
                $h = strtolower((string)($u['host'] ?? ''));
                if ($h !== '' && isset($ours[$h])) {
                    $path = ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
                    $second = self::request($h, $path === '' ? '/' : $path);
                    $final = $second['code'];
                    $note = "→ {$h}{$path}";
                } else {
                    $note = '→ ' . ($h !== '' ? $h : 'otra dirección') . ' (fuera de este servidor, no se sigue)';
                }
            }
            $isErr = self::isError($final);
            $results[] = [
                'domain' => $a['domain'], 'code' => $r['code'], 'redirect' => $r['redirect'], 'final' => $final, 'note' => $note,
                'error' => $isErr, 'reason' => $isErr ? self::reasonFor((string)$a['home_dir']) : '',
            ];
        }
        $errors = count(array_filter($results, static fn($x) => $x['error']));
        return ['results' => $results, 'errors' => $errors, 'ok' => count($results) - $errors];
    }

    /** Lanza la prueba en segundo plano unos segundos después (Caddy y PHP-FPM tardan en estar listos tras un cambio de rol). */
    public static function scheduleAfterPromote(int $delaySec = 45): string
    {
        $script = dirname(__DIR__, 2) . '/bin/web-smoke.php';
        shell_exec('nohup sh -c ' . escapeshellarg('sleep ' . (int)$delaySec . '; php ' . escapeshellarg($script) . ' --notify --reason=promocion') . ' >/dev/null 2>&1 &');
        return "prueba de las webs dentro de {$delaySec} s (en segundo plano)";
    }

    /** Avisa si alguna web falla (un solo aviso por conjunto de webs fallidas). */
    public static function notify(array $report, string $reason = ''): void
    {
        $bad = array_values(array_filter($report['results'], static fn($x) => $x['error']));
        $hash = $bad ? md5(implode(',', array_column($bad, 'domain'))) : '';
        if ($hash === (string)\MuseDockPanel\Settings::get('web_smoke_alert_hash', '')) {
            return;
        }
        \MuseDockPanel\Settings::set('web_smoke_alert_hash', $hash);
        $host = (string)gethostname();
        if (!$bad) {
            if ($reason !== '') {
                NotificationService::send("Webs de {$host}: todas responden", "Prueba tras {$reason}: las {$report['ok']} webs responden sin error.", 'web_errors');
            }
            return;
        }
        $lines = array_map(static fn($x) => "• {$x['domain']}: " . ($x['final'] === 0 ? 'sin respuesta' : "HTTP {$x['final']}") . ($x['note'] !== '' ? " {$x['note']}" : '') . ($x['reason'] !== '' ? "\n    {$x['reason']}" : ''), $bad);
        NotificationService::send("Webs con error en {$host}" . ($reason !== '' ? " tras {$reason}" : ''),
            count($bad) . " de " . count($report['results']) . " webs fallan al pedirlas contra este servidor:\n\n" . implode("\n", $lines)
            . "\n\nProbado con la portada de cada web contra 127.0.0.1. Detalle: php bin/web-smoke.php", 'web_errors');
    }
}
