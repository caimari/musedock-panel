<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php
    // Role badges helper
    function replBadge(string $role): string {
        return match($role) {
            'master' => '<span class="badge bg-success">Master</span>',
            'slave'  => '<span class="badge bg-warning text-dark">Slave</span>',
            default  => '<span class="badge bg-secondary">Standalone</span>',
        };
    }

    $pgRole       = $pgRole ?? 'standalone';
    $mysqlRole    = $mysqlRole ?? 'standalone';
    $pgVersion    = $pgVersion ?? '—';
    $mysqlVersion = $mysqlVersion ?? '—';
    $pgMasterStatus   = $pgMasterStatus ?? null;
    $pgSlaveStatus    = $pgSlaveStatus ?? null;
    $mysqlMasterStatus = $mysqlMasterStatus ?? null;
    $mysqlSlaveStatus  = $mysqlSlaveStatus ?? null;
    $pgUsers      = $pgUsers ?? [];
    $mysqlUsers   = $mysqlUsers ?? [];
    $pgIps        = $pgIps ?? [];
    $mysqlIps     = $mysqlIps ?? [];
    $clusterNodes = $clusterNodes ?? [];
    $pgDatabases  = $pgDatabases ?? [];
    $mysqlDatabases = $mysqlDatabases ?? [];
    $configuredAt = $configuredAt ?? null;

    $serverIp = trim(shell_exec('hostname -I 2>/dev/null') ?: '—');
?>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SECTION 0 — Resumen General                                 -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card bg-dark border-secondary mb-4">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap align-items-center gap-4">
            <span class="text-muted"><i class="bi bi-globe me-1"></i>IP: <code class="text-light"><?= View::e($serverIp) ?></code></span>
            <span class="text-muted"><i class="bi bi-database me-1"></i>PostgreSQL <?= View::e($pgVersion) ?> <?= replBadge($pgRole) ?></span>
            <span class="text-muted"><i class="bi bi-database-fill me-1"></i>MySQL <?= View::e($mysqlVersion) ?> <?= replBadge($mysqlRole) ?></span>
            <?php if ($configuredAt): ?>
                <span class="text-muted ms-auto"><i class="bi bi-clock me-1"></i>Configurado: <?= View::e($configuredAt) ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Status polling countdown -->
<?php if ($pgRole !== 'standalone' || $mysqlRole !== 'standalone'): ?>
<div class="text-end mb-2">
    <small class="text-muted" id="pollCountdown"><i class="bi bi-arrow-repeat me-1"></i>Actualizando en 5s...</small>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- PostgreSQL Engine Card                                       -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="col-lg-6">
<div class="card bg-dark border-secondary mb-4">
    <div class="card-header border-secondary d-flex justify-content-between align-items-center">
        <span><i class="bi bi-database me-2"></i>PostgreSQL <?= View::e($pgVersion) ?></span>
        <?= replBadge($pgRole) ?>
    </div>
    <div class="card-body">

        <?php if ($pgRole === 'standalone'): ?>
        <!-- ── PG STANDALONE ── -->

        <!-- Opcion 1: Activar como Master -->
        <form method="POST" action="/settings/replication/activate-master" id="pgActivateMasterForm">
            <?= \MuseDockPanel\View::csrf() ?>
            <input type="hidden" name="engine" value="pg">
            <button type="submit" class="btn btn-success w-100 mb-3" onclick="return confirmActivateMaster(event, 'pg', this.form)">
                <i class="bi bi-star me-1"></i>Activar como Master
            </button>
        </form>

        <!-- Opcion 2: Configurar como Slave -->
        <div class="mb-3">
            <button class="btn btn-outline-warning w-100" type="button" data-bs-toggle="collapse" data-bs-target="#pgSlaveCollapse">
                <i class="bi bi-arrow-down-circle me-1"></i>Configurar como Slave
            </button>
            <div class="collapse mt-3" id="pgSlaveCollapse">
                <div class="card bg-dark border-secondary">
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label text-light">IP del Master</label>
                            <input type="text" class="form-control bg-dark text-light border-secondary" id="pgSlaveMasterIp" placeholder="192.168.1.100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-light">Puerto</label>
                            <input type="number" class="form-control bg-dark text-light border-secondary" id="pgSlavePort" value="5432">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-light">Usuario de replicacion</label>
                            <input type="text" class="form-control bg-dark text-light border-secondary" id="pgSlaveUser" placeholder="repl_user">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-light">Password</label>
                            <input type="password" class="form-control bg-dark text-light border-secondary" id="pgSlavePass">
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-info btn-sm" onclick="testSlaveMaster('pg')">
                                <i class="bi bi-plug me-1"></i>Test Conexion
                            </button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="confirmConvertSlave('pg')">
                                <i class="bi bi-exclamation-triangle me-1"></i>Convertir en Slave
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($clusterNodes)): ?>
        <!-- Opcion 3: Configuracion Automatica -->
        <div class="mt-3">
            <h6 class="text-muted"><i class="bi bi-diagram-3 me-1"></i>Configuración Automática</h6>
            <p class="small text-muted mb-2">
                Configura <strong>este servidor como Slave</strong> del nodo seleccionado (que será el Master).
                El nodo remoto se configura como Master automáticamente.
                <strong class="text-warning">Las bases de datos locales serán reemplazadas.</strong>
            </p>
            <?php foreach ($clusterNodes as $node): ?>
                <?php
                    $nodeHost = parse_url($node['api_url'], PHP_URL_HOST);
                    $isOnline = ($node['status'] ?? '') === 'online';
                ?>
                <?php if ($isOnline): ?>
                <button type="button" class="btn btn-outline-primary btn-sm mb-2 w-100" onclick="autoConfigureRepl(<?= (int)$node['id'] ?>, 'pg', '<?= View::e($node['name']) ?>', '<?= View::e($nodeHost) ?>')">
                    <i class="bi bi-arrow-down-circle me-1"></i>Convertir este nodo en Slave de <?= View::e($node['name']) ?> (<?= View::e($nodeHost) ?>)
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-outline-secondary btn-sm mb-2 w-100" disabled>
                    <i class="bi bi-x-circle me-1"></i><?= View::e($node['name']) ?> — offline
                </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php elseif ($pgRole === 'master'): ?>
        <!-- ── PG MASTER ── -->

        <!-- Monitor -->
        <h6 class="text-muted mb-2"><i class="bi bi-activity me-1"></i>Estado de Replicacion</h6>
        <div id="pgMonitor">
            <?php if ($pgMasterStatus): ?>
            <div class="table-responsive">
                <table class="table table-dark table-hover table-sm">
                    <thead><tr>
                        <th>Slave</th>
                        <th>Estado</th>
                        <th>Sent LSN</th>
                        <th>Write LSN</th>
                        <th>Flush LSN</th>
                        <th>Replay LSN</th>
                        <th>Lag</th>
                    </tr></thead>
                    <tbody>
                    <?php if (is_array($pgMasterStatus) && isset($pgMasterStatus[0])): ?>
                        <?php foreach ($pgMasterStatus as $s): ?>
                        <tr>
                            <td><?= View::e($s['client_addr'] ?? '—') ?></td>
                            <td><span class="badge bg-<?= ($s['state'] ?? '') === 'streaming' ? 'success' : 'warning' ?>"><?= View::e($s['state'] ?? '—') ?></span></td>
                            <td><code><?= View::e($s['sent_lsn'] ?? '—') ?></code></td>
                            <td><code><?= View::e($s['write_lsn'] ?? '—') ?></code></td>
                            <td><code><?= View::e($s['flush_lsn'] ?? '—') ?></code></td>
                            <td><code><?= View::e($s['replay_lsn'] ?? '—') ?></code></td>
                            <td><?= View::e($s['replay_lag'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php elseif (is_array($pgMasterStatus) && !empty($pgMasterStatus)): ?>
                        <tr>
                            <td><?= View::e($pgMasterStatus['client_addr'] ?? '—') ?></td>
                            <td><span class="badge bg-<?= ($pgMasterStatus['state'] ?? '') === 'streaming' ? 'success' : 'warning' ?>"><?= View::e($pgMasterStatus['state'] ?? '—') ?></span></td>
                            <td><code><?= View::e($pgMasterStatus['sent_lsn'] ?? '—') ?></code></td>
                            <td><code><?= View::e($pgMasterStatus['write_lsn'] ?? '—') ?></code></td>
                            <td><code><?= View::e($pgMasterStatus['flush_lsn'] ?? '—') ?></code></td>
                            <td><code><?= View::e($pgMasterStatus['replay_lsn'] ?? '—') ?></code></td>
                            <td><?= View::e($pgMasterStatus['replay_lag'] ?? '—') ?></td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-muted text-center">Sin slaves conectados</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="text-muted"><i class="bi bi-info-circle me-1"></i>Sin slaves conectados actualmente.</p>
            <?php endif; ?>
        </div>

        <hr class="border-secondary">

        <!-- Usuarios de Replicacion -->
        <h6 class="text-muted mb-2"><i class="bi bi-people me-1"></i>Usuarios de Replicacion</h6>
        <div class="table-responsive" id="pgUsersTable">
            <table class="table table-dark table-hover table-sm">
                <thead><tr><th>Usuario</th><th>Password</th><th>Creado</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($pgUsers)): ?>
                    <tr><td colspan="4" class="text-muted text-center">No hay usuarios de replicacion</td></tr>
                <?php else: ?>
                    <?php foreach ($pgUsers as $u): ?>
                    <tr>
                        <td><code><?= View::e($u['username']) ?></code></td>
                        <td>
                            <span class="text-muted">********</span>
                            <button class="btn btn-outline-light btn-sm ms-1 py-0 px-1" onclick="navigator.clipboard.writeText('<?= View::e($u['password']) ?>').then(()=>toastOk('Password copiado'))" title="Copiar password">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </td>
                        <td><small class="text-muted"><?= View::e($u['created_at'] ?? '—') ?></small></td>
                        <td>
                            <button class="btn btn-outline-danger btn-sm py-0 px-1" onclick="deleteReplUser(<?= (int)$u['id'] ?>, 'pg')" title="Eliminar">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <button type="button" class="btn btn-outline-success btn-sm" onclick="createReplUser('pg')">
            <i class="bi bi-person-plus me-1"></i>Crear Usuario
        </button>

        <hr class="border-secondary">

        <!-- Slaves Autorizados (IPs) -->
        <h6 class="text-muted mb-2"><i class="bi bi-shield-check me-1"></i>Slaves Autorizados (IPs)</h6>
        <div class="table-responsive" id="pgIpsTable">
            <table class="table table-dark table-hover table-sm">
                <thead><tr><th>IP</th><th>Etiqueta</th><th>Agregado</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($pgIps)): ?>
                    <tr><td colspan="4" class="text-muted text-center">No hay IPs autorizadas</td></tr>
                <?php else: ?>
                    <?php foreach ($pgIps as $ip): ?>
                    <tr>
                        <td><code><?= View::e($ip['ip_address']) ?></code></td>
                        <td><?= View::e($ip['label'] ?? '—') ?></td>
                        <td><small class="text-muted"><?= View::e($ip['created_at'] ?? '—') ?></small></td>
                        <td>
                            <button class="btn btn-outline-danger btn-sm py-0 px-1" onclick="removeAuthorizedIp(<?= (int)$ip['id'] ?>, 'pg')" title="Eliminar">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="d-flex gap-2 align-items-end">
            <div>
                <label class="form-label text-muted small mb-1">IP</label>
                <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="pgNewIp" placeholder="10.0.0.5">
            </div>
            <div>
                <label class="form-label text-muted small mb-1">Etiqueta</label>
                <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="pgNewIpLabel" placeholder="Slave 1">
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addAuthorizedIp('pg')">
                <i class="bi bi-plus-circle me-1"></i>Anadir IP
            </button>
        </div>

        <hr class="border-secondary">
        <form method="POST" action="/settings/replication/reset-standalone" onsubmit="return confirmReset(event, 'pg', this)">
            <?= \MuseDockPanel\View::csrf() ?>
            <input type="hidden" name="engine" value="pg">
            <button type="submit" class="btn btn-link btn-sm text-muted p-0">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Resetear a Standalone
            </button>
        </form>

        <?php elseif ($pgRole === 'slave'): ?>
        <!-- ── PG SLAVE ── -->

        <!-- Monitor -->
        <h6 class="text-muted mb-2"><i class="bi bi-activity me-1"></i>Estado de Replicacion</h6>
        <div id="pgMonitor">
            <?php if ($pgSlaveStatus): ?>
            <div class="table-responsive">
                <table class="table table-dark table-hover table-sm">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width:40%">Sender Host</td>
                            <td><code><?= View::e($pgSlaveStatus['sender_host'] ?? '—') ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Receive LSN</td>
                            <td><code><?= View::e($pgSlaveStatus['receive_lsn'] ?? $pgSlaveStatus['received_lsn'] ?? '—') ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Replay LSN</td>
                            <td><code><?= View::e($pgSlaveStatus['replay_lsn'] ?? '—') ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Lag (segundos)</td>
                            <td>
                                <?php
                                    $pgLag = $pgSlaveStatus['lag_seconds'] ?? $pgSlaveStatus['replay_lag'] ?? '—';
                                    $pgLagClass = is_numeric($pgLag) && $pgLag > 10 ? 'text-danger' : 'text-success';
                                ?>
                                <span class="<?= $pgLagClass ?>"><?= View::e($pgLag) ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Estado</td>
                            <td>
                                <?php $pgConnState = $pgSlaveStatus['status'] ?? $pgSlaveStatus['state'] ?? 'unknown'; ?>
                                <span class="badge bg-<?= $pgConnState === 'streaming' ? 'success' : 'warning' ?>"><?= View::e($pgConnState) ?></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="text-muted"><i class="bi bi-info-circle me-1"></i>No se pudo obtener el estado de replicacion.</p>
            <?php endif; ?>
        </div>

        <hr class="border-secondary">

        <div class="d-flex gap-2 flex-wrap">
            <form method="POST" action="/settings/replication/promote" onsubmit="return confirmPromote(event, 'pg', this)">
                <?= \MuseDockPanel\View::csrf() ?>
                <input type="hidden" name="engine" value="pg">
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="bi bi-arrow-up-circle me-1"></i>Promover a Master
                </button>
            </form>
            <form method="POST" action="/settings/replication/reset-standalone" onsubmit="return confirmReset(event, 'pg', this)">
                <?= \MuseDockPanel\View::csrf() ?>
                <input type="hidden" name="engine" value="pg">
                <button type="submit" class="btn btn-link btn-sm text-muted p-0">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Resetear a Standalone
                </button>
            </form>
        </div>

        <?php endif; ?>
    </div>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MySQL Engine Card                                            -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="col-lg-6">
<div class="card bg-dark border-secondary mb-4">
    <div class="card-header border-secondary d-flex justify-content-between align-items-center">
        <span><i class="bi bi-database-fill me-2"></i>MySQL <?= View::e($mysqlVersion) ?></span>
        <?= replBadge($mysqlRole) ?>
    </div>
    <div class="card-body">

        <?php if ($mysqlRole === 'standalone'): ?>
        <!-- ── MYSQL STANDALONE ── -->

        <!-- Opcion 1: Activar como Master -->
        <form method="POST" action="/settings/replication/activate-master" id="mysqlActivateMasterForm">
            <?= \MuseDockPanel\View::csrf() ?>
            <input type="hidden" name="engine" value="mysql">
            <button type="submit" class="btn btn-success w-100 mb-3" onclick="return confirmActivateMaster(event, 'mysql', this.form)">
                <i class="bi bi-star me-1"></i>Activar como Master
            </button>
        </form>

        <!-- Opcion 2: Configurar como Slave -->
        <div class="mb-3">
            <button class="btn btn-outline-warning w-100" type="button" data-bs-toggle="collapse" data-bs-target="#mysqlSlaveCollapse">
                <i class="bi bi-arrow-down-circle me-1"></i>Configurar como Slave
            </button>
            <div class="collapse mt-3" id="mysqlSlaveCollapse">
                <div class="card bg-dark border-secondary">
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label text-light">IP del Master</label>
                            <input type="text" class="form-control bg-dark text-light border-secondary" id="mysqlSlaveMasterIp" placeholder="192.168.1.100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-light">Puerto</label>
                            <input type="number" class="form-control bg-dark text-light border-secondary" id="mysqlSlavePort" value="3306">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-light">Usuario de replicacion</label>
                            <input type="text" class="form-control bg-dark text-light border-secondary" id="mysqlSlaveUser" placeholder="repl_user">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-light">Password</label>
                            <input type="password" class="form-control bg-dark text-light border-secondary" id="mysqlSlavePass">
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-info btn-sm" onclick="testSlaveMaster('mysql')">
                                <i class="bi bi-plug me-1"></i>Test Conexion
                            </button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="confirmConvertSlave('mysql')">
                                <i class="bi bi-exclamation-triangle me-1"></i>Convertir en Slave
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($clusterNodes)): ?>
        <!-- Opcion 3: Configuración Automática -->
        <div class="mt-3">
            <h6 class="text-muted"><i class="bi bi-diagram-3 me-1"></i>Configuración Automática</h6>
            <p class="small text-muted mb-2">
                Configura <strong>este servidor como Slave</strong> del nodo seleccionado (que será el Master).
                El nodo remoto se configura como Master automáticamente.
                <strong class="text-warning">Las bases de datos locales serán reemplazadas.</strong>
            </p>
            <?php foreach ($clusterNodes as $node): ?>
                <?php
                    $nodeHost = parse_url($node['api_url'], PHP_URL_HOST);
                    $isOnline = ($node['status'] ?? '') === 'online';
                ?>
                <?php if ($isOnline): ?>
                <button type="button" class="btn btn-outline-primary btn-sm mb-2 w-100" onclick="autoConfigureRepl(<?= (int)$node['id'] ?>, 'mysql', '<?= View::e($node['name']) ?>', '<?= View::e($nodeHost) ?>')">
                    <i class="bi bi-arrow-down-circle me-1"></i>Convertir este nodo en Slave de <?= View::e($node['name']) ?> (<?= View::e($nodeHost) ?>)
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-outline-secondary btn-sm mb-2 w-100" disabled>
                    <i class="bi bi-x-circle me-1"></i><?= View::e($node['name']) ?> — offline
                </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php elseif ($mysqlRole === 'master'): ?>
        <!-- ── MYSQL MASTER ── -->

        <!-- Monitor -->
        <h6 class="text-muted mb-2"><i class="bi bi-activity me-1"></i>Estado de Replicacion</h6>
        <div id="mysqlMonitor">
            <?php if ($mysqlMasterStatus): ?>
            <div class="table-responsive">
                <table class="table table-dark table-hover table-sm">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width:40%">File</td>
                            <td><code><?= View::e($mysqlMasterStatus['File'] ?? '—') ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Position</td>
                            <td><code><?= View::e($mysqlMasterStatus['Position'] ?? '—') ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Binlog Do DB</td>
                            <td><?= View::e($mysqlMasterStatus['Binlog_Do_DB'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Binlog Ignore DB</td>
                            <td><?= View::e($mysqlMasterStatus['Binlog_Ignore_DB'] ?? '—') ?></td>
                        </tr>
                        <?php if (!empty($mysqlMasterStatus['Executed_Gtid_Set'])): ?>
                        <tr>
                            <td class="text-muted">GTID Set</td>
                            <td><code class="small"><?= View::e($mysqlMasterStatus['Executed_Gtid_Set']) ?></code></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="text-muted"><i class="bi bi-info-circle me-1"></i>No se pudo obtener el estado de master.</p>
            <?php endif; ?>
        </div>

        <hr class="border-secondary">

        <!-- Usuarios de Replicacion -->
        <h6 class="text-muted mb-2"><i class="bi bi-people me-1"></i>Usuarios de Replicacion</h6>
        <div class="table-responsive" id="mysqlUsersTable">
            <table class="table table-dark table-hover table-sm">
                <thead><tr><th>Usuario</th><th>Password</th><th>Creado</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($mysqlUsers)): ?>
                    <tr><td colspan="4" class="text-muted text-center">No hay usuarios de replicacion</td></tr>
                <?php else: ?>
                    <?php foreach ($mysqlUsers as $u): ?>
                    <tr>
                        <td><code><?= View::e($u['username']) ?></code></td>
                        <td>
                            <span class="text-muted">********</span>
                            <button class="btn btn-outline-light btn-sm ms-1 py-0 px-1" onclick="navigator.clipboard.writeText('<?= View::e($u['password']) ?>').then(()=>toastOk('Password copiado'))" title="Copiar password">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </td>
                        <td><small class="text-muted"><?= View::e($u['created_at'] ?? '—') ?></small></td>
                        <td>
                            <button class="btn btn-outline-danger btn-sm py-0 px-1" onclick="deleteReplUser(<?= (int)$u['id'] ?>, 'mysql')" title="Eliminar">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <button type="button" class="btn btn-outline-success btn-sm" onclick="createReplUser('mysql')">
            <i class="bi bi-person-plus me-1"></i>Crear Usuario
        </button>

        <hr class="border-secondary">

        <!-- Slaves Autorizados (IPs) -->
        <h6 class="text-muted mb-2"><i class="bi bi-shield-check me-1"></i>Slaves Autorizados (IPs)</h6>
        <div class="table-responsive" id="mysqlIpsTable">
            <table class="table table-dark table-hover table-sm">
                <thead><tr><th>IP</th><th>Etiqueta</th><th>Agregado</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($mysqlIps)): ?>
                    <tr><td colspan="4" class="text-muted text-center">No hay IPs autorizadas</td></tr>
                <?php else: ?>
                    <?php foreach ($mysqlIps as $ip): ?>
                    <tr>
                        <td><code><?= View::e($ip['ip_address']) ?></code></td>
                        <td><?= View::e($ip['label'] ?? '—') ?></td>
                        <td><small class="text-muted"><?= View::e($ip['created_at'] ?? '—') ?></small></td>
                        <td>
                            <button class="btn btn-outline-danger btn-sm py-0 px-1" onclick="removeAuthorizedIp(<?= (int)$ip['id'] ?>, 'mysql')" title="Eliminar">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="d-flex gap-2 align-items-end">
            <div>
                <label class="form-label text-muted small mb-1">IP</label>
                <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="mysqlNewIp" placeholder="10.0.0.5">
            </div>
            <div>
                <label class="form-label text-muted small mb-1">Etiqueta</label>
                <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="mysqlNewIpLabel" placeholder="Slave 1">
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addAuthorizedIp('mysql')">
                <i class="bi bi-plus-circle me-1"></i>Anadir IP
            </button>
        </div>

        <hr class="border-secondary">
        <form method="POST" action="/settings/replication/reset-standalone" onsubmit="return confirmReset(event, 'mysql', this)">
            <?= \MuseDockPanel\View::csrf() ?>
            <input type="hidden" name="engine" value="mysql">
            <button type="submit" class="btn btn-link btn-sm text-muted p-0">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Resetear a Standalone
            </button>
        </form>

        <?php elseif ($mysqlRole === 'slave'): ?>
        <!-- ── MYSQL SLAVE ── -->

        <!-- Monitor -->
        <h6 class="text-muted mb-2"><i class="bi bi-activity me-1"></i>Estado de Replicacion</h6>
        <div id="mysqlMonitor">
            <?php if ($mysqlSlaveStatus): ?>
            <div class="table-responsive">
                <table class="table table-dark table-hover table-sm">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width:40%">IO Running</td>
                            <td>
                                <?php $ioRun = $mysqlSlaveStatus['Slave_IO_Running'] ?? $mysqlSlaveStatus['io_running'] ?? '—'; ?>
                                <span class="badge bg-<?= $ioRun === 'Yes' ? 'success' : 'danger' ?>"><?= View::e($ioRun) ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">SQL Running</td>
                            <td>
                                <?php $sqlRun = $mysqlSlaveStatus['Slave_SQL_Running'] ?? $mysqlSlaveStatus['sql_running'] ?? '—'; ?>
                                <span class="badge bg-<?= $sqlRun === 'Yes' ? 'success' : 'danger' ?>"><?= View::e($sqlRun) ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Seconds Behind Master</td>
                            <td>
                                <?php
                                    $mysqlLag = $mysqlSlaveStatus['Seconds_Behind_Master'] ?? $mysqlSlaveStatus['seconds_behind'] ?? '—';
                                    $mysqlLagClass = is_numeric($mysqlLag) && $mysqlLag > 10 ? 'text-danger' : 'text-success';
                                ?>
                                <span class="<?= $mysqlLagClass ?>"><?= View::e((string)$mysqlLag) ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Master Host</td>
                            <td><code><?= View::e($mysqlSlaveStatus['Master_Host'] ?? $mysqlSlaveStatus['master_host'] ?? '—') ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Master Log File</td>
                            <td><code><?= View::e($mysqlSlaveStatus['Master_Log_File'] ?? $mysqlSlaveStatus['master_log_file'] ?? '—') ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Read Master Log Pos</td>
                            <td><code><?= View::e($mysqlSlaveStatus['Read_Master_Log_Pos'] ?? $mysqlSlaveStatus['read_master_log_pos'] ?? '—') ?></code></td>
                        </tr>
                        <?php
                            $lastErr = $mysqlSlaveStatus['Last_Error'] ?? $mysqlSlaveStatus['last_error'] ?? '';
                        ?>
                        <?php if (!empty($lastErr)): ?>
                        <tr>
                            <td class="text-muted">Ultimo Error</td>
                            <td><span class="text-danger"><?= View::e($lastErr) ?></span></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="text-muted"><i class="bi bi-info-circle me-1"></i>No se pudo obtener el estado de replicacion.</p>
            <?php endif; ?>
        </div>

        <hr class="border-secondary">

        <div class="d-flex gap-2 flex-wrap">
            <form method="POST" action="/settings/replication/promote" onsubmit="return confirmPromote(event, 'mysql', this)">
                <?= \MuseDockPanel\View::csrf() ?>
                <input type="hidden" name="engine" value="mysql">
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="bi bi-arrow-up-circle me-1"></i>Promover a Master
                </button>
            </form>
            <form method="POST" action="/settings/replication/reset-standalone" onsubmit="return confirmReset(event, 'mysql', this)">
                <?= \MuseDockPanel\View::csrf() ?>
                <input type="hidden" name="engine" value="mysql">
                <button type="submit" class="btn btn-link btn-sm text-muted p-0">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Resetear a Standalone
                </button>
            </form>
        </div>

        <?php endif; ?>
    </div>
</div>
</div>

</div><!-- /.row -->

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- Databases inventory (hidden, used by JS for slave warning)  -->
<!-- ═══════════════════════════════════════════════════════════ -->
<script id="pgDatabasesJson" type="application/json"><?= json_encode(array_map(function($db) {
    return is_array($db) ? ($db['datname'] ?? $db['name'] ?? '?') : $db;
}, $pgDatabases)) ?></script>
<script id="mysqlDatabasesJson" type="application/json"><?= json_encode($mysqlDatabases) ?></script>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- JavaScript                                                   -->
<!-- ═══════════════════════════════════════════════════════════ -->
<script>
(function() {
    'use strict';

    // ── CSRF Token ──
    const csrfToken = document.querySelector('input[name=_csrf_token]')?.value;

    // ── Roles (from PHP) ──
    const pgRole    = <?= json_encode($pgRole) ?>;
    const mysqlRole = <?= json_encode($mysqlRole) ?>;

    // ── Toast helper ──
    window.toastOk = function(msg) {
        if (typeof SwalDark !== 'undefined') {
            SwalDark.fire({ toast: true, position: 'top-end', icon: 'success', title: msg, showConfirmButton: false, timer: 2000 });
        }
    };

    function toastErr(msg) {
        if (typeof SwalDark !== 'undefined') {
            SwalDark.fire({ toast: true, position: 'top-end', icon: 'error', title: msg, showConfirmButton: false, timer: 4000 });
        }
    }

    // ── AJAX POST helper ──
    async function ajaxPost(url, data = {}) {
        data._csrf_token = csrfToken;
        try {
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(data)
            });
            const json = await resp.json();
            if (!resp.ok || json.error) {
                throw new Error(json.error || json.message || 'Error del servidor');
            }
            return json;
        } catch (e) {
            toastErr(e.message || 'Error de conexion');
            throw e;
        }
    }

    // ── Confirm Activate Master ──
    window.confirmActivateMaster = function(e, engine, form) {
        e.preventDefault();
        const label = engine === 'pg' ? 'PostgreSQL' : 'MySQL';
        SwalDark.fire({
            title: 'Activar como Master',
            html: '<p>Se configurara <b>' + label + '</b> como servidor Master de replicacion.</p><p>Se habilitaran los ajustes necesarios para aceptar conexiones de slaves.</p>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Activar Master',
            confirmButtonColor: '#198754',
            cancelButtonText: 'Cancelar'
        }).then(function(result) {
            if (result.isConfirmed) form.submit();
        });
        return false;
    };

    // ── Create Replication User ──
    window.createReplUser = async function(engine) {
        try {
            const data = await ajaxPost('/settings/replication/repl-user/create', { engine: engine });
            SwalDark.fire({
                title: 'Usuario Creado',
                html: '<p>Usuario: <code>' + data.username + '</code></p>' +
                      '<p>Password: <code id="newPass">' + data.password + '</code></p>' +
                      '<button class="btn btn-sm btn-outline-light" onclick="navigator.clipboard.writeText(\'' + data.password + '\').then(function(){window.toastOk(\'Copiado\')})">' +
                      '<i class="bi bi-clipboard"></i> Copiar Password</button>' +
                      '<p class="text-warning mt-2"><small>Este password solo se muestra una vez. Copialo ahora.</small></p>',
                icon: 'success'
            }).then(function() { location.reload(); });
        } catch (e) { /* handled by ajaxPost */ }
    };

    // ── Delete Replication User ──
    window.deleteReplUser = async function(id, engine) {
        const result = await SwalDark.fire({
            title: 'Eliminar usuario',
            text: 'Se eliminara este usuario de replicacion. Los slaves que lo usen dejaran de funcionar.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Eliminar',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Cancelar'
        });
        if (!result.isConfirmed) return;
        try {
            await ajaxPost('/settings/replication/repl-user/delete', { id: id, engine: engine });
            toastOk('Usuario eliminado');
            setTimeout(function() { location.reload(); }, 800);
        } catch (e) { /* handled */ }
    };

    // ── Add Authorized IP ──
    window.addAuthorizedIp = async function(engine) {
        var ipInput    = document.getElementById(engine + 'NewIp');
        var labelInput = document.getElementById(engine + 'NewIpLabel');
        var ip    = ipInput ? ipInput.value.trim() : '';
        var label = labelInput ? labelInput.value.trim() : '';
        if (!ip) { toastErr('Ingresa una IP'); return; }
        try {
            await ajaxPost('/settings/replication/authorized-ip/add', { engine: engine, ip_address: ip, label: label });
            toastOk('IP agregada');
            setTimeout(function() { location.reload(); }, 800);
        } catch (e) { /* handled */ }
    };

    // ── Remove Authorized IP ──
    window.removeAuthorizedIp = async function(id, engine) {
        const result = await SwalDark.fire({
            title: 'Eliminar IP',
            text: 'Esta IP ya no podra conectarse como slave.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Eliminar',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Cancelar'
        });
        if (!result.isConfirmed) return;
        try {
            await ajaxPost('/settings/replication/authorized-ip/remove', { id: id, engine: engine });
            toastOk('IP eliminada');
            setTimeout(function() { location.reload(); }, 800);
        } catch (e) { /* handled */ }
    };

    // ── Test Slave-Master Connection ──
    window.testSlaveMaster = async function(engine) {
        var ip   = document.getElementById(engine + 'SlaveMasterIp');
        var port = document.getElementById(engine + 'SlavePort');
        var user = document.getElementById(engine + 'SlaveUser');
        var pass = document.getElementById(engine + 'SlavePass');
        ip   = ip   ? ip.value.trim()   : '';
        port = port ? port.value.trim() : '';
        user = user ? user.value.trim() : '';
        pass = pass ? pass.value.trim() : '';
        if (!ip || !user || !pass) {
            toastErr('Completa todos los campos');
            return;
        }
        try {
            const data = await ajaxPost('/settings/replication/test-slave-master', {
                engine: engine, ip: ip, port: port, user: user, password: pass
            });
            if (data.success) {
                SwalDark.fire({ title: 'Conexion exitosa', html: '<p class="text-success">Se pudo conectar al master correctamente.</p>', icon: 'success' });
            } else {
                SwalDark.fire({ title: 'Conexion fallida', html: '<p class="text-danger">' + (data.message || 'No se pudo conectar.') + '</p>', icon: 'error' });
            }
        } catch (e) { /* handled */ }
    };

    // ── Convert to Slave (with DELETE confirmation) ──
    window.confirmConvertSlave = function(engine) {
        var ipEl   = document.getElementById(engine + 'SlaveMasterIp');
        var portEl = document.getElementById(engine + 'SlavePort');
        var userEl = document.getElementById(engine + 'SlaveUser');
        var passEl = document.getElementById(engine + 'SlavePass');
        var ip   = ipEl   ? ipEl.value.trim()   : '';
        var port = portEl ? portEl.value.trim() : '';
        var user = userEl ? userEl.value.trim() : '';
        var pass = passEl ? passEl.value.trim() : '';
        if (!ip || !user || !pass) {
            toastErr('Completa todos los campos primero');
            return;
        }

        // Get databases list
        var dbJsonEl = document.getElementById(engine + 'DatabasesJson');
        var databases = [];
        try { databases = JSON.parse(dbJsonEl ? dbJsonEl.textContent : '[]'); } catch(ex) {}
        var dbList = databases.length > 0
            ? '<ul class="text-start">' + databases.map(function(d) { return '<li><code>' + escHtml(d) + '</code></li>'; }).join('') + '</ul>'
            : '<p class="text-muted">No se detectaron bases de datos.</p>';

        var label = engine === 'pg' ? 'PostgreSQL' : 'MySQL';

        SwalDark.fire({
            title: 'PELIGRO: Convertir en Slave',
            html: '<div class="text-start">' +
                  '<div class="alert" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#f87171;">' +
                  '<i class="bi bi-exclamation-octagon me-1"></i>' +
                  '<strong>Activar streaming replication borrará TODAS las bases de datos locales</strong> de ' + label + ' y las reemplazará con una copia exacta del master.' +
                  '</div>' +
                  '<p><strong>Bases de datos que se perderán:</strong></p>' +
                  dbList +
                  '<div class="form-check form-switch mb-3">' +
                  '<input class="form-check-input" type="checkbox" id="autoBackupCheck" checked>' +
                  '<label class="form-check-label" for="autoBackupCheck">' +
                  'Crear backup automático antes de proceder' +
                  '</label>' +
                  '</div>' +
                  '<div class="small text-muted mb-3">' +
                  '<i class="bi bi-shield-check me-1"></i>' +
                  'El backup se guardará en <code>/var/backups/musedock/pre-replication/</code> y podrá restaurarse manualmente si es necesario.' +
                  '</div>' +
                  '<p>Escribe <b>DELETE</b> para confirmar:</p>' +
                  '</div>',
            input: 'text',
            inputPlaceholder: 'DELETE',
            inputValidator: function(value) { return value !== 'DELETE' ? 'Escribe DELETE para confirmar' : null; },
            confirmButtonText: 'Convertir en Slave',
            confirmButtonColor: '#dc3545',
            showCancelButton: true,
            cancelButtonText: 'Cancelar'
        }).then(function(result) {
            if (result.isConfirmed) {
                var autoBackup = document.getElementById('autoBackupCheck');
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '/settings/replication/convert-to-slave';
                form.style.display = 'none';

                var fields = {
                    '_csrf_token': csrfToken,
                    'engine': engine,
                    'master_ip': ip,
                    'port': port,
                    'user': user,
                    'pass': pass,
                    'confirm': 'DELETE',
                    'auto_backup': (autoBackup && autoBackup.checked) ? '1' : '0'
                };
                for (var key in fields) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = fields[key];
                    form.appendChild(input);
                }
                document.body.appendChild(form);
                form.submit();
            }
        });
    };

    // ── Confirm Promote ──
    window.confirmPromote = function(e, engine, form) {
        e.preventDefault();
        var label = engine === 'pg' ? 'PostgreSQL' : 'MySQL';
        SwalDark.fire({
            title: 'Promover a Master',
            html: '<p>Se promovera este servidor ' + label + ' de <b>Slave</b> a <b>Master</b>.</p>' +
                  '<p>La replicacion con el master actual se detendra.</p>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Promover',
            confirmButtonColor: '#ffc107',
            cancelButtonText: 'Cancelar'
        }).then(function(result) {
            if (result.isConfirmed) form.submit();
        });
        return false;
    };

    // ── Confirm Reset to Standalone ──
    window.confirmReset = function(e, engine, form) {
        e.preventDefault();
        var label = engine === 'pg' ? 'PostgreSQL' : 'MySQL';
        SwalDark.fire({
            title: 'Resetear a Standalone',
            html: '<p>Se desactivara la replicacion de ' + label + ' y volvera a modo standalone.</p>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Resetear',
            confirmButtonColor: '#6c757d',
            cancelButtonText: 'Cancelar'
        }).then(function(result) {
            if (result.isConfirmed) form.submit();
        });
        return false;
    };

    // ── Auto Configure Replication ──
    window.autoConfigureRepl = async function(nodeId, engine, nodeName, nodeIp) {
        var label = engine === 'pg' ? 'PostgreSQL' : 'MySQL';
        nodeName = nodeName || 'Nodo #' + nodeId;
        nodeIp = nodeIp || '';

        var result = await SwalDark.fire({
            title: 'Convertir este nodo en Slave de ' + label,
            html: '<div class="text-start">' +
                  '<div class="alert" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#f87171;">' +
                  '<i class="bi bi-exclamation-octagon me-1"></i>' +
                  '<strong>TODAS las bases de datos locales de ' + label + ' serán eliminadas</strong> y reemplazadas con una copia exacta del Master.' +
                  '</div>' +
                  '<div class="p-2 mb-3 rounded" style="background:#2a2a3e;">' +
                  '<div class="d-flex justify-content-between">' +
                  '<span><strong>' + escHtml(nodeName) + '</strong>' + (nodeIp ? ' <code>(' + escHtml(nodeIp) + ')</code>' : '') + '</span>' +
                  '<span class="badge bg-success">Master</span>' +
                  '</div>' +
                  '<hr class="border-secondary my-2">' +
                  '<div class="d-flex justify-content-between">' +
                  '<span><strong>Este servidor</strong> (local)</span>' +
                  '<span class="badge bg-warning text-dark">Slave</span>' +
                  '</div>' +
                  '</div>' +
                  '<p><strong>Lo que hará:</strong></p>' +
                  '<ol class="small">' +
                  '<li>Configurar <strong>' + escHtml(nodeName) + '</strong> como Master de ' + label + '</li>' +
                  '<li>Crear usuario de replicación en el Master</li>' +
                  '<li>Crear backup automático de las BD locales</li>' +
                  '<li>Configurar <strong>este servidor</strong> como Slave (réplica)</li>' +
                  '</ol>' +
                  '<hr class="border-secondary">' +
                  '<p class="mb-1">Escriba su contraseña de administrador para confirmar:</p>' +
                  '<input type="password" id="swal-auto-repl-pass" class="form-control bg-dark text-light border-secondary" placeholder="Contraseña de admin">' +
                  '</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, convertir en Slave',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Cancelar',
            preConfirm: function() {
                var pass = document.getElementById('swal-auto-repl-pass').value;
                if (!pass) {
                    Swal.showValidationMessage('Debe ingresar la contraseña');
                    return false;
                }
                return fetch('/settings/cluster/verify-admin-password', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: '_csrf_token=' + encodeURIComponent(csrfToken) +
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
        });
        if (!result.isConfirmed) return;

        SwalDark.fire({
            title: 'Configurando replicación...',
            html: '<p>Configurando ' + label + ' entre <strong>' + escHtml(nodeName) + '</strong> (Master) y este servidor (Slave).</p>' +
                  '<p class="small text-muted">Esto puede tardar unos minutos.</p>',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });
        try {
            var data = await ajaxPost('/settings/replication/auto-configure', { node_id: nodeId, engine: engine });
            SwalDark.fire({
                title: 'Replicación configurada',
                html: '<p class="text-success"><i class="bi bi-check-circle me-1"></i>' + (data.message || 'Este servidor es ahora Slave de ' + label + '.') + '</p>',
                icon: 'success'
            }).then(function() { location.reload(); });
        } catch (e) {
            SwalDark.fire({
                title: 'Error',
                html: '<p class="text-danger"><i class="bi bi-x-circle me-1"></i>' + (e.message || 'Error en la configuración automática.') + '</p>',
                icon: 'error'
            });
        }
    };

    // ── HTML escape helper ──
    function escHtml(str) {
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    // ── Status Polling with Countdown ──
    function startStatusPolling() {
        if (pgRole === 'standalone' && mysqlRole === 'standalone') return;

        var countdownEl = document.getElementById('pollCountdown');
        var seconds = 5;

        function tick() {
            if (!countdownEl) return;
            if (seconds > 0) {
                countdownEl.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Actualizando en ' + seconds + 's...';
                seconds--;
            } else {
                countdownEl.innerHTML = '<i class="bi bi-arrow-repeat me-1 spin-icon"></i>Actualizando...';
                fetchStatus();
            }
        }

        async function fetchStatus() {
            try {
                var resp = await fetch('/settings/replication/status', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                var data = await resp.json();

                if (data.pg && document.getElementById('pgMonitor')) {
                    updatePgMonitor(data.pg);
                }
                if (data.mysql && document.getElementById('mysqlMonitor')) {
                    updateMysqlMonitor(data.mysql);
                }
            } catch (e) {
                console.warn('Error polling replication status:', e);
            }
            seconds = 5;
        }

        function updatePgMonitor(pg) {
            var el = document.getElementById('pgMonitor');
            if (!el) return;

            if (pgRole === 'master' && pg.master_status) {
                var slaves = Array.isArray(pg.master_status) ? pg.master_status : [pg.master_status];
                if (slaves.length === 0 || (!slaves[0].client_addr && !slaves[0].state)) {
                    el.innerHTML = '<p class="text-muted"><i class="bi bi-info-circle me-1"></i>Sin slaves conectados actualmente.</p>';
                    return;
                }
                var html = '<div class="table-responsive"><table class="table table-dark table-hover table-sm">' +
                    '<thead><tr><th>Slave</th><th>Estado</th><th>Sent LSN</th><th>Write LSN</th><th>Flush LSN</th><th>Replay LSN</th><th>Lag</th></tr></thead><tbody>';
                slaves.forEach(function(s) {
                    var sc = s.state === 'streaming' ? 'success' : 'warning';
                    html += '<tr><td>' + escHtml(s.client_addr || '—') + '</td>' +
                        '<td><span class="badge bg-' + sc + '">' + escHtml(s.state || '—') + '</span></td>' +
                        '<td><code>' + escHtml(s.sent_lsn || '—') + '</code></td>' +
                        '<td><code>' + escHtml(s.write_lsn || '—') + '</code></td>' +
                        '<td><code>' + escHtml(s.flush_lsn || '—') + '</code></td>' +
                        '<td><code>' + escHtml(s.replay_lsn || '—') + '</code></td>' +
                        '<td>' + escHtml(s.replay_lag || '—') + '</td></tr>';
                });
                html += '</tbody></table></div>';
                el.innerHTML = html;
            } else if (pgRole === 'slave' && pg.slave_status) {
                var s = pg.slave_status;
                var lagVal = s.lag_seconds !== undefined ? s.lag_seconds : (s.replay_lag !== undefined ? s.replay_lag : '—');
                var lagCls = !isNaN(lagVal) && Number(lagVal) > 10 ? 'text-danger' : 'text-success';
                var stVal = s.status || s.state || 'unknown';
                var stCls = stVal === 'streaming' ? 'success' : 'warning';
                el.innerHTML = '<div class="table-responsive"><table class="table table-dark table-hover table-sm"><tbody>' +
                    '<tr><td class="text-muted" style="width:40%">Sender Host</td><td><code>' + escHtml(s.sender_host || '—') + '</code></td></tr>' +
                    '<tr><td class="text-muted">Receive LSN</td><td><code>' + escHtml(s.receive_lsn || s.received_lsn || '—') + '</code></td></tr>' +
                    '<tr><td class="text-muted">Replay LSN</td><td><code>' + escHtml(s.replay_lsn || '—') + '</code></td></tr>' +
                    '<tr><td class="text-muted">Lag (segundos)</td><td><span class="' + lagCls + '">' + escHtml(String(lagVal)) + '</span></td></tr>' +
                    '<tr><td class="text-muted">Estado</td><td><span class="badge bg-' + stCls + '">' + escHtml(stVal) + '</span></td></tr>' +
                    '</tbody></table></div>';
            }
        }

        function updateMysqlMonitor(mysql) {
            var el = document.getElementById('mysqlMonitor');
            if (!el) return;

            if (mysqlRole === 'master' && mysql.master_status) {
                var s = mysql.master_status;
                var html = '<div class="table-responsive"><table class="table table-dark table-hover table-sm"><tbody>' +
                    '<tr><td class="text-muted" style="width:40%">File</td><td><code>' + escHtml(s.File || '—') + '</code></td></tr>' +
                    '<tr><td class="text-muted">Position</td><td><code>' + escHtml(String(s.Position || '—')) + '</code></td></tr>' +
                    '<tr><td class="text-muted">Binlog Do DB</td><td>' + escHtml(s.Binlog_Do_DB || '—') + '</td></tr>' +
                    '<tr><td class="text-muted">Binlog Ignore DB</td><td>' + escHtml(s.Binlog_Ignore_DB || '—') + '</td></tr>';
                if (s.Executed_Gtid_Set) {
                    html += '<tr><td class="text-muted">GTID Set</td><td><code class="small">' + escHtml(s.Executed_Gtid_Set) + '</code></td></tr>';
                }
                html += '</tbody></table></div>';
                el.innerHTML = html;
            } else if (mysqlRole === 'slave' && mysql.slave_status) {
                var s = mysql.slave_status;
                var ioRun  = s.Slave_IO_Running  || s.io_running  || '—';
                var sqlRun = s.Slave_SQL_Running  || s.sql_running || '—';
                var lag    = s.Seconds_Behind_Master !== undefined ? s.Seconds_Behind_Master : (s.seconds_behind !== undefined ? s.seconds_behind : '—');
                var lagCls = !isNaN(lag) && Number(lag) > 10 ? 'text-danger' : 'text-success';
                var lastErr = s.Last_Error || s.last_error || '';
                var html = '<div class="table-responsive"><table class="table table-dark table-hover table-sm"><tbody>' +
                    '<tr><td class="text-muted" style="width:40%">IO Running</td><td><span class="badge bg-' + (ioRun === 'Yes' ? 'success' : 'danger') + '">' + escHtml(ioRun) + '</span></td></tr>' +
                    '<tr><td class="text-muted">SQL Running</td><td><span class="badge bg-' + (sqlRun === 'Yes' ? 'success' : 'danger') + '">' + escHtml(sqlRun) + '</span></td></tr>' +
                    '<tr><td class="text-muted">Seconds Behind Master</td><td><span class="' + lagCls + '">' + escHtml(String(lag)) + '</span></td></tr>' +
                    '<tr><td class="text-muted">Master Host</td><td><code>' + escHtml(s.Master_Host || s.master_host || '—') + '</code></td></tr>' +
                    '<tr><td class="text-muted">Master Log File</td><td><code>' + escHtml(s.Master_Log_File || s.master_log_file || '—') + '</code></td></tr>' +
                    '<tr><td class="text-muted">Read Master Log Pos</td><td><code>' + escHtml(String(s.Read_Master_Log_Pos || s.read_master_log_pos || '—')) + '</code></td></tr>';
                if (lastErr) {
                    html += '<tr><td class="text-muted">Ultimo Error</td><td><span class="text-danger">' + escHtml(lastErr) + '</span></td></tr>';
                }
                html += '</tbody></table></div>';
                el.innerHTML = html;
            }
        }

        setInterval(tick, 1000);
        tick();
    }

    // ── Init ──
    document.addEventListener('DOMContentLoaded', function() {
        startStatusPolling();
    });

})();
</script>

<style>
    .spin-icon {
        display: inline-block;
        animation: spin 1s linear infinite;
    }
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
</style>
