<?php use MuseDockPanel\View; ?>
<?php $ro = $readOnly ?? false; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/mail" class="text-muted text-decoration-none"><i class="bi bi-arrow-left me-1"></i> Mail</a>
    </div>
    <?php if (!$ro): ?>
    <div class="d-flex gap-2">
        <a href="/mail/domains/<?= $domain['id'] ?>/accounts/create" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> New Mailbox
        </a>
        <form method="POST" action="/mail/domains/<?= $domain['id'] ?>/delete" class="d-inline js-confirm-delete"
              data-confirm-title="¿Eliminar el dominio de correo?"
              data-confirm-html="<?= View::e('Se eliminará <strong>' . View::e($domain['domain']) . '</strong> con <strong>todos sus buzones, alias y correos</strong>.<br>Esta acción no se puede deshacer.') ?>">
            <?= View::csrf() ?>
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if ($ro): ?>
<div class="alert py-2 mb-3" style="background:rgba(251,191,36,0.1);border:1px solid rgba(251,191,36,0.25);color:#fbbf24;">
    <i class="bi bi-eye me-1"></i> Solo lectura — la gestion de mail se realiza desde el panel master.
</div>
<?php endif; ?>

<!-- Domain info -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-globe2 me-2"></i><?= View::e($domain['domain']) ?></div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-4">
                        <div class="text-muted small">Status</div>
                        <span class="badge badge-<?= $domain['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $domain['status'] ?></span>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Mail Node</div>
                        <span class="fw-semibold"><?= View::e($domain['node_name'] ?? 'Local') ?></span>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Customer</div>
                        <span><?= View::e($domain['customer_name'] ?? '-') ?></span>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Max Accounts</div>
                        <span><?= $domain['max_accounts'] ?: 'Unlimited' ?></span>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">DKIM</div>
                        <?php if ($domain['dkim_public_key']): ?>
                            <span class="badge bg-success">Configured</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">Not generated</span>
                        <?php endif; ?>
                        <?php if (!$ro): ?>
                        <form method="POST" action="/mail/domains/<?= $domain['id'] ?>/regenerate-dkim" class="d-inline ms-1">
                            <?= View::csrf() ?>
                            <button class="btn btn-outline-light btn-sm py-0 px-1" title="Regenerate DKIM"><i class="bi bi-arrow-clockwise"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Created</div>
                        <span class="text-muted"><?= $domain['created_at'] ?></span>
                    </div>
                </div>

                <!-- Política de envío del dominio (anti-abuso) -->
                <hr class="border-secondary my-3">
                <div class="row g-3 align-items-end">
                    <div class="col-12">
                        <span class="small fw-semibold"><i class="bi bi-shield-lock me-1"></i>Política de envío del dominio</span>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small text-muted mb-1">Modo por defecto de los buzones nuevos</label>
                        <?php $dsm = $domain['default_send_mode'] ?? 'normal'; ?>
                        <select id="dom-send-mode" class="form-select form-select-sm">
                            <option value="normal" <?= $dsm === 'normal' ? 'selected' : '' ?>>Normal</option>
                            <option value="webmail_only" <?= $dsm === 'webmail_only' ? 'selected' : '' ?>>Solo webmail</option>
                            <option value="readonly" <?= $dsm === 'readonly' ? 'selected' : '' ?>>Solo lectura</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Límite por defecto (correos/hora)</label>
                        <input type="number" id="dom-rate" class="form-control form-control-sm" min="0"
                               value="<?= (int)($domain['default_rate_limit_per_hour'] ?? 0) ?>" placeholder="0 = global">
                    </div>
                    <div class="col-md-3">
                        <div class="form-check form-switch">
                            <?php $sendAllowed = in_array((string)($domain['send_allowed'] ?? 't'), ['1','t','true','yes','on'], true); ?>
                            <input class="form-check-input" type="checkbox" id="dom-send-allowed" <?= $sendAllowed ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="dom-send-allowed">Permite envío</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="button" id="dom-policy-save" class="btn btn-sm btn-success"><i class="bi bi-check-circle me-1"></i>Guardar política</button>
                        <span id="dom-policy-msg" class="small ms-2"></span>
                        <div class="small text-muted mt-1"><i class="bi bi-info-circle me-1"></i>“Permite envío” solo aplica si la lista blanca está activada en <a href="/mail?tab=antispam" class="text-info">Anti-spam</a>.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-signpost me-2"></i>DNS Records</span>
                <?php if (!empty($dnsRecords)):
                    // Build a tab-separated block of ALL records for one-click copy.
                    $allLines = [];
                    foreach ($dnsRecords as $r) {
                        $v = (isset($r['priority']) ? $r['priority'] . ' ' : '') . $r['value'];
                        $allLines[] = $r['type'] . "\t" . $r['name'] . "\t" . $v;
                    }
                    $allBlock = implode("\n", $allLines);
                ?>
                <button type="button" class="btn btn-outline-light btn-sm py-0 px-2 dns-copy-all"
                        title="Copiar todos los registros" data-copy="<?= View::e($allBlock) ?>">
                    <i class="bi bi-clipboard-check me-1"></i>Copiar todo
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($dnsRecords)): ?>
                    <div class="p-3 text-muted">No DNS records available.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size: 0.78rem;">
                            <thead>
                                <tr><th class="ps-3" style="width:90px;">Type</th><th style="width:1%;white-space:nowrap;">Name</th><th>Value</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dnsRecords as $r):
                                    // Full value (with priority prefix for MX) — this is what gets copied.
                                    $fullValue = (isset($r['priority']) ? $r['priority'] . ' ' : '') . $r['value'];
                                    $shown = mb_substr($fullValue, 0, 160) . (mb_strlen($fullValue) > 160 ? '…' : '');
                                ?>
                                <tr>
                                    <td class="ps-3 align-middle"><code><?= $r['type'] ?></code>
                                        <?php if ($r['type'] === 'TXT' && str_contains($r['value'], 'DKIM')): ?>
                                            <div class="badge bg-secondary mt-1" style="font-size:.6rem;"><?= mb_strlen($fullValue) ?> chars</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle">
                                        <div class="d-flex align-items-center justify-content-between gap-2">
                                            <span class="text-nowrap" style="font-family:monospace;"><?= View::e($r['name']) ?></span>
                                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 flex-shrink-0 dns-copy-name"
                                                    title="Copiar nombre" data-copy="<?= View::e($r['name']) ?>"><i class="bi bi-clipboard"></i></button>
                                        </div>
                                    </td>
                                    <td class="align-middle" style="max-width:0; width:100%;">
                                        <div class="d-flex align-items-center justify-content-between gap-2">
                                            <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; font-family:monospace; font-size:.72rem;" title="<?= View::e($fullValue) ?>"><?= View::e($shown) ?></span>
                                            <button type="button" class="btn btn-outline-primary btn-sm py-0 px-1 flex-shrink-0 dns-copy-value"
                                                    title="Copiar valor completo" data-copy="<?= View::e($fullValue) ?>"><i class="bi bi-clipboard"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Mailboxes -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-mailbox me-2"></i>Mailboxes</span>
        <?php
        $usageAts = array_filter(array_map(static fn($x) => (int)($x['at'] ?? 0), $accounts));
        $usageAt = $usageAts ? min($usageAts) : 0;
        $usageAgo = $usageAt ? max(0, (int)round((time() - $usageAt) / 60)) : null;
        ?>
        <span class="d-flex align-items-center gap-2">
            <span class="text-muted small" title="El uso (correos, sin leer, espacio) se actualiza solo cada 30 minutos">
                <?= $usageAgo === null ? 'Uso aún sin medir' : ('Uso actualizado ' . ($usageAgo < 1 ? 'ahora' : "hace {$usageAgo} min")) ?>
            </span>
            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" id="usage-refresh" title="Actualizar ya el uso de los buzones" data-id="<?= (int)$domain['id'] ?>">
                <i class="bi bi-arrow-repeat"></i>
            </button>
            <?php if (!$ro): ?>
            <button type="button" class="btn btn-outline-danger btn-sm py-0 d-none bulk-delete" data-group="accounts"><i class="bi bi-trash me-1"></i>Borrar seleccionados (<span>0</span>)</button>
            <a href="/mail/domains/<?= $domain['id'] ?>/accounts/create" class="btn btn-primary btn-sm py-0 px-2">
                <i class="bi bi-plus-lg"></i>
            </a>
            <?php endif; ?>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($accounts)): ?>
            <div class="p-4 text-center text-muted">
                <p>No mailboxes yet.</p>
                <?php if (!$ro): ?>
                <a href="/mail/domains/<?= $domain['id'] ?>/accounts/create" class="btn btn-primary btn-sm">Create first mailbox</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <?php if (!$ro): ?><th class="ps-3" style="width:28px;"><input type="checkbox" class="form-check-input bulk-all" data-group="accounts" title="Seleccionar todos"></th><?php endif; ?>
                        <th class="<?= $ro ? 'ps-3' : '' ?>">Email</th>
                        <th>Display Name</th>
                        <th>Quota</th>
                        <th>Correos</th>
                        <th>Used</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <?php if (!$ro): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $a): ?>
                    <tr>
                        <?php if (!$ro): ?><td class="ps-3"><input type="checkbox" class="form-check-input bulk-item" data-group="accounts" value="<?= (int)$a['id'] ?>" data-name="<?= View::e($a['email']) ?>"></td><?php endif; ?>
                        <td class="<?= $ro ? 'ps-3 ' : '' ?>fw-semibold"><?= View::e($a['email']) ?></td>
                        <td><?= View::e($a['display_name'] ?: '-') ?></td>
                        <td><?= (int)$a['quota_mb'] === 0 ? '<span class="badge bg-secondary">Ilimitado</span>' : ((int)$a['quota_mb'] . ' MB') ?></td>
                        <td>
                            <?php if (isset($a['messages'])): ?>
                                <?= (int)$a['messages'] ?>
                                <?php if ((int)$a['unread'] > 0): ?>
                                    <a href="#" class="text-warning small" data-mail-folders="<?= View::e(json_encode($a['folders'] ?? [], JSON_UNESCAPED_UNICODE)) ?>" data-mailbox="<?= View::e($a['email']) ?>" title="Ver en qué carpetas están">(<?= (int)$a['unread'] ?> sin leer)</a>
                                <?php elseif (!empty($a['folders'])): ?>
                                    <a href="#" class="text-muted small" data-mail-folders="<?= View::e(json_encode($a['folders'], JSON_UNESCAPED_UNICODE)) ?>" data-mailbox="<?= View::e($a['email']) ?>" title="Ver por carpetas"><i class="bi bi-folder2"></i></a>
                                <?php endif; ?>
                                <?php if (!empty($a['spam'])): ?><div class="small text-muted"><?= (int)$a['spam'] ?> en spam</div><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $a['used_mb'] ?> MB
                            <?php if ($a['quota_mb'] > 0): ?>
                                <div class="progress mt-1" style="height: 3px; width: 60px;">
                                    <?php $pct = min(100, round($a['used_mb'] / $a['quota_mb'] * 100)); ?>
                                    <div class="progress-bar <?= $pct > 90 ? 'bg-danger' : ($pct > 70 ? 'bg-warning' : 'bg-info') ?>"
                                         style="width: <?= $pct ?>%"></div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $a['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $a['status'] ?></span>
                        </td>
                        <td class="text-muted small"><?= !empty($a['last_login_at']) ? View::e($a['last_login_at']) : '<span title="El panel aún no registra los accesos de los buzones">—</span>' ?></td>
                        <?php if (!$ro): ?>
                        <td>
                            <?php $wm = \MuseDockPanel\Services\WebmailService::loginUrl((string)$a['email']); ?>
                            <?php if ($wm !== ''): ?>
                            <a href="<?= View::e($wm) ?>" target="_blank" rel="noopener" class="btn btn-outline-info btn-sm" title="Abrir el webmail con este buzón ya puesto (solo falta la contraseña)"><i class="bi bi-box-arrow-up-right"></i></a>
                            <?php endif; ?>
                            <a href="/mail/accounts/<?= $a['id'] ?>/edit" class="btn btn-outline-light btn-sm"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="/mail/accounts/<?= $a['id'] ?>/delete" class="d-inline js-confirm-delete"
                                  data-confirm-title="¿Eliminar el buzón?"
                                  data-confirm-html="<?= View::e('Se eliminará <strong>' . View::e($a['email']) . '</strong> y <strong>todos sus correos</strong>.<br>Esta acción no se puede deshacer.') ?>">
                                <?= View::csrf() ?>
                                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Aliases -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-arrow-left-right me-2"></i>Aliases & Forwards</span>
        <?php if (!$ro): ?>
        <button type="button" class="btn btn-outline-danger btn-sm py-0 d-none bulk-delete" data-group="aliases"><i class="bi bi-trash me-1"></i>Borrar seleccionados (<span>0</span>)</button>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (!empty($aliases)): ?>
            <table class="table table-sm mb-3">
                <thead>
                    <tr><?php if (!$ro): ?><th style="width:28px;"><input type="checkbox" class="form-check-input bulk-all" data-group="aliases" title="Seleccionar todos"></th><?php endif; ?><th>Source</th><th>Destination</th><th>Catchall</th><?php if (!$ro): ?><th></th><?php endif; ?></tr>
                </thead>
                <tbody>
                    <?php foreach ($aliases as $al): ?>
                    <tr>
                        <?php if (!$ro): ?><td><input type="checkbox" class="form-check-input bulk-item" data-group="aliases" value="<?= (int)$al['id'] ?>" data-name="<?= View::e($al['source']) ?>"></td><?php endif; ?>
                        <td><?= View::e($al['source']) ?></td>
                        <?php $alActive = in_array($al['is_active'] ?? true, [true, 't', 'true', 1, '1'], true); ?>
                        <td><?= View::e($al['destination']) ?><?php if (!$alActive): ?> <span class="badge bg-secondary ms-1" title="No reenvía: está pausado">pausado</span><?php endif; ?></td>
                        <td><?= $al['is_catchall'] ? '<span class="badge bg-info">Yes</span>' : '-' ?></td>
                        <?php if (!$ro): ?>
                        <td class="text-nowrap">
                            <button type="button" class="btn btn-outline-light btn-sm py-0 alias-edit" title="Cambiar el destino"
                                    data-action="/mail/domains/<?= (int)$domain['id'] ?>/aliases/<?= (int)$al['id'] ?>/update"
                                    data-source="<?= View::e($al['source']) ?>" data-destination="<?= View::e($al['destination']) ?>"
                                    data-catchall="<?= $al['is_catchall'] ? '1' : '0' ?>" data-active="<?= $alActive ? '1' : '0' ?>"><i class="bi bi-pencil"></i></button>
                            <form method="POST" action="/mail/domains/<?= $domain['id'] ?>/aliases/<?= $al['id'] ?>/delete" class="d-inline js-confirm-delete"
                                  data-confirm-title="<?= $al['is_catchall'] ? '¿Eliminar el catch-all?' : '¿Eliminar el alias?' ?>"
                                  data-confirm-html="<?= View::e($al['is_catchall']
                                      ? 'Se eliminará el catch-all <strong>' . View::e($al['source'] ?? '') . '</strong> → <strong>' . View::e($al['destination']) . '</strong>.<br>Los correos a direcciones <strong>que no existan</strong> dejarán de llegar y serán rechazados.'
                                      : 'Se eliminará el alias <strong>' . View::e($al['source'] ?? '') . '</strong> → <strong>' . View::e($al['destination']) . '</strong>.<br>Los correos a esa dirección dejarán de reenviarse.') ?>">
                                <?= View::csrf() ?>
                                <button class="btn btn-outline-danger btn-sm py-0"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($ro): ?>
            <p class="text-muted mb-0">No aliases configured.</p>
        <?php endif; ?>

        <?php if (!$ro): ?>
        <form method="POST" action="/mail/domains/<?= $domain['id'] ?>/aliases/store" class="row g-2 align-items-end">
            <?= View::csrf() ?>
            <div class="col-md-4">
                <label class="form-label small">Source (origen)</label>
                <input type="text" name="source" list="alias-source-list" class="form-control form-control-sm"
                       placeholder="alias@<?= View::e($domain['domain']) ?>" required>
                <datalist id="alias-source-list">
                    <?php foreach (($accounts ?? []) as $a): ?>
                        <option value="<?= View::e($a['email']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <div class="form-text">Elige un buzón existente o escribe una dirección nueva.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Destination (destino)</label>
                <input type="text" name="destination" list="alias-dest-list" class="form-control form-control-sm"
                       placeholder="user@example.com" required>
                <datalist id="alias-dest-list">
                    <?php foreach (($accounts ?? []) as $a): ?>
                        <option value="<?= View::e($a['email']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <div class="form-text">A dónde se reenvía (buzón local u otra dirección).</div>
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_catchall" id="catchall">
                    <label class="form-check-label small" for="catchall">Catchall</label>
                </div>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i> Add</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
// Copy-to-clipboard for DNS records (copies the FULL value, not the truncated one).
(function () {
    function copyText(text, btn) {
        var done = function () {
            var icon = btn.querySelector('i');
            if (!icon) return;
            var prev = icon.className;
            icon.className = 'bi bi-check-lg text-success';
            setTimeout(function () { icon.className = prev; }, 1200);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () { fallback(text, done); });
        } else { fallback(text, done); }
    }
    function fallback(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(ta);
    }
    document.querySelectorAll('.dns-copy-value, .dns-copy-name, .dns-copy-all').forEach(function (btn) {
        btn.addEventListener('click', function () { copyText(btn.getAttribute('data-copy') || '', btn); });
    });
})();
(function () {
    const btn = document.getElementById('dom-policy-save');
    if (!btn) return;
    const csrf = '<?= View::csrfToken() ?>';
    const msg = document.getElementById('dom-policy-msg');
    btn.addEventListener('click', function () {
        const fd = new FormData();
        fd.append('_csrf_token', csrf);
        fd.append('default_send_mode', document.getElementById('dom-send-mode').value);
        fd.append('default_rate_limit_per_hour', document.getElementById('dom-rate').value);
        fd.append('send_allowed', document.getElementById('dom-send-allowed').checked ? '1' : '0');
        btn.disabled = true;
        msg.textContent = 'Guardando…'; msg.className = 'small ms-2 text-muted';
        fetch('/mail/domains/<?= (int)$domain['id'] ?>/policy', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                btn.disabled = false;
                if (d.ok) { msg.textContent = '✓ Guardado'; msg.className = 'small ms-2 text-success'; }
                else { msg.textContent = 'Error: ' + (d.error || ''); msg.className = 'small ms-2 text-danger'; }
            })
            .catch(() => { btn.disabled = false; msg.textContent = 'Error de red'; msg.className = 'small ms-2 text-danger'; });
    });
})();
</script>
<script>
// Confirmación con modal antes de borrar dominio / buzón / alias.
// Si SweetAlert no está disponible (CDN caído), cae al confirm() nativo: nunca se
// borra sin preguntar. El HTML del mensaje viene doble-escapado desde PHP.
(function () {
    document.querySelectorAll('form.js-confirm-delete').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (form.dataset.confirmed === '1') return;
            ev.preventDefault();
            var title = form.dataset.confirmTitle || '¿Eliminar?';
            var html  = form.dataset.confirmHtml || 'Esta acción no se puede deshacer.';
            var S = window.SwalDark || window.Swal;
            if (!S || typeof S.fire !== 'function') {
                var tmp = document.createElement('div'); tmp.innerHTML = html;
                if (confirm(title + '\n\n' + tmp.textContent)) { form.dataset.confirmed = '1'; form.submit(); }
                return;
            }
            S.fire({
                icon: 'warning',
                title: title,
                html: html,
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-trash me-1"></i>Eliminar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ef4444',
                focusCancel: true,
                reverseButtons: true
            }).then(function (r) {
                if (r.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); }
            });
        });
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

<datalist id="alias-dest-options">
    <?php foreach ($accounts as $a): ?><option value="<?= View::e($a['email']) ?>"><?php endforeach; ?>
</datalist>
<script>
// Cambiar el destino de un alias en una ventana (uno o varios, separados por comas).
document.addEventListener('click', function (e) {
    const b = e.target.closest('.alias-edit');
    if (!b) return;
    const esc = (t) => { const d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; };
    const catchall = b.dataset.catchall === '1' || String(b.dataset.source || '').startsWith('@');
    Swal.fire({
        title: 'Editar el alias',
        html: '<div class="text-start small mb-2"><code>' + esc(b.dataset.source) + '</code>' + (b.dataset.catchall === '1' ? ' <span class="badge bg-info">recoge-todo</span>' : '')
            + '<br>Ahora: <code>' + esc(b.dataset.destination) + '</code></div>'
            + '<input id="alias-dest" class="swal2-input" list="alias-dest-options" autocomplete="off" value="' + esc(b.dataset.destination) + '">'
            + '<div class="small text-muted text-start mt-2">Un buzón de este dominio u otra dirección. Varios destinos, separados por comas. El cambio se copia a los nodos de correo.</div>'
            + '<div class="form-check text-start mt-3"><input class="form-check-input" type="checkbox" id="alias-active"' + (b.dataset.active === '1' ? ' checked' : '') + '>'
            + '<label class="form-check-label" for="alias-active">' + (catchall ? 'Recoge-todo activo' : 'Activo (reenvía)') + '</label></div>'
            + '<div id="alias-off-warn" class="small text-danger text-start mt-1" style="display:none"></div>',
        showCancelButton: true, confirmButtonText: 'Guardar', cancelButtonText: 'Cancelar', width: 560,
        didOpen: () => {
            const i = document.getElementById('alias-dest'); i.focus(); i.select();
            const cb = document.getElementById('alias-active'), w = document.getElementById('alias-off-warn');
            const upd = () => {
                const off = !cb.checked && b.dataset.active === '1', on = cb.checked && b.dataset.active !== '1';
                w.style.display = off ? '' : 'none';
                w.innerHTML = catchall
                    ? '<i class="bi bi-exclamation-triangle me-1"></i>Al desactivar el recoge-todo, los correos a direcciones de este dominio <strong>que no existan</strong> se <strong>rechazarán</strong> (hoy llegan a ' + esc(b.dataset.destination) + '). Los buzones y los demás alias siguen igual.'
                    : '<i class="bi bi-exclamation-triangle me-1"></i>Al pausarlo, los correos a <strong>' + esc(b.dataset.source) + '</strong> dejarán de reenviarse (si no hay recoge-todo, se rechazarán). No se borra: se puede reactivar.';
                Swal.getConfirmButton().textContent = off ? (catchall ? 'Desactivar recoge-todo' : 'Pausar alias') : (on ? 'Reactivar' : 'Guardar');
                Swal.getConfirmButton().classList.toggle('btn-danger', off);
            };
            cb.addEventListener('change', upd);
        },
        preConfirm: () => {
            const v = document.getElementById('alias-dest').value.trim();
            if (!v) { Swal.showValidationMessage('Escribe al menos una dirección'); return false; }
            const bad = v.split(/[\s,;]+/).filter(x => x && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(x));
            if (bad.length) { Swal.showValidationMessage('No es una dirección válida: ' + bad.join(', ')); return false; }
            return {dest: v, active: document.getElementById('alias-active').checked};
        }
    }).then((r) => {
        if (!r.isConfirmed) return;
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = b.dataset.action;
        f.innerHTML = <?= json_encode(View::csrf()) ?> + '<input type="hidden" name="destination"><input type="hidden" name="active">';
        f.querySelector('[name=destination]').value = r.value.dest;
        f.querySelector('[name=active]').value = r.value.active ? '1' : '0';
        document.body.appendChild(f);
        f.submit();
    });
});
</script>

<script>
// Borrar varios buzones o alias a la vez: casillas, "seleccionar todos" y confirmación con
// la lista de lo que se borra y la contraseña de administrador.
(function () {
    const esc = (t) => { const d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; };
    const items = (g) => Array.from(document.querySelectorAll('.bulk-item[data-group="' + g + '"]'));
    const refresh = (g) => {
        const sel = items(g).filter(i => i.checked), btn = document.querySelector('.bulk-delete[data-group="' + g + '"]');
        if (btn) { btn.querySelector('span').textContent = sel.length; btn.classList.toggle('d-none', sel.length === 0); }
        const all = document.querySelector('.bulk-all[data-group="' + g + '"]');
        if (all) { all.checked = sel.length > 0 && sel.length === items(g).length; all.indeterminate = sel.length > 0 && sel.length < items(g).length; }
    };
    document.addEventListener('change', (e) => {
        const all = e.target.closest('.bulk-all');
        if (all) { items(all.dataset.group).forEach(i => { i.checked = all.checked; }); refresh(all.dataset.group); return; }
        const it = e.target.closest('.bulk-item');
        if (it) refresh(it.dataset.group);
    });
    document.addEventListener('click', (e) => {
        const b = e.target.closest('.bulk-delete');
        if (!b) return;
        const g = b.dataset.group, sel = items(g).filter(i => i.checked);
        if (!sel.length) return;
        const isAcc = g === 'accounts';
        const list = '<ul class="text-start small mb-2" style="max-height:200px;overflow:auto">' + sel.map(i => '<li><code>' + esc(i.dataset.name) + '</code></li>').join('') + '</ul>';
        Swal.fire({
            icon: 'warning',
            title: 'Borrar ' + sel.length + (isAcc ? ' buzón(es)' : ' alias'),
            html: list + (isAcc
                ? '<div class="text-start small text-danger mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Se borran los buzones <strong>y todos sus correos</strong>, aquí y en la réplica. No se puede deshacer.</div>'
                : '<div class="text-start small text-danger mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Los correos a esas direcciones dejarán de reenviarse' + (sel.some(i => String(i.dataset.name).startsWith('@')) ? ' (incluido el <strong>recoge-todo</strong>: los correos a direcciones que no existan se rechazarán)' : '') + '.</div>')
                + '<input type="password" id="bulk-pwd" class="swal2-input" placeholder="Contraseña de administrador" autocomplete="current-password">',
            showCancelButton: true, confirmButtonText: 'Borrar ' + sel.length, cancelButtonText: 'Cancelar', confirmButtonColor: '#dc3545', width: 560,
            preConfirm: () => { const v = document.getElementById('bulk-pwd').value; if (!v) { Swal.showValidationMessage('Escribe la contraseña'); return false; } return v; }
        }).then((r) => {
            if (!r.isConfirmed) return;
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = '/mail/domains/<?= (int)$domain['id'] ?>/bulk-delete';
            f.innerHTML = <?= json_encode(View::csrf()) ?> + '<input type="hidden" name="type"><input type="hidden" name="admin_password">';
            f.querySelector('[name=type]').value = g;
            f.querySelector('[name=admin_password]').value = r.value;
            sel.forEach(i => { const h = document.createElement('input'); h.type = 'hidden'; h.name = 'ids[]'; h.value = i.value; f.appendChild(h); });
            document.body.appendChild(f);
            f.submit();
        });
    });
})();
</script>
