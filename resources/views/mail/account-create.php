<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="mb-3">
            <a href="/mail/domains/<?= $domain['id'] ?>" class="text-muted text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i> <?= View::e($domain['domain']) ?>
            </a>
        </div>
        <div class="card">
            <div class="card-header"><i class="bi bi-mailbox me-2"></i>New Mailbox for <?= View::e($domain['domain']) ?></div>
            <div class="card-body">
                <form method="POST" action="/mail/domains/<?= $domain['id'] ?>/accounts/store">
                    <?= View::csrf() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Username *</label>
                            <div class="input-group">
                                <input type="text" name="local_part" class="form-control" placeholder="user" required>
                                <span class="input-group-text">@<?= View::e($domain['domain']) ?></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" minlength="8" required>
                            <div class="form-text">Minimum 8 characters.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Display Name</label>
                            <input type="text" name="display_name" class="form-control" placeholder="John Doe">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Quota (MB)</label>
                            <input type="number" name="quota_mb" class="form-control"
                                   value="<?= View::e(\MuseDockPanel\Settings::get('mail_default_quota_mb', '1024')) ?>" min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Customer</label>
                            <select name="customer_id" class="form-select">
                                <option value="">-- None --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= ($domain['customer_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                                        <?= View::e($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Link to Hosting Account</label>
                            <select name="account_id" class="form-select">
                                <option value="">-- None (standalone mail) --</option>
                                <?php foreach ($hostingAccounts as $h): ?>
                                    <option value="<?= $h['id'] ?>"><?= View::e($h['domain']) ?> (<?= View::e($h['username']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Optional. Link this mailbox to a hosting account.</div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Create Mailbox</button>
                        <a href="/mail/domains/<?= $domain['id'] ?>" class="btn btn-outline-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
