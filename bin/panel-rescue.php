<?php
/**
 * Panel de rescate: deja el panel accesible en su puerto (8444 por defecto) cuando
 * el Caddy principal está parado, p. ej. con el nodo apartado (fence) en un relevo.
 *
 * Es un Caddy aparte y mínimo: SOLO escucha en el puerto del panel y SOLO lleva al
 * panel interno (127.0.0.1:8445). No conoce ninguna web, así que no puede volver a
 * servirlas. Certificado propio (CA interna de Caddy): el navegador avisará y hay que
 * aceptarlo; se entra por IP (https://IP:8444) porque el nombre puede estar apuntando
 * al nodo de relevo. Unidad transitoria de systemd: no sobrevive a un reinicio.
 *
 * Uso (como root): php bin/panel-rescue.php start|stop|status
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Env;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}

$unit = 'musedock-panel-rescue';
$dir = '/var/lib/musedock/panel-rescue';
$conf = "{$dir}/caddy.json";
$port = (int)Env::get('PANEL_PORT', 8444);
$internal = (string)Env::get('PANEL_INTERNAL', '127.0.0.1:8445');

function rescueActive(string $unit): bool
{
    return trim((string)shell_exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null')) === 'active';
}

$cmd = $argv[1] ?? 'status';
if ($cmd === 'status') {
    echo rescueActive($unit) ? "Panel de rescate ACTIVO en :{$port}\n" : "Panel de rescate parado\n";
    exit(0);
}
if ($cmd === 'stop') {
    shell_exec('systemctl stop ' . escapeshellarg($unit) . ' 2>&1');
    echo rescueActive($unit) ? "No se pudo parar\n" : "Panel de rescate parado\n";
    exit(0);
}
if ($cmd !== 'start') {
    fwrite(STDERR, "Uso: php bin/panel-rescue.php start|stop|status\n");
    exit(1);
}
if (rescueActive($unit)) {
    echo "Ya estaba activo en :{$port}\n";
    exit(0);
}
if (trim((string)shell_exec('systemctl is-active caddy 2>/dev/null')) === 'active') {
    fwrite(STDERR, "El Caddy principal está en marcha (ya sirve el panel): no hace falta el de rescate.\n");
    exit(1);
}

// Nombres para el certificado: las IPs del servidor y localhost.
$names = ['localhost', '127.0.0.1'];
foreach (preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) as $ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $names[] = $ip;
    }
}
$names = array_values(array_unique($names));

$cfg = [
    'admin' => ['disabled' => true],
    'storage' => ['module' => 'file_system', 'root' => $dir . '/data'],
    'apps' => [
        'pki' => ['certificate_authorities' => ['local' => ['install_trust' => false]]],
        'tls' => [
            'certificates' => ['automate' => $names],
            'automation' => ['policies' => [['subjects' => $names, 'issuers' => [['module' => 'internal']]]]],
        ],
        'http' => ['servers' => ['rescue' => [
            'listen' => [":{$port}"],
            'automatic_https' => ['disable_redirects' => true],
            'tls_connection_policies' => [new \stdClass()],
            'routes' => [['handle' => [['handler' => 'reverse_proxy', 'upstreams' => [['dial' => $internal]]]]]],
        ]]],
    ],
];
@mkdir($dir, 0700, true);
file_put_contents($conf, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
chmod($conf, 0600);

$out = trim((string)shell_exec('systemd-run --quiet --collect --unit=' . escapeshellarg($unit)
    . ' --property=Restart=on-failure /usr/bin/caddy run --config ' . escapeshellarg($conf) . ' 2>&1'));
sleep(2);
if (!rescueActive($unit)) {
    fwrite(STDERR, "No arrancó. {$out}\n" . (string)shell_exec('journalctl -u ' . escapeshellarg($unit) . ' -n 15 --no-pager 2>&1'));
    exit(1);
}
echo "Panel de rescate ACTIVO: https://" . ($names[2] ?? '127.0.0.1') . ":{$port} (certificado propio: acepta el aviso del navegador)\n";
