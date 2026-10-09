<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="mb-3">
            <a href="/mail/domains/<?= $account['mail_domain_id'] ?>" class="text-muted text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i> <?= View::e($account['domain_name']) ?>
            </a>
        </div>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-inbox me-2"></i>Uso del buzón</span>
                <span class="d-flex align-items-center gap-2">
                    <span class="text-muted small"><?= !empty($usage['at']) ? 'Actualizado hace ' . max(0, (int)round((time() - (int)$usage['at']) / 60)) . ' min' : 'Aún sin medir' ?></span>
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" id="usage-refresh" data-id="<?= (int)$account['mail_domain_id'] ?>" title="Actualizar ya"><i class="bi bi-arrow-repeat"></i></button>
                    <?php if (!empty($webmailUrl)): ?>
                    <a href="<?= View::e($webmailUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-info btn-sm py-0" title="Abre el webmail con este buzón ya puesto: solo falta la contraseña">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Webmail
                    </a>
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body">
                <div class="row text-center small">
                    <div class="col"><div class="text-muted">Correos</div><div class="fs-5"><?= isset($usage['messages']) ? (int)$usage['messages'] : '—' ?></div></div>
                    <div class="col"><div class="text-muted">Sin leer</div><div class="fs-5">
                        <?php if (isset($usage['unread'])): ?>
                            <a href="#" class="text-decoration-none <?= !empty($usage['unread']) ? 'text-warning' : 'text-light' ?>" data-mail-folders="<?= View::e(json_encode($usage['folders'] ?? [], JSON_UNESCAPED_UNICODE)) ?>" data-mailbox="<?= View::e($account['email']) ?>" title="Ver en qué carpetas están"><?= (int)$usage['unread'] ?> <i class="bi bi-folder2 small"></i></a>
                        <?php else: ?>—<?php endif; ?>
                    </div></div>
                    <div class="col"><div class="text-muted">En spam</div><div class="fs-5 <?= !empty($usage['spam']) ? 'text-warning' : '' ?>"><?= isset($usage['spam']) ? (int)$usage['spam'] : '—' ?></div></div>
                    <div class="col"><div class="text-muted">Espacio</div><div class="fs-5"><?= isset($usage['used_mb']) ? View::e((string)$usage['used_mb']) . ' MB' : '—' ?></div></div>
                    <div class="col"><div class="text-muted">Cuota</div><div class="fs-5"><?= (int)$account['quota_mb'] > 0 ? (int)$account['quota_mb'] . ' MB' : 'Sin límite' ?></div></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="bi bi-pencil me-2"></i>Edit: <?= View::e($account['email']) ?></div>
            <div class="card-body">
                <form method="POST" action="/mail/accounts/<?= $account['id'] ?>/update" id="account-edit-form">
                    <?= View::csrf() ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="text" class="form-control" value="<?= View::e($account['email']) ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="pw1"><i class="bi bi-key me-1"></i>Nueva contraseña</label>
                            <div class="input-group">
                                <input type="password" name="password" id="pw1" class="form-control" minlength="8"
                                       autocomplete="new-password" placeholder="Vacío = no cambiar">
                                <button type="button" class="btn btn-outline-secondary pw-eye" data-target="pw1" title="Mostrar/ocultar" tabindex="-1"><i class="bi bi-eye"></i></button>
                                <button type="button" class="btn btn-outline-info" id="pw-gen" title="Generar una contraseña segura" tabindex="-1"><i class="bi bi-magic"></i></button>
                                <button type="button" class="btn btn-outline-secondary d-none" id="pw-copy" title="Copiar la contraseña" tabindex="-1"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="pw2">Repetir contraseña</label>
                            <div class="input-group">
                                <input type="password" name="password_confirm" id="pw2" class="form-control" minlength="8"
                                       autocomplete="new-password" placeholder="Vacío = no cambiar">
                                <button type="button" class="btn btn-outline-secondary pw-eye" data-target="pw2" title="Mostrar/ocultar" tabindex="-1"><i class="bi bi-eye"></i></button>
                            </div>
                            <div id="pw-msg" class="form-text"></div>
                        </div>
                        <div class="col-12 mt-1">
                            <div class="form-text"><i class="bi bi-info-circle me-1"></i>Escribe la contraseña a mano o pulsa <i class="bi bi-magic"></i> para generar una segura
                                (luego <i class="bi bi-clipboard"></i> la copia). Vacío = no se cambia. Se aplica al guardar; anótala antes, luego no se puede volver a ver.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Display Name</label>
                            <input type="text" name="display_name" class="form-control" value="<?= View::e($account['display_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Quota (MB)</label>
                            <input type="number" name="quota_mb" class="form-control" value="<?= $account['quota_mb'] ?>" min="0">
                            <div class="form-text"><strong>0 = sin límite</strong> (ilimitado).</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?= $account['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="suspended" <?= $account['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mt-1 p-2 rounded" style="background:rgba(59,130,246,.05);border:1px solid rgba(59,130,246,.18);">
                        <div class="col-12"><span class="small fw-semibold"><i class="bi bi-shield-lock me-1"></i>Política de envío (anti-abuso)</span></div>
                        <div class="col-md-4">
                            <label class="form-label small">Modo de envío</label>
                            <?php $sm = $account['send_mode'] ?? 'normal'; ?>
                            <select name="send_mode" class="form-select form-select-sm">
                                <option value="normal" <?= $sm === 'normal' ? 'selected' : '' ?>>Normal (clientes + webmail)</option>
                                <option value="webmail_only" <?= $sm === 'webmail_only' ? 'selected' : '' ?>>Solo webmail (sin SMTP externo)</option>
                                <option value="readonly" <?= $sm === 'readonly' ? 'selected' : '' ?>>Solo lectura (no envía)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Límite propio (correos/hora)</label>
                            <input type="number" name="rate_limit_per_hour" class="form-control form-control-sm" min="0" value="<?= (int)($account['rate_limit_per_hour'] ?? 0) ?>" placeholder="0 = usar el global">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <?php $canSend = in_array((string)($account['can_send'] ?? 't'), ['1','t','true','yes','on'], true); ?>
                                <input class="form-check-input" type="checkbox" name="can_send" value="1" id="can_send" <?= $canSend ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="can_send">Puede enviar</label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 p-3 rounded" style="background:rgba(34,197,94,.05);border:1px solid rgba(34,197,94,.18);">
                        <div class="form-check form-switch mb-3">
                            <?php $autoresponderOn = in_array((string)($account['autoresponder_enabled'] ?? ''), ['1', 't', 'true', 'yes', 'on'], true); ?>
                            <input class="form-check-input" type="checkbox" name="autoresponder_enabled" value="1" id="autoresponder_enabled" <?= $autoresponderOn ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="autoresponder_enabled">Autoresponder / vacaciones</label>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label">Asunto</label>
                                <input type="text" name="autoresponder_subject" class="form-control" value="<?= View::e($account['autoresponder_subject'] ?? '') ?>" placeholder="Estoy fuera de la oficina">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Mensaje</label>
                                <textarea name="autoresponder_body" class="form-control" rows="4" placeholder="Gracias por tu email. Responderé lo antes posible."><?= View::e($account['autoresponder_body'] ?? '') ?></textarea>
                                <div class="form-text text-muted">
                                    Esto genera un script Sieve en el nodo de correo. Si el usuario gestiona filtros desde Roundcube, Roundcube puede sustituir el script activo.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Usage info -->
                    <div class="mt-3 p-3 rounded" style="background: rgba(56,189,248,0.05);">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <div class="text-muted small">Used</div>
                                <span class="fw-bold"><?= $account['used_mb'] ?> MB</span>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Quota</div>
                                <span class="fw-bold"><?= $account['quota_mb'] ?> MB</span>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Last Login</div>
                                <span><?= $account['last_login_at'] ?? 'Never' ?></span>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Created</div>
                                <span><?= $account['created_at'] ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Update</button>
                        <a href="/mail/domains/<?= $account['mail_domain_id'] ?>" class="btn btn-outline-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
// Contraseña: ojito para mostrar/ocultar + validación en vivo (coinciden y >= 8).
(function () {
    document.querySelectorAll('.pw-eye').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.dataset.target);
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    });

    var form = document.getElementById('account-edit-form');
    var p1 = document.getElementById('pw1'), p2 = document.getElementById('pw2');
    var msg = document.getElementById('pw-msg');
    if (!form || !p1 || !p2) return;

    function check() {
        var a = p1.value, b = p2.value;
        p1.classList.remove('is-invalid', 'is-valid'); p2.classList.remove('is-invalid', 'is-valid');
        if (a === '' && b === '') { msg.textContent = ''; msg.className = 'form-text'; return true; }
        if (a.length < 8) { msg.textContent = 'Mínimo 8 caracteres.'; msg.className = 'form-text text-danger'; p1.classList.add('is-invalid'); return false; }
        if (a !== b) { msg.textContent = 'Las contraseñas no coinciden.'; msg.className = 'form-text text-danger'; p2.classList.add('is-invalid'); return false; }
        msg.textContent = '✓ Las contraseñas coinciden.'; msg.className = 'form-text text-success';
        p1.classList.add('is-valid'); p2.classList.add('is-valid');
        return true;
    }
    p1.addEventListener('input', check);
    p2.addEventListener('input', check);
    form.addEventListener('submit', function (ev) {
        if (!check()) { ev.preventDefault(); (p1.value.length < 8 ? p1 : p2).focus(); }
    });
})();
</script>

<script>
(function () {
    const b = document.getElementById('usage-refresh');
    if (!b) return;
    b.addEventListener('click', function () {
        b.disabled = true;
        b.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        const fd = new FormData();
        fd.append('_csrf_token', <?= json_encode(View::csrfToken()) ?>);
        fetch('/mail/domains/' + encodeURIComponent(b.dataset.id) + '/usage-refresh', {method: 'POST', body: fd})
            .then(r => r.json()).then(() => location.reload())
            .catch(() => { b.disabled = false; b.innerHTML = '<i class="bi bi-arrow-repeat"></i>'; });
    });
})();
</script>

<script>
// Generar contraseña segura: rellena los dos campos, la muestra y permite copiarla.
(function () {
    const gen = document.getElementById('pw-gen'), copy = document.getElementById('pw-copy');
    const p1 = document.getElementById('pw1'), p2 = document.getElementById('pw2');
    if (!gen || !p1 || !p2) return;
    gen.addEventListener('click', function () {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_.!';
        const buf = new Uint32Array(20);
        crypto.getRandomValues(buf);
        const pw = Array.from(buf, (n) => chars[n % chars.length]).join('');
        p1.value = pw; p2.value = pw;
        p1.type = 'text'; p2.type = 'text';
        p1.dispatchEvent(new Event('input')); p2.dispatchEvent(new Event('input'));
        copy.classList.remove('d-none');
    });
    copy.addEventListener('click', function () {
        navigator.clipboard.writeText(p1.value).then(() => {
            copy.innerHTML = '<i class="bi bi-check2"></i>';
            setTimeout(() => { copy.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
        });
    });
})();
</script>
