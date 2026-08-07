<?php
/**
 * 云界论坛 - 发布新帖
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';
require_once APP_ROOT . 'app/captcha/core.php';
require_once APP_ROOT . 'app/components/sensitive_filter/helper.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

require_login();
update_last_active();

// 检查当前用户是否被禁言
$muteMessage = get_user_mute_message((int)$_SESSION['user_id']);
if ($muteMessage !== null) {
    set_flash($muteMessage . t('newpost_mute_tip', '无法发布新帖。如对处罚有异议，可到站内信页面提交申诉。'), 'error');
    redirect('/');
}

$db = get_db();
$errors = [];
$title = '';
$content = '';
$forumId = isset($_GET['forum_id']) ? (int)$_GET['forum_id'] : 0;

// 获取版块列表（按分类分组，用于下拉框）
$forumsByCategory = get_forums_by_category();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = t('newpost_error_csrf', '安全验证失败，请刷新页面重试。');
    } elseif (!validate_post_nonce()) {
        $errors[] = t('newpost_error_nonce', '发帖请求已过期或重复提交，请刷新页面后重试。');
    } else {
        captcha_record_signal('submit');

        $forumId = isset($_POST['forum_id']) ? (int)$_POST['forum_id'] : 0;
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $content = isset($_POST['content']) ? trim($_POST['content']) : '';

        // 标题、正文不能为空（包括纯空格、换行、HTML 空标签、&nbsp;、零宽字符等）
        if (is_effectively_empty($title)) {
            $errors[] = t('newpost_error_title_empty', '标题不能为空。');
        }
        if (is_effectively_empty($content)) {
            $errors[] = t('newpost_error_content_empty', '正文不能为空。');
        }

        // 校验版块：必须选择有效版块
        $forum = $forumId > 0 ? get_forum($forumId) : null;
        if (!$forum) {
            $errors[] = t('newpost_error_forum', '请选择有效的版块。');
        }

        // 标题最大长度限制（已校验非空，无额外最小长度限制）
        if (mb_strlen($title) > 255) {
            $errors[] = t('newpost_error_title_too_long', '标题不能超过 255 个字符。');
        }

        // 防止重复发帖：最近 30 秒内存在标题 + 内容完全相同的帖子则拒绝（跨板块不检查）
        if (has_recent_duplicate_post((int)$_SESSION['user_id'], $title, $content, 30, (int)$forumId)) {
            $errors[] = t('newpost_error_duplicate', '你刚刚发布过完全相同的帖子，请勿重复提交。');
        }

        // 敏感词过滤
        $processedTitle = sw_process_content($title, 'post_title', (int)$_SESSION['user_id'], null, $errors);
        $processedContent = sw_process_content($content, 'post_content', (int)$_SESSION['user_id'], null, $errors);

        if (captcha_enabled() && should_trigger_captcha('new_post') && !captcha_passed($_POST['captcha_token'] ?? '')) {
            $errors[] = t('slider_captcha_fail', '请先完成人机验证。');
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // 插入帖子（含 forum_id）
                $stmt = $db->prepare("INSERT INTO posts (forum_id, user_id, title, content) VALUES (:forum_id, :user_id, :title, :content)");
                $stmt->execute([
                    ':forum_id' => $forumId,
                    ':user_id' => $_SESSION['user_id'],
                    ':title' => $processedTitle,
                    ':content' => $processedContent,
                ]);
                $newPostId = (int)$db->lastInsertId();

                // 更新用户 posts_count + 1 并奖励积分
                $stmt = $db->prepare("UPDATE users SET posts_count = posts_count + 1 WHERE id = :id");
                $stmt->execute([':id' => $_SESSION['user_id']]);
                $newGroup = add_user_points(
                    $_SESSION['user_id'],
                    POST_POINTS,
                    true,
                    'post_create',
                    'post',
                    $newPostId,
                    t('new_post_e8c54e','发布帖子获得积分')
                );

                // 更新版块统计与最后发帖信息
                if ($forumId > 0) {
                    update_forum_stats($forumId);
                    update_forum_last_post($forumId, $newPostId);
                }

                $db->commit();

                $message = t('newpost_success', '帖子发布成功！获得 {points} 积分。', ['points' => POST_POINTS]);
                if ($newGroup) {
                    $message .= t('newpost_level_up', ' 恭喜升级为 {title}！', ['title' => $newGroup['title']]);
                }
                set_flash($message, 'success');
                redirect('/post?id=' . $newPostId);
            } catch (Exception $e) {
                try { $db->rollBack(); } catch (Exception $ignored) {}
                error_log(t('new_post_b76bf6','发帖失败：') . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                error_log(t('new_post_9e880e','发帖失败 trace: ') . $e->getTraceAsString());
                $errors[] = t('newpost_error_failed', '发布失败，请稍后重试。');
            }
        }
    }
}

// 当前选中版块（用于面包屑与取消链接）
$currentForum = $forumId > 0 ? get_forum($forumId) : null;

$pageTitle = t('newpost_page_title', '发布新帖');
include APP_ROOT . 'app/includes/header.php';
?>

<nav class="breadcrumb" aria-label="<?php echo e(t('newpost_breadcrumb', '面包屑导航')); ?>">
    <a href="/"><?php echo e(t('newpost_home', '首页')); ?></a>
    <?php if ($currentForum): ?>
        <span class="breadcrumb-separator">/</span>
        <a href="<?php echo site_url('forum', ['id' => (int)$currentForum['id']]); ?>"><?php echo e($currentForum['name']); ?></a>
    <?php endif; ?>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?php echo e(t('newpost_breadcrumb_current', '发帖')); ?></span>
</nav>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('newpost_heading', '发布新帖')); ?></h1>
</div>

<div class="card">
    <?php if (!empty($errors)): ?>
        <?php foreach ($errors as $err): ?>
            <?php echo show_message($err, 'error'); ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <form method="POST" action="<?php echo site_url('new_post'); ?>" data-validate id="new-post-form">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="post_nonce" value="<?php echo post_nonce_token(); ?>">

        <div class="form-group">
            <label class="form-label" for="forum_id"><?php echo e(t('newpost_label_forum', '版块')); ?></label>
            <select class="form-control" id="forum_id" name="forum_id">
                <option value="0"><?php echo e(t('newpost_forum_placeholder', '-- 请选择版块 --')); ?></option>
                <?php foreach ($forumsByCategory as $group): ?>
                    <optgroup label="<?php echo e($group['category']['name']); ?>">
                        <?php foreach ($group['forums'] as $f): ?>
                            <option value="<?php echo (int)$f['id']; ?>"<?php echo $forumId === (int)$f['id'] ? ' selected' : ''; ?>>
                                <?php echo e($f['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="title"><?php echo e(t('newpost_label_title', '标题')); ?></label>
            <input type="text" class="form-control" id="title" name="title" value="<?php echo e($title); ?>" maxlength="255" placeholder="<?php echo e(t('newpost_title_placeholder', '请输入标题')); ?>">
        </div>

        <div class="form-group">
            <label class="form-label" for="content"><?php echo e(t('newpost_label_content', '正文')); ?></label>
            <div class="bbcode-toolbar" style="display: flex; flex-wrap: wrap; gap: 0.25rem; margin-bottom: 0.5rem;">
                <button type="button" class="btn btn-sm btn-secondary" onclick="insertBBCode('content', '[b]', '[/b]')" title="<?php echo e(t('newpost_tool_bold', '加粗')); ?>"><strong>B</strong></button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="insertBBCode('content', '[i]', '[/i]')" title="<?php echo e(t('newpost_tool_italic', '斜体')); ?>"><em>I</em></button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="insertBBCode('content', '[u]', '[/u]')" title="<?php echo e(t('newpost_tool_underline', '下划线')); ?>"><u>U</u></button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="insertBBCode('content', '[quote]', '[/quote]')" title="<?php echo e(t('newpost_tool_quote', '引用')); ?>"><?php echo e(t('newpost_tool_quote', '引用')); ?></button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="insertBBCode('content', '[code]', '[/code]')" title="<?php echo e(t('newpost_tool_code', '代码')); ?>"><?php echo e(t('newpost_tool_code', '代码')); ?></button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="insertBBCode('content', '[url=', '][/url]')" title="<?php echo e(t('newpost_tool_link', '链接')); ?>"><?php echo e(t('newpost_tool_link', '链接')); ?></button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="insertBBCode('content', '[img]', '[/img]')" title="<?php echo e(t('newpost_tool_image', '图片链接')); ?>"><?php echo e(t('newpost_tool_image', '图片链接')); ?></button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="EditorUpload.uploadLocalImage('content', '<?php echo csrf_token(); ?>')" title="<?php echo e(t('newpost_tool_upload', '上传图片')); ?>"><?php echo e(t('newpost_tool_upload', '上传图片')); ?></button>
            </div>
            <textarea class="form-control" id="content" name="content" rows="10" placeholder="<?php echo e(t('newpost_content_placeholder', '分享你的想法... 支持 BBCode 语法')); ?>"><?php echo e($content); ?></textarea>
            <p class="form-hint"><?php echo e(t('newpost_bbcode_hint', '支持 BBCode：[b]粗体[/b] [i]斜体[/i] [u]下划线[/u] [quote]引用[/quote] [code]代码[/code] [url=地址]文字[/url] [img]图片地址[/img]；也可点击“上传图片”直接插入本地图片。')); ?></p>
        </div>

        <?php if (captcha_enabled() && should_trigger_captcha('new_post')): ?>
        <div class="form-group">
            <div id="captcha" data-api="<?php echo site_url('api/captcha'); ?>" data-display="<?php echo e(captcha_display()); ?>"></div>
            <input type="hidden" name="captcha_token" id="captcha_token" value="">
        </div>
        <?php endif; ?>

        <div class="flex gap-1">
            <button type="submit" class="btn btn-primary" id="submit-post-btn"><?php echo e(t('newpost_submit', '发布帖子')); ?></button>
            <a href="<?php echo $currentForum ? site_url('forum', ['id' => (int)$currentForum['id']]) : '/'; ?>" class="btn btn-secondary"><?php echo e(t('newpost_cancel', '取消')); ?></a>
        </div>
    </form>
</div>

<script src="../public/js/editor.js"></script>
<script>
// 防止疯狂点击导致重复提交：提交后禁用按钮并显示加载状态
var NEWPOST_SUBMITTING_TEXT = <?php echo json_encode(t('newpost_submitting', '发布中…')); ?>;
(function () {
    var form = document.getElementById('new-post-form');
    var btn = document.getElementById('submit-post-btn');
    if (!form || !btn) return;
    form.addEventListener('submit', function () {
        if (form.hasAttribute('data-submitting')) return;
        form.setAttribute('data-submitting', '1');
        btn.disabled = true;
        btn.textContent = NEWPOST_SUBMITTING_TEXT;
    });
})();

function insertBBCode(id, open, close) {
    var ta = document.getElementById(id);
    if (!ta) return;
    var start = ta.selectionStart;
    var end = ta.selectionEnd;
    var sel = ta.value.substring(start, end);
    var insert = open + (sel || '') + close;
    ta.value = ta.value.substring(0, start) + insert + ta.value.substring(end);
    var cursor = sel ? start + insert.length : start + open.length;
    ta.focus();
    ta.setSelectionRange(cursor, cursor);
}

</script>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
