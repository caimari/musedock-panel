<?php use MuseDockPanel\View; ?>
<?php
    $mailCreateAvailable = (bool)($mailCreateAvailable ?? true);
    $mailCreateBlockedReason = (string)($mailCreateBlockedReason ?? '');
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-envelope-plus me-2"></i>New Mail Domain</div>
            <div class="card-body text-light">
                <?php if (!$mailCreateAvailable): ?>
                    <div class="alert alert-warning" style="background:rgba(251,191,36,.12);border-color:rgba(251,191,36,.32);color:#fde68a;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <?= View::e($mailCreateBlockedReason !== '' ? $mailCreateBlockedReason : 'No hay backend de correo operativo.') ?>
                    </div>
                <?php endif; ?>
                <form method="POST" action="/mail/domains/store">
                    <?= View::csrf() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-light">Domain *</label>
                            <input type="text" name="domain" class="form-control" placeholder="example.com" value="<?= View::e($_GET['domain'] ?? '') ?>" required <?= $mailCreateAvailable ? '' : 'disabled' ?>>
                            <div class="form-text text-secondary">The domain for mail accounts (user@domain).</div>
                            <div id="hosting-hint" class="small mt-2 p-2 rounded" style="display:none;background:rgba(56,189,248,0.08);border:1px solid rgba(56,189,248,0.3);color:#7dd3fc;"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light">Customer</label>
                            <select name="customer_id" class="form-select" <?= $mailCreateAvailable ? '' : 'disabled' ?>>
                                <option value="">-- None --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= View::e($c['name']) ?> (<?= View::e($c['email']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light">Mail Node</label>
                            <select name="mail_node_id" class="form-select" <?= $mailCreateAvailable ? '' : 'disabled' ?>>
                                <option value="">Local (this server)</option>
                                <?php foreach ($mailNodes as $n): ?>
                                    <option value="<?= $n['id'] ?>"><?= View::e($n['name']) ?> (<?= $n['status'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-secondary">Where mailboxes for this domain will be physically stored.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light">Max Accounts</label>
                            <input type="number" name="max_accounts" class="form-control" value="0" min="0" <?= $mailCreateAvailable ? '' : 'disabled' ?>>
                            <div class="form-text text-secondary">0 = unlimited</div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary" <?= $mailCreateAvailable ? '' : 'disabled' ?>><i class="bi bi-check-lg me-1"></i> Create Domain</button>
                        <a href="/mail" class="btn btn-outline-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
// Aviso si el dominio ya es una web del panel: su correo se verá también en la
// ficha del hosting, y se propone su cliente (si no se ha elegido otro).
(function () {
    var map = <?= json_encode($hostingMap ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var input = document.querySelector('input[name="domain"]');
    var sel = document.querySelector('select[name="customer_id"]');
    var hint = document.getElementById('hosting-hint');
    if (!input || !hint) return;
    var autoSet = false;
    function check() {
        var h = map[input.value.trim().toLowerCase()];
        if (!h) {
            hint.style.display = 'none';
            if (autoSet && sel) { sel.value = ''; autoSet = false; }
            return;
        }
        hint.textContent = '';
        var i = document.createElement('i'); i.className = 'bi bi-info-circle me-1'; hint.appendChild(i);
        hint.appendChild(document.createTextNode('Este dominio ya es una web del panel (' + h.kind + ' del hosting '));
        var a = document.createElement('a'); a.href = '/accounts/' + h.id; a.textContent = h.domain; a.className = 'text-info'; hint.appendChild(a);
        hint.appendChild(document.createTextNode('). Su correo se verá también en la ficha de ese hosting.'));
        hint.style.display = '';
        if (sel && h.customer_id && (sel.value === '' || autoSet)) { sel.value = String(h.customer_id); autoSet = true; }
    }
    input.addEventListener('input', check);
    check();
})();
</script>
