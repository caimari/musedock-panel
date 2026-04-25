<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h4 class="mb-1">Mail / Entregabilidad</h4><div class="text-muted small">Comprobacion DNS en tiempo real y sincronizacion de estado tecnico.</div></div>
    <div class="d-flex gap-2"><a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a><a href="/mail?tab=deliverability" class="btn btn-outline-info btn-sm"><i class="bi bi-clipboard me-1"></i>Abrir Entregabilidad</a></div>
</div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-search me-2"></i>Que analiza</div><div class="card-body"><ul class="small text-muted mb-0"><li>SPF del dominio remitente.</li><li>DKIM del selector esperado.</li><li>DMARC del dominio.</li><li>A record del hostname de salida.</li><li>PTR/rDNS de la IP de salida.</li><li>Estado en listas negras comunes.</li></ul></div></div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-arrow-repeat me-2"></i>Actualizacion recomendada</div><div class="card-body"><p class="small text-muted mb-2">Los checks deben ejecutarse al pulsar boton de comprobacion/refresh, no en cada carga de la pagina. Tras comprobar DNS, se actualiza el estado mostrado y la BD operacional.</p><p class="small text-muted mb-0">Evitar botones redundantes: idealmente una accion que refresque DNS y persista estado de una vez.</p></div></div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-table me-2"></i>Como copiar en Cloudflare (campos exactos)</div><div class="card-body"><p class="small text-muted mb-2">La tabla de "Registros recomendados" de la pestaña Entregabilidad ya viene en formato Cloudflare:</p><ul class="small text-muted mb-0"><li><strong>Tipo</strong> -> campo Type.</li><li><strong>Nombre (Host)</strong> -> campo Name (ej. <code>@</code>, <code>_dmarc</code>, <code>default._domainkey</code>).</li><li><strong>Contenido (Value)</strong> -> campo Content.</li><li><strong>Prioridad</strong> -> solo para MX.</li><li><strong>Proxy</strong> -> para A/MX/TXT en correo usa <code>Solo DNS</code>.</li><li><strong>PTR</strong> no se crea en Cloudflare DNS; va en el proveedor de la IP publica (rDNS).</li></ul></div></div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Si ya tienes otros relays/proveedores DNS-mail</div><div class="card-body"><ul class="small text-muted mb-0"><li><strong>DKIM:</strong> puedes tener varios selectores a la vez (ej. proveedor + <code>default</code> de MuseDock). No borres uno para poner el otro.</li><li><strong>SPF:</strong> debe quedar en un solo TXT <code>v=spf1 ...</code>. Si ya existe, amplialo combinando emisores (IP propia + <code>include:</code> del proveedor).</li><li><strong>DMARC:</strong> debe existir uno solo. Si ya esta creado, editalo; no dupliques registros DMARC.</li><li><strong>MX:</strong> si recibes correo con otro proveedor, conserva sus MX; no cambies MX salvo que migres recepcion al servidor mail propio.</li></ul></div></div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-journal-richtext me-2"></i>Caso de referencia anonimo: unificar proveedores</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Ejemplo profesional de topologia hibrida (sin datos reales):
            <strong>recepcion</strong> en proveedor DNS-mail, <strong>envio principal</strong> en servidor propio,
            y <strong>envio alternativo</strong> en relay externo.
        </p>
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);">
                    <div class="small fw-semibold mb-2">Quien controla cada capa</div>
                    <ul class="small text-muted mb-0">
                        <li><strong>DNS Provider:</strong> SPF, DKIM publico, DMARC, MX, A/CNAME.</li>
                        <li><strong>Servidor SMTP propio:</strong> Postfix/Exim, DKIM privada, hostname de salida.</li>
                        <li><strong>Relay externo:</strong> envio alternativo con su selector DKIM propio.</li>
                        <li><strong>Proveedor IP/VPS:</strong> PTR/rDNS de la IP publica.</li>
                    </ul>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);">
                    <div class="small fw-semibold mb-2">Reglas de coexistencia</div>
                    <ul class="small text-muted mb-0">
                        <li>SPF: un solo TXT, combinando todos los emisores.</li>
                        <li>DKIM: varios selectores son validos (uno por servicio).</li>
                        <li>DMARC: uno solo para el dominio.</li>
                        <li>MX define quien recibe; no tocar si no migras recepcion.</li>
                        <li>PTR nunca en DNS publico: solo en proveedor de IP.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-table me-2"></i>Tabla ejemplo (anonima) para Cloudflare</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Nombre (Host)</th>
                        <th>Contenido (Value)</th>
                        <th>Prioridad</th>
                        <th>Proxy</th>
                        <th>TTL</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>A</code></td>
                        <td><code>mail</code></td>
                        <td><code>203.0.113.25</code></td>
                        <td><code>-</code></td>
                        <td>Solo DNS</td>
                        <td>Auto</td>
                    </tr>
                    <tr>
                        <td><code>TXT</code></td>
                        <td><code>@</code></td>
                        <td><code>v=spf1 ip4:203.0.113.25 include:_spf.dnsmail.example include:relay.example ~all</code></td>
                        <td><code>-</code></td>
                        <td>Solo DNS</td>
                        <td>Auto</td>
                    </tr>
                    <tr>
                        <td><code>TXT</code></td>
                        <td><code>default._domainkey</code></td>
                        <td><code>v=DKIM1; k=rsa; p=...</code></td>
                        <td><code>-</code></td>
                        <td>Solo DNS</td>
                        <td>Auto</td>
                    </tr>
                    <tr>
                        <td><code>CNAME</code></td>
                        <td><code>selector1._domainkey</code></td>
                        <td><code>selector1._domainkey.relay.example</code></td>
                        <td><code>-</code></td>
                        <td>Solo DNS</td>
                        <td>Auto</td>
                    </tr>
                    <tr>
                        <td><code>TXT</code></td>
                        <td><code>_dmarc</code></td>
                        <td><code>v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com</code></td>
                        <td><code>-</code></td>
                        <td>Solo DNS</td>
                        <td>Auto</td>
                    </tr>
                    <tr>
                        <td><code>MX</code></td>
                        <td><code>@</code></td>
                        <td><code>route1.mx.dnsmail.example</code></td>
                        <td><code>10</code></td>
                        <td>Solo DNS</td>
                        <td>Auto</td>
                    </tr>
                    <tr>
                        <td><code>MX</code></td>
                        <td><code>@</code></td>
                        <td><code>route2.mx.dnsmail.example</code></td>
                        <td><code>20</code></td>
                        <td>Solo DNS</td>
                        <td>Auto</td>
                    </tr>
                    <tr>
                        <td><code>PTR</code></td>
                        <td><code>203.0.113.25</code></td>
                        <td><code>mail.example.com</code></td>
                        <td><code>-</code></td>
                        <td>N/A</td>
                        <td>N/A</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="p-3 small text-muted">
            <strong>Importante:</strong> la fila PTR es de referencia documental.
            Se configura en el panel del proveedor IP/VPS, no en Cloudflare DNS.
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-envelope-check me-2"></i>Cuentas sugeridas para operacion</div>
    <div class="card-body">
        <p class="small text-muted mb-2">Como baseline operativo, se recomienda crear/gestionar estas direcciones (o aliases) en cada dominio:</p>
        <ul class="small text-muted mb-2">
            <li><code>dmarc@tu-dominio.com</code> para recibir reportes DMARC (RUA).</li>
            <li><code>postgresql@tu-dominio.com</code> para alertas tecnicas de BD/servicios.</li>
            <li><code>root@tu-dominio.com</code> para notificaciones de sistema/seguridad.</li>
        </ul>
        <p class="small text-muted mb-0">
            Si no quieres buzones reales para todas, usa aliases internos hacia un mailbox de operaciones compartido.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-speedometer2 me-2"></i>Test de reputacion recomendado</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Herramienta principal: <a href="https://mail-tester.com/" target="_blank" rel="noopener noreferrer" class="text-info">Mail-Tester</a>.
            Flujo: genera direccion temporal, envia desde el sistema, y revisa score/diagnostico.
        </p>
        <ul class="small text-muted mb-2">
            <li><strong>Test 1:</strong> envio desde servidor propio.</li>
            <li><strong>Test 2:</strong> envio desde relay/proveedor externo.</li>
            <li>Comparar resultados para aislar fallos por emisor.</li>
        </ul>
        <p class="small text-muted mb-2">
            En el panel, el formulario de <code>Test de envio</code> permite elegir origen de remitente
            (<code>mail_from_address</code>, email admin o recomendado) y fuerza envelope sender para validar SPF/DMARC de forma mas realista.
        </p>
        <p class="small text-muted mb-0">
            Criterio minimo de calidad: <strong>SPF PASS</strong>, <strong>DKIM PASS</strong>, <strong>DMARC PASS</strong>,
            <strong>Reverse DNS OK</strong> y sin listas negras activas.
        </p>
    </div>
</div>

<div class="card"><div class="card-header"><i class="bi bi-check2-square me-2"></i>Criterio de OK</div><div class="card-body"><ul class="small text-muted mb-0"><li>SPF, DKIM y DMARC en estado correcto.</li><li>A y PTR alineados con hostname/IP de salida.</li><li>Sin blacklist activa.</li><li>Estado BD del dominio pasa a <code>active</code> cuando aplica.</li></ul></div></div>
