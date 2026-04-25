<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Mail / Webmail: configuracion operativa</h4>
        <div class="text-muted small">Guia hija. Que hace cada campo, como configurarlo, y que validar antes/despues.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/mail-sections" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver al mapa Mail
        </a>
        <a href="/mail?tab=webmail#webmail" class="btn btn-outline-info btn-sm">
            <i class="bi bi-envelope-at me-1"></i> Abrir Webmail
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color: rgba(251,191,36,.4);">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <div class="small text-warning mb-1"><i class="bi bi-hourglass-split me-1"></i>Pendiente</div>
            <div class="fw-semibold">Proveedor configurable · Roundcube disponible</div>
        </div>
        <div class="small text-muted">
            Fase 1 instala Roundcube (IMAP/SMTP). Fase 2 integra hostnames de cliente. Fase 3 activa Sieve/ManageSieve.
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-layers me-2"></i>Fases</div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge text-bg-success">1 · Roundcube</span>
            <span class="badge text-bg-secondary">2 · Portal cliente</span>
            <span class="badge text-bg-secondary">3 · Sieve/filtros</span>
        </div>
        <ul class="small text-muted mb-0">
            <li><strong>Fase 1:</strong> instala paquetes, descarga Roundcube y crea ruta Caddy para el hostname webmail.</li>
            <li><strong>Fase 2:</strong> anade hostnames por cliente (<code>webmail.cliente.com</code>) apuntando al mismo Roundcube.</li>
            <li><strong>Fase 3:</strong> activa Dovecot Sieve + ManageSieve para filtros, reenvios y autoresponder.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-lock me-2"></i>Configuracion actual y bloqueo por candado</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Cuando Webmail ya esta configurado, la edicion queda bloqueada por defecto. Pulsa el candado para desbloquear cambios.
            Los valores se precargan desde la configuracion actual de correo cuando existe backend local/remoto.
        </p>
        <p class="small text-muted mb-0">
            Cambiar aqui <strong>solo</strong> actualiza la configuracion de Webmail
            (<code>mail_webmail_*</code>: Roundcube/Caddy). <strong>No</strong> reescribe Postfix, Dovecot ni OpenDKIM.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-ui-checks-grid me-2"></i>Que hace cada campo</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="min-width:180px;">Campo</th>
                        <th>Que configura</th>
                        <th style="min-width:240px;">Que verificar</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Proveedor</strong><br><code>Roundcube</code></td>
                        <td class="small text-muted">Motor de webmail que se despliega y mantiene.</td>
                        <td class="small text-muted">Proveedor soportado + paquetes instalados correctamente.</td>
                    </tr>
                    <tr>
                        <td><strong>Hostname webmail</strong><br><code>webmail.tudominio.com</code></td>
                        <td class="small text-muted">Dominio publico del frontend de Roundcube en Caddy.</td>
                        <td class="small text-muted">DNS A/AAAA apuntando al nodo correcto y certificado TLS valido.</td>
                    </tr>
                    <tr>
                        <td><strong>Servidor IMAP</strong></td>
                        <td class="small text-muted">Host al que Roundcube conecta para login y lectura de buzon.</td>
                        <td class="small text-muted">Resuelve por DNS y acepta IMAP/TLS desde el nodo webmail.</td>
                    </tr>
                    <tr>
                        <td><strong>Servidor SMTP</strong></td>
                        <td class="small text-muted">Host al que Roundcube conecta para envio SMTP autenticado.</td>
                        <td class="small text-muted">Resuelve por DNS y acepta SMTP Submission (587/TLS).</td>
                    </tr>
                    <tr>
                        <td><strong>Password admin</strong></td>
                        <td class="small text-muted">Credencial de confirmacion para acciones sensibles de instalacion/reconfig.</td>
                        <td class="small text-muted">Password valida del panel antes de ejecutar cambios de sistema.</td>
                    </tr>
                    <tr>
                        <td><strong>Webmail por dominio de cliente</strong></td>
                        <td class="small text-muted">Hostnames adicionales que publican el mismo Roundcube.</td>
                        <td class="small text-muted">Cada hostname con DNS correcto y certificado emitido.</td>
                    </tr>
                    <tr>
                        <td><strong>Filtros, reenvios y vacaciones</strong></td>
                        <td class="small text-muted">Activa Sieve/ManageSieve para gestionar filtros/autoresponder desde Roundcube.</td>
                        <td class="small text-muted">Dovecot Sieve activo y puerto ManageSieve disponible.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-play-circle me-2"></i>Como configurarlo (paso a paso)</div>
            <div class="card-body">
                <ol class="small text-muted mb-0">
                    <li>Define el modo de correo en <a href="/mail?tab=infra&setup=1" class="text-info">Mail &gt; Infra</a> (relay/completo/etc.).</li>
                    <li>Abre <a href="/mail?tab=webmail#webmail" class="text-info">Mail &gt; Webmail</a> y valida proveedor + hostname.</li>
                    <li>Comprueba que <code>Hostname webmail</code> tiene DNS correcto hacia este servidor/nodo.</li>
                    <li>Revisa IMAP/SMTP. Si estan bloqueados por el modo actual, mantenlos alineados con Infra.</li>
                    <li>Pulsa <strong>Instalar / reconfigurar Roundcube</strong> con password admin.</li>
                    <li>Prueba login real en la URL webmail y prueba envio/recepcion.</li>
                    <li>Si usaras filtros, activa <strong>Sieve/ManageSieve</strong> y valida reglas desde Roundcube.</li>
                </ol>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-search me-2"></i>Que verificar siempre</div>
            <div class="card-body">
                <ul class="small text-muted mb-0">
                    <li>La URL webmail abre por HTTPS sin error de certificado.</li>
                    <li>Login IMAP correcto con un buzon real.</li>
                    <li>Envio SMTP correcto (prueba a dominio externo).</li>
                    <li>Si hay aliases/filtros, comprobar que Sieve aplica reglas.</li>
                    <li>En <a href="/mail?tab=deliverability" class="text-info">Entregabilidad</a>, SPF/DKIM/DMARC del dominio remitente en estado OK o revisado.</li>
                    <li>Si se usa nodo remoto, confirmar que DNS apunta al nodo que publica Roundcube.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Impacto de cambios y riesgos</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Cambiar <strong>Hostname webmail</strong> exige actualizar DNS y certificado; si no, webmail queda inaccesible por ese host.</li>
            <li>Cambiar <strong>IMAP/SMTP</strong> aqui no toca Postfix/Dovecot del servidor; solo cambia a que host conecta Roundcube.</li>
            <li>Si IMAP/SMTP se ponen mal, se rompe login/envio en webmail, pero el servidor de correo puede seguir funcionando.</li>
            <li>Para cambios estructurales del backend de correo, usar <a href="/mail?tab=infra&setup=1" class="text-info">Infra &gt; Configurar servidor de mail</a>.</li>
        </ul>
    </div>
</div>
