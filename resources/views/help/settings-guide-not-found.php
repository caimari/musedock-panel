<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Guia no encontrada</h4>
        <div class="text-muted small">No existe una guia para este slug de settings.</div>
    </div>
    <a href="/docs/settings-sections" class="btn btn-outline-light btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Volver al mapa
    </a>
</div>

<div class="alert alert-warning">
    No se encontro documentacion para <code><?= View::e($slug ?? '') ?></code>.
</div>
