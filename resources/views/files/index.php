<?php
use MuseDockPanel\View;
use MuseDockPanel\Controllers\FileManagerController as FM;

$accountId = $account['id'];
$parentPath = dirname($currentPath);
if ($parentPath === '.') $parentPath = '/';
$pag = $pagination ?? ['total' => count($items), 'page' => 1, 'per_page' => 100, 'pages' => 1, 'sort' => 'name', 'order' => 'asc', 'search' => ''];
$wm = $writeMode ?? false;
$wmExpires = $_SESSION['fm_write_mode']['expires_at'] ?? 0;
$wmRemaining = max(0, $wmExpires - time());
?>

<!-- Back to account + RGPD banner -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/accounts/<?= $accountId ?>" class="btn btn-outline-light btn-sm me-2"><i class="bi bi-arrow-left me-1"></i>Volver a cuenta</a>
        <a href="/accounts/<?= $accountId ?>/audit-log" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i>Audit Log</a>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if ($wm): ?>
        <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;font-size:0.75rem;" id="fm-write-badge">
            <i class="bi bi-pencil-square me-1"></i>Edicion activa — <span id="fm-write-timer"><?= gmdate('i:s', $wmRemaining) ?></span>
        </span>
        <?php else: ?>
        <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.75rem;">
            <i class="bi bi-eye me-1"></i>Solo lectura
        </span>
        <button class="btn btn-sm" style="border:1px solid #fbbf24;color:#fbbf24;font-size:0.75rem;" onclick="showWriteModeDialog()">
            <i class="bi bi-unlock me-1"></i>Activar edicion
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="mb-2" style="font-size:0.7rem;color:#64748b;">
    <i class="bi bi-shield-check me-1"></i>Todas las operaciones quedan registradas en el audit log (RGPD Art. 30)
</div>

<!-- Toolbar -->
<div class="card mb-3">
    <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-folder me-1 text-muted"></i>
            <small class="text-muted" id="fm-path-display"><?= View::e($currentPath) ?></small>
            <span class="badge" style="background:rgba(56,189,248,0.1);color:#38bdf8;font-size:0.7rem;" id="fm-total-badge"><?= $pag['total'] ?> items</span>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <div class="input-group input-group-sm" style="width:200px;">
                <span class="input-group-text" style="background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.1);color:#64748b;"><i class="bi bi-search"></i></span>
                <input type="text" id="fm-search" class="form-control form-control-sm" placeholder="Buscar..." value="<?= View::e($pag['search']) ?>" style="background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.1);color:inherit;">
            </div>
            <?php if ($wm): ?>
            <button class="btn btn-sm" style="border:1px solid #94a3b8;color:#38bdf8;" onclick="document.getElementById('uploadForm').style.display=document.getElementById('uploadForm').style.display==='none'?'':'none'">
                <i class="bi bi-cloud-upload me-1"></i><span class="d-none d-md-inline">Subir</span>
            </button>
            <button class="btn btn-sm" style="border:1px solid #94a3b8;color:#38bdf8;" onclick="document.getElementById('mkdirForm').style.display=document.getElementById('mkdirForm').style.display==='none'?'':'none'">
                <i class="bi bi-folder-plus me-1"></i><span class="d-none d-md-inline">Nueva carpeta</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($wm): ?>
    <!-- Upload form -->
    <div id="uploadForm" style="display:none;" class="card-body py-2 border-top" style="border-color:#334155 !important;">
        <form method="POST" action="/accounts/<?= $accountId ?>/files/upload" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
            <?= View::csrf() ?>
            <input type="hidden" name="upload_path" id="fm-upload-path" value="<?= View::e($currentPath) ?>">
            <input type="file" name="file" required class="form-control form-control-sm" style="max-width:300px;background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.1);color:inherit;">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-upload me-1"></i>Subir</button>
        </form>
    </div>

    <!-- Mkdir form -->
    <div id="mkdirForm" style="display:none;" class="card-body py-2 border-top" style="border-color:#334155 !important;">
        <form method="POST" action="/accounts/<?= $accountId ?>/files/mkdir" class="d-flex gap-2 align-items-center">
            <?= View::csrf() ?>
            <input type="hidden" name="parent_path" id="fm-mkdir-path" value="<?= View::e($currentPath) ?>">
            <input type="text" name="dir_name" required placeholder="Nombre de la carpeta" class="form-control form-control-sm" style="max-width:250px;background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.1);color:inherit;">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-folder-plus me-1"></i>Crear</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($error)): ?>
<div class="alert py-2 px-3" style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);color:#ef4444;font-size:0.85rem;" id="fm-error">
    <i class="bi bi-exclamation-triangle me-1"></i><?= View::e($error) ?>
</div>
<?php else: ?>

<!-- Breadcrumb -->
<div class="mb-2" style="font-size:0.8rem;" id="fm-breadcrumb">
    <a href="/accounts/<?= $accountId ?>/files?path=/" class="text-info text-decoration-none fm-nav" data-path="/"><i class="bi bi-house me-1"></i>root</a>
    <?php
    $pathParts = array_filter(explode('/', $currentPath));
    $buildPath = '';
    foreach ($pathParts as $part):
        $buildPath .= '/' . $part;
    ?>
    <span class="text-muted mx-1">/</span>
    <a href="/accounts/<?= $accountId ?>/files?path=<?= urlencode($buildPath) ?>" class="text-info text-decoration-none fm-nav" data-path="<?= View::e($buildPath) ?>"><?= View::e($part) ?></a>
    <?php endforeach; ?>
</div>

<!-- File listing -->
<div class="card">
    <div class="card-body p-0">
        <div class="d-none d-md-block">
        <table class="table table-hover mb-0" style="font-size:0.85rem;" id="fm-table">
            <thead>
                <tr>
                    <th class="ps-3" style="width:45%;cursor:pointer;" data-sort="name">Nombre <i class="bi bi-chevron-expand ms-1" style="font-size:0.65rem;"></i></th>
                    <th style="cursor:pointer;" data-sort="size">Tamano <i class="bi bi-chevron-expand ms-1" style="font-size:0.65rem;"></i></th>
                    <th style="cursor:pointer;" data-sort="perms">Permisos</th>
                    <th style="cursor:pointer;" data-sort="modified">Modificado <i class="bi bi-chevron-expand ms-1" style="font-size:0.65rem;"></i></th>
                    <th class="text-end pe-3">Acciones</th>
                </tr>
            </thead>
            <tbody id="fm-tbody">
                <?php if ($currentPath !== '/'): ?>
                <tr class="fm-parent-row">
                    <td class="ps-3" colspan="5">
                        <a href="/accounts/<?= $accountId ?>/files?path=<?= urlencode($parentPath) ?>" class="text-info text-decoration-none fm-nav" data-path="<?= View::e($parentPath) ?>">
                            <i class="bi bi-arrow-up me-2"></i>..
                        </a>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($items as $item):
                    $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
                    $filePath = rtrim($currentPath, '/') . '/' . $item['name'];
                    $isEditable = in_array($ext, ['php','html','htm','css','js','json','xml','txt','md','env','htaccess','yml','yaml','toml','ini','conf','sh','py','sql','log','csv','svg'], true)
                                  || in_array($item['name'], ['.env', '.htaccess', '.gitignore'], true);
                ?>
                <tr class="fm-item" data-type="<?= $item['type'] ?>">
                    <td class="ps-3">
                        <?php if ($item['type'] === 'dir'): ?>
                        <a href="/accounts/<?= $accountId ?>/files?path=<?= urlencode($filePath) ?>" class="text-info text-decoration-none fm-nav" data-path="<?= View::e($filePath) ?>">
                            <i class="bi bi-folder-fill me-2" style="color:#fbbf24;"></i><?= View::e($item['name']) ?>
                        </a>
                        <?php elseif ($isEditable): ?>
                        <a href="/accounts/<?= $accountId ?>/files/edit?path=<?= urlencode($filePath) ?>" class="text-decoration-none" style="color:inherit;">
                            <i class="bi <?= FM::fileIcon($ext, $item['name']) ?> me-2" style="color:#64748b;"></i><?= View::e($item['name']) ?>
                        </a>
                        <?php else: ?>
                        <i class="bi <?= FM::fileIcon($ext, $item['name']) ?> me-2" style="color:#64748b;"></i><?= View::e($item['name']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= $item['type'] === 'dir' ? '—' : FM::formatSize($item['size']) ?></td>
                    <td><code style="font-size:0.75rem;"><?= View::e($item['perms']) ?></code></td>
                    <td class="text-muted"><?= date('d/m/Y H:i', $item['modified']) ?></td>
                    <td class="text-end pe-3 text-nowrap">
                        <?php if ($item['type'] !== 'dir' && $isEditable): ?>
                        <a href="/accounts/<?= $accountId ?>/files/edit?path=<?= urlencode($filePath) ?>" class="btn btn-sm py-0 px-1 me-1" style="font-size:0.7rem;border:1px solid #94a3b8;color:#38bdf8;" title="Editar"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
                        <?php if ($item['type'] !== 'dir'): ?>
                        <a href="/accounts/<?= $accountId ?>/files/download?path=<?= urlencode($filePath) ?>" class="btn btn-sm py-0 px-1 me-1" style="font-size:0.7rem;border:1px solid #94a3b8;color:#0891b2;" title="Descargar"><i class="bi bi-download"></i></a>
                        <?php endif; ?>
                        <?php if ($wm): ?>
                        <form method="POST" action="/accounts/<?= $accountId ?>/files/delete" style="display:inline;" onsubmit="return confirm('Eliminar <?= View::e($item['name']) ?>?')">
                            <?= View::csrf() ?>
                            <input type="hidden" name="path" value="<?= View::e($filePath) ?>">
                            <button type="submit" class="btn btn-sm py-0 px-1" style="font-size:0.7rem;border:1px solid #fca5a5;color:#ef4444;" title="Eliminar"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($items)): ?>
                <tr id="fm-empty"><td colspan="5" class="text-center text-muted py-3">Carpeta vacia</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile cards -->
        <div class="d-md-none p-2" id="fm-cards">
            <?php if ($currentPath !== '/'): ?>
            <a href="/accounts/<?= $accountId ?>/files?path=<?= urlencode($parentPath) ?>" class="d-block p-2 mb-1 text-info text-decoration-none fm-nav" data-path="<?= View::e($parentPath) ?>" style="border-radius:8px;background:rgba(255,255,255,0.02);">
                <i class="bi bi-arrow-up me-2"></i>..
            </a>
            <?php endif; ?>
            <?php foreach ($items as $item):
                $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
                $filePath = rtrim($currentPath, '/') . '/' . $item['name'];
                $isEditable = in_array($ext, ['php','html','htm','css','js','json','xml','txt','md','env','htaccess','yml','yaml','toml','ini','conf','sh','py','sql','log','csv','svg'], true)
                              || in_array($item['name'], ['.env', '.htaccess', '.gitignore'], true);
            ?>
            <div class="d-flex align-items-center justify-content-between p-2 mb-1" style="border-radius:8px;background:rgba(255,255,255,0.02);">
                <div style="min-width:0;flex:1;">
                    <?php if ($item['type'] === 'dir'): ?>
                    <a href="/accounts/<?= $accountId ?>/files?path=<?= urlencode($filePath) ?>" class="text-info text-decoration-none fm-nav" data-path="<?= View::e($filePath) ?>">
                        <i class="bi bi-folder-fill me-1" style="color:#fbbf24;"></i><?= View::e($item['name']) ?>
                    </a>
                    <?php elseif ($isEditable): ?>
                    <a href="/accounts/<?= $accountId ?>/files/edit?path=<?= urlencode($filePath) ?>" class="text-decoration-none" style="color:inherit;">
                        <i class="bi <?= FM::fileIcon($ext, $item['name']) ?> me-1" style="color:#64748b;"></i><?= View::e($item['name']) ?>
                    </a>
                    <?php else: ?>
                    <i class="bi <?= FM::fileIcon($ext, $item['name']) ?> me-1" style="color:#64748b;"></i><?= View::e($item['name']) ?>
                    <?php endif; ?>
                    <div class="text-muted" style="font-size:0.7rem;">
                        <?= $item['type'] === 'dir' ? 'Carpeta' : FM::formatSize($item['size']) ?>
                        <span class="ms-2"><?= date('d/m H:i', $item['modified']) ?></span>
                    </div>
                </div>
                <div class="text-nowrap ms-2">
                    <?php if ($item['type'] !== 'dir'): ?>
                    <a href="/accounts/<?= $accountId ?>/files/download?path=<?= urlencode($filePath) ?>" class="btn btn-sm py-0 px-1" style="font-size:0.7rem;border:1px solid #94a3b8;color:#0891b2;"><i class="bi bi-download"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Load more -->
<?php if ($pag['pages'] > 1): ?>
<div class="text-center mt-3" id="fm-load-more-wrap">
    <button class="btn btn-sm" style="border:1px solid #94a3b8;color:#38bdf8;" id="fm-load-more">
        <i class="bi bi-arrow-down-circle me-1"></i>Cargar mas (<?= $pag['total'] - ($pag['page'] * $pag['per_page']) ?> restantes)
    </button>
    <div class="text-muted mt-1" style="font-size:0.7rem;">
        Pagina <?= $pag['page'] ?> de <?= $pag['pages'] ?> — <?= $pag['total'] ?> items total
    </div>
</div>
<?php endif; ?>

<div class="text-center py-4" id="fm-loading" style="display:none;">
    <div class="spinner-border spinner-border-sm text-info" role="status"></div>
    <span class="text-muted ms-2" style="font-size:0.85rem;">Cargando...</span>
</div>
<?php endif; ?>

<!-- Write mode activation modal -->
<div class="modal fade" id="writeModeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background:#1e293b;border:1px solid #334155;">
            <form method="POST" action="/accounts/<?= $accountId ?>/files/write-mode">
                <?= View::csrf() ?>
                <div class="modal-header" style="border-color:#334155;">
                    <h6 class="modal-title" style="color:#e2e8f0;"><i class="bi bi-unlock me-2"></i>Activar modo edicion</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">El modo edicion permite modificar, crear y eliminar archivos en la cuenta del cliente. Se desactiva automaticamente a los 30 minutos.</p>
                    <div class="mb-3">
                        <label class="form-label small text-muted">Motivo del acceso</label>
                        <select name="reason" class="form-select form-select-sm" style="background:#0f172a;border-color:#334155;color:#e2e8f0;">
                            <option value="support_request">Soporte tecnico (ticket del cliente)</option>
                            <option value="maintenance">Mantenimiento programado</option>
                            <option value="security_incident">Incidencia de seguridad</option>
                            <option value="contract_execution">Prestacion del servicio</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted">Descripcion (opcional)</label>
                        <input type="text" name="description" class="form-control form-control-sm" placeholder="Ej: ticket #1234, revisar wp-config..." style="background:#0f172a;border-color:#334155;color:#e2e8f0;">
                    </div>
                </div>
                <div class="modal-footer" style="border-color:#334155;">
                    <button type="button" class="btn btn-sm btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm" style="background:rgba(251,191,36,0.15);color:#fbbf24;border:1px solid rgba(251,191,36,0.3);">
                        <i class="bi bi-unlock me-1"></i>Activar edicion (30 min)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    var ACCOUNT_ID = <?= (int)$accountId ?>;
    var EDITABLE_EXT = <?= json_encode(['php','html','htm','css','js','json','xml','txt','md','env','htaccess','yml','yaml','toml','ini','conf','sh','py','sql','log','csv','svg']) ?>;
    var EDITABLE_NAMES = ['.env', '.htaccess', '.gitignore'];
    var CSRF_TOKEN = <?= json_encode(View::csrfToken()) ?>;
    var WRITE_MODE = <?= $wm ? 'true' : 'false' ?>;

    var state = {
        path: <?= json_encode($currentPath) ?>,
        page: <?= (int)$pag['page'] ?>,
        pages: <?= (int)$pag['pages'] ?>,
        perPage: <?= (int)$pag['per_page'] ?>,
        total: <?= (int)$pag['total'] ?>,
        sort: <?= json_encode($pag['sort']) ?>,
        order: <?= json_encode($pag['order']) ?>,
        search: <?= json_encode($pag['search']) ?>,
        loading: false
    };

    var searchTimer = null;

    function baseUrl() { return '/accounts/' + ACCOUNT_ID + '/files'; }

    function isEditable(name) {
        if (EDITABLE_NAMES.indexOf(name) >= 0) return true;
        var dot = name.lastIndexOf('.');
        if (dot < 0) return false;
        return EDITABLE_EXT.indexOf(name.substring(dot + 1).toLowerCase()) >= 0;
    }

    function fileIcon(name) {
        var dot = name.lastIndexOf('.');
        var ext = dot >= 0 ? name.substring(dot + 1).toLowerCase() : '';
        var map = {
            'php':'bi-filetype-php','html':'bi-filetype-html','htm':'bi-filetype-html',
            'css':'bi-filetype-css','js':'bi-filetype-js','json':'bi-filetype-json',
            'xml':'bi-filetype-xml','svg':'bi-filetype-xml',
            'jpg':'bi-file-image','jpeg':'bi-file-image','png':'bi-file-image',
            'gif':'bi-file-image','webp':'bi-file-image','ico':'bi-file-image',
            'pdf':'bi-file-pdf','zip':'bi-file-zip','tar':'bi-file-zip',
            'gz':'bi-file-zip','rar':'bi-file-zip',
            'sql':'bi-database','md':'bi-file-text','txt':'bi-file-text',
            'sh':'bi-terminal','bash':'bi-terminal','env':'bi-key'
        };
        return map[ext] || 'bi-file-earmark';
    }

    function formatSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1048576).toFixed(1) + ' MB';
    }

    function formatDate(ts) {
        var d = new Date(ts * 1000), p = function(n){return n<10?'0'+n:n;};
        return p(d.getDate())+'/'+p(d.getMonth()+1)+'/'+d.getFullYear()+' '+p(d.getHours())+':'+p(d.getMinutes());
    }

    function esc(s) { var e=document.createElement('span'); e.textContent=s; return e.innerHTML; }

    function renderRow(item) {
        var fp = state.path.replace(/\/+$/, '') + '/' + item.name;
        var editable = isEditable(item.name);
        var icon = item.type==='dir' ? 'bi-folder-fill' : fileIcon(item.name);
        var iconColor = item.type==='dir' ? 'color:#fbbf24;' : 'color:#64748b;';
        var nameHtml;

        if (item.type === 'dir') {
            nameHtml = '<a href="'+baseUrl()+'?path='+encodeURIComponent(fp)+'" class="text-info text-decoration-none fm-nav" data-path="'+esc(fp)+'"><i class="bi '+icon+' me-2" style="'+iconColor+'"></i>'+esc(item.name)+'</a>';
        } else if (editable) {
            nameHtml = '<a href="'+baseUrl()+'/edit?path='+encodeURIComponent(fp)+'" class="text-decoration-none" style="color:inherit;"><i class="bi '+icon+' me-2" style="'+iconColor+'"></i>'+esc(item.name)+'</a>';
        } else {
            nameHtml = '<i class="bi '+icon+' me-2" style="'+iconColor+'"></i>'+esc(item.name);
        }

        var actions = '';
        if (item.type !== 'dir' && editable) actions += '<a href="'+baseUrl()+'/edit?path='+encodeURIComponent(fp)+'" class="btn btn-sm py-0 px-1 me-1" style="font-size:0.7rem;border:1px solid #94a3b8;color:#38bdf8;" title="Editar"><i class="bi bi-pencil"></i></a>';
        if (item.type !== 'dir') actions += '<a href="'+baseUrl()+'/download?path='+encodeURIComponent(fp)+'" class="btn btn-sm py-0 px-1 me-1" style="font-size:0.7rem;border:1px solid #94a3b8;color:#0891b2;" title="Descargar"><i class="bi bi-download"></i></a>';
        if (WRITE_MODE) actions += '<form method="POST" action="'+baseUrl()+'/delete" style="display:inline;" onsubmit="return confirm(\'Eliminar '+esc(item.name).replace(/\'/g,"\\'")+'?\')"><input type="hidden" name="_csrf_token" value="'+CSRF_TOKEN+'"><input type="hidden" name="path" value="'+esc(fp)+'"><button type="submit" class="btn btn-sm py-0 px-1" style="font-size:0.7rem;border:1px solid #fca5a5;color:#ef4444;" title="Eliminar"><i class="bi bi-trash"></i></button></form>';

        return '<tr class="fm-item" data-type="'+item.type+'"><td class="ps-3">'+nameHtml+'</td>'+
            '<td class="text-muted">'+(item.type==='dir'?'—':formatSize(item.size))+'</td>'+
            '<td><code style="font-size:0.75rem;">'+esc(item.perms)+'</code></td>'+
            '<td class="text-muted">'+formatDate(item.modified)+'</td>'+
            '<td class="text-end pe-3 text-nowrap">'+actions+'</td></tr>';
    }

    function renderBreadcrumb(path) {
        var html = '<a href="'+baseUrl()+'?path=/" class="text-info text-decoration-none fm-nav" data-path="/"><i class="bi bi-house me-1"></i>root</a>';
        var parts = path.split('/').filter(function(p){return p;});
        var build = '';
        for (var i=0; i<parts.length; i++) {
            build += '/'+parts[i];
            html += '<span class="text-muted mx-1">/</span><a href="'+baseUrl()+'?path='+encodeURIComponent(build)+'" class="text-info text-decoration-none fm-nav" data-path="'+esc(build)+'">'+esc(parts[i])+'</a>';
        }
        return html;
    }

    function updateLoadMoreBtn() {
        var wrap = document.getElementById('fm-load-more-wrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'text-center mt-3';
            wrap.id = 'fm-load-more-wrap';
            var card = document.querySelector('.card:last-of-type');
            if (card) card.parentNode.insertBefore(wrap, card.nextSibling);
        }
        var remaining = state.total - state.page * state.perPage;
        if (remaining <= 0 || state.pages <= 1) { wrap.style.display='none'; return; }
        wrap.style.display = '';
        wrap.innerHTML = '<button class="btn btn-sm" style="border:1px solid #94a3b8;color:#38bdf8;" id="fm-load-more"><i class="bi bi-arrow-down-circle me-1"></i>Cargar mas ('+remaining+' restantes)</button><div class="text-muted mt-1" style="font-size:0.7rem;">Pagina '+state.page+' de '+state.pages+' — '+state.total+' items total</div>';
        document.getElementById('fm-load-more').addEventListener('click', loadMore);
    }

    function showLoading(show) { var el=document.getElementById('fm-loading'); if(el) el.style.display=show?'':'none'; }

    function fetchItems(page, append) {
        if (state.loading) return;
        state.loading = true;
        showLoading(true);
        var url = baseUrl()+'?path='+encodeURIComponent(state.path)+'&page='+page+'&per_page='+state.perPage+'&sort='+state.sort+'&order='+state.order;
        if (state.search) url += '&search='+encodeURIComponent(state.search);

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onload = function() {
            state.loading = false;
            showLoading(false);
            try { var data = JSON.parse(xhr.responseText); } catch(e) { return; }
            if (!data.ok) return;

            state.page = data.page; state.pages = data.pages; state.total = data.total;
            document.getElementById('fm-total-badge').textContent = data.total + ' items';

            var tbody = document.getElementById('fm-tbody');
            if (!append && tbody) {
                var rows = tbody.querySelectorAll('.fm-item');
                for (var i=0;i<rows.length;i++) rows[i].remove();
                var empty = document.getElementById('fm-empty');
                if (empty) empty.remove();
            }

            if (data.items.length===0 && !append) {
                if (tbody) tbody.insertAdjacentHTML('beforeend','<tr id="fm-empty"><td colspan="5" class="text-center text-muted py-3">Carpeta vacia</td></tr>');
            } else {
                for (var i=0;i<data.items.length;i++) {
                    if (tbody) tbody.insertAdjacentHTML('beforeend', renderRow(data.items[i]));
                }
            }
            bindNavLinks();
            updateLoadMoreBtn();
        };
        xhr.onerror = function() { state.loading=false; showLoading(false); };
        xhr.send();
    }

    function navigate(path) {
        state.path = path; state.page = 1; state.search = '';
        document.getElementById('fm-search').value = '';
        document.getElementById('fm-breadcrumb').innerHTML = renderBreadcrumb(path);
        document.getElementById('fm-path-display').textContent = path;
        var up=document.getElementById('fm-upload-path'), mk=document.getElementById('fm-mkdir-path');
        if(up) up.value=path; if(mk) mk.value=path;

        var tbody = document.getElementById('fm-tbody');
        var parentRow = tbody ? tbody.querySelector('.fm-parent-row') : null;
        if (path === '/') { if(parentRow) parentRow.remove(); }
        else {
            var parent = path.replace(/\/[^\/]+\/?$/, '') || '/';
            if (!parentRow && tbody) {
                tbody.insertAdjacentHTML('afterbegin','<tr class="fm-parent-row"><td class="ps-3" colspan="5"><a href="'+baseUrl()+'?path='+encodeURIComponent(parent)+'" class="text-info text-decoration-none fm-nav" data-path="'+esc(parent)+'"><i class="bi bi-arrow-up me-2"></i>..</a></td></tr>');
            } else if (parentRow) {
                var link = parentRow.querySelector('a');
                link.setAttribute('data-path', parent);
                link.href = baseUrl()+'?path='+encodeURIComponent(parent);
            }
        }
        history.pushState({path:path}, '', baseUrl()+'?path='+encodeURIComponent(path));
        fetchItems(1, false);
    }

    function loadMore() { if(state.page<state.pages) fetchItems(state.page+1, true); }

    function sortBy(column) {
        if (state.sort===column) state.order = state.order==='asc'?'desc':'asc';
        else { state.sort=column; state.order='asc'; }
        state.page = 1;
        var ths = document.querySelectorAll('#fm-table thead th[data-sort]');
        for (var i=0;i<ths.length;i++) {
            var ic = ths[i].querySelector('i.bi');
            if (!ic) continue;
            ic.className = ths[i].getAttribute('data-sort')===state.sort ? ('bi ms-1 '+(state.order==='asc'?'bi-chevron-up':'bi-chevron-down')) : 'bi bi-chevron-expand ms-1';
        }
        fetchItems(1, false);
    }

    function bindNavLinks() {
        var links = document.querySelectorAll('.fm-nav');
        for (var i=0;i<links.length;i++) {
            links[i].removeEventListener('click', navHandler);
            links[i].addEventListener('click', navHandler);
        }
    }
    function navHandler(e) { e.preventDefault(); var p=this.getAttribute('data-path'); if(p) navigate(p); }

    // Sort headers
    var sortHeaders = document.querySelectorAll('#fm-table thead th[data-sort]');
    for (var i=0;i<sortHeaders.length;i++) sortHeaders[i].addEventListener('click', function(e){ if(e.target.type==='checkbox') return; sortBy(this.getAttribute('data-sort')); });

    // Search debounce
    var searchInput = document.getElementById('fm-search');
    if (searchInput) searchInput.addEventListener('input', function(){ var v=this.value; clearTimeout(searchTimer); searchTimer=setTimeout(function(){ state.search=v; state.page=1; fetchItems(1,false); }, 300); });

    // Load more
    var lb = document.getElementById('fm-load-more');
    if (lb) lb.addEventListener('click', loadMore);

    // Browser nav
    window.addEventListener('popstate', function(e){ if(e.state&&e.state.path){ state.path=e.state.path; state.page=1; document.getElementById('fm-breadcrumb').innerHTML=renderBreadcrumb(state.path); document.getElementById('fm-path-display').textContent=state.path; fetchItems(1,false); } });
    history.replaceState({path:state.path}, '', window.location.href);
    bindNavLinks();

    // Infinite scroll
    window.addEventListener('scroll', function(){ if(state.loading||state.page>=state.pages) return; if(document.documentElement.scrollHeight-window.innerHeight-window.scrollY<300) loadMore(); });

    // Write mode timer
    <?php if ($wm && $wmRemaining > 0): ?>
    var remaining = <?= $wmRemaining ?>;
    var timerEl = document.getElementById('fm-write-timer');
    var timerInterval = setInterval(function(){
        remaining--;
        if (remaining <= 0) {
            clearInterval(timerInterval);
            location.reload();
            return;
        }
        var m = Math.floor(remaining/60), s = remaining%60;
        timerEl.textContent = (m<10?'0':'')+m+':'+(s<10?'0':'')+s;
    }, 1000);
    <?php endif; ?>
})();

function showWriteModeDialog() {
    var modal = new bootstrap.Modal(document.getElementById('writeModeModal'));
    modal.show();
}
</script>
