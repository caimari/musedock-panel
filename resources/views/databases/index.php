<?php use MuseDockPanel\View; ?>

<?php
function formatDbSize(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}
?>

<?php if (!empty($creds)): ?>
<div class="card mb-4" style="border-color: #22c55e;">
    <div class="card-header" style="background: rgba(34,197,94,0.1); border-bottom-color: #22c55e;">
        <i class="bi bi-key me-2" style="color: #22c55e;"></i>
        <span style="color: #22c55e;">Credenciales de la nueva base de datos</span>
    </div>
    <div class="card-body">
        <p class="mb-2" style="color: #fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i> Guarda estas credenciales. La contrasena no se mostrara de nuevo.</p>
        <div class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Tipo</label>
                <input type="text" class="form-control" value="<?= View::e(strtoupper($creds['db_type'] ?? 'mysql')) ?>" readonly>
            </div>
            <div class="col-md-2">
                <label class="form-label">Host</label>
                <input type="text" class="form-control" value="<?= View::e($creds['db_host']) ?>" readonly onclick="this.select()">
            </div>
            <div class="col-md-3">
                <label class="form-label">Base de datos</label>
                <input type="text" class="form-control" value="<?= View::e($creds['db_name']) ?>" readonly onclick="this.select()">
            </div>
            <div class="col-md-2">
                <label class="form-label">Usuario</label>
                <input type="text" class="form-control" value="<?= View::e($creds['db_user']) ?>" readonly onclick="this.select()">
            </div>
            <div class="col-md-3">
                <label class="form-label">Contrasena</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="dbPassField" value="<?= View::e($creds['db_pass']) ?>" readonly onclick="this.select()">
                    <button type="button" class="btn btn-outline-light" onclick="navigator.clipboard.writeText(document.getElementById('dbPassField').value); this.innerHTML='<i class=\'bi bi-check\'></i>'; setTimeout(()=>this.innerHTML='<i class=\'bi bi-clipboard\'></i>',1500)" title="Copiar">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($dbSyncStatus) && ($clusterRole ?? '') === 'slave'): ?>
<div class="card mb-4 border-info">
    <div class="card-body py-2">
        <div class="d-flex align-items-center">
            <i class="bi bi-arrow-repeat me-2 text-info"></i>
            <span class="text-info fw-bold me-2">Servidor Slave</span>
            <?php if ($dbSyncStatus['has_dumps']): ?>
                <?php
                    $ago = $dbSyncStatus['ago'];
                    if ($ago < 60) $agoText = $ago . ' segundos';
                    elseif ($ago < 3600) $agoText = floor($ago / 60) . ' minutos';
                    elseif ($ago < 86400) $agoText = floor($ago / 3600) . ' horas';
                    else $agoText = floor($ago / 86400) . ' días';
                ?>
                <span class="text-muted">
                    — Última sincronización de BD: <strong class="text-light"><?= View::e($dbSyncStatus['last_sync']) ?></strong>
                    <span class="ms-1">(hace <?= $agoText ?>)</span>
                </span>
                <?php if (count($dbSyncStatus['databases']) > 0): ?>
                    <span class="ms-3 text-muted">
                        <?= count($dbSyncStatus['databases']) ?> BD<?= count($dbSyncStatus['databases']) > 1 ? 's' : '' ?> sincronizadas desde el master
                        (<?php
                            $totalSize = array_sum(array_column($dbSyncStatus['databases'], 'size'));
                            echo formatDbSize($totalSize);
                        ?> comprimido)
                    </span>
                <?php endif; ?>
            <?php else: ?>
                <span class="text-warning">
                    — No se han recibido dumps del master. Active la sincronización de BD en el master
                    (<a href="/settings/cluster#archivos" class="text-warning">Cluster → Archivos</a>)
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <span class="text-muted">
            <?= $totalPgMain ?> PostgreSQL (hosting)
            &middot; <?= $totalPgPanel ?> PostgreSQL (panel)
            <?php if ($mysqlAvailable): ?>
                &middot; <?= $totalMysql ?> MySQL
            <?php endif; ?>
        </span>
    </div>
    <a href="/databases/create" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Nueva Base de Datos
    </a>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- PostgreSQL Panel (port 5433) — Panel only, no replication   -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>
            <i class="bi bi-database me-2" style="color:#fbbf24;"></i>PostgreSQL — Panel
            <span class="badge bg-secondary ms-1"><?= $totalPgPanel ?></span>
            <small class="text-muted ms-2">Puerto 5433 &middot; Cluster <code>panel</code></small>
        </span>
        <span>
            <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;">
                <i class="bi bi-lock me-1"></i>Instancia dedicada
            </span>
            <span class="badge bg-secondary ms-1">No replicable</span>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($pgPanelDatabases)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-database-x" style="font-size: 2rem;"></i>
                <p class="mt-2">No se pudo conectar a PostgreSQL en puerto 5433.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Base de Datos</th>
                            <th>Owner</th>
                            <th>Tamano</th>
                            <th>Estado</th>
                            <th class="text-end pe-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pgPanelDatabases as $db): ?>
                            <?php
                                $dbName = $db['db_name'];
                                $isSystem = in_array($dbName, $pgPanelSystemDbs);
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <code><?= View::e($dbName) ?></code>
                                    <?php if ($dbName === 'musedock_panel'): ?>
                                        <span class="badge ms-1" style="background:rgba(251,191,36,0.15);color:#fbbf24;">
                                            <i class="bi bi-lock me-1"></i>Panel
                                        </span>
                                    <?php elseif ($dbName === 'postgres'): ?>
                                        <span class="badge bg-secondary ms-1">Default</span>
                                    <?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?= View::e($db['owner'] ?? '-') ?></small></td>
                                <td><small><?= formatDbSize((int)($db['size_bytes'] ?? 0)) ?></small></td>
                                <td>
                                    <?php if ($isSystem): ?>
                                        <span class="badge bg-secondary">Sistema</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:rgba(107,114,128,0.15);color:#9ca3af;">Externa</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <span class="text-muted"><small>Protegida</small></span>
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
<!-- PostgreSQL Main (port 5432) — Hosting, replicable          -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>
            <i class="bi bi-database me-2" style="color:#22c55e;"></i>PostgreSQL — Hosting
            <span class="badge bg-secondary ms-1"><?= $totalPgMain ?></span>
            <small class="text-muted ms-2">Puerto 5432 &middot; Cluster <code>main</code></small>
        </span>
        <span>
            <?php if (!empty($pgMainReplication)): ?>
                <?php if ($pgMainReplication['role'] === 'master'): ?>
                    <span class="badge bg-success"><i class="bi bi-arrow-up-circle me-1"></i>Master</span>
                    <?php if ($pgMainReplication['slaves'] > 0): ?>
                        <span class="badge bg-info ms-1"><?= $pgMainReplication['slaves'] ?> slave(s)</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark ms-1">Sin slaves</span>
                    <?php endif; ?>
                <?php elseif ($pgMainReplication['role'] === 'slave'): ?>
                    <span class="badge bg-info"><i class="bi bi-arrow-down-circle me-1"></i>Slave</span>
                    <?php if (($pgMainReplication['state'] ?? '') === 'streaming'): ?>
                        <span class="badge bg-success ms-1">Streaming</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark ms-1"><?= View::e($pgMainReplication['state'] ?? 'unknown') ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <span class="badge bg-secondary">Standalone</span>
                <span class="badge bg-success ms-1"><i class="bi bi-arrow-repeat me-1"></i>Replicable</span>
            <?php endif; ?>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($pgMainDatabases)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-database-x" style="font-size: 2rem;"></i>
                <p class="mt-2">No se pudo conectar a PostgreSQL en puerto 5432 o no hay bases de datos.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Base de Datos</th>
                            <th>Owner</th>
                            <th>Tamano</th>
                            <th>Estado</th>
                            <?php if (!empty($pgMainReplication)): ?>
                                <th>Replicacion</th>
                            <?php endif; ?>
                            <th class="text-end pe-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pgMainDatabases as $db): ?>
                            <?php
                                $dbName = $db['db_name'];
                                $isSystem = in_array($dbName, $pgMainSystemDbs);
                                $isManaged = isset($panelDbMap[$dbName]);
                                $managedInfo = $isManaged ? $panelDbMap[$dbName] : null;
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <code><?= View::e($dbName) ?></code>
                                    <?php if ($dbName === 'postgres'): ?>
                                        <span class="badge bg-secondary ms-1">Default</span>
                                    <?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?= View::e($db['owner'] ?? '-') ?></small></td>
                                <td><small><?= formatDbSize((int)($db['size_bytes'] ?? 0)) ?></small></td>
                                <td>
                                    <?php if ($isManaged): ?>
                                        <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;">
                                            <i class="bi bi-server me-1"></i><?= View::e($managedInfo['username']) ?>
                                        </span>
                                    <?php elseif ($isSystem): ?>
                                        <span class="badge bg-secondary">Sistema</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:rgba(107,114,128,0.15);color:#9ca3af;">Externa</span>
                                    <?php endif; ?>
                                </td>
                                <?php if (!empty($pgMainReplication)): ?>
                                    <td>
                                        <?php if ($pgMainReplication['role'] === 'master' && $pgMainReplication['slaves'] > 0): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Replicada</span>
                                        <?php elseif ($pgMainReplication['role'] === 'slave'): ?>
                                            <span class="badge bg-info"><i class="bi bi-arrow-down me-1"></i>Replica</span>
                                        <?php elseif ($pgMainReplication['role'] === 'master'): ?>
                                            <span class="badge bg-warning text-dark"><i class="bi bi-dash-circle me-1"></i>Sin replica</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="text-end pe-3">
                                    <?php if ($isManaged && !$isSystem): ?>
                                        <form method="POST" action="/databases/<?= $managedInfo['id'] ?>/delete" class="d-inline delete-db-form">
                                            <?= View::csrf() ?>
                                            <input type="hidden" name="password" class="delete-password-field" value="">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar"
                                                    data-db-name="<?= View::e($dbName) ?>"
                                                    data-db-user="<?= View::e($managedInfo['db_user']) ?>"
                                                    data-db-type="PGSQL">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($isSystem): ?>
                                        <span class="text-muted"><small>Protegida</small></span>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-info btn-sm btn-associate-db"
                                                data-db-name="<?= View::e($dbName) ?>"
                                                data-db-type="pgsql"
                                                data-db-owner="<?= View::e($db['owner'] ?? '') ?>"
                                                title="Asociar a hosting">
                                            <i class="bi bi-link-45deg"></i> Asociar
                                        </button>
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

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MySQL Databases                                             -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>
            <i class="bi bi-database me-2" style="color:#38bdf8;"></i>MySQL
            <?php if ($mysqlAvailable): ?>
                <span class="badge bg-secondary ms-1"><?= $totalMysql ?></span>
            <?php endif; ?>
        </span>
        <span>
            <?php if (!$mysqlAvailable): ?>
                <span class="badge bg-secondary">No disponible</span>
            <?php elseif (!empty($mysqlReplication)): ?>
                <?php if ($mysqlReplication['role'] === 'master'): ?>
                    <span class="badge bg-success"><i class="bi bi-arrow-up-circle me-1"></i>Master</span>
                <?php elseif ($mysqlReplication['role'] === 'slave'): ?>
                    <span class="badge bg-info"><i class="bi bi-arrow-down-circle me-1"></i>Slave</span>
                    <?php
                        $ioOk = ($mysqlReplication['io_running'] ?? 'No') === 'Yes';
                        $sqlOk = ($mysqlReplication['sql_running'] ?? 'No') === 'Yes';
                    ?>
                    <?php if ($ioOk && $sqlOk): ?>
                        <span class="badge bg-success ms-1">Sincronizado</span>
                    <?php else: ?>
                        <span class="badge bg-danger ms-1">Error</span>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <span class="badge bg-secondary">Standalone</span>
                <span class="badge bg-success ms-1"><i class="bi bi-arrow-repeat me-1"></i>Replicable</span>
            <?php endif; ?>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (!$mysqlAvailable): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-database-x" style="font-size: 2rem;"></i>
                <p class="mt-2">MySQL no esta disponible o no se pudo conectar.</p>
            </div>
        <?php elseif (empty($mysqlDatabases)): ?>
            <div class="p-4 text-center text-muted">
                <p class="mt-2">No hay bases de datos MySQL.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Base de Datos</th>
                            <th>Tamano</th>
                            <th>Estado</th>
                            <?php if (!empty($mysqlReplication)): ?>
                                <th>Replicacion</th>
                            <?php endif; ?>
                            <th class="text-end pe-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mysqlDatabases as $db): ?>
                            <?php
                                $dbName = $db['db_name'];
                                $isSystem = in_array($dbName, $mysqlSystemDbs);
                                $isManaged = isset($panelDbMap[$dbName]);
                                $managedInfo = $isManaged ? $panelDbMap[$dbName] : null;
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <code><?= View::e($dbName) ?></code>
                                    <?php if ($isSystem): ?>
                                        <span class="badge bg-secondary ms-1">Sistema</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= formatDbSize((int)($db['size_bytes'] ?? 0)) ?></small></td>
                                <td>
                                    <?php if ($isManaged): ?>
                                        <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;">
                                            <i class="bi bi-server me-1"></i><?= View::e($managedInfo['username']) ?>
                                        </span>
                                    <?php elseif ($isSystem): ?>
                                        <span class="badge bg-secondary">Sistema</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:rgba(107,114,128,0.15);color:#9ca3af;">Externa</span>
                                    <?php endif; ?>
                                </td>
                                <?php if (!empty($mysqlReplication)): ?>
                                    <td>
                                        <?php if ($isSystem): ?>
                                            <span class="badge bg-secondary"><i class="bi bi-dash me-1"></i>N/A</span>
                                        <?php elseif ($mysqlReplication['role'] === 'master'): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Replicada</span>
                                        <?php elseif ($mysqlReplication['role'] === 'slave'): ?>
                                            <span class="badge bg-info"><i class="bi bi-arrow-down me-1"></i>Replica</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="text-end pe-3">
                                    <?php if ($isManaged && !$isSystem): ?>
                                        <form method="POST" action="/databases/<?= $managedInfo['id'] ?>/delete" class="d-inline delete-db-form">
                                            <?= View::csrf() ?>
                                            <input type="hidden" name="password" class="delete-password-field" value="">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar"
                                                    data-db-name="<?= View::e($dbName) ?>"
                                                    data-db-user="<?= View::e($managedInfo['db_user']) ?>"
                                                    data-db-type="MYSQL">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($isSystem): ?>
                                        <span class="text-muted"><small>Protegida</small></span>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-info btn-sm btn-associate-db"
                                                data-db-name="<?= View::e($dbName) ?>"
                                                data-db-type="mysql"
                                                data-db-owner=""
                                                title="Asociar a hosting">
                                            <i class="bi bi-link-45deg"></i> Asociar
                                        </button>
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

<!-- Associate DB Modal -->
<form method="POST" action="/databases/associate" id="associateDbForm">
    <?= View::csrf() ?>
    <input type="hidden" name="db_name" id="assocDbName" value="">
    <input type="hidden" name="db_type" id="assocDbType" value="">
</form>

<script>
(function() {
    // Associate DB buttons
    var hostingAccounts = <?= json_encode($hostingAccounts ?? []) ?>;
    document.querySelectorAll('.btn-associate-db').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var dbName = btn.dataset.dbName;
            var dbType = btn.dataset.dbType;
            var dbOwner = btn.dataset.dbOwner || '';

            var optionsHtml = '<option value="">-- Seleccionar hosting --</option>';
            var bestMatch = '';
            hostingAccounts.forEach(function(acc) {
                var selected = '';
                // Auto-select if owner matches username
                if (dbOwner && acc.username === dbOwner) {
                    selected = 'selected';
                    bestMatch = acc.domain;
                }
                optionsHtml += '<option value="' + acc.id + '" ' + selected + '>' + acc.domain + ' (' + acc.username + ')</option>';
            });

            SwalDark.fire({
                title: 'Asociar base de datos',
                html: '<p>Vincular <strong><code>' + dbName + '</code></strong> (' + dbType.toUpperCase() + ') a un hosting.</p>' +
                      (dbOwner ? '<p class="text-muted" style="font-size:0.85em;">Owner detectado: <strong>' + dbOwner + '</strong></p>' : '') +
                      '<p style="color:#fbbf24;font-size:0.85em;"><i class="bi bi-info-circle me-1"></i>Solo se registra en el panel. No modifica la base de datos ni sus permisos.</p>' +
                      '<select id="swal-account-id" class="swal2-select" style="background:#0f172a;color:#e2e8f0;border:1px solid #334155;width:100%;padding:8px;border-radius:4px;">' + optionsHtml + '</select>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-link-45deg me-1"></i> Asociar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#0ea5e9',
                preConfirm: function() {
                    var accountId = document.getElementById('swal-account-id').value;
                    if (!accountId) {
                        Swal.showValidationMessage('Debes seleccionar un hosting');
                        return false;
                    }
                    return accountId;
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    var form = document.getElementById('associateDbForm');
                    document.getElementById('assocDbName').value = dbName;
                    document.getElementById('assocDbType').value = dbType;
                    // Add account_id dynamically
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'account_id';
                    input.value = result.value;
                    form.appendChild(input);
                    form.submit();
                }
            });
        });
    });

    // Delete DB forms
    document.querySelectorAll('.delete-db-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            var dbName = btn.dataset.dbName;
            var dbUser = btn.dataset.dbUser;
            var dbType = btn.dataset.dbType;
            var passwordField = form.querySelector('.delete-password-field');

            SwalDark.fire({
                title: 'Confirmar eliminacion',
                html: '<p>Se eliminara <strong>' + dbName + '</strong> (' + dbType + ') y el usuario <strong>' + dbUser + '</strong>.</p>' +
                      '<p style="color:#ef4444;">Esta accion es irreversible.</p>' +
                      '<p>Escribe tu contrasena de admin para confirmar:</p>' +
                      '<input type="password" id="swal-password" class="swal2-input" style="background:#0f172a;color:#e2e8f0;border:1px solid #334155;" placeholder="Contrasena">',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Eliminar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ef4444',
                preConfirm: function() {
                    var pwd = document.getElementById('swal-password').value;
                    if (!pwd) {
                        Swal.showValidationMessage('Debes ingresar tu contrasena');
                        return false;
                    }
                    return pwd;
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    passwordField.value = result.value;
                    form.submit();
                }
            });
        });
    });
})();
</script>
