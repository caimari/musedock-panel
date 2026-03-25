<?php use MuseDockPanel\View; ?>
<?= View::csrf() ?>

<!-- Offline / Standby Nodes Alert Banner -->
<?php if (!empty($offlineNodes)):
    $downNodes = array_filter($offlineNodes, fn($n) => empty($n['standby']));
    $standbyNodes = array_filter($offlineNodes, fn($n) => !empty($n['standby']));
    $hasDown = count($downNodes) > 0;
    $hasStandby = count($standbyNodes) > 0;
    // Banner color: red if any truly down, yellow/warning if only standby
    $bannerBg = $hasDown ? 'danger' : 'warning';
    $bannerText = $hasDown ? 'text-white' : 'text-dark';
?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card border-<?= $bannerBg ?>">
            <div class="card-header bg-<?= $bannerBg ?> <?= $bannerText ?> d-flex justify-content-between align-items-center py-2">
                <span>
                    <?php if ($hasDown): ?>
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong><?= count($downNodes) ?> nodo<?= count($downNodes) > 1 ? 's' : '' ?> caido<?= count($downNodes) > 1 ? 's' : '' ?></strong>
                        <?php if ($hasStandby): ?>
                            <span class="ms-2" style="opacity:0.8;">| <?= count($standbyNodes) ?> en standby</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <i class="bi bi-pause-circle-fill me-2"></i>
                        <strong><?= count($standbyNodes) ?> nodo<?= count($standbyNodes) > 1 ? 's' : '' ?> en standby</strong>
                    <?php endif; ?>
                </span>
                <a href="/settings/cluster#nodos" class="btn btn-outline-<?= $hasDown ? 'light' : 'dark' ?> btn-sm py-0">
                    <i class="bi bi-gear me-1"></i>Cluster
                </a>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Nodo</th>
                            <th>URL</th>
                            <th>Ultimo contacto</th>
                            <th>Estado</th>
                            <th class="text-center">Alertas</th>
                            <th class="text-end pe-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($offlineNodes as $oNode):
                            $isStandby = !empty($oNode['standby']);
                        ?>
                        <tr>
                            <td class="ps-3">
                                <?php if ($isStandby): ?>
                                    <i class="bi bi-pause-circle-fill text-warning me-1" style="font-size: 0.6rem; vertical-align: middle;"></i>
                                <?php else: ?>
                                    <i class="bi bi-circle-fill text-danger me-1" style="font-size: 0.5rem; vertical-align: middle;"></i>
                                <?php endif; ?>
                                <strong><?= View::e($oNode['name']) ?></strong>
                            </td>
                            <td><code class="small"><?= View::e($oNode['api_url']) ?></code></td>
                            <td><small class="text-muted"><?= View::e($oNode['last_seen_at']) ?></small></td>
                            <td>
                                <?php if ($isStandby): ?>
                                    <?php
                                        $standbyTime = '';
                                        if (!empty($oNode['standby_since'])) {
                                            $sm = round((time() - strtotime($oNode['standby_since'])) / 60);
                                            if ($sm >= 1440) {
                                                $standbyTime = ' · ' . round($sm / 1440, 1) . 'd';
                                            } elseif ($sm >= 60) {
                                                $standbyTime = ' · ' . round($sm / 60, 1) . 'h';
                                            } else {
                                                $standbyTime = ' · ' . $sm . 'min';
                                            }
                                        }
                                    ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-pause-circle me-1"></i>Standby<?= $standbyTime ?></span>
                                    <?php if ($oNode['standby_reason']): ?>
                                        <small class="text-muted ms-1"><?= View::e($oNode['standby_reason']) ?></small>
                                    <?php endif; ?>
                                <?php elseif ($oNode['down_minutes'] !== null): ?>
                                    <?php
                                        $dm = $oNode['down_minutes'];
                                        if ($dm >= 1440) {
                                            $downLabel = round($dm / 1440, 1) . ' dias';
                                        } elseif ($dm >= 60) {
                                            $downLabel = round($dm / 60, 1) . ' horas';
                                        } else {
                                            $downLabel = $dm . ' min';
                                        }
                                    ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Caido <?= $downLabel ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Nunca visto</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($isStandby): ?>
                                    <span class="badge bg-secondary"><i class="bi bi-bell-slash me-1"></i>Pausadas</span>
                                <?php elseif ($oNode['muted']): ?>
                                    <span class="badge bg-secondary"><i class="bi bi-bell-slash me-1"></i>Silenciadas</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-bell-fill me-1"></i>Activas</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <?php if ($isStandby): ?>
                                    <button class="btn btn-outline-success btn-sm py-0 px-2" onclick="reactivateNode(<?= $oNode['id'] ?>, '<?= View::e($oNode['name']) ?>')" title="Reactivar nodo">
                                        <i class="bi bi-play-fill me-1"></i>Reactivar
                                    </button>
                                <?php elseif ($oNode['muted']): ?>
                                    <button class="btn btn-outline-warning btn-sm py-0 px-2" onclick="toggleNodeAlerts(<?= $oNode['id'] ?>, 'unmute', '<?= View::e($oNode['name']) ?>')" title="Reactivar alertas">
                                        <i class="bi bi-bell me-1"></i>Reactivar alertas
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-outline-secondary btn-sm py-0 px-2" onclick="toggleNodeAlerts(<?= $oNode['id'] ?>, 'mute', '<?= View::e($oNode['name']) ?>')" title="Silenciar alertas">
                                        <i class="bi bi-bell-slash me-1"></i>Silenciar
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- System Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3 d-flex">
        <div class="stat-card w-100 d-flex flex-column" role="button" onclick="openProcessModal('cpu')" title="Ver procesos por CPU" style="cursor:pointer">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value"><?= $stats['cpu']['percent'] ?>%</div>
                    <div class="stat-label">CPU (<?= $stats['cpu']['cores'] ?> cores)</div>
                </div>
                <i class="bi bi-cpu stat-icon"></i>
            </div>
            <div class="progress mt-2"><div class="progress-bar bg-info" style="width: <?= $stats['cpu']['percent'] ?>%"></div></div>
            <small class="text-muted">Load: <?= $stats['cpu']['load_1'] ?> / <?= $stats['cpu']['load_5'] ?> / <?= $stats['cpu']['load_15'] ?></small>
            <div class="text-end mt-auto"><small class="text-muted"><i class="bi bi-eye"></i> Click para ver procesos</small></div>
        </div>
    </div>
    <div class="col-md-3 d-flex">
        <div class="stat-card w-100 d-flex flex-column" role="button" onclick="openProcessModal('ram')" title="Ver procesos por RAM" style="cursor:pointer">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value"><?= $stats['memory']['percent'] ?>%</div>
                    <div class="stat-label">RAM (<?= $stats['memory']['used_gb'] ?> / <?= $stats['memory']['total_gb'] ?> GB)</div>
                </div>
                <i class="bi bi-memory stat-icon"></i>
            </div>
            <div class="progress mt-2"><div class="progress-bar bg-warning" style="width: <?= $stats['memory']['percent'] ?>%"></div></div>
            <div class="text-end mt-auto"><small class="text-muted"><i class="bi bi-eye"></i> Click para ver procesos</small></div>
        </div>
    </div>
    <div class="col-md-3 d-flex">
        <div class="stat-card w-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value"><?= $stats['disk']['percent'] ?>%</div>
                    <div class="stat-label">Disk (<?= $stats['disk']['used_gb'] ?> / <?= $stats['disk']['total_gb'] ?> GB)</div>
                </div>
                <i class="bi bi-hdd stat-icon"></i>
            </div>
            <div class="progress mt-2"><div class="progress-bar bg-<?= $stats['disk']['percent'] > 85 ? 'danger' : 'success' ?>" style="width: <?= $stats['disk']['percent'] ?>%"></div></div>
            <small class="text-muted"><?= $stats['disk']['free_gb'] ?> GB free</small>
        </div>
    </div>
    <div class="col-md-3 d-flex">
        <div class="stat-card w-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value"><?= $accounts['total'] ?? 0 ?></div>
                    <div class="stat-label">Hosting Accounts</div>
                </div>
                <i class="bi bi-server stat-icon"></i>
            </div>
            <div class="mt-auto pt-2">
                <span class="badge badge-active"><?= $accounts['active'] ?? 0 ?> active</span>
                <?php if (($accounts['suspended'] ?? 0) > 0): ?>
                    <span class="badge badge-suspended"><?= $accounts['suspended'] ?> suspended</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Process Modal -->
<div class="modal fade" id="processModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary d-flex align-items-center">
                <h5 class="modal-title me-auto" id="processModalTitle">
                    <i class="bi bi-cpu me-2"></i>Procesos
                </h5>
                <span id="processSummary" class="text-muted small me-3"></span>
                <div class="form-check form-switch mb-0 me-3">
                    <input class="form-check-input" type="checkbox" id="processAutoRefresh" checked>
                    <label class="form-check-label small text-muted" for="processAutoRefresh">
                        Auto <span id="processCountdown">3s</span>
                    </label>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Tab toggle: CPU / RAM -->
                <div class="d-flex border-bottom border-secondary">
                    <button class="btn btn-sm rounded-0 flex-fill process-tab active" data-sort="cpu" onclick="switchProcessTab('cpu')">
                        <i class="bi bi-cpu me-1"></i> Por CPU %
                    </button>
                    <button class="btn btn-sm rounded-0 flex-fill process-tab" data-sort="ram" onclick="switchProcessTab('ram')">
                        <i class="bi bi-memory me-1"></i> Por RAM %
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover table-sm mb-0" id="processTable">
                        <thead>
                            <tr>
                                <th class="ps-3" style="width:50px">PID</th>
                                <th style="width:90px">Usuario</th>
                                <th style="width:70px" class="text-end">CPU %</th>
                                <th style="width:70px" class="text-end">RAM %</th>
                                <th style="width:80px" class="text-end">RSS</th>
                                <th style="width:70px">Estado</th>
                                <th style="width:70px">Tiempo</th>
                                <th>Comando</th>
                            </tr>
                        </thead>
                        <tbody id="processTableBody">
                            <tr><td colspan="8" class="text-center text-muted py-4"><i class="bi bi-hourglass-split"></i> Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-secondary py-1">
                <small class="text-muted" id="processTimestamp"></small>
            </div>
        </div>
    </div>
</div>

<!-- Process Detail Modal -->
<div class="modal fade" id="processDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-terminal me-2"></i>Proceso <span id="detailPid" class="text-info"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="processDetailBody">
                <div class="text-center text-muted py-4"><i class="bi bi-hourglass-split"></i> Cargando...</div>
            </div>
            <div class="modal-footer border-secondary">
                <div class="d-flex gap-2 w-100">
                    <button class="btn btn-warning btn-sm" onclick="killProcess(currentDetailPid, 'TERM')">
                        <i class="bi bi-exclamation-triangle me-1"></i>SIGTERM (graceful)
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="killProcess(currentDetailPid, 'KILL')">
                        <i class="bi bi-x-octagon me-1"></i>SIGKILL (forzar)
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="killProcess(currentDetailPid, 'HUP')">
                        <i class="bi bi-arrow-repeat me-1"></i>SIGHUP (reload)
                    </button>
                    <button class="btn btn-outline-secondary btn-sm ms-auto" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cluster Status (solo si no es standalone) -->
<?php if (!empty($clusterInfo)): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <?php
            $cRole = $clusterInfo['role'];
            $cBadge = $cRole === 'master' ? 'bg-success' : 'bg-info';
            $cLabel = $cRole === 'master' ? 'Master' : 'Slave';
            $mIp = $clusterInfo['master_ip'] ?? '';
            $mHb = $clusterInfo['master_last_hb'] ?? '';
            $mAge = $mHb ? (time() - strtotime($mHb)) : 99999;
        ?>
        <div class="card border-<?= $cRole === 'slave' ? 'info' : 'success' ?>">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-diagram-3 me-2"></i>Cluster
                    <span class="badge <?= $cBadge ?> ms-2"><?= $cLabel ?></span>
                </span>
                <a href="/settings/cluster" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-gear me-1"></i>Configuracion
                </a>
            </div>
            <div class="card-body py-2">
                <?php if ($cRole === 'slave' && !empty($clusterInfo['self_standby'])): ?>
                    <div class="alert alert-warning mb-2 py-2 d-flex align-items-center gap-2" style="background:rgba(251,191,36,0.15);border-color:rgba(251,191,36,0.3);color:#fbbf24;">
                        <i class="bi bi-pause-circle-fill fs-5"></i>
                        <div>
                            <strong>Nodo en Standby</strong> — El master ha pausado este nodo. No se reciben sincronizaciones de archivos, BD ni cola.
                            El heartbeat sigue activo para mantener la conexion.
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($cRole === 'slave' && $mIp): ?>
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-shield-check fs-4 text-info"></i>
                        <div>
                            <span class="text-muted">Master:</span>
                            <code><?= View::e($mIp) ?></code>
                            <?php if ($mAge < 120): ?>
                                <span class="badge bg-success ms-2"><i class="bi bi-check-circle me-1"></i>Conectado</span>
                            <?php else: ?>
                                <span class="badge bg-danger ms-2"><i class="bi bi-exclamation-triangle me-1"></i>Sin contacto</span>
                            <?php endif; ?>
                            <?php if ($mHb): ?>
                                <small class="text-muted ms-2">(ultimo heartbeat: <?= View::e($mHb) ?>)</small>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($cRole === 'slave'): ?>
                    <span class="text-muted"><i class="bi bi-hourglass me-1"></i>Esperando heartbeat del master...</span>
                <?php elseif ($cRole === 'master'): ?>
                    <span class="text-muted"><i class="bi bi-broadcast me-1"></i>Este servidor gestiona y sincroniza hostings a los nodos slave.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Failover ISP Status -->
<?php if (!empty($failoverStatus)):
    $foState = $failoverStatus['state'] ?? 'normal';
    $foBadge = \MuseDockPanel\Services\FailoverService::stateBadgeClass($foState);
    $foLabel = $failoverStatus['state_label'] ?? 'Normal';
    $foIsNormal = $foState === 'normal';
?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card border-<?= $foIsNormal ? 'success' : 'danger' ?>">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-shield-check me-2"></i>Failover ISP
                    <span class="badge <?= $foBadge ?> ms-2"><?= View::e($foLabel) ?></span>
                </span>
                <a href="/settings/cluster#tab-failover" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-gear me-1"></i>Configurar
                </a>
            </div>
            <?php if (!$foIsNormal): ?>
            <div class="card-body py-2">
                <div class="alert alert-danger mb-0 py-2">
                    <i class="bi bi-exclamation-octagon me-2"></i>
                    <strong>Failover activo:</strong> <?= View::e($foLabel) ?>
                    <?php if ($failoverStatus['state_since'] ?? ''): ?>
                        desde <?= View::e($failoverStatus['state_since']) ?>
                    <?php endif; ?>
                    <a href="/settings/cluster#tab-failover" class="ms-2">Ver detalles</a>
                </div>
            </div>
            <?php else: ?>
            <div class="card-body py-2">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <?php foreach ($failoverStatus['servers'] ?? [] as $srv): ?>
                    <span class="small text-muted">
                        <i class="bi bi-<?= ($srv['role'] ?? '') === 'backup' ? 'router' : 'cloud' ?> me-1"></i>
                        <?= View::e($srv['name'] ?? '') ?>
                        <code class="ms-1"><?= View::e($srv['ip'] ?? '--') ?></code>
                        <span class="badge bg-<?= match($srv['role'] ?? '') { 'primary' => 'info', 'failover' => 'warning text-dark', default => 'secondary' } ?>" style="font-size:.6rem;">
                            <?= View::e(ucfirst($srv['role'] ?? '')) ?>
                        </span>
                    </span>
                    <?php endforeach; ?>
                    <?php if ($failoverStatus['dyndns'] ?? ''): ?>
                    <span class="small text-muted">
                        <i class="bi bi-globe me-1"></i>DynDNS: <code><?= View::e($failoverStatus['dyndns']) ?></code>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- System Info -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>System Info</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="ps-3 text-muted" style="width:40%">Hostname</td><td><?= View::e($stats['hostname']) ?></td></tr>
                    <tr><td class="ps-3 text-muted">OS</td><td><?= View::e($stats['os']) ?></td></tr>
                    <tr><td class="ps-3 text-muted">PHP</td><td><?= View::e($stats['php_version']) ?></td></tr>
                    <tr><td class="ps-3 text-muted">Uptime</td><td><?= View::e($stats['uptime']) ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history me-2"></i>Recent Activity</div>
            <div class="card-body p-0">
                <?php if (empty($recentLog)): ?>
                    <div class="p-3 text-muted text-center">No recent activity</div>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <?php foreach (array_slice($recentLog, 0, 8) as $log): ?>
                        <tr>
                            <td class="ps-3"><small class="text-muted"><?= date('d/m H:i', strtotime($log['created_at'])) ?></small></td>
                            <td><span class="badge bg-dark"><?= View::e($log['action']) ?></span></td>
                            <td><?= View::e($log['target'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (empty($offlineNodes) && !empty($onlineNodes)): ?>
<!-- Synced Nodes (info, no alert) -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card" style="border-color:#0d6efd33 !important;">
            <div class="card-header d-flex justify-content-between align-items-center py-2" style="background:rgba(13,110,253,0.08);border-bottom:1px solid #0d6efd22;">
                <span style="color:#6ea8fe;">
                    <i class="bi bi-arrow-repeat me-2"></i>
                    <strong><?= count($onlineNodes) ?> nodo<?= count($onlineNodes) > 1 ? 's' : '' ?> sincronizado<?= count($onlineNodes) > 1 ? 's' : '' ?></strong>
                </span>
                <a href="/settings/cluster#nodos" class="btn btn-outline-info btn-sm py-0">
                    <i class="bi bi-gear me-1"></i>Cluster
                </a>
            </div>
            <div class="card-body py-2 px-3">
                <?php foreach ($onlineNodes as $oNode):
                    $svc = $oNode['services'] ?? ['web'];
                    $replRole = $oNode['repl_role'] ?? 'standalone';
                    $pgRepl = $oNode['pg_repl'] ?? null;
                    $mysqlRepl = $oNode['mysql_repl'] ?? null;
                    // PG replication status
                    $pgOk = $pgRepl && (($pgRepl['streaming'] ?? false) || ($pgRepl['state'] ?? '') === 'streaming');
                    // MySQL replication status
                    $mysqlOk = $mysqlRepl && (($mysqlRepl['running'] ?? false) || (($mysqlRepl['io_running'] ?? '') === 'Yes' && ($mysqlRepl['sql_running'] ?? '') === 'Yes'));
                ?>
                <div class="d-flex align-items-center gap-2 <?= count($onlineNodes) > 1 ? 'mb-2' : '' ?> flex-wrap">
                    <i class="bi bi-circle-fill text-success" style="font-size:0.45rem;"></i>
                    <strong class="small" style="color:#e2e8f0;"><?= View::e($oNode['name']) ?></strong>
                    <div class="d-flex gap-1">
                        <?php if (in_array('web', $svc)): ?>
                            <span class="badge" style="font-size:0.65rem;background:#1a5c2a;color:#4ade80;">web</span>
                        <?php endif; ?>
                        <?php if (in_array('mail', $svc)): ?>
                            <span class="badge" style="font-size:0.65rem;background:#1a3a5c;color:#60a5fa;">mail</span>
                        <?php endif; ?>
                        <?php if ($replRole !== 'standalone'): ?>
                            <?php if ($pgOk): ?>
                                <span class="badge" style="font-size:0.65rem;background:#1a3a5c;color:#60a5fa;" title="PostgreSQL streaming">
                                    <i class="bi bi-database me-1"></i>PG <i class="bi bi-check-lg"></i>
                                </span>
                            <?php elseif ($pgRepl !== null): ?>
                                <span class="badge" style="font-size:0.65rem;background:#5c1a1a;color:#f87171;" title="PostgreSQL no replicando">
                                    <i class="bi bi-database me-1"></i>PG <i class="bi bi-x-lg"></i>
                                </span>
                            <?php endif; ?>
                            <?php if ($mysqlOk): ?>
                                <span class="badge" style="font-size:0.65rem;background:#1a3a5c;color:#60a5fa;" title="MySQL replicando">
                                    <i class="bi bi-database me-1"></i>MySQL <i class="bi bi-check-lg"></i>
                                </span>
                            <?php elseif ($mysqlRepl !== null): ?>
                                <span class="badge" style="font-size:0.65rem;background:#5c1a1a;color:#f87171;" title="MySQL no replicando">
                                    <i class="bi bi-database me-1"></i>MySQL <i class="bi bi-x-lg"></i>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge" style="font-size:0.65rem;background:#3a3a3a;color:#9ca3af;" title="Sin replicación de BD">standalone</span>
                        <?php endif; ?>
                    </div>
                    <span class="text-muted small ms-auto"><?= View::e($oNode['last_seen_at']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .stat-card[role="button"]:hover {
        border-color: rgba(255,255,255,0.3) !important;
        box-shadow: 0 0 10px rgba(255,255,255,0.05);
        transition: all 0.2s;
    }
    .process-tab {
        background: transparent;
        color: #adb5bd;
        border: none;
        border-bottom: 2px solid transparent;
        padding: 8px 16px;
    }
    .process-tab:hover {
        color: #fff;
        background: rgba(255,255,255,0.05);
    }
    .process-tab.active {
        color: #0dcaf0;
        border-bottom-color: #0dcaf0;
        background: rgba(13,202,240,0.05);
    }
    #processTable tbody tr {
        cursor: pointer;
    }
    #processTable tbody tr:hover {
        background: rgba(255,255,255,0.08);
    }
    .cpu-bar, .mem-bar {
        display: inline-block;
        height: 4px;
        border-radius: 2px;
        min-width: 2px;
        vertical-align: middle;
        margin-left: 6px;
    }
    .cpu-bar { background: #0dcaf0; }
    .mem-bar { background: #ffc107; }
</style>

<script>
(function() {
    let currentSort = 'cpu';
    let refreshTimer = null;
    let countdownTimer = null;
    let countdown = 3;
    const REFRESH_INTERVAL = 3; // seconds

    const modal = document.getElementById('processModal');
    let bsModal = null;

    // Format KB to human-readable
    function formatKB(kb) {
        if (kb >= 1048576) return (kb / 1048576).toFixed(1) + ' GB';
        if (kb >= 1024) return (kb / 1024).toFixed(0) + ' MB';
        return kb + ' KB';
    }

    // Color for percentage
    function cpuColor(val) {
        if (val >= 50) return '#dc3545';
        if (val >= 20) return '#ffc107';
        if (val > 1) return '#0dcaf0';
        return '#6c757d';
    }
    function memColor(val) {
        if (val >= 30) return '#dc3545';
        if (val >= 10) return '#ffc107';
        if (val > 1) return '#ffc107';
        return '#6c757d';
    }

    // Escape HTML
    function esc(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    // Truncate command
    function truncCmd(cmd, max) {
        if (!cmd) return '';
        return cmd.length > max ? cmd.substring(0, max) + '...' : cmd;
    }

    // Fetch and render processes
    async function fetchProcesses() {
        try {
            const resp = await fetch(`/dashboard/processes?sort=${currentSort}&limit=25`);
            const data = await resp.json();
            if (!data.ok) return;

            const tbody = document.getElementById('processTableBody');
            const s = data.summary;

            // Update summary
            document.getElementById('processSummary').innerHTML =
                `CPU: <b>${s.cpu_percent}%</b> (${s.cpu_load} load, ${s.cores} cores) | RAM: <b>${s.mem_percent}%</b> (${s.mem_used_gb}/${s.mem_total_gb} GB)`;

            // Update timestamp
            const now = new Date();
            document.getElementById('processTimestamp').textContent =
                'Actualizado: ' + now.toLocaleTimeString('es-ES');

            // Render rows
            if (!data.processes || data.processes.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">Sin procesos</td></tr>';
                return;
            }

            tbody.innerHTML = data.processes.map(p => {
                const cpuW = Math.min(60, p.cpu * 2);
                const memW = Math.min(60, p.mem * 3);
                return `<tr onclick="openProcessDetail(${p.pid})" title="Click para ver detalle del proceso ${p.pid}">
                    <td class="ps-3 text-muted">${p.pid}</td>
                    <td><small>${esc(p.user)}</small></td>
                    <td class="text-end">
                        <span style="color:${cpuColor(p.cpu)}">${p.cpu.toFixed(1)}</span>
                        <span class="cpu-bar" style="width:${cpuW}px"></span>
                    </td>
                    <td class="text-end">
                        <span style="color:${memColor(p.mem)}">${p.mem.toFixed(1)}</span>
                        <span class="mem-bar" style="width:${memW}px"></span>
                    </td>
                    <td class="text-end"><small class="text-muted">${formatKB(p.rss)}</small></td>
                    <td><small class="text-muted">${esc(p.stat)}</small></td>
                    <td><small class="text-muted">${esc(p.time)}</small></td>
                    <td><small>${esc(truncCmd(p.command, 80))}</small></td>
                </tr>`;
            }).join('');

        } catch (e) {
            console.error('Error fetching processes:', e);
        }
    }

    // Start auto-refresh countdown
    function startRefresh() {
        stopRefresh();
        countdown = REFRESH_INTERVAL;
        updateCountdownDisplay();

        countdownTimer = setInterval(() => {
            countdown--;
            if (countdown <= 0) {
                fetchProcesses();
                countdown = REFRESH_INTERVAL;
            }
            updateCountdownDisplay();
        }, 1000);
    }

    function stopRefresh() {
        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
    }

    function updateCountdownDisplay() {
        const el = document.getElementById('processCountdown');
        if (el) el.textContent = countdown + 's';
    }

    // Tab switch
    window.switchProcessTab = function(sort) {
        currentSort = sort;
        document.querySelectorAll('.process-tab').forEach(t => {
            t.classList.toggle('active', t.dataset.sort === sort);
        });
        // Update title icon
        const icon = sort === 'cpu' ? 'bi-cpu' : 'bi-memory';
        const label = sort === 'cpu' ? 'Procesos por CPU' : 'Procesos por RAM';
        document.getElementById('processModalTitle').innerHTML = `<i class="bi ${icon} me-2"></i>${label}`;
        fetchProcesses();
    };

    // Open modal
    window.openProcessModal = function(sort) {
        currentSort = sort || 'cpu';

        // Set active tab
        document.querySelectorAll('.process-tab').forEach(t => {
            t.classList.toggle('active', t.dataset.sort === currentSort);
        });

        const icon = currentSort === 'cpu' ? 'bi-cpu' : 'bi-memory';
        const label = currentSort === 'cpu' ? 'Procesos por CPU' : 'Procesos por RAM';
        document.getElementById('processModalTitle').innerHTML = `<i class="bi ${icon} me-2"></i>${label}`;

        if (!bsModal) {
            bsModal = new bootstrap.Modal(modal);
        }
        bsModal.show();
        fetchProcesses();

        // Start auto-refresh if checkbox is checked
        if (document.getElementById('processAutoRefresh').checked) {
            startRefresh();
        }
    };

    // Auto-refresh toggle
    document.getElementById('processAutoRefresh')?.addEventListener('change', function() {
        if (this.checked) {
            startRefresh();
        } else {
            stopRefresh();
            document.getElementById('processCountdown').textContent = 'off';
        }
    });

    // Stop refresh when modal closes
    modal?.addEventListener('hidden.bs.modal', function() {
        stopRefresh();
    });

    // ─── Process Detail ─────────────────────────────────────

    let currentDetailPid = 0;
    let detailModal = null;

    window.openProcessDetail = async function(pid) {
        currentDetailPid = pid;
        document.getElementById('detailPid').textContent = '#' + pid;
        document.getElementById('processDetailBody').innerHTML =
            '<div class="text-center text-muted py-4"><i class="bi bi-hourglass-split"></i> Cargando...</div>';

        if (!detailModal) {
            detailModal = new bootstrap.Modal(document.getElementById('processDetailModal'));
        }
        detailModal.show();

        try {
            const resp = await fetch(`/dashboard/process-detail?pid=${pid}`);
            const data = await resp.json();

            if (!data.ok) {
                document.getElementById('processDetailBody').innerHTML =
                    `<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-2"></i>${esc(data.error)}</div>`;
                return;
            }

            const d = data;
            document.getElementById('processDetailBody').innerHTML = `
                <div class="table-responsive">
                    <table class="table table-dark table-sm mb-0">
                        <tr><td class="ps-3 text-muted" style="width:140px">PID</td><td><code>${d.pid}</code></td></tr>
                        <tr><td class="ps-3 text-muted">PPID</td><td><code>${d.ppid}</code></td></tr>
                        <tr><td class="ps-3 text-muted">Usuario</td><td>${esc(d.user)}</td></tr>
                        <tr><td class="ps-3 text-muted">CPU %</td><td><span style="color:${cpuColor(d.cpu)}">${d.cpu.toFixed(1)}%</span></td></tr>
                        <tr><td class="ps-3 text-muted">RAM %</td><td><span style="color:${memColor(d.mem)}">${d.mem.toFixed(1)}%</span></td></tr>
                        <tr><td class="ps-3 text-muted">RSS</td><td>${formatKB(d.rss)}</td></tr>
                        <tr><td class="ps-3 text-muted">VSZ</td><td>${formatKB(d.vsz)}</td></tr>
                        <tr><td class="ps-3 text-muted">Estado</td><td><code>${esc(d.stat)}</code></td></tr>
                        <tr><td class="ps-3 text-muted">Iniciado</td><td>${esc(d.started)}</td></tr>
                        <tr><td class="ps-3 text-muted">Tiempo CPU</td><td>${esc(d.time)}</td></tr>
                        <tr><td class="ps-3 text-muted">Threads</td><td>${d.threads}</td></tr>
                        <tr><td class="ps-3 text-muted">File Descriptors</td><td>${d.fd_count}</td></tr>
                        ${d.exe ? `<tr><td class="ps-3 text-muted">Ejecutable</td><td><code class="text-info small">${esc(d.exe)}</code></td></tr>` : ''}
                        ${d.cwd ? `<tr><td class="ps-3 text-muted">Directorio</td><td><code class="small">${esc(d.cwd)}</code></td></tr>` : ''}
                    </table>
                </div>
                <div class="mt-3">
                    <label class="text-muted small mb-1 d-block">Comando completo:</label>
                    <pre class="bg-black text-light p-3 rounded small mb-0" style="white-space:pre-wrap;word-break:break-all;max-height:200px;overflow-y:auto">${esc(d.cmdline || d.command)}</pre>
                </div>
            `;
        } catch (e) {
            document.getElementById('processDetailBody').innerHTML =
                `<div class="alert alert-danger mb-0">Error: ${esc(e.message)}</div>`;
        }
    };

    // ─── Kill Process ───────────────────────────────────────

    const csrfToken = document.querySelector('input[name=_csrf_token]')?.value || '<?= $_SESSION['_csrf_token'] ?? '' ?>';

    window.killProcess = async function(pid, signal) {
        if (!pid || pid < 2) return;

        const signalLabels = { TERM: 'SIGTERM (terminacion graceful)', KILL: 'SIGKILL (forzar terminacion)', HUP: 'SIGHUP (reload)' };
        const label = signalLabels[signal] || signal;

        const confirmed = await (typeof SwalDark !== 'undefined' ? SwalDark : Swal).fire({
            title: 'Confirmar Kill',
            html: `<p>Enviar <b>${label}</b> al proceso <code>${pid}</code>?</p>
                   ${signal === 'KILL' ? '<p class="text-danger"><small>SIGKILL termina el proceso inmediatamente sin dar oportunidad de limpieza.</small></p>' : ''}`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: `Enviar ${signal}`,
            confirmButtonColor: signal === 'KILL' ? '#dc3545' : '#ffc107',
            cancelButtonText: 'Cancelar',
        });

        if (!confirmed.isConfirmed) return;

        try {
            const form = new FormData();
            form.append('pid', pid);
            form.append('signal', signal);
            form.append('_csrf_token', csrfToken);

            const resp = await fetch('/dashboard/process-kill', { method: 'POST', body: form });
            const data = await resp.json();

            (typeof SwalDark !== 'undefined' ? SwalDark : Swal).fire({
                title: data.killed ? 'Proceso Terminado' : 'Signal Enviada',
                text: data.message || (data.ok ? 'OK' : data.error),
                icon: data.killed ? 'success' : (data.ok ? 'info' : 'error'),
                timer: 3000,
            });

            if (data.killed && detailModal) {
                detailModal.hide();
            }

            // Refresh process list
            fetchProcesses();
        } catch (e) {
            (typeof SwalDark !== 'undefined' ? SwalDark : Swal).fire({
                title: 'Error',
                text: e.message,
                icon: 'error',
            });
        }
    };

})();

// ─── Node Alert Mute/Unmute ──────────────────────────────
window.toggleNodeAlerts = async function(nodeId, action, nodeName) {
    const csrfToken = document.querySelector('input[name=_csrf_token]')?.value || '<?= $_SESSION['_csrf_token'] ?? '' ?>';
    const isMute = action === 'mute';

    const confirmed = await (typeof SwalDark !== 'undefined' ? SwalDark : Swal).fire({
        title: isMute ? 'Silenciar alertas' : 'Reactivar alertas',
        html: isMute
            ? `<p>Silenciar todas las alertas (Telegram/Email) del nodo <b>${nodeName}</b>?</p><p class="text-muted"><small>Las alertas se reactivaran automaticamente cuando el nodo vuelva a estar online.</small></p>`
            : `<p>Reactivar las alertas del nodo <b>${nodeName}</b>?</p>`,
        icon: isMute ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: isMute ? 'Silenciar' : 'Reactivar',
        confirmButtonColor: isMute ? '#6c757d' : '#ffc107',
        cancelButtonText: 'Cancelar',
    });

    if (!confirmed.isConfirmed) return;

    try {
        const url = isMute
            ? '/settings/cluster/mute-node-alerts'
            : '/settings/cluster/unmute-node-alerts';

        const form = new FormData();
        form.append('node_id', nodeId);
        form.append('_csrf_token', csrfToken);

        const resp = await fetch(url, { method: 'POST', body: form });
        const data = await resp.json();

        (typeof SwalDark !== 'undefined' ? SwalDark : Swal).fire({
            title: data.ok ? 'OK' : 'Error',
            text: data.message || data.error || 'Operacion completada',
            icon: data.ok ? 'success' : 'error',
            timer: 2000,
        }).then(() => {
            if (data.ok) location.reload();
        });
    } catch (e) {
        (typeof SwalDark !== 'undefined' ? SwalDark : Swal).fire({
            title: 'Error',
            text: e.message,
            icon: 'error',
        });
    }
};

window.reactivateNode = async function(nodeId, nodeName) {
    const S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;
    const csrfToken = document.querySelector('input[name=_csrf_token]')?.value || '<?= $_SESSION['_csrf_token'] ?? '' ?>';

    const result = await S.fire({
        title: 'Reactivar nodo',
        html: '<p>Reactivar el nodo <strong>' + nodeName + '</strong>?</p>' +
              '<p class="small text-muted">Se reanudarán: sincronización de archivos, cola, alertas y todas las operaciones.</p>' +
              '<div class="mb-2"><label class="form-label small">Contraseña de administrador</label>' +
              '<input type="password" id="reactivatePassword" class="form-control" placeholder="Confirmar con tu contraseña" style="background:#2a2a3e;color:#fff;border-color:#444;"></div>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Reactivar',
        confirmButtonColor: '#22c55e',
        cancelButtonText: 'Cancelar',
        preConfirm: function() {
            var pw = document.getElementById('reactivatePassword').value;
            if (!pw) {
                Swal.showValidationMessage('La contraseña es obligatoria');
                return false;
            }
            return pw;
        }
    });

    if (!result.isConfirmed) return;

    try {
        var form = new FormData();
        form.append('node_id', nodeId);
        form.append('action', 'deactivate');
        form.append('admin_password', result.value);
        form.append('reason', '');
        form.append('_csrf_token', csrfToken);

        const resp = await fetch('/settings/cluster/node-standby', { method: 'POST', body: form });
        const data = await resp.json();

        S.fire({
            title: data.ok ? 'OK' : 'Error',
            text: data.message || data.error,
            icon: data.ok ? 'success' : 'error',
            timer: 2500,
        }).then(function() { if (data.ok) location.reload(); });
    } catch (e) {
        S.fire({ title: 'Error', text: e.message, icon: 'error' });
    }
};
</script>
