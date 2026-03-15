<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<div class="alert py-2 px-3 mb-3" style="background:rgba(56,189,248,0.1);border:1px solid rgba(56,189,248,0.2);color:#94a3b8;font-size:0.85rem;">
    <i class="bi bi-info-circle text-info me-1"></i>
    <strong>Configuracion global</strong> — estos valores afectan a <strong>todas las cuentas</strong> que usen esta version de PHP.
    Cada cuenta de hosting puede sobreescribir estos valores desde su ficha individual (pool FPM propio).
</div>

<?php if (empty($versions)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-exclamation-triangle" style="font-size:2rem;"></i>
        <p class="mt-2">No se encontraron versiones de PHP-FPM instaladas.</p>
    </div>
</div>
<?php else: ?>

<?php foreach ($versions as $ver => $info): ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-filetype-php me-1"></i> PHP <?= View::e($ver) ?>
            <?php if ($info['status'] === 'active'): ?>
                <span class="badge bg-success ms-1">activo</span>
            <?php else: ?>
                <span class="badge bg-danger ms-1"><?= View::e($info['status']) ?></span>
            <?php endif; ?>
        </span>
        <span class="text-muted small"><?= View::e($info['pools']) ?> pool(s) — <?= View::e($info['ini_file']) ?></span>
    </div>
    <div class="card-body">
        <form method="POST" action="/settings/php/ini-save">
            <?= View::csrf() ?>
            <input type="hidden" name="version" value="<?= View::e($ver) ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">memory_limit</label>
                    <input type="text" name="memory_limit" class="form-control" value="<?= View::e($info['ini']['memory_limit'] ?? '128M') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">upload_max_filesize</label>
                    <input type="text" name="upload_max_filesize" class="form-control" value="<?= View::e($info['ini']['upload_max_filesize'] ?? '2M') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">post_max_size</label>
                    <input type="text" name="post_max_size" class="form-control" value="<?= View::e($info['ini']['post_max_size'] ?? '8M') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">max_execution_time</label>
                    <input type="text" name="max_execution_time" class="form-control" value="<?= View::e($info['ini']['max_execution_time'] ?? '30') ?>">
                    <small class="text-muted">segundos</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">max_input_time</label>
                    <input type="text" name="max_input_time" class="form-control" value="<?= View::e($info['ini']['max_input_time'] ?? '60') ?>">
                    <small class="text-muted">segundos (-1 = sin limite)</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">max_input_vars</label>
                    <input type="text" name="max_input_vars" class="form-control" value="<?= View::e($info['ini']['max_input_vars'] ?? '1000') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">display_errors</label>
                    <select name="display_errors" class="form-select">
                        <option value="Off" <?= ($info['ini']['display_errors'] ?? 'Off') === 'Off' ? 'selected' : '' ?>>Off</option>
                        <option value="On" <?= ($info['ini']['display_errors'] ?? 'Off') === 'On' ? 'selected' : '' ?>>On</option>
                    </select>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Guardar y reiniciar FPM</button>
            </div>
        </form>

        <!-- Extensions -->
        <div class="mt-3">
            <a class="text-muted small" data-bs-toggle="collapse" href="#ext-<?= View::e($ver) ?>"><i class="bi bi-puzzle me-1"></i><?= count($info['extensions']) ?> extensiones instaladas</a>
            <div class="collapse mt-2" id="ext-<?= View::e($ver) ?>">
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($info['extensions'] as $ext): ?>
                    <span class="badge bg-dark"><?= View::e($ext) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>
