<?php use MuseDockPanel\View; ?>

<div class="d-flex justify-content-between align-items-start gap-3 mb-3">
    <h4 class="mb-0">Correo capturado</h4>
    <a href="/mail/web-capture" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i> Volver</a>
</div>
<div class="card">
    <div class="card-body">
        <p class="text-muted small mb-2"><?= View::e($name) ?> · solo se muestra como texto: no se interpreta nada de lo que lleva.</p>
        <pre class="p-3 rounded mb-0" style="background:rgba(0,0,0,.3);white-space:pre-wrap;word-break:break-word;max-height:70vh;overflow:auto;"><?= View::e($raw) ?></pre>
    </div>
</div>
