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
                    else $agoText = floor($ago / 86400) . ' dias';
                ?>
                <span class="text-muted">
                    — Ultima sincronizacion de BD: <strong class="text-light"><?= date('d/m/Y H:i:s', strtotime($dbSyncStatus['last_sync'])) ?></strong>
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
                    — No se han recibido dumps del master. Active la sincronizacion de BD en el master
                    (<a href="/settings/cluster#archivos" class="text-warning">Cluster &rarr; Archivos</a>)
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
    <div class="d-flex gap-2">
        <form method="POST" action="/databases/backup-all" class="d-inline" id="backupAllForm">
            <?= View::csrf() ?>
            <button type="submit" class="btn btn-outline-success" title="Backup de todas las bases de datos">
                <i class="bi bi-archive me-1"></i> Backup All
            </button>
        </form>
        <a href="/databases/create" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i> Nueva Base de Datos
        </a>
    </div>
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
                                    <?php if ($dbName !== 'postgres'): ?>
                                        <form method="POST" action="/databases/backup" class="d-inline backup-db-form">
                                            <?= View::csrf() ?>
                                            <input type="hidden" name="db_name" value="<?= View::e($dbName) ?>">
                                            <input type="hidden" name="db_type" value="pgsql">
                                            <button type="submit" class="btn btn-outline-success btn-sm" title="Crear backup">
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted"><small>Protegida</small></span>
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
                                    <?php if (!$isSystem): ?>
                                        <form method="POST" action="/databases/backup" class="d-inline backup-db-form">
                                            <?= View::csrf() ?>
                                            <input type="hidden" name="db_name" value="<?= View::e($dbName) ?>">
                                            <input type="hidden" name="db_type" value="pgsql">
                                            <button type="submit" class="btn btn-outline-success btn-sm" title="Crear backup">
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
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
                                    <?php elseif (!$isManaged): ?>
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
                                    <?php if (!$isSystem): ?>
                                        <form method="POST" action="/databases/backup" class="d-inline backup-db-form">
                                            <?= View::csrf() ?>
                                            <input type="hidden" name="db_name" value="<?= View::e($dbName) ?>">
                                            <input type="hidden" name="db_type" value="mysql">
                                            <button type="submit" class="btn btn-outline-success btn-sm" title="Crear backup">
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
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
                                    <?php elseif (!$isManaged): ?>
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

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- Database Backups                                            -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>
            <i class="bi bi-archive me-2" style="color:#a78bfa;"></i>Backups de Bases de Datos
            <span class="badge bg-secondary ms-1"><?= count($dbBackups ?? []) ?></span>
        </span>
        <div class="d-flex gap-2 align-items-center">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnBackupSettings" title="Configurar directorio">
                <i class="bi bi-gear"></i>
            </button>
            <form method="POST" action="/databases/backups/cleanup" class="d-inline">
                <?= View::csrf() ?>
                <button type="submit" class="btn btn-outline-warning btn-sm" title="Sincroniza la tabla de registros con los archivos reales: elimina registros de archivos borrados y detecta archivos huerfanos no registrados">
                    <i class="bi bi-arrow-repeat"></i> Cleanup
                </button>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="px-3 py-2 border-bottom" style="background: rgba(167,139,250,0.05);">
            <small class="text-muted">
                <i class="bi bi-folder me-1"></i>Directorio: <code><?= View::e($backupDir ?? '/opt/musedock-panel/storage/db-backups') ?></code>
            </small>
        </div>
        <?php if (empty($dbBackups)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-archive" style="font-size: 2rem;"></i>
                <p class="mt-2">No hay backups. Usa el boton <i class="bi bi-archive"></i> en cada base de datos o <strong>Backup All</strong> para crear backups.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Base de Datos</th>
                            <th>Tipo</th>
                            <th>Archivo</th>
                            <th>Tamano</th>
                            <th>Fecha</th>
                            <th>Creado por</th>
                            <th class="text-end pe-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dbBackups as $bk): ?>
                            <tr>
                                <td class="ps-3"><code><?= View::e($bk['db_name']) ?></code></td>
                                <td>
                                    <span class="badge <?= $bk['db_type'] === 'pgsql' ? 'bg-success' : '' ?>" style="<?= $bk['db_type'] === 'mysql' ? 'background:rgba(56,189,248,0.15);color:#38bdf8;' : '' ?>">
                                        <?= strtoupper(View::e($bk['db_type'])) ?>
                                    </span>
                                </td>
                                <td><small class="text-muted"><?= View::e($bk['filename']) ?></small></td>
                                <td><small><?= formatDbSize((int)($bk['file_size'] ?? 0)) ?></small></td>
                                <td><small class="text-muted"><?= date('d/m/Y H:i:s', strtotime($bk['created_at'])) ?></small></td>
                                <td><small class="text-muted"><?= View::e($bk['admin_username'] ?? '-') ?></small></td>
                                <td class="text-end pe-3">
                                    <a href="/databases/backups/<?= $bk['id'] ?>/download" class="btn btn-outline-light btn-sm" title="Descargar">
                                        <i class="bi bi-cloud-download"></i>
                                    </a>
                                    <form method="POST" action="/databases/backups/<?= $bk['id'] ?>/restore" class="d-inline restore-backup-form">
                                        <?= View::csrf() ?>
                                        <input type="hidden" name="password" class="restore-password-field" value="">
                                        <button type="submit" class="btn btn-outline-warning btn-sm" title="Restaurar"
                                                data-db-name="<?= View::e($bk['db_name']) ?>"
                                                data-filename="<?= View::e($bk['filename']) ?>">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
                                    </form>
                                    <form method="POST" action="/databases/backups/<?= $bk['id'] ?>/delete" class="d-inline delete-backup-form">
                                        <?= View::csrf() ?>
                                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar backup"
                                                data-filename="<?= View::e($bk['filename']) ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
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

<!-- Backup Settings Form (hidden) -->
<form method="POST" action="/databases/backup-settings" id="backupSettingsForm">
    <?= View::csrf() ?>
    <input type="hidden" name="db_backup_path" id="backupPathField" value="">
</form>

<script>
(function() {
    // ─── Backup single DB confirmation ────────────────────────
    document.querySelectorAll('.backup-db-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var dbName = form.querySelector('input[name="db_name"]').value;
            SwalDark.fire({
                title: 'Crear backup',
                html: '<p>Se creara un backup de <strong><code>' + dbName + '</code></strong>.</p><p class="text-muted" style="font-size:0.85em;">El archivo se guardara comprimido (.sql.gz) en el directorio de backups.</p>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-archive me-1"></i> Crear Backup',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#22c55e'
            }).then(function(result) {
                if (result.isConfirmed) {
                    SwalDark.fire({
                        title: 'Creando backup...',
                        html: '<p>Generando backup de <strong><code>' + dbName + '</code></strong></p><p class="text-muted" style="font-size:0.85em;">Esto puede tardar unos segundos.</p>',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: function() { Swal.showLoading(); }
                    });
                    form.submit();
                }
            });
        });
    });

    // ─── Backup All confirmation ──────────────────────────────
    var backupAllForm = document.getElementById('backupAllForm');
    if (backupAllForm) {
        backupAllForm.addEventListener('submit', function(e) {
            e.preventDefault();
            SwalDark.fire({
                title: 'Backup de todas las bases de datos',
                html: '<p>Se creara un backup de <strong>todas</strong> las bases de datos (excepto las de sistema).</p><p class="text-muted" style="font-size:0.85em;">Esto puede tardar varios minutos dependiendo del tamano de las bases de datos.</p>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-archive me-1"></i> Backup All',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#22c55e'
            }).then(function(result) {
                if (result.isConfirmed) {
                    SwalDark.fire({
                        title: 'Creando backups...',
                        html: '<p>Generando backup de todas las bases de datos.</p><p class="text-muted" style="font-size:0.85em;">Esto puede tardar varios minutos.</p>',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: function() { Swal.showLoading(); }
                    });
                    backupAllForm.submit();
                }
            });
        });
    }

    // ─── Restore backup confirmation (with password) ──────────
    document.querySelectorAll('.restore-backup-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            var dbName = btn.dataset.dbName;
            var filename = btn.dataset.filename;
            var passwordField = form.querySelector('.restore-password-field');

            SwalDark.fire({
                title: 'Restaurar base de datos',
                html: '<p>Se restaurara <strong><code>' + dbName + '</code></strong> desde el backup:</p>' +
                      '<p><code style="font-size:0.85em;">' + filename + '</code></p>' +
                      '<p style="color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i>Los datos actuales de la base de datos seran sobrescritos.</p>' +
                      '<p>Escribe tu contrasena de admin para confirmar:</p>' +
                      '<input type="password" id="swal-restore-password" class="swal2-input" style="background:#0f172a;color:#e2e8f0;border:1px solid #334155;" placeholder="Contrasena">',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-arrow-counterclockwise me-1"></i> Restaurar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#f59e0b',
                preConfirm: function() {
                    var pwd = document.getElementById('swal-restore-password').value;
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

    // ─── Delete backup confirmation ───────────────────────────
    document.querySelectorAll('.delete-backup-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            var filename = btn.dataset.filename;

            SwalDark.fire({
                title: 'Eliminar backup',
                html: '<p>Se eliminara el backup:</p><p><code>' + filename + '</code></p><p style="color:#ef4444;">Esta accion es irreversible.</p>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Eliminar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ef4444'
            }).then(function(result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });

    // ─── Backup Settings ──────────────────────────────────────
    var btnSettings = document.getElementById('btnBackupSettings');
    if (btnSettings) {
        btnSettings.addEventListener('click', function() {
            SwalDark.fire({
                title: 'Directorio de Backups',
                html: '<p class="text-muted" style="font-size:0.85em;">Ruta absoluta donde se guardan los backups de bases de datos.</p>' +
                      '<input type="text" id="swal-backup-path" class="swal2-input" style="background:#0f172a;color:#e2e8f0;border:1px solid #334155;font-family:monospace;font-size:0.9em;" value="<?= View::e($backupDir ?? '/opt/musedock-panel/storage/db-backups') ?>">',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-check-lg me-1"></i> Guardar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#0ea5e9',
                preConfirm: function() {
                    var path = document.getElementById('swal-backup-path').value.trim();
                    if (!path || path[0] !== '/') {
                        Swal.showValidationMessage('La ruta debe ser absoluta (comenzar con /)');
                        return false;
                    }
                    return path;
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    document.getElementById('backupPathField').value = result.value;
                    document.getElementById('backupSettingsForm').submit();
                }
            });
        });
    }

    // ─── Associate DB buttons ─────────────────────────────────
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

    // ─── Delete DB forms ──────────────────────────────────────
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
