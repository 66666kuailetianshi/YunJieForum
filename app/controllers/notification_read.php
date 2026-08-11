<?php
/**
 * 云界论坛 - 标记通知已读并跳转
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

require_login();

// 仅接受 POST（通知条目已渲染为 POST 表单，隐藏域携带目标地址）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash(t('post_flash_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
    redirect('/notifications');
}

if (!validate_csrf()) {
    set_flash(t('notif_read_csrf_failed', '安全验证失败，请刷新页面重试。'), 'error');
    redirect('/notifications');
}

$notificationId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$userId = (int)$_SESSION['user_id'];

$db = get_db();
$stmt = $db->prepare("SELECT link FROM notifications WHERE id = :id AND user_id = :user_id");
$stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
$notification = $stmt->fetch();

if ($notification) {
    mark_notification_read($notificationId, $userId);
    // 目标地址以数据库记录为准，隐藏域 link 仅作兑底；跳转前走站内白名单校验防开放重定向
    $target = !empty($notification['link']) ? $notification['link'] : (string)($_POST['link'] ?? '');
    if ($target !== ''
        && (strpos($target, '/') === 0 || strpos($target, '?') === 0)
        && strpos($target, '//') === false
        && strpos($target, '\\') === false
        && !preg_match('/^[a-z0-9+.-]+:/i', $target)) {
        redirect($target);
    }
}

redirect('/notifications');
