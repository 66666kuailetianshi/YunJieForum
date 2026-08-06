<?php
/**
 * 云界论坛 - 发送注册邮箱验证码（AJAX 接口）
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!email_verification_enabled()) {
    echo json_encode(['success' => false, 'error' => t('send_email_code_d1441c','邮件验证未启用。')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => t('send_email_code_f7bf3e','请求方式错误。')]);
    exit;
}

if (!validate_csrf()) {
    echo json_encode(['success' => false, 'error' => t('send_email_code_f9b9e9','安全验证失败，请刷新页面重试。')]);
    exit;
}

$email = trim($_POST['email'] ?? '');
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => t('send_email_code_fbbddf','请输入有效的邮箱地址。')]);
    exit;
}

try {
    $db = get_db();
    $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => t('send_email_code_4e1fd7','该邮箱已被注册。')]);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => t('send_email_code_1f14cc','服务器繁忙，请稍后再试。')]);
    exit;
}

$result = send_email_verification_code($email);
echo json_encode($result);
