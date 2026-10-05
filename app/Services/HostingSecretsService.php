<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;

/**
 * Permisos de los ficheros secretos de cada hosting (.env y wp-config.php).
 *
 * Todos los usuarios de hosting están en el grupo www-data y los homes son 750 con
 * grupo www-data: un .env o wp-config.php legible por el grupo www-data (o por todos)
 * lo puede leer CUALQUIER otro hosting (claves de base de datos, de correo, de API).
 * Regla: el fichero es del usuario del hosting y solo él lo lee (600); si está cerrado
 * por "Blindar WordPress" (strict, dueño root), root:grupo-propio-del-hosting 640.
 *
 * Solo cambia permisos y grupo (nada se borra). Lo que tenga un dueño inesperado no se
 * toca: se informa para revisarlo a mano.
 */
class HostingSecretsService
{
    private const NAMES = ['.env', 'wp-config.php'];
    private const SKIP_DIRS = ['node_modules', 'vendor', '.git', 'cache', 'sessions', 'logs', 'tmp', 'uploads'];

    /** @return array{changes: array, review: array, ok_count: int} */
    public static function scan(bool $apply = false): array
    {
        $changes = [];
        $review = [];
        $ok = 0;
        foreach (Database::fetchAll("SELECT domain, username, home_dir FROM hosting_accounts WHERE status != 'deleted' ORDER BY domain") as $acc) {
            $home = rtrim((string)$acc['home_dir'], '/');
            $pw = posix_getpwnam((string)$acc['username']);
            if ($home === '' || !is_dir($home) || !$pw || !str_starts_with((string)realpath($home), '/var/www/vhosts/')) {
                continue;
            }
            $ownGroup = (string)(posix_getgrgid((int)$pw['gid'])['name'] ?? $acc['username']);
            foreach (self::find($home) as $f) {
                $mode = fileperms($f) & 0777;
                $uid = fileowner($f);
                $gid = filegroup($f);
                $rel = substr($f, strlen($home) + 1);
                if ($uid === (int)$pw['uid']) {
                    $want = 0600;   // solo su dueño (PHP corre como él)
                    $wantGid = $gid;
                } elseif ($uid === 0) {
                    $want = 0640;   // cerrado por strict: root + grupo propio del hosting
                    $wantGid = (int)$pw['gid'];
                } else {
                    $owner = posix_getpwuid($uid)['name'] ?? (string)$uid;
                    $review[] = "{$acc['domain']}: {$rel} es de {$owner}, no de {$acc['username']}: revisar a mano";
                    continue;
                }
                if ($mode === $want && $gid === $wantGid) {
                    $ok++;
                    continue;
                }
                $from = sprintf('%o %s', $mode, posix_getgrgid($gid)['name'] ?? $gid);
                $to = sprintf('%o %s', $want, $wantGid === $gid ? (posix_getgrgid($gid)['name'] ?? $gid) : $ownGroup);
                $line = ['domain' => $acc['domain'], 'file' => $rel, 'from' => $from, 'to' => $to,
                    'risk' => ($mode & 0004) ? 'LEGIBLE POR TODOS' : (($mode & 0040) && ($gid === 33) ? 'legible por todos los hostings (grupo www-data)' : 'legible por su grupo')];
                if ($apply) {
                    $okc = @chmod($f, $want) && ($wantGid === $gid || @chgrp($f, $wantGid));
                    $line['applied'] = $okc ? 'sí' : 'ERROR';
                }
                $changes[] = $line;
            }
        }
        if ($apply && $changes) {
            LogService::log('security.secrets', 'permisos', count($changes) . ' fichero(s) secreto(s) cerrados (.env/wp-config.php)');
        }
        return ['changes' => $changes, 'review' => $review, 'ok_count' => $ok];
    }

    /** .env (no .env.example) y wp-config.php del hosting, sin entrar en vendor, node_modules… */
    private static function find(string $home): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($home, \FilesystemIterator::SKIP_DOTS),
            static fn($f) => $f->isDir() ? (!$f->isLink() && !in_array($f->getFilename(), self::SKIP_DIRS, true)) : true
        ), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        $it->setMaxDepth(5);
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink() && in_array($f->getFilename(), self::NAMES, true)) {
                $out[] = $f->getPathname();
            }
        }
        return $out;
    }
}
