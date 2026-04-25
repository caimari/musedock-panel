<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Guia de Mail no encontrada</h4>
        <div class="text-muted small">La seccion solicitada no existe o aun no esta documentada.</div>
    </div>
    <a href="/docs/mail-sections" class="btn btn-outline-light btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Volver al mapa Mail
    </a>
</div>

<div class="card">
    <div class="card-body">
        <p class="text-muted small mb-0">Slug solicitado: <code><?= View::e($slug ?? '') ?></code></p>
    </div>
</div>
