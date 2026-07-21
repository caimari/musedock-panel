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
                                   value="<?= View::e(\MuseDockPanel\Settings::get('mail_default_quota_mb', '1024')) ?>" min="0">
                            <div class="form-text"><strong>0 = sin límite</strong> (ilimitado). Cualquier otro valor es el máximo en MB.</div>
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
                            <label class="form-label">Cuenta de hosting vinculada</label>
                            <?php
                                // Auto-select the hosting whose domain matches this mail domain
                                // (the sensible default). Falls back to "none" if the domain has
                                // no hosting on this server (mail-only domain).
                                $mailDom = strtolower((string)($domain['domain'] ?? ''));
                                $autoId = null;
                                foreach ($hostingAccounts as $h) {
                                    if (strtolower((string)$h['domain']) === $mailDom) { $autoId = (int)$h['id']; break; }
                                }
                            ?>
                            <select name="account_id" class="form-select">
                                <option value="">-- Ninguna (correo independiente) --</option>
                                <?php foreach ($hostingAccounts as $h): ?>
                                    <option value="<?= $h['id'] ?>" <?= ($autoId === (int)$h['id']) ? 'selected' : '' ?>>
                                        <?= View::e($h['domain']) ?> (<?= View::e($h['username']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <?php if ($autoId !== null): ?>
                                    Vinculado automáticamente al hosting de <strong><?= View::e($mailDom) ?></strong>. Cámbialo solo si es correo independiente.
                                <?php else: ?>
                                    No hay hosting para <strong><?= View::e($mailDom) ?></strong> en este servidor — buzón de solo correo. Puedes vincularlo a otro hosting si quieres.
                                <?php endif; ?>
                            </div>
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
