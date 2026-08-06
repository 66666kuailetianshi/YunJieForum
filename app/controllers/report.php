<?php
/**
 * 云界论坛 - 提交举报
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/');
}

if (!validate_csrf()) {
    set_flash(t('report_csrf_failed', '安全验证失败，请刷新页面重试。'), 'error');
    // 仅跳转本站同源来源页，防止开放重定向
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $redirect = '/';
    if ($referer !== '' && parse_url($referer, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) {
        $redirect = (string)parse_url($referer, PHP_URL_PATH);
        if (parse_url($referer, PHP_URL_QUERY) !== null) {
            $redirect .= '?' . parse_url($referer, PHP_URL_QUERY);
        }
    }
    redirect($redirect);
}

$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
$replyId = isset($_POST['reply_id']) ? (int)$_POST['reply_id'] : 0;
$reasonType = isset($_POST['reason_type']) ? trim($_POST['reason_type']) : 'other';
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
$returnUrl = isset($_POST['return_url']) ? trim($_POST['return_url']) : '';

if ($returnUrl === '' || !preg_match('#^/post\?#i', $returnUrl)) {
    $returnUrl = '/post?id=' . ($postId > 0 ? $postId : 0);
}

$result = add_report($_SESSION['user_id'], $reasonType, $reason, $postId > 0 ? $postId : null, $replyId > 0 ? $replyId : null);

if ($result['success']) {
    // 通知所有管理员
    $reasonTypes = get_report_reason_types();
    $reasonLabel = $reasonTypes[$reasonType] ?? t('report_b244ea','其他原因');
    $notifyContent = t('report_2fcdf6','举报类型：') . $reasonLabel;
    if ($reason !== '') {
        $notifyContent .= t('report_618916','，说明：') . $reason;
    }
    $db = get_db();
    $adminStmt = $db->prepare("SELECT id FROM users WHERE role = 'admin'");
    $adminStmt->execute();
    foreach ($adminStmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
        send_notification((int)$adminId, 'report', t('report_ffc21f','收到新的举报'), $notifyContent, site_url('admin/reports'));
    }

    set_flash(t('report_success', '举报已提交，管理员会尽快处理。'), 'success');
} else {
    set_flash($result['error'] ?? t('report_failed', '举报提交失败，请稍后重试。'), 'error');
}

redirect($returnUrl);
