<?php
/**
 * 云界论坛 - 管理后台「系统更新中心」
 *
 * 提供：
 *   1. 当前版本展示与「检查更新」入口（手动）。
 *   2. 「立即更新」入口（手动应用：下载 → 校验 → 备份 → 覆盖）。
 *   3. 自动更新设置（更新源地址、更新通道、是否自动应用、检查间隔）。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';
require_once APP_ROOT . 'app/includes/update_center.php';

$errors   = [];
$autoResult = null;

$updateSourceUrl    = uc_get_setting('update_source_url', '');
$updateChannel      = uc_get_setting('update_channel', 'stable');
$updateAutoEnabled  = uc_get_setting('update_auto_enabled', '0') === '1';
$updateAutoInterval = (int)uc_get_setting('update_auto_interval', '24');
$updateSslVerify    = uc_get_setting('update_ssl_verify', '0') === '1';
$updateSkipHash     = uc_get_setting('update_skip_hash', '0') === '1';
$updateLastCheck    = (int)uc_get_setting('update_last_check', '0');
$updateLastVersion  = uc_get_setting('update_last_version', '');
$currentVersion     = uc_get_current_version();

// 自动更新触发（仅非 POST 访问、且已启用时，按间隔自动检查并应用）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $autoResult = uc_try_auto_update();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $updateSourceUrl = trim((string)($_POST['update_source_url'] ?? ''));
    $updateChannel   = trim((string)($_POST['update_channel'] ?? 'stable'));
    if (!in_array($updateChannel, ['stable', 'beta', 'dev'], true)) {
        $updateChannel = 'stable';
    }
    $updateAutoEnabled  = !empty($_POST['update_auto_enabled']) ? '1' : '0';
    $updateAutoInterval = (int)($_POST['update_auto_interval'] ?? '24');
    $updateSslVerify    = !empty($_POST['update_ssl_verify']) ? '1' : '0';
    $updateSkipHash     = !empty($_POST['update_skip_hash']) ? '1' : '0';
    if ($updateAutoInterval < 1)  $updateAutoInterval = 1;
    if ($updateAutoInterval > 720) $updateAutoInterval = 720;

    set_site_setting('update_source_url', $updateSourceUrl);
    set_site_setting('update_channel', $updateChannel);
    set_site_setting('update_auto_enabled', $updateAutoEnabled);
    set_site_setting('update_auto_interval', (string)$updateAutoInterval);
    set_site_setting('update_ssl_verify', $updateSslVerify);
    set_site_setting('update_skip_hash', $updateSkipHash);

    set_flash(t('update_settings_saved', '更新中心设置已保存。'), 'success');
    redirect('/admin/update_center');
}

$pageTitle   = t('update_title', '系统更新');
$activeMenu  = 'update_center';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo e(t('update_title', '系统更新')); ?></h1>
        <p class="page-subtitle"><?php echo e(t('update_desc', '检查并应用云界论坛的版本更新，支持手动与自动两种方式。')); ?></p>
    </div>
</div>

<?php if ($autoResult !== null && !empty($autoResult['ran'])): ?>
    <?php $ar = $autoResult['result'] ?? []; ?>
    <?php if (!empty($ar['success'])): ?>
        <?php echo show_message(t('update_auto_applied', '已自动更新至 {to}（备份：{backup}）', ['to' => $ar['to'] ?? '', 'backup' => basename($ar['backup'] ?? '')]), 'success'); ?>
    <?php elseif (!empty($ar['error'])): ?>
        <?php echo show_message(t('update_auto_failed', '自动更新未成功：{err}', ['err' => $ar['error']]), 'error'); ?>
    <?php endif; ?>
<?php endif; ?>

<div class="card mb-2">
    <h2 class="card-title mb-1"><?php echo e(t('update_status', '更新状态')); ?></h2>
    <div class="update-meta">
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('update_current_version', '当前版本')); ?></span>
            <span class="update-meta-value" id="currentVersion"><?php echo e($currentVersion); ?></span>
        </div>
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('update_last_check', '上次检查')); ?></span>
            <span class="update-meta-value" id="lastCheck">
                <?php echo $updateLastCheck > 0 ? e(date('Y-m-d H:i:s', $updateLastCheck)) : e(t('update_never', '从未')); ?>
            </span>
        </div>
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('update_channel', '更新通道')); ?></span>
            <span class="update-meta-value"><?php echo e($updateChannel); ?></span>
        </div>
    </div>

    <div id="updateStatus" class="update-status" style="display:none;"></div>

    <!-- 更新进度条 -->
    <div id="updateProgress" class="update-progress-wrap" style="display:none;">
        <div class="update-progress-header">
            <span class="update-progress-spinner">⟳</span>
            <span id="updateProgressStage" class="update-progress-stage"><?php echo e(t('update_progress_preparing', '准备中…')); ?></span>
            <span id="updateProgressPct" class="update-progress-pct">0%</span>
        </div>
        <div class="update-progress-bar-outer">
            <div class="update-progress-bar-inner" id="updateProgressBar" style="width:0%"></div>
        </div>
        <div id="updateProgressDetail" class="update-progress-detail"></div>
    </div>

    <div class="update-actions mt-2">
        <button type="button" class="btn btn-secondary" id="checkBtn"><?php echo e(t('update_check_now', '检查更新')); ?></button>
        <button type="button" class="btn btn-primary" id="updateBtn" disabled><?php echo e(t('update_update_now', '立即更新')); ?></button>
    </div>
    <p class="form-hint mt-1"><?php echo e(t('update_manual_hint', '「立即更新」会在下载后校验文件哈希、先自动备份现有代码，再覆盖升级。请在操作前确认已开启自动备份。')); ?></p>
</div>

<div class="card">
    <h2 class="card-title mb-1"><?php echo e(t('update_settings', '更新设置')); ?></h2>
    <form method="POST" action="<?php echo site_url('admin/update_center'); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <div class="form-group">
            <label class="form-label" for="update_source_url"><?php echo e(t('update_source_url', '更新源地址')); ?></label>
            <input type="text" class="form-control" id="update_source_url" name="update_source_url"
                   value="<?php echo e($updateSourceUrl); ?>" placeholder="https://update.example.com/yunjie"
                   style="max-width: 480px;">
            <p class="form-hint"><?php echo e(t('update_source_url_hint', '支持两种格式：<br>① 目录地址（如 https://example.com/updates）→ 自动拼接 /{通道}/version.json<br>② 直链文件（如 .txt/.json 结尾）→ 直接作为版本信息读取，内容为 JSON 或纯文本版本号。留空则无法进行更新检查。')); ?></p>
        </div>

        <div class="form-group">
            <label class="form-label" for="update_channel"><?php echo e(t('update_channel', '更新通道')); ?></label>
            <select class="form-control" id="update_channel" name="update_channel" style="max-width: 260px;">
                <option value="stable" <?php echo $updateChannel === 'stable' ? 'selected' : ''; ?>><?php echo e(t('update_channel_stable', '稳定版（stable）')); ?></option>
                <option value="beta" <?php echo $updateChannel === 'beta' ? 'selected' : ''; ?>><?php echo e(t('update_channel_beta', '测试版（beta）')); ?></option>
                <option value="dev" <?php echo $updateChannel === 'dev' ? 'selected' : ''; ?>><?php echo e(t('update_channel_dev', '开发版（dev）')); ?></option>
            </select>
            <p class="form-hint"><?php echo e(t('update_channel_hint', '稳定版经过完整测试；测试版/开发版可能包含未稳定的功能，仅建议在测试环境启用。')); ?></p>
        </div>

        <div class="form-group">
            <label class="flex items-center gap-1" style="cursor: pointer;">
                <input type="checkbox" id="update_ssl_verify" name="update_ssl_verify" value="1" <?php echo $updateSslVerify ? 'checked' : ''; ?>>
                <span style="font-weight:600;"><?php echo e(t('update_ssl_verify', '严格校验 SSL 证书')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('update_ssl_verify_hint', '若更新源使用自签名证书（如大部分个人服务器），请保持关闭。仅在更新源由正规 CA 签发证书时才需开启。默认关闭。')); ?></p>
        </div>

        <div class="form-group">
            <label class="flex items-center gap-1" style="cursor: pointer;">
                <input type="checkbox" id="update_skip_hash" name="update_skip_hash" value="1" <?php echo $updateSkipHash ? 'checked' : ''; ?>>
                <span style="font-weight:600;"><?php echo e(t('update_skip_hash', '跳过哈希校验（不推荐）')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('update_skip_hash_hint', '默认强制校验更新包 SHA256 哈希以防篡改。若你的更新源（如网盘）不方便提供哈希值，且仅在完全信任该源时，可开启此选项跳过校验。开启后存在被篡改的更新包覆盖本站的风险。')); ?></p>
        </div>

        <div class="form-group">
            <label class="flex items-center gap-1" style="cursor: pointer;">
                <input type="checkbox" id="update_auto_enabled" name="update_auto_enabled" value="1" <?php echo $updateAutoEnabled ? 'checked' : ''; ?>>
                <span style="font-weight:600;"><?php echo e(t('update_auto_enabled', '启用自动更新（自动下载并安装）')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('update_auto_enabled_hint', '开启后，系统会按下方间隔自动检查并在发现新版本时自动下载、备份并覆盖升级。升级前会自动创建代码备份，可随时从「数据备份」恢复。')); ?></p>
            <label class="form-label" for="update_auto_interval" style="margin-top: 0.75rem;"><?php echo e(t('update_auto_interval', '自动更新间隔（小时）')); ?></label>
            <input type="number" class="form-control" id="update_auto_interval" name="update_auto_interval"
                   value="<?php echo e($updateAutoInterval); ?>" min="1" max="720" style="max-width: 200px;">
            <p class="form-hint"><?php echo e(t('update_auto_interval_hint', '距离上次检查/更新超过该小时数后，再次访问后台将触发自动检查与安装。建议 24（每天一次）。')); ?></p>
        </div>

        <button type="submit" class="btn btn-primary"><?php echo e(t('settings_save', '保存设置')); ?></button>
    </form>
</div>

<script>
(function () {
    var checkBtn = document.getElementById('checkBtn');
    var updateBtn = document.getElementById('updateBtn');
    var statusBox = document.getElementById('updateStatus');
    var currentEl = document.getElementById('currentVersion');
    var lastEl = document.getElementById('lastCheck');

    function showStatus(html, type) {
        statusBox.style.display = '';
        statusBox.className = 'update-status' + (type ? ' is-' + type : '');
        statusBox.innerHTML = html;
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }
    function fmtSize(n) {
        n = parseInt(n, 10) || 0;
        if (n >= 1048576) return (n / 1048576).toFixed(2) + ' MB';
        if (n >= 1024) return (n / 1024).toFixed(2) + ' KB';
        return n + ' B';
    }

    checkBtn.addEventListener('click', function () {
        checkBtn.disabled = true;
        checkBtn.innerHTML = '<?php echo e(t('update_checking', '检查中…')); ?>';
        updateBtn.disabled = true;
        fetch('/index.php?route=admin/api/update_ajax&action=check')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    var msg = res.error === 'update_source_not_configured'
                        ? '<?php echo e(t('update_no_source', '尚未配置更新源地址，请先在下方填写。')); ?>'
                        : ('<?php echo e(t('update_check_error', '检查失败：')); ?>' + escapeHtml(res.error || ''));
                    // 显示额外调试信息
                    if (res.debug_keys) msg += ' [keys: ' + escapeHtml(res.debug_keys.join(', ')) + ']';
                    if (res.preview) msg += '<br><small style="color:var(--text-muted);word-break:break-all">' + escapeHtml(res.preview) + '</small>';
                    showStatus(msg, 'error');
                    return;
                }
                currentEl.textContent = res.current;
                lastEl.textContent = new Date((res.checked_at || Date.now() / 1000) * 1000).toLocaleString();
                if (res.update_available) {
                    var html = '<div class="update-avail">'
                        + '<strong><?php echo e(t('update_new_available', '发现新版本')); ?> ' + escapeHtml(res.latest) + '</strong>'
                        + (res.release_date ? ' <span class="update-date">(' + escapeHtml(res.release_date) + ')</span>' : '')
                        + (res.size ? ' — ' + fmtSize(res.size) : '')
                        + '</div>';
                    if (res.changelog) {
                        html += '<div class="update-changelog"><pre>' + escapeHtml(res.changelog) + '</pre></div>';
                    }
                    if (res.requires_php) {
                        html += '<div class="update-req"><?php echo e(t('update_requires_php', '要求 PHP')); ?> ' + escapeHtml(res.requires_php) + '</div>';
                    }
                    showStatus(html, 'warn');
                    updateBtn.disabled = false;
                } else {
                    showStatus('<?php echo e(t('update_up_to_date', '已是最新版本（')); ?>' + escapeHtml(res.current) + '）', 'ok');
                }
            })
            .catch(function () {
                showStatus('<?php echo e(t('update_check_network_fail', '网络错误，检查失败。')); ?>', 'error');
            })
            .finally(function () {
                checkBtn.disabled = false;
                checkBtn.innerHTML = '<?php echo e(t('update_check_now', '检查更新')); ?>';
            });
    });

    // ===== 进度条相关 =====
    var progressWrap = document.getElementById('updateProgress');
    var progressBar  = document.getElementById('updateProgressBar');
    var progressStage = document.getElementById('updateProgressStage');
    var progressPct   = document.getElementById('updateProgressPct');
    var progressDetail = document.getElementById('updateProgressDetail');
    var progTimer     = null;

    function showProgress() {
        progressWrap.style.display = '';
        progressBar.style.width = '0%';
        progressBar.className = 'update-progress-bar-inner';
        progressDetail.innerHTML = '';
    }
    function hideProgress() {
        progressWrap.style.display = 'none';
        if (progTimer) { clearInterval(progTimer); progTimer = null; }
    }
    function updateProgressUI(p) {
        var pct = Math.min(100, Math.max(0, p.progress || 0));
        progressBar.style.width = pct + '%';
        progressPct.textContent = pct + '%';
        // 阶段文案映射
        var stageMap = {
            preparing:   '<?php echo e(t('update_progress_preparing', '准备中…')); ?>',
            downloading: '<?php echo e(t('update_progress_downloading', '下载更新包…')); ?>',
            verifying:   '<?php echo e(t('update_progress_verifying', '校验文件完整性…')); ?>',
            backing_up:  '<?php echo e(t('update_progress_backing_up', '备份当前代码…')); ?>',
            extracting:  '<?php echo e(t('update_progress_extracting', '解压并覆盖文件…')); ?>',
            done:        '<?php echo e(t('update_progress_done', '更新完成')); ?>',
            error:       '<?php echo e(t('update_progress_error', '出错')); ?>'
        };
        progressStage.textContent = stageMap[p.stage] || p.stage || '';
        // 下载详情
        if (p.stage === 'downloading' && p.total > 0) {
            progressDetail.textContent = fmtSize(p.downloaded || 0) + ' / ' + fmtSize(p.total);
        } else {
            progressDetail.textContent = '';
        }
        // 完成变绿 / 出错变红
        if (p.done && p.stage === 'done') {
            progressBar.classList.add('is-done');
            progressStage.classList.add('is-done');
        } else if (p.done && p.stage === 'error') {
            progressBar.classList.add('is-error');
            progressStage.classList.add('is-error');
        }
    }
    function startProgressPolling() {
        showProgress();
        // 立即拉一次
        fetch('/index.php?route=admin/api/update_ajax&action=progress')
            .then(function(r){return r.json();}).then(updateProgressUI).catch(function(){});
        // 每 800ms 轮询
        progTimer = setInterval(function () {
            fetch('/index.php?route=admin/api/update_ajax&action=progress')
                .then(function (r) { return r.json(); })
                .then(function (p) {
                    updateProgressUI(p);
                    if (p.done) { hideProgress(); }
                })
                .catch(function () {});
        }, 800);
    }

    updateBtn.addEventListener('click', function () {
        if (!confirm('<?php echo e(t('update_confirm', '确定要立即更新吗？系统将先备份当前代码再覆盖升级。')); ?>')) {
            return;
        }
        updateBtn.disabled = true;
        updateBtn.innerHTML = '<?php echo e(t('update_updating', '更新中…')); ?>';
        statusBox.style.display = 'none';
        // 启动进度轮询（后端 uc_perform_update 会写进度文件）
        startProgressPolling();
        var form = new FormData();
        form.append('action', 'update');
        form.append('csrf_token', '<?php echo csrf_token(); ?>');
        fetch('/index.php?route=admin/api/update_ajax', {
            method: 'POST',
            body: form
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                // 停止轮询，确保最终状态同步
                hideProgress();
                if (res.success) {
                    showStatus('<?php echo e(t('update_success', '更新成功：')); ?>' + escapeHtml(res.from) + ' → ' + escapeHtml(res.to)
                        + '<br><?php echo e(t('update_backup_at', '备份文件')); ?>：' + escapeHtml(res.backup ? res.backup.split(/[\\/]/).pop() : '') + ''
                        + '（' + (res.files || 0) + ' <?php echo e(t('update_files', '个文件')); ?>）', 'ok');
                    currentEl.textContent = res.to;
                    updateBtn.disabled = true;
                } else {
                    var msg = '<?php echo e(t('update_failed', '更新失败')); ?>';
                    if (res.error === 'no_update_available') msg = '<?php echo e(t('update_none', '当前已是最新，无需更新。')); ?>';
                    else if (res.error === 'no_package_url') msg = '<?php echo e(t('update_no_package_url', '更新源未提供更新包地址（package_url）。请将更新包命名为 update.zip 放到「{通道}/」目录下，或在 version.json 中加入 package_url 字段。')); ?>';
                    else if (res.error === 'no_package_hash') msg = '<?php echo e(t('update_no_package_hash', '更新包缺少哈希校验值（package_hash）。为安全起见默认禁止无校验更新。请在 version.json 中加入 package_hash（sha256 值）后重试，或在「更新设置」中开启「跳过哈希校验」。')); ?>';
                    else if (res.error === 'hash_mismatch') msg = '<?php echo e(t('update_hash_fail', '更新包校验失败（哈希不匹配），已自动取消以保障安全。')); ?>';
                    else if (res.error === 'backup_failed') msg = '<?php echo e(t('update_backup_err', '更新前备份失败，已取消更新以防数据丢失。')); ?>';
                    else if (res.error && res.error.indexOf('check_failed') === 0) msg = '<?php echo e(t('update_check_failed', '检查更新失败（网络错误或更新源不可用）：')); ?>' + escapeHtml((res.error || '').replace('check_failed: ', ''));
                    else msg += '：' + escapeHtml(res.error || '');
                    if (res.hint) msg += '<br><small style="color:var(--text-muted);word-break:break-word">' + escapeHtml(res.hint) + '</small>';
                    if (res.backup) msg += '<br><?php echo e(t('update_backup_kept', '已保留备份')); ?>：' + escapeHtml(res.backup.split(/[\\/]/).pop()) + '';
                    showStatus(msg, 'error');
                    updateBtn.disabled = false;
                }
            })
            .catch(function () {
                hideProgress();
                showStatus('<?php echo e(t('update_network_fail', '网络错误，更新失败。')); ?>', 'error');
                updateBtn.disabled = false;
            })
            .finally(function () {
                updateBtn.innerHTML = '<?php echo e(t('update_update_now', '立即更新')); ?>';
            });
    });
})();
</script>

<style>
.update-meta { display: flex; flex-wrap: wrap; gap: 1.5rem; margin-bottom: .5rem; }
.update-meta-item { display: flex; flex-direction: column; }
.update-meta-label { font-size: .8rem; color: var(--muted, #888); }
.update-meta-value { font-size: 1.1rem; font-weight: 600; }
.update-status { margin: .75rem 0; padding: .75rem 1rem; border-radius: 8px; border: 1px solid transparent; }
.update-status.is-ok { background: #ecfdf5; border-color: #6ee7b7; color: #065f46; }
.update-status.is-warn { background: #fffbeb; border-color: #fcd34d; color: #92400e; }
.update-status.is-error { background: #fef2f2; border-color: #fca5a5; color: #991b1b; }
.update-avail { font-size: 1.05rem; }
.update-date { color: var(--muted, #888); font-weight: 400; }
.update-changelog { margin-top: .5rem; }
.update-changelog pre { white-space: pre-wrap; word-break: break-word; background: rgba(0,0,0,.03); padding: .6rem; border-radius: 6px; max-height: 220px; overflow: auto; margin: 0; }
.update-req { margin-top: .4rem; font-size: .85rem; color: #92400e; }
.update-actions { display: flex; gap: .75rem; }

/* 更新进度条 */
.update-progress-wrap { margin: .75rem 0; padding: 1rem; border-radius: 8px; border: 1px solid var(--border, #ddd); background: var(--bg-card, #fff); }
.update-progress-header { display: flex; align-items: center; gap: .5rem; margin-bottom: .5rem; }
.update-progress-spinner { display: inline-block; animation: spin 1s linear infinite; font-size: 1.1rem; color: var(--primary, #3b82f6); }
.update-progress-stage { font-size: .9rem; font-weight: 600; color: var(--text, #333); flex: 1; }
.update-progress-stage.is-done { color: #16a34a; }
.update-progress-stage.is-error { color: #dc2626; }
.update-progress-pct { font-size: .85rem; color: var(--muted, #888); min-width: 36px; text-align: right; }
.update-progress-bar-outer { width: 100%; height: 10px; background: rgba(0,0,0,.08); border-radius: 5px; overflow: hidden; }
.update-progress-bar-inner { height: 100%; width: 0%; background: linear-gradient(90deg, #3b82f6, #60a5fa); border-radius: 5px; transition: width .3s ease; }
.update-progress-bar-inner.is-done { background: linear-gradient(90deg, #16a34a, #22c55e); }
.update-progress-bar-inner.is-error { background: linear-gradient(90deg, #dc2626, #ef4444); }
.update-progress-detail { margin-top: .25rem; font-size: .78rem; color: var(--muted, #888); text-align: right; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
