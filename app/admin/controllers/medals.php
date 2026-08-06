<?php
/**
 * 云界论坛 - 管理后台勋章管理
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$db = get_db();
$action = $_GET['action'] ?? 'list';
$medalId = (int)($_GET['medal_id'] ?? 0);
$errors = [];

// CSRF 统一校验：涉及状态变更的 GET 操作必须先通过校验，失败时明确提示（避免静默失败）
if ($action === 'delete' && !validate_csrf()) {
    set_flash(t('admin_medals_csrf_failed', '安全校验失败（链接已过期），请刷新页面后重新操作。'), 'error');
    redirect('/admin/medals');
}

if ($action === 'delete' && $medalId > 0) {
    $db->prepare("DELETE FROM medals WHERE id = :id")->execute([':id' => $medalId]);
    set_flash(t('admin_medals_flash_deleted', '勋章已删除。'), 'success');
    redirect('/admin/medals');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $name = trim($_POST['name'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $color = trim($_POST['color'] ?? '#3b82f6');
    $icon = trim($_POST['icon'] ?? 'award');

    if (empty($name) || empty($displayName)) {
        $errors[] = t('admin_medals_err_name_required', '名称和显示名称不能为空。');
    } elseif (!preg_match('/^[a-z0-9_]+$/', $name)) {
        $errors[] = t('admin_medals_err_name_format', '勋章标识只能包含小写字母、数字和下划线。');
    }

    if (empty($errors)) {
        if ($medalId > 0) {
            $stmt = $db->prepare("UPDATE medals SET name = :name, display_name = :display_name, description = :description, color = :color, icon = :icon WHERE id = :id");
            $stmt->execute([':name' => $name, ':display_name' => $displayName, ':description' => $description, ':color' => $color, ':icon' => $icon, ':id' => $medalId]);
            set_flash(t('admin_medals_flash_updated', '勋章已更新。'), 'success');
        } else {
            $stmt = $db->prepare("INSERT INTO medals (name, display_name, description, color, icon) VALUES (:name, :display_name, :description, :color, :icon)");
            $stmt->execute([':name' => $name, ':display_name' => $displayName, ':description' => $description, ':color' => $color, ':icon' => $icon]);
            set_flash(t('admin_medals_flash_created', '勋章已创建。'), 'success');
        }
        redirect('/admin/medals');
    }
}

$medal = null;
if ($action === 'edit' && $medalId > 0) {
    $stmt = $db->prepare("SELECT * FROM medals WHERE id = :id");
    $stmt->execute([':id' => $medalId]);
    $medal = $stmt->fetch();
}

$medals = $db->query("SELECT * FROM medals ORDER BY display_name")->fetchAll();

$pageTitle = t('admin_medals_title', '勋章管理');
$activeMenu = 'medals';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_medals_title', '勋章管理')); ?></h1>
    <?php if ($action !== 'edit' && $action !== 'add'): ?>
        <a href="<?php echo site_url('admin/medals', ['action' => 'add']); ?>" class="btn btn-primary"><?php echo e(t('admin_medals_add', '新增勋章')); ?></a>
    <?php endif; ?>
</div>

<?php if ($action === 'edit' || $action === 'add'): ?>
    <div class="card">
        <h2 class="card-title mb-1"><?php echo $medal ? e(t('admin_medals_edit', '编辑勋章')) : e(t('admin_medals_add', '新增勋章')); ?></h2>
        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <?php echo show_message($err, 'error'); ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <form method="POST" action="<?php echo site_url('admin/medals', $medal ? ['action' => 'edit', 'medal_id' => (int)$medal['id']] : ['action' => 'add']); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_medals_label_name', '勋章标识')); ?></label>
                <input type="text" class="form-control" name="name" value="<?php echo e($medal['name'] ?? ''); ?>" required placeholder="<?php echo e(t('admin_medals_ph_name', '例如：early_bird\"')); ?>>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_medals_label_display_name', '显示名称')); ?></label>
                <input type="text" class="form-control" name="display_name" value="<?php echo e($medal['display_name'] ?? ''); ?>" required placeholder="<?php echo e(t('admin_medals_ph_display_name', '例如：早鸟勋章\"')); ?>>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_medals_label_desc', '描述')); ?></label>
                <input type="text" class="form-control" name="description" value="<?php echo e($medal['description'] ?? ''); ?>" placeholder="<?php echo e(t('admin_medals_ph_desc', '简短描述...\"')); ?>>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_medals_label_color', '颜色')); ?></label>
                <input type="color" class="form-control" name="color" value="<?php echo e($medal['color'] ?? '#3b82f6'); ?>" style="padding: 0; height: 44px;">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_medals_label_icon', '图标')); ?></label>
                <input type="text" class="form-control" name="icon" value="<?php echo e($medal['icon'] ?? 'award'); ?>" maxlength="50">
                <p class="form-hint"><?php echo e(t('admin_medals_hint_icon', '填写 ui_icon 图标名，例如：star、shield、crown、diamond、award、trophy、heart')); ?></p>
            </div>
            <button type="submit" class="btn btn-primary"><?php echo e(t('admin_medals_save', '保存')); ?></button>
            <a href="<?php echo site_url('admin/medals'); ?>" class="btn btn-secondary"><?php echo e(t('admin_medals_back', '返回')); ?></a>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr><th><?php echo e(t('admin_medals_th_id', 'ID')); ?></th><th><?php echo e(t('admin_medals_th_name', '标识')); ?></th><th><?php echo e(t('admin_medals_th_display_name', '显示名称')); ?></th><th><?php echo e(t('admin_medals_th_style', '样式')); ?></th><th><?php echo e(t('admin_medals_th_actions', '操作')); ?></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($medals as $m): ?>
                        <tr>
                            <td><?php echo $m['id']; ?></td>
                            <td><?php echo e($m['name']); ?></td>
                            <td><?php echo e($m['display_name']); ?></td>
                            <td>
                                <span class="profile-medal" style="color: <?php echo e($m['color']); ?>; border-color: <?php echo e($m['color']); ?>">
                                    <?php echo ui_icon($m['icon'], 12); ?> <?php echo e($m['display_name']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo site_url('admin/medals', ['action' => 'edit', 'medal_id' => (int)$m['id']]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('admin_medals_action_edit', '编辑')); ?></a>
                                <a href="<?php echo site_url('admin/medals', ['action' => 'delete', 'medal_id' => (int)$m['id'], 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-danger" data-confirm="<?php echo e(t('admin_medals_confirm_delete', "确定删除该勋章吗？\n已授予该勋章的用户记录也会被一并移除。")); ?>"><?php echo e(t('admin_medals_action_delete', '删除')); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
