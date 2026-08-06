<?php
/**
 * 云界论坛 - 管理后台用户编辑
 *
 * 支持调整用户基本信息、积分（自动计算等级）、权限组、勋章。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$db = get_db();
$userId = (int)($_GET['user_id'] ?? 0);
$errors = [];

if ($userId <= 0) {
    set_flash(t('admin_useredit_no_user_specified', '未指定用户。'), 'error');
    redirect('/admin/users');
}

$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    set_flash(t('admin_useredit_user_not_found', '用户不存在。'), 'error');
    redirect('/admin/users');
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$isSelf = $currentUserId === $userId;

// 保存用户
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $signature = trim($_POST['signature'] ?? '');
    $points = (int)($_POST['points'] ?? 0);
    $role = trim($_POST['role'] ?? 'user');
    $roleIds = array_map('intval', $_POST['roles'] ?? []);
    $medalIds = array_map('intval', $_POST['medals'] ?? []);
    $password = trim($_POST['password'] ?? '');

    // 校验
    if ($username === '' || $email === '') {
        $errors[] = t('admin_useredit_error_required', '用户名和邮箱不能为空。');
    } elseif (!preg_match('/^[a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]{2,32}$/u', $username)) {
        $errors[] = t('admin_useredit_error_username_format', '用户名格式不正确（2-32 位，支持中英文、数字、下划线、连字符）。');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('admin_useredit_error_email_format', '邮箱格式不正确。');
    }

    // 检查用户名/邮箱唯一性
    if (empty($errors)) {
        $check = $db->prepare("SELECT id FROM users WHERE (LOWER(username) = LOWER(:username) OR LOWER(email) = LOWER(:email)) AND id != :id LIMIT 1");
        $check->execute([':username' => $username, ':email' => $email, ':id' => $userId]);
        if ($check->fetch()) {
            $errors[] = t('admin_useredit_error_duplicate', '用户名或邮箱已被其他用户使用。');
        }
    }

    // 不能取消自己的管理员身份
    if ($isSelf && $role !== 'admin') {
        $errors[] = t('admin_useredit_error_self_demote', '不能取消当前登录账号的管理员权限。');
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            // 更新用户基本信息
            $sql = "UPDATE users SET username = :username, email = :email, signature = :signature, points = :points, role = :role";
            $params = [
                ':username' => $username,
                ':email' => $email,
                ':signature' => $signature,
                ':points' => max(0, $points),
                ':role' => in_array($role, ['user', 'admin'], true) ? $role : 'user',
                ':id' => $userId,
            ];
            if ($password !== '') {
                if (strlen($password) < 6) {
                    throw new Exception(t('admin_useredit_error_password_short', '密码长度不能少于 6 位。'));
                }
                $sql .= ", password = :password";
                $params[':password'] = password_hash($password, PASSWORD_BCRYPT);
            }
            $sql .= " WHERE id = :id";
            $db->prepare($sql)->execute($params);

            // 更新权限组
            $db->prepare("DELETE FROM user_roles WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            $roleStmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)");
            foreach ($roleIds as $rid) {
                if ($rid > 0) {
                    $roleStmt->execute([':user_id' => $userId, ':role_id' => $rid]);
                }
            }

            // 更新勋章
            $db->prepare("DELETE FROM user_medals WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            $medalStmt = $db->prepare("INSERT INTO user_medals (user_id, medal_id, awarded_by) VALUES (:user_id, :medal_id, :awarded_by)");
            foreach ($medalIds as $mid) {
                if ($mid > 0) {
                    $medalStmt->execute([':user_id' => $userId, ':medal_id' => $mid, ':awarded_by' => $currentUserId]);
                }
            }

            $db->commit();
            set_flash(t('admin_useredit_saved', '用户信息已更新。'), 'success');
            redirect('/admin/user_edit?user_id=' . $userId);
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = t('admin_useredit_save_failed', '保存失败：{error}', ['error' => $e->getMessage()]);
        }
    }
}

// 加载当前用户的权限组和勋章
$stmt = $db->prepare("SELECT role_id FROM user_roles WHERE user_id = :user_id");
$stmt->execute([':user_id' => $userId]);
$userRoleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $db->prepare("SELECT medal_id FROM user_medals WHERE user_id = :user_id");
$stmt->execute([':user_id' => $userId]);
$userMedalIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$roles = $db->query("SELECT * FROM roles ORDER BY display_name")->fetchAll();
$medals = $db->query("SELECT * FROM medals ORDER BY display_name")->fetchAll();
$groups = $db->query("SELECT * FROM user_groups ORDER BY min_points ASC")->fetchAll();
$userGroup = get_user_group((int)$user['points']);

$pageTitle = t('admin_useredit_page_title_named', '编辑用户：{name}', ['name' => $user['username']]);
$activeMenu = 'users';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_useredit_heading', '编辑用户')); ?></h1>
    <a href="<?php echo site_url('admin/users'); ?>" class="btn btn-secondary"><?php echo e(t('admin_useredit_back_to_list', '返回用户列表')); ?></a>
</div>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
        <?php echo show_message($err, 'error'); ?>
    <?php endforeach; ?>
<?php endif; ?>

<form method="POST" action="<?php echo site_url('admin/user_edit', ['user_id' => (int)$userId]); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

    <div class="grid grid-2 mb-2">
        <div class="card">
            <h2 class="card-title mb-1"><?php echo e(t('admin_useredit_section_basic', '基本信息')); ?></h2>
            <div class="form-row">
                <div class="form-group form-group-half">
                    <label class="form-label">UID</label>
                    <input type="text" class="form-control" value="<?php echo e((string)($user['uid'] ?? '')); ?>" disabled>
                    <p class="form-hint"><?php echo e(t('admin_useredit_hint_uid', 'UID 不可修改。')); ?></p>
                </div>
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_useredit_label_user_id', '用户 ID')); ?></label>
                    <input type="text" class="form-control" value="<?php echo $user['id']; ?>" disabled>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_useredit_label_username', '用户名')); ?></label>
                    <input type="text" class="form-control" name="username" value="<?php echo e($user['username']); ?>" required>
                </div>
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_useredit_label_email', '邮箱')); ?></label>
                    <input type="email" class="form-control" name="email" value="<?php echo e($user['email']); ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_useredit_label_signature', '个性签名')); ?></label>
                <textarea class="form-control" name="signature" rows="3"><?php echo e($user['signature']); ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_useredit_label_role', '角色')); ?></label>
                    <select class="form-control" name="role" <?php echo $isSelf ? 'disabled' : ''; ?>>
                        <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>><?php echo e(t('admin_useredit_role_user', '普通用户')); ?></option>
                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>><?php echo e(t('admin_useredit_role_admin', '管理员')); ?></option>
                    </select>
                    <?php if ($isSelf): ?>
                        <p class="form-hint text-error"><?php echo e(t('admin_useredit_hint_self_role', '不能修改当前登录账号的角色。')); ?></p>
                        <input type="hidden" name="role" value="admin">
                    <?php endif; ?>
                </div>
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_useredit_label_reset_password', '重置密码（留空则不修改）')); ?></label>
                    <input type="password" class="form-control" name="password" placeholder="<?php echo e(t('admin_useredit_placeholder_password', '输入新密码')); ?>" autocomplete="new-password">
                </div>
            </div>
        </div>

        <div class="card">
            <h2 class="card-title mb-1"><?php echo e(t('admin_useredit_section_points', '积分与等级')); ?></h2>
            <div class="form-row">
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_useredit_label_points', '当前积分')); ?></label>
                    <input type="number" class="form-control" name="points" value="<?php echo (int)$user['points']; ?>" min="0" required>
                    <p class="form-hint"><?php echo e(t('admin_useredit_hint_points', '修改积分后，等级会根据用户组规则自动重新计算。')); ?></p>
                </div>
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_useredit_label_current_level', '当前等级')); ?></label>
                    <div class="form-control-static">
                        <span class="badge" style="background:<?php echo e($userGroup['color']); ?>;color:#fff;">
                                    <?php echo ui_icon($userGroup['icon'], 14); ?>
                                    <?php echo e($userGroup['title']); ?>
                                </span>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_useredit_label_level_rules', '等级规则')); ?></label>
                <div class="group-rules">
                    <?php foreach ($groups as $g): ?>
                        <div class="group-rule-item">
                            <span class="badge" style="background:<?php echo e($g['color']); ?>;color:#fff;">
                                <?php echo e($g['display_name']); ?>
                            </span>
                            <span class="text-muted text-xs">
                                <?php echo (int)$g['min_points']; ?> -
                                <?php echo $g['max_points'] !== null ? (int)$g['max_points'] : '∞'; ?> <?php echo e(t('admin_useredit_points_unit', '积分')); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-2 mb-2">
        <div class="card">
            <h2 class="card-title mb-1"><?php echo e(t('admin_useredit_section_roles', '权限组')); ?></h2>
            <?php if (empty($roles)): ?>
                <p class="text-muted"><?php echo t('admin_useredit_no_roles', '暂无权限组，请先在 {link} 中创建。', ['link' => '<a href="' . site_url('admin/roles') . '">' . e(t('admin_useredit_roles_link', '权限组管理')) . '</a>']); ?></p>
            <?php else: ?>
                <div class="checkbox-grid">
                    <?php foreach ($roles as $role): ?>
                        <label class="checkbox-card">
                            <input type="checkbox" name="roles[]" value="<?php echo $role['id']; ?>" <?php echo in_array($role['id'], $userRoleIds) ? 'checked' : ''; ?>>
                            <span class="checkbox-label"><?php echo e($role['display_name']); ?></span>
                            <?php if ($role['permissions']): ?>
                                <span class="checkbox-hint"><?php echo e($role['permissions']); ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="card-title mb-1"><?php echo e(t('admin_useredit_section_medals', '勋章')); ?></h2>
            <?php if (empty($medals)): ?>
                <p class="text-muted"><?php echo t('admin_useredit_no_medals', '暂无勋章，请先在 {link} 中创建。', ['link' => '<a href="' . site_url('admin/medals') . '">' . e(t('admin_useredit_medals_link', '勋章管理')) . '</a>']); ?></p>
            <?php else: ?>
                <div class="checkbox-grid">
                    <?php foreach ($medals as $medal): ?>
                        <label class="checkbox-card">
                            <input type="checkbox" name="medals[]" value="<?php echo $medal['id']; ?>" <?php echo in_array($medal['id'], $userMedalIds) ? 'checked' : ''; ?>>
                            <span class="medal" style="color: <?php echo e($medal['color']); ?>; border-color: <?php echo e($medal['color']); ?>"><?php echo e($medal['icon']); ?> <?php echo e($medal['display_name']); ?></span>
                            <?php if ($medal['description']): ?>
                                <span class="checkbox-hint"><?php echo e($medal['description']); ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?php echo e(t('admin_useredit_submit', '保存修改')); ?></button>
        <a href="<?php echo site_url('admin/users'); ?>" class="btn btn-secondary"><?php echo e(t('admin_useredit_cancel', '取消')); ?></a>
    </div>
</form>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
