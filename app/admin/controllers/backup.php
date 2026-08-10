<?php
/**
 * 云界论坛 - 管理后台数据备份
 *
 * 功能：创建、下载、恢复、删除数据库备份。
 * 备份文件存储在 data/backups/，使用 gzip 压缩。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';
require_once APP_ROOT . 'app/includes/backup_manager.php';

$dbDriver = get_db_driver();
$manager = new BackupManager();
$GLOBALS['_backup_manager'] = $manager;

// 处理下载请求（GET 方式，需要 CSRF token 校验）
if (($_GET['action'] ?? '') === 'download') {
    $manager = $GLOBALS['_backup_manager'];
    $filename = $_GET['filename'] ?? '';
    $token = $_GET['token'] ?? '';
    if (!hash_equals(session_id(), $token)) {
        set_flash(t('admin_backup_flash_download_token_invalid', '下载令牌无效。'), 'error');
        redirect('/admin/backup');
    }
    $result = $manager->getBackupPath($filename);
    if (!$result['success']) {
        set_flash($result['error'], 'error');
        redirect('/admin/backup');
    }
    $filepath = $result['filepath'];
    $basename = basename($filepath);
    // 下载文件名：将 backup_20260801_120000.db.gz 改为 云界论坛_备份_20260801_120000.db.gz
    $downloadName = preg_replace('/^backup_/', SITE_NAME . t('admin_backup_download_prefix', '_备份_'), $basename);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($filepath);
    exit;
}

// 初始加载统计数据
$manager = $GLOBALS['_backup_manager'];
$stats = $manager->getStats();
$backups = $manager->listBackups();
$autoConfig = $manager->getAutoBackupConfig();

// 检查是否有自动备份结果需要提示
$autoResult = $_SESSION['_auto_backup_result'] ?? null;
unset($_SESSION['_auto_backup_result']);

$pageTitle = t('admin_backup_title', '数据备份');
$activeMenu = 'backup';

require_once dirname(__DIR__) . '/layout/header.php';

/**
 * 格式化字节
 */
function format_bytes_local(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo e(t('admin_backup_title', '数据备份')); ?></h1>
        <p class="page-subtitle"><?php echo $dbDriver->isFileBased() ? e(t('admin_backup_subtitle_sqlite', '管理 SQLite 数据库备份，支持创建、下载、恢复与删除')) : e(t('admin_backup_subtitle_db', '管理 {db} 数据库备份，支持创建、下载、恢复与删除', ['db' => strtoupper(DB_TYPE)])); ?></p>
    </div>
    <div class="page-tools">
        <button type="button" class="btn btn-primary btn-sm" id="quick-backup-btn">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <?php echo e(t('admin_backup_btn_quick', '立即备份')); ?>
        </button>
    </div>
</div>

<?php
$flash = get_flash();
if ($flash): ?>
    <?php echo show_message($flash['message'], $flash['type']); ?>
<?php endif; ?>

<?php if ($autoResult !== null): ?>
    <?php if ($autoResult['success']): ?>
        <?php echo show_message(t('admin_backup_auto_done', '定时自动备份已完成：') . e($autoResult['filename']), 'success'); ?>
    <?php else: ?>
        <?php echo show_message(t('admin_backup_auto_failed', '定时自动备份失败：') . e($autoResult['error'] ?? t('admin_backup_unknown_error', '未知错误')), 'error'); ?>
    <?php endif; ?>
<?php endif; ?>

<!-- 状态横幅 -->
<div class="backup-status-banner">
    <div class="backup-status-banner-icon">
        <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
    </div>
    <div class="backup-status-banner-body">
        <div class="backup-status-banner-title"><?php echo $dbDriver->isFileBased() ? e(t('admin_backup_banner_sqlite', 'SQLite 数据库备份')) : e(t('admin_backup_banner_db', '数据库备份')); ?></div>
        <div class="backup-status-banner-meta">
            <span class="backup-meta-item"><span class="backup-meta-label"><?php echo e(t('admin_backup_meta_db_file', '数据库文件')); ?></span><?php echo e($dbDriver->isFileBased() ? basename($dbDriver->getDbFile() ?: 'n/a') : (defined('DB_NAME') ? DB_NAME : 'n/a')); ?></span>
            <span class="backup-meta-divider"></span>
            <span class="backup-meta-item"><span class="backup-meta-label"><?php echo e(t('admin_backup_meta_db_size', '数据库大小')); ?></span><?php echo format_bytes_local($stats['db_size']); ?></span>
            <?php if ($stats['wal_size'] > 0): ?>
            <span class="backup-meta-divider"></span>
            <span class="backup-meta-item"><span class="backup-meta-label"><?php echo e(t('admin_backup_meta_wal', 'WAL 日志')); ?></span><?php echo format_bytes_local($stats['wal_size']); ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 统计卡片 -->
<div class="backup-stats-grid">
    <div class="backup-stat-card">
        <div class="backup-stat-icon backup-stat-icon-count">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="backup-stat-body">
            <div class="backup-stat-value" id="stat-backup-count"><?php echo $stats['count']; ?></div>
            <div class="backup-stat-label"><?php echo e(t('admin_backup_stat_count', '备份总数')); ?></div>
        </div>
    </div>
    <div class="backup-stat-card">
        <div class="backup-stat-icon backup-stat-icon-size">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </div>
        <div class="backup-stat-body">
            <div class="backup-stat-value" id="stat-backup-size"><?php echo format_bytes_local($stats['total_size']); ?></div>
            <div class="backup-stat-label"><?php echo e(t('admin_backup_stat_size', '备份占用空间')); ?></div>
        </div>
    </div>
    <div class="backup-stat-card">
        <div class="backup-stat-icon backup-stat-icon-db">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
        </div>
        <div class="backup-stat-body">
            <div class="backup-stat-value"><?php echo format_bytes_local($stats['db_size']); ?></div>
            <div class="backup-stat-label"><?php echo e(t('admin_backup_stat_current_size', '当前数据库大小')); ?></div>
        </div>
    </div>
    <div class="backup-stat-card">
        <div class="backup-stat-icon backup-stat-icon-last">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="backup-stat-body">
            <div class="backup-stat-value" id="stat-last-backup" style="font-size:1.1rem;">
                <?php echo $stats['last_backup_ts'] ? date('Y-m-d H:i', $stats['last_backup_ts']) : e(t('admin_backup_stat_never', '从未备份')); ?>
            </div>
            <div class="backup-stat-label"><?php echo e(t('admin_backup_stat_last_time', '上次备份时间')); ?></div>
        </div>
    </div>
</div>

<!-- 定时自动备份设置 -->
<div class="card" id="auto-backup-card">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('admin_backup_auto_title', '定时自动备份')); ?></h2>
        <label class="toggle-switch" style="margin:0;">
            <input type="checkbox" id="auto-backup-enabled" <?php echo $autoConfig['enabled'] ? 'checked' : ''; ?>>
            <span class="toggle-slider"></span>
        </label>
    </div>
    <div class="auto-backup-body" id="auto-backup-body" <?php echo !$autoConfig['enabled'] ? 'style="display:none;"' : ''; ?>>
        <div class="auto-backup-info">
            <div class="auto-backup-info-item">
                <span class="auto-backup-info-label"><?php echo e(t('admin_backup_auto_last_run', '上次自动备份')); ?></span>
                <span class="auto-backup-info-value" id="auto-last-run">
                    <?php echo $autoConfig['last_run'] ? date('Y-m-d H:i:s', $autoConfig['last_run']) : e(t('admin_backup_auto_not_run', '尚未执行')); ?>
                </span>
            </div>
            <div class="auto-backup-info-item">
                <span class="auto-backup-info-label"><?php echo e(t('admin_backup_auto_next_run', '下次预计执行')); ?></span>
                <span class="auto-backup-info-value" id="auto-next-run">
                    <?php if ($autoConfig['enabled'] && $autoConfig['last_run']): ?>
                        <?php echo date('Y-m-d H:i:s', $autoConfig['last_run'] + $autoConfig['interval'] * 3600); ?>
                    <?php else: ?>
                        <?php echo e(t('admin_backup_auto_after_enable', '启用后在设定间隔后执行')); ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <div class="auto-backup-form">
            <div class="form-group">
                <label class="form-label" for="auto-backup-interval"><?php echo e(t('admin_backup_auto_interval', '备份间隔')); ?></label>
                <select class="form-control" id="auto-backup-interval" style="width:auto;min-width:180px;">
                    <option value="6" <?php echo $autoConfig['interval'] == 6 ? 'selected' : ''; ?>><?php echo e(t('admin_backup_interval_6h', '每 6 小时')); ?></option>
                    <option value="12" <?php echo $autoConfig['interval'] == 12 ? 'selected' : ''; ?>><?php echo e(t('admin_backup_interval_12h', '每 12 小时')); ?></option>
                    <option value="24" <?php echo $autoConfig['interval'] == 24 ? 'selected' : ''; ?>><?php echo e(t('admin_backup_interval_24h', '每 24 小时（推荐）')); ?></option>
                    <option value="48" <?php echo $autoConfig['interval'] == 48 ? 'selected' : ''; ?>><?php echo e(t('admin_backup_interval_48h', '每 48 小时')); ?></option>
                    <option value="168" <?php echo $autoConfig['interval'] == 168 ? 'selected' : ''; ?>><?php echo e(t('admin_backup_interval_weekly', '每周')); ?></option>
                </select>
            </div>
            <div class="form-group" style="margin-left: 1.5rem;">
                <label class="form-label" for="auto-backup-keep"><?php echo e(t('admin_backup_auto_keep', '保留数量')); ?></label>
                <select class="form-control" id="auto-backup-keep" style="width:auto;min-width:140px;">
                    <option value="5" <?php echo $autoConfig['keep'] == 5 ? 'selected' : ''; ?>><?php echo e(t('admin_backup_keep_n', '保留 {n} 个', ['n' => 5])); ?></option>
                    <option value="10" <?php echo $autoConfig['keep'] == 10 ? 'selected' : ''; ?>><?php echo e(t('admin_backup_keep_n', '保留 {n} 个', ['n' => 10])); ?></option>
                    <option value="20" <?php echo $autoConfig['keep'] == 20 ? 'selected' : ''; ?>><?php echo e(t('admin_backup_keep_n', '保留 {n} 个', ['n' => 20])); ?></option>
                    <option value="30" <?php echo $autoConfig['keep'] == 30 ? 'selected' : ''; ?>><?php echo e(t('admin_backup_keep_n', '保留 {n} 个', ['n' => 30])); ?></option>
                </select>
            </div>
        </div>
        <p class="form-hint" style="margin-top:0.5rem;">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:2px;"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            <?php echo t('admin_backup_auto_hint', '启用后，系统将在<b>管理员访问后台</b>时自动检查并执行备份。建议选择 24 小时间隔，避免频繁备份占用资源。'); ?>
        </p>
    </div>
</div>

<style>
/* 自动备份样式 */
.auto-backup-body { padding: 1rem 1.25rem 1.25rem; }
.auto-backup-info {
    display: flex;
    gap: 2rem;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-soft);
}
.auto-backup-info-item { display: flex; flex-direction: column; gap: 0.125rem; }
.auto-backup-info-label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.auto-backup-info-value { font-size: 0.875rem; font-weight: 600; color: var(--text); }
.auto-backup-form { display: flex; gap: 1.5rem; }
@media (max-width: 640px) {
    .auto-backup-info { flex-direction: column; gap: 0.5rem; }
    .auto-backup-form { flex-direction: column; }
    .auto-backup-form .form-group { margin-left: 0 !important; }
}

/* 切换开关 */
.toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; cursor: pointer; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; top: 0; left: 0; right: 0; bottom: 0;
    background: var(--border); border-radius: 12px; transition: var(--transition);
}
.toggle-slider::before {
    content: ''; position: absolute; left: 3px; bottom: 3px; width: 18px; height: 18px;
    background: white; border-radius: 50%; transition: var(--transition);
}
.toggle-switch input:checked + .toggle-slider { background: var(--primary); }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }
</style>

<!-- 创建备份区 -->
<div class="card backup-create-card">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('admin_backup_create_title', '创建新备份')); ?></h2>
    </div>
    <div class="backup-create-body">
        <div class="form-group" style="margin:0;flex:1;">
            <label class="form-label" for="backup-description"><?php echo e(t('admin_backup_create_desc_label', '备份描述（可选）')); ?></label>
            <input type="text" class="form-control" id="backup-description" placeholder="<?php echo e(t('admin_backup_create_desc_placeholder', '例如：升级前备份、每日例行备份...')); ?>" maxlength="200">
        </div>
        <button type="button" class="btn btn-primary" id="create-backup-btn">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?php echo e(t('admin_backup_create_btn', '创建备份')); ?>
        </button>
    </div>
    <p class="form-hint" style="margin-top:0.75rem;"><?php echo e(t('admin_backup_create_hint', '备份将使用 gzip 压缩存储，自动保留最近 30 个备份，更早的备份将被自动清理。')); ?></p>
</div>

<!-- 备份列表 -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('admin_backup_list_title', '备份列表')); ?></h2>
        <span class="backup-refresh-hint" id="backup-refresh-hint">
            <span class="backup-refresh-dot"></span>
            <?php echo t('admin_backup_list_count_prefix', '共 '); ?><strong id="backup-list-count"><?php echo count($backups); ?></strong><?php echo t('admin_backup_list_count_suffix', ' 个备份'); ?>
        </span>
    </div>

    <div class="backup-list" id="backup-list">
        <?php if (empty($backups)): ?>
            <div class="backup-empty">
                <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;margin-bottom:0.5rem;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                <p><?php echo e(t('admin_backup_empty_hint', '暂无备份记录，点击上方"创建备份"开始第一次备份。')); ?></p>
            </div>
        <?php else: ?>
            <div class="backup-table-wrap">
                <table class="backup-table">
                    <thead>
                        <tr>
                            <th><?php echo e(t('admin_backup_th_filename', '文件名')); ?></th>
                            <th><?php echo e(t('admin_backup_th_created', '创建时间')); ?></th>
                            <th><?php echo e(t('admin_backup_th_size', '大小')); ?></th>
                            <th><?php echo e(t('admin_backup_th_desc', '描述')); ?></th>
                            <th><?php echo e(t('admin_backup_th_creator', '创建者')); ?></th>
                            <th><?php echo e(t('admin_backup_th_actions', '操作')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="backup-table-body">
                        <?php foreach ($backups as $b): ?>
                            <tr data-filename="<?php echo e($b['filename']); ?>">
                                <td class="backup-cell-filename" title="<?php echo e($b['filename']); ?>">
                                    <?php echo e($b['filename']); ?>
                                    <?php if (!empty($b['app_version'])): ?>
                                    <span class="backup-version-tag">v<?php echo e($b['app_version']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e(date('Y-m-d H:i:s', $b['created_at_ts'])); ?></td>
                                <td>
                                    <div class="backup-size-info">
                                        <span class="backup-size-current"><?php echo format_bytes_local($b['size']); ?></span>
                                        <?php if ($b['original_size'] > 0): ?>
                                        <span class="backup-size-orig"><?php echo e(t('admin_backup_original_size', '原始 {size}', ['size' => format_bytes_local($b['original_size'])])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="backup-cell-desc"><?php echo $b['description'] ? e($b['description']) : '<span class="text-muted">—</span>'; ?></td>
                                <td><?php echo e($b['created_by_name']); ?></td>
                                <td class="backup-cell-actions">
                                    <a href="<?php echo site_url('admin/backup', ['action' => 'download', 'filename' => $b['filename'], 'token' => session_id()]); ?>" class="btn btn-secondary btn-sm backup-action-btn" title="<?php echo e(t('admin_backup_btn_download', '下载')); ?>">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        <?php echo e(t('admin_backup_btn_download', '下载')); ?>
                                    </a>
                                    <button type="button" class="btn btn-secondary btn-sm backup-action-btn backup-restore-btn" data-filename="<?php echo e($b['filename']); ?>" title="<?php echo e(t('admin_backup_btn_restore_title', '恢复此备份')); ?>">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                                        <?php echo e(t('admin_backup_btn_restore', '恢复')); ?>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm backup-action-btn backup-delete-btn is-danger" data-filename="<?php echo e($b['filename']); ?>" title="<?php echo e(t('admin_backup_btn_delete', '删除')); ?>">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        <?php echo e(t('admin_backup_btn_delete', '删除')); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 确认对话框 -->
<div class="modal-overlay" id="confirm-modal" style="display:none;">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-header">
            <h3 class="modal-title" id="confirm-title"><?php echo e(t('admin_backup_modal_confirm', '确认操作')); ?></h3>
            <button type="button" class="modal-close" id="confirm-close">&times;</button>
        </div>
        <div class="modal-body">
            <p id="confirm-message" style="margin:0;"></p>
            <div id="confirm-warning" class="backup-confirm-warning" style="display:none;margin-top:0.75rem;">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span id="confirm-warning-text"></span>
            </div>
        </div>
        <div class="modal-footer" style="padding:0.75rem 1.25rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:0.5rem;">
            <button type="button" class="btn btn-secondary" id="confirm-cancel"><?php echo e(t('admin_backup_modal_cancel', '取消')); ?></button>
            <button type="button" class="btn btn-primary" id="confirm-ok"><?php echo e(t('admin_backup_modal_ok', '确定')); ?></button>
        </div>
    </div>
</div>

<!-- 操作结果反馈 -->
<div class="backup-toast" id="backup-toast" style="display:none;">
    <span id="backup-toast-text"></span>
</div>

<!-- 恢复进度模态框 -->
<div class="modal-overlay" id="restore-progress-modal" style="display:none;z-index:11000;">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <h3 class="modal-title"><?php echo e(t('admin_backup_js_restore_progress_title', '正在恢复备份')); ?></h3>
        </div>
        <div class="modal-body" style="padding:1.5rem 1.25rem;">
            <p style="margin:0 0 1rem;color:var(--text-muted);"><?php echo e(t('admin_backup_js_restore_progress_desc', '请勿关闭或刷新页面，恢复完成后将自动刷新。')); ?></p>
            <div class="backup-progress-track">
                <div class="backup-progress-bar" id="restore-progress-bar"></div>
            </div>
            <div class="backup-progress-status" id="restore-progress-status"><?php echo e(t('admin_backup_js_restore_progress_step', '正在执行恢复操作...')); ?></div>
        </div>
    </div>
</div>

<style>
/* === 备份页面专用样式 === */
.backup-status-banner {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
    border: 1px solid #c7d2fe;
    border-radius: var(--radius-lg);
    margin-bottom: 1.25rem;
}
.backup-status-banner-icon {
    width: 56px;
    height: 56px;
    border-radius: var(--radius);
    background: var(--surface);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary);
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.15);
}
.backup-status-banner-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 0.25rem;
}
.backup-status-banner-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: var(--text-secondary);
}
.backup-meta-item { display: inline-flex; gap: 0.375rem; }
.backup-meta-label { color: var(--text-muted); }
.backup-meta-divider { width: 1px; height: 14px; background: var(--border); }

.backup-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.25rem;
}
@media (max-width: 768px) {
    .backup-stats-grid { grid-template-columns: repeat(2, 1fr); }
    .backup-status-banner-meta { flex-direction: column; align-items: flex-start; gap: 0.25rem; }
    .backup-meta-divider { display: none; }
}

.backup-stat-card {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 1.1rem 1.25rem;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-xs);
    transition: var(--transition);
}
.backup-stat-card:hover {
    box-shadow: var(--shadow-sm);
    border-color: var(--primary-lighter);
}
.backup-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.backup-stat-icon-count { background: #eef2ff; color: var(--primary); }
.backup-stat-icon-size { background: #d1fae5; color: #10b981; }
.backup-stat-icon-db { background: #dbeafe; color: #2563eb; }
.backup-stat-icon-last { background: #fef3c7; color: #d97706; }
.backup-stat-value {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--text);
    line-height: 1.2;
}
.backup-stat-label {
    font-size: 0.8125rem;
    color: var(--text-muted);
    margin-top: 0.125rem;
}

.backup-create-card { margin-bottom: 1.25rem; }
.backup-create-body {
    display: flex;
    gap: 0.875rem;
    align-items: flex-end;
}
@media (max-width: 640px) {
    .backup-create-body { flex-direction: column; align-items: stretch; }
}

.backup-refresh-hint {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.8125rem;
    color: var(--text-muted);
}
.backup-refresh-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10b981;
    animation: backup-pulse 2s ease-in-out infinite;
}
@keyframes backup-pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(0.85); }
}

.backup-list { min-height: 200px; }
.backup-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: var(--text-muted);
    display: flex;
    flex-direction: column;
    align-items: center;
}
.backup-empty p { margin: 0; }

.backup-table-wrap { overflow-x: auto; }
.backup-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.backup-table th {
    text-align: left;
    padding: 0.75rem 1rem;
    background: var(--surface-2);
    color: var(--text-muted);
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.backup-table td {
    padding: 0.875rem 1rem;
    border-bottom: 1px solid var(--border-soft);
    color: var(--text);
    vertical-align: middle;
}
.backup-table tbody tr:hover { background: var(--surface-2); }
.backup-table tbody tr:last-child td { border-bottom: none; }

.backup-cell-filename {
    font-family: 'SF Mono', Monaco, Consolas, monospace;
    font-size: 0.8125rem;
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.backup-version-tag {
    display: inline-block;
    padding: 1px 6px;
    font-size: 0.6875rem;
    background: var(--primary-light);
    color: var(--primary-dark);
    border-radius: 4px;
    margin-left: 0.375rem;
    vertical-align: 1px;
}
.backup-size-info { display: flex; flex-direction: column; gap: 2px; }
.backup-size-current { font-weight: 600; color: var(--text); }
.backup-size-orig { font-size: 0.75rem; color: var(--text-muted); }
.backup-cell-desc { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.backup-cell-actions { white-space: nowrap; text-align: right; min-width: 180px; }
.backup-action-btn {
    padding: 0.3rem 0.55rem !important;
    font-size: 0.75rem !important;
    margin-left: 0.25rem;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}
.backup-action-btn.is-danger:hover {
    color: #ef4444;
    border-color: #fecaca;
    background: #fef2f2;
}

.backup-confirm-warning {
    padding: 0.625rem 0.875rem;
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: var(--radius-sm);
    color: #92400e;
    font-size: 0.8125rem;
}

.backup-toast {
    position: fixed;
    top: 1.5rem;
    right: 1.5rem;
    z-index: 10000;
    padding: 0.875rem 1.25rem;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-lg);
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: opacity 0.3s, transform 0.3s;
}
.backup-toast.is-success { border-left: 4px solid #10b981; }
.backup-toast.is-error { border-left: 4px solid #ef4444; }
.backup-toast.is-info { border-left: 4px solid var(--primary); }

/* 恢复进度条 */
.backup-progress-track {
    width: 100%;
    height: 8px;
    background: var(--border);
    border-radius: 999px;
    overflow: hidden;
    margin-bottom: 0.75rem;
}
.backup-progress-bar {
    width: 30%;
    height: 100%;
    background: linear-gradient(90deg, var(--primary), #8b5cf6);
    border-radius: 999px;
    animation: backup-progress-move 1.5s ease-in-out infinite;
}
@keyframes backup-progress-move {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(400%); }
}
.backup-progress-status {
    text-align: center;
    font-size: 0.8125rem;
    color: var(--text-muted);
}

/* 行操作中的状态 */
.backup-table tbody tr.is-processing { opacity: 0.6; pointer-events: none; }
.backup-table tbody tr.is-restoring { background: #fef3c7 !important; }

/* === 暗色模式适配 === */
[data-theme="dark"] .backup-status-banner {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.12) 0%, rgba(99, 102, 241, 0.06) 100%);
    border-color: rgba(99, 102, 241, 0.3);
}
[data-theme="dark"] .backup-status-banner-icon {
    background: var(--surface-2);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}
[data-theme="dark"] .backup-stat-icon-count { background: rgba(99, 102, 241, 0.18); color: #a5b4fc; }
[data-theme="dark"] .backup-stat-icon-size { background: rgba(16, 185, 129, 0.18); color: #6ee7b7; }
[data-theme="dark"] .backup-stat-icon-db { background: rgba(37, 99, 235, 0.18); color: #93c5fd; }
[data-theme="dark"] .backup-stat-icon-last { background: rgba(217, 119, 6, 0.18); color: #fcd34d; }
[data-theme="dark"] .backup-confirm-warning {
    background: rgba(217, 119, 6, 0.15);
    border-color: rgba(217, 119, 6, 0.4);
    color: #fcd34d;
}
[data-theme="dark"] .backup-action-btn.is-danger:hover {
    color: #fca5a5;
    border-color: rgba(239, 68, 68, 0.4);
    background: rgba(239, 68, 68, 0.12);
}
[data-theme="dark"] .backup-table tbody tr.is-restoring { background: rgba(217, 119, 6, 0.18) !important; }
[data-theme="dark"] .backup-version-tag {
    background: rgba(99, 102, 241, 0.25);
    color: #c7d2fe;
}
</style>

<script>
(function () {
    var csrfToken = '<?php echo csrf_token(); ?>';
    var sessionId = '<?php echo e(session_id()); ?>';

    function showToast(text, type) {
        var toast = document.getElementById('backup-toast');
        var textEl = document.getElementById('backup-toast-text');
        toast.className = 'backup-toast is-' + (type || 'info');
        toast.style.display = 'flex';
        textEl.textContent = text;
        clearTimeout(showToast._timer);
        showToast._timer = setTimeout(function () {
            toast.style.display = 'none';
        }, 3500);
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(1024));
        i = Math.min(i, units.length - 1);
        return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + units[i];
    }

    // 确认对话框
    var modal = document.getElementById('confirm-modal');
    var modalTitle = document.getElementById('confirm-title');
    var modalMessage = document.getElementById('confirm-message');
    var modalWarning = document.getElementById('confirm-warning');
    var modalWarningText = document.getElementById('confirm-warning-text');
    var modalOk = document.getElementById('confirm-ok');
    var modalCancel = document.getElementById('confirm-cancel');
    var modalClose = document.getElementById('confirm-close');
    var pendingAction = null;

    function confirmAction(title, message, warning, onOk) {
        modalTitle.textContent = title;
        modalMessage.textContent = message;
        if (warning) {
            modalWarning.style.display = '';
            modalWarningText.textContent = warning;
        } else {
            modalWarning.style.display = 'none';
        }
        pendingAction = onOk;
        modal.style.display = 'flex';
    }
    function closeModal() {
        modal.style.display = 'none';
        pendingAction = null;
    }
    modalClose.addEventListener('click', closeModal);
    modalCancel.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    modalOk.addEventListener('click', function () {
        if (typeof pendingAction === 'function') pendingAction();
        closeModal();
    });

    // 创建备份
    function createBackup(description) {
        var btn = document.getElementById('create-backup-btn');
        var quickBtn = document.getElementById('quick-backup-btn');
        var originalText = btn.innerHTML;
        btn.disabled = true;
        quickBtn.disabled = true;
        btn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><circle cx="12" cy="12" r="10" stroke-dasharray="40" stroke-dashoffset="20"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="1s" repeatCount="indefinite"/></circle></svg>' + <?php echo json_encode(t('admin_backup_js_creating', '备份中...')); ?>;

        var formData = new FormData();
        formData.append('action', 'create');
        formData.append('csrf_token', csrfToken);
        formData.append('description', description || '');

        fetch('<?php echo site_url('admin/api/backup_ajax'); ?>', { method: 'POST', body: formData, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    showToast(<?php echo json_encode(t('admin_backup_js_created', '备份创建成功：')); ?> + res.filename, 'success');
                    setTimeout(function () { location.reload(); }, 1200);
                } else {
                    showToast(<?php echo json_encode(t('admin_backup_js_create_failed', '备份失败：')); ?> + (res.error || <?php echo json_encode(t('admin_backup_js_unknown_error', '未知错误')); ?>), 'error');
                }
            })
            .catch(function () { showToast(<?php echo json_encode(t('admin_backup_js_network_fail', '网络错误，备份失败。')); ?>, 'error'); })
            .finally(function () {
                btn.disabled = false;
                quickBtn.disabled = false;
                btn.innerHTML = originalText;
            });
    }

    document.getElementById('create-backup-btn').addEventListener('click', function () {
        var desc = document.getElementById('backup-description').value.trim();
        createBackup(desc);
    });
    document.getElementById('quick-backup-btn').addEventListener('click', function () {
        createBackup(<?php echo json_encode(t('admin_backup_js_quick_desc', '快速备份')); ?>);
    });

    // 删除备份
    document.querySelectorAll('.backup-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var filename = btn.dataset.filename;
            var row = btn.closest('tr');
            confirmAction(
                <?php echo json_encode(t('admin_backup_js_confirm_delete_title', '删除备份')); ?>,
                <?php echo json_encode(t('admin_backup_js_confirm_delete_msg', '确定要删除备份文件 "{name}" 吗？')); ?>.replace('{name}', filename),
                <?php echo json_encode(t('admin_backup_js_confirm_delete_warn', '此操作不可恢复，删除后无法找回该备份。')); ?>,
                function () {
                    row.classList.add('is-processing');
                    var formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('csrf_token', csrfToken);
                    formData.append('filename', filename);
                    fetch('<?php echo site_url('admin/api/backup_ajax'); ?>', { method: 'POST', body: formData, cache: 'no-store' })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) {
                                showToast(<?php echo json_encode(t('admin_backup_js_deleted', '备份已删除')); ?>, 'success');
                                row.style.transition = 'opacity 0.3s';
                                row.style.opacity = '0';
                                setTimeout(function () {
                                    row.remove();
                                    updateListCount();
                                }, 300);
                            } else {
                                showToast(<?php echo json_encode(t('admin_backup_js_delete_failed', '删除失败：')); ?> + (res.error || <?php echo json_encode(t('admin_backup_js_unknown_error', '未知错误')); ?>), 'error');
                                row.classList.remove('is-processing');
                            }
                        })
                        .catch(function () { showToast(<?php echo json_encode(t('admin_backup_js_network_delete_fail', '网络错误，删除失败。')); ?>, 'error'); row.classList.remove('is-processing'); });
                }
            );
        });
    });

    // 恢复备份
    document.querySelectorAll('.backup-restore-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var filename = btn.dataset.filename;
            var row = btn.closest('tr');
            confirmAction(
                <?php echo json_encode(t('admin_backup_js_restore_title', '恢复备份')); ?>,
                <?php echo json_encode(t('admin_backup_js_restore_msg', '确定要将数据库恢复到备份 "{name}" 的状态吗？')); ?>.replace('{name}', filename),
                <?php echo json_encode(t('admin_backup_js_restore_warn', '恢复将覆盖当前数据库的所有数据。系统会先自动创建一个"恢复前快照"作为安全网。恢复完成后建议刷新页面。')); ?>,
                function () {
                    row.classList.add('is-restoring');
                    // 显示恢复进度模态框，防止用户重复操作并明确进度
                    var progressModal = document.getElementById('restore-progress-modal');
                    if (progressModal) progressModal.style.display = 'flex';
                    var formData = new FormData();
                    formData.append('action', 'restore');
                    formData.append('csrf_token', csrfToken);
                    formData.append('filename', filename);
                    fetch('<?php echo site_url('admin/api/backup_ajax'); ?>', { method: 'POST', body: formData, cache: 'no-store' })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) {
                                var msg = <?php echo json_encode(t('admin_backup_js_restore_success', '恢复成功！')); ?> + res.message;
                                if (typeof res.user_count !== 'undefined') {
                                    msg += '\n' + <?php echo json_encode(t('admin_backup_js_restore_counts', '用户数：{u}，帖子数：{p}')); ?>.replace('{u}', res.user_count).replace('{p}', res.post_count);
                                }
                                showToast(<?php echo json_encode(t('admin_backup_js_restore_refreshing', '数据库已成功恢复，正在刷新...')); ?>, 'success');
                                setTimeout(function () { location.reload(); }, 2000);
                            } else {
                                showToast(<?php echo json_encode(t('admin_backup_js_restore_failed', '恢复失败：')); ?> + (res.error || <?php echo json_encode(t('admin_backup_js_unknown_error', '未知错误')); ?>), 'error');
                                row.classList.remove('is-restoring');
                                if (progressModal) progressModal.style.display = 'none';
                            }
                        })
                        .catch(function () { showToast(<?php echo json_encode(t('admin_backup_js_network_restore_fail', '网络错误，恢复失败。')); ?>, 'error'); row.classList.remove('is-restoring'); if (progressModal) progressModal.style.display = 'none'; });
                }
            );
        });
    });

    function updateListCount() {
        var rows = document.querySelectorAll('#backup-table-body tr');
        var countEl = document.getElementById('backup-list-count');
        if (countEl) countEl.textContent = rows.length;
        if (rows.length === 0) {
            var list = document.getElementById('backup-list');
            if (list) {
                list.innerHTML = '<div class="backup-empty"><svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;margin-bottom:0.5rem;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg><p>' + <?php echo json_encode(t('admin_backup_empty_text', '暂无备份记录，点击上方"创建备份"按钮开始第一次备份。')); ?> + '</p></div>';
            }
        }
    }

    // ===== 自动备份设置 =====
    var autoEnabled = document.getElementById('auto-backup-enabled');
    var autoBody = document.getElementById('auto-backup-body');
    var autoInterval = document.getElementById('auto-backup-interval');
    var autoKeep = document.getElementById('auto-backup-keep');

    function saveAutoConfig() {
        var config = {
            enabled: autoEnabled.checked,
            interval: parseInt(autoInterval.value),
            keep: parseInt(autoKeep.value)
        };
        var formData = new FormData();
        formData.append('action', 'save_auto_config');
        formData.append('csrf_token', csrfToken);
        formData.append('config', JSON.stringify(config));
        fetch('<?php echo site_url('admin/api/backup_ajax'); ?>', { method: 'POST', body: formData, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    showToast(res.message || <?php echo json_encode(t('admin_backup_js_auto_saved', '自动备份设置已保存')); ?>, 'success');
                    // 更新下次执行时间
                    if (res.next_run) {
                        document.getElementById('auto-next-run').textContent = res.next_run;
                    }
                    if (res.last_run) {
                        document.getElementById('auto-last-run').textContent = res.last_run;
                    }
                } else {
                    showToast(<?php echo json_encode(t('admin_backup_js_save_failed', '保存失败：')); ?> + (res.error || <?php echo json_encode(t('admin_backup_js_unknown_error', '未知错误')); ?>), 'error');
                }
            })
            .catch(function () { showToast(<?php echo json_encode(t('admin_backup_js_network_save_fail', '网络错误，保存失败。')); ?>, 'error'); });
    }

    autoEnabled.addEventListener('change', function () {
        autoBody.style.display = this.checked ? '' : 'none';
        saveAutoConfig();
    });

    autoInterval.addEventListener('change', saveAutoConfig);
    autoKeep.addEventListener('change', saveAutoConfig);
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
