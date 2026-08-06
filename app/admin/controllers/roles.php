<?php
/**
 * 云界论坛 - 管理后台权限组管理
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$db = get_db();
$action = $_GET['action'] ?? 'list';
$roleId = (int)($_GET['role_id'] ?? 0);
$errors = [];

// CSRF 统一校验：涉及状态变更的 GET 操作必须先通过校验，失败时明确提示（避免静默失败）
if ($action === 'delete' && !validate_csrf()) {
    set_flash(t('admin_roles_csrf_failed', '安全校验失败（链接已过期），请刷新页面后重新操作。'), 'error');
    redirect('/admin/roles');
}

if ($action === 'delete' && $roleId > 0) {
    $db->prepare("DELETE FROM roles WHERE id = :id")->execute([':id' => $roleId]);
    set_flash(t('admin_roles_flash_deleted', '权限组已删除。'), 'success');
    redirect('/admin/roles');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $name = trim($_POST['name'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $permissions = trim($_POST['permissions'] ?? '');

    if (empty($name) || empty($displayName)) {
        $errors[] = t('admin_roles_err_name_required', '名称和显示名称不能为空。');
    } elseif (!preg_match('/^[a-z0-9_]+$/', $name)) {
        $errors[] = t('admin_roles_err_name_format', '权限组标识只能包含小写字母、数字和下划线。');
    }

    if (empty($errors)) {
        if ($roleId > 0) {
            $stmt = $db->prepare("UPDATE roles SET name = :name, display_name = :display_name, permissions = :permissions WHERE id = :id");
            $stmt->execute([':name' => $name, ':display_name' => $displayName, ':permissions' => $permissions, ':id' => $roleId]);
            set_flash(t('admin_roles_flash_updated', '权限组已更新。'), 'success');
        } else {
            $stmt = $db->prepare("INSERT INTO roles (name, display_name, permissions) VALUES (:name, :display_name, :permissions)");
            $stmt->execute([':name' => $name, ':display_name' => $displayName, ':permissions' => $permissions]);
            set_flash(t('admin_roles_flash_created', '权限组已创建。'), 'success');
        }
        redirect('/admin/roles');
    }
}

$role = null;
if ($action === 'edit' && $roleId > 0) {
    $stmt = $db->prepare("SELECT * FROM roles WHERE id = :id");
    $stmt->execute([':id' => $roleId]);
    $role = $stmt->fetch();
}

$roles = $db->query("SELECT * FROM roles ORDER BY display_name")->fetchAll();

$pageTitle = t('admin_roles_title', '权限组管理');
$activeMenu = 'roles';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_roles_title', '权限组管理')); ?></h1>
    <?php if ($action !== 'edit' && $action !== 'add'): ?>
        <a href="<?php echo site_url('admin/roles', ['action' => 'add']); ?>" class="btn btn-primary"><?php echo e(t('admin_roles_add', '新增权限组')); ?></a>
    <?php endif; ?>
</div>

<?php if ($action === 'edit' || $action === 'add'): ?>
    <div class="card">
        <h2 class="card-title mb-1"><?php echo $role ? e(t('admin_roles_edit', '编辑权限组')) : e(t('admin_roles_add', '新增权限组')); ?></h2>
        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <?php echo show_message($err, 'error'); ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <form method="POST" action="<?php echo site_url('admin/roles', $role ? ['action' => 'edit', 'role_id' => (int)$role['id']] : ['action' => 'add']); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_roles_label_name', '权限组标识')); ?></label>
                <input type="text" class="form-control" name="name" value="<?php echo e($role['name'] ?? ''); ?>" required placeholder="<?php echo e(t('admin_roles_ph_name', '例如：moderator')); ?>">
                <p class="form-hint"><?php echo e(t('admin_roles_hint_name', '仅小写字母、数字、下划线，创建后不建议修改。')); ?></p>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_roles_label_display_name', '显示名称')); ?></label>
                <input type="text" class="form-control" name="display_name" value="<?php echo e($role['display_name'] ?? ''); ?>" required placeholder="<?php echo e(t('admin_roles_ph_display_name', '例如：版主')); ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_roles_label_permissions', '权限标识（逗号分隔）')); ?></label>
                <input type="text" class="form-control" name="permissions" value="<?php echo e($role['permissions'] ?? ''); ?>" placeholder="<?php echo e(t('admin_roles_ph_permissions', '例如：manage_posts,manage_replies,manage_users')); ?>">
                <p class="form-hint"><?php echo e(t('admin_roles_hint_permissions', 'admin_access 可进入管理后台；manage_posts/manage_replies/manage_users 分别管理帖子/回复/用户。')); ?></p>
            </div>
            <button type="submit" class="btn btn-primary"><?php echo e(t('admin_roles_save', '保存')); ?></button>
            <a href="<?php echo site_url('admin/roles'); ?>" class="btn btn-secondary"><?php echo e(t('admin_roles_back', '返回')); ?></a>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr><th><?php echo e(t('admin_roles_th_id', 'ID')); ?></th><th><?php echo e(t('admin_roles_th_name', '标识')); ?></th><th><?php echo e(t('admin_roles_th_display_name', '显示名称')); ?></th><th><?php echo e(t('admin_roles_th_permissions', '权限')); ?></th><th><?php echo e(t('admin_roles_th_actions', '操作')); ?></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $r): ?>
                        <tr>
                            <td><?php echo $r['id']; ?></td>
                            <td><?php echo e($r['name']); ?></td>
                            <td><?php echo e($r['display_name']); ?></td>
                            <td><?php echo e($r['permissions']); ?></td>
                            <td>
                                <a href="<?php echo site_url('admin/roles', ['action' => 'edit', 'role_id' => (int)$r['id']]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('admin_roles_action_edit', '编辑')); ?></a>
                                <a href="<?php echo site_url('admin/roles', ['action' => 'delete', 'role_id' => (int)$r['id'], 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-danger" data-confirm="<?php echo e(t('admin_roles_confirm_delete', "确定删除该权限组吗？\n已分配该权限组的用户也会失去对应权限。")); ?>"><?php echo e(t('admin_roles_action_delete', '删除')); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
