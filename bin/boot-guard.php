<?php
/**
 * Guardián de arranque (anti split-brain). Lo lanza musedock-boot-guard.service ANTES de que
 * arranquen las bases de datos de datos, Caddy y el correo (instalar con bin/install-boot-guard.sh).
 *
 * Caso real (2026-10-18): un master reiniciado volvió creyéndose master mientras el otro nodo ya
 * mandaba. Sus bases de datos arrancaron escribibles y las apps escribieron ~48 KB hasta que, 2,5
 * minutos después, el cluster-worker (cron) lo aisló; esas escrituras impidieron reincorporarlo solo.
 *
 * Aquí solo se hace lo MÍNIMO y se hace antes: si este nodo es master y otro nodo se promovió
 * DESPUÉS que él, se marca apartado (Caddy y correo no arrancan) y las bases de datos de datos
 * arrancan en solo lectura. El aislamiento completo, los avisos y la reincorporación como réplica
 * los hace el cluster-worker en cuanto corre. NO hace demote ni toca PostgreSQL en marcha: dentro de
 * una unidad que debe terminar antes que PostgreSQL, eso se bloquearía a sí mismo.
 *
 * Falla siempre abierto: sin red, sin los otros nodos o ante cualquier error, deja arrancar y sale
 * con 0. Un master legítimo nunca se aparta por no poder preguntar.
 *
 * Uso: php /opt/musedock-panel/bin/boot-guard.php [--dry-run]   (solo muestra qué haría)
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\FailoverSafetyService;
use MuseDockPanel\Settings;

$dry = in_array('--dry-run', $argv ?? [], true);
$say = static function (string $m): void {
    fwrite(STDOUT, '[boot-guard] ' . $m . "\n");
};

try {
    $role = Settings::get('cluster_role', '') ?: \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
    if ($role !== 'master') {
        $say("rol {$role}: nada que hacer");
        exit(0);
    }
    if (is_file(FailoverSafetyService::FENCE_FLAG)) {
        if (!$dry) {
            FailoverSafetyService::writeFencedReadOnlyConf();
        }
        $say('ya apartado antes del reinicio: se asegura el solo-lectura de PostgreSQL');
        exit(0);
    }

    $r = FailoverSafetyService::checkStaleMaster(false);
    if (!empty($r['stale'])) {
        $reason = (string)($r['reason'] ?? 'ya estaba aislado antes del reinicio');
        $say('MASTER CADUCADO: ' . $reason);
        if ($dry) {
            $say('(--dry-run: no se aparta nada)');
        } else {
            $f = FailoverSafetyService::fenceAtBoot($reason);
            $say('apartado: Caddy y correo no arrancarán; PostgreSQL de datos en solo lectura: ' . implode(', ', $f['pg_read_only'] ?: ['ninguno']));
        }
    } else {
        $say('ningún otro nodo es master más reciente (nodos consultados: ' . (int)($r['nodes_asked'] ?? 0) . '): se arranca con normalidad');
    }
} catch (\Throwable $e) {
    $say('error (se deja arrancar): ' . $e->getMessage());
}
exit(0);
