<?php
/**
 * 云界论坛 - 强制修改密码
 *
 * 当管理员重置用户密码后，用户首次登录必须先修改密码才能继续使用。
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

require_login();

$user = current_user();
if (!$user || empty($user['force_password_change'])) {
    redirect('/');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = t('forcepw_security_verify_fail', '安全验证失败，请刷新页面重试。');
    } else {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($newPassword) < 6) {
            $errors[] = t('forcepw_password_length', '新密码长度不能少于 6 位。');
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = t('forcepw_password_mismatch', '两次输入的新密码不一致。');
        }
        if (password_verify($newPassword, $user['password'])) {
            $errors[] = t('forcepw_password_same', '新密码不能与旧密码相同。');
        }

        if (empty($errors)) {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $db = get_db();
            $stmt = $db->prepare("UPDATE users SET password = :password, force_password_change = 0, remember_token = NULL WHERE id = :id");
            $stmt->execute([':password' => $hash, ':id' => $user['id']]);
            unset($_SESSION['user']);
            session_regenerate_id(true);
            set_flash(t('forcepw_success', '密码已修改，请妥善保管。'), 'success');
            redirect('/');
        }
    }
}

$pageTitle = t('forcepw_title', '修改密码');
include APP_ROOT . 'app/includes/header.php';
?>

<div class="auth-container">
    <div class="card">
        <div class="auth-header">
            <img src="../public/images/logo.svg" alt="" class="auth-logo">
            <h1 class="auth-title"><?php echo e(t('forcepw_heading', '修改密码')); ?></h1>
            <p class="text-muted"><?php echo e(t('forcepw_desc', '您的密码已被管理员重置，请先设置新密码。')); ?></p>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <?php echo show_message($err, 'error'); ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="POST" action="<?php echo site_url('force_change_password'); ?>" data-validate>
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="form-group">
                <label class="form-label" for="new_password"><?php echo e(t('forcepw_label_password', '新密码')); ?></label>
                <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
            </div>
            <div class="form-group">
                <label class="form-label" for="confirm_password"><?php echo e(t('forcepw_label_password_confirm', '确认新密码')); ?></label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
            </div>
            <button type="submit" class="btn btn-primary btn-block"><?php echo e(t('forcepw_submit', '确认修改')); ?></button>
        </form>
    </div>
</div>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
