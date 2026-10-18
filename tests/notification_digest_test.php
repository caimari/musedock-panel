#!/usr/bin/env php
<?php
/**
 * Avisos de nodo agrupados en un correo. Solo lógica: usa una carpeta temporal y NO envía nada
 * (compose() es pura; enqueue/flush se prueban sin llegar a deliver()).
 *
 * Usage: php tests/notification_digest_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

use MuseDockPanel\Services\NotificationDigestService as D;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }

echo "\n\033[1mQué se agrupa\033[0m\n";
foreach (['service_down', 'replication', 'lsyncd', 'mail_node', 'ha_readiness'] as $t) {
    ok("{$t} es agrupable", D::isGroupable($t));
}
foreach (['login_anomaly', 'config_drift', 'firewall_change', 'public_exposure', 'system_changes', 'security_hardening', ''] as $t) {
    ok('«' . $t . '» sale al momento (seguridad / sin tipo)', !D::isGroupable($t));
}

echo "\n\033[1mCómo se une\033[0m\n";
$one = [['type' => 'service_down', 'subject' => 'Servicio caído: dovecot', 'message' => 'cuerpo uno', 'at' => 1]];
[$s, $b, $t] = D::compose($one);
ok('un solo aviso sale TAL CUAL (asunto y cuerpo)', $s === 'Servicio caído: dovecot' && $b === 'cuerpo uno' && $t === 'service_down');
$many = [
    ['type' => 'service_down', 'subject' => 'Servicio caído: dovecot', 'message' => 'uno', 'at' => 1700000000],
    ['type' => 'replication', 'subject' => 'Réplica con problemas', 'message' => 'dos', 'at' => 1700000005],
    ['type' => 'lsyncd', 'subject' => 'lsyncd parado', 'message' => 'tres', 'at' => 1700000009],
    ['type' => 'node_down', 'subject' => 'Nodo caído', 'message' => 'cuatro', 'at' => 1700000012],
];
[$s, $b] = D::compose($many);
ok('varios: el asunto dice cuántos y de qué tipos', str_starts_with($s, '4 avisos del clúster: ') && str_contains($s, 'Réplica con problemas') && str_ends_with($s, '…'), $s);
ok('varios: el cuerpo lleva TODOS los avisos con su texto completo', str_contains($b, 'uno') && str_contains($b, 'dos') && str_contains($b, 'tres') && str_contains($b, 'cuatro') && str_contains($b, 'Nodo caído'));
ok('varios: cada aviso va numerado con su hora', str_contains($b, '1/4') && str_contains($b, '4/4') && str_contains($b, 'UTC'));

echo "\n\033[1mCarpeta de avisos (aislada)\033[0m\n";
$dir = PANEL_ROOT . '/storage/notify-digest';
$had = is_dir($dir) ? (glob($dir . '/*') ?: []) : [];
ok('la carpeta de prueba está vacía (no se pisa nada real)', $had === [], json_encode($had));
if ($had === []) {
    // Settings sin base de datos: la ventana se lee con un valor por defecto; si no hay BD, enqueue devuelve false (sale ya).
    $r = null;
    try { $r = D::enqueue('service_down', 'x', 'y'); } catch (\Throwable $e) { $r = 'excepción'; }
    ok('sin poder leer ajustes/guardar, NO se traga el aviso (false → sale como siempre) o lo apunta', $r === false || $r === true, var_export($r, true));
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }   // solo lo que acaba de crear esta prueba
}
ok('un tipo no agrupable nunca se apunta', D::enqueue('login_anomaly', 'x', 'y') === false);

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
