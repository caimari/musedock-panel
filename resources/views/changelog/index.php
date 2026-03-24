<?php use MuseDockPanel\View; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <small class="text-muted">Version actual: <strong class="text-info">v<?= View::e($panelVersion) ?></strong></small>
    </div>
    <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-light active" onclick="setLang('es')" id="btn-es">Espanol</button>
        <button type="button" class="btn btn-outline-light" onclick="setLang('en')" id="btn-en">English</button>
    </div>
</div>

<?php foreach ($versions as $v): ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <span class="badge bg-<?= View::e($v['badge']) ?> me-2">v<?= View::e($v['version']) ?></span>
            <strong>v<?= View::e($v['version']) ?></strong>
        </span>
        <small class="text-muted"><?= View::e($v['date']) ?></small>
    </div>
    <div class="card-body">
        <?php
        $sections = [
            'new'      => ['icon' => 'bi-plus-circle',    'color' => 'success', 'es' => 'Nuevo',       'en' => 'New'],
            'added'    => ['icon' => 'bi-plus-circle',    'color' => 'success', 'es' => 'Anadido',     'en' => 'Added'],
            'improved' => ['icon' => 'bi-arrow-up-circle','color' => 'primary', 'es' => 'Mejorado',    'en' => 'Improved'],
            'fixed'    => ['icon' => 'bi-wrench',         'color' => 'info',    'es' => 'Corregido',   'en' => 'Fixed'],
            'planned'  => ['icon' => 'bi-clock',          'color' => 'warning', 'es' => 'Planificado', 'en' => 'Planned'],
        ];
        foreach ($sections as $key => $meta):
            if (empty($v['changes'][$key])) continue;
        ?>
        <h6 class="text-<?= $meta['color'] ?> mb-2"><i class="bi <?= $meta['icon'] ?> me-1"></i>
            <span class="lang-es"><?= $meta['es'] ?></span>
            <span class="lang-en" style="display:none"><?= $meta['en'] ?></span>
        </h6>
        <ul class="mb-3">
            <?php foreach ($v['changes'][$key]['es'] as $i => $item): ?>
            <li>
                <span class="lang-es"><?= View::e($item) ?></span>
                <span class="lang-en" style="display:none"><?= View::e($v['changes'][$key]['en'][$i] ?? $item) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<script>
function setLang(lang) {
    document.querySelectorAll('.lang-es').forEach(el => el.style.display = lang === 'es' ? '' : 'none');
    document.querySelectorAll('.lang-en').forEach(el => el.style.display = lang === 'en' ? '' : 'none');
    document.getElementById('btn-es').classList.toggle('active', lang === 'es');
    document.getElementById('btn-en').classList.toggle('active', lang === 'en');
    localStorage.setItem('changelog_lang', lang);
}
// Restore preference
(function() {
    var saved = localStorage.getItem('changelog_lang');
    if (saved) setLang(saved);
})();
</script>
