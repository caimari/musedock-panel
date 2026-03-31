<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-plus-circle me-2"></i>New Hosting Account</div>
            <div class="card-body">
                <form id="createAccountForm" method="POST" action="/accounts/store">
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
                        <button type="submit" class="btn btn-primary" id="btnCreate"><i class="bi bi-check-lg me-1"></i> Create Account</button>
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

    domainInput.addEventListener('input', function() {
        var domain = this.value.trim().toLowerCase();
        if (domain && !usernameInput.dataset.manual) {
            var suggested = domain.replace(/[^a-z0-9]/g, '');
            if (suggested.length > 20) suggested = suggested.substring(0, 20);
            if (suggested.length >= 3) usernameInput.value = suggested;
        }

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

// ── Async provisioning with SSE modal ──
(function() {
    var STORAGE_KEY = 'mdp_provision_token';
    var form = document.getElementById('createAccountForm');

    // Build modal HTML
    function modalHtml() {
        return '<div style="text-align:left;">' +
            '<div id="provStepBadge" class="mb-2"></div>' +
            '<div class="progress mb-2" style="height:22px;background:#1e293b;border-radius:8px;">' +
                '<div id="provProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%;background:#38bdf8;transition:width .3s;"></div>' +
            '</div>' +
            '<div id="provProgressText" class="text-muted small mb-2" style="text-align:center;">Iniciando...</div>' +
            '<div id="provLog" style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:10px 12px;max-height:220px;overflow-y:auto;font-family:monospace;font-size:0.82rem;line-height:1.5;"></div>' +
            '<div id="provResult" style="display:none;" class="mt-3"></div>' +
        '</div>';
    }

    function addLog(el, text, color) {
        var div = document.createElement('div');
        div.style.color = color || '#94a3b8';
        div.textContent = text;
        el.appendChild(div);
        el.scrollTop = el.scrollHeight;
    }

    function stepBadge(el, text) {
        el.innerHTML = '<span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:0.85rem;padding:5px 12px;">' +
            '<span class="spinner-border spinner-border-sm me-1" style="width:14px;height:14px;"></span>' + text + '</span>';
    }

    // Connect SSE and drive the modal
    function connectSSE(token) {
        var logEl = document.getElementById('provLog');
        var stepEl = document.getElementById('provStepBadge');
        var bar = document.getElementById('provProgressBar');
        var barText = document.getElementById('provProgressText');
        var resultEl = document.getElementById('provResult');

        var es = new EventSource('/accounts/provision-stream?token=' + token);
        var receivedAnyEvent = false;

        // Fallback: if no SSE events after 5 seconds, switch to polling
        var sseTimeout = setTimeout(function() {
            if (!receivedAnyEvent) {
                es.close();
                addLog(logEl, 'Cambiando a modo polling...', '#fbbf24');
                pollStatus(token);
            }
        }, 5000);

        es.addEventListener('log', function(e) {
            receivedAnyEvent = true;
            var color = '#94a3b8';
            if (e.data.indexOf('ERROR') !== -1 || e.data.indexOf('Error') !== -1) color = '#ef4444';
            else if (e.data.indexOf('AVISO') !== -1) color = '#fbbf24';
            else if (e.data.indexOf('creado') !== -1 || e.data.indexOf('completado') !== -1 || e.data.indexOf('correctamente') !== -1) color = '#22c55e';
            addLog(logEl, e.data, color);
        });

        es.addEventListener('step', function(e) {
            receivedAnyEvent = true;
            stepBadge(stepEl, e.data);
        });

        es.addEventListener('progress', function(e) {
            receivedAnyEvent = true;
            try {
                var p = JSON.parse(e.data);
                if (p.percent !== undefined) {
                    bar.style.width = p.percent + '%';
                    barText.textContent = 'Paso ' + p.step + ' de ' + p.total + ' (' + p.percent + '%)';
                }
            } catch(ex) {}
        });

        es.addEventListener('error', function(e) {
            if (e.data) addLog(logEl, e.data, '#ef4444');
        });

        es.addEventListener('done', function(e) {
            receivedAnyEvent = true;
            clearTimeout(sseTimeout);
            es.close();
            localStorage.removeItem(STORAGE_KEY);

            var result;
            try { result = JSON.parse(e.data); } catch(ex) { result = {ok: false}; }

            bar.classList.remove('progress-bar-animated', 'progress-bar-striped');

            if (result.ok) {
                bar.style.width = '100%';
                bar.style.background = '#22c55e';
                barText.textContent = 'Completado';
                stepEl.innerHTML = '<span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.85rem;padding:5px 12px;">' +
                    '<i class="bi bi-check-circle me-1"></i>Hosting creado</span>';

                var html = '<div class="p-3 rounded" style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.3);">' +
                    '<div style="color:#22c55e;font-weight:600;margin-bottom:8px;"><i class="bi bi-check-circle-fill me-1"></i>Hosting creado correctamente</div>' +
                    '<div class="small text-muted">Dominio: <strong style="color:#e2e8f0;">' + (result.domain || '') + '</strong></div>' +
                    '<div class="small text-muted">Usuario: <strong style="color:#e2e8f0;">' + (result.username || '') + '</strong></div>';
                if (result.warnings && result.warnings.length > 0) {
                    html += '<div class="mt-2 small" style="color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i>' + result.warnings.join('<br>') + '</div>';
                }
                html += '</div>';
                html += '<div class="mt-3 d-flex gap-2 justify-content-center">' +
                    '<a href="/accounts/' + result.account_id + '" class="btn btn-sm btn-primary"><i class="bi bi-eye me-1"></i>Ver cuenta</a>' +
                    '<a href="/accounts" class="btn btn-sm btn-outline-light"><i class="bi bi-list me-1"></i>Listado</a>' +
                    '<a href="/accounts/create" class="btn btn-sm btn-outline-light"><i class="bi bi-plus me-1"></i>Crear otra</a>' +
                '</div>';
                resultEl.innerHTML = html;
                resultEl.style.display = '';

                // Update SweetAlert to allow close
                Swal.update({ allowOutsideClick: true, showConfirmButton: false });
            } else {
                bar.style.width = '100%';
                bar.style.background = '#ef4444';
                barText.textContent = 'Error';
                stepEl.innerHTML = '<span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;font-size:0.85rem;padding:5px 12px;">' +
                    '<i class="bi bi-x-circle me-1"></i>Error en la creacion</span>';

                resultEl.innerHTML = '<div class="p-3 rounded" style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.3);">' +
                    '<div style="color:#ef4444;font-weight:600;"><i class="bi bi-x-circle-fill me-1"></i>La creacion del hosting fallo</div>' +
                    '<div class="small text-muted mt-1">Revisa el log de arriba para mas detalles.</div>' +
                '</div>' +
                '<div class="mt-3 text-center"><button onclick="Swal.close()" class="btn btn-sm btn-outline-light">Cerrar</button></div>';
                resultEl.style.display = '';

                Swal.update({ allowOutsideClick: true, showConfirmButton: false });
            }
        });

        es.onerror = function() {
            // SSE connection lost — might be transient or page issue
            es.close();
            addLog(logEl, 'Conexion perdida. Verificando estado...', '#fbbf24');
            // Poll for final status
            setTimeout(function() { pollStatus(token); }, 1500);
        };
    }

    // Poll status (reconnection after SSE drop or page reload)
    function pollStatus(token) {
        fetch('/accounts/provision-status?token=' + token)
        .then(function(r) { return r.json(); })
        .then(function(status) {
            if (!status.active && !status.done) {
                // Token expired or invalid
                localStorage.removeItem(STORAGE_KEY);
                return;
            }

            // Show modal with existing logs
            SwalDark.fire({
                title: '<i class="bi bi-server me-2"></i>Creando hosting',
                html: modalHtml(),
                showConfirmButton: false,
                allowOutsideClick: false,
                allowEscapeKey: false,
                width: 580,
                didOpen: function() {
                    var logEl = document.getElementById('provLog');
                    var stepEl = document.getElementById('provStepBadge');
                    var bar = document.getElementById('provProgressBar');
                    var barText = document.getElementById('provProgressText');
                    var resultEl = document.getElementById('provResult');

                    // Replay existing logs
                    if (status.logs) {
                        status.logs.forEach(function(line) {
                            var color = '#94a3b8';
                            if (line.indexOf('ERROR') !== -1) color = '#ef4444';
                            else if (line.indexOf('AVISO') !== -1) color = '#fbbf24';
                            else if (line.indexOf('creado') !== -1 || line.indexOf('correctamente') !== -1) color = '#22c55e';
                            addLog(logEl, line, color);
                        });
                    }
                    if (status.step) stepBadge(stepEl, status.step);
                    if (status.progress && status.progress.percent !== undefined) {
                        bar.style.width = status.progress.percent + '%';
                        barText.textContent = 'Paso ' + status.progress.step + ' de ' + status.progress.total + ' (' + status.progress.percent + '%)';
                    }

                    if (status.done) {
                        localStorage.removeItem(STORAGE_KEY);
                        bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
                        var result = status.result || {};
                        if (result.ok) {
                            bar.style.width = '100%';
                            bar.style.background = '#22c55e';
                            barText.textContent = 'Completado';
                            stepEl.innerHTML = '<span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.85rem;padding:5px 12px;">' +
                                '<i class="bi bi-check-circle me-1"></i>Hosting creado</span>';
                            resultEl.innerHTML = '<div class="p-3 rounded" style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.3);">' +
                                '<div style="color:#22c55e;font-weight:600;"><i class="bi bi-check-circle-fill me-1"></i>Hosting creado correctamente</div>' +
                                '<div class="small text-muted">Dominio: <strong style="color:#e2e8f0;">' + (result.domain || status.domain || '') + '</strong></div>' +
                                '</div>' +
                                '<div class="mt-3 d-flex gap-2 justify-content-center">' +
                                (result.account_id ? '<a href="/accounts/' + result.account_id + '" class="btn btn-sm btn-primary"><i class="bi bi-eye me-1"></i>Ver cuenta</a>' : '') +
                                '<a href="/accounts" class="btn btn-sm btn-outline-light"><i class="bi bi-list me-1"></i>Listado</a>' +
                                '</div>';
                            resultEl.style.display = '';
                            Swal.update({ allowOutsideClick: true });
                        } else {
                            bar.style.width = '100%';
                            bar.style.background = '#ef4444';
                            barText.textContent = 'Error';
                            resultEl.innerHTML = '<div class="p-3 rounded" style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.3);">' +
                                '<div style="color:#ef4444;"><i class="bi bi-x-circle-fill me-1"></i>La creacion fallo. Revisa el log.</div></div>' +
                                '<div class="mt-3 text-center"><button onclick="Swal.close()" class="btn btn-sm btn-outline-light">Cerrar</button></div>';
                            resultEl.style.display = '';
                            Swal.update({ allowOutsideClick: true });
                        }
                    } else {
                        // Still running — keep polling
                        var pollInterval = setInterval(function() {
                            fetch('/accounts/provision-status?token=' + token)
                            .then(function(r) { return r.json(); })
                            .then(function(s) {
                                // Add new logs
                                var currentCount = logEl.childElementCount;
                                if (s.logs && s.logs.length > currentCount) {
                                    for (var i = currentCount; i < s.logs.length; i++) {
                                        var color = '#94a3b8';
                                        if (s.logs[i].indexOf('ERROR') !== -1) color = '#ef4444';
                                        else if (s.logs[i].indexOf('AVISO') !== -1) color = '#fbbf24';
                                        else if (s.logs[i].indexOf('creado') !== -1 || s.logs[i].indexOf('correctamente') !== -1) color = '#22c55e';
                                        addLog(logEl, s.logs[i], color);
                                    }
                                }
                                if (s.step) stepBadge(stepEl, s.step);
                                if (s.progress && s.progress.percent !== undefined) {
                                    bar.style.width = s.progress.percent + '%';
                                    barText.textContent = 'Paso ' + s.progress.step + ' de ' + s.progress.total + ' (' + s.progress.percent + '%)';
                                }
                                if (s.done) {
                                    clearInterval(pollInterval);
                                    localStorage.removeItem(STORAGE_KEY);
                                    bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
                                    var r = s.result || {};
                                    if (r.ok) {
                                        bar.style.width = '100%'; bar.style.background = '#22c55e'; barText.textContent = 'Completado';
                                        stepEl.innerHTML = '<span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.85rem;padding:5px 12px;"><i class="bi bi-check-circle me-1"></i>Hosting creado</span>';
                                        resultEl.innerHTML = '<div class="p-3 rounded" style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.3);">' +
                                            '<div style="color:#22c55e;font-weight:600;"><i class="bi bi-check-circle-fill me-1"></i>Hosting creado</div>' +
                                            '<div class="small text-muted">Dominio: <strong style="color:#e2e8f0;">' + (r.domain || '') + '</strong></div></div>' +
                                            '<div class="mt-3 d-flex gap-2 justify-content-center">' +
                                            (r.account_id ? '<a href="/accounts/' + r.account_id + '" class="btn btn-sm btn-primary"><i class="bi bi-eye me-1"></i>Ver cuenta</a>' : '') +
                                            '<a href="/accounts" class="btn btn-sm btn-outline-light"><i class="bi bi-list me-1"></i>Listado</a></div>';
                                        resultEl.style.display = '';
                                    } else {
                                        bar.style.width = '100%'; bar.style.background = '#ef4444'; barText.textContent = 'Error';
                                        resultEl.innerHTML = '<div class="p-3 rounded" style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.3);">' +
                                            '<div style="color:#ef4444;"><i class="bi bi-x-circle-fill me-1"></i>La creacion fallo</div></div>' +
                                            '<div class="mt-3 text-center"><button onclick="Swal.close()" class="btn btn-sm btn-outline-light">Cerrar</button></div>';
                                        resultEl.style.display = '';
                                    }
                                    Swal.update({ allowOutsideClick: true });
                                }
                            }).catch(function() {});
                        }, 1000);
                    }
                }
            });
        })
        .catch(function() {
            localStorage.removeItem(STORAGE_KEY);
        });
    }

    // Check on page load for active/completed provision
    var savedToken = localStorage.getItem(STORAGE_KEY);
    if (savedToken) {
        pollStatus(savedToken);
    }

    // Submit form directly (synchronous creation + redirect)
    form.addEventListener('submit', function(e) {
        var btn = document.getElementById('btnCreate');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creando...';
        // Let the form submit normally to /accounts/store (POST)
        form.action = '/accounts/store';
    });
})();
</script>
