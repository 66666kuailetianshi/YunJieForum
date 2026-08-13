<?php
/**
 * 云界论坛 - 标签筛选 / 标签云
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

update_last_active();

$db = get_db();
$tagName = isset($_GET['name']) ? trim(urldecode((string)$_GET['name'])) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = POSTS_PER_PAGE;
$offset = ($page - 1) * $perPage;

$tag = null;
if ($tagName !== '') {
    $stmt = $db->prepare("SELECT * FROM post_tags WHERE name = :name");
    $stmt->execute([':name' => $tagName]);
    $tag = $stmt->fetch();
}

$posts = [];
$total = 0;
if ($tag) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM post_tag_map m WHERE m.tag_id = :tid");
    $stmt->execute([':tid' => (int)$tag['id']]);
    $total = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT p.id, p.title, p.views, p.replies_count, p.is_pinned, p.is_essence, p.is_locked, p.post_type, p.created_at, p.updated_at,
        u.username, u.avatar, u.posts_count, u.points
        FROM post_tag_map m
        JOIN posts p ON m.post_id = p.id
        JOIN users u ON p.user_id = u.id OR p.user_id = u.uid
        WHERE m.tag_id = :tid
        ORDER BY p.updated_at DESC
        LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':tid', (int)$tag['id'], PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();
}

$popularTags = get_popular_tags(40);

$pageTitle = $tag ? t('tag_page_title', '标签：{name}', ['name' => $tagName]) : t('tag_not_found_title', '标签不存在');
include APP_ROOT . 'app/includes/header.php';
?>

<nav class="breadcrumb" aria-label="<?php echo e(t('tag_breadcrumb_aria', '面包屑导航')); ?>">
    <a href="/"><?php echo e(t('tag_home', '首页')); ?></a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?php echo e($tag ? $tagName : t('tag_not_found', '标签未找到')); ?></span>
</nav>

<div class="page-header">
    <h1 class="page-title">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-4px;"><path d="M20.59 13.41 11 3.83A2 2 0 0 0 9.59 3H4v5.59A2 2 0 0 0 4.59 10l9.59 9.59a2 2 0 0 0 2.82 0l4.59-4.59a2 2 0 0 0 0-2.59z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
        <?php echo e($tag ? t('tag_heading', '标签：{name}', ['name' => $tagName]) : t('tag_not_found', '标签未找到')); ?>
    </h1>
</div>

<?php if (!$tag): ?>
    <div class="card empty-state">
        <div class="empty-state-icon"><?php echo ui_icon('tag', 64); ?></div>
        <p><?php echo e(t('tag_not_found_desc', '没有找到该标签，看看下面的热门标签吧。')); ?></p>
    </div>
<?php elseif (empty($posts)): ?>
    <div class="card empty-state">
        <p><?php echo e(t('tag_empty', '该标签下还没有帖子。')); ?></p>
    </div>
<?php else: ?>
    <div class="thread-list">
        <?php foreach ($posts as $post): ?>
            <?php $title = user_title((int)$post['posts_count'], (int)$post['points']); ?>
            <article class="thread-item <?php echo !empty($post['is_pinned']) ? 'pinned' : ''; ?>">
                <img src="<?php echo avatar_url($post['avatar'], $post['username']); ?>" alt="" class="thread-avatar avatar">
                <div class="thread-main">
                    <h3 class="thread-title">
                        <a href="<?php echo site_url('post', ['id' => (int)$post['id']]); ?>">
                            <span class="thread-title-text"><?php echo e(strip_bbcode($post['title'])); ?></span>
                        </a>
                        <span class="thread-badges">
                            <?php if (!empty($post['is_pinned'])): ?><span class="badge badge-warning"><?php echo e(t('forum_badge_pinned', '置顶')); ?></span><?php endif; ?>
                            <?php if (!empty($post['is_essence'])): ?><span class="badge badge-success"><?php echo e(t('forum_badge_essence', '精华')); ?></span><?php endif; ?>
                            <?php if (!empty($post['is_locked'])): ?><span class="badge badge-danger"><?php echo e(t('forum_badge_locked', '锁定')); ?></span><?php endif; ?>
                            <?php if (($post['post_type'] ?? 'normal') === 'vote'): ?><span class="badge badge-info"><?php echo e(t('newpost_type_vote', '投票')); ?></span><?php endif; ?>
                            <?php if (($post['post_type'] ?? 'normal') === 'debate'): ?><span class="badge badge-info"><?php echo e(t('newpost_type_debate', '辩论')); ?></span><?php endif; ?>
                            <?php if (($post['post_type'] ?? 'normal') === 'bounty'): ?><span class="badge badge-warning"><?php echo e(t('newpost_type_bounty', '悬赏')); ?></span><?php endif; ?>
                        </span>
                    </h3>
                    <div class="thread-author">
                        <span><?php echo e($post['username']); ?></span>
                        <span>·</span>
                        <span><?php echo time_ago($post['updated_at']); ?></span>
                        <span>·</span>
                        <span class="author-title" style="background: <?php echo e($title['color']); ?>"><?php echo e($title['title']); ?></span>
                    </div>
                </div>
                <div class="thread-stats">
                    <div><span class="thread-stats-num"><?php echo (int)$post['replies_count']; ?></span> <?php echo e(t('forum_replies', '回复')); ?></div>
                    <div><span class="thread-stats-num"><?php echo (int)$post['views']; ?></span> <?php echo e(t('forum_views', '浏览')); ?></div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php echo pagination($page, $total, $perPage, site_url('tag', ['name' => urlencode($tagName)])); ?>
<?php endif; ?>

<?php if (!empty($popularTags)): ?>
<div class="card mt-3">
    <h3 class="card-title"><?php echo e(t('tag_cloud_title', '热门标签')); ?></h3>
    <div class="tag-cloud">
        <?php foreach ($popularTags as $pt): ?>
            <a class="tag-chip" href="<?php echo site_url('tag', ['name' => urlencode($pt['name'])]); ?>"><?php echo e($pt['name']); ?><span class="tag-count"><?php echo (int)$pt['usage_count']; ?></span></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<style>
.tag-cloud { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.5rem; }
.tag-chip { display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .6rem; background:var(--tag-bg,#eef2ff); color:var(--tag-color,#4f46e5); border-radius:999px; font-size:.85rem; text-decoration:none; }
.tag-chip:hover { background:var(--tag-bg-hover,#e0e7ff); }
.tag-count { font-size:.7rem; color:var(--text-muted,#6b7280); }
</style>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
