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

if (!validate_csrf()) {
    set_flash(t('notif_read_csrf_failed', '安全验证失败，请刷新页面重试。'), 'error');
    redirect('/notifications');
}

$notificationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$userId = (int)$_SESSION['user_id'];

$db = get_db();
$stmt = $db->prepare("SELECT link FROM notifications WHERE id = :id AND user_id = :user_id");
$stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
$notification = $stmt->fetch();

if ($notification) {
    mark_notification_read($notificationId, $userId);
    if (!empty($notification['link'])) {
        redirect($notification['link']);
    }
}

redirect('/notifications');
