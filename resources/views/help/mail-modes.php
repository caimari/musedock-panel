<?php use MuseDockPanel\View; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Modos de correo</h4>
        <div class="text-muted small">Guia rapida para elegir Satellite, Relay Privado, Correo Completo o SMTP Externo.</div>
    </div>
    <a href="/mail?tab=general#mail-setup-card" class="btn btn-outline-info">
        <i class="bi bi-arrow-left me-1"></i> Volver al instalador
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-send me-2 text-info"></i>Solo Envio (Satellite)</div>
            <div class="card-body">
                <p class="text-muted">
                    Usa este modo cuando una app local solo necesita enviar correo: avisos, facturas, recuperacion de password o formularios.
                    Instala Postfix + OpenDKIM, firma DKIM y no recibe correo publico.
                </p>
                <ul class="text-muted small mb-0">
                    <li>La app de la misma maquina puede enviar por <code>localhost:25</code>.</li>
                    <li>No crea buzones, no usa IMAP y no necesita puerto 993.</li>
                    <li>Puede migrar despues a Correo Completo anadiendo Dovecot y Rspamd.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-hdd-network me-2 text-info"></i>Relay Privado (WireGuard)</div>
            <div class="card-body">
                <p class="text-muted">
                    Usa este modo si otros servidores deben enviar a traves de esta maquina. Es un relay SMTP privado que escucha por WireGuard
                    y exige usuario/password para maquinas remotas.
                </p>
                <ul class="text-muted small mb-0">
                    <li>El SaaS local puede seguir usando <code>localhost</code> sin credenciales.</li>
                    <li>Mortadelo u otros nodos usan <code>MAIL_HOST=10.10.70.x</code>, puerto <code>587</code>, usuario y password.</li>
                    <li>Los usuarios SMTP se crean en Mail &rarr; Relay &rarr; Usuarios.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-envelope-check me-2 text-success"></i>Correo Completo</div>
            <div class="card-body">
                <p class="text-muted">
                    Es el modo para hosting de correo real: buzones, aliases, IMAP, recepcion SMTP, webmail, Sieve y antispam.
                    Instala Postfix + Dovecot + OpenDKIM + Rspamd.
                </p>
                <ul class="text-muted small mb-0">
                    <li>Requiere MX apuntando al servidor.</li>
                    <li>Requiere PTR/rDNS correcto para buena reputacion.</li>
                    <li>Necesita puertos 25, 587 y 993 abiertos.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-cloud-arrow-up me-2 text-warning"></i>SMTP Externo</div>
            <div class="card-body">
                <p class="text-muted">
                    Usa un proveedor externo como Sweego, SES, Mailgun, Brevo u otro. El panel guarda credenciales y configura envio,
                    pero no instala servidor de correo local.
                </p>
                <ul class="text-muted small mb-0">
                    <li>Es la opcion mas simple si ya tienes proveedor de envio.</li>
                    <li>No crea DKIM local ni escucha puertos SMTP/IMAP.</li>
                    <li>La reputacion depende del proveedor elegido.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header"><i class="bi bi-signpost-split me-2"></i>Migracion gradual con Sweego/Cloudflare</div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-lg-6">
                <h6>DKIM puede convivir</h6>
                <p class="text-muted small">
                    DKIM funciona por selector. Puedes tener <code>selector1._domainkey.muserelay.com</code> para Sweego y
                    <code>default._domainkey.muserelay.com</code> para OpenDKIM de MuseDock. Cada email indica que selector uso.
                </p>
            </div>
            <div class="col-lg-6">
                <h6>SPF debe incluir todos los emisores</h6>
                <p class="text-muted small mb-2">
                    Mientras convivan tu servidor y Cloudflare/Sweego, autoriza ambos:
                </p>
                <pre class="small p-3 rounded mb-0" style="background:#0f172a;color:#e2e8f0;">v=spf1 ip4:TU_IP_SERVIDOR include:_spf.mx.cloudflare.net ~all</pre>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Ejemplo WireGuard para otro servidor</div>
    <div class="card-body">
        <p class="text-muted small">
            Si instalas Relay Privado en muserelay y mortadelo debe enviar por ahi, configura las apps de mortadelo con la IP privada del relay:
        </p>
        <pre class="small p-3 rounded mb-0" style="background:#0f172a;color:#e2e8f0;">MAIL_HOST=10.10.70.X
MAIL_PORT=587
MAIL_USERNAME=mortadelo-relay
MAIL_PASSWORD=la_password_generada
MAIL_ENCRYPTION=tls</pre>
    </div>
</div>
