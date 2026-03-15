<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<!-- Aviso firewall -->
<?php if ($role === 'standalone'): ?>
<div class="alert" style="background: rgba(251,191,36,0.1); border: 1px solid rgba(251,191,36,0.3); color: #fbbf24;">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong>Importante:</strong> Antes de configurar la replicacion, asegurese de que los puertos 5432 (PostgreSQL) y 3306 (MySQL) esten abiertos entre los servidores.
</div>
<?php endif; ?>

<!-- Alert si replicacion rota -->
<div id="repl-alert" class="alert mb-3" style="display:none; background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); color: #ef4444;">
    <i class="bi bi-exclamation-octagon me-2"></i>
    <strong>Replicacion con problemas:</strong> <span id="repl-alert-msg"></span>
</div>

<div class="row g-3 mb-3">
    <!-- Rol del Servidor -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Rol del Servidor</div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="fs-5">Rol actual:</span>
                    <?php
                        $badgeClass = match($role) {
                            'master' => 'bg-success',
                            'slave'  => 'bg-info',
                            default  => 'bg-secondary',
                        };
                        $roleLabel = match($role) {
                            'master' => 'Master',
                            'slave'  => 'Slave',
                            default  => 'Standalone',
                        };
                    ?>
                    <span class="badge <?= $badgeClass ?> fs-6"><?= $roleLabel ?></span>
                </div>

                <div class="mb-3">
                    <small class="text-muted">
                        PostgreSQL: <strong><?= View::e($pgVersion ?? 'No detectado') ?></strong> |
                        MySQL: <strong><?= View::e($mysqlVersion ?? 'No detectado') ?></strong>
                    </small>
                </div>

                <?php if (!empty($settings['repl_configured_at'])): ?>
                <div class="mb-3">
                    <small class="text-muted">Configurado: <?= View::e($settings['repl_configured_at']) ?></small>
                </div>
                <?php endif; ?>

                <?php if ($role === 'standalone'): ?>
                    <div class="d-flex gap-2 mt-3">
                        <form method="post" action="/settings/replication/setup-master" id="form-master">
                            <?= View::csrf() ?>
                            <button type="button" class="btn btn-success" onclick="confirmSetupMaster()">
                                <i class="bi bi-arrow-up-circle me-1"></i>Configurar como Master
                            </button>
                        </form>
                        <form method="post" action="/settings/replication/setup-slave" id="form-slave">
                            <?= View::csrf() ?>
                            <input type="hidden" name="confirm" value="">
                            <button type="button" class="btn btn-info" onclick="confirmSetupSlave()">
                                <i class="bi bi-arrow-down-circle me-1"></i>Configurar como Slave
                            </button>
                        </form>
                    </div>
                <?php elseif ($role === 'slave'): ?>
                    <form method="post" action="/settings/replication/promote" id="form-promote">
                        <?= View::csrf() ?>
                        <button type="button" class="btn btn-warning" onclick="confirmPromote()">
                            <i class="bi bi-arrow-up-circle me-1"></i>Promover a Master
                        </button>
                    </form>
                <?php elseif ($role === 'master'): ?>
                    <form method="post" action="/settings/replication/demote" id="form-demote">
                        <?= View::csrf() ?>
                        <button type="button" class="btn btn-danger" onclick="confirmDemote()">
                            <i class="bi bi-arrow-down-circle me-1"></i>Degradar a Slave
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($role !== 'standalone'): ?>
                <div class="mt-3">
                    <form method="post" action="/settings/replication/setup-master">
                        <?= View::csrf() ?>
                        <input type="hidden" name="reset" value="standalone">
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmReset(this.form)">
                            <i class="bi bi-x-circle me-1"></i>Resetear a Standalone
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Conexion Remota -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-cloud-arrow-up me-2"></i>Conexion Remota</div>
            <div class="card-body">
                <form method="post" action="/settings/replication/save">
                    <?= View::csrf() ?>

                    <div class="mb-3">
                        <label class="form-label">IP del Servidor Remoto</label>
                        <input type="text" name="remote_ip" class="form-control" value="<?= View::e($settings['repl_remote_ip'] ?? '') ?>" placeholder="192.168.1.100">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-12"><strong class="text-info"><i class="bi bi-database me-1"></i>PostgreSQL</strong></div>
                        <div class="col-md-3">
                            <label class="form-label">Puerto</label>
                            <input type="number" name="pg_port" class="form-control" value="<?= View::e($settings['repl_pg_port'] ?? '5432') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Usuario Replicacion</label>
                            <input type="text" name="pg_user" class="form-control" value="<?= View::e($settings['repl_pg_user'] ?? 'replicator') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="pg_pass" class="form-control" placeholder="<?= !empty($settings['repl_pg_pass']) ? '••••••••' : '' ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-info btn-sm w-100" onclick="testConn('pg')">Test</button>
                        </div>
                        <div class="col-12"><small id="pg-test-result" class="text-muted"></small></div>
                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" name="pg_enabled" class="form-check-input" id="pgEnabled" <?= ($settings['repl_pg_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pgEnabled">Habilitar replicacion PostgreSQL</label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-12"><strong style="color: #fbbf24;"><i class="bi bi-database me-1"></i>MySQL</strong></div>
                        <div class="col-md-3">
                            <label class="form-label">Puerto</label>
                            <input type="number" name="mysql_port" class="form-control" value="<?= View::e($settings['repl_mysql_port'] ?? '3306') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Usuario Replicacion</label>
                            <input type="text" name="mysql_user" class="form-control" value="<?= View::e($settings['repl_mysql_user'] ?? 'repl_user') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="mysql_pass" class="form-control" placeholder="<?= !empty($settings['repl_mysql_pass']) ? '••••••••' : '' ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-warning btn-sm w-100" onclick="testConn('mysql')">Test</button>
                        </div>
                        <div class="col-12"><small id="mysql-test-result" class="text-muted"></small></div>
                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" name="mysql_enabled" class="form-check-input" id="mysqlEnabled" <?= ($settings['repl_mysql_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="mysqlEnabled">Habilitar replicacion MySQL</label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar Configuracion</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Monitor de Replicacion -->
<?php if ($role !== 'standalone'): ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-activity me-2"></i>Monitor de Replicacion</span>
        <span class="text-muted small"><i class="bi bi-arrow-repeat spin-icon me-1" id="refresh-icon"></i>Actualizando cada 5s</span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <!-- PostgreSQL -->
            <?php if ($pgEnabled): ?>
            <div class="col-lg-6">
                <h6 class="text-info mb-3"><i class="bi bi-database me-1"></i>PostgreSQL <span id="pg-badge" class="badge bg-secondary ms-2">—</span></h6>

                <?php if ($role === 'master'): ?>
                <table class="table table-sm">
                    <tr><td class="text-muted" style="width:40%">Estado</td><td id="pg-state">—</td></tr>
                    <tr><td class="text-muted">Cliente</td><td id="pg-client">—</td></tr>
                    <tr><td class="text-muted">Sent LSN</td><td id="pg-sent-lsn">—</td></tr>
                    <tr><td class="text-muted">Write LSN</td><td id="pg-write-lsn">—</td></tr>
                    <tr><td class="text-muted">Flush LSN</td><td id="pg-flush-lsn">—</td></tr>
                    <tr><td class="text-muted">Replay LSN</td><td id="pg-replay-lsn">—</td></tr>
                    <tr><td class="text-muted">Lag (bytes)</td><td id="pg-lag-bytes">—</td></tr>
                    <tr><td class="text-muted">Sync</td><td id="pg-sync">—</td></tr>
                </table>
                <?php else: ?>
                <table class="table table-sm">
                    <tr><td class="text-muted" style="width:40%">Estado</td><td id="pg-state">—</td></tr>
                    <tr><td class="text-muted">Master</td><td id="pg-sender">—</td></tr>
                    <tr><td class="text-muted">Receive LSN</td><td id="pg-receive-lsn">—</td></tr>
                    <tr><td class="text-muted">Replay LSN</td><td id="pg-replay-lsn">—</td></tr>
                    <tr><td class="text-muted">Lag (segundos)</td><td id="pg-lag-seconds">—</td></tr>
                    <tr><td class="text-muted">Ultimo replay</td><td id="pg-replay-time">—</td></tr>
                </table>
                <?php endif; ?>

                <div class="progress mt-2" style="height:6px;">
                    <div id="pg-progress" class="progress-bar bg-success" style="width:100%"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- MySQL -->
            <?php if ($mysqlEnabled): ?>
            <div class="col-lg-6">
                <h6 style="color:#fbbf24;" class="mb-3"><i class="bi bi-database me-1"></i>MySQL <span id="mysql-badge" class="badge bg-secondary ms-2">—</span></h6>

                <?php if ($role === 'master'): ?>
                <table class="table table-sm">
                    <tr><td class="text-muted" style="width:40%">Binlog File</td><td id="mysql-file">—</td></tr>
                    <tr><td class="text-muted">Position</td><td id="mysql-position">—</td></tr>
                    <tr><td class="text-muted">Binlog Do DB</td><td id="mysql-dodb">—</td></tr>
                </table>
                <?php else: ?>
                <table class="table table-sm">
                    <tr><td class="text-muted" style="width:40%">IO Thread</td><td id="mysql-io">—</td></tr>
                    <tr><td class="text-muted">SQL Thread</td><td id="mysql-sql">—</td></tr>
                    <tr><td class="text-muted">Lag (segundos)</td><td id="mysql-lag">—</td></tr>
                    <tr><td class="text-muted">Master Log</td><td id="mysql-master-log">—</td></tr>
                    <tr><td class="text-muted">Read Position</td><td id="mysql-read-pos">—</td></tr>
                    <tr><td class="text-muted">Ultimo Error</td><td id="mysql-error" class="text-danger">—</td></tr>
                </table>
                <?php endif; ?>

                <div class="progress mt-2" style="height:6px;">
                    <div id="mysql-progress" class="progress-bar bg-success" style="width:100%"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Operaciones -->
<div class="card">
    <div class="card-header"><i class="bi bi-lightning me-2"></i>Operaciones de Switchover</div>
    <div class="card-body">
        <?php if ($role === 'slave'): ?>
        <p class="text-muted mb-3">Promover este servidor de Slave a Master. El master actual dejara de recibir escrituras y debera ser reconfigurado como slave manualmente o desde su propio panel.</p>
        <form method="post" action="/settings/replication/promote" id="form-promote-op">
            <?= View::csrf() ?>
            <button type="button" class="btn btn-warning" onclick="confirmPromote()">
                <i class="bi bi-arrow-up-circle me-1"></i>Promover a Master
            </button>
        </form>
        <?php elseif ($role === 'master'): ?>
        <p class="text-muted mb-3">Degradar este servidor de Master a Slave. Necesita la IP del servidor que sera el nuevo Master (debe estar ya promovido o configurado como master).</p>
        <form method="post" action="/settings/replication/demote" id="form-demote-op">
            <?= View::csrf() ?>
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">IP del nuevo Master</label>
                    <input type="text" name="new_master_ip" class="form-control" placeholder="192.168.1.100" required>
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-danger" onclick="confirmDemote()">
                        <i class="bi bi-arrow-down-circle me-1"></i>Degradar a Slave
                    </button>
                </div>
            </div>
        </form>
        <?php else: ?>
        <p class="text-muted">Las operaciones de switchover solo estan disponibles cuando el servidor esta configurado como Master o Slave.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.spin-icon { display: inline-block; animation: spin 1s linear infinite; }
</style>

<script>
// CSRF token for AJAX
var csrfToken = document.querySelector('input[name="_csrf_token"]')?.value || '';

// Test connection
function testConn(engine) {
    var resultEl = document.getElementById(engine + '-test-result');
    resultEl.textContent = 'Probando conexion...';
    resultEl.className = 'text-muted small';

    var form = document.querySelector('form[action="/settings/replication/save"]');
    var prefix = engine === 'pg' ? 'pg' : 'mysql';
    var data = new FormData();
    data.append('_csrf_token', csrfToken);
    data.append('engine', engine);
    data.append('host', form.querySelector('[name="remote_ip"]').value);
    data.append('port', form.querySelector('[name="' + prefix + '_port"]').value);
    data.append('user', form.querySelector('[name="' + prefix + '_user"]').value);
    data.append('pass', form.querySelector('[name="' + prefix + '_pass"]').value);

    fetch('/settings/replication/test-connection', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                resultEl.textContent = 'Conexion exitosa — ' + d.version;
                resultEl.className = 'small';
                resultEl.style.color = '#22c55e';
            } else {
                resultEl.textContent = 'Error: ' + d.message;
                resultEl.className = 'small';
                resultEl.style.color = '#ef4444';
            }
        })
        .catch(function() {
            resultEl.textContent = 'Error de red';
            resultEl.style.color = '#ef4444';
        });
}

// SwalDark confirmations
function confirmSetupMaster() {
    SwalDark.fire({
        title: 'Configurar como Master',
        html: 'Esto modificara <code>postgresql.conf</code>, <code>pg_hba.conf</code> y/o <code>my.cnf</code> y reiniciara los servicios de base de datos.<br><br>Se crearan backups de los archivos originales.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Configurar Master',
        cancelButtonText: 'Cancelar',
    }).then(function(result) {
        if (result.isConfirmed) document.getElementById('form-master').submit();
    });
}

function confirmSetupSlave() {
    SwalDark.fire({
        title: 'Configurar como Slave',
        html: '<strong class="text-danger">ATENCION:</strong> Para PostgreSQL, esto ejecutara <code>pg_basebackup</code> que <strong>borrara todos los datos locales de PostgreSQL</strong> y los reemplazara con una copia del master.<br><br>Asegurese de tener un backup si hay datos importantes en este servidor.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Si, configurar como Slave',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
    }).then(function(result) {
        if (result.isConfirmed) {
            var form = document.getElementById('form-slave');
            form.querySelector('[name="confirm"]').value = 'yes';
            form.submit();
        }
    });
}

function confirmPromote() {
    SwalDark.fire({
        title: 'Promover a Master',
        text: 'Este servidor dejara de ser slave y se convertira en master independiente. El antiguo master debera ser reconfigurado.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Promover',
        cancelButtonText: 'Cancelar',
    }).then(function(result) {
        if (result.isConfirmed) {
            var form = document.getElementById('form-promote') || document.getElementById('form-promote-op');
            form.submit();
        }
    });
}

function confirmDemote() {
    SwalDark.fire({
        title: 'Degradar a Slave',
        html: 'Este servidor dejara de aceptar escrituras y se reconfigurara como slave del nuevo master.<br><br><strong class="text-danger">Los datos locales de PostgreSQL seran reemplazados.</strong>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Degradar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
    }).then(function(result) {
        if (result.isConfirmed) {
            var form = document.getElementById('form-demote') || document.getElementById('form-demote-op');
            form.submit();
        }
    });
}

function confirmReset(form) {
    SwalDark.fire({
        title: 'Resetear a Standalone',
        text: 'Esto marcara el servidor como standalone. No detendra la replicacion activa — solo cambia el rol en el panel.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Resetear',
        cancelButtonText: 'Cancelar',
    }).then(function(result) {
        if (result.isConfirmed) form.submit();
    });
}

// Auto-refresh status
<?php if ($role !== 'standalone'): ?>
var refreshInterval = setInterval(refreshStatus, 5000);

function refreshStatus() {
    fetch('/settings/replication/status')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            updatePgStatus(data);
            updateMysqlStatus(data);
            checkAlerts(data);
        })
        .catch(function() {});
}

function updatePgStatus(data) {
    if (!data.pg) return;
    var pg = data.pg;
    var role = data.role;
    var badge = document.getElementById('pg-badge');

    if (role === 'master') {
        setEl('pg-state', pg.state || 'Sin replicas');
        setEl('pg-client', pg.client_addr || '—');
        setEl('pg-sent-lsn', pg.sent_lsn || '—');
        setEl('pg-write-lsn', pg.write_lsn || '—');
        setEl('pg-flush-lsn', pg.flush_lsn || '—');
        setEl('pg-replay-lsn', pg.replay_lsn || '—');
        setEl('pg-lag-bytes', formatBytes(pg.lag_bytes || 0));
        setEl('pg-sync', pg.sync_state || '—');

        if (badge) {
            badge.textContent = pg.state || 'Sin replicas';
            badge.className = 'badge ms-2 ' + (pg.state === 'streaming' ? 'bg-success' : pg.state ? 'bg-warning' : 'bg-secondary');
        }

        var bar = document.getElementById('pg-progress');
        if (bar) {
            var lag = pg.lag_bytes || 0;
            bar.style.width = lag === 0 ? '100%' : Math.max(10, 100 - Math.min(lag / 10000, 90)) + '%';
            bar.className = 'progress-bar ' + (lag === 0 ? 'bg-success' : lag < 1000000 ? 'bg-warning' : 'bg-danger');
        }
    } else {
        setEl('pg-state', pg.status || 'disconnected');
        setEl('pg-sender', (pg.sender_host || '—') + ':' + (pg.sender_port || ''));
        setEl('pg-receive-lsn', pg.receive_lsn || '—');
        setEl('pg-replay-lsn', pg.replay_lsn || '—');
        setEl('pg-lag-seconds', pg.lag_seconds + 's');
        setEl('pg-replay-time', pg.replay_time || '—');

        if (badge) {
            badge.textContent = pg.status || 'disconnected';
            badge.className = 'badge ms-2 ' + (pg.status === 'streaming' ? 'bg-success' : pg.status ? 'bg-warning' : 'bg-danger');
        }

        var bar = document.getElementById('pg-progress');
        if (bar) {
            var lag = pg.lag_seconds || 0;
            bar.style.width = lag === 0 ? '100%' : Math.max(10, 100 - Math.min(lag, 90)) + '%';
            bar.className = 'progress-bar ' + (lag === 0 ? 'bg-success' : lag < 30 ? 'bg-warning' : 'bg-danger');
        }
    }
}

function updateMysqlStatus(data) {
    if (!data.mysql) return;
    var m = data.mysql;
    var role = data.role;
    var badge = document.getElementById('mysql-badge');

    if (role === 'master') {
        setEl('mysql-file', m.File || '—');
        setEl('mysql-position', m.Position || '—');
        setEl('mysql-dodb', m.Binlog_Do_DB || 'Todas');

        if (badge) { badge.textContent = 'Activo'; badge.className = 'badge ms-2 bg-success'; }
    } else {
        var ioRunning = m.Slave_IO_Running || m.Replica_IO_Running || '—';
        var sqlRunning = m.Slave_SQL_Running || m.Replica_SQL_Running || '—';
        var lag = m.Seconds_Behind_Master ?? m.Seconds_Behind_Source ?? '—';
        var lastErr = m.Last_Error || m.Last_SQL_Error || m.Last_IO_Error || '';

        setEl('mysql-io', ioRunning);
        setEl('mysql-sql', sqlRunning);
        setEl('mysql-lag', lag === null ? 'NULL' : lag + 's');
        setEl('mysql-master-log', m.Master_Log_File || m.Source_Log_File || '—');
        setEl('mysql-read-pos', m.Read_Master_Log_Pos || m.Read_Source_Log_Pos || '—');
        setEl('mysql-error', lastErr || 'Ninguno');

        var ioEl = document.getElementById('mysql-io');
        if (ioEl) ioEl.style.color = ioRunning === 'Yes' ? '#22c55e' : '#ef4444';
        var sqlEl = document.getElementById('mysql-sql');
        if (sqlEl) sqlEl.style.color = sqlRunning === 'Yes' ? '#22c55e' : '#ef4444';

        if (badge) {
            var ok = ioRunning === 'Yes' && sqlRunning === 'Yes';
            badge.textContent = ok ? 'Replicando' : 'Error';
            badge.className = 'badge ms-2 ' + (ok ? 'bg-success' : 'bg-danger');
        }

        var bar = document.getElementById('mysql-progress');
        if (bar) {
            var lagNum = parseInt(lag) || 0;
            bar.style.width = lagNum === 0 ? '100%' : Math.max(10, 100 - Math.min(lagNum, 90)) + '%';
            bar.className = 'progress-bar ' + (ioRunning !== 'Yes' || sqlRunning !== 'Yes' ? 'bg-danger' : lagNum === 0 ? 'bg-success' : 'bg-warning');
        }
    }
}

function checkAlerts(data) {
    var alertEl = document.getElementById('repl-alert');
    var msgEl = document.getElementById('repl-alert-msg');
    var problems = [];

    if (data.pg && data.role === 'slave' && data.pg.status !== 'streaming') {
        problems.push('PostgreSQL: ' + (data.pg.status || 'desconectado'));
    }
    if (data.mysql && data.role === 'slave') {
        var io = data.mysql.Slave_IO_Running || data.mysql.Replica_IO_Running;
        var sql = data.mysql.Slave_SQL_Running || data.mysql.Replica_SQL_Running;
        if (io !== 'Yes' || sql !== 'Yes') {
            problems.push('MySQL: IO=' + io + ', SQL=' + sql);
        }
    }

    if (problems.length > 0) {
        msgEl.textContent = problems.join(' | ');
        alertEl.style.display = 'block';
    } else {
        alertEl.style.display = 'none';
    }
}

function setEl(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = val;
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
}

// Initial load
refreshStatus();
<?php endif; ?>
</script>
