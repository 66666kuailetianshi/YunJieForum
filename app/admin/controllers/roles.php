<?php
/**
 * 云界论坛 - 管理后台权限组管理
 *
 * 仅管理角色标识与显示名称。权限点不在此页面配置：
 * 超级管理员（users.role='admin'）天然拥有全部权限；
 * 社区管理员等内置角色的权限由系统播种固定（db.php），界面不提供权限点勾选。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：权限组管理仅超级管理员可用
require_super_admin();

$db = get_db();
$action = $_GET['action'] ?? 'list';
$roleId = (int)($_GET['role_id'] ?? 0);
$errors = [];

// 删除：仅接受 POST（CSRF 由 admin-init.php 对所有 POST 统一校验）
// 注意：必须置于下方泛化 POST 保存分支之前，命中后直接 redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $delRoleId = (int)($_POST['role_id'] ?? 0);
    if ($delRoleId > 0) {
        // 内置角色（播种角色）不可删除：删除后授权写入会静默失效（保存角色后仍为普通用户）
        $stmt = $db->prepare("SELECT name FROM roles WHERE id = :id");
        $stmt->execute([':id' => $delRoleId]);
        $delName = (string)$stmt->fetchColumn();
        if (in_array($delName, ['community_admin', 'moderator', 'vip'], true)) {
            set_flash(t('admin_roles_cannot_delete_builtin', '内置权限组（社区管理员/版主/VIP）不能删除。'), 'error');
        } else {
            // 级联清理：先移除用户关联，再删角色，避免 user_roles 残留脏数据
            $db->prepare("DELETE FROM user_roles WHERE role_id = :id")->execute([':id' => $delRoleId]);
            $db->prepare("DELETE FROM roles WHERE id = :id")->execute([':id' => $delRoleId]);
            set_flash(t('admin_roles_flash_deleted', '权限组已删除。'), 'success');
        }
    }
    redirect('/admin/roles');
}
// 旧 GET 删除链接命中：不执行删除，提示刷新
if ($action === 'delete') {
    set_flash(t('post_flash_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
    redirect('/admin/roles');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $name = trim($_POST['name'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');

    if (empty($name) || empty($displayName)) {
        $errors[] = t('admin_roles_err_name_required', '名称和显示名称不能为空。');
    } elseif (!preg_match('/^[a-z0-9_]+$/', $name)) {
        $errors[] = t('admin_roles_err_name_format', '权限组标识只能包含小写字母、数字和下划线。');
    }

    if (empty($errors)) {
        if ($roleId > 0) {
            // 内置角色（community_admin/moderator/vip）标识不可修改，仅允许调整显示名称
            $stmt = $db->prepare("SELECT name FROM roles WHERE id = :id");
            $stmt->execute([':id' => $roleId]);
            $origName = (string)$stmt->fetchColumn();
            if (in_array($origName, ['community_admin', 'moderator', 'vip'], true)) {
                $name = $origName;
            }
            // 编辑仅更新标识与显示名称；permissions 保持原值（权限点由系统播种管理，不在此配置）
            $stmt = $db->prepare("UPDATE roles SET name = :name, display_name = :display_name WHERE id = :id");
            $stmt->execute([':name' => $name, ':display_name' => $displayName, ':id' => $roleId]);
            set_flash(t('admin_roles_flash_updated', '权限组已更新。'), 'success');
        } else {
            // 新增角色不带权限点（权限点不在此配置）
            $stmt = $db->prepare("INSERT INTO roles (name, display_name, permissions) VALUES (:name, :display_name, '')");
            $stmt->execute([':name' => $name, ':display_name' => $displayName]);
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
            <p class="form-hint"><?php echo e(t('admin_roles_hint_no_permissions', '权限点由系统内置管理：超级管理员天然拥有全部权限，社区管理员等内置角色的权限由系统固定，不在此页面配置。')); ?></p>
            <button type="submit" class="btn btn-primary"><?php echo e(t('admin_roles_save', '保存')); ?></button>
            <a href="<?php echo site_url('admin/roles'); ?>" class="btn btn-secondary"><?php echo e(t('admin_roles_back', '返回')); ?></a>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr><th><?php echo e(t('admin_roles_th_id', 'ID')); ?></th><th><?php echo e(t('admin_roles_th_name', '标识')); ?></th><th><?php echo e(t('admin_roles_th_display_name', '显示名称')); ?></th><th><?php echo e(t('admin_roles_th_actions', '操作')); ?></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $r): ?>
                        <tr>
                            <td><?php echo $r['id']; ?></td>
                            <td><?php echo e($r['name']); ?></td>
                            <td><?php echo e($r['display_name']); ?></td>
                            <td>
                                <a href="<?php echo site_url('admin/roles', ['action' => 'edit', 'role_id' => (int)$r['id']]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('admin_roles_action_edit', '编辑')); ?></a>
                                <?php echo admin_action_form(site_url('admin/roles'), 'delete', ['role_id' => (int)$r['id']], t('admin_roles_action_delete', '删除'), ['class' => 'btn btn-sm btn-danger', 'confirm' => t('admin_roles_confirm_delete', "确定删除该权限组吗？\n已分配该权限组的用户也会失去对应权限。")] ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
