<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Correo en Alta Disponibilidad (master + slave)</h4>
        <div class="text-muted small">Cómo montar el correo en el master y una réplica de respaldo en un nodo slave, paso a paso.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a>
        <a href="/mail?tab=infra" class="btn btn-outline-info btn-sm"><i class="bi bi-hdd-network me-1"></i>Abrir Infra</a>
    </div>
</div>

<!-- Modelo -->
<div class="card mb-4" style="border-color:rgba(56,189,248,.24);">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Cómo funciona (el modelo)</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            El correo se <strong>gestiona en el master</strong>. Un nodo slave mantiene una <strong>copia viva</strong> de los buzones
            para poder tomar el relevo si el master cae. Hay dos capas que se replican por caminos distintos:
        </p>
        <ul class="small text-muted mb-2">
            <li><strong>La configuración</strong> (qué dominios y buzones existen, contraseñas, cuotas): el slave la lee directamente
                de la base de datos del master por WireGuard. Aparece al instante.</li>
            <li><strong>Los mensajes</strong> (los correos dentro de cada buzón): se copian por <strong>Dovecot dsync</strong> a medida que llegan.</li>
        </ul>
        <p class="small text-muted mb-0">
            Regla de oro: <strong>los dominios y buzones se crean SOLO en el master</strong>. El slave nunca se administra directamente.
        </p>
    </div>
</div>

<!-- SECCIÓN 1 -->
<div class="card mb-4">
    <div class="card-header"><span class="badge bg-primary me-2">Sección 1</span>Instalar el correo en el MASTER</div>
    <div class="card-body">
        <ol class="small mb-0" style="line-height:1.9;">
            <li><strong>Instalar el servidor de correo.</strong> Ve a <code>Mail → Infra</code> y configura el modo
                <strong>Correo Completo</strong> con el hostname (p.ej. <code>mail.musedock.com</code>). Instala Postfix, Dovecot,
                OpenDKIM y Rspamd.</li>
            <li><strong>Crear el dominio de correo.</strong> <code>Mail → Dominios → Crear dominio</code> (p.ej. <code>musedock.com</code>).
                Al crearlo, el panel <strong>genera la clave DKIM</strong> y te muestra los registros DNS en la tarjeta <em>DNS Records</em>.</li>
            <li><strong>Crear los buzones.</strong> Dentro del dominio → <code>Nuevo buzón</code> (p.ej. <code>antoni@musedock.com</code>),
                con contraseña y cuota.</li>
            <li><strong>Configurar el DNS</strong> (ver la tabla de más abajo).</li>
            <li><strong>Verificar.</strong> En <code>Mail → Entregabilidad</code> el panel comprueba en vivo MX, SPF, DKIM, DMARC, A y PTR.
                Envía y recibe un correo de prueba antes de continuar.</li>
        </ol>
    </div>
</div>

<!-- DNS -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-globe me-2"></i>Registros DNS (para cada dominio de correo)</div>
    <div class="card-body">
        <p class="small text-muted mb-2">El panel te los da hechos en la tarjeta <em>DNS Records</em>. Los pones en tu servidor DNS
            (Cloudflare). El único que <strong>no</strong> va en Cloudflare es el PTR.</p>
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Tipo</th><th>Nombre</th><th>Valor</th><th>Para qué</th></tr></thead>
            <tbody class="small">
                <tr><td><span class="badge bg-secondary">MX</span></td><td><code>musedock.com</code></td><td><code>mail.musedock.com</code> (prio 10)</td><td>Dónde se entrega el correo</td></tr>
                <tr><td><span class="badge bg-secondary">A</span></td><td><code>mail.musedock.com</code></td><td>IP pública del master</td><td>Resuelve el hostname del correo</td></tr>
                <tr><td><span class="badge bg-secondary">TXT</span></td><td><code>musedock.com</code></td><td><code>v=spf1 mx ~all</code></td><td>SPF: quién puede enviar</td></tr>
                <tr><td><span class="badge bg-secondary">TXT</span></td><td><code>default._domainkey…</code></td><td>(clave DKIM del panel)</td><td>Firma de autenticidad</td></tr>
                <tr><td><span class="badge bg-secondary">TXT</span></td><td><code>_dmarc.musedock.com</code></td><td><code>v=DMARC1; p=quarantine; …</code></td><td>Política ante fallos</td></tr>
                <tr><td><span class="badge bg-warning text-dark">PTR</span></td><td>(IP del master)</td><td><code>mail.musedock.com</code></td><td><strong>En el panel del VPS (Contabo), NO en Cloudflare</strong></td></tr>
            </tbody>
        </table>
        </div>
        <p class="small text-muted mt-2 mb-0">
            <i class="bi bi-exclamation-triangle text-warning me-1"></i>
            Si usas Cloudflare como proxy, el registro <code>A</code> de <code>mail.</code> debe ir en modo <strong>"solo DNS"</strong>
            (nube gris), nunca proxied — el correo no pasa por el proxy de Cloudflare.
        </p>
    </div>
</div>

<!-- SECCIÓN 2 -->
<div class="card mb-4">
    <div class="card-header"><span class="badge bg-primary me-2">Sección 2</span>Activar la réplica de respaldo en el SLAVE</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            <strong>Esto se lanza desde el panel del MASTER</strong> (no desde el slave). El master abre el acceso a su base de datos,
            comparte el secreto de replicación y configura ambos lados de forma segura.
        </p>
        <ol class="small mb-3" style="line-height:1.9;">
            <li>En el master, ve a <code>Mail → Infra</code>. Verás la tarjeta
                <strong>"Réplica de respaldo de correo (failover)"</strong> con la lista de nodos slave.</li>
            <li>Pulsa <strong>"Instalar réplica de correo"</strong> en el nodo deseado (p.ej. Filemon) y confirma tu contraseña de administrador.</li>
            <li>El master hace todo automáticamente:
                <ul class="mb-1">
                    <li>abre su <code>pg_hba</code> para que el slave lea las cuentas por WireGuard;</li>
                    <li>comparte el secreto de <strong>dsync</strong> y configura ambos lados;</li>
                    <li>ordena al slave instalar Postfix/Dovecot leyendo la base de datos del master;</li>
                    <li>programa el <strong>sync inicial</strong> de los buzones existentes (se ejecuta al terminar la instalación).</li>
                </ul>
            </li>
            <li>La instalación de servicios continúa en segundo plano en el slave. Puedes ver el avance en el panel del slave
                (<code>Mail → General</code>), donde los pasos pasan a verde.</li>
        </ol>
        <div class="alert alert-info small mb-0">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Qué se replica y cuándo:</strong> los <strong>dominios y buzones nuevos</strong> aparecen en el slave al instante
            (los lee del master). Los <strong>mensajes</strong> se copian por dsync a medida que llegan, y los ya existentes con el
            sync inicial. Nunca crees dominios ni buzones en el slave: solo en el master.
        </div>
    </div>
</div>

<!-- Verificación slave -->
<div class="row g-4 mb-4">
    <div class="col-lg-6"><div class="card h-100">
        <div class="card-header"><i class="bi bi-check2-square me-2"></i>Cómo saber que el slave está listo</div>
        <div class="card-body"><ul class="small text-muted mb-0">
            <li>En el panel del slave (<code>Mail → General</code>) los dos pasos están en verde: servicios instalados + dsync activo.</li>
            <li>Los contadores de dominios/buzones del slave coinciden con los del master.</li>
            <li>Un correo nuevo entregado en el master aparece también en el slave a los pocos segundos.</li>
        </ul></div>
    </div></div>
    <div class="col-lg-6"><div class="card h-100">
        <div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Notas importantes</div>
        <div class="card-body"><ul class="small text-muted mb-0">
            <li>Es un estreno: la primera vez, hazlo con el correo del master ya <strong>verificado y funcionando</strong>.</li>
            <li>El correo del slave lee las cuentas del master: si el master está caído, el slave necesita ser promovido para servir escritura.</li>
            <li>La promoción del slave a master (failover) se hace en <code>Settings → Cluster</code>, no aquí.</li>
        </ul></div>
    </div></div>
</div>
