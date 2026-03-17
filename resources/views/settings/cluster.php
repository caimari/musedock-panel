<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php
    $role = $localStatus['role'] ?? 'standalone';
    $replRole = $localStatus['repl_role'] ?? 'standalone';
    $clusterRole = $settings['cluster_role'] ?? 'standalone';
    $roleBadge = match($replRole) {
        'master' => 'bg-success',
        'slave'  => 'bg-info',
        default  => 'bg-secondary',
    };
    $roleLabel = match($replRole) {
        'master' => 'Master',
        'slave'  => 'Slave',
        default  => 'Standalone',
    };
    $clusterBadge = match($clusterRole) {
        'master' => 'bg-success',
        'slave'  => 'bg-info',
        default  => 'bg-secondary',
    };
    $clusterLabel = match($clusterRole) {
        'master' => 'Master',
        'slave'  => 'Slave',
        default  => 'Standalone',
    };

    $masterIp = $settings['cluster_master_ip'] ?? '';
    $masterLastHb = $settings['cluster_master_last_heartbeat'] ?? '';
    $hbAge = $masterLastHb ? (time() - strtotime($masterLastHb)) : 99999;

    $fsEnabled = ($settings['filesync_enabled'] ?? '0') === '1';
    $sshKeyExists = file_exists($settings['filesync_ssh_key_path'] ?? '/root/.ssh/id_ed25519');
?>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- Cluster Sub-Tabs                                            -->
<!-- ═══════════════════════════════════════════════════════════ -->
<style>
.cluster-tabs .nav-link {
    color: #94a3b8;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    margin-right: 0.25rem;
    font-size: 0.9rem;
}
.cluster-tabs .nav-link:hover {
    color: #cbd5e1;
    background: rgba(255,255,255,0.05);
}
.cluster-tabs .nav-link.active {
    background: rgba(34,197,94,0.15);
    color: #22c55e;
    border: none;
}
.cluster-tabs .nav-link .tab-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-left: 6px;
    vertical-align: middle;
}
.cluster-tabs .nav-link .tab-dot.dot-green { background: #22c55e; }
.cluster-tabs .nav-link .tab-dot.dot-yellow { background: #eab308; }
.cluster-tabs .nav-link .tab-dot.dot-gray { background: #64748b; }
</style>

<?php
    // Determine tab status dots
    $estadoDot = 'dot-green';
    if ($clusterRole === 'standalone') $estadoDot = 'dot-gray';

    $nodosDot = 'dot-gray';
    if ($clusterRole === 'master' && !empty($nodes)) $nodosDot = 'dot-green';
    elseif ($clusterRole === 'master' && empty($nodes)) $nodosDot = 'dot-yellow';

    $archivosDot = 'dot-gray';
    if ($clusterRole !== 'slave' && $fsEnabled && $sshKeyExists) $archivosDot = 'dot-green';
    elseif ($clusterRole !== 'slave' && (!$fsEnabled || !$sshKeyExists)) $archivosDot = 'dot-yellow';

    $failoverDot = 'dot-gray';
    if ($clusterRole === 'master' || $clusterRole === 'slave') $failoverDot = 'dot-green';

    $configDot = 'dot-green';
    if ($clusterRole === 'standalone') $configDot = 'dot-yellow';

    $colaDot = 'dot-gray';
    if ($clusterRole !== 'slave') {
        $pendingCount = (int)($queueStats['pending'] ?? 0);
        $failedCount = (int)($queueStats['failed'] ?? 0);
        if ($failedCount > 0) $colaDot = 'dot-yellow';
        elseif ($pendingCount > 0) $colaDot = 'dot-yellow';
        else $colaDot = 'dot-green';
    }
?>

<ul class="nav nav-pills cluster-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-estado" type="button" role="tab">
            <i class="bi bi-diagram-3 me-1"></i>Estado
            <span class="tab-dot <?= $estadoDot ?>"></span>
        </button>
    </li>
    <?php if ($clusterRole !== 'slave'): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-nodos" type="button" role="tab">
            <i class="bi bi-hdd-network me-1"></i>Nodos
            <span class="tab-dot <?= $nodosDot ?>"></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-archivos" type="button" role="tab">
            <i class="bi bi-arrow-repeat me-1"></i>Archivos
            <span class="tab-dot <?= $archivosDot ?>"></span>
        </button>
    </li>
    <?php endif; ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-failover" type="button" role="tab">
            <i class="bi bi-arrow-left-right me-1"></i>Failover
            <span class="tab-dot <?= $failoverDot ?>"></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-configuracion" type="button" role="tab">
            <i class="bi bi-gear me-1"></i>Configuración
            <span class="tab-dot <?= $configDot ?>"></span>
        </button>
    </li>
    <?php if ($clusterRole !== 'slave'): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cola" type="button" role="tab">
            <i class="bi bi-collection me-1"></i>Cola
            <span class="tab-dot <?= $colaDot ?>"></span>
        </button>
    </li>
    <?php endif; ?>
</ul>

<div class="tab-content">

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB 1 — Estado                                              -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="tab-estado" role="tabpanel">

    <div class="mb-3 p-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);">
        <small style="color:#6ea8fe;">
            <i class="bi bi-info-circle me-1"></i>
            Resumen del estado actual del cluster. Desde aquí puede lanzar una sincronización completa.
        </small>
    </div>

    <!-- CARD 1 — Estado del Cluster -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-diagram-3 me-2"></i>Estado del Cluster</span>
            <button type="button" class="btn btn-outline-light btn-sm" onclick="refreshClusterStatus()">
                <i class="bi bi-arrow-clockwise me-1"></i>Actualizar
            </button>
        </div>
        <div class="card-body">
            <div class="row" id="local-status">
                <div class="col-md-6">
                    <h6 class="text-muted mb-3">Servidor Local</h6>
                    <table class="table table-sm">
                        <tr>
                            <td class="text-muted" style="width:40%">Rol Cluster</td>
                            <td>
                                <span class="badge <?= $clusterBadge ?>" id="local-cluster-role"><?= $clusterLabel ?></span>
                                <?php if ($clusterRole === 'master'): ?>
                                    <small class="text-muted ms-1">(gestiona hostings)</small>
                                <?php elseif ($clusterRole === 'slave'): ?>
                                    <small class="text-muted ms-1">(recibe hostings, solo lectura)</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Rol Replicación</td>
                            <td><span class="badge <?= $roleBadge ?>" id="local-role"><?= $roleLabel ?></span></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Uptime</td>
                            <td id="local-uptime"><?= View::e($localStatus['uptime'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">PostgreSQL 5432</td>
                            <td id="local-pg5432">
                                <?php $pg5432 = $localStatus['pg_5432_status'] ?? []; ?>
                                <?php if ($pg5432['running'] ?? false): ?>
                                    <span class="badge bg-success">Running</span>
                                    <small class="text-muted ms-1">(<?= View::e($pg5432['repl_role'] ?? 'standalone') ?>)</small>
                                <?php else: ?>
                                    <span class="badge bg-danger">Stopped</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">PostgreSQL 5433</td>
                            <td id="local-pg5433">
                                <?php $pg5433 = $localStatus['pg_5433_status'] ?? []; ?>
                                <?php if ($pg5433['running'] ?? false): ?>
                                    <span class="badge bg-success">Running</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Stopped</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">MySQL</td>
                            <td id="local-mysql">
                                <?php $mysql = $localStatus['mysql_status'] ?? []; ?>
                                <?php if ($mysql['running'] ?? false): ?>
                                    <span class="badge bg-success">Running</span>
                                    <small class="text-muted ms-1">(<?= View::e($mysql['repl_role'] ?? 'standalone') ?>)</small>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Stopped</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6 class="text-muted mb-3">Recursos del Sistema</h6>
                    <table class="table table-sm">
                        <?php $disk = $localStatus['disk_usage'] ?? []; ?>
                        <tr>
                            <td class="text-muted" style="width:40%">Disco</td>
                            <td id="local-disk">
                                <?= View::e($disk['used'] ?? '?') ?> / <?= View::e($disk['total'] ?? '?') ?>
                                <small class="text-muted">(<?= View::e($disk['percent'] ?? '?') ?>)</small>
                            </td>
                        </tr>
                        <?php $ram = $localStatus['ram_usage'] ?? []; ?>
                        <tr>
                            <td class="text-muted">RAM</td>
                            <td id="local-ram">
                                <?= number_format($ram['used'] ?? 0) ?> MB / <?= number_format($ram['total'] ?? 0) ?> MB
                                <small class="text-muted">(<?= $ram['percent'] ?? 0 ?>%)</small>
                            </td>
                        </tr>
                        <?php $cpu = $localStatus['cpu_load'] ?? []; ?>
                        <tr>
                            <td class="text-muted">CPU Load</td>
                            <td id="local-cpu">
                                <?= $cpu['1min'] ?? 0 ?> / <?= $cpu['5min'] ?? 0 ?> / <?= $cpu['15min'] ?? 0 ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Hostings</td>
                            <td id="local-hostings"><?= (int)($localStatus['hosting_count'] ?? 0) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Versión Panel</td>
                            <td><?= View::e($localStatus['panel_version'] ?? '?') ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- CARD 1B — Master que monitoriza (solo si somos slave) -->
    <?php if ($clusterRole === 'slave' && $masterIp): ?>
    <div class="card mb-3 border-info">
        <div class="card-header bg-info bg-opacity-10">
            <i class="bi bi-shield-check me-2"></i>Master que monitoriza este servidor
        </div>
        <div class="card-body">
            <div class="d-flex align-items-center gap-4">
                <div>
                    <i class="bi bi-pc-display-horizontal fs-3 text-info"></i>
                </div>
                <div>
                    <table class="table table-sm mb-0" style="width:auto">
                        <tr>
                            <td class="text-muted pe-3">IP del Master</td>
                            <td><code id="master-ip"><?= View::e($masterIp) ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted pe-3">Último Heartbeat</td>
                            <td>
                                <span id="master-last-hb"><?= View::e($masterLastHb ?: 'Nunca') ?></span>
                                <?php if ($masterLastHb): ?>
                                    <?php
                                        $hbAge = time() - strtotime($masterLastHb);
                                        if ($hbAge < 60) {
                                            $hbBadge = 'bg-success';
                                            $hbText = 'hace ' . $hbAge . 's';
                                        } elseif ($hbAge < 300) {
                                            $hbBadge = 'bg-warning text-dark';
                                            $hbText = 'hace ' . round($hbAge / 60) . ' min';
                                        } else {
                                            $hbBadge = 'bg-danger';
                                            $hbText = 'hace ' . round($hbAge / 60) . ' min';
                                        }
                                    ?>
                                    <span class="badge <?= $hbBadge ?> ms-2" id="master-hb-age"><?= $hbText ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted pe-3">Estado</td>
                            <td>
                                <?php if ($hbAge < 120): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Conectado</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Sin contacto reciente</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            <div class="mt-2 small text-muted">
                <i class="bi bi-info-circle me-1"></i>Este servidor está siendo monitorizado por el master indicado. Los heartbeats se reciben automáticamente.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Orchestrator: Sincronización Completa (solo master con nodos) -->
    <?php if ($clusterRole === 'master' && !empty($nodes)): ?>
    <?php foreach ($nodes as $node): ?>
    <div class="card mb-3 border-success">
        <div class="card-body text-center py-4">
            <h5>Sincronización Completa</h5>
            <p class="text-muted">Ejecuta todos los pasos en secuencia: crear hostings &rarr; copiar archivos &rarr; copiar certificados SSL</p>
            <button class="btn btn-success btn-lg" onclick="fullSync(<?= (int)$node['id'] ?>, '<?= View::e($node['name']) ?>')">
                <i class="bi bi-play-circle me-1"></i>Sincronización Completa a <?= View::e($node['name']) ?>
            </button>
            <div class="mt-2 small text-muted">
                Si SSH no está configurado, solo se ejecutará la parte de hostings (API) y avisará.
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB 2 — Nodos                                               -->
<!-- ═══════════════════════════════════════════════════════════ -->
<?php if ($clusterRole !== 'slave'): ?>
<div class="tab-pane fade" id="tab-nodos" role="tabpanel">

    <div class="mb-3 p-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);">
        <small style="color:#6ea8fe;">
            <i class="bi bi-info-circle me-1"></i>
            Gestión de servidores vinculados. Añada nodos slave para replicar hostings. El botón 'Sync Todo' encola la creación de cuentas (usuario Linux, PHP-FPM, Caddy) en el nodo — NO copia archivos. Para archivos, vaya a la pestaña 'Archivos'.
        </small>
    </div>

    <!-- CARD 2 — Nodos Vinculados -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-hdd-network me-2"></i>Nodos Vinculados</span>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addNodeModal">
                <i class="bi bi-plus-circle me-1"></i>Añadir Nodo
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($nodes)): ?>
                <p class="text-muted text-center mb-0">
                    <i class="bi bi-info-circle me-1"></i>No hay nodos vinculados. Use "Añadir Nodo" para conectar otro servidor.
                </p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>URL</th>
                                <th>Estado</th>
                                <th>Último Heartbeat</th>
                                <th>Lag</th>
                                <th>Rol Remoto</th>
                                <th class="text-center">Alertas</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="nodes-table-body">
                            <?php foreach ($nodes as $node): ?>
                            <tr id="node-row-<?= (int)$node['id'] ?>">
                                <td><strong><?= View::e($node['name']) ?></strong></td>
                                <td><code class="small"><?= View::e($node['api_url']) ?></code></td>
                                <td>
                                    <?php
                                        $status = $node['status'] ?? 'offline';
                                        $statusBadge = match($status) {
                                            'online'  => 'bg-success',
                                            'syncing' => 'bg-warning text-dark',
                                            default   => 'bg-danger',
                                        };
                                    ?>
                                    <span class="badge <?= $statusBadge ?>" id="node-status-<?= (int)$node['id'] ?>">
                                        <?= View::e(ucfirst($status)) ?>
                                    </span>
                                </td>
                                <td class="small" id="node-lastseen-<?= (int)$node['id'] ?>">
                                    <?= $node['last_seen_at'] ? View::e($node['last_seen_at']) : '<span class="text-muted">Nunca</span>' ?>
                                </td>
                                <td id="node-lag-<?= (int)$node['id'] ?>">
                                    <?= (int)($node['sync_lag_seconds'] ?? 0) ?>s
                                </td>
                                <td>
                                    <span class="badge bg-secondary" id="node-role-<?= (int)$node['id'] ?>">
                                        <?= View::e(ucfirst($node['role'] ?? 'unknown')) ?>
                                    </span>
                                </td>
                                <td class="text-center" id="node-alerts-<?= (int)$node['id'] ?>">
                                    <?php if (!empty($node['alerts_muted'])): ?>
                                        <span class="badge bg-secondary"><i class="bi bi-bell-slash me-1"></i>Silenciadas</span>
                                    <?php else: ?>
                                        <span class="badge bg-success"><i class="bi bi-bell-fill me-1"></i>Activas</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if (!empty($node['alerts_muted'])): ?>
                                    <button type="button" class="btn btn-outline-warning btn-sm"
                                            onclick="toggleNodeAlerts(<?= (int)$node['id'] ?>, 'unmute', '<?= View::e($node['name']) ?>')"
                                            title="Reactivar alertas para este nodo">
                                        <i class="bi bi-bell me-1"></i>Reactivar
                                    </button>
                                    <?php else: ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm"
                                            onclick="toggleNodeAlerts(<?= (int)$node['id'] ?>, 'mute', '<?= View::e($node['name']) ?>')"
                                            title="Silenciar alertas (mantenimiento programado)">
                                        <i class="bi bi-bell-slash me-1"></i>Silenciar
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($clusterRole === 'master'): ?>
                                    <button type="button" class="btn btn-outline-success btn-sm"
                                            onclick="confirmSyncAll(<?= (int)$node['id'] ?>, '<?= View::e($node['name']) ?>')"
                                            title="Sincronizar todos los hostings existentes a este nodo">
                                        <i class="bi bi-arrow-repeat me-1"></i>Sync Todo
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-info btn-sm"
                                            onclick="viewNodeStatus(<?= (int)$node['id'] ?>, '<?= View::e($node['name']) ?>')">
                                        <i class="bi bi-eye me-1"></i>Ver Estado
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm"
                                            onclick="confirmRemoveNode(<?= (int)$node['id'] ?>, '<?= View::e($node['name']) ?>')">
                                        <i class="bi bi-trash me-1"></i>Eliminar
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($nodes)): ?>
            <hr class="border-secondary mt-3 mb-2">
            <div class="small">
                <div class="mb-1">
                    <i class="bi bi-info-circle text-info me-1"></i>
                    <strong>Qué se sincroniza:</strong>
                </div>
                <div class="d-flex flex-wrap gap-3 ms-3">
                    <div>
                        <span class="badge bg-success"><i class="bi bi-check me-1"></i>Hostings</span>
                        <span class="text-muted ms-1">Cuentas, dirs, Caddy, PHP-FPM (via API, automático)</span>
                    </div>
                    <div>
                        <?php if ($fsEnabled && $sshKeyExists): ?>
                            <span class="badge bg-success"><i class="bi bi-check me-1"></i>Archivos</span>
                            <span class="text-muted ms-1">Contenido web via SSH cada <?= (int)($settings['filesync_interval'] ?? 15) ?> min</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Archivos</span>
                            <span class="text-muted ms-1">
                                <?php if (!$sshKeyExists): ?>
                                    Falta generar clave SSH
                                <?php elseif (!$fsEnabled): ?>
                                    Sincronización de archivos desactivada
                                <?php endif; ?>
                                — <a href="#archivos" class="text-info">configurar en pestaña Archivos</a>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal: Añadir Nodo -->
    <div class="modal fade" id="addNodeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Añadir Nodo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" action="/settings/cluster/add-node" id="form-add-node">
                    <?= View::csrf() ?>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nombre del Nodo</label>
                            <input type="text" name="node_name" class="form-control" placeholder="Servidor 2" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">URL de la API</label>
                            <input type="url" name="api_url" class="form-control" id="add-node-url"
                                   placeholder="https://192.168.1.100:8444" required>
                            <small class="text-muted">Formato: https://IP:PUERTO (normalmente puerto 8444)</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Token de Autenticación</label>
                            <div class="input-group">
                                <input type="text" name="auth_token" class="form-control font-monospace" id="add-node-token"
                                       placeholder="Token del nodo remoto" required>
                                <button type="button" class="btn btn-outline-warning" onclick="generateTokenForAdd()">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Generar
                                </button>
                            </div>
                        </div>

                        <!-- Test Connection -->
                        <div class="mb-3">
                            <button type="button" class="btn btn-outline-info" onclick="testNodeConnection()" id="btn-test-node">
                                <i class="bi bi-plug me-1"></i>Probar Conexión
                            </button>
                            <span id="test-node-result" class="ms-2"></span>
                        </div>

                        <!-- Remote info (shown after test) -->
                        <div id="remote-info" class="alert" style="display:none; background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #22c55e;"></div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Añadir Nodo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Ver Estado del Nodo -->
    <div class="modal fade" id="nodeStatusModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Estado del Nodo: <span id="node-status-name"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="node-status-content">
                        <div class="text-center text-muted py-4">
                            <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden form for remove-node -->
    <form method="post" id="form-remove-node" style="display:none;">
        <?= View::csrf() ?>
        <input type="hidden" name="node_id" id="remove-node-id">
    </form>

    <!-- Hidden form for sync-all-hostings -->
    <form method="post" action="/settings/cluster/sync-all-hostings" id="form-sync-all" style="display:none;">
        <?= View::csrf() ?>
        <input type="hidden" name="node_id" id="sync-all-node-id">
    </form>

</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB 3 — Archivos                                            -->
<!-- ═══════════════════════════════════════════════════════════ -->
<?php if ($clusterRole !== 'slave'): ?>
<?php $fsConfig = \MuseDockPanel\Services\FileSyncService::getConfig(); ?>
<div class="tab-pane fade" id="tab-archivos" role="tabpanel">

    <div class="mb-3 p-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);">
        <small style="color:#6ea8fe;">
            <i class="bi bi-info-circle me-1"></i>
            Sincronización de contenido web entre servidores. Usa rsync (SSH) o HTTPS. Los archivos se copian cada X minutos automáticamente. Esto es independiente de la pestaña 'Nodos' — 'Nodos' crea la estructura del hosting, 'Archivos' copia el contenido.
        </small>
    </div>

    <!-- Dependency indicators -->
    <div class="mb-2 small">
        <?php if (!empty($nodes)): ?>
            <span class="badge bg-success"><i class="bi bi-check me-1"></i>Nodos</span> configurados
        <?php else: ?>
            <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Nodos</span> no configurados — <a href="#nodos">configurar</a>
        <?php endif; ?>
        &nbsp;
        <?php if ($sshKeyExists): ?>
            <span class="badge bg-success"><i class="bi bi-check me-1"></i>SSH</span> clave generada
        <?php else: ?>
            <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>SSH</span> no configurado — genere la clave abajo
        <?php endif; ?>
    </div>

    <!-- CARD 6 — Sincronización de Archivos -->
    <div class="card mb-3" id="card-filesync">
        <div class="card-header"><i class="bi bi-arrow-repeat me-2"></i>Sincronización de Archivos</div>
        <div class="card-body">
            <form method="post" action="/settings/cluster/filesync-settings">
                <?= View::csrf() ?>

                <!-- Enable toggle -->
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="filesync_enabled" id="filesyncEnabled"
                           <?= $fsConfig['enabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="filesyncEnabled">Activar sincronización automática de archivos</label>
                </div>

                <!-- Method selector -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Método de sincronización</label>
                        <select name="filesync_method" class="form-select" id="filesyncMethod">
                            <option value="ssh" <?= $fsConfig['method'] === 'ssh' ? 'selected' : '' ?>>SSH (rsync)</option>
                            <option value="https" <?= $fsConfig['method'] === 'https' ? 'selected' : '' ?>>HTTPS (API del panel)</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <div class="small mt-4">
                            <span class="text-muted" id="filesyncMethodDesc">
                                <?php if ($fsConfig['method'] === 'ssh'): ?>
                                    rsync por SSH. Ideal para WireGuard (~5 MB/s). Requiere clave SSH.
                                <?php else: ?>
                                    Archivos via API HTTPS. Más rápido entre VPS (~24 MB/s). No requiere SSH.
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <hr class="border-secondary">

                <!-- SSH Settings (shown when method=ssh) -->
                <div id="sshSettings" style="<?= $fsConfig['method'] !== 'ssh' ? 'display:none' : '' ?>">
                    <h6 class="text-muted mb-2">Configuración SSH</h6>
                    <div class="alert alert-info small py-2 mb-3" style="background:#1a3a4a;border-color:#2a5a6a;color:#a8d8ea;">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Cómo funciona:</strong> Este servidor (master) se conecta por SSH al slave para copiar archivos con rsync.
                        La clave privada se queda aquí, la clave pública se instala en los slaves.
                        <br>
                        <strong>1.</strong> Pulsa "Generar" para crear el par de claves en este servidor &rarr;
                        <strong>2.</strong> Pulsa "Instalar clave" en cada nodo slave &rarr;
                        <strong>3.</strong> Pulsa "Test SSH" para verificar la conexión.
                        <br>
                        <span class="text-warning"><i class="bi bi-shield-check me-1"></i>Debe ser <code>root</code> para poder leer/escribir archivos de todos los hostings y mantener permisos.</span>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Puerto SSH</label>
                            <input type="number" name="filesync_ssh_port" class="form-control"
                                   value="<?= $fsConfig['ssh_port'] ?>" min="1" max="65535">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Usuario SSH</label>
                            <input type="text" name="filesync_ssh_user" class="form-control"
                                   value="<?= View::e($fsConfig['ssh_user']) ?>">
                            <small class="text-muted">Debe ser root</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ruta clave SSH privada (de este servidor)</label>
                            <div class="input-group">
                                <input type="text" name="filesync_ssh_key_path" class="form-control font-monospace"
                                       id="sshKeyPath" value="<?= View::e($fsConfig['ssh_key_path']) ?>">
                                <button type="button" class="btn btn-outline-warning" onclick="generateSshKey()">
                                    <i class="bi bi-key me-1"></i>Generar
                                </button>
                            </div>
                            <small class="text-muted">Se genera aquí. La pública se envía a los slaves.</small>
                        </div>
                    </div>

                    <!-- Public key display -->
                    <div class="mb-3" id="sshPubKeyArea" style="display:none">
                        <label class="form-label small text-muted">Clave pública (para copiar al slave):</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace small" id="sshPubKeyDisplay" readonly>
                            <button type="button" class="btn btn-outline-light" onclick="navigator.clipboard.writeText(document.getElementById('sshPubKeyDisplay').value)">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Per-node SSH actions -->
                    <?php if (!empty($nodes)): ?>
                    <div class="mb-3">
                        <label class="form-label small text-muted">Acciones SSH por nodo:</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($nodes as $node): ?>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-info" onclick="installSshKeyOnNode(<?= (int)$node['id'] ?>, '<?= View::e($node['name']) ?>')">
                                    <i class="bi bi-key me-1"></i><?= View::e($node['name']) ?>: Instalar clave
                                </button>
                                <button type="button" class="btn btn-outline-success" onclick="testSshNode(<?= (int)$node['id'] ?>, '<?= View::e($node['name']) ?>')">
                                    <i class="bi bi-plug me-1"></i>Test SSH
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="sshActionResult" class="mt-2 small"></div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning small py-2 mb-3" style="background:#3a3000;border-color:#5a5000;color:#e8d44d;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        No hay nodos configurados. Primero añade un nodo en la pestaña "Nodos" para poder instalar la clave SSH y hacer test de conexión.
                    </div>
                    <?php endif; ?>

                    <hr class="border-secondary">
                </div>

                <!-- Common settings -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Intervalo (minutos)</label>
                        <input type="number" name="filesync_interval" class="form-control"
                               value="<?= $fsConfig['interval_minutes'] ?>" min="1" max="1440">
                        <small class="text-muted">Cada cuántos minutos sincronizar</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Límite ancho de banda (KB/s)</label>
                        <input type="number" name="filesync_bwlimit" class="form-control"
                               value="<?= $fsConfig['bandwidth_limit'] ?>" min="0">
                        <small class="text-muted">0 = sin límite</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Patrones a excluir</label>
                        <input type="text" name="filesync_exclude" class="form-control"
                               value="<?= View::e($fsConfig['exclude_patterns']) ?>">
                        <small class="text-muted">Separados por coma</small>
                    </div>
                </div>

                <hr class="border-secondary">

                <!-- SSL Certificates -->
                <h6 class="text-muted mb-2">Certificados SSL</h6>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="filesync_ssl_certs" id="filesyncSslCerts"
                           <?= $fsConfig['sync_ssl_certs'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="filesyncSslCerts">Copiar certificados SSL del master al slave</label>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted">Ruta certificados Caddy (auto-detectada si vacío)</label>
                        <?php $detectedCertDir = $fsConfig['ssl_cert_path'] ?: \MuseDockPanel\Services\FileSyncService::findCaddyCertDir(); ?>
                        <input type="text" name="filesync_ssl_cert_path" class="form-control font-monospace"
                               value="<?= View::e($detectedCertDir) ?>"
                               placeholder="No detectado">
                    </div>
                </div>
                <p class="small text-muted mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Opciones SSL para el slave: <strong>Copiar del master</strong> (recomendado),
                    <strong>Cloudflare DNS challenge</strong> (requiere token),
                    o <strong>certificados autofirmados</strong> de Caddy (temporal, warning en navegadores).
                </p>

                <hr class="border-secondary">

                <!-- DB_HOST rewrite -->
                <h6 class="text-muted mb-2">Protección de credenciales</h6>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="filesync_rewrite_dbhost" id="filesyncRewriteDb"
                           <?= $fsConfig['rewrite_db_host'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="filesyncRewriteDb">
                        Reescribir DB_HOST a <code>localhost</code> en .env y wp-config.php del slave
                    </label>
                </div>
                <p class="small text-muted mb-3">
                    <i class="bi bi-shield-check me-1"></i>
                    Evita que las apps del slave intenten conectar a la BD del master. Cambia automáticamente DB_HOST a localhost
                    en archivos .env (Laravel) y wp-config.php (WordPress) al recibir archivos.
                </p>

                <!-- Check DB_HOST button -->
                <button type="button" class="btn btn-outline-warning btn-sm mb-3" onclick="checkDbHost()">
                    <i class="bi bi-search me-1"></i>Verificar DB_HOST en todos los hostings
                </button>
                <div id="dbhostCheckResult" class="mb-3"></div>

                <hr class="border-secondary">

                <!-- Database Dump Sync (Nivel 1) -->
                <h6 class="text-muted mb-2"><i class="bi bi-database me-1"></i>Bases de datos (dump)</h6>
                <?php
                    $streamingActive = \MuseDockPanel\Services\ReplicationService::isStreamingActive();
                ?>
                <?php if ($streamingActive['any_active']): ?>
                <div class="p-2 mb-3 rounded" style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.15);">
                    <small style="color:#22c55e;">
                        <i class="bi bi-check-circle me-1"></i>
                        <strong>Replicación streaming activa.</strong> Las bases de datos se replican en tiempo real.
                        El dump periódico se omite automáticamente (es innecesario).
                    </small>
                </div>
                <?php else: ?>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="filesync_db_dumps" id="filesyncDbDumps"
                           <?= ($settings['filesync_db_dumps'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="filesyncDbDumps">
                        Incluir dump de bases de datos en la sincronización
                    </label>
                </div>
                <div class="row mb-2 ms-4">
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="filesync_db_dump_mysql" id="filesyncDbMySQL"
                                   <?= ($settings['filesync_db_dump_mysql'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="filesyncDbMySQL">
                                <i class="bi bi-database-fill me-1"></i>MySQL / MariaDB
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="filesync_db_dump_pgsql" id="filesyncDbPgSQL"
                                   <?= ($settings['filesync_db_dump_pgsql'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="filesyncDbPgSQL">
                                <i class="bi bi-database me-1"></i>PostgreSQL (hosting, puerto 5432)
                            </label>
                        </div>
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-6">
                        <label class="form-label small text-muted">Ruta temporal de dumps</label>
                        <input type="text" name="filesync_db_dump_path" class="form-control font-monospace"
                               value="<?= View::e($settings['filesync_db_dump_path'] ?? '/tmp/musedock-dumps') ?>"
                               placeholder="/tmp/musedock-dumps">
                    </div>
                </div>
                <p class="small text-muted mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Las bases de datos se exportan con <code>pg_dump</code> / <code>mysqldump</code> (comprimidas con gzip)
                    y se restauran en el slave cada intervalo. La BD del panel (<code>musedock_panel</code>) nunca se sincroniza.
                    Si activa la <a href="/settings/replication" class="text-info">replicación streaming</a> (nivel avanzado),
                    este paso se omite automáticamente.
                </p>
                <?php endif; ?>

                <hr class="border-secondary">

                <!-- Manual sync buttons -->
                <?php if (!empty($nodes) && $clusterRole === 'master'): ?>
                <h6 class="text-muted mb-2">Sincronización manual de archivos</h6>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php foreach ($nodes as $node): ?>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="syncFilesNow(<?= (int)$node['id'] ?>, '<?= View::e($node['name']) ?>')">
                        <i class="bi bi-arrow-repeat me-1"></i>Sync archivos a <?= View::e($node['name']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div id="syncFilesResult" class="mb-3"></div>
                <?php endif; ?>

                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle me-1"></i>Guardar Configuración de Archivos
                </button>
            </form>
        </div>
    </div>

</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB 4 — Failover                                            -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-failover" role="tabpanel">

    <div class="mb-3 p-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);">
        <small style="color:#6ea8fe;">
            <i class="bi bi-info-circle me-1"></i>
            Operaciones para cambiar el rol del servidor. Si el master cae, puede promover un slave a master desde aquí.
        </small>
    </div>

    <!-- CARD 4 — Operaciones de Failover -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Operaciones de Failover</div>
        <div class="card-body">
            <p class="text-muted mb-3">
                Las operaciones de failover permiten cambiar el rol de este servidor dentro del cluster.
                Use estas opciones con precaución.
            </p>

            <?php
                // Determine effective failover role: cluster_role OR repl_role
                $failoverRole = 'standalone';
                if ($clusterRole === 'slave' || $replRole === 'slave') $failoverRole = 'slave';
                elseif ($clusterRole === 'master' || $replRole === 'master') $failoverRole = 'master';
            ?>

            <?php if ($failoverRole === 'slave'): ?>
                <?php
                    $masterIpSaved = $settings['cluster_master_ip'] ?? '';
                    $masterLastHb = $settings['cluster_master_last_heartbeat'] ?? '';
                    $masterAge = $masterLastHb ? (time() - strtotime($masterLastHb)) : 99999;
                    $masterDown = $masterAge > (int)($settings['cluster_unreachable_timeout'] ?? 300);
                ?>

                <?php if ($masterDown && $masterIpSaved): ?>
                    <div class="alert" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #f87171;">
                        <i class="bi bi-exclamation-octagon me-2"></i>
                        <strong>Master caído.</strong> El master (<?= View::e($masterIpSaved) ?>) no responde desde hace <?= round($masterAge / 60) ?> minutos.
                        Puede promover este servidor a Master para que atienda el tráfico.
                    </div>
                <?php else: ?>
                    <div class="alert" style="background: rgba(251,191,36,0.1); border: 1px solid rgba(251,191,36,0.3); color: #fbbf24;">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Este servidor es actualmente un <strong>Slave</strong>. Puede promoverlo a Master si el master actual ha fallado.
                    </div>
                <?php endif; ?>

                <form method="post" action="/settings/cluster/promote" id="form-promote-cluster">
                    <?= View::csrf() ?>
                    <button type="button" class="btn btn-warning btn-lg" onclick="confirmPromoteCluster()">
                        <i class="bi bi-arrow-up-circle me-1"></i>Promover a Master
                    </button>
                </form>

            <?php elseif ($failoverRole === 'master'): ?>
                <div class="alert" style="background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); color: #60a5fa;">
                    <i class="bi bi-info-circle me-2"></i>
                    Este servidor es actualmente el <strong>Master</strong>. Puede degradarlo a Slave si necesita transferir el rol a otro servidor.
                </div>
                <form method="post" action="/settings/cluster/demote" id="form-demote-cluster">
                    <?= View::csrf() ?>
                    <div class="mb-3" style="max-width: 400px;">
                        <label class="form-label">Seleccione el nuevo Master</label>
                        <?php if (!empty($nodes)): ?>
                        <select name="new_master_ip" class="form-select" id="demote-node-select" required>
                            <option value="">-- Seleccionar nodo --</option>
                            <?php foreach ($nodes as $n):
                                $nodeHost = parse_url($n['api_url'], PHP_URL_HOST);
                            ?>
                            <option value="<?= View::e($nodeHost) ?>"
                                data-name="<?= View::e($n['name']) ?>"
                                data-url="<?= View::e($n['api_url']) ?>">
                                <?= View::e($n['name']) ?> (<?= View::e($nodeHost) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <div class="text-warning small">
                            <i class="bi bi-exclamation-triangle me-1"></i>No hay nodos configurados. Añada un nodo primero en la pestaña "Nodos".
                        </div>
                        <input type="text" name="new_master_ip" class="form-control mt-2" placeholder="IP manual (ej: 192.168.1.100)" required>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-danger" onclick="confirmDemoteCluster()" <?= empty($nodes) ? '' : '' ?>>
                        <i class="bi bi-arrow-down-circle me-1"></i>Degradar a Slave
                    </button>
                </form>

            <?php else: ?>
                <div class="alert" style="background: rgba(107,114,128,0.1); border: 1px solid rgba(107,114,128,0.3); color: #9ca3af;">
                    <i class="bi bi-info-circle me-2"></i>
                    Este servidor es <strong>Standalone</strong>. Configure el cluster primero seleccionando un rol (Master o Slave) en la pestaña Configuración.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Slave-specific: alertas y auto-failover -->
    <?php if ($clusterRole === 'slave'): ?>
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-bell me-2"></i>Alertas y Auto-Failover</div>
        <div class="card-body">
            <!-- Slave: alertas de master caido -->
            <h6 class="text-muted mb-2">Alerta de Master caído</h6>
            <p class="small text-muted mb-2">
                Si este servidor deja de recibir heartbeat del master durante el timeout configurado, se enviará una alerta.
                Seleccione los canales por los que desea recibir la notificación.
            </p>
            <form method="post" action="/settings/cluster/save-settings" id="form-slave-alerts">
                <?= View::csrf() ?>
                <input type="hidden" name="cluster_role" value="<?= View::e($clusterRole) ?>">
                <input type="hidden" name="cluster_local_token" value="<?= View::e($localToken) ?>">
                <input type="hidden" name="cluster_heartbeat_interval" value="<?= (int)($settings['cluster_heartbeat_interval'] ?? 30) ?>">
                <input type="hidden" name="cluster_unreachable_timeout" value="<?= (int)($settings['cluster_unreachable_timeout'] ?? 300) ?>">

                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="cluster_slave_notify_email" id="slaveNotifyEmail"
                           <?= (($settings['cluster_slave_notify_email'] ?? '1') === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="slaveNotifyEmail">
                        <i class="bi bi-envelope me-1"></i>Notificar por Email
                    </label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="cluster_slave_notify_telegram" id="slaveNotifyTelegram"
                           <?= (($settings['cluster_slave_notify_telegram'] ?? '1') === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="slaveNotifyTelegram">
                        <i class="bi bi-telegram me-1"></i>Notificar por Telegram
                    </label>
                </div>

                <hr class="border-secondary">

                <!-- Slave: auto-failover -->
                <h6 class="text-muted mb-2">Auto-Failover</h6>
                <?php $autoFailoverOn = (($settings['cluster_auto_failover'] ?? '0') === '1'); ?>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="autoFailoverToggle"
                               <?= $autoFailoverOn ? 'checked' : '' ?>
                               onchange="toggleAutoFailover(this)">
                        <label class="form-check-label" for="autoFailoverToggle">
                            Promover automáticamente a Master si el master cae
                        </label>
                    </div>
                    <?php if ($autoFailoverOn): ?>
                        <span class="badge bg-danger"><i class="bi bi-lightning me-1"></i>ACTIVO</span>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="cluster_auto_failover" id="autoFailoverValue" value="<?= $autoFailoverOn ? '1' : '0' ?>">
                <div class="small text-muted mb-3" style="max-width:600px;">
                    <i class="bi bi-exclamation-triangle text-warning me-1"></i>
                    <strong>Precaución:</strong> Si se activa, este servidor se promoverá automáticamente a Master cuando detecte que el master no responde.
                    Esto abrirá los puertos 80/443 y cambiará el rol del cluster. Desactivado por defecto para evitar problemas de split-brain.
                </div>

                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle me-1"></i>Guardar Alertas
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB 5 — Configuración                                       -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-configuracion" role="tabpanel">

    <div class="mb-3 p-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);">
        <small style="color:#6ea8fe;">
            <i class="bi bi-info-circle me-1"></i>
            Configuración base del cluster: rol del servidor, token de autenticación y intervalos de monitorización.
        </small>
    </div>

    <!-- CARD 5 — Configuración del Cluster -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-gear me-2"></i>Configuración del Cluster</div>
        <div class="card-body">
            <form method="post" action="/settings/cluster/save-settings">
                <?= View::csrf() ?>

                <!-- Cluster Role -->
                <h6 class="text-muted mb-2">Rol del Cluster</h6>
                <p class="small text-muted">Define el rol de este servidor en la sincronización de hostings.</p>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <select name="cluster_role" class="form-select">
                            <option value="standalone" <?= ($clusterRole === 'standalone') ? 'selected' : '' ?>>Standalone (sin cluster)</option>
                            <option value="master" <?= ($clusterRole === 'master') ? 'selected' : '' ?>>Master (crea y envia hostings)</option>
                            <option value="slave" <?= ($clusterRole === 'slave') ? 'selected' : '' ?>>Slave (recibe hostings, solo lectura)</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <div class="small mt-2">
                            <?php if ($clusterRole === 'master'): ?>
                                <span class="text-success"><i class="bi bi-check-circle me-1"></i>Los hostings creados aquí se replicarán a los nodos slave vinculados.</span>
                            <?php elseif ($clusterRole === 'slave'): ?>
                                <span class="text-info"><i class="bi bi-info-circle me-1"></i>La creación de hostings está bloqueada. Solo se reciben hostings del master.</span>
                            <?php else: ?>
                                <span class="text-muted"><i class="bi bi-dash-circle me-1"></i>No se sincronizan hostings. Cada servidor trabaja de forma independiente.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <hr class="border-secondary">

                <!-- Token Local -->
                <h6 class="text-muted mb-2">Token Local</h6>
                <p class="small text-muted">Este token se usa para que otros nodos se conecten a este servidor.</p>
                <div class="input-group mb-3" style="max-width: 600px;">
                    <input type="text" class="form-control font-monospace" id="local-token-input"
                           name="cluster_local_token" value="<?= View::e($localToken) ?>"
                           placeholder="Token de autenticación local" readonly>
                    <button type="button" class="btn btn-outline-light" onclick="copyLocalToken()">
                        <i class="bi bi-clipboard"></i>
                    </button>
                    <button type="button" class="btn btn-outline-warning" onclick="regenerateLocalToken()">
                        <i class="bi bi-arrow-clockwise"></i> Regenerar
                    </button>
                </div>

                <hr class="border-secondary">

                <!-- Intervalos -->
                <h6 class="text-muted mb-2">Intervalos</h6>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Intervalo de Heartbeat (seg)</label>
                        <input type="number" name="cluster_heartbeat_interval" class="form-control"
                               value="<?= (int)($settings['cluster_heartbeat_interval'] ?? 30) ?>" min="10" max="300">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Timeout inaccesible (seg)</label>
                        <input type="number" name="cluster_unreachable_timeout" class="form-control"
                               value="<?= (int)($settings['cluster_unreachable_timeout'] ?? 300) ?>" min="60" max="3600">
                    </div>
                </div>

                <hr class="border-secondary">

                <!-- Notificaciones — enlace a la nueva página -->
                <h6 class="text-muted mb-2">Notificaciones</h6>
                <p class="small text-muted mb-2">
                    Las alertas del cluster (nodos caídos, failover, etc.) se envían a través de los canales configurados en Notificaciones.
                </p>
                <a href="/settings/notifications" class="btn btn-outline-info btn-sm mb-3">
                    <i class="bi bi-bell me-1"></i>Configurar Notificaciones (Email / Telegram)
                </a>

                <br>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle me-1"></i>Guardar Configuración
                </button>
            </form>
        </div>
    </div>

</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB 6 — Cola                                                -->
<!-- ═══════════════════════════════════════════════════════════ -->
<?php if ($clusterRole !== 'slave'): ?>
<div class="tab-pane fade" id="tab-cola" role="tabpanel">

    <div class="mb-3 p-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);">
        <small style="color:#6ea8fe;">
            <i class="bi bi-info-circle me-1"></i>
            Eventos pendientes y completados. La cola procesa automáticamente cada minuto. Los elementos llegan aquí cuando se usa 'Sync Todo' en la pestaña Nodos.
        </small>
    </div>

    <!-- CARD 3 — Cola de Sincronización -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-collection me-2"></i>Cola de Sincronización</span>
            <div class="d-flex gap-2">
                <form method="post" action="/settings/cluster/process-queue" class="d-inline">
                    <?= View::csrf() ?>
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-play-circle me-1"></i>Procesar Cola
                    </button>
                </form>
                <form method="post" action="/settings/cluster/clean-queue" class="d-inline">
                    <?= View::csrf() ?>
                    <button type="button" class="btn btn-outline-warning btn-sm" onclick="confirmCleanQueue(this.closest('form'))">
                        <i class="bi bi-trash me-1"></i>Limpiar Completados
                    </button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <div class="small text-muted mb-2">
                <i class="bi bi-clock me-1"></i>La cola se procesa automáticamente cada minuto. El botón "Procesar Cola" es para forzar manualmente.
            </div>
            <!-- Stats -->
            <div class="d-flex gap-3 mb-3 flex-wrap" id="queue-stats">
                <span class="badge bg-secondary fs-6">
                    <i class="bi bi-hourglass me-1"></i>Pendientes: <?= (int)($queueStats['pending'] ?? 0) ?>
                </span>
                <span class="badge bg-info fs-6">
                    <i class="bi bi-gear-wide-connected me-1"></i>Procesando: <?= (int)($queueStats['processing'] ?? 0) ?>
                </span>
                <span class="badge bg-success fs-6">
                    <i class="bi bi-check-circle me-1"></i>Completados: <?= (int)($queueStats['completed'] ?? 0) ?>
                </span>
                <span class="badge bg-danger fs-6">
                    <i class="bi bi-x-circle me-1"></i>Fallidos: <?= (int)($queueStats['failed'] ?? 0) ?>
                </span>
            </div>

            <?php if (empty($recentQueue)): ?>
                <p class="text-muted text-center mb-0"><i class="bi bi-info-circle me-1"></i>La cola está vacía.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Acción</th>
                                <th>Nodo Destino</th>
                                <th>Estado</th>
                                <th>Intentos</th>
                                <th>Creado</th>
                                <th>Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentQueue as $item): ?>
                            <tr>
                                <td><code><?= View::e($item['action']) ?></code></td>
                                <td><?= View::e($item['node_name'] ?? 'N/A') ?></td>
                                <td>
                                    <?php
                                        $qStatus = $item['status'] ?? 'pending';
                                        $qBadge = match($qStatus) {
                                            'completed'  => 'bg-success',
                                            'processing' => 'bg-info',
                                            'failed'     => 'bg-danger',
                                            default      => 'bg-secondary',
                                        };
                                    ?>
                                    <span class="badge <?= $qBadge ?>"><?= View::e(ucfirst($qStatus)) ?></span>
                                </td>
                                <td><?= (int)$item['attempts'] ?>/<?= (int)$item['max_attempts'] ?></td>
                                <td class="small"><?= View::e($item['created_at'] ?? '') ?></td>
                                <td class="small text-danger"><?= View::e($item['error_message'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
<?php endif; ?>

</div><!-- end tab-content -->

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- JavaScript                                                 -->
<!-- ═══════════════════════════════════════════════════════════ -->
<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.spin-icon { display: inline-block; animation: spin 1s linear infinite; }
</style>
<script>
// Tab switching with URL hash
document.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash.replace('#', '') || 'estado';
    const tab = document.querySelector('[data-bs-target="#tab-' + hash + '"]');
    if (tab) new bootstrap.Tab(tab).show();

    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function(el) {
        el.addEventListener('shown.bs.tab', function(e) {
            history.replaceState(null, '', '#' + e.target.dataset.bsTarget.replace('#tab-', ''));
        });
    });
});

// Auto-refresh every 10 seconds
let statusInterval = null;

document.addEventListener('DOMContentLoaded', function() {
    statusInterval = setInterval(refreshClusterStatus, 10000);
});

function refreshClusterStatus() {
    fetch('/settings/cluster/node-status')
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;

            // Update nodes
            (data.nodes || []).forEach(function(node) {
                const statusEl = document.getElementById('node-status-' + node.id);
                const lastSeenEl = document.getElementById('node-lastseen-' + node.id);
                const roleEl = document.getElementById('node-role-' + node.id);

                if (statusEl) {
                    statusEl.className = 'badge ' + (node.status === 'online' ? 'bg-success' : 'bg-danger');
                    statusEl.textContent = node.status === 'online' ? 'Online' : 'Offline';
                }
                if (lastSeenEl && node.last_seen_at) {
                    lastSeenEl.textContent = node.last_seen_at;
                }
                if (roleEl) {
                    roleEl.textContent = (node.role || 'unknown').charAt(0).toUpperCase() + (node.role || 'unknown').slice(1);
                }
            });

            // Update local status
            const local = data.local || {};
            if (local.cpu_load) {
                const cpuEl = document.getElementById('local-cpu');
                if (cpuEl) cpuEl.textContent = local.cpu_load['1min'] + ' / ' + local.cpu_load['5min'] + ' / ' + local.cpu_load['15min'];
            }
            if (local.ram_usage) {
                const ramEl = document.getElementById('local-ram');
                if (ramEl) ramEl.innerHTML = local.ram_usage.used + ' MB / ' + local.ram_usage.total + ' MB <small class="text-muted">(' + local.ram_usage.percent + '%)</small>';
            }

            // Update master monitoring info (for slaves)
            const mi = data.master_info;
            if (mi) {
                const hbEl = document.getElementById('master-last-hb');
                const ageEl = document.getElementById('master-hb-age');
                if (hbEl && mi.last_heartbeat) hbEl.textContent = mi.last_heartbeat;
                if (ageEl && mi.age_seconds !== null) {
                    let ageTxt, ageCls;
                    if (mi.age_seconds < 60) { ageTxt = 'hace ' + mi.age_seconds + 's'; ageCls = 'badge bg-success ms-2'; }
                    else if (mi.age_seconds < 300) { ageTxt = 'hace ' + Math.round(mi.age_seconds/60) + ' min'; ageCls = 'badge bg-warning text-dark ms-2'; }
                    else { ageTxt = 'hace ' + Math.round(mi.age_seconds/60) + ' min'; ageCls = 'badge bg-danger ms-2'; }
                    ageEl.className = ageCls;
                    ageEl.textContent = ageTxt;
                }
            }

            // Update queue stats
            const qs = data.queue_stats || {};
            const qsEl = document.getElementById('queue-stats');
            if (qsEl) {
                qsEl.innerHTML =
                    '<span class="badge bg-secondary fs-6"><i class="bi bi-hourglass me-1"></i>Pendientes: ' + (qs.pending || 0) + '</span>' +
                    '<span class="badge bg-info fs-6"><i class="bi bi-gear-wide-connected me-1"></i>Procesando: ' + (qs.processing || 0) + '</span>' +
                    '<span class="badge bg-success fs-6"><i class="bi bi-check-circle me-1"></i>Completados: ' + (qs.completed || 0) + '</span>' +
                    '<span class="badge bg-danger fs-6"><i class="bi bi-x-circle me-1"></i>Fallidos: ' + (qs.failed || 0) + '</span>';
            }
        })
        .catch(function() {});
}

function testNodeConnection() {
    const url = document.getElementById('add-node-url').value;
    const token = document.getElementById('add-node-token').value;
    const resultEl = document.getElementById('test-node-result');
    const btn = document.getElementById('btn-test-node');
    const remoteInfo = document.getElementById('remote-info');

    if (!url || !token) {
        resultEl.innerHTML = '<span class="text-danger">URL y token son obligatorios</span>';
        return;
    }

    btn.disabled = true;
    resultEl.innerHTML = '<span class="text-muted"><div class="spinner-border spinner-border-sm me-1"></div>Probando...</span>';
    remoteInfo.style.display = 'none';

    const formData = new FormData();
    formData.append('api_url', url);
    formData.append('auth_token', token);
    formData.append('_csrf_token', document.querySelector('[name=_csrf_token]').value);

    fetch('/settings/cluster/test-node', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.ok) {
                resultEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + data.message + '</span>';
                // Show remote info
                const remote = data.remote || {};
                let info = '<strong>Información del nodo remoto:</strong><br>';
                info += 'Rol: ' + (remote.repl_role || remote.role || 'unknown') + '<br>';
                info += 'Hostings: ' + (remote.hosting_count || 0) + '<br>';
                info += 'Version: ' + (remote.panel_version || '?') + '<br>';
                if (remote.uptime) info += 'Uptime desde: ' + remote.uptime + '<br>';
                remoteInfo.innerHTML = info;
                remoteInfo.style.display = 'block';
            } else {
                resultEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + data.message + '</span>';
            }
        })
        .catch(function(e) {
            btn.disabled = false;
            resultEl.innerHTML = '<span class="text-danger">Error de conexión</span>';
        });
}

function generateTokenForAdd() {
    fetch('/settings/cluster/generate-token', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: '_csrf_token=' + encodeURIComponent(document.querySelector('[name=_csrf_token]').value)
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            document.getElementById('add-node-token').value = data.token;
        }
    })
    .catch(function() {});
}

function viewNodeStatus(nodeId, nodeName) {
    document.getElementById('node-status-name').textContent = nodeName;
    document.getElementById('node-status-content').innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>Cargando...</div>';
    new bootstrap.Modal(document.getElementById('nodeStatusModal')).show();

    // Step 1: Load instantly from DB (no network call to remote node)
    fetch('/settings/cluster/node-status-quick?node_id=' + nodeId)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                document.getElementById('node-status-content').innerHTML = '<div class="text-danger">' + (data.error || 'Error') + '</div>';
                return;
            }

            const statusBadge = data.status === 'online' ? 'bg-success' : 'bg-danger';
            const ageText = data.age_seconds !== null
                ? (data.age_seconds < 60 ? data.age_seconds + 's' : Math.round(data.age_seconds / 60) + ' min')
                : 'N/A';
            const mutedBadge = data.alerts_muted
                ? '<span class="badge bg-secondary ms-1"><i class="bi bi-bell-slash me-1"></i>Silenciadas</span>'
                : '<span class="badge bg-success ms-1"><i class="bi bi-bell-fill me-1"></i>Activas</span>';

            let html = '<table class="table table-sm mb-3">';
            html += '<tr><td class="text-muted" style="width:160px">Estado (DB)</td><td><span class="badge ' + statusBadge + '">' + data.status + '</span></td></tr>';
            html += '<tr><td class="text-muted">Conectividad</td><td id="ping-result"><div class="spinner-border spinner-border-sm text-info me-2"></div><span class="text-muted">Verificando conexión en vivo...</span></td></tr>';
            html += '<tr><td class="text-muted">Rol</td><td>' + (data.role || 'unknown') + '</td></tr>';
            html += '<tr><td class="text-muted">URL</td><td><code>' + (data.api_url || '') + '</code></td></tr>';
            html += '<tr><td class="text-muted">Último heartbeat</td><td>' + (data.last_seen_at || 'Nunca') + (data.age_seconds !== null ? ' <small class="text-muted">(' + ageText + ' atrás)</small>' : '') + '</td></tr>';
            html += '<tr><td class="text-muted">Sync lag</td><td>' + data.sync_lag + 's</td></tr>';
            html += '<tr><td class="text-muted">Alertas</td><td>' + mutedBadge + '</td></tr>';
            html += '</table>';

            document.getElementById('node-status-content').innerHTML = html;

            // Step 2: Live ping (may take up to 30s if offline)
            fetch('/settings/cluster/ping-node?node_id=' + nodeId)
                .then(r => r.json())
                .then(ping => {
                    const el = document.getElementById('ping-result');
                    if (!el) return;
                    if (ping.ok) {
                        el.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Online</span>' +
                            (ping.response_ms ? ' <small class="text-muted">' + ping.response_ms + 'ms</small>' : '') +
                            ' <small class="text-muted">— responde correctamente</small>';
                    } else {
                        el.innerHTML = '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>No responde</span>' +
                            (ping.error ? ' <small class="text-danger ms-2">' + ping.error + '</small>' : '');
                    }
                })
                .catch(() => {
                    const el = document.getElementById('ping-result');
                    if (el) el.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-question-circle me-1"></i>No se pudo verificar</span>';
                });
        })
        .catch(function() {
            document.getElementById('node-status-content').innerHTML = '<div class="text-danger">Error al obtener el estado</div>';
        });
}

function confirmRemoveNode(nodeId, nodeName) {
    Swal.fire({
        title: 'Eliminar nodo',
        html: '<div class="text-start">' +
              '<p>Se eliminará el nodo <strong>' + nodeName + '</strong> del cluster.</p>' +
              '<div class="rounded p-3 mb-3" style="background:rgba(13,202,240,0.1);border:1px solid rgba(13,202,240,0.2);">' +
              '<small>' +
              '<i class="bi bi-info-circle text-info me-1"></i>' +
              '<strong>Esto solo desvincula el nodo de este panel.</strong><br>' +
              'Los hostings, datos y configuración del servidor remoto <strong>no se borran</strong>. ' +
              'El nodo seguirá funcionando de forma independiente.' +
              '</small>' +
              '</div>' +
              '<p class="mb-1">Se eliminará:</p>' +
              '<ul class="small text-muted">' +
              '<li>El registro del nodo en este panel</li>' +
              '<li>Todos los elementos de la cola pendientes para este nodo</li>' +
              '<li>Las alertas y heartbeats dejarán de enviarse</li>' +
              '</ul>' +
              '<hr class="border-secondary">' +
              '<p class="mb-1">Introduce tu contraseña de administrador para confirmar:</p>' +
              '<input type="password" id="swal-remove-pass" class="form-control bg-dark text-light border-secondary" placeholder="Contraseña de admin">' +
              '</div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar nodo',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
        background: '#1e1e2e',
        color: '#fff',
        preConfirm: function() {
            const pass = document.getElementById('swal-remove-pass').value;
            if (!pass) {
                Swal.showValidationMessage('Introduce tu contraseña');
                return false;
            }
            // Verify password via API
            const fd = new FormData();
            fd.append('password', pass);
            fd.append('_csrf_token', document.querySelector('[name=_csrf_token]').value);
            return fetch('/settings/cluster/verify-admin-password', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data.ok) {
                        Swal.showValidationMessage('Contraseña incorrecta');
                        return false;
                    }
                    return true;
                })
                .catch(() => {
                    Swal.showValidationMessage('Error al verificar contraseña');
                    return false;
                });
        },
    }).then(function(result) {
        if (result.isConfirmed) {
            const form = document.getElementById('form-remove-node');
            document.getElementById('remove-node-id').value = nodeId;
            form.action = '/settings/cluster/remove-node/' + nodeId;
            form.submit();
        }
    });
}

function toggleNodeAlerts(nodeId, action, nodeName) {
    const isMute = action === 'mute';
    Swal.fire({
        title: isMute ? 'Silenciar alertas' : 'Reactivar alertas',
        html: isMute
            ? '<p>Silenciar todas las alertas (Telegram/Email) del nodo <strong>' + nodeName + '</strong>?</p>' +
              '<p class="text-muted"><small>Útil para mantenimiento programado. Las alertas se reactivarán automáticamente si el nodo vuelve online.</small></p>'
            : '<p>Reactivar las alertas del nodo <strong>' + nodeName + '</strong>?</p>',
        icon: isMute ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: isMute ? 'Silenciar' : 'Reactivar',
        confirmButtonColor: isMute ? '#6c757d' : '#ffc107',
        cancelButtonText: 'Cancelar',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (!result.isConfirmed) return;

        const url = isMute
            ? '/settings/cluster/mute-node-alerts'
            : '/settings/cluster/unmute-node-alerts';

        const form = new FormData();
        form.append('node_id', nodeId);
        form.append('_csrf_token', document.querySelector('[name=_csrf_token]').value);

        fetch(url, { method: 'POST', body: form })
            .then(r => r.json())
            .then(data => {
                Swal.fire({
                    title: data.ok ? 'OK' : 'Error',
                    text: data.message || data.error,
                    icon: data.ok ? 'success' : 'error',
                    timer: 2000,
                    background: '#1e1e2e',
                    color: '#fff',
                }).then(() => { if (data.ok) location.reload(); });
            })
            .catch(e => {
                Swal.fire({ title: 'Error', text: e.message, icon: 'error', background: '#1e1e2e', color: '#fff' });
            });
    });
}

function confirmPromoteCluster() {
    Swal.fire({
        title: 'Promover a Master',
        html: '<div class="text-start">' +
              '<p><strong class="text-warning">⚠ OPERACIÓN CRÍTICA</strong></p>' +
              '<p>Este servidor será promovido a <strong>Master</strong>. Esto implica:</p>' +
              '<ul>' +
              '<li>PostgreSQL dejará de ser réplica y aceptará escrituras</li>' +
              '<li>MySQL dejará de replicar</li>' +
              '<li>El rol del panel cambiará a <code>master</code></li>' +
              '<li>Este servidor comenzará a atender tráfico de producción</li>' +
              '</ul>' +
              '<p class="text-danger"><strong>Solo haga esto si el master actual ha fallado y no se puede recuperar.</strong></p>' +
              '<hr class="border-secondary">' +
              '<p class="mb-1">Escriba su contraseña de administrador para confirmar:</p>' +
              '<input type="password" id="swal-promote-pass" class="form-control bg-dark text-light border-secondary" placeholder="Contraseña de admin">' +
              '</div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Promover a Master',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f59e0b',
        background: '#1e1e2e',
        color: '#fff',
        preConfirm: function() {
            const pass = document.getElementById('swal-promote-pass').value;
            if (!pass) {
                Swal.showValidationMessage('Debe ingresar la contraseña');
                return false;
            }
            return fetch('/settings/cluster/verify-admin-password', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: '_csrf_token=' + encodeURIComponent(document.querySelector('[name=_csrf_token]').value) +
                      '&password=' + encodeURIComponent(pass)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.ok) {
                    Swal.showValidationMessage('Contraseña incorrecta');
                    return false;
                }
                return true;
            });
        }
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('form-promote-cluster').submit();
        }
    });
}

function confirmDemoteCluster() {
    const select = document.getElementById('demote-node-select');
    const ipInput = document.querySelector('input[name=new_master_ip]');
    let ip = '';
    let nodeName = '';

    if (select) {
        ip = select.value;
        nodeName = select.options[select.selectedIndex]?.dataset?.name || ip;
    } else if (ipInput) {
        ip = ipInput.value;
        nodeName = ip;
    }

    if (!ip) {
        Swal.fire({ title: 'Error', text: 'Seleccione el nodo que será el nuevo Master', icon: 'error', background: '#1e1e2e', color: '#fff' });
        return;
    }

    Swal.fire({
        title: 'Degradar a Slave',
        html: '<div class="text-start">' +
              '<p><strong class="text-danger">⚠ OPERACIÓN CRÍTICA</strong></p>' +
              '<p>Este servidor será degradado a <strong>Slave</strong> y se reconfigurará para replicar desde:</p>' +
              '<div class="p-2 mb-3 rounded" style="background:#2a2a3e;">' +
              '<strong>' + nodeName + '</strong> <code>(' + ip + ')</code>' +
              '</div>' +
              '<p>Esto implica:</p>' +
              '<ul>' +
              '<li>El panel dejará de aceptar creación de hostings</li>' +
              '<li>Las bases de datos se reconfigurarán como réplica</li>' +
              '<li>El servidor pasará a modo de solo recepción</li>' +
              '</ul>' +
              '<hr class="border-secondary">' +
              '<p class="mb-1">Escriba su contraseña de administrador para confirmar:</p>' +
              '<input type="password" id="swal-demote-pass" class="form-control bg-dark text-light border-secondary" placeholder="Contraseña de admin">' +
              '</div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, degradar a Slave',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
        background: '#1e1e2e',
        color: '#fff',
        preConfirm: function() {
            const pass = document.getElementById('swal-demote-pass').value;
            if (!pass) {
                Swal.showValidationMessage('Debe ingresar la contraseña');
                return false;
            }
            return fetch('/settings/cluster/verify-admin-password', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: '_csrf_token=' + encodeURIComponent(document.querySelector('[name=_csrf_token]').value) +
                      '&password=' + encodeURIComponent(pass)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.ok) {
                    Swal.showValidationMessage('Contraseña incorrecta');
                    return false;
                }
                return true;
            });
        }
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('form-demote-cluster').submit();
        }
    });
}

function toggleAutoFailover(checkbox) {
    const hiddenInput = document.getElementById('autoFailoverValue');
    if (checkbox.checked) {
        // Activating — show confirmation modal with password
        Swal.fire({
            title: 'Activar Auto-Failover',
            html: '<div class="text-start">' +
                  '<p><strong class="text-danger">ATENCIÓN:</strong> Esta es una operación crítica.</p>' +
                  '<p>Si se activa, este servidor se promoverá <strong>automáticamente a Master</strong> cuando detecte que el master no responde durante el timeout configurado.</p>' +
                  '<p>Consecuencias:</p>' +
                  '<ul>' +
                  '<li>Se abrirán los puertos 80/443 al público</li>' +
                  '<li>El rol del cluster cambiará a Master</li>' +
                  '<li>Se detendrá la replicación de base de datos</li>' +
                  '<li><strong class="text-warning">Riesgo de split-brain</strong> si hay problemas de red temporales</li>' +
                  '</ul>' +
                  '<p class="mb-1">Escriba su contraseña de administrador para confirmar:</p>' +
                  '<input type="password" id="swal-admin-pass" class="form-control bg-dark text-light border-secondary" placeholder="Contraseña">' +
                  '</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Activar Auto-Failover',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#ef4444',
            background: '#1e1e2e',
            color: '#fff',
            preConfirm: function() {
                const pass = document.getElementById('swal-admin-pass').value;
                if (!pass) {
                    Swal.showValidationMessage('Debe ingresar su contraseña');
                    return false;
                }
                return pass;
            }
        }).then(function(result) {
            if (result.isConfirmed) {
                // Verify password via AJAX
                const form = new FormData();
                form.append('password', result.value);
                form.append('_csrf_token', document.querySelector('[name=_csrf_token]').value);
                fetch('/settings/cluster/verify-admin-password', { method: 'POST', body: form })
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok) {
                            hiddenInput.value = '1';
                            Swal.fire({ title: 'Auto-Failover activado', icon: 'success', timer: 2000, background: '#1e1e2e', color: '#fff' });
                        } else {
                            checkbox.checked = false;
                            hiddenInput.value = '0';
                            Swal.fire({ title: 'Contraseña incorrecta', icon: 'error', background: '#1e1e2e', color: '#fff' });
                        }
                    })
                    .catch(function() {
                        checkbox.checked = false;
                        hiddenInput.value = '0';
                    });
            } else {
                checkbox.checked = false;
                hiddenInput.value = '0';
            }
        });
    } else {
        // Deactivating — no confirmation needed
        hiddenInput.value = '0';
    }
}

function confirmSyncAll(nodeId, nodeName) {
    Swal.fire({
        title: 'Sincronizar todos los hostings',
        html: '<p>Se encolaran <strong>todos los hostings existentes</strong> para ser creados en el nodo <strong>' + nodeName + '</strong>.</p>' +
              '<p><i class="bi bi-clock me-1"></i>Los elementos se añaden a la <strong>cola de sincronización</strong> y se procesan <strong>automáticamente cada minuto</strong> por el cron worker.</p>' +
              '<p><i class="bi bi-check-circle me-1 text-success"></i>Si un hosting ya existe en el nodo remoto, se detecta y se omite (no se duplica ni genera errores).</p>' +
              '<hr style="border-color:rgba(255,255,255,0.1)">' +
              '<p class="mb-1"><strong>Qué se sincroniza automáticamente (API):</strong></p>' +
              '<ul class="text-start" style="font-size:0.9em"><li>Cuenta de sistema (usuario Linux, pool FPM, Caddy)</li><li>Registro en base de datos (hosting + dominio)</li></ul>' +
              '<p class="mb-1"><strong>Qué requiere SSH configurado:</strong></p>' +
              '<ul class="text-start" style="font-size:0.9em"><li>Contenido de archivos (rsync incremental — solo copia archivos nuevos o modificados)</li></ul>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, sincronizar todo',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#22c55e',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('sync-all-node-id').value = nodeId;
            document.getElementById('form-sync-all').submit();
        }
    });
}

function confirmCleanQueue(form) {
    Swal.fire({
        title: 'Limpiar cola',
        text: 'Se eliminarán todos los elementos completados de la cola. ¿Continuar?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, limpiar',
        cancelButtonText: 'Cancelar',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (result.isConfirmed) {
            form.submit();
        }
    });
}

function copyLocalToken() {
    const input = document.getElementById('local-token-input');
    input.select();
    navigator.clipboard.writeText(input.value).then(function() {
        Swal.fire({
            toast: true, position: 'top-end', icon: 'success',
            title: 'Token copiado', showConfirmButton: false, timer: 1500,
            background: '#1e1e2e', color: '#fff'
        });
    });
}

// ─── File Sync Functions ─────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function() {
    const methodSelect = document.getElementById('filesyncMethod');
    const descEl = document.getElementById('filesyncMethodDesc');
    if (methodSelect) {
        methodSelect.addEventListener('change', function() {
            const sshSection = document.getElementById('sshSettings');
            if (sshSection) {
                sshSection.style.display = this.value === 'ssh' ? '' : 'none';
            }
            if (descEl) {
                descEl.textContent = this.value === 'ssh'
                    ? 'Usa rsync sobre SSH. Más rápido para sincronizaciones incrementales.'
                    : 'Usa la API HTTPS del panel. No requiere SSH, funciona a través de NAT.';
            }
        });
    }
});

function generateSshKey() {
    const csrf = document.querySelector('[name=_csrf_token]').value;
    Swal.fire({
        title: 'Generar clave SSH',
        text: 'Se generará un nuevo par de claves SSH ed25519. Si ya existe una, será reemplazada.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Generar',
        cancelButtonText: 'Cancelar',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (!result.isConfirmed) return;

        fetch('/settings/cluster/generate-ssh-key', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: '_csrf_token=' + encodeURIComponent(csrf)
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const keyArea = document.getElementById('sshPubKeyArea');
                const keyEl = document.getElementById('sshPubKeyDisplay');
                if (keyEl) keyEl.value = data.public_key || '';
                if (keyArea) keyArea.style.display = '';
                Swal.fire({
                    toast: true, position: 'top-end', icon: 'success',
                    title: 'Clave SSH generada correctamente',
                    showConfirmButton: false, timer: 2000,
                    background: '#1e1e2e', color: '#fff'
                });
            } else {
                Swal.fire({ title: 'Error', text: data.error || 'Error al generar la clave', icon: 'error', background: '#1e1e2e', color: '#fff' });
            }
        })
        .catch(function() {
            Swal.fire({ title: 'Error', text: 'Error de conexión', icon: 'error', background: '#1e1e2e', color: '#fff' });
        });
    });
}

function installSshKeyOnNode(nodeId, nodeName) {
    const csrf = document.querySelector('[name=_csrf_token]').value;
    Swal.fire({
        title: 'Instalar clave SSH',
        html: 'Se instalará la clave pública de este servidor en <strong>' + nodeName + '</strong> para permitir conexiones rsync.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Instalar',
        cancelButtonText: 'Cancelar',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Instalando clave SSH...',
            allowOutsideClick: false, allowEscapeKey: false,
            didOpen: () => Swal.showLoading(),
            background: '#1e1e2e', color: '#fff'
        });

        const formData = new FormData();
        formData.append('node_id', nodeId);
        formData.append('_csrf_token', csrf);

        fetch('/settings/cluster/install-ssh-key', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                Swal.fire({ title: 'OK', text: data.message || 'Clave instalada en ' + nodeName, icon: 'success', background: '#1e1e2e', color: '#fff' });
            } else {
                Swal.fire({ title: 'Error', text: data.error || 'Error al instalar la clave', icon: 'error', background: '#1e1e2e', color: '#fff' });
            }
        })
        .catch(function() {
            Swal.fire({ title: 'Error', text: 'Error de conexión', icon: 'error', background: '#1e1e2e', color: '#fff' });
        });
    });
}

function testSshNode(nodeId, nodeName) {
    const csrf = document.querySelector('[name=_csrf_token]').value;

    Swal.fire({
        title: 'Probando SSH a ' + nodeName + '...',
        allowOutsideClick: false, allowEscapeKey: false,
        didOpen: () => Swal.showLoading(),
        background: '#1e1e2e', color: '#fff'
    });

    const formData = new FormData();
    formData.append('node_id', nodeId);
    formData.append('_csrf_token', csrf);

    fetch('/settings/cluster/test-ssh', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            Swal.fire({ title: 'Conexión SSH OK', html: '<pre class="text-start">' + (data.output || 'OK') + '</pre>', icon: 'success', background: '#1e1e2e', color: '#fff' });
        } else {
            Swal.fire({ title: 'Fallo SSH', html: '<pre class="text-start text-danger">' + (data.error || 'Error desconocido') + '</pre>', icon: 'error', background: '#1e1e2e', color: '#fff' });
        }
    })
    .catch(function() {
        Swal.fire({ title: 'Error', text: 'Error de conexión', icon: 'error', background: '#1e1e2e', color: '#fff' });
    });
}

let _syncPollTimer = null;
let _syncStartTime = null;

function syncFilesNow(nodeId, nodeName) {
    const csrf = document.querySelector('[name=_csrf_token]').value;

    Swal.fire({
        title: 'Sincronizar archivos a ' + nodeName,
        html: '<p>Se sincronizarán los archivos de <strong>todos los hostings activos</strong> al nodo seleccionado.</p>' +
              '<p><i class="bi bi-arrow-repeat me-1"></i>Usa rsync incremental — solo copia archivos nuevos o modificados.</p>' +
              '<p class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Puede tardar varios minutos según el volumen de datos.</p>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sincronizar ahora',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#22c55e',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (!result.isConfirmed) return;

        // Show initial loading modal
        _showSyncModal('Iniciando sincronización...', nodeName);

        const formData = new FormData();
        formData.append('node_id', nodeId);
        formData.append('_csrf_token', csrf);

        fetch('/settings/cluster/sync-files-now', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.ok && data.sync_id) {
                // Save to sessionStorage so modal survives page reload
                sessionStorage.setItem('active_sync', JSON.stringify({
                    sync_id: data.sync_id, node_name: data.node_name || nodeName,
                    started: Date.now()
                }));
                _syncStartTime = Date.now();
                _startSyncPolling(data.sync_id, data.node_name || nodeName);
            } else {
                Swal.fire({ title: 'Error', text: data.error || 'Error al iniciar sync', icon: 'error', background: '#1e1e2e', color: '#fff' });
            }
        })
        .catch(function() {
            Swal.fire({ title: 'Error', text: 'Error de conexión', icon: 'error', background: '#1e1e2e', color: '#fff' });
        });
    });
}

function _showSyncModal(subtitle, nodeName) {
    Swal.fire({
        title: 'Sincronizando archivos',
        html: '<div id="sync-progress-content">' +
              '<p class="mb-2">' + subtitle + '</p>' +
              '<div class="progress mb-2" style="height:20px;background:rgba(255,255,255,0.1);border-radius:10px;">' +
              '  <div id="sync-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%;background:#22c55e;"></div>' +
              '</div>' +
              '<div class="d-flex justify-content-between mb-2" style="font-size:0.85rem;">' +
              '  <span id="sync-counter">0 / 0</span>' +
              '  <span id="sync-timer">00:00</span>' +
              '</div>' +
              '<div id="sync-current" class="text-muted mb-2" style="font-size:0.85rem;"></div>' +
              '<div id="sync-details" class="text-start" style="max-height:180px;overflow-y:auto;font-size:0.8rem;"></div>' +
              '</div>',
        allowOutsideClick: false, allowEscapeKey: false,
        showConfirmButton: false,
        background: '#1e1e2e', color: '#fff'
    });
}

function _startSyncPolling(syncId, nodeName) {
    if (_syncPollTimer) clearInterval(_syncPollTimer);

    _syncPollTimer = setInterval(function() {
        fetch('/settings/cluster/sync-progress?sync_id=' + encodeURIComponent(syncId))
        .then(r => r.json())
        .then(data => {
            _updateSyncModal(data, nodeName, syncId);
        })
        .catch(function() {});
    }, 2000);
}

function _updateSyncModal(data, nodeName, syncId) {
    // Update timer
    const timerEl = document.getElementById('sync-timer');
    if (timerEl) {
        const elapsed = data.elapsed || 0;
        const mins = Math.floor(elapsed / 60);
        const secs = Math.floor(elapsed % 60);
        timerEl.textContent = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }

    // Update progress bar
    const bar = document.getElementById('sync-bar');
    const counter = document.getElementById('sync-counter');
    const current = document.getElementById('sync-current');
    const details = document.getElementById('sync-details');

    if (bar && data.total > 0) {
        const pct = Math.round((data.current / data.total) * 100);
        bar.style.width = pct + '%';
        bar.textContent = pct + '%';
    }

    if (counter) {
        counter.textContent = (data.current || 0) + ' / ' + (data.total || 0);
    }

    if (current && data.current_domain) {
        const phase = data.phase || '';
        let icon = '<i class="bi bi-arrow-repeat spin-icon me-1"></i>';
        if (phase === 'ssl') icon = '<i class="bi bi-shield-lock me-1 text-warning"></i>';
        if (phase === 'db_dumps') icon = '<i class="bi bi-database me-1 text-info"></i>';
        if (phase === 'done') icon = '<i class="bi bi-check me-1 text-success"></i>';
        current.innerHTML = icon + 'Sincronizando: <strong>' + data.current_domain + '</strong>';
    }

    if (details && data.details && data.details.length) {
        let html = '';
        data.details.forEach(function(d) {
            const icon = d.ok
                ? '<i class="bi bi-check-circle text-success"></i>'
                : '<i class="bi bi-x-circle text-danger"></i>';
            const err = d.error ? ' <span class="text-danger">— ' + d.error + '</span>' : '';
            html += icon + ' ' + d.domain + err + '<br>';
        });
        details.innerHTML = html;
        details.scrollTop = details.scrollHeight;
    }

    // Check if completed
    if (data.status === 'completed' || data.status === 'error') {
        if (_syncPollTimer) { clearInterval(_syncPollTimer); _syncPollTimer = null; }
        sessionStorage.removeItem('active_sync');

        setTimeout(function() {
            if (data.status === 'error') {
                Swal.fire({ title: 'Error', text: data.error || 'Error durante la sincronización', icon: 'error', background: '#1e1e2e', color: '#fff' });
                return;
            }

            let html = '<div class="text-start">';
            html += '<div class="d-flex justify-content-around mb-3">';
            html += '<div class="text-center"><div style="font-size:1.5rem;color:#22c55e;">' + (data.ok_count || 0) + '</div><small class="text-muted">OK</small></div>';
            html += '<div class="text-center"><div style="font-size:1.5rem;color:#ef4444;">' + (data.fail_count || 0) + '</div><small class="text-muted">Fallidos</small></div>';
            html += '<div class="text-center"><div style="font-size:1.5rem;color:#94a3b8;">' + (data.elapsed || 0).toFixed(1) + 's</div><small class="text-muted">Tiempo</small></div>';
            html += '</div>';

            if (data.ssl) {
                const sslIcon = data.ssl.ok ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>';
                html += '<p>' + sslIcon + ' Certificados SSL: ' + (data.ssl.ok ? 'copiados' : (data.ssl.error || 'error')) + '</p>';
            }

            if (data.db_dumps) {
                const dbIcon = data.db_dumps.ok ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>';
                const dbDetail = data.db_dumps.dump_ok
                    ? data.db_dumps.dump_ok + '/' + data.db_dumps.dumped + ' bases de datos restauradas'
                    : (data.db_dumps.detail || data.db_dumps.error || 'error');
                html += '<p>' + dbIcon + ' Bases de datos: ' + dbDetail + '</p>';
            }

            if (data.details && data.details.length) {
                html += '<div style="max-height:200px;overflow-y:auto;font-size:0.85rem;border-top:1px solid rgba(255,255,255,0.1);padding-top:8px;">';
                data.details.forEach(function(d) {
                    const icon = d.ok ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>';
                    const err = d.error ? ' <span class="text-danger">— ' + d.error + '</span>' : '';
                    html += icon + ' ' + d.domain + err + '<br>';
                });
                html += '</div>';
            }
            html += '</div>';

            const finalIcon = (data.fail_count || 0) === 0 ? 'success' : 'warning';
            Swal.fire({ title: 'Sincronización completada', html: html, icon: finalIcon, background: '#1e1e2e', color: '#fff' });
        }, 500);
    }
}

// Resume sync modal on page load if a sync was in progress
(function() {
    const saved = sessionStorage.getItem('active_sync');
    if (saved) {
        try {
            const info = JSON.parse(saved);
            if (info.sync_id) {
                _syncStartTime = info.started || Date.now();
                _showSyncModal('Recuperando progreso...', info.node_name || '');
                _startSyncPolling(info.sync_id, info.node_name || '');
            }
        } catch(e) { sessionStorage.removeItem('active_sync'); }
    }
})();

function checkDbHost() {
    const csrf = document.querySelector('[name=_csrf_token]').value;

    Swal.fire({
        title: 'Verificando DB_HOST...',
        html: 'Escaneando archivos .env y wp-config.php de todos los hostings...',
        allowOutsideClick: false, allowEscapeKey: false,
        didOpen: () => Swal.showLoading(),
        background: '#1e1e2e', color: '#fff'
    });

    fetch('/settings/cluster/check-dbhost', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: '_csrf_token=' + encodeURIComponent(csrf)
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            const results = data.results || [];
            let html = '';
            if (results.length === 0) {
                html = '<div class="text-success"><i class="bi bi-check-circle me-1"></i>Todos los hostings usan localhost como DB_HOST.</div>';
            } else {
                html = '<div class="text-warning mb-2"><i class="bi bi-exclamation-triangle me-1"></i>' + results.length + ' hosting(s) con DB_HOST remoto:</div>';
                html += '<div class="text-start" style="max-height:300px;overflow-y:auto;font-size:0.85rem;">';
                results.forEach(function(r) {
                    html += '<div class="mb-2 p-2" style="background:#2a2a3e;border-radius:4px;">';
                    html += '<strong>' + r.domain + '</strong><br>';
                    (r.files || []).forEach(function(f) {
                        html += '<code>' + f.file + '</code>: <span class="text-warning">' + f.db_host + '</span><br>';
                    });
                    html += '</div>';
                });
                html += '</div>';
            }
            Swal.fire({ title: 'Resultado DB_HOST', html: html, icon: results.length ? 'warning' : 'success', background: '#1e1e2e', color: '#fff', width: 600 });
        } else {
            Swal.fire({ title: 'Error', text: data.error || 'Error al verificar', icon: 'error', background: '#1e1e2e', color: '#fff' });
        }
    })
    .catch(function() {
        Swal.fire({ title: 'Error', text: 'Error de conexión', icon: 'error', background: '#1e1e2e', color: '#fff' });
    });
}

function regenerateLocalToken() {
    Swal.fire({
        title: 'Regenerar token local',
        text: 'Los nodos remotos que usen este token dejarán de poder conectarse. ¿Continuar?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, regenerar',
        cancelButtonText: 'Cancelar',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (result.isConfirmed) {
            fetch('/settings/cluster/generate-token', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: '_csrf_token=' + encodeURIComponent(document.querySelector('[name=_csrf_token]').value)
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    const input = document.getElementById('local-token-input');
                    input.value = data.token;
                    input.readOnly = false; // Allow form to submit the new value
                    Swal.fire({
                        toast: true, position: 'top-end', icon: 'success',
                        title: 'Token regenerado. Guarde la configuración para aplicar.',
                        showConfirmButton: false, timer: 3000,
                        background: '#1e1e2e', color: '#fff'
                    });
                }
            });
        }
    });
}

// ─── Full Sync Orchestrator ──────────────────────────────────────────

function fullSync(nodeId, nodeName) {
    const csrf = document.querySelector('[name=_csrf_token]').value;
    Swal.fire({
        title: 'Sincronización Completa a ' + nodeName,
        html: '<div class="text-start">' +
              '<p>Se ejecutarán los siguientes pasos en secuencia:</p>' +
              '<ol>' +
              '<li><strong>Hostings</strong> — Crear/reparar cuentas de sistema, PHP-FPM, Caddy (via API)</li>' +
              '<li><strong>Archivos</strong> — Copiar contenido web con rsync (requiere SSH)</li>' +
              '<li><strong>Certificados SSL</strong> — Copiar certs de Caddy al slave</li>' +
              '</ol>' +
              '<p class="text-muted small">Si SSH no está configurado, se ejecutará solo el paso 1 y se avisará.</p>' +
              '</div>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Iniciar sincronización completa',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#22c55e',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (!result.isConfirmed) return;
        _showSyncModal('Iniciando sincronización completa...', nodeName);

        const formData = new FormData();
        formData.append('node_id', nodeId);
        formData.append('_csrf_token', csrf);

        fetch('/settings/cluster/full-sync', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.ok && data.sync_id) {
                sessionStorage.setItem('active_sync', JSON.stringify({
                    sync_id: data.sync_id, node_name: data.node_name || nodeName,
                    started: Date.now()
                }));
                _syncStartTime = Date.now();
                _startSyncPolling(data.sync_id, data.node_name || nodeName);
            } else {
                Swal.fire({ title: 'Error', text: data.error || 'Error al iniciar', icon: 'error', background: '#1e1e2e', color: '#fff' });
            }
        })
        .catch(function() {
            Swal.fire({ title: 'Error', text: 'Error de conexión', icon: 'error', background: '#1e1e2e', color: '#fff' });
        });
    });
}
</script>
