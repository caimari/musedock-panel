<?php
use MuseDockPanel\View;

$accountId = $account['id'];
$parentPath = dirname($filePath);
if ($parentPath === '.') $parentPath = '/';
$wm = $writeMode ?? false;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/accounts/<?= $accountId ?>/files?path=<?= urlencode($parentPath) ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-muted" style="font-size:0.8rem;"><code><?= View::e($filePath) ?></code></span>
        <?php if ($wm): ?>
        <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;font-size:0.75rem;">
            <i class="bi bi-pencil-square me-1"></i>Edicion activa
        </span>
        <?php else: ?>
        <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.75rem;">
            <i class="bi bi-eye me-1"></i>Solo lectura
        </span>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert py-2 px-3" style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);color:#ef4444;font-size:0.85rem;">
    <i class="bi bi-exclamation-triangle me-1"></i><?= View::e($error) ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span style="font-size:0.85rem;"><i class="bi bi-file-code me-2"></i><?= View::e($fileName) ?></span>
        <?php if ($wm): ?>
        <div class="d-flex gap-2">
            <a href="/accounts/<?= $accountId ?>/files/download?path=<?= urlencode($filePath) ?>" class="btn btn-sm btn-outline-light py-0 px-2" style="font-size:0.75rem;"><i class="bi bi-download me-1"></i>Descargar</a>
            <button type="submit" form="editForm" class="btn btn-sm btn-primary py-0 px-3" style="font-size:0.75rem;"><i class="bi bi-check-lg me-1"></i>Guardar</button>
        </div>
        <?php else: ?>
        <a href="/accounts/<?= $accountId ?>/files/download?path=<?= urlencode($filePath) ?>" class="btn btn-sm btn-outline-light py-0 px-2" style="font-size:0.75rem;"><i class="bi bi-download me-1"></i>Descargar</a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if ($wm): ?>
        <form id="editForm" method="POST" action="/accounts/<?= $accountId ?>/files/save">
            <?= View::csrf() ?>
            <input type="hidden" name="path" value="<?= View::e($filePath) ?>">
            <textarea name="content" id="fm-editor" style="width:100%;min-height:70vh;background:#0f172a;color:#e2e8f0;border:none;padding:16px;font-family:'JetBrains Mono',Consolas,'Courier New',monospace;font-size:0.8rem;line-height:1.6;resize:vertical;outline:none;tab-size:4;" spellcheck="false"><?= View::e($content) ?></textarea>
        </form>
        <?php else: ?>
        <pre style="margin:0;padding:16px;background:#0f172a;color:#e2e8f0;font-size:0.8rem;line-height:1.6;overflow-x:auto;white-space:pre-wrap;word-wrap:break-word;"><code><?= View::e($content) ?></code></pre>
        <?php endif; ?>
    </div>
</div>

<div class="mt-2 text-muted" style="font-size:0.7rem;">
    <i class="bi bi-shield-check me-1"></i>Este acceso queda registrado en el audit log (RGPD Art. 30)
</div>

<?php if ($wm): ?>
<script>
// Tab key support in textarea
document.getElementById('fm-editor').addEventListener('keydown', function(e) {
    if (e.key === 'Tab') {
        e.preventDefault();
        var start = this.selectionStart, end = this.selectionEnd;
        this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
        this.selectionStart = this.selectionEnd = start + 4;
    }
    // Ctrl+S to save
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        document.getElementById('editForm').submit();
    }
});
</script>
<?php endif; ?>
