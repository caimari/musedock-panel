<?php
/**
 * Vigilante de entrada de otros servidores (IngressWatchService). En el panel de FUERA.
 *
 *   php bin/ingress-watch.php add --ip=<IP normal> --health=<nombre comprobación> --alt=<nombre DNS alternativa>
 *   php bin/ingress-watch.php remove --ip=<IP>
 *   php bin/ingress-watch.php set [--fail-minutes=3] [--recover-minutes=10] [--max-latency-ms=1500] [--max-loss-pct=30]
 *   php bin/ingress-watch.php status | check      (check: una pasada ahora, sin esperar al minuto)
 *   php bin/ingress-watch.php force-primary       (devuelve ya la entrada normal)
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\IngressWatchService as W;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$opt = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
$print = static fn($d) => print(json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
$c = W::config();

switch ($argv[1] ?? '') {
    case 'add':
        $ip = (string)($opt['ip'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP) || empty($opt['health']) || empty($opt['alt'])) {
            fwrite(STDERR, "Uso: add --ip=IP --health=NOMBRE --alt=NOMBRE_DNS\n");
            exit(1);
        }
        $c['servers'] = array_values(array_filter($c['servers'], static fn($s) => ($s['ip'] ?? '') !== $ip));
        $c['servers'][] = ['ip' => $ip, 'health' => strtolower($opt['health']), 'alt_host' => strtolower($opt['alt'])];
        W::saveConfig($c);
        $print(W::status());
        exit(0);
    case 'remove':
        $c['servers'] = array_values(array_filter($c['servers'], static fn($s) => ($s['ip'] ?? '') !== ($opt['ip'] ?? '')));
        W::saveConfig($c);
        $print(W::status());
        exit(0);
    case 'set':
        foreach (['fail-minutes' => 'fail_minutes', 'recover-minutes' => 'recover_minutes', 'max-latency-ms' => 'max_latency_ms', 'max-loss-pct' => 'max_loss_pct'] as $o => $k) {
            if (isset($opt[$o]) && ctype_digit($opt[$o])) {
                $c[$k] = (int)$opt[$o];
            }
        }
        W::saveConfig($c);
        $print(W::status());
        exit(0);
    case 'status':
        $print(W::status());
        exit(0);
    case 'check':
        $print(W::run());
        exit(0);
    case 'force-primary':
        $print(W::forcePrimary());
        exit(0);
    default:
        fwrite(STDERR, "Uso: add | remove | set | status | check | force-primary (ver cabecera)\n");
        exit(1);
}
