<?php
/**
 * 云界论坛 - 用户注册
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';
require_once APP_ROOT . 'app/components/sensitive_filter/helper.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

if (is_logged_in()) {
    redirect('/');
}

$errors = [];
$username = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = t('register_security_verify_fail', '安全验证失败，请刷新页面重试。');
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $agreeTerms = !empty($_POST['agree_terms']);

        if (!$agreeTerms) {
            $errors[] = t('register_agree_terms_required', '请阅读并同意用户协议与隐私政策。');
        }

        if (empty($username) || mb_strlen($username) < 3 || mb_strlen($username) > 32) {
            $errors[] = t('register_username_length', '用户名长度必须在 3-32 个字符之间。');
        } elseif (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
            $errors[] = t('register_username_chars', '用户名只能包含中文、英文、数字和下划线。');
        } elseif (has_sensitive_words($username, 2)) {
            $errors[] = t('register_username_sensitive', '用户名包含违规内容，请更换。');
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('register_email_invalid', '请输入有效的邮箱地址。');
        }

        if (strlen($password) < 6) {
            $errors[] = t('register_password_length', '密码长度不能少于 6 位。');
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors[] = t('register_password_alnum', '密码必须同时包含字母和数字。');
        }

        if ($password !== $passwordConfirm) {
            $errors[] = t('register_password_mismatch', '两次输入的密码不一致。');
        }

        $verificationCode = trim($_POST['verification_code'] ?? '');
        if (email_verification_enabled()) {
            if ($verificationCode === '') {
                $errors[] = t('register_code_required', '请输入邮箱验证码。');
            } elseif (!validate_email_verification_code($email, $verificationCode)) {
                $errors[] = t('register_code_invalid', '邮箱验证码错误或已过期。');
            }
        }

        if (empty($errors)) {
            $db = get_db();

            // 检查用户名/邮箱是否已存在
            $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username) OR LOWER(email) = LOWER(:email) LIMIT 1");
            $stmt->execute([':username' => $username, ':email' => $email]);
            if ($stmt->fetch()) {
                $errors[] = t('register_taken', '用户名或邮箱已被注册。');
            } else {
                // 判断是否已有管理员
                $adminExists = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
                $role = $adminExists ? 'user' : 'admin';

                $hash = password_hash($password, PASSWORD_BCRYPT);
                $uid = generate_uid();
                $stmt = $db->prepare("INSERT INTO users (uid, username, email, password, role) VALUES (:uid, :username, :email, :password, :role)");
                $stmt->execute([
                    ':uid' => $uid,
                    ':username' => $username,
                    ':email' => $email,
                    ':password' => $hash,
                    ':role' => $role,
                ]);

                $userId = (int)$db->lastInsertId();

                // 重新生成 session id，防止会话固定攻击
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                unset($_SESSION['user']); // 强制重新加载
                // 轮换 CSRF token
                rotate_csrf_token();

                // 注册成功，清除邮箱验证码
                clear_email_verification_code();

                set_flash(t('register_success', '注册成功！欢迎加入云界论坛。'), 'success');

                $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
                unset($_SESSION['redirect_after_login']);
                if (strpos($redirect, '/') !== 0 || strpos($redirect, '//') === 0) {
                    $redirect = 'index.php';
                }
                redirect($redirect);
            }
        }
    }
}

$pageTitle = t('register_title', '注册');
include APP_ROOT . 'app/includes/header.php';
?>

<div class="auth-container">
    <div class="card">
        <div class="auth-header">
            <img src="../public/images/logo.svg" alt="" class="auth-logo">
            <h1 class="auth-title"><?php echo e(t('register_create_account', '创建账号')); ?></h1>
            <p class="text-muted"><?php echo e(t('register_join_site', '加入 {site}', ['site' => SITE_NAME])); ?></p>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <?php echo show_message($err, 'error'); ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="POST" action="<?php echo site_url('register'); ?>" data-validate id="register-form">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="form-group">
                <label class="form-label" for="username"><?php echo e(t('register_label_username', '用户名')); ?></label>
                <input type="text" class="form-control" id="username" name="username" value="<?php echo e($username); ?>" autocomplete="username" required minlength="3" maxlength="32">
                <p class="form-hint"><?php echo e(t('register_username_hint', '3-32 个字符，支持中文、英文、数字和下划线')); ?></p>
            </div>
            <div class="form-group">
                <label class="form-label" for="email"><?php echo e(t('register_label_email', '邮箱')); ?></label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo e($email); ?>" autocomplete="email" required>
                <?php if (!email_verification_enabled()): ?>
                    <p class="form-hint"><?php echo e(t('register_email_hint', '请填写真实邮箱，将用于密码找回等重要操作。')); ?></p>
                <?php endif; ?>
            </div>
            <?php if (email_verification_enabled()): ?>
                <div class="form-group">
                    <label class="form-label" for="verification_code"><?php echo e(t('register_label_code', '邮箱验证码')); ?></label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" class="form-control" id="verification_code" name="verification_code" placeholder="<?php echo e(t('register_code_placeholder', '6 位数字验证码\"')); ?> maxlength="6" pattern="\d{6}" inputmode="numeric" style="flex: 1;">
                        <button type="button" class="btn btn-secondary" id="sendCodeBtn" style="white-space: nowrap;"><?php echo e(t('register_get_code', '获取验证码')); ?></button>
                    </div>
                    <p class="form-hint" id="codeHint"><?php echo e(t('register_code_hint_initial', '点击按钮发送验证码到邮箱。')); ?></p>
                </div>
            <?php endif; ?>
            <div class="form-group">
                <label class="form-label" for="password"><?php echo e(t('register_label_password', '密码')); ?></label>
                <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" required minlength="6">
            </div>
            <div class="form-group">
                <label class="form-label" for="password_confirm"><?php echo e(t('register_label_password_confirm', '确认密码')); ?></label>
                <input type="password" class="form-control" id="password_confirm" name="password_confirm" autocomplete="new-password" required minlength="6">
            </div>
            <div class="form-group">
                <label class="flex items-start gap-1" style="cursor: pointer; line-height: 1.5;">
                    <input type="checkbox" id="agree_terms" name="agree_terms" value="1">
                    <span style="font-size: 0.875rem;">
                        <?php echo e(t('register_agree_intro', '我已阅读并同意')); ?>
                        <a href="<?php echo site_url('terms'); ?>" target="_blank"><?php echo e(t('register_terms_text', '用户协议')); ?></a>
                        <?php echo e(t('register_and', '和')); ?>
                        <a href="<?php echo site_url('privacy'); ?>" target="_blank"><?php echo e(t('register_privacy_text', '隐私政策')); ?></a>
                    </span>
                </label>
                <div id="agree-terms-error" class="form-error" style="display: none; margin-top: 0.25rem; color: var(--error); font-size: 0.875rem;"><?php echo e(t('register_agree_error', '请阅读并同意用户协议与隐私政策。')); ?></div>
            </div>
            <button type="submit" class="btn btn-primary btn-block"><?php echo e(t('register_submit', '注册')); ?></button>
        </form>

        <script>
        (function () {
            var form = document.getElementById('register-form');
            var agreeCheckbox = document.getElementById('agree_terms');
            var agreeError = document.getElementById('agree-terms-error');
            if (!form || !agreeCheckbox) return;

            function hideAgreeError() {
                if (agreeError) agreeError.style.display = 'none';
                agreeCheckbox.classList.remove('is-invalid');
            }

            function showAgreeError() {
                if (agreeError) agreeError.style.display = 'block';
                agreeCheckbox.classList.add('is-invalid');
            }

            agreeCheckbox.addEventListener('change', hideAgreeError);

            form.addEventListener('submit', function (e) {
                if (!agreeCheckbox.checked) {
                    e.preventDefault();
                    showAgreeError();
                    agreeCheckbox.focus();
                    return false;
                }
                hideAgreeError();
            });
        })();
        </script>

        <?php if (email_verification_enabled()): ?>
        <script>
        (function() {
            var btn = document.getElementById('sendCodeBtn');
            var emailInput = document.getElementById('email');
            var hint = document.getElementById('codeHint');
            var csrf = document.querySelector('input[name="csrf_token"]').value;
            if (!btn || !emailInput) return;

            function setCountdown(seconds) {
                btn.disabled = true;
                btn.textContent = seconds + <?php echo json_encode(t('register_seconds_retry', '秒后重试')); ?>;
                hint.textContent = <?php echo json_encode(t('register_code_sent', '验证码已发送，请查收邮件。')); ?>;
                var t = setInterval(function() {
                    seconds--;
                    if (seconds <= 0) {
                        clearInterval(t);
                        btn.disabled = false;
                        btn.textContent = <?php echo json_encode(t('register_get_code', '获取验证码')); ?>;
                        hint.textContent = <?php echo json_encode(t('register_code_hint_initial', '点击按钮发送验证码到邮箱。')); ?>;
                    } else {
                        btn.textContent = seconds + <?php echo addslashes(t('register_21c325',' 秒后重试')); ?>;
                    }
                }, 1000);
            }

            btn.addEventListener('click', function() {
                var email = emailInput.value.trim();
                if (!email) {
                    hint.textContent = <?php echo json_encode(t('register_email_empty', '请先填写邮箱地址。')); ?>;
                    hint.style.color = 'var(--error)';
                    return;
                }
                if (btn.disabled) return;
                btn.disabled = true;
                hint.style.color = '';
                hint.textContent = <?php echo addslashes(t('register_a822c0','正在发送…')); ?>;

                var formData = new FormData();
                formData.append('email', email);
                formData.append('csrf_token', csrf);

                fetch('/index.php?route=send_email_code', {
                    method: 'POST',
                    body: formData
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.success) {
                        setCountdown(60);
                    } else {
                        btn.disabled = false;
                        hint.textContent = data.error || <?php echo json_encode(t('register_send_fail', '发送失败，请重试。')); ?>;
                        hint.style.color = 'var(--error)';
                    }
                }).catch(function() {
                    btn.disabled = false;
                    hint.textContent = <?php echo json_encode(t('register_network_error', '网络错误，请重试。')); ?>;
                    hint.style.color = 'var(--error)';
                });
            });
        })();
        </script>
        <?php endif; ?>

        <div class="auth-footer">
            <?php echo e(t('register_have_account', '已有账号？')); ?><a href="<?php echo site_url('login'); ?>"><?php echo e(t('register_login_now', '立即登录')); ?></a>
        </div>
    </div>
</div>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
