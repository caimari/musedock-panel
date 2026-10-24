<?php
// Default is preview. --apply installs for next boot, NEVER starts or restarts services.
require dirname(__DIR__) . '/app/bootstrap.php';
use MuseDockPanel\Services\{PgClusterService, HaReadinessService};
try {
    $apply = in_array('--apply', $argv, true);
    if ($apply && !is_file(dirname(__DIR__) . '/.env')) throw new RuntimeException('No instalar desde una copia sin .env local.');
    $panelPort = (int)MuseDockPanel\Env::get('DB_PORT', 5433);
    $panelUnit = '';
    $names = [];
    foreach (PgClusterService::listClusters() as $c) {
        $unit = $c['unit'] . '.service';
        if ((int)$c['port'] === $panelPort) $panelUnit = $unit;
        else $names[] = $unit;
    }
    if ($panelUnit === '' || !$names) throw new RuntimeException('Se exige BD del panel separada y clúster de datos identificados.');
    foreach (['caddy.service','redis-server.service','supervisor.service','lsyncd.service','mariadb.service','mysql.service','dovecot.service','postfix@-.service'] as $unit) {
        $load = trim((string)shell_exec('systemctl show ' . escapeshellarg($unit) . ' -p LoadState --value 2>/dev/null'));
        if (in_array($load, ['loaded','masked'], true)) $names[] = $unit;
    }
    $unitPath = '/etc/systemd/system/musedock-ha-boot-guard.service';
    $plan = [$unitPath => HaReadinessService::bootUnit(__DIR__ . '/ha-boot-check.php', $panelUnit)];
    foreach (array_unique($names) as $unit) {
        if (!preg_match('/^[a-zA-Z0-9@_.:-]+\.service$/', $unit)) throw new RuntimeException('Unidad no válida');
        $plan['/etc/systemd/system/' . $unit . '.d/zz-musedock-ha-boot.conf'] = HaReadinessService::consumerDropin();
    }
    if (!$apply) {
        foreach ($plan as $file => $content) echo "### {$file}\n{$content}\n";
        echo "PREVIEW: no se ha instalado nada. Revisar orden, aliases, hooks y ciclo de recuperación antes de --apply.\n";
        exit(0);
    }
    if (posix_geteuid() !== 0) throw new RuntimeException('Se requiere root.');
    $backup = '/var/lib/musedock/ha-boot-backups/' . gmdate('YmdHis') . '-' . getmypid();
    if (!mkdir($backup, 0700, true)) throw new RuntimeException('No se pudo crear backup');
    $manifest = [];
    foreach ($plan as $file => $content) {
        if (is_link($file)) throw new RuntimeException('No sobrescribir enlaces: ' . $file);
        $manifest[$file] = is_file($file) ? base64_encode((string)file_get_contents($file)) : null;
    }
    file_put_contents($backup . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    foreach ($plan as $file => $content) {
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true)) throw new RuntimeException('No se pudo crear directorio');
        if (file_put_contents($file, $content) === false) throw new RuntimeException('No se pudo escribir ' . $file);
        chmod($file, 0644);
    }
    passthru('systemctl daemon-reload', $code);
    if ($code !== 0) throw new RuntimeException('daemon-reload falló; restaurar manifest: ' . $backup);
    passthru('systemd-analyze verify ' . escapeshellarg($unitPath), $code);
    if ($code !== 0) throw new RuntimeException('Verificación falló: NO habilitado; restaurar manifest: ' . $backup);
    passthru('systemctl enable musedock-ha-boot-guard.service', $code);
    if ($code !== 0) throw new RuntimeException('No se pudo habilitar; restaurar manifest: ' . $backup);
    echo "Instalado para el próximo arranque. No se ha iniciado ni reiniciado ningún servicio. Backup: {$backup}\n";
} catch (Throwable $e) {
    if (isset($manifest, $backup)) {
        foreach ($manifest as $file => $previous) {
            if ($previous === null) {
                if (is_file($file)) unlink($file);
            } else {
                file_put_contents($file, base64_decode($previous, true));
            }
        }
        shell_exec('systemctl daemon-reload 2>&1');
        fwrite(STDERR, "Ficheros anteriores restaurados; backup conservado: {$backup}\n");
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
