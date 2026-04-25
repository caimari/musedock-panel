<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h4 class="mb-1">Mail / Entregabilidad</h4><div class="text-muted small">Comprobacion DNS en tiempo real y sincronizacion de estado tecnico.</div></div>
    <div class="d-flex gap-2"><a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a><a href="/mail?tab=deliverability" class="btn btn-outline-info btn-sm"><i class="bi bi-clipboard me-1"></i>Abrir Entregabilidad</a></div>
</div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-search me-2"></i>Que analiza</div><div class="card-body"><ul class="small text-muted mb-0"><li>SPF del dominio remitente.</li><li>DKIM del selector esperado.</li><li>DMARC del dominio.</li><li>A record del hostname de salida.</li><li>PTR/rDNS de la IP de salida.</li><li>Estado en listas negras comunes.</li></ul></div></div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-arrow-repeat me-2"></i>Actualizacion recomendada</div><div class="card-body"><p class="small text-muted mb-2">Los checks deben ejecutarse al pulsar boton de comprobacion/refresh, no en cada carga de la pagina. Tras comprobar DNS, se actualiza el estado mostrado y la BD operacional.</p><p class="small text-muted mb-0">Evitar botones redundantes: idealmente una accion que refresque DNS y persista estado de una vez.</p></div></div>

<div class="card"><div class="card-header"><i class="bi bi-check2-square me-2"></i>Criterio de OK</div><div class="card-body"><ul class="small text-muted mb-0"><li>SPF, DKIM y DMARC en estado correcto.</li><li>A y PTR alineados con hostname/IP de salida.</li><li>Sin blacklist activa.</li><li>Estado BD del dominio pasa a <code>active</code> cuando aplica.</li></ul></div></div>
