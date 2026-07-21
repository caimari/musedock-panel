#!/usr/bin/env php
<?php
/**
 * G1+G2 APPLY (REAL) — panel cluster (5433):
 *   G1: listen_addresses = '127.0.0.1,<WG>'   (abre 5433 solo a la WireGuard)
 *   G2: wal_log_hints = on                    (requisito de pg_rewind)
 * + rol de replicación + slot físico + pg_hba /32 del slave.
 *
 * ⚠️ REINICIA el cluster 5433 (~2-5s): corta panel + servidor de licencias.
 *    Ejecutar EN VENTANA TRANQUILA, como ROOT:
 *      sudo php /opt/musedock-panel/bin/g1g2-apply.php --slave=10.10.70.154 --confirm
 *
 * Sin --confirm hace DRY-RUN. La contraseña de replicación se genera y se guarda
 * cifrada en panel_settings (repl_pg_password) para que G3 la reutilice.
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

use MuseDockPanel\Services\PgClusterService;
use MuseDockPanel\Services\ReplicationService;
use MuseDockPanel\Settings;

$opts = getopt('', ['slave:', 'confirm']);
$slaveIp = $opts['slave'] ?? '10.10.70.154';
$confirm = isset($opts['confirm']);

if (!filter_var($slaveIp, FILTER_VALIDATE_IP)) {
    fwrite(STDERR, "IP de slave inválida: {$slaveIp}\n"); exit(1);
}
if (posix_geteuid() !== 0 && $confirm) {
    fwrite(STDERR, "Debe ejecutarse como root con --confirm (edita /etc/postgresql y reinicia el cluster).\n");
    exit(1);
}

$panel = PgClusterService::getPanelCluster();
if (!$panel) { fwrite(STDERR, "No se pudo resolver el cluster panel\n"); exit(1); }

// Reuse an existing replication password if present, else generate one.
$replUser = 'repl_panel';
$replPass = Settings::get('repl_pg_password', '');
if ($replPass === '') {
    $replPass = bin2hex(random_bytes(18));
}
$slot = 'panel_slave_' . preg_replace('/[^0-9]/', '', $slaveIp); // e.g. panel_slave_101070154

echo "== G1+G2 sobre cluster panel (5433) ==\n";
echo "  slave (Filemon) = {$slaveIp}\n";
echo "  rol replicación = {$replUser}\n";
echo "  slot            = {$slot}\n";
echo "  modo            = " . ($confirm ? "APLICAR (reinicia 5433)" : "DRY-RUN") . "\n\n";

$res = ReplicationService::setupPgMasterForCluster(
    $panel, [$slaveIp], $replUser, $replPass, [$slot], /*dryRun*/ !$confirm
);

echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

if ($confirm && ($res['ok'] ?? false)) {
    // The cluster we just restarted IS this script's own database, so the pooled
    // PDO handle is dead ("terminating connection due to administrator command").
    // Persist the credentials over a FRESH connection, retrying while the cluster
    // finishes coming back up.
    $saved = false; $lastErr = '';
    $cfg = require PANEL_ROOT . '/config/panel.php';
    $db  = $cfg['db'];
    for ($i = 0; $i < 15; $i++) {
        try {
            $pdo = new PDO(
                "pgsql:host={$db['host']};port={$db['port']};dbname={$db['database']};connect_timeout=3",
                $db['username'], $db['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $stmt = $pdo->prepare(
                "INSERT INTO panel_settings (key, value) VALUES (:k, :v)
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value"
            );
            foreach ([
                'repl_pg_password'     => $replPass,
                'repl_pg_user'         => $replUser,
                'repl_panel_slot'      => $slot,
                'repl_panel_slave_ip'  => $slaveIp,
                'repl_panel_port'      => (string)$panel['port'],
            ] as $k => $v) {
                $stmt->execute(['k' => $k, 'v' => $v]);
            }
            $saved = true;
            break;
        } catch (Throwable $e) {
            $lastErr = $e->getMessage();
            sleep(1); // cluster still restarting
        }
    }

    echo "\n✓ G1+G2 aplicados sobre el clúster panel (5433).\n";
    if ($saved) {
        echo "✓ Credenciales de replicación guardadas en panel_settings (las usará G3).\n";
    } else {
        echo "⚠ G1+G2 OK, pero NO se pudieron guardar las credenciales: {$lastErr}\n";
        echo "  Vuelve a ejecutar este script (es idempotente) para guardarlas.\n";
    }
    echo "\n  Verifica:\n";
    echo "    sudo -u postgres psql -p {$panel['port']} -tAc \"SHOW wal_log_hints;\"\n";
    echo "    sudo -u postgres psql -p {$panel['port']} -tAc \"SHOW listen_addresses;\"\n";
    if (!$saved) exit(1);
} elseif ($confirm) {
    echo "\n✗ Falló. Revisa 'steps'. La config tiene backup (.bak) por si hay que revertir.\n";
    exit(1);
}
