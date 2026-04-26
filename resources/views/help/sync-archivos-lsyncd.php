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
