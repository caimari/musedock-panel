<?php
/**
 * Trabajos en segundo plano del cambio de rol (RoleSwitchService). Lo lanza el panel;
 * deja el avance en /var/lib/musedock/role-switch-<tarea>.json.
 *
 *   php bin/role-switch-run.php orchestrate <tarea> <id-nodo>                 (en el master)
 *   php bin/role-switch-run.php promote <tarea> <ip-vpn-antiguo> <ip-pub-antiguo> <ip-pub-nuevo>   (en el elegido)
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\RoleSwitchService;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
set_time_limit(0);
$mode = $argv[1] ?? '';
$task = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($argv[2] ?? ''));
if ($task === '') {
    fwrite(STDERR, "Falta la tarea.\n");
    exit(1);
}
try {
    if ($mode === 'orchestrate') {
        RoleSwitchService::orchestrate($task, (int)($argv[3] ?? 0));
    } elseif ($mode === 'promote') {
        RoleSwitchService::promoteHere($task, (string)($argv[3] ?? ''), (string)($argv[4] ?? ''), (string)($argv[5] ?? ''));
    } else {
        fwrite(STDERR, "Modo no válido.\n");
        exit(1);
    }
} catch (\Throwable $e) {
    $st = RoleSwitchService::status($task);
    $st['state'] = 'failed';
    $st['error'] = 'Excepción: ' . $e->getMessage();
    $st['steps'][] = ['at' => date('H:i:s'), 'msg' => $st['error'], 'ok' => false];
    file_put_contents(RoleSwitchService::statusFile($task), json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    exit(1);
}
