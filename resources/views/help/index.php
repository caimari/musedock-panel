<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Documentacion</h4>
        <div class="text-muted small">Indice interno del panel. Usa la busqueda para encontrar guias y procedimientos.</div>
    </div>
    <a href="/changelog" class="btn btn-outline-light btn-sm">
        <i class="bi bi-clock-history me-1"></i> Changelog
    </a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" action="/docs" class="row g-2 align-items-end">
            <div class="col-lg-10">
                <label class="form-label">Buscar en docs</label>
                <div class="input-group">
                    <span class="input-group-text" style="background:#0f172a;border-color:#334155;color:#94a3b8;">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="search" name="q" class="form-control" value="<?= View::e($query ?? '') ?>" placeholder="mail, relay, DKIM, WireGuard..." autocomplete="off">
                </div>
            </div>
            <div class="col-lg-2 d-grid">
                <button class="btn btn-primary" type="submit">Buscar</button>
            </div>
        </form>
        <?php if (!empty($query)): ?>
            <div class="mt-3 small text-muted">
                Resultados para <code><?= View::e($query) ?></code>.
                <a href="/docs" class="text-info text-decoration-none ms-2">Limpiar busqueda</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($topics)): ?>
    <div class="alert alert-info">
        No hay resultados para esta busqueda.
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($topics as $topic): ?>
            <div class="col-lg-6 col-xl-4">
                <a href="<?= View::e($topic['url']) ?>" class="text-decoration-none">
                    <div class="card h-100" style="transition: border-color .15s, transform .15s;">
                        <div class="card-body">
                            <div class="d-flex gap-3 align-items-start">
                                <div class="rounded d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:rgba(56,189,248,.12);color:#38bdf8;">
                                    <i class="bi <?= View::e($topic['icon'] ?? 'bi-journal-text') ?>"></i>
                                </div>
                                <div>
                                    <div class="small text-info mb-1"><?= View::e($topic['category'] ?? 'Docs') ?></div>
                                    <h5 class="mb-2"><?= View::e($topic['title']) ?></h5>
                                    <p class="text-muted small mb-0"><?= View::e($topic['description']) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card mt-4">
    <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Como ampliar esta documentacion</div>
    <div class="card-body">
        <p class="text-muted small mb-2">
            Para anadir una nueva guia, crea una vista en <code>resources/views/help/</code>, anade una ruta <code>/docs/...</code>
            y registra el tema en <code>DocsController::topics()</code>. Asi aparecera en el indice y en la busqueda.
        </p>
    </div>
</div>
