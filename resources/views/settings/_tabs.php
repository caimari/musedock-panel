<?php if (\MuseDockPanel\Settings::get('cluster_role', 'standalone') === 'slave'): ?>
<div class="alert mb-3 py-2 px-3 small d-flex align-items-center" style="background:rgba(56,189,248,0.08);border:1px solid rgba(56,189,248,0.2);color:#94a3b8;">
    <i class="bi bi-lock me-2" style="color:#38bdf8;"></i>
    <span><strong style="color:#38bdf8;">Servidor Slave</strong> — Los ajustes se pueden consultar pero no editar. Los cambios deben realizarse en el Master.</span>
</div>
<?php endif; ?>
<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="/settings/server" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Servidor' ? 'active' : '' ?>"><i class="bi bi-server me-1"></i>Servidor</a>
    <a href="/settings/php" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'PHP Settings' ? 'active' : '' ?>"><i class="bi bi-filetype-php me-1"></i>PHP</a>
    <a href="/settings/ssl" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'SSL/TLS' ? 'active' : '' ?>"><i class="bi bi-shield-lock me-1"></i>SSL/TLS</a>
    <a href="/settings/security" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Seguridad' ? 'active' : '' ?>"><i class="bi bi-lock me-1"></i>Seguridad</a>
    <a href="/settings/fail2ban" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Fail2Ban' ? 'active' : '' ?>"><i class="bi bi-shield-exclamation me-1"></i>Fail2Ban</a>
    <a href="/settings/crons" class="btn btn-outline-light btn-sm <?= str_contains($pageTitle ?? '', 'Cron') ? 'active' : '' ?>"><i class="bi bi-clock-history me-1"></i>Cron</a>
    <a href="/settings/caddy" class="btn btn-outline-light btn-sm <?= str_contains($pageTitle ?? '', 'Caddy') ? 'active' : '' ?>"><i class="bi bi-globe me-1"></i>Caddy</a>
    <a href="/settings/logs" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Visor de Logs' ? 'active' : '' ?>"><i class="bi bi-terminal me-1"></i>Logs</a>
    <a href="/settings/replication" class="btn btn-outline-light btn-sm <?= str_contains($pageTitle ?? '', 'Replicaci') ? 'active' : '' ?>"><i class="bi bi-arrow-repeat me-1"></i>Replicacion</a>
    <a href="/settings/firewall" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Firewall' ? 'active' : '' ?>"><i class="bi bi-shield-fill me-1"></i>Firewall</a>
    <a href="/settings/wireguard" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'WireGuard' ? 'active' : '' ?>"><i class="bi bi-hdd-network me-1"></i>WireGuard</a>
    <a href="/settings/notifications" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Notificaciones' ? 'active' : '' ?>"><i class="bi bi-bell me-1"></i>Notificaciones</a>
    <a href="/settings/cluster" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Cluster' ? 'active' : '' ?>"><i class="bi bi-diagram-3 me-1"></i>Cluster</a>
    <a href="/settings/proxy-routes" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Proxy Routes' ? 'active' : '' ?>"><i class="bi bi-diagram-2 me-1"></i>Proxy Routes</a>
    <a href="/settings/cloudflare-dns" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Cloudflare DNS' ? 'active' : '' ?>" style="<?= ($pageTitle ?? '') === 'Cloudflare DNS' ? '' : 'border-color:#f97316;color:#f97316;' ?>"><i class="bi bi-cloud-fill me-1"></i>Cloudflare DNS</a>
    <a href="/settings/health" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'System Health' ? 'active' : '' ?>"><i class="bi bi-heart-pulse me-1"></i>System Health</a>
    <a href="/settings/updates" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Updates' ? 'active' : '' ?>"><i class="bi bi-cloud-arrow-down me-1"></i>Updates</a>
    <a href="/settings/services" class="btn btn-outline-light btn-sm <?= str_contains($pageTitle ?? '', 'Servicio') ? 'active' : '' ?>"><i class="bi bi-hdd-rack me-1"></i>Servicios</a>
    <a href="/settings/portal" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Portal Clientes' ? 'active' : '' ?>" style="<?= ($pageTitle ?? '') === 'Portal Clientes' ? '' : 'border-color:#a855f7;color:#a855f7;' ?>"><i class="bi bi-people me-1"></i>Portal Clientes</a>
    <a href="/settings/federation" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Federation' ? 'active' : '' ?>" style="<?= ($pageTitle ?? '') === 'Federation' ? '' : 'border-color:#10b981;color:#10b981;' ?>"><i class="bi bi-arrow-left-right me-1"></i>Federation</a>
    <a href="/docs/mail-modes" class="btn btn-outline-light btn-sm <?= str_starts_with($pageTitle ?? '', 'Docs') ? 'active' : '' ?>"><i class="bi bi-journal-text me-1"></i>Docs Mail</a>
</div>
