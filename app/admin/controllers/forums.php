<?php
/**
 * 云界论坛 - 管理后台版块管理
 *
 * 管理版块分类与版块：
 * - 分类：添加 / 编辑 / 删除 / 排序
 * - 版块：添加 / 编辑 / 删除 / 排序（删除版块时将其下帖子的 forum_id 置空，不删帖子）
 */

$activeMenu = 'forums';
require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：版块管理仅超级管理员可用
require_super_admin();

$pageTitle = t('admin_forums_title', '版块管理');

$db = get_db();
$action = $_GET['action'] ?? 'list';
$forumId = (int)($_GET['id'] ?? 0);
$categoryId = (int)($_GET['cat_id'] ?? 0);
$errors = [];

// 删除动作：仅接受 POST（CSRF 由 admin-init.php 对所有 POST 统一校验）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['delete', 'delete_category'], true)) {
    if (($_POST['action'] ?? '') === 'delete') {
        // 删除版块（先将其下帖子的 forum_id 置空，再删除版块）
        $delForumId = (int)($_POST['id'] ?? 0);
        if ($delForumId > 0) {
            $db->prepare("UPDATE posts SET forum_id = NULL WHERE forum_id = :fid")->execute([':fid' => $delForumId]);
            $db->prepare("DELETE FROM forums WHERE id = :id")->execute([':id' => $delForumId]);
            set_flash(t('admin_forums_flash_deleted', '版块已删除，相关帖子已转为未分类。'), 'success');
        }
    } else {
        // 删除分类（一并清理其下版块，相关帖子 forum_id 置空）
        $delCatId = (int)($_POST['cat_id'] ?? 0);
        if ($delCatId > 0) {
            $db->prepare("UPDATE posts SET forum_id = NULL WHERE forum_id IN (SELECT id FROM forums WHERE category_id = :cid)")->execute([':cid' => $delCatId]);
            $db->prepare("DELETE FROM forums WHERE category_id = :cid")->execute([':cid' => $delCatId]);
            $db->prepare("DELETE FROM forum_categories WHERE id = :id")->execute([':id' => $delCatId]);
            set_flash(t('admin_forums_flash_cat_deleted', '分类及其下版块已删除。'), 'success');
        }
    }
    redirect('/admin/forums');
}
// 旧 GET 删除链接命中：不执行删除，提示刷新
if (in_array($action, ['delete', 'delete_category'], true)) {
    set_flash(t('post_flash_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
    redirect('/admin/forums');
}

// POST: 保存版块
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'save' && validate_csrf()) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $rawIcon = trim($_POST['icon'] ?? '');
    $catId = (int)($_POST['category_id'] ?? 0);
    $displayOrder = (int)($_POST['display_order'] ?? 0);
    $editId = (int)($_POST['id'] ?? 0);

    // 无效图标自动按名称推断
    $icon = normalize_forum_icon($rawIcon, $name);

    if ($name === '') {
        $errors[] = t('admin_forums_err_name_required', '版块名称不能为空。');
    }
    $catCheck = $db->prepare("SELECT id FROM forum_categories WHERE id = :id");
    $catCheck->execute([':id' => $catId]);
    if (!$catCheck->fetch()) {
        $errors[] = t('admin_forums_err_invalid_cat', '请选择有效的分类。');
    }

    if (empty($errors)) {
        if ($editId > 0) {
            $stmt = $db->prepare("UPDATE forums SET category_id = :cat, name = :name, description = :desc, icon = :icon, display_order = :ord WHERE id = :id");
            $stmt->execute([':cat' => $catId, ':name' => $name, ':desc' => $description, ':icon' => $icon, ':ord' => $displayOrder, ':id' => $editId]);
            set_flash(t('admin_forums_flash_updated', '版块已更新。'), 'success');
        } else {
            $stmt = $db->prepare("INSERT INTO forums (category_id, name, description, icon, display_order) VALUES (:cat, :name, :desc, :icon, :ord)");
            $stmt->execute([':cat' => $catId, ':name' => $name, ':desc' => $description, ':icon' => $icon, ':ord' => $displayOrder]);
            set_flash(t('admin_forums_flash_created', '版块已创建。'), 'success');
        }
        redirect('/admin/forums');
    }
    // 校验失败时回到表单
    $action = $editId > 0 ? 'edit' : 'add';
    $forumId = $editId;
}

// POST: 保存分类
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'save_category' && validate_csrf()) {
    $catName = trim($_POST['category_name'] ?? '');
    $catOrder = (int)($_POST['category_order'] ?? 0);
    $editCatId = (int)($_POST['cat_id'] ?? 0);

    if ($catName === '') {
        $errors[] = t('admin_forums_err_cat_name_required', '分类名称不能为空。');
    }

    if (empty($errors)) {
        if ($editCatId > 0) {
            $stmt = $db->prepare("UPDATE forum_categories SET name = :name, display_order = :ord WHERE id = :id");
            $stmt->execute([':name' => $catName, ':ord' => $catOrder, ':id' => $editCatId]);
            set_flash(t('admin_forums_flash_cat_updated', '分类已更新。'), 'success');
        } else {
            $stmt = $db->prepare("INSERT INTO forum_categories (name, display_order) VALUES (:name, :ord)");
            $stmt->execute([':name' => $catName, ':ord' => $catOrder]);
            set_flash(t('admin_forums_flash_cat_created', '分类已创建。'), 'success');
        }
        redirect('/admin/forums');
    }
    $action = $editCatId > 0 ? 'edit_category' : 'add_category';
    $categoryId = $editCatId;
}

// 加载编辑中的版块
$editingForum = null;
if ($action === 'edit' && $forumId > 0) {
    $stmt = $db->prepare("SELECT * FROM forums WHERE id = :id");
    $stmt->execute([':id' => $forumId]);
    $editingForum = $stmt->fetch();
    if (!$editingForum) {
        set_flash(t('admin_forums_flash_not_found', '版块不存在。'), 'error');
        redirect('/admin/forums');
    }
    // 旧版 emoji 或无效图标自动规范化为 key
    $editingForum['icon'] = normalize_forum_icon($editingForum['icon'] ?? null, $editingForum['name'] ?? '');
}

// 加载编辑中的分类
$editingCategory = null;
if ($action === 'edit_category' && $categoryId > 0) {
    $stmt = $db->prepare("SELECT * FROM forum_categories WHERE id = :id");
    $stmt->execute([':id' => $categoryId]);
    $editingCategory = $stmt->fetch();
    if (!$editingCategory) {
        set_flash(t('admin_forums_flash_cat_not_found', '分类不存在。'), 'error');
        redirect('/admin/forums');
    }
}

// 加载列表数据：分类 + 各分类下的版块（单次 JOIN 查询）
$categories = $db->query("SELECT * FROM forum_categories ORDER BY display_order ASC, id ASC")->fetchAll();
$allForums = $db->query("SELECT f.*, fc.name AS category_name FROM forums f JOIN forum_categories fc ON fc.id = f.category_id ORDER BY fc.display_order ASC, fc.id ASC, f.display_order ASC, f.id ASC")->fetchAll();
$forumsByCat = [];
// 初始化所有分类为空数组
foreach ($categories as $cat) {
    $forumsByCat[$cat['id']] = [];
}
// 按 category_id 分组
foreach ($allForums as $forum) {
    $forumsByCat[$forum['category_id']][] = $forum;
}

// 可选图标列表（键 => 中文标签）
$iconOptions = forum_icon_options();

$showForm = in_array($action, ['add', 'edit', 'add_category', 'edit_category'], true);

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_forums_title', '版块管理')); ?></h1>
    <?php if (!$showForm): ?>
        <div>
            <a href="<?php echo site_url('admin/forums', ['action' => 'add_category']); ?>" class="btn btn-secondary"><?php echo e(t('admin_forums_add_category', '新增分类')); ?></a>
            <a href="<?php echo site_url('admin/forums', ['action' => 'add']); ?>" class="btn btn-primary"><?php echo e(t('admin_forums_add_forum', '新增版块')); ?></a>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
        <?php echo show_message($err, 'error'); ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="card">
        <h2 class="card-title mb-1"><?php echo $editingForum ? e(t('admin_forums_edit_forum', '编辑版块')) : e(t('admin_forums_add_forum', '新增版块')); ?></h2>
        <?php if (empty($categories)): ?>
            <?php echo show_message(t('admin_forums_need_category_first', '请先创建至少一个分类后再添加版块。'), 'warning'); ?>
            <a href="<?php echo site_url('admin/forums', ['action' => 'add_category']); ?>" class="btn btn-secondary"><?php echo e(t('admin_forums_go_create_cat', '去创建分类')); ?></a>
        <?php else: ?>
        <form method="POST" action="<?php echo site_url('admin/forums'); ?>">
            <input type="hidden" name="do" value="save">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="id" value="<?php echo $editingForum ? (int)$editingForum['id'] : 0; ?>">
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_forums_label_parent_cat', '所属分类')); ?></label>
                <select class="form-control" name="category_id" required>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo $editingForum && (int)$editingForum['category_id'] === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo e($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_forums_label_name', '版块名称')); ?></label>
                <input type="text" class="form-control" name="name" id="forum-name" value="<?php echo e($editingForum['name'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_forums_label_desc', '描述')); ?></label>
                <input type="text" class="form-control" name="description" value="<?php echo e($editingForum['description'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_forums_label_icon', '图标')); ?></label>
                <input type="hidden" class="form-control" name="icon" id="forum-icon" value="<?php echo e($editingForum['icon'] ?? 'folder'); ?>">
                <div class="icon-preview" id="icon-preview">
                    <span class="icon-preview-box"><?php echo forum_icon($editingForum['icon'] ?? null, 28, $editingForum['name'] ?? ''); ?></span>
                    <span class="icon-preview-label" id="icon-preview-label"><?php echo e($iconOptions[$editingForum['icon'] ?? ''] ?? t('admin_forums_icon_auto', '自动匹配')); ?></span>
                    <button type="button" class="btn btn-sm btn-secondary" id="auto-suggest-icon" title="<?php echo e(t('admin_forums_suggest_icon_title', '根据版块名称自动推荐图标')); ?>"><?php echo e(t('admin_forums_suggest_icon', '智能推荐')); ?></button>
                </div>
                <p class="form-hint"><?php echo e(t('admin_forums_icon_hint', '从下方图标库中选择，或点击「智能推荐」根据版块名称匹配最合适的图标。')); ?></p>
                <div class="icon-picker" id="icon-picker">
                    <?php foreach ($iconOptions as $key => $label): ?>
                        <button type="button" class="icon-picker-item" data-icon="<?php echo e($key); ?>" data-label="<?php echo e($label); ?>" title="<?php echo e($label); ?>">
                            <span class="icon-picker-svg"><?php echo forum_icon($key, 22); ?></span>
                            <span class="icon-picker-label"><?php echo e($label); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_forums_label_order', '排序值（越小越靠前）')); ?></label>
                <input type="number" class="form-control" name="display_order" value="<?php echo $editingForum ? (int)$editingForum['display_order'] : 0; ?>">
            </div>
            <button type="submit" class="btn btn-primary"><?php echo e(t('admin_forums_save', '保存')); ?></button>
            <a href="<?php echo site_url('admin/forums'); ?>" class="btn btn-secondary"><?php echo e(t('admin_forums_back', '返回')); ?></a>
        </form>
        <?php endif; ?>
    </div>
<?php elseif ($action === 'add_category' || $action === 'edit_category'): ?>
    <div class="card">
        <h2 class="card-title mb-1"><?php echo $editingCategory ? e(t('admin_forums_edit_category', '编辑分类')) : e(t('admin_forums_add_category', '新增分类')); ?></h2>
        <form method="POST" action="<?php echo site_url('admin/forums'); ?>">
            <input type="hidden" name="do" value="save_category">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="cat_id" value="<?php echo $editingCategory ? (int)$editingCategory['id'] : 0; ?>">
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_forums_label_cat_name', '分类名称')); ?></label>
                <input type="text" class="form-control" name="category_name" value="<?php echo e($editingCategory['name'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_forums_label_order', '排序值（越小越靠前）')); ?></label>
                <input type="number" class="form-control" name="category_order" value="<?php echo $editingCategory ? (int)$editingCategory['display_order'] : 0; ?>">
            </div>
            <button type="submit" class="btn btn-primary"><?php echo e(t('admin_forums_save', '保存')); ?></button>
            <a href="<?php echo site_url('admin/forums'); ?>" class="btn btn-secondary"><?php echo e(t('admin_forums_back', '返回')); ?></a>
        </form>
    </div>
<?php else: ?>
    <?php if (empty($categories)): ?>
        <div class="card">
            <p><?php echo e(t('admin_forums_empty_cats', '暂无分类，请先创建分类。')); ?></p>
            <a href="<?php echo site_url('admin/forums', ['action' => 'add_category']); ?>" class="btn btn-primary"><?php echo e(t('admin_forums_add_category', '新增分类')); ?></a>
        </div>
    <?php else: ?>
        <?php foreach ($categories as $cat): ?>
            <?php $catForums = $forumsByCat[$cat['id']] ?? []; ?>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 8px;">
                    <h2 class="card-title"><?php echo e($cat['name']); ?> <small style="color: #888; font-weight: normal; font-size: 0.85em;"><?php echo e(t('admin_forums_cat_order', '排序: {order}', ['order' => (int)$cat['display_order']])); ?></small></h2>
                    <div>
                        <a href="<?php echo site_url('admin/forums', ['action' => 'edit_category', 'cat_id' => (int)$cat['id']]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('admin_forums_edit_category', '编辑分类')); ?></a>
                        <?php echo admin_action_form(site_url('admin/forums'), 'delete_category', ['cat_id' => (int)$cat['id']], t('admin_forums_delete_category', '删除分类'), ['class' => 'btn btn-sm btn-danger', 'confirm' => t('admin_forums_confirm_delete_cat', "确定删除分类「{name}」吗？\n其下所有版块将被删除，相关帖子将转为未分类。", ['name' => $cat['name']])]); ?>
                    </div>
                </div>
                <?php if (empty($catForums)): ?>
                    <p style="color: #888;"><?php echo e(t('admin_forums_no_forums_in_cat', '该分类下暂无版块。')); ?></p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th><?php echo e(t('admin_forums_th_id', 'ID')); ?></th><th><?php echo e(t('admin_forums_th_icon', '图标')); ?></th><th><?php echo e(t('admin_forums_th_name', '版块名称')); ?></th><th><?php echo e(t('admin_forums_th_desc', '描述')); ?></th><th><?php echo e(t('admin_forums_th_order', '排序')); ?></th><th><?php echo e(t('admin_forums_th_threads', '主题数')); ?></th><th><?php echo e(t('admin_forums_th_actions', '操作')); ?></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($catForums as $f): ?>
                                    <tr>
                                        <td><?php echo (int)$f['id']; ?></td>
                                        <td><span class="post-meta-icon"><?php echo forum_icon($f['icon'], 20, $f['name']); ?></span></td>
                                        <td><?php echo e($f['name']); ?></td>
                                        <td><?php echo e($f['description']); ?></td>
                                        <td><?php echo (int)$f['display_order']; ?></td>
                                        <td><?php echo (int)$f['threads_count']; ?></td>
                                        <td>
                                            <a href="<?php echo site_url('admin/forums', ['action' => 'edit', 'id' => (int)$f['id']]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('admin_forums_edit', '编辑')); ?></a>
                                            <?php echo admin_action_form(site_url('admin/forums'), 'delete', ['id' => (int)$f['id']], t('admin_forums_delete', '删除'), ['class' => 'btn btn-sm btn-danger', 'confirm' => t('admin_forums_confirm_delete', "确定删除版块「{name}」吗？\n该版块下的帖子将转为未分类（不会被删除）。", ['name' => $f['name']])]); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<script>
(function() {
    var input = document.getElementById('forum-icon');
    if (!input) return;
    var preview = document.getElementById('icon-preview');
    var previewBox = preview ? preview.querySelector('.icon-preview-box') : null;
    var previewLabel = document.getElementById('icon-preview-label');
    var nameInput = document.getElementById('forum-name');
    var suggestBtn = document.getElementById('auto-suggest-icon');
    var items = document.querySelectorAll('.icon-picker-item');

    // 版块名称关键字 → 推荐图标映射（顺序优先）
    var suggestRules = [
        { keys: [<?php echo json_encode(t('admin_forums_3f9569','公告')); ?>, <?php echo json_encode(t('admin_forums_7a66c0','通知')); ?>, <?php echo json_encode(t('admin_forums_753ccc','动态')); ?>, <?php echo json_encode(t('admin_forums_210ce7','官方')); ?>, 'news', 'notice'], icon: 'announcement' },
        { keys: [<?php echo json_encode(t('admin_forums_663800','后端')); ?>, <?php echo json_encode(t('admin_forums_30d94c','服务器')); ?>, 'python', 'java', 'go', 'rust', 'node', 'php', 'backend'], icon: 'code' },
        { keys: [<?php echo json_encode(t('admin_forums_7a19a8','前端')); ?>, <?php echo json_encode(t('admin_forums_06caf5','网页')); ?>, 'css', 'html', 'javascript', 'js', 'vue', 'react', 'frontend', 'ui'], icon: 'design' },
        { keys: [<?php echo json_encode(t('admin_forums_f4dbbc','数据库')); ?>, 'mysql', 'sqlite', 'redis', 'mongo', 'sql', 'db'], icon: 'database' },
        { keys: [<?php echo json_encode(t('admin_forums_33246d','代码')); ?>, <?php echo json_encode(t('admin_forums_3b70f4','编程')); ?>, <?php echo json_encode(t('admin_forums_eb3774','开发')); ?>, <?php echo json_encode(t('admin_forums_1a68c2','程序')); ?>, 'coding', 'dev'], icon: 'code' },
        { keys: [<?php echo json_encode(t('admin_forums_b4cd99','硬件')); ?>, 'cpu', <?php echo json_encode(t('admin_forums_3d639f','显卡')); ?>, <?php echo json_encode(t('admin_forums_7fef5a','主板')); ?>, <?php echo json_encode(t('admin_forums_d014ab','内存')); ?>, <?php echo json_encode(t('admin_forums_90312d','硬盘')); ?>, <?php echo json_encode(t('admin_forums_76ecf2','组装')); ?>], icon: 'cpu' },
        { keys: [<?php echo json_encode(t('admin_forums_0cbda6','网络')); ?>, <?php echo json_encode(t('admin_forums_4ed766','互联网')); ?>, 'http', 'tcp', <?php echo json_encode(t('admin_forums_19da0c','路由')); ?>, 'wifi', 'network'], icon: 'globe' },
        { keys: [<?php echo json_encode(t('admin_forums_2ea617','云')); ?>, 'cloud', 'aws', <?php echo json_encode(t('admin_forums_20ef37','阿里云')); ?>, <?php echo json_encode(t('admin_forums_88f4c7','腾讯云')); ?>, <?php echo json_encode(t('admin_forums_3220a1','部署')); ?>, <?php echo json_encode(t('admin_forums_57bd58','运维')); ?>], icon: 'cloud' },
        { keys: [<?php echo json_encode(t('admin_forums_8e662a','安全')); ?>, <?php echo json_encode(t('admin_forums_c51f0d','加密')); ?>, <?php echo json_encode(t('admin_forums_adfb29','渗透')); ?>, <?php echo json_encode(t('admin_forums_86835d','漏洞')); ?>, <?php echo json_encode(t('admin_forums_885e1d','防护')); ?>, 'security'], icon: 'shield' },
        { keys: [<?php echo json_encode(t('admin_forums_2305ee','游戏')); ?>, <?php echo json_encode(t('admin_forums_ba958f','电竞')); ?>, <?php echo json_encode(t('admin_forums_0f300e','网游')); ?>, <?php echo json_encode(t('admin_forums_2e8a0c','主机')); ?>, 'steam', 'game'], icon: 'game' },
        { keys: [<?php echo json_encode(t('admin_forums_4c83e1','影视')); ?>, <?php echo json_encode(t('admin_forums_ba723f','电影')); ?>, <?php echo json_encode(t('admin_forums_325813','电视')); ?>, <?php echo json_encode(t('admin_forums_24caf3','剧')); ?>, <?php echo json_encode(t('admin_forums_8ca4a7','动漫')); ?>, 'film', 'movie', 'anime'], icon: 'film' },
        { keys: [<?php echo json_encode(t('admin_forums_afb3c4','音乐')); ?>, <?php echo json_encode(t('admin_forums_bf52cf','歌曲')); ?>, <?php echo json_encode(t('admin_forums_3a3857','歌')); ?>, <?php echo json_encode(t('admin_forums_8eaf85','乐')); ?>, 'music', 'song'], icon: 'music' },
        { keys: [<?php echo json_encode(t('admin_forums_dc2d97','摄影')); ?>, <?php echo json_encode(t('admin_forums_6042a0','相机')); ?>, <?php echo json_encode(t('admin_forums_3da4b3','拍照')); ?>, <?php echo json_encode(t('admin_forums_48e9e5','照片')); ?>, 'photo', 'camera'], icon: 'camera' },
        { keys: [<?php echo json_encode(t('admin_forums_82d0f5','体育')); ?>, <?php echo json_encode(t('admin_forums_9d99da','运动')); ?>, <?php echo json_encode(t('admin_forums_514ef7','足球')); ?>, <?php echo json_encode(t('admin_forums_bfb578','篮球')); ?>, 'sport', 'football'], icon: 'sport' },
        { keys: [<?php echo json_encode(t('admin_forums_2e33ad','写作')); ?>, <?php echo json_encode(t('admin_forums_6eb705','小说')); ?>, <?php echo json_encode(t('admin_forums_de5ec3','文学')); ?>, <?php echo json_encode(t('admin_forums_d53864','散文')); ?>, <?php echo json_encode(t('admin_forums_ad3fd0','文章')); ?>, 'writing'], icon: 'pen' },
        { keys: [<?php echo json_encode(t('admin_forums_e1bd09','读书')); ?>, <?php echo json_encode(t('admin_forums_2b1e69','书籍')); ?>, <?php echo json_encode(t('admin_forums_fe0427','书')); ?>, 'book', <?php echo json_encode(t('admin_forums_aac0ef','阅读')); ?>], icon: 'book' },
        { keys: [<?php echo json_encode(t('admin_forums_c5ca39','资源')); ?>, <?php echo json_encode(t('admin_forums_7a9243','分享')); ?>, <?php echo json_encode(t('admin_forums_2b9d01','下载')); ?>, <?php echo json_encode(t('admin_forums_75e00c','素材')); ?>, 'resource', 'share', 'gift'], icon: 'gift' },
        { keys: [<?php echo json_encode(t('admin_forums_0ea257','闲聊')); ?>, <?php echo json_encode(t('admin_forums_ba64d5','灌水')); ?>, <?php echo json_encode(t('admin_forums_5358b2','聊天')); ?>, <?php echo json_encode(t('admin_forums_5629ca','水区')); ?>, <?php echo json_encode(t('admin_forums_05b368','聊天室')); ?>, 'chat'], icon: 'chat' },
        { keys: [<?php echo json_encode(t('admin_forums_db4224','咖啡')); ?>, <?php echo json_encode(t('admin_forums_97a08c','茶')); ?>, <?php echo json_encode(t('admin_forums_b78bd2','饮品')); ?>, <?php echo json_encode(t('admin_forums_8e1145','生活')); ?>, <?php echo json_encode(t('admin_forums_8d0e83','日常')); ?>, 'coffee', 'life'], icon: 'coffee' },
        { keys: [<?php echo json_encode(t('admin_forums_8e1145','生活')); ?>, <?php echo json_encode(t('admin_forums_226825','情感')); ?>, <?php echo json_encode(t('admin_forums_300ee5','心情')); ?>, 'heart'], icon: 'heart' },
        { keys: [<?php echo json_encode(t('admin_forums_42a36e','反馈')); ?>, <?php echo json_encode(t('admin_forums_c5134e','建议')); ?>, <?php echo json_encode(t('admin_forums_5562d2','意见')); ?>, 'bug', <?php echo json_encode(t('admin_forums_2fb49e','问题')); ?>, <?php echo json_encode(t('admin_forums_adf465','帮助')); ?>, 'help', 'feedback'], icon: 'help' },
        { keys: [<?php echo json_encode(t('admin_forums_e77290','想法')); ?>, <?php echo json_encode(t('admin_forums_f36817','创意')); ?>, <?php echo json_encode(t('admin_forums_6428c3','点子')); ?>, <?php echo json_encode(t('admin_forums_ae2e58','灵感')); ?>, 'idea', 'lightbulb'], icon: 'lightbulb' },
        { keys: [<?php echo json_encode(t('admin_forums_61bf13','热门')); ?>, <?php echo json_encode(t('admin_forums_d24048','精选')); ?>, <?php echo json_encode(t('admin_forums_7de02c','精华')); ?>, <?php echo json_encode(t('admin_forums_2c39ed','人气')); ?>, 'hot', 'fire'], icon: 'fire' },
        { keys: [<?php echo json_encode(t('admin_forums_7de02c','精华')); ?>, <?php echo json_encode(t('admin_forums_62b46f','推荐')); ?>, 'star'], icon: 'star' },
        { keys: [<?php echo json_encode(t('admin_forums_a72ef1','工具')); ?>, <?php echo json_encode(t('admin_forums_1e34fd','软件')); ?>, <?php echo json_encode(t('admin_forums_62b46f','推荐')); ?>, 'tool'], icon: 'tool' },
        { keys: [<?php echo json_encode(t('admin_forums_ec87a4','电脑')); ?>, <?php echo json_encode(t('admin_forums_3294fc','笔记本')); ?>, <?php echo json_encode(t('admin_forums_6e9ece','台式')); ?>, 'pc', 'desktop'], icon: 'desktop' },
        { keys: [<?php echo json_encode(t('admin_forums_9ba763','用户')); ?>, <?php echo json_encode(t('admin_forums_ed6b21','会员')); ?>, <?php echo json_encode(t('admin_forums_6a5e50','新人')); ?>, <?php echo json_encode(t('admin_forums_b96ae2','报道')); ?>, 'user'], icon: 'users' },
        { keys: [<?php echo json_encode(t('admin_forums_1c8e46','邮件')); ?>, <?php echo json_encode(t('admin_forums_9ed627','邮箱')); ?>, 'mail', 'email'], icon: 'mail' },
        { keys: [<?php echo json_encode(t('admin_forums_9171c7','火箭')); ?>, <?php echo json_encode(t('admin_forums_03d53e','创业')); ?>, <?php echo json_encode(t('admin_forums_22336e','项目')); ?>, 'startup', 'rocket'], icon: 'rocket' },
        { keys: [<?php echo json_encode(t('admin_forums_46e1ef','地图')); ?>, <?php echo json_encode(t('admin_forums_88c344','位置')); ?>, <?php echo json_encode(t('admin_forums_e8666c','本地')); ?>, <?php echo json_encode(t('admin_forums_17fc93','区域')); ?>, 'map'], icon: 'map' },
        { keys: [<?php echo json_encode(t('admin_forums_7a66c0','通知')); ?>, <?php echo json_encode(t('admin_forums_81944e','提醒')); ?>, 'bell'], icon: 'bell' },
        { keys: [<?php echo json_encode(t('admin_forums_7debf9','设置')); ?>, <?php echo json_encode(t('admin_forums_d7d7ce','配置')); ?>, <?php echo json_encode(t('admin_forums_1a1f6d','系统')); ?>, 'cog', 'setting'], icon: 'cog' },
        { keys: [<?php echo json_encode(t('admin_forums_1ed10b','庆祝')); ?>, <?php echo json_encode(t('admin_forums_b25486','活动')); ?>, <?php echo json_encode(t('admin_forums_83cd4e','节日')); ?>, 'party', 'event'], icon: 'party' },
        { keys: [<?php echo json_encode(t('admin_forums_820f5b','书签')); ?>, <?php echo json_encode(t('admin_forums_d07cee','收藏')); ?>, 'bookmark'], icon: 'bookmark' },
        { keys: [<?php echo json_encode(t('admin_forums_475833','标志')); ?>, <?php echo json_encode(t('admin_forums_ae0a7a','标签')); ?>, 'flag'], icon: 'flag' },
        { keys: [<?php echo json_encode(t('admin_forums_9171c7','火箭')); ?>, <?php echo json_encode(t('admin_forums_2dd56c','航天')); ?>, <?php echo json_encode(t('admin_forums_492b7e','太空')); ?>, 'space'], icon: 'rocket' }
    ];

    function suggestIconByName(name) {
        if (!name) return null;
        var lower = name.toLowerCase();
        for (var i = 0; i < suggestRules.length; i++) {
            var rule = suggestRules[i];
            for (var j = 0; j < rule.keys.length; j++) {
                if (lower.indexOf(rule.keys[j]) !== -1) {
                    return rule.icon;
                }
            }
        }
        return null;
    }

    function clearRecommended() {
        items.forEach(function(btn) {
            btn.classList.remove('is-recommended');
        });
    }

    function syncSelected() {
        var current = input.value;
        items.forEach(function(btn) {
            btn.classList.toggle('is-selected', btn.getAttribute('data-icon') === current);
        });
    }

    function applyIcon(iconKey, iconLabel) {
        input.value = iconKey;
        // 更新预览
        var srcBtn = null;
        items.forEach(function(btn) {
            if (btn.getAttribute('data-icon') === iconKey) srcBtn = btn;
        });
        if (srcBtn && previewBox) {
            var svg = srcBtn.querySelector('svg');
            if (svg) previewBox.innerHTML = svg.outerHTML;
        }
        if (previewLabel) {
            previewLabel.textContent = iconLabel;
        }
        syncSelected();
    }

    items.forEach(function(btn) {
        btn.addEventListener('click', function() {
            applyIcon(btn.getAttribute('data-icon'), btn.getAttribute('data-label'));
        });
    });

    // 输入版块名称时实时高亮推荐图标
    if (nameInput) {
        nameInput.addEventListener('input', function() {
            var suggested = suggestIconByName(nameInput.value);
            clearRecommended();
            if (suggested) {
                items.forEach(function(btn) {
                    if (btn.getAttribute('data-icon') === suggested) {
                        btn.classList.add('is-recommended');
                    }
                });
            }
        });
    }

    // 点击智能推荐按钮：直接应用推荐图标
    if (suggestBtn) {
        suggestBtn.addEventListener('click', function() {
            var name = nameInput ? nameInput.value : '';
            var suggested = suggestIconByName(name);
            if (!suggested) {
                alert(<?php echo json_encode(t('admin_forums_js_no_icon_match', '未识别到匹配的图标，请手动从图标库中选择。')); ?>);
                return;
            }
            var label = '';
            items.forEach(function(btn) {
                if (btn.getAttribute('data-icon') === suggested) {
                    label = btn.getAttribute('data-label');
                }
            });
            applyIcon(suggested, label);
            clearRecommended();
        });
    }

    syncSelected();

    // 初始化时如果版块名称已填，触发一次推荐高亮
    if (nameInput && nameInput.value) {
        var initSuggested = suggestIconByName(nameInput.value);
        if (initSuggested) {
            items.forEach(function(btn) {
                if (btn.getAttribute('data-icon') === initSuggested) {
                    btn.classList.add('is-recommended');
                }
            });
        }
    }
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
