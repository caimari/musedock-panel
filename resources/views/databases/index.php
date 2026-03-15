<?php use MuseDockPanel\View; use MuseDockPanel\Flash; ?>

<?php
// Check for credentials flash (show copyable block)
$creds = Flash::get('db_credentials');
if ($creds) {
    $creds = json_decode($creds, true);
}
?>

<?php if ($creds): ?>
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

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <span class="text-muted"><?= $totalDbs ?> base(s) de datos en total</span>
    </div>
    <a href="/databases/create" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Nueva Base de Datos
    </a>
</div>

<!-- System Database -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-shield-lock me-2"></i>Base de Datos del Sistema
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Base de Datos</th>
                    <th>Usuario DB</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th class="text-end pe-3">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="ps-3">
                        <code><?= View::e($systemDb['db_name']) ?></code>
                    </td>
                    <td>
                        <code><?= View::e($systemDb['db_user']) ?></code>
                    </td>
                    <td>
                        <span class="badge" style="background: rgba(34,197,94,0.15); color: #22c55e;">
                            PGSQL
                        </span>
                    </td>
                    <td>
                        <span class="badge" style="background: rgba(251,191,36,0.15); color: #fbbf24;">
                            <i class="bi bi-lock me-1"></i>Sistema
                        </span>
                    </td>
                    <td class="text-end pe-3">
                        <span class="text-muted"><small>Protegida</small></span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- User Databases -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-database me-2"></i>Bases de Datos de Cuentas
    </div>
    <div class="card-body p-0">
        <?php if (empty($grouped)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-database" style="font-size: 2rem;"></i>
                <p class="mt-2">No hay bases de datos. Crea una para comenzar.</p>
                <a href="/databases/create" class="btn btn-outline-light btn-sm"><i class="bi bi-plus-lg me-1"></i> Crear Base de Datos</a>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Base de Datos</th>
                        <th>Usuario DB</th>
                        <th>Tipo</th>
                        <th>Creada</th>
                        <th class="text-end pe-3">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped as $group): ?>
                    <!-- Account group header -->
                    <tr style="background: rgba(56,189,248,0.05);">
                        <td colspan="5" class="ps-3 py-2">
                            <i class="bi bi-server me-1" style="color: #38bdf8;"></i>
                            <a href="/accounts/<?= $group['account_id'] ?>" class="text-info text-decoration-none fw-semibold">
                                <?= View::e($group['username']) ?>
                            </a>
                            <small class="text-muted ms-2"><?= View::e($group['domain']) ?></small>
                            <span class="badge bg-dark ms-2"><?= count($group['databases']) ?> DB</span>
                        </td>
                    </tr>
                    <?php foreach ($group['databases'] as $db): ?>
                    <tr>
                        <td class="ps-4">
                            <code><?= View::e($db['db_name']) ?></code>
                        </td>
                        <td>
                            <code><?= View::e($db['db_user']) ?></code>
                        </td>
                        <td>
                            <?php if (($db['db_type'] ?? 'mysql') === 'pgsql'): ?>
                                <span class="badge" style="background: rgba(34,197,94,0.15); color: #22c55e;">
                                    PGSQL
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background: rgba(56,189,248,0.15); color: #38bdf8;">
                                    MYSQL
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted"><?= date('d/m/Y H:i', strtotime($db['created_at'])) ?></small>
                        </td>
                        <td class="text-end pe-3">
                            <?php if (empty($db['is_system'])): ?>
                                <form method="POST" action="/databases/<?= $db['id'] ?>/delete" class="d-inline delete-db-form">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="password" class="delete-password-field" value="">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar"
                                            data-db-name="<?= View::e($db['db_name']) ?>"
                                            data-db-user="<?= View::e($db['db_user']) ?>"
                                            data-db-type="<?= View::e(strtoupper($db['db_type'] ?? 'mysql')) ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted"><small>Protegida</small></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
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
