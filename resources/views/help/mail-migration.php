<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h4 class="mb-1">Mail / Migracion</h4><div class="text-muted small">Guia base para migrar entre modos de correo o entre nodos.</div></div>
    <div class="d-flex gap-2"><a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a><a href="/mail?tab=migration" class="btn btn-outline-info btn-sm"><i class="bi bi-arrow-left-right me-1"></i>Abrir Migracion</a></div>
</div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-list-check me-2"></i>Checklist previa</div><div class="card-body"><ul class="small text-muted mb-0"><li>Backup de configuracion y datos relevantes.</li><li>Inventario de dominios y usuarios SMTP activos.</li><li>Ventana de cambio definida.</li><li>Plan de rollback antes de aplicar.</li></ul></div></div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-play-circle me-2"></i>Secuencia recomendada</div><div class="card-body"><ol class="small text-muted mb-0"><li>Cambiar modo o nodo objetivo en <a href="/mail?tab=infra&setup=1" class="text-info">Infra</a>.</li><li>Reaplicar/reconfigurar componentes mail.</li><li>Actualizar DNS necesarios y validar entregabilidad.</li><li>Probar envio/recepcion con cuentas de prueba.</li></ol></div></div>

<div class="card"><div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Riesgos</div><div class="card-body"><p class="small text-muted mb-0">Cambiar hostname, modo o nodo sin alinear DNS y servicios puede cortar envio/recepcion temporalmente. Siempre verificar estado final en Deliverability y en la cola.</p></div></div>
