<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-pencil me-2"></i>Edit Customer: <?= View::e($customer['name']) ?></div>
            <div class="card-body">
                <form method="POST" action="/customers/<?= $customer['id'] ?>/update">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name *</label>
                            <input type="text" name="name" class="form-control" value="<?= View::e($customer['name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" class="form-control" value="<?= View::e($customer['email']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company</label>
                            <input type="text" name="company" class="form-control" value="<?= View::e($customer['company'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= View::e($customer['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?= $customer['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="suspended" <?= $customer['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3"><?= View::e($customer['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save</button>
                        <a href="/customers/<?= $customer['id'] ?>" class="btn btn-outline-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
