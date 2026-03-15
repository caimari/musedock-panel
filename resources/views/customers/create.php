<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-person-plus me-2"></i>New Customer</div>
            <div class="card-body">
                <form method="POST" action="/customers/store">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company</label>
                            <input type="text" name="company" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Create Customer</button>
                        <a href="/customers" class="btn btn-outline-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
