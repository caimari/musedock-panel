<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Sesiones de PHP caducadas de los hostings. Cada hosting guarda sus sesiones en
 * /var/www/vhosts/<dominio>/sessions (session.save_path de su pool), y la limpieza de PHP en
 * Debian/Ubuntu (cron sessionclean) solo vacía la carpeta del sistema: aquí se acumulaban
 * sin fin (cientos de miles de ficheros por cada visita de bots).
 *
 * Solo toca ficheros sess_* de esas carpetas que llevan más de N horas sin usarse
 * (por defecto 24: una sesión de PHP caduca en minutos). Nada más.
 */
class SessionCleanupService
{
    public static function maxAgeHours(): int
    {
        return max(2, min(720, (int)Settings::get('session_cleanup_hours', '24')));
    }

    public static function enabled(): bool
    {
        return Settings::get('session_cleanup_enabled', '1') === '1';
    }

    /** Carpetas sessions/ de los hostings (reales, sin enlaces, dentro de /var/www/vhosts). */
    private static function dirs(): array
    {
        $out = [];
        foreach (glob('/var/www/vhosts/*/sessions', GLOB_ONLYDIR) ?: [] as $d) {
            $real = realpath($d);
            if ($real !== false && !is_link($d) && preg_match('#^/var/www/vhosts/[^/]+/sessions$#', $real)) {
                $out[] = $real;
            }
        }
        return $out;
    }

    /**
     * Cuenta (y con $apply borra) las sesiones caducadas.
     *
     * @return array{ok:bool, apply:bool, hours:int, files:int, bytes:int, by_hosting:array<string,array{files:int,bytes:int}>}
     */
    public static function run(bool $apply = false): array
    {
        $hours = self::maxAgeHours();
        $out = ['ok' => true, 'apply' => $apply, 'hours' => $hours, 'files' => 0, 'bytes' => 0, 'by_hosting' => []];
        foreach (self::dirs() as $dir) {
            $base = 'find ' . escapeshellarg($dir) . ' -maxdepth 1 -type f -name ' . escapeshellarg('sess_*') . ' -mmin +' . ($hours * 60);
            // Una sola pasada: número de ficheros y espacio que ocupan en disco (%k: KB por
            // bloques; cada sesión, aunque pese 0 bytes, gasta un bloque).
            $line = trim((string)shell_exec($base . " -printf '%k\\n' 2>/dev/null | awk '{n++; s+=\$1*1024} END {printf \"%d %d\", n, s}'"));
            [$n, $bytes] = array_map('intval', explode(' ', $line ?: '0 0') + [0, 0]);
            if ($n === 0) {
                continue;
            }
            if ($apply) {
                shell_exec($base . ' -delete 2>/dev/null');
            }
            $out['by_hosting'][basename(dirname($dir))] = ['files' => $n, 'bytes' => $bytes];
            $out['files'] += $n;
            $out['bytes'] += $bytes;
        }
        uasort($out['by_hosting'], static fn($a, $b) => $b['files'] <=> $a['files']);
        if ($apply && $out['files'] > 0) {
            LogService::log('maintenance.sessions', null, "Sesiones caducadas borradas: {$out['files']} ficheros, "
                . round($out['bytes'] / 1048576, 1) . " MB (más de {$hours} h sin usar)");
        }
        return $out;
    }
}
