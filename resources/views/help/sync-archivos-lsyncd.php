<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Sync de archivos (lsyncd)</h4>
        <div class="text-muted small">Guia para diagnosticar "Sync degradado", cola de eventos y conectividad SSH entre nodos.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver a Docs
        </a>
        <a href="/settings/cluster#archivos" class="btn btn-outline-info btn-sm">
            <i class="bi bi-diagram-3 me-1"></i> Ir a Cluster &gt; Archivos
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(25,135,84,.35);">
    <div class="card-header"><i class="bi bi-arrow-repeat me-2 text-success"></i>Como funciona la sincronizacion de archivos</div>
    <div class="card-body small">
        <p class="mb-2">
            La sincronizacion de archivos hacia los slaves <strong>no es un evento unico</strong>: es continua y automatica.
            El boton <em>Sincronizacion Completa</em> solo es para el <strong>arranque inicial de un nodo nuevo o una reparacion manual</strong>.
        </p>

        <div class="fw-bold mt-3 mb-1"><i class="bi bi-clock-history me-1"></i>Cada cuanto se sincroniza</div>
        <ul class="mb-3">
            <li><strong>lsyncd — tiempo real:</strong> cualquier cambio en <code>/var/www/vhosts/</code> se copia al slave en segundos.</li>
            <li><strong>Refuerzo periodico — cada 15 minutos:</strong> un <code>rsync</code> de repaso + sincronizacion de bases de datos (via dump/restore, si no hay replicacion streaming activa).</li>
            <li><strong>Worker de cluster — cada minuto:</strong> procesa la cola de tareas (altas de hosting, metadatos) y los heartbeats.</li>
        </ul>

        <div class="fw-bold mb-1"><i class="bi bi-files me-1"></i>Que copia y como (rsync)</div>
        <ul class="mb-3">
            <li><strong>Solo lo modificado:</strong> usa <code>rsync -avz</code>, que es <strong>incremental</strong> — compara tamaño y fecha de cada archivo y solo transfiere lo <strong>nuevo o cambiado</strong>. Los archivos identicos ni se tocan.
                <div class="text-muted mt-1">Ejemplo: si ya hay 107&nbsp;GB copiados y cambias un archivo de 1&nbsp;MB, el siguiente sync transfiere 1&nbsp;MB, no 107&nbsp;GB.</div>
            </li>
            <li><strong>Es rapido en repeticiones:</strong> la primera vez copia todo (puede tardar segun el volumen); las siguientes solo mueven los cambios, en segundos o pocos minutos. Calcular lo que falta es rapido: compara metadatos, no relee todo el contenido.</li>
            <li><strong>Es seguro pulsarlo varias veces:</strong> los hostings se reparan si ya existen y las bases se reimportan sin perder datos.</li>
        </ul>

        <div class="p-2 rounded" style="background:rgba(255,193,7,.08);border:1px solid rgba(255,193,7,.3);">
            <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1 text-warning"></i>Importante: el slave es un espejo EXACTO (<code>--delete</code>)</div>
            <ul class="mb-0">
                <li>Si <strong>borras</strong> un archivo en el master, tambien se <strong>borra</strong> en el slave. Correcto: es un espejo.</li>
                <li>Si alguien crea un archivo <strong>directamente en el slave</strong> que no existe en el master, <strong>rsync lo eliminara</strong> en la siguiente sincronizacion.</li>
                <li><strong>Regla:</strong> no guardes nunca archivos propios en un slave. La unica fuente de verdad es el master.</li>
            </ul>
        </div>

        <div class="fw-bold mt-3 mb-1"><i class="bi bi-info-circle me-1"></i>Que NO se copia</div>
        <p class="text-muted mb-0">
            El binario de Caddy (con sus modulos DNS compilados), <code>/etc/default/caddy</code> (token de Cloudflare),
            la configuracion del sistema (<code>/etc</code>, paquetes, servicios) y los patrones excluidos
            (<code>.git</code>, <code>.cache</code>, <code>node_modules</code>, logs, sesiones, directorios de IDE).
            Un slave es una replica de <strong>hostings y bases de clientes</strong>, no un clon completo del sistema operativo.
        </p>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(239,68,68,.35);">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Que significa "Sync degradado"</div>
    <div class="card-body">
        <p class="small text-muted mb-0">
            El panel detecta que <code>lsyncd</code> no puede sincronizar correctamente (normalmente por SSH no accesible,
            cola creciente o consumo anormal). La alerta incluye acceso directo a <code>/settings/cluster#archivos</code>
            para corregir desde la UI.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-lightning-charge me-2"></i>Respuesta rapida recomendada</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <li>Ir a <a href="/settings/cluster#archivos" class="text-info">Cluster &rarr; Archivos</a>.</li>
            <li>Usar <strong>Reintentar</strong> o <strong>Autocorregir (contener)</strong> si la alerta sigue activa.</li>
            <li>Probar conectividad SSH entre nodos (sin password para automatizacion).</li>
            <li>Verificar que <code>lsyncd</code> esta activo en el nodo master.</li>
            <li>Confirmar que la cola baja y la alerta desaparece en monitor.</li>
        </ol>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-terminal me-2"></i>Comandos de diagnostico</div>
    <div class="card-body">
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;"># Estado del servicio
systemctl is-active lsyncd
systemctl is-enabled lsyncd

# Estado SSH en nodo remoto (ejemplo: 10.10.70.156)
nc -vz -w2 10.10.70.156 22

# Test SSH sin password (BatchMode)
ssh -o BatchMode=yes -o PasswordAuthentication=no \
    -o PreferredAuthentications=publickey \
    -o IdentitiesOnly=yes \
    -i /root/.ssh/id_ed25519 root@10.10.70.156 'echo OK'

# Logs recientes de sync
tail -n 200 /opt/musedock-panel/storage/logs/filesync-worker.log
journalctl -u lsyncd -n 200 --no-pager</pre>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-shield-lock me-2"></i>Causas frecuentes</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Fail2Ban bloquea IP interna del cluster (falso positivo).</li>
            <li>Clave SSH no instalada en el nodo remoto o clave incorrecta.</li>
            <li><code>sshd</code> remoto caido o puerto 22 no escuchando temporalmente.</li>
            <li>Regla de firewall o ruta de red bloqueando trafico interno.</li>
            <li>Destino remoto con carga alta y colas acumuladas de eventos.</li>
        </ul>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(56,189,248,.35);">
    <div class="card-header"><i class="bi bi-arrow-repeat me-2 text-info"></i>Buenas practicas para evitar recurrencia</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Mantener SSH bidireccional con claves independientes por nodo.</li>
            <li>Agregar red interna del cluster a <code>ignoreip</code> de Fail2Ban.</li>
            <li>Usar IP privada (WireGuard) para sync entre nodos.</li>
            <li>Monitorear alertas <code>LSYNCD_SYNC_DEGRADED</code> en Monitor y Dashboard.</li>
            <li>Antes de mantenimiento en nodos, pausar sync o usar modo standby controlado.</li>
        </ul>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-check2-square me-2"></i>Estado saludable esperado</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li><code>lsyncd</code> activo y estable.</li>
            <li>SSH entre nodos responde sin password para cuenta tecnica.</li>
            <li>Sin crecimiento sostenido de cola de eventos.</li>
            <li>Sin alerta activa de "Sync degradado" en Monitor.</li>
        </ul>
    </div>
</div>
