<?php
/** SensitiveFilesService: patrones sin nada demasiado amplio, regex válida y sin cambios por defecto (opt-in). Solo lee ajustes. */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\SensitiveFilesService as S;
use MuseDockPanel\Services\SystemService;

$ok = 0; $bad = 0;
function check(string $n, bool $c): void { global $ok, $bad; echo ($c ? "  ✓ " : "  ✗ ") . $n . "\n"; $c ? $ok++ : $bad++; }

echo "Patrones\n";
$all = array_merge(S::ROOT_FILES, S::EXTENSIONS, array_map(fn($d) => '/' . $d . '/*', S::DIRS));
$broad = ['*', '/*', '/storage/*', '/storage/app/*', '*.php', '*.js', '*.css', '*.html', '/wp-content/*', '*.md', '*.sh', '*.txt', '*.json'];
check('ningún patrón demasiado amplio', !array_intersect($all, $broad));
check('los volcados y logs están', in_array('*.sql.gz', S::EXTENSIONS, true) && in_array('*.log', S::EXTENSIONS, true));
check('vendor y storage/app/backups están', in_array('vendor', S::DIRS, true) && in_array('storage/app/backups', S::DIRS, true));
check('NO se bloquea /storage entero (uploads públicos)', !in_array('storage', S::DIRS, true) && !in_array('storage/app', S::DIRS, true));

echo "Por defecto no cambia nada (opt-in)\n";
$tmp = sys_get_temp_dir() . '/sf-test-' . getmypid();
@mkdir($tmp . '/vendor', 0777, true);
check('docroot que no está en la lista → sin rutas de bloqueo', S::caddyRoutes($tmp) === [] || S::enabledFor($tmp));
$sub = SystemService::buildCaddySubroutes($tmp, 'x', '8.3', 'php');
$hasDeny = false;
foreach ($sub as $r) {
    foreach ($r['handle'] ?? [] as $h) {
        if (($h['handler'] ?? '') === 'static_response' && ($h['status_code'] ?? 0) === 404) { $hasDeny = true; }
    }
}
check('las subrutas no llevan 404 de bloqueo si no está activado', S::enabledFor($tmp) === $hasDeny);
check('la regex de scripts compila', @preg_match('/(?i)^\/[^\/]+\.sh$/', '/a.sh') === 1 && @preg_match('/(?i)^\/[^\/]+\.sh$/', '/a/b.sh') === 0);
@rmdir($tmp . '/vendor'); @rmdir($tmp);

echo "\n" . ($bad ? "$bad fallo(s) de " . ($ok + $bad) : "Todo correcto: $ok comprobaciones") . "\n";
exit($bad ? 1 : 0);
