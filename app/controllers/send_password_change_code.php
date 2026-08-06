<?php
/**
 * 云界论坛 - 发送修改密码邮箱验证码（AJAX 接口）
 * 仅限已登录用户，发送到当前账号绑定的邮箱
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!email_verification_enabled()) {
    echo json_encode(['success' => false, 'error' => t('send_password_change_code_d1441c','邮件验证未启用。')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => t('send_password_change_code_f7bf3e','请求方式错误。')]);
    exit;
}

if (!validate_csrf()) {
    echo json_encode(['success' => false, 'error' => t('send_password_change_code_f9b9e9','安全验证失败，请刷新页面重试。')]);
    exit;
}

require_login();

$user = current_user();
if (!$user) {
    echo json_encode(['success' => false, 'error' => t('send_password_change_code_05e9ef','请先登录。')]);
    exit;
}

$email = $user['email'] ?? '';
if (empty($email)) {
    echo json_encode(['success' => false, 'error' => t('send_password_change_code_5fb1d2','您的账号未绑定邮箱，无法发送验证码。')]);
    exit;
}

$result = send_password_change_email_code($email);
echo json_encode($result);
