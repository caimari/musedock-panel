<?php use MuseDockPanel\View; ?>

<div class="d-flex justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Articulo no encontrado</h4>
        <div class="text-muted small">No existe una guia de bug con slug <code><?= View::e($slug ?? '') ?></code>.</div>
    </div>
    <a href="/docs/bugs-sections" class="btn btn-outline-light btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Volver a Bugs
    </a>
</div>

<div class="alert alert-warning mb-0">
    Revisa la URL o registra el articulo en <code>DocsController::bugGuides()</code>.
</div>
