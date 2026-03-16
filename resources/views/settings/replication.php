<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php
// ── Helpers de badge por rol ─────────────────────────────────────────────────
function roleBadge(string $role): string {
    return match($role) {
        'master'     => '<span class="badge bg-success"><i class="bi bi-arrow-up-circle me-1"></i>Master</span>',
        'slave'      => '<span class="badge bg-warning text-dark"><i class="bi bi-arrow-down-circle me-1"></i>Slave</span>',
        'error'      => '<span class="badge bg-danger"><i class="bi bi-exclamation-octagon me-1"></i>Error</span>',
        default      => '<span class="badge bg-secondary">Standalone</span>',
    };
}

$isMaster  = ($pgRole === 'master' || $mysqlRole === 'master');
$isActive  = ($pgRole !== 'standalone' || $mysqlRole !== 'standalone');
?>

<!-- Alert global de replicacion con problemas -->
<div id="repl-alert" class="alert mb-3" style="display:none; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#ef4444;">
    <i class="bi bi-exclamation-octagon me-2"></i>
    <strong>Replicacion con problemas:</strong> <span id="repl-alert-msg"></span>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SECCION 1 — Estado de Replicacion (diagrama, auto-refresh) -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card bg-dark border-secondary mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-3 me-2"></i>Estado de Replicacion</span>
        <span class="text-muted small" id="status-refresh-indicator">
            Proxima actualizacion en <span id="countdown">5</span>s
        </span>
    </div>
    <div class="card-body pb-2">
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="max-width:700px;">
                <tbody>
                    <!-- PostgreSQL row -->
                    <tr>
                        <td style="width:200px;" class="text-muted py-2">
                            <i class="bi bi-database me-1 text-info"></i>
                            <strong>PostgreSQL (5432)</strong>
                        </td>
                        <td class="py-2" id="status-pg-badge">
                            <?= roleBadge($pgRole) ?>
                        </td>
                        <td class="py-2" id="status-pg-detail">
                            <?php if ($pgRole === 'master' && !empty($slaves)): ?>
                                <?php foreach ($slaves as $s): if (!$s['pg_enabled']) continue; ?>
                                    <span class="text-muted small me-2">
                                        <i class="bi bi-arrow-right me-1 text-success"></i>
                                        <code><?= View::e($s['primary_ip']) ?></code>
                                        <span class="text-muted">(Slave)</span>
                                    </span>
                                <?php endforeach; ?>
                            <?php elseif ($pgRole === 'slave'): ?>
                                <span class="text-muted small" id="status-pg-lag-text">Conectando...</span>
                            <?php else: ?>
                                <span class="text-muted small">No configurado</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- MySQL row -->
                    <tr>
                        <td style="width:200px;" class="text-muted py-2">
                            <i class="bi bi-database me-1" style="color:#fbbf24;"></i>
                            <strong>MySQL (3306)</strong>
                        </td>
                        <td class="py-2" id="status-mysql-badge">
                            <?= roleBadge($mysqlRole) ?>
                        </td>
                        <td class="py-2" id="status-mysql-detail">
                            <?php if ($mysqlRole === 'master' && !empty($slaves)): ?>
                                <?php foreach ($slaves as $s): if (!$s['mysql_enabled']) continue; ?>
                                    <span class="text-muted small me-2">
                                        <i class="bi bi-arrow-right me-1 text-success"></i>
                                        <code><?= View::e($s['primary_ip']) ?></code>
                                        <span class="text-muted">(Slave)</span>
                                    </span>
                                <?php endforeach; ?>
                            <?php elseif ($mysqlRole === 'slave'): ?>
                                <span class="text-muted small" id="status-mysql-lag-text">Conectando...</span>
                            <?php else: ?>
                                <span class="text-muted small">No configurado</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Panel DB row -->
                    <tr>
                        <td class="text-muted py-2">
                            <i class="bi bi-server me-1 text-secondary"></i>
                            <strong>Panel DB (5433)</strong>
                        </td>
                        <td class="py-2">
                            <span class="badge bg-secondary">Independiente</span>
                        </td>
                        <td class="py-2">
                            <span class="text-muted small">Backup cada hora</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SECCION 2 — Informacion del Rol del Servidor               -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card bg-dark border-secondary mb-4">
    <div class="card-header">
        <i class="bi bi-info-circle me-2"></i>Informacion del Servidor
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="mb-2">
                    <small class="text-muted d-block">Versiones detectadas</small>
                    <span class="text-info"><i class="bi bi-database me-1"></i>PostgreSQL:</span>
                    <strong><?= View::e($pgVersion ?? 'No detectado') ?></strong>
                    &nbsp;&nbsp;
                    <span style="color:#fbbf24;"><i class="bi bi-database me-1"></i>MySQL:</span>
                    <strong><?= View::e($mysqlVersion ?? 'No detectado') ?></strong>
                </div>
                <?php if (!empty($configuredAt)): ?>
                <div>
                    <small class="text-muted">Ultima configuracion: <strong><?= View::e($configuredAt) ?></strong></small>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <div class="d-flex flex-column gap-1" style="font-size:0.85rem;">
                    <div>
                        <span class="badge bg-success me-2">Master</span>
                        <span class="text-muted">Este servidor acepta escrituras y las replica a los slaves. Seguro de activar.</span>
                    </div>
                    <div>
                        <span class="badge bg-warning text-dark me-2">Slave</span>
                        <span class="text-muted">Solo lectura, copia del master. <strong class="text-danger">DESTRUCTIVO:</strong> borra datos locales al configurar.</span>
                    </div>
                    <div>
                        <span class="badge bg-secondary me-2">Standalone</span>
                        <span class="text-muted">Sin replicacion configurada para este motor.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SECCION 3 — PostgreSQL Replication Card                    -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card bg-dark border-secondary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-database text-info"></i>
        <strong>PostgreSQL Replication (port 5432)</strong>
        <span id="pg-role-badge" class="ms-2"><?= roleBadge($pgRole) ?></span>
    </div>
    <div class="card-body">

        <!-- Connection config -->
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <?php if ($pgRole === 'master'): ?>
                    <label class="form-label text-muted small mb-1">IP del Slave (servidor que recibira la copia)</label>
                    <input type="text" id="pg-remote-ip" class="form-control form-control-sm"
                           value="<?= View::e($settings['repl_pg_remote_ip'] ?? $settings['repl_remote_ip'] ?? '') ?>"
                           placeholder="10.10.70.x">
                    <small class="text-muted">La IP del servidor remoto que se conectara a este para replicar los datos.</small>
                <?php elseif ($pgRole === 'slave'): ?>
                    <label class="form-label text-muted small mb-1">IP del Master (servidor origen de los datos)</label>
                    <input type="text" id="pg-remote-ip" class="form-control form-control-sm"
                           value="<?= View::e($settings['repl_pg_remote_ip'] ?? $settings['repl_remote_ip'] ?? '') ?>"
                           placeholder="10.10.70.x">
                    <small class="text-muted">La IP del servidor master del que se copiaran los datos. ATENCION: esto borrara las BD locales.</small>
                <?php else: ?>
                    <label class="form-label text-muted small mb-1">IP del Servidor Remoto</label>
                    <input type="text" id="pg-remote-ip" class="form-control form-control-sm"
                           value="<?= View::e($settings['repl_pg_remote_ip'] ?? $settings['repl_remote_ip'] ?? '') ?>"
                           placeholder="10.10.70.x">
                    <small class="text-muted">Si configuras como Master, es la IP del slave. Si configuras como Slave, es la IP del master.</small>
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <label class="form-label text-muted small mb-1">Puerto</label>
                <input type="number" id="pg-remote-port" class="form-control form-control-sm"
                       value="<?= View::e($settings['repl_pg_port'] ?? '5432') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label text-muted small mb-1">Usuario Replicacion</label>
                <input type="text" id="pg-remote-user" class="form-control form-control-sm"
                       value="<?= View::e($settings['repl_pg_user'] ?? 'replicator') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label text-muted small mb-1">Password</label>
                <input type="password" id="pg-remote-pass" class="form-control form-control-sm"
                       placeholder="<?= !empty($settings['repl_pg_pass']) ? '••••••••' : '' ?>">
            </div>
            <div class="col-12 d-flex align-items-center gap-3">
                <button type="button" class="btn btn-outline-info btn-sm" onclick="testEngineConn('pg')">
                    <i class="bi bi-wifi me-1"></i>Test Conexion
                </button>
                <small id="pg-conn-result" class="text-muted"></small>
            </div>
        </div>

        <!-- Action buttons -->
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php if ($pgRole === 'standalone'): ?>
                <!-- setup-master: conn fields injected via JS before submit -->
                <form method="post" action="/settings/replication/setup-master" id="form-pg-master">
                    <?= View::csrf() ?>
                    <input type="hidden" name="engine" value="pg">
                    <!-- connection fields will be appended by confirmPgMaster() -->
                    <button type="button" class="btn btn-success btn-sm" onclick="confirmPgMaster()">
                        <i class="bi bi-arrow-up-circle me-1"></i>Configurar como Master
                    </button>
                </form>
                <!-- setup-slave: conn fields + confirm=DELETE injected via JS before submit -->
                <form method="post" action="/settings/replication/setup-slave" id="form-pg-slave">
                    <?= View::csrf() ?>
                    <input type="hidden" name="engine" value="pg">
                    <input type="hidden" name="confirm" id="pg-slave-confirm" value="">
                    <input type="hidden" name="remote_ip"   id="pg-slave-remote-ip">
                    <input type="hidden" name="remote_port" id="pg-slave-remote-port">
                    <input type="hidden" name="remote_user" id="pg-slave-remote-user">
                    <input type="hidden" name="remote_pass" id="pg-slave-remote-pass">
                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmPgSlave()">
                        <i class="bi bi-exclamation-triangle me-1"></i>Configurar como Slave
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($pgRole === 'slave'): ?>
                <form method="post" action="/settings/replication/promote" id="form-pg-promote">
                    <?= View::csrf() ?>
                    <input type="hidden" name="engine" value="pg">
                    <button type="button" class="btn btn-warning btn-sm" onclick="confirmPgPromote()">
                        <i class="bi bi-arrow-up-circle me-1"></i>Promote to Master
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($pgRole === 'master' || $pgRole === 'slave'): ?>
                <form method="post" action="/settings/replication/setup-master" id="form-pg-reset">
                    <?= View::csrf() ?>
                    <input type="hidden" name="engine" value="pg">
                    <input type="hidden" name="reset" value="standalone">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="confirmPgReset()">
                        <i class="bi bi-x-circle me-1"></i>Reset to Standalone
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Monitor section (shown when not standalone) -->
        <?php if ($pgRole !== 'standalone'): ?>
        <div id="pg-monitor-section">
            <hr class="border-secondary">
            <h6 class="text-muted mb-3">
                <i class="bi bi-activity me-1"></i>Monitor PostgreSQL
                <span id="pg-monitor-badge" class="badge bg-secondary ms-2">—</span>
            </h6>
            <?php if ($pgRole === 'master'): ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0" style="max-width:700px;">
                    <thead><tr>
                        <th class="text-muted" style="font-weight:normal; font-size:0.8rem;">Estado</th>
                        <th class="text-muted" style="font-weight:normal; font-size:0.8rem;">Sent LSN</th>
                        <th class="text-muted" style="font-weight:normal; font-size:0.8rem;">Write LSN</th>
                        <th class="text-muted" style="font-weight:normal; font-size:0.8rem;">Flush LSN</th>
                        <th class="text-muted" style="font-weight:normal; font-size:0.8rem;">Replay LSN</th>
                        <th class="text-muted" style="font-weight:normal; font-size:0.8rem;">Lag</th>
                    </tr></thead>
                    <tbody>
                        <tr>
                            <td id="pgm-state">—</td>
                            <td id="pgm-sent-lsn">—</td>
                            <td id="pgm-write-lsn">—</td>
                            <td id="pgm-flush-lsn">—</td>
                            <td id="pgm-replay-lsn">—</td>
                            <td id="pgm-lag">—</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php elseif ($pgRole === 'slave'): ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0" style="max-width:600px;">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width:180px; font-size:0.85rem;">Estado</td>
                            <td id="pgm-state">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">Master</td>
                            <td id="pgm-sender">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">Last WAL Receive LSN</td>
                            <td id="pgm-receive-lsn">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">Replay LSN</td>
                            <td id="pgm-replay-lsn">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">Replay Timestamp</td>
                            <td id="pgm-replay-time">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">Lag</td>
                            <td id="pgm-lag">—</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <div class="progress mt-2" style="height:4px; max-width:400px;">
                <div id="pg-monitor-bar" class="progress-bar bg-success" style="width:100%"></div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SECCION 4 — MySQL Replication Card                         -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card bg-dark border-secondary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-database" style="color:#fbbf24;"></i>
        <strong>MySQL Replication (port 3306)</strong>
        <span id="mysql-role-badge" class="ms-2"><?= roleBadge($mysqlRole) ?></span>
    </div>
    <div class="card-body">

        <!-- Connection config -->
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <?php if ($mysqlRole === 'master'): ?>
                    <label class="form-label text-muted small mb-1">IP del Slave (servidor que recibira la copia)</label>
                    <input type="text" id="mysql-remote-ip" class="form-control form-control-sm"
                           value="<?= View::e($settings['repl_mysql_remote_ip'] ?? $settings['repl_remote_ip'] ?? '') ?>"
                           placeholder="10.10.70.x">
                    <small class="text-muted">La IP del servidor remoto que se conectara a este para replicar los datos.</small>
                <?php elseif ($mysqlRole === 'slave'): ?>
                    <label class="form-label text-muted small mb-1">IP del Master (servidor origen de los datos)</label>
                    <input type="text" id="mysql-remote-ip" class="form-control form-control-sm"
                           value="<?= View::e($settings['repl_mysql_remote_ip'] ?? $settings['repl_remote_ip'] ?? '') ?>"
                           placeholder="10.10.70.x">
                    <small class="text-muted">La IP del servidor master del que se copiaran los datos. ATENCION: esto borrara las BD locales.</small>
                <?php else: ?>
                    <label class="form-label text-muted small mb-1">IP del Servidor Remoto</label>
                    <input type="text" id="mysql-remote-ip" class="form-control form-control-sm"
                           value="<?= View::e($settings['repl_mysql_remote_ip'] ?? $settings['repl_remote_ip'] ?? '') ?>"
                           placeholder="10.10.70.x">
                    <small class="text-muted">Si configuras como Master, es la IP del slave. Si configuras como Slave, es la IP del master.</small>
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <label class="form-label text-muted small mb-1">Puerto</label>
                <input type="number" id="mysql-remote-port" class="form-control form-control-sm"
                       value="<?= View::e($settings['repl_mysql_port'] ?? '3306') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label text-muted small mb-1">Usuario Replicacion</label>
                <input type="text" id="mysql-remote-user" class="form-control form-control-sm"
                       value="<?= View::e($settings['repl_mysql_user'] ?? 'repl_user') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label text-muted small mb-1">Password</label>
                <input type="password" id="mysql-remote-pass" class="form-control form-control-sm"
                       placeholder="<?= !empty($settings['repl_mysql_pass']) ? '••••••••' : '' ?>">
            </div>
            <div class="col-12 d-flex align-items-center gap-3">
                <button type="button" class="btn btn-sm" style="border-color:#fbbf24; color:#fbbf24;" onclick="testEngineConn('mysql')">
                    <i class="bi bi-wifi me-1"></i>Test Conexion
                </button>
                <small id="mysql-conn-result" class="text-muted"></small>
            </div>
        </div>

        <!-- Action buttons -->
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php if ($mysqlRole === 'standalone'): ?>
                <!-- setup-master: conn fields injected via JS before submit -->
                <form method="post" action="/settings/replication/setup-master" id="form-mysql-master">
                    <?= View::csrf() ?>
                    <input type="hidden" name="engine" value="mysql">
                    <!-- connection fields will be appended by confirmMysqlMaster() -->
                    <button type="button" class="btn btn-success btn-sm" onclick="confirmMysqlMaster()">
                        <i class="bi bi-arrow-up-circle me-1"></i>Configurar como Master
                    </button>
                </form>
                <!-- setup-slave: conn fields + confirm=DELETE injected via JS before submit -->
                <form method="post" action="/settings/replication/setup-slave" id="form-mysql-slave">
                    <?= View::csrf() ?>
                    <input type="hidden" name="engine" value="mysql">
                    <input type="hidden" name="confirm" id="mysql-slave-confirm" value="">
                    <input type="hidden" name="remote_ip"   id="mysql-slave-remote-ip">
                    <input type="hidden" name="remote_port" id="mysql-slave-remote-port">
                    <input type="hidden" name="remote_user" id="mysql-slave-remote-user">
                    <input type="hidden" name="remote_pass" id="mysql-slave-remote-pass">
                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmMysqlSlave()">
                        <i class="bi bi-exclamation-triangle me-1"></i>Configurar como Slave
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($mysqlRole === 'slave'): ?>
                <form method="post" action="/settings/replication/promote" id="form-mysql-promote">
                    <?= View::csrf() ?>
                    <input type="hidden" name="engine" value="mysql">
                    <button type="button" class="btn btn-warning btn-sm" onclick="confirmMysqlPromote()">
                        <i class="bi bi-arrow-up-circle me-1"></i>Promote to Master
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($mysqlRole === 'master' || $mysqlRole === 'slave'): ?>
                <form method="post" action="/settings/replication/setup-master" id="form-mysql-reset">
                    <?= View::csrf() ?>
                    <input type="hidden" name="engine" value="mysql">
                    <input type="hidden" name="reset" value="standalone">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="confirmMysqlReset()">
                        <i class="bi bi-x-circle me-1"></i>Reset to Standalone
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Monitor section -->
        <?php if ($mysqlRole !== 'standalone'): ?>
        <div id="mysql-monitor-section">
            <hr class="border-secondary">
            <h6 class="text-muted mb-3">
                <i class="bi bi-activity me-1"></i>Monitor MySQL
                <span id="mysql-monitor-badge" class="badge bg-secondary ms-2">—</span>
            </h6>
            <?php if ($mysqlRole === 'master'): ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0" style="max-width:600px;">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width:180px; font-size:0.85rem;">Binlog File</td>
                            <td id="mysqlm-file">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">Position</td>
                            <td id="mysqlm-position">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">GTID Mode</td>
                            <td id="mysqlm-gtid-mode">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">GTID Executed</td>
                            <td id="mysqlm-gtid-exec" class="text-truncate" style="max-width:300px;">—</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php elseif ($mysqlRole === 'slave'): ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0" style="max-width:600px;">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width:200px; font-size:0.85rem;">IO Thread</td>
                            <td id="mysqlm-io">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">SQL Thread</td>
                            <td id="mysqlm-sql">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">Seconds Behind Master</td>
                            <td id="mysqlm-lag">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">Master Log File</td>
                            <td id="mysqlm-master-log">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">Read Position</td>
                            <td id="mysqlm-read-pos">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">GTID Mode</td>
                            <td id="mysqlm-gtid-mode">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">GTID Executed</td>
                            <td id="mysqlm-gtid-exec" class="text-truncate" style="max-width:300px;">—</td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="font-size:0.85rem;">Ultimo Error</td>
                            <td id="mysqlm-error" class="text-danger">—</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <div class="progress mt-2" style="height:4px; max-width:400px;">
                <div id="mysql-monitor-bar" class="progress-bar bg-success" style="width:100%"></div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SECCION 5 — Gestion de Slaves (si alguno es master)        -->
<!-- ═══════════════════════════════════════════════════════════ -->
<?php if ($isMaster): ?>
<div class="card bg-dark border-secondary mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hdd-network me-2"></i>Gestion de Slaves</span>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSlaveModal">
                <i class="bi bi-plus-circle me-1"></i>Anadir Slave
            </button>
            <form method="post" action="/settings/replication/apply-master" id="form-apply-master">
                <?= View::csrf() ?>
                <button type="button" class="btn btn-success btn-sm" onclick="confirmApplyMaster()" <?= empty($slaves) ? 'disabled' : '' ?>>
                    <i class="bi bi-check-circle me-1"></i>Aplicar Configuracion Master
                </button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($slaves)): ?>
            <p class="text-muted text-center mb-0">
                <i class="bi bi-info-circle me-1"></i>No hay slaves configurados. Use "Anadir Slave" para comenzar.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>IP Primaria</th>
                            <th>IP Fallback</th>
                            <th>PG</th>
                            <th>MySQL</th>
                            <th>Sync Mode</th>
                            <th>Estado</th>
                            <th>Conexion Activa</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="monitor-tbody">
                        <?php foreach ($slaves as $slave): ?>
                        <tr id="slave-row-<?= (int)$slave['id'] ?>">
                            <td><strong><?= View::e($slave['name']) ?></strong></td>
                            <td><code><?= View::e($slave['primary_ip']) ?></code></td>
                            <td>
                                <?php if (!empty($slave['fallback_ip'])): ?>
                                    <code><?= View::e($slave['fallback_ip']) ?></code>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($slave['pg_enabled']): ?>
                                    <span class="badge bg-info">
                                        <i class="bi bi-check me-1"></i><?= View::e($slave['pg_repl_type'] ?? 'physical') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Off</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($slave['mysql_enabled']): ?>
                                    <span class="badge" style="background:#b45309;">
                                        <i class="bi bi-check me-1"></i><?= !empty($slave['mysql_gtid_enabled']) ? 'GTID' : 'Bin' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Off</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                    $syncMode  = $slave['pg_sync_mode'] ?? 'async';
                                    $syncBadge = match($syncMode) {
                                        'sync'         => 'bg-success',
                                        'remote_apply' => 'bg-warning text-dark',
                                        default        => 'bg-secondary',
                                    };
                                ?>
                                <span class="badge <?= $syncBadge ?>"><?= View::e(ucfirst($syncMode)) ?></span>
                            </td>
                            <td>
                                <?php
                                    $status      = $slave['status'] ?? 'pending';
                                    $statusBadge = match($status) {
                                        'configured', 'active' => 'bg-success',
                                        'error'                => 'bg-danger',
                                        default                => 'bg-secondary',
                                    };
                                ?>
                                <span class="badge <?= $statusBadge ?>" id="slave-status-<?= (int)$slave['id'] ?>"><?= View::e(ucfirst($status)) ?></span>
                            </td>
                            <td>
                                <?php
                                    $activeConn = $slave['active_connection'] ?? 'primary';
                                    $connColor  = $activeConn === 'primary' ? '#22c55e' : '#f59e0b';
                                ?>
                                <span style="color:<?= $connColor ?>">
                                    <i class="bi bi-circle-fill me-1" style="font-size:0.5rem;vertical-align:middle;"></i>
                                    <?= $activeConn === 'primary' ? 'Primaria' : 'Fallback' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-light btn-sm" onclick="openEditSlave(<?= (int)$slave['id'] ?>)" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" action="/settings/replication/remove-slave" class="d-inline" id="form-remove-slave-<?= (int)$slave['id'] ?>">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="slave_id" value="<?= (int)$slave['id'] ?>">
                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmRemoveSlave(<?= (int)$slave['id'] ?>, '<?= View::e($slave['name']) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Multi-slave monitor table -->
            <hr class="border-secondary mt-4">
            <h6 class="text-muted mb-3">
                <i class="bi bi-activity me-1"></i>Monitor Multi-Slave
                <span class="text-muted small">(auto-refresh 5s)</span>
            </h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Slave</th>
                            <th>IP Activa</th>
                            <th>Estado PG</th>
                            <th>Lag PG</th>
                            <th>Estado MySQL</th>
                            <th>GTID Position</th>
                            <th>Lag MySQL</th>
                        </tr>
                    </thead>
                    <tbody id="multi-monitor-tbody">
                        <?php foreach ($slaves as $slave): ?>
                        <tr id="monitor-row-<?= (int)$slave['id'] ?>">
                            <td><strong><?= View::e($slave['name']) ?></strong></td>
                            <td>
                                <code id="monitor-ip-<?= (int)$slave['id'] ?>">
                                    <?php
                                        $activeIp = ($slave['active_connection'] ?? 'primary') === 'fallback' && !empty($slave['fallback_ip'])
                                            ? $slave['fallback_ip']
                                            : $slave['primary_ip'];
                                        echo View::e($activeIp);
                                    ?>
                                </code>
                            </td>
                            <td>
                                <?php if ($slave['pg_enabled']): ?>
                                    <span id="monitor-pg-state-<?= (int)$slave['id'] ?>" class="badge bg-secondary">—</span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($slave['pg_enabled']): ?>
                                    <span id="monitor-pg-lag-<?= (int)$slave['id'] ?>">—</span>
                                    <div class="progress mt-1" style="height:3px;width:80px;">
                                        <div id="monitor-pg-bar-<?= (int)$slave['id'] ?>" class="progress-bar bg-secondary" style="width:0%"></div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($slave['mysql_enabled']): ?>
                                    <span id="monitor-mysql-state-<?= (int)$slave['id'] ?>" class="badge bg-secondary">—</span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($slave['mysql_enabled']): ?>
                                    <small id="monitor-gtid-<?= (int)$slave['id'] ?>" class="text-muted text-truncate d-inline-block" style="max-width:200px;">—</small>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($slave['mysql_enabled']): ?>
                                    <span id="monitor-mysql-lag-<?= (int)$slave['id'] ?>">—</span>
                                    <div class="progress mt-1" style="height:3px;width:80px;">
                                        <div id="monitor-mysql-bar-<?= (int)$slave['id'] ?>" class="progress-bar bg-secondary" style="width:0%"></div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Anadir Slave -->
<div class="modal fade" id="addSlaveModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <form method="post" action="/settings/replication/add-slave" id="form-add-slave">
                <?= View::csrf() ?>
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Anadir Slave</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="slave_name" class="form-control" placeholder="slave-01" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IP Primaria <span class="text-danger">*</span></label>
                            <input type="text" name="primary_ip" class="form-control" placeholder="192.168.1.100" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IP Fallback <small class="text-muted">(opcional)</small></label>
                            <input type="text" name="fallback_ip" class="form-control" placeholder="10.0.0.100">
                        </div>

                        <!-- PostgreSQL (solo si PG es master) -->
                        <?php if ($pgRole === 'master'): ?>
                        <div class="col-12"><hr class="border-secondary my-1"><strong class="text-info"><i class="bi bi-database me-1"></i>PostgreSQL</strong></div>
                        <div class="col-md-1 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="pg_enabled" class="form-check-input" id="addPgEnabled">
                                <label class="form-check-label" for="addPgEnabled">On</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Puerto</label>
                            <input type="number" name="pg_port" class="form-control" value="5432">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Usuario</label>
                            <input type="text" name="pg_user" class="form-control" value="replicator">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="pg_pass" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sync Mode</label>
                            <select name="pg_sync_mode" class="form-select">
                                <option value="async">Async</option>
                                <option value="sync">Sync</option>
                                <option value="remote_apply">Remote Apply</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo de Replicacion</label>
                            <div class="d-flex gap-3 mt-1">
                                <div class="form-check">
                                    <input type="radio" name="pg_repl_type" value="physical" class="form-check-input" id="addReplPhysical" checked onchange="toggleLogicalDbs('add')">
                                    <label class="form-check-label" for="addReplPhysical">Physical</label>
                                </div>
                                <div class="form-check">
                                    <input type="radio" name="pg_repl_type" value="logical" class="form-check-input" id="addReplLogical" onchange="toggleLogicalDbs('add')">
                                    <label class="form-check-label" for="addReplLogical">Logical</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4" id="add-logical-dbs" style="display:none;">
                            <label class="form-label">Bases de datos a replicar</label>
                            <select name="pg_logical_databases[]" class="form-select" multiple size="3">
                                <?php foreach ($pgDatabases as $db): ?>
                                <option value="<?= View::e(is_array($db) ? ($db['datname'] ?? $db) : $db) ?>"><?= View::e(is_array($db) ? ($db['datname'] ?? $db) : $db) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end gap-2">
                            <button type="button" class="btn btn-outline-info btn-sm" onclick="testSlaveConn('add', 'pg')">
                                <i class="bi bi-wifi me-1"></i>Test PG
                            </button>
                            <small id="add-pg-test-result" class="text-muted"></small>
                        </div>
                        <?php endif; ?>

                        <!-- MySQL (solo si MySQL es master) -->
                        <?php if ($mysqlRole === 'master'): ?>
                        <div class="col-12"><hr class="border-secondary my-1"><strong style="color:#fbbf24;"><i class="bi bi-database me-1"></i>MySQL</strong></div>
                        <div class="col-md-1 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="mysql_enabled" class="form-check-input" id="addMysqlEnabled">
                                <label class="form-check-label" for="addMysqlEnabled">On</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Puerto</label>
                            <input type="number" name="mysql_port" class="form-control" value="3306">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Usuario</label>
                            <input type="text" name="mysql_user" class="form-control" value="repl_user">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="mysql_pass" class="form-control">
                        </div>
                        <div class="col-md-3 d-flex align-items-end gap-2">
                            <div class="form-check">
                                <input type="checkbox" name="mysql_gtid_enabled" class="form-check-input" id="addGtidEnabled" checked>
                                <label class="form-check-label" for="addGtidEnabled">GTID</label>
                            </div>
                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="testSlaveConn('add', 'mysql')">
                                <i class="bi bi-wifi me-1"></i>Test MySQL
                            </button>
                        </div>
                        <div class="col-12"><small id="add-mysql-test-result" class="text-muted"></small></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar Slave</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Slave -->
<div class="modal fade" id="editSlaveModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <form method="post" action="/settings/replication/update-slave" id="form-edit-slave">
                <?= View::csrf() ?>
                <input type="hidden" name="slave_id" id="edit-slave-id">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Editar Slave</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="slave_name" class="form-control" id="edit-slave-name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IP Primaria <span class="text-danger">*</span></label>
                            <input type="text" name="primary_ip" class="form-control" id="edit-primary-ip" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IP Fallback</label>
                            <input type="text" name="fallback_ip" class="form-control" id="edit-fallback-ip">
                        </div>

                        <div class="col-12"><hr class="border-secondary my-1"><strong class="text-info"><i class="bi bi-database me-1"></i>PostgreSQL</strong></div>
                        <div class="col-md-1 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="pg_enabled" class="form-check-input" id="editPgEnabled">
                                <label class="form-check-label" for="editPgEnabled">On</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Puerto</label>
                            <input type="number" name="pg_port" class="form-control" id="edit-pg-port">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Usuario</label>
                            <input type="text" name="pg_user" class="form-control" id="edit-pg-user">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Password <small class="text-muted">(vacio = sin cambio)</small></label>
                            <input type="password" name="pg_pass" class="form-control" id="edit-pg-pass">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sync Mode</label>
                            <select name="pg_sync_mode" class="form-select" id="edit-pg-sync-mode">
                                <option value="async">Async</option>
                                <option value="sync">Sync</option>
                                <option value="remote_apply">Remote Apply</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo de Replicacion</label>
                            <div class="d-flex gap-3 mt-1">
                                <div class="form-check">
                                    <input type="radio" name="pg_repl_type" value="physical" class="form-check-input" id="editReplPhysical" onchange="toggleLogicalDbs('edit')">
                                    <label class="form-check-label" for="editReplPhysical">Physical</label>
                                </div>
                                <div class="form-check">
                                    <input type="radio" name="pg_repl_type" value="logical" class="form-check-input" id="editReplLogical" onchange="toggleLogicalDbs('edit')">
                                    <label class="form-check-label" for="editReplLogical">Logical</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4" id="edit-logical-dbs" style="display:none;">
                            <label class="form-label">Bases de datos a replicar</label>
                            <select name="pg_logical_databases[]" class="form-select" id="edit-pg-logical-databases" multiple size="3">
                                <?php foreach ($pgDatabases as $db): ?>
                                <option value="<?= View::e(is_array($db) ? ($db['datname'] ?? $db) : $db) ?>"><?= View::e(is_array($db) ? ($db['datname'] ?? $db) : $db) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end gap-2">
                            <button type="button" class="btn btn-outline-info btn-sm" onclick="testSlaveConn('edit', 'pg')">
                                <i class="bi bi-wifi me-1"></i>Test PG
                            </button>
                            <small id="edit-pg-test-result" class="ms-2 text-muted"></small>
                        </div>

                        <div class="col-12"><hr class="border-secondary my-1"><strong style="color:#fbbf24;"><i class="bi bi-database me-1"></i>MySQL</strong></div>
                        <div class="col-md-1 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="mysql_enabled" class="form-check-input" id="editMysqlEnabled">
                                <label class="form-check-label" for="editMysqlEnabled">On</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Puerto</label>
                            <input type="number" name="mysql_port" class="form-control" id="edit-mysql-port">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Usuario</label>
                            <input type="text" name="mysql_user" class="form-control" id="edit-mysql-user">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Password <small class="text-muted">(vacio = sin cambio)</small></label>
                            <input type="password" name="mysql_pass" class="form-control" id="edit-mysql-pass">
                        </div>
                        <div class="col-md-3 d-flex align-items-end gap-2">
                            <div class="form-check">
                                <input type="checkbox" name="mysql_gtid_enabled" class="form-check-input" id="editGtidEnabled">
                                <label class="form-check-label" for="editGtidEnabled">GTID</label>
                            </div>
                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="testSlaveConn('edit', 'mysql')">
                                <i class="bi bi-wifi me-1"></i>Test MySQL
                            </button>
                        </div>
                        <div class="col-12"><small id="edit-mysql-test-result" class="text-muted"></small></div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SECCION 6 — Configuracion Avanzada (si alguno es master)   -->
<!-- ═══════════════════════════════════════════════════════════ -->
<?php if ($isMaster): ?>
<div class="card bg-dark border-secondary mb-4">
    <div class="card-header"><i class="bi bi-sliders me-2"></i>Configuracion Avanzada</div>
    <div class="card-body">
        <form method="post" action="/settings/replication/save-advanced">
            <?= View::csrf() ?>
            <div class="row g-4">
                <!-- PG column -->
                <div class="col-md-6">
                    <h6 class="text-info mb-3"><i class="bi bi-database me-1"></i>PostgreSQL</h6>
                    <?php if ($pgRole === 'master'): ?>
                    <div class="mb-3">
                        <label class="form-label">WAL Level</label>
                        <select name="pg_wal_level" class="form-select">
                            <option value="replica" <?= ($settings['repl_pg_wal_level'] ?? 'replica') === 'replica' ? 'selected' : '' ?>>replica</option>
                            <option value="logical" <?= ($settings['repl_pg_wal_level'] ?? '') === 'logical' ? 'selected' : '' ?>>logical</option>
                        </select>
                        <small class="text-muted">Use "logical" si algun slave usa replicacion logica.</small>
                    </div>
                    <?php
                        $syncSlaveNames = [];
                        foreach ($slaves as $s) {
                            if ($s['pg_enabled'] && ($s['pg_sync_mode'] ?? 'async') !== 'async') {
                                $syncSlaveNames[] = $s['name'];
                            }
                        }
                        $syncNamesStr = $settings['repl_pg_sync_names'] ?? '';
                    ?>
                    <?php if (!empty($syncSlaveNames)): ?>
                    <div class="mb-3">
                        <label class="form-label">Synchronous Standby Names <small class="text-muted">(auto-generado)</small></label>
                        <input type="text" class="form-control" value="<?= View::e($syncNamesStr ?: 'FIRST 1 (' . implode(', ', $syncSlaveNames) . ')') ?>" readonly>
                        <small class="text-muted">Generado desde slaves con sync mode != async.</small>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <p class="text-muted small"><i class="bi bi-info-circle me-1"></i>PostgreSQL no es master — sin opciones de configuracion avanzada.</p>
                    <?php endif; ?>
                </div>

                <!-- MySQL column -->
                <div class="col-md-6">
                    <h6 style="color:#fbbf24;" class="mb-3"><i class="bi bi-database me-1"></i>MySQL</h6>
                    <?php if ($mysqlRole === 'master'): ?>
                    <div class="mb-3">
                        <label class="form-label">Binlog Format</label>
                        <select name="mysql_binlog_format" class="form-select">
                            <?php $bf = $settings['repl_mysql_binlog_format'] ?? 'ROW'; ?>
                            <option value="ROW"       <?= $bf === 'ROW'       ? 'selected' : '' ?>>ROW</option>
                            <option value="MIXED"     <?= $bf === 'MIXED'     ? 'selected' : '' ?>>MIXED</option>
                            <option value="STATEMENT" <?= $bf === 'STATEMENT' ? 'selected' : '' ?>>STATEMENT</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="mysql_gtid_mode" class="form-check-input" id="advGtidMode"
                                   <?= ($settings['repl_mysql_gtid_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="advGtidMode">Habilitar GTID Mode</label>
                        </div>
                    </div>
                    <?php else: ?>
                    <p class="text-muted small"><i class="bi bi-info-circle me-1"></i>MySQL no es master — sin opciones de configuracion avanzada.</p>
                    <?php endif; ?>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Guardar Configuracion Avanzada
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// ═══════════════════════════════════════════════════════════
// Variables globales
// ═══════════════════════════════════════════════════════════
var csrfToken      = document.querySelector('input[name="_csrf_token"]')?.value || '';
var slavesData     = <?= json_encode($slaves ?? []) ?>;
var pgDatabases    = <?= json_encode(array_map(function($d) { return is_array($d) ? ($d['datname'] ?? $d) : $d; }, $pgDatabases ?? [])) ?>;
var mysqlDatabases = <?= json_encode(array_map(function($d) { return is_array($d) ? ($d['Database'] ?? $d['SCHEMA_NAME'] ?? $d) : $d; }, $mysqlDatabases ?? [])) ?>;

// ═══════════════════════════════════════════════════════════
// Save config helper (saves remote IP/port/user/pass before
// test-connection or setup-master/slave)
// ═══════════════════════════════════════════════════════════
function saveEngineConfig(engine) {
    var data = new FormData();
    data.append('_csrf_token', csrfToken);
    data.append('engine',      engine);
    data.append('remote_ip',   document.getElementById(engine + '-remote-ip').value);
    data.append('remote_port', document.getElementById(engine + '-remote-port').value);
    data.append('remote_user', document.getElementById(engine + '-remote-user').value);
    data.append('remote_pass', document.getElementById(engine + '-remote-pass').value);
    return fetch('/settings/replication/save', { method: 'POST', body: data });
}

// ═══════════════════════════════════════════════════════════
// Test de conexion por motor (saves config first, then tests)
// ═══════════════════════════════════════════════════════════
function testEngineConn(engine) {
    var resultEl = document.getElementById(engine + '-conn-result');
    resultEl.textContent = 'Guardando configuracion...';
    resultEl.style.color = '';

    saveEngineConfig(engine)
        .then(function() {
            resultEl.textContent = 'Probando conexion...';

            var data = new FormData();
            data.append('_csrf_token', csrfToken);
            data.append('engine',      engine);
            data.append('host',        document.getElementById(engine + '-remote-ip').value);
            data.append('port',        document.getElementById(engine + '-remote-port').value);
            data.append('user',        document.getElementById(engine + '-remote-user').value);
            data.append('pass',        document.getElementById(engine + '-remote-pass').value);

            return fetch('/settings/replication/test-connection', { method: 'POST', body: data });
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                resultEl.textContent = 'Conexion exitosa' + (d.version ? ' — ' + d.version : '');
                resultEl.style.color = '#22c55e';
            } else {
                resultEl.textContent = 'Error: ' + (d.message || 'desconocido');
                resultEl.style.color = '#ef4444';
            }
        })
        .catch(function() {
            resultEl.textContent = 'Error de red';
            resultEl.style.color = '#ef4444';
        });
}

// ═══════════════════════════════════════════════════════════
// Test conexion en modales de slave
// ═══════════════════════════════════════════════════════════
function testSlaveConn(mode, engine) {
    var formId   = mode === 'add' ? 'form-add-slave' : 'form-edit-slave';
    var form     = document.getElementById(formId);
    var resultEl = document.getElementById(mode + '-' + engine + '-test-result');
    resultEl.textContent = 'Probando...';
    resultEl.style.color = '';

    var data = new FormData();
    data.append('_csrf_token', csrfToken);
    data.append('engine', engine);
    data.append('primary_ip', form.querySelector('[name="primary_ip"]').value);
    data.append('fallback_ip', (form.querySelector('[name="fallback_ip"]') || {value:''}).value);
    data.append('port', form.querySelector('[name="' + engine + '_port"]').value);
    data.append('user', form.querySelector('[name="' + engine + '_user"]').value);
    data.append('pass', form.querySelector('[name="' + engine + '_pass"]').value);

    fetch('/settings/replication/test-slave-connection', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                resultEl.textContent = (d.message || 'OK') + (d.version ? ' — ' + d.version : '');
                resultEl.style.color = '#22c55e';
            } else {
                resultEl.textContent = 'Error: ' + d.message;
                resultEl.style.color = '#ef4444';
            }
        })
        .catch(function() {
            resultEl.textContent = 'Error de red';
            resultEl.style.color = '#ef4444';
        });
}

// ═══════════════════════════════════════════════════════════
// Logical DB toggle
// ═══════════════════════════════════════════════════════════
function toggleLogicalDbs(mode) {
    var el    = document.getElementById(mode + '-logical-dbs');
    var radio = document.getElementById(mode === 'add' ? 'addReplLogical' : 'editReplLogical');
    if (el) el.style.display = radio && radio.checked ? '' : 'none';
}

// ═══════════════════════════════════════════════════════════
// Edit Slave modal
// ═══════════════════════════════════════════════════════════
function openEditSlave(id) {
    var slave = null;
    for (var i = 0; i < slavesData.length; i++) {
        if (parseInt(slavesData[i].id) === id) { slave = slavesData[i]; break; }
    }
    if (!slave) return;

    document.getElementById('edit-slave-id').value          = slave.id;
    document.getElementById('edit-slave-name').value        = slave.name || '';
    document.getElementById('edit-primary-ip').value        = slave.primary_ip || '';
    document.getElementById('edit-fallback-ip').value       = slave.fallback_ip || '';
    document.getElementById('editPgEnabled').checked        = !!slave.pg_enabled;
    document.getElementById('edit-pg-port').value           = slave.pg_port || 5432;
    document.getElementById('edit-pg-user').value           = slave.pg_user || 'replicator';
    document.getElementById('edit-pg-pass').value           = '';
    document.getElementById('edit-pg-sync-mode').value      = slave.pg_sync_mode || 'async';
    document.getElementById('editMysqlEnabled').checked     = !!slave.mysql_enabled;
    document.getElementById('edit-mysql-port').value        = slave.mysql_port || 3306;
    document.getElementById('edit-mysql-user').value        = slave.mysql_user || 'repl_user';
    document.getElementById('edit-mysql-pass').value        = '';
    document.getElementById('editGtidEnabled').checked      = !!slave.mysql_gtid_enabled;

    var replType = slave.pg_repl_type || 'physical';
    document.getElementById('editReplPhysical').checked = (replType === 'physical');
    document.getElementById('editReplLogical').checked  = (replType === 'logical');
    toggleLogicalDbs('edit');

    var selectEl = document.getElementById('edit-pg-logical-databases');
    if (selectEl) {
        var selectedDbs = (slave.pg_logical_databases || '').split(',').map(function(s) { return s.trim(); });
        for (var j = 0; j < selectEl.options.length; j++) {
            selectEl.options[j].selected = selectedDbs.indexOf(selectEl.options[j].value) !== -1;
        }
    }

    ['edit-pg-test-result', 'edit-mysql-test-result'].forEach(function(eid) {
        var el = document.getElementById(eid);
        if (el) { el.textContent = ''; el.style.color = ''; }
    });

    new bootstrap.Modal(document.getElementById('editSlaveModal')).show();
}

// ═══════════════════════════════════════════════════════════
// Helper: append connection fields to a form before submit
// ═══════════════════════════════════════════════════════════
function appendConnFields(form, engine) {
    var fields = {
        remote_ip:   document.getElementById(engine + '-remote-ip').value,
        remote_port: document.getElementById(engine + '-remote-port').value,
        remote_user: document.getElementById(engine + '-remote-user').value,
        remote_pass: document.getElementById(engine + '-remote-pass').value,
    };
    Object.keys(fields).forEach(function(k) {
        // avoid duplicates
        var existing = form.querySelector('input[name="' + k + '"]');
        if (existing) { existing.value = fields[k]; return; }
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = k;
        inp.value = fields[k];
        form.appendChild(inp);
    });
}

// ═══════════════════════════════════════════════════════════
// SwalDark confirmations — PostgreSQL
// ═══════════════════════════════════════════════════════════
function confirmPgMaster() {
    SwalDark.fire({
        title: 'Configurar PostgreSQL como Master',
        html: 'Esto modificara <code>postgresql.conf</code> y <code>pg_hba.conf</code> y reiniciara PostgreSQL.<br><br>'
            + 'La configuracion de conexion sera guardada primero.<br><br>'
            + 'Se crearan backups de los archivos originales.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Configurar Master',
        cancelButtonText: 'Cancelar',
    }).then(function(r) {
        if (!r.isConfirmed) return;
        // Save config first, then submit setup-master form
        saveEngineConfig('pg')
            .then(function() {
                var f = document.getElementById('form-pg-master');
                appendConnFields(f, 'pg');
                f.submit();
            })
            .catch(function() {
                var f = document.getElementById('form-pg-master');
                appendConnFields(f, 'pg');
                f.submit();
            });
    });
}

function confirmPgSlave() {
    var dbList = pgDatabases.length > 0
        ? '<ul class="text-start mt-2 mb-1">' + pgDatabases.map(function(d) { return '<li><code>' + d + '</code></li>'; }).join('') + '</ul>'
        : '<p class="text-muted small">No se detectaron bases de datos.</p>';

    SwalDark.fire({
        title: 'Configurar PostgreSQL como Slave',
        html: '<div class="text-danger fw-bold mb-2"><i class="bi bi-exclamation-triangle me-1"></i>ATENCION: Se borrarán TODAS las bases de datos de PostgreSQL en este servidor y se reemplazarán con una copia del master remoto.</div>'
            + '<div class="mb-2">Bases de datos que seran eliminadas:' + dbList + '</div>'
            + '<div class="mb-2">Escriba <strong>DELETE</strong> para confirmar:</div>'
            + '<input type="text" id="swal-pg-confirm-input" class="swal2-input" placeholder="DELETE">',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Continuar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
        preConfirm: function() {
            var val = document.getElementById('swal-pg-confirm-input').value;
            if (val !== 'DELETE') {
                Swal.showValidationMessage('Debe escribir DELETE exactamente para confirmar.');
                return false;
            }
            return true;
        },
    }).then(function(r) {
        if (!r.isConfirmed) return;
        // Populate hidden fields, set confirm=DELETE, then submit
        var f = document.getElementById('form-pg-slave');
        document.getElementById('pg-slave-confirm').value      = 'DELETE';
        document.getElementById('pg-slave-remote-ip').value    = document.getElementById('pg-remote-ip').value;
        document.getElementById('pg-slave-remote-port').value  = document.getElementById('pg-remote-port').value;
        document.getElementById('pg-slave-remote-user').value  = document.getElementById('pg-remote-user').value;
        document.getElementById('pg-slave-remote-pass').value  = document.getElementById('pg-remote-pass').value;
        f.submit();
    });
}

function confirmPgPromote() {
    SwalDark.fire({
        title: 'Promover PostgreSQL a Master',
        text: 'Este servidor dejara de ser slave de PostgreSQL y se convertira en master independiente.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Promover',
        cancelButtonText: 'Cancelar',
    }).then(function(r) {
        if (r.isConfirmed) document.getElementById('form-pg-promote').submit();
    });
}

function confirmPgReset() {
    SwalDark.fire({
        title: 'Reset PostgreSQL a Standalone',
        text: 'Esto marcara PostgreSQL como standalone. Solo cambia el rol en el panel — no detiene la replicacion activa.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Resetear',
        cancelButtonText: 'Cancelar',
    }).then(function(r) {
        if (r.isConfirmed) document.getElementById('form-pg-reset').submit();
    });
}

// ═══════════════════════════════════════════════════════════
// SwalDark confirmations — MySQL
// ═══════════════════════════════════════════════════════════
function confirmMysqlMaster() {
    SwalDark.fire({
        title: 'Configurar MySQL como Master',
        html: 'Esto modificara <code>my.cnf</code> y reiniciara MySQL.<br><br>'
            + 'La configuracion de conexion sera guardada primero.<br><br>'
            + 'Se crearan backups de los archivos originales.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Configurar Master',
        cancelButtonText: 'Cancelar',
    }).then(function(r) {
        if (!r.isConfirmed) return;
        // Save config first, then submit setup-master form
        saveEngineConfig('mysql')
            .then(function() {
                var f = document.getElementById('form-mysql-master');
                appendConnFields(f, 'mysql');
                f.submit();
            })
            .catch(function() {
                var f = document.getElementById('form-mysql-master');
                appendConnFields(f, 'mysql');
                f.submit();
            });
    });
}

function confirmMysqlSlave() {
    var dbList = mysqlDatabases.length > 0
        ? '<ul class="text-start mt-2 mb-1">' + mysqlDatabases.map(function(d) { return '<li><code>' + d + '</code></li>'; }).join('') + '</ul>'
        : '<p class="text-muted small">No se detectaron bases de datos.</p>';

    SwalDark.fire({
        title: 'Configurar MySQL como Slave',
        html: '<div class="text-danger fw-bold mb-2"><i class="bi bi-exclamation-triangle me-1"></i>ATENCION: Se borrarán TODAS las bases de datos de MySQL en este servidor y se reemplazarán con una copia del master remoto.</div>'
            + '<div class="mb-2">Bases de datos que seran eliminadas:' + dbList + '</div>'
            + '<div class="mb-2">Escriba <strong>DELETE</strong> para confirmar:</div>'
            + '<input type="text" id="swal-mysql-confirm-input" class="swal2-input" placeholder="DELETE">',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Continuar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
        preConfirm: function() {
            var val = document.getElementById('swal-mysql-confirm-input').value;
            if (val !== 'DELETE') {
                Swal.showValidationMessage('Debe escribir DELETE exactamente para confirmar.');
                return false;
            }
            return true;
        },
    }).then(function(r) {
        if (!r.isConfirmed) return;
        // Populate hidden fields, set confirm=DELETE, then submit
        var f = document.getElementById('form-mysql-slave');
        document.getElementById('mysql-slave-confirm').value      = 'DELETE';
        document.getElementById('mysql-slave-remote-ip').value    = document.getElementById('mysql-remote-ip').value;
        document.getElementById('mysql-slave-remote-port').value  = document.getElementById('mysql-remote-port').value;
        document.getElementById('mysql-slave-remote-user').value  = document.getElementById('mysql-remote-user').value;
        document.getElementById('mysql-slave-remote-pass').value  = document.getElementById('mysql-remote-pass').value;
        f.submit();
    });
}

function confirmMysqlPromote() {
    SwalDark.fire({
        title: 'Promover MySQL a Master',
        text: 'Este servidor dejara de ser slave de MySQL y se convertira en master independiente.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Promover',
        cancelButtonText: 'Cancelar',
    }).then(function(r) {
        if (r.isConfirmed) document.getElementById('form-mysql-promote').submit();
    });
}

function confirmMysqlReset() {
    SwalDark.fire({
        title: 'Reset MySQL a Standalone',
        text: 'Esto marcara MySQL como standalone. Solo cambia el rol en el panel — no detiene la replicacion activa.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Resetear',
        cancelButtonText: 'Cancelar',
    }).then(function(r) {
        if (r.isConfirmed) document.getElementById('form-mysql-reset').submit();
    });
}

// ═══════════════════════════════════════════════════════════
// SwalDark confirmations — Slaves
// ═══════════════════════════════════════════════════════════
function confirmApplyMaster() {
    SwalDark.fire({
        title: 'Aplicar Configuracion Master',
        html: 'Esto configurara <code>pg_hba.conf</code>, usuarios de replicacion MySQL y parametros para <strong>todos los slaves</strong> registrados.<br><br>Los servicios de base de datos seran reiniciados.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Aplicar',
        cancelButtonText: 'Cancelar',
    }).then(function(r) {
        if (r.isConfirmed) document.getElementById('form-apply-master').submit();
    });
}

function confirmRemoveSlave(id, name) {
    SwalDark.fire({
        title: 'Eliminar Slave',
        html: 'Se eliminara el slave <strong>' + name + '</strong> y sus entradas en pg_hba.conf y usuarios MySQL asociados.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
    }).then(function(r) {
        if (r.isConfirmed) document.getElementById('form-remove-slave-' + id).submit();
    });
}

// ═══════════════════════════════════════════════════════════
// Countdown + Auto-refresh
// ═══════════════════════════════════════════════════════════
<?php if ($isActive): ?>
var countdownVal = 5;
var countdownEl  = document.getElementById('countdown');

function tickCountdown() {
    countdownVal--;
    if (countdownVal <= 0) {
        countdownVal = 5;
        refreshAllStatus();
    }
    if (countdownEl) countdownEl.textContent = countdownVal;
}

// Initial fetch immediately, then tick every second
refreshAllStatus();
setInterval(tickCountdown, 1000);

function refreshAllStatus() {
    <?php if ($isMaster && !empty($slaves)): ?>
    // Multi-slave polling
    fetch('/settings/replication/slave-status')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.slaves) data.slaves.forEach(updateSlaveMonitorRow);
            updateStatusDiagram(data);
            checkAlerts(data);
        })
        .catch(function() {});
    <?php else: ?>
    // Single-node polling
    fetch('/settings/replication/status')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            updatePgMonitor(data);
            updateMysqlMonitor(data);
            updateStatusDiagram(data);
            checkAlerts(data);
        })
        .catch(function() {});
    <?php endif; ?>
}

// ── Status diagram (Seccion 1) ───────────────────────────────────────────────
function updateStatusDiagram(data) {
    // PG
    if (data.pg) {
        var pgBadgeEl = document.getElementById('status-pg-badge');
        var pgStatus  = data.pg.status || data.pg.state || '';

        if (pgBadgeEl) {
            if (pgStatus === 'streaming' || pgStatus === 'master') {
                pgBadgeEl.innerHTML = '<span class="badge bg-success"><i class="bi bi-arrow-up-circle me-1"></i>Master</span>';
            } else if (pgStatus === 'slave' || pgStatus === 'replica') {
                pgBadgeEl.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-arrow-down-circle me-1"></i>Slave</span>';
            } else if (pgStatus === 'error' || pgStatus === 'disconnected') {
                pgBadgeEl.innerHTML = '<span class="badge bg-danger"><i class="bi bi-exclamation-octagon me-1"></i>Error</span>';
            }
        }
        var lagSec = data.pg.lag_seconds;
        if (lagSec !== undefined && lagSec !== null) {
            var lagEl = document.getElementById('status-pg-lag-text');
            if (lagEl) lagEl.textContent = 'lag: ' + lagSec + 's';
        }
    }

    // MySQL
    if (data.mysql) {
        var mysqlBadgeEl = document.getElementById('status-mysql-badge');
        var ioR  = data.mysql.Slave_IO_Running  || data.mysql.Replica_IO_Running  || '';
        var sqlR = data.mysql.Slave_SQL_Running || data.mysql.Replica_SQL_Running || '';
        if (mysqlBadgeEl) {
            if (data.mysql.role === 'master' || data.mysql.File) {
                mysqlBadgeEl.innerHTML = '<span class="badge bg-success"><i class="bi bi-arrow-up-circle me-1"></i>Master</span>';
            } else if (ioR === 'Yes' && sqlR === 'Yes') {
                var lagMysql = data.mysql.Seconds_Behind_Master !== undefined ? data.mysql.Seconds_Behind_Master : (data.mysql.Seconds_Behind_Source !== undefined ? data.mysql.Seconds_Behind_Source : null);
                var lagText  = lagMysql !== null ? ' (lag: ' + lagMysql + 's)' : '';
                var lagEl2   = document.getElementById('status-mysql-lag-text');
                if (lagEl2) lagEl2.textContent = 'Replicando' + lagText;
            } else if (ioR || sqlR) {
                mysqlBadgeEl.innerHTML = '<span class="badge bg-danger"><i class="bi bi-exclamation-octagon me-1"></i>Error</span>';
            }
        }
    }
}

<?php if ($isMaster && !empty($slaves)): ?>
// ── Multi-slave row updater ──────────────────────────────────────────────────
function updateSlaveMonitorRow(slave) {
    var id = slave.id;

    var ipEl = document.getElementById('monitor-ip-' + id);
    if (ipEl) {
        ipEl.textContent = (slave.active_connection === 'fallback' && slave.fallback_ip) ? slave.fallback_ip : slave.primary_ip;
    }

    if (slave.pg_enabled && slave.pg_status) {
        var pgState = document.getElementById('monitor-pg-state-' + id);
        if (pgState) {
            var st = slave.pg_status.state || 'disconnected';
            pgState.textContent = st;
            pgState.className = 'badge ' + (st === 'streaming' ? 'bg-success' : st === 'catchup' ? 'bg-warning text-dark' : 'bg-danger');
        }
        var pgLag = document.getElementById('monitor-pg-lag-' + id);
        if (pgLag) pgLag.textContent = formatBytes(slave.pg_status.lag_bytes || 0);
        var pgBar = document.getElementById('monitor-pg-bar-' + id);
        if (pgBar) {
            var lag = slave.pg_status.lag_bytes || 0;
            pgBar.style.width = lag === 0 ? '100%' : Math.max(10, 100 - Math.min(lag / 10000, 90)) + '%';
            pgBar.className   = 'progress-bar ' + (lag === 0 ? 'bg-success' : lag < 1000000 ? 'bg-warning' : 'bg-danger');
        }
    }

    if (slave.mysql_enabled && slave.mysql_status) {
        var mysqlState = document.getElementById('monitor-mysql-state-' + id);
        if (mysqlState) {
            mysqlState.textContent = 'Activo' + (slave.mysql_status.gtid_mode !== 'OFF' ? ' (GTID)' : '');
            mysqlState.className   = 'badge bg-success';
        }
        var gtidEl = document.getElementById('monitor-gtid-' + id);
        if (gtidEl) { gtidEl.textContent = slave.mysql_status.gtid_executed || '—'; gtidEl.title = slave.mysql_status.gtid_executed || ''; }
        var mLag = document.getElementById('monitor-mysql-lag-' + id);
        if (mLag) mLag.textContent = slave.mysql_status.position || '—';
        var mBar = document.getElementById('monitor-mysql-bar-' + id);
        if (mBar) { mBar.style.width = '100%'; mBar.className = 'progress-bar bg-success'; }
    }
}
<?php endif; ?>

<?php if ($pgRole !== 'standalone'): ?>
// ── PG Monitor (Seccion 3) ───────────────────────────────────────────────────
function updatePgMonitor(data) {
    if (!data.pg) return;
    var pg    = data.pg;
    var badge = document.getElementById('pg-monitor-badge');
    var bar   = document.getElementById('pg-monitor-bar');

    <?php if ($pgRole === 'master'): ?>
    setEl('pgm-state',      pg.state     || 'Sin replicas');
    setEl('pgm-sent-lsn',   pg.sent_lsn  || '—');
    setEl('pgm-write-lsn',  pg.write_lsn || '—');
    setEl('pgm-flush-lsn',  pg.flush_lsn || '—');
    setEl('pgm-replay-lsn', pg.replay_lsn || '—');
    setEl('pgm-lag',        formatBytes(pg.lag_bytes || 0));
    if (badge) {
        badge.textContent = pg.state || 'Sin replicas';
        badge.className   = 'badge ms-2 ' + (pg.state === 'streaming' ? 'bg-success' : pg.state ? 'bg-warning text-dark' : 'bg-secondary');
    }
    if (bar) {
        var lb = pg.lag_bytes || 0;
        bar.style.width = lb === 0 ? '100%' : Math.max(10, 100 - Math.min(lb / 10000, 90)) + '%';
        bar.className   = 'progress-bar ' + (lb === 0 ? 'bg-success' : lb < 1000000 ? 'bg-warning' : 'bg-danger');
    }
    <?php elseif ($pgRole === 'slave'): ?>
    setEl('pgm-state',       pg.status      || 'disconnected');
    setEl('pgm-sender',      (pg.sender_host || '—') + ':' + (pg.sender_port || ''));
    setEl('pgm-receive-lsn', pg.receive_lsn  || '—');
    setEl('pgm-replay-lsn',  pg.replay_lsn   || '—');
    setEl('pgm-replay-time', pg.replay_time  || '—');
    setEl('pgm-lag',         (pg.lag_seconds !== undefined ? pg.lag_seconds + 's' : '—'));
    if (badge) {
        badge.textContent = pg.status || 'disconnected';
        badge.className   = 'badge ms-2 ' + (pg.status === 'streaming' ? 'bg-success' : pg.status ? 'bg-warning text-dark' : 'bg-danger');
    }
    if (bar) {
        var ls = pg.lag_seconds || 0;
        bar.style.width = ls === 0 ? '100%' : Math.max(10, 100 - Math.min(ls, 90)) + '%';
        bar.className   = 'progress-bar ' + (ls === 0 ? 'bg-success' : ls < 30 ? 'bg-warning' : 'bg-danger');
    }
    <?php endif; ?>
}
<?php endif; ?>

<?php if ($mysqlRole !== 'standalone'): ?>
// ── MySQL Monitor (Seccion 4) ────────────────────────────────────────────────
function updateMysqlMonitor(data) {
    if (!data.mysql) return;
    var m     = data.mysql;
    var badge = document.getElementById('mysql-monitor-badge');
    var bar   = document.getElementById('mysql-monitor-bar');

    <?php if ($mysqlRole === 'master'): ?>
    setEl('mysqlm-file',      m.File         || '—');
    setEl('mysqlm-position',  m.Position     || '—');
    setEl('mysqlm-gtid-mode', m.Gtid_Mode    || 'OFF');
    setEl('mysqlm-gtid-exec', m.Gtid_Executed || '—');
    if (badge) { badge.textContent = 'Activo'; badge.className = 'badge ms-2 bg-success'; }
    if (bar)   { bar.style.width = '100%'; bar.className = 'progress-bar bg-success'; }

    <?php elseif ($mysqlRole === 'slave'): ?>
    var ioR  = m.Slave_IO_Running  || m.Replica_IO_Running  || '—';
    var sqlR = m.Slave_SQL_Running || m.Replica_SQL_Running || '—';
    var lag  = m.Seconds_Behind_Master !== undefined ? m.Seconds_Behind_Master : (m.Seconds_Behind_Source !== undefined ? m.Seconds_Behind_Source : '—');
    var err  = m.Last_Error || m.Last_SQL_Error || m.Last_IO_Error || '';

    setEl('mysqlm-io',         ioR);
    setEl('mysqlm-sql',        sqlR);
    setEl('mysqlm-lag',        lag === null ? 'NULL' : (lag === '—' ? '—' : lag + 's'));
    setEl('mysqlm-master-log', m.Master_Log_File  || m.Source_Log_File  || '—');
    setEl('mysqlm-read-pos',   m.Read_Master_Log_Pos || m.Read_Source_Log_Pos || '—');
    setEl('mysqlm-gtid-mode',  m.Gtid_Mode || 'OFF');
    setEl('mysqlm-gtid-exec',  m.Executed_Gtid_Set || m.Gtid_Executed || '—');
    setEl('mysqlm-error',      err || 'Ninguno');

    var ioEl = document.getElementById('mysqlm-io');
    if (ioEl)  ioEl.style.color  = ioR  === 'Yes' ? '#22c55e' : '#ef4444';
    var sqlEl = document.getElementById('mysqlm-sql');
    if (sqlEl) sqlEl.style.color = sqlR === 'Yes' ? '#22c55e' : '#ef4444';

    if (badge) {
        var ok = (ioR === 'Yes' && sqlR === 'Yes');
        badge.textContent = ok ? 'Replicando' : 'Error';
        badge.className   = 'badge ms-2 ' + (ok ? 'bg-success' : 'bg-danger');
    }
    if (bar) {
        var lagNum = parseInt(lag) || 0;
        bar.style.width = lagNum === 0 ? '100%' : Math.max(10, 100 - Math.min(lagNum, 90)) + '%';
        bar.className   = 'progress-bar ' + ((ioR !== 'Yes' || sqlR !== 'Yes') ? 'bg-danger' : lagNum === 0 ? 'bg-success' : 'bg-warning');
    }
    <?php endif; ?>
}
<?php endif; ?>

// ── Alert global ─────────────────────────────────────────────────────────────
function checkAlerts(data) {
    var alertEl  = document.getElementById('repl-alert');
    var msgEl    = document.getElementById('repl-alert-msg');
    var problems = [];

    <?php if ($pgRole === 'slave'): ?>
    if (data.pg && data.pg.status !== 'streaming') {
        problems.push('PostgreSQL: ' + (data.pg.status || 'desconectado'));
    }
    <?php endif; ?>

    <?php if ($mysqlRole === 'slave'): ?>
    if (data.mysql) {
        var io  = data.mysql.Slave_IO_Running  || data.mysql.Replica_IO_Running;
        var sql = data.mysql.Slave_SQL_Running || data.mysql.Replica_SQL_Running;
        if (io !== 'Yes' || sql !== 'Yes') {
            problems.push('MySQL: IO=' + io + ', SQL=' + sql);
        }
    }
    <?php endif; ?>

    if (problems.length > 0) {
        msgEl.textContent     = problems.join(' | ');
        alertEl.style.display = 'block';
    } else {
        alertEl.style.display = 'none';
    }
}

<?php endif; // $isActive ?>

// ═══════════════════════════════════════════════════════════
// Utilidades
// ═══════════════════════════════════════════════════════════
function setEl(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = val;
}

function formatBytes(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
}
</script>
