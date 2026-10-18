<?php
/**
 * Instala (o quita) el guardián de arranque: musedock-boot-guard.service.
 *
 * Uso (como root):
 *   php /opt/musedock-panel/bin/install-boot-guard.php            instala y habilita (vale desde el próximo arranque)
 *   php /opt/musedock-panel/bin/install-boot-guard.php --dry-run  solo enseña la unidad que pondría
 *   php /opt/musedock-panel/bin/install-boot-guard.php --remove   lo desinstala
 *
 * No arranca nada ahora y no toca ningún servicio en marcha. Para probar la decisión sin aislar
 * nada: php /opt/musedock-panel/bin/boot-guard.php --dry-run
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Services\ClusterGuardService;
use MuseDockPanel\Services\PgClusterService;

$unitFile = '/etc/systemd/system/musedock-boot-guard.service';
$dry = in_array('--dry-run', $argv ?? [], true);

if (in_array('--remove', $argv ?? [], true)) {
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fwrite(STDERR, "Hay que ejecutarlo como root.\n");
        exit(1);
    }
    shell_exec('systemctl disable musedock-boot-guard.service 2>&1');
    @unlink($unitFile);
    shell_exec('systemctl daemon-reload 2>&1');
    echo "Guardián de arranque desinstalado.\n";
    exit(0);
}

if (!$dry && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Hay que ejecutarlo como root (o con --dry-run para ver la unidad).\n");
    exit(1);
}

// La BD del panel (de la que lee los ajustes) debe estar levantada antes; las bases de datos de
// datos deben esperar al guardián. Si el clúster del panel es el único, no se ordena respecto a él.
$panelPort = (int)\MuseDockPanel\Env::int('DB_PORT', 5432);
$all = PgClusterService::listClusters();
$panelUnit = '';
$before = [];
foreach ($all as $c) {
    $isPanel = $c['cluster'] === 'panel' || (count($all) > 1 && (int)$c['port'] === $panelPort) || (count($all) === 1);
    if ($isPanel && $panelUnit === '') {
        $panelUnit = (string)$c['unit'];
    } elseif (!$isPanel) {
        $before[] = (string)$c['unit'];
    }
}
foreach (['caddy.service' => '/lib/systemd/system/caddy.service', 'dovecot.service' => '/lib/systemd/system/dovecot.service', 'postfix@-.service' => '/lib/systemd/system/postfix@.service'] as $u => $file) {
    if (is_file($file)) {
        $before[] = $u;
    }
}
$wgUnit = '';
foreach (glob('/etc/systemd/system/*.target.wants/wg-quick@*.service') ?: [] as $w) {
    $wgUnit = basename($w);
    break;
}

$content = ClusterGuardService::bootGuardUnitContent(__DIR__ . '/boot-guard.php', $panelUnit, $before, $wgUnit);
if ($dry) {
    echo $content;
    exit(0);
}
file_put_contents($unitFile, $content);
@chmod($unitFile, 0644);
echo shell_exec('systemctl daemon-reload 2>&1; systemctl enable musedock-boot-guard.service 2>&1');
$verify = trim((string)shell_exec('systemd-analyze verify ' . escapeshellarg($unitFile) . ' 2>&1'));
echo "Guardián de arranque instalado: {$unitFile}\n";
echo $verify !== '' ? "systemd-analyze verify:\n{$verify}\n" : "systemd-analyze verify: sin avisos\n";
echo "Vale desde el próximo arranque. Probar la decisión sin aislar nada: php " . __DIR__ . "/boot-guard.php --dry-run\n";
