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
        <?php if (!empty($v['changes']['planned'])): ?>
        <h6 class="text-warning mb-2"><i class="bi bi-clock me-1"></i>
            <span class="lang-es">Planificado</span>
            <span class="lang-en" style="display:none">Planned</span>
        </h6>
        <ul class="mb-3">
            <?php foreach ($v['changes']['planned']['es'] as $i => $item): ?>
            <li>
                <span class="lang-es"><?= View::e($item) ?></span>
                <span class="lang-en" style="display:none"><?= View::e($v['changes']['planned']['en'][$i] ?? $item) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if (!empty($v['changes']['added'])): ?>
        <h6 class="text-success mb-2"><i class="bi bi-plus-circle me-1"></i>
            <span class="lang-es">Anadido</span>
            <span class="lang-en" style="display:none">Added</span>
        </h6>
        <ul class="mb-3">
            <?php foreach ($v['changes']['added']['es'] as $i => $item): ?>
            <li>
                <span class="lang-es"><?= View::e($item) ?></span>
                <span class="lang-en" style="display:none"><?= View::e($v['changes']['added']['en'][$i] ?? $item) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if (!empty($v['changes']['fixed'])): ?>
        <h6 class="text-info mb-2"><i class="bi bi-wrench me-1"></i>
            <span class="lang-es">Corregido</span>
            <span class="lang-en" style="display:none">Fixed</span>
        </h6>
        <ul class="mb-0">
            <?php foreach ($v['changes']['fixed']['es'] as $i => $item): ?>
            <li>
                <span class="lang-es"><?= View::e($item) ?></span>
                <span class="lang-en" style="display:none"><?= View::e($v['changes']['fixed']['en'][$i] ?? $item) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
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
