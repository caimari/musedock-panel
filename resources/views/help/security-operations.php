<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Seguridad operativa del host y panel</h4>
        <div class="text-muted small">Hardening, drift, exposicion publica, lockdown temporal y MFA admin.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver a Docs
        </a>
        <a href="/settings/security" class="btn btn-outline-info btn-sm">
            <i class="bi bi-lock me-1"></i> Abrir Seguridad
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(56,189,248,.35);">
    <div class="card-header"><i class="bi bi-info-circle me-2 text-info"></i>Que significa "fingerprint/hash del firewall cambio"</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            El monitor calcula una huella del estado real del firewall (tipo, activo/inactivo, politica INPUT, hash de reglas IPv4 e IPv6).
            Si esa huella cambia, lanza evento <code>FIREWALL_CHANGED</code>.
        </p>
        <p class="small text-muted mb-2">
            <strong>No siempre implica accion humana.</strong> Puede cambiar por tareas automaticas:
            Fail2Ban (bans/unbans), reinicios de servicio firewall, restauracion de reglas al boot, Docker/servicios de red, o recarga de UFW/iptables.
        </p>
        <p class="small text-muted mb-0">
            Tambien puede pasar en primera deteccion (baseline inicial) si antes no habia snapshot guardado.
        </p>
        <p class="small text-muted mt-2 mb-0">
            Nota tecnica: la huella ignora ruido de <code>iptables-save</code> (fecha de generacion y contadores de paquetes/bytes)
            para evitar falsos positivos por trafico normal.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Mapa de funcionalidades y secciones</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr class="text-muted">
                        <th>Funcionalidad</th>
                        <th>Donde se gestiona</th>
                        <th>Evento/alerta</th>
                        <th>Motor</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Auditoria de hardening + fix 1 clic</td>
                        <td><code>/settings/security</code></td>
                        <td><code>SECURITY_HARDENING</code></td>
                        <td>SecurityService + monitor collector</td>
                    </tr>
                    <tr>
                        <td>Drift en archivos criticos con diff</td>
                        <td><code>/settings/notifications</code> (switch de evento)</td>
                        <td><code>CONFIG_DRIFT</code></td>
                        <td>monitor collector (sshd_config + fail2ban)</td>
                    </tr>
                    <tr>
                        <td>Exposicion publica inesperada de puertos</td>
                        <td><code>/settings/security</code> (puertos esperados) + <code>/settings/notifications</code></td>
                        <td><code>PORT_EXPOSURE</code></td>
                        <td>monitor collector (ss -lnt + politica esperada)</td>
                    </tr>
                    <tr>
                        <td>Lockdown temporal de emergencia (auto-expira)</td>
                        <td><code>/settings/firewall</code></td>
                        <td><code>FIREWALL_LOCKDOWN_EXPIRED</code> / <code>FIREWALL_LOCKDOWN_ERROR</code></td>
                        <td>FirewallService + monitor collector</td>
                    </tr>
                    <tr>
                        <td>MFA obligatoria + login admin anomalo</td>
                        <td><code>/settings/security</code> (obligatoria) + <code>/profile</code> (enrolado MFA)</td>
                        <td><code>LOGIN_ANOMALY</code></td>
                        <td>AuthController + SecurityService</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(14,165,233,.35);">
    <div class="card-header"><i class="bi bi-list-check me-2 text-info"></i>Runbook rapido cuando llega FIREWALL_CHANGED</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <li>Revisar en <code>/settings/firewall</code> si el estado actual coincide con lo esperado.</li>
            <li>Comprobar en <code>/logs</code> si hubo accion <code>firewall.*</code> reciente desde panel.</li>
            <li>Validar si hubo ban/unban de Fail2Ban en el mismo intervalo.</li>
            <li>Si no cuadra, ejecutar comandos de verificacion y guardar snapshot completo.</li>
            <li>Si es incidente activo, usar lockdown temporal 10-15 min y corregir reglas.</li>
        </ol>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-terminal me-2"></i>Comandos de verificacion recomendados</div>
    <div class="card-body">
        <pre class="small p-3 rounded mb-0" style="background:#0f172a;color:#e2e8f0;"># Estado real del firewall
iptables -L -n --line-numbers
ip6tables -S

# Politicas por defecto
iptables -S | grep "^-P"

# Cambios recientes de fail2ban (si aplica)
fail2ban-client status

# Puertos realmente escuchando
ss -tuln

# Ejecutar collector manualmente para forzar chequeos
cd /opt/musedock-panel && php bin/monitor-collector.php

# Ver log del collector
tail -n 100 /opt/musedock-panel/storage/logs/monitor-collector.log</pre>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-shield-check me-2"></i>Buenas practicas</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Antes de cambios de red, guardar snapshot completo en <code>/settings/firewall</code>.</li>
            <li>Mantener activo email y switches de eventos en <code>/settings/notifications</code>.</li>
            <li>Definir bien puertos esperados en <code>/settings/security</code> para evitar ruido en <code>PORT_EXPOSURE</code>.</li>
            <li>Activar MFA para todos los admins y despues habilitar MFA obligatoria global.</li>
            <li>Actualizar todos los nodos (master y slaves) con el mismo updater para mantener reglas y watchers coherentes.</li>
        </ul>
    </div>
</div>
