<?php use MuseDockPanel\View; use MuseDockPanel\Flash; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::e($pageTitle ?? 'Dashboard') ?> — <?= View::e($panelName) ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --sidebar-w: 250px; --header-h: 56px; }
        body { background: #0f172a; color: #e2e8f0; font-family: 'Inter', -apple-system, sans-serif; }
        .sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-w); height: 100vh;
                   background: #1e293b; border-right: 1px solid #334155; z-index: 100; display: flex; flex-direction: column; }
        .sidebar-brand { padding: 1rem 1.25rem; border-bottom: 1px solid #334155; }
        .sidebar-brand h5 { margin: 0; color: #38bdf8; font-weight: 700; font-size: 1.1rem; }
        .sidebar-brand small { color: #64748b; font-size: 0.7rem; }
        .sidebar-nav { padding: 0.75rem 0; flex: 1; }
        .sidebar-nav a { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 1.25rem;
                         color: #94a3b8; text-decoration: none; font-size: 0.9rem; transition: all 0.15s; }
        .sidebar-nav a:hover, .sidebar-nav a.active { background: rgba(56,189,248,0.1); color: #38bdf8; }
        .sidebar-nav a i { font-size: 1.1rem; width: 20px; text-align: center; }
        .sidebar-footer { padding: 1rem 1.25rem; border-top: 1px solid #334155; font-size: 0.8rem; }
        .sidebar-footer a { color: #94a3b8; text-decoration: none; }
        .sidebar-footer a:hover { color: #f87171; }
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; }
        .top-bar { padding: 1rem 2rem; border-bottom: 1px solid #1e293b; display: flex;
                   justify-content: space-between; align-items: center; background: #0f172a; }
        .top-bar h4 { margin: 0; font-size: 1.2rem; font-weight: 600; }
        .content-area { padding: 1.5rem 2rem; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; }
        .card-header { background: transparent; border-bottom: 1px solid #334155; padding: 1rem 1.25rem; font-weight: 600; }
        .table { color: #e2e8f0; --bs-table-bg: transparent; --bs-table-striped-bg: rgba(255,255,255,0.02); --bs-table-hover-bg: rgba(56,189,248,0.05); }
        .table thead th { border-bottom: 1px solid #334155; color: #94a3b8; font-weight: 500; font-size: 0.85rem; text-transform: uppercase; background: transparent; }
        .table td { border-bottom: 1px solid rgba(51,65,85,0.5); vertical-align: middle; background: transparent; }
        .table-hover tbody tr:hover { --bs-table-hover-bg: rgba(56,189,248,0.05); }
        .table-sm td, .table-sm th { background: transparent; }
        .stat-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 1.25rem; }
        .stat-card .stat-value { font-size: 1.75rem; font-weight: 700; color: #f1f5f9; }
        .stat-card .stat-label { color: #64748b; font-size: 0.85rem; margin-top: 0.25rem; }
        .stat-card .stat-icon { font-size: 2rem; color: #334155; }
        .badge-active { background: rgba(34,197,94,0.15); color: #22c55e; }
        .badge-suspended { background: rgba(239,68,68,0.15); color: #ef4444; }
        .progress { background: #334155; height: 8px; border-radius: 4px; }
        .form-control, .form-select { background: #0f172a; border: 1px solid #334155; color: #e2e8f0; }
        .form-control:focus, .form-select:focus { background: #0f172a; border-color: #38bdf8; color: #e2e8f0; box-shadow: 0 0 0 2px rgba(56,189,248,0.2); }
        .form-control:-webkit-autofill,
        .form-control:-webkit-autofill:hover,
        .form-control:-webkit-autofill:focus,
        .form-select:-webkit-autofill,
        .form-select:-webkit-autofill:hover,
        .form-select:-webkit-autofill:focus {
            -webkit-text-fill-color: #f1f5f9 !important;
            caret-color: #f1f5f9 !important;
            box-shadow: 0 0 0 1000px #0f172a inset !important;
            transition: background-color 9999s ease-in-out 0s;
            border-color: #38bdf8;
        }
        .btn-primary { background: #0ea5e9; border-color: #0ea5e9; }
        .btn-primary:hover { background: #0284c7; border-color: #0284c7; }
        .btn-outline-light { border-color: #334155; color: #94a3b8; }
        .btn-outline-light:hover { background: rgba(56,189,248,0.1); border-color: #38bdf8; color: #38bdf8; }
        .form-label { color: #94a3b8; font-size: 0.85rem; font-weight: 500; }
        .form-text { color: #94a3b8 !important; }
        label { color: #e2e8f0; }
        .card-header { color: #e2e8f0; }
        .card-body { color: #e2e8f0; }
        h1, h2, h3, h4, h5, h6 { color: #f1f5f9; }
        p, span, div, td, th, li, a { color: inherit; }
        .text-muted { color: #64748b !important; }
        small { color: #94a3b8; }
        code { color: #38bdf8; }
        .alert { color: #e2e8f0; }
        .alert-danger { background: rgba(127,29,29,0.34) !important; border-color: rgba(248,113,113,0.35) !important; color: #fecaca !important; }
        .alert-warning { background: rgba(120,53,15,0.34) !important; border-color: rgba(251,191,36,0.35) !important; color: #fde68a !important; }
        .alert-success { background: rgba(20,83,45,0.34) !important; border-color: rgba(34,197,94,0.35) !important; color: #bbf7d0 !important; }
        .alert-info { background: rgba(12,74,110,0.34) !important; border-color: rgba(56,189,248,0.35) !important; color: #bae6fd !important; }
        .form-control::placeholder { color: #475569; }
        option { background: #1e293b; color: #e2e8f0; }
        .card-body { background: transparent; }
        .table > :not(caption) > * > * { background: transparent; color: #e2e8f0; }
        .badge.bg-dark { background: #334155 !important; }
        .badge.bg-info { background: rgba(56,189,248,0.15) !important; color: #38bdf8 !important; }
        .btn-outline-warning { border-color: #fbbf24; color: #fbbf24; }
        .btn-outline-warning:hover { background: rgba(251,191,36,0.15); color: #fbbf24; }
        .btn-outline-success { border-color: #22c55e; color: #22c55e; }
        .btn-outline-success:hover { background: rgba(34,197,94,0.15); color: #22c55e; }
        .btn-outline-danger, .btn-danger { border-color: #ef4444; }
        .input-group .btn { border-color: #334155; }
        .form-control:disabled, .form-select:disabled { background: #334155; color: #f1f5f9; opacity: 1; -webkit-text-fill-color: #f1f5f9; border-color: #475569; }
        .swal2-popup.swal-dark-popup { border: 1px solid #334155; border-radius: 16px; }
        .swal2-popup .swal2-title { color: #f1f5f9; }
        .swal2-popup .swal2-html-container { color: #94a3b8; }
        .swal2-popup .swal2-cancel { background: #334155 !important; color: #94a3b8 !important; border: 1px solid #475569 !important; }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-brand">
        <h5><i class="bi bi-hdd-rack"></i> MuseDock Panel</h5>
        <?php if (!empty($panelHostname)): ?>
            <div><small style="color:#94a3b8;font-size:0.75rem;"><i class="bi bi-server" style="font-size:0.65rem;"></i> <?= View::e($panelHostname) ?></small></div>
        <?php endif; ?>
        <a href="/changelog" style="text-decoration:none;"><small style="color:#64748b;">v<?= View::e($panelVersion) ?></small></a>
    </div>
    <nav class="sidebar-nav">
        <a href="/" class="<?= ($pageTitle ?? '') === 'Dashboard' ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="/monitor" class="<?= ($pageTitle ?? '') === 'Monitoring' ? 'active' : '' ?>">
            <i class="bi bi-activity"></i> Monitoring
        </a>
        <a href="/accounts" class="<?= str_contains($pageTitle ?? '', 'Hosting') || str_contains($pageTitle ?? '', 'Create') ? 'active' : '' ?>">
            <i class="bi bi-server"></i> Hosting Accounts
        </a>
        <a href="/domains" class="<?= str_contains($pageTitle ?? '', 'Domain') ? 'active' : '' ?>">
            <i class="bi bi-globe2"></i> Domains
        </a>
        <a href="/databases" class="<?= str_contains($pageTitle ?? '', 'Base') || str_contains($pageTitle ?? '', 'Crear Base') ? 'active' : '' ?>">
            <i class="bi bi-database"></i> Databases
        </a>
        <a href="/customers" class="<?= str_contains($pageTitle ?? '', 'Customer') ? 'active' : '' ?>">
            <i class="bi bi-people"></i> Customers
        </a>
        <a href="/mail" class="<?= str_contains($pageTitle ?? '', 'Mail') ? 'active' : '' ?>">
            <i class="bi bi-envelope"></i> Mail
        </a>
        <a href="/system-users" class="<?= str_contains($pageTitle ?? '', 'System User') ? 'active' : '' ?>">
            <i class="bi bi-people-fill"></i> System Users
        </a>
        <a href="/logs" class="<?= ($pageTitle ?? '') === 'Activity Log' ? 'active' : '' ?>">
            <i class="bi bi-journal-text"></i> Activity Log
        </a>
        <a href="/admin/file-audit-log" class="<?= str_contains($pageTitle ?? '', 'File Audit') ? 'active' : '' ?>">
            <i class="bi bi-shield-check"></i> File Audit Log
        </a>
        <a href="/backups" class="<?= str_contains($pageTitle ?? '', 'Backup') ? 'active' : '' ?>">
            <i class="bi bi-cloud-arrow-down"></i> Backups
        </a>
        <a href="/settings/server" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/settings') ? 'active' : '' ?>">
            <i class="bi bi-gear"></i> Settings
        </a>
    </nav>
    <div class="sidebar-footer">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <a href="/docs" style="text-decoration:none;color:#94a3b8;" title="Documentacion">
                <i class="bi bi-journal-text"></i> Docs
            </a>
            <a href="/changelog" style="text-decoration:none;color:#64748b;" title="Changelog">v<?= View::e($panelVersion) ?></a>
        </div>
        <a href="/profile" style="text-decoration:none;color:#94a3b8;" title="Mi Perfil"><i class="bi bi-person-circle"></i> <?= View::e($currentUser['username'] ?? 'admin') ?></a>
        <a href="/logout" class="float-end"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</div>

<!-- Main content -->
<div class="main-content">
    <div class="top-bar">
        <h4><?= View::e($pageTitle ?? 'Dashboard') ?></h4>
        <span class="text-muted small"><?= date('d M Y, H:i') ?> (<?= date('T') ?> — System Time)</span>
        <span id="update-banner" style="display:none;" class="ms-3">
            <a href="/settings/updates" class="badge text-decoration-none" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.8rem;padding:6px 12px;">
                <i class="bi bi-cloud-arrow-down me-1"></i>
                v<span id="update-remote-ver"></span> disponible
            </a>
        </span>
    </div>

    <div class="content-area">
        <?php foreach (Flash::all() as $type => $msg): ?>
            <?php
                $alertClass = match($type) {
                    'error' => 'danger',
                    default => $type,
                };
                $bgColor = match($type) {
                    'success' => 'rgba(34,197,94,0.15)',
                    'error' => 'rgba(239,68,68,0.15)',
                    'warning' => 'rgba(251,191,36,0.15)',
                    default => 'rgba(56,189,248,0.15)',
                };
                $textColor = match($type) {
                    'success' => '#22c55e',
                    'error' => '#ef4444',
                    'warning' => '#fbbf24',
                    default => '#38bdf8',
                };
                $borderColor = match($type) {
                    'success' => 'rgba(34,197,94,0.3)',
                    'error' => 'rgba(239,68,68,0.3)',
                    'warning' => 'rgba(251,191,36,0.3)',
                    default => 'rgba(56,189,248,0.3)',
                };
            ?>
            <div class="alert alert-dismissible fade show flash-alert" role="alert" style="background: <?= $bgColor ?>; border: 1px solid <?= $borderColor ?>; color: <?= $textColor ?>;">
                <?= View::e($msg) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" style="filter: invert(1); opacity: 0.6;"></button>
            </div>
        <?php endforeach; ?>

        <?= $content ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// SweetAlert2 dark theme defaults
const SwalDark = Swal.mixin({
    background: '#1e293b',
    color: '#e2e8f0',
    confirmButtonColor: '#0ea5e9',
    cancelButtonColor: '#475569',
    customClass: {
        popup: 'swal-dark-popup'
    }
});

// Confirm action helper
function confirmAction(form, options, preSubmitFn) {
    SwalDark.fire({
        title: options.title || 'Are you sure?',
        text: options.text || '',
        icon: options.icon || 'warning',
        html: options.html || undefined,
        showCancelButton: true,
        confirmButtonText: options.confirmText || 'Confirm',
        cancelButtonText: 'Cancel',
    }).then(function(result) {
        if (result.isConfirmed) {
            if (typeof preSubmitFn === 'function') preSubmitFn();
            form.submit();
        }
    });
}

// Auto-dismiss flash alerts after 4 seconds
document.querySelectorAll('.flash-alert').forEach(function(el) {
    setTimeout(function() {
        var alert = bootstrap.Alert.getOrCreateInstance(el);
        alert.close();
    }, 4000);
});
</script>
<script>
fetch('/settings/updates/api/status').then(r=>r.json()).then(d=>{
    if(d.has_update){
        document.getElementById('update-remote-ver').textContent=d.remote;
        document.getElementById('update-banner').style.display='inline';
    }
}).catch(()=>{});
</script>
</body>
</html>
