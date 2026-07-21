<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="mb-3">
            <a href="/mail/domains/<?= $account['mail_domain_id'] ?>" class="text-muted text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i> <?= View::e($account['domain_name']) ?>
            </a>
        </div>
        <div class="card">
            <div class="card-header"><i class="bi bi-pencil me-2"></i>Edit: <?= View::e($account['email']) ?></div>
            <div class="card-body">
                <form method="POST" action="/mail/accounts/<?= $account['id'] ?>/update">
                    <?= View::csrf() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="text" class="form-control" value="<?= View::e($account['email']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">New Password</label>
                            <input type="password" name="password" class="form-control" minlength="8" placeholder="Leave empty to keep current">
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
