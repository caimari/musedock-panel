<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * Vigilante de cambios del sistema: avisa cuando aparece algo nuevo en los sitios donde
 * se suele instalar una app... o esconder un intruso (carpetas de apps, /etc, servicios,
 * tareas programadas, programas en /usr/local, claves SSH, ejecutables en /tmp).
 *
 * Compara con una foto anterior guardada en storage/state (la primera vez solo la toma)
 * y avisa UNA vez de cada novedad. Corre en todos los nodos (cada uno vigila lo suyo).
 * No sustituye a un antivirus ni a un IDS: un intruso con root puede desactivarlo. Es un
 * cable trampa barato que avisa de lo que nadie debería instalar sin saberlo.
 */
class SystemWatchService
{
    public const INTERVAL = 600;
    private const MAX_PER_CAT = 200;

    public const LABELS = [
        'apps'     => 'Carpetas nuevas de aplicaciones (/opt, /srv, /var/www)',
        'vhosts'   => 'Carpetas en /var/www/vhosts que no son de ningún hosting del panel',
        'etc'      => 'Carpetas nuevas en /etc que no instala ningún paquete',
        'systemd'  => 'Servicios de systemd nuevos o cambiados (no de un paquete)',
        'cron'     => 'Tareas programadas nuevas o cambiadas',
        'bin'      => 'Programas nuevos o cambiados en /usr/local/bin o /usr/local/sbin',
        'ssh_keys' => 'Claves SSH autorizadas nuevas o cambiadas',
        'tmp_exec' => 'Ejecutables en /tmp, /var/tmp o /dev/shm',
    ];

    /** Lo que reescribe una actualización del panel: tras actualizar se toma la foto otra vez sin avisar. */
    private const UPDATED_BY_PANEL = ['systemd', 'cron', 'bin'];

    private static function stateFile(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/state';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/system-watch.json';
    }

    private static function panelVersion(): string
    {
        $cfg = @include dirname(__DIR__, 2) . '/config/panel.php';
        return is_array($cfg) ? (string)($cfg['version'] ?? '') : '';
    }

    /** Carpetas de primer nivel (sin ocultas ni enlaces). */
    private static function dirs(string $base): array
    {
        $out = [];
        foreach (glob(rtrim($base, '/') . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $n = basename($d);
            if ($n[0] !== '.' && $n !== 'lost+found' && !is_link($d)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /** Ficheros normales (sin enlaces) que casan con los patrones. */
    private static function files(array $patterns): array
    {
        $out = [];
        foreach ($patterns as $p) {
            foreach (glob($p) ?: [] as $f) {
                if (is_file($f) && !is_link($f)) {
                    $out[] = $f;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** Rutas que pertenecen a algún paquete del sistema (dpkg), de una sola pasada. */
    private static function dpkgOwned(array $paths): array
    {
        $owned = [];
        if (!$paths || !is_executable('/usr/bin/dpkg-query')) {
            return $owned;
        }
        $want = array_fill_keys($paths, true);
        foreach (array_chunk($paths, 100) as $chunk) {
            $lines = [];
            exec('dpkg-query -S ' . implode(' ', array_map('escapeshellarg', $chunk)) . ' 2>/dev/null', $lines);
            foreach ($lines as $l) {
                $p = strrpos($l, ': ');
                if ($p !== false && isset($want[$path = substr($l, $p + 2)])) {
                    $owned[$path] = true;
                }
            }
        }
        return $owned;
    }

    /** Huella de un fichero: contenido si es pequeño; tamaño y fecha si es grande. */
    private static function sig(string $f): string
    {
        $size = (int)@filesize($f);
        return $size <= 2 * 1024 * 1024 ? (string)@hash_file('sha256', $f) : $size . '@' . (int)@filemtime($f);
    }

    /** Carpetas de /var/www/vhosts que usa algún hosting, subdominio o buzón del panel. */
    private static function knownVhosts(): array
    {
        $known = [];
        try {
            $rows = array_merge(
                Database::fetchAll('SELECT home_dir AS p FROM hosting_accounts UNION ALL SELECT document_root FROM hosting_accounts'),
                Database::fetchAll('SELECT document_root AS p FROM hosting_subdomains WHERE document_root IS NOT NULL')
            );
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $r) {
            if (preg_match('#^/var/www/vhosts/([^/]+)#', (string)($r['p'] ?? ''), $m)) {
                $known['/var/www/vhosts/' . $m[1]] = true;
            }
        }
        return $known;
    }

    /** Claves autorizadas como lista legible ("tipo comentario #huella") para decir cuál se añadió. */
    private static function sshKeysSig(string $f): string
    {
        $keys = [];
        foreach (@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $l = trim($l);
            if ($l === '' || $l[0] === '#') {
                continue;
            }
            if (!preg_match('/((?:ssh|ecdsa|sk)-[a-z0-9@.-]+)\s+([A-Za-z0-9+\/=]+)\s*(.*)$/', $l, $m)) {
                $keys[] = 'línea rara #' . substr(md5($l), 0, 8);
                continue;
            }
            $keys[] = $m[1] . ' ' . (trim($m[3]) !== '' ? mb_substr(trim($m[3]), 0, 60) : '(sin comentario)') . ' #' . substr(md5($m[2]), 0, 8);
        }
        sort($keys);
        return implode("\n", $keys);
    }

    /** @return array<string,array<string,string>> categoría => [ruta => huella] */
    public static function snapshot(): array
    {
        $s = array_fill_keys(array_keys(self::LABELS), []);

        $apps = [];
        foreach (['/opt', '/srv', '/var/www'] as $base) {
            foreach (self::dirs($base) as $d) {
                if ($d !== '/var/www/vhosts' && count(@scandir($d) ?: []) > 2) {
                    $apps[] = $d;
                }
            }
        }
        $etc = self::dirs('/etc');
        $units = self::files(['/etc/systemd/system/*.service', '/etc/systemd/system/*.timer', '/etc/systemd/system/*.socket',
            '/etc/systemd/system/*.path', '/etc/systemd/system/*.d/*.conf']);
        $owned = self::dpkgOwned(array_merge($apps, $etc, $units));

        foreach ($apps as $d) {
            if (!isset($owned[$d])) {
                $s['apps'][$d] = 'dir';
            }
        }
        foreach ($etc as $d) {
            if (!isset($owned[$d])) {
                $s['etc'][$d] = 'dir';
            }
        }
        foreach ($units as $f) {
            if (!isset($owned[$f])) {
                $s['systemd'][$f] = self::sig($f);
            }
        }

        // Hostings: una carpeta recién creada puede no haber llegado aún a la base (réplica):
        // las de menos de 30 min se miran en la siguiente vuelta.
        $known = self::knownVhosts();
        if ($known) {
            foreach (self::dirs('/var/www/vhosts') as $d) {
                if (!isset($known[$d]) && time() - (int)@filectime($d) > 1800) {
                    $s['vhosts'][$d] = 'dir';
                }
            }
        }

        foreach (self::files(['/etc/crontab', '/etc/cron.d/*', '/etc/cron.hourly/*', '/etc/cron.daily/*', '/etc/cron.weekly/*',
            '/etc/cron.monthly/*', '/var/spool/cron/crontabs/*']) as $f) {
            $s['cron'][$f] = self::sig($f);
        }
        foreach (self::files(['/usr/local/bin/*', '/usr/local/sbin/*']) as $f) {
            $s['bin'][$f] = self::sig($f);
        }
        foreach (self::files(['/root/.ssh/authorized_keys', '/root/.ssh/authorized_keys2', '/home/*/.ssh/authorized_keys',
            '/var/www/vhosts/*/.ssh/authorized_keys']) as $f) {
            $s['ssh_keys'][$f] = self::sshKeysSig($f);
        }

        $found = [];
        exec('find /tmp /var/tmp /dev/shm -xdev -maxdepth 3 -type f -perm /111 -size -100M -not -path "*/systemd-private-*" 2>/dev/null | head -n '
            . self::MAX_PER_CAT, $found);
        foreach ($found as $f) {
            $s['tmp_exec'][$f] = '1';
        }

        foreach ($s as $cat => $items) {
            if (count($items) > self::MAX_PER_CAT) {
                $s[$cat] = array_slice($items, 0, self::MAX_PER_CAT, true);
            }
            ksort($s[$cat]);
        }
        return $s;
    }

    /** Patrones que no se vigilan (Avisos → "Cambios del sistema: ignorar"). Admite "host:patrón". */
    public static function ignored(string $path): bool
    {
        $host = strtolower(explode('.', (string)gethostname())[0]);
        foreach (AlertPolicyService::systemWatchIgnore() as $p) {
            [$h, $pat] = str_contains($p, ':') ? explode(':', $p, 2) : ['', $p];
            if (($h === '' || strtolower($h) === $host) && $pat !== '' && (fnmatch($pat, $path) || str_starts_with($path, rtrim($pat, '/') . '/'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compara con la foto anterior. La primera vez (o con $rebaseline) solo guarda la foto.
     *
     * @return array{baseline:bool, changes:array<string,array<int,string>>}
     */
    public static function check(bool $force = false, bool $rebaseline = false): array
    {
        $file = self::stateFile();
        $state = json_decode((string)@file_get_contents($file), true) ?: [];
        if (!$force && !$rebaseline && time() - (int)($state['at'] ?? 0) < self::INTERVAL) {
            return ['baseline' => false, 'changes' => [], 'skipped' => true];
        }
        $curr = self::snapshot();
        $prev = is_array($state['snapshot'] ?? null) ? $state['snapshot'] : [];
        $version = self::panelVersion();
        $baseline = $rebaseline || !$prev;
        $changes = [];
        if (!$baseline) {
            // Una actualización del panel reescribe sus servicios, tareas y programas: no es una novedad.
            $skip = ($state['panel_version'] ?? '') !== $version ? self::UPDATED_BY_PANEL : [];
            foreach ($curr as $cat => $items) {
                if (in_array($cat, $skip, true)) {
                    continue;
                }
                $old = (array)($prev[$cat] ?? []);
                foreach ($items as $path => $sig) {
                    if (self::ignored($path)) {
                        continue;
                    }
                    if (!array_key_exists($path, $old)) {
                        $changes[$cat][] = "NUEVO  {$path}" . self::detail($cat, '', $sig);
                    } elseif ($old[$path] !== $sig) {
                        $changes[$cat][] = "CAMBIADO  {$path}" . self::detail($cat, (string)$old[$path], $sig);
                    }
                }
            }
        }
        @file_put_contents($file, json_encode(['at' => time(), 'panel_version' => $version, 'snapshot' => $curr],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($file, 0600);
        return ['baseline' => $baseline, 'changes' => $changes];
    }

    /** Para las claves SSH: qué claves se añadieron o quitaron. */
    private static function detail(string $cat, string $old, string $new): string
    {
        if ($cat !== 'ssh_keys') {
            return '';
        }
        $o = $old === '' ? [] : explode("\n", $old);
        $n = $new === '' ? [] : explode("\n", $new);
        $txt = '';
        foreach (array_diff($n, $o) as $k) {
            $txt .= "\n      + {$k}";
        }
        foreach (array_diff($o, $n) as $k) {
            $txt .= "\n      - {$k}";
        }
        return $txt;
    }

    /** Texto del correo. */
    public static function report(string $host, array $changes): string
    {
        $out = "En {$host} han aparecido o cambiado estas cosas desde la última revisión (cada 10 min):\n";
        foreach ($changes as $cat => $lines) {
            $out .= "\n" . (self::LABELS[$cat] ?? $cat) . ":\n";
            foreach (array_slice($lines, 0, 40) as $l) {
                $out .= "  {$l}\n";
            }
            if (count($lines) > 40) {
                $out .= '  … y ' . (count($lines) - 40) . " más\n";
            }
        }
        $out .= "\nSi lo has hecho tú (o alguien de confianza instalando algo), no hay que hacer nada: de cada cosa se avisa una sola vez."
            . "\nSi no lo reconoces, puede ser un intruso. Para empezar a mirar (como root):"
            . "\n  ls -la <ruta>   ·   last -n 20   ·   who   ·   journalctl --since '-1h'"
            . "\n  y si es un programa o un servicio: systemctl status <nombre>, ps aux | grep <nombre>"
            . "\nSi algo cambia a menudo y es normal, añádelo a \"Cambios del sistema: ignorar\" en Ajustes → Avisos.";
        return $out;
    }
}
