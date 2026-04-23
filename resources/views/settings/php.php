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

        <!-- OPcache + JIT -->
        <div class="mt-3 pt-3" style="border-top: 1px solid #334155;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0"><i class="bi bi-lightning-charge me-1" style="color:#fbbf24;"></i>OPcache / JIT</h6>
                <?php
                $jitMode = $info['opcache']['opcache.jit'] ?? 'off';
                $opcacheOn = ($info['opcache']['opcache.enable'] ?? '0') === '1';
                ?>
                <div>
                    <?php if ($opcacheOn): ?>
                        <span class="badge bg-success">OPcache ON</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">OPcache OFF</span>
                    <?php endif; ?>
                    <?php if ($jitMode !== 'off' && $jitMode !== '0'): ?>
                        <span class="badge ms-1" style="background:rgba(251,191,36,0.15);color:#fbbf24;">JIT: <?= View::e($jitMode) ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-1">JIT OFF</span>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST" action="/settings/php/opcache-save">
                <?= View::csrf() ?>
                <input type="hidden" name="version" value="<?= View::e($ver) ?>">

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">opcache.enable</label>
                        <select name="opcache_enable" class="form-select">
                            <option value="1" <?= ($info['opcache']['opcache.enable'] ?? '0') === '1' ? 'selected' : '' ?>>1 (ON)</option>
                            <option value="0" <?= ($info['opcache']['opcache.enable'] ?? '0') === '0' ? 'selected' : '' ?>>0 (OFF)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">memory_consumption</label>
                        <div class="input-group">
                            <input type="number" name="opcache_memory_consumption" class="form-control" value="<?= View::e($info['opcache']['opcache.memory_consumption'] ?? '128') ?>" min="32" max="2048">
                            <span class="input-group-text" style="background:#0f172a;border-color:#334155;color:#64748b;">MB</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">interned_strings_buffer</label>
                        <div class="input-group">
                            <input type="number" name="opcache_interned_strings_buffer" class="form-control" value="<?= View::e($info['opcache']['opcache.interned_strings_buffer'] ?? '8') ?>" min="4" max="128">
                            <span class="input-group-text" style="background:#0f172a;border-color:#334155;color:#64748b;">MB</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">max_accelerated_files</label>
                        <input type="number" name="opcache_max_accelerated_files" class="form-control" value="<?= View::e($info['opcache']['opcache.max_accelerated_files'] ?? '10000') ?>" min="200" max="1000000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">revalidate_freq</label>
                        <div class="input-group">
                            <input type="number" name="opcache_revalidate_freq" class="form-control" value="<?= View::e($info['opcache']['opcache.revalidate_freq'] ?? '2') ?>" min="0" max="3600">
                            <span class="input-group-text" style="background:#0f172a;border-color:#334155;color:#64748b;">seg</span>
                        </div>
                        <small class="text-muted">0=siempre verificar, 60=produccion</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">jit</label>
                        <select name="opcache_jit" class="form-select">
                            <option value="off" <?= $jitMode === 'off' ? 'selected' : '' ?>>off (desactivado)</option>
                            <option value="1255" <?= $jitMode === '1255' ? 'selected' : '' ?>>1255 (tracing optimizado)</option>
                            <option value="1235" <?= $jitMode === '1235' ? 'selected' : '' ?>>1235 (tracing)</option>
                            <option value="function" <?= $jitMode === 'function' ? 'selected' : '' ?>>function</option>
                            <option value="tracing" <?= $jitMode === 'tracing' ? 'selected' : '' ?>>tracing</option>
                            <option value="on" <?= $jitMode === 'on' ? 'selected' : '' ?>>on</option>
                        </select>
                        <small class="text-muted">1255 = maximo rendimiento</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">jit_buffer_size</label>
                        <input type="text" name="opcache_jit_buffer_size" class="form-control" value="<?= View::e($info['opcache']['opcache.jit_buffer_size'] ?? '0') ?>" placeholder="64M">
                        <small class="text-muted">Ej: 64M, 128M (0=off)</small>
                    </div>
                </div>

                <div class="mt-2 p-2 rounded small" style="background:rgba(251,191,36,0.05);border:1px solid rgba(251,191,36,0.15);color:#94a3b8;">
                    <i class="bi bi-lightbulb me-1" style="color:#fbbf24;"></i>
                    <strong>Produccion recomendado:</strong> enable=1, memory=192, strings=16, files=10000, revalidate=60, jit=1255, jit_buffer=64M
                    <button type="button" class="btn btn-outline-warning btn-sm py-0 px-2 ms-2" style="font-size:0.7rem;" onclick="applyOpcachePreset('<?= View::e($ver) ?>')">Aplicar preset</button>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Guardar OPcache y reiniciar FPM</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
function applyOpcachePreset(ver) {
    var forms = document.querySelectorAll('form[action="/settings/php/opcache-save"]');
    for (var form of forms) {
        if (form.querySelector('input[name=version]').value !== ver) continue;
        form.querySelector('[name=opcache_enable]').value = '1';
        form.querySelector('[name=opcache_memory_consumption]').value = '192';
        form.querySelector('[name=opcache_interned_strings_buffer]').value = '16';
        form.querySelector('[name=opcache_max_accelerated_files]').value = '10000';
        form.querySelector('[name=opcache_revalidate_freq]').value = '60';
        form.querySelector('[name=opcache_jit]').value = '1255';
        form.querySelector('[name=opcache_jit_buffer_size]').value = '64M';
        SwalDark.fire({icon:'success', title:'Preset aplicado', text:'Valores de produccion cargados. Pulsa Guardar para aplicar.', timer:2000});
        break;
    }
}
</script>

<?php endif; ?>
