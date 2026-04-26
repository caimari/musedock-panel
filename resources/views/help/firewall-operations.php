<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Firewall: operaciones completas</h4>
        <div class="text-muted small">Snapshots completos, export/import JSON y verificacion real del estado aplicado.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver a Docs
        </a>
        <a href="/settings/firewall" class="btn btn-outline-info btn-sm">
            <i class="bi bi-shield-fill me-1"></i> Abrir Firewall
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(56,189,248,.35);">
    <div class="card-header"><i class="bi bi-info-circle me-2 text-info"></i>Objetivo</div>
    <div class="card-body">
        <p class="small text-muted mb-0">
            Esta guia cubre el flujo seguro para cambios de firewall desde el panel: guardar estado completo, aplicar cambios,
            exportar/importar entre nodos y validar que el sistema real coincide con lo mostrado en pantalla.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-layers me-2"></i>Diferencia clave: Preset vs Snapshot</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li><strong>Preset de regla:</strong> guarda una regla individual para reutilizarla.</li>
            <li><strong>Snapshot completo:</strong> guarda toda la configuracion activa del firewall (<code>iptables-save</code>) para restauracion total.</li>
            <li><strong>Export JSON:</strong> paquete transportable para importar en otro nodo (append o replace).</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-lightning-charge me-2"></i>Flujo recomendado (produccion)</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <li>Entrar a <a href="/settings/firewall" class="text-info">Settings &rarr; Firewall</a>.</li>
            <li>Guardar un <strong>snapshot completo</strong> antes de tocar reglas.</li>
            <li>Aplicar cambios por bloques pequenos y verificar acceso SSH/panel.</li>
            <li>Si todo queda correcto, exportar JSON para replicar en otros nodos.</li>
            <li>En el nodo destino, importar en modo <code>append</code> o <code>replace</code> segun el caso.</li>
        </ol>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-terminal me-2"></i>Comandos utiles de verificacion</div>
    <div class="card-body">
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;"># 1) Ver reglas efectivas en orden
iptables -L -n --line-numbers

# 2) Ver politicas por defecto (INPUT/FORWARD/OUTPUT)
iptables -S | grep "^-P"

# 3) Detectar apertura global peligrosa
iptables -S | grep -E "ACCEPT.*0.0.0.0/0.*0.0.0.0/0"

# 4) Revisar SSH publicado
iptables -L INPUT -n | grep dpt:22

# 5) Puertos escuchando realmente (servicios)
ss -tuln

# 6) Estado de Fail2Ban (si aplica)
fail2ban-client status</pre>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.35);">
    <div class="card-header"><i class="bi bi-arrow-left-right me-2 text-success"></i>Exportar/Importar entre nodos</div>
    <div class="card-body">
        <ul class="small text-muted mb-3">
            <li><strong>Exportar:</strong> genera JSON con reglas, presets y snapshots completos disponibles.</li>
            <li><strong>Importar append:</strong> suma contenido sin vaciar configuracion previa.</li>
            <li><strong>Importar replace:</strong> reemplaza estado actual por el bloque importado (usar solo con validacion previa).</li>
        </ul>
        <div class="small text-muted mb-0">
            En acciones sensibles (guardar/aplicar/eliminar/importar) se solicita password admin para evitar cambios accidentales.
        </div>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(245,158,11,.35);">
    <div class="card-header"><i class="bi bi-hdd-network me-2 text-warning"></i>Gestion IPv6 por interfaz</div>
    <div class="card-body">
        <ul class="small text-muted mb-3">
            <li>En <code>/settings/firewall</code> puedes activar o desactivar <strong>solo IPv6</strong> por interfaz desde la tabla de red.</li>
            <li>IPv4 no se modifica en esa acción.</li>
            <li>La operación pide password admin y persiste en <code>/etc/sysctl.d/99-musedock-ipv6.conf</code>.</li>
        </ul>
        <div class="small text-muted mb-0">
            Si IPv6 está activa y no hay protección IPv6 efectiva, la auditoría muestra alerta con fix directo:
            <strong>Bloquear IPv6 por defecto</strong> (políticas INPUT/FORWARD en DROP con baseline seguro).
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-check2-square me-2"></i>Checklist de cierre</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>SSH y panel accesibles desde la red administrativa.</li>
            <li>No hay reglas globales de <code>ACCEPT all</code> sin condicion.</li>
            <li>Politica esperada aplicada: normalmente <code>INPUT DROP</code> y <code>FORWARD DROP</code>.</li>
            <li>Snapshot completo actualizado y export JSON guardado.</li>
        </ul>
    </div>
</div>
