<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h4 class="mb-1">Mail / Relay</h4><div class="text-muted small">Operacion de Relay Privado: dominios, usuarios SMTP y activacion por DNS.</div></div>
    <div class="d-flex gap-2"><a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a><a href="/mail?tab=relay" class="btn btn-outline-info btn-sm"><i class="bi bi-diagram-3 me-1"></i>Abrir Relay</a></div>
</div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-signpost-split me-2"></i>Flujo recomendado</div><div class="card-body"><ol class="small text-muted mb-0"><li>Autorizar dominio remitente.</li><li>Publicar SPF, DKIM y DMARC.</li><li>Crear usuario SMTP para app/servidor remoto.</li><li>Probar envio por WireGuard o red privada.</li><li>Refrescar checks hasta estado activo.</li></ol></div></div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-check2-square me-2"></i>Que revisar</div><div class="card-body"><ul class="small text-muted mb-0"><li>Host de salida y IP coinciden con DNS publicado.</li><li>Usuario SMTP con password guardada de forma segura.</li><li>TLS/puerto correctos en cliente SMTP.</li><li>Si todo esta OK, la card de activacion puede quedar plegada automaticamente.</li></ul></div></div>

<div class="card"><div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Errores tipicos</div><div class="card-body"><ul class="small text-muted mb-0"><li>DKIM no publicado o selector incorrecto.</li><li>SPF sin IP de salida real.</li><li>Cliente SMTP apuntando a host/puerto equivocado.</li><li>WireGuard sin ruta hacia el relay.</li></ul></div></div>
