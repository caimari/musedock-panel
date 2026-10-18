#!/usr/bin/env php
<?php
/**
 * La revisión de correo no marca «error» un nodo réplica (correo parado a propósito).
 * Solo lógica: no abre conexiones ni toca servicios.
 *
 * Usage: php tests/mail_node_standby_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

use MuseDockPanel\Services\MailService;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }

echo "\n\033[1mNodo réplica o principal\033[0m\n";
ok('esclavo del clúster = en espera', MailService::mailNodeIsStandby(['cluster_role' => 'slave']));
ok('aislado por un relevo = en espera', MailService::mailNodeIsStandby(['fenced' => true, 'cluster_role' => 'master']));
ok('PostgreSQL en recuperación (vivo) = en espera', MailService::mailNodeIsStandby(['is_replica' => true, 'pg_alive' => true]));
ok('principal sano NO está en espera', !MailService::mailNodeIsStandby(['cluster_role' => 'master', 'is_replica' => false, 'pg_alive' => true]));
ok('sin datos del nodo (API caída) NO se da por réplica', !MailService::mailNodeIsStandby([]));
ok('PostgreSQL caído en un principal NO se disfraza de réplica', !MailService::mailNodeIsStandby(['cluster_role' => 'master', 'pg_alive' => false, 'is_replica' => false]));

$src = file_get_contents(PANEL_ROOT . '/app/Services/MailService.php');
ok('los puertos cerrados de una réplica no ponen allOk a false', (bool)preg_match('/!\$ok && \$standby\)\s*\{[^}]*\[\'ok\'\] = true;/s', $src));
ok('el nodo informa su papel (cluster_role y fenced)', str_contains($src, "'cluster_role' => (string)Settings::get('cluster_role', '')") && str_contains($src, "'fenced' =>"));

echo "\n\033[1mBase de datos de correo en una réplica\033[0m\n";
$m = new ReflectionMethod(MailService::class, 'evaluateMailDbHealth');
$m->setAccessible(true);
$ev = fn(array $h) => $m->invoke(null, $h);
$d = $ev(['mode' => 'full', 'standby' => true, 'pg_alive' => false, 'pg_read_ok' => false]);
ok('réplica con PostgreSQL local sin respuesta: activo y sin pausar la cola', $d['status'] === 'active' && !$d['pause_queue'], json_encode($d));
$d = $ev(['mode' => 'full', 'standby' => true, 'pg_alive' => true, 'pg_read_ok' => false]);
ok('réplica que no lee las tablas de correo: activo', $d['status'] === 'active' && !$d['pause_queue']);
$d = $ev(['mode' => 'full', 'standby' => false, 'pg_alive' => false]);
ok('un principal con PostgreSQL caído SIGUE siendo «down» y pausa la cola', $d['status'] === 'down' && $d['pause_queue']);
$d = $ev(['mode' => 'full', 'standby' => false, 'pg_alive' => true, 'pg_read_ok' => false]);
ok('un principal que no lee las tablas SIGUE degradado', $d['status'] === 'degraded');

echo "\n\033[1mAlias en la ruta viva de Caddy\033[0m\n";
ok('sin ruta legible no se toca nada', \MuseDockPanel\Services\DomainAliasService::aliasesMissingFromRoute('http://127.0.0.1:1', 'x', ['a.example']) === []);
ok('sin alias no hay nada que comprobar', \MuseDockPanel\Services\DomainAliasService::aliasesMissingFromRoute('http://127.0.0.1:1', 'x', []) === []);

echo "\n" . ($fail === 0 ? "\033[0;32mTodo correcto: {$pass} comprobaciones\033[0m" : "\033[0;31m{$fail} fallo(s) de " . ($pass + $fail) . "\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
