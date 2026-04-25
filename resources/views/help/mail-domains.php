<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h4 class="mb-1">Mail / Dominios</h4><div class="text-muted small">Dominios remitentes y su estado de activacion para envio.</div></div>
    <div class="d-flex gap-2"><a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a><a href="/mail?tab=domains" class="btn btn-outline-info btn-sm"><i class="bi bi-globe me-1"></i>Abrir Dominios</a></div>
</div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-info-circle me-2"></i>Que hace esta seccion</div><div class="card-body"><p class="small text-muted mb-0">Gestiona que dominios estan autorizados para enviar correo en el modo actual. Cada dominio tiene su estado tecnico (pending/active) segun DNS y validaciones del sistema.</p></div></div>

<div class="row g-4">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-play-circle me-2"></i>Flujo basico</div><div class="card-body"><ol class="small text-muted mb-0"><li>Anadir dominio remitente.</li><li>Publicar DNS recomendados (SPF, DKIM, DMARC, A/PTR cuando toque).</li><li>Pulsar refresh/check para actualizar estado.</li><li>Confirmar cambio a estado activo.</li></ol></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-check2-square me-2"></i>Validaciones</div><div class="card-body"><ul class="small text-muted mb-0"><li>DKIM del selector esperado publicado.</li><li>SPF incluye IP/host real de salida.</li><li>DMARC presente con politica definida.</li><li>Dominio en estado usable para SMTP.</li></ul></div></div></div>
</div>
