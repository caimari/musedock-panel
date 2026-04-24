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
