<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Mail / General</h4>
        <div class="text-muted small">Vista de estado global del modulo de correo y punto de entrada al instalador.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a>
        <a href="/mail?tab=general" class="btn btn-outline-info btn-sm"><i class="bi bi-envelope me-1"></i>Abrir General</a>
    </div>
</div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-info-circle me-2"></i>Que es</div><div class="card-body"><p class="small text-muted mb-0">Aqui ves el estado del sistema de correo, el modo activo y los accesos rapidos para instalar, reconfigurar o revisar estado antes de tocar DNS o usuarios.</p></div></div>

<div class="row g-4">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-play-circle me-2"></i>Pasos recomendados</div><div class="card-body"><ol class="small text-muted mb-0"><li>Validar modo actual (Solo Envio, Relay Privado, Correo Completo, SMTP Externo).</li><li>Si esta pendiente, ir a <a href="/mail?tab=infra&setup=1" class="text-info">Infra</a> para configurar servidor.</li><li>Confirmar host salida e IP mostrada por el panel.</li><li>Despues pasar a <a href="/mail?tab=deliverability" class="text-info">Entregabilidad</a>.</li></ol></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-check2-square me-2"></i>Que verificar</div><div class="card-body"><ul class="small text-muted mb-0"><li>Modo de correo coherente con el uso real.</li><li>Sin alertas criticas de servicios mail.</li><li>Hostname/IP de salida correctos.</li><li>Si hay nodo remoto, confirmar que se muestra el nodo correcto.</li></ul></div></div></div>
</div>
