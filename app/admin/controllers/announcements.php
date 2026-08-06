<?php
/**
 * 云界论坛 - 管理后台公告管理
 *
 * 公告的添加 / 编辑 / 删除 / 启用禁用切换 / 排序
 */

$activeMenu = 'announcements';
require_once dirname(__DIR__) . '/layout/admin-init.php';
$pageTitle = t('admin_ann_title', '公告管理');

$db = get_db();
$action = $_GET['action'] ?? 'list';
$annId = (int)($_GET['id'] ?? 0);
$errors = [];

// CSRF 统一校验：涉及状态变更的 GET 操作必须先通过校验，失败时明确提示（避免静默失败）
if (in_array($action, ['delete', 'toggle'], true) && !validate_csrf()) {
    set_flash(t('admin_ann_csrf_failed', '安全校验失败（链接已过期），请刷新页面后重新操作。'), 'error');
    redirect('/admin/announcements');
}

// GET: 删除公告
if ($action === 'delete' && $annId > 0) {
    $db->prepare("DELETE FROM announcements WHERE id = :id")->execute([':id' => $annId]);
    set_flash(t('admin_ann_flash_deleted', '公告已删除。'), 'success');
    redirect('/admin/announcements');
}

// GET: 启用/禁用切换
if ($action === 'toggle' && $annId > 0) {
    $stmt = $db->prepare("UPDATE announcements SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id");
    $stmt->execute([':id' => $annId]);
    set_flash(t('admin_ann_flash_status_updated', '公告状态已更新。'), 'success');
    redirect('/admin/announcements');
}

// POST: 保存公告（新增或编辑）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $displayOrder = (int)($_POST['display_order'] ?? 0);
    $editId = (int)($_POST['id'] ?? 0);

    if ($title === '') {
        $errors[] = t('admin_ann_err_title_required', '公告标题不能为空。');
    }
    if ($content === '') {
        $errors[] = t('admin_ann_err_content_required', '公告内容不能为空。');
    }

    if (empty($errors)) {
        if ($editId > 0) {
            $stmt = $db->prepare("UPDATE announcements SET title = :title, content = :content, is_active = :active, display_order = :ord WHERE id = :id");
            $stmt->execute([':title' => $title, ':content' => $content, ':active' => $isActive, ':ord' => $displayOrder, ':id' => $editId]);
            set_flash(t('admin_ann_flash_updated', '公告已更新。'), 'success');
        } else {
            $stmt = $db->prepare("INSERT INTO announcements (title, content, is_active, display_order) VALUES (:title, :content, :active, :ord)");
            $stmt->execute([':title' => $title, ':content' => $content, ':active' => $isActive, ':ord' => $displayOrder]);
            set_flash(t('admin_ann_flash_created', '公告已创建。'), 'success');
        }
        redirect('/admin/announcements');
    }
    // 校验失败时回到表单
    $action = $editId > 0 ? 'edit' : 'add';
    $annId = $editId;
}

// 加载编辑中的公告
$editingAnnouncement = null;
if ($action === 'edit' && $annId > 0) {
    $stmt = $db->prepare("SELECT * FROM announcements WHERE id = :id");
    $stmt->execute([':id' => $annId]);
    $editingAnnouncement = $stmt->fetch();
    if (!$editingAnnouncement) {
        set_flash(t('admin_ann_flash_not_found', '公告不存在。'), 'error');
        redirect('/admin/announcements');
    }
}

$announcements = $db->query("SELECT * FROM announcements ORDER BY display_order ASC, created_at DESC")->fetchAll();

$showForm = in_array($action, ['add', 'edit'], true);
// 新增时默认启用
$defaultActive = $action === 'add' ? 1 : 0;

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_ann_title', '公告管理')); ?></h1>
    <?php if (!$showForm): ?>
        <a href="<?php echo site_url('admin/announcements', ['action' => 'add']); ?>" class="btn btn-primary"><?php echo e(t('admin_ann_add', '新增公告')); ?></a>
    <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
        <?php echo show_message($err, 'error'); ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($showForm): ?>
    <div class="card">
        <h2 class="card-title mb-1"><?php echo $editingAnnouncement ? e(t('admin_ann_edit', '编辑公告')) : e(t('admin_ann_add', '新增公告')); ?></h2>
        <form method="POST" action="<?php echo site_url('admin/announcements', $editingAnnouncement ? ['action' => 'edit', 'id' => (int)$editingAnnouncement['id']] : ['action' => 'add']); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="id" value="<?php echo $editingAnnouncement ? (int)$editingAnnouncement['id'] : 0; ?>">
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_ann_label_title', '标题')); ?></label>
                <input type="text" class="form-control" name="title" value="<?php echo e($editingAnnouncement['title'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_ann_label_content', '内容')); ?></label>
                <textarea class="form-control" name="content" rows="6" required><?php echo e($editingAnnouncement['content'] ?? ''); ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label" style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                    <input type="checkbox" name="is_active" value="1" <?php echo ($editingAnnouncement ? (int)$editingAnnouncement['is_active'] : $defaultActive) ? 'checked' : ''; ?>>
                    <?php echo e(t('admin_ann_label_enable', '启用公告')); ?>
                </label>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_ann_label_order', '排序值（越小越靠前）')); ?></label>
                <input type="number" class="form-control" name="display_order" value="<?php echo $editingAnnouncement ? (int)$editingAnnouncement['display_order'] : 0; ?>">
            </div>
            <button type="submit" class="btn btn-primary"><?php echo e(t('admin_ann_save', '保存')); ?></button>
            <a href="<?php echo site_url('admin/announcements'); ?>" class="btn btn-secondary"><?php echo e(t('admin_ann_back', '返回')); ?></a>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <?php if (empty($announcements)): ?>
            <p><?php echo e(t('admin_ann_empty', '暂无公告，点击右上角"新增公告"创建。')); ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr><th><?php echo e(t('admin_ann_th_id', 'ID')); ?></th><th><?php echo e(t('admin_ann_th_title', '标题')); ?></th><th><?php echo e(t('admin_ann_th_status', '状态')); ?></th><th><?php echo e(t('admin_ann_th_order', '排序')); ?></th><th><?php echo e(t('admin_ann_th_created', '创建时间')); ?></th><th><?php echo e(t('admin_ann_th_actions', '操作')); ?></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($announcements as $a): ?>
                            <tr>
                                <td><?php echo (int)$a['id']; ?></td>
                                <td><?php echo e($a['title']); ?></td>
                                <td>
                                    <?php if ((int)$a['is_active'] === 1): ?>
                                        <span class="badge badge-success"><?php echo e(t('admin_ann_status_enabled', '启用')); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-warning"><?php echo e(t('admin_ann_status_disabled', '禁用')); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$a['display_order']; ?></td>
                                <td><?php echo e(date('Y-m-d H:i', db_time($a['created_at']))); ?></td>
                                <td>
                                    <a href="<?php echo site_url('admin/announcements', ['action' => 'toggle', 'id' => (int)$a['id'], 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-secondary"><?php echo (int)$a['is_active'] === 1 ? e(t('admin_ann_action_disable', '禁用')) : e(t('admin_ann_action_enable', '启用')); ?></a>
                                    <a href="<?php echo site_url('admin/announcements', ['action' => 'edit', 'id' => (int)$a['id']]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('admin_ann_action_edit', '编辑')); ?></a>
                                    <a href="<?php echo site_url('admin/announcements', ['action' => 'delete', 'id' => (int)$a['id'], 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-danger" data-confirm="<?php echo e(t('admin_ann_confirm_delete', '确定删除该公告吗？此操作不可撤销。\"')); ?>><?php echo e(t('admin_ann_action_delete', '删除')); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
