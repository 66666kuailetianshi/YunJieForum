<?php
/**
 * 云界论坛 - 退出登录
 * 需要 CSRF 校验，防止通过 <img src="logout.php"> 强制登出
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

// CSRF 校验：支持 GET 参数 csrf_token（前台导航退出链接）
if (!validate_csrf()) {
    set_flash(t('logout_f13f10','退出登录失败，请重试。'), 'error');
    redirect('/');
}

if (is_logged_in()) {
    $user = current_user();
    if ($user) {
        try {
            $db = get_db();
            $stmt = $db->prepare("UPDATE users SET remember_token = NULL WHERE id = :id");
            $stmt->execute([':id' => $user['id']]);
        } catch (Exception $e) {
            // 忽略数据库异常，仍允许登出
        }
    }
}

setcookie('forum_remember', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => COOKIE_SECURE,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// 清空 session 并重新生成 id
session_regenerate_id(true);
$_SESSION = [];
session_destroy();

redirect('/');
