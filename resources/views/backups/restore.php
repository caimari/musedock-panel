<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <?php if (empty($backup['account_exists'])): ?>
        <div class="alert" style="background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); color: #ef4444;">
            <i class="bi bi-exclamation-triangle me-1"></i>
            La cuenta <strong><?= View::e($backup['username'] ?? '') ?></strong> ya no existe en el sistema. No se puede restaurar este backup.
        </div>
        <a href="/backups" class="btn btn-outline-light">
            <i class="bi bi-arrow-left me-1"></i> Volver a Backups
        </a>
        <?php else: ?>

        <div class="card mb-3">
            <div class="card-header">
                <i class="bi bi-arrow-counterclockwise me-2"></i>Restaurar Backup
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <table class="table table-sm mb-0">
                            <tr>
                                <td class="text-muted" style="width: 120px;">Cuenta</td>
                                <td><strong><?= View::e($backup['username'] ?? '') ?></strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Dominio</td>
                                <td><?= View::e($backup['domain'] ?? '') ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Fecha</td>
                                <td><?= date('d/m/Y H:i:s', strtotime($backup['date'] ?? 'now')) ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Tamano total</td>
                                <td>
                                    <?php
                                        $size = $backup['file_size'] ?? 0;
                                        if ($size >= 1073741824) echo round($size / 1073741824, 2) . ' GB';
                                        elseif ($size >= 1048576) echo round($size / 1048576, 2) . ' MB';
                                        elseif ($size >= 1024) echo round($size / 1024, 2) . ' KB';
                                        else echo $size . ' B';
                                    ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <form method="POST" action="/backups/<?= urlencode($backup['dir_name']) ?>/restore" id="restoreForm">
                    <?= View::csrf() ?>

                    <h6 class="mb-3">Que restaurar:</h6>

                    <?php if (!empty($backup['has_files'])): ?>
                    <?php $backupScope = $backup['scope'] ?? 'httpdocs'; ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="restore_files" id="restoreFiles" value="1" checked>
                        <label class="form-check-label" for="restoreFiles">
                            <i class="bi bi-folder me-1" style="color: #22c55e;"></i> Archivos (files.tar.gz)
                            <?php if ($backupScope === 'full'): ?>
                                <span class="badge bg-success ms-1" style="font-size:0.7em;">Directorio completo</span>
                            <?php else: ?>
                                <span class="badge bg-secondary ms-1" style="font-size:0.7em;">Solo httpdocs/</span>
                            <?php endif; ?>
                        </label>
                        <br><small class="text-muted ms-4">Se extraeran los archivos a <code><?= View::e($backup['account']['home_dir'] ?? '') ?>/</code></small>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($backup['db_files'])): ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="restore_databases" id="restoreDatabases" value="1" checked>
                        <label class="form-check-label" for="restoreDatabases">
                            <i class="bi bi-database me-1" style="color: #38bdf8;"></i> Bases de datos
                        </label>
                    </div>

                    <div class="ms-4 mb-3">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Base de datos</th>
                                    <th>Tamano</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backup['db_files'] as $dbFile): ?>
                                <tr>
                                    <td><code><?= View::e($dbFile['name']) ?></code></td>
                                    <td>
                                        <?php
                                            $s = $dbFile['size'] ?? 0;
                                            if ($s >= 1048576) echo round($s / 1048576, 2) . ' MB';
                                            elseif ($s >= 1024) echo round($s / 1024, 2) . ' KB';
                                            else echo $s . ' B';
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <div class="alert" style="background: rgba(251,191,36,0.15); border: 1px solid rgba(251,191,36,0.3); color: #fbbf24;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Atencion:</strong> La restauracion sobreescribira los archivos y datos actuales de la cuenta.
                        Asegurate de tener un backup actual si necesitas los datos existentes.
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="/backups" class="btn btn-outline-light">
                            <i class="bi bi-arrow-left me-1"></i> Cancelar
                        </a>
                        <button type="button" class="btn btn-warning" onclick="confirmRestore()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Restaurar Backup
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<style>
    .form-check-input { background-color: #0f172a; border-color: #334155; }
    .form-check-input:checked { background-color: #0ea5e9; border-color: #0ea5e9; }
    .form-check-input:focus { box-shadow: 0 0 0 2px rgba(56,189,248,0.2); border-color: #38bdf8; }
</style>

<script>
function confirmRestore() {
    SwalDark.fire({
        title: 'Confirmar restauracion?',
        html: 'Se restaurara el backup de <strong><?= View::e($backup['username'] ?? '') ?></strong> del <strong><?= date('d/m/Y H:i', strtotime($backup['date'] ?? 'now')) ?></strong>.<br><br>' +
              '<span style="color:#fbbf24;">Los archivos y datos actuales seran sobreescritos.</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Si, restaurar',
        cancelButtonText: 'Cancelar',
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('restoreForm').submit();
        }
    });
}
</script>
