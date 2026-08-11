<?php
/**
 * 云界论坛 - 管理后台公告管理
 *
 * 公告的添加 / 编辑 / 删除 / 启用禁用切换 / 排序
 */

$activeMenu = 'announcements';
require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：公告管理仅超级管理员可用
require_super_admin();

$pageTitle = t('admin_ann_title', '公告管理');

$db = get_db();
$action = $_GET['action'] ?? 'list';
$annId = (int)($_GET['id'] ?? 0);
$errors = [];

// 删除/启用禁用切换：仅接受 POST（CSRF 由 admin-init.php 对所有 POST 统一校验）
// 注意：必须置于下方泛化 POST 保存分支之前，命中后直接 redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['delete', 'toggle'], true)) {
    $postAnnId = (int)($_POST['id'] ?? 0);
    if ($postAnnId > 0) {
        if ($_POST['action'] === 'delete') {
            $db->prepare("DELETE FROM announcements WHERE id = :id")->execute([':id' => $postAnnId]);
            set_flash(t('admin_ann_flash_deleted', '公告已删除。'), 'success');
        } else {
            $stmt = $db->prepare("UPDATE announcements SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id");
            $stmt->execute([':id' => $postAnnId]);
            set_flash(t('admin_ann_flash_status_updated', '公告状态已更新。'), 'success');
        }
    }
    redirect('/admin/announcements');
}
// 旧 GET 写操作链接命中：不执行写操作，提示刷新
if (in_array($action, ['delete', 'toggle'], true)) {
    set_flash(t('post_flash_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
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
                                    <?php echo admin_action_form(site_url('admin/announcements'), 'toggle', ['id' => (int)$a['id']], (int)$a['is_active'] === 1 ? t('admin_ann_action_disable', '禁用') : t('admin_ann_action_enable', '启用'), ['class' => 'btn btn-sm btn-secondary']); ?>
                                    <a href="<?php echo site_url('admin/announcements', ['action' => 'edit', 'id' => (int)$a['id']]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('admin_ann_action_edit', '编辑')); ?></a>
                                    <?php echo admin_action_form(site_url('admin/announcements'), 'delete', ['id' => (int)$a['id']], t('admin_ann_action_delete', '删除'), ['class' => 'btn btn-sm btn-danger', 'confirm' => t('admin_ann_confirm_delete', '确定删除该公告吗？此操作不可撤销。')]); ?>
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
