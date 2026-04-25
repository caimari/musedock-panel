<?php use MuseDockPanel\View; ?>
<?php
$sections = [
    [
        'title' => 'General',
        'url' => '/mail?tab=general',
        'doc_url' => '/docs/mail/general',
        'icon' => 'bi-envelope',
        'summary' => 'Resumen operativo del modulo de correo, estado global y acciones base.',
    ],
    [
        'title' => 'Dominios',
        'url' => '/mail?tab=domains',
        'doc_url' => '/docs/mail/domains',
        'icon' => 'bi-globe',
        'summary' => 'Dominios autorizados y estado de activacion en modo relay/completo.',
    ],
    [
        'title' => 'Webmail',
        'url' => '/mail?tab=webmail#webmail',
        'doc_url' => '/docs/mail/webmail',
        'icon' => 'bi-envelope',
        'summary' => 'Roundcube, hostnames, IMAP/SMTP, bloqueo por candado y validaciones DNS.',
    ],
    [
        'title' => 'Relay',
        'url' => '/mail?tab=relay',
        'doc_url' => '/docs/mail/relay',
        'icon' => 'bi-diagram-3',
        'summary' => 'Flujo relay privado: dominios remitentes, usuarios SMTP y estado por dominio.',
    ],
    [
        'title' => 'Cola',
        'url' => '/mail?tab=queue',
        'doc_url' => '/docs/mail/queue',
        'icon' => 'bi-inboxes',
        'summary' => 'Cola de Postfix, reintentos, borrado controlado e historico reciente de relay.',
    ],
    [
        'title' => 'Migracion',
        'url' => '/mail?tab=migration',
        'doc_url' => '/docs/mail/migration',
        'icon' => 'bi-arrow-left-right',
        'summary' => 'Pasos para mover configuracion y operativa de correo entre modos/nodos.',
    ],
    [
        'title' => 'Infra',
        'url' => '/mail?tab=infra&setup=1',
        'doc_url' => '/docs/mail/infra',
        'icon' => 'bi-hdd-network',
        'summary' => 'Instalacion o actualizacion del servidor mail y parametros estructurales.',
    ],
    [
        'title' => 'Entregabilidad',
        'url' => '/mail?tab=deliverability',
        'doc_url' => '/docs/mail/deliverability',
        'icon' => 'bi-clipboard',
        'summary' => 'Checks DNS en tiempo real: SPF, DKIM, DMARC, A hostname, PTR/rDNS y blacklist.',
    ],
];
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Mail: mapa base</h4>
        <div class="text-muted small">Guia padre. Desde aqui entras a las guias hijas de Mail y a cada tab funcional.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm">
            <i class="bi bi-journal-text me-1"></i> Volver a Docs
        </a>
        <a href="/mail?tab=general" class="btn btn-outline-info btn-sm">
            <i class="bi bi-envelope me-1"></i> Abrir Mail
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <p class="text-muted small mb-0">
            Esta guia resume para que sirve cada pantalla de <code>Mail</code>. El titulo de cada tarjeta abre su documentacion
            (si ya existe), y el boton abre la pantalla funcional del panel.
        </p>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($sections as $section): ?>
        <div class="col-lg-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex align-items-start gap-3 mb-2">
                        <div class="rounded d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(56,189,248,.12);color:#38bdf8;">
                            <i class="bi <?= View::e($section['icon']) ?>"></i>
                        </div>
                        <div>
                            <?php if (!empty($section['doc_url'])): ?>
                                <h5 class="mb-1">
                                    <a href="<?= View::e($section['doc_url']) ?>" class="text-decoration-none text-light">
                                        <?= View::e($section['title']) ?>
                                    </a>
                                </h5>
                            <?php else: ?>
                                <h5 class="mb-1"><?= View::e($section['title']) ?></h5>
                            <?php endif; ?>
                            <code class="small"><?= View::e($section['url']) ?></code>
                        </div>
                    </div>
                    <p class="text-muted small mb-3 flex-grow-1"><?= View::e($section['summary']) ?></p>
                    <div class="d-flex gap-2">
                        <a href="<?= View::e($section['doc_url']) ?>" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-journal-text me-1"></i> Ver doc
                        </a>
                        <a href="<?= View::e($section['url']) ?>" class="btn btn-outline-light btn-sm">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Abrir seccion
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
