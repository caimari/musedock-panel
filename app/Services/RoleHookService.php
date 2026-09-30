<?php
namespace MuseDockPanel\Services;

/**
 * Scripts de relevo ("hooks") que el administrador deja en el servidor para lo que
 * el panel no gestiona: mover una IP flotante, arrancar programas de supervisor,
 * activar crons, republicar dominios en Caddy...
 *
 *   /etc/musedock/hooks/promote.d/*   → al final de promoteToMaster()
 *   /etc/musedock/hooks/demote.d/*    → al final de demoteToSlave()
 *
 * Mismo modelo que run-parts / cron.d: solo se ejecuta lo que root ha puesto ahí.
 * Reglas (si no se cumplen, el script NO se ejecuta y se indica por qué):
 *  - directorios y ficheros propiedad de root, sin escritura para grupo ni otros;
 *  - fichero regular (no enlace), ejecutable, nombre [A-Za-z0-9_-] (opcional .sh);
 *  - se ejecutan en orden alfabético, cada uno con un límite de 120 s.
 * Reciben MUSEDOCK_EVENT y, según el caso, MUSEDOCK_NEW_MASTER_IP u
 * MUSEDOCK_OLD_MASTER_IP. Su salida se devuelve (recortada) pero NO se escribe en
 * panel_log, que se replica a todos los nodos: allí solo va el código de salida.
 */
final class RoleHookService
{
    public const BASE = '/etc/musedock/hooks';
    private const EVENTS = ['promote', 'demote'];
    private const TIMEOUT = 120;

    private static function unsafeReason(string $path, bool $isDir): ?string
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            return 'es un enlace simbólico';
        }
        $st = @stat($path);
        if (!$st) {
            return 'no existe';
        }
        if ($isDir !== is_dir($path)) {
            return $isDir ? 'no es un directorio' : 'no es un fichero regular';
        }
        if ((int)$st['uid'] !== 0) {
            return 'no es propiedad de root';
        }
        if (($st['mode'] & 0022) !== 0) {
            return 'escribible por grupo u otros';
        }
        if (!$isDir && ($st['mode'] & 0100) === 0) {
            return 'no es ejecutable';
        }
        return null;
    }

    /** Scripts de un evento y si son válidos (no ejecuta nada). */
    public static function list(string $event): array
    {
        if (!in_array($event, self::EVENTS, true)) {
            return [];
        }
        $dir = self::BASE . "/{$event}.d";
        if (!is_dir($dir)) {
            return [];
        }
        $dirIssue = self::unsafeReason(self::BASE, true) ?? self::unsafeReason($dir, true);
        $out = [];
        $files = scandir($dir) ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_-]+(\.sh)?$/', $f)) {
                $out[] = ['file' => $f, 'valid' => false, 'ignored' => true, 'reason' => 'nombre no válido (se ignora, como run-parts)'];
                continue;
            }
            $reason = $dirIssue !== null ? "directorio inseguro: {$dirIssue}" : self::unsafeReason("{$dir}/{$f}", false);
            $out[] = ['file' => $f, 'valid' => $reason === null, 'reason' => $reason];
        }
        return $out;
    }

    /**
     * Ejecuta los scripts válidos de un evento. $env: variables extra (solo
     * nombres MUSEDOCK_* y valores simples).
     */
    public static function run(string $event, array $env = []): array
    {
        $results = [];
        foreach (self::list($event) as $h) {
            if (!empty($h['ignored'])) {
                continue;
            }
            if (!$h['valid']) {
                $results[] = ['file' => $h['file'], 'ok' => false, 'skipped' => $h['reason']];
                continue;
            }
            $path = self::BASE . "/{$event}.d/{$h['file']}";
            $vars = ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'MUSEDOCK_EVENT' => $event];
            foreach ($env as $k => $v) {
                if (preg_match('/^MUSEDOCK_[A-Z_]+$/', (string)$k) && preg_match('/^[A-Za-z0-9.:_-]*$/', (string)$v)) {
                    $vars[$k] = (string)$v;
                }
            }
            $t = microtime(true);
            $proc = proc_open(['timeout', (string)self::TIMEOUT, $path], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, '/', $vars);
            if (!is_resource($proc)) {
                $results[] = ['file' => $h['file'], 'ok' => false, 'error' => 'no se pudo ejecutar'];
                continue;
            }
            $output = (string)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($proc);
            $results[] = [
                'file' => $h['file'],
                'ok' => $code === 0,
                'exit_code' => $code,
                'timed_out' => $code === 124,
                'seconds' => round(microtime(true) - $t, 1),
                'output_tail' => mb_substr(trim($output), -2000),
            ];
        }
        if ($results) {
            $summary = implode(', ', array_map(static fn($r) => $r['file'] . '=' . ($r['ok'] ? 'ok' : ($r['skipped'] ?? ('exit ' . ($r['exit_code'] ?? '?')))), $results));
            try {
                LogService::log('cluster.hooks', $event, $summary);
            } catch (\Throwable) {
            }
        }
        return $results;
    }
}
