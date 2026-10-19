#!/usr/bin/env php
<?php
/**
 * Herramientas MCP de documentación (docs_catalog / docs_search / docs_read): lógica pura y el catálogo real de /docs.
 * Solo lectura. Usage: php tests/mcp_docs_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
require PANEL_ROOT . '/app/bootstrap.php';

use MuseDockPanel\Mcp\McpDocsTools as D;
use MuseDockPanel\Mcp\McpTools;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }

echo "\n\033[1mLógica pura\033[0m\n";
ok('grupos por URL', D::groupOf('/docs/settings/x') === 'settings' && D::groupOf('/docs/mail/ha') === 'mail' && D::groupOf('/docs/bugs/x') === 'bugs' && D::groupOf('/docs/web-mail') === 'special');
ok('solo URLs de /docs/', D::validUrl('/docs/web-mail') && D::validUrl('/docs/mail/ha') && !D::validUrl('/etc/passwd') && !D::validUrl('/docs/../etc/passwd') && !D::validUrl('http://x/docs/a') && !D::validUrl('/docs/a b'));
$terms = ['dsync'];
ok('el título pesa más que el texto', D::score($terms, 'Correo dsync', '', '', 'x') > D::score($terms, 'Otra', '', '', 'dsync dsync'));
ok('sin coincidencias, puntuación 0', D::score(['zzz'], 'a', 'b', 'c', 'd') === 0);
ok('el fragmento incluye lo buscado', str_contains(D::excerpt(str_repeat('a ', 200) . 'la réplica dsync funciona ' . str_repeat('b ', 200), ['dsync']), 'dsync'));

echo "\n\033[1mCatálogo real de la documentación\033[0m\n";
$c = D::run('docs_catalog', []);
ok('hay más de 50 guías', $c['total'] > 50, (string)$c['total']);
ok('están los cuatro grupos', count($c['por_grupo']) === 4, json_encode($c['por_grupo']));
$empty = array_filter($c['guias'], fn($g) => $g['characters'] === 0);
ok('ninguna guía sale sin texto', $empty === [], implode(', ', array_column($empty, 'url')));
ok('todas las URL son válidas', count(array_filter($c['guias'], fn($g) => !D::validUrl($g['url']))) === 0);
$urls = implode(' ', array_column($c['guias'], 'url'));
$skip = ['index', 'settings-guide-not-found', 'mail-guide-not-found', 'bug-guide-not-found', 'settings-sections', 'mail-sections', 'bugs-sections', 'settings-guide'];
$missing = [];
foreach (glob(PANEL_ROOT . '/resources/views/help/*.php') as $f) {
    $b = basename($f, '.php');
    if (in_array($b, $skip, true)) continue;
    if (stripos($urls, $b) === false && stripos($urls, preg_replace('/^(mail|bug)-/', '', $b)) === false) $missing[] = $b;
}
ok('toda vista de ayuda tiene su entrada en el catálogo (nada queda sin descubrir)', $missing === [], implode(', ', $missing));
$g = D::run('docs_catalog', ['group' => 'mail']);
ok('se puede filtrar por grupo', $g['total'] > 0 && count(array_unique(array_column($g['guias'], 'group'))) === 1);

echo "\n\033[1mBúsqueda y lectura\033[0m\n";
$s = D::run('docs_search', ['query' => 'sendmail_path capturar', 'limit' => 5]);
ok('«sendmail_path capturar» encuentra la guía del correo de las webs', ($s['resultados'][0]['url'] ?? '') === '/docs/web-mail', json_encode(array_column($s['resultados'], 'url')));
$s = D::run('docs_search', ['query' => 'dsync réplica correo identidades']);
ok('la réplica del correo y las identidades están en la guía de Correo HA', in_array('/docs/mail/ha', array_column($s['resultados'], 'url'), true));
$r = D::run('docs_read', ['url' => '/docs/system-mail']);
ok('docs_read devuelve el texto de una guía', str_contains($r['text'], 'myorigin') && $r['offset'] === 0);
$long = null;
foreach ($c['guias'] as $x) { if ($x['characters'] > D::MAX_CHARS + 100) { $long = $x['url']; break; } }
if ($long) {
    $p1 = D::run('docs_read', ['url' => $long]);
    $p2 = D::run('docs_read', ['url' => $long, 'offset' => $p1['next_offset']]);
    ok('una guía larga se lee por partes con next_offset', $p1['next_offset'] > 0 && $p2['offset'] === $p1['next_offset'] && $p2['text'] !== $p1['text']);
}
$threw = false;
try { D::run('docs_read', ['url' => '/docs/../etc/passwd']); } catch (\Throwable) { $threw = true; }
ok('una URL fuera de la doc se rechaza', $threw);
$threw = false;
try { D::run('docs_search', ['query' => '  ']); } catch (\Throwable) { $threw = true; }
ok('una búsqueda vacía se rechaza', $threw);

echo "\n\033[1mRegistro en el MCP\033[0m\n";
$all = McpTools::all();
ok('las tres herramientas están registradas, son de solo lectura', isset($all['docs_catalog'], $all['docs_search'], $all['docs_read']) && empty($all['docs_read']['write']) && empty($all['docs_search']['write']));

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
