<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php
    // Helper to format file size
    function formatLogSize(int $bytes): string {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    $groupIcons = [
        'Panel' => 'bi-layout-text-sidebar',
        'Caddy' => 'bi-globe',
        'Cuentas' => 'bi-people',
        'PHP-FPM' => 'bi-filetype-php',
        'Sistema' => 'bi-cpu',
    ];
?>

<div class="row g-3">
    <!-- Left: File selector -->
    <div class="col-md-3">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-folder2-open me-1"></i>Archivos de Log</h6>
            </div>
            <div class="card-body p-2">
                <?php if (empty($logFiles)): ?>
                    <p class="text-muted small mb-0">No se encontraron archivos de log.</p>
                <?php else: ?>
                    <?php foreach ($logFiles as $group => $files): ?>
                        <div class="mb-2">
                            <small class="text-uppercase fw-bold" style="color:#94a3b8;font-size:0.7rem;letter-spacing:0.05em;">
                                <i class="<?= $groupIcons[$group] ?? 'bi-file-text' ?> me-1"></i><?= View::e($group) ?>
                            </small>
                            <div class="list-group list-group-flush mt-1">
                                <?php foreach ($files as $file): ?>
                                    <?php
                                        $isActive = ($selectedFile === $file['path']);
                                        $activeClass = $isActive ? 'active' : '';
                                        $url = '/settings/logs?' . http_build_query(['file' => $file['path'], 'lines' => $lines]);
                                    ?>
                                    <a href="<?= View::e($url) ?>"
                                       class="list-group-item list-group-item-action py-1 px-2 d-flex justify-content-between align-items-center <?= $activeClass ?>"
                                       style="font-size:0.8rem;background:<?= $isActive ? 'rgba(56,189,248,0.15)' : 'transparent' ?>;border-color:rgba(148,163,184,0.1);color:<?= $isActive ? '#38bdf8' : '#cbd5e1' ?>;">
                                        <span class="text-truncate me-1"><?= View::e($file['label']) ?></span>
                                        <span class="badge" style="background:rgba(148,163,184,0.15);color:#94a3b8;font-size:0.65rem;"><?= formatLogSize($file['size']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right: Log content -->
    <div class="col-md-9">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h6 class="mb-0"><i class="bi bi-terminal me-1"></i>Visor de Logs</h6>
                    <?php if ($fileExists): ?>
                        <small class="text-muted" style="font-size:0.75rem;">
                            <?= View::e($displayPath) ?> &mdash; <?= formatLogSize($fileSize) ?>
                        </small>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <!-- Lines selector -->
                    <div class="d-flex align-items-center gap-1">
                        <small class="text-muted">Lineas:</small>
                        <?php foreach ([50, 100, 200, 500] as $n): ?>
                            <?php
                                $isActiveLines = ($lines === $n);
                                $linesUrl = '/settings/logs?' . http_build_query(['file' => $selectedFile, 'lines' => $n]);
                            ?>
                            <a href="<?= View::e($linesUrl) ?>"
                               class="btn btn-sm <?= $isActiveLines ? 'btn-primary' : 'btn-outline-secondary' ?>"
                               style="font-size:0.7rem;padding:2px 8px;">
                                <?= $n ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Refresh -->
                    <a href="/settings/logs?<?= http_build_query(['file' => $selectedFile, 'lines' => $lines]) ?>"
                       class="btn btn-outline-light btn-sm" title="Refrescar">
                        <i class="bi bi-arrow-clockwise"></i>
                    </a>

                    <!-- Scroll to bottom -->
                    <button type="button" class="btn btn-outline-light btn-sm" id="btnScrollBottom" title="Ir al final">
                        <i class="bi bi-arrow-down-circle"></i>
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="logViewer" style="max-height:600px;overflow:auto;background:#0f172a;border-radius:0 0 0.375rem 0.375rem;">
                    <pre style="margin:0;padding:1rem;font-family:'JetBrains Mono','Fira Code','Cascadia Code',Consolas,monospace;font-size:0.78rem;line-height:1.5;color:#e2e8f0;white-space:pre-wrap;word-wrap:break-word;"><?php if (!$fileExists && !empty($selectedFile)): ?><span style="color:#f87171;"><?= View::e($logContent) ?></span><?php elseif (empty(trim($logContent))): ?><span style="color:#64748b;">-- Archivo vacio --</span><?php else: ?><?= View::e($logContent) ?><?php endif; ?></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Log viewer scrollbar */
    #logViewer::-webkit-scrollbar { width: 8px; height: 8px; }
    #logViewer::-webkit-scrollbar-track { background: #1e293b; }
    #logViewer::-webkit-scrollbar-thumb { background: #475569; border-radius: 4px; }
    #logViewer::-webkit-scrollbar-thumb:hover { background: #64748b; }

    /* Active file in list */
    .list-group-item.active {
        border-left: 2px solid #38bdf8 !important;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var viewer = document.getElementById('logViewer');
    var btnScroll = document.getElementById('btnScrollBottom');

    // Auto-scroll to bottom on load
    if (viewer) {
        viewer.scrollTop = viewer.scrollHeight;
    }

    // Scroll to bottom button
    if (btnScroll && viewer) {
        btnScroll.addEventListener('click', function() {
            viewer.scrollTo({ top: viewer.scrollHeight, behavior: 'smooth' });
        });
    }
});
</script>
