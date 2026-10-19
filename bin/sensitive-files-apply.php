<?php
/**
 * Pone (o actualiza) en la ruta de Caddy de un hosting el bloqueo de ficheros sensibles (SensitiveFilesService:
 * volcados .sql*, logs, .env, .git, vendor, database, storage/logs…, todo con 404).
 *
 * Las rutas NUEVAS ya lo llevan al crearse. Las que ya existen no se tocan solas: hay que reconstruirlas, y este
 * script lo hace de una en una para poder probar cada web.
 *
 *   php bin/sensitive-files-apply.php --domain=ejemplo.com           enseña qué se bloquearía (no cambia nada)
 *   php bin/sensitive-files-apply.php --domain=ejemplo.com --apply   activa el bloqueo en ESE hosting y reconstruye su ruta (como root)
 *   php bin/sensitive-files-apply.php --domain=ejemplo.com --revert  lo desactiva en ese hosting y reconstruye su ruta (como root)
 * Es opt-in por hosting y el ajuste es de cada nodo: aplícalo en cada nodo (Mortadelo y Filemon).
 *   php bin/sensitive-files-apply.php --all                          lista qué hostings tienen el docroot con ficheros sensibles
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Database;
use MuseDockPanel\Services\DomainAliasService;
use MuseDockPanel\Services\SensitiveFilesService;

$opts = [];
foreach (array_slice($argv ?? [], 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

/** Qué hay en el docroot que esta regla tapa (para decidir con datos). */
function exposed(string $docRoot): array
{
    $found = [];
    foreach (SensitiveFilesService::DIRS as $d) {
        if (is_dir($docRoot . '/' . $d)) {
            $found[] = $d . '/';
        }
    }
    foreach (SensitiveFilesService::ROOT_FILES as $f) {
        if (!str_contains($f, '*') && is_file($docRoot . $f)) {
            $found[] = ltrim($f, '/');
        }
    }
    $cmd = 'find ' . escapeshellarg($docRoot) . ' -xdev \( -path "*/vendor" -o -path "*/node_modules" -o -path "*/.git" \) -prune -o -type f \( -name "*.sql" -o -name "*.sql.gz" -o -name "*.log" -o -name "*.sqlite" \) -print 2>/dev/null | head -n 5';
    foreach (array_filter(explode("\n", (string)shell_exec($cmd))) as $p) {
        $found[] = str_replace($docRoot . '/', '', $p);
    }
    return $found;
}

if (isset($opts['all'])) {
    foreach (Database::fetchAll("SELECT domain, home_dir, document_root FROM hosting_accounts WHERE status = 'active' ORDER BY domain") as $a) {
        $root = rtrim((string)($a['document_root'] ?: rtrim((string)$a['home_dir'], '/') . '/httpdocs'), '/');
        if (!is_dir($root)) {
            continue;
        }
        $e = exposed($root);
        echo sprintf("%-32s %s\n", $a['domain'], $e ? implode(', ', array_slice($e, 0, 8)) : '—');
    }
    exit(0);
}

$domain = is_string($opts['domain'] ?? null) ? strtolower($opts['domain']) : '';
if ($domain === '') {
    fwrite(STDERR, "Uso: php bin/sensitive-files-apply.php --domain=ejemplo.com [--apply]   |   --all\n");
    exit(1);
}
$acc = Database::fetchOne("SELECT * FROM hosting_accounts WHERE lower(domain) = :d AND status = 'active'", ['d' => $domain]);
if (!$acc) {
    fwrite(STDERR, "No hay un hosting activo con ese dominio.\n");
    exit(1);
}
$docRoot = rtrim((string)($acc['document_root'] ?: rtrim((string)$acc['home_dir'], '/') . '/httpdocs'), '/');
$wasOn = SensitiveFilesService::enabledFor($docRoot);
if (isset($opts['apply']) || isset($opts['revert'])) {
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fwrite(STDERR, "Para cambiarlo hay que ejecutarlo como root.\n");
        exit(1);
    }
    SensitiveFilesService::setEnabled($docRoot, isset($opts['apply']));
    // En el master, avisa ya a los demás nodos (cada uno guarda el ajuste y rehace la ruta de esa web)
    if (\MuseDockPanel\Settings::get('cluster_role', 'standalone') === 'master') {
        foreach (SensitiveFilesService::pushToNodes($docRoot, isset($opts['apply'])) as $n => $r) {
            echo "Nodo {$n}: {$r}\n";
        }
    }
}
if (isset($opts['revert'])) {
    $ok = DomainAliasService::rebuildCaddyRoute($acc);
    echo $ok ? "Bloqueo desactivado en {$domain}; ruta reconstruida.\n" : "ERROR al reconstruir la ruta.\n";
    exit($ok ? 0 : 1);
}
// Para enseñar las reglas aunque aún no esté activado en este hosting:
$routes = SensitiveFilesService::caddyRoutes($docRoot) ?: (function () use ($docRoot) {
    $keep = SensitiveFilesService::roots();
    SensitiveFilesService::setEnabled($docRoot, true);
    $r = SensitiveFilesService::caddyRoutes($docRoot);
    \MuseDockPanel\Settings::set('hosting_sensitive_roots', implode("\n", $keep));
    return $r;
})();
if (!$routes) {
    echo "El bloqueo está apagado (hosting_sensitive_deny=0): no se añade nada.\n";
    exit(0);
}
echo "Se responderá 404 a:\n";
foreach ($routes as $r) {
    $m = $r['match'][0];
    echo '  ' . (isset($m['path']) ? implode(' ', $m['path']) : 'regex ' . $m['path_regexp']['pattern']) . "\n";
}
$e = exposed($docRoot);
echo $e ? "En este docroot hay ahora (se taparían): " . implode(', ', $e) . "\n" : "En este docroot no hay nada de esto ahora.\n";

if (!isset($opts['apply'])) {
    echo "\n" . ($wasOn ? "(El bloqueo YA está activo en este hosting.)" : "(No se ha cambiado nada. Para activarlo: --apply, como root.)") . "\n";
    exit(0);
}
$ok = DomainAliasService::rebuildCaddyRoute($acc);
echo $ok ? "Ruta de Caddy reconstruida para {$domain}. Prueba la web y un fichero tapado (debe dar 404).\n" : "ERROR al reconstruir la ruta.\n";
exit($ok ? 0 : 1);
