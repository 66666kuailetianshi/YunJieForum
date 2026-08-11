<?php
/**
 * 云界论坛 - 管理后台「系统更新中心」
 *
 * 提供：
 *   1. 当前版本展示与「检查更新」入口（手动）。
 *   2. 「立即更新」入口（手动应用：下载 → 校验 → 备份 → 覆盖）。
 *   3. 自动更新设置（更新源地址、更新通道、是否自动应用、检查间隔）。
 *   4. 历史更新备份列表（位于更新设置下方，可下载 / 分享 / 删除，带分页）。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：系统更新中心仅超级管理员可用
require_super_admin();

require_once APP_ROOT . 'app/includes/update_center.php';

// 处理历史更新备份下载（GET + 一次性派生令牌，直接流式输出）
if (($_GET['action'] ?? '') === 'download') {
    $filename = (string)($_GET['filename'] ?? '');
    $token    = (string)($_GET['token'] ?? '');
    if (!uc_is_update_backup_name($filename) || !admin_backup_download_token_valid($filename, $token)) {
        set_flash(t('admin_backup_flash_download_token_invalid', '下载令牌无效。'), 'error');
        redirect('/admin/update_center');
    }
    $filepath = uc_update_backup_dir() . $filename;
    if (!is_file($filepath)) {
        set_flash(t('update_history_file_missing', '备份文件不存在或已被删除。'), 'error');
        redirect('/admin/update_center');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($filepath);
    exit;
}

$errors   = [];
$autoResult = null;

$updateSourceUrl    = uc_get_setting('update_source_url', '');
$updateChannel      = uc_get_setting('update_channel', 'stable');
$updateAutoEnabled  = uc_get_setting('update_auto_enabled', '0') === '1';
$updateAutoInterval = (int)uc_get_setting('update_auto_interval', '24');
$updateSslVerify    = uc_get_setting('update_ssl_verify', '1') === '1';
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

// 历史更新备份列表（更新前自动创建的代码备份），分页每页 10 条（与「数据备份」页一致）
$updateBackups = uc_list_update_backups();
$historyPerPage = 10;
$historyTotal = count($updateBackups);
$historyTotalPages = max(1, (int)ceil($historyTotal / $historyPerPage));
$historyPage = max(1, min((int)($_GET['page'] ?? 1), $historyTotalPages));
$pagedUpdateBackups = array_slice($updateBackups, ($historyPage - 1) * $historyPerPage, $historyPerPage);

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

<!-- 更新确认对话框（替代原生 confirm） -->
<div class="modal-overlay" id="updateConfirmModal" style="display:none;">
    <div class="modal-box" style="max-width:460px;">
        <div class="modal-header">
            <h3 class="modal-title" id="updateConfirmTitle"><?php echo e(t('update_confirm_title', '确认立即更新')); ?></h3>
            <button type="button" class="modal-close" id="updateConfirmClose">&times;</button>
        </div>
        <div class="modal-body" style="padding:1.25rem 1.5rem;">
            <p id="updateConfirmText" style="margin:0;font-size:.95rem;line-height:1.7;color:var(--text);"><?php echo e(t('update_confirm', '确定要立即更新吗？系统将先备份当前代码再覆盖升级。')); ?></p>
            <div class="update-confirm-safe" id="updateConfirmSafe">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                <span><?php echo e(t('update_confirm_data_safe', '您的配置不会丢失：data/ 目录（数据库、站点设置、SMTP 邮件服务等）在升级中不会被覆盖。升级前还会自动备份全部代码，可随时恢复。')); ?></span>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="updateConfirmCancel"><?php echo e(t('update_confirm_cancel', '取消')); ?></button>
            <button type="button" class="btn btn-primary" id="updateConfirmOk"><?php echo e(t('update_confirm_ok', '确认更新')); ?></button>
        </div>
    </div>
</div>

<!-- 备份分享对话框 -->
<div class="modal-overlay" id="shareModal" style="display:none;">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-header">
            <h3 class="modal-title"><?php echo e(t('update_history_share_title', '分享更新备份')); ?></h3>
            <button type="button" class="modal-close" id="shareModalClose">&times;</button>
        </div>
        <div class="modal-body" style="padding:1.25rem 1.5rem;">
            <p style="margin:0 0 .75rem;font-size:.9rem;line-height:1.6;color:var(--text-muted);"><?php echo e(t('update_history_share_desc', '获得下方链接的人无需登录即可下载该备份，链接默认 7 天内有效，请勿分享给不信任的人。')); ?></p>
            <input type="text" class="form-control" id="shareUrlInput" readonly style="font-size:.85rem;">
            <p class="form-hint mt-1" id="shareExpires" style="margin-bottom:0;"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="shareModalCancel"><?php echo e(t('update_confirm_cancel', '取消')); ?></button>
            <button type="button" class="btn btn-primary" id="shareCopyBtn"><?php echo e(t('update_history_share_copy', '复制链接')); ?></button>
        </div>
    </div>
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
            <label class="flex items-center gap-1 cursor-pointer">
                <input type="checkbox" id="update_ssl_verify" name="update_ssl_verify" value="1" <?php echo $updateSslVerify ? 'checked' : ''; ?>>
                <span style="font-weight:600;"><?php echo e(t('update_ssl_verify', '严格校验 SSL 证书')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('update_ssl_verify_hint', '默认开启校验，防止更新包在传输中被中间人篡改。若更新源使用自签名证书（如大部分个人服务器），可关闭校验。')); ?></p>
        </div>

        <div class="form-group">
            <label class="flex items-center gap-1 cursor-pointer">
                <input type="checkbox" id="update_skip_hash" name="update_skip_hash" value="1" <?php echo $updateSkipHash ? 'checked' : ''; ?>>
                <span style="font-weight:600;"><?php echo e(t('update_skip_hash', '跳过哈希校验（不推荐）')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('update_skip_hash_hint', '默认强制校验更新包 SHA256 哈希以防篡改。若你的更新源（如网盘）不方便提供哈希值，且仅在完全信任该源时，可开启此选项跳过校验。开启后存在被篡改的更新包覆盖本站的风险。')); ?></p>
        </div>

        <div class="form-group">
            <label class="flex items-center gap-1 cursor-pointer">
                <input type="checkbox" id="update_auto_enabled" name="update_auto_enabled" value="1" <?php echo $updateAutoEnabled ? 'checked' : ''; ?>>
                <span style="font-weight:600;"><?php echo e(t('update_auto_enabled', '启用自动更新（自动下载并安装）')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('update_auto_enabled_hint', '开启后，系统会按下方间隔自动检查并在发现新版本时自动下载、备份并覆盖升级。升级前会自动创建代码备份，可随时从「数据备份」恢复。')); ?></p>
            <label class="form-label mt-3" for="update_auto_interval"><?php echo e(t('update_auto_interval', '自动更新间隔（小时）')); ?></label>
            <input type="number" class="form-control" id="update_auto_interval" name="update_auto_interval"
                   value="<?php echo e($updateAutoInterval); ?>" min="1" max="720" style="max-width: 200px;">
            <p class="form-hint"><?php echo e(t('update_auto_interval_hint', '距离上次检查/更新超过该小时数后，再次访问后台将触发自动检查与安装。建议 24（每天一次）。')); ?></p>
        </div>

        <button type="submit" class="btn btn-primary"><?php echo e(t('settings_save', '保存设置')); ?></button>
    </form>
</div>

<!-- 历史更新备份列表（位于更新设置下方；可下载 / 分享 / 删除） -->
<div class="card mt-2" id="updateHistoryCard">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('update_history_title', '历史更新备份')); ?></h2>
        <span class="backup-refresh-hint">
            <?php echo t('update_history_count_prefix', '共 '); ?><strong id="updateHistoryCount"><?php echo count($updateBackups); ?></strong><?php echo t('update_history_count_suffix', ' 个备份'); ?>
        </span>
    </div>
    <p class="form-hint mt-1"><?php echo e(t('update_history_desc', '每次更新前系统会自动创建代码备份（update_pre_*.zip），包含 app/、public/ 及入口文件，不包含 data/ 数据。可下载留存、分享给他人或删除以释放空间。')); ?></p>
    <div class="backup-list">
        <div class="backup-empty" id="updateHistoryEmpty" <?php echo $updateBackups ? 'style="display:none;"' : ''; ?>>
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;margin-bottom:0.5rem;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            <p><?php echo e(t('update_history_empty', '暂无历史更新备份。执行一次系统更新后，这里会列出更新前自动创建的代码备份。')); ?></p>
        </div>
        <div class="backup-table-wrap" id="updateHistoryTableWrap" <?php echo $updateBackups ? '' : 'style="display:none;"'; ?>>
            <table class="backup-table">
                <thead>
                    <tr>
                        <th><?php echo e(t('admin_backup_th_filename', '文件名')); ?></th>
                        <th><?php echo e(t('admin_backup_th_created', '创建时间')); ?></th>
                        <th><?php echo e(t('admin_backup_th_size', '大小')); ?></th>
                        <th><?php echo e(t('admin_backup_th_actions', '操作')); ?></th>
                    </tr>
                </thead>
                <tbody id="updateHistoryBody">
                    <?php foreach ($pagedUpdateBackups as $ub): ?>
                        <tr data-filename="<?php echo e($ub['filename']); ?>">
                            <td class="backup-cell-filename" title="<?php echo e($ub['filename']); ?>"><?php echo e($ub['filename']); ?></td>
                            <td><?php echo e(date('Y-m-d H:i:s', $ub['time'])); ?></td>
                            <td><?php echo e(uc_format_bytes($ub['size'])); ?></td>
                            <td class="backup-cell-actions">
                                <a href="<?php echo site_url('admin/update_center', ['action' => 'download', 'filename' => $ub['filename'], 'token' => admin_backup_download_token($ub['filename'])]); ?>" class="btn btn-secondary btn-sm backup-action-btn" title="<?php echo e(t('admin_backup_btn_download', '下载')); ?>">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    <?php echo e(t('admin_backup_btn_download', '下载')); ?>
                                </a>
                                <button type="button" class="btn btn-secondary btn-sm backup-action-btn update-history-share-btn" data-filename="<?php echo e($ub['filename']); ?>" title="<?php echo e(t('update_history_share', '分享')); ?>">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                                    <?php echo e(t('update_history_share', '分享')); ?>
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm backup-action-btn update-history-delete-btn is-danger" data-filename="<?php echo e($ub['filename']); ?>" title="<?php echo e(t('admin_backup_btn_delete', '删除')); ?>">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    <?php echo e(t('admin_backup_btn_delete', '删除')); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($historyTotalPages > 1): ?>
        <div style="padding:0 1.25rem 1.25rem;">
            <?php echo pagination($historyPage, $historyTotal, $historyPerPage, site_url('admin/update_center')); ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var checkBtn = document.getElementById('checkBtn');
    var updateBtn = document.getElementById('updateBtn');
    var statusBox = document.getElementById('updateStatus');
    var currentEl = document.getElementById('currentVersion');
    var lastEl = document.getElementById('lastCheck');
    // 强制更新模式：当前已是最新（版本号相同）时仍允许重新下载安装
    var forceMode = false;

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
                    forceMode = false;
                    updateBtn.innerHTML = '<?php echo e(t('update_update_now', '立即更新')); ?>';
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
                    // 已是最新：允许「强制更新」重新应用更新包（同版本覆盖安装）
                    forceMode = true;
                    updateBtn.innerHTML = '<?php echo e(t('update_force_install', '强制更新')); ?>';
                    updateBtn.disabled = false;
                    showStatus('<?php echo e(t('update_up_to_date', '已是最新版本（')); ?>' + escapeHtml(res.current) + '）' + '<?php echo e(t('update_up_to_date_force_hint', ' 如需重新应用更新包，可点击「强制更新」')); ?>', 'ok');
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
            verifying_pkg:'<?php echo e(t('update_progress_verifying_pkg', '校验包内版本…')); ?>',
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

    function doUpdate() {
        updateBtn.disabled = true;
        updateBtn.innerHTML = '<?php echo e(t('update_updating', '更新中…')); ?>';
        statusBox.style.display = 'none';
        // 启动进度轮询（后端 uc_perform_update 会写进度文件）
        startProgressPolling();
        var form = new FormData();
        form.append('action', 'update');
        form.append('force', forceMode ? '1' : '0');
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
                    forceMode = false;
                    updateBtn.disabled = true;
                    // 更新成功后刷新历史更新备份列表（新增了一份更新前备份）
                    refreshBackupHistory();
                } else {
                    var msg = '<?php echo e(t('update_failed', '更新失败')); ?>';
                    if (res.error === 'no_update_available') msg = '<?php echo e(t('update_none', '当前已是最新，无需更新。')); ?>';
                    else if (res.error === 'no_package_url') msg = '<?php echo e(t('update_no_package_url', '更新源未提供更新包地址（package_url）。请将更新包命名为 update.zip 放到「{通道}/」目录下，或在 version.json 中加入 package_url 字段。')); ?>';
                    else if (res.error === 'no_package_hash') msg = '<?php echo e(t('update_no_package_hash', '更新包缺少哈希校验值（package_hash）。为安全起见默认禁止无校验更新。请在 version.json 中加入 package_hash（sha256 值）后重试，或在「更新设置」中开启「跳过哈希校验」。')); ?>';
                    else if (res.error === 'hash_mismatch') msg = '<?php echo e(t('update_hash_fail', '更新包校验失败（哈希不匹配），已自动取消以保障安全。')); ?>';
                    else if (res.error === 'package_version_mismatch') msg = '<?php echo e(t('update_pkg_version_mismatch', '更新包版本名不副实：包内版本 {pkg} ≠ 声明版本 {declared}，已取消更新。请重新制作更新包（config.php 的 APP_VERSION 与 version.json 保持一致）。')); ?>'.replace('{pkg}', escapeHtml(res.package_version || '')).replace('{declared}', escapeHtml(res.declared || ''));
                    else if (res.error && res.error.indexOf('extract_failed') === 0) {
                        msg = '<?php echo e(t('update_extract_failed', '更新文件解压失败')); ?>' + (res.failed && res.failed.length ? '（' + res.failed.length + ' 个）' : '') + '，更新不完整，请检查文件权限或从备份恢复。';
                        if (res.failed && res.failed.length) msg += '<br><small style="color:var(--text-muted);word-break:break-word">' + escapeHtml(res.failed.slice(0, 8).join('<br>')) + '</small>';
                    }
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
                updateBtn.innerHTML = (forceMode ? '<?php echo e(t('update_force_install', '强制更新')); ?>' : '<?php echo e(t('update_update_now', '立即更新')); ?>');
            });
    }

    // 自定义确认对话框（替代原生 confirm，更新确认与删除备份确认共用）
    var confirmModal = document.getElementById('updateConfirmModal');
    var confirmTitleEl = document.getElementById('updateConfirmTitle');
    var confirmTextEl = document.getElementById('updateConfirmText');
    var confirmSafeEl = document.getElementById('updateConfirmSafe');
    var confirmOkBtn = document.getElementById('updateConfirmOk');
    var pendingConfirmOk = null;
    function openConfirm(title, text, showSafeHint, onOk, okText) {
        confirmTitleEl.textContent = title;
        confirmTextEl.textContent = text;
        confirmTextEl.style.whiteSpace = showSafeHint ? '' : 'pre-line';
        confirmSafeEl.style.display = showSafeHint ? '' : 'none';
        confirmOkBtn.textContent = okText || '<?php echo e(t('update_confirm_ok', '确认更新')); ?>';
        // 破坏性操作（如删除）用红色主按钮，与「确认更新」区分
        confirmOkBtn.classList.toggle('btn-primary', showSafeHint);
        confirmOkBtn.classList.toggle('btn-danger', !showSafeHint);
        pendingConfirmOk = onOk;
        confirmModal.style.display = 'flex';
    }
    function closeUpdateConfirm() { confirmModal.style.display = 'none'; pendingConfirmOk = null; }

    updateBtn.addEventListener('click', function () {
        // 强制模式下切换确认框文案
        openConfirm(
            (forceMode ? '<?php echo e(t('update_confirm_force_title', '确认强制更新')); ?>' : '<?php echo e(t('update_confirm_title', '确认立即更新')); ?>'),
            (forceMode ? '<?php echo e(t('update_confirm_force', '当前已是最新版本，确定要强制重新安装吗？将重新下载更新包并覆盖代码（data/ 配置与数据不会被覆盖，升级前自动备份）。')); ?>' : '<?php echo e(t('update_confirm', '确定要立即更新吗？系统将先备份当前代码再覆盖升级。')); ?>'),
            true,
            doUpdate,
            '<?php echo e(t('update_confirm_ok', '确认更新')); ?>'
        );
    });
    document.getElementById('updateConfirmOk').addEventListener('click', function () {
        var cb = pendingConfirmOk;
        closeUpdateConfirm();
        if (typeof cb === 'function') { cb(); }
    });
    document.getElementById('updateConfirmCancel').addEventListener('click', closeUpdateConfirm);
    document.getElementById('updateConfirmClose').addEventListener('click', closeUpdateConfirm);
    confirmModal.addEventListener('click', function (e) {
        if (e.target === confirmModal) { closeUpdateConfirm(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && confirmModal.style.display !== 'none') { closeUpdateConfirm(); }
    });

    // ===== 历史更新备份：删除 / 列表刷新 =====
    var historyCsrf = '<?php echo csrf_token(); ?>';

    function bindHistoryDeleteBtn(btn) {
        btn.addEventListener('click', function () {
            var filename = btn.dataset.filename;
            openConfirm(
                <?php echo json_encode(t('admin_backup_js_confirm_delete_title', '删除备份')); ?>,
                <?php echo json_encode(t('admin_backup_js_confirm_delete_msg', '确定要删除备份文件 "{name}" 吗？')); ?>.replace('{name}', filename) + '\n' + <?php echo json_encode(t('admin_backup_js_confirm_delete_warn', '此操作不可恢复，删除后无法找回该备份。')); ?>,
                false,
                function () {
                    var form = new FormData();
                    form.append('action', 'backup_delete');
                    form.append('csrf_token', historyCsrf);
                    form.append('filename', filename);
                    fetch('/index.php?route=admin/api/update_ajax', { method: 'POST', body: form })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) {
                                refreshBackupHistory();
                            } else {
                                showStatus('<?php echo e(t('admin_backup_js_delete_failed', '删除失败：')); ?>' + escapeHtml(res.error || <?php echo json_encode(t('admin_backup_js_unknown_error', '未知错误')); ?>), 'error');
                            }
                        })
                        .catch(function () { showStatus(<?php echo json_encode(t('admin_backup_js_network_delete_fail', '网络错误，删除失败。')); ?>, 'error'); });
                },
                <?php echo json_encode(t('update_history_delete_ok', '确认删除')); ?>
            );
        });
    }

    // ===== 历史更新备份：分享 =====
    var shareModal = document.getElementById('shareModal');
    var shareUrlInput = document.getElementById('shareUrlInput');
    var shareExpiresEl = document.getElementById('shareExpires');
    var shareCopyBtn = document.getElementById('shareCopyBtn');
    function closeShareModal() { shareModal.style.display = 'none'; }
    shareCopyBtn.addEventListener('click', function () {
        var url = shareUrlInput.value;
        var done = function () {
            shareCopyBtn.textContent = '<?php echo e(t('update_history_share_copied', '已复制')); ?>';
            setTimeout(function () {
                shareCopyBtn.textContent = '<?php echo e(t('update_history_share_copy', '复制链接')); ?>';
            }, 2000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done).catch(function () {
                shareUrlInput.select();
                document.execCommand('copy');
                done();
            });
        } else {
            shareUrlInput.select();
            document.execCommand('copy');
            done();
        }
    });
    document.getElementById('shareModalCancel').addEventListener('click', closeShareModal);
    document.getElementById('shareModalClose').addEventListener('click', closeShareModal);
    shareModal.addEventListener('click', function (e) {
        if (e.target === shareModal) { closeShareModal(); }
    });

    // 将服务端生成的绝对链接的域名替换为浏览器地址栏的「当前访问域名」：
    // 服务端拿到的 HTTP_HOST 是请求头域名，CDN/反代改写 Host 时可能与实际访问域名不一致；
    // 以 window.location 为准，路径与参数保持不变。
    function toCurrentAccessDomain(absUrl) {
        try {
            var u = new URL(absUrl);
            if (u.origin !== window.location.origin) {
                u.protocol = window.location.protocol;
                u.host = window.location.host;
            }
            return u.href;
        } catch (e) {
            return absUrl;
        }
    }

    function bindHistoryShareBtn(btn) {
        btn.addEventListener('click', function () {
            var filename = btn.dataset.filename;
            btn.disabled = true;
            var form = new FormData();
            form.append('action', 'backup_share');
            form.append('csrf_token', historyCsrf);
            form.append('filename', filename);
            fetch('/index.php?route=admin/api/update_ajax', { method: 'POST', body: form })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        shareUrlInput.value = toCurrentAccessDomain(res.url);
                        shareExpiresEl.textContent = '<?php echo e(t('update_history_share_expires_prefix', '链接有效期至：')); ?>' + new Date(res.expires * 1000).toLocaleString();
                        shareModal.style.display = 'flex';
                    } else {
                        showStatus('<?php echo e(t('update_history_share_failed', '生成分享链接失败：')); ?>' + escapeHtml(res.error || <?php echo json_encode(t('admin_backup_js_unknown_error', '未知错误')); ?>), 'error');
                    }
                })
                .catch(function () { showStatus(<?php echo json_encode(t('update_history_share_network_fail', '网络错误，生成分享链接失败。')); ?>, 'error'); })
                .finally(function () { btn.disabled = false; });
        });
    }

    function refreshBackupHistory() {
        // 重新拉取当前页 HTML，仅替换历史更新备份卡片（含分页），
        // 与服务端渲染保持完全一致；页码超界时后端会自动夹取到最后一页
        fetch(window.location.href, { cache: 'no-store' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newCard = doc.getElementById('updateHistoryCard');
                var curCard = document.getElementById('updateHistoryCard');
                if (newCard && curCard) {
                    curCard.innerHTML = newCard.innerHTML;
                    curCard.querySelectorAll('.update-history-delete-btn').forEach(bindHistoryDeleteBtn);
                    curCard.querySelectorAll('.update-history-share-btn').forEach(bindHistoryShareBtn);
                }
            })
            .catch(function () {});
    }

    document.querySelectorAll('.update-history-delete-btn').forEach(bindHistoryDeleteBtn);
    document.querySelectorAll('.update-history-share-btn').forEach(bindHistoryShareBtn);
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
