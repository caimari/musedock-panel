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
                        <small class="text-muted">Si pones un dominio, Caddy generara certificado SSL automatico. Vacio = acceso por IP.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Protocolo</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="panel_protocol" value="http" id="proto_http"
                                    <?= ($settings['panel_protocol'] ?? 'http') === 'http' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="proto_http">HTTP</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="panel_protocol" value="https" id="proto_https"
                                    <?= ($settings['panel_protocol'] ?? 'http') === 'https' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="proto_https">HTTPS</label>
                            </div>
                        </div>
                        <small class="text-muted">HTTPS requiere un dominio configurado arriba o certificado autofirmado.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">URL de acceso actual</label>
                        <?php
                        $proto = $settings['panel_protocol'] ?? 'http';
                        $host = !empty($settings['panel_hostname']) ? $settings['panel_hostname'] : $serverIp;
                        $panelUrl = "{$proto}://{$host}:{$panelPort}";
                        ?>
                        <div class="input-group">
                            <input type="text" class="form-control" value="<?= View::e($panelUrl) ?>" disabled>
                            <button class="btn btn-outline-light" type="button" onclick="navigator.clipboard.writeText('<?= View::e($panelUrl) ?>')"><i class="bi bi-clipboard"></i></button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Guardar</button>
                </form>
            </div>
        </div>
    </div>
</div>
