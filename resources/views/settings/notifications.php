<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php
    $method = $settings['notify_email_method'] ?? 'smtp';
    $encryption = $settings['notify_smtp_encryption'] ?? 'tls';
    $hasSmtpPass = !empty($settings['notify_smtp_pass']);
    $hasTelegram = !empty($settings['notify_telegram_token']) && !empty($settings['notify_telegram_chat_id']);
    $hasSmtp = !empty($settings['notify_smtp_host']);
    $manualTo = $settings['notify_email_to'] ?? '';
    $firewallWatchEnabled = ($settings['firewall_change_watch_enabled'] ?? '0') === '1';
    $serverRebootNotifyEnabled = ($settings['server_reboot_notify_enabled'] ?? '0') === '1';
    $collectorGapNotifyEnabled = ($settings['notify_event_collector_gap_enabled'] ?? '1') === '1';
    $hardeningNotifyEnabled = ($settings['notify_event_hardening_enabled'] ?? '1') === '1';
    $configDriftNotifyEnabled = ($settings['notify_event_config_drift_enabled'] ?? '1') === '1';
    $publicExposureNotifyEnabled = ($settings['notify_event_public_exposure_enabled'] ?? '1') === '1';
    $loginAnomalyNotifyEnabled = ($settings['notify_login_anomaly_enabled'] ?? '1') === '1';
    $collectorGapSeconds = (int)($settings['notify_event_collector_gap_seconds'] ?? 300);
    if ($collectorGapSeconds < 120) $collectorGapSeconds = 300;
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
                    <small class="text-muted">Ej: "Servidor principal", "Servidor de reserva"</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Marca en los correos a clientes</label>
                    <input type="text" name="notify_brand" class="form-control" maxlength="60"
                           value="<?= View::e(\MuseDockPanel\Settings::get('notify_brand', '')) ?>" placeholder="Tu empresa">
                    <small class="text-muted">Sale arriba en las invitaciones y avisos del portal. Vacío = el nombre del dominio del remitente.</small>
                </div>
            </div>

            <hr class="border-secondary">

            <!-- SMTP secundario (opcional): se usa solo si el principal falla o rechaza -->
            <?php $s2 = static fn(string $k, string $d = '') => \MuseDockPanel\Settings::get('notify_smtp2_' . $k, $d); $enc2 = $s2('encryption', 'tls'); ?>
            <h6 class="text-muted mb-1">Servidor SMTP secundario <span class="badge bg-secondary">opcional</span></h6>
            <p class="small text-muted mb-2">Solo se usa si el principal falla o rechaza el envío (caído, sin cupo…). Vacío = sin reserva.</p>
            <div class="row mb-3">
                <div class="col-md-5">
                    <label class="form-label">Host SMTP</label>
                    <input type="text" name="notify_smtp2_host" class="form-control" value="<?= View::e($s2('host')) ?>" placeholder="mail.tudominio.com">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Puerto</label>
                    <input type="number" name="notify_smtp2_port" class="form-control" value="<?= (int)($s2('port', '587') ?: 587) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cifrado</label>
                    <select name="notify_smtp2_encryption" class="form-select">
                        <option value="tls" <?= $enc2 === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                        <option value="ssl" <?= $enc2 === 'ssl' ? 'selected' : '' ?>>SSL/TLS</option>
                        <option value="none" <?= $enc2 === 'none' ? 'selected' : '' ?>>Sin cifrado</option>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Usuario SMTP</label>
                    <input type="text" name="notify_smtp2_user" class="form-control" value="<?= View::e($s2('user')) ?>" autocomplete="off">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Password SMTP</label>
                    <input type="password" name="notify_smtp2_pass" class="form-control" autocomplete="new-password" placeholder="<?= $s2('pass') !== '' ? '••••••••' : '' ?>">
                    <?php if ($s2('pass') !== ''): ?><small class="text-muted">Dejar vacío para mantener la actual</small><?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Remitente (From) del secundario</label>
                    <input type="email" name="notify_smtp2_from" class="form-control" value="<?= View::e($s2('from')) ?>" placeholder="vacío = el del principal">
                    <small class="text-muted">Por si este servidor no acepta el remitente del principal.</small>
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
                    <div class="input-group">
                        <input type="password" name="notify_telegram_token" id="tgToken" class="form-control" autocomplete="off"
                               value="<?= View::e($settings['notify_telegram_token'] ?? '') ?>" placeholder="123456:ABC-DEF...">
                        <button type="button" class="btn btn-outline-secondary" title="Ver / ocultar"
                                onclick="var i=document.getElementById('tgToken');i.type=i.type==='password'?'text':'password';this.firstElementChild.className=i.type==='password'?'bi bi-eye':'bi bi-eye-slash';"><i class="bi bi-eye"></i></button>
                        <button type="button" class="btn btn-outline-secondary" title="Copiar (para pegarlo en otro servidor)"
                                onclick="var b=this;navigator.clipboard.writeText(document.getElementById('tgToken').value).then(function(){b.firstElementChild.className='bi bi-check2';setTimeout(function(){b.firstElementChild.className='bi bi-clipboard';},1500);});"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <small class="text-muted">Obtenlo desde <a href="https://t.me/BotFather" target="_blank" class="text-info">@BotFather</a> · <a href="/docs/notifications" class="text-info">Guía paso a paso</a></small>
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

    <!-- Card 3: Eventos del sistema -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-shield-check me-2"></i>Eventos del sistema (sin spam)</div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Estas opciones controlan avisos de seguridad/operativa detectados por el monitor collector.
                Se aplica deduplicacion y cooldown para evitar ruido.
            </p>

            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="evFirewallChange" name="firewall_change_watch_enabled" value="1" <?= $firewallWatchEnabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="evFirewallChange">
                    Avisar cambios externos de firewall (shell/manual)
                </label>
            </div>
            <div class="small text-muted mb-3">
                Si cambia el firewall fuera del panel, crea alerta <code>FIREWALL_CHANGED</code> y envia email.
            </div>

            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="evServerReboot" name="server_reboot_notify_enabled" value="1" <?= $serverRebootNotifyEnabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="evServerReboot">
                    Avisar reinicio del servidor
                </label>
            </div>
            <div class="small text-muted mb-3">
                Detecta cambio de <code>boot_id</code>, crea alerta <code>SERVER_REBOOT</code> y envia email.
            </div>

            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="evCollectorGap" name="notify_event_collector_gap_enabled" value="1" <?= $collectorGapNotifyEnabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="evCollectorGap">
                    Avisar paradas (gap del monitor/host)
                </label>
            </div>
            <div class="row g-2 align-items-center mb-2">
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1" for="collectorGapSeconds">Umbral de parada (segundos)</label>
                    <input type="number" min="120" max="86400" step="30" class="form-control form-control-sm" id="collectorGapSeconds" name="notify_event_collector_gap_seconds" value="<?= (int)$collectorGapSeconds ?>">
                </div>
                <div class="col-md-8">
                    <div class="small text-muted mt-4 mt-md-0">
                        Si el collector deja de ejecutar por encima del umbral, crea alerta <code>MONITOR_GAP</code> y envia email.
                    </div>
                </div>
            </div>

            <hr class="border-secondary my-3">

            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="evHardening" name="notify_event_hardening_enabled" value="1" <?= $hardeningNotifyEnabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="evHardening">
                    Avisar hardening degradado del host
                </label>
            </div>
            <div class="small text-muted mb-3">
                Genera alerta <code>SECURITY_HARDENING</code> cuando la auditoria detecta baseline roto (sshd/fail2ban/sysctl/permisos).
            </div>

            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="evConfigDrift" name="notify_event_config_drift_enabled" value="1" <?= $configDriftNotifyEnabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="evConfigDrift">
                    Avisar drift en ficheros criticos
                </label>
            </div>
            <div class="small text-muted mb-3">
                Genera alerta <code>CONFIG_DRIFT</code> con diff resumido cuando cambian <code>/etc/ssh/sshd_config</code> o config de Fail2Ban fuera del flujo normal.
            </div>

            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="evPublicExposure" name="notify_event_public_exposure_enabled" value="1" <?= $publicExposureNotifyEnabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="evPublicExposure">
                    Avisar exposicion publica inesperada
                </label>
            </div>
            <div class="small text-muted mb-3">
                Genera alerta <code>PORT_EXPOSURE</code> si detecta puertos TCP publicos escuchando fuera de la politica esperada definida en Settings &rarr; Security.
            </div>

            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="evLoginAnomaly" name="notify_login_anomaly_enabled" value="1" <?= $loginAnomalyNotifyEnabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="evLoginAnomaly">
                    Avisar login admin anomalo
                </label>
            </div>
            <div class="small text-muted">
                Genera alerta <code>LOGIN_ANOMALY</code> en login exitoso desde IP/ASN/pais nuevo y envia email con cooldown.
            </div>
        </div>
    </div>

    <!-- Card 4: Resumen -->
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

<script>
// Botón copiar junto al Chat ID, los usuarios SMTP y las contraseñas SMTP (para ponerlos en
// otro servidor). Las contraseñas no están en la página: si no se ha escrito una nueva, se
// piden al panel al pulsar (queda apuntado en el registro).
(function () {
    var csrf = function () { return (document.querySelector('input[name=_csrf_token]') || {}).value || ''; };
    var flash = function (b, ok) { var i = b.firstElementChild; i.className = ok ? 'bi bi-check2' : 'bi bi-x-lg'; setTimeout(function () { i.className = 'bi bi-clipboard'; }, 1500); };
    var addBtn = function (name, getter) {
        var inp = document.querySelector('input[name="' + name + '"]');
        if (!inp || inp.closest('.input-group')) return;
        var g = document.createElement('div'); g.className = 'input-group';
        inp.parentNode.insertBefore(g, inp); g.appendChild(inp);
        var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-outline-secondary'; b.title = 'Copiar';
        b.innerHTML = '<i class="bi bi-clipboard"></i>';
        b.onclick = function () {
            Promise.resolve(getter(inp)).then(function (v) {
                if (!v) { flash(b, false); return; }
                return navigator.clipboard.writeText(v).then(function () { flash(b, true); });
            }).catch(function () { flash(b, false); });
        };
        g.appendChild(b);
    };
    var plain = function (inp) { return inp.value; };
    var secret = function (field) {
        return function (inp) {
            if (inp.value) return inp.value;
            var fd = new FormData(); fd.append('_csrf_token', csrf()); fd.append('field', field);
            return fetch('/settings/notifications/reveal', {method: 'POST', body: fd}).then(function (r) { return r.json(); }).then(function (d) { return d.ok ? d.value : ''; });
        };
    };
    addBtn('notify_telegram_chat_id', plain);
    addBtn('notify_smtp_user', plain);
    addBtn('notify_smtp2_user', plain);
    addBtn('notify_smtp_pass', secret('smtp_pass'));
    addBtn('notify_smtp2_pass', secret('smtp2_pass'));
})();
</script>
