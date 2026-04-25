<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Replica espejo PostgreSQL (Master/Slave)</h4>
        <div class="text-muted small">Guia especial operativa para montar espejo de bases de datos entre nodos.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm">
            <i class="bi bi-journal-text me-1"></i> Volver a Docs
        </a>
        <a href="/settings/replication" class="btn btn-outline-info btn-sm">
            <i class="bi bi-arrow-repeat me-1"></i> Abrir Replication
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(251,191,36,.35);">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Antes de tocar nada</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>En modo espejo, convertir un nodo a <code>slave</code> puede borrar BDs locales para resembrar desde el master.</li>
            <li>Haz backup/snapshot antes de cada cambio de rol.</li>
            <li>Usa ventana de mantenimiento para evitar escritura concurrente durante el corte.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-info-circle me-2"></i>Que se replica y que no</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Se replica el PostgreSQL de datos de hosting (el que usas para BDs de clientes).</li>
            <li>No se replica el PostgreSQL interno del panel (<code>musedock_panel</code>, normalmente en puerto <code>5433</code>), que es propio de cada nodo.</li>
            <li>La configuracion de panel/cluster se sincroniza por sus propios mecanismos, no por espejo de ese PostgreSQL interno.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-check2-square me-2"></i>Prerequisitos globales</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <li>Nodos con version mayor de PostgreSQL compatible (idealmente igual).</li>
            <li>Conectividad estable entre nodos por red privada (recomendado WireGuard).</li>
            <li>Firewall permitiendo puerto PostgreSQL solo entre IPs privadas de cluster.</li>
            <li>Espacio libre suficiente en slave para copia inicial completa.</li>
            <li>NTP/hora sincronizada en ambos nodos.</li>
        </ol>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-server me-2"></i>Paso a paso en Master</div>
            <div class="card-body">
                <ol class="small text-muted mb-0">
                    <li>Define IP de replicacion (mejor IP privada WireGuard del slave).</li>
                    <li>En <code>/settings/replication</code>, deja este nodo como <code>master</code> para PostgreSQL.</li>
                    <li>Crea o valida usuario de replicacion (rol con permiso <code>REPLICATION</code>).</li>
                    <li>Verifica parametros base: <code>wal_level=replica</code>, <code>max_wal_senders</code>, <code>max_replication_slots</code>.</li>
                    <li>Permite la IP del slave en <code>pg_hba.conf</code> para conexion de replicacion.</li>
                    <li>Recarga/reinicia PostgreSQL y valida que acepta conexiones de replica.</li>
                </ol>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-hdd-network me-2"></i>Paso a paso en Slave</div>
            <div class="card-body">
                <ol class="small text-muted mb-0">
                    <li>Haz backup local si el slave tenia BDs utiles (se pueden sobrescribir).</li>
                    <li>En <code>/settings/replication</code>, configura este nodo como <code>slave</code> y apunta al master.</li>
                    <li>Usa host/IP privada del master (WireGuard recomendado) y credenciales de replicacion.</li>
                    <li>Ejecuta inicializacion de replica (basebackup) desde el panel.</li>
                    <li>Arranca/valida streaming: estado <code>online</code> y lag bajo.</li>
                    <li>Confirma que no hay escritura directa no autorizada en slave.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4 mb-4">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Mas de un slave espejo</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Si, PostgreSQL permite varios slaves en paralelo contra un mismo master (streaming replication).
        </p>
        <ul class="small text-muted mb-0">
            <li>Ajusta <code>max_wal_senders</code> y <code>max_replication_slots</code> segun numero de replicas.</li>
            <li>Controla ancho de banda y I/O: cada slave consume WAL y transferencia propia.</li>
            <li>Monitorea lag por nodo; no todos los slaves iran exactamente al mismo ritmo.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-bug me-2"></i>Cuando se puede romper la base de datos</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Promocionar/demover nodos sin orden o con split-brain.</li>
            <li>Convertir a slave un nodo con datos que no has respaldado (sobrescritura).</li>
            <li>Configurar master por IP publica inestable o con NAT/ACL inconsistente.</li>
            <li>Mezclar versiones mayores incompatibles de PostgreSQL.</li>
            <li>Editar <code>pg_hba.conf</code>/<code>postgresql.conf</code> con valores invalidos sin validar reinicio.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-folder2-open me-2"></i>Archivos root y red (si toca ajuste manual)</div>
    <div class="card-body">
        <p class="small text-muted mb-2">Dependiendo de distro/version, revisa estos ficheros como root:</p>
        <ul class="small text-muted">
            <li><code>/etc/postgresql/&lt;version&gt;/main/postgresql.conf</code></li>
            <li><code>/etc/postgresql/&lt;version&gt;/main/pg_hba.conf</code></li>
            <li><code>/var/lib/postgresql/&lt;version&gt;/main/</code> (data dir, no tocar sin backup)</li>
        </ul>
        <div class="small text-muted mb-2">Ejemplo de red recomendada:</div>
        <pre class="small p-3 rounded mb-0" style="background:#0f172a;color:#e2e8f0;">MASTER (WG): 10.10.70.1
SLAVE-1 (WG): 10.10.70.2
SLAVE-2 (WG): 10.10.70.3

pg_hba (master):
host    replication    repl_user    10.10.70.2/32    md5
host    replication    repl_user    10.10.70.3/32    md5</pre>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>Checklist final de validacion</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <li>Replica en <code>streaming</code> y sin errores en logs.</li>
            <li>Lag aceptable en carga real.</li>
            <li>Backups confirmados antes y despues del cambio.</li>
            <li>Runbook de failover/promocion documentado y probado.</li>
            <li>Queda claro para el equipo: PostgreSQL del panel es local por nodo y no forma parte de este espejo.</li>
        </ol>
    </div>
</div>
