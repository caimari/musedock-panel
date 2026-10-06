<?php
/**
 * Testigos externos ("solo ojos") de este panel (WitnessService).
 *
 *   php bin/witness.php add <nombre> <https://IP:puerto> <huella-sha256>   (pide la clave sin mostrarla)
 *   php bin/witness.php list
 *   php bin/witness.php test [nombre]
 *   php bin/witness.php remove <nombre>
 *   php bin/witness.php export-key <nombre> | ssh root@otro "cd /opt/musedock-panel && php bin/witness.php add <nombre> <url> <huella>"
 *       (dar de alta en otro panel un testigo que este ya tiene; la clave solo sale por
 *        una tubería, nunca a la pantalla)
 *
 * La clave se escribe en la terminal (no se ve) o se pasa por la entrada estándar
 * (p. ej. desde un fichero de root): nunca como argumento, para que no salga en `ps`.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\WitnessService;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root.\n");
    exit(1);
}
$print = static fn($d) => print(json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

switch ($argv[1] ?? '') {
    case 'add':
        [$name, $url, $fp] = [(string)($argv[2] ?? ''), (string)($argv[3] ?? ''), (string)($argv[4] ?? '')];
        if ($name === '' || $url === '' || $fp === '') {
            fwrite(STDERR, "Uso: add <nombre> <https://IP:puerto> <huella-sha256>\n");
            exit(1);
        }
        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            fwrite(STDERR, "Clave del testigo {$name} (no se mostrará): ");
            shell_exec('stty -echo');
            $key = trim((string)fgets(STDIN));
            shell_exec('stty echo');
            fwrite(STDERR, "\n");
        } else {
            $key = trim((string)stream_get_contents(STDIN));
        }
        $r = WitnessService::add($name, $url, $fp, $key);
        $print($r);
        exit(empty($r['ok']) ? 1 : 0);
    case 'list':
        $print(array_map(static fn($w) => ['name' => $w['name'], 'url' => $w['url'], 'fingerprint' => $w['fingerprint']], WitnessService::all()));
        exit(0);
    case 'test':
        $only = (string)($argv[2] ?? '');
        foreach (WitnessService::all() as $w) {
            if ($only !== '' && $w['name'] !== $only) {
                continue;
            }
            $a = WitnessService::query($w);
            echo "== {$w['name']} ({$w['url']}): " . (!empty($a['ok']) ? 'responde' : 'NO responde — ' . ($a['error'] ?? '?')) . "\n";
            foreach ((array)($a['targets'] ?? []) as $id => $t) {
                printf("   %-16s %-16s %-6s %5s ms  pérdida %d%%\n", $id, $t['addr'] ?? '?', !empty($t['ok']) ? 'ok' : 'FALLA', $t['latency_avg_ms'] ?? '-', (int)($t['loss_pct'] ?? 0));
            }
        }
        exit(0);
    case 'export-key':
        // Solo hacia otro comando (tubería): si la salida es la pantalla, se niega.
        if (function_exists('posix_isatty') && posix_isatty(STDOUT)) {
            fwrite(STDERR, "La clave no se muestra en pantalla: úsalo con una tubería hacia 'witness.php add' en el otro panel.\n");
            exit(1);
        }
        $name = (string)($argv[2] ?? '');
        foreach (WitnessService::all() as $w) {
            if ($w['name'] === $name) {
                $key = \MuseDockPanel\Services\ReplicationService::decryptPassword((string)($w['key'] ?? ''));
                if ($key === '') {
                    fwrite(STDERR, "No se pudo descifrar la clave de {$name}.\n");
                    exit(1);
                }
                fwrite(STDERR, "Testigo {$name}: url {$w['url']} huella {$w['fingerprint']}\n");
                echo $key;
                exit(0);
            }
        }
        fwrite(STDERR, "No hay ningún testigo llamado {$name} en este panel.\n");
        exit(1);
    case 'remove':
        echo WitnessService::remove((string)($argv[2] ?? '')) ? "Quitado.\n" : "No estaba.\n";
        exit(0);
    default:
        fwrite(STDERR, "Uso: php bin/witness.php add <nombre> <https://IP:puerto> <huella> | list | test [nombre] | remove <nombre> | export-key <nombre>\n");
        exit(1);
}
