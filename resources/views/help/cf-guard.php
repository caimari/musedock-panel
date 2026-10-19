<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Bloqueos de Cloudflare (LaLiga)</h4>
        <div class="text-muted small">Quitar la nube naranja sola cuando las operadoras bloquean IPs de Cloudflare, y volver a ponerla cuando pasa. Para los dominios de hosting de este panel.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
        <a href="/settings/cf-guard" class="btn btn-outline-info btn-sm"><i class="bi bi-shield-exclamation me-1"></i> Abrir Bloqueos Cloudflare</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-question-circle me-2"></i>El problema</div>
    <div class="card-body small text-muted">
        <p>Cloudflare no da una IP a cada web: miles de webs comparten las mismas. En España, durante los partidos de LaLiga, las operadoras (Movistar, Vodafone/ONO, Orange, Digi…)
            bloquean por orden judicial las IPs de Cloudflare donde LaLiga detecta retransmisiones piratas. Con ellas caen todas las webs legales que las comparten.</p>
        <p class="mb-0">Desde España la web no carga: por HTTPS el navegador dice <code>ERR_CONNECTION_TIMED_OUT</code>; por HTTP, Vodafone enseña «Por causas ajenas a Vodafone, esta web no está disponible»
            (Orange no enseña nada). Desde fuera de España funciona. No es una caída de la web: es un <strong>acceso cortado por la operadora</strong>. En los planes normales de Cloudflare no se pueden pedir
            otras IPs: la única forma de seguir visible es quitar el proxy mientras dura el bloqueo.</p>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(249,115,22,.35);">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>No es un escudo</div>
    <div class="card-body small text-muted">
        Quitar el proxy tiene un coste: mientras dura el bloqueo, el dominio pierde la caché y la protección de Cloudflare y se ve la IP del servidor. Es un riesgo que se asume a propósito
        porque es la única forma de que la web siga viéndose. Por eso se hace solo en los dominios afectados, solo cuando el servidor responde bien por su cuenta, y el menor tiempo posible.
        Mientras tanto, las jaulas de Fail2Ban siguen baneando a quien prueba contraseñas o escanea (<a href="/docs/fail2ban-cluster" class="text-info">guía de Fail2Ban</a>).
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Cómo funciona</div>
    <div class="card-body small text-muted">
        <ol class="mb-2">
            <li><strong>Inventario:</strong> cada 30 minutos el panel lee de Cloudflare (cuentas de Cloudflare DNS) los registros A y CNAME de los dominios de hosting: dominio de cada cuenta, dominios extra, subdominios y alias, con su www.</li>
            <li><strong>Sondas:</strong> una máquina en el país que se vigila (p. ej. en la oficina, con dos operadoras) prueba cada minuto, por cada operadora, si llega a esas IPs de Cloudflare, y resuelve los dominios con el DNS de allí (Cloudflare da IPs distintas según el país).</li>
            <li><strong>Decisión:</strong> si una IP falla dos informes seguidos desde alguna operadora, se da por bloqueada. Antes de cambiar nada, la sonda comprueba que el servidor responde directo con certificado válido.</li>
            <li><strong>Cambio:</strong> se quita la nube naranja de los registros afectados (TTL 60 s) y se guarda cómo estaban.</li>
            <li><strong>Vuelta:</strong> cuando todas las IPs del dominio llevan 30 minutos (ajustable) respondiendo desde todas las operadoras, se vuelve a poner el proxy.</li>
        </ol>
        El panel también prueba la IP desde el propio servidor: si desde aquí responde, el aviso dice «bloqueo de operadoras»; si no responde desde ningún sitio, «Cloudflare no responde».
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.3);">
    <div class="card-header"><i class="bi bi-shield-check me-2"></i>Reglas que nunca se rompen</div>
    <div class="card-body small text-muted">
        <ul class="mb-0">
            <li>Solo se toca lo que tenía el proxy puesto. Lo que iba directo nunca entra en el ciclo, así que nunca se le pone el proxy.</li>
            <li>Al volver, el proxy solo se pone si el registro sigue exactamente como lo dejó el sistema. Si alguien lo cambió (Cloudflare, Cloudflare DNS del panel, un cliente), se respeta y se avisa.</li>
            <li>Sin informes de sondas en los últimos 5 minutos, no se cambia nada.</li>
            <li>Si el servidor no responde directo con un certificado válido, el dominio se deja como está.</li>
            <li>Las sondas no tienen las claves de Cloudflare: solo informan. Quien cambia el DNS es el panel.</li>
            <li>Solo actúa el panel que manda (master o standalone, sin aislar). En un slave la pantalla es de solo lectura.</li>
            <li>Las cuentas que tienen dentro un <strong>MuseDock CMS</strong> con su propia conmutación se excluyen solas: de esos dominios se encarga el CMS (se ve en «Nunca tocar»).</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-toggles me-2"></i>Modos</div>
    <div class="card-body small text-muted">
        <ul class="mb-0">
            <li><strong>Apagado:</strong> no quita el proxy a nada; si había cambios hechos, los sigue deshaciendo cuando la IP vuelve.</li>
            <li><strong>Simulación:</strong> detecta y avisa de lo que haría, sin tocar Cloudflare. Para empezar.</li>
            <li><strong>Automático:</strong> quita y vuelve a poner el proxy solo, y avisa (notificaciones del panel, tipo «Bloqueos de Cloudflare», silenciable en Ajustes → Avisos).</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-broadcast me-2"></i>Poner una sonda</div>
    <div class="card-body small text-muted">
        <p>La sonda es el mismo script que usa MuseDock CMS (Python 3 + curl, sin datos de la instalación). Una misma máquina puede informar al CMS y al panel: cada uno con su configuración y su token.</p>
        <ol>
            <li>En <a href="/settings/cf-guard" class="text-info">Bloqueos Cloudflare → Sondas</a>, pon un nombre y pulsa <strong>Crear sonda</strong>. El token sale una sola vez (el panel guarda su huella).</li>
            <li>Descarga el script con <strong>Descargar sonda</strong> y cópialo a <code>/usr/local/bin/musedock-edge-probe.py</code> en la máquina.</li>
            <li>Guarda el token en <code>/etc/musedock-edge-probe-panel.token</code> (permisos 600) y crea <code>/etc/musedock-edge-probe-panel.conf</code>:</li>
        </ol>
<pre class="p-2 rounded" style="background:rgba(0,0,0,.3);white-space:pre-wrap;">URL=https://tu-panel.example:8444
API_PREFIX=/api/cf-guard
TOKEN_FILE=/etc/musedock-edge-probe-panel.token
PATHS=Operadora1:eth0,Operadora2:eth1
RESOLVE=203.0.113.10          # IP real del servidor del panel (no la de Cloudflare)</pre>
        <ul>
            <li><code>PATHS</code>: cada salida a internet como <code>nombre:interfaz</code> (<code>ip -br addr</code> da los nombres). Vacío = la salida por defecto.</li>
            <li><code>RESOLVE</code>: llegar al panel por su IP real, sin pasar por el DNS ni por Cloudflare. El puerto del panel debe estar abierto a la IP pública de la sonda (o por VPN).</li>
            <li><code>INSECURE=1</code> solo si el panel usa un certificado propio no válido (mejor ponerle uno válido).</li>
        </ul>
        <p>Prueba a mano y deja el cron:</p>
<pre class="p-2 rounded mb-0" style="background:rgba(0,0,0,.3);white-space:pre-wrap;">EDGE_PROBE_CONF=/etc/musedock-edge-probe-panel.conf python3 -I /usr/local/bin/musedock-edge-probe.py -v
echo '* * * * * root EDGE_PROBE_CONF=/etc/musedock-edge-probe-panel.conf flock -n /run/musedock-edge-probe-panel.lock python3 -I /usr/local/bin/musedock-edge-probe.py' &gt; /etc/cron.d/musedock-edge-probe-panel</pre>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-pencil-square me-2"></i>Cambiar la configuración original</div>
    <div class="card-body small text-muted">
        En «Tus dominios en Cloudflare», el badge de cada dominio (Con proxy / Sin proxy) se pulsa para cambiarlo, con tu contraseña. Tiene en cuenta el bloqueo:
        <ul class="mb-0">
            <li>A «con proxy» sin bloqueo: se pone ya. Con la zona bloqueada: sigue directo y queda <strong>pendiente</strong>; se le pone el proxy al acabar el bloqueo.</li>
            <li>A «sin proxy» sin bloqueo: se quita ya. Si ya iba directo por un bloqueo: se <strong>anula el regreso</strong> y no se le vuelve a poner.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-terminal me-2"></i>Proceso y comandos</div>
    <div class="card-body small text-muted">
        <p>El vigilante es <code>bin/cf-guard-worker.php</code>, cada minuto (<code>/etc/cron.d/musedock-cf-guard</code>, lo instala <code>bin/update.sh</code>). Registro: <code>storage/logs/cf-guard-worker.log</code>.</p>
<pre class="p-2 rounded mb-0" style="background:rgba(0,0,0,.3);white-space:pre-wrap;">php bin/cf-guard-worker.php --status       # sondas, IPs y cambios activos
php bin/cf-guard-worker.php --inventory    # volver a leer Cloudflare ya
php bin/cf-guard-worker.php --new-probe=NOMBRE --token-file=/root/token   # crear sonda sin mostrar el token</pre>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-info-circle me-2"></i>Lo que hay que saber</div>
    <div class="card-body small text-muted">
        <ul class="mb-0">
            <li>Cada operadora levanta el bloqueo a su ritmo (el 17/10/2026, Orange a las 20:50 y Vodafone/ONO más tarde): el proxy no vuelve hasta que respondan todas.</li>
            <li>Los dominios de buckets <strong>R2</strong> y de Workers no se pueden pasar a directo: solo funcionan a través de Cloudflare (p. ej. el CDN de imágenes de una web).</li>
            <li>Solo se gestionan registros A y CNAME; los AAAA con proxy se quedan como están.</li>
            <li>Los cambios tardan lo que dura la caché DNS de cada visitante (hasta 5 minutos en registros con proxy).</li>
            <li>Horas: la pantalla muestra hora de España y, entre paréntesis, UTC.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Nombres extra: dominios que no son hostings de este panel</div>
    <div class="card-body small text-muted">
        <p>La guarda solo vigila los registros cuyo nombre es un <strong>hosting, un alias o un subdominio de este panel</strong>. Un dominio que sirve una aplicación
            por su cuenta (por ejemplo, el dominio de un cliente que apunta con un CNAME con proxy a un nombre de este servidor y que la aplicación añade directamente a Caddy)
            no figura en ninguna lista del panel y <strong>no se vigilaría</strong>: si se bloquean las IPs de Cloudflare, seguiría con la nube naranja y quedaría inaccesible
            desde las líneas afectadas.</p>
        <ul class="mb-2">
            <li>Añádelo en <em>Ajustes → Bloqueos Cloudflare → Nombres extra a vigilar</em> (uno por línea, el nombre exacto) o con <code>php bin/cf-guard-worker.php --add-host=app.cliente.com</code>
                (<code>--remove-host</code> para quitarlo, <code>--hosts</code> para listarlos).</li>
            <li>Tiene que hacerlo <strong>el panel que tenga la zona de ese dominio</strong> en sus cuentas de Cloudflare y que mande en ese momento; el ajuste se copia al otro nodo con el resto de la configuración.
                Si la zona está en otra cuenta que un panel no tiene, ese panel no puede tocarlo.</li>
            <li>Se lee de Cloudflare en la siguiente lectura del inventario (cada 30 minutos) o al momento con <code>--inventory</code>. Al quitar el proxy, el nombre pasa a ir directo al servidor
                (un CNAME sin proxy resuelve a la IP del servidor al que apunta), así que ese servidor necesita un certificado válido para ese nombre.</li>
        </ul>
        <p class="mb-0">Al vigilar un nombre el panel lo trata igual que a un hosting: lo quita del proxy solo cuando varias sondas ven bloqueadas sus IPs y lo repone cuando llevan el tiempo configurado respondiendo bien.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Con un par master / réplica</div>
    <div class="card-body small text-muted">
        <p>Solo <strong>manda el panel que es master</strong> (una réplica o un nodo apartado muestra el estado pero no toca Cloudflare). Para que el sistema siga protegiendo tras un relevo, dos cosas tienen que seguir al master:</p>
        <ul class="mb-2">
            <li><strong>La configuración</strong> (modo, minutos de espera, dominios que no se tocan, zona horaria y las sondas, estas solo como <em>huella</em>: sirve para reconocer a la sonda, no para hacerse pasar por ella; el token nunca viaja) se copia del master a su réplica junto con el resto de la configuración de relevo. Un master con esta pantalla <strong>en blanco</strong> (apagado, sin sondas ni exclusiones) <strong>no la manda</strong>, para no pisar la de una réplica que sí la tiene; al contrario, <strong>la adopta</strong> de ella en cuanto manda (cada 5 min como mucho). Nunca pisa una configuración que ya existe.</li>
            <li><strong>Las sondas</strong> deben informar a los <strong>dos</strong> paneles, no a uno fijo: <code>URL=https://panel-a:8444,https://panel-b:8444</code> (y, si hace falta, <code>RESOLVE=ip-a,ip-b</code>, una por posición). La sonda entrega el mismo informe a los dos y solo se queja si <em>ninguno</em> lo acepta, así que una réplica o un nodo apartado no llenan el correo. Solo el master actúa sobre Cloudflare.</li>
        </ul>
        <p class="mb-0">Si la sonda informa a un solo servidor y ese deja de mandar, el nuevo master ve «ninguna sonda ha informado» y <strong>no cambia nada</strong>: es el caso del relevo del 2026-10-18, que coincidió con un bloqueo de LaLiga y dejó 12 webs sin quitar el proxy.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-wrench me-2"></i>Solución de problemas</div>
    <div class="card-body small text-muted">
        <ul class="mb-0">
            <li><strong>Sonda «muda»:</strong> ejecútala a mano con <code>-v</code>. HTTP 401 = token mal; 000 = no llega al panel (URL, puerto, RESOLVE, cortafuegos); 429 = acababa de informar, normal.</li>
            <li><strong>Todas las IPs «Sin datos»:</strong> la web de control falla desde la sonda: esa salida no tiene internet (revisa la interfaz en PATHS).</li>
            <li><strong>«El servidor no responde directo con un certificado válido»:</strong> el dominio no tiene certificado propio en el servidor o el cortafuegos solo deja entrar a Cloudflare. No se cambia hasta arreglarlo.</li>
            <li><strong>Un dominio no aparece:</strong> solo entran los dominios de hosting del panel cuya zona está en una cuenta de Cloudflare DNS. Pulsa «Leer Cloudflare» y mira los errores.</li>
        </ul>
    </div>
</div>
