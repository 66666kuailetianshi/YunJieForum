<?php
/**
 * 云界论坛 - 个人中心
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';
require_once APP_ROOT . 'app/components/sensitive_filter/helper.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

require_login();

$db = get_db();
$currentUser = current_user();
$viewUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// 通过 user_id 查看他人资料；未指定时查看自己
if ($viewUserId > 0 && $viewUserId !== (int)$currentUser['id']) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $viewUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        set_flash(t('profile_user_not_found', '用户不存在。'), 'error');
        redirect('/');
    }
    $isOwnProfile = false;
} else {
    $user = $currentUser;
    $isOwnProfile = true;
}

$tab = $_GET['tab'] ?? 'profile';
$tab = in_array($tab, ['profile', 'security', 'posts', 'replies', 'checkins', 'favorites', 'points']) ? $tab : 'profile';

// 查看他人资料时只允许浏览型标签
if (!$isOwnProfile && !in_array($tab, ['profile', 'posts', 'replies'])) {
    $tab = 'profile';
}

// 积分流水
$pointsLog = get_user_points_log($user['id'], 50);

$errors = [];
$success = '';

// 处理基本资料更新（用户名、头像、签名）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'profile') {
    if (!validate_csrf()) {
        $errors[] = t('profile_csrf_failed', '安全验证失败，请刷新页面重试。');
    } else {
        $username = trim($_POST['username'] ?? '');
        $avatar = trim($_POST['avatar'] ?? '');
        $signature = trim($_POST['signature'] ?? '');

        // 处理本地上传头像
        $uploadedAvatar = '';
        if (!empty($_FILES['avatar_file']['tmp_name'])) {
            $file = $_FILES['avatar_file'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = t('profile_avatar_upload_failed', '头像上传失败，请重试。');
            } elseif (!in_array($file['type'], $allowedTypes, true) || !in_array($ext, $allowedExts, true)) {
                $errors[] = t('profile_avatar_type_invalid', '头像仅支持 JPG、PNG、GIF、WEBP 格式。');
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = t('profile_avatar_too_large', '头像文件大小不能超过 2MB。');
            } else {
                if (!is_dir(AVATAR_PATH)) {
                    if (!mkdir(AVATAR_PATH, 0755, true) && !is_dir(AVATAR_PATH)) {
                        $errors[] = t('profile_avatar_dir_failed', '无法创建头像上传目录。');
                    }
                }
                if (empty($errors)) {
                    $filename = 'avatar_' . $user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $destPath = AVATAR_PATH . $filename;
                    if (move_uploaded_file($file['tmp_name'], $destPath)) {
                        // 删除旧头像文件
                        if (!empty($user['avatar']) && preg_match('#^uploads/avatars/#i', $user['avatar'])) {
                            $oldPath = ROOT_PATH . str_replace('/', DIRECTORY_SEPARATOR, $user['avatar']);
                            if (is_file($oldPath)) {
                                @unlink($oldPath);
                            }
                        }
                        $uploadedAvatar = AVATAR_URL . $filename;
                    } else {
                        $errors[] = t('profile_avatar_save_failed', '头像保存失败，请重试。');
                    }
                }
            }
        }

        // 验证用户名
        if (empty($username) || mb_strlen($username) < 3 || mb_strlen($username) > 32) {
            $errors[] = t('profile_username_length', '用户名长度必须在 3-32 个字符之间。');
        } elseif (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
            $errors[] = t('profile_username_charset', '用户名只能包含中文、英文、数字和下划线。');
        } elseif ($username !== $user['username']) {
            // 检查用户名是否已被占用
            $check = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username) AND id != :id LIMIT 1");
            $check->execute([':username' => $username, ':id' => $user['id']]);
            if ($check->fetch()) {
                $errors[] = t('profile_username_taken', '该用户名已被占用，请换一个。');
            }
        }

        // 优先使用本地上传的头像
        if ($uploadedAvatar !== '') {
            $avatar = $uploadedAvatar;
        }

        // 头像 URL 校验：允许 http/https 外链、本地上传路径，或留空
        if ($avatar !== '' && !preg_match('#^(https?://|uploads/avatars/)#i', $avatar)) {
            $errors[] = t('profile_avatar_url_invalid', '头像地址格式不正确。');
        }
        if (strlen($avatar) > 500) {
            $errors[] = t('profile_avatar_url_too_long', '头像地址过长。');
        }

        if (mb_strlen($signature) > 255) {
            $errors[] = t('profile_signature_too_long', '个性签名不能超过 255 个字符。');
        }

        // 敏感词过滤签名
        $processedSignature = sw_process_content($signature, 'signature', (int)$_SESSION['user_id'], null, $errors);

        if (empty($errors)) {
            $stmt = $db->prepare("UPDATE users SET username = :username, avatar = :avatar, signature = :signature WHERE id = :id");
            $stmt->execute([
                ':username' => $username,
                ':avatar' => $avatar,
                ':signature' => $processedSignature,
                ':id' => $_SESSION['user_id'],
            ]);
            unset($_SESSION['user']); // 刷新缓存
            set_flash(t('profile_updated', '个人资料已更新。'), 'success');
            redirect('/profile?tab=profile');
        }
    }
}

// 处理账号安全更新（邮箱、密码）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'security') {
    if (!validate_csrf()) {
        $errors[] = t('profile_csrf_failed', '安全验证失败，请刷新页面重试。');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_email') {
            // 修改邮箱
            $email = trim($_POST['email'] ?? '');
            $currentPassword = $_POST['current_password'] ?? '';

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = t('profile_email_invalid', '请输入有效的邮箱地址。');
            }
            if (!password_verify($currentPassword, $user['password'])) {
                $errors[] = t('profile_current_password_wrong', '当前密码不正确。');
            }
            if ($email !== $user['email'] && empty($errors)) {
                $check = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:email) AND id != :id LIMIT 1");
                $check->execute([':email' => $email, ':id' => $user['id']]);
                if ($check->fetch()) {
                    $errors[] = t('profile_email_taken', '该邮箱已被其他账号使用。');
                }
            }

            if (empty($errors)) {
                $stmt = $db->prepare("UPDATE users SET email = :email WHERE id = :id");
                $stmt->execute([':email' => $email, ':id' => $_SESSION['user_id']]);
                unset($_SESSION['user']);
                set_flash(t('profile_email_updated', '邮箱已更新。'), 'success');
                redirect('/profile?tab=security');
            }
        } elseif ($action === 'update_password') {
            // 修改密码
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            $verificationCode = trim($_POST['verification_code'] ?? '');

            if (!password_verify($currentPassword, $user['password'])) {
                $errors[] = t('profile_current_password_wrong', '当前密码不正确。');
            }
            if (strlen($newPassword) < 6) {
                $errors[] = t('profile_password_too_short', '新密码长度不能少于 6 位。');
            } elseif (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
                $errors[] = t('profile_password_need_letter_digit', '密码必须同时包含字母和数字。');
            }
            if ($newPassword !== $confirmPassword) {
                $errors[] = t('profile_password_mismatch', '两次输入的新密码不一致。');
            }
            if (password_verify($newPassword, $user['password'])) {
                $errors[] = t('profile_password_same_as_old', '新密码不能与旧密码相同。');
            }

            // 邮箱验证码校验（SMTP 启用时）
            if (empty($errors) && email_verification_enabled()) {
                $userEmail = $user['email'] ?? '';
                if (empty($userEmail)) {
                    $errors[] = t('profile_no_email_bound', '您的账号未绑定邮箱，无法进行验证。');
                } elseif (empty($verificationCode)) {
                    $errors[] = t('profile_code_required', '请输入邮箱验证码。');
                } elseif (!validate_password_change_email_code($userEmail, $verificationCode)) {
                    $errors[] = t('profile_code_invalid', '验证码错误或已过期。');
                }
            }

            if (empty($errors)) {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                // 改密码后清除 remember_token，防止旧令牌继续可用
                $stmt = $db->prepare("UPDATE users SET password = :password, remember_token = NULL WHERE id = :id");
                $stmt->execute([':password' => $hash, ':id' => $_SESSION['user_id']]);
                // 清空用户缓存，确保后续验证使用新密码哈希
                unset($_SESSION['user']);
                // 重新生成 session id，防止会话固定
                session_regenerate_id(true);
                // 轮换 CSRF token
                rotate_csrf_token();
                // 清除密码修改验证码
                clear_password_change_email_code();
                set_flash(t('profile_password_updated', '密码已更新，请使用新密码登录。'), 'success');
                redirect('/profile?tab=security');
            }
        } elseif ($action === 'update_security_question') {
            // 设置/修改密保问题
            $currentPassword = $_POST['current_password'] ?? '';
            $securityQuestion = trim($_POST['security_question'] ?? '');
            $securityAnswer = trim($_POST['security_answer'] ?? '');

            if (!password_verify($currentPassword, $user['password'])) {
                $errors[] = t('profile_current_password_wrong', '当前密码不正确。');
            }
            if (mb_strlen($securityQuestion) < 3 || mb_strlen($securityQuestion) > 255) {
                $errors[] = t('profile_sq_question_length', '密保问题长度需在 3-255 个字符之间。');
            }
            if (mb_strlen($securityAnswer) < 1 || mb_strlen($securityAnswer) > 255) {
                $errors[] = t('profile_sq_answer_required', '请输入密保答案。');
            }

            if (empty($errors)) {
                $answerHash = password_hash($securityAnswer, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET security_question = :question, security_answer_hash = :answer WHERE id = :id");
                $stmt->execute([
                    ':question' => $securityQuestion,
                    ':answer'   => $answerHash,
                    ':id'       => $_SESSION['user_id'],
                ]);
                unset($_SESSION['user']);
                set_flash(t('profile_sq_saved', '密保问题已设置。'), 'success');
                redirect('/profile?tab=security');
            }
        } elseif ($action === 'remove_security_question') {
            // 清除密保问题
            $currentPassword = $_POST['current_password'] ?? '';
            if (!password_verify($currentPassword, $user['password'])) {
                $errors[] = t('profile_current_password_wrong', '当前密码不正确。');
            } else {
                $stmt = $db->prepare("UPDATE users SET security_question = NULL, security_answer_hash = NULL WHERE id = :id");
                $stmt->execute([':id' => $_SESSION['user_id']]);
                unset($_SESSION['user']);
                set_flash(t('profile_sq_removed', '密保问题已清除。'), 'success');
                redirect('/profile?tab=security');
            }
        }
    }
}

// 获取用户角色与勋章
$stmt = $db->prepare("SELECT r.display_name FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = :user_id");
$stmt->execute([':user_id' => $user['id']]);
$roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $db->prepare("SELECT m.* FROM medals m JOIN user_medals um ON m.id = um.medal_id WHERE um.user_id = :user_id ORDER BY um.awarded_at DESC");
$stmt->execute([':user_id' => $user['id']]);
$medals = $stmt->fetchAll();

// 统计数据
$stmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE user_id = :user_id");
$stmt->execute([':user_id' => $user['id']]);
$postCount = (int)$stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM replies WHERE user_id = :user_id");
$stmt->execute([':user_id' => $user['id']]);
$replyCount = (int)$stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = :user_id");
$stmt->execute([':user_id' => $user['id']]);
$favoriteCount = (int)$stmt->fetchColumn();

// 用户等级头衔（统一使用 posts_count + replies_count 口径）
$userGroup = get_user_group((int)$user['points']);

$pageTitle = $isOwnProfile ? t('profile_page_title', '个人中心') : t('profile_page_title_other', '{name} 的资料', ['name' => e($user['username'])]);
$extraStyles = ['/public/css/profile.css'];
include APP_ROOT . 'app/includes/header.php';
?>

<div class="profile-hero">
    <div class="profile-hero-top">
        <div class="profile-avatar-wrap">
            <img src="<?php echo avatar_url($user['avatar'], $user['username']); ?>" alt="" class="profile-avatar">
            <span class="profile-level-badge" style="background: <?php echo e($userGroup['color']); ?>;">
                <?php echo ui_icon($userGroup['icon'], 12); ?>
                <?php echo e($userGroup['title']); ?>
            </span>
        </div>
        <div class="profile-meta">
            <div class="profile-name-row">
                <h1 class="profile-name"><?php echo e($user['username']); ?></h1>
                <?php if ($user['role'] === 'admin'): ?>
                    <span class="profile-tag profile-tag-admin"><?php echo e(t('profile_tag_admin', '管理员')); ?></span>
                <?php endif; ?>
                <span class="profile-uid">UID: <?php echo e($user['uid']); ?></span>
            </div>
            <p class="profile-signature"><?php echo ($user['signature'] !== '' && $user['signature'] !== null) ? e($user['signature']) : e(t('profile_no_signature', '这个人很懒，什么都没写～')); ?></p>
            <?php if (!empty($roles) || !empty($medals)): ?>
                <div class="profile-tags">
                    <?php foreach ($roles as $roleName): ?>
                        <span class="profile-tag"><?php echo e($roleName); ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($medals as $medal): ?>
                        <span class="profile-medal" style="color: <?php echo e($medal['color']); ?>; border-color: <?php echo e($medal['color']); ?>" title="<?php echo e($medal['description']); ?>">
                            <?php echo ui_icon($medal['icon'], 12); ?>
                            <?php echo e($medal['display_name']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="profile-actions">
            <?php if (!$isOwnProfile && $currentUser): ?>
                <a href="<?php echo site_url('pm', ['action' => 'new', 'to' => (int)$user['id']]); ?>" class="btn btn-primary"><?php echo e(t('profile_send_pm', '发私信')); ?></a>
            <?php endif; ?>
            <?php if ($isOwnProfile): ?>
                <?php if ($user['last_checkin'] !== date('Y-m-d')): ?>
                    <a href="<?php echo site_url('checkin', ['csrf_token' => csrf_token()]); ?>" class="btn btn-primary"><?php echo e(t('profile_daily_checkin', '每日签到')); ?></a>
                <?php else: ?>
                    <span class="btn btn-checked-in"><?php echo e(t('profile_checked_in_today', '今日已签到')); ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="profile-stats-bar">
        <div class="profile-stat">
            <div class="profile-stat-value"><?php echo (int)$user['points']; ?></div>
            <div class="profile-stat-label"><?php echo e(t('profile_stat_points', '积分')); ?></div>
        </div>
        <div class="profile-stat">
            <div class="profile-stat-value"><?php echo (int)($user['coins'] ?? 0); ?></div>
            <div class="profile-stat-label"><?php echo e(t('profile_stat_coins', '金币')); ?></div>
        </div>
        <div class="profile-stat">
            <div class="profile-stat-value"><?php echo (int)$user['checkin_streak']; ?></div>
            <div class="profile-stat-label"><?php echo e(t('profile_stat_streak', '连续签到')); ?></div>
        </div>
        <div class="profile-stat">
            <div class="profile-stat-value"><?php echo $postCount; ?></div>
            <div class="profile-stat-label"><?php echo e(t('profile_stat_posts', '帖子')); ?></div>
        </div>
        <div class="profile-stat">
            <div class="profile-stat-value"><?php echo $replyCount; ?></div>
            <div class="profile-stat-label"><?php echo e(t('profile_stat_replies', '回复')); ?></div>
        </div>
        <div class="profile-stat">
            <div class="profile-stat-value"><?php echo $favoriteCount; ?></div>
            <div class="profile-stat-label"><?php echo e(t('profile_stat_favorites', '收藏')); ?></div>
        </div>
    </div>
</div>

<div class="profile-tabs">
    <?php if ($isOwnProfile): ?>
        <a href="<?php echo site_url('profile', ['tab' => 'profile']); ?>" class="profile-tab <?php echo $tab === 'profile' ? 'active' : ''; ?>"><?php echo e(t('profile_tab_edit', '编辑资料')); ?></a>
        <a href="<?php echo site_url('profile', ['tab' => 'security']); ?>" class="profile-tab <?php echo $tab === 'security' ? 'active' : ''; ?>"><?php echo e(t('profile_tab_security', '账号安全')); ?></a>
    <?php else: ?>
        <a href="<?php echo site_url('profile', ['user_id' => (int)$user['id'], 'tab' => 'profile']); ?>" class="profile-tab <?php echo $tab === 'profile' ? 'active' : ''; ?>"><?php echo e(t('profile_tab_profile', '资料')); ?></a>
    <?php endif; ?>
    <a href="<?php echo site_url('profile', $isOwnProfile ? ['tab' => 'posts'] : ['user_id' => (int)$user['id'], 'tab' => 'posts']); ?>" class="profile-tab <?php echo $tab === 'posts' ? 'active' : ''; ?>"><?php echo e($isOwnProfile ? t('profile_tab_my_posts', '我的帖子') : t('profile_tab_their_posts', 'TA 的帖子')); ?></a>
    <a href="<?php echo site_url('profile', $isOwnProfile ? ['tab' => 'replies'] : ['user_id' => (int)$user['id'], 'tab' => 'replies']); ?>" class="profile-tab <?php echo $tab === 'replies' ? 'active' : ''; ?>"><?php echo e($isOwnProfile ? t('profile_tab_my_replies', '我的回复') : t('profile_tab_their_replies', 'TA 的回复')); ?></a>
    <?php if ($isOwnProfile): ?>
        <a href="<?php echo site_url('profile', ['tab' => 'favorites']); ?>" class="profile-tab <?php echo $tab === 'favorites' ? 'active' : ''; ?>"><?php echo e(t('profile_tab_my_favorites', '我的收藏')); ?></a>
        <a href="<?php echo site_url('profile', ['tab' => 'checkins']); ?>" class="profile-tab <?php echo $tab === 'checkins' ? 'active' : ''; ?>"><?php echo e(t('profile_tab_checkins', '签到记录')); ?></a>
        <a href="<?php echo site_url('profile', ['tab' => 'points']); ?>" class="profile-tab <?php echo $tab === 'points' ? 'active' : ''; ?>"><?php echo e(t('profile_tab_points', '积分记录')); ?></a>
    <?php endif; ?>
</div>

<?php if ($tab === 'profile'): ?>
    <div class="profile-card">
        <h2 class="profile-card-title"><?php echo e($isOwnProfile ? t('profile_tab_edit', '编辑资料') : t('profile_card_user_info', '用户资料')); ?></h2>
        <?php if ($isOwnProfile): ?>
            <p class="text-muted mb-2"><?php echo e(t('profile_edit_desc', '修改你的用户名、头像和个性签名。')); ?></p>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <?php echo show_message($err, 'error'); ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($isOwnProfile): ?>
            <form method="POST" action="<?php echo site_url('profile', ['tab' => 'profile']); ?>" enctype="multipart/form-data" data-validate>
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="form-group">
                    <label class="form-label" for="username"><?php echo e(t('profile_label_username', '用户名')); ?></label>
                    <input type="text" class="form-control" id="username" name="username" value="<?php echo e($user['username']); ?>" required minlength="3" maxlength="32">
                    <p class="form-hint"><?php echo e(t('profile_username_hint', '3-32 个字符，支持中文、英文、数字和下划线')); ?></p>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('profile_label_avatar', '头像')); ?></label>
                    <div class="avatar-preview" style="margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.75rem;">
                        <img id="avatar-preview-img" src="<?php echo avatar_url($user['avatar'], $user['username']); ?>" alt="" class="avatar avatar-md">
                        <span class="text-muted" style="font-size: 0.875rem;"><?php echo e(t('profile_current_avatar', '当前头像')); ?></span>
                    </div>
                    <input type="file" class="form-control" id="avatar_file" name="avatar_file" accept="image/jpeg,image/png,image/gif,image/webp">
                    <p class="form-hint"><?php echo e(t('profile_avatar_hint', '支持 JPG、PNG、GIF、WEBP，大小不超过 2MB。上传新头像会替换当前头像。')); ?></p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="avatar"><?php echo e(t('profile_label_avatar_url', '或填写头像 URL')); ?></label>
                    <input type="url" class="form-control" id="avatar" name="avatar" value="<?php echo e($user['avatar']); ?>" placeholder="<?php echo e(t('profile_avatar_url_placeholder', '留空则使用首字母头像\"')); ?>>
                    <p class="form-hint"><?php echo e(t('profile_avatar_url_hint', '输入图片链接地址；若本地上传了新头像，此地址将被忽略。')); ?></p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="signature"><?php echo e(t('profile_label_signature', '个性签名')); ?></label>
                    <input type="text" class="form-control" id="signature" name="signature" value="<?php echo e($user['signature']); ?>" maxlength="255" placeholder="<?php echo e(t('profile_signature_placeholder', '写点什么...\"')); ?>>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo e(t('profile_save', '保存修改')); ?></button>
            </form>
        <?php else: ?>
            <div class="read-only-profile">
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('profile_label_username', '用户名')); ?></label>
                    <p><?php echo e($user['username']); ?></p>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('profile_label_avatar', '头像')); ?></label>
                    <div class="avatar-preview" style="display: flex; align-items: center; gap: 0.75rem;">
                        <img src="<?php echo avatar_url($user['avatar'], $user['username']); ?>" alt="" class="avatar avatar-md">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('profile_label_signature', '个性签名')); ?></label>
                    <p><?php echo ($user['signature'] !== '' && $user['signature'] !== null) ? e($user['signature']) : e(t('profile_no_signature', '这个人很懒，什么都没写～')); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'security' && $isOwnProfile): ?>
    <div class="profile-card">
        <h2 class="profile-card-title"><?php echo e(t('profile_change_email', '修改邮箱')); ?></h2>
        <p class="text-muted mb-2"><?php echo e(t('profile_change_email_desc', '需要验证当前密码才能修改邮箱地址。')); ?></p>
        <?php if (!empty($errors) && ($_POST['action'] ?? '') === 'update_email'): ?>
            <?php foreach ($errors as $err): ?>
                <?php echo show_message($err, 'error'); ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <form method="POST" action="<?php echo site_url('profile', ['tab' => 'security']); ?>" data-validate>
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="update_email">
            <div class="form-group">
                <label class="form-label" for="email"><?php echo e(t('profile_label_email', '邮箱地址')); ?></label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo e($user['email']); ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="email_current_password"><?php echo e(t('profile_label_current_password', '当前密码')); ?></label>
                <input type="password" class="form-control" id="email_current_password" name="current_password" required>
                <p class="form-hint"><?php echo e(t('profile_current_password_hint', '出于安全考虑，请输入当前密码以确认操作。')); ?></p>
            </div>
            <button type="submit" class="btn btn-primary"><?php echo e(t('profile_update_email_btn', '更新邮箱')); ?></button>
        </form>
    </div>

    <div class="profile-card">
        <h2 class="profile-card-title"><?php echo e(t('profile_change_password', '修改密码')); ?></h2>
        <p class="text-muted mb-2"><?php echo e(t('profile_change_password_desc', '建议定期更换密码以保障账号安全。')); ?></p>
        <?php if (!empty($errors) && ($_POST['action'] ?? '') === 'update_password'): ?>
            <?php foreach ($errors as $err): ?>
                <?php echo show_message($err, 'error'); ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <form method="POST" action="<?php echo site_url('profile', ['tab' => 'security']); ?>" data-validate id="passwordChangeForm">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="update_password">
            <div class="form-group">
                <label class="form-label" for="pwd_current"><?php echo e(t('profile_label_current_password', '当前密码')); ?></label>
                <input type="password" class="form-control" id="pwd_current" name="current_password" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="pwd_new"><?php echo e(t('profile_label_new_password', '新密码')); ?></label>
                <input type="password" class="form-control" id="pwd_new" name="new_password" required minlength="6">
                <p class="form-hint"><?php echo e(t('profile_password_hint', '至少 6 个字符')); ?></p>
            </div>
            <div class="form-group">
                <label class="form-label" for="pwd_confirm"><?php echo e(t('profile_label_confirm_password', '确认新密码')); ?></label>
                <input type="password" class="form-control" id="pwd_confirm" name="confirm_password" required minlength="6">
            </div>
            <?php if (email_verification_enabled()): ?>
                <div class="form-group">
                    <label class="form-label" for="pwd_verification_code"><?php echo e(t('profile_label_verification_code', '邮箱验证码')); ?></label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" class="form-control" id="pwd_verification_code" name="verification_code" placeholder="<?php echo e(t('profile_code_placeholder', '6 位数字验证码\"')); ?> maxlength="6" pattern="\d{6}" inputmode="numeric" style="flex: 1;">
                        <button type="button" class="btn btn-secondary" id="pwdSendCodeBtn" style="white-space: nowrap;"><?php echo e(t('profile_get_code', '获取验证码')); ?></button>
                    </div>
                    <p class="form-hint" id="pwdCodeHint"><?php echo e(t('profile_code_hint', '验证码将发送至您的绑定邮箱。')); ?></p>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary"><?php echo e(t('profile_change_password', '修改密码')); ?></button>
        </form>

        <?php if (email_verification_enabled()): ?>
        <script>
        (function() {
            var btn = document.getElementById('pwdSendCodeBtn');
            var hint = document.getElementById('pwdCodeHint');
            var csrfInput = document.querySelector('#passwordChangeForm input[name="csrf_token"]');
            if (!btn || !csrfInput) return;

            var retryTpl = <?php echo json_encode(t('profile_retry_seconds', '{n} 秒后重试')); ?>;
            var codeSentText = <?php echo json_encode(t('profile_code_sent', '验证码已发送，请查收邮件。')); ?>;
            var getCodeText = <?php echo json_encode(t('profile_get_code', '获取验证码')); ?>;
            var codeHintText = <?php echo json_encode(t('profile_code_hint', '验证码将发送至您的绑定邮箱。')); ?>;

            function setCountdown(seconds) {
                btn.disabled = true;
                btn.textContent = retryTpl.replace('{n}', seconds);
                hint.textContent = codeSentText;
                hint.style.color = '';
                var t = setInterval(function() {
                    seconds--;
                    if (seconds <= 0) {
                        clearInterval(t);
                        btn.disabled = false;
                        btn.textContent = getCodeText;
                        hint.textContent = codeHintText;
                    } else {
                        btn.textContent = retryTpl.replace('{n}', seconds);
                    }
                }, 1000);
            }

            btn.addEventListener('click', function() {
                if (btn.disabled) return;
                btn.disabled = true;
                hint.style.color = '';
                hint.textContent = <?php echo json_encode(t('profile_sending', '正在发送…')); ?>;

                var formData = new FormData();
                formData.append('csrf_token', csrfInput.value);

                fetch('/index.php?route=send_password_change_code', {
                    method: 'POST',
                    body: formData
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.success) {
                        setCountdown(60);
                    } else {
                        btn.disabled = false;
                        hint.textContent = data.error || <?php echo json_encode(t('profile_send_failed', '发送失败，请重试。')); ?>;
                        hint.style.color = 'var(--error)';
                    }
                }).catch(function() {
                    btn.disabled = false;
                    hint.textContent = <?php echo json_encode(t('profile_network_error', '网络错误，请重试。')); ?>;
                    hint.style.color = 'var(--error)';
                });
            });
        })();
        </script>
        <?php endif; ?>
    </div>

    <div class="profile-card">
        <h2 class="profile-card-title"><?php echo e(t('profile_security_question', '密保问题')); ?></h2>
        <p class="text-muted mb-2"><?php echo e(t('profile_sq_desc', '当站点未启用邮件重置时，管理员可通过密保问题确认是否本人申请重置密码。')); ?></p>
        <?php if (!empty($errors) && in_array(($_POST['action'] ?? ''), ['update_security_question', 'remove_security_question'], true)): ?>
            <?php foreach ($errors as $err): ?>
                <?php echo show_message($err, 'error'); ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($user['security_question'])): ?>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('profile_current_sq', '当前密保问题')); ?></label>
                <p class="text-muted"><?php echo e($user['security_question']); ?></p>
            </div>
        <?php endif; ?>
        <form method="POST" action="<?php echo site_url('profile', ['tab' => 'security']); ?>" data-validate>
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="update_security_question">
            <div class="form-group">
                <label class="form-label" for="security_question"><?php echo e(t('profile_security_question', '密保问题')); ?></label>
                <input type="text" class="form-control" id="security_question" name="security_question" value="" placeholder="<?php echo e(t('profile_sq_placeholder', '例如：我的小学班主任叫什么名字？\"')); ?> maxlength="255">
                <p class="form-hint"><?php echo e(t('profile_sq_hint', '设置后如需修改，回答新问题即可覆盖旧问题。')); ?></p>
            </div>
            <div class="form-group">
                <label class="form-label" for="security_answer"><?php echo e(t('profile_sq_answer', '密保答案')); ?></label>
                <input type="text" class="form-control" id="security_answer" name="security_answer" value="" placeholder="<?php echo e(t('profile_sq_answer_placeholder', '请输入答案\"')); ?> maxlength="255">
                <p class="form-hint"><?php echo e(t('profile_sq_answer_hint', '答案区分大小写，请牢记。')); ?></p>
            </div>
            <div class="form-group">
                <label class="form-label" for="sq_current_password"><?php echo e(t('profile_label_current_password', '当前密码')); ?></label>
                <input type="password" class="form-control" id="sq_current_password" name="current_password" required>
            </div>
            <div class="flex gap-1 flex-wrap">
                <button type="submit" class="btn btn-primary"><?php echo e(empty($user['security_question']) ? t('profile_sq_set', '设置密保') : t('profile_sq_modify', '修改密保')); ?></button>
                <?php if (!empty($user['security_question'])): ?>
                    <button type="submit" formaction="<?php echo site_url('profile', ['tab' => 'security']); ?>" formmethod="POST" name="action" value="remove_security_question" class="btn btn-secondary" data-confirm="<?php echo e(t('profile_sq_remove_confirm', '清除后无法通过密保验证重置密码，确定继续吗？\"')); ?>><?php echo e(t('profile_sq_remove', '清除密保')); ?></button>
                <?php endif; ?>
            </div>
        </form>
    </div>

<?php elseif ($tab === 'posts'): ?>
    <div class="profile-card">
        <h2 class="profile-card-title"><?php echo e($isOwnProfile ? t('profile_tab_my_posts', '我的帖子') : t('profile_tab_their_posts', 'TA 的帖子')); ?></h2>
        <?php
        $profilePage = max(1, (int)($_GET['p'] ?? 1));
        $profilePerPage = 20;
        $profileOffset = ($profilePage - 1) * $profilePerPage;
        $countStmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE user_id = :user_id");
        $countStmt->execute([':user_id' => $user['id']]);
        $postTotal = (int)$countStmt->fetchColumn();
        $stmt = $db->prepare("SELECT * FROM posts WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $profilePerPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $profileOffset, PDO::PARAM_INT);
        $stmt->execute();
        $myPosts = $stmt->fetchAll();
        ?>
        <?php if (empty($myPosts)): ?>
            <div class="empty-state" style="padding: 2rem 1rem;">
                <div class="empty-state-icon"><?php echo ui_icon('file-text', 48); ?></div>
                <p><?php echo e(t('profile_no_posts', '还没有发布过帖子。')); ?></p>
            </div>
        <?php else: ?>
            <div class="post-list">
                <?php foreach ($myPosts as $p): ?>
                    <div class="post-item">
                        <h3 class="post-title"><a href="<?php echo site_url('post', ['id' => (int)$p['id']]); ?>"><?php echo e(strip_bbcode($p['title'])); ?></a></h3>
                        <div class="post-meta">
                            <span><?php echo time_ago($p['created_at']); ?></span>
                            <span class="post-stat"><?php echo ui_icon('eye', 14); ?> <?php echo $p['views']; ?></span>
                            <span class="post-stat"><?php echo ui_icon('message', 14); ?> <?php echo $p['replies_count']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php echo pagination($profilePage, $postTotal, $profilePerPage, site_url('profile', array_merge($isOwnProfile ? [] : ['user_id' => (int)$user['id']], ['tab' => 'posts']))); ?>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'replies'): ?>
    <div class="profile-card">
        <h2 class="profile-card-title"><?php echo e($isOwnProfile ? t('profile_tab_my_replies', '我的回复') : t('profile_tab_their_replies', 'TA 的回复')); ?></h2>
        <?php
        $profilePage = max(1, (int)($_GET['p'] ?? 1));
        $profilePerPage = 20;
        $profileOffset = ($profilePage - 1) * $profilePerPage;
        $countStmt = $db->prepare("SELECT COUNT(*) FROM replies r JOIN posts p ON r.post_id = p.id WHERE r.user_id = :user_id");
        $countStmt->execute([':user_id' => $user['id']]);
        $replyTotal = (int)$countStmt->fetchColumn();
        $stmt = $db->prepare("SELECT r.*, p.title AS post_title, p.id AS post_id FROM replies r JOIN posts p ON r.post_id = p.id WHERE r.user_id = :user_id ORDER BY r.created_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $profilePerPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $profileOffset, PDO::PARAM_INT);
        $stmt->execute();
        $myReplies = $stmt->fetchAll();
        ?>
        <?php if (empty($myReplies)): ?>
            <div class="empty-state" style="padding: 2rem 1rem;">
                <div class="empty-state-icon"><?php echo ui_icon('message', 48); ?></div>
                <p><?php echo e(t('profile_no_replies', '还没有回复过任何帖子。')); ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($myReplies as $r): ?>
                <div class="reply-item">
                    <div class="reply-body">
                        <div class="reply-header">
                            <span class="author-name"><?php echo e(t('profile_replied_to', '回复了')); ?> <a href="<?php echo site_url('post', ['id' => (int)$r['post_id']]); ?>"><?php echo e($r['post_title']); ?></a></span>
                            <span class="text-muted" style="font-size: 0.875rem;"><?php echo time_ago($r['created_at']); ?></span>
                        </div>
                        <div class="reply-content">
                            <?php echo nl2br(linkify($r['content']), false); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php echo pagination($profilePage, $replyTotal, $profilePerPage, site_url('profile', array_merge($isOwnProfile ? [] : ['user_id' => (int)$user['id']], ['tab' => 'replies']))); ?>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'favorites'): ?>
    <div class="profile-card">
        <h2 class="profile-card-title"><?php echo e(t('profile_tab_my_favorites', '我的收藏')); ?></h2>
        <?php
        $profilePage = max(1, (int)($_GET['p'] ?? 1));
        $profilePerPage = 20;
        $profileOffset = ($profilePage - 1) * $profilePerPage;
        $countStmt = $db->prepare("SELECT COUNT(*) FROM favorites f JOIN posts p ON f.post_id = p.id WHERE f.user_id = :user_id");
        $countStmt->execute([':user_id' => $user['id']]);
        $favTotal = (int)$countStmt->fetchColumn();
        $stmt = $db->prepare("SELECT p.*, f.id AS fav_id, u.username FROM favorites f JOIN posts p ON f.post_id = p.id JOIN users u ON p.user_id = u.id WHERE f.user_id = :user_id ORDER BY f.created_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $profilePerPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $profileOffset, PDO::PARAM_INT);
        $stmt->execute();
        $myFavorites = $stmt->fetchAll();
        ?>
        <?php if (empty($myFavorites)): ?>
            <div class="empty-state" style="padding: 2rem 1rem;">
                <div class="empty-state-icon"><?php echo ui_icon('star', 48); ?></div>
                <p><?php echo e(t('profile_no_favorites', '还没有收藏过帖子。')); ?></p>
            </div>
        <?php else: ?>
            <div class="post-list">
                <?php foreach ($myFavorites as $p): ?>
                    <div class="post-item">
                        <h3 class="post-title"><a href="<?php echo site_url('post', ['id' => (int)$p['id']]); ?>"><?php echo e(strip_bbcode($p['title'])); ?></a></h3>
                        <div class="post-meta">
                            <span><?php echo t('profile_fav_author', '作者: {name}', ['name' => e($p['username'])]); ?></span>
                            <span><?php echo time_ago($p['created_at']); ?></span>
                            <a href="<?php echo site_url('post', ['id' => (int)$p['id'], 'fav_action' => 'remove', 'csrf_token' => csrf_token()]); ?>" class="text-muted" style="font-size: 0.8125rem;" data-confirm="<?php echo e(t('profile_fav_remove_confirm', '确定取消收藏？\"')); ?>><?php echo e(t('profile_fav_remove', '取消收藏')); ?></a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php echo pagination($profilePage, $favTotal, $profilePerPage, site_url('profile', ['tab' => 'favorites'])); ?>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'checkins'): ?>
    <div class="profile-card">
        <h2 class="profile-card-title"><?php echo e(t('profile_tab_checkins', '签到记录')); ?></h2>
        <?php
        $stmt = $db->prepare("SELECT * FROM checkins WHERE user_id = :user_id ORDER BY checkin_date DESC LIMIT 30");
        $stmt->execute([':user_id' => $user['id']]);
        $checkins = $stmt->fetchAll();
        ?>
        <?php if (empty($checkins)): ?>
            <div class="empty-state" style="padding: 2rem 1rem;">
                <div class="empty-state-icon"><?php echo ui_icon('calendar', 48); ?></div>
                <p><?php echo e(t('profile_no_checkins', '还没有签到记录。')); ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?php echo e(t('profile_th_date', '日期')); ?></th>
                            <th><?php echo e(t('profile_th_streak', '连续天数')); ?></th>
                            <th><?php echo e(t('profile_th_points_earned', '获得积分')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($checkins as $c): ?>
                            <tr>
                                <td><?php echo e($c['checkin_date']); ?></td>
                                <td><?php echo e(t('profile_days', '{n} 天', ['n' => $c['streak']])); ?></td>
                                <td>+<?php echo $c['points']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'points'): ?>
    <div class="profile-card">
        <h2 class="profile-card-title"><?php echo e(t('profile_tab_points', '积分记录')); ?></h2>
        <p class="text-muted mb-2"><?php echo e(t('profile_points_desc', '展示最近 50 条积分与金币变动记录。')); ?></p>
        <?php if (empty($pointsLog)): ?>
            <div class="empty-state" style="padding: 2rem 1rem;">
                <div class="empty-state-icon"><?php echo ui_icon('activity', 48); ?></div>
                <p><?php echo e(t('profile_no_points_log', '暂无积分记录。')); ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?php echo e(t('profile_th_time', '时间')); ?></th>
                            <th><?php echo e(t('profile_th_type', '类型')); ?></th>
                            <th><?php echo e(t('profile_th_desc', '说明')); ?></th>
                            <th><?php echo e(t('profile_th_points', '积分')); ?></th>
                            <th><?php echo e(t('profile_th_coins', '金币')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pointsLog as $log): ?>
                            <tr>
                                <td><?php echo e(date('Y-m-d H:i', db_time($log['created_at']))); ?></td>
                                <td><?php echo e(format_points_type($log['type'])); ?></td>
                                <td><?php echo e($log['description']); ?></td>
                                <td><?php echo $log['points'] > 0 ? '+' . $log['points'] : $log['points']; ?></td>
                                <td><?php echo $log['coins'] > 0 ? '+' . $log['coins'] : $log['coins']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
// 头像实时预览
(function() {
    var avatarInput = document.getElementById('avatar');
    var avatarFile = document.getElementById('avatar_file');
    var avatarPreview = document.getElementById('avatar-preview-img');
    var defaultSrc = avatarPreview ? avatarPreview.src : '';

    if (avatarInput && avatarPreview) {
        avatarInput.addEventListener('input', function() {
            var url = avatarInput.value.trim();
            if (url) {
                avatarPreview.src = url;
            } else {
                avatarPreview.src = defaultSrc;
            }
        });
    }

    if (avatarFile && avatarPreview) {
        avatarFile.addEventListener('change', function() {
            var file = avatarFile.files[0];
            if (file && file.type.indexOf('image/') === 0) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    avatarPreview.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    }
})();
</script>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
