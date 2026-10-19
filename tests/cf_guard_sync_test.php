#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — «Bloqueos Cloudflare» entre nodos (standalone, sin PHPUnit). Solo lógica pura.
 *
 * Caso real (2026-10-18): tras el relevo, el nuevo master tenía el sistema APAGADO y sin sondas (la configuración solo
 * existía en el antiguo), y su copia vacía podía pisar la configuración buena del antiguo. Con el bloqueo de LaLiga
 * activo, nadie quitó la nube naranja de 12 webs.
 *
 * Usage: php tests/cf_guard_sync_test.php
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
use MuseDockPanel\Services\FailoverService as F;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }
function section(string $t) { echo "\n\033[1m{$t}\033[0m\n"; }

$blank = ['mode' => 'off', 'restore_minutes' => '', 'excluded' => '', 'display_tz' => '', 'probes' => []];
$hash = hash('sha256', 'egp_secreto');
$real = ['mode' => 'auto', 'restore_minutes' => '30', 'excluded' => "ejemplo.com\notro.org", 'display_tz' => 'Europe/Madrid',
         'probes' => [['name' => 'oficina', 'hash' => $hash, 'created_at' => '2026-10-10 10:00:00']]];

section('1. ¿Está en blanco?');
ok('apagado, sin sondas ni exclusiones: en blanco', G::isConfigBlank($blank));
ok('modo automático: no está en blanco', !G::isConfigBlank(['mode' => 'auto'] + $blank));
ok('apagado pero con una sonda: no está en blanco', !G::isConfigBlank(['probes' => $real['probes']] + $blank));
ok('apagado pero con exclusiones: no está en blanco', !G::isConfigBlank(['excluded' => 'ejemplo.com'] + $blank));
ok('solo espacios en las exclusiones: sigue en blanco', G::isConfigBlank(['excluded' => "  \n "] + $blank));

section('2. Qué se adopta del otro nodo');
$plan = G::adoptionPlan($blank, $real);
ok('en blanco aquí y configurado allí: adopta modo, minutos, exclusiones y zona', ($plan['cf_guard_mode'] ?? '') === 'auto' && ($plan['cf_guard_restore_minutes'] ?? '') === '30' && str_contains($plan['cf_guard_excluded'] ?? '', 'ejemplo.com') && ($plan['cf_guard_display_tz'] ?? '') === 'Europe/Madrid', json_encode($plan));
$probes = json_decode($plan['cf_guard_probes'] ?? '[]', true);
ok('adopta la sonda por su huella', count($probes) === 1 && $probes[0]['hash'] === $hash && $probes[0]['name'] === 'oficina');
ok('NUNCA pisa una configuración que ya existe aquí', G::adoptionPlan($real, ['mode' => 'simulate'] + $real) === []);
ok('NUNCA pisa aunque aquí solo haya el modo puesto', G::adoptionPlan(['mode' => 'simulate'] + $blank, $real) === []);
ok('si el otro también está en blanco, no hay nada que adoptar', G::adoptionPlan($blank, $blank) === []);
ok('un modo inventado se queda en apagado (no activa nada raro)', (G::adoptionPlan($blank, ['mode' => 'turbo', 'probes' => $real['probes']] + $blank)['cf_guard_mode'] ?? '') === 'off');

section('3. Lo que viaja y lo que no');
$bad = ['mode' => 'auto', 'probes' => [
    ['name' => 'ok', 'hash' => $hash],
    ['name' => 'token-en-claro', 'hash' => 'egp_' . str_repeat('a', 48)],   // un token, no una huella: se descarta
    ['name' => 'corta', 'hash' => 'abc123'],
    ['name' => 'sin-huella'],
]] + $blank;
$sent = json_decode(G::adoptionPlan($blank, $bad)['cf_guard_probes'] ?? '[]', true);
ok('solo se aceptan huellas sha256 válidas (64 hex); un token en claro o una huella corta se descartan', count($sent) === 1 && $sent[0]['name'] === 'ok', json_encode($sent));
ok('la exportación nunca lleva tokens: solo name, hash y created_at', (function () {
    $e = G::exportConfig();
    foreach ($e['probes'] as $p) {
        if (array_keys($p) !== ['name', 'hash', 'created_at']) return false;
        if (str_starts_with($p['hash'], 'egp_')) return false;
    }
    return true;
})());
ok('las claves que viajan son solo de configuración (no bloqueos, observaciones ni inventario)', G::SYNC_KEYS === ['cf_guard_mode', 'cf_guard_restore_minutes', 'cf_guard_excluded', 'cf_guard_display_tz', 'cf_guard_probes', 'cf_guard_extra_hosts']);
$sync = F::getSyncableConfigKeys();
ok('todas esas claves están en la lista de lo que se copia entre nodos', array_diff(G::SYNC_KEYS, $sync) === []);
ok('el estado interno NO está en esa lista', !array_filter($sync, static fn($k) => preg_match('/^cf_guard_(blocked_ips|inventory|last_report|stale_noted)/', $k)));

echo "\n\033[1mNombres extra a vigilar (dominios de tenants de otras aplicaciones, p. ej. un CNAME con proxy)\033[0m\n";
$h = G::normalizeHostList("App.Cliente.com.\n  otro.cliente.org , basura sin punto\nAPP.cliente.com\n; drop table\n-malo-.com\n*.wild.cliente.net");
ok('limpia mayúsculas, puntos finales y repetidos, y descarta lo que no es un nombre', $h === ['app.cliente.com', 'otro.cliente.org', '*.wild.cliente.net'], json_encode($h));
ok('nada de inyectar texto raro en la lista: un nombre con «;» o «$(…)» se descarta entero', G::normalizeHostList("a.com; rm -rf /\n\$(x).com") === [] && G::normalizeHostList('ok.com') === ['ok.com']);
ok('una lista vacía sale vacía', G::normalizeHostList('  ') === []);
ok('un nombre extra cuenta como configuración (no está «en blanco»)', !G::isConfigBlank(['extra_hosts' => 'app.cliente.com'] + $blank));
ok('solo espacios: sigue en blanco', G::isConfigBlank(['extra_hosts' => " \n "] + $blank));
$plan2 = G::adoptionPlan($blank, ['mode' => 'auto', 'extra_hosts' => "app.cliente.com"] + $real);
ok('un nodo en blanco adopta también los nombres extra del otro', ($plan2['cf_guard_extra_hosts'] ?? '') === 'app.cliente.com', json_encode($plan2));
ok('los nombres extra se exportan con la configuración', array_key_exists('extra_hosts', G::exportConfig()));
$cfsrc = file_get_contents(PANEL_ROOT . '/app/Services/CfGuardService.php');
ok('el inventario vigila los nombres extra (solo el nombre exacto, sin su www)', str_contains($cfsrc, 'foreach (self::extraHosts() as $x)') && str_contains($cfsrc, '$add($x, false)'));

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
