<?php
/**
 * 云界论坛 - 管理后台创建账号
 *
 * 超级管理员手动创建新用户：设置用户名、邮箱、初始密码，
 * 可选直接分配「社区管理员」角色（两级管理员体系，与 user_edit 角色下拉一致）。
 * 校验规则与前台注册保持一致（用户名长度/字符集/敏感词、邮箱格式、密码强度、唯一性）。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';
require_once APP_ROOT . 'app/components/sensitive_filter/helper.php';

// 权限门禁：创建账号影响账号体系，仅超级管理员可用
require_super_admin();

$db = get_db();
$errors = [];
$old = ['username' => '', 'email' => '', 'role' => 'user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $old['username'] = trim($_POST['username'] ?? '');
    $old['email'] = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
    // 角色白名单：创建时仅允许 普通用户 / 社区管理员（admin 角色只能由 user_edit 提升，且需单独确认）
    $old['role'] = in_array($_POST['role'] ?? '', ['user', 'community_admin'], true) ? $_POST['role'] : 'user';

    // 用户名：长度 + 字符集 + 敏感词（与前台注册一致）
    if (empty($old['username']) || mb_strlen($old['username']) < 3 || mb_strlen($old['username']) > 32) {
        $errors[] = t('register_username_length', '用户名长度必须在 3-32 个字符之间。');
    } elseif (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $old['username'])) {
        $errors[] = t('register_username_chars', '用户名只能包含中文、英文、数字和下划线。');
    } elseif (has_sensitive_words($old['username'], 2)) {
        $errors[] = t('register_username_sensitive', '用户名包含违规内容，请更换。');
    }

    // 邮箱格式
    if (empty($old['email']) || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('register_email_invalid', '请输入有效的邮箱地址。');
    }

    // 初始密码强度（与注册一致）：至少 6 位且同时包含字母和数字
    if (strlen($password) < 6) {
        $errors[] = t('register_password_length', '密码长度不能少于 6 位。');
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = t('register_password_alnum', '密码必须同时包含字母和数字。');
    }
    if ($password !== $passwordConfirm) {
        $errors[] = t('register_password_mismatch', '两次输入的密码不一致。');
    }

    // 用户名/邮箱唯一性（与注册一致，大小写不敏感）
    $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username) OR LOWER(email) = LOWER(:email) LIMIT 1");
    $stmt->execute([':username' => $old['username'], ':email' => $old['email']]);
    if ($stmt->fetch()) {
        $errors[] = t('register_taken', '用户名或邮箱已被注册。');
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("INSERT INTO users (uid, username, email, password, role, register_ip, last_ip) VALUES (:uid, :username, :email, :password, 'user', :ip, :ip)");
            $stmt->execute([
                ':uid' => generate_uid(),
                ':username' => $old['username'],
                ':email' => $old['email'],
                ':password' => password_hash($password, PASSWORD_BCRYPT),
                ':ip' => client_ip(),
            ]);
            $newUserId = (int)$db->lastInsertId();

            // 选择「社区管理员」时补 user_roles 关联（users.role 保持 'user'，与 user_edit 保存逻辑一致）
            // 注意：必须用 sql_prepare() 做方言翻译——原生 PDO 下 MySQL 不支持 INSERT OR IGNORE
            if ($old['role'] === 'community_admin') {
                // 内置角色可能被误删：先兑底重建再取 ID
                $cid = ensure_builtin_role('community_admin', '社区管理员', 'admin_access,manage_posts,manage_replies,manage_reports,manage_ban_appeals,manage_user_dispose');
                if ($cid > 0) {
                    $roleStmt = sql_prepare($db, "INSERT OR IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)");
                    $roleStmt->execute([':user_id' => $newUserId, ':role_id' => $cid]);
                }
            }

            $db->commit();
            set_flash(t('admin_usercreate_flash_created', '账号已创建。'), 'success');
            redirect('/admin/users');
        } catch (\Throwable $e) {
            try { $db->rollBack(); } catch (\Throwable $ignored) {}
            error_log('admin user_create failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $errors[] = t('admin_usercreate_err_save_failed', '创建失败：{error}', ['error' => $e->getMessage()]);
        }
    }
}

$pageTitle = t('admin_usercreate_page_title', '创建账号');
$activeMenu = 'users';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_usercreate_heading', '创建账号')); ?></h1>
    <a href="<?php echo site_url('admin/users'); ?>" class="btn btn-secondary"><?php echo e(t('admin_usercreate_back_to_list', '返回用户列表')); ?></a>
</div>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
        <?php echo show_message($err, 'error'); ?>
    <?php endforeach; ?>
<?php endif; ?>

<form method="POST" action="<?php echo site_url('admin/user_create'); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

    <div class="card" style="max-width: 640px;">
        <h2 class="card-title mb-1"><?php echo e(t('admin_usercreate_section_account', '账号信息')); ?></h2>
        <div class="form-group">
            <label class="form-label"><?php echo e(t('admin_usercreate_label_username', '用户名')); ?></label>
            <input type="text" class="form-control" name="username" value="<?php echo e($old['username']); ?>" required minlength="3" maxlength="32" autocomplete="off">
            <p class="form-hint"><?php echo e(t('admin_usercreate_hint_username', '3-32 个字符，仅限中英文、数字和下划线。')); ?></p>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo e(t('admin_usercreate_label_email', '邮箱')); ?></label>
            <input type="email" class="form-control" name="email" value="<?php echo e($old['email']); ?>" required autocomplete="off">
        </div>
        <div class="form-row">
            <div class="form-group form-group-half">
                <label class="form-label"><?php echo e(t('admin_usercreate_label_password', '初始密码')); ?></label>
                <input type="password" class="form-control" name="password" required minlength="6" autocomplete="new-password">
                <p class="form-hint"><?php echo e(t('admin_usercreate_hint_password', '至少 6 位，须同时包含字母和数字。')); ?></p>
            </div>
            <div class="form-group form-group-half">
                <label class="form-label"><?php echo e(t('admin_usercreate_label_password_confirm', '确认密码')); ?></label>
                <input type="password" class="form-control" name="password_confirm" required minlength="6" autocomplete="new-password">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo e(t('admin_usercreate_label_role', '角色')); ?></label>
            <select class="form-control" name="role">
                <option value="user" <?php echo $old['role'] === 'user' ? 'selected' : ''; ?>><?php echo e(t('admin_useredit_role_user', '普通用户')); ?></option>
                <option value="community_admin" <?php echo $old['role'] === 'community_admin' ? 'selected' : ''; ?>><?php echo e(t('admin_useredit_role_community_admin', '社区管理员')); ?></option>
            </select>
            <p class="form-hint"><?php echo e(t('admin_usercreate_hint_role', '社区管理员可进入后台管理内容；超级管理员只能通过编辑已有用户提升。')); ?></p>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?php echo e(t('admin_usercreate_submit', '创建账号')); ?></button>
            <a href="<?php echo site_url('admin/users'); ?>" class="btn btn-secondary"><?php echo e(t('admin_usercreate_cancel', '取消')); ?></a>
        </div>
    </div>
</form>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
