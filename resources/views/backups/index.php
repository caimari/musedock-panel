<?php use MuseDockPanel\View; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <span class="text-muted"><?= count($backups) ?> backup(s) en total</span>
    </div>
    <a href="/backups/create" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Nuevo Backup
    </a>
</div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-cloud-arrow-down me-2"></i>Backups
    </div>
    <div class="card-body p-0">
        <?php if (empty($backups)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-cloud-arrow-down" style="font-size: 2rem;"></i>
                <p class="mt-2">No hay backups. Crea uno para comenzar.</p>
                <a href="/backups/create" class="btn btn-outline-light btn-sm"><i class="bi bi-plus-lg me-1"></i> Crear Backup</a>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Cuenta</th>
                        <th>Fecha</th>
                        <th>Tamano</th>
                        <th>Contenido</th>
                        <th class="text-end pe-3">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td class="ps-3">
                            <i class="bi bi-server me-1" style="color: #38bdf8;"></i>
                            <strong><?= View::e($backup['username'] ?? '') ?></strong>
                            <br>
                            <small class="text-muted"><?= View::e($backup['domain'] ?? '') ?></small>
                        </td>
                        <td>
                            <small><?= date('d/m/Y H:i', strtotime($backup['date'] ?? 'now')) ?></small>
                        </td>
                        <td>
                            <?php
                                $size = $backup['file_size'] ?? 0;
                                if ($size >= 1073741824) echo round($size / 1073741824, 2) . ' GB';
                                elseif ($size >= 1048576) echo round($size / 1048576, 2) . ' MB';
                                elseif ($size >= 1024) echo round($size / 1024, 2) . ' KB';
                                else echo $size . ' B';
                            ?>
                        </td>
                        <td>
                            <?php if (!empty($backup['has_files'])): ?>
                                <span class="badge" style="background: rgba(34,197,94,0.15); color: #22c55e;">
                                    <i class="bi bi-folder me-1"></i>Archivos
                                </span>
                            <?php endif; ?>
                            <?php if (($backup['db_count'] ?? 0) > 0): ?>
                                <span class="badge" style="background: rgba(56,189,248,0.15); color: #38bdf8;">
                                    <i class="bi bi-database me-1"></i><?= $backup['db_count'] ?> BD
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
                            <?php $dirName = View::e($backup['dir_name'] ?? ''); ?>

                            <?php if (!empty($backup['has_files'])): ?>
                            <a href="/backups/download?backup=<?= urlencode($backup['dir_name']) ?>&path=files.tar.gz"
                               class="btn btn-outline-light btn-sm" title="Descargar archivos">
                                <i class="bi bi-download"></i>
                            </a>
                            <?php endif; ?>

                            <a href="/backups/<?= urlencode($backup['dir_name']) ?>/restore"
                               class="btn btn-outline-success btn-sm" title="Restaurar">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>

                            <form method="POST" action="/backups/<?= urlencode($backup['dir_name']) ?>/delete"
                                  class="d-inline" id="delete-form-<?= $dirName ?>">
                                <?= View::csrf() ?>
                                <input type="hidden" name="admin_password" id="delete-pass-<?= $dirName ?>">
                                <button type="button" class="btn btn-outline-danger btn-sm" title="Eliminar"
                                        onclick="deleteBackup('<?= $dirName ?>', '<?= View::e($backup['username'] ?? '') ?>', '<?= date('d/m/Y H:i', strtotime($backup['date'] ?? 'now')) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
function deleteBackup(dirName, username, date) {
    SwalDark.fire({
        title: 'Eliminar backup?',
        html: 'Se eliminara el backup de <strong>' + username + '</strong> del <strong>' + date + '</strong>.<br><br>' +
              '<span style="color:#ef4444;">Esta accion es irreversible.</span><br><br>' +
              'Ingresa tu contrasena de administrador para confirmar:',
        icon: 'warning',
        input: 'password',
        inputPlaceholder: 'Contrasena de administrador',
        inputAttributes: {
            autocomplete: 'current-password'
        },
        showCancelButton: true,
        confirmButtonText: 'Si, eliminar',
        cancelButtonText: 'Cancelar',
        preConfirm: function(password) {
            if (!password) {
                Swal.showValidationMessage('Debes ingresar tu contrasena');
                return false;
            }
            return password;
        }
    }).then(function(result) {
        if (result.isConfirmed && result.value) {
            document.getElementById('delete-pass-' + dirName).value = result.value;
            document.getElementById('delete-form-' + dirName).submit();
        }
    });
}
</script>
