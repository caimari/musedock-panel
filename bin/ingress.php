<?php
/**
 * Entrada alternativa por un proxy de SNI con PROXY protocol (IngressService).
 *
 *   php bin/ingress.php enable --source=<IP del proxy> --health=<nombre> [--port=8443]
 *   php bin/ingress.php status
 *   php bin/ingress.php disable
 *   php bin/ingress.php show-key | rotate-key   (la clave del mapa: solo en esta terminal)
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\IngressService;

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
$showKey = static function (string $k): void {
    echo "\nClave del mapa de dominios (escríbela tú en el proxy; NO la pegues en ningún chat):\n  {$k}\n";
};

switch ($argv[1] ?? '') {
    case 'enable':
        $r = IngressService::enable((string)($opt['source'] ?? ''), (string)($opt['health'] ?? ''), (int)($opt['port'] ?? 8443));
        $k = $r['new_key'] ?? null;
        unset($r['new_key']);
        $print($r);
        if ($k) {
            $showKey($k);
        }
        $c = IngressService::config();
        echo "\nURL del mapa (por la VPN): https://<IP-VPN-de-este-servidor>:" . (\MuseDockPanel\Settings::get('panel_port', '8444') ?: 8444) . "/api/ingress/domains\n";
        echo "Comprobación: https://{$c['health_name']} (por el proxy) debe responder ok-" . IngressService::healthLabel($c['health_name']) . "\n";
        exit(empty($r['ok']) ? 1 : 0);
    case 'status':
        $c = IngressService::config();
        $print($c + ['caddy' => IngressService::ensure(), 'domains' => count(IngressService::domains())]);
        exit(0);
    case 'disable':
        $print(IngressService::disable());
        exit(0);
    case 'show-key':
        $k = IngressService::key();
        $k === '' ? print("No hay clave (activa primero con enable).\n") : $showKey($k);
        exit(0);
    case 'rotate-key':
        $showKey(IngressService::rotateKey());
        echo "Cámbiala también en el proxy.\n";
        exit(0);
    default:
        fwrite(STDERR, "Uso: php bin/ingress.php enable --source=IP --health=NOMBRE [--port=8443] | status | disable | show-key | rotate-key\n");
        exit(1);
}
