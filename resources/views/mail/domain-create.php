<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-envelope-plus me-2"></i>New Mail Domain</div>
            <div class="card-body">
                <form method="POST" action="/mail/domains/store">
                    <?= View::csrf() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Domain *</label>
                            <input type="text" name="domain" class="form-control" placeholder="example.com" value="<?= View::e($_GET['domain'] ?? '') ?>" required>
                            <div class="form-text">The domain for mail accounts (user@domain).</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Customer</label>
                            <select name="customer_id" class="form-select">
                                <option value="">-- None --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= View::e($c['name']) ?> (<?= View::e($c['email']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mail Node</label>
                            <select name="mail_node_id" class="form-select">
                                <option value="">Local (this server)</option>
                                <?php foreach ($mailNodes as $n): ?>
                                    <option value="<?= $n['id'] ?>"><?= View::e($n['name']) ?> (<?= $n['status'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Where mailboxes for this domain will be physically stored.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Max Accounts</label>
                            <input type="number" name="max_accounts" class="form-control" value="0" min="0">
                            <div class="form-text">0 = unlimited</div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Create Domain</button>
                        <a href="/mail" class="btn btn-outline-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
