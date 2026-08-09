<?php
/**
 * 云界论坛 - 管理后台数据迁移
 *
 * 与"数据备份"（整库二进制/SQL 转储，用于同实例回滚）不同，本页面提供
 * 逻辑级数据迁移：将业务表导出为通用 JSON，再导入到另一个实例（可跨驱动）。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$pageTitle = t('admin_mig_title', '数据迁移');
$activeMenu = 'data_migration';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo e(t('admin_mig_title', '数据迁移')); ?></h1>
        <p class="page-subtitle"><?php echo e(t('admin_mig_subtitle', '将业务数据导出为通用 JSON，再导入到另一个实例（支持跨 SQLite / MySQL）')); ?></p>
    </div>
</div>

<?php if (function_exists('get_flash')): ?>
    <?php $flash = get_flash(); ?>
    <?php if ($flash): ?>
        <?php echo show_message($flash['message'], $flash['type']); ?>
    <?php endif; ?>
<?php endif; ?>

<!-- 说明横幅 -->
<div class="mig-info-banner">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div>
        <?php echo t('admin_mig_banner_tip', '<b>数据迁移</b>与<b>数据备份</b>不同：备份是整库快照（用于同实例回滚）；迁移是逻辑级数据导出/导入，适合把一个站点的用户、帖子、版块等数据搬到另一个新站点。导入前系统会自动创建"导入前快照"，可随时回滚。'); ?>
    </div>
</div>

<!-- 导出区 -->
<div class="card mig-card">
    <div class="card-header">
        <h2 class="card-title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <?php echo e(t('admin_mig_export_title', '导出数据')); ?>
        </h2>
        <div class="mig-header-tools">
            <button type="button" class="btn btn-sm btn-secondary" id="mig-select-all"><?php echo e(t('admin_mig_select_all', '全选')); ?></button>
            <button type="button" class="btn btn-sm btn-secondary" id="mig-select-none"><?php echo e(t('admin_mig_select_none', '全不选')); ?></button>
        </div>
    </div>
    <div class="mig-body">
        <p class="form-hint mig-export-hint">
            <?php echo e(t('admin_mig_export_hint', '勾选需要迁移的业务表，点击"导出选中数据"将生成 JSON 文件下载。默认已全部勾选。')); ?>
        </p>
        <div class="mig-table-grid" id="mig-table-grid">
            <div class="mig-table-loading"><?php echo e(t('admin_mig_loading_tables', '正在加载数据表…')); ?></div>
        </div>
        <div class="mig-export-footer">
            <span class="mig-selected-info" id="mig-selected-info"><?php echo e(t('admin_mig_selected_count', '已选 0 张表')); ?></span>
            <button type="button" class="btn btn-primary" id="mig-export-btn">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <?php echo e(t('admin_mig_export_btn', '导出选中数据')); ?>
            </button>
        </div>
    </div>
</div>

<!-- 导入区 -->
<div class="card mig-card">
    <div class="card-header">
        <h2 class="card-title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 5 17 10"/><line x1="12" y1="5" x2="12" y2="15"/></svg>
            <?php echo e(t('admin_mig_import_title', '导入数据')); ?>
        </h2>
    </div>
    <div class="mig-body">
        <p class="form-hint mig-import-hint">
            <?php echo e(t('admin_mig_import_hint', '选择此前导出的 .json 迁移文件，选择导入模式后点击"开始导入"。导入前会自动创建快照。')); ?>
        </p>
        <div class="mig-import-form">
            <div class="form-group">
                <label class="form-label" for="mig-file"><?php echo e(t('admin_mig_file_label', '迁移文件')); ?></label>
                <input type="file" id="mig-file" accept=".json,application/json" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_mig_mode_label', '导入模式')); ?></label>
                <div class="mig-mode-options">
                    <label class="mig-radio">
                        <input type="radio" name="mig_mode" value="overwrite" checked>
                        <span><?php echo e(t('admin_mig_mode_overwrite', '覆盖')); ?></span>
                        <small><?php echo e(t('admin_mig_mode_overwrite_tip', '清空目标表后写入，适合整站搬迁')); ?></small>
                    </label>
                    <label class="mig-radio">
                        <input type="radio" name="mig_mode" value="merge">
                        <span><?php echo e(t('admin_mig_mode_merge', '合并')); ?></span>
                        <small><?php echo e(t('admin_mig_mode_merge_tip', '保留目标数据，跳过主键冲突')); ?></small>
                    </label>
                </div>
            </div>
        </div>
        <div class="mig-import-footer">
            <button type="button" class="btn btn-primary" id="mig-import-btn">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 5 17 10"/><line x1="12" y1="5" x2="12" y2="15"/></svg>
                <?php echo e(t('admin_mig_import_btn', '开始导入')); ?>
            </button>
        </div>
        <div class="mig-import-result" id="mig-import-result" style="display:none;"></div>
    </div>
</div>

<!-- 操作结果反馈 -->
<div class="mig-toast" id="mig-toast" style="display:none;">
    <span id="mig-toast-text"></span>
</div>

<style>
.mig-info-banner {
    display: flex; gap: 0.75rem; align-items: flex-start;
    padding: 1rem 1.25rem; margin-bottom: 1.25rem;
    background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-lg);
    color: #1e40af; font-size: 0.875rem; line-height: 1.6;
}
[data-theme="dark"] .mig-info-banner { background: rgba(59,130,246,0.12); border-color: rgba(59,130,246,0.3); color: #93c5fd; }
.mig-info-banner b { color: inherit; }

.mig-card { margin-bottom: 1.25rem; }
.mig-header-tools { display: flex; gap: 0.5rem; }
.mig-export-hint, .mig-import-hint { margin-top: 0; margin-bottom: 1rem; }

.mig-table-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.625rem;
    max-height: 360px; overflow-y: auto; padding: 0.25rem; margin-bottom: 1rem;
    border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface-2);
}
.mig-table-loading { grid-column: 1 / -1; text-align: center; padding: 2rem; color: var(--text-muted); }

.mig-table-item {
    display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem;
    background: var(--surface); border: 1px solid var(--border-soft); border-radius: var(--radius-sm);
    cursor: pointer; transition: var(--transition); font-size: 0.875rem;
}
.mig-table-item:hover { border-color: var(--primary-lighter); }
.mig-table-item input { cursor: pointer; }
.mig-table-item .mig-tname { font-family: 'SF Mono', Monaco, Consolas, monospace; flex: 1; }
.mig-table-item .mig-tcount { font-size: 0.75rem; color: var(--text-muted); white-space: nowrap; }

.mig-export-footer, .mig-import-footer { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.mig-selected-info { font-size: 0.875rem; color: var(--text-secondary); font-weight: 600; }

.mig-import-form { display: flex; flex-direction: column; gap: 1.25rem; margin-bottom: 1rem; }
.mig-mode-options { display: flex; gap: 1rem; flex-wrap: wrap; }
.mig-radio { display: flex; flex-direction: column; gap: 0.25rem; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: var(--radius-sm); cursor: pointer; min-width: 200px; }
.mig-radio input { margin-right: 0.5rem; }
.mig-radio span { font-weight: 600; color: var(--text); }
.mig-radio small { color: var(--text-muted); font-size: 0.75rem; }

.mig-import-result {
    margin-top: 1rem; padding: 1rem 1.25rem; border-radius: var(--radius);
    font-size: 0.875rem; line-height: 1.7;
}
.mig-import-result.is-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
.mig-import-result.is-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
[data-theme="dark"] .mig-import-result.is-success { background: rgba(16,185,129,0.12); border-color: rgba(16,185,129,0.3); color: #6ee7b7; }
[data-theme="dark"] .mig-import-result.is-error { background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.3); color: #fca5a5; }
.mig-result-table { margin-top: 0.5rem; font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 0.8125rem; }
.mig-result-snapshot { margin-top: 0.5rem; font-size: 0.8125rem; opacity: 0.9; }

.mig-toast {
    position: fixed; top: 1.5rem; right: 1.5rem; z-index: 10000;
    padding: 0.875rem 1.25rem; background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); box-shadow: var(--shadow-lg);
    font-size: 0.875rem; font-weight: 500; color: var(--text);
    display: flex; align-items: center; gap: 0.5rem; transition: opacity 0.3s, transform 0.3s;
}
.mig-toast.is-success { border-left: 4px solid #10b981; }
.mig-toast.is-error { border-left: 4px solid #ef4444; }
.mig-toast.is-info { border-left: 4px solid var(--primary); }
</style>

<script>
(function () {
    var csrfToken = '<?php echo csrf_token(); ?>';
    var apiUrl = '<?php echo site_url('admin/api/data_migration_ajax'); ?>';

    function showToast(text, type) {
        var toast = document.getElementById('mig-toast');
        var textEl = document.getElementById('mig-toast-text');
        toast.className = 'mig-toast is-' + (type || 'info');
        toast.style.display = 'flex';
        textEl.textContent = text;
        clearTimeout(showToast._timer);
        showToast._timer = setTimeout(function () { toast.style.display = 'none'; }, 4000);
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ===== 加载数据表列表 =====
    var grid = document.getElementById('mig-table-grid');
    var selectedInfo = document.getElementById('mig-selected-info');

    function updateSelectedInfo() {
        var checked = grid.querySelectorAll('input[type=checkbox]:checked').length;
        selectedInfo.textContent = '<?php echo e(t('admin_mig_selected_count', '已选 {n} 张表')); ?>'.replace('{n}', checked);
    }

    fetch(apiUrl + '&action=list_tables&_=' + Date.now(), { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) { grid.innerHTML = '<div class="mig-table-loading">' + escapeHtml(res.error || '') + '</div>'; return; }
            if (!res.tables.length) { grid.innerHTML = '<div class="mig-table-loading"><?php echo e(t('admin_mig_no_tables', '没有可迁移的数据表')); ?></div>'; return; }
            var html = '';
            res.tables.forEach(function (t) {
                html += '<label class="mig-table-item">'
                    + '<input type="checkbox" class="mig-table-cb" value="' + escapeHtml(t.name) + '" checked>'
                    + '<span class="mig-tname">' + escapeHtml(t.name) + '</span>'
                    + '<span class="mig-tcount">' + t.count + ' <?php echo e(t('admin_mig_rows', '行')); ?></span>'
                    + '</label>';
            });
            grid.innerHTML = html;
            grid.querySelectorAll('.mig-table-cb').forEach(function (cb) {
                cb.addEventListener('change', updateSelectedInfo);
            });
            updateSelectedInfo();
        })
        .catch(function () { grid.innerHTML = '<div class="mig-table-loading"><?php echo e(t('admin_mig_load_failed', '加载失败，请刷新页面')); ?></div>'; });

    // ===== 全选 / 全不选 =====
    document.getElementById('mig-select-all').addEventListener('click', function () {
        grid.querySelectorAll('.mig-table-cb').forEach(function (cb) { cb.checked = true; });
        updateSelectedInfo();
    });
    document.getElementById('mig-select-none').addEventListener('click', function () {
        grid.querySelectorAll('.mig-table-cb').forEach(function (cb) { cb.checked = false; });
        updateSelectedInfo();
    });

    // ===== 导出 =====
    var exportBtn = document.getElementById('mig-export-btn');
    exportBtn.addEventListener('click', function () {
        var checked = Array.prototype.map.call(grid.querySelectorAll('.mig-table-cb:checked'), function (cb) { return cb.value; });
        if (!checked.length) { showToast('<?php echo e(t('admin_mig_no_table_selected', '请至少选择一张表进行导出')); ?>', 'error'); return; }

        var orig = exportBtn.innerHTML;
        exportBtn.disabled = true;
        exportBtn.innerHTML = '<?php echo e(t('admin_mig_exporting', '导出中…')); ?>';

        var fd = new FormData();
        fd.append('action', 'export');
        fd.append('csrf_token', csrfToken);
        fd.append('tables', checked.join(','));

        fetch(apiUrl, { method: 'POST', body: fd, cache: 'no-store' })
            .then(function (r) {
                if (!r.ok) return r.json().then(function (j) { throw new Error(j.error || '<?php echo e(t('admin_mig_export_failed', '导出失败')); ?>'); });
                var cd = r.headers.get('Content-Disposition') || '';
                var m = cd.match(/filename="?([^";]+)"?/);
                var fname = m ? m[1] : ('云界论坛_数据迁移_' + Date.now() + '.json');
                return r.blob().then(function (b) { return { b: b, fname: fname }; });
            })
            .then(function (o) {
                var url = URL.createObjectURL(o.b);
                var a = document.createElement('a');
                a.href = url; a.download = o.fname;
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                URL.revokeObjectURL(url);
                showToast('<?php echo e(t('admin_mig_export_success', '导出成功，已开始下载')); ?>', 'success');
            })
            .catch(function (e) { showToast(e.message || '<?php echo e(t('admin_mig_export_failed', '导出失败')); ?>', 'error'); })
            .finally(function () { exportBtn.disabled = false; exportBtn.innerHTML = orig; });
    });

    // ===== 导入 =====
    var importBtn = document.getElementById('mig-import-btn');
    var resultBox = document.getElementById('mig-import-result');
    importBtn.addEventListener('click', function () {
        var fileInput = document.getElementById('mig-file');
        if (!fileInput.files || !fileInput.files.length) { showToast('<?php echo e(t('admin_mig_no_file', '请先选择迁移文件')); ?>', 'error'); return; }
        var mode = (document.querySelector('input[name=mig_mode]:checked') || {}).value || 'overwrite';

        var orig = importBtn.innerHTML;
        importBtn.disabled = true;
        importBtn.innerHTML = '<?php echo e(t('admin_mig_importing', '导入中…')); ?>';
        resultBox.style.display = 'none';

        var fd = new FormData();
        fd.append('action', 'import');
        fd.append('csrf_token', csrfToken);
        fd.append('mode', mode);
        fd.append('file', fileInput.files[0]);

        fetch(apiUrl, { method: 'POST', body: fd, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    resultBox.className = 'mig-import-result is-error';
                    var html = escapeHtml(res.error || '<?php echo e(t('admin_mig_import_failed', '导入失败')); ?>');
                    if (res.snapshot) html += '<div class="mig-result-snapshot"><?php echo e(t('admin_mig_snapshot_created', '已创建导入前快照：')); ?>' + escapeHtml(res.snapshot) + '</div>';
                    resultBox.innerHTML = html;
                    resultBox.style.display = '';
                    showToast('<?php echo e(t('admin_mig_import_failed', '导入失败')); ?>', 'error');
                    return;
                }
                resultBox.className = 'mig-import-result is-success';
                var html = '<div>' + escapeHtml(res.message || '') + '</div>';
                html += '<div class="mig-result-table"><?php echo e(t('admin_mig_total_inserted', '总写入行数')); ?>：' + (res.total_inserted || 0) + '</div>';
                if (res.results) {
                    html += '<div class="mig-result-table">';
                    Object.keys(res.results).forEach(function (t) {
                        html += escapeHtml(t) + ': ' + res.results[t] + ' <?php echo e(t('admin_mig_rows', '行')); ?><br>';
                    });
                    html += '</div>';
                }
                if (res.snapshot) html += '<div class="mig-result-snapshot"><?php echo e(t('admin_mig_snapshot_created', '已创建导入前快照：')); ?>' + escapeHtml(res.snapshot) + '</div>';
                resultBox.innerHTML = html;
                resultBox.style.display = '';
                showToast('<?php echo e(t('admin_mig_import_done', '导入完成')); ?>', 'success');
            })
            .catch(function () { showToast('<?php echo e(t('admin_mig_import_network_fail', '网络错误，导入失败')); ?>', 'error'); })
            .finally(function () { importBtn.disabled = false; importBtn.innerHTML = orig; });
    });
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
