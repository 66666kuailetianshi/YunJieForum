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

// 功能增强字段默认值
$postType = 'normal';
$readPermission = 'public';
$minPoints = 0;
$tagsInput = '';
$bountyPoints = 0;
$pollQuestion = '';
$pollOptions = [];
$pollMulti = 0;

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

        if (captcha_enabled() && should_trigger_captcha('new_post')) {
            if (!captcha_honeypot_ok($_POST)) {
                $errors[] = t('captcha_bot_detected', '验证未通过，请重试');
            } elseif (!captcha_passed($_POST['captcha_token'] ?? '')) {
                $errors[] = t('slider_captcha_fail', '请先完成人机验证。');
            }
        }

        // ===== 功能增强字段收集与校验 =====
        $postType = isset($_POST['post_type']) ? trim($_POST['post_type']) : 'normal';
        if (!in_array($postType, ['normal', 'vote', 'debate', 'bounty'], true)) {
            $postType = 'normal';
        }
        $readPermission = isset($_POST['read_permission']) ? trim($_POST['read_permission']) : 'public';
        if (!in_array($readPermission, ['public', 'members', 'points'], true)) {
            $readPermission = 'public';
        }
        $minPoints = $readPermission === 'points' ? max(0, (int)($_POST['min_points'] ?? 0)) : 0;
        $tagsInput = isset($_POST['tags']) ? trim($_POST['tags']) : '';
        $tagsArray = $tagsInput !== '' ? preg_split('/[\s,，]+/u', $tagsInput) : [];
        $tagsArray = array_slice(array_filter(array_map('trim', $tagsArray), function ($t) { return $t !== ''; }), 0, 10);

        $pollQuestion = '';
        $pollOptions = [];
        $pollMulti = 0;
        if ($postType === 'vote') {
            $pollQuestion = isset($_POST['poll_question']) ? trim($_POST['poll_question']) : '';
            $pollOptionsRaw = isset($_POST['poll_options']) ? (array)$_POST['poll_options'] : [];
            $pollOptions = array_values(array_filter(array_map('trim', $pollOptionsRaw), function ($o) { return $o !== ''; }));
            $pollMulti = isset($_POST['poll_multi']) ? 1 : 0;
            if ($pollQuestion === '') {
                $errors[] = t('newpost_poll_question_required', '请填写投票问题。');
            }
            if (count($pollOptions) < 2) {
                $errors[] = t('newpost_poll_options_required', '投票至少需要 2 个选项。');
            }
        }

        $bountyPoints = 0;
        if ($postType === 'bounty') {
            $bountyPoints = max(0, (int)($_POST['bounty_points'] ?? 0));
            if ($bountyPoints <= 0) {
                $errors[] = t('newpost_bounty_required', '请填写有效的悬赏积分。');
            } else {
                $stmt = $db->prepare("SELECT points FROM users WHERE id = :id");
                $stmt->execute([':id' => $_SESSION['user_id']]);
                $balance = (int)$stmt->fetchColumn();
                if ($bountyPoints > $balance) {
                    $errors[] = t('newpost_bounty_insufficient', '你的积分不足，无法发布该悬赏帖。');
                }
            }
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // 插入帖子（含 forum_id 与功能增强字段）
                $bountyStatus = ($postType === 'bounty') ? 'open' : '';
                $stmt = $db->prepare("INSERT INTO posts (forum_id, user_id, title, content, ip, post_type, read_permission, min_points, bounty_points, bounty_status) VALUES (:forum_id, :user_id, :title, :content, :ip, :post_type, :read_permission, :min_points, :bounty_points, :bounty_status)");
                $stmt->execute([
                    ':forum_id' => $forumId,
                    ':user_id' => $_SESSION['user_id'],
                    ':title' => $processedTitle,
                    ':content' => $processedContent,
                    ':ip' => client_ip(),
                    ':post_type' => $postType,
                    ':read_permission' => $readPermission,
                    ':min_points' => $minPoints,
                    ':bounty_points' => $bountyPoints,
                    ':bounty_status' => $bountyStatus,
                ]);
                $newPostId = (int)$db->lastInsertId();

                // 悬赏帖：扣除积分（余额已在外部校验）
                if ($postType === 'bounty' && $bountyPoints > 0) {
                    $db->prepare("UPDATE users SET points = points - :bp WHERE id = :uid")
                        ->execute([':bp' => $bountyPoints, ':uid' => $_SESSION['user_id']]);
                    $db->prepare("INSERT INTO user_points_log (user_id, points, coins, type, source_type, source_id, description) VALUES (:uid, :bp, 0, 'bounty_ask', 'post', :sid, :desc)")
                        ->execute([
                            ':uid' => $_SESSION['user_id'],
                            ':bp' => -$bountyPoints,
                            ':sid' => $newPostId,
                            ':desc' => t('bounty_ask_desc', '发布悬赏帖扣除积分'),
                        ]);
                }

                // 投票帖：创建投票与选项
                if ($postType === 'vote') {
                    $db->prepare("INSERT INTO polls (post_id, question, multi_choice) VALUES (:pid, :q, :m)")
                        ->execute([':pid' => $newPostId, ':q' => $pollQuestion, ':m' => $pollMulti]);
                    $pollId = (int)$db->lastInsertId();
                    foreach ($pollOptions as $idx => $opt) {
                        $db->prepare("INSERT INTO poll_options (poll_id, title, sort_order) VALUES (:pid, :t, :s)")
                            ->execute([':pid' => $pollId, ':t' => $opt, ':s' => $idx]);
                    }
                }

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

                // 保存标签
                save_post_tags($newPostId, $tagsArray);

                // 通知订阅了该版块的用户（排除作者本人）
                if ($forumId > 0) {
                    $subs = get_forum_subscriber_ids($forumId);
                    $notifyLink = site_url('post', ['id' => $newPostId]);
                    foreach ($subs as $sid) {
                        if ($sid === (int)$_SESSION['user_id']) continue;
                        send_notification($sid, 'forum_subscribe', t('sub_new_post_title', '你订阅的版块有新帖'), mb_substr($processedTitle, 0, 60, 'UTF-8'), $notifyLink);
                    }
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

// GET 请求时，阅读权限默认继承版块设置
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $currentForum) {
    $readPermission = $currentForum['default_read_permission'] ?? 'public';
}

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

        <!-- 帖子类型 -->
        <div class="form-group">
            <label class="form-label" for="post_type"><?php echo e(t('newpost_label_type', '帖子类型')); ?></label>
            <select class="form-control" id="post_type" name="post_type">
                <option value="normal"<?php echo $postType === 'normal' ? ' selected' : ''; ?>><?php echo e(t('newpost_type_normal', '普通帖')); ?></option>
                <option value="vote"<?php echo $postType === 'vote' ? ' selected' : ''; ?>><?php echo e(t('newpost_type_vote', '投票帖')); ?></option>
                <option value="debate"<?php echo $postType === 'debate' ? ' selected' : ''; ?>><?php echo e(t('newpost_type_debate', '辩论帖')); ?></option>
                <option value="bounty"<?php echo $postType === 'bounty' ? ' selected' : ''; ?>><?php echo e(t('newpost_type_bounty', '悬赏帖')); ?></option>
            </select>
        </div>

        <!-- 标签 -->
        <div class="form-group">
            <label class="form-label" for="tags"><?php echo e(t('newpost_label_tags', '标签')); ?></label>
            <input type="text" class="form-control" id="tags" name="tags" value="<?php echo e($tagsInput); ?>" placeholder="<?php echo e(t('newpost_tags_placeholder', '用空格或逗号分隔，最多 10 个')); ?>">
            <p class="form-hint"><?php echo e(t('newpost_tags_hint', '标签便于其他用户按主题检索你的帖子。')); ?></p>
        </div>

        <!-- 阅读权限 -->
        <div class="form-group">
            <label class="form-label" for="read_permission"><?php echo e(t('newpost_label_read', '阅读权限')); ?></label>
            <select class="form-control" id="read_permission" name="read_permission">
                <option value="public"<?php echo $readPermission === 'public' ? ' selected' : ''; ?>><?php echo e(t('newpost_read_public', '公开（所有人可见）')); ?></option>
                <option value="members"<?php echo $readPermission === 'members' ? ' selected' : ''; ?>><?php echo e(t('newpost_read_members', '仅登录会员可见')); ?></option>
                <option value="points"<?php echo $readPermission === 'points' ? ' selected' : ''; ?>><?php echo e(t('newpost_read_points', '达到积分门槛可见')); ?></option>
            </select>
        </div>
        <div class="form-group" id="min-points-group" style="display:<?php echo $readPermission === 'points' ? 'block' : 'none'; ?>">
            <label class="form-label" for="min_points"><?php echo e(t('newpost_label_minpoints', '所需积分')); ?></label>
            <input type="number" min="0" class="form-control" id="min_points" name="min_points" value="<?php echo (int)$minPoints; ?>" placeholder="0">
        </div>

        <!-- 投票帖选项 -->
        <div id="poll-fields" style="display:<?php echo $postType === 'vote' ? 'block' : 'none'; ?>">
            <div class="form-group">
                <label class="form-label" for="poll_question"><?php echo e(t('newpost_label_poll_q', '投票问题')); ?></label>
                <input type="text" class="form-control" id="poll_question" name="poll_question" value="<?php echo e($pollQuestion); ?>" placeholder="<?php echo e(t('newpost_poll_q_placeholder', '例如：你更喜欢哪个方案？')); ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('newpost_label_poll_opts', '投票选项')); ?></label>
                <div id="poll-options-wrap">
                    <?php
                    $pollOptDefaults = !empty($pollOptions) ? $pollOptions : ['', ''];
                    foreach ($pollOptDefaults as $po): ?>
                        <div class="poll-option-row">
                            <input type="text" class="form-control" name="poll_options[]" value="<?php echo e($po); ?>" placeholder="<?php echo e(t('newpost_poll_opt_placeholder', '选项内容')); ?>">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="removePollOption(this)"><?php echo e(t('newpost_remove', '删除')); ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-secondary mt-1" onclick="addPollOption()"><?php echo e(t('newpost_poll_add', '添加选项')); ?></button>
                <label class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" name="poll_multi" value="1"<?php echo !empty($pollMulti) ? ' checked' : ''; ?>>
                    <span class="form-check-label"><?php echo e(t('newpost_poll_multi', '允许多选')); ?></span>
                </label>
            </div>
        </div>

        <!-- 悬赏帖积分 -->
        <div class="form-group" id="bounty-fields" style="display:<?php echo $postType === 'bounty' ? 'block' : 'none'; ?>">
            <label class="form-label" for="bounty_points"><?php echo e(t('newpost_label_bounty', '悬赏积分')); ?></label>
            <input type="number" min="0" class="form-control" id="bounty_points" name="bounty_points" value="<?php echo (int)$bountyPoints; ?>" placeholder="0">
            <p class="form-hint"><?php echo e(t('newpost_bounty_hint', '发布悬赏将立即扣除对应积分，采纳答案后发放给回答者。当前积分：')); ?><?php echo (int)(current_user()['points'] ?? 0); ?></p>
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
var NEWPOST_SUBMITTING_TEXT = <?php echo json_encode(t('newpost_submitting', '发布中…')) ?: '""'; ?>;
var NEWPOST_POLL_OPT_PLACEHOLDER = <?php echo json_encode(t('newpost_poll_opt_placeholder', '选项内容')) ?: '""'; ?>;
var NEWPOST_REMOVE_TEXT = <?php echo json_encode(t('newpost_remove', '删除')) ?: '""'; ?>;
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

// ===== 功能增强表单交互 =====
function togglePostTypeFields() {
    var type = document.getElementById('post_type').value;
    document.getElementById('poll-fields').style.display = (type === 'vote') ? 'block' : 'none';
    document.getElementById('bounty-fields').style.display = (type === 'bounty') ? 'block' : 'none';
}
function toggleMinPoints() {
    var rp = document.getElementById('read_permission').value;
    document.getElementById('min-points-group').style.display = (rp === 'points') ? 'block' : 'none';
}
function addPollOption() {
    var wrap = document.getElementById('poll-options-wrap');
    var row = document.createElement('div');
    row.className = 'poll-option-row';
    row.innerHTML = '<input type="text" class="form-control" name="poll_options[]" placeholder="' + NEWPOST_POLL_OPT_PLACEHOLDER + '"><button type="button" class="btn btn-sm btn-secondary" onclick="removePollOption(this)">' + NEWPOST_REMOVE_TEXT + '</button>';
    wrap.appendChild(row);
}
function removePollOption(btn) {
    var wrap = document.getElementById('poll-options-wrap');
    if (wrap.children.length <= 2) return;
    btn.parentNode.remove();
}
(function () {
    var pt = document.getElementById('post_type');
    var rp = document.getElementById('read_permission');
    if (pt) pt.addEventListener('change', togglePostTypeFields);
    if (rp) rp.addEventListener('change', toggleMinPoints);
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
