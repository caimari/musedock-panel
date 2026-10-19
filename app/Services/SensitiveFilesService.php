<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Ficheros que NUNCA deben salir por web en un hosting cuyo docroot no es public/ (Caddy sirve la carpeta entera):
 * volcados de bases de datos, logs, .env, .git, carpetas de Laravel (vendor, database, storage/logs…), ficheros de
 * proyecto. Se añade a la ruta de Caddy de cada hosting como respuestas 404 (no se revela que existan).
 *
 * Caso real (2026-10-19, picalias): 16 volcados .sql.gz con usuarios y hashes de contraseña, y laravel.log, servidos con
 * 200 desde el docroot. Es una red de seguridad, no sustituye a sacar esos ficheros del docroot.
 *
 * OPT-IN por hosting (una app podría servir algo de /vendor/ a propósito): solo se aplica a los docroots de la lista
 * hosting_sensitive_roots (uno por línea; «*» = todos), que rellena bin/sensitive-files-apply.php --apply al probar
 * cada web. Las carpetas (vendor, database, storage/…) solo se bloquean si EXISTEN en el docroot. Apagado general:
 * hosting_sensitive_deny=0. Los ajustes son de cada nodo: hay que aplicarlo en cada uno.
 */
final class SensitiveFilesService
{
    /** Carpetas que, si existen en el docroot, no se sirven (ruta relativa al docroot). */
    public const DIRS = ['vendor', 'database', 'storage/logs', 'storage/framework', 'storage/app/backups', '.claude', '.idea', '.vscode'];

    /** Rutas exactas de la raíz (ficheros de proyecto y de configuración). */
    public const ROOT_FILES = ['/.env', '/.env.*', '/composer.json', '/composer.lock', '/package.json', '/package-lock.json',
        '/yarn.lock', '/artisan', '/phpunit.xml', '/phpunit.xml.dist', '/docker-compose.yml', '/Dockerfile'];

    /** Extensiones que no se sirven en ninguna carpeta (volcados, bases de datos locales, logs). */
    public const EXTENSIONS = ['*.sql', '*.sql.gz', '*.sql.zip', '*.sql.bz2', '*.sqlite', '*.sqlite3', '*.log', '*.log.gz', '*.log.1', '*.dump'];

    /** Docroots con el bloqueo activado (lista hosting_sensitive_roots; «*» = todos). */
    public static function roots(): array
    {
        $out = [];
        foreach (preg_split('/\R+/', (string)Settings::get('hosting_sensitive_roots', '')) ?: [] as $l) {
            $l = rtrim(trim($l), '/');
            if ($l !== '') {
                $out[$l] = true;
            }
        }
        return array_keys($out);
    }

    public static function enabledFor(string $docRoot): bool
    {
        $r = self::roots();
        return in_array('*', $r, true) || in_array(rtrim($docRoot, '/'), $r, true);
    }

    public static function setEnabled(string $docRoot, bool $on): void
    {
        $docRoot = rtrim($docRoot, '/');
        $r = array_values(array_filter(self::roots(), static fn($x) => $x !== $docRoot));
        if ($on) {
            $r[] = $docRoot;
        }
        sort($r);
        Settings::set('hosting_sensitive_roots', implode("\n", $r));
    }

    /** Rutas de Caddy (para el subroute del hosting). Pura salvo la comprobación de carpetas del docroot. */
    public static function caddyRoutes(string $docRoot): array
    {
        if (Settings::get('hosting_sensitive_deny', '1') === '0' || !self::enabledFor($docRoot)) {
            return [];
        }
        $deny = static fn(array $match) => ['match' => [$match], 'handle' => [[
            'handler' => 'static_response', 'status_code' => 404, 'body' => "404 Not Found\n", 'close' => true]], 'terminal' => true];

        $paths = ['/.git', '/.git/*', '/.svn/*', '/.hg/*', '*/.DS_Store'];
        foreach (self::ROOT_FILES as $f) {
            $paths[] = $f;
        }
        foreach (self::DIRS as $d) {
            if (is_dir(rtrim($docRoot, '/') . '/' . $d)) {
                $paths[] = '/' . $d;
                $paths[] = '/' . $d . '/*';
            }
        }
        $routes = [$deny(['path' => $paths]), $deny(['path' => self::EXTENSIONS])];
        // Scripts de shell en la raíz (instaladores, scripts de despliegue): solo en la raíz, no en subcarpetas.
        $routes[] = $deny(['path_regexp' => ['name' => 'sensitiveroot', 'pattern' => '(?i)^/[^/]+\.sh$']]);
        return $routes;
    }
}
