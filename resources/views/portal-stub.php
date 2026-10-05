<?php
// Página pública: la ven los clientes (y cualquiera) si el portal no tiene licencia.
// No enlaza al panel ni muestra su puerto. Español o inglés según el navegador.
$lang = str_starts_with(strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'es')), 'es') ? 'es' : 'en';
$t = $lang === 'es'
    ? ['title' => 'Portal no disponible', 'h1' => 'Portal temporalmente no disponible',
       'p1' => 'El área de clientes no está disponible en este momento.',
       'p2' => 'Tus webs, correo y bases de datos siguen funcionando con normalidad. Si necesitas algo, contacta con tu proveedor de hosting.']
    : ['title' => 'Portal unavailable', 'h1' => 'Portal temporarily unavailable',
       'p1' => 'The customer area is not available right now.',
       'p2' => 'Your websites, email and databases keep working normally. If you need anything, please contact your hosting provider.'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($t['title']) ?></title>
    <meta name="robots" content="noindex">
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
        <h1><?= htmlspecialchars($t['h1']) ?></h1>
        <p><?= htmlspecialchars($t['p1']) ?></p>
        <p><?= htmlspecialchars($t['p2']) ?></p>
    </div>
</body>
</html>
