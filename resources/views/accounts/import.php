<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-lg-10">

        <div class="mb-3">
            <a href="/accounts" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver a Hosting Accounts</a>
        </div>

        <?php if (empty($orphans)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-check-circle" style="font-size:3rem;color:#22c55e;"></i>
                    <h5 class="mt-3">Todo sincronizado</h5>
                    <p class="text-muted">Todos los hostings en <code>/var/www/vhosts/</code> estan registrados en el panel.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-search me-2"></i>Hostings Detectados sin Registrar</span>
                    <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;"><?= count($orphans) ?> huerfano(s)</span>
                </div>
                <div class="card-body p-0">
                    <?php foreach ($orphans as $i => $o): ?>
                    <div class="p-3 <?= $i > 0 ? 'border-top' : '' ?>" style="border-color:#334155 !important;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <h6 class="mb-1">
                                    <i class="bi bi-globe me-1" style="color:#38bdf8;"></i><?= View::e($o['domain']) ?>
                                    <?php if (($o['source'] ?? 'vhosts') === 'caddy'): ?>
                                        <span class="badge ms-1" style="background:rgba(168,85,247,0.15);color:#a855f7;font-size:0.65rem;">desde Caddy</span>
                                    <?php endif; ?>
                                </h6>
                                <div class="row g-2 mt-1" style="font-size:0.85rem;">
                                    <div class="col-auto">
                                        <span class="text-muted">Usuario:</span>
                                        <?php if ($o['username']): ?>
                                            <code><?= View::e($o['username']) ?></code>
                                            <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.7rem;">UID <?= $o['uid'] ?></span>
                                        <?php else: ?>
                                            <span class="text-warning">No detectado</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-auto">
                                        <span class="text-muted">Home:</span> <code style="font-size:0.75rem;"><?= View::e($o['home_dir']) ?></code>
                                    </div>
                                    <div class="col-auto">
                                        <span class="text-muted">Doc root:</span> <code style="font-size:0.75rem;"><?= View::e($o['document_root']) ?></code>
                                    </div>
                                    <?php if ($o['php_version']): ?>
                                    <div class="col-auto">
                                        <span class="text-muted">PHP:</span> <code><?= View::e($o['php_version']) ?></code>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($o['fpm_pool']): ?>
                                    <div class="col-auto">
                                        <span class="text-muted">FPM Pool:</span>
                                        <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.7rem;">Existe</span>
                                    </div>
                                    <?php else: ?>
                                    <div class="col-auto">
                                        <span class="text-muted">FPM Pool:</span>
                                        <span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;font-size:0.7rem;">No encontrado</span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-auto">
                                        <span class="text-muted">Caddy:</span>
                                        <?php if ($o['caddy_route']): ?>
                                            <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.7rem;"><?= View::e($o['caddy_route']) ?></span>
                                        <?php else: ?>
                                            <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:0.7rem;">Se creara al importar</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-auto">
                                        <span class="text-muted">Shell:</span> <code style="font-size:0.75rem;"><?= View::e($o['shell']) ?></code>
                                    </div>
                                    <?php if ($o['disk_mb']): ?>
                                    <div class="col-auto">
                                        <span class="text-muted">Disco:</span> <code><?= $o['disk_mb'] ?> MB</code>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($o['warnings'])): ?>
                                <div class="mt-2">
                                    <?php foreach ($o['warnings'] as $w): ?>
                                        <small style="color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i><?= View::e($w) ?></small><br>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="ms-3">
                                <form method="POST" action="/accounts/import" onsubmit="return importConfirm(event, this, '<?= View::e(addslashes($o['domain'])) ?>')">
                    <?= \MuseDockPanel\View::csrf() ?>
                                    <input type="hidden" name="domain" value="<?= View::e($o['domain']) ?>">
                                    <input type="hidden" name="home_dir" value="<?= View::e($o['home_dir']) ?>">
                                    <input type="hidden" name="document_root" value="<?= View::e($o['document_root']) ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="bi bi-box-arrow-in-down me-1"></i>Importar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="p-2 rounded" style="background: rgba(56,189,248,0.05); border: 1px solid #334155;">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1" style="color:#38bdf8;"></i>
                    <strong>Importar</strong> registra el hosting en el panel y configura automaticamente la ruta Caddy si no existe. No crea usuarios ni directorios.
                </small>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function importConfirm(e, form, domain) {
    e.preventDefault();
    SwalDark.fire({
        title: 'Importar ' + domain + '?',
        html: 'Se registrara este hosting en el panel y se creara la ruta Caddy si no existe.<br><small class="text-muted">No se modificaran archivos del sitio.</small>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-box-arrow-in-down me-1"></i> Importar',
        confirmButtonColor: '#0ea5e9',
        cancelButtonText: 'Cancelar'
    }).then(function(result) {
        if (result.isConfirmed) form.submit();
    });
    return false;
}
</script>
