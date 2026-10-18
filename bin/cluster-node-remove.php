<?php
/**
 * Quita un nodo del registro del cluster (una fila de cluster_nodes), por ejemplo un duplicado
 * registrado por un nombre de DNS que el relevo movió a otro servidor. NO toca el servidor
 * quitado ni sus datos: solo deja de estar en la lista de este panel.
 *
 * Por defecto SOLO ENSEÑA qué se vería afectado (simulación). Con --apply lo quita.
 *
 * Uso (como root, en el servidor cuyo registro se limpia):
 *   php bin/cluster-node-remove.php <id>                          # simulación
 *   php bin/cluster-node-remove.php <id> --apply                  # quita el nodo
 *   php bin/cluster-node-remove.php <id> --move-mail-to <otro-id> --apply
 *       (si hay dominios de correo asignados al nodo, antes se pasan al otro; sin eso, se niega)
 *
 * Lo que se pierde al quitarlo: sus tareas pendientes en la cola del cluster y su historial de salud
 * del correo (los dominios y buzones no se tocan; si estaban asignados a este nodo, hay que moverlos).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Database;
use MuseDockPanel\Services\ClusterGuardService;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Settings;

$args = array_slice($argv ?? [], 1);
$apply = in_array('--apply', $args, true);
$id = 0;
$moveTo = 0;
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--move-mail-to') {
        $moveTo = (int)($args[++$i] ?? 0);
    } elseif (ctype_digit($args[$i])) {
        $id = (int)$args[$i];
    }
}
if ($id < 1) {
    fwrite(STDERR, "Uso: php bin/cluster-node-remove.php <id> [--move-mail-to <otro-id>] [--apply]\n");
    exit(1);
}
if ($apply && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Para aplicar hay que ejecutarlo como root (sin --apply solo se simula).\n");
    exit(1);
}

$node = ClusterService::getNode($id);
if (!$node) {
    fwrite(STDERR, "No existe el nodo #{$id}.\n");
    exit(1);
}
$host = (string)(parse_url((string)$node['api_url'], PHP_URL_HOST) ?: '');
echo "Nodo #{$id}: «{$node['name']}» · {$node['api_url']} · rol {$node['role']} · estado {$node['status']} · visto " . ($node['last_seen_at'] ?? 'nunca') . "\n";

// Nunca quitarse a sí mismo
if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) && ClusterGuardService::hostIsSelf($host)) {
    fwrite(STDERR, "Ese nodo es ESTE servidor ({$host}): no se quita.\n");
    exit(1);
}
$viaName = $host !== '' && !filter_var($host, FILTER_VALIDATE_IP) && ClusterGuardService::hostIsSelf($host);
if ($viaName) {
    echo "  · Se registró por el nombre {$host}, que ahora apunta a ESTE servidor (el relevo lo movió).\n";
}
// Otros nodos con el mismo nombre de máquina: ¿hay otro registro que cubre el mismo servidor?
$first = strtolower(preg_replace('/[^a-z0-9].*$/i', '', (string)$node['name']));
$siblings = [];
foreach (ClusterService::getNodes() as $o) {
    if ((int)$o['id'] !== $id && $first !== '' && str_starts_with(strtolower((string)$o['name']), $first)) {
        $siblings[] = "#{$o['id']} «{$o['name']}» ({$o['api_url']}, {$o['status']})";
    }
}
echo $siblings ? '  · Parece duplicado de: ' . implode('; ', $siblings) . "\n" : "  · No hay otro nodo con un nombre parecido: ¿seguro que es un duplicado?\n";

$queue = (int)(Database::fetchOne('SELECT COUNT(*) AS c FROM cluster_queue WHERE node_id = :i', ['i' => $id])['c'] ?? 0);
$health = (int)(Database::fetchOne('SELECT COUNT(*) AS c FROM mail_node_health WHERE node_id = :i', ['i' => $id])['c'] ?? 0);
$domains = Database::fetchAll('SELECT domain FROM mail_domains WHERE mail_node_id = :i ORDER BY domain', ['i' => $id]);
$mig = (int)(Database::fetchOne('SELECT COUNT(*) AS c FROM mail_migrations WHERE source_node_id = :i OR target_node_id = :i', ['i' => $id])['c'] ?? 0);
$isDefault = (int)Settings::get('mail_default_node_id', '0') === $id;
echo "  · Tareas en la cola del cluster que se pierden: {$queue}\n";
echo "  · Filas de salud de correo que se pierden: {$health}\n";
echo '  · Dominios de correo asignados a este nodo: ' . count($domains) . ($domains ? ' (' . implode(', ', array_slice(array_column($domains, 'domain'), 0, 8)) . (count($domains) > 8 ? ', …' : '') . ')' : '') . "\n";
echo "  · Migraciones de correo que lo citan (quedan sin ese extremo): {$mig}\n";
echo '  · Es el nodo de correo por defecto: ' . ($isDefault ? 'SÍ' : 'no') . "\n";

$blockers = [];
if (($domains || $isDefault) && $moveTo < 1) {
    $blockers[] = 'hay dominios de correo (o el nodo por defecto) asignados a este nodo: indica --move-mail-to <otro-id>';
}
if ($moveTo > 0) {
    $target = ClusterService::getNode($moveTo);
    if (!$target || $moveTo === $id) {
        $blockers[] = "el nodo de destino #{$moveTo} no existe o es el mismo";
    } else {
        echo "  · Los dominios (y el valor por defecto) pasarían al nodo #{$moveTo} «{$target['name']}».\n";
    }
}
if ($blockers) {
    foreach ($blockers as $b) {
        echo "  ✗ NO se puede quitar: {$b}\n";
    }
    exit(2);
}
if (!$apply) {
    echo "\nSimulación: no se ha cambiado nada. Para quitarlo: añade --apply\n";
    exit(0);
}

if ($moveTo > 0) {
    Database::query('UPDATE mail_domains SET mail_node_id = :t WHERE mail_node_id = :i', ['t' => $moveTo, 'i' => $id]);
    if ($isDefault) {
        Settings::set('mail_default_node_id', (string)$moveTo);
    }
}
ClusterService::removeNode($id);
LogService::log('cluster.node', 'remove', "Nodo #{$id} «{$node['name']}» quitado del registro (cluster-node-remove)" . ($moveTo > 0 ? "; correo pasado al nodo #{$moveTo}" : ''));
echo "\nQuitado el nodo #{$id} «{$node['name']}» del registro. El servidor en sí no se ha tocado.\n";
