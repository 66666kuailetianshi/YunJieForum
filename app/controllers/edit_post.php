<?php
/**
 * 云界论坛 - 编辑帖子（作者或管理员可编辑，保留编辑历史）
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';
require_once APP_ROOT . 'app/components/sensitive_filter/helper.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

require_login();
update_last_active();

$db = get_db();
$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $db->prepare("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id = u.id OR p.user_id = u.uid WHERE p.id = :id LIMIT 1");
$stmt->execute([':id' => $postId]);
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    $pageTitle = t('post_not_found_title', '帖子不存在');
    include APP_ROOT . 'app/includes/header.php';
    echo '<div class="card empty-state"><div class="empty-state-icon"><svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></div><p>' . e(t('post_not_found_desc', '帖子不存在或已被删除。')) . '</p><a href="/" class="btn btn-primary">' . e(t('post_back_home', '返回首页')) . '</a></div>';
    include APP_ROOT . 'app/includes/footer.php';
    exit;
}

// 权限：仅作者或拥有帖子管理权限者可编辑
if (!((int)$_SESSION['user_id'] === (int)$post['user_id'] || has_permission('manage_posts'))) {
    set_flash(t('edit_post_no_permission', '你没有权限编辑该帖子。'), 'error');
    redirect('/post?id=' . $postId);
}

$errors = [];
$title = $post['title'];
$content = $post['content'];
$currentTags = get_post_tags($postId);
$tagsInput = implode(' ', array_column($currentTags, 'name'));
$editReason = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = t('newpost_error_csrf', '安全验证失败，请刷新页面重试。');
    } else {
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $content = isset($_POST['content']) ? trim($_POST['content']) : '';
        $editReason = isset($_POST['edit_reason']) ? trim($_POST['edit_reason']) : '';
        $tagsInput = isset($_POST['tags']) ? trim($_POST['tags']) : '';

        if (is_effectively_empty($title)) {
            $errors[] = t('newpost_error_title_empty', '标题不能为空。');
        }
        if (is_effectively_empty($content)) {
            $errors[] = t('newpost_error_content_empty', '正文不能为空。');
        }
        if (mb_strlen($title) > 255) {
            $errors[] = t('newpost_error_title_too_long', '标题不能超过 255 个字符。');
        }

        $processedTitle = sw_process_content($title, 'post_title', (int)$_SESSION['user_id'], null, $errors);
        $processedContent = sw_process_content($content, 'post_content', (int)$_SESSION['user_id'], null, $errors);

        if (empty($errors)) {
            try {
                $db->beginTransaction();
                // 记录编辑历史（保存编辑前的标题与正文）
                record_post_edit($postId, (int)$_SESSION['user_id'], $post['title'], $post['content'], $editReason);
                $stmt = $db->prepare("UPDATE posts SET title = :title, content = :content, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([
                    ':title'   => $processedTitle,
                    ':content' => $processedContent,
                    ':id'      => $postId,
                ]);
                // 标签：覆盖式保存
                $tagsArray = $tagsInput !== '' ? preg_split('/[\s,，]+/u', $tagsInput) : [];
                $tagsArray = array_slice(array_filter(array_map('trim', $tagsArray), function ($t) { return $t !== ''; }), 0, 10);
                save_post_tags($postId, $tagsArray);
                $db->commit();
                set_flash(t('edit_post_success', '帖子已更新。'), 'success');
                redirect('/post?id=' . $postId);
            } catch (Exception $e) {
                try { $db->rollBack(); } catch (Exception $ignored) {}
                error_log('edit_post failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $errors[] = t('edit_post_failed', '保存失败，请重试。');
            }
        }
    }
}

$pageTitle = t('edit_post_title', '编辑帖子');
include APP_ROOT . 'app/includes/header.php';
?>
<nav class="breadcrumb" aria-label="<?php echo e(t('edit_post_breadcrumb', '面包屑导航')); ?>">
    <a href="/"><?php echo e(t('edit_post_home', '首页')); ?></a>
    <span class="breadcrumb-separator">/</span>
    <a href="<?php echo site_url('post', ['id' => $postId]); ?>"><?php echo e(strip_bbcode($post['title'])); ?></a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?php echo e(t('edit_post_current', '编辑')); ?></span>
</nav>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('edit_post_heading', '编辑帖子')); ?></h1>
</div>

<div class="card">
    <?php if (!empty($errors)): ?>
        <?php foreach ($errors as $err): ?>
            <?php echo show_message($err, 'error'); ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <form method="POST" action="<?php echo site_url('edit_post', ['id' => $postId]); ?>" data-validate id="edit-post-form">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <div class="form-group">
            <label class="form-label" for="title"><?php echo e(t('newpost_label_title', '标题')); ?></label>
            <input type="text" class="form-control" id="title" name="title" value="<?php echo e($title); ?>" maxlength="255" placeholder="<?php echo e(t('newpost_title_placeholder', '请输入标题')); ?>">
        </div>

        <div class="form-group">
            <label class="form-label" for="content"><?php echo e(t('newpost_label_content', '正文')); ?></label>
            <textarea class="form-control" id="content" name="content" rows="10" placeholder="<?php echo e(t('newpost_content_placeholder', '分享你的想法... 支持 BBCode 语法')); ?>"><?php echo e($content); ?></textarea>
            <p class="form-hint"><?php echo e(t('newpost_bbcode_hint', '支持 BBCode：[b]粗体[/b] [i]斜体[/i] [u]下划线[/u] [quote]引用[/quote] [code]代码[/code] [url=地址]文字[/url] [img]图片地址[/img]；也可点击“上传图片”直接插入本地图片。')); ?></p>
        </div>

        <div class="form-group">
            <label class="form-label" for="tags"><?php echo e(t('newpost_label_tags', '标签')); ?></label>
            <input type="text" class="form-control" id="tags" name="tags" value="<?php echo e($tagsInput); ?>" placeholder="<?php echo e(t('newpost_tags_placeholder', '用空格或逗号分隔，最多 10 个')); ?>">
            <p class="form-hint"><?php echo e(t('newpost_tags_hint', '标签便于其他用户按主题检索你的帖子。')); ?></p>
        </div>

        <div class="form-group">
            <label class="form-label" for="edit_reason"><?php echo e(t('edit_post_reason_label', '编辑说明（选填）')); ?></label>
            <input type="text" class="form-control" id="edit_reason" name="edit_reason" value="<?php echo e($editReason); ?>" maxlength="200" placeholder="<?php echo e(t('edit_post_reason_placeholder', '简要说明本次修改内容')); ?>">
        </div>

        <div class="flex gap-1">
            <button type="submit" class="btn btn-primary"><?php echo e(t('edit_post_submit', '保存修改')); ?></button>
            <a href="<?php echo site_url('post', ['id' => $postId]); ?>" class="btn btn-secondary"><?php echo e(t('edit_post_cancel', '取消')); ?></a>
        </div>
    </form>

    <?php
    // 编辑历史
    $edits = get_post_edits($postId);
    if (!empty($edits)):
    ?>
    <div class="edit-history mt-3">
        <h3 class="card-title"><?php echo e(t('edit_post_history_title', '编辑历史')); ?></h3>
        <ul class="edit-history-list">
            <?php foreach ($edits as $ed): ?>
                <li>
                    <span class="edit-history-user"><?php echo e($ed['username']); ?></span>
                    <span class="edit-history-time"><?php echo e(date('Y-m-d H:i', db_time($ed['created_at']))); ?></span>
                    <?php if (!empty($ed['edit_reason'])): ?><span class="edit-history-reason"><?php echo e($ed['edit_reason']); ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>

<style>
.edit-history { border-top:1px solid var(--border,#e5e7eb); padding-top:1rem; }
.edit-history-list { list-style:none; padding:0; margin:0; }
.edit-history-list li { padding:.4rem 0; border-bottom:1px dashed var(--border,#e5e7eb); font-size:.85rem; display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
.edit-history-user { font-weight:600; }
.edit-history-time { color:var(--text-muted,#6b7280); }
.edit-history-reason { color:var(--text-muted,#6b7280); }
</style>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
