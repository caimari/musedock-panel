<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="/settings/services" class="btn btn-outline-light btn-sm <?= str_contains($pageTitle ?? '', 'Servicio') ? 'active' : '' ?>"><i class="bi bi-hdd-rack me-1"></i>Servicios</a>
    <a href="/settings/server" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Servidor' ? 'active' : '' ?>"><i class="bi bi-server me-1"></i>Servidor</a>
    <a href="/settings/php" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'PHP Settings' ? 'active' : '' ?>"><i class="bi bi-filetype-php me-1"></i>PHP</a>
    <a href="/settings/ssl" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'SSL/TLS' ? 'active' : '' ?>"><i class="bi bi-shield-lock me-1"></i>SSL/TLS</a>
    <a href="/settings/security" class="btn btn-outline-light btn-sm <?= ($pageTitle ?? '') === 'Seguridad' ? 'active' : '' ?>"><i class="bi bi-lock me-1"></i>Seguridad</a>
    <a href="/settings/crons" class="btn btn-outline-light btn-sm <?= str_contains($pageTitle ?? '', 'Cron') ? 'active' : '' ?>"><i class="bi bi-clock-history me-1"></i>Cron</a>
    <a href="/settings/caddy" class="btn btn-outline-light btn-sm <?= str_contains($pageTitle ?? '', 'Caddy') ? 'active' : '' ?>"><i class="bi bi-globe me-1"></i>Caddy</a>
</div>
