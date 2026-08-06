<?php
/**
 * 云界论坛 - 忘记密码
 *
 * 支持两种模式：
 * 1. 已启用 SMTP：发送邮件重置链接。
 * 2. 未启用 SMTP：提交重置申请，等待管理员后台审核。
 *    若账号设置了密保问题，必须先正确回答才能提交申请，降低冒领风险。
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';
require_once APP_ROOT . 'app/captcha/core.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

if (is_logged_in()) {
    redirect('/');
}

// 取消密保验证步骤
if (isset($_GET['cancel']) && $_GET['cancel'] === '1') {
    unset($_SESSION['forgot_password_step'], $_SESSION['forgot_password_email']);
    redirect('/forgot_password');
}

$errors = [];
$email = '';
$sent = false;
$smtpEnabled = email_verification_enabled();
$step = $_SESSION['forgot_password_step'] ?? 'email'; // email | security
$pendingEmail = $_SESSION['forgot_password_email'] ?? '';
$pendingUser = null;
$securityQuestion = '';

if ($step === 'security' && $pendingEmail !== '') {
    $db = get_db();
    $stmt = $db->prepare("SELECT id, username, security_question, security_answer_hash FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
    $stmt->execute([':email' => $pendingEmail]);
    $pendingUser = $stmt->fetch();
    if ($pendingUser) {
        $securityQuestion = $pendingUser['security_question'] ?? '';
    }
    if (empty($securityQuestion)) {
        // 没有密保问题则直接回到邮箱步骤，避免步骤悬空
        unset($_SESSION['forgot_password_step'], $_SESSION['forgot_password_email']);
        $step = 'email';
        $pendingEmail = '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = t('forgot_security_verify_fail', '安全验证失败，请刷新页面重试。');
    } elseif (captcha_enabled() && !captcha_passed($_POST['captcha_token'] ?? '')) {
        $errors[] = t('slider_captcha_fail', '请先完成人机验证。');
    } elseif ($step === 'email') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('forgot_email_invalid', '请输入有效的邮箱地址。');
        } else {
            // 频率限制：每 60 秒只能请求一次
            $lastRequest = $_SESSION['forgot_password_sent_at'] ?? 0;
            if (time() - $lastRequest < 60) {
                $errors[] = t('forgot_too_frequent', '发送太频繁，请稍后再试。');
            } else {
                $db = get_db();
                $stmt = $db->prepare("SELECT id, username, email, security_question, security_answer_hash FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch();

                if ($user) {
                    if ($smtpEnabled) {
                        // SMTP 模式：生成 token 并通过邮件发送重置链接
                        $token = bin2hex(random_bytes(32));
                        $tokenHash = hash('sha256', $token);
                        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 小时有效

                        $stmt = $db->prepare("UPDATE users SET reset_token = :token, reset_expires = :expires WHERE id = :id");
                        $stmt->execute([':token' => $tokenHash, ':expires' => $expires, ':id' => $user['id']]);

                        $resetUrl = site_absolute_url() . '/index.php?route=reset_password&email=' . rawurlencode($email) . '&token=' . $token;

                        $subject = '【' . SITE_NAME . '】' . t('forgot_reset_subject', '重置密码');
                        $body  = '<p>' . t('forgot_email_hello', '您好，<strong>{name}</strong>', ['name' => e($user['username'])]) . '</p>';
                        $body .= '<p>' . t('forgot_email_intro', '您申请了重置密码，请点击下方按钮完成重置（链接 <strong>1 小时</strong>内有效）：') . '</p>';
                        $body .= '<p style="color:#71717a;">' . t('forgot_email_ignore', '如非本人操作，请忽略此邮件，您的密码不会发生变化。') . '</p>';
                        $body = render_email_template(t('forgot_reset_subject', '重置密码'), $body, [
                            'subject'      => $subject,
                            'action_url'   => $resetUrl,
                            'action_text'  => t('forgot_email_action_text', '立即重置密码'),
                        ]);

                        $result = send_mail($email, $user['username'], $subject, $body, 'reset');
                        if (!$result['success']) {
                            $errors[] = t('forgot_email_send_fail', '邮件发送失败：') . $result['error'];
                        } else {
                            $_SESSION['forgot_password_sent_at'] = time();
                            $sent = true;
                        }
                    } else {
                        // 无 SMTP 模式
                        if (!empty($user['security_question']) && !empty($user['security_answer_hash'])) {
                            // 需要密保验证，进入下一步
                            $_SESSION['forgot_password_step'] = 'security';
                            $_SESSION['forgot_password_email'] = $email;
                            $step = 'security';
                            $pendingEmail = $email;
                            $pendingUser = $user;
                            $securityQuestion = $user['security_question'];
                        } else {
                            // 未设置密保，直接提交申请（管理员会收到风险提示）
                            createPasswordResetRequest($db, $user, false, false);
                            $_SESSION['forgot_password_sent_at'] = time();
                            $sent = true;
                        }
                    }
                } else {
                    // 邮箱不存在也显示相同提示，避免枚举
                    $sent = true;
                }
            }
        }
    } elseif ($step === 'security' && $pendingUser) {
        $answer = trim($_POST['security_answer'] ?? '');
        if ($answer === '') {
            $errors[] = t('forgot_security_answer_required', '请输入密保答案。');
        } elseif (empty($pendingUser['security_answer_hash'])) {
            $errors[] = t('forgot_no_security_question', '该账号未设置密保问题，请返回重新申请。');
        } else {
            if (password_verify($answer, $pendingUser['security_answer_hash'])) {
                $db = get_db();
                createPasswordResetRequest($db, $pendingUser, true, true);
                $_SESSION['forgot_password_sent_at'] = time();
                unset($_SESSION['forgot_password_step'], $_SESSION['forgot_password_email']);
                $sent = true;
            } else {
                $errors[] = t('forgot_security_answer_wrong', '密保答案不正确。');
            }
        }
    }
}

/**
 * 创建密码重置申请
 */
function createPasswordResetRequest(PDO $db, array $user, bool $hasSecurityQuestion, bool $securityVerified): void {
    $stmt = $db->prepare("SELECT id FROM password_reset_requests WHERE user_id = :uid AND status = 'pending' LIMIT 1");
    $stmt->execute([':uid' => $user['id']]);
    if (!$stmt->fetch()) {
        $stmt = $db->prepare("INSERT INTO password_reset_requests (user_id, email, has_security_question, security_verified) VALUES (:uid, :email, :has_sq, :verified)");
        $stmt->execute([
            ':uid'       => $user['id'],
            ':email'     => $user['email'],
            ':has_sq'    => $hasSecurityQuestion ? 1 : 0,
            ':verified'  => $securityVerified ? 1 : 0,
        ]);
    }
}

$pageTitle = t('forgot_title', '忘记密码');
include APP_ROOT . 'app/includes/header.php';
?>

<div class="auth-container">
    <div class="card">
        <div class="auth-header">
            <img src="../public/images/logo.svg" alt="" class="auth-logo">
            <h1 class="auth-title"><?php echo e(t('forgot_heading', '忘记密码')); ?></h1>
            <p class="text-muted"><?php echo $smtpEnabled ? e(t('forgot_desc_smtp', '通过邮箱重置 {site} 密码', ['site' => SITE_NAME])) : e(t('forgot_desc_manual', '提交密码重置申请，等待管理员审核')); ?></p>
        </div>

        <?php if ($sent): ?>
            <?php if ($smtpEnabled): ?>
                <?php echo show_message(t('forgot_sent_smtp', '如果该邮箱已注册，我们已发送重置链接，请查收邮件。'), 'success'); ?>
            <?php else: ?>
                <?php echo show_message(t('forgot_sent_manual', '如果该邮箱已注册，密码重置申请已提交，请等待管理员审核。'), 'success'); ?>
            <?php endif; ?>
            <a href="<?php echo site_url('login'); ?>" class="btn btn-primary btn-block mt-2"><?php echo e(t('forgot_back_login', '返回登录')); ?></a>
        <?php elseif ($step === 'security' && $pendingUser): ?>
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $err): ?>
                    <?php echo show_message($err, 'error'); ?>
                <?php endforeach; ?>
            <?php endif; ?>
            <form method="POST" action="<?php echo site_url('forgot_password'); ?>" data-validate>
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('forgot_label_security_question', '密保问题')); ?></label>
                    <p class="form-hint"><?php echo e($securityQuestion); ?></p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="security_answer"><?php echo e(t('forgot_label_security_answer', '密保答案')); ?></label>
                    <input type="text" class="form-control" id="security_answer" name="security_answer" required>
                    <p class="form-hint"><?php echo e(t('forgot_security_hint', '答案区分大小写。若忘记答案，请联系管理员。')); ?></p>
                </div>
                <?php if (captcha_enabled()): ?>
                    <div class="form-group">
                        <div id="captcha" data-api="<?php echo site_url('api/captcha'); ?>"></div>
                        <input type="hidden" name="captcha_token" id="captcha_token" value="">
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-block"><?php echo e(t('forgot_submit_request', '提交申请')); ?></button>
                <a href="<?php echo site_url('forgot_password', ['cancel' => 1]); ?>" class="btn btn-secondary btn-block mt-1"><?php echo e(t('forgot_back', '返回')); ?></a>
            </form>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $err): ?>
                    <?php echo show_message($err, 'error'); ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="POST" action="<?php echo site_url('forgot_password'); ?>" data-validate>
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="form-group">
                    <label class="form-label" for="email"><?php echo e(t('forgot_label_email', '注册邮箱')); ?></label>
                    <input type="email" class="form-control" id="email" name="email" value="<?php echo e($email); ?>" required>
                </div>
                <?php if (captcha_enabled()): ?>
                    <div class="form-group">
                        <div id="captcha" data-api="<?php echo site_url('api/captcha'); ?>"></div>
                        <input type="hidden" name="captcha_token" id="captcha_token" value="">
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-block">
                    <?php echo $smtpEnabled ? e(t('forgot_btn_smtp', '发送重置链接')) : e(t('forgot_btn_manual', '提交重置申请')); ?>
                </button>
            </form>

            <div class="auth-footer">
                <?php echo e(t('forgot_remember_password', '想起密码了？')); ?><a href="<?php echo site_url('login'); ?>"><?php echo e(t('forgot_login_now', '立即登录')); ?></a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
