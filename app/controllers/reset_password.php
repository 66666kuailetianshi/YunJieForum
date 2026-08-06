<?php
/**
 * 云界论坛 - 重置密码
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

if (is_logged_in()) {
    redirect('/');
}

if (!email_verification_enabled()) {
    redirect('/login');
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$email = $_GET['email'] ?? $_POST['email'] ?? '';
$token = trim($token);
$email = trim($email);

$errors = [];
$success = false;
$valid = false;
$userId = null;

if ($token !== '' && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $db = get_db();
    $stmt = $db->prepare("SELECT id, reset_token, reset_expires FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user && !empty($user['reset_token']) && !empty($user['reset_expires'])) {
        $now = time();
        $expires = db_time($user['reset_expires']);
        if ($now <= $expires && hash_equals($user['reset_token'], hash('sha256', $token))) {
            $valid = true;
            $userId = (int)$user['id'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    if (!validate_csrf()) {
        $errors[] = t('reset_security_verify_fail', '安全验证失败，请刷新页面重试。');
    } else {
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 6) {
            $errors[] = t('reset_password_length', '密码长度不能少于 6 位。');
        } elseif ($password !== $passwordConfirm) {
            $errors[] = t('reset_password_mismatch', '两次输入的密码不一致。');
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $db = get_db();
            $stmt = $db->prepare("UPDATE users SET password = :password, reset_token = NULL, reset_expires = NULL WHERE id = :id");
            $stmt->execute([':password' => $hash, ':id' => $userId]);

            // 清除可能存在的 remember_token，强制重新登录
            $db->prepare("UPDATE users SET remember_token = NULL WHERE id = :id")->execute([':id' => $userId]);

            $success = true;
            set_flash(t('reset_success', '密码已重置，请使用新密码登录。'), 'success');
            redirect('/login');
        }
    }
}

$pageTitle = t('reset_title', '重置密码');
include APP_ROOT . 'app/includes/header.php';
?>

<div class="auth-container">
    <div class="card">
        <div class="auth-header">
            <img src="../public/images/logo.svg" alt="" class="auth-logo">
            <h1 class="auth-title"><?php echo e(t('reset_heading', '重置密码')); ?></h1>
            <p class="text-muted"><?php echo e(t('reset_desc', '设置新的登录密码')); ?></p>
        </div>

        <?php if (!$valid): ?>
            <?php echo show_message(t('reset_link_invalid', '重置链接无效或已过期。'), 'error'); ?>
            <a href="<?php echo site_url('forgot_password'); ?>" class="btn btn-primary btn-block mt-2"><?php echo e(t('reset_apply_again', '重新申请')); ?></a>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $err): ?>
                    <?php echo show_message($err, 'error'); ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="POST" action="<?php echo site_url('reset_password'); ?>" data-validate>
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="token" value="<?php echo e($token); ?>">
                <input type="hidden" name="email" value="<?php echo e($email); ?>">

                <div class="form-group">
                    <label class="form-label" for="password"><?php echo e(t('reset_label_password', '新密码')); ?></label>
                    <input type="password" class="form-control" id="password" name="password" required minlength="6">
                </div>
                <div class="form-group">
                    <label class="form-label" for="password_confirm"><?php echo e(t('reset_label_password_confirm', '确认新密码')); ?></label>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" required minlength="6">
                </div>
                <button type="submit" class="btn btn-primary btn-block"><?php echo e(t('reset_submit', '确认重置')); ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
