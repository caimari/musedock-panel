<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Bugs: incidencias y diagnostico</h4>
        <div class="text-muted small">Articulos de bugs reales del panel: sintoma, causa raiz, diagnostico, fix y prevencion.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm">
            <i class="bi bi-journal-text me-1"></i> Volver a Docs
        </a>
        <a href="/settings/system-health" class="btn btn-outline-info btn-sm">
            <i class="bi bi-heart-pulse me-1"></i> System Health
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(251,191,36,.35);">
    <div class="card-header"><i class="bi bi-bug-fill me-2 text-warning"></i>Como leer esta seccion</div>
    <div class="card-body">
        <p class="small text-muted mb-0">
            Esta zona no sustituye al changelog. El changelog dice que cambio; estos articulos explican incidentes concretos:
            que se veia desde navegador o shell, por que pasaba, que comandos confirman el diagnostico y que se cambio para que no vuelva a ocurrir.
        </p>
    </div>
</div>

<div class="row g-3">
    <?php foreach (($bugGuides ?? []) as $slug => $guide): ?>
        <div class="col-lg-6 col-xl-4">
            <a href="/docs/bugs/<?= View::e($slug) ?>" class="text-decoration-none">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex gap-3 align-items-start">
                            <div class="d-flex align-items-center justify-content-center rounded"
                                 style="width:56px;height:56px;flex-shrink:0;background:linear-gradient(180deg,rgba(127,29,29,.55),rgba(30,64,175,.28));border:1px solid rgba(248,113,113,.38);color:#f87171;">
                                <i class="bi <?= View::e($guide['icon'] ?? 'bi-bug') ?>" style="font-size:1.55rem;"></i>
                            </div>
                            <div>
                                <div class="small text-warning mb-1">Articulo de bug</div>
                                <h5 class="mb-2"><?= View::e($guide['title'] ?? $slug) ?></h5>
                                <p class="text-muted small mb-0"><?= View::e($guide['summary'] ?? '') ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
