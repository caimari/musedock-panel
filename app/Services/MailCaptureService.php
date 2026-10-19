<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Correo que mandan las webs (PHP mail(), wp_mail()) por el sendmail local, sin autenticar.
 *
 * Por hosting hay tres modos, que se aplican en su pool de PHP-FPM con sendmail_path:
 *   allow    como siempre: PHP manda por el Postfix local (sin autenticar; Gmail lo rechaza y una web hackeada puede
 *            mandar spam desde la IP del servidor);
 *   capture  el mensaje se guarda en una carpeta del servidor (con qué web, usuario, hora y destinatarios) y no sale;
 *   block    no sale ni se guarda entero (solo cabecera y 4 KB) y PHP recibe un error.
 * Las webs que envían por SMTP autenticado (plugin de WordPress, configuración de la app) no pasan por sendmail y no se ven afectadas.
 *
 * No toca Postfix (cron y avisos del sistema siguen funcionando) ni pisa un sendmail_path propio del administrador.
 */
final class MailCaptureService
{
    public const MODES = ['allow', 'capture', 'block'];
    public const BIN = '/usr/local/sbin/musedock-mail-capture';
    public const SPOOL = '/var/spool/musedock-mail-capture';
    public const MAX_KEEP_DAYS = 30;
    public const MAX_KEEP_FILES = 3000;

    /** sendmail_path de un modo para un dominio. null = allow (sin directiva). Puro. */
    public static function sendmailPath(string $domain, string $mode): ?string
    {
        $domain = preg_replace('/[^a-z0-9.-]/', '', strtolower($domain));
        if ($mode === 'allow' || !in_array($mode, self::MODES, true) || $domain === '') {
            return null;
        }
        return self::BIN . " --site={$domain} --mode={$mode}";
    }

    /** Modo que indica el texto de un pool. 'custom' = sendmail_path que no es del panel. Puro. */
    public static function modeFromPool(string $poolContent): string
    {
        if (!preg_match('/^\s*php_(?:admin_)?value\[sendmail_path\]\s*=\s*(.+)$/m', $poolContent, $m)) {
            return 'allow';
        }
        $val = trim($m[1]);
        if (!str_contains($val, 'musedock-mail-capture')) {
            return 'custom';
        }
        return preg_match('/--mode=block\b/', $val) ? 'block' : 'capture';
    }

    /**
     * Pool con el modo aplicado (o quitado). Puro. @return array{0:?string,1:string} [contenido|null, error]
     * null + error = no se puede (sendmail_path propio del administrador).
     */
    public static function poolWithMode(string $pool, string $domain, string $mode): array
    {
        $current = self::modeFromPool($pool);
        if ($current === 'custom') {
            return [null, 'Este hosting tiene un sendmail_path propio (no del panel): no se pisa. Quítalo a mano del pool si quieres que lo gestione el panel.'];
        }
        $line = self::sendmailPath($domain, $mode);
        $pattern = '/^\s*php_(?:admin_)?value\[sendmail_path\]\s*=.*\R?/m';
        $pool = preg_replace($pattern, '', $pool) ?? $pool;
        if ($line === null) {
            return [$pool, ''];
        }
        $new = "php_admin_value[sendmail_path] = {$line}";
        if (strpos($pool, 'security.limit_extensions') !== false) {
            $pool = preg_replace('/^(security\.limit_extensions\s*=)/m', $new . "\n\n$1", $pool, 1);
        } else {
            $pool = rtrim($pool) . "\n{$new}\n";
        }
        return [$pool, ''];
    }

    /** Modo por defecto de los hostings NUEVOS: lo fija el administrador; sin fijar, 'capture' en una instalación sin hostings y 'allow' en una con ellos. */
    public static function defaultMode(): string
    {
        $m = strtolower(trim((string)Settings::get('hosting_mail_default_mode', '')));
        if (in_array($m, self::MODES, true)) {
            return $m;
        }
        try {
            $n = (int)(\MuseDockPanel\Database::fetchOne('SELECT COUNT(*) AS n FROM hosting_accounts')['n'] ?? 0);
        } catch (\Throwable) {
            $n = 1;
        }
        return $n === 0 ? 'capture' : 'allow';
    }

    /** Modo actual de una cuenta (lee su pool). */
    public static function modeOf(array $account): string
    {
        $file = HostingPhpSettingsService::poolFile($account);
        return is_file($file) ? self::modeFromPool((string)@file_get_contents($file)) : 'allow';
    }

    /** Pone el programa y la carpeta de captura en este nodo (idempotente). @return ''|string error */
    public static function ensureInstalled(): string
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return 'hay que ser root para instalar el programa de captura';
        }
        $src = (defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2)) . '/bin/musedock-mail-capture.php';
        if (!is_file($src)) {
            return 'falta bin/musedock-mail-capture.php';
        }
        if (!is_dir(self::SPOOL) && !@mkdir(self::SPOOL, 01733, true) && !is_dir(self::SPOOL)) {
            return 'no se pudo crear ' . self::SPOOL;
        }
        @chown(self::SPOOL, 'root');
        @chgrp(self::SPOOL, 'root');
        @chmod(self::SPOOL, 01733);
        if (!is_file(self::BIN) || md5_file(self::BIN) !== md5_file($src)) {
            $tmp = self::BIN . '.new';
            if (!@copy($src, $tmp)) {
                return 'no se pudo copiar el programa a ' . self::BIN;
            }
            @chown($tmp, 'root');
            @chgrp($tmp, 'root');
            @chmod($tmp, 0755);
            @rename($tmp, self::BIN);
        }
        return '';
    }

    /**
     * Cambia el modo de una cuenta: instala el programa si hace falta, edita su pool, comprueba con php-fpm -t
     * (si falla, lo deja como estaba) y recarga PHP-FPM. @return array{ok:bool,error?:string,mode?:string}
     */
    public static function setMode(array $account, string $mode): array
    {
        if (!in_array($mode, self::MODES, true)) {
            return ['ok' => false, 'error' => 'Modo no válido: allow, capture o block.'];
        }
        $file = HostingPhpSettingsService::poolFile($account);
        if (!is_file($file)) {
            return ['ok' => false, 'error' => 'No se encontró el archivo de pool FPM.'];
        }
        $original = (string)@file_get_contents($file);
        [$new, $err] = self::poolWithMode($original, (string)($account['domain'] ?? ''), $mode);
        if ($new === null) {
            return ['ok' => false, 'error' => $err];
        }
        if ($new === $original) {
            return ['ok' => true, 'mode' => $mode];
        }
        if ($mode !== 'allow') {
            $e = self::ensureInstalled();
            if ($e !== '') {
                return ['ok' => false, 'error' => $e];
            }
        }
        if (@file_put_contents($file, $new) === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir el pool.'];
        }
        $ver = preg_replace('/[^0-9.]/', '', (string)($account['php_version'] ?? ''));
        $out = [];
        $rc = 0;
        @exec("php-fpm{$ver} -t 2>&1", $out, $rc);
        if ($rc !== 0) {
            @file_put_contents($file, $original);
            return ['ok' => false, 'error' => 'PHP-FPM rechaza la configuración; se deja como estaba: ' . trim(implode(' ', array_slice($out, -2)))];
        }
        @shell_exec("systemctl reload php{$ver}-fpm 2>&1");
        try {
            LogService::log('account.mail_mode', (string)($account['domain'] ?? ''), "Correo de la web (PHP): {$mode}");
        } catch (\Throwable) {
        }
        return ['ok' => true, 'mode' => $mode];
    }

    // ── Auditoría: quién manda correo por el Postfix local (solo lectura del registro) ──

    /**
     * Resume un registro de Postfix: por cada usuario local (uid del «pickup», es decir, lo que entra por sendmail)
     * cuántos mensajes, a cuántos destinatarios, con qué resultado y con qué remitentes. Pura.
     * @param iterable<string> $lines
     * @param list<string> $localDomains dominios de ESTE servidor: un mensaje solo para ellos es correo del sistema, no de una web
     * @return array<int,array{uid:int,messages:int,recipients:int,sent:int,bounced:int,deferred:int,froms:array<string,int>,domains:array<string,int>}>
     */
    public static function auditParse(iterable $lines, array $localDomains = []): array
    {
        $local = array_flip(array_map('strtolower', $localDomains));
        $byId = [];   // queue id => ['uid'=>, 'from'=>, 'to'=>[...], 'st'=>[...], 'why'=>[...]]
        foreach ($lines as $l) {
            if (!preg_match('/postfix\/(\w+)\[\d+\]: ([0-9A-Za-z]{6,}): (.*)$/', (string)$l, $m)) {
                continue;
            }
            [, $proc, $id, $rest] = $m;
            if ($proc === 'pickup' && preg_match('/uid=(\d+) from=<([^>]*)>/', $rest, $u)) {
                $byId[$id] = ['uid' => (int)$u[1], 'from' => $u[2], 'to' => [], 'st' => [], 'why' => []];
            } elseif (isset($byId[$id]) && in_array($proc, ['smtp', 'error', 'local', 'virtual', 'lmtp', 'relay'], true)
                && preg_match('/to=<([^>]*)>.*status=(\w+)/', $rest, $t)) {
                $byId[$id]['to'][$t[1]] = true;
                $byId[$id]['st'][] = $t[2];
                // motivo del rechazo (p. ej. 5.7.26 de Gmail: remitente sin autenticar), tras «status=…» como
                // «550-5.7.26» o «550 5.1.1», para no confundirlo con una IP
                if ($t[2] !== 'sent' && preg_match('/status=\w+ \((.*)$/', $rest, $sm) && preg_match('/\b\d{3}[ -]([245]\.\d\.\d{1,3})\b/', $sm[1], $r)) {
                    $byId[$id]['why'][] = $r[1];
                }
            }
        }
        $zero = ['messages' => 0, 'recipients' => 0, 'sent' => 0, 'bounced' => 0, 'deferred' => 0, 'reasons' => []];
        $out = [];
        foreach ($byId as $q) {
            $uid = $q['uid'];
            $out[$uid] ??= ['uid' => $uid, 'froms' => [], 'domains' => [], 'sys_messages' => 0] + $zero
                + ['web_messages' => 0, 'web_recipients' => 0, 'web_sent' => 0, 'web_bounced' => 0, 'web_deferred' => 0, 'web_reasons' => []];
            // Correo del sistema (cron, root…): todos sus destinatarios son de este servidor
            $domains = [];
            foreach (array_keys($q['to']) as $to) {
                $domains[] = strtolower(substr((string)strrchr($to, '@'), 1)) ?: '(local)';
            }
            $sys = $domains !== [] && count(array_filter($domains, static fn($d) => $d === '(local)' || isset($local[$d]))) === count($domains);
            $out[$uid]['messages']++;
            $out[$uid]['froms'][$q['from']] = ($out[$uid]['froms'][$q['from']] ?? 0) + 1;
            $out[$uid]['recipients'] += count($domains);
            foreach ($domains as $d) {
                $out[$uid]['domains'][$d] = ($out[$uid]['domains'][$d] ?? 0) + 1;
            }
            if ($sys) {
                $out[$uid]['sys_messages']++;
            } else {
                $out[$uid]['web_messages']++;
                $out[$uid]['web_recipients'] += count($domains);
            }
            foreach ($q['st'] as $st) {
                if (isset($out[$uid][$st])) {
                    $out[$uid][$st]++;
                    if (!$sys) {
                        $out[$uid]['web_' . $st]++;
                    }
                }
            }
            foreach ($q['why'] as $w) {
                $out[$uid]['reasons'][$w] = ($out[$uid]['reasons'][$w] ?? 0) + 1;
                if (!$sys) {
                    $out[$uid]['web_reasons'][$w] = ($out[$uid]['web_reasons'][$w] ?? 0) + 1;
                }
            }
        }
        uasort($out, static fn($a, $b) => $b['messages'] <=> $a['messages']);
        return $out;
    }

    // ── Mensajes capturados (solo root los lee) ──

    /** Cabeceras que importan de un .eml capturado. Puro. @return array<string,string> */
    public static function parseHead(string $raw): array
    {
        $head = preg_split('/\R\R/', $raw, 2)[0] ?? '';
        $out = [];
        $last = '';
        foreach (preg_split('/\R/', $head) ?: [] as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $last !== '') {
                $out[$last] .= ' ' . trim($line);
            } elseif (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
                $last = strtolower($m[1]);
                $out[$last] = $m[2];
            }
        }
        return $out;
    }

    /** Lista los capturados, el más reciente primero. */
    public static function list(int $limit = 200): array
    {
        $rows = [];
        $files = glob(self::SPOOL . '/*.eml') ?: [];
        rsort($files);
        foreach (array_slice($files, 0, max(1, $limit)) as $f) {
            $h = self::parseHead((string)@file_get_contents($f, false, null, 0, 8192));
            $rows[] = [
                'file' => basename($f),
                'site' => $h['x-musedock-capture-site'] ?? '',
                'user' => $h['x-musedock-capture-user'] ?? '',
                'mode' => $h['x-musedock-capture-mode'] ?? '',
                'time' => $h['x-musedock-capture-time'] ?? gmdate('c', (int)@filemtime($f)),
                'to' => $h['to'] ?? '',
                'subject' => $h['subject'] ?? '',
                'from' => $h['from'] ?? ($h['x-musedock-capture-envelope-from'] ?? ''),
                'size' => (int)@filesize($f),
            ];
        }
        return $rows;
    }

    public static function safeName(string $name): bool
    {
        return (bool)preg_match('/^\d+-\d+-[a-f0-9]{8}\.eml$/', $name);
    }

    public static function read(string $name): ?string
    {
        if (!self::safeName($name) || !is_file(self::SPOOL . '/' . $name)) {
            return null;
        }
        return (string)@file_get_contents(self::SPOOL . '/' . $name);
    }

    public static function delete(string $name): bool
    {
        return self::safeName($name) && @unlink(self::SPOOL . '/' . $name);
    }

    /** Limpieza: más de MAX_KEEP_DAYS días o por encima de MAX_KEEP_FILES. @return int borrados */
    public static function prune(): int
    {
        $files = glob(self::SPOOL . '/*.eml') ?: [];
        sort($files);
        $n = 0;
        $limit = time() - self::MAX_KEEP_DAYS * 86400;
        $excess = max(0, count($files) - self::MAX_KEEP_FILES);
        foreach ($files as $i => $f) {
            if ($i < $excess || (int)@filemtime($f) < $limit) {
                if (@unlink($f)) {
                    $n++;
                }
            }
        }
        // los contadores de ritmo de usuarios que ya no mandan
        foreach (glob(self::SPOOL . '/.rate-*') ?: [] as $f) {
            if ((int)@filemtime($f) < time() - 2 * 86400) {
                @unlink($f);
            }
        }
        return $n;
    }

    /** ¿Algún pool de este nodo usa el programa de captura? (para mantenerlo instalado en réplicas) */
    public static function inUse(): bool
    {
        foreach (glob('/etc/php/*/fpm/pool.d/*.conf') ?: [] as $f) {
            if (str_contains((string)@file_get_contents($f), 'musedock-mail-capture')) {
                return true;
            }
        }
        return false;
    }

    /** Cron del clúster, una vez cada 30 min: mantiene el programa instalado donde los pools lo usan y limpia lo viejo. */
    public static function periodic(): string
    {
        if (time() - (int)Settings::get('mail_capture_run_at', '0') < 1800) {
            return '';
        }
        Settings::set('mail_capture_run_at', (string)time());
        if (!self::inUse()) {
            return '';
        }
        $e = self::ensureInstalled();
        $n = $e === '' ? self::prune() : 0;
        return $e !== '' ? "captura de correo de webs: {$e}" : ($n > 0 ? "captura de correo de webs: {$n} mensajes viejos borrados" : '');
    }
}
