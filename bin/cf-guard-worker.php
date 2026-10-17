#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — Conmutación automática ante bloqueos de Cloudflare (CfGuardService). Cada minuto.
 *
 * Uso:
 *   php bin/cf-guard-worker.php                 una vuelta (cron)
 *   php bin/cf-guard-worker.php --inventory     vuelve a leer ya los registros de Cloudflare
 *   php bin/cf-guard-worker.php --status        sondas, IPs y cambios activos, sin hacer nada
 *   php bin/cf-guard-worker.php --new-probe=NOMBRE --token-file=RUTA   crea una sonda; el token va al fichero (600)
 *
 * Solo actúa en el panel que manda (no en un slave ni en uno aislado).
 */

define('PANEL_ROOT', dirname(__DIR__));

spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

use MuseDockPanel\Services\CfGuardService as G;

$opts = getopt('', ['inventory', 'status', 'new-probe:', 'token-file:']);

$lockFp = fopen(PANEL_ROOT . '/storage/cf-guard-worker.lock', 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit(0);
}

if (isset($opts['new-probe'])) {
    $file = (string) ($opts['token-file'] ?? '');
    if ($file === '') {
        fwrite(STDERR, "Falta --token-file=RUTA\n");
        exit(1);
    }
    $token = G::createProbe((string) $opts['new-probe']);
    $old = umask(0077);
    file_put_contents($file, $token . "\n");
    umask($old);
    chmod($file, 0600);
    echo "Sonda creada; token guardado en {$file}\n";
    exit(0);
}

if (isset($opts['status'])) {
    echo 'Modo: ' . G::mode() . (G::isActive() ? '' : ' (este panel no manda: no actúa)') . "\n";
    foreach (G::livePaths() as $k => $p) {
        echo "Sonda viva {$k} (IP pública {$p['public_ip']}, último informe " . G::fmt($p['last']) . ")\n";
    }
    foreach (G::edgeStates() as $ip => $s) {
        $paths = implode(' ', array_map(fn ($k, $v) => $k . '=' . ($v === null ? '?' : ($v ? 'ok' : 'FALLA')), array_keys($s['paths']), $s['paths']));
        echo "IP {$ip}: {$s['state']} {$paths}\n";
    }
    foreach (G::activeChanges() as $c) {
        echo 'Cambio activo: ' . $c['name'] . ((int) $c['simulated'] ? ' (simulado)' : '') . ' desde ' . G::fmt($c['changed_at']) . "\n";
    }
    exit(0);
}

if (!G::isActive()) {
    exit(0);
}

$report = G::run(isset($opts['inventory']));
$lines = array_merge($report['applied'], $report['restored'], $report['notes']);
foreach ($lines as $l) {
    echo '[' . gmdate('Y-m-d H:i:s') . " UTC] {$l}\n";
}
if ($lines) {
    $sim = G::mode() === 'simulate' ? '[Simulación] ' : '';
    $subject = $sim . 'Bloqueos de Cloudflare: ' . ($report['applied'] ? count($report['applied']) . ' cambio(s)' : ($report['restored'] ? count($report['restored']) . ' vuelta(s) a la normalidad' : 'aviso'));
    $body = '';
    foreach (['applied' => 'Proxy quitado', 'restored' => 'Proxy restaurado', 'notes' => 'Avisos'] as $key => $title) {
        if ($report[$key]) {
            $body .= $title . ":\n- " . implode("\n- ", $report[$key]) . "\n\n";
        }
    }
    $body .= 'Detalle e historial: Ajustes → Bloqueos Cloudflare.';
    try {
        \MuseDockPanel\Services\NotificationService::send($subject, $body, 'cf_proxy');
    } catch (\Throwable $e) {
        echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] No se pudo enviar el aviso: ' . $e->getMessage() . "\n";
    }
}
