<?php
/**
 * 云界论坛 - 我的通知
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

require_login();

$db = get_db();
$userId = (int)$_SESSION['user_id'];

// 标记全部已读：仅接受 POST
$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    if (!validate_csrf()) {
        set_flash(t('notif_csrf_failed', '安全校验失败，请刷新页面后重新操作。'), 'error');
        redirect('/notifications');
    }
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0");
    $stmt->execute([':user_id' => $userId]);
    set_flash(t('notif_all_marked_read', '全部通知已标记为已读。'), 'success');
    $redirect = $_POST['redirect'] ?? '/notifications';
    // 白名单校验：仅接受以 / 或 ? 开头、不含 // 与反斜杠、不带 scheme 的站内相对路径，防止开放重定向
    if (!is_string($redirect)
        || (strpos($redirect, '/') !== 0 && strpos($redirect, '?') !== 0)
        || strpos($redirect, '//') !== false
        || strpos($redirect, '\\') !== false
        || preg_match('/^[a-z0-9+.-]+:/i', $redirect)) {
        $redirect = '/notifications';
    }
    redirect($redirect);
}
// 旧 GET 链接命中：不执行写操作，提示刷新
if ($action === 'mark_all_read') {
    set_flash(t('post_flash_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
    redirect('/notifications');
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$totalStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id");
$totalStmt->execute([':user_id' => $userId]);
$total = (int)$totalStmt->fetchColumn();

$stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll();

$pageTitle = t('notif_page_title', '我的通知');
include APP_ROOT . 'app/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('notif_page_title', '我的通知')); ?></h1>
    <div class="page-tools">
        <form method="post" action="<?php echo site_url('notifications'); ?>" class="inline-action-form">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="mark_all_read">
            <input type="hidden" name="redirect" value="/notifications">
            <button type="submit" class="btn btn-secondary"><?php echo e(t('notif_mark_all_read', '全部已读')); ?></button>
        </form>
    </div>
</div>

<div class="card">
    <?php if (empty($notifications)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </div>
            <p><?php echo e(t('notif_empty', '暂无通知')); ?></p>
        </div>
    <?php else: ?>
        <ul class="notification-full-list">
            <?php foreach ($notifications as $n): ?>
                <li class="notification-full-item <?php echo (int)$n['is_read'] === 0 ? 'is-unread' : 'is-read'; ?>">
                    <form method="post" action="<?php echo site_url('notification_read'); ?>" class="inline-action-form notification-full-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                        <input type="hidden" name="link" value="<?php echo e($n['link'] ?? ''); ?>">
                        <button type="submit" class="notification-full-link">
                            <div class="notification-full-main">
                                <div class="notification-full-title"><?php echo e($n['title']); ?></div>
                                <?php if ($n['content'] !== ''): ?>
                                    <div class="notification-full-content"><?php echo e($n['content']); ?></div>
                                <?php endif; ?>
                                <div class="notification-full-time"><?php echo time_ago($n['created_at']); ?></div>
                            </div>
                            <?php if ((int)$n['is_read'] === 0): ?>
                                <span class="notification-unread-dot" aria-label="<?php echo e(t('notif_unread', '未读')); ?>"></span>
                            <?php endif; ?>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php echo pagination($page, $total, $perPage, '/notifications'); ?>
    <?php endif; ?>
</div>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
