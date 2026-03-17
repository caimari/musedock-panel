<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php
    $method = $settings['notify_email_method'] ?? 'smtp';
    $encryption = $settings['notify_smtp_encryption'] ?? 'tls';
    $hasSmtpPass = !empty($settings['notify_smtp_pass']);
    $hasTelegram = !empty($settings['notify_telegram_token']) && !empty($settings['notify_telegram_chat_id']);
    $hasSmtp = !empty($settings['notify_smtp_host']);
    $manualTo = $settings['notify_email_to'] ?? '';
?>

<form method="post" action="/settings/notifications/save" id="notifForm">
    <?= View::csrf() ?>

    <!-- Card 1: Metodo de Email -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-envelope me-2"></i>Configuracion de Email</div>
        <div class="card-body">
            <!-- Metodo de envio -->
            <h6 class="text-muted mb-2">Metodo de envio</h6>
            <div class="d-flex gap-3 mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="notify_email_method" id="methodSmtp"
                           value="smtp" <?= $method === 'smtp' ? 'checked' : '' ?> onchange="toggleSmtpFields()">
                    <label class="form-check-label" for="methodSmtp">
                        <i class="bi bi-hdd-rack me-1"></i>SMTP
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="notify_email_method" id="methodPhp"
                           value="php" <?= $method === 'php' ? 'checked' : '' ?> onchange="toggleSmtpFields()">
                    <label class="form-check-label" for="methodPhp">
                        <i class="bi bi-filetype-php me-1"></i>PHP mail()
                    </label>
                </div>
            </div>

            <!-- SMTP fields -->
            <div id="smtpFields" style="<?= $method === 'php' ? 'display:none;' : '' ?>">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Host SMTP</label>
                        <input type="text" name="notify_smtp_host" class="form-control"
                               value="<?= View::e($settings['notify_smtp_host'] ?? '') ?>" placeholder="smtp.ejemplo.com">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Puerto</label>
                        <input type="number" name="notify_smtp_port" class="form-control"
                               value="<?= (int)($settings['notify_smtp_port'] ?? 587) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Cifrado</label>
                        <select name="notify_smtp_encryption" class="form-select">
                            <option value="tls" <?= $encryption === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                            <option value="ssl" <?= $encryption === 'ssl' ? 'selected' : '' ?>>SSL/TLS</option>
                            <option value="none" <?= $encryption === 'none' ? 'selected' : '' ?>>Sin cifrado</option>
                        </select>
                        <small class="text-muted">STARTTLS = puertos 587, 2525. SSL = puerto 465.</small>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Usuario SMTP</label>
                        <input type="text" name="notify_smtp_user" class="form-control"
                               value="<?= View::e($settings['notify_smtp_user'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Password SMTP</label>
                        <input type="password" name="notify_smtp_pass" class="form-control"
                               placeholder="<?= $hasSmtpPass ? '••••••••' : '' ?>">
                        <?php if ($hasSmtpPass): ?>
                            <small class="text-muted">Dejar vacio para mantener la actual</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- From (both methods) -->
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Email remitente (From)</label>
                    <input type="email" name="notify_smtp_from" class="form-control"
                           value="<?= View::e($settings['notify_smtp_from'] ?? '') ?>" placeholder="<?= View::e($recipientEmail) ?>">
                    <?php if ($recipientEmail): ?>
                        <small class="text-muted">Si esta vacio, se usara <code><?= View::e($recipientEmail) ?></code> (email del admin)</small>
                    <?php else: ?>
                        <small class="text-warning">Configura tu email en <a href="/profile" class="text-info">tu perfil</a></small>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nombre remitente</label>
                    <input type="text" name="notify_smtp_from_name" class="form-control"
                           value="<?= View::e($settings['notify_smtp_from_name'] ?? '') ?>" placeholder="MuseDock Panel">
                    <small class="text-muted">Ej: "Mortadelo Master", "Filemon Slave"</small>
                </div>
            </div>

            <hr class="border-secondary">

            <!-- Destinatario -->
            <h6 class="text-muted mb-2">Destinatario</h6>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Email destinatario</label>
                    <input type="email" name="notify_email_to" class="form-control"
                           value="<?= View::e($manualTo) ?>" placeholder="Dejar vacio para usar email del admin">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div>
                        <?php if ($recipientEmail): ?>
                            <span class="text-muted small">
                                <i class="bi bi-arrow-right me-1"></i>Se enviara a:
                                <code><?= View::e($recipientEmail) ?></code>
                                <?php if ($manualTo === ''): ?>
                                    <span class="badge bg-secondary ms-1">desde perfil</span>
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="text-warning small">
                                <i class="bi bi-exclamation-triangle me-1"></i>No hay email configurado.
                                <a href="/profile" class="text-info">Configura tu email en el perfil</a>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Test Email -->
            <button type="button" class="btn btn-outline-info btn-sm" onclick="testEmail()" id="btnTestEmail">
                <i class="bi bi-send me-1"></i>Enviar email de prueba
            </button>
            <span id="testEmailResult" class="ms-2 small"></span>
        </div>
    </div>

    <!-- Card 2: Telegram -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-telegram me-2"></i>Notificaciones por Telegram</div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-5">
                    <label class="form-label">Bot Token</label>
                    <input type="text" name="notify_telegram_token" class="form-control"
                           value="<?= View::e($settings['notify_telegram_token'] ?? '') ?>" placeholder="123456:ABC-DEF...">
                    <small class="text-muted">Obtenlo desde <a href="https://t.me/BotFather" target="_blank" class="text-info">@BotFather</a></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Chat ID</label>
                    <input type="text" name="notify_telegram_chat_id" class="form-control"
                           value="<?= View::e($settings['notify_telegram_chat_id'] ?? '') ?>" placeholder="-1001234567890">
                    <small class="text-muted">ID del chat, grupo o canal</small>
                </div>
            </div>

            <!-- Test Telegram -->
            <button type="button" class="btn btn-outline-info btn-sm" onclick="testTelegram()" id="btnTestTelegram">
                <i class="bi bi-send me-1"></i>Enviar mensaje de prueba
            </button>
            <span id="testTelegramResult" class="ms-2 small"></span>
        </div>
    </div>

    <!-- Card 3: Resumen -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-info-circle me-2"></i>Resumen de canales</div>
        <div class="card-body">
            <table class="table table-sm mb-3" style="max-width:500px;">
                <tr>
                    <td class="text-muted">Email</td>
                    <td>
                        <?php if ($method === 'php'): ?>
                            <span class="badge bg-info">PHP mail()</span>
                        <?php elseif ($hasSmtp): ?>
                            <span class="badge bg-success">SMTP configurado</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">No configurado</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">Telegram</td>
                    <td>
                        <?php if ($hasTelegram): ?>
                            <span class="badge bg-success">Configurado</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">No configurado</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">Destinatario</td>
                    <td>
                        <?php if ($recipientEmail): ?>
                            <code><?= View::e($recipientEmail) ?></code>
                        <?php else: ?>
                            <span class="text-warning">Sin configurar</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <p class="text-muted small mb-3">
                <i class="bi bi-info-circle me-1"></i>Las notificaciones se envian cuando hay alertas del cluster, fallos de replicacion, nodos caidos, etc.
                Se intentara enviar por todos los canales configurados.
            </p>

            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-circle me-1"></i>Guardar Configuracion
            </button>
        </div>
    </div>
</form>

<script>
function toggleSmtpFields() {
    var smtpFields = document.getElementById('smtpFields');
    var isSmtp = document.getElementById('methodSmtp').checked;
    smtpFields.style.display = isSmtp ? '' : 'none';
}

function testEmail() {
    var btn = document.getElementById('btnTestEmail');
    var result = document.getElementById('testEmailResult');
    btn.disabled = true;
    result.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Enviando...</span>';

    // Save form first, then test
    var formData = new FormData(document.getElementById('notifForm'));

    fetch('/settings/notifications/test-email', {
        method: 'POST',
        body: formData,
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        if (data.ok) {
            result.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + data.message + '</span>';
        } else {
            result.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + data.message + '</span>';
        }
    })
    .catch(function() {
        btn.disabled = false;
        result.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Error de conexion</span>';
    });
}

function testTelegram() {
    var btn = document.getElementById('btnTestTelegram');
    var result = document.getElementById('testTelegramResult');
    btn.disabled = true;
    result.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Enviando...</span>';

    var formData = new FormData(document.getElementById('notifForm'));

    fetch('/settings/notifications/test-telegram', {
        method: 'POST',
        body: formData,
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        if (data.ok) {
            result.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + data.message + '</span>';
        } else {
            result.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + data.message + '</span>';
        }
    })
    .catch(function() {
        btn.disabled = false;
        result.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Error de conexion</span>';
    });
}
</script>
