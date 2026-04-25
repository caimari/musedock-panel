<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Cluster: guia base</h4>
        <div class="text-muted small">Version inicial para operacion diaria. Se ampliara con guias detalladas por flujo.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm">
            <i class="bi bi-journal-text me-1"></i> Volver a Docs
        </a>
        <a href="/settings/cluster" class="btn btn-outline-info btn-sm">
            <i class="bi bi-diagram-3 me-1"></i> Abrir Cluster
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <p class="text-muted small mb-0">
            <strong>Objetivo:</strong> coordinar varios nodos (master/slave), sincronizar hostings y archivos,
            y tener failover operativo. Esta pagina resume el flujo minimo para no perderse entre pestañas.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-signpost-split me-2"></i>Mapa rapido de pestañas</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);">
                    <h6 class="mb-2"><a href="/settings/cluster#estado" class="text-decoration-none text-info">Estado</a></h6>
                    <p class="small text-muted mb-0">Salud local/remota, estado de recursos y acciones de sincronizacion completa.</p>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);">
                    <h6 class="mb-2"><a href="/settings/cluster#nodos" class="text-decoration-none text-info">Nodos</a></h6>
                    <p class="small text-muted mb-0">Alta/edicion de nodos slave, test de conectividad, servicios web/mail y Sync Todo.</p>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);">
                    <h6 class="mb-2"><a href="/settings/cluster#archivos" class="text-decoration-none text-info">Archivos</a></h6>
                    <p class="small text-muted mb-0">Sincronizacion de contenido (rsync/lsyncd), claves SSH, exclusiones, SSL y dumps de BD.</p>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);">
                    <h6 class="mb-2"><a href="/settings/cluster#failover" class="text-decoration-none text-info">Failover</a></h6>
                    <p class="small text-muted mb-0">Conmutacion de trafico (DNS/Cloudflare), estado de emergencia y failback.</p>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);">
                    <h6 class="mb-2"><a href="/settings/cluster#configuracion" class="text-decoration-none text-info">Configuracion</a></h6>
                    <p class="small text-muted mb-0">Rol del nodo, token local, intervalos de heartbeat/timeout y ajustes globales.</p>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);">
                    <h6 class="mb-2"><a href="/settings/cluster#cola" class="text-decoration-none text-info">Cola</a></h6>
                    <p class="small text-muted mb-0">Tareas pendientes/fallidas de sincronizacion, reprocesado y limpieza.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-lightning-charge me-2"></i>Flujo base recomendado</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <li>Definir rol en <a href="/settings/cluster#configuracion" class="text-info">Configuracion</a>: <code>master</code> en nodo principal y <code>slave</code> en nodo remoto.</li>
            <li>Generar/confirmar token local del nodo remoto y guardarlo.</li>
            <li>En el master, ir a <a href="/settings/cluster#nodos" class="text-info">Nodos</a> y anadir el slave (URL API + token), haciendo prueba de conexion.</li>
            <li>Ejecutar <strong>Sync Todo</strong> para crear estructura de hostings en el slave.</li>
            <li>Configurar <a href="/settings/cluster#archivos" class="text-info">Archivos</a>: SSH o HTTPS, exclusiones y modo de sync (periodico o lsyncd).</li>
            <li>Lanzar una <strong>Sincronizacion Completa</strong> desde <a href="/settings/cluster#estado" class="text-info">Estado</a>.</li>
            <li>Revisar <a href="/settings/cluster#cola" class="text-info">Cola</a> y dejar pendientes/fallidos en cero o controlados.</li>
            <li>Si aplica alta disponibilidad publica, terminar la configuracion de <a href="/settings/cluster#failover" class="text-info">Failover</a>.</li>
        </ol>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-node-plus me-2"></i>Anadir nodo slave paso a paso</div>
    <div class="card-body">
        <ol class="small text-muted mb-3">
            <li><strong>Preparar el slave:</strong> entrar en <a href="/settings/cluster#configuracion" class="text-info">Settings &rarr; Cluster &rarr; Configuracion</a>, poner <code>cluster_role=slave</code> y guardar.</li>
            <li><strong>Sacar token del slave:</strong> en la misma pantalla, copiar el <strong>Token Local</strong> (o regenerarlo y guardar).</li>
            <li><strong>Preparar el master:</strong> en su <a href="/settings/cluster#configuracion" class="text-info">Configuracion</a>, confirmar <code>cluster_role=master</code>.</li>
            <li><strong>Alta del nodo:</strong> ir en el master a <a href="/settings/cluster#nodos" class="text-info">Nodos &rarr; Anadir Nodo</a>.</li>
            <li><strong>Rellenar campos:</strong>
                <code>Nombre</code>, <code>URL API</code> (formato <code>https://IP:8444</code>), <code>Token</code> del slave, y opcionalmente <code>TLS pin</code> / <code>CA</code>.</li>
            <li><strong>Servicios:</strong> marcar <code>web</code>, <code>mail</code> o ambos segun lo que hara ese nodo.</li>
            <li><strong>Validar:</strong> pulsar <strong>Probar Conexion</strong> y luego <strong>Anadir Nodo</strong>.</li>
            <li><strong>Provisionar:</strong> ejecutar <strong>Sync Todo</strong> para crear estructura de hostings en el slave.</li>
            <li><strong>Copiar contenido:</strong> configurar <a href="/settings/cluster#archivos" class="text-info">Archivos</a> (SSH/HTTPS) y lanzar una sincronizacion inicial.</li>
        </ol>

        <div class="p-3 rounded" style="background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);">
            <div class="small mb-2"><strong class="text-info">Donde se indica la IP del slave:</strong></div>
            <div class="small text-muted mb-0">
                Se define en el campo <strong>URL de la API</strong> al anadir el nodo en el master.
                Ejemplo: <code>https://10.10.70.12:8444</code> o <code>https://203.0.113.25:8444</code>.
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-signpost-2 me-2"></i>IP publica o privada (recomendacion)</div>
    <div class="card-body">
        <div class="small text-muted mb-2">
            <strong>Recomendado:</strong> usar <strong>IP privada de WireGuard</strong> en <code>URL API</code> y en la sincronizacion de archivos por SSH.
        </div>
        <ul class="small text-muted mb-3">
            <li><strong>Con WireGuard:</strong> usa la IP <code>10.x/172.16-31.x/192.168.x</code> del tunel. Menos exposicion y trafico interno cifrado.</li>
            <li><strong>Sin WireGuard:</strong> usa IP publica, pero restringe puerto <code>8444</code> en firewall para que solo acepte la IP del master.</li>
            <li><strong>TLS:</strong> si usas certificados internos o self-signed, configura <code>TLS pin</code> o <code>CA bundle</code> al anadir nodo.</li>
            <li><strong>Consistencia:</strong> evita mezclar IP publica/privada aleatoriamente entre pruebas; deja una ruta estable por nodo.</li>
        </ul>
        <div class="small text-muted mb-0">
            Regla rapida: si existe conectividad WireGuard entre nodos, usa siempre la IP privada del tunel.
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-check2-square me-2"></i>Checklist minima</div>
            <div class="card-body">
                <ul class="small text-muted mb-0">
                    <li>Al menos un slave en estado <code>online</code>.</li>
                    <li>Sync Todo ejecutado al menos una vez tras anadir nodo.</li>
                    <li>Sincronizacion de archivos activa y probada.</li>
                    <li>Sin errores persistentes en cola cluster.</li>
                    <li>Heartbeat reciente entre master y slave.</li>
                    <li>Failover definido si se requiere continuidad externa.</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-tools me-2"></i>Troubleshooting corto</div>
            <div class="card-body">
                <ul class="small text-muted mb-0">
                    <li><strong>Nodo offline:</strong> revisar URL API, token, TLS pin/CA y firewall.</li>
                    <li><strong>Hostings no aparecen en slave:</strong> ejecutar Sync Todo y revisar cola.</li>
                    <li><strong>Archivos desfasados:</strong> validar clave SSH, exclusiones y modo de sync.</li>
                    <li><strong>Errores recurrentes en cola:</strong> abrir logs del panel y reintentar solo tras corregir causa.</li>
                    <li><strong>Duda Promote/Demote:</strong> use Promote/Demote para rol BD; use Failover para trafico DNS.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header"><i class="bi bi-arrow-right-circle me-2"></i>Siguiente iteracion sugerida</div>
    <div class="card-body">
        <p class="small text-muted mb-0">
            Dividir esta guia en sub-guias: <code>alta de nodo</code>, <code>filesync</code>, <code>full sync</code>,
            <code>failover manual/semi/auto</code> y <code>recuperacion de errores en cola</code>.
        </p>
    </div>
</div>
