<?php
/**
 * 云界论坛 - 管理后台初始化
 *
 * 负责加载公共函数、检查安装状态与管理员权限。
 * 此文件应在所有 admin/*.php 页面开头引入，且必须在任何 HTML 输出之前。
 */

require_once dirname(__DIR__) . '/../includes/functions.php';
require_once dirname(__DIR__) . '/../includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

if (!is_logged_in()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    redirect('/login');
}

if (!is_admin()) {
    set_flash(t('common_admin_no_permission', '你没有权限访问该页面。'), 'error');
    redirect('/');
}

// 统一 CSRF 防护：所有管理后台 POST 请求必须先通过 CSRF 校验。
// 避免 token 过期（多标签页操作、登录后 token 轮换等）时表单提交被静默丢弃，
// 造成"点了没反应 / 以为已保存但实际未保存"的问题。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validate_csrf()) {
    set_flash(t('common_admin_csrf_expired', '安全校验失败（表单已过期），请刷新页面后重新操作。'), 'error');
    redirect('/admin/');
}

// 定时自动备份触发器（管理员每次访问后台时检查是否需要执行自动备份）
require_once APP_ROOT . 'app/includes/backup_manager.php';
$autoBackupResult = (new BackupManager())->tryAutoBackup();
// 将结果存入 session，backup 页面可以读取显示
if ($autoBackupResult !== null) {
    $_SESSION['_auto_backup_result'] = $autoBackupResult;
}
