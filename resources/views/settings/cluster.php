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
?>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- CARD 1 — Estado del Cluster                                -->
<!-- ═══════════════════════════════════════════════════════════ -->
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
                        <td class="text-muted">Rol Replicacion</td>
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
                        <td class="text-muted">Version Panel</td>
                        <td><?= View::e($localStatus['panel_version'] ?? '?') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- CARD 2 — Nodos Vinculados                                  -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hdd-network me-2"></i>Nodos Vinculados</span>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addNodeModal">
            <i class="bi bi-plus-circle me-1"></i>Anadir Nodo
        </button>
    </div>
    <div class="card-body">
        <?php if (empty($nodes)): ?>
            <p class="text-muted text-center mb-0">
                <i class="bi bi-info-circle me-1"></i>No hay nodos vinculados. Use "Anadir Nodo" para conectar otro servidor.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>URL</th>
                            <th>Estado</th>
                            <th>Ultimo Heartbeat</th>
                            <th>Lag</th>
                            <th>Rol Remoto</th>
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
                            <td class="text-end">
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
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- CARD 3 — Cola de Sincronizacion                            -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-collection me-2"></i>Cola de Sincronizacion</span>
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
            <p class="text-muted text-center mb-0"><i class="bi bi-info-circle me-1"></i>La cola esta vacia.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Accion</th>
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

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- CARD 4 — Operaciones de Failover                           -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Operaciones de Failover</div>
    <div class="card-body">
        <p class="text-muted mb-3">
            Las operaciones de failover permiten cambiar el rol de este servidor dentro del cluster.
            Use estas opciones con precaucion, ya que afectan la replicacion de bases de datos.
        </p>

        <?php if ($replRole === 'slave'): ?>
            <div class="alert" style="background: rgba(251,191,36,0.1); border: 1px solid rgba(251,191,36,0.3); color: #fbbf24;">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Este servidor es actualmente un <strong>Slave</strong>. Puede promoverlo a Master si el master actual ha fallado.
            </div>
            <form method="post" action="/settings/cluster/promote" id="form-promote-cluster">
                <?= View::csrf() ?>
                <button type="button" class="btn btn-warning" onclick="confirmPromoteCluster()">
                    <i class="bi bi-arrow-up-circle me-1"></i>Promover a Master
                </button>
            </form>

        <?php elseif ($replRole === 'master'): ?>
            <div class="alert" style="background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); color: #60a5fa;">
                <i class="bi bi-info-circle me-2"></i>
                Este servidor es actualmente el <strong>Master</strong>. Puede degradarlo a Slave si necesita transferir el rol a otro servidor.
            </div>
            <form method="post" action="/settings/cluster/demote" id="form-demote-cluster">
                <?= View::csrf() ?>
                <div class="mb-3" style="max-width: 350px;">
                    <label class="form-label">IP del nuevo Master</label>
                    <input type="text" name="new_master_ip" class="form-control" placeholder="192.168.1.100" required>
                </div>
                <button type="button" class="btn btn-danger" onclick="confirmDemoteCluster()">
                    <i class="bi bi-arrow-down-circle me-1"></i>Degradar a Slave
                </button>
            </form>

        <?php else: ?>
            <div class="alert" style="background: rgba(107,114,128,0.1); border: 1px solid rgba(107,114,128,0.3); color: #9ca3af;">
                <i class="bi bi-info-circle me-2"></i>
                Este servidor es <strong>Standalone</strong>. Configure la replicacion primero en
                <a href="/settings/replication" class="text-info">Ajustes de Replicacion</a> antes de usar operaciones de failover.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- CARD 5 — Configuracion                                     -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-gear me-2"></i>Configuracion del Cluster</div>
    <div class="card-body">
        <form method="post" action="/settings/cluster/save-settings">
            <?= View::csrf() ?>

            <!-- Cluster Role -->
            <h6 class="text-muted mb-2">Rol del Cluster</h6>
            <p class="small text-muted">Define el rol de este servidor en la sincronizacion de hostings.</p>
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
                            <span class="text-success"><i class="bi bi-check-circle me-1"></i>Los hostings creados aqui se replicaran a los nodos slave vinculados.</span>
                        <?php elseif ($clusterRole === 'slave'): ?>
                            <span class="text-info"><i class="bi bi-info-circle me-1"></i>La creacion de hostings esta bloqueada. Solo se reciben hostings del master.</span>
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
                       placeholder="Token de autenticacion local" readonly>
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

            <!-- Notificaciones — enlace a la nueva pagina -->
            <h6 class="text-muted mb-2">Notificaciones</h6>
            <p class="small text-muted mb-2">
                Las alertas del cluster (nodos caidos, failover, etc.) se envian a traves de los canales configurados en Notificaciones.
            </p>
            <a href="/settings/notifications" class="btn btn-outline-info btn-sm mb-3">
                <i class="bi bi-bell me-1"></i>Configurar Notificaciones (Email / Telegram)
            </a>

            <br>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-circle me-1"></i>Guardar Configuracion
            </button>
        </form>
    </div>
</div>

<?php $fsConfig = \MuseDockPanel\Services\FileSyncService::getConfig(); ?>
<!-- ═══════════════════════════════════════════════════════════ -->
<!-- CARD 6 — Sincronizacion de Archivos                         -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-arrow-repeat me-2"></i>Sincronizacion de Archivos</div>
    <div class="card-body">
        <form method="post" action="/settings/cluster/filesync-settings">
            <?= View::csrf() ?>

            <!-- Enable toggle -->
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="filesync_enabled" id="filesyncEnabled"
                       <?= $fsConfig['enabled'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="filesyncEnabled">Activar sincronizacion automatica de archivos</label>
            </div>

            <!-- Method selector -->
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Metodo de sincronizacion</label>
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
                                Archivos via API HTTPS. Mas rapido entre VPS (~24 MB/s). No requiere SSH.
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>

            <hr class="border-secondary">

            <!-- SSH Settings (shown when method=ssh) -->
            <div id="sshSettings" style="<?= $fsConfig['method'] !== 'ssh' ? 'display:none' : '' ?>">
                <h6 class="text-muted mb-2">Configuracion SSH</h6>
                <div class="alert alert-info small py-2 mb-3" style="background:#1a3a4a;border-color:#2a5a6a;color:#a8d8ea;">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Como funciona:</strong> Este servidor (master) se conecta por SSH al slave para copiar archivos con rsync.
                    La clave privada se queda aqui, la clave publica se instala en los slaves.
                    <br>
                    <strong>1.</strong> Pulsa "Generar" para crear el par de claves en este servidor &rarr;
                    <strong>2.</strong> Pulsa "Instalar clave" en cada nodo slave &rarr;
                    <strong>3.</strong> Pulsa "Test SSH" para verificar la conexion.
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
                        <small class="text-muted">Se genera aqui. La publica se envia a los slaves.</small>
                    </div>
                </div>

                <!-- Public key display -->
                <div class="mb-3" id="sshPubKeyArea" style="display:none">
                    <label class="form-label small text-muted">Clave publica (para copiar al slave):</label>
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
                    No hay nodos configurados. Primero añade un nodo en la seccion "Nodos del Cluster" (arriba) para poder instalar la clave SSH y hacer test de conexion.
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
                    <small class="text-muted">Cada cuantos minutos sincronizar</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Limite ancho de banda (KB/s)</label>
                    <input type="number" name="filesync_bwlimit" class="form-control"
                           value="<?= $fsConfig['bandwidth_limit'] ?>" min="0">
                    <small class="text-muted">0 = sin limite</small>
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
                    <label class="form-label small text-muted">Ruta certificados Caddy (auto-detectada si vacio)</label>
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
            <h6 class="text-muted mb-2">Proteccion de credenciales</h6>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="filesync_rewrite_dbhost" id="filesyncRewriteDb"
                       <?= $fsConfig['rewrite_db_host'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="filesyncRewriteDb">
                    Reescribir DB_HOST a <code>localhost</code> en .env y wp-config.php del slave
                </label>
            </div>
            <p class="small text-muted mb-3">
                <i class="bi bi-shield-check me-1"></i>
                Evita que las apps del slave intenten conectar a la BD del master. Cambia automaticamente DB_HOST a localhost
                en archivos .env (Laravel) y wp-config.php (WordPress) al recibir archivos.
            </p>

            <!-- Check DB_HOST button -->
            <button type="button" class="btn btn-outline-warning btn-sm mb-3" onclick="checkDbHost()">
                <i class="bi bi-search me-1"></i>Verificar DB_HOST en todos los hostings
            </button>
            <div id="dbhostCheckResult" class="mb-3"></div>

            <hr class="border-secondary">

            <!-- Manual sync buttons -->
            <?php if (!empty($nodes) && $clusterRole === 'master'): ?>
            <h6 class="text-muted mb-2">Sincronizacion manual de archivos</h6>
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
                <i class="bi bi-check-circle me-1"></i>Guardar Configuracion de Archivos
            </button>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- Modal: Anadir Nodo                                         -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="addNodeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Anadir Nodo</h5>
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
                        <label class="form-label">Token de Autenticacion</label>
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
                            <i class="bi bi-plug me-1"></i>Probar Conexion
                        </button>
                        <span id="test-node-result" class="ms-2"></span>
                    </div>

                    <!-- Remote info (shown after test) -->
                    <div id="remote-info" class="alert" style="display:none; background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #22c55e;"></div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Anadir Nodo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- Modal: Ver Estado del Nodo                                 -->
<!-- ═══════════════════════════════════════════════════════════ -->
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

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- JavaScript                                                 -->
<!-- ═══════════════════════════════════════════════════════════ -->
<script>
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
                let info = '<strong>Informacion del nodo remoto:</strong><br>';
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
            resultEl.innerHTML = '<span class="text-danger">Error de conexion</span>';
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
    document.getElementById('node-status-content').innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>Cargando estado del nodo...</div>';
    new bootstrap.Modal(document.getElementById('nodeStatusModal')).show();

    // Get node status via the node-status endpoint
    fetch('/settings/cluster/node-status')
        .then(r => r.json())
        .then(data => {
            const node = (data.nodes || []).find(n => n.id == nodeId);
            if (!node) {
                document.getElementById('node-status-content').innerHTML = '<div class="text-danger">Nodo no encontrado en la respuesta</div>';
                return;
            }

            let html = '<table class="table table-sm">';
            html += '<tr><td class="text-muted">Estado</td><td><span class="badge ' + (node.status === 'online' ? 'bg-success' : 'bg-danger') + '">' + node.status + '</span></td></tr>';
            html += '<tr><td class="text-muted">Rol</td><td>' + (node.role || 'unknown') + '</td></tr>';
            html += '<tr><td class="text-muted">URL</td><td><code>' + (node.api_url || '') + '</code></td></tr>';
            html += '<tr><td class="text-muted">Ultimo heartbeat</td><td>' + (node.last_seen_at || 'Nunca') + '</td></tr>';
            if (node.error) {
                html += '<tr><td class="text-muted">Error</td><td class="text-danger">' + node.error + '</td></tr>';
            }
            html += '</table>';

            document.getElementById('node-status-content').innerHTML = html;
        })
        .catch(function() {
            document.getElementById('node-status-content').innerHTML = '<div class="text-danger">Error al obtener el estado</div>';
        });
}

function confirmRemoveNode(nodeId, nodeName) {
    Swal.fire({
        title: 'Eliminar nodo',
        html: 'Se eliminara el nodo <strong>' + nodeName + '</strong> y todos sus elementos de la cola.<br><br>Esta accion no se puede deshacer.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Si, eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (result.isConfirmed) {
            const form = document.getElementById('form-remove-node');
            document.getElementById('remove-node-id').value = nodeId;
            form.action = '/settings/cluster/remove-node/' + nodeId;
            form.submit();
        }
    });
}

function confirmPromoteCluster() {
    Swal.fire({
        title: 'Promover a Master',
        html: '<strong>ATENCION:</strong> Esta operacion promovera este servidor a Master.<br><br>' +
              'Esto significa que:<br>' +
              '- PostgreSQL dejara de ser replica<br>' +
              '- MySQL dejara de replicar<br>' +
              '- El .env se actualizara a PANEL_ROLE=master<br><br>' +
              '<strong>Solo haga esto si el master actual ha fallado.</strong>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Si, promover a Master',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f59e0b',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('form-promote-cluster').submit();
        }
    });
}

function confirmDemoteCluster() {
    const ip = document.querySelector('[name=new_master_ip]').value;
    if (!ip) {
        Swal.fire({ title: 'Error', text: 'Ingrese la IP del nuevo Master', icon: 'error', background: '#1e1e2e', color: '#fff' });
        return;
    }
    Swal.fire({
        title: 'Degradar a Slave',
        html: 'Este servidor sera degradado a <strong>Slave</strong> y se reconfigurara para replicar desde <strong>' + ip + '</strong>.<br><br>Esta seguro?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Si, degradar a Slave',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('form-demote-cluster').submit();
        }
    });
}

function confirmSyncAll(nodeId, nodeName) {
    Swal.fire({
        title: 'Sincronizar todos los hostings',
        html: 'Se encolaran <strong>todos los hostings existentes</strong> para ser creados en el nodo <strong>' + nodeName + '</strong>.<br><br>' +
              'Esto creara la estructura (usuario Linux, pool FPM, ruta Caddy) en el nodo remoto.<br><br>' +
              '<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Si los hostings ya existen en el nodo, se intentaran crear de nuevo (pueden dar error).</span>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Si, sincronizar todo',
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
        text: 'Se eliminaran todos los elementos completados de la cola. Continuar?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Si, limpiar',
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
                    ? 'Usa rsync sobre SSH. Mas rapido para sincronizaciones incrementales.'
                    : 'Usa la API HTTPS del panel. No requiere SSH, funciona a traves de NAT.';
            }
        });
    }
});

function generateSshKey() {
    const csrf = document.querySelector('[name=_csrf_token]').value;
    Swal.fire({
        title: 'Generar clave SSH',
        text: 'Se generara un nuevo par de claves SSH ed25519. Si ya existe una, sera reemplazada.',
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
            Swal.fire({ title: 'Error', text: 'Error de conexion', icon: 'error', background: '#1e1e2e', color: '#fff' });
        });
    });
}

function installSshKeyOnNode(nodeId, nodeName) {
    const csrf = document.querySelector('[name=_csrf_token]').value;
    Swal.fire({
        title: 'Instalar clave SSH',
        html: 'Se instalara la clave publica de este servidor en <strong>' + nodeName + '</strong> para permitir conexiones rsync.',
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
            Swal.fire({ title: 'Error', text: 'Error de conexion', icon: 'error', background: '#1e1e2e', color: '#fff' });
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
            Swal.fire({ title: 'Conexion SSH OK', html: '<pre class="text-start">' + (data.output || 'OK') + '</pre>', icon: 'success', background: '#1e1e2e', color: '#fff' });
        } else {
            Swal.fire({ title: 'Fallo SSH', html: '<pre class="text-start text-danger">' + (data.error || 'Error desconocido') + '</pre>', icon: 'error', background: '#1e1e2e', color: '#fff' });
        }
    })
    .catch(function() {
        Swal.fire({ title: 'Error', text: 'Error de conexion', icon: 'error', background: '#1e1e2e', color: '#fff' });
    });
}

function syncFilesNow(nodeId, nodeName) {
    const csrf = document.querySelector('[name=_csrf_token]').value;

    Swal.fire({
        title: 'Sincronizar archivos a ' + nodeName,
        html: 'Se sincronizaran los archivos de <strong>todos los hostings activos</strong> al nodo seleccionado.<br><br>' +
              '<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Esto puede tardar varios minutos dependiendo del volumen de datos.</span>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sincronizar ahora',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#22c55e',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Sincronizando archivos...',
            html: 'Enviando archivos a <strong>' + nodeName + '</strong>. Por favor espere...',
            allowOutsideClick: false, allowEscapeKey: false,
            didOpen: () => Swal.showLoading(),
            background: '#1e1e2e', color: '#fff'
        });

        const formData = new FormData();
        formData.append('node_id', nodeId);
        formData.append('_csrf_token', csrf);

        fetch('/settings/cluster/sync-files-now', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                let html = '<strong>Resultado:</strong><br>';
                html += 'OK: ' + (data.ok_count || 0) + ' | Fallidos: ' + (data.fail_count || 0);
                if (data.details && data.details.length) {
                    html += '<br><br><div class="text-start" style="max-height:200px;overflow-y:auto;font-size:0.85rem;">';
                    data.details.forEach(function(d) {
                        const icon = d.ok ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>';
                        html += icon + ' ' + d.domain + (d.error ? ' — ' + d.error : '') + '<br>';
                    });
                    html += '</div>';
                }
                Swal.fire({ title: 'Sincronizacion completada', html: html, icon: 'success', background: '#1e1e2e', color: '#fff' });
            } else {
                Swal.fire({ title: 'Error', text: data.error || 'Error al sincronizar', icon: 'error', background: '#1e1e2e', color: '#fff' });
            }
        })
        .catch(function() {
            Swal.fire({ title: 'Error', text: 'Error de conexion', icon: 'error', background: '#1e1e2e', color: '#fff' });
        });
    });
}

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
        Swal.fire({ title: 'Error', text: 'Error de conexion', icon: 'error', background: '#1e1e2e', color: '#fff' });
    });
}

function regenerateLocalToken() {
    Swal.fire({
        title: 'Regenerar token local',
        text: 'Los nodos remotos que usen este token dejaran de poder conectarse. Continuar?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Si, regenerar',
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
                        title: 'Token regenerado. Guarde la configuracion para aplicar.',
                        showConfirmButton: false, timer: 3000,
                        background: '#1e1e2e', color: '#fff'
                    });
                }
            });
        }
    });
}
</script>
