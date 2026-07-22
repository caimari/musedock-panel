<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-shield-lock me-2 text-success"></i>Seguridad y anti-abuso del correo</h4>
        <div class="text-muted small">Cómo el servidor impide que se use para enviar spam, y qué protege cada capa.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a>
        <a href="/mail?tab=antispam" class="btn btn-outline-warning btn-sm"><i class="bi bi-shield me-1"></i>Abrir Anti-spam</a>
    </div>
</div>

<!-- La muralla principal -->
<div class="card mb-4" style="border-color:rgba(70,201,138,.35);">
    <div class="card-header"><i class="bi bi-shield-check me-2 text-success"></i>La muralla principal: autenticación obligatoria</div>
    <div class="card-body">
        <p class="small mb-2"><strong>Pregunta clave:</strong> ¿si alguien no tiene la contraseña de un buzón, puede enviar spam a través del servidor?</p>
        <p class="small mb-2"><span class="badge bg-success">Respuesta: NO</span> Postfix exige autenticación para enviar. La regla de los puertos de envío (587/465) es:</p>
        <pre class="small mb-2" style="background:#12181f;border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:12px;color:#c7d2dc;">smtpd_recipient_restrictions = permit_sasl_authenticated, reject</pre>
        <p class="small mb-2 text-muted">Traducción: <em>«solo permite enviar a quien se ha autenticado; a todos los demás, RECHAZA»</em>. Sin contraseña → rechazado → no envía nada.</p>
        <div class="alert alert-success small mb-0">
            <i class="bi bi-check-circle me-1"></i>El servidor <strong>NO es un «open relay»</strong> (el peor fallo de un servidor de correo, que dejaría a cualquiera enviar spam por ti). Esta protección está siempre activa.
        </div>
    </div>
</div>

<!-- Las capas extra -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-layers me-2"></i>Capas extra (para el caso de contraseña robada)</div>
    <div class="card-body">
        <p class="small text-muted mb-3">La muralla impide el ataque <strong>sin contraseña</strong>. Pero, ¿y si un hacker <strong>roba</strong> la contraseña de un buzón (phishing, malware en el PC del cliente)? Para ese caso hay capas adicionales, activables en <a href="/mail?tab=antispam" class="text-info">Mail → Anti-spam</a>:</p>
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th class="ps-3">Capa</th><th>Qué hace</th><th>Protege contra</th></tr></thead>
            <tbody class="small">
                <tr>
                    <td class="ps-3 fw-semibold">Autenticación obligatoria</td>
                    <td>Sin contraseña, no se envía</td>
                    <td><span class="badge bg-success">Siempre activa</span> — ataque sin contraseña</td>
                </tr>
                <tr>
                    <td class="ps-3 fw-semibold">fail2ban</td>
                    <td>Banea IPs que fallan la contraseña varias veces (5 intentos en 10 min → 1h de baneo)</td>
                    <td>Fuerza bruta que <em>intenta adivinar</em> contraseñas</td>
                </tr>
                <tr>
                    <td class="ps-3 fw-semibold">Límite de tasa</td>
                    <td>Máximo de correos/hora <strong>por buzón</strong></td>
                    <td>Contraseña <em>ya robada</em>: limita el daño</td>
                </tr>
                <tr>
                    <td class="ps-3 fw-semibold">Lista blanca de dominios</td>
                    <td>Solo ciertos dominios pueden enviar</td>
                    <td>Restringir el envío a dominios concretos</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- El límite de tasa explicado -->
<div class="card mb-4" style="border-color:rgba(56,189,248,.24);">
    <div class="card-header"><i class="bi bi-speedometer2 me-2"></i>El límite de tasa, explicado</div>
    <div class="card-body">
        <p class="small mb-2">El límite de tasa se aplica <strong>POR BUZÓN</strong> (cada cuenta tiene su propio contador), no por dominio ni global:</p>
        <pre class="small mb-2" style="background:#12181f;border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:12px;color:#c7d2dc;">antoni@dominio.com   → máx 100 correos/hora
info@dominio.com     → máx 100 correos/hora   (contador separado)
hello@otrodominio.com → máx 100 correos/hora   (contador separado)</pre>
        <ul class="small mb-2" style="line-height:1.8;">
            <li><strong>Qué pasa al superarlo</strong>: ese buzón no puede enviar más durante esa hora; al pasar la hora, se resetea.</li>
            <li><strong>Por qué protege</strong>: si roban la contraseña de un buzón e intentan enviar 10.000 spams, <strong>solo saldrían 100 la primera hora</strong> → lo notas y cortas, con el daño acotado.</li>
            <li><strong>Ajustable</strong>: el valor por defecto (100/hora) se puede cambiar globalmente, por dominio o por buzón desde el panel.</li>
        </ul>
        <div class="alert alert-info small mb-0"><i class="bi bi-lightbulb me-1"></i>Es un <strong>techo de daño</strong>: no impide el robo de la contraseña, pero convierte «10.000 spams» en «100 y te enteras».</div>
    </div>
</div>

<!-- Cluster / slave -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>En un cluster: mismas protecciones en todos los nodos</div>
    <div class="card-body">
        <p class="small mb-2">Cuando activas una protección (fail2ban, límite de tasa, lista blanca) en el <strong>master</strong>, se <strong>propaga automáticamente a los nodos slave</strong> por el canal del cluster:</p>
        <ul class="small mb-2" style="line-height:1.8;">
            <li><strong>Políticas por buzón</strong> (modo de envío, límite por cuenta): viven en la base de datos, que el slave ya lee del master.</li>
            <li><strong>fail2ban y límite de tasa global</strong>: son configuración del sistema/Rspamd, local a cada nodo, así que el panel las <strong>reenvía al slave</strong> al activarlas (encoladas; se reintentan si el nodo está caído).</li>
        </ul>
        <div class="alert alert-info small mb-0"><i class="bi bi-shield-check me-1"></i>Así, si el master cae y se promueve el slave, el nodo promovido tiene <strong>exactamente las mismas protecciones anti-abuso</strong>. Son réplicas de seguridad reales.</div>
    </div>
</div>

<!-- Recomendación -->
<div class="alert alert-warning">
    <i class="bi bi-check2-square me-1"></i>
    <strong>Recomendación</strong>: la muralla principal ya te protege del ataque sin contraseña. Para cubrir también el caso de <strong>contraseña robada</strong> (habitual), activa en <a href="/mail?tab=antispam" class="alert-link">Anti-spam</a> el <strong>fail2ban</strong> (frena la fuerza bruta) y el <strong>límite de tasa</strong> (acota el daño). Se propagan a todo el cluster.
</div>
