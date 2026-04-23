<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<div class="row g-3">
    <!-- Server Info (read-only) -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> Informacion del Servidor</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted" style="width:40%">Hostname</td><td><strong><?= View::e($hostname) ?></strong></td></tr>
                    <tr><td class="text-muted">OS</td><td><strong><?= View::e($distro) ?></strong></td></tr>
                    <tr><td class="text-muted">Kernel</td><td><?= View::e($os) ?></td></tr>
                    <tr><td class="text-muted">IP del servidor</td><td>
                        <strong><?= View::e($serverIp) ?></strong>
                        <button class="btn btn-sm btn-outline-light ms-2 py-0" onclick="navigator.clipboard.writeText('<?= View::e($serverIp) ?>')"><i class="bi bi-clipboard"></i></button>
                    </td></tr>
                    <tr><td class="text-muted">Uptime</td><td><?= View::e($uptime) ?></td></tr>
                    <tr><td class="text-muted">PHP</td><td><?= PHP_VERSION ?></td></tr>
                    <tr><td class="text-muted">Panel</td><td>v<?= View::e($panelVersion ?? '0.1.0') ?></td></tr>
                    <tr><td class="text-muted">Timezone</td><td><?= View::e($currentTz) ?> — <?= date('H:i:s T') ?></td></tr>
                    <tr>
                        <td class="text-muted">NTP</td>
                        <td>
                            <?php if ($ntpActive && $ntpSynced): ?>
                                <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle me-1"></i>Synchronized</span>
                            <?php elseif ($ntpActive): ?>
                                <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i>Active (not synced)</span>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                            <?php endif; ?>
                            <?php if (!empty($ntpServer)): ?>
                                <small class="text-muted ms-2"><?= View::e($ntpServer) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Panel URL & Timezone (editable) -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-gear me-1"></i> Configuracion del Panel</div>
            <div class="card-body">
                <form method="POST" action="/settings/server/save">
                    <?= View::csrf() ?>

                    <div class="mb-3">
                        <label class="form-label">Zona horaria del servidor</label>
                        <select name="timezone" class="form-select">
                            <?php foreach ($timezones as $tz): ?>
                            <option value="<?= View::e($tz) ?>" <?= $tz === $currentTz ? 'selected' : '' ?>><?= View::e($tz) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Actual: <?= View::e($currentTz) ?> — <?= date('H:i:s T') ?></small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Dominio del panel <small class="text-muted">(opcional)</small></label>
                        <input type="text" name="panel_hostname" class="form-control" value="<?= View::e($settings['panel_hostname'] ?? '') ?>" placeholder="panel.ejemplo.com">
                        <small class="text-muted">Al guardar, el panel crea/actualiza la ruta HTTPS en Caddy para este dominio. Si el DNS apunta aqui, el certificado publico se emite automaticamente.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Protocolo</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <?php
                                    // Auto-detect protocol from real connection (X-Forwarded-Proto from Caddy, or HTTPS flag)
                                    $detectedProto = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                                        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                                        ? 'https' : 'http';
                                    $currentProto = $detectedProto;
                                ?>
                                <input class="form-check-input" type="radio" name="panel_protocol" value="http" id="proto_http"
                                    <?= $currentProto === 'http' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="proto_http">HTTP</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="panel_protocol" value="https" id="proto_https"
                                    <?= $currentProto === 'https' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="proto_https">HTTPS</label>
                            </div>
                        </div>
                        <small class="text-muted">HTTPS requiere un dominio configurado arriba o certificado autofirmado.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">URL de acceso actual</label>
                        <?php
                        $host = !empty($settings['panel_hostname']) ? $settings['panel_hostname'] : $serverIp;
                        $panelUrl = !empty($settings['panel_hostname'])
                            ? "https://{$host}"
                            : "https://{$host}:{$panelPort}";
                        $fallbackUrl = "https://{$host}:{$panelPort}";
                        ?>
                        <div class="input-group">
                            <input type="text" class="form-control" value="<?= View::e($panelUrl) ?>" disabled>
                            <button class="btn btn-outline-light" type="button" onclick="navigator.clipboard.writeText('<?= View::e($panelUrl) ?>')"><i class="bi bi-clipboard"></i></button>
                        </div>
                        <?php if (!empty($settings['panel_hostname'])): ?>
                            <small class="text-muted d-block mt-1">Fallback emergencia: <?= View::e($fallbackUrl) ?> (certificado interno).</small>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Guardar</button>
                </form>
            </div>
        </div>
    </div>
</div>
