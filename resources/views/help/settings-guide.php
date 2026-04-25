<?php use MuseDockPanel\View; ?>
<?php
$guide = $guide ?? [];
$title = $guide['title'] ?? 'Guia';
$summary = $guide['summary'] ?? '';
$whatIs = $guide['what_is'] ?? '';
$panelUrl = $guide['panel_url'] ?? '/settings/server';
$quickSteps = is_array($guide['quick_steps'] ?? null) ? $guide['quick_steps'] : [];
$checklist = is_array($guide['checklist'] ?? null) ? $guide['checklist'] : [];
$pitfalls = is_array($guide['pitfalls'] ?? null) ? $guide['pitfalls'] : [];
$advancedSteps = is_array($guide['advanced_steps'] ?? null) ? $guide['advanced_steps'] : [];
$verifyCommands = is_array($guide['verify_commands'] ?? null) ? $guide['verify_commands'] : [];
$rollbackSteps = is_array($guide['rollback_steps'] ?? null) ? $guide['rollback_steps'] : [];
$specialShortcut = !empty($guide['special_shortcut']);
$slug = (string)($slug ?? '');
$toggleAction = '/docs/settings/' . rawurlencode($slug) . '/shortcut-toggle';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1"><?= View::e($title) ?></h4>
        <div class="text-muted small"><?= View::e($summary) ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/settings-sections" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver al mapa
        </a>
        <a href="<?= View::e($panelUrl) ?>" class="btn btn-outline-info btn-sm">
            <i class="bi bi-box-arrow-up-right me-1"></i> Abrir seccion
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:<?= $specialShortcut ? 'rgba(251,191,36,.35)' : 'rgba(51,65,85,.6)' ?>;">
    <div class="card-header">
        <i class="bi bi-star-fill me-2 <?= $specialShortcut ? 'text-warning' : 'text-muted' ?>"></i>
        Acceso directo especial (estrella)
    </div>
    <div class="card-body">
        <?php if ($specialShortcut): ?>
            <div class="small text-warning mb-3">Esta guia esta marcada y aparece en "Accesos directos especiales" de <code>/docs</code>.</div>
            <form method="post" action="<?= View::e($toggleAction) ?>" class="mb-0">
                <?= View::csrf() ?>
                <button type="submit" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-star me-1"></i>Quitar de accesos directos especiales
                </button>
            </form>
        <?php else: ?>
            <div class="small text-muted mb-3">Esta guia no esta marcada como acceso directo especial.</div>
            <form method="post" action="<?= View::e($toggleAction) ?>" class="mb-0">
                <?= View::csrf() ?>
                <button type="submit" class="btn btn-sm btn-warning">
                    <i class="bi bi-star-fill me-1"></i>Anadir a accesos directos especiales
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-info-circle me-2"></i>Que es esta seccion</div>
    <div class="card-body">
        <p class="small text-muted mb-0"><?= View::e($whatIs) ?></p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-list-ol me-2"></i>Configuracion base recomendada</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <?php foreach ($quickSteps as $step): ?>
                <li><?= View::e((string)$step) ?></li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>

<?php if (!empty($advancedSteps)): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Procedimiento nivel 2</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <?php foreach ($advancedSteps as $step): ?>
                <li><?= View::e((string)$step) ?></li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($verifyCommands)): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-terminal me-2"></i>Comandos de verificacion</div>
    <div class="card-body">
        <?php foreach ($verifyCommands as $cmd): ?>
            <code class="d-block small mb-2"><?= View::e((string)$cmd) ?></code>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($rollbackSteps)): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-arrow-counterclockwise me-2"></i>Rollback rapido</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <?php foreach ($rollbackSteps as $step): ?>
                <li><?= View::e((string)$step) ?></li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>Checklist minima</div>
            <div class="card-body">
                <ul class="small text-muted mb-0">
                    <?php foreach ($checklist as $item): ?>
                        <li><?= View::e((string)$item) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Errores frecuentes</div>
            <div class="card-body">
                <ul class="small text-muted mb-0">
                    <?php foreach ($pitfalls as $item): ?>
                        <li><?= View::e((string)$item) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
