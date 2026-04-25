<?php use MuseDockPanel\View; ?>

<style>
    .docs-topic-card {
        transition: border-color .15s, transform .15s, box-shadow .15s;
    }
    .docs-topic-card:hover {
        transform: translateY(-2px);
        border-color: rgba(56, 189, 248, .35);
        box-shadow: 0 10px 24px rgba(2, 6, 23, .35);
    }
    .docs-topic-icon {
        width: 56px;
        height: 56px;
        border-radius: 14px;
        flex-shrink: 0;
        background:
            radial-gradient(circle at 30% 20%, rgba(125, 211, 252, .26), transparent 55%),
            linear-gradient(180deg, rgba(30, 64, 175, .55) 0%, rgba(14, 116, 144, .34) 100%);
        border: 1px solid rgba(56, 189, 248, .42);
        box-shadow:
            inset 0 1px 0 rgba(191, 219, 254, .22),
            inset 0 -10px 18px rgba(2, 132, 199, .12),
            0 10px 24px rgba(2, 6, 23, .42);
        color: #38bdf8;
    }
    .docs-topic-icon i {
        font-size: 1.62rem;
        line-height: 1;
        -webkit-text-stroke: .42px rgba(56, 189, 248, .95);
        text-shadow: 0 0 10px rgba(56, 189, 248, .55), 0 2px 6px rgba(2, 6, 23, .45);
    }
</style>

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

<?php if (!empty($query)): ?>
    <?php if (empty($topics)): ?>
        <div class="alert alert-info">
            No hay resultados para esta busqueda.
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($topics as $topic): ?>
                <div class="col-lg-6 col-xl-4">
                    <a href="<?= View::e($topic['url']) ?>" class="text-decoration-none">
                        <div class="card h-100 docs-topic-card">
                            <div class="card-body">
                                <div class="d-flex gap-3 align-items-start">
                                    <div class="docs-topic-icon d-flex align-items-center justify-content-center">
                                        <i class="bi <?= View::e($topic['icon'] ?? 'bi-journal-text') ?>"></i>
                                    </div>
                                    <div>
                                        <div class="small text-info mb-1"><?= View::e($topic['category'] ?? 'Docs') ?></div>
                                        <h5 class="mb-2"><?= View::e($topic['title']) ?></h5>
                                        <p class="text-muted small mb-0"><?= View::e($topic['description']) ?></p>
                                        <?php if (!empty($topic['search_excerpt'])): ?>
                                            <p class="small mt-2 mb-0" style="color:#cbd5e1;">
                                                <span class="text-info">Coincidencia:</span>
                                                <?= View::e($topic['search_excerpt']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Guias padre</div>
        <div class="card-body">
            <?php if (empty($parentTopics ?? [])): ?>
                <div class="text-muted small">No hay guias padre disponibles.</div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach (($parentTopics ?? []) as $topic): ?>
                        <div class="col-lg-6 col-xl-4">
                            <a href="<?= View::e($topic['url']) ?>" class="text-decoration-none">
                                <div class="card h-100 docs-topic-card">
                                    <div class="card-body">
                                        <div class="d-flex gap-3 align-items-start">
                                        <div class="docs-topic-icon d-flex align-items-center justify-content-center">
                                            <i class="bi <?= View::e($topic['icon'] ?? 'bi-journal-text') ?>"></i>
                                        </div>
                                        <div>
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
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-star-fill me-2 text-warning"></i>Accesos directos especiales</div>
        <div class="card-body">
            <?php if (empty($specialShortcutTopics ?? [])): ?>
                <div class="text-muted small">No hay hijas marcadas como acceso directo especial.</div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach (($specialShortcutTopics ?? []) as $topic): ?>
                        <div class="col-lg-6 col-xl-4">
                            <a href="<?= View::e($topic['url']) ?>" class="text-decoration-none">
                                <div class="card h-100 docs-topic-card">
                                    <div class="card-body">
                                        <div class="d-flex gap-3 align-items-start">
                                            <div class="docs-topic-icon d-flex align-items-center justify-content-center">
                                                <i class="bi <?= View::e($topic['icon'] ?? 'bi-journal-text') ?>"></i>
                                            </div>
                                            <div>
                                                <div class="small text-info mb-1"><?= View::e($topic['category'] ?? 'Docs') ?></div>
                                                <h5 class="mb-2">
                                                    <i class="bi bi-star-fill text-warning me-1"></i><?= View::e($topic['title']) ?>
                                                </h5>
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
        </div>
    </div>

    <div class="card mb-2">
        <div class="card-header"><i class="bi bi-stars me-2"></i>Guias especiales</div>
        <div class="card-body">
            <?php if (empty($specialTopics ?? [])): ?>
                <div class="text-muted small">Todavia no hay guias especiales registradas.</div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach (($specialTopics ?? []) as $topic): ?>
                        <div class="col-lg-6 col-xl-4">
                            <a href="<?= View::e($topic['url']) ?>" class="text-decoration-none">
                                <div class="card h-100 docs-topic-card">
                                    <div class="card-body">
                                        <div class="d-flex gap-3 align-items-start">
                                            <div class="docs-topic-icon d-flex align-items-center justify-content-center">
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
        </div>
    </div>
<?php endif; ?>

<div class="card mt-4">
    <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Como ampliar esta documentacion</div>
    <div class="card-body">
        <p class="text-muted small mb-2">
            La home muestra cards de guias padre. Las hijas no salen todas: solo aparecen en "Accesos directos especiales"
            si se marcan con estrella desde su propia pagina de documentacion (<code>/docs/settings/{slug}</code>).
            Las hijas de Mail se registran en <code>DocsController::mailChildTopics()</code>.
            Para guias especiales, crea vista + ruta <code>/docs/...</code> y registra la card en <code>DocsController::specialTopics()</code>.
            La busqueda indexa metadatos y contenido.
        </p>
    </div>
</div>
