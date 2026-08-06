<?php
/**
 * 云界论坛 - 帖子详情与回复（火绒风格）
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';
require_once APP_ROOT . 'app/components/sensitive_filter/helper.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

update_last_active();

$db = get_db();
$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 查询帖子（含楼主扩展信息）
$stmt = $db->prepare("SELECT p.*, u.username, u.avatar, u.signature, u.points, u.posts_count, u.created_at AS user_created_at
    FROM posts p
    JOIN users u ON p.user_id = u.id
    WHERE p.id = :id
    LIMIT 1");
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

// 管理操作 / 收藏操作参数（提前取出，用于判断本次请求是否为“真实浏览”）
$action = isset($_GET['action']) ? $_GET['action'] : '';
$adminActions = ['pin', 'unpin', 'essence', 'unessence', 'lock', 'unlock', 'delete'];
$favAction = isset($_GET['fav_action']) ? $_GET['fav_action'] : '';

// 增加浏览量：仅在“真实查看帖子”时计入。
// 置顶 / 加精 / 锁定 / 删除等管理操作、收藏 / 取消收藏本身只是动作请求，不应算作浏览量——
// 否则每次操作会 +1，且操作完成后内部 redirect 回帖子页还会再 +1，造成浏览量虚高。
$isManagementAction = (is_admin() && in_array($action, $adminActions, true))
    || (is_logged_in() && in_array($favAction, ['add', 'remove'], true));
if (!$isManagementAction) {
    $stmt = $db->prepare("UPDATE posts SET views = views + 1 WHERE id = :id");
    $stmt->execute([':id' => $postId]);
    $post['views'] = (int)$post['views'] + 1;
}

// 管理操作（管理员可见，GET + csrf_token）
if (is_admin() && in_array($action, $adminActions, true)) {
    // CSRF 校验失败不静默：明确提示并返回帖子页
    if (!validate_csrf(isset($_GET['csrf_token']) ? $_GET['csrf_token'] : '')) {
        set_flash(t('post_flash_csrf_expired', '安全校验失败（链接已过期），请刷新页面后重新操作。'), 'error');
        redirect('/post?id=' . $postId);
    }
    if ($action === 'pin') {
        $stmt = $db->prepare("UPDATE posts SET is_pinned = 1 WHERE id = :id");
        $stmt->execute([':id' => $postId]);
        set_flash(t('post_flash_pinned', '帖子已置顶。'), 'success');
    } elseif ($action === 'unpin') {
        $stmt = $db->prepare("UPDATE posts SET is_pinned = 0 WHERE id = :id");
        $stmt->execute([':id' => $postId]);
        set_flash(t('post_flash_unpinned', '已取消置顶。'), 'success');
    } elseif ($action === 'essence') {
        $stmt = $db->prepare("UPDATE posts SET is_essence = 1 WHERE id = :id");
        $stmt->execute([':id' => $postId]);
        set_flash(t('post_flash_essence', '帖子已加精。'), 'success');
    } elseif ($action === 'unessence') {
        $stmt = $db->prepare("UPDATE posts SET is_essence = 0 WHERE id = :id");
        $stmt->execute([':id' => $postId]);
        set_flash(t('post_flash_unessence', '已取消加精。'), 'success');
    } elseif ($action === 'lock') {
        $stmt = $db->prepare("UPDATE posts SET is_locked = 1 WHERE id = :id");
        $stmt->execute([':id' => $postId]);
        set_flash(t('post_flash_locked', '帖子已锁定。'), 'success');
    } elseif ($action === 'unlock') {
        $stmt = $db->prepare("UPDATE posts SET is_locked = 0 WHERE id = :id");
        $stmt->execute([':id' => $postId]);
        set_flash(t('post_flash_unlocked', '帖子已解锁。'), 'success');
    } elseif ($action === 'delete') {
        if (delete_post($postId)) {
            set_flash(t('post_flash_deleted', '帖子已删除。'), 'success');
            redirect('/');
        } else {
            set_flash(t('post_flash_delete_failed', '帖子不存在或删除失败。'), 'error');
        }
    }
    redirect('/post?id=' . $postId);
}

// 收藏 / 取消收藏（GET + csrf_token）
if (is_logged_in() && in_array($favAction, ['add', 'remove'], true) && validate_csrf(isset($_GET['csrf_token']) ? $_GET['csrf_token'] : '')) {
    if ($favAction === 'add') {
        // 查询是否已收藏（防止重复收藏刷积分）
        $stmt = $db->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = :uid AND post_id = :pid");
        $stmt->execute([':uid' => $_SESSION['user_id'], ':pid' => $postId]);
        $alreadyFavorited = (int)$stmt->fetchColumn() > 0;

        $stmt = sql_prepare($db, "INSERT OR IGNORE INTO favorites (user_id, post_id) VALUES (:uid, :pid)");
        $stmt->execute([':uid' => $_SESSION['user_id'], ':pid' => $postId]);

        // 首次收藏才给楼主奖励，且不能收藏自己的帖子刷分
        if (!$alreadyFavorited && (int)$post['user_id'] !== (int)$_SESSION['user_id']) {
            add_user_points(
                (int)$post['user_id'],
                FAVORITE_RECEIVED_POINTS,
                false,
                'favorite_received',
                'post',
                $postId,
                t('post_df0717','帖子被收藏获得积分')
            );
        }
        set_flash(t('post_flash_favorited', '已收藏帖子。'), 'success');
    } else {
        $stmt = $db->prepare("DELETE FROM favorites WHERE user_id = :uid AND post_id = :pid");
        $stmt->execute([':uid' => $_SESSION['user_id'], ':pid' => $postId]);
        set_flash(t('post_flash_unfavorited', '已取消收藏。'), 'success');
    }
    redirect('/post?id=' . $postId);
}

// 处理回复提交
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_logged_in()) {
    if (!validate_csrf()) {
        $errors[] = t('post_error_csrf', '安全验证失败，请刷新页面重试。');
    } elseif ((int)$post['is_locked'] === 1) {
        $errors[] = t('post_error_locked', '帖子已锁定，无法回复。');
    } elseif (($muteMessage = get_user_mute_message((int)$_SESSION['user_id'])) !== null) {
        $errors[] = $muteMessage . t('post_error_muted_suffix', '无法回复。');
    } else {
        $replyContent = isset($_POST['content']) ? trim($_POST['content']) : '';
        if (is_effectively_empty($replyContent)) {
            $errors[] = t('post_error_reply_empty', '回复内容不能为空。');
        }
        $replyTo = isset($_POST['reply_to']) ? (int)$_POST['reply_to'] : 0;
        if ($replyTo <= 0) {
            $replyTo = null;
        }
        $quoteContent = isset($_POST['quote_content']) ? trim($_POST['quote_content']) : '';
        // 校验可视化引用内容格式，防止脏数据入库
        if ($quoteContent !== '') {
            $quoteData = json_decode($quoteContent, true);
            if (!is_array($quoteData) || !isset($quoteData['content']) || !is_string($quoteData['content'])) {
                $quoteContent = '';
            }
        }

        $floor = 0;

        // 敏感词过滤
        $processedReply = sw_process_content($replyContent, 'reply_content', (int)$_SESSION['user_id'], $postId, $errors);

        if (empty($errors)) {
            $inReplyTransaction = false;
            try {
                // 统一使用 PDO::beginTransaction() 开启事务：
                // 若用 $db->exec("BEGIN IMMEDIATE")，PDO 不会更新 inTransaction 标志，
                // 后续 $db->commit() 会报 "There is no active transaction"（SQLite 下复现）。
                // SQLite 的 BEGIN（deferred）会在首次写操作时自动升级为写事务。
                $db->beginTransaction();
                $inReplyTransaction = true;

                // 在事务内锁定并读取 replies_count，计算楼层号
                // SQLite 不支持 SELECT ... FOR UPDATE，仅对 MySQL/PostgreSQL 加行锁
                $lockClause = get_db_driver() instanceof SQLiteDriver ? '' : ' FOR UPDATE';
                $stmt = $db->prepare("SELECT replies_count FROM posts WHERE id = :id{$lockClause}");
                $stmt->execute([':id' => $postId]);
                $repliesCount = (int)$stmt->fetchColumn();
                $floor = $repliesCount + 2;

                $stmt = $db->prepare("INSERT INTO replies (post_id, user_id, content, floor, reply_to, quote_content) VALUES (:post_id, :user_id, :content, :floor, :reply_to, :quote_content)");
                $stmt->execute([
                    ':post_id' => $postId,
                    ':user_id' => $_SESSION['user_id'],
                    ':content' => $processedReply,
                    ':floor' => $floor,
                    ':reply_to' => $replyTo,
                    ':quote_content' => $quoteContent !== '' ? $quoteContent : null,
                ]);
                $stmt = $db->prepare("UPDATE posts SET replies_count = replies_count + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([':id' => $postId]);

                // 奖励回复积分（同一帖子每日仅首次回复获得积分；回复自己的帖子不获得积分）
                $replyAuthorId = (int)$_SESSION['user_id'];
                $postAuthorId = (int)$post['user_id'];
                $newGroup = null;
                if ($replyAuthorId !== $postAuthorId && !has_earned_points_for_source($replyAuthorId, 'reply_create', 'post', $postId)) {
                    $newGroup = add_user_points(
                        $replyAuthorId,
                        REPLY_POINTS,
                        true,
                        'reply_create',
                        'post',
                        $postId,
                        t('post_7fd3d0','回复帖子获得积分')
                    );
                    // 楼主收到回复奖励
                    add_user_points(
                        $postAuthorId,
                        REPLY_RECEIVED_POINTS,
                        true,
                        'reply_received',
                        'post',
                        $postId,
                        t('post_2f5bfb','帖子收到回复获得积分')
                    );
                }

                // 同步更新版块统计与最后回复时间
                if (!empty($post['forum_id'])) {
                    $forumId = (int)$post['forum_id'];
                    update_forum_stats($forumId);
                    update_forum_last_post($forumId, $postId);
                }

                $db->commit();

                $post['replies_count'] = (int)$post['replies_count'] + 1;

                $message = t('post_reply_success', '回复发布成功！');
                if ($replyAuthorId !== $postAuthorId && !has_earned_points_for_source($replyAuthorId, 'reply_create', 'post', $postId)) {
                    $message .= t('post_reply_points', '获得 {points} 积分。', ['points' => REPLY_POINTS]);
                }
                if ($newGroup) {
                    $message .= t('post_reply_level_up', ' 恭喜升级为 {title}！', ['title' => $newGroup['title']]);
                }
                set_flash($message, 'success');

                // 发送回复通知
                $notifyPage = max(1, (int)ceil((int)$post['replies_count'] / REPLIES_PER_PAGE));
                $notifyLink = site_url('post', ['id' => $postId, 'page' => $notifyPage]);
                $notifySnippet = mb_substr(strip_tags($replyContent), 0, 60, 'UTF-8');
                if ($replyAuthorId !== $postAuthorId) {
                    send_notification(
                        $postAuthorId,
                        'reply',
                        t('post_179d00','你的帖子收到新回复'),
                        $notifySnippet ?: t('post_c2f9ca','有人回复了你的帖子'),
                        $notifyLink
                    );
                }
                if ($replyTo !== null && $replyTo > 0) {
                    $parentStmt = $db->prepare("SELECT user_id FROM replies WHERE id = :id");
                    $parentStmt->execute([':id' => $replyTo]);
                    $parentUserId = (int)$parentStmt->fetchColumn();
                    if ($parentUserId > 0 && $parentUserId !== $replyAuthorId && $parentUserId !== $postAuthorId) {
                        send_notification(
                            $parentUserId,
                            'reply',
                            t('post_1695f5','有人回复了你的评论'),
                            $notifySnippet ?: t('post_1695f5','有人回复了你的评论'),
                            $notifyLink
                        );
                    }
                }

                $gotoLastPage = isset($_POST['goto_last_page']) && $_POST['goto_last_page'] === '1';
                if ($gotoLastPage) {
                    $totalReplies = (int)$post['replies_count'];
                    $targetPage = max(1, (int)ceil($totalReplies / REPLIES_PER_PAGE));
                } else {
                    // 保持用户当前所在页（POST 分支中 $_GET['page'] 仍然有效）
                    $targetPage = max(1, (int)($_GET['page'] ?? 1));
                }
                redirect('/post?id=' . $postId . '&page=' . $targetPage);
            } catch (Exception $e) {
                if ($inReplyTransaction) {
                    try { $db->rollBack(); } catch (Exception $ignored) {}
                }
                error_log(t('post_8af7d4','回复失败：') . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                error_log(t('post_24d2f9','回复失败 trace: ') . $e->getTraceAsString());
                $errors[] = t('post_error_reply_failed', '回复发布失败，请重试。');
            }
        }
    }
}

// 只看该作者过滤
$authorFilter = isset($_GET['author']) ? (int)$_GET['author'] : 0;

// 回复分页
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = REPLIES_PER_PAGE;
$offset = ($page - 1) * $perPage;

if ($authorFilter > 0) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM replies WHERE post_id = :post_id AND user_id = :user_id");
    $stmt->execute([':post_id' => $postId, ':user_id' => $authorFilter]);
} else {
    $stmt = $db->prepare("SELECT COUNT(*) FROM replies WHERE post_id = :post_id");
    $stmt->execute([':post_id' => $postId]);
}
$totalReplies = (int)$stmt->fetchColumn();

if ($authorFilter > 0) {
    $stmt = $db->prepare("SELECT r.*, u.username, u.avatar, u.points, u.posts_count, u.created_at AS user_created_at
        FROM replies r
        JOIN users u ON r.user_id = u.id
        WHERE r.post_id = :post_id AND r.user_id = :user_id
        ORDER BY r.floor ASC, r.created_at ASC
        LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':post_id', $postId, PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $authorFilter, PDO::PARAM_INT);
} else {
    $stmt = $db->prepare("SELECT r.*, u.username, u.avatar, u.points, u.posts_count, u.created_at AS user_created_at
        FROM replies r
        JOIN users u ON r.user_id = u.id
        WHERE r.post_id = :post_id
        ORDER BY r.floor ASC, r.created_at ASC
        LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':post_id', $postId, PDO::PARAM_INT);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$replies = $stmt->fetchAll();

// 批量获取被引用回复的信息（用于显示引用块）
$quotedReplies = [];
$quoteIds = [];
foreach ($replies as $reply) {
    if (!empty($reply['reply_to'])) {
        $quoteIds[] = (int)$reply['reply_to'];
    }
}
if (!empty($quoteIds)) {
    $quoteIds = array_values(array_unique($quoteIds));
    $safeIds = implode(',', array_map('intval', $quoteIds));
    $stmt = $db->query("SELECT r.id, r.floor, r.content, u.username FROM replies r JOIN users u ON r.user_id = u.id WHERE r.id IN ($safeIds)");
    foreach ($stmt->fetchAll() as $row) {
        $quotedReplies[(int)$row['id']] = $row;
    }
}

// 帖子所属版块（用于面包屑）
$forum = null;
if (!empty($post['forum_id'])) {
    $forum = get_forum((int)$post['forum_id']);
}

// 是否已收藏
$favorited = is_favorited($postId);

// 楼主等级头衔
$authorTitle = user_title((int)$post['posts_count'], (int)$post['points']);

$pageTitle = strip_bbcode($post['title']);
include APP_ROOT . 'app/includes/header.php';
?>

<nav class="breadcrumb" aria-label="<?php echo e(t('post_breadcrumb', '面包屑导航\"')); ?>>
    <a href="/"><?php echo e(t('post_home', '首页')); ?></a>
    <?php if ($forum): ?>
        <span class="breadcrumb-separator">/</span>
        <a href="<?php echo e(site_url('forum', ['id' => (int)$forum['id']])); ?>"><?php echo e($forum['name']); ?></a>
    <?php endif; ?>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?php echo e(strip_bbcode($post['title'])); ?></span>
</nav>

<div class="page-header">
    <h1 class="page-title">
        <span class="post-marks">
            <?php if ((int)$post['is_pinned'] === 1): ?><span class="badge badge-warning"><?php echo e(t('post_badge_pinned', '置顶')); ?></span><?php endif; ?>
            <?php if ((int)$post['is_essence'] === 1): ?><span class="badge badge-success"><?php echo e(t('post_badge_essence', '精华')); ?></span><?php endif; ?>
            <?php if ((int)$post['is_locked'] === 1): ?><span class="badge badge-danger"><?php echo e(t('post_badge_locked', '锁定')); ?></span><?php endif; ?>
        </span>
        <?php echo e(strip_bbcode($post['title'])); ?>
    </h1>
    <div class="page-header-actions">
        <?php if (is_logged_in()): ?>
            <?php if ($favorited): ?>
                <a href="<?php echo site_url('post', ['id' => $postId, 'fav_action' => 'remove', 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('post_unfavorite', '取消收藏')); ?></a>
            <?php else: ?>
                <a href="<?php echo site_url('post', ['id' => $postId, 'fav_action' => 'add', 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('post_favorite', '收藏')); ?></a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (is_admin()): ?>
            <?php if ((int)$post['is_pinned'] === 1): ?>
                <a href="<?php echo site_url('post', ['id' => $postId, 'action' => 'unpin', 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('post_unpin', '取消置顶')); ?></a>
            <?php else: ?>
                <a href="<?php echo site_url('post', ['id' => $postId, 'action' => 'pin', 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('post_pin', '置顶')); ?></a>
            <?php endif; ?>
            <?php if ((int)$post['is_essence'] === 1): ?>
                <a href="<?php echo site_url('post', ['id' => $postId, 'action' => 'unessence', 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('post_unessence', '取消加精')); ?></a>
            <?php else: ?>
                <a href="<?php echo site_url('post', ['id' => $postId, 'action' => 'essence', 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('post_essence', '加精')); ?></a>
            <?php endif; ?>
            <?php if ((int)$post['is_locked'] === 1): ?>
                <a href="<?php echo site_url('post', ['id' => $postId, 'action' => 'unlock', 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('post_unlock', '解锁')); ?></a>
            <?php else: ?>
                <a href="<?php echo site_url('post', ['id' => $postId, 'action' => 'lock', 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('post_lock', '锁定')); ?></a>
            <?php endif; ?>
            <a href="<?php echo site_url('post', ['id' => $postId, 'action' => 'delete', 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-danger" data-confirm="<?php echo e(t('post_delete_confirm', '确定删除该帖子吗？此操作不可撤销。\"')); ?>><?php echo e(t('post_delete', '删除')); ?></a>
        <?php endif; ?>
    </div>
</div>

<!-- 1楼：楼主 -->
<div class="card">
    <article class="floor-item">
        <div class="floor-sidebar">
            <a href="<?php echo site_url('profile', ['user_id' => (int)$post['user_id']]); ?>" class="floor-avatar-link" title="<?php echo e(t('post_view_profile', '查看 {name} 的主页', ['name' => $post['username']])); ?>">
                <img src="<?php echo avatar_url($post['avatar'], $post['username']); ?>" alt="" class="floor-avatar">
            </a>
            <div class="floor-username"><a href="<?php echo site_url('profile', ['user_id' => (int)$post['user_id']]); ?>" title="<?php echo e(t('post_view_profile', '查看 {name} 的主页', ['name' => $post['username']])); ?>"><?php echo e($post['username']); ?></a></div>
            <span class="floor-title-badge" style="background: <?php echo e($authorTitle['color']); ?>"><?php echo e($authorTitle['title']); ?></span>
            <div class="floor-user-meta">
                <div><?php echo e(t('post_meta_posts', '帖子')); ?> <?php echo (int)$post['posts_count']; ?></div>
                <div><?php echo e(t('post_meta_points', '积分')); ?> <?php echo (int)$post['points']; ?></div>
                <div><?php echo e(t('post_meta_joined', '注册')); ?> <?php echo e(date('Y-m-d', db_time($post['user_created_at']))); ?></div>
            </div>
        </div>
        <div class="floor-body">
            <div class="floor-header">
                <div class="floor-header-meta">
                    <span class="floor-header-stat" title="<?php echo e(t('post_views_title', '浏览次数\"')); ?>><?php echo ui_icon('eye', 14); ?> <?php echo (int)$post['views']; ?></span>
                    <span class="floor-header-stat" title="<?php echo e(t('post_replies_title', '回复数量\"')); ?>><?php echo ui_icon('message-circle', 14); ?> <?php echo (int)$post['replies_count']; ?></span>
                    <span><?php echo e(t('post_posted_at', '发表于')); ?> <?php echo e(date('Y-m-d H:i', db_time($post['created_at']))); ?></span>
                    <a href="<?php echo site_url('post', ['id' => $postId, 'author' => (int)$post['user_id']]); ?>" class="floor-header-link"><?php echo e(t('post_only_author', '只看该作者')); ?></a>
                </div>
                <span class="floor-badge floor-badge-op"><?php echo floor_label(1); ?></span>
            </div>
            <div class="floor-content">
                <?php echo bbcode($post['content']); ?>
            </div>
            <?php if (!empty($post['signature'])): ?>
                <div class="floor-signature"><?php echo e($post['signature']); ?></div>
            <?php endif; ?>

            <div class="floor-actions">
                <div class="floor-actions-left">
                    <?php if (is_logged_in() && (int)$_SESSION['user_id'] !== (int)$post['user_id']): ?>
                        <a href="<?php echo site_url('pm', ['action' => 'new', 'to' => (int)$post['user_id']]); ?>" class="btn btn-sm btn-secondary" title="<?php echo e(t('post_send_pm_to', '给 {name} 发私信', ['name' => $post['username']])); ?>"><?php echo e(t('post_send_pm', '发私信')); ?></a>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </article>
</div>

<!-- 回复列表 -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <?php if ($authorFilter > 0): ?>
                <?php echo e(t('post_author_replies', '该作者的回复（{count}）', ['count' => $totalReplies])); ?>
                <a href="<?php echo site_url('post', ['id' => $postId]); ?>" class="btn btn-sm btn-secondary ml-2"><?php echo e(t('post_view_all', '查看全部')); ?></a>
            <?php else: ?>
                <?php echo e(t('post_all_replies', '全部回复（{count}）', ['count' => (int)$post['replies_count']])); ?>
            <?php endif; ?>
        </h2>
    </div>

    <?php if (empty($replies)): ?>
        <div class="empty-state" style="padding: 2rem 1rem;">
            <div class="empty-state-icon">
                <svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            </div>
            <?php if ($authorFilter > 0): ?>
                <p><?php echo e(t('post_author_no_replies', '该作者在此帖下暂无回复。')); ?></p>
                <a href="<?php echo site_url('post', ['id' => $postId]); ?>" class="btn btn-sm btn-secondary mt-1"><?php echo e(t('post_view_all_replies', '查看全部回复')); ?></a>
            <?php else: ?>
                <p><?php echo e(t('post_no_replies', '暂无回复，来说两句吧～')); ?></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($replies as $loopIndex => $reply):
            $replyTitle = user_title((int)$reply['posts_count'], (int)$reply['points']);
            $displayFloor = (int)$reply['floor'] > 0 ? (int)$reply['floor'] : ($offset + $loopIndex + 2);
        ?>
            <article class="floor-item" id="reply-<?php echo (int)$reply['id']; ?>">
                <div class="floor-sidebar">
                    <a href="<?php echo site_url('profile', ['user_id' => (int)$reply['user_id']]); ?>" class="floor-avatar-link" title="<?php echo e(t('post_view_profile', '查看 {name} 的主页', ['name' => $reply['username']])); ?>">
                        <img src="<?php echo avatar_url($reply['avatar'], $reply['username']); ?>" alt="" class="floor-avatar">
                    </a>
                    <div class="floor-username"><a href="<?php echo site_url('profile', ['user_id' => (int)$reply['user_id']]); ?>" title="<?php echo e(t('post_view_profile', '查看 {name} 的主页', ['name' => $reply['username']])); ?>"><?php echo e($reply['username']); ?></a></div>
                    <span class="floor-title-badge" style="background: <?php echo e($replyTitle['color']); ?>"><?php echo e($replyTitle['title']); ?></span>
                    <div class="floor-user-meta">
                        <div><?php echo e(t('post_meta_posts', '帖子')); ?> <?php echo (int)$reply['posts_count']; ?></div>
                        <div><?php echo e(t('post_meta_points', '积分')); ?> <?php echo (int)$reply['points']; ?></div>
                    </div>
                </div>
                <div class="floor-body">
                    <div class="floor-header">
                        <div class="floor-header-meta">
                            <span><?php echo e(t('post_posted_at', '发表于')); ?> <?php echo e(date('Y-m-d H:i', db_time($reply['created_at']))); ?></span>
                            <a href="<?php echo site_url('post', ['id' => $postId, 'author' => (int)$reply['user_id']]); ?>" class="floor-header-link"><?php echo e(t('post_only_author', '只看该作者')); ?></a>
                        </div>
                        <span class="floor-badge"><?php echo floor_label($displayFloor); ?></span>
                    </div>

                    <?php
                    $hasContentQuote = stripos($reply['content'], '[quote]') !== false;
                    if (!empty($reply['reply_to']) && isset($quotedReplies[(int)$reply['reply_to']]) && !$hasContentQuote):
                        $quoted = $quotedReplies[(int)$reply['reply_to']];
                    ?>
                        <blockquote class="quote-block">
                            <div class="quote-block-header">
                                <span class="quote-block-author"><?php echo e($quoted['username']); ?></span>
                                <span class="quote-block-floor"><?php echo (int)$quoted['floor']; ?>#</span>
                            </div>
                            <div class="quote-block-content">
                                <?php echo e(mb_substr($quoted['content'], 0, 200, 'UTF-8')); ?>
                                <?php if (mb_strlen($quoted['content'], 'UTF-8') > 200): ?>...<?php endif; ?>
                            </div>
                        </blockquote>
                    <?php elseif (!empty($reply['quote_content']) && !$hasContentQuote):
                        $quoteData = json_decode($reply['quote_content'], true);
                        if (is_array($quoteData) && isset($quoteData['content']) && is_string($quoteData['content'])):
                            $quoteBody = trim($quoteData['content']);
                    ?>
                        <blockquote class="quote-block">
                            <div class="quote-block-header">
                                <span class="quote-block-author"><?php echo e($quoteData['username'] ?? ''); ?></span>
                                <span class="quote-block-floor"><?php echo (int)($quoteData['floor'] ?? 0); ?>#</span>
                            </div>
                            <div class="quote-block-content">
                                <?php echo e(mb_substr($quoteBody, 0, 200, 'UTF-8')); ?>
                                <?php if (mb_strlen($quoteBody, 'UTF-8') > 200): ?>...<?php endif; ?>
                            </div>
                        </blockquote>
                    <?php endif; endif; ?>

                    <div class="floor-content">
                        <?php echo bbcode($reply['content']); ?>
                    </div>

                    <div class="floor-actions">
                        <div class="floor-actions-left"><button type="button" class="btn btn-sm btn-secondary" onclick="replyTo(<?php echo (int)$reply['id']; ?>, <?php echo $displayFloor; ?>, <?php echo htmlspecialchars(json_encode($reply['username'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(mb_substr(strip_tags(bbcode($reply['content'])), 0, 120, 'UTF-8'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)" aria-label="<?php echo e(t('post_reply_to_user', '回复 {name}', ['name' => $reply['username']])); ?>"><?php echo e(t('post_reply_btn', '回复')); ?></button>
                        <?php if (is_logged_in() && (int)$_SESSION['user_id'] !== (int)$reply['user_id']): ?>
                            <a href="<?php echo site_url('pm', ['action' => 'new', 'to' => (int)$reply['user_id']]); ?>" class="btn btn-sm btn-secondary" title="<?php echo e(t('post_send_pm_to', '给 {name} 发私信', ['name' => $reply['username']])); ?>"><?php echo e(t('post_send_pm', '发私信')); ?></a>
                        <?php endif; ?>
                        </div>
                        <div class="floor-actions-right">
                            <button type="button" class="btn btn-sm btn-text floor-action-report" onclick="openReportModal(<?php echo (int)$reply['id']; ?>, 'reply', <?php echo $displayFloor; ?>, <?php echo htmlspecialchars(json_encode($reply['username'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)" aria-label="<?php echo e(t('post_report_reply_of', '举报 {name} 的回复', ['name' => $reply['username']])); ?>">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
                                <?php echo e(t('post_report', '举报')); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>

        <div class="new-replies-banner" id="new-replies-banner" style="display:none;" role="status" aria-live="polite">
            <span id="new-replies-text"><?php echo e(t('post_content_updated', '内容有更新，点击刷新')); ?></span>
            <button type="button" class="btn btn-sm btn-primary" onclick="location.reload()"><?php echo e(t('post_refresh', '刷新')); ?></button>
        </div>

        <?php
        $paginationBaseUrl = site_url('post', ['id' => $postId]);
        if ($authorFilter > 0) {
            $paginationBaseUrl .= '&amp;author=' . $authorFilter;
        }
        echo pagination($page, $totalReplies, $perPage, $paginationBaseUrl);
        ?>
    <?php endif; ?>
</div>

<!-- 回复表单 -->
<?php if ((int)$post['is_locked'] === 1): ?>
    <div class="card text-center">
        <p class="text-muted mb-0"><?php echo e(t('post_error_locked', '帖子已锁定，无法回复。')); ?></p>
    </div>
<?php elseif (is_logged_in() && ($muteMessage = get_user_mute_message((int)$_SESSION['user_id'])) !== null): ?>
    <div class="card text-center">
        <p class="text-error mb-0"><?php echo e($muteMessage); ?><?php echo e(t('post_error_muted_suffix', '无法回复。')); ?></p>
        <p class="mb-0" style="margin-top:0.5rem;">
            <a href="<?php echo site_url('appeal'); ?>"><?php echo e(t('post_appeal_link', '对处罚有异议？申请申诉')); ?></a>
        </p>
    </div>
<?php elseif (is_logged_in()): ?>
    <div class="card" id="reply-form">
        <h3 class="card-title mb-1"><?php echo e(t('post_reply_form_title', '发表回复')); ?></h3>
        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <?php echo show_message($err, 'error'); ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <form method="POST" action="<?php echo site_url('post', ['id' => $postId] + ($page > 1 ? ['page' => $page] : [])); ?>" data-validate>
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="reply_to" id="reply-to" value="">
            <input type="hidden" name="quote_content" id="quote-content" value="">
            <div id="quote-indicator" class="quote-indicator" style="display: none;">
                <span id="quote-text"></span>
                <button type="button" class="btn btn-sm btn-secondary" onclick="clearQuote()"><?php echo e(t('post_cancel_quote', '取消回复')); ?></button>
            </div>

            <!-- 富文本编辑器工具栏 -->
            <div class="editor-toolbar" id="editor-toolbar">
                <button type="button" class="toolbar-btn" title="<?php echo e(t('post_tool_bold', '粗体 (Ctrl+B)\"')); ?> onclick="formatText('b')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/><path d="M6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/></svg>
                </button>
                <button type="button" class="toolbar-btn" title="<?php echo e(t('post_tool_italic', '斜体 (Ctrl+I)\"')); ?> onclick="formatText('i')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/></svg>
                </button>
                <button type="button" class="toolbar-btn" title="<?php echo e(t('post_tool_underline', '下划线 (Ctrl+U)\"')); ?> onclick="formatText('u')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3v7a6 6 0 0 0 6 6 6 6 0 0 0 6-6V3"/><line x1="4" y1="21" x2="20" y2="21"/></svg>
                </button>
                <button type="button" class="toolbar-btn" title="<?php echo e(t('post_tool_strike', '删除线\"')); ?> onclick="formatText('s')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.3 19c.68-1.06 1-2.25 1-3.5a6 6 0 0 0-6-6 6 6 0 0 0-6 6c0 1.25.32 2.44 1 3.5"/><path d="M12 12v9"/><path d="M4 7h16"/></svg>
                </button>
                <span class="toolbar-divider"></span>
                <button type="button" class="toolbar-btn" title="<?php echo e(t('post_tool_link', '插入链接\"')); ?> onclick="insertLink()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                </button>
                <button type="button" class="toolbar-btn" title="<?php echo e(t('post_tool_image', '插入图片链接\"')); ?> onclick="insertImage()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </button>
                <button type="button" class="toolbar-btn" title="<?php echo e(t('post_tool_upload', '上传本地图片\"')); ?> onclick="EditorUpload.uploadLocalImage('reply-content', '<?php echo csrf_token(); ?>')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2" ry="2"/><circle cx="8.5" cy="10.5" r="1.5"/><polyline points="21 17 16 12 12 16 9 13 3 19"/><path d="M12 5V2M9 5l3-3 3 3"/></svg>
                </button>
                <button type="button" class="toolbar-btn" title="<?php echo e(t('post_tool_code', '代码\"')); ?> onclick="insertCode()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                </button>
                <span class="toolbar-divider"></span>
                <button type="button" class="toolbar-btn" title="<?php echo e(t('post_tool_emoji', '插入表情\"')); ?> id="emoji-btn" onclick="toggleEmojiPanel(event)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                </button>
                <button type="button" class="toolbar-btn" title="<?php echo e(t('post_tool_at', '@用户\"')); ?> onclick="insertAt()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/></svg>
                </button>
                <div class="toolbar-spacer"></div>
                <button type="button" class="toolbar-btn toolbar-btn-text" title="<?php echo e(t('post_tool_toggle_height', '切换编辑器高度\"')); ?> id="advanced-mode-toggle" onclick="toggleAdvancedMode()">
                    <?php echo e(t('post_advanced_mode', '高级模式')); ?>
                </button>
            </div>

            <!-- 工具栏内联输入面板（替代 prompt） -->
            <div class="toolbar-input-panel" id="toolbar-input-panel">
                <input type="text" class="form-control" id="toolbar-input-main" placeholder="">
                <input type="text" class="form-control" id="toolbar-input-extra" placeholder="" style="display:none;">
                <div class="toolbar-input-actions">
                    <button type="button" class="btn btn-sm btn-primary" onclick="submitToolbarInput()"><?php echo e(t('post_confirm', '确定')); ?></button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="cancelToolbarInput()"><?php echo e(t('post_cancel', '取消')); ?></button>
                </div>
                <div class="toolbar-input-error" id="toolbar-input-error" style="display:none;"></div>
            </div>

            <!-- 表情选择面板 -->
            <div class="emoji-panel" id="emoji-panel" style="display: none;">
                <div class="emoji-panel-header">
                    <span class="emoji-panel-title"><?php echo e(t('post_emoji_title', '选择表情')); ?></span>
                    <button type="button" class="emoji-panel-close" onclick="toggleEmojiPanel(event)" aria-label="<?php echo e(t('post_close', '关闭\"')); ?>>&times;</button>
                </div>
                <div class="emoji-grid">
                    <?php foreach (get_emoji_list() as $emoji): ?>
                        <button type="button" class="emoji-btn" title="<?php echo e($emoji['name']); ?>" onclick="insertEmoji('<?php echo e($emoji['code']); ?>')">
                            <?php echo e($emoji['code']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group editor-textarea-wrap">
                <textarea class="form-control" id="reply-content" name="content" rows="5" placeholder="<?php echo e(t('post_reply_placeholder', '写下你的回复... 支持 BBCode 语法\"')); ?> required></textarea>
            </div>

            <div class="reply-form-footer">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="goto-last-page" name="goto_last_page" value="1" checked>
                    <label class="form-check-label" for="goto-last-page"><?php echo e(t('post_goto_last_page', '回帖后跳转到最后一页')); ?></label>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo e(t('post_submit_reply', '发布回复')); ?></button>
            </div>
        </form>
    </div>
<?php else: ?>
    <div class="card text-center">
        <p class="text-muted mb-0"><?php echo e(t('post_login_to_reply', '登录后才能回复，')); ?><a href="<?php echo site_url('login'); ?>"><?php echo e(t('post_login_now', '立即登录')); ?></a></p>
    </div>
<?php endif; ?>

<?php if (is_logged_in()): ?>
<!-- 举报弹窗 -->
<div class="modal-overlay" id="report-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="report-modal-title">
    <div class="modal-dialog modal-sm">
        <div class="modal-header">
            <h4 class="modal-title" id="report-modal-title"><?php echo e(t('post_report_modal_title', '举报内容')); ?></h4>
            <button type="button" class="modal-close" onclick="closeReportModal()" aria-label="<?php echo e(t('post_close', '关闭\"')); ?>>&times;</button>
        </div>
        <form method="POST" action="<?php echo site_url('report'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="post_id" id="report-post-id" value="<?php echo $postId; ?>">
            <input type="hidden" name="reply_id" id="report-reply-id" value="">
            <input type="hidden" name="return_url" value="<?php echo e('/post?id=' . $postId . ($page > 1 ? '&amp;page=' . $page : '')); ?>">
            <div class="modal-body">
                <p class="report-target" id="report-target-text"></p>
                <div class="form-group">
                    <label class="form-label" for="report-reason-type"><?php echo e(t('post_report_reason', '举报原因')); ?></label>
                    <select class="form-control" id="report-reason-type" name="reason_type" required>
                        <?php foreach (get_report_reason_types() as $key => $label): ?>
                            <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="report-reason"><?php echo e(t('post_report_note', '补充说明')); ?></label>
                    <textarea class="form-control" id="report-reason" name="reason" rows="3" placeholder="<?php echo e(t('post_report_note_placeholder', '请简要描述举报原因（选填）\"')); ?>></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeReportModal()"><?php echo e(t('post_cancel', '取消')); ?></button>
                <button type="submit" class="btn btn-primary"><?php echo e(t('post_submit_report', '提交举报')); ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="../public/js/editor.js"></script>
<script>
var POST_I18N = {
    linkRequired: <?php echo json_encode(t('post_js_link_required', '请输入链接地址。')); ?>,
    imageRequired: <?php echo json_encode(t('post_js_image_required', '请输入图片地址。')); ?>,
    usernameRequired: <?php echo json_encode(t('post_js_username_required', '请输入用户名。')); ?>,
    linkMainPlaceholder: <?php echo json_encode(t('post_js_link_main_placeholder', '请输入链接地址（可省略 https://，会自动补全）')); ?>,
    linkTextPlaceholder: <?php echo json_encode(t('post_js_link_text_placeholder', '请输入链接文字（留空使用地址）')); ?>,
    imagePlaceholder: <?php echo json_encode(t('post_js_image_placeholder', '请输入图片地址（可省略 https://，会自动补全）')); ?>,
    atPlaceholder: <?php echo json_encode(t('post_js_at_placeholder', '请输入要 @ 的用户名')); ?>,
    simpleMode: <?php echo json_encode(t('post_js_simple_mode', '精简模式')); ?>,
    advancedMode: <?php echo json_encode(t('post_advanced_mode', '高级模式')); ?>,
    reportTarget: <?php echo json_encode(t('post_js_report_target', '被举报对象：{floor}楼 {name} 的{type}')); ?>,
    typePost: <?php echo json_encode(t('post_js_type_post', '帖子')); ?>,
    typeReply: <?php echo json_encode(t('post_js_type_reply', '回复')); ?>,
    replyingTo: <?php echo json_encode(t('post_js_replying_to', '正在回复 {floor}楼 {name}')); ?>,
    autoRefreshPaused: <?php echo json_encode(t('post_js_auto_refresh_paused', '已暂停自动刷新，点击按钮手动刷新')); ?>,
    updatedCountdown: <?php echo json_encode(t('post_js_updated_countdown', '有更新，{seconds} 秒后自动刷新…')); ?>,
    newRepliesCountdown: <?php echo json_encode(t('post_js_new_replies_countdown', '有 {count} 条新回复，{seconds} 秒后自动刷新…')); ?>,
    contentUpdated: <?php echo json_encode(t('post_content_updated', '内容有更新，点击刷新')); ?>
};

function getReplyTextarea() {
    return document.getElementById('reply-content');
}

function insertAtCursor(textBefore, textAfter) {
    var textarea = getReplyTextarea();
    if (!textarea) return;
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var selected = textarea.value.substring(start, end);
    var replacement = textBefore + selected + textAfter;
    textarea.value = textarea.value.substring(0, start) + replacement + textarea.value.substring(end);
    textarea.focus();
    var cursorPos = start + textBefore.length + selected.length;
    textarea.setSelectionRange(cursorPos, cursorPos);
}

function formatText(tag) {
    insertAtCursor('[' + tag + ']', '[/' + tag + ']');
}

var toolbarPanel = document.getElementById('toolbar-input-panel');
var toolbarInputMain = document.getElementById('toolbar-input-main');
var toolbarInputExtra = document.getElementById('toolbar-input-extra');
var toolbarInputError = document.getElementById('toolbar-input-error');
var currentToolbarAction = null;

function showToolbarInput(action, mainPlaceholder, extraPlaceholder) {
    currentToolbarAction = action;
    toolbarInputMain.placeholder = mainPlaceholder || '';
    toolbarInputExtra.placeholder = extraPlaceholder || '';
    toolbarInputExtra.style.display = extraPlaceholder ? 'block' : 'none';
    toolbarInputMain.value = '';
    toolbarInputExtra.value = '';
    toolbarInputError.style.display = 'none';
    toolbarInputError.textContent = '';
    toolbarPanel.classList.add('is-visible');
    toolbarInputMain.focus();
}

function hideToolbarInput() {
    toolbarPanel.classList.remove('is-visible');
    currentToolbarAction = null;
}

function showToolbarError(msg) {
    toolbarInputError.textContent = msg;
    toolbarInputError.style.display = 'block';
}

function submitToolbarInput() {
    if (!currentToolbarAction) return;
    var main = toolbarInputMain.value.trim();
    var extra = toolbarInputExtra.value.trim();
    toolbarInputError.style.display = 'none';
    toolbarInputError.textContent = '';
    if (currentToolbarAction === 'link') {
        if (!main) {
            showToolbarError(POST_I18N.linkRequired);
            return;
        }
        // 自动补全协议：未以 http:// 或 https:// 开头时自动补上 https://
        if (!/^https?:\/\//i.test(main)) {
            main = 'https://' + main;
        }
        if (extra) {
            insertAtCursor('[url=' + main + ']' + extra + '[/url]', '');
        } else {
            insertAtCursor('[url]' + main + '[/url]', '');
        }
    } else if (currentToolbarAction === 'image') {
        if (!main) {
            showToolbarError(POST_I18N.imageRequired);
            return;
        }
        // 自动补全协议：未以 http:// 或 https:// 开头时自动补上 https://
        if (!/^https?:\/\//i.test(main)) {
            main = 'https://' + main;
        }
        insertAtCursor('[img]' + main + '[/img]', '');
    } else if (currentToolbarAction === 'at') {
        if (!main) {
            showToolbarError(POST_I18N.usernameRequired);
            return;
        }
        insertAtCursor('@' + main + ' ', '');
    }
    hideToolbarInput();
}

function cancelToolbarInput() {
    hideToolbarInput();
}

function insertLink() {
    showToolbarInput('link', POST_I18N.linkMainPlaceholder, POST_I18N.linkTextPlaceholder);
}

function insertImage() {
    showToolbarInput('image', POST_I18N.imagePlaceholder);
}

function insertCode() {
    insertAtCursor('[code]\n', '\n[/code]');
}

function insertAt() {
    showToolbarInput('at', POST_I18N.atPlaceholder);
}

function insertEmoji(emoji) {
    insertAtCursor(emoji, '');
    toggleEmojiPanel(null, false);
}

function toggleEmojiPanel(event, forceState) {
    if (event) {
        event.stopPropagation();
    }
    var panel = document.getElementById('emoji-panel');
    if (!panel) return;
    if (typeof forceState === 'boolean') {
        panel.style.display = forceState ? 'block' : 'none';
    } else {
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    }
}

function toggleAdvancedMode() {
    var textarea = getReplyTextarea();
    var btn = document.getElementById('advanced-mode-toggle');
    if (!textarea || !btn) return;
    if (textarea.rows <= 5) {
        textarea.rows = 12;
        btn.textContent = POST_I18N.simpleMode;
        btn.classList.add('active');
    } else {
        textarea.rows = 5;
        btn.textContent = POST_I18N.advancedMode;
        btn.classList.remove('active');
    }
}

// 点击页面其他区域关闭表情面板
document.addEventListener('click', function(e) {
    var panel = document.getElementById('emoji-panel');
    var btn = document.getElementById('emoji-btn');
    if (!panel || panel.style.display === 'none') return;
    if (btn && btn.contains(e.target)) return;
    if (panel.contains(e.target)) return;
    panel.style.display = 'none';
});

// 编辑器快捷键
document.addEventListener('keydown', function(e) {
    var textarea = getReplyTextarea();
    if (!textarea || document.activeElement !== textarea) return;
    if (e.ctrlKey || e.metaKey) {
        if (e.key === 'b') { e.preventDefault(); formatText('b'); }
        else if (e.key === 'i') { e.preventDefault(); formatText('i'); }
        else if (e.key === 'u') { e.preventDefault(); formatText('u'); }
        else if (e.key === 'k') { e.preventDefault(); insertLink(); }
    }
});

function openReportModal(replyId, type, floor, username) {
    var modal = document.getElementById('report-modal');
    var replyField = document.getElementById('report-reply-id');
    var targetText = document.getElementById('report-target-text');
    if (!modal || !replyField) return;
    replyField.value = replyId || '';
    if (targetText) {
        targetText.textContent = POST_I18N.reportTarget
            .replace('{floor}', floor)
            .replace('{name}', username || '')
            .replace('{type}', type === 'post' ? POST_I18N.typePost : POST_I18N.typeReply);
    }
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeReportModal() {
    var modal = document.getElementById('report-modal');
    if (!modal) return;
    modal.style.display = 'none';
    document.body.style.overflow = '';
}

// 点击遮罩关闭举报弹窗
document.addEventListener('click', function(e) {
    var modal = document.getElementById('report-modal');
    if (!modal || modal.style.display === 'none') return;
    if (e.target === modal) {
        closeReportModal();
    }
});

// ESC 关闭举报弹窗
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeReportModal();
    }
});

function replyTo(replyId, floor, username, content) {
    var form = document.getElementById('reply-form');
    var replyToField = document.getElementById('reply-to');
    var quoteContent = document.getElementById('quote-content');
    var indicator = document.getElementById('quote-indicator');
    var quoteText = document.getElementById('quote-text');
    var textarea = getReplyTextarea();
    if (!form || !replyToField) return;
    replyToField.value = replyId;
    if (quoteContent) quoteContent.value = '';
    if (indicator && quoteText) {
        quoteText.textContent = POST_I18N.replyingTo.replace('{floor}', floor).replace('{name}', username);
        indicator.style.display = 'block';
    }

    // 引用信息写入隐藏字段（发布时由服务端渲染成引用块），
    // 不再向编辑框插入 [quote] 原始标签，避免用户看到原始 BBCode
    if (quoteContent) {
        quoteContent.value = JSON.stringify({
            username: username,
            floor: floor,
            content: content || ''
        });
    }

    form.scrollIntoView({ behavior: 'smooth' });
    if (textarea) {
        setTimeout(function() { textarea.focus(); }, 300);
    }
}

function clearQuote() {
    var replyTo = document.getElementById('reply-to');
    var quoteContent = document.getElementById('quote-content');
    var indicator = document.getElementById('quote-indicator');
    if (replyTo) replyTo.value = '';
    if (quoteContent) quoteContent.value = '';
    if (indicator) indicator.style.display = 'none';
}

// 实时检测新回复（轮询 + 自动刷新）
(function () {
    var postId = <?php echo (int)$postId; ?>;
    var authorFilter = <?php echo (int)$authorFilter; ?>;
    var initialCount = <?php echo (int)$totalReplies; ?>;
    var banner = document.getElementById('new-replies-banner');
    var bannerText = document.getElementById('new-replies-text');
    var textarea = document.querySelector('.reply-textarea, #reply-content, textarea[name="content"]');
    if (!banner || postId <= 0) return;

    var interval = 1000; // 1 秒检测一次（接口仅 1 条带索引的 COUNT，极轻量）
    var autoRefreshDelay = 5000; // 5 秒后自动刷新
    var url = '<?php echo site_url('api/post_replies_count'); ?>&id=' + postId + (authorFilter > 0 ? '&author=' + authorFilter : '');
    var refreshTimer = null;
    var userCancelled = false;

    function cancelAutoRefresh() {
        if (refreshTimer) {
            clearTimeout(refreshTimer);
            refreshTimer = null;
        }
        userCancelled = true;
        if (bannerText) bannerText.textContent = POST_I18N.autoRefreshPaused;
    }

    if (textarea) {
        textarea.addEventListener('focus', cancelAutoRefresh);
        textarea.addEventListener('input', cancelAutoRefresh);
    }

    function scheduleRefresh() {
        if (refreshTimer || userCancelled) return;
        var remaining = Math.ceil(autoRefreshDelay / 1000);
        function tick() {
            remaining--;
            if (remaining <= 0) {
                location.reload();
                return;
            }
            if (bannerText && !userCancelled) {
                bannerText.textContent = POST_I18N.updatedCountdown.replace('{seconds}', remaining);
            }
            refreshTimer = setTimeout(tick, 1000);
        }
        refreshTimer = setTimeout(tick, 0);
    }

    function check() {
        if (typeof fetch !== 'function') return;
        fetch(url, { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.success) return;
                var count = parseInt(data.count, 10);
                if (count !== initialCount) {
                    var diff = count - initialCount;
                    if (bannerText && !refreshTimer && !userCancelled) {
                        bannerText.textContent = diff > 0
                            ? POST_I18N.newRepliesCountdown.replace('{count}', diff).replace('{seconds}', Math.ceil(autoRefreshDelay / 1000))
                            : POST_I18N.contentUpdated;
                    }
                    banner.style.display = 'flex';
                    scheduleRefresh();
                }
            })
            .catch(function () { /* 忽略网络错误 */ });
    }

    // 1 秒轮询：setTimeout 链 + 请求去重，响应慢时不会堆积请求（不阻塞）
    var checking = false;
    var timer = null;
    function pollLoop() {
        timer = setTimeout(function () {
            if (!checking) {
                checking = true;
                fetch(url, { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data || !data.success) return;
                        var count = parseInt(data.count, 10);
                        if (count !== initialCount) {
                            var diff = count - initialCount;
                            if (bannerText && !refreshTimer && !userCancelled) {
                                bannerText.textContent = diff > 0
                                    ? POST_I18N.newRepliesCountdown.replace('{count}', diff).replace('{seconds}', Math.ceil(autoRefreshDelay / 1000))
                                    : POST_I18N.contentUpdated;
                            }
                            banner.style.display = 'flex';
                            scheduleRefresh();
                        }
                    })
                    .catch(function () {})
                    .then(function () {
                        checking = false;
                    });
            }
            pollLoop();
        }, interval);
    }
    pollLoop();
    // 移除旧的 setInterval，统一由 pollLoop 调度
})();
</script>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
