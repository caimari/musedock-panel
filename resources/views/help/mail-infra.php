<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h4 class="mb-1">Mail / Infra</h4><div class="text-muted small">Instalacion y cambios estructurales del backend de correo.</div></div>
    <div class="d-flex gap-2"><a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a><a href="/mail?tab=infra&setup=1" class="btn btn-outline-info btn-sm"><i class="bi bi-hdd-network me-1"></i>Abrir Infra</a></div>
</div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-info-circle me-2"></i>Que cambia desde aqui</div><div class="card-body"><p class="small text-muted mb-0">Infra define el modo de correo y reescribe configuracion estructural del servidor (Postfix, Dovecot, OpenDKIM, servicios asociados segun el modo). Si ya esta instalado, la accion correcta es <strong>Actualizar/Reconfigurar</strong>, no instalar desde cero.</p></div></div>

<div class="card mb-4" style="border-color:rgba(56,189,248,.24);">
    <div class="card-header"><i class="bi bi-signpost-2 me-2"></i>Hostname de correo</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Antes de cambiar <code>Hostname de mail</code>, revisa si conviene usar dominio raiz o subdominio <code>mail</code>.
            Ese cambio afecta DNS A, PTR/rDNS, certificados, Postfix, SMTP/IMAP y, en Correo Completo, MX de recepcion.
        </p>
        <a href="/docs/mail/hostname" class="btn btn-outline-info btn-sm">
            <i class="bi bi-journal-text me-1"></i> Ver guia de hostname
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-play-circle me-2"></i>Pasos</div><div class="card-body"><ol class="small text-muted mb-0"><li>Seleccionar donde instalar (local o nodo remoto).</li><li>Elegir modo de correo correcto para el caso real.</li><li>Definir hostname de mail y tipo de certificado.</li><li>Aplicar y revisar salida de instalacion/reconfiguracion.</li></ol></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-check2-square me-2"></i>Verificaciones</div><div class="card-body"><ul class="small text-muted mb-0"><li>Hostname configurado coincide con DNS A/PTR esperados.</li><li>Servicios del modo elegido activos.</li><li>No quedan parametros antiguos mezclados de otro modo.</li><li>Webmail/Relay se apoyan en esta base sin desincronizacion.</li></ul></div></div></div>
</div>
