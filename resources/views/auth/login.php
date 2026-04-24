<?php use MuseDockPanel\View; use MuseDockPanel\Flash; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MuseDock Panel</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #0f172a; display: flex; justify-content: center; align-items: center;
               min-height: 100vh; font-family: 'Inter', -apple-system, sans-serif; }
        .login-card { background: #1e293b; border: 1px solid #334155; border-radius: 16px;
                      padding: 2.5rem; width: 100%; max-width: 400px; }
        .login-card h3 { color: #38bdf8; font-weight: 700; margin-bottom: 0.25rem; }
        .login-card p { color: #64748b; font-size: 0.9rem; margin-bottom: 1.5rem; }
        .form-control { background: #0f172a; border: 1px solid #334155; color: #f1f5f9 !important; padding: 0.75rem 1rem; }
        .form-control:focus { background: #0f172a; border-color: #38bdf8; color: #f1f5f9 !important; box-shadow: 0 0 0 2px rgba(56,189,248,0.2); }
        .form-control::placeholder { color: #64748b; }
        .form-label { color: #94a3b8; font-size: 0.85rem; font-weight: 500; }
        .btn-login { background: #0ea5e9; border: none; padding: 0.75rem; font-weight: 600; width: 100%; }
        .btn-login:hover { background: #0284c7; }
        .version { color: #334155; font-size: 0.75rem; text-align: center; margin-top: 1.5rem; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="text-center mb-3">
            <i class="bi bi-hdd-rack" style="font-size: 2.5rem; color: #38bdf8;"></i>
        </div>
        <h3 class="text-center">MuseDock Panel</h3>
        <p class="text-center">Server Management</p>

        <?php foreach (Flash::all() as $type => $msg): ?>
            <?php
                $flashStyle = match ($type) {
                    'success' => 'background: rgba(34,197,94,0.15); border-color: rgba(34,197,94,0.3); color: #86efac;',
                    'warning' => 'background: rgba(251,191,36,0.15); border-color: rgba(251,191,36,0.3); color: #fde68a;',
                    default => 'background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #fca5a5;',
                };
            ?>
            <div class="alert py-2 px-3" style="font-size: 0.85rem; <?= $flashStyle ?>">
                <?= View::e($msg) ?>
            </div>
        <?php endforeach; ?>

        <form method="POST" action="/login/submit">
                    <?= \MuseDockPanel\View::csrf() ?>
            <div class="mb-3">
                <label class="form-label">Usuario</label>
                <input type="text" name="username" class="form-control" placeholder="Nombre de usuario" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">Contraseña</label>
                <input type="password" name="password" class="form-control" placeholder="Password" required>
            </div>
            <button type="submit" class="btn btn-login btn-primary">
                <i class="bi bi-box-arrow-in-right me-1"></i> Acceder
            </button>
        </form>

        <div class="version">v<?= View::e($panelVersion ?? '0.1.0') ?></div>
    </div>
</body>
</html>
