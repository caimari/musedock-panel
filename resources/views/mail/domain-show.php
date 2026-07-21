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
        <form method="POST" action="/mail/domains/<?= $domain['id'] ?>/delete" class="d-inline"
              onsubmit="return confirm('Delete domain <?= View::e($domain['domain']) ?> and all its accounts?')">
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
    <div class="col-md-8">
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
    <div class="col-md-4">
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
                                <tr><th class="ps-3">Type</th><th>Name</th><th>Value</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dnsRecords as $r):
                                    // Full value (with priority prefix for MX) — this is what gets copied.
                                    $fullValue = (isset($r['priority']) ? $r['priority'] . ' ' : '') . $r['value'];
                                    $shown = mb_substr($fullValue, 0, 80) . (mb_strlen($fullValue) > 80 ? '…' : '');
                                ?>
                                <tr>
                                    <td class="ps-3 align-middle"><code><?= $r['type'] ?></code>
                                        <?php if ($r['type'] === 'TXT' && str_contains($r['value'], 'DKIM')): ?>
                                            <div class="badge bg-secondary mt-1" style="font-size:.6rem;"><?= mb_strlen($fullValue) ?> chars</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle">
                                        <div class="d-flex align-items-center justify-content-between gap-2">
                                            <span class="text-break" style="min-width:0;"><?= View::e($r['name']) ?></span>
                                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 flex-shrink-0 dns-copy-name"
                                                    title="Copiar nombre" data-copy="<?= View::e($r['name']) ?>"><i class="bi bi-clipboard"></i></button>
                                        </div>
                                    </td>
                                    <td class="align-middle" style="max-width:240px;">
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
        <?php if (!$ro): ?>
        <a href="/mail/domains/<?= $domain['id'] ?>/accounts/create" class="btn btn-primary btn-sm py-0 px-2">
            <i class="bi bi-plus-lg"></i>
        </a>
        <?php endif; ?>
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
                        <th class="ps-3">Email</th>
                        <th>Display Name</th>
                        <th>Quota</th>
                        <th>Used</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <?php if (!$ro): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $a): ?>
                    <tr>
                        <td class="ps-3 fw-semibold"><?= View::e($a['email']) ?></td>
                        <td><?= View::e($a['display_name'] ?: '-') ?></td>
                        <td><?= (int)$a['quota_mb'] === 0 ? '<span class="badge bg-secondary">Ilimitado</span>' : ((int)$a['quota_mb'] . ' MB') ?></td>
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
                        <td class="text-muted small"><?= $a['last_login_at'] ?? 'Never' ?></td>
                        <?php if (!$ro): ?>
                        <td>
                            <a href="/mail/accounts/<?= $a['id'] ?>/edit" class="btn btn-outline-light btn-sm"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="/mail/accounts/<?= $a['id'] ?>/delete" class="d-inline"
                                  onsubmit="return confirm('Delete <?= View::e($a['email']) ?>?')">
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
    <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Aliases & Forwards</div>
    <div class="card-body">
        <?php if (!empty($aliases)): ?>
            <table class="table table-sm mb-3">
                <thead>
                    <tr><th>Source</th><th>Destination</th><th>Catchall</th><?php if (!$ro): ?><th></th><?php endif; ?></tr>
                </thead>
                <tbody>
                    <?php foreach ($aliases as $al): ?>
                    <tr>
                        <td><?= View::e($al['source']) ?></td>
                        <td><?= View::e($al['destination']) ?></td>
                        <td><?= $al['is_catchall'] ? '<span class="badge bg-info">Yes</span>' : '-' ?></td>
                        <?php if (!$ro): ?>
                        <td>
                            <form method="POST" action="/mail/domains/<?= $domain['id'] ?>/aliases/<?= $al['id'] ?>/delete" class="d-inline">
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
