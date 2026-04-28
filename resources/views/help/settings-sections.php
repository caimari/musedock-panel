<?php use MuseDockPanel\View; ?>
<?php
$sections = [
    [
        'title' => 'Servidor',
        'url' => '/settings/server',
        'doc_url' => '/docs/settings/server',
        'icon' => 'bi-server',
        'summary' => 'Identidad del nodo, hostname, TLS del panel y ajustes globales del host.',
    ],
    [
        'title' => 'PHP',
        'url' => '/settings/php',
        'doc_url' => '/docs/settings/php',
        'icon' => 'bi-filetype-php',
        'summary' => 'Versiones PHP, limites y parametros base para las apps.',
    ],
    [
        'title' => 'SSL/TLS',
        'url' => '/settings/ssl',
        'doc_url' => '/docs/settings/ssl-tls',
        'icon' => 'bi-shield-lock',
        'summary' => 'Certificados, HTTPS y estado de cifrado de dominios.',
    ],
    [
        'title' => 'Seguridad',
        'url' => '/settings/security',
        'doc_url' => '/docs/settings/security',
        'icon' => 'bi-lock',
        'summary' => 'Hardening del host, puertos esperados, MFA admin y controles de acceso.',
    ],
    [
        'title' => 'Fail2Ban',
        'url' => '/settings/fail2ban',
        'doc_url' => '/docs/settings/fail2ban',
        'icon' => 'bi-shield-exclamation',
        'summary' => 'Bloqueo automatico de IPs por intentos fallidos o abuso.',
    ],
    [
        'title' => 'Cron',
        'url' => '/settings/crons',
        'doc_url' => '/docs/settings/cron',
        'icon' => 'bi-clock-history',
        'summary' => 'Tareas programadas del sistema y jobs de mantenimiento.',
    ],
    [
        'title' => 'Caddy',
        'url' => '/settings/caddy',
        'doc_url' => '/docs/settings/caddy',
        'icon' => 'bi-globe',
        'summary' => 'Estado y parametros del web server/reverse proxy Caddy.',
    ],
    [
        'title' => 'Logs',
        'url' => '/settings/logs',
        'doc_url' => '/docs/settings/logs',
        'icon' => 'bi-terminal',
        'summary' => 'Visor de logs para diagnostico rapido de errores y eventos.',
    ],
    [
        'title' => 'Replicacion',
        'url' => '/settings/replication',
        'doc_url' => '/docs/settings/replication',
        'icon' => 'bi-arrow-repeat',
        'summary' => 'Estado y configuracion de replicacion entre nodos.',
    ],
    [
        'title' => 'Firewall',
        'url' => '/settings/firewall',
        'doc_url' => '/docs/settings/firewall',
        'icon' => 'bi-shield-fill',
        'summary' => 'Reglas de red, auditoria, snapshots, import/export y lockdown temporal.',
    ],
    [
        'title' => 'WireGuard',
        'url' => '/settings/wireguard',
        'doc_url' => '/docs/settings/wireguard',
        'icon' => 'bi-hdd-network',
        'summary' => 'Tuneles privados entre nodos para trafico interno seguro.',
    ],
    [
        'title' => 'Notificaciones',
        'url' => '/settings/notifications',
        'doc_url' => '/docs/settings/notifications',
        'icon' => 'bi-bell',
        'summary' => 'Canales de alerta y eventos de seguridad (firewall, reboot, hardening, drift, exposicion, login anomalo).',
    ],
    [
        'title' => 'Cluster',
        'url' => '/settings/cluster',
        'doc_url' => '/docs/settings/cluster',
        'icon' => 'bi-diagram-3',
        'summary' => 'Topologia, rol del nodo y coordinacion Master/Slave.',
    ],
    [
        'title' => 'Cluster Archivos (Sync lsyncd)',
        'url' => '/settings/cluster#archivos',
        'doc_url' => '/docs/sync-archivos-lsyncd',
        'icon' => 'bi-files',
        'summary' => 'Sync de archivos, lsyncd, SSH inter-nodo y diagnostico de Sync degradado.',
    ],
    [
        'title' => 'Proxy Routes',
        'url' => '/settings/proxy-routes',
        'doc_url' => '/docs/settings/proxy-routes',
        'icon' => 'bi-diagram-2',
        'summary' => 'Rutas proxy para exponer servicios internos por dominio.',
    ],
    [
        'title' => 'DNS',
        'url' => '/settings/dns',
        'doc_url' => '/docs/settings/dns',
        'icon' => 'bi-globe2',
        'summary' => 'Proveedor DNS-01 del panel, modulos Caddy y credenciales API para certificados sin abrir 80/443.',
    ],
    [
        'title' => 'Cloudflare DNS',
        'url' => '/settings/cloudflare-dns',
        'doc_url' => '/docs/settings/cloudflare-dns',
        'icon' => 'bi-cloud-fill',
        'summary' => 'Gestion de zonas y registros DNS via API de Cloudflare.',
    ],
    [
        'title' => 'System Health',
        'url' => '/settings/health',
        'doc_url' => '/docs/settings/system-health',
        'icon' => 'bi-heart-pulse',
        'summary' => 'Metricas de salud del sistema, servicios y recursos.',
    ],
    [
        'title' => 'Updates',
        'url' => '/settings/updates',
        'doc_url' => '/docs/settings/updates',
        'icon' => 'bi-cloud-arrow-down',
        'summary' => 'Actualizacion del panel y estado de versiones instaladas.',
    ],
    [
        'title' => 'Servicios',
        'url' => '/settings/services',
        'doc_url' => '/docs/settings/services',
        'icon' => 'bi-hdd-rack',
        'summary' => 'Arranque/parada y estado de servicios del sistema.',
    ],
    [
        'title' => 'Portal Clientes',
        'url' => '/settings/portal',
        'doc_url' => '/docs/settings/portal-clientes',
        'icon' => 'bi-people',
        'summary' => 'Configuracion del portal orientado a clientes finales.',
    ],
    [
        'title' => 'Federation',
        'url' => '/settings/federation',
        'doc_url' => '/docs/settings/federation',
        'icon' => 'bi-arrow-left-right',
        'summary' => 'Integracion y enlaces entre paneles o nodos federados.',
    ],
];
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Settings: mapa base</h4>
        <div class="text-muted small">Primera version de referencia. Luego ampliaremos cada seccion con pasos detallados.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm">
            <i class="bi bi-journal-text me-1"></i> Volver a Docs
        </a>
        <a href="/settings/server" class="btn btn-outline-info btn-sm">
            <i class="bi bi-gear me-1"></i> Abrir Settings
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <p class="text-muted small mb-0">
            Esta guia resume para que sirve cada pantalla de <code>Settings</code>. Es un baseline operativo para el equipo.
            El titulo de cada tarjeta abre su documentacion (si ya existe) y el boton abre la pantalla funcional del panel.
        </p>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(56,189,248,.35);">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="small text-muted">
            Para el mapa completo de seguridad (hardening, drift, exposicion, lockdown y MFA), revisa la guia especial:
            <code>/docs/security-operations</code>.
        </div>
        <a href="/docs/security-operations" class="btn btn-outline-info btn-sm">
            <i class="bi bi-shield-lock me-1"></i> Abrir guia de seguridad
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.35);">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="small text-muted">
            Para configurar el panel con dominio/subdominio, certificados publicos, DNS-01, proxy naranja y puertos 80/443 cerrados:
            <code>/docs/panel-tls-dns01</code>.
        </div>
        <a href="/docs/panel-tls-dns01" class="btn btn-outline-success btn-sm">
            <i class="bi bi-shield-lock me-1"></i> Guia TLS del panel
        </a>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($sections as $section): ?>
        <div class="col-lg-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex align-items-start gap-3 mb-2">
                        <div class="rounded d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(56,189,248,.12);color:#38bdf8;">
                            <i class="bi <?= View::e($section['icon']) ?>"></i>
                        </div>
                        <div>
                            <?php if (!empty($section['doc_url'])): ?>
                                <h5 class="mb-1">
                                    <a href="<?= View::e($section['doc_url']) ?>" class="text-decoration-none text-light">
                                        <?= View::e($section['title']) ?>
                                    </a>
                                </h5>
                            <?php else: ?>
                                <h5 class="mb-1"><?= View::e($section['title']) ?></h5>
                            <?php endif; ?>
                            <code class="small"><?= View::e($section['url']) ?></code>
                        </div>
                    </div>
                    <p class="text-muted small mb-3 flex-grow-1"><?= View::e($section['summary']) ?></p>
                    <div class="d-flex gap-2">
                        <a href="<?= View::e($section['doc_url']) ?>" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-journal-text me-1"></i> Ver doc
                        </a>
                        <a href="<?= View::e($section['url']) ?>" class="btn btn-outline-light btn-sm">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Abrir seccion
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
