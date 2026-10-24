<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Vigila que el PHP de ESTE servidor no esté por detrás de la versión corregida de cada rama, y que ningún proceso
 * siga con el binario viejo en memoria después de actualizar.
 *
 * Caso real (2026-10-21, CVE-2026-109618/109619, ejecución remota sin autenticación): nitro seguía en PHP 8.3.30
 * desde febrero y nadie lo notó; en otros nodos, tras instalar el paquete, el panel («php -S»), Octane o Horizon
 * seguían con el PHP viejo hasta que se reiniciaron.
 *
 * Cada nodo se mira a sí mismo (dpkg + /proc) y avisa por el tipo «php_patch». Las versiones mínimas están en
 * MINIMUM y se pueden cambiar sin tocar código con el ajuste php_min_versions («8.3=8.3.36,8.4=8.4.27»,
 * Settings → clave de texto). Solo lectura: no instala ni reinicia nada.
 */
class PhpPatchService
{
    /** Versión mínima corregida por rama (CVE-2026-109618/109619). 8.1: paquete 8.1.34-9 de Ondřej Surý. */
    public const MINIMUM = [
        '8.1' => '8.1.34-9',
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /** Se repite el mismo aviso como mucho cada tantas horas (si no cambia nada). */
    private const REALERT_HOURS = 72;

    /** Mínimos efectivos: los del ajuste php_min_versions encima de los de fábrica. */
    public static function minimums(): array
    {
        $min = self::MINIMUM;
        foreach (preg_split('/[,\s]+/', (string)Settings::get('php_min_versions', '')) ?: [] as $p) {
            if (preg_match('/^(\d+\.\d+)=([0-9][0-9A-Za-z.+~:-]*)$/', $p, $m)) {
                $min[$m[1]] = $m[2];
            }
        }
        return $min;
    }

    /** Versiones instaladas por rama (paquete php8.X-cli o -fpm): ['8.3' => '8.3.36-1+ubuntu…']. */
    public static function installed(?string $dpkgOut = null): array
    {
        if ($dpkgOut === null) {
            $dpkgOut = (string)@shell_exec('dpkg-query -W -f=\'${Package} ${Status} ${Version}\n\' \'php*-cli\' \'php*-fpm\' 2>/dev/null');
        }
        return self::parseInstalled($dpkgOut);
    }

    /** Pura: «php8.3-fpm install ok installed 8.3.36-1+…» → ['8.3' => '8.3.36-1+…']. */
    public static function parseInstalled(string $out): array
    {
        $r = [];
        foreach (preg_split('/\R/', $out) ?: [] as $l) {
            if (preg_match('/^php(\d+\.\d+)-(?:cli|fpm) install ok installed (\S+)/', trim($l), $m)) {
                // Si cli y fpm difieren, vale el más bajo: es el que queda por detrás.
                if (!isset($r[$m[1]]) || self::lessThan($m[2], $r[$m[1]])) {
                    $r[$m[1]] = $m[2];
                }
            }
        }
        ksort($r);
        return $r;
    }

    /** a < b con el orden de versiones de Debian. */
    public static function lessThan(string $a, string $b): bool
    {
        if (is_executable('/usr/bin/dpkg')) {
            $rc = 1;
            @exec('/usr/bin/dpkg --compare-versions ' . escapeshellarg($a) . ' lt ' . escapeshellarg($b) . ' 2>/dev/null', $o, $rc);
            return $rc === 0;
        }
        return version_compare(preg_replace('/[-+~].*$/', '', $a) . '.' . (preg_match('/-(\d+)/', $a, $m) ? $m[1] : '0'),
            preg_replace('/[-+~].*$/', '', $b) . '.' . (preg_match('/-(\d+)/', $b, $m) ? $m[1] : '0'), '<');
    }

    /** Ramas instaladas cuya versión es menor que el mínimo: [rama => [instalada, mínima]]. */
    public static function behind(array $installed, array $min): array
    {
        $out = [];
        foreach ($installed as $branch => $ver) {
            // Paquetes de la distribución (Ubuntu/Debian) arreglan los fallos sin subir el número de PHP: no se pueden comparar.
            if (!str_contains($ver, 'sury') && preg_match('/ubuntu|deb\d/', $ver)) {
                continue;
            }
            if (isset($min[$branch]) && self::lessThan($ver, $min[$branch])) {
                $out[$branch] = [$ver, $min[$branch]];
            }
        }
        return $out;
    }

    /** Procesos PHP que siguen con un binario ya reemplazado (hay que reiniciarlos): [[pid, comando]]. Hace falta root para verlos todos. */
    public static function staleProcesses(): array
    {
        $out = [];
        foreach (@glob('/proc/[0-9]*') ?: [] as $p) {
            $exe = @readlink($p . '/exe');
            if ($exe === false || !str_contains($exe, 'php') || !str_contains($exe, '(deleted)')) {
                continue;
            }
            $cmd = trim(str_replace("\0", ' ', (string)@file_get_contents($p . '/cmdline')));
            $out[] = [(int)basename($p), mb_substr($cmd !== '' ? $cmd : $exe, 0, 90)];
        }
        return $out;
    }

    /**
     * Revisión completa. Devuelve ['behind'=>…, 'stale'=>…, 'alerted'=>bool]. Avisa una vez por cada cambio en el
     * problema (o cada REALERT_HOURS si sigue igual) y una vez al quedar todo bien.
     */
    public static function checkAndAlert(): array
    {
        Settings::set('php_patch_last_run', date('Y-m-d H:i:s'));
        $installed = self::installed();
        $behind = self::behind($installed, self::minimums());
        $stale = posix_geteuid() === 0 ? self::staleProcesses() : [];

        $sig = md5(json_encode([$behind, array_map(static fn($s) => $s[1], $stale)]));
        $problem = $behind || $stale;
        $lastSig = (string)Settings::get('php_patch_sig', '');
        $lastAt = (int)strtotime((string)Settings::get('php_patch_alerted_at', '') ?: '@0');
        $res = ['installed' => $installed, 'behind' => $behind, 'stale' => $stale, 'alerted' => false];

        if ($problem) {
            if ($sig === $lastSig && (time() - $lastAt) < self::REALERT_HOURS * 3600) {
                return $res;
            }
            NotificationService::send(self::subject($behind, $stale), self::body($installed, $behind, $stale), 'php_patch');
            Settings::set('php_patch_sig', $sig);
            Settings::set('php_patch_alerted_at', date('Y-m-d H:i:s'));
            $res['alerted'] = true;
        } elseif ($lastSig !== '' && $lastSig !== 'ok') {
            NotificationService::send('PHP al día en este servidor',
                'PHP vuelve a estar en la versión corregida y ningún proceso usa un binario viejo.' . "\n" . self::listInstalled($installed), 'php_patch');
            Settings::set('php_patch_sig', 'ok');
            $res['alerted'] = true;
        } elseif ($lastSig === '') {
            Settings::set('php_patch_sig', 'ok');
        }
        return $res;
    }

    private static function subject(array $behind, array $stale): string
    {
        $parts = [];
        foreach ($behind as $b => [$v, $m]) {
            $parts[] = "PHP {$b} sin parchear";
        }
        if ($stale) {
            $parts[] = count($stale) . ' proceso(s) con PHP viejo en memoria';
        }
        return implode(' · ', $parts);
    }

    private static function listInstalled(array $installed): string
    {
        $l = [];
        foreach ($installed as $b => $v) {
            $l[] = "  PHP {$b}: {$v}";
        }
        return $l ? "Instalado:\n" . implode("\n", $l) : '';
    }

    private static function body(array $installed, array $behind, array $stale): string
    {
        $t = '';
        if ($behind) {
            $t .= "Estas versiones de PHP están por detrás de la corregida (fallos críticos de ejecución remota sin autenticación):\n";
            foreach ($behind as $b => [$v, $m]) {
                $t .= "  PHP {$b}: instalada {$v} · mínima {$m}\n";
            }
            $t .= "\nActualiza SOLO esa rama, sin comodines que arrastren otras (cambian el php por defecto):\n";
            foreach (array_keys($behind) as $b) {
                $t .= "  apt update && apt install --only-upgrade 'php{$b}-*'   # o los php{$b}-* de «dpkg -l | grep php{$b}»\n";
            }
            $t .= "  systemctl restart php<rama>-fpm musedock-panel\n\n";
        }
        if ($stale) {
            $t .= "Estos procesos siguen con el PHP viejo en memoria (hay que reiniciarlos; recargar no basta):\n";
            foreach (array_slice($stale, 0, 12) as [$pid, $cmd]) {
                $t .= "  {$pid}  {$cmd}\n";
            }
            $t .= "\nReinicia el servicio que los lanza (php-fpm, musedock-panel, supervisor: Octane/Horizon/websockets).\n\n";
        }
        return $t . self::listInstalled($installed);
    }
}
