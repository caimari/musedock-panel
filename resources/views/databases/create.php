<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-database-add me-2"></i>Nueva Base de Datos MySQL</div>
            <div class="card-body">
                <form method="POST" action="/databases/store">
                    <?= View::csrf() ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Cuenta de Hosting *</label>
                            <select name="account_id" id="accountSelect" class="form-select" required>
                                <option value="">-- Seleccionar cuenta --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>" data-username="<?= View::e($acc['username']) ?>">
                                        <?= View::e($acc['username']) ?> (<?= View::e($acc['domain']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($accounts)): ?>
                                <small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>No hay cuentas activas. <a href="/accounts/create" class="text-info">Crea una primero</a>.</small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nombre de la Base de Datos *</label>
                            <div class="input-group">
                                <span class="input-group-text" id="dbPrefix" style="background: #334155; border-color: #334155; color: #94a3b8; font-family: monospace; font-size: 0.85rem;">usuario_</span>
                                <input type="text" name="db_name" id="dbNameInput" class="form-control" placeholder="mibasedatos" required pattern="[a-zA-Z0-9_]+" maxlength="50">
                            </div>
                            <small class="text-muted">Solo letras, numeros y guion bajo. Se creara con prefijo del usuario.</small>
                        </div>
                    </div>

                    <!-- Preview -->
                    <div id="dbPreview" class="mt-3 p-3 rounded" style="background: rgba(56,189,248,0.05); border: 1px solid #334155; display: none;">
                        <small class="text-muted">
                            <i class="bi bi-eye me-1"></i> <strong>Vista previa:</strong><br>
                            <i class="bi bi-database me-1"></i> Base de datos: <code id="previewDbName">-</code><br>
                            <i class="bi bi-person me-1"></i> Usuario MySQL: <code id="previewDbUser">-</code><br>
                            <i class="bi bi-key me-1"></i> Contrasena: <span style="color: #94a3b8;">Se generara automaticamente</span><br>
                            <i class="bi bi-hdd-network me-1"></i> Host: <code>localhost</code>
                        </small>
                    </div>

                    <div class="mt-3 p-3 rounded" style="background: rgba(251,191,36,0.05); border: 1px solid rgba(251,191,36,0.2);">
                        <small style="color: #fbbf24;">
                            <i class="bi bi-info-circle me-1"></i>
                            La contrasena se generara automaticamente y se mostrara una sola vez despues de crear la base de datos. Asegurate de copiarla.
                        </small>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Crear Base de Datos</button>
                        <a href="/databases" class="btn btn-outline-light">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var accountSelect = document.getElementById('accountSelect');
    var dbNameInput = document.getElementById('dbNameInput');
    var dbPrefix = document.getElementById('dbPrefix');
    var previewDbName = document.getElementById('previewDbName');
    var previewDbUser = document.getElementById('previewDbUser');
    var previewBox = document.getElementById('dbPreview');

    function updatePreview() {
        var option = accountSelect.options[accountSelect.selectedIndex];
        var username = option ? (option.dataset.username || '') : '';
        var dbName = dbNameInput.value.trim();

        if (username) {
            dbPrefix.textContent = username + '_';
        } else {
            dbPrefix.textContent = 'usuario_';
        }

        if (username && dbName) {
            var fullName = username + '_' + dbName;
            previewDbName.textContent = fullName;
            previewDbUser.textContent = fullName;
            previewBox.style.display = 'block';
        } else {
            previewBox.style.display = 'none';
        }
    }

    accountSelect.addEventListener('change', updatePreview);
    dbNameInput.addEventListener('input', updatePreview);
})();
</script>
