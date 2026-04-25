<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Mail / Hostname de correo</h4>
        <div class="text-muted small">Dominio raiz vs subdominio <code>mail</code> para SMTP/IMAP, PTR/rDNS, MX, TLS y Cloudflare.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a>
        <a href="/mail?tab=infra&amp;setup=1" class="btn btn-outline-info btn-sm"><i class="bi bi-hdd-network me-1"></i>Abrir Infra</a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(56,189,248,.28);">
    <div class="card-header"><i class="bi bi-lightning-charge me-2"></i>Respuesta corta</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Si el hostname de correo fuese <code>example.com</code> en vez de <code>mail.example.com</code>, puede funcionar en
            <strong>Solo Envio</strong>, <strong>Relay Privado</strong> y <strong>Correo Completo</strong>, pero solo si DNS, PTR,
            certificado y configuracion de Postfix quedan alineados.
        </p>
        <pre class="small mb-0 p-3 rounded" style="background:#0f172a;color:#e2e8f0;white-space:pre-wrap;">Hostname mail: example.com
SMTP/IMAP: example.com
PTR/rDNS: 203.0.113.10 -> example.com</pre>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-check2-square me-2"></i>Condiciones si usas dominio raiz</div>
            <div class="card-body">
                <ul class="small text-muted mb-0">
                    <li>El registro <code>A</code> de <code>example.com</code> debe apuntar a la IP publica del servidor mail.</li>
                    <li>En Cloudflare debe estar en <code>Solo DNS</code>; SMTP/IMAP no funcionan detras de proxy HTTP/nube naranja.</li>
                    <li>El <code>PTR/rDNS</code> de la IP debe apuntar a <code>example.com</code> y se configura en el proveedor de IP/VPS.</li>
                    <li>Postfix debe anunciar <code>myhostname = example.com</code>.</li>
                    <li>El certificado TLS debe cubrir <code>example.com</code>.</li>
                    <li>Los clientes SMTP/IMAP deben usar el mismo hostname publicado.</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-shield-check me-2"></i>Por que se recomienda mail.example.com</div>
            <div class="card-body">
                <ul class="small text-muted mb-0">
                    <li>Separa la web principal (<code>example.com</code>) del servicio mail (<code>mail.example.com</code>).</li>
                    <li>Permite tener la web detras de Cloudflare proxy y el mail en <code>Solo DNS</code>.</li>
                    <li>Deja el PTR claro: <code>203.0.113.10 -> mail.example.com</code>.</li>
                    <li>Reduce riesgo de romper web, portal, Caddy, certificados o rutas del dominio principal.</li>
                    <li>Es el patron mas habitual para servidores de correo completos.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-send-check me-2"></i>Solo Envio / Relay Privado</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Para enviar correo, el dominio raiz como hostname es viable. El <code>MX</code> no es imprescindible para enviar; solo define quien recibe correo.
        </p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Tipo</th><th>Nombre</th><th>Contenido</th><th>Proxy</th><th>Donde</th></tr></thead>
                <tbody>
                    <tr><td><code>A</code></td><td><code>@</code></td><td><code>203.0.113.10</code></td><td>Solo DNS</td><td>DNS provider</td></tr>
                    <tr><td><code>TXT</code></td><td><code>@</code></td><td><code>v=spf1 ip4:203.0.113.10 include:proveedor.example ~all</code></td><td>Solo DNS</td><td>DNS provider</td></tr>
                    <tr><td><code>TXT</code></td><td><code>default._domainkey</code></td><td><code>v=DKIM1; k=rsa; p=...</code></td><td>Solo DNS</td><td>DNS provider</td></tr>
                    <tr><td><code>TXT</code></td><td><code>_dmarc</code></td><td><code>v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com</code></td><td>Solo DNS</td><td>DNS provider</td></tr>
                    <tr><td><code>PTR</code></td><td><code>203.0.113.10</code></td><td><code>example.com</code></td><td>N/A</td><td>Proveedor IP/VPS</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(251,191,36,.35);">
    <div class="card-header"><i class="bi bi-envelope-check me-2 text-warning"></i>Correo Completo y recepcion</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            En <strong>Correo Completo</strong> tambien puede funcionar con dominio raiz, pero si quieres recibir correo en ese servidor el
            <code>MX</code> debe apuntar al servidor propio.
        </p>
        <pre class="small mb-3 p-3 rounded" style="background:#0f172a;color:#e2e8f0;white-space:pre-wrap;">MX @ -> example.com</pre>
        <div class="small text-muted">
            Si ya recibes correo con Cloudflare Email Routing u otro proveedor, hay conflicto conceptual:
            Cloudflare recibe cuando los <code>MX</code> apuntan a Cloudflare; Correo Completo recibe cuando los <code>MX</code>
            apuntan al servidor propio. Para el mismo dominio no pueden ser ambos receptores principales a la vez salvo disenos avanzados.
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-sliders me-2"></i>Como cambiarlo desde el panel</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <li>Entrar en <a href="/mail?tab=infra&amp;setup=1" class="text-info">Mail &gt; Infra</a>.</li>
            <li>Pulsar configurar/actualizar modo de correo.</li>
            <li>Mantener el modo actual si no quieres migrar de arquitectura: <code>Relay Privado</code>, <code>Solo Envio</code> o <code>Correo Completo</code>.</li>
            <li>Cambiar <code>Hostname de mail</code> de <code>mail.example.com</code> a <code>example.com</code>.</li>
            <li>Ejecutar actualizar/reconfigurar.</li>
            <li>Actualizar DNS y PTR/rDNS para que coincidan con el nuevo hostname.</li>
            <li>Ir a <a href="/mail?tab=deliverability" class="text-info">Mail &gt; Entregabilidad</a> y pulsar <strong>Comprobar DNS ahora</strong>.</li>
            <li>Hacer prueba externa en <a href="https://mail-tester.com/" target="_blank" rel="noopener noreferrer" class="text-info">Mail-Tester</a>.</li>
        </ol>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>Recomendacion operativa</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Para produccion estable, salvo necesidad concreta, conviene separar web y correo:
        </p>
        <pre class="small mb-3 p-3 rounded" style="background:#0f172a;color:#e2e8f0;white-space:pre-wrap;">Web:  example.com
Mail: mail.example.com
PTR:  mail.example.com
MX:   mail.example.com o proveedor externo, segun quien reciba</pre>
        <p class="small text-muted mb-0">
            Esta separacion reduce acoplamiento entre Caddy/web, Cloudflare proxy, certificados, SMTP/IMAP y reputacion de correo.
        </p>
    </div>
</div>
