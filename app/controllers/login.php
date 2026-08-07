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
$credKey = get_remember_credentials_key();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = t('login_security_verify_fail', '安全验证失败，请刷新页面重试。');
    } elseif (captcha_enabled() && !captcha_passed($_POST['captcha_token'] ?? '')) {
        $errors[] = t('slider_captcha_fail', '请先完成人机验证。');
    } else {
        $account = trim($_POST['account'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);
        $agreeTerms = !empty($_POST['agree_terms']);

        if (empty($account) || empty($password)) {
            $errors[] = t('login_enter_account_password', '请输入账号和密码。');
        } elseif (!$agreeTerms) {
            $errors[] = t('login_agree_terms_required', '请阅读并同意用户协议与隐私政策。');
        } else {
            $db = get_db();
            $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(:account1) OR LOWER(email) = LOWER(:account2) LIMIT 1");
            $stmt->execute([':account1' => $account, ':account2' => $account]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                captcha_record_signal('login_fail');
                $errors[] = t('login_account_password_error', '账号或密码错误。');
            } else {
                // 检查账号是否被封禁
                if (is_user_banned((int)$user['id'])) {
                    $bannedUntilRaw = !empty($user['banned_until']) ? $user['banned_until'] : null;
                    $until = $bannedUntilRaw ? date('Y-m-d H:i', db_time($bannedUntilRaw)) : t('login_409752','永久');
                    $reason = !empty($user['status_reason']) ? $user['status_reason'] : '';
                    $_SESSION['banned_info'] = [
                        'username' => $user['username'],
                        'until' => $until,
                        'until_raw' => $bannedUntilRaw,
                        'reason' => $reason,
                    ];
                    redirect('/banned');
                } else {
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
                            'expires' => $expires,
                            'path' => '/',
                            'secure' => COOKIE_SECURE,
                            'httponly' => true,
                            'samesite' => 'Lax',
                        ]);
                        try {
                            $stmt = $db->prepare("UPDATE users SET remember_token = :hash WHERE id = :id");
                            $stmt->execute([':hash' => $hash, ':id' => $user['id']]);
                        } catch (Exception $e) {
                            // 数据库写入失败时保留 cookie，下次登录会重新生成；仅记录日志避免阻断登录
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
}

$pageTitle = t('login_title', '登录');
include APP_ROOT . 'app/includes/header.php';
?>

<div class="auth-container">
    <div class="card">
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
                <label class="flex items-center gap-1" style="cursor: pointer;">
                    <input type="checkbox" id="remember" name="remember" value="1">
                    <span><?php echo e(t('login_remember', '记住账号密码并保持登录')); ?></span>
                </label>
            </div>
            <div class="form-group">
                <label class="flex items-start gap-1" style="cursor: pointer; line-height: 1.5;">
                    <input type="checkbox" id="agree_terms" name="agree_terms" value="1">
                    <span style="font-size: 0.875rem;">
                        <?php echo e(t('login_agree_intro', '我已阅读并同意')); ?>
                        <a href="<?php echo site_url('terms'); ?>" target="_blank"><?php echo e(t('login_terms_text', '用户协议')); ?></a>
                        <?php echo e(t('login_and', '和')); ?>
                        <a href="<?php echo site_url('privacy'); ?>" target="_blank"><?php echo e(t('login_privacy_text', '隐私政策')); ?></a>
                    </span>
                </label>
                <div id="agree-terms-error" class="form-error" style="display: none; margin-top: 0.25rem; color: var(--error); font-size: 0.875rem;"><?php echo e(t('login_agree_error', '请阅读并同意用户协议与隐私政策。')); ?></div>
            </div>
            <?php $capRender = captcha_enabled() && (should_trigger_captcha('login') || in_array(captcha_display(), ['popup', 'trigger'], true)); ?>
        <?php if ($capRender): ?>
            <div class="form-group" data-captcha-wrap>
                <?php if (captcha_enabled() && !should_trigger_captcha('login') && captcha_display() !== 'popup' && captcha_display() !== 'trigger'): ?>
                    <!-- 无需触发且非弹窗/触发模式：占位空行 -->
                    <span class="text-muted" style="font-size:12px;"><?php echo e(t('captcha_not_required', '本次操作无需验证')); ?></span>
                <?php elseif (captcha_enabled()): ?>
                    <div id="captcha" data-api="<?php echo site_url('api/captcha'); ?>" data-display="<?php echo e(captcha_display()); ?>"></div>
                    <input type="hidden" name="captcha_token" id="captcha_token" value="">
                <?php endif; ?>
            </div>
        <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-block"><?php echo e(t('login_submit', '登录')); ?></button>
        </form>

        <script>
        (function () {
            var form = document.getElementById('login-form');
            var accountInput = document.getElementById('account');
            var passwordInput = document.getElementById('password');
            var rememberCheckbox = document.getElementById('remember');
            var agreeCheckbox = document.getElementById('agree_terms');
            var agreeError = document.getElementById('agree-terms-error');
            var rawKey = <?php echo json_encode($credKey, JSON_UNESCAPED_UNICODE); ?>;
            var storageKey = 'forum_credentials';

            if (!form || !accountInput || !passwordInput) return;

            function hideAgreeError() {
                if (agreeError) agreeError.style.display = 'none';
                if (agreeCheckbox) agreeCheckbox.classList.remove('is-invalid');
            }

            function showAgreeError() {
                if (agreeError) agreeError.style.display = 'block';
                if (agreeCheckbox) agreeCheckbox.classList.add('is-invalid');
            }

            if (agreeCheckbox) agreeCheckbox.addEventListener('change', hideAgreeError);

            if (rememberCheckbox) {
                rememberCheckbox.addEventListener('change', function () {
                    if (!rememberCheckbox.checked) {
                        localStorage.removeItem(storageKey);
                    }
                });
            }

            function bufferToBase64(buffer) {
                var bytes = new Uint8Array(buffer);
                var binary = '';
                for (var i = 0; i < bytes.byteLength; i++) {
                    binary += String.fromCharCode(bytes[i]);
                }
                return btoa(binary);
            }

            function base64ToBuffer(base64) {
                var binary = atob(base64);
                var bytes = new Uint8Array(binary.length);
                for (var i = 0; i < binary.length; i++) {
                    bytes[i] = binary.charCodeAt(i);
                }
                return bytes;
            }

            function getKey() {
                var enc = new TextEncoder();
                return crypto.subtle.digest('SHA-256', enc.encode(rawKey)).then(function (hash) {
                    return crypto.subtle.importKey('raw', hash, {name: 'AES-GCM'}, false, ['encrypt', 'decrypt']);
                });
            }

            function encrypt(text, key) {
                var iv = crypto.getRandomValues(new Uint8Array(12));
                var enc = new TextEncoder();
                return crypto.subtle.encrypt({name: 'AES-GCM', iv: iv}, key, enc.encode(text)).then(function (ciphertext) {
                    return JSON.stringify({
                        iv: bufferToBase64(iv),
                        data: bufferToBase64(ciphertext)
                    });
                });
            }

            function decrypt(payload, key) {
                var parsed = JSON.parse(payload);
                var iv = base64ToBuffer(parsed.iv);
                var data = base64ToBuffer(parsed.data);
                return crypto.subtle.decrypt({name: 'AES-GCM', iv: iv}, key, data).then(function (plain) {
                    return new TextDecoder().decode(plain);
                });
            }

            function saveCredentials() {
                if (!rememberCheckbox || !rememberCheckbox.checked) return Promise.resolve();
                var account = accountInput.value;
                var password = passwordInput.value;
                if (!account || !password) return Promise.resolve();
                return getKey().then(function (key) {
                    return Promise.all([
                        encrypt(account, key),
                        encrypt(password, key)
                    ]);
                }).then(function (results) {
                    localStorage.setItem(storageKey, JSON.stringify({
                        account: results[0],
                        password: results[1]
                    }));
                });
            }

            function loadCredentials() {
                var stored = localStorage.getItem(storageKey);
                if (!stored) return;
                getKey().then(function (key) {
                    var parsed = JSON.parse(stored);
                    return Promise.all([
                        decrypt(parsed.account, key),
                        decrypt(parsed.password, key)
                    ]);
                }).then(function (values) {
                    accountInput.value = values[0];
                    passwordInput.value = values[1];
                    if (rememberCheckbox) rememberCheckbox.checked = true;
                }).catch(function () {
                    // 解密失败（如密钥变更）则清除旧数据
                    localStorage.removeItem(storageKey);
                });
            }

            loadCredentials();

            form.addEventListener('submit', function (e) {
                if (agreeCheckbox && !agreeCheckbox.checked) {
                    e.preventDefault();
                    showAgreeError();
                    agreeCheckbox.focus();
                    return false;
                }
                hideAgreeError();

                if (rememberCheckbox && rememberCheckbox.checked) {
                    e.preventDefault();
                    saveCredentials().then(function () {
                        form.submit();
                    }).catch(function () {
                        form.submit();
                    });
                    return false;
                }
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
