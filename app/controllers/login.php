<?php
/**
 * 云界论坛 - 用户登录
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

$errors = [];
$account = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ---- 阶段 1：安全校验（CSRF + 验证码），仅收集错误，不阻断后续流程 ----
    if (!validate_csrf()) {
        $errors[] = t('login_security_verify_fail', '安全验证失败，请刷新页面重试。');
    }
    // 验证码校验（仅当验证码启用时检查）
    if (captcha_enabled()) {
        if (!captcha_honeypot_ok($_POST)) {
            $errors[] = t('captcha_bot_detected', '验证未通过，请重试');
        } elseif (!captcha_passed($_POST['captcha_token'] ?? '')) {
            $errors[] = t('slider_captcha_fail', '请先完成人机验证。');
        }
    }

    // ---- 阶段 2：表单数据提取与基本校验 ----
    $account = trim($_POST['account'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);
    $agreeTerms = !empty($_POST['agree_terms']);

    if (empty($account) || empty($password)) {
        $errors[] = t('login_enter_account_password', '请输入账号和密码。');
    } elseif (!$agreeTerms) {
        $errors[] = t('login_agree_terms_required', '请阅读并同意用户协议与隐私政策。');
    }

    // ---- 阶段 3：登录锁定校验 ----
    // 连续失败达阈值（LOGIN_MAX_FAILS）后账号进入锁定期，锁定中直接拒绝登录。
    // 错误文案与「账号或密码错误」完全一致，不透露锁定状态，防止账号枚举。
    if (empty($errors) && login_lock_check($account)) {
        $errors[] = t('login_account_password_error', '账号或密码错误。');
    }

    // ---- 阶段 4：无错误时执行登录逻辑 ----
    if (empty($errors)) {
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(:account1) OR LOWER(email) = LOWER(:account2) LIMIT 1");
        $stmt->execute([':account1' => $account, ':account2' => $account]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            // 诊断用：写入错误日志，便于区分“账号不存在”还是“密码哈希不匹配”
            if (!$user) {
                error_log('[login] 账号不存在: ' . $account);
            } else {
                error_log('[login] 密码校验失败(非bcrypt或哈希损坏): user=' . $user['username']
                    . ' hash_prefix=' . substr($user['password'], 0, 8) . ' len=' . strlen($user['password']));
            }
            captcha_record_signal('login_fail');
            // 记录一次失败；达阈值后自动写入 15 分钟锁定
            login_lock_hit($account);
            $errors[] = t('login_account_password_error', '账号或密码错误。');
        } else {
            // 检查账号是否被封禁
            if (is_user_banned((int)$user['id'])) {
                $bannedUntilRaw = !empty($user['banned_until']) ? $user['banned_until'] : null;
                $until = $bannedUntilRaw ? date('Y-m-d H:i', db_time($bannedUntilRaw)) : t('login_409752','永久');
                $reason = !empty($user['status_reason']) ? $user['status_reason'] : '';
                $_SESSION['banned_info'] = [
                    'username' => $user['username'],
                    'until'   => $until,
                    'until_raw' => $bannedUntilRaw,
                    'reason'  => $reason,
                ];
                redirect('/banned');
            } else {
                // 登录成功：清零失败计数与锁定状态
                login_lock_clear($account);
                // 重新生成 session id，防止会话固定攻击
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                unset($_SESSION['user'], $_SESSION['banned_info']);
                // 轮换 CSRF token，防止登录前获取的 token 被复用
                rotate_csrf_token();

                // 管理员重置密码后，强制用户先修改密码
                if (!empty($user['force_password_change'])) {
                    redirect('/force_change_password');
                }

                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    $hash = hash('sha256', $token);
                    // 记住我：默认 400 天，可通过 COOKIE_REMEMBER_DAYS 调整
                    $expires = time() + COOKIE_REMEMBER_DAYS * 86400;
                    setcookie('forum_remember', $token, [
                        'expires'  => $expires,
                        'path'     => '/',
                        'secure'   => COOKIE_SECURE,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                    try {
                        $stmt = $db->prepare("UPDATE users SET remember_token = :hash WHERE id = :id");
                        $stmt->execute([':hash' => $hash, ':id' => $user['id']]);
                    } catch (Exception $e) {
                        error_log('remember_token update failed: ' . $e->getMessage());
                    }
                }

                set_flash(t('login_welcome_back', '欢迎回来，{name}！', ['name' => $user['username']]), 'success');

                captcha_clear_signals();

                $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
                unset($_SESSION['redirect_after_login']);
                // 防止开放重定向：仅允许相对路径
                if (strpos($redirect, '/') !== 0 || strpos($redirect, '//') === 0) {
                    $redirect = 'index.php';
                }
                redirect($redirect);
            }
        }
    }
}

$pageTitle = t('login_title', '登录');
include APP_ROOT . 'app/includes/header.php';
?>

<div class="auth-container">
    <div class="card auth-card">
        <div class="auth-header">
            <img src="../public/images/logo.svg" alt="" class="auth-logo">
            <h1 class="auth-title"><?php echo e(t('login_welcome_title', '欢迎回来')); ?></h1>
            <p class="text-muted"><?php echo e(t('login_login_site', '登录 {site}', ['site' => SITE_NAME])); ?></p>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <?php echo show_message($err, 'error'); ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="POST" action="<?php echo site_url('login'); ?>" data-validate id="login-form">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="form-group">
                <label class="form-label" for="account"><?php echo e(t('login_label_account', '用户名或邮箱')); ?></label>
                <input type="text" class="form-control" id="account" name="account" value="<?php echo e($account); ?>" autocomplete="username" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="password"><?php echo e(t('login_label_password', '密码')); ?></label>
                <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
            </div>
            <div class="form-group">
                <label class="flex items-center gap-1 auth-check">
                    <input type="checkbox" id="remember" name="remember" value="1">
                    <span><?php echo e(t('login_remember_me', '保持登录')); ?></span>
                </label>
            </div>
            <div class="form-group">
                <label class="flex items-start gap-1 auth-check">
                    <input type="checkbox" id="agree_terms" name="agree_terms" value="1">
                    <span class="auth-agree-text">
                        <?php echo e(t('login_agree_intro', '我已阅读并同意')); ?>
                        <a href="<?php echo site_url('terms'); ?>" target="_blank"><?php echo e(t('login_terms_text', '用户协议')); ?></a>
                        <?php echo e(t('login_and', '和')); ?>
                        <a href="<?php echo site_url('privacy'); ?>" target="_blank"><?php echo e(t('login_privacy_text', '隐私政策')); ?></a>
                    </span>
                </label>
                <div id="agree-terms-error" class="form-error is-initially-hidden"><?php echo e(t('login_agree_error', '请阅读并同意用户协议与隐私政策。')); ?></div>
            </div>
            <?php if (captcha_enabled()): ?>
            <div class="form-group" data-captcha-wrap>
                <div id="captcha" data-api="<?php echo site_url('api/captcha'); ?>" data-display="<?php echo e(captcha_display()); ?>"></div>
                <input type="hidden" name="captcha_token" id="captcha_token" value="">
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-block"><?php echo e(t('login_submit', '登录')); ?></button>
        </form>

        <script>
        (function () {
            // 安全升级后的存量清理：旧版「记住账号密码」会把明文凭据加密后存入 localStorage，
            // 加密密钥由非 HttpOnly cookie（forum_cred_key）下发，任何 XSS 即可解密还原明文，
            // 该机制已整体移除，「保持登录」改由服务端 HttpOnly cookie（forum_remember）实现。
            // 此处一次性清除客户端残留的密文与密钥 cookie。
            try { localStorage.removeItem('forum_credentials'); } catch (e) {}
            document.cookie = 'forum_cred_key=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';

            var form = document.getElementById('login-form');
            var agreeCheckbox = document.getElementById('agree_terms');
            var agreeError = document.getElementById('agree-terms-error');

            if (!form) return;

            function hideAgreeError() {
                if (agreeError) agreeError.style.display = 'none';
                if (agreeCheckbox) agreeCheckbox.classList.remove('is-invalid');
            }

            function showAgreeError() {
                if (agreeError) agreeError.style.display = 'block';
                if (agreeCheckbox) agreeCheckbox.classList.add('is-invalid');
            }

            if (agreeCheckbox) agreeCheckbox.addEventListener('change', hideAgreeError);

            form.addEventListener('submit', function (e) {
                if (agreeCheckbox && !agreeCheckbox.checked) {
                    e.preventDefault();
                    showAgreeError();
                    agreeCheckbox.focus();
                    return false;
                }
                hideAgreeError();
            });
        })();
        </script>

        <div class="auth-footer">
            <?php echo e(t('login_no_account', '还没有账号？')); ?><a href="<?php echo site_url('register'); ?>"><?php echo e(t('login_register_now', '立即注册')); ?></a>
            <span class="text-muted">·</span>
            <a href="<?php echo site_url('forgot_password'); ?>"><?php echo e(t('login_forgot_password', '忘记密码')); ?></a>
        </div>
    </div>
</div>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
