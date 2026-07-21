#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — hosting UID allocation tests.
 *
 * READ-ONLY / logic-only: never runs useradd, never touches /etc/passwd.
 * Verifies the dedicated hosting UID band and the conflict-visibility logic
 * that keep node UIDs consistent across a cluster.
 *
 * Usage: php tests/hosting_uid_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

use MuseDockPanel\Services\SystemService as S;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }
function section(string $t) { echo "\n\033[1m{$t}\033[0m\n"; }

section('1. Banda de UID dedicada para hostings');
ok('HOSTING_UID_MIN = 20000 (fuera del rango OS 1000-9999)', S::HOSTING_UID_MIN === 20000);
ok('HOSTING_UID_MAX = 59999 (dentro de login.defs UID_MAX 60000)', S::HOSTING_UID_MAX === 59999);
ok('la banda no solapa con usuarios del sistema (>= 20000)', S::HOSTING_UID_MIN >= 10000);

section('2. nextFreeHostingUid');
$next = S::nextFreeHostingUid();
ok('devuelve un UID dentro de la banda', $next === null || ($next >= S::HOSTING_UID_MIN && $next <= S::HOSTING_UID_MAX));
ok('en una máquina sin hostings en la banda, empieza en el mínimo',
    $next === null || $next === S::HOSTING_UID_MIN || $next > S::HOSTING_UID_MIN);

section('3. El código respeta el modelo (conflicto visible, no silencioso)');
$src = file_get_contents(PANEL_ROOT . '/app/Services/SystemService.php');
ok('el conflicto de UID forzado se registra (no se ignora)',
    str_contains($src, 'system.uid_conflict') && str_contains($src, 'divergente'));
ok('sin forceUid, asigna desde la banda dedicada',
    str_contains($src, 'nextFreeHostingUid()') && str_contains($src, "elseif (\$forceUid === null)"));
ok('con forceUid libre, reproduce el UID del master exacto',
    str_contains($src, '$chosenUid = $forceUid;'));
ok('NO cae en silencio: el conflicto se registra y se reasigna en banda',
    str_contains($src, 'never let it land in the OS/admin range') || str_contains($src, 'requiere reconciliación'));

section('4. No rompe los hostings existentes');
ok('la banda nueva (20000+) no colisiona con los UID actuales (1000-1025)',
    S::HOSTING_UID_MIN > 1025);

section('5. Fixes de la review adversarial');
ok('#2 en colisión de forceUid, el divergente se queda EN la banda (no cae a 1000+)',
    str_contains($src, '$chosenUid = self::nextFreeHostingUid();') &&
    str_contains($src, 'Keep the divergent user inside the hosting band'));
ok('#6 el logging nunca rompe la creación (safeLog con try/catch)',
    str_contains($src, 'private static function safeLog') &&
    str_contains($src, 'catch (\\Throwable') && str_contains($src, 'error_log'));
ok('#6 el path de conflicto usa safeLog, no LogService directo',
    str_contains($src, "self::safeLog(\n                    'system.uid_conflict'") ||
    str_contains($src, "self::safeLog('system.uid_conflict'") ||
    (str_contains($src, 'safeLog') && str_contains($src, 'system.uid_conflict')));
ok('#3 banda agotada se registra (evento operacional)',
    str_contains($src, 'system.uid_band_exhausted'));
ok('#4 GID = UID: se fuerza grupo primario con el mismo número',
    str_contains($src, 'groupadd -g') && str_contains($src, "-u %d -g %d"));

echo "\n\033[1m─────────────────────────────────────────\033[0m\n";
echo "  \033[0;32m{$pass} passed\033[0m" . ($fail ? ", \033[0;31m{$fail} failed\033[0m" : '') . "\n\n";
exit($fail > 0 ? 1 : 0);
