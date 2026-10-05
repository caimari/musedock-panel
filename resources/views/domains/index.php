<?php
use MuseDockPanel\View;
use MuseDockPanel\Services\CloudflareService;

// Get server IP (cached in settings — updated by monitor-collector)
$serverIp = \MuseDockPanel\Settings::get('server_public_ip', '');
if (empty($serverIp)) {
    $serverIp = trim(shell_exec('curl -s -4 --max-time 3 ifconfig.me 2>/dev/null') ?: '');
    if ($serverIp) \MuseDockPanel\Settings::set('server_public_ip', $serverIp);
}

// Celda de DNS con el ÚLTIMO estado guardado (la página no comprueba nada al
// cargar, salvo los dominios que nunca se han comprobado) + icono para
// comprobarlo ahora.
function renderDnsCell(string $domain, array $cache): string {
    $e = $cache[strtolower($domain)] ?? null;
    $badge = renderDnsBadge($e
        ? ['status' => $e['status'] ?? 'none', 'ips' => $e['records'] ?? []]
        : ['status' => 'pending', 'ips' => []]);
    $age = '';
    if ($e && !empty($e['checked_at'])) {
        $s = time() - (int)$e['checked_at'];
        $age = $s < 90 ? 'ahora' : ($s < 5400 ? 'hace ' . round($s / 60) . ' min' : ($s < 172800 ? 'hace ' . round($s / 3600) . ' h' : 'hace ' . round($s / 86400) . ' d'));
    }
    return '<span class="dns-badge">' . $badge . '</span>'
        . '<span class="dns-age text-muted ms-1" style="font-size:0.7rem;">' . View::e($age) . '</span>'
        . '<button type="button" class="btn btn-link btn-sm p-0 ms-1 dns-refresh" title="Comprobar ahora" style="color:#64748b;font-size:0.75rem;vertical-align:baseline;"><i class="bi bi-arrow-clockwise"></i></button>';
}

// Helper: render DNS status badge
function renderDnsBadge(array $dns): string {
    $status = $dns['status'];
    $ips = implode(', ', $dns['ips']);

    return match ($status) {
        'ok' => '<span class="badge" style="background: rgba(34,197,94,0.15); color: #22c55e;">
                    <i class="bi bi-check-circle me-1"></i>OK — ' . $ips . '
                 </span>',
        'cloudflare' => '<span class="badge" style="background: rgba(249,115,22,0.15); color: #f97316;">
                            <i class="bi bi-cloud-fill me-1"></i>Cloudflare Proxy
                         </span>
                         <small class="text-muted d-block">SSL via CF — ' . $ips . '</small>',
        'elsewhere' => '<span class="badge" style="background: rgba(251,191,36,0.15); color: #fbbf24;">
                            <i class="bi bi-exclamation-triangle me-1"></i>' . $ips . '
                         </span>
                         <small class="text-muted d-block">Points elsewhere</small>',
        'pending' => '<span class="badge" style="background: rgba(100,116,139,0.1); color: #94a3b8; font-size:0.7rem;">
                        <span class="spinner-border spinner-border-sm me-1" style="width:0.6rem;height:0.6rem;"></span>
                    </span>',
        default => '<span class="badge" style="background: rgba(239,68,68,0.15); color: #ef4444;">
                        <i class="bi bi-x-circle me-1"></i>No DNS
                    </span>',
    };
}
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-globe2 me-2"></i>All Domains</span>
        <span class="d-flex align-items-center gap-2">
            <small class="text-muted">Server IP: <code><?= View::e($serverIp) ?></code></small>
            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" id="dns-refresh-all" title="Comprobar el DNS de todos los dominios ahora">
                <i class="bi bi-arrow-clockwise me-1"></i>Comprobar DNS
            </button>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($accountDomains)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-globe2" style="font-size: 2rem;"></i>
                <p class="mt-2">No domains yet. Create a hosting account first.</p>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Domain</th>
                        <th>DNS Status</th>
                        <th>Account</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accountDomains as $acc): ?>
                    <?php $dns = ['status' => 'pending', 'ips' => []]; ?>
                    <tr>
                        <td class="ps-3">
                            <a href="/accounts/<?= $acc['id'] ?>" class="text-info text-decoration-none fw-semibold"><?= View::e($acc['domain']) ?></a>
                            <a href="https://<?= View::e($acc['domain']) ?>" target="_blank" class="ms-1" style="color:#64748b;font-size:0.75rem;" title="Open site"><i class="bi bi-box-arrow-up-right"></i></a>
                            <?php $md = $mailDomains[strtolower($acc['domain'])] ?? null; ?>
                            <?php if ($md): ?>
                                <a href="/mail/domains/<?= (int)$md['id'] ?>" class="badge text-decoration-none ms-1" style="background:rgba(168,85,247,0.15);color:#c084fc;font-size:0.65rem;" title="Este dominio tiene correo en el panel (<?= View::e($md['status']) ?>)"><i class="bi bi-envelope me-1"></i>Correo</a>
                            <?php endif; ?>
                        </td>
                        <td class="dns-cell" data-domain="<?= View::e($acc['domain']) ?>" data-cached="<?= isset($dnsCache[strtolower($acc['domain'])]) ? 1 : 0 ?>"><?= renderDnsCell($acc['domain'], $dnsCache) ?></td>
                        <td>
                            <a href="/accounts/<?= $acc['id'] ?>" class="text-decoration-none text-light">
                                <code><?= View::e($acc['username']) ?></code>
                            </a>
                        </td>
                        <td><?= View::e($acc['customer_name'] ?? '-') ?></td>
                        <td>
                            <span class="badge badge-<?= $acc['status'] === 'active' ? 'active' : 'suspended' ?>">
                                <?= $acc['status'] ?>
                            </span>
                        </td>
                        <td><small class="text-muted"><?= date('d/m/Y', strtotime($acc['created_at'])) ?></small></td>
                    </tr>

                    <?php
                        // Show alias domains for this account (from hosting_domains table)
                        $accAliases = array_filter($domains, fn($d) => (int)$d['account_id'] === (int)$acc['id'] && $d['domain'] !== $acc['domain']);
                        foreach ($accAliases as $alias):
                            $aliasDns = ['status' => 'pending', 'ips' => []];
                    ?>
                    <tr style="background: rgba(56,189,248,0.03);">
                        <td class="ps-3">
                            <span class="text-muted me-2">&darr;</span>
                            <a href="https://<?= View::e($alias['domain']) ?>" target="_blank" class="text-info text-decoration-none">
                                <?= View::e($alias['domain']) ?> <i class="bi bi-box-arrow-up-right small"></i>
                            </a>
                            <span class="badge bg-dark ms-1">alias</span>
                        </td>
                        <td class="dns-cell" data-domain="<?= View::e($alias['domain']) ?>" data-cached="<?= isset($dnsCache[strtolower($alias['domain'])]) ? 1 : 0 ?>"><?= renderDnsCell($alias['domain'], $dnsCache) ?></td>
                        <td colspan="4"></td>
                    </tr>
                    <?php endforeach; ?>

                    <?php
                        // Show subdomains for this account
                        $accSubs = $subdomainsByAccount[(int)$acc['id']] ?? [];
                        foreach ($accSubs as $sub):
                            $subDomain = $sub['subdomain'];
                            $subDns = ['status' => 'pending', 'ips' => []];
                    ?>
                    <tr style="background: rgba(168,85,247,0.03);">
                        <td class="ps-3">
                            <span class="text-muted me-2" style="margin-left:0.5rem;">&rdsh;</span>
                            <a href="/accounts/<?= $acc['id'] ?>/subdomains" class="text-info text-decoration-none">
                                <?= View::e($subDomain) ?>
                            </a>
                            <a href="https://<?= View::e($subDomain) ?>" target="_blank" class="ms-1" style="color:#64748b;font-size:0.75rem;" title="Open site"><i class="bi bi-box-arrow-up-right"></i></a>
                            <span class="badge ms-1" style="background: rgba(168,85,247,0.15); color: #a855f7; font-size:0.65rem;">subdomain</span>
                        </td>
                        <td class="dns-cell" data-domain="<?= View::e($subDomain) ?>" data-cached="<?= isset($dnsCache[strtolower($subDomain)]) ? 1 : 0 ?>"><?= renderDnsCell($subDomain, $dnsCache) ?></td>
                        <td colspan="4"></td>
                    </tr>
                    <?php endforeach; ?>

                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php
    // Split: hosting-linked vs standalone
    $hostingLinked = array_filter($aliasesAndRedirects, fn($r) => !empty($r['hosting_account_id']));
    $standaloneRedirects = array_filter($aliasesAndRedirects, fn($r) => empty($r['hosting_account_id']));
?>

<!-- Hosting Aliases & Redirects (linked to accounts) -->
<?php if (!empty($hostingLinked)): ?>
<div class="card mt-4">
    <div class="card-header">
        <i class="bi bi-diagram-3 me-2"></i>Hosting Aliases & Redirects
        <small class="text-muted ms-2">(vinculados a cuentas de hosting)</small>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Domain</th>
                    <th>Type</th>
                    <th>DNS Status</th>
                    <th>Target Account</th>
                    <th>Customer</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hostingLinked as $item):
                    $itemDns = ['status' => 'pending', 'ips' => []];
                ?>
                <tr>
                    <td class="ps-3">
                        <a href="https://<?= View::e($item['domain']) ?>" target="_blank" class="text-info text-decoration-none">
                            <?= View::e($item['domain']) ?> <i class="bi bi-box-arrow-up-right small"></i>
                        </a>
                    </td>
                    <td>
                        <?php if ($item['type'] === 'alias'): ?>
                            <span class="badge" style="background: rgba(56,189,248,0.15); color: #38bdf8;">
                                <i class="bi bi-files me-1"></i>Alias
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background: rgba(251,146,60,0.15); color: #fb923c;">
                                <i class="bi bi-arrow-right-circle me-1"></i><?= $item['redirect_code'] ?> Redirect
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="dns-cell" data-domain="<?= View::e($item['domain']) ?>" data-cached="<?= isset($dnsCache[strtolower($item['domain'])]) ? 1 : 0 ?>"><?= renderDnsCell($item['domain'], $dnsCache) ?></td>
                    <td>
                        <a href="/accounts/<?= $item['hosting_account_id'] ?>" class="text-decoration-none text-light">
                            <i class="bi bi-arrow-right me-1 text-muted"></i><?= View::e($item['account_domain'] ?? '') ?>
                            <code class="ms-1"><?= View::e($item['username'] ?? '') ?></code>
                        </a>
                    </td>
                    <td><?= View::e($item['customer_name'] ?? '-') ?></td>
                    <td><small class="text-muted"><?= date('d/m/Y', strtotime($item['created_at'])) ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Standalone Redirects (not linked to hosting) -->
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-arrow-right-circle me-2" style="color:#fb923c;"></i>Standalone Redirects</span>
        <div>
            <small class="text-muted me-3"><?= count($standaloneRedirects) ?> redirect(s)</small>
            <button class="btn btn-danger btn-sm py-0 px-2" onclick="document.getElementById('addRedirectForm').style.display = document.getElementById('addRedirectForm').style.display === 'none' ? '' : 'none'">
                <i class="bi bi-plus-lg me-1"></i>Redirect
            </button>
        </div>
    </div>

    <!-- Add redirect form -->
    <div id="addRedirectForm" style="display:none;" class="card-body" style="border-bottom:1px solid #334155;">
        <form action="/domains/add-redirect" method="POST">
            <?= View::csrf() ?>
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Dominio</label>
                    <input type="text" name="domain" class="form-control form-control-sm" placeholder="ejemplo.com" required style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Redirigir a</label>
                    <input type="text" name="target_url" class="form-control form-control-sm" placeholder="https://destino.com" required style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Cliente</label>
                    <select name="customer_id" class="form-select form-select-sm" style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                        <option value="">— Sin asignar —</option>
                        <?php foreach ($customers as $cust): ?>
                        <option value="<?= $cust['id'] ?>"><?= View::e($cust['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted">Codigo</label>
                    <select name="redirect_code" class="form-select form-select-sm" style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                        <option value="301">301</option>
                        <option value="302">302</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" name="preserve_path" checked>
                        <label class="form-check-label small text-muted">Path</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Crear</button>
                </div>
            </div>
        </form>
    </div>

    <?php if (!empty($standaloneRedirects)): ?>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Domain</th>
                    <th>Code</th>
                    <th>DNS Status</th>
                    <th>Target</th>
                    <th>Customer</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($standaloneRedirects as $item):
                    $itemDns = ['status' => 'pending', 'ips' => []];
                ?>
                <tr>
                    <td class="ps-3">
                        <a href="https://<?= View::e($item['domain']) ?>" target="_blank" class="text-info text-decoration-none">
                            <?= View::e($item['domain']) ?> <i class="bi bi-box-arrow-up-right small"></i>
                        </a>
                    </td>
                    <td>
                        <span class="badge" style="background: rgba(251,146,60,0.15); color: #fb923c;">
                            <?= $item['redirect_code'] ?>
                        </span>
                    </td>
                    <td class="dns-cell" data-domain="<?= View::e($item['domain']) ?>" data-cached="<?= isset($dnsCache[strtolower($item['domain'])]) ? 1 : 0 ?>"><?= renderDnsCell($item['domain'], $dnsCache) ?></td>
                    <td>
                        <i class="bi bi-arrow-right me-1 text-muted"></i>
                        <a href="<?= View::e($item['target_url'] ?? '#') ?>" target="_blank" class="text-decoration-none text-light">
                            <?= View::e($item['target_url'] ?? '-') ?>
                        </a>
                    </td>
                    <td><?= View::e($item['customer_name'] ?? '-') ?></td>
                    <td><small class="text-muted"><?= date('d/m/Y', strtotime($item['created_at'])) ?></small></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:0.7rem;" title="Eliminar"
                            onclick="confirmDeleteRedirect(<?= (int)$item['id'] ?>, <?= View::js($item['domain']) ?>)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body text-center text-muted py-3">
        <small>No hay redirects standalone. Usa el boton "+ Redirect" para crear uno.</small>
    </div>
    <?php endif; ?>
</div>

<script>
function confirmDeleteRedirect(id, domain) {
    Swal.fire({
        title: '<i class="bi bi-exclamation-triangle me-2" style="color:#ef4444;"></i>Eliminar redirect',
        html: '<p style="color:#e2e8f0;">Vas a eliminar el redirect <strong>' + domain + '</strong>.</p>' +
              '<p style="color:#94a3b8;font-size:0.85rem;">Se eliminara la ruta de Caddy y el certificado SSL.</p>' +
              '<input type="password" id="redirectDeletePw" class="form-control form-control-sm mt-3" placeholder="Password de administrador" ' +
              'style="background:#1e293b;border-color:#334155;color:#e2e8f0;">',
        background: '#0f172a',
        color: '#e2e8f0',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-trash me-1"></i>Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
        focusConfirm: false,
        preConfirm: function() {
            var pw = document.getElementById('redirectDeletePw').value;
            if (!pw) { Swal.showValidationMessage('Password requerido'); return false; }
            return pw;
        }
    }).then(function(result) {
        if (result.isConfirmed) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '/domains/delete-redirect';

            var csrf = document.querySelector('input[name=_csrf_token]');
            if (csrf) {
                var ci = document.createElement('input');
                ci.type = 'hidden'; ci.name = '_csrf_token'; ci.value = csrf.value;
                form.appendChild(ci);
            }

            var idInput = document.createElement('input');
            idInput.type = 'hidden'; idInput.name = 'id'; idInput.value = id;
            form.appendChild(idInput);

            var pwInput = document.createElement('input');
            pwInput.type = 'hidden'; pwInput.name = 'admin_password'; pwInput.value = result.value;
            form.appendChild(pwInput);

            document.body.appendChild(form);
            form.submit();
        }
    });
}

// DNS: la página pinta el último estado guardado. Aquí solo se comprueban los
// dominios que nunca se han comprobado; el resto, al pulsar el icono de la fila
// o "Comprobar DNS". Uno detrás de otro, para no saturar.
(function() {
    var csrf = document.querySelector('input[name=_csrf_token]')?.value || '';
    var queue = [];
    var running = false;
    function cellsFor(domain) { return document.querySelectorAll('.dns-cell[data-domain="' + CSS.escape(domain) + '"]'); }
    function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    function badge(data) {
        var ips = esc((data.records || []).join(', '));
        switch (data.status) {
            case 'ok': return '<span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle me-1"></i>OK — ' + ips + '</span>';
            case 'cloudflare': return '<span class="badge" style="background:rgba(249,115,22,0.15);color:#f97316;"><i class="bi bi-cloud-fill me-1"></i>CF Proxy</span>';
            case 'elsewhere': return '<span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i>' + ips + '</span><small class="text-muted d-block">Points elsewhere</small>';
            default: return '<span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-x-circle me-1"></i>No DNS</span>';
        }
    }
    function setLoading(domain) {
        cellsFor(domain).forEach(function(c) {
            var b = c.querySelector('.dns-refresh i'); if (b) b.className = 'spinner-border spinner-border-sm';
        });
    }
    function render(domain, html, age) {
        cellsFor(domain).forEach(function(c) {
            var b = c.querySelector('.dns-badge'); if (b) b.innerHTML = html;
            var a = c.querySelector('.dns-age'); if (a) a.textContent = age;
            var i = c.querySelector('.dns-refresh i'); if (i) i.className = 'bi bi-arrow-clockwise';
            c.dataset.cached = '1';
        });
    }
    function next() {
        if (!queue.length) { running = false; return; }
        running = true;
        var domain = queue.shift();
        setLoading(domain);
        fetch('/domains/check-dns', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'domain=' + encodeURIComponent(domain) + '&_csrf_token=' + encodeURIComponent(csrf)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) { render(domain, data.error ? '<span class="badge" style="background:rgba(100,116,139,0.1);color:#64748b;">?</span>' : badge(data), 'ahora'); })
        .catch(function() { render(domain, '<span class="badge" style="background:rgba(100,116,139,0.1);color:#64748b;">?</span>', ''); })
        .finally(next);
    }
    function enqueue(domain) {
        if (queue.indexOf(domain) === -1) queue.push(domain);
        if (!running) next();
    }
    // Al cargar: solo los que no tienen estado guardado.
    document.querySelectorAll('.dns-cell[data-domain][data-cached="0"]').forEach(function(c) { enqueue(c.dataset.domain); });
    document.querySelectorAll('.dns-cell .dns-refresh').forEach(function(btn) {
        btn.addEventListener('click', function() { enqueue(btn.closest('.dns-cell').dataset.domain); });
    });
    var all = document.getElementById('dns-refresh-all');
    if (all) all.addEventListener('click', function() {
        document.querySelectorAll('.dns-cell[data-domain]').forEach(function(c) { enqueue(c.dataset.domain); });
    });
})();
</script>

<div class="mt-3 p-3 rounded" style="background: rgba(56,189,248,0.05); border: 1px solid #334155;">
    <small class="text-muted">
        <i class="bi bi-info-circle me-1"></i>
        <strong>DNS Status:</strong>
        <span style="color: #22c55e;">OK</span> = domain points directly to this server (<?= View::e($serverIp) ?>).
        <span style="color: #f97316;"><i class="bi bi-cloud-fill"></i> Cloudflare Proxy</span> = domain goes through Cloudflare (SSL provided by CF, certificates auto-generated when proxy is disabled).
        <span style="color: #fbbf24;">Warning</span> = domain resolves but to a different IP.
        <span style="color: #ef4444;">No DNS</span> = domain has no A record.
        <br>
        <i class="bi bi-info-circle me-1 mt-1"></i>
        <strong>Alias</strong> = same content as the main domain (multiple domains, one site).
        <strong>Redirect</strong> = redirects visitors to the main domain (SEO migration, 301/302).
    </small>
</div>
