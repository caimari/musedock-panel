<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal — MuseDock Panel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex; justify-content: center; align-items: center; min-height: 100vh;
            background: #0f172a; color: #e2e8f0;
        }
        .card {
            text-align: center; padding: 3rem; max-width: 500px;
            background: rgba(255,255,255,0.03); border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .icon { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
        h1 { font-size: 1.4rem; margin-bottom: 0.5rem; color: #fb923c; }
        p { color: #94a3b8; font-size: 0.9rem; line-height: 1.6; margin-bottom: 1rem; }
        a { color: #38bdf8; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .badge {
            display: inline-block; padding: 4px 12px; border-radius: 20px;
            font-size: 0.7rem; color: #fb923c;
            background: rgba(251,146,60,0.12); margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">&#128274;</div>
        <h1>Portal no activado</h1>
        <p>
            El portal de clientes no esta instalado o no tiene una licencia activa.
        </p>
        <p>
            Para activar el portal, instala el modulo <strong>MuseDock Portal</strong>
            y configura tu clave de licencia en
            <a href="/settings">Settings</a>.
        </p>
        <p style="font-size:0.8rem;">
            <a href="https://musedock.com/portal" target="_blank">Mas informacion sobre MuseDock Portal</a>
        </p>
        <div class="badge">MuseDock Panel <?= defined('PANEL_VERSION') ? PANEL_VERSION : '' ?></div>
    </div>
</body>
</html>
