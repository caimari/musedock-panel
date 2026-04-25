<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h4 class="mb-1">Mail / Cola</h4><div class="text-muted small">Gestion de cola real de Postfix y del historico de relay.</div></div>
    <div class="d-flex gap-2"><a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a><a href="/mail?tab=queue" class="btn btn-outline-info btn-sm"><i class="bi bi-inboxes me-1"></i>Abrir Cola</a></div>
</div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-info-circle me-2"></i>Que incluye</div><div class="card-body"><ul class="small text-muted mb-0"><li>Reintento de cola deferred.</li><li>Borrado de deferred o cola completa (accion delicada).</li><li>Historico reciente del relay con paginacion y limite por pagina.</li><li>Borrado de historico con confirmacion.</li></ul></div></div>

<div class="row g-4">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-shield-check me-2"></i>Seguridad operativa</div><div class="card-body"><ul class="small text-muted mb-0"><li>Usar modales SweetAlert para todas las confirmaciones.</li><li>Acciones destructivas deben pedir password admin.</li><li>No borrar cola sin revisar primero causa del bloqueo.</li></ul></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-check2-square me-2"></i>Que verificar</div><div class="card-body"><ul class="small text-muted mb-0"><li>Tras reintento, baja el numero de deferred.</li><li>Historico se limpia/actualiza segun accion.</li><li>Selector de registros por pagina (25, 100, 200, 500, 1000) aplicado.</li></ul></div></div></div>
</div>
