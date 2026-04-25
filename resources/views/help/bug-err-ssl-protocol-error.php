<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">ERR_SSL_PROTOCOL_ERROR por IP/dominio en el panel</h4>
        <div class="text-muted small">Caso real: acceso a <code>https://IP:8444</code> sin opcion "Avanzado" en Chrome.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/bugs-sections" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver a Bugs
        </a>
        <a href="/docs/install-recovery" class="btn btn-outline-info btn-sm">
            <i class="bi bi-tools me-1"></i> Instalacion
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(248,113,113,.38);">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Sintoma</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            El navegador muestra:
        </p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">Este sitio no puede proporcionar una conexion segura
IP_DEL_SERVIDOR envio una respuesta no valida.
ERR_SSL_PROTOCOL_ERROR</pre>
        <ul class="small text-muted mb-0">
            <li>No aparece la opcion normal de "Avanzado".</li>
            <li>No es lo mismo que un certificado autofirmado o no confiable.</li>
            <li>Con certificado autofirmado el navegador avisa de confianza, pero el protocolo TLS funciona.</li>
            <li>Con <code>ERR_SSL_PROTOCOL_ERROR</code>, el navegador intenta TLS y el servidor responde algo que no es TLS valido.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Arquitectura correcta del panel</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Componente</th>
                        <th>Debe escuchar en</th>
                        <th>Protocolo</th>
                        <th>Funcion</th>
                    </tr>
                </thead>
                <tbody class="small text-muted">
                    <tr>
                        <td><code>caddy</code></td>
                        <td><code>0.0.0.0:8444</code></td>
                        <td>HTTPS / TLS</td>
                        <td>Entrada publica del panel.</td>
                    </tr>
                    <tr>
                        <td><code>musedock-panel</code> PHP</td>
                        <td><code>127.0.0.1:8445</code></td>
                        <td>HTTP interno</td>
                        <td>Aplicacion PHP detras del proxy.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="small text-muted mt-3 mb-0">
            Si PHP queda escuchando directamente en <code>0.0.0.0:8444</code>, el navegador abre HTTPS contra un servicio HTTP plano.
            Eso produce <code>ERR_SSL_PROTOCOL_ERROR</code> o <code>wrong version number</code>.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-activity me-2"></i>Diagnostico rapido</div>
    <div class="card-body">
        <div class="small text-muted mb-2">Ver quien escucha en los puertos:</div>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo ss -tlnp | grep -E ':8444|:8445'</pre>
        <div class="small text-muted mb-2">Resultado esperado:</div>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">*:8444            users:(("caddy",...))
127.0.0.1:8445    users:(("php",...))</pre>
        <div class="small text-muted mb-2">Probar HTTPS local usando la IP como host:</div>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">curl -vk --resolve IP_DEL_SERVIDOR:8444:127.0.0.1 https://IP_DEL_SERVIDOR:8444/setup -o /dev/null</pre>
        <ul class="small text-muted mb-0">
            <li><code>HTTP/2 302</code> o <code>HTTP/1.1 302</code>: TLS funciona; el navegador deberia mostrar aviso normal de certificado si no confia en la CA interna.</li>
            <li><code>wrong version number</code>: el puerto responde HTTP plano o Caddy cargo una config runtime sin TLS.</li>
            <li><code>tlsv1 alert internal error</code>: Caddy escucha TLS, pero no pudo seleccionar/generar certificado para ese host/IP.</li>
        </ul>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(56,189,248,.35);">
    <div class="card-header"><i class="bi bi-search me-2 text-info"></i>Que paso en el bug real</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <li>El instalador tenia una ruta de emergencia: si Caddy fallaba, cambiaba el servicio PHP para escuchar directamente en <code>0.0.0.0:8444</code>.</li>
            <li>Eso hacia que <code>https://IP:8444</code> hablara contra HTTP plano. Resultado: <code>ERR_SSL_PROTOCOL_ERROR</code>.</li>
            <li>Se corrigio para que el puerto publico <code>8444</code> quede reservado a Caddy/TLS y PHP solo escuche en <code>127.0.0.1:8445</code>.</li>
            <li>Despues aparecio otro fallo: Caddy con bloque generico <code>:8444 { tls internal }</code> podia fallar en acceso por IP al seleccionar certificado.</li>
            <li>Se cambio el bloque a hosts explicitos: <code>https://IP:8444</code>, <code>https://127.0.0.1:8444</code> y <code>https://localhost:8444</code>.</li>
            <li>Luego <code>repair-caddy-routes.php</code> y <code>cluster-worker.php</code> volvian a tocar runtime por API y podian degradar la config a HTTP.</li>
            <li>Se anadio guarda: si <code>PANEL_PORT</code> esta gestionado por Caddyfile con <code>tls internal</code>, no se muta runtime del panel por API.</li>
            <li>Finalmente se corrigio el parser de Caddyfile: eliminaba mal el bloque viejo <code>:8444</code> y dejaba una llave <code>}</code> suelta.</li>
        </ol>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-file-earmark-code me-2"></i>Caddyfile correcto para acceso por IP</div>
    <div class="card-body">
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">{
    auto_https disable_redirects
    admin localhost:2019
}

https://IP_DEL_SERVIDOR:8444, https://127.0.0.1:8444, https://localhost:8444 {
    tls internal
    reverse_proxy 127.0.0.1:8445 {
        header_up X-Forwarded-Proto https
        header_up X-Real-Ip {remote_host}
    }
}</pre>
        <p class="small text-muted mb-0">
            Para dominio real, el mismo principio aplica: el dominio debe estar en un bloque HTTPS valido o en una ruta Caddy que tenga politica TLS correcta.
            Si el dominio esta detras de Cloudflare proxy, el certificado publico puede requerir DNS-01 o usar modo especifico del panel.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-wrench-adjustable me-2"></i>Reparacion recomendada</div>
    <div class="card-body">
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">cd /opt/musedock-panel
git pull --ff-only origin main
sudo bash bin/update.sh --auto</pre>
        <div class="small text-muted mb-2">Validar Caddyfile:</div>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl restart caddy</pre>
        <div class="small text-muted mb-2">Confirmar que no hay <code>--resume</code> pisando el Caddyfile:</div>
        <pre class="small p-3 rounded mb-0" style="background:#0f172a;color:#e2e8f0;">sudo systemctl cat caddy | grep -E 'ExecStart|resume' -n</pre>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-shield-check me-2"></i>Prevencion</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>El instalador no debe exponer nunca PHP directo en el puerto publico HTTPS.</li>
            <li>Los health checks no deben considerar correcto HTTP plano en <code>PANEL_PORT</code>.</li>
            <li>Si Caddyfile gestiona el panel, los workers no deben reescribir ese runtime por API.</li>
            <li>Cuando se actualiza Caddyfile, hay que validar antes de reiniciar y guardar backup.</li>
            <li>Los parsers de Caddyfile deben contar llaves anidadas; <code>reverse_proxy { ... }</code> introduce cierres internos.</li>
        </ul>
    </div>
</div>
