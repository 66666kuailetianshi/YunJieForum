<?php
/**
 * 云界论坛 - 版块帖子列表（火绒风格）
 */

require_once APP_ROOT . 'app/includes/functions.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

update_last_active();

$db = get_db();
$forumId = (int)($_GET['id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = POSTS_PER_PAGE;
$offset = ($page - 1) * $perPage;

$forum = get_forum($forumId);

if (!$forum) {
    http_response_code(404);
    $pageTitle = t('forum_not_found_title', '版块不存在');
    include APP_ROOT . 'app/includes/header.php';
    echo '<div class="card empty-state"><div class="empty-state-icon"><svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></div><p>' . e(t('forum_not_found_desc', '版块不存在或已被删除。')) . '</p><a href="/" class="btn btn-primary">' . e(t('forum_back_home', '返回首页')) . '</a></div>';
    include APP_ROOT . 'app/includes/footer.php';
    exit;
}

// 该版块下的主题总数
$stmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE forum_id = :fid");
$stmt->execute([':fid' => $forumId]);
$total = (int)$stmt->fetchColumn();

// 取帖子列表（置顶优先，其次按更新时间倒序）
$stmt = $db->prepare("SELECT p.id, p.title, p.views, p.replies_count, p.is_pinned, p.is_essence, p.is_locked,
    p.created_at, p.updated_at,
    u.username, u.avatar, u.posts_count, u.points
    FROM posts p
    JOIN users u ON p.user_id = u.id
    WHERE p.forum_id = :fid
    ORDER BY p.is_pinned DESC, p.updated_at DESC
    LIMIT :limit OFFSET :offset");
$stmt->bindValue(':fid', $forumId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll();

$pageTitle = $forum['name'];
include APP_ROOT . 'app/includes/header.php';
?>

<nav class="breadcrumb" aria-label="<?php echo e(t('forum_breadcrumb', '面包屑导航')); ?>">
    <a href="/"><?php echo e(t('forum_home', '首页')); ?></a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?php echo e($forum['name']); ?></span>
</nav>

<div class="page-header">
    <div class="forum-header-card">
        <div class="forum-header-icon">
            <?php echo forum_icon($forum['icon'], 36, $forum['name']); ?>
        </div>
        <div class="forum-header-meta">
            <h1 class="forum-header-name"><?php echo e($forum['name']); ?></h1>
            <?php if (!empty($forum['description'])): ?>
                <p class="forum-header-desc"><?php echo e($forum['description']); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php if (is_logged_in()): ?>
        <a href="<?php echo site_url('new_post', ['forum_id' => $forumId]); ?>" class="btn btn-primary"><?php echo e(t('forum_new_post', '发新帖')); ?></a>
    <?php else: ?>
        <a href="<?php echo site_url('login'); ?>" class="btn btn-secondary"><?php echo e(t('forum_login_to_post', '登录后发帖')); ?></a>
    <?php endif; ?>
</div>

<?php if (empty($posts)): ?>
    <div class="card empty-state">
        <div class="empty-state-icon">
            <svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
        </div>
        <p><?php echo e(t('forum_empty', '该版块下还没有主题，快来发布第一条吧！')); ?></p>
        <?php if (is_logged_in()): ?>
            <a href="<?php echo site_url('new_post', ['forum_id' => $forumId]); ?>" class="btn btn-primary"><?php echo e(t('forum_publish_post', '发布新帖')); ?></a>
        <?php else: ?>
            <a href="<?php echo site_url('login'); ?>" class="btn btn-primary"><?php echo e(t('forum_login_to_post', '登录后发帖')); ?></a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="thread-list">
        <?php foreach ($posts as $post): ?>
            <?php
            $title = user_title((int)$post['posts_count'], (int)$post['points']);
            $displayTime = ($post['replies_count'] > 0) ? $post['updated_at'] : $post['created_at'];
            ?>
            <article class="thread-item <?php echo $post['is_pinned'] ? 'pinned' : ''; ?>">
                <img src="<?php echo avatar_url($post['avatar'], $post['username']); ?>" alt="" class="thread-avatar avatar">
                <div class="thread-main">
                    <h3 class="thread-title">
                        <a href="<?php echo site_url('post', ['id' => (int)$post['id']]); ?>">
                            <span class="thread-title-text"><?php echo e(strip_bbcode($post['title'])); ?></span>
                        </a>
                        <span class="thread-badges">
                            <?php if ($post['is_pinned']): ?><span class="badge badge-warning"><?php echo e(t('forum_badge_pinned', '置顶')); ?></span><?php endif; ?>
                            <?php if ($post['is_essence']): ?><span class="badge badge-success"><?php echo e(t('forum_badge_essence', '精华')); ?></span><?php endif; ?>
                            <?php if ($post['is_locked']): ?><span class="badge badge-danger"><?php echo e(t('forum_badge_locked', '锁定')); ?></span><?php endif; ?>
                        </span>
                    </h3>
                    <div class="thread-author">
                        <span><?php echo e($post['username']); ?></span>
                        <span>·</span>
                        <span><?php echo time_ago($displayTime); ?></span>
                        <span>·</span>
                        <span class="author-title" style="background: <?php echo e($title['color']); ?>"><?php echo e($title['title']); ?></span>
                    </div>
                </div>
                <div class="thread-stats">
                    <div><span class="thread-stats-num"><?php echo (int)$post['replies_count']; ?></span> <?php echo e(t('forum_replies', '回复')); ?></div>
                    <div><span class="thread-stats-num"><?php echo (int)$post['views']; ?></span> <?php echo e(t('forum_views', '浏览')); ?></div>
                </div>
                <div class="thread-last-reply">
                    <?php echo time_ago($displayTime); ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php echo pagination($page, $total, $perPage, site_url('forum', ['id' => $forumId])); ?>
<?php endif; ?>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
