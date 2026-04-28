<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">TLS del panel: dominio, DNS-01, proxy y firewall</h4>
        <div class="text-muted small">Guia para publicar el panel en un dominio/subdominio con certificado valido sin perder el acceso por IP.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver a Docs
        </a>
        <a href="/settings/server" class="btn btn-outline-info btn-sm">
            <i class="bi bi-server me-1"></i> Abrir Server
        </a>
        <a href="/settings/firewall" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-shield-fill me-1"></i> Abrir Firewall
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(56,189,248,.35);">
    <div class="card-header"><i class="bi bi-info-circle me-2 text-info"></i>Objetivo</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            El panel puede abrirse por IP, por dominio o por subdominio. La forma recomendada para produccion es usar un nombre
            como <code>panel.midominio.com</code> en <code>https://panel.midominio.com:8444/</code>, con certificado publico emitido por ACME/Let's Encrypt.
        </p>
        <p class="small text-muted mb-0">
            Esta guia explica que metodo elegir, que pasa si el firewall tiene <code>80/443</code> cerrados, como funciona DNS-01,
            que ocurre si el dominio esta detras de proxy naranja, y como instalar proveedores DNS en Caddy desde el panel.
        </p>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pc-display me-2"></i>Acceso por IP</div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    Ejemplo: <code>https://203.0.113.10:8444/</code>.
                </p>
                <ul class="small text-muted mb-0">
                    <li>Sirve como acceso de rescate o primer acceso tras instalar un nodo.</li>
                    <li>Usa certificado interno/autofirmado; el navegador puede mostrar aviso de confianza.</li>
                    <li>No depende de DNS ni de Let's Encrypt.</li>
                    <li>No debe desaparecer aunque configures un dominio publico para el panel.</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-globe2 me-2"></i>Acceso por dominio</div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    Ejemplo: <code>https://panel.midominio.com:8444/</code>.
                </p>
                <ul class="small text-muted mb-0">
                    <li>Es el modelo comodo para administradores y nodos remotos.</li>
                    <li>Debe resolver por DNS a la IP publica del servidor.</li>
                    <li>Puede usar HTTP-01/TLS-ALPN-01 o DNS-01 para el certificado.</li>
                    <li>El puerto del panel sigue siendo <code>8444</code>; <code>443</code> puede existir solo para validacion/redirect.</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-cloud-fill me-2"></i>Dominio con proxy DNS</div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    Ejemplo: <code>panel.midominio.com</code> detras de proxy/CDN.
                </p>
                <ul class="small text-muted mb-0">
                    <li>HTTP-01 puede fallar si el proxy no reenvia el challenge correctamente.</li>
                    <li>TLS-ALPN-01 puede fallar si el proxy termina TLS antes de llegar al servidor.</li>
                    <li>DNS-01 es el modelo recomendado en este caso.</li>
                    <li>DNS-01 valida por TXT DNS, no por conexion directa al servidor.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Modelos de certificado disponibles</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Modo</th>
                        <th>Necesita 80/443 publicos</th>
                        <th>Necesita API DNS</th>
                        <th>Uso recomendado</th>
                    </tr>
                </thead>
                <tbody class="small text-muted">
                    <tr>
                        <td><code>self_signed</code></td>
                        <td>No</td>
                        <td>No</td>
                        <td>Primer acceso, IP directa, panel privado, laboratorio o rescate.</td>
                    </tr>
                    <tr>
                        <td><code>HTTP-01 / TLS-ALPN-01</code></td>
                        <td>Si</td>
                        <td>No</td>
                        <td>Dominio directo al servidor, sin proxy que intercepte el challenge.</td>
                    </tr>
                    <tr>
                        <td><code>DNS-01</code></td>
                        <td>No</td>
                        <td>Si</td>
                        <td>Firewall estricto, proxy naranja/CDN, dominios donde no quieres abrir 80/443.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.35);">
    <div class="card-header"><i class="bi bi-patch-check me-2 text-success"></i>Que es DNS-01 en cristiano</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Let's Encrypt necesita comprobar que controlas el dominio. En HTTP-01 lo comprueba entrando a tu servidor por red.
            En DNS-01 no entra al servidor: mira un registro TXT temporal en tu DNS publico.
        </p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">_acme-challenge.panel.midominio.com = token_temporal_generado_por_caddy</pre>
        <ol class="small text-muted mb-0">
            <li>Caddy pide un certificado para <code>panel.midominio.com</code>.</li>
            <li>Let's Encrypt pide demostrar control del dominio.</li>
            <li>Caddy usa la API del proveedor DNS para crear el TXT <code>_acme-challenge.panel.midominio.com</code>.</li>
            <li>Let's Encrypt consulta DNS publico y verifica el token.</li>
            <li>Si el TXT es correcto, emite el certificado.</li>
            <li>Caddy guarda el certificado y borra el TXT temporal.</li>
        </ol>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-signpost-2 me-2"></i>Configuracion recomendada para dominio del panel</div>
    <div class="card-body">
        <ol class="small text-muted mb-3">
            <li>Crear un subdominio dedicado, por ejemplo <code>panel.midominio.com</code>.</li>
            <li>En el DNS del dominio, crear un registro <code>A</code> hacia la IP publica del servidor.</li>
            <li>Entrar por IP al panel: <code>https://203.0.113.10:8444/</code>.</li>
            <li>Abrir <code>Settings &gt; Server</code>.</li>
            <li>En <strong>Dominio del panel</strong>, escribir <code>panel.midominio.com</code>.</li>
            <li>Elegir el modo TLS segun la red: HTTP-01/TLS-ALPN-01 o DNS-01.</li>
            <li>Guardar y esperar la emision del certificado. Puede tardar de unos segundos a un par de minutos.</li>
            <li>Probar acceso final: <code>https://panel.midominio.com:8444/</code>.</li>
        </ol>
        <div class="small text-muted mb-0">
            Consejo operativo: conserva siempre el acceso por IP como fallback. El dominio es comodidad y certificado publico; la IP es rescate.
        </div>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(245,158,11,.35);">
    <div class="card-header"><i class="bi bi-fire me-2 text-warning"></i>Si 80/443 estan cerrados</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Con firewall estricto, HTTP-01 y TLS-ALPN-01 fallan porque Let's Encrypt intenta conectar desde Internet a la IP publica.
            Si no puede conectar, Caddy mostrara errores de timeout en logs.
        </p>
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.24);">
                    <div class="fw-semibold mb-2">Opcion A: asistencia ACME temporal</div>
                    <ul class="small text-muted mb-0">
                        <li>El panel detecta si <code>80/443</code> no estan abiertos publicamente.</li>
                        <li>Al guardar o pulsar asistencia, pide password admin.</li>
                        <li>Abre reglas temporales para emitir el certificado.</li>
                        <li>Las reglas se marcan como <code>MuseDock ACME temporary</code>.</li>
                        <li>El sistema elimina solo esas reglas temporales al expirar.</li>
                    </ul>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.24);">
                    <div class="fw-semibold mb-2">Opcion B: DNS-01</div>
                    <ul class="small text-muted mb-0">
                        <li>No requiere abrir <code>80/443</code>.</li>
                        <li>Funciona con firewall estricto.</li>
                        <li>Funciona aunque el dominio este detras de proxy/CDN.</li>
                        <li>Necesita modulo DNS en Caddy y credenciales API del proveedor DNS.</li>
                        <li>Es la opcion mas estable para renovaciones automaticas con puertos cerrados.</li>
                    </ul>
                </div>
            </div>
        </div>
        <p class="small text-muted mt-3 mb-0">
            Si abres <code>80/443</code> manualmente como reglas permanentes, MuseDock no debe cerrarlas. La limpieza temporal solo borra reglas que el propio asistente creo.
        </p>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(249,115,22,.35);">
    <div class="card-header"><i class="bi bi-cloud me-2" style="color:#f97316;"></i>Si el dominio esta detras de proxy naranja</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Cuando un proxy/CDN queda delante del servidor, Let's Encrypt puede no llegar directamente al Caddy del nodo.
            Eso afecta especialmente a HTTP-01 y TLS-ALPN-01.
        </p>
        <ul class="small text-muted mb-3">
            <li><strong>HTTP-01:</strong> necesita que <code>http://panel.midominio.com/.well-known/acme-challenge/...</code> llegue al Caddy correcto.</li>
            <li><strong>TLS-ALPN-01:</strong> necesita que la conexion TLS en <code>443</code> llegue al Caddy que responde el challenge.</li>
            <li><strong>DNS-01:</strong> no depende de que el trafico llegue al servidor; por eso evita el problema.</li>
        </ul>
        <div class="small text-muted mb-0">
            Si usas proxy naranja/CDN y quieres firewall cerrado, el modelo mas limpio es DNS-01 con el proveedor DNS que controla la zona.
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-box-arrow-down me-2"></i>Proveedores DNS y modulos Caddy</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Caddy no carga proveedores DNS como plugins dinamicos. Para usar DNS-01 con un proveedor, el binario de Caddy debe estar compilado
            con <code>dns.providers.&lt;proveedor&gt;</code>. MuseDock detecta los instalados y puede instalar nuevos desde <code>Settings &gt; DNS</code>.
        </p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Proveedor</th>
                        <th>Modulo Caddy</th>
                        <th>JSON habitual</th>
                    </tr>
                </thead>
                <tbody class="small text-muted">
                    <tr><td>Cloudflare</td><td><code>dns.providers.cloudflare</code></td><td><code>{"api_token":"..."}</code></td></tr>
                    <tr><td>DigitalOcean</td><td><code>dns.providers.digitalocean</code></td><td><code>{"token":"..."}</code></td></tr>
                    <tr><td>Route53</td><td><code>dns.providers.route53</code></td><td><code>{"access_key_id":"...","secret_access_key":"...","region":"us-east-1"}</code></td></tr>
                    <tr><td>Hetzner DNS</td><td><code>dns.providers.hetzner</code></td><td><code>{"api_token":"..."}</code></td></tr>
                    <tr><td>OVH</td><td><code>dns.providers.ovh</code></td><td><code>{"endpoint":"ovh-eu","application_key":"...","application_secret":"...","consumer_key":"..."}</code></td></tr>
                    <tr><td>Vultr</td><td><code>dns.providers.vultr</code></td><td><code>{"api_token":"..."}</code></td></tr>
                    <tr><td>Linode</td><td><code>dns.providers.linode</code></td><td><code>{"token":"..."}</code></td></tr>
                    <tr><td>Porkbun</td><td><code>dns.providers.porkbun</code></td><td><code>{"api_key":"...","secret_api_key":"..."}</code></td></tr>
                    <tr><td>Namecheap</td><td><code>dns.providers.namecheap</code></td><td><code>{"api_user":"...","api_key":"..."}</code></td></tr>
                    <tr><td>Gandi</td><td><code>dns.providers.gandi</code></td><td><code>{"api_token":"..."}</code></td></tr>
                    <tr><td>PowerDNS</td><td><code>dns.providers.powerdns</code></td><td><code>{"server_url":"https://dns.example.com","api_token":"..."}</code></td></tr>
                    <tr><td>RFC2136/BIND</td><td><code>dns.providers.rfc2136</code></td><td><code>{"key_name":"...","key_alg":"hmac-sha256","key":"...","server":"127.0.0.1:53"}</code></td></tr>
                </tbody>
            </table>
        </div>
        <p class="small text-muted mt-3 mb-0">
            En el JSON no incluyas <code>name</code>. MuseDock lo rellena con el proveedor seleccionado para evitar que el formulario diga una cosa y Caddy reciba otra.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-tools me-2"></i>Instalar un proveedor DNS en Caddy desde el panel</div>
    <div class="card-body">
        <ol class="small text-muted mb-3">
            <li>Abrir <code>Settings &gt; DNS</code>.</li>
            <li>Seleccionar proveedor, instalar modulo si falta y guardar credenciales.</li>
            <li>Marcar <strong>Activar DNS-01 como TLS del panel</strong> si quieres aplicar el cambio al guardar.</li>
            <li>Si aun no hay dominio o email ACME, completarlos antes en <code>Settings &gt; Server</code>.</li>
            <li>En el bloque <strong>Instalar modulo DNS en Caddy</strong>, elegir proveedor y pulsar <strong>Instalar proveedor</strong>.</li>
            <li>Confirmar con password de administrador.</li>
            <li>Esperar unos minutos y recargar la pagina.</li>
            <li>Verificar que el proveedor aparece en el selector de proveedores instalados.</li>
        </ol>
        <p class="small text-muted mb-2">El instalador hace:</p>
        <ul class="small text-muted mb-0">
            <li>Prepara Go/xcaddy si faltan.</li>
            <li>Lee modulos no estandar ya instalados para preservarlos.</li>
            <li>Compila un nuevo binario de Caddy con el modulo DNS elegido.</li>
            <li>Guarda backup del binario actual en <code>/var/backups/musedock/caddy/</code>.</li>
            <li>Reemplaza el binario, reinicia Caddy y comprueba que queda activo.</li>
            <li>Si falla, restaura el binario anterior y reinicia Caddy.</li>
            <li>Guarda el estado en <code>storage/caddy-dns-provider-install.json</code> y el log en <code>storage/logs/caddy-dns-provider-install.log</code>.</li>
        </ul>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-check2-square me-2"></i>Checklist antes de guardar DNS-01</div>
            <div class="card-body">
                <ul class="small text-muted mb-0">
                    <li>El dominio/subdominio existe y resuelve correctamente.</li>
                    <li>El proveedor DNS seleccionado es el que controla la zona real del dominio.</li>
                    <li>Caddy reporta el modulo <code>dns.providers.&lt;proveedor&gt;</code>.</li>
                    <li>El JSON contiene credenciales validas y con permisos de editar TXT en la zona.</li>
                    <li>El email ACME es valido.</li>
                    <li>El acceso por IP del panel sigue funcionando como fallback.</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Errores frecuentes</div>
            <div class="card-body">
                <ul class="small text-muted mb-0">
                    <li><strong>Modulo no instalado:</strong> Caddy no tiene <code>dns.providers.&lt;proveedor&gt;</code>.</li>
                    <li><strong>Token sin permisos:</strong> el proveedor rechaza crear el TXT.</li>
                    <li><strong>Zona equivocada:</strong> el dominio pertenece a otra cuenta/proveedor.</li>
                    <li><strong>Proxy confundido con DNS:</strong> el proxy puede estar activo, pero DNS-01 depende de la API DNS real.</li>
                    <li><strong>Esperar certificado instantaneo:</strong> ACME puede tardar; revisa logs antes de repetir muchas veces.</li>
                    <li><strong>HSTS del navegador:</strong> si antes habia certificado interno o invalido, prueba ventana privada tras corregir.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-terminal me-2"></i>Comandos de diagnostico</div>
    <div class="card-body">
        <div class="small text-muted mb-2">Ver proveedores DNS instalados:</div>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">caddy list-modules | grep '^dns.providers'</pre>
        <div class="small text-muted mb-2">Ver modulos no estandar con paquete Go:</div>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">caddy list-modules --packages --skip-standard</pre>
        <div class="small text-muted mb-2">Ver logs de emision ACME:</div>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">journalctl -u caddy --since "15 minutes ago" --no-pager \
  | egrep -i 'panel.midominio.com|acme|challenge|certificate|obtain|error|timeout|unauthorized|dns'</pre>
        <div class="small text-muted mb-2">Ver certificado servido realmente:</div>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">echo | openssl s_client -connect panel.midominio.com:8444 -servername panel.midominio.com -showcerts 2>/dev/null \
  | sed -n '/BEGIN CERTIFICATE/,/END CERTIFICATE/p' \
  | openssl x509 -noout -subject -issuer -dates -ext subjectAltName</pre>
        <div class="small text-muted mb-2">Ver reglas temporales ACME si usaste asistencia de firewall:</div>
        <pre class="small p-3 rounded mb-0" style="background:#0f172a;color:#e2e8f0;">iptables -S INPUT | grep 'MuseDock ACME temporary'</pre>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-route me-2"></i>Decision rapida</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Situacion</th>
                        <th>Modo recomendado</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody class="small text-muted">
                    <tr>
                        <td>Primer acceso por IP o laboratorio</td>
                        <td><code>self_signed</code></td>
                        <td>No depende de DNS ni Internet.</td>
                    </tr>
                    <tr>
                        <td>Dominio directo al servidor y 80/443 abiertos</td>
                        <td><code>HTTP-01/TLS-ALPN-01</code></td>
                        <td>Simple, sin API DNS.</td>
                    </tr>
                    <tr>
                        <td>Firewall cerrado y quieres renovaciones automaticas</td>
                        <td><code>DNS-01</code></td>
                        <td>No necesita entrada publica por 80/443.</td>
                    </tr>
                    <tr>
                        <td>Dominio detras de proxy naranja/CDN</td>
                        <td><code>DNS-01</code></td>
                        <td>La validacion se hace en DNS, no atravesando el proxy.</td>
                    </tr>
                    <tr>
                        <td>No tienes API DNS ni quieres instalar modulo</td>
                        <td><code>HTTP-01/TLS-ALPN-01</code></td>
                        <td>Necesitas abrir 80/443, al menos durante emision/renovacion.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-shield-check me-2"></i>Resumen operativo</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Para un panel publico, usa un subdominio dedicado como <code>panel.midominio.com</code>.</li>
            <li>El acceso por IP debe mantenerse como fallback con certificado interno.</li>
            <li>HTTP-01/TLS-ALPN-01 es simple, pero requiere <code>80/443</code> alcanzables desde Internet.</li>
            <li>DNS-01 es la opcion correcta con firewall cerrado o proxy delante.</li>
            <li>DNS-01 depende de que Caddy tenga el modulo del proveedor DNS y credenciales API validas.</li>
            <li>El instalador de modulos DNS recompila Caddy con backup y rollback; no es un cambio cosmetico.</li>
            <li>Tras guardar TLS, la emision puede tardar; confirma con logs y certificado servido antes de repetir acciones.</li>
        </ul>
    </div>
</div>
