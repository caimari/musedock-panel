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
    $foCurrentState = $failoverStatus['state'] ?? 'normal';
    if (!empty($failoverStatus['configured'])) {
        $failoverDot = match($foCurrentState) {
            'normal' => 'dot-green',
            'degraded' => 'dot-yellow',
            'primary_down', 'emergency' => 'dot-yellow',
            default => 'dot-gray',
        };
    }

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
            <p class="text-muted">Ejecuta todos los pasos en secuencia: provisionar hostings &rarr; copiar archivos web (rsync vía SSH) &rarr; copiar certificados SSL</p>
            <button class="btn btn-success btn-lg" onclick="fullSync(<?= (int)$node['id'] ?>, '<?= View::e($node['name']) ?>')">
                <i class="bi bi-play-circle me-1"></i>Sincronización Completa a <?= View::e($node['name']) ?>
            </button>
            <div class="mt-3 small text-muted text-start mx-auto" style="max-width:600px;">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Paso 1 — Hostings (API):</strong> Crea/repara cuentas de sistema, PHP-FPM y Caddy en el nodo remoto.<br>
                <strong>Paso 2 — Archivos (rsync vía SSH):</strong> Copia el contenido web de <code>/var/www/vhosts/</code> al slave. Requiere SSH configurado.<br>
                <strong>Paso 3 — SSL (rsync vía SSH):</strong> Copia los certificados de Caddy al slave.<br>
                <i class="bi bi-shield-check me-1 text-success"></i>Es seguro ejecutar varias veces: los hostings se reparan si ya existen, y rsync es incremental (solo copia lo nuevo o modificado, no borra nada).<br>
                <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Si SSH no está configurado, solo se ejecutará el paso 1 y se avisará.</span><br>
                <span class="mt-1 d-block">La sincronización continua de archivos se gestiona en la pestaña <a href="#archivos" style="color:#6ea8fe;">Archivos</a> (lsyncd en tiempo real o rsync periódico).</span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- ── Promote / Demote Cluster ──────────────────────────── -->
    <?php
        $failoverRole = 'standalone';
        if ($clusterRole === 'slave' || $replRole === 'slave') $failoverRole = 'slave';
        elseif ($clusterRole === 'master' || $replRole === 'master') $failoverRole = 'master';
    ?>
    <?php if ($failoverRole !== 'standalone'): ?>
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Promote / Demote Cluster</div>
        <div class="card-body">
            <div class="small mb-3 py-2 px-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);color:#94a3b8;">
                <i class="bi bi-info-circle text-info me-1"></i>
                <strong>Cambia el rol de la base de datos</strong> (PostgreSQL/MySQL) y los puertos del firewall (80/443).
                NO cambia DNS ni la sincronización de archivos (lsyncd).<br>
                Úsalo para mantenimiento planificado o migraciones donde quieres mover la BD sin redirigir el tráfico web.
                Tras promover, revisa la configuración de lsyncd en la pestaña <strong>Archivos</strong> si necesitas invertir la dirección de sincronización.<br>
                <span style="color:#6ea8fe;">Para emergencias reales</span> (master caído, webs offline), usa los botones de la pestaña
                <strong>Failover</strong> que cambian DNS + BD + firewall en un solo clic.
            </div>
            <?php if ($failoverRole === 'slave'): ?>
                <?php
                    $masterIpSaved = $settings['cluster_master_ip'] ?? '';
                    $masterLastHb2 = $settings['cluster_master_last_heartbeat'] ?? '';
                    $masterAge2 = $masterLastHb2 ? (time() - strtotime($masterLastHb2)) : 99999;
                    $masterDown2 = $masterAge2 > (int)($settings['cluster_unreachable_timeout'] ?? 300);
                ?>
                <?php if ($masterDown2 && $masterIpSaved): ?>
                    <div class="alert" style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#f87171;">
                        <i class="bi bi-exclamation-octagon me-2"></i>
                        <strong>Master caído</strong> (<?= View::e($masterIpSaved) ?>) — <?= round($masterAge2 / 60) ?> min sin respuesta
                    </div>
                <?php endif; ?>
                <form method="post" action="/settings/cluster/promote" id="form-promote-cluster">
                    <?= View::csrf() ?>
                    <button type="button" class="btn btn-warning btn-sm" onclick="confirmPromoteCluster()">
                        <i class="bi bi-arrow-up-circle me-1"></i>Promover a Master
                    </button>
                </form>

                <?php $autoFailoverOn = (($settings['cluster_auto_failover'] ?? '0') === '1'); ?>
                <hr class="border-secondary my-3">
                <input type="hidden" id="autoFailoverValue" value="<?= $autoFailoverOn ? '1' : '0' ?>">
                <div class="d-flex align-items-center gap-3">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="autoFailoverToggle"
                               <?= $autoFailoverOn ? 'checked' : '' ?> onchange="toggleAutoFailover(this)">
                        <label class="form-check-label" for="autoFailoverToggle">
                            Auto-promover a Master si el master cae
                        </label>
                    </div>
                    <?php if ($autoFailoverOn): ?>
                        <span class="badge bg-danger"><i class="bi bi-lightning me-1"></i>ACTIVO</span>
                    <?php endif; ?>
                </div>
                <div class="small text-muted mt-1" style="max-width:600px;">
                    <i class="bi bi-exclamation-triangle text-warning me-1"></i>
                    Promoverá automáticamente este servidor a Master si pierde contacto con el master actual.
                </div>
            <?php elseif ($failoverRole === 'master'): ?>
                <form method="post" action="/settings/cluster/demote" id="form-demote-cluster">
                    <?= View::csrf() ?>
                    <div class="mb-3" style="max-width:400px;">
                        <label class="form-label small">Seleccione el nuevo Master</label>
                        <?php if (!empty($nodes)): ?>
                        <select name="new_master_ip" class="form-select form-select-sm" id="demote-node-select" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($nodes as $n): $nodeHost = parse_url($n['api_url'], PHP_URL_HOST); ?>
                            <option value="<?= View::e($nodeHost) ?>"><?= View::e($n['name']) ?> (<?= View::e($nodeHost) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="text" name="new_master_ip" class="form-control form-control-sm" placeholder="IP" required>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmDemoteCluster()">
                        <i class="bi bi-arrow-down-circle me-1"></i>Degradar a Slave
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
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
            Nodos slave vinculados. Cada hosting nuevo se replica automáticamente. <strong>Sync Todo</strong> re-provisiona todos los hostings existentes en el nodo (usuario Linux, PHP-FPM, Caddy) — útil al añadir un nodo nuevo o para forzar una re-creación. No copia archivos web; para eso vaya a la pestaña <a href="#archivos" style="color:#6ea8fe;">Archivos</a>.
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
                                <th>Servicios</th>
                                <th>Mail Hostname</th>
                                <th class="text-center">Alertas</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="nodes-table-body">
                            <?php foreach ($nodes as $node): ?>
                            <?php $isStandby = !empty($node['standby']); ?>
                            <tr id="node-row-<?= (int)$node['id'] ?>" <?= $isStandby ? 'style="opacity:0.6;"' : '' ?>>
                                <td>
                                    <strong><?= View::e($node['name']) ?></strong>
                                    <?php if ($isStandby): ?>
                                        <br><span class="badge bg-warning text-dark"><i class="bi bi-pause-circle me-1"></i>Standby</span>
                                        <?php if ($node['standby_reason']): ?>
                                            <small class="text-muted d-block"><?= View::e($node['standby_reason']) ?></small>
                                        <?php endif; ?>
                                        <?php if ($node['standby_since']): ?>
                                            <small class="text-muted d-block">Desde: <?= View::e($node['standby_since']) ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><code class="small"><?= View::e($node['api_url']) ?></code></td>
                                <td>
                                    <?php if ($isStandby): ?>
                                        <span class="badge bg-warning text-dark" id="node-status-<?= (int)$node['id'] ?>">
                                            Standby
                                        </span>
                                    <?php else: ?>
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
                                    <?php endif; ?>
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
                                <td id="node-services-<?= (int)$node['id'] ?>">
                                    <?php $nodeServices = json_decode($node['services'] ?? '["web"]', true) ?: ['web']; ?>
                                    <span class="badge <?= in_array('web', $nodeServices) ? 'bg-info' : 'bg-secondary opacity-50' ?> me-1"
                                          style="font-size: 0.7rem; cursor: pointer;"
                                          onclick="toggleNodeService(<?= (int)$node['id'] ?>, 'web')"
                                          title="Click para <?= in_array('web', $nodeServices) ? 'desactivar' : 'activar' ?> web">
                                        <i class="bi bi-globe me-1"></i>web
                                    </span>
                                    <span class="badge <?= in_array('mail', $nodeServices) ? 'bg-success' : 'bg-secondary opacity-50' ?>"
                                          style="font-size: 0.7rem; cursor: pointer;"
                                          onclick="toggleNodeService(<?= (int)$node['id'] ?>, 'mail')"
                                          title="Click para <?= in_array('mail', $nodeServices) ? 'desactivar' : 'activar' ?> mail">
                                        <i class="bi bi-envelope me-1"></i>mail
                                    </span>
                                </td>
                                <td class="small">
                                    <?php if (!empty($node['mail_hostname'])): ?>
                                        <code><?= View::e($node['mail_hostname']) ?></code>
                                    <?php elseif (in_array('mail', $nodeServices)): ?>
                                        <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Sin asignar</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" id="node-alerts-<?= (int)$node['id'] ?>">
                                    <?php if (!empty($node['alerts_muted'])): ?>
                                        <span class="badge bg-secondary"><i class="bi bi-bell-slash me-1"></i>Silenciadas</span>
                                    <?php else: ?>
                                        <span class="badge bg-success"><i class="bi bi-bell-fill me-1"></i>Activas</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex flex-wrap gap-2 justify-content-end">
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
                                    <?php if ($isStandby): ?>
                                    <button type="button" class="btn btn-outline-success btn-sm"
                                            onclick="toggleStandby(<?= (int)$node['id'] ?>, 'deactivate', '<?= View::e($node['name']) ?>')"
                                            title="Reactivar nodo — reanudar sync, cola y alertas">
                                        <i class="bi bi-play-circle me-1"></i>Reactivar
                                    </button>
                                    <?php else: ?>
                                    <button type="button" class="btn btn-outline-warning btn-sm"
                                            onclick="toggleStandby(<?= (int)$node['id'] ?>, 'activate', '<?= View::e($node['name']) ?>')"
                                            title="Poner en standby — pausar sync, cola y alertas">
                                        <i class="bi bi-pause-circle me-1"></i>Standby
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-danger btn-sm"
                                            onclick="confirmRemoveNode(<?= (int)$node['id'] ?>, '<?= View::e($node['name']) ?>')">
                                        <i class="bi bi-trash me-1"></i>Eliminar
                                    </button>
                                    </div>
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

                        <div class="mb-3">
                            <label class="form-label">Servicios del Nodo</label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="services[]" value="web" id="svc-web" checked>
                                    <label class="form-check-label" for="svc-web">
                                        <i class="bi bi-globe me-1 text-info"></i>Web (hostings)
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="services[]" value="mail" id="svc-mail">
                                    <label class="form-check-label" for="svc-mail">
                                        <i class="bi bi-envelope me-1 text-success"></i>Mail
                                    </label>
                                </div>
                            </div>
                            <small class="text-muted">Define que funciones tendra este nodo. Un nodo solo mail no recibira hostings web.</small>
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

                <!-- Sync mode selector -->
                <?php $syncMode = $fsConfig['sync_mode'] ?? 'periodic'; ?>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Modo de sincronización de archivos</label>
                        <select name="filesync_sync_mode" class="form-select" id="filesyncSyncMode" onchange="toggleSyncMode()">
                            <option value="periodic" <?= $syncMode === 'periodic' ? 'selected' : '' ?>>Periódico (rsync cada X min)</option>
                            <option value="lsyncd" <?= $syncMode === 'lsyncd' ? 'selected' : '' ?>>Tiempo real (lsyncd)</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <div class="small mt-4">
                            <span class="text-muted" id="syncModeDesc"></span>
                        </div>
                    </div>
                </div>

                <!-- lsyncd panel (visible when mode=lsyncd) -->
                <?php $lsyncdStatus = \MuseDockPanel\Services\FileSyncService::getLsyncdStatus(); ?>
                <div id="lsyncdPanel" style="<?= $syncMode !== 'lsyncd' ? 'display:none' : '' ?>">
                    <div class="card mb-3" style="background:rgba(13,110,253,0.05);border-color:rgba(13,110,253,0.15);">
                        <div class="card-body py-3">
                            <h6 class="mb-2"><i class="bi bi-lightning-charge me-1"></i>lsyncd — Sincronización en tiempo real</h6>
                            <p class="small text-muted mb-2">
                                lsyncd vigila <code>/var/www/vhosts/</code> y sincroniza cambios al instante via rsync SSH.
                                El intervalo periódico no aplica para archivos, pero sigue activo para SSL, dumps de BD y disk usage.
                            </p>

                            <div class="d-flex align-items-center gap-3 mb-2">
                                <span class="small">
                                    Estado:
                                    <?php if (!$lsyncdStatus['installed']): ?>
                                        <span class="badge bg-secondary">No instalado</span>
                                    <?php elseif ($lsyncdStatus['running']): ?>
                                        <span class="badge bg-success">Activo</span>
                                        <?php if ($lsyncdStatus['pid']): ?>
                                            <span class="text-muted">(PID <?= $lsyncdStatus['pid'] ?>)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Detenido</span>
                                    <?php endif; ?>
                                </span>
                                <span id="lsyncdActionStatus" class="small"></span>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <?php if (!$lsyncdStatus['installed']): ?>
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="lsyncdAction('install')">
                                        <i class="bi bi-download me-1"></i>Instalar lsyncd
                                    </button>
                                <?php else: ?>
                                    <?php if ($lsyncdStatus['running']): ?>
                                        <button type="button" class="btn btn-outline-warning btn-sm" onclick="lsyncdAction('stop')">
                                            <i class="bi bi-stop-circle me-1"></i>Detener
                                        </button>
                                        <button type="button" class="btn btn-outline-info btn-sm" onclick="lsyncdAction('reload')">
                                            <i class="bi bi-arrow-clockwise me-1"></i>Recargar config
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-success btn-sm" onclick="lsyncdAction('start')">
                                            <i class="bi bi-play-circle me-1"></i>Iniciar
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="lsyncdAction('status')">
                                        <i class="bi bi-info-circle me-1"></i>Ver estado
                                    </button>
                                <?php endif; ?>
                            </div>

                            <?php if ($lsyncdStatus['installed'] && $lsyncdStatus['log_tail']): ?>
                            <div class="mt-2">
                                <button class="btn btn-link btn-sm text-muted p-0" type="button" data-bs-toggle="collapse" data-bs-target="#lsyncdLog">
                                    <i class="bi bi-terminal me-1"></i>Ver log
                                </button>
                                <div class="collapse mt-1" id="lsyncdLog">
                                    <pre class="p-2 rounded small" style="background:#1a1a2e;color:#a8d8ea;max-height:200px;overflow:auto;font-size:0.75rem;"><?= htmlspecialchars($lsyncdStatus['log_tail']) ?></pre>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Method selector (for periodic mode or lsyncd SSH config) -->
                <div id="methodSelector" style="<?= $syncMode === 'lsyncd' ? 'display:none' : '' ?>">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Método de transferencia</label>
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
                    <div class="col-md-3" id="intervalField">
                        <label class="form-label">Intervalo (minutos)</label>
                        <input type="number" name="filesync_interval" class="form-control"
                               value="<?= $fsConfig['interval_minutes'] ?>" min="1" max="1440">
                        <small class="text-muted" id="intervalDesc">Cada cuántos minutos sincronizar</small>
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
                        <small class="text-muted">Separados por coma (ej: <code>*.log</code>, <code>node_modules</code>, <code>*.tmp</code>)</small>
                    </div>
                </div>

                <!-- Specific exclusions -->
                <?php
                    $exclusionsList = array_filter(array_map('trim', explode("\n", \MuseDockPanel\Settings::get('filesync_exclusions_list', ''))));
                ?>
                <div class="mb-3">
                    <label class="form-label">Exclusiones específicas</label>
                    <div class="d-flex align-items-start gap-2">
                        <div class="flex-grow-1">
                            <?php if (empty($exclusionsList)): ?>
                                <span class="text-muted small">No hay exclusiones específicas configuradas.</span>
                            <?php else: ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($exclusionsList as $exc): ?>
                                        <span class="badge bg-secondary" style="font-size:0.8rem;">
                                            <i class="bi bi-folder-x me-1"></i><?= View::e($exc) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-outline-info btn-sm" onclick="openExclusionBrowser()">
                            <i class="bi bi-folder2-open me-1"></i>Explorar y excluir
                        </button>
                    </div>
                    <small class="text-muted">Directorios o archivos específicos a excluir de la sincronización.</small>
                </div>

                <hr class="border-secondary">

                <!-- Independent tasks info -->
                <div class="p-2 mb-3 rounded" style="background:rgba(13,110,253,0.06);border:1px solid rgba(13,110,253,0.12);">
                    <small class="text-info">
                        <i class="bi bi-info-circle me-1"></i>
                        Las siguientes opciones (SSL, credenciales, bases de datos) se ejecutan cada intervalo independientemente del modo de archivos.
                        Aunque uses <strong>lsyncd</strong> para archivos en tiempo real, estas tareas siguen ejecutándose de forma periódica.
                    </small>
                </div>

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
                    $pgStreaming = $streamingActive['pg'] ?? false;
                    $mysqlStreaming = $streamingActive['mysql'] ?? false;
                ?>
                <?php if ($pgStreaming && $mysqlStreaming): ?>
                <div class="p-2 mb-3 rounded" style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.15);">
                    <small style="color:#22c55e;">
                        <i class="bi bi-check-circle me-1"></i>
                        <strong>Ambos motores gestionados por <a href="/settings/replication" class="text-success">Replicación Streaming</a>.</strong>
                        PostgreSQL y MySQL se replican en tiempo real. Los dumps periódicos no son necesarios.
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
                        <?php if ($mysqlStreaming): ?>
                        <div class="p-2 rounded" style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.12);">
                            <small style="color:#22c55e;">
                                <i class="bi bi-database-fill me-1"></i>MySQL / MariaDB
                                <br>
                                <i class="bi bi-check-circle me-1"></i>Gestionado por <a href="/settings/replication" class="text-success">Replicación Streaming</a>
                            </small>
                        </div>
                        <?php else: ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="filesync_db_dump_mysql" id="filesyncDbMySQL"
                                   <?= ($settings['filesync_db_dump_mysql'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="filesyncDbMySQL">
                                <i class="bi bi-database-fill me-1"></i>MySQL / MariaDB
                            </label>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <?php if ($pgStreaming): ?>
                        <div class="p-2 rounded" style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.12);">
                            <small style="color:#22c55e;">
                                <i class="bi bi-database me-1"></i>PostgreSQL
                                <br>
                                <i class="bi bi-check-circle me-1"></i>Gestionado por <a href="/settings/replication" class="text-success">Replicación Streaming</a>
                            </small>
                        </div>
                        <?php else: ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="filesync_db_dump_pgsql" id="filesyncDbPgSQL"
                                   <?= ($settings['filesync_db_dump_pgsql'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="filesyncDbPgSQL">
                                <i class="bi bi-database me-1"></i>PostgreSQL (hosting, puerto 5432)
                            </label>
                        </div>
                        <?php endif; ?>
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
                    Los dumps se ejecutan cada intervalo, independientemente del modo de archivos (periódico o lsyncd).
                    La BD del panel (<code>musedock_panel</code>) nunca se sincroniza.
                    Los motores con <a href="/settings/replication" class="text-info">replicación streaming</a> activa
                    se gestionan allí y no necesitan dumps.
                </p>
                <?php endif; ?>

                <hr class="border-secondary">

                <!-- Manual sync buttons -->
                <?php if (!empty($nodes) && $clusterRole === 'master'): ?>
                <h6 class="text-muted mb-2">Sincronización manual de archivos</h6>
                <p class="small text-muted mb-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Fuerza un rsync completo de <code>/var/www/vhosts/</code> al nodo seleccionado de forma inmediata,
                    independientemente del modo configurado (periódico o lsyncd).
                    Respeta los patrones y exclusiones específicas configuradas arriba.
                </p>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php foreach ($nodes as $node): ?>
                    <?php $nodeStandby = !empty($node['standby']); ?>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="syncFilesNow(<?= (int)$node['id'] ?>, '<?= View::e($node['name']) ?>')"
                            <?= $nodeStandby ? 'disabled title="Nodo en standby"' : '' ?>>
                        <i class="bi bi-arrow-repeat me-1"></i>Sync archivos a <?= View::e($node['name']) ?>
                        <?php if ($nodeStandby): ?><span class="badge bg-warning text-dark ms-1">standby</span><?php endif; ?>
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
<!-- TAB 4 — Failover Multi-ISP                                    -->
<!-- ═══════════════════════════════════════════════════════════ -->
<?php
    $fc = $failoverConfig ?? [];
    $fs = $failoverStatus ?? [];
    $foServers = $failoverServers ?? [];
    $foState = $fs['state'] ?? 'normal';
    $foConfigured = !empty($fs['configured']);
    $foIsSlave = ($clusterRole === 'slave');
    $foSyncedAt = \MuseDockPanel\Settings::get('failover_config_synced_at', '');
?>
<div class="tab-pane fade" id="tab-failover" role="tabpanel">

    <?php if ($foIsSlave): ?>
    <!-- Slave: read-only notice -->
    <div class="mb-3 p-3 rounded" style="background:rgba(234,179,8,0.1);border:1px solid rgba(234,179,8,0.3);color:#fbbf24;">
        <i class="bi bi-lock me-2"></i>
        <strong>Modo solo lectura</strong> — La configuración de failover se gestiona desde el servidor Master.
        Este slave recibe la configuración automáticamente.
        <?php if ($foSyncedAt): ?>
            <br><small style="color:#94a3b8;">Última sincronización: <?= \MuseDockPanel\View::e($foSyncedAt) ?></small>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Explicación del sistema ───────────────────────────── -->
    <div class="mb-3 p-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);">
        <small style="color:#6ea8fe;">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Multi-ISP Failover</strong> — Redundancia DNS automática entre múltiples servidores e ISPs.
            La replicación de datos (PostgreSQL, archivos) se gestiona en las pestañas Estado y Configuración.
            Este sistema gestiona <strong>hacia dónde apunta el tráfico</strong> cuando algo cae.
        </small>

        <div class="mt-3 mb-1" style="font-size:.8rem;color:#94a3b8;">
            <strong style="color:#6ea8fe;">¿Cómo funciona?</strong> Añada servidores con cualquier nombre y asígneles un rol:
        </div>
        <div class="ps-3" style="font-size:.78rem;color:#94a3b8;line-height:1.7;">
            <strong class="text-info">Primary</strong> — Servidores principales (VPS, datacenter) con <strong>IP pública fija</strong>. Los registros DNS (A/CNAME) de Cloudflare apuntan aquí en estado normal. Puede añadir tantos como necesite.<br>
            <strong class="text-warning">Failover</strong> — Servidores de respaldo (otro ISP, otra ubicación) con <strong>IP pública fija</strong>. Cuando un Primary cae, Cloudflare cambia los registros DNS para apuntar al Failover asignado. Puede tener varios.<br>
            <strong style="color:#ef4444;">Backup</strong> — Último recurso, solo necesita <strong>una IP</strong> (puede ser dinámica con DynDNS). Este servidor ejecuta caddy-l4 como proxy SNI: recibe todo el tráfico y lo reenvía por nombre de dominio.
            <strong>Configure NAT/port-forwarding en su router</strong> (puertos 80 y 443) hacia este equipo.
        </div>

        <div class="mt-3 mb-1" style="font-size:.8rem;color:#94a3b8;">
            <strong style="color:#6ea8fe;">Flujo de failover:</strong>
        </div>
        <div class="d-flex align-items-stretch gap-0 flex-wrap mt-1" style="font-size:.73rem;">
            <div class="p-2 rounded-start text-center" style="background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);min-width:140px;">
                <div class="fw-bold text-success">NORMAL</div>
                <div style="color:#86efac;">Todos los Primary sirven</div>
                <div style="color:#6b7280;">DNS → Primary servers</div>
            </div>
            <div class="d-flex align-items-center px-1" style="color:#64748b;"><i class="bi bi-chevron-right"></i></div>
            <div class="p-2 text-center" style="background:rgba(234,179,8,0.15);border:1px solid rgba(234,179,8,0.3);min-width:140px;">
                <div class="fw-bold text-warning">DEGRADADO</div>
                <div style="color:#fde68a;">Algún Primary cae</div>
                <div style="color:#6b7280;">DNS caído → su Failover</div>
            </div>
            <div class="d-flex align-items-center px-1" style="color:#64748b;"><i class="bi bi-chevron-right"></i></div>
            <div class="p-2 text-center" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);min-width:140px;">
                <div class="fw-bold" style="color:#f87171;">PRIMARIOS CAÍDOS</div>
                <div style="color:#fca5a5;">Todos los Primary caen</div>
                <div style="color:#6b7280;">DNS → Failover servers</div>
            </div>
            <div class="d-flex align-items-center px-1" style="color:#64748b;"><i class="bi bi-chevron-right"></i></div>
            <div class="p-2 rounded-end text-center" style="background:rgba(239,68,68,0.25);border:1px solid rgba(239,68,68,0.5);min-width:160px;">
                <div class="fw-bold" style="color:#ef4444;"><i class="bi bi-radioactive me-1"></i>EMERGENCIA</div>
                <div style="color:#fca5a5;">Primary + Failover caen</div>
                <div style="color:#6b7280;">DNS → Backup + caddy-l4</div>
            </div>
        </div>
        <div class="mt-2 d-flex align-items-center gap-2" style="font-size:.73rem;color:#64748b;">
            <i class="bi bi-arrow-counterclockwise text-success"></i>
            <span><strong class="text-success">Failback:</strong> Desde cualquier estado se puede restaurar a NORMAL cuando los servidores se recuperan.</span>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- SECCIÓN: Operaciones                                     -->
    <!-- ═══════════════════════════════════════════════════════ -->

    <!-- ── Estado + Health checks ────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-shield-check me-2"></i>Estado del Failover
                <span class="badge bg-secondary ms-2" style="font-size:.65rem;"><?= ucfirst($fc['failover_mode'] ?? 'manual') ?></span>
            </span>
            <span class="badge <?= \MuseDockPanel\Services\FailoverService::stateBadgeClass($foState) ?>" id="fo-state-badge">
                <?= View::e($fs['state_label'] ?? 'Sin configurar') ?>
            </span>
        </div>
        <div class="card-body">
            <?php if (!$foConfigured): ?>
                <?php
                    $foHasPrimary  = !empty(array_filter($foServers, fn($s) => ($s['role'] ?? '') === 'primary'));
                    $foHasFailover = !empty(array_filter($foServers, fn($s) => ($s['role'] ?? '') === 'failover'));
                    $foHasCf       = !empty(\MuseDockPanel\Services\CloudflareService::getConfiguredAccounts());
                    $foIsManual    = ($fc['failover_mode'] ?? 'manual') === 'manual';
                    $foNothingSet  = empty($foServers) && !$foHasCf;
                ?>
                <?php if ($foNothingSet): ?>
                <div class="alert mb-0" style="background:rgba(234,179,8,0.1);border:1px solid rgba(234,179,8,0.3);color:#fbbf24;">
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi bi-shield-exclamation" style="font-size:1.5rem;margin-top:2px;"></i>
                        <div>
                            <strong>Failover no configurado — sin protección ante caídas</strong>
                            <div class="mt-1" style="color:#d4a017;font-size:.85rem;">
                                Tus webs dependen de un solo servidor. Si este servidor cae, todas las webs serán inaccesibles hasta que alguien intervenga manualmente.
                            </div>
                            <div class="mt-2" style="font-size:.82rem;color:#94a3b8;">
                                <strong>Para activar el failover automático necesitas:</strong>
                            </div>
                            <ul class="mb-0 mt-1" style="font-size:.82rem;color:#94a3b8;">
                                <li><i class="bi bi-<?= $foHasPrimary ? 'check-circle text-success' : 'x-circle text-danger' ?> me-1"></i>Al menos un servidor <strong>Primary</strong> (este servidor)</li>
                                <li><i class="bi bi-<?= $foHasFailover ? 'check-circle text-success' : 'x-circle text-danger' ?> me-1"></i>Al menos un servidor <strong>Failover</strong> (el que toma el relevo)</li>
                                <li><i class="bi bi-<?= $foHasCf ? 'check-circle text-success' : 'x-circle text-danger' ?> me-1"></i>Una cuenta de <strong>Cloudflare</strong> con los dominios a proteger</li>
                                <li><i class="bi bi-<?= !$foIsManual ? 'check-circle text-success' : 'dash-circle text-muted' ?> me-1"></i>Modo <strong>Semi-auto</strong> o <strong>Auto</strong> (actualmente: <?= ucfirst($fc['failover_mode'] ?? 'manual') ?>)</li>
                            </ul>
                            <div class="mt-2">
                                <a href="#fo-infra-section" class="btn btn-warning btn-sm" onclick="document.getElementById('fo-infra-section')?.scrollIntoView({behavior:'smooth'})">
                                    <i class="bi bi-plus-circle me-1"></i>Configurar servidores
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert mb-0" style="background:rgba(107,114,128,0.1);border:1px solid rgba(107,114,128,0.3);color:#9ca3af;">
                    <i class="bi bi-info-circle me-2"></i>
                    Configuración incompleta:
                    <?php if (!$foHasPrimary): ?><span class="badge bg-danger me-1">Falta Primary</span><?php endif; ?>
                    <?php if (!$foHasFailover): ?><span class="badge bg-danger me-1">Falta Failover</span><?php endif; ?>
                    <?php if (!$foHasCf): ?><span class="badge bg-warning text-dark me-1">Sin Cloudflare</span><?php endif; ?>
                    — Añada lo necesario en Infraestructura para activar el sistema.
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="row g-3 mb-3">
                    <?php foreach ($foServers as $srv): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="p-3 rounded" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="small"><?= View::e($srv['name'] ?? '') ?></strong>
                                <div>
                                    <span class="badge bg-<?= match($srv['role'] ?? '') { 'primary' => 'info', 'failover' => 'warning text-dark', 'backup' => 'secondary', default => 'secondary' } ?>" style="font-size:.65rem;">
                                        <?= View::e(ucfirst($srv['role'] ?? '')) ?>
                                    </span>
                                    <span class="badge bg-secondary fo-badge" data-key="<?= View::e($srv['id'] ?? '') ?>">--</span>
                                </div>
                            </div>
                            <code class="small text-muted"><?= View::e($srv['ip'] ?? '--') ?><?= ($srv['port'] ?? 443) != 443 ? ':' . $srv['port'] : '' ?></code>
                            <?php if ($srv['dyndns'] ?? false): ?>
                                <span class="badge bg-secondary" style="font-size:.6rem;">DynDNS</span>
                            <?php endif; ?>
                            <div class="small text-muted mt-1 fo-ms" data-key="<?= View::e($srv['id'] ?? '') ?>"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="p-3 rounded" id="caddy-l4-card" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="small">caddy-l4</strong>
                                <span class="badge <?= ($fs['caddy_l4_installed'] ?? false) ? 'bg-success' : 'bg-warning text-dark' ?>" id="caddy-l4-badge">
                                    <?= ($fs['caddy_l4_installed'] ?? false) ? 'Instalado' : 'No instalado' ?>
                                </span>
                            </div>
                            <code class="small text-muted"><?= View::e($fc['failover_caddy_l4_bin'] ?? '/usr/local/bin/caddy-l4') ?></code>
                            <?php if (!($fs['caddy_l4_installed'] ?? false)): ?>
                            <div class="mt-2" id="caddy-l4-install-box">
                                <div class="small mb-2" style="color:#f59e0b;">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    Necesario para modo emergencia (backup con IP dinámica).
                                    Sin caddy-l4, el failover de último recurso no funcionará.
                                </div>
                                <button class="btn btn-warning btn-sm" id="btn-install-caddy-l4" onclick="foInstallCaddyL4()">
                                    <i class="bi bi-download me-1"></i>Instalar caddy-l4
                                </button>
                                <div class="small text-muted mt-1" id="caddy-l4-install-status"></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 align-items-center">
                    <button class="btn btn-outline-info btn-sm" onclick="foCheckHealth()">
                        <i class="bi bi-heart-pulse me-1"></i>Comprobar
                    </button>
                    <span class="small text-muted" id="fo-check-time"></span>
                </div>

                <?php if ($foState !== 'normal'): ?>
                <div class="alert mt-3 mb-0" style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#f87171;">
                    <i class="bi bi-exclamation-octagon me-2"></i>
                    <strong>Failover activo:</strong> <?= View::e($fs['state_label'] ?? '') ?>
                    <?php if ($fs['state_since'] ?? ''): ?> desde <?= View::e($fs['state_since']) ?><?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Acciones manuales (solo master) ──────────────────── -->
    <?php if ($foConfigured && !$foIsSlave): ?>
    <?php
        // Build descriptive names for confirm dialogs
        $foPrimaries = array_filter($foServers, fn($s) => ($s['role'] ?? '') === 'primary');
        $foFailovers = array_filter($foServers, fn($s) => ($s['role'] ?? '') === 'failover');
        $foPrimaryNames = implode(', ', array_map(fn($s) => $s['name'] ?? $s['ip'], $foPrimaries));
        $foFailoverNames = implode(', ', array_map(fn($s) => $s['name'] ?? $s['ip'], $foFailovers));
    ?>
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-lightning me-2"></i>Acciones de Failover</div>
        <div class="card-body">
            <div class="small mb-3 py-2 px-3 rounded" style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.15);color:#94a3b8;">
                <i class="bi bi-exclamation-triangle text-danger me-1"></i>
                <strong style="color:#f87171;">Acción atómica</strong> — Cada botón ejecuta todo de golpe en un solo clic:<br>
                <span class="ms-3">✓ DNS en Cloudflare (redirige tráfico web)</span><br>
                <span class="ms-3">✓ Promote/Demote del cluster (BD acepta escrituras)</span><br>
                <span class="ms-3">✓ Firewall (abre/cierra puertos 80/443)</span><br>
                <span class="ms-3 text-warning">⚠ La sincronización de archivos (lsyncd) no se invierte automáticamente — revisa la pestaña Archivos tras un failover.</span><br>
                <span style="color:#6ea8fe;">Para operaciones quirúrgicas</span> (cambiar solo la BD sin tocar DNS), usa Promote/Demote en la pestaña <strong>Estado</strong>.
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($foState === 'normal'): ?>
                    <button class="btn btn-warning btn-sm" onclick="foExecute('failover_degraded', 'Esto cambiará los DNS de los primarios caídos a sus failover asignados.\n\nPrimarios: <?= View::e($foPrimaryNames) ?>\nFailover: <?= View::e($foFailoverNames) ?>')">
                        <i class="bi bi-exclamation-triangle me-1"></i>Failover Parcial
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="foExecute('failover_primary', 'Esto cambiará TODOS los DNS en Cloudflare y promoverá los slaves a master.\n\n✓ DNS: <?= View::e($foPrimaryNames) ?> → <?= View::e($foFailoverNames) ?>\n✓ Cluster: promote slave a master')">
                        <i class="bi bi-exclamation-octagon me-1"></i>Primarios Caídos
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="foExecute('failover_emergency', 'EMERGENCIA: Todo el tráfico irá al servidor Backup vía caddy-l4.\n\n✓ DNS: todas las IPs → IP backup\n✓ Cluster: promote slave a master\n✓ caddy-l4: activado como proxy SNI')">
                        <i class="bi bi-radioactive me-1"></i>Emergencia
                    </button>
                <?php else: ?>
                    <button class="btn btn-success" onclick="foExecute('failback', 'Esto restaurará los DNS a los primarios y degradará los slaves.\n\n✓ DNS: restaurar IPs originales\n✓ Cluster: demote a slave\n✓ TTL: restaurar a normal')">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Failback a Normal
                    </button>
                    <?php if ($foState !== 'emergency'): ?>
                    <button class="btn btn-danger btn-sm" onclick="foExecute('failover_emergency', 'Escalar a emergencia: todo el tráfico al backup + caddy-l4')">
                        <i class="bi bi-radioactive me-1"></i>Escalar
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div id="fo-action-result" class="mt-3" style="display:none;">
                <pre class="p-3 rounded small" style="background:rgba(0,0,0,0.3);max-height:300px;overflow-y:auto;white-space:pre-wrap;"></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$foIsSlave): ?>
    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- SECCIÓN: Infraestructura (solo master)                    -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <hr class="border-secondary my-4" id="fo-infra-section">
    <h6 class="text-muted mb-3"><i class="bi bi-hdd-network me-2"></i>Infraestructura</h6>

    <!-- ── Servidores (dinámico) ─────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-server me-2"></i>Servidores</span>
            <button type="button" class="btn btn-outline-info btn-sm" onclick="foAddServer()">
                <i class="bi bi-plus me-1"></i>Añadir servidor
            </button>
        </div>
        <div class="card-body">
            <div class="mb-3 p-2 rounded small" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);color:#94a3b8;">
                <i class="bi bi-lightbulb text-warning me-1"></i>
                Añada todos los servidores que participan en el failover. Puede tener <strong>múltiples Primary</strong> y <strong>múltiples Failover</strong>.
                Cada Primary debe tener un Failover asignado en la columna "Failover a" (pueden compartir el mismo Failover).
                El servidor <strong>Backup</strong> solo se usa en emergencia total — es donde caddy-l4 redirige el tráfico por SNI.
                Si el Backup tiene IP dinámica, marque <strong>DynDNS</strong>.
            </div>
            <form method="post" action="/settings/failover/save-servers" id="form-fo-servers">
                <?= View::csrf() ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="fo-servers-table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>IP</th>
                                <th>Puerto</th>
                                <th>Rol</th>
                                <th style="width:70px;" title="Prioridad de election (1=más alta). Solo para Failover.">Prio</th>
                                <th>Failover a</th>
                                <th>DynDNS</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="fo-servers-body">
                            <?php foreach ($foServers as $srv): ?>
                            <tr class="fo-server-row">
                                <td>
                                    <input type="hidden" name="srv_id[]" value="<?= View::e($srv['id'] ?? '') ?>">
                                    <input type="text" name="srv_name[]" class="form-control form-control-sm" value="<?= View::e($srv['name'] ?? '') ?>" required>
                                </td>
                                <td><input type="text" name="srv_ip[]" class="form-control form-control-sm" value="<?= View::e($srv['ip'] ?? '') ?>" placeholder="IP"></td>
                                <td><input type="number" name="srv_port[]" class="form-control form-control-sm" value="<?= (int)($srv['port'] ?? 443) ?>" style="width:80px;"></td>
                                <td>
                                    <select name="srv_role[]" class="form-select form-select-sm">
                                        <option value="primary" <?= ($srv['role'] ?? '') === 'primary' ? 'selected' : '' ?>>Primary</option>
                                        <option value="failover" <?= ($srv['role'] ?? '') === 'failover' ? 'selected' : '' ?>>Failover</option>
                                        <option value="backup" <?= ($srv['role'] ?? '') === 'backup' ? 'selected' : '' ?>>Backup</option>
                                        <option value="replica" <?= ($srv['role'] ?? '') === 'replica' ? 'selected' : '' ?>>Replica</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="srv_priority[]" class="form-control form-control-sm" value="<?= (int)($srv['failover_priority'] ?? 99) ?>" min="1" max="99" style="width:60px;" title="1 = mayor prioridad">
                                </td>
                                <td>
                                    <select name="srv_failover_to[]" class="form-select form-select-sm fo-failover-select">
                                        <option value="">--</option>
                                        <?php foreach ($foServers as $opt): if (($opt['role'] ?? '') !== 'primary'): ?>
                                        <option value="<?= View::e($opt['id'] ?? '') ?>" <?= ($srv['failover_to'] ?? '') === ($opt['id'] ?? '') ? 'selected' : '' ?>>
                                            <?= View::e($opt['name'] ?? '') ?>
                                        </option>
                                        <?php endif; endforeach; ?>
                                    </select>
                                </td>
                                <td class="text-center">
                                    <input type="hidden" name="srv_dyndns[]" value="0">
                                    <input type="checkbox" value="1" class="form-check-input fo-dyndns-check" <?= !empty($srv['dyndns']) ? 'checked' : '' ?>
                                           onchange="this.previousElementSibling.value = this.checked ? '1' : '0'">
                                </td>
                                <td>
                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove()">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (empty($foServers)): ?>
                <div class="text-muted small mt-2 mb-2">
                    <i class="bi bi-info-circle me-1"></i>No hay servidores configurados. Pulse "Añadir servidor" para empezar.
                </div>
                <?php endif; ?>
                <div class="small mt-2 mb-2" style="background:rgba(13,110,253,0.08);border-radius:6px;padding:10px 14px;color:#94a3b8;">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Roles:</strong>
                    <b>Primary</b> = servidor principal (DNS target normal).
                    <b>Failover</b> = slave activo que puede promoverse a master.
                    <b>Backup</b> = último recurso (caddy-l4, IP dinámica).
                    <b>Replica</b> = replica pasiva de BD, nunca se promueve ni sirve tráfico.<br>
                    <i class="bi bi-sort-numeric-up me-1"></i>
                    <strong>Prioridad (Prio):</strong> Solo aplica a servidores <b>Failover</b>. El de menor número (1 = más alta) se promueve primero. Si hay un solo Failover, dejar en 1.
                </div>
                <button type="submit" class="btn btn-success btn-sm mt-2">
                    <i class="bi bi-check-circle me-1"></i>Guardar Servidores
                </button>
            </form>
        </div>
    </div>

    <!-- ── Cuentas Cloudflare ───────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-cloud me-2"></i>Cuentas Cloudflare</span>
            <button type="button" class="btn btn-outline-info btn-sm" onclick="foAddCfAccount()">
                <i class="bi bi-plus me-1"></i>Añadir cuenta
            </button>
        </div>
        <div class="card-body">
            <form method="post" action="/settings/failover/save-cf-accounts" id="form-cf-accounts">
                <?= View::csrf() ?>
                <div id="cf-accounts-list">
                    <?php foreach ($cfAccounts ?? [] as $i => $acct): ?>
                    <div class="cf-account-row border rounded p-3 mb-2" style="border-color:rgba(255,255,255,0.1)!important;">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small">Nombre</label>
                                <input type="text" name="cf_name[]" class="form-control form-control-sm" value="<?= View::e($acct['name'] ?? '') ?>">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small">API Token</label>
                                <div class="input-group input-group-sm">
                                    <input type="password" name="cf_token[]" class="form-control cf-token-input" value="<?= View::e($acct['token'] ?? '') ?>">
                                    <button type="button" class="btn btn-outline-info" onclick="foVerifyCfToken(this)">
                                        <i class="bi bi-check-circle"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <span class="small text-muted cf-zone-info">
                                    <?php if (!empty($acct['zones'])): ?>
                                        <?= count($acct['zones']) ?> zona(s):
                                        <?= View::e(implode(', ', array_column($acct['zones'], 'name'))) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="col-md-1 text-end">
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.cf-account-row').remove()">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($cfAccounts)): ?>
                <div class="text-muted small mb-2">
                    <i class="bi bi-info-circle me-1"></i>Sin cuentas Cloudflare. Añada al menos una para que el failover pueda cambiar DNS automáticamente.
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-success btn-sm mt-2">
                    <i class="bi bi-check-circle me-1"></i>Guardar Cuentas CF
                </button>
            </form>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- SECCIÓN: Ajustes                                         -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <hr class="border-secondary my-4">
    <h6 class="text-muted mb-3"><i class="bi bi-sliders me-2"></i>Ajustes</h6>

    <!-- ── Configuración general ────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-gear me-2"></i>Configuración Failover</div>
        <div class="card-body">
            <form method="post" action="/settings/failover/save-config" id="form-fo-config">
                <?= View::csrf() ?>

                <!-- Modo de operación -->
                <h6 class="text-muted mb-2">Modo de operación</h6>
                <div class="small mb-3 py-2 px-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);color:#94a3b8;">
                    <strong>Manual:</strong> El sistema detecta caídas y muestra el estado, pero el admin pulsa el botón para ejecutar failover/failback.<br>
                    <strong>Semi-auto:</strong> Detecta caídas y envía notificación (email/Telegram) al admin. El admin confirma con un clic.<br>
                    <strong>Auto:</strong> Detecta caídas y ejecuta failover/failback automáticamente sin intervención. Incluye promote/demote del cluster.
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small">Modo</label>
                        <select name="failover_mode" class="form-select form-select-sm">
                            <option value="manual" <?= ($fc['failover_mode'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual</option>
                            <option value="semiauto" <?= ($fc['failover_mode'] ?? '') === 'semiauto' ? 'selected' : '' ?>>Semi-auto</option>
                            <option value="auto" <?= ($fc['failover_mode'] ?? '') === 'auto' ? 'selected' : '' ?>>Automático</option>
                        </select>
                    </div>
                </div>

                <!-- DynDNS -->
                <h6 class="text-muted mb-2 mt-3">DynDNS (solo para servidores Backup con IP dinámica)</h6>
                <div class="small mb-3 py-2 px-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);color:#94a3b8;">
                    Si tienes un servidor Backup con IP dinámica (ej: conexión doméstica), configura aquí el proveedor DynDNS para que el sistema siempre sepa su IP actual.
                    Si todos tus servidores tienen IP fija, déjalo en "Ninguno".
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small">Proveedor DynDNS</label>
                        <select name="failover_dyndns_provider" class="form-select form-select-sm">
                            <option value="" <?= empty($fc['failover_dyndns_provider'] ?? '') ? 'selected' : '' ?>>Ninguno</option>
                            <option value="freedns" <?= ($fc['failover_dyndns_provider'] ?? '') === 'freedns' ? 'selected' : '' ?>>FreeDNS</option>
                            <option value="noip" <?= ($fc['failover_dyndns_provider'] ?? '') === 'noip' ? 'selected' : '' ?>>No-IP</option>
                            <option value="duckdns" <?= ($fc['failover_dyndns_provider'] ?? '') === 'duckdns' ? 'selected' : '' ?>>DuckDNS</option>
                            <option value="dynu" <?= ($fc['failover_dyndns_provider'] ?? '') === 'dynu' ? 'selected' : '' ?>>Dynu</option>
                            <option value="other" <?= ($fc['failover_dyndns_provider'] ?? '') === 'other' ? 'selected' : '' ?>>Otro</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Hostname DynDNS</label>
                        <input type="text" name="failover_dyndns_hostname" class="form-control form-control-sm"
                               value="<?= View::e($fc['failover_dyndns_hostname'] ?? '') ?>" placeholder="host.freedns.org">
                    </div>
                </div>

                <!-- TTL -->
                <h6 class="text-muted mb-2 mt-3">TTL de DNS en Cloudflare</h6>
                <div class="small mb-3 py-2 px-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);color:#94a3b8;">
                    El TTL controla cuánto tiempo los DNS cachean las IPs. En estado normal se usa un TTL alto (menos consultas).
                    Cuando se detecta un problema, el TTL se baja automáticamente para que el cambio de IP se propague rápido.
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-2">
                        <label class="form-label small">TTL Normal</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="failover_ttl_normal" class="form-control form-control-sm" value="<?= (int)($fc['failover_ttl_normal'] ?? 300) ?>">
                            <span class="input-group-text">seg</span>
                        </div>
                        <div class="form-text" style="color:#94a3b8;">Todo OK (def: 300 = 5min)</div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">TTL Alerta</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="failover_ttl_alert" class="form-control form-control-sm" value="<?= (int)($fc['failover_ttl_alert'] ?? 60) ?>">
                            <span class="input-group-text">seg</span>
                        </div>
                        <div class="form-text" style="color:#94a3b8;">Algo va mal (def: 60)</div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">TTL Failover</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="failover_ttl_failover" class="form-control form-control-sm" value="<?= (int)($fc['failover_ttl_failover'] ?? 60) ?>">
                            <span class="input-group-text">seg</span>
                        </div>
                        <div class="form-text" style="color:#94a3b8;">Durante failover (def: 60)</div>
                    </div>
                </div>

                <!-- Health Checks -->
                <h6 class="text-muted mb-2 mt-3">Health Checks</h6>
                <div class="small mb-3 py-2 px-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);color:#94a3b8;">
                    El worker (cron cada minuto) llama a <code>/api/health</code> en cada servidor.
                    Para evitar falsos positivos, un servidor no se marca como caído hasta que falle N veces seguidas.
                    Igualmente, un servidor recuperado necesita M checks OK consecutivos antes de considerarse sano.
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small">Intervalo entre checks</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="failover_check_interval" class="form-control form-control-sm" value="<?= (int)($fc['failover_check_interval'] ?? 60) ?>">
                            <span class="input-group-text">seg</span>
                        </div>
                        <div class="form-text" style="color:#94a3b8;">Cada cuánto se comprueba (def: 60)</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Timeout por servidor</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="failover_check_timeout" class="form-control form-control-sm" value="<?= (int)($fc['failover_check_timeout'] ?? 10) ?>">
                            <span class="input-group-text">seg</span>
                        </div>
                        <div class="form-text" style="color:#94a3b8;">Tiempo máximo de espera (def: 10)</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Fallos para marcar DOWN</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="failover_down_threshold" class="form-control form-control-sm" value="<?= (int)($fc['failover_down_threshold'] ?? 3) ?>" min="1" max="20">
                            <span class="input-group-text">checks</span>
                        </div>
                        <div class="form-text" style="color:#94a3b8;">Ej: 3 = caído tras 3 minutos</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">OK para marcar RECOVERED</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="failover_up_threshold" class="form-control form-control-sm" value="<?= (int)($fc['failover_up_threshold'] ?? 5) ?>" min="1" max="30">
                            <span class="input-group-text">checks</span>
                        </div>
                        <div class="form-text" style="color:#94a3b8;">Ej: 5 = recuperado tras 5 minutos</div>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small">Cooldown post-failback</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="failover_cooldown_minutes" class="form-control form-control-sm" value="<?= (int)($fc['failover_cooldown_minutes'] ?? 15) ?>" min="0" max="120">
                            <span class="input-group-text">min</span>
                        </div>
                        <div class="form-text" style="color:#94a3b8;">Tras un failback, no re-disparar failover durante este tiempo. Evita flapping si el servidor es inestable. (def: 15)</div>
                    </div>
                </div>

                <!-- Umbrales de Severidad -->
                <h6 class="text-muted mb-2 mt-3">Umbrales de disco y carga</h6>
                <div class="small mb-3 py-2 px-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);color:#94a3b8;">
                    <span class="badge bg-danger">Critical</span> = dispara failover (tras N checks consecutivos).
                    <span class="badge bg-warning text-dark">Warning</span> = solo notifica al admin, NO dispara failover.
                    <br>El disco se mide en <strong>% usado</strong>. Ej: 95% = disco casi lleno, solo queda 5% libre.
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small">Disco: failover cuando uso &ge;</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="failover_disk_critical_pct" class="form-control form-control-sm"
                                   value="<?= (int)($fc['failover_disk_critical_pct'] ?? 5) ?>" min="1" max="50"
                                   id="fo_disk_crit_free">
                            <span class="input-group-text">% libre</span>
                        </div>
                        <div class="form-text text-danger" id="fo_disk_crit_text">
                            = disco al <?= 100 - (int)($fc['failover_disk_critical_pct'] ?? 5) ?>% lleno → <strong>failover</strong>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Disco: aviso cuando uso &ge;</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="failover_disk_warning_pct" class="form-control form-control-sm"
                                   value="<?= (int)($fc['failover_disk_warning_pct'] ?? 10) ?>" min="1" max="80"
                                   id="fo_disk_warn_free">
                            <span class="input-group-text">% libre</span>
                        </div>
                        <div class="form-text text-warning" id="fo_disk_warn_text">
                            = disco al <?= 100 - (int)($fc['failover_disk_warning_pct'] ?? 10) ?>% lleno → <strong>notificar</strong>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Carga CPU: failover cuando &gt;</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="failover_load_critical_mult" class="form-control form-control-sm"
                                   value="<?= (float)($fc['failover_load_critical_mult'] ?? 3) ?>" min="1" max="10" step="0.5"
                                   id="fo_load_crit">
                            <span class="input-group-text">× cores</span>
                        </div>
                        <div class="form-text text-danger">
                            Ej: 3× en 8 cores = load &gt; 24 → <strong>failover</strong>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Carga CPU: aviso cuando &gt;</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="failover_load_warning_mult" class="form-control form-control-sm"
                                   value="<?= (float)($fc['failover_load_warning_mult'] ?? 2) ?>" min="1" max="10" step="0.5"
                                   id="fo_load_warn">
                            <span class="input-group-text">× cores</span>
                        </div>
                        <div class="form-text text-warning">
                            Ej: 2× en 8 cores = load &gt; 16 → <strong>notificar</strong>
                        </div>
                    </div>
                </div>

                <!-- Severidad por servicio -->
                <h6 class="text-muted mb-2 mt-3">Severidad por servicio</h6>
                <div class="small mb-3 py-2 px-3 rounded" style="background:rgba(13,110,253,0.08);border:1px solid rgba(13,110,253,0.15);color:#94a3b8;">
                    Cada servicio monitorizado puede configurarse independientemente.
                    <span class="badge bg-danger">Critical</span> = si este servicio cae, se dispara failover (tras N checks).
                    <span class="badge bg-warning text-dark">Warning</span> = si cae, se notifica al admin pero las webs pueden seguir funcionando.
                    <span class="badge bg-secondary">Ignorar</span> = si cae, solo se registra en el log.
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small">Caddy (web server, puerto 443)</label>
                        <select name="failover_caddy_severity" class="form-select form-select-sm">
                            <option value="critical" <?= ($fc['failover_caddy_severity'] ?? 'critical') === 'critical' ? 'selected' : '' ?>>Critical — failover</option>
                            <option value="warning" <?= ($fc['failover_caddy_severity'] ?? '') === 'warning' ? 'selected' : '' ?>>Warning — notificar</option>
                            <option value="ignore" <?= ($fc['failover_caddy_severity'] ?? '') === 'ignore' ? 'selected' : '' ?>>Ignorar</option>
                        </select>
                        <div class="form-text" style="color:#94a3b8;">Caddy sirve todas las webs. Sin él, nada funciona.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">PostgreSQL Hosting (puerto 5432)</label>
                        <select name="failover_pg_hosting_severity" class="form-select form-select-sm">
                            <option value="critical" <?= ($fc['failover_pg_hosting_severity'] ?? 'critical') === 'critical' ? 'selected' : '' ?>>Critical — failover</option>
                            <option value="warning" <?= ($fc['failover_pg_hosting_severity'] ?? '') === 'warning' ? 'selected' : '' ?>>Warning — notificar</option>
                            <option value="ignore" <?= ($fc['failover_pg_hosting_severity'] ?? '') === 'ignore' ? 'selected' : '' ?>>Ignorar</option>
                        </select>
                        <div class="form-text" style="color:#94a3b8;">BD de las webs de clientes (WordPress, etc.).</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">PostgreSQL Panel (puerto 5433)</label>
                        <select name="failover_pg_panel_severity" class="form-select form-select-sm">
                            <option value="warning" <?= ($fc['failover_pg_panel_severity'] ?? 'warning') === 'warning' ? 'selected' : '' ?>>Warning — notificar</option>
                            <option value="critical" <?= ($fc['failover_pg_panel_severity'] ?? '') === 'critical' ? 'selected' : '' ?>>Critical — failover</option>
                            <option value="ignore" <?= ($fc['failover_pg_panel_severity'] ?? '') === 'ignore' ? 'selected' : '' ?>>Ignorar</option>
                        </select>
                        <div class="form-text" style="color:#94a3b8;">Solo afecta al panel admin. Las webs de clientes siguen funcionando sin él.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">MySQL (puerto 3306)</label>
                        <select name="failover_mysql_severity" class="form-select form-select-sm">
                            <option value="warning" <?= ($fc['failover_mysql_severity'] ?? 'warning') === 'warning' ? 'selected' : '' ?>>Warning — notificar</option>
                            <option value="critical" <?= ($fc['failover_mysql_severity'] ?? '') === 'critical' ? 'selected' : '' ?>>Critical — failover</option>
                            <option value="ignore" <?= ($fc['failover_mysql_severity'] ?? '') === 'ignore' ? 'selected' : '' ?>>Ignorar</option>
                        </select>
                        <div class="form-text" style="color:#94a3b8;">BD MySQL de clientes. Si no usas MySQL, ponlo en "Ignorar".</div>
                    </div>
                </div>

                <h6 class="text-muted mb-2 mt-3">
                    <i class="bi bi-ethernet me-1"></i>Detección de interfaces de red (self-check local)
                </h6>
                <div class="small mb-2" style="background:rgba(13,110,253,0.08);border-radius:6px;padding:10px 14px;color:#94a3b8;">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Solo para slaves con dos ISPs.</strong>
                    Si este servidor tiene una conexión principal (ej: ONO, IP fija) y una de backup (ej: Orange, NAT + DynDNS),
                    configura aquí las interfaces para que el failover-worker detecte automáticamente cuándo la principal cae
                    y active caddy-l4 + cambie DNS a la IP dinámica.
                    <strong>Dejar vacío si no aplica a este servidor.</strong>
                </div>
                <?php
                    // Detect physical network interfaces on this server
                    $detectedIfaces = [];
                    $ifaceDir = '/sys/class/net';
                    if (is_dir($ifaceDir)) {
                        foreach (scandir($ifaceDir) as $iface) {
                            if ($iface === '.' || $iface === '..' || $iface === 'lo') continue;
                            $state = trim(@file_get_contents("{$ifaceDir}/{$iface}/operstate") ?: 'unknown');
                            $ipAddr = trim((string)shell_exec("ip -4 addr show " . escapeshellarg($iface) . " 2>/dev/null | grep -oP 'inet \\K[\\d.]+'"));
                            $type = file_exists("{$ifaceDir}/{$iface}/device") ? 'physical' : (str_starts_with($iface, 'wg') ? 'wireguard' : (str_starts_with($iface, 'veth') ? 'virtual' : 'other'));
                            $detectedIfaces[$iface] = ['state' => $state, 'ip' => $ipAddr, 'type' => $type];
                        }
                        // Sort: physical first, then wireguard, then others
                        uksort($detectedIfaces, function($a, $b) use ($detectedIfaces) {
                            $order = ['physical' => 0, 'wireguard' => 1, 'other' => 2, 'virtual' => 3];
                            return ($order[$detectedIfaces[$a]['type']] ?? 9) - ($order[$detectedIfaces[$b]['type']] ?? 9);
                        });
                    }
                    $selectedPrimary = $fc['failover_iface_primary'] ?? '';
                    $selectedBackup = $fc['failover_iface_backup'] ?? '';
                ?>
                <div class="row g-2 mb-2">
                    <div class="col-md-3">
                        <label class="form-label small">Interfaz principal</label>
                        <select name="failover_iface_primary" id="fo-iface-primary" class="form-select form-select-sm font-monospace" onchange="foIfaceChanged(this, 'fo-iface-primary-ip')">
                            <option value="">-- ninguna --</option>
                            <?php foreach ($detectedIfaces as $ifName => $ifInfo): ?>
                            <option value="<?= View::e($ifName) ?>"
                                    data-ip="<?= View::e($ifInfo['ip']) ?>"
                                    data-state="<?= View::e($ifInfo['state']) ?>"
                                    <?= $selectedPrimary === $ifName ? 'selected' : '' ?>>
                                <?= View::e($ifName) ?> — <?= View::e($ifInfo['ip'] ?: 'sin IP') ?> (<?= View::e($ifInfo['state']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" style="color:#94a3b8;">Ethernet del ISP principal (IP fija)</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">IP esperada</label>
                        <input type="text" name="failover_iface_primary_ip" id="fo-iface-primary-ip" class="form-control form-control-sm font-monospace"
                               value="<?= View::e($fc['failover_iface_primary_ip'] ?? '') ?>"
                               placeholder="ej: 213.201.21.154">
                        <div class="form-text" style="color:#94a3b8;">IP que debe tener la interfaz principal</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Interfaz backup</label>
                        <select name="failover_iface_backup" id="fo-iface-backup" class="form-select form-select-sm font-monospace">
                            <option value="">-- ninguna --</option>
                            <?php foreach ($detectedIfaces as $ifName => $ifInfo): ?>
                            <option value="<?= View::e($ifName) ?>"
                                    data-ip="<?= View::e($ifInfo['ip']) ?>"
                                    data-state="<?= View::e($ifInfo['state']) ?>"
                                    <?= $selectedBackup === $ifName ? 'selected' : '' ?>>
                                <?= View::e($ifName) ?> — <?= View::e($ifInfo['ip'] ?: 'sin IP') ?> (<?= View::e($ifInfo['state']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" style="color:#94a3b8;">Conexión de backup (NAT/router)</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Estado / Test</label>
                        <div class="d-flex align-items-center gap-2">
                            <?php $ifMode = $fc['failover_iface_mode'] ?? 'normal'; ?>
                            <span id="fo-iface-status-badge">
                                <?php if ($ifMode === 'nat'): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>NAT</span>
                                <?php elseif ($ifMode === 'isolated'): ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Aislado</span>
                                <?php else: ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Normal</span>
                                <?php endif; ?>
                            </span>
                            <button type="button" class="btn btn-outline-info btn-sm" onclick="foTestIfaces()" title="Verificar interfaces ahora">
                                <i class="bi bi-arrow-repeat"></i> Test
                            </button>
                        </div>
                    </div>
                </div>
                <div id="fo-iface-test-result" class="small mb-3" style="display:none;"></div>

                <h6 class="text-muted mb-2 mt-3">caddy-l4 (modo emergencia)</h6>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Binario caddy-l4</label>
                        <input type="text" name="failover_caddy_l4_bin" class="form-control form-control-sm font-monospace"
                               value="<?= View::e($fc['failover_caddy_l4_bin'] ?? '/usr/local/bin/caddy-l4') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Config caddy-l4</label>
                        <input type="text" name="failover_caddy_l4_conf" class="form-control form-control-sm font-monospace"
                               value="<?= View::e($fc['failover_caddy_l4_conf'] ?? '/etc/caddy/caddy-l4.json') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Puerto normal</label>
                        <input type="number" name="failover_caddy_normal_port" class="form-control form-control-sm"
                               value="<?= (int)($fc['failover_caddy_normal_port'] ?? 443) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Puerto backup</label>
                        <input type="number" name="failover_caddy_backup_port" class="form-control form-control-sm"
                               value="<?= (int)($fc['failover_caddy_backup_port'] ?? 8443) ?>">
                    </div>
                </div>

                <h6 class="text-muted mb-2 mt-3">
                    <i class="bi bi-globe me-1"></i>Dominios remotos (caddy-l4 → otro servidor)
                </h6>
                <div class="small mb-2" style="background:rgba(13,110,253,0.08);border-radius:6px;padding:10px 14px;color:#94a3b8;">
                    <i class="bi bi-info-circle me-1"></i>
                    En modo emergencia, caddy-l4 necesita saber qué dominios redirigir a otros servidores.
                    <strong>Automático:</strong> Añade servidores remotos y sus dominios se obtienen vía <code>/api/domains</code>.
                    <strong>Manual:</strong> Usa el textarea de abajo como backup o suplemento.
                </div>

                <?php $remoteSources = \MuseDockPanel\Services\FailoverService::getRemoteDomainSources(); ?>
                <div class="mb-3">
                    <label class="form-label small"><i class="bi bi-hdd-network me-1"></i>Servidores remotos (automático)</label>
                    <table class="table table-sm align-middle mb-2" id="fo-remote-sources-table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>URL del panel</th>
                                <th>Token API</th>
                                <th style="width:90px;">Dominios</th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody id="fo-remote-sources-body">
                            <?php foreach ($remoteSources as $rs): ?>
                            <tr>
                                <td><input type="text" name="rds_name[]" class="form-control form-control-sm" value="<?= View::e($rs['name'] ?? '') ?>" placeholder="Server-2"></td>
                                <td><input type="text" name="rds_url[]" class="form-control form-control-sm font-monospace" value="<?= View::e($rs['url'] ?? '') ?>" placeholder="https://192.168.2.155:8444"></td>
                                <td><input type="password" name="rds_token[]" class="form-control form-control-sm font-monospace" value="<?= View::e($rs['token'] ?? '') ?>" placeholder="token del servidor remoto"></td>
                                <td class="text-center">
                                    <?php
                                        $cacheKey = 'failover_remote_domains_cache_' . md5($rs['url'] ?? '');
                                        $cached = \MuseDockPanel\Settings::get($cacheKey, '');
                                        $cachedCount = $cached ? count(json_decode($cached, true) ?: []) : 0;
                                    ?>
                                    <span class="badge <?= $cachedCount > 0 ? 'bg-success' : 'bg-secondary' ?>"><?= $cachedCount ?></span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove()">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="foAddRemoteSource()">
                        <i class="bi bi-plus-circle me-1"></i>Añadir servidor remoto
                    </button>
                    <button type="button" class="btn btn-outline-info btn-sm ms-2" onclick="foTestRemoteSources()">
                        <i class="bi bi-arrow-repeat me-1"></i>Probar conexión
                    </button>
                    <span id="fo-remote-test-result" class="small ms-2"></span>
                </div>

                <label class="form-label small"><i class="bi bi-pencil me-1"></i>Dominios manuales (backup / suplemento)</label>
                <textarea name="failover_remote_domains" class="form-control form-control-sm font-monospace mb-3" rows="3"
                          placeholder="dominio1.com&#10;dominio2.com"
                          style="color:#94a3b8;"><?= View::e(\MuseDockPanel\Settings::get('failover_remote_domains', '')) ?></textarea>
                <div class="form-text mb-2" style="color:#6b7280;">Los dominios manuales se combinan con los automáticos. Usa esto si un servidor no tiene panel o como fallback.</div>

                <button type="submit" class="btn btn-success btn-sm">
                    <i class="bi bi-check-circle me-1"></i>Guardar Configuración
                </button>
            </form>
        </div>
    </div>

    <!-- ── Preview caddy-l4 ─────────────────────────────────── -->
    <?php if ($foConfigured): ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-filetype-json me-2"></i>caddy-l4 Config Preview</span>
            <button type="button" class="btn btn-outline-info btn-sm" onclick="foPreviewCaddyL4()">
                <i class="bi bi-eye me-1"></i>Generar preview
            </button>
        </div>
        <div class="card-body" style="display:none;" id="fo-caddy-preview">
            <div class="d-flex gap-3 mb-2">
                <span class="small text-muted">Dominios locales: <strong id="fo-local-count">0</strong></span>
                <span class="small text-muted">Dominios remotos: <strong id="fo-remote-count">0</strong></span>
            </div>
            <pre class="p-3 rounded small" style="background:rgba(0,0,0,0.3);max-height:400px;overflow-y:auto;" id="fo-caddy-json"></pre>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ── Failover JS ──────────────────────────────────────────── -->
<script>
function foCheckHealth() {
    document.querySelectorAll('.fo-badge').forEach(b => { b.textContent = '...'; b.className = 'badge bg-secondary fo-badge'; });
    fetch('/settings/failover/check-health')
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;
            for (const [key, check] of Object.entries(data.checks)) {
                const badge = document.querySelector(`.fo-badge[data-key="${key}"]`);
                const ms = document.querySelector(`.fo-ms[data-key="${key}"]`);
                if (badge) {
                    badge.textContent = check.ok ? 'OK' : 'DOWN';
                    badge.className = `badge fo-badge ${check.ok ? 'bg-success' : 'bg-danger'}`;
                }
                if (ms) ms.textContent = check.ok ? `${check.ms}ms` : (check.error || 'Sin respuesta');
            }
            document.getElementById('fo-check-time').textContent = 'Comprobado: ' + new Date().toLocaleTimeString();

            if (data.mismatch) {
                const labels = {normal:'Normal',degraded:'Degradado',primary_down:'Primarios caídos',emergency:'Emergencia'};
                const msg = `Estado actual: ${labels[data.current]||data.current}. Estado recomendado: ${labels[data.recommended]||data.recommended}`;
                const el = document.getElementById('fo-action-result');
                el.style.display = 'block';
                el.querySelector('pre').textContent = msg;
            }
        })
        .catch(e => console.error('Health check error:', e));
}

function foExecute(action, description) {
    Swal.fire({
        title: 'Confirmar acción',
        html: '<div class="text-start small mb-3" style="white-space:pre-line;color:#94a3b8;">' + description + '</div>' +
              '<input type="password" id="fo-pwd" class="swal2-input" placeholder="Contraseña admin">',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ejecutar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#dc3545',
        background: '#1e1e2e',
        color: '#fff',
        preConfirm: () => {
            const pwd = document.getElementById('fo-pwd').value;
            if (!pwd) { Swal.showValidationMessage('Introduce la contraseña'); return false; }
            return pwd;
        }
    }).then(result => {
        if (!result.isConfirmed) return;
        const el = document.getElementById('fo-action-result');
        el.style.display = 'block';
        el.querySelector('pre').textContent = 'Ejecutando...';

        const fd = new FormData();
        fd.append('action', action);
        fd.append('password', result.value);
        fd.append('_token', document.querySelector('input[name="_token"]').value);

        fetch('/settings/failover/execute', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    el.querySelector('pre').textContent = (data.actions || []).join('\n') || 'OK';
                    Swal.fire({icon:'success', title:'Failover ejecutado', text: data.state || '', timer:3000});
                    setTimeout(() => location.reload(), 3000);
                } else {
                    el.querySelector('pre').textContent = 'ERROR: ' + (data.error || 'Error desconocido');
                    Swal.fire({icon:'error', title:'Error', text: data.error || ''});
                }
            })
            .catch(e => { el.querySelector('pre').textContent = 'Error: ' + e.message; });
    });
}

function foAddCfAccount() {
    const html = `<div class="cf-account-row border rounded p-3 mb-2" style="border-color:rgba(255,255,255,0.1)!important;">
        <div class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label small">Nombre</label>
                <input type="text" name="cf_name[]" class="form-control form-control-sm" placeholder="Mi cuenta CF"></div>
            <div class="col-md-5"><label class="form-label small">API Token</label>
                <div class="input-group input-group-sm">
                    <input type="text" name="cf_token[]" class="form-control cf-token-input" placeholder="Token Cloudflare">
                    <button type="button" class="btn btn-outline-info" onclick="foVerifyCfToken(this)"><i class="bi bi-check-circle"></i></button>
                </div></div>
            <div class="col-md-3"><span class="small text-muted cf-zone-info"></span></div>
            <div class="col-md-1 text-end"><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.cf-account-row').remove()"><i class="bi bi-trash"></i></button></div>
        </div></div>`;
    document.getElementById('cf-accounts-list').insertAdjacentHTML('beforeend', html);
}

function foVerifyCfToken(btn) {
    const row = btn.closest('.cf-account-row');
    const tokenInput = row.querySelector('.cf-token-input');
    const info = row.querySelector('.cf-zone-info');
    info.textContent = 'Verificando...';

    const fd = new FormData();
    fd.append('token', tokenInput.value);
    fd.append('_token', document.querySelector('input[name="_token"]').value);

    fetch('/settings/failover/verify-cf-token', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                info.innerHTML = `<span class="text-success">${data.zones.length} zona(s): ${data.zones.map(z=>z.name).join(', ')}</span>`;
            } else {
                info.innerHTML = `<span class="text-danger">${data.error}</span>`;
            }
        })
        .catch(e => { info.innerHTML = `<span class="text-danger">Error: ${e.message}</span>`; });
}

function foPreviewCaddyL4() {
    const el = document.getElementById('fo-caddy-preview');
    el.style.display = 'block';
    document.getElementById('fo-caddy-json').textContent = 'Generando...';
    fetch('/settings/failover/caddy-l4-preview')
        .then(r => r.json())
        .then(data => {
            document.getElementById('fo-caddy-json').textContent = data.config || 'Error';
            document.getElementById('fo-local-count').textContent = data.local || 0;
            document.getElementById('fo-remote-count').textContent = data.remote || 0;
        });
}

function foAddServer() {
    const html = `<tr class="fo-server-row">
        <td>
            <input type="hidden" name="srv_id[]" value="">
            <input type="text" name="srv_name[]" class="form-control form-control-sm" placeholder="Nombre" required>
        </td>
        <td><input type="text" name="srv_ip[]" class="form-control form-control-sm" placeholder="IP"></td>
        <td><input type="number" name="srv_port[]" class="form-control form-control-sm" value="443" style="width:80px;"></td>
        <td>
            <select name="srv_role[]" class="form-select form-select-sm">
                <option value="primary">Primary</option>
                <option value="failover">Failover</option>
                <option value="backup">Backup</option>
                <option value="replica">Replica</option>
            </select>
        </td>
        <td>
            <input type="number" name="srv_priority[]" class="form-control form-control-sm" value="99" min="1" max="99" style="width:60px;" title="1 = mayor prioridad">
        </td>
        <td>
            <select name="srv_failover_to[]" class="form-select form-select-sm fo-failover-select">
                <option value="">--</option>
            </select>
        </td>
        <td class="text-center">
            <input type="hidden" name="srv_dyndns[]" value="0">
            <input type="checkbox" value="1" class="form-check-input fo-dyndns-check"
                   onchange="this.previousElementSibling.value = this.checked ? '1' : '0'">
        </td>
        <td>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove()">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>`;
    document.getElementById('fo-servers-body').insertAdjacentHTML('beforeend', html);
}

function foInstallCaddyL4() {
    const btn = document.getElementById('btn-install-caddy-l4');
    const status = document.getElementById('caddy-l4-install-status');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Instalando... (puede tardar 1-2 min)';
    status.textContent = 'Compilando caddy con módulo layer4...';

    fetch('/settings/failover/install-caddy-l4', {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(data => {
        if (data.installed) {
            const badge = document.getElementById('caddy-l4-badge');
            badge.className = 'badge bg-success';
            badge.textContent = 'Instalado';
            const box = document.getElementById('caddy-l4-install-box');
            if (box) box.innerHTML = '<div class="small mt-1" style="color:#22c55e;"><i class="bi bi-check-circle me-1"></i>caddy-l4 instalado correctamente.</div>';
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-download me-1"></i>Reintentar instalación';
            status.innerHTML = '<span style="color:#ef4444;">Error en la instalación. Revisa los logs.</span>';
            console.error('caddy-l4 install output:', data.output);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-download me-1"></i>Reintentar';
        status.innerHTML = '<span style="color:#ef4444;">Error de red: ' + err.message + '</span>';
    });
}

// Auto-fill IP when selecting a detected interface
function foIfaceChanged(sel, ipFieldId) {
    const opt = sel.options[sel.selectedIndex];
    const ip = opt?.dataset?.ip || '';
    const ipField = document.getElementById(ipFieldId);
    if (ipField && ip && !ipField.value) {
        ipField.value = ip;
    }
}

// Test interfaces via AJAX
function foTestIfaces() {
    const resultDiv = document.getElementById('fo-iface-test-result');
    const badge = document.getElementById('fo-iface-status-badge');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<span class="text-muted"><i class="bi bi-arrow-repeat spin me-1"></i>Verificando interfaces...</span>';

    fetch('/settings/failover/test-ifaces')
        .then(r => r.json())
        .then(data => {
            let html = '';

            // Show each detected interface
            if (data.interfaces) {
                html += '<div class="p-2 rounded mb-1" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);">';
                html += '<strong class="small">Interfaces detectadas:</strong><br>';
                for (const [name, info] of Object.entries(data.interfaces)) {
                    const stateColor = info.state === 'up' ? '#22c55e' : (info.state === 'down' ? '#ef4444' : '#94a3b8');
                    const icon = info.state === 'up' ? 'check-circle' : (info.state === 'down' ? 'x-circle' : 'question-circle');
                    html += `<span class="me-3"><i class="bi bi-${icon}" style="color:${stateColor}"></i> <code>${name}</code> — ${info.ip || 'sin IP'} <small style="color:${stateColor}">(${info.state})</small></span>`;
                }
                html += '</div>';
            }

            // Show self-check result
            const modeColors = {normal: '#22c55e', nat: '#f59e0b', isolated: '#ef4444'};
            const modeIcons = {normal: 'check-circle', nat: 'exclamation-triangle', isolated: 'x-circle'};
            const color = modeColors[data.mode] || '#94a3b8';
            const icon = modeIcons[data.mode] || 'question-circle';
            html += `<div class="mt-1"><i class="bi bi-${icon}" style="color:${color}"></i> <strong style="color:${color}">${data.details}</strong></div>`;

            if (data.primary_iface && !data.primary_up) {
                html += `<div class="mt-1" style="color:#f59e0b;"><i class="bi bi-exclamation-triangle me-1"></i>Interfaz principal <code>${data.primary_iface}</code> NO tiene la IP esperada o está caída.</div>`;
            }

            resultDiv.innerHTML = html;

            // Update badge
            if (data.mode === 'nat') {
                badge.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>NAT</span>';
            } else if (data.mode === 'isolated') {
                badge.innerHTML = '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Aislado</span>';
            } else {
                badge.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Normal</span>';
            }
        })
        .catch(err => {
            resultDiv.innerHTML = `<span style="color:#ef4444;">Error: ${err.message}</span>`;
        });
}

function foAddRemoteSource() {
    const html = `<tr>
        <td><input type="text" name="rds_name[]" class="form-control form-control-sm" placeholder="Server-2"></td>
        <td><input type="text" name="rds_url[]" class="form-control form-control-sm font-monospace" placeholder="https://192.168.2.155:8444"></td>
        <td><input type="password" name="rds_token[]" class="form-control form-control-sm font-monospace" placeholder="token del servidor remoto"></td>
        <td class="text-center"><span class="badge bg-secondary">--</span></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
    </tr>`;
    document.getElementById('fo-remote-sources-body').insertAdjacentHTML('beforeend', html);
}

function foTestRemoteSources() {
    const result = document.getElementById('fo-remote-test-result');
    const rows = document.querySelectorAll('#fo-remote-sources-body tr');
    if (!rows.length) {
        result.innerHTML = '<span style="color:#94a3b8;">No hay servidores remotos configurados.</span>';
        return;
    }
    result.innerHTML = '<span class="text-muted"><i class="bi bi-arrow-repeat spin me-1"></i>Probando...</span>';

    const sources = [];
    rows.forEach(row => {
        const inputs = row.querySelectorAll('input');
        sources.push({
            name: inputs[0]?.value || '',
            url: inputs[1]?.value || '',
            token: inputs[2]?.value || ''
        });
    });

    fetch('/settings/failover/test-remote-sources', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify({sources})
    })
    .then(r => r.json())
    .then(data => {
        if (data.results) {
            let html = '';
            const badges = document.querySelectorAll('#fo-remote-sources-body tr td:nth-child(4) .badge');
            data.results.forEach((r, i) => {
                const icon = r.ok ? '✓' : '✗';
                const color = r.ok ? '#22c55e' : '#ef4444';
                html += `<span style="color:${color}" class="me-2">${icon} ${r.name}: ${r.ok ? r.count + ' dominios' : r.error}</span>`;
                if (badges[i]) {
                    badges[i].className = r.ok ? 'badge bg-success' : 'badge bg-danger';
                    badges[i].textContent = r.ok ? r.count : '✗';
                }
            });
            result.innerHTML = html;
        }
    })
    .catch(err => {
        result.innerHTML = `<span style="color:#ef4444;">Error: ${err.message}</span>`;
    });
}
</script>
<?php endif; /* !$foIsSlave */ ?>

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
                    <input type="hidden" name="type" value="completed">
                    <button type="button" class="btn btn-outline-warning btn-sm" onclick="confirmCleanQueue(this.closest('form'), 'completados')">
                        <i class="bi bi-trash me-1"></i>Limpiar Completados
                    </button>
                </form>
                <form method="post" action="/settings/cluster/clean-queue" class="d-inline">
                    <?= View::csrf() ?>
                    <input type="hidden" name="type" value="failed">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmCleanQueue(this.closest('form'), 'fallidos')">
                        <i class="bi bi-trash me-1"></i>Limpiar Fallidos
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

    // Internal links like href="#archivos" switch to that tab
    document.addEventListener('click', function(e) {
        var link = e.target.closest('a[href^="#"]');
        if (!link) return;
        var target = link.getAttribute('href').replace('#', '');
        var tab = document.querySelector('[data-bs-target="#tab-' + target + '"]');
        if (tab) {
            e.preventDefault();
            new bootstrap.Tab(tab).show();
        }
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

function toggleNodeService(nodeId, service, confirmed) {
    var form = new FormData();
    form.append('_csrf_token', '<?= View::csrfToken() ?>');
    form.append('node_id', nodeId);
    form.append('service', service);
    if (confirmed) form.append('confirmed', '1');

    fetch('/settings/cluster/toggle-node-service', { method: 'POST', body: form })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                updateServiceBadges(nodeId, data.services);
            } else if (data.confirm_required) {
                var details = '<div class="text-start">' +
                    '<p><strong>' + data.message + '</strong></p>' +
                    '<div class="rounded p-3 mb-3" style="background:rgba(220,53,69,0.1);border:1px solid rgba(220,53,69,0.3);">' +
                    '<p class="mb-1"><strong>¿Que pasa al desactivar?</strong></p>' +
                    '<ul class="mb-0 small">' +
                    '<li>El nodo <strong>deja de recibir</strong> nuevas acciones de ' + data.service + '</li>' +
                    '<li>Los datos y servicios existentes <strong>no se borran</strong> — siguen funcionando</li>' +
                    '<li>La replica de PostgreSQL <strong>no se toca</strong> — es independiente</li>' +
                    '<li>Las acciones perdidas durante la pausa <strong>no se recuperan automaticamente</strong></li>' +
                    '</ul></div>' +
                    '<p class="small text-muted mb-0">' +
                    (data.service === 'web'
                        ? 'Si reactivas mas tarde, usa <strong>Sync Todo</strong> para recuperar los hostings que falten.'
                        : 'Si reactivas mas tarde, los nuevos dominios/cuentas se enviaran normalmente. Los creados durante la pausa habra que reenviarlos.') +
                    '</p></div>';
                Swal.fire({
                    title: 'Desactivar ' + data.service,
                    html: details,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Si, desactivar',
                    confirmButtonColor: '#dc3545',
                    cancelButtonText: 'Cancelar',
                    background: '#1e1e2e',
                    color: '#fff',
                    width: 520
                }).then(function(result) {
                    if (result.isConfirmed) toggleNodeService(nodeId, service, true);
                });
            } else if (data.setup_required) {
                Swal.fire({
                    title: 'Mail no configurado',
                    html: '<p>' + data.error + '</p>' +
                          '<a href="/mail?setup=1" class="btn btn-primary btn-sm mt-2">' +
                          '<i class="bi bi-gear me-1"></i>Ir a configurar mail</a>',
                    icon: 'info',
                    showConfirmButton: false,
                    showCancelButton: true,
                    cancelButtonText: 'Cerrar',
                    background: '#1e1e2e',
                    color: '#fff'
                });
            } else {
                alert('Error: ' + (data.error || 'Error desconocido'));
            }
        })
        .catch(function(err) { alert('Error: ' + err.message); });
}

function updateServiceBadges(nodeId, services) {
    var td = document.getElementById('node-services-' + nodeId);
    if (!td) return;
    var html = '';
    ['web', 'mail'].forEach(function(svc) {
        var active = services.indexOf(svc) !== -1;
        var color = svc === 'web' ? (active ? 'bg-info' : 'bg-secondary opacity-50') : (active ? 'bg-success' : 'bg-secondary opacity-50');
        var icon = svc === 'web' ? 'bi-globe' : 'bi-envelope';
        html += '<span class="badge ' + color + (svc === 'web' ? ' me-1' : '') + '" style="font-size:0.7rem;cursor:pointer;" ' +
                'onclick="toggleNodeService(' + nodeId + ',\'' + svc + '\')" ' +
                'title="Click para ' + (active ? 'desactivar' : 'activar') + ' ' + svc + '">' +
                '<i class="bi ' + icon + ' me-1"></i>' + svc + '</span>';
    });
    td.innerHTML = html;
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

function toggleStandby(nodeId, action, nodeName) {
    var isActivate = action === 'activate';

    Swal.fire({
        title: isActivate ? 'Poner en Standby' : 'Reactivar nodo',
        html: isActivate
            ? '<p>Poner el nodo <strong>' + nodeName + '</strong> en modo standby?</p>' +
              '<p class="small text-muted mb-2">Se pausarán: sincronización de archivos, cola del cluster, dumps de BD, SSL, disk usage y alertas.</p>' +
              '<div class="mb-3"><label class="form-label small">Motivo (opcional)</label>' +
              '<input type="text" id="standbyReason" class="form-control" placeholder="Ej: Reparación hardware, migración..." style="background:#2a2a3e;color:#fff;border-color:#444;"></div>' +
              '<div class="mb-2"><label class="form-label small">Contraseña de administrador</label>' +
              '<input type="password" id="standbyPassword" class="form-control" placeholder="Confirmar con tu contraseña" style="background:#2a2a3e;color:#fff;border-color:#444;"></div>'
            : '<p>Reactivar el nodo <strong>' + nodeName + '</strong>?</p>' +
              '<p class="small text-muted">Se reanudarán: sincronización, cola, alertas y todas las operaciones.</p>' +
              '<div class="mb-2"><label class="form-label small">Contraseña de administrador</label>' +
              '<input type="password" id="standbyPassword" class="form-control" placeholder="Confirmar con tu contraseña" style="background:#2a2a3e;color:#fff;border-color:#444;"></div>',
        icon: isActivate ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: isActivate ? 'Activar Standby' : 'Reactivar',
        confirmButtonColor: isActivate ? '#ffc107' : '#22c55e',
        cancelButtonText: 'Cancelar',
        background: '#1e1e2e',
        color: '#fff',
        preConfirm: function() {
            var pw = document.getElementById('standbyPassword').value;
            if (!pw) {
                Swal.showValidationMessage('La contraseña es obligatoria');
                return false;
            }
            return {
                password: pw,
                reason: document.getElementById('standbyReason') ? document.getElementById('standbyReason').value : ''
            };
        }
    }).then(function(result) {
        if (!result.isConfirmed) return;

        var form = new FormData();
        form.append('node_id', nodeId);
        form.append('action', action);
        form.append('admin_password', result.value.password);
        form.append('reason', result.value.reason || '');
        form.append('_csrf_token', document.querySelector('[name=_csrf_token]').value);

        fetch('/settings/cluster/node-standby', { method: 'POST', body: form })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            Swal.fire({
                title: data.ok ? 'OK' : 'Error',
                text: data.message || data.error,
                icon: data.ok ? 'success' : 'error',
                timer: 2500,
                background: '#1e1e2e',
                color: '#fff',
            }).then(function() { if (data.ok) location.reload(); });
        })
        .catch(function(e) {
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
                            // Save the setting
                            const sf = new FormData();
                            sf.append('key', 'cluster_auto_failover');
                            sf.append('value', '1');
                            sf.append('_csrf_token', document.querySelector('[name=_csrf_token]').value);
                            fetch('/settings/cluster/save-setting', { method: 'POST', body: sf });
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
        const sf = new FormData();
        sf.append('key', 'cluster_auto_failover');
        sf.append('value', '0');
        sf.append('_csrf_token', document.querySelector('[name=_csrf_token]').value);
        fetch('/settings/cluster/save-setting', { method: 'POST', body: sf });
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

function confirmCleanQueue(form, tipo) {
    Swal.fire({
        title: 'Limpiar ' + tipo,
        text: 'Se eliminarán todos los elementos ' + tipo + ' de la cola. ¿Continuar?',
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

// ─── Sync mode toggle ──────────────────────────────────
function toggleSyncMode() {
    var mode = document.getElementById('filesyncSyncMode').value;
    var lsyncdPanel = document.getElementById('lsyncdPanel');
    var methodSelector = document.getElementById('methodSelector');
    var intervalField = document.getElementById('intervalField');
    var intervalDesc = document.getElementById('intervalDesc');
    var descEl = document.getElementById('syncModeDesc');

    if (mode === 'lsyncd') {
        lsyncdPanel.style.display = '';
        methodSelector.style.display = 'none';
        intervalDesc.textContent = 'Solo para SSL, dumps de BD y disk usage';
        descEl.innerHTML = '<i class="bi bi-lightning-charge text-info me-1"></i>Los archivos se sincronizan en tiempo real. El intervalo solo aplica a SSL, dumps y disk usage.';
    } else {
        lsyncdPanel.style.display = 'none';
        methodSelector.style.display = '';
        intervalDesc.textContent = 'Cada cuántos minutos sincronizar';
        descEl.innerHTML = 'rsync periódico cada X minutos. Archivos, SSL, dumps y disk usage se sincronizan en el mismo ciclo.';
    }
}

function lsyncdAction(action) {
    var csrf = document.querySelector('[name=_csrf_token]').value;
    var statusEl = document.getElementById('lsyncdActionStatus');

    if (action === 'status') {
        statusEl.innerHTML = '<span class="text-info"><i class="bi bi-hourglass-split me-1"></i>Consultando...</span>';
        fetch('/settings/cluster/lsyncd-status')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var html = '<strong>Instalado:</strong> ' + (data.installed ? 'Si' : 'No');
            if (data.installed) {
                html += ' | <strong>Activo:</strong> ' + (data.running ? 'Si' : 'No');
                if (data.pid) html += ' (PID ' + data.pid + ')';
                html += ' | <strong>Habilitado:</strong> ' + (data.enabled ? 'Si' : 'No');
            }
            statusEl.innerHTML = '<span class="text-info">' + html + '</span>';
            if (data.log_tail) {
                var logEl = document.getElementById('lsyncdLog');
                if (logEl) {
                    logEl.querySelector('pre').textContent = data.log_tail;
                }
            }
        })
        .catch(function() {
            statusEl.innerHTML = '<span class="text-danger">Error de conexion</span>';
        });
        return;
    }

    var labels = { install: 'Instalando lsyncd...', start: 'Iniciando lsyncd...', stop: 'Deteniendo lsyncd...', reload: 'Recargando config...' };
    statusEl.innerHTML = '<span class="text-warning"><i class="bi bi-hourglass-split me-1"></i>' + (labels[action] || 'Procesando...') + '</span>';

    var formData = new FormData();
    formData.append('_csrf_token', csrf);

    fetch('/settings/cluster/lsyncd-' + action, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            statusEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>OK</span>';
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            statusEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + (data.error || 'Error') + '</span>';
        }
    })
    .catch(function() {
        statusEl.innerHTML = '<span class="text-danger">Error de conexion</span>';
    });
}

// Init sync mode UI on page load
document.addEventListener('DOMContentLoaded', function() { toggleSyncMode(); });

// ─── Exclusion Browser ──────────────────────────────────
var _exclusionSet = new Set();

function openExclusionBrowser() {
    // Load current exclusions
    fetch('/settings/cluster/browse-vhosts')
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.ok) { alert(data.error); return; }
        _exclusionSet = new Set(data.exclusions || []);
        _showExclusionModal(data, '');
    });
}

function _showExclusionModal(data, currentPath) {
    var breadcrumb = '<span class="text-info" style="cursor:pointer;" onclick="browseExclusionPath(\'\')">/var/www/vhosts/</span>';
    if (currentPath) {
        var parts = currentPath.split('/');
        var accumulated = '';
        parts.forEach(function(part, i) {
            accumulated += (i > 0 ? '/' : '') + part;
            var accCopy = accumulated;
            breadcrumb += '<span class="text-info" style="cursor:pointer;" onclick="browseExclusionPath(\'' + accCopy + '\')">' + part + '/</span>';
        });
    }

    var tableRows = '';
    data.items.forEach(function(item) {
        var icon = item.is_dir ? '<i class="bi bi-folder-fill text-warning me-1"></i>' : '<i class="bi bi-file-earmark me-1"></i>';
        var checked = _exclusionSet.has(item.path) ? 'checked' : '';
        var nameHtml = item.is_dir
            ? '<a href="#" onclick="browseExclusionPath(\'' + item.path + '\');return false;" class="text-info text-decoration-none">' + icon + item.name + '</a>'
            : icon + '<span class="text-light">' + item.name + '</span>';
        var sizeStr = item.is_dir ? '' : _formatSize(item.size);

        tableRows += '<tr>' +
            '<td style="width:40px;"><input type="checkbox" class="form-check-input excl-check" data-path="' + item.path + '" ' + checked + ' onchange="toggleExclusion(this)"></td>' +
            '<td>' + nameHtml + '</td>' +
            '<td class="text-end text-muted small">' + sizeStr + '</td>' +
            '</tr>';
    });

    var countBadge = _exclusionSet.size > 0 ? ' <span class="badge bg-warning text-dark">' + _exclusionSet.size + ' excluidos</span>' : '';

    Swal.fire({
        title: 'Explorar /var/www/vhosts/',
        html: '<div class="text-start">' +
              '<div class="mb-2 small" style="word-break:break-all;">' + breadcrumb + countBadge + '</div>' +
              '<div style="max-height:400px;overflow:auto;">' +
              '<table class="table table-sm table-dark mb-0" style="font-size:0.85rem;">' +
              '<thead><tr><th style="width:40px;"></th><th>Nombre</th><th class="text-end" style="width:80px;">Tamaño</th></tr></thead>' +
              '<tbody>' + tableRows + '</tbody>' +
              '</table>' +
              '</div>' +
              '</div>',
        width: 650,
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-check-circle me-1"></i>Guardar exclusiones',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#22c55e',
        background: '#1e1e2e',
        color: '#fff',
    }).then(function(result) {
        if (!result.isConfirmed) return;
        _saveExclusions();
    });
}

function browseExclusionPath(path) {
    fetch('/settings/cluster/browse-vhosts?path=' + encodeURIComponent(path))
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.ok) { alert(data.error); return; }
        _showExclusionModal(data, path);
    });
}

function toggleExclusion(checkbox) {
    var path = checkbox.getAttribute('data-path');
    if (checkbox.checked) {
        _exclusionSet.add(path);
    } else {
        _exclusionSet.delete(path);
    }
}

function _saveExclusions() {
    var csrf = document.querySelector('[name=_csrf_token]').value;
    var exclusions = Array.from(_exclusionSet).join("\n");

    var form = new FormData();
    form.append('_csrf_token', csrf);
    form.append('exclusions', exclusions);

    fetch('/settings/cluster/save-exclusions', { method: 'POST', body: form })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            Swal.fire({ title: 'Guardado', text: 'Exclusiones actualizadas', icon: 'success', timer: 1500, background: '#1e1e2e', color: '#fff' })
            .then(function() { location.reload(); });
        } else {
            Swal.fire({ title: 'Error', text: data.error || 'Error', icon: 'error', background: '#1e1e2e', color: '#fff' });
        }
    });
}

function _formatSize(bytes) {
    if (bytes === 0) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
    return (bytes / 1073741824).toFixed(1) + ' GB';
}

let _syncPollTimer = null;
let _syncStartTime = null;

function syncFilesNow(nodeId, nodeName) {
    const csrf = document.querySelector('[name=_csrf_token]').value;

    Swal.fire({
        title: 'Sincronizar archivos a ' + nodeName,
        html: '<p>Se sincronizará <strong>todo /var/www/vhosts/</strong> al nodo seleccionado de forma inmediata.</p>' +
              '<p><i class="bi bi-arrow-repeat me-1"></i>Usa rsync incremental — solo copia archivos nuevos o modificados.</p>' +
              '<p class="small text-muted"><i class="bi bi-funnel me-1"></i>Respeta los patrones y exclusiones específicas configuradas.</p>' +
              '<p class="small text-muted"><i class="bi bi-lightning-charge me-1"></i>Funciona independientemente del modo (periódico o lsyncd).</p>' +
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
              '<li><strong>Hostings (API)</strong> — Crear/reparar cuentas de sistema, PHP-FPM, Caddy en el nodo</li>' +
              '<li><strong>Archivos (rsync vía SSH)</strong> — Copiar contenido web de /var/www/vhosts/ al slave</li>' +
              '<li><strong>SSL (rsync vía SSH)</strong> — Copiar certificados de Caddy al slave</li>' +
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
