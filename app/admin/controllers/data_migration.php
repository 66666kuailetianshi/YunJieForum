<?php
/**
 * 云界论坛 - 管理后台数据迁移
 *
 * 与"数据备份"（整库二进制/SQL 转储，用于同实例回滚）不同，本页面提供
 * 逻辑级数据迁移：将业务表导出为通用 JSON，再导入到另一个实例（可跨驱动）。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：数据迁移仅超级管理员可用
require_super_admin();

$pageTitle = t('admin_mig_title', '数据迁移');
$activeMenu = 'data_migration';

require_once dirname(__DIR__) . '/layout/header.php';

// 当前数据库类型，用于限制导出格式与提示（禁止跨数据库类型迁移）
$currentDbType = 'mysql';
if (function_exists('get_db_driver')) {
    $currentDbType = get_db_driver()->isFileBased() ? 'sqlite' : 'mysql';
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo e(t('admin_mig_title', '数据迁移')); ?></h1>
        <p class="page-subtitle"><?php echo e(t('admin_mig_subtitle', '将业务数据导出为 SQL 或 JSON，再导入到另一个实例。支持 SQLite、MySQL 与通用 JSON 三种格式，但禁止在不同数据库类型之间互相迁移。')); ?></p>
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
        <?php echo t('admin_mig_banner_tip', '<b>数据迁移</b>与<b>数据备份</b>不同：备份是整库快照（用于同实例回滚）；迁移是逻辑级数据导出/导入，适合把一个站点的用户、帖子、版块等数据搬到另一个<b>同类型数据库</b>的新站点。系统支持 SQLite、MySQL 与通用 JSON 三种格式，但<b>禁止跨数据库类型迁移</b>（例如不能把 MySQL 数据导入 SQLite）。导入前系统会自动创建"导入前快照"，可随时回滚。'); ?>
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
            <?php echo e(t('admin_mig_export_hint', '勾选需要迁移的业务表，选择导出格式后点击"导出"。三种格式各自独立：SQLite 数据库 SQL、MySQL 数据库 SQL 仅可在同类型数据库间迁移，通用 JSON 也会记录来源数据库类型并在导入时校验。无法导出与当前数据库类型不符的格式（已自动禁用）。默认已全部勾选。')); ?>
        </p>
        <div class="mig-table-grid" id="mig-table-grid">
            <div class="mig-table-loading"><?php echo e(t('admin_mig_loading_tables', '正在加载数据表…')); ?></div>
        </div>
        <div class="mig-export-footer">
            <div class="mig-export-row">
                <span class="mig-selected-info" id="mig-selected-info"><?php echo e(t('admin_mig_selected_count', '已选 0 张表')); ?></span>
                <label class="mig-format-label">
                    <span><?php echo e(t('admin_mig_export_format', '格式')); ?></span>
                    <select id="mig-export-format" class="mig-format-select">
                        <option value="json"><?php echo e(t('admin_mig_format_json', '通用 JSON')); ?></option>
                        <option value="json_zip"><?php echo e(t('admin_mig_format_json_zip', '通用 JSON（ZIP 含头像）')); ?></option>
                        <option value="sqlite"<?php echo $currentDbType === 'sqlite' ? ' selected' : ''; ?><?php echo $currentDbType !== 'sqlite' ? ' disabled' : ''; ?>><?php echo e(t('admin_mig_format_sqlite', 'SQLite 数据库（ZIP 含头像）')); ?><?php echo $currentDbType !== 'sqlite' ? ' — ' . e(t('admin_mig_format_current_mismatch', '当前为 MySQL')) : ''; ?></option>
                        <option value="mysql"<?php echo $currentDbType === 'mysql' ? ' selected' : ''; ?><?php echo $currentDbType !== 'mysql' ? ' disabled' : ''; ?>><?php echo e(t('admin_mig_format_mysql', 'MySQL 数据库（ZIP 含头像）')); ?><?php echo $currentDbType !== 'mysql' ? ' — ' . e(t('admin_mig_format_current_mismatch2', '当前为 SQLite')) : ''; ?></option>
                    </select>
                </label>
                <button type="button" class="btn btn-primary mig-export-btn" id="mig-export-btn">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <?php echo e(t('admin_mig_export_btn', '导出选中数据')); ?>
                </button>
            </div>
            <div id="mig-format-scene" class="mig-format-scene"></div>
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
            <?php echo e(t('admin_mig_import_hint', '选择此前导出的迁移文件（支持 .json、.sql 和 .zip），选择导入模式后点击“开始导入”。导入前会自动创建快照。注意：系统会自动校验文件来源数据库类型，禁止在不同数据库类型之间迁移。.zip 格式包含完整的上传文件（头像等），推荐用于完整迁移。SQL 格式同时包含覆盖和合并两种模式，导入时自动根据所选模式匹配对应文件。')); ?>
        </p>
        <div class="mig-import-form">
            <div class="form-group">
                <label class="form-label" for="mig-file"><?php echo e(t('admin_mig_file_label', '迁移文件')); ?></label>
                <input type="file" id="mig-file" accept=".json,.sql,.zip,application/json,text/sql,application/zip" class="form-control">
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
        <div class="mig-cleanup-bar">
            <p class="form-hint mig-cleanup-hint">
                <?php echo e(t('admin_mig_cleanup_hint', '如果合并导入后发现首页出现重复的分类、版块、帖子或回复，可点击右侧按钮一键合并重复项：保留最早创建的一条，将其余重复项下的关联数据归并到保留项后删除，并自动重新计算用户帖子统计。')); ?>
            </p>
            <button type="button" class="btn btn-secondary" id="mig-cleanup-btn">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;vertical-align:-3px;"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                <?php echo e(t('admin_mig_cleanup_btn', '清理重复数据')); ?>
            </button>
        </div>
        <div class="mig-import-footer">
            <button type="button" class="btn btn-primary" id="mig-import-btn">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 5 17 10"/><line x1="12" y1="5" x2="12" y2="15"/></svg>
                <?php echo e(t('admin_mig_import_btn', '开始导入')); ?>
            </button>
        </div>
        <!-- 导入进度条 -->
        <div class="mig-progress" id="mig-import-progress" style="display:none;">
            <div class="mig-progress-bar">
                <div class="mig-progress-fill" id="mig-progress-fill"></div>
            </div>
            <div class="mig-progress-stage" id="mig-progress-stage"></div>
        </div>
        <div class="mig-import-result" id="mig-import-result" style="display:none;"></div>
    </div>
</div>

<!-- 操作结果反馈 -->
<div class="mig-toast" id="mig-toast" style="display:none;">
    <span id="mig-toast-text"></span>
</div>

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

    var listTablesFd = new FormData();
        listTablesFd.append('action', 'list_tables');
        listTablesFd.append('csrf_token', csrfToken);
        fetch(apiUrl, { method: 'POST', body: listTablesFd, cache: 'no-store', credentials: 'same-origin' })
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

    // ===== 导出格式场景说明 =====
    var formatSelect = document.getElementById('mig-export-format');
    var formatScene = document.getElementById('mig-format-scene');
    var formatScenes = {
        json: '<?php echo e(t('admin_mig_scene_json', '适合跨 SQLite / MySQL 环境迁移；文件小巧、可读，支持合并导入（保留目标数据并跳过冲突）或覆盖导入。不带头像等上传文件。')); ?>',
        json_zip: '<?php echo e(t('admin_mig_scene_json_zip', '适合跨环境迁移且需要保留头像、上传文件；ZIP 内为 JSON + uploads/，支持合并或覆盖导入。')); ?>',
        sqlite: '<?php echo e(t('admin_mig_scene_sqlite', '适合相同 SQLite 环境做整库搬迁；ZIP 内为 SQL + uploads/，会执行 DROP TABLE + CREATE TABLE，仅支持覆盖导入。')); ?>',
        mysql: '<?php echo e(t('admin_mig_scene_mysql', '适合相同 MySQL 环境做整库搬迁；ZIP 内为 SQL + uploads/，会执行 DROP TABLE + CREATE TABLE，仅支持覆盖导入。')); ?>'
    };
    function updateFormatScene() {
        if (!formatScene) return;
        var text = formatScenes[formatSelect.value];
        if (text) {
            formatScene.innerHTML = '<strong>' + '<?php echo e(t('admin_mig_scene_label', '适用场景')); ?>' + '</strong>：' + escapeHtml(text);
        } else {
            formatScene.innerHTML = '';
        }
    }
    formatSelect.addEventListener('change', updateFormatScene);
    updateFormatScene();

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
        fd.append('format', document.getElementById('mig-export-format').value);

        fetch(apiUrl, { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) return r.json().then(function (j) { throw new Error(j.error || '<?php echo e(t('admin_mig_export_failed', '导出失败')); ?>'); });
                var cd = r.headers.get('Content-Disposition') || '';
                var m = cd.match(/filename="?([^";]+)"?/);
                var fname = m ? m[1] : (<?php echo json_encode((defined('SITE_NAME') ? SITE_NAME : '云界论坛') . '_数据迁移_', JSON_UNESCAPED_UNICODE); ?> + Date.now() + '.json');
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

    // ===== 导入模式随文件类型联动 =====
    // .sql 文件支持覆盖/合并两种模式（合并模式通过 -- MIG-MODE: merge 头部控制，跳过 DROP TABLE）；
    // .json 文件支持“合并”与“覆盖”两种模式；
    // .zip 文件内部可能是 SQL（覆盖/合并）或 JSON（支持合并），由后端根据实际内容判断。
    var fileInputForMode = document.getElementById('mig-file');
    var mergeRadio = document.querySelector('input[name=mig_mode][value=merge]');
    var overwriteRadio = document.querySelector('input[name=mig_mode][value=overwrite]');
    var modeFileTip = document.createElement('div');
    modeFileTip.className = 'form-hint';
    modeFileTip.style.cssText = 'color:#991b1b;margin-top:0.5rem;display:none;';
    document.querySelector('.mig-mode-options').after(modeFileTip);
    
    function updateModeByFile() {
        var name = (fileInputForMode.files && fileInputForMode.files[0] && fileInputForMode.files[0].name) || '';
        var ext = name.split('.').pop().toLowerCase();
        if (ext === 'sql') {
            mergeRadio.disabled = false;
            modeFileTip.style.display = '';
            modeFileTip.textContent = '<?php echo e(t('admin_mig_sql_merge_enabled', 'SQL 文件支持覆盖/合并两种模式。合并模式将跳过 DROP TABLE 语句，保留目标库已有数据，并使用 INSERT IGNORE 跳过主键冲突。')); ?>';
            modeFileTip.style.color = '#15803d';
        } else if (ext === 'zip') {
            mergeRadio.disabled = false;
            modeFileTip.style.display = '';
            modeFileTip.textContent = '<?php echo e(t('admin_mig_zip_merge_hint', 'ZIP 文件同时包含覆盖和合并两种 SQL（或 JSON），导入时系统会根据您选择的模式自动匹配对应文件。')); ?>';
            modeFileTip.style.color = '#15803d';
        } else {
            mergeRadio.disabled = false;
            modeFileTip.style.display = 'none';
        }
    }
    fileInputForMode.addEventListener('change', updateModeByFile);
    updateModeByFile();

    // ===== 导入 =====
    var importBtn = document.getElementById('mig-import-btn');
    var resultBox = document.getElementById('mig-import-result');
    var progressBar = document.getElementById('mig-import-progress');
    var progressFill = document.getElementById('mig-progress-fill');
    var progressStage = document.getElementById('mig-progress-stage');

    // 阶段文案（根据文件类型动态选择）
    var stages = {
        zip: [
            '<?php echo e(t('admin_mig_prog_upload', '上传文件中…')); ?>',
            '<?php echo e(t('admin_mig_prog_parse', '解析压缩包…')); ?>',
            '<?php echo e(t('admin_mig_prog_restore', '还原头像等资源文件…')); ?>',
            '<?php echo e(t('admin_mig_prog_snapshot', '创建导入前快照…')); ?>',
            '<?php echo e(t('admin_mig_prog_import', '导入数据到数据库…')); ?>'
        ],
        other: [
            '<?php echo e(t('admin_mig_prog_upload', '上传文件中…')); ?>',
            '<?php echo e(t('admin_mig_prog_parse', '解析迁移文件…')); ?>',
            '<?php echo e(t('admin_mig_prog_snapshot', '创建导入前快照…')); ?>',
            '<?php echo e(t('admin_mig_prog_import', '导入数据到数据库…')); ?>'
        ]
    };

    function showProgress(isZip) {
        progressBar.style.display = '';
        progressBar.className = 'mig-progress';
        progressFill.className = 'mig-progress-fill is-active';
        progressFill.style.width = '';
        resultBox.style.display = 'none';
        // 按阶段推进（每阶段约 3-5 秒，实际由服务端响应决定何时结束）
        var list = isZip ? stages.zip : stages.other;
        var idx = 0;
        progressStage.textContent = list[idx] || '';
        var timer = setInterval(function () {
            idx++;
            if (idx < list.length) {
                progressStage.textContent = list[idx];
                // 逐步增加视觉进度感
                var pct = Math.min(20 + (idx / list.length) * 50, 70);
                progressFill.style.width = pct + '%';
                progressFill.classList.remove('is-active'); // 切换为固定宽度模式
            } else {
                clearInterval(timer);
            }
        }, 3500);
        return timer; // 返回定时器 ID，供外部清除
    }

    function hideProgress(done) {
        if (done) {
            progressBar.className = 'mig-progress is-done';
            progressFill.className = 'mig-progress-fill';
            progressFill.style.width = '100%';
            progressStage.textContent = '<?php echo e(t('admin_mig_prog_done', '导入完成')); ?>';
            setTimeout(function () { progressBar.style.display = 'none'; }, 2500);
        } else {
            progressBar.style.display = 'none';
        }
    }

    importBtn.addEventListener('click', function () {
        var fileInput = document.getElementById('mig-file');
        if (!fileInput.files || !fileInput.files.length) { showToast('<?php echo e(t('admin_mig_no_file', '请先选择迁移文件')); ?>', 'error'); return; }
        var mode = (document.querySelector('input[name=mig_mode]:checked') || {}).value || 'overwrite';
        var isZip = (fileInput.files[0].name || '').toLowerCase().endsWith('.zip');

        var orig = importBtn.innerHTML;
        importBtn.disabled = true;
        importBtn.innerHTML = '<?php echo e(t('admin_mig_importing', '导入中…')); ?>';

        var progTimer = showProgress(isZip);

        var fd = new FormData();
        fd.append('action', 'import');
        fd.append('csrf_token', csrfToken);
        fd.append('mode', mode);
        fd.append('file', fileInput.files[0]);

        fetch(apiUrl, { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) {
                return r.text().then(function (text) {
                    if (!text || text.trim() === '') {
                        throw new Error('服务器未返回任何数据。常见原因：导入数据量较大，触发服务器或反向代理超时（通常 60 秒）。建议：1) 减少单次导入的数据量；2) 联系管理员调大 proxy_read_timeout 与 PHP max_execution_time；3) 改用覆盖模式分批导入。');
                    }
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('服务器返回了非 JSON 内容（可能被截断或超时）：' + text.slice(0, 300));
                    }
                });
            })
            .then(function (res) {
                clearInterval(progTimer);
                if (!res.success) {
                    hideProgress(false);
                    resultBox.className = 'mig-import-result is-error';
                    // 优先显示 error 字段，其次 message（SQL 部分失败时带具体错误）
                    var html = escapeHtml(res.error || res.message || '<?php echo e(t('admin_mig_import_failed', '导入失败')); ?>');
                    // 显示详细错误列表：error 字段已附带前 5 条时仅补显剩余条目，避免重复展示造成视觉重叠
                    if (res.errors && res.errors.length) {
                        var errOffset = (res.error && res.errors.length > 5) ? 5 : 0;
                        var restErrors = res.errors.slice(errOffset);
                        if (restErrors.length) {
                            html += '<div class="mig-result-table mt-2">';
                            restErrors.slice(0, 20).forEach(function (err) {
                                html += escapeHtml(err) + '<br>';
                            });
                            html += '</div>';
                        }
                    }
                    // 显示失败 SQL 原文（前 10 条），便于定位 "near ''" 被截断位置
                    if (res.failed_statements && res.failed_statements.length) {
                        html += '<details class="mt-2"><summary style="cursor:pointer;color:var(--text-muted);"><?php echo e(t('admin_mig_failed_statements', '查看失败 SQL 原文（用于诊断）')); ?></summary>';
                        html += '<pre style="margin-top:0.5rem;padding:0.5rem;background:var(--surface-3);border-radius:4px;white-space:pre-wrap;word-break:break-all;font-size:0.75rem;">';
                        res.failed_statements.forEach(function (stmt) {
                            html += escapeHtml(stmt) + "\n\n";
                        });
                        html += '</pre></details>';
                    }
                    if (res.snapshot) html += '<div class="mig-result-snapshot"><?php echo e(t('admin_mig_snapshot_created', '已创建导入前快照：')); ?>' + escapeHtml(res.snapshot) + '</div>';
                    resultBox.innerHTML = html;
                    resultBox.style.display = '';
                    showToast('<?php echo e(t('admin_mig_import_failed', '导入失败')); ?>', 'error');
                    return;
                }
                hideProgress(true);
                resultBox.className = 'mig-import-result is-success';
                var html = '<div>' + escapeHtml(res.message || '') + '</div>';
                html += '<div class="mig-result-table"><?php echo e(t('admin_mig_total_inserted', '总写入行数')); ?>：' + (res.total_inserted || res.total_executed || 0) + '</div>';
                if (res.total_skipped > 0) {
                    html += '<div class="mig-result-table"><?php echo e(t('admin_mig_total_skipped', '总跳过行数')); ?>：' + res.total_skipped + '</div>';
                }
                if (res.total_remapped > 0) {
                    html += '<div class="mig-result-table"><?php echo e(t('admin_mig_total_remapped', '总重映射行数')); ?>：' + res.total_remapped + '</div>';
                }
                if (res.results) {
                    html += '<div class="mig-result-table">';
                    Object.keys(res.results).forEach(function (t) {
                        var rinfo = res.results[t];
                        if (typeof rinfo === 'number') {
                            html += escapeHtml(t) + ': ' + rinfo + ' <?php echo e(t('admin_mig_rows', '行')); ?><br>';
                        } else {
                            html += escapeHtml(t) + ': ' + (rinfo.inserted || 0) + ' <?php echo e(t('admin_mig_rows_inserted', '行写入')); ?>';
                            if (rinfo.skipped > 0) html += ' / ' + rinfo.skipped + ' <?php echo e(t('admin_mig_rows_skipped', '行跳过')); ?>';
                            if (rinfo.remapped > 0) html += ' / ' + rinfo.remapped + ' <?php echo e(t('admin_mig_rows_remapped', '行重映射')); ?>';
                            html += '<br>';
                        }
                    });
                    html += '</div>';
                }
                if (res.row_errors && res.row_errors.length) {
                    html += '<div class="mig-result-table" style="color:#991b1b;margin-top:0.5rem;"><?php echo e(t('admin_mig_row_errors', '部分行跳过原因（前 20 条）')); ?>：<br>';
                    res.row_errors.forEach(function (err) {
                        html += escapeHtml(err) + '<br>';
                    });
                    html += '</div>';
                }
                if (res.snapshot) html += '<div class="mig-result-snapshot"><?php echo e(t('admin_mig_snapshot_created', '已创建导入前快照：')); ?>' + escapeHtml(res.snapshot) + '</div>';
                resultBox.innerHTML = html;
                resultBox.style.display = '';
                showToast('<?php echo e(t('admin_mig_import_done', '导入完成')); ?>', 'success');
            })
            .catch(function (e) {
                clearInterval(progTimer);
                hideProgress(false);
                showToast((e && e.message ? e.message : '<?php echo e(t('admin_mig_import_network_fail', '网络错误，导入失败')); ?>'), 'error');
            })
            .finally(function () { importBtn.disabled = false; importBtn.innerHTML = orig; });
    });

    // ===== 清理重复分类/版块 =====
    var cleanupBtn = document.getElementById('mig-cleanup-btn');
    cleanupBtn.addEventListener('click', function () {
        if (!confirm('<?php echo e(t('admin_mig_cleanup_confirm', '确定要合并重复的分类/版块/帖子/回复吗？此操作会保留每组重复项中最早创建的一条，并将其余重复项下的关联数据迁移到保留项，并自动重新计算用户帖子统计。建议先创建备份。')); ?>')) return;
        var orig = cleanupBtn.innerHTML;
        cleanupBtn.disabled = true;
        cleanupBtn.innerHTML = '<?php echo e(t('admin_mig_cleaning', '清理中…')); ?>';

        var fd = new FormData();
        fd.append('action', 'cleanup_duplicate_forums');
        fd.append('csrf_token', csrfToken);

        fetch(apiUrl, { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (text) {
                if (!text || text.trim() === '') throw new Error('服务器未返回数据');
                try { return JSON.parse(text); } catch (e) { throw new Error('返回内容解析失败：' + text.slice(0, 200)); }
            })
            .then(function (res) {
                resultBox.style.display = '';
                if (res.success) {
                    resultBox.className = 'mig-import-result is-success';
                    resultBox.innerHTML = '<div>' + escapeHtml(res.message || '') + '</div>';
                    showToast('<?php echo e(t('admin_mig_cleanup_done_short', '清理完成')); ?>', 'success');
                } else {
                    resultBox.className = 'mig-import-result is-error';
                    resultBox.innerHTML = '<div>' + escapeHtml(res.error || '<?php echo e(t('admin_mig_cleanup_failed', '清理失败')); ?>') + '</div>';
                    showToast('<?php echo e(t('admin_mig_cleanup_failed', '清理失败')); ?>', 'error');
                }
            })
            .catch(function (e) {
                resultBox.className = 'mig-import-result is-error';
                resultBox.innerHTML = '<div>' + escapeHtml(e.message || '<?php echo e(t('admin_mig_cleanup_failed', '清理失败')); ?>') + '</div>';
                resultBox.style.display = '';
                showToast(e.message || '<?php echo e(t('admin_mig_cleanup_failed', '清理失败')); ?>', 'error');
            })
            .finally(function () { cleanupBtn.disabled = false; cleanupBtn.innerHTML = orig; });
    });
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
