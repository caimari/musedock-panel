<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-plus-circle me-2"></i>New Hosting Account</div>
            <div class="card-body">
                <form method="POST" action="/accounts/store">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Domain *</label>
                            <input type="text" name="domain" class="form-control" placeholder="example.com" required>
                            <small class="text-muted">Primary domain for this account</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">System Username *</label>
                            <input type="text" name="username" class="form-control" placeholder="examplecomcalamar" required pattern="[a-z][a-z0-9_]{2,30}">
                            <small class="text-muted">Linux user for SFTP/SSH (lowercase, 3-31 chars)</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password *</label>
                            <div class="input-group">
                                <input type="text" name="password" id="passwordField" class="form-control" required minlength="8">
                                <button type="button" class="btn btn-outline-light" onclick="generatePassword()" title="Generate password">
                                    <i class="bi bi-key"></i>
                                </button>
                            </div>
                            <small class="text-muted">Password for SFTP/SSH access (min 8 chars)</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Customer</label>
                            <select name="customer_id" class="form-select">
                                <option value="">-- No customer (internal) --</option>
                                <?php foreach ($customers ?? [] as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= View::e($c['name']) ?> (<?= View::e($c['email']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($customers)): ?>
                                <small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>No customers yet. <a href="/customers/create" class="text-info">Create one first</a> or leave as internal.</small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">PHP Version</label>
                            <select name="php_version" class="form-select">
                                <?php
                                $phpVersions = [];
                                foreach (glob('/etc/php/*/fpm') as $fpmDir) {
                                    $phpVersions[] = basename(dirname($fpmDir));
                                }
                                rsort($phpVersions); // newest first
                                foreach ($phpVersions as $i => $ver):
                                ?>
                                    <option value="<?= $ver ?>" <?= $i === 0 ? 'selected' : '' ?>>PHP <?= $ver ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Disk Quota (MB)</label>
                            <input type="number" name="disk_quota_mb" class="form-control" value="1024" min="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Shell Access</label>
                            <select name="shell" class="form-select">
                                <option value="/bin/bash">Bash (full SSH)</option>
                                <option value="/usr/sbin/nologin" selected>No login (SFTP only)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Notes about this account..."></textarea>
                        </div>
                    </div>

                    <!-- DNS check result -->
                    <div id="dnsCheckResult" class="mt-3" style="display:none;"></div>

                    <div class="mt-3 p-3 rounded" style="background: rgba(56,189,248,0.05); border: 1px solid #334155;">
                        <small class="text-muted">
                            <i class="bi bi-folder me-1"></i>
                            Directory structure: <code>/var/www/vhosts/[domain]/httpdocs/</code> (document root), <code>logs/</code>, <code>tmp/</code>, <code>sessions/</code><br>
                            <i class="bi bi-people me-1"></i>
                            User: <code>[username]</code>, Group: <code>www-data</code><br>
                            <i class="bi bi-shield-lock me-1"></i>
                            SSL certificate will be obtained automatically when DNS points to this server.
                            If DNS is not configured yet, the account will be created without SSL until DNS is ready.
                        </small>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Create Account</button>
                        <a href="/accounts" class="btn btn-outline-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var domainInput = document.querySelector('input[name="domain"]');
    var usernameInput = document.querySelector('input[name="username"]');
    var dnsResult = document.getElementById('dnsCheckResult');
    var timer = null;

    // Auto-suggest username from domain (format: domaincomcalamar like Plesk)
    domainInput.addEventListener('input', function() {
        var domain = this.value.trim().toLowerCase();
        if (domain && !usernameInput.dataset.manual) {
            // Convert example.com → examplecomcalamar
            var suggested = domain.replace(/[^a-z0-9]/g, '');
            if (suggested.length > 20) suggested = suggested.substring(0, 20);
            if (suggested.length >= 3) usernameInput.value = suggested;
        }

        // DNS check with debounce
        clearTimeout(timer);
        if (domain.length > 3 && domain.includes('.')) {
            timer = setTimeout(function() { checkDns(domain); }, 800);
        } else {
            dnsResult.style.display = 'none';
        }
    });

    usernameInput.addEventListener('input', function() { this.dataset.manual = '1'; });

    function checkDns(domain) {
        dnsResult.style.display = 'block';
        dnsResult.innerHTML = '<small class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Checking DNS for ' + domain + '...</small>';

        fetch('/domains/check-dns', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'domain=' + encodeURIComponent(domain)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.points_here) {
                dnsResult.innerHTML = '<div class="p-2 rounded" style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);"><small style="color:#22c55e;"><i class="bi bi-check-circle me-1"></i>DNS OK — ' + domain + ' points to this server (' + data.server_ip + '). SSL will be configured automatically.</small></div>';
            } else if (data.records && data.records.length > 0) {
                dnsResult.innerHTML = '<div class="p-2 rounded" style="background:rgba(251,191,36,0.1);border:1px solid rgba(251,191,36,0.3);"><small style="color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i>' + domain + ' points to ' + data.records.join(', ') + ' (not this server: ' + data.server_ip + '). Account will be created but SSL will fail until DNS is updated.</small></div>';
            } else {
                dnsResult.innerHTML = '<div class="p-2 rounded" style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);"><small style="color:#ef4444;"><i class="bi bi-x-circle me-1"></i>' + domain + ' has no DNS A record. Account will be created but SSL will fail until DNS is configured to point to ' + data.server_ip + '.</small></div>';
            }
        })
        .catch(function() {
            dnsResult.innerHTML = '<small class="text-muted">Could not check DNS.</small>';
        });
    }
})();

function generatePassword() {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
    var password = '';
    for (var i = 0; i < 16; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('passwordField').value = password;
}
</script>
