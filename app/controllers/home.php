<?php
/**
 * 云界论坛 - 首页（火绒安全论坛风格）
 */

require_once APP_ROOT . 'app/includes/functions.php';

if (!is_forum_installed()) {
    // 清除残留的安装锁文件，确保下次会重定向到安装向导
    if (file_exists(INSTALLED_FILE)) {
        @unlink(INSTALLED_FILE);
    }
    redirect('/install');
}

update_last_active();

$db = get_db();
$announcements = get_active_announcements();
$categories = get_forums_by_category();
$stats = forum_stats();

// 获取最新 10 条帖子
$stmt = $db->prepare("SELECT p.id, p.title, p.views, p.replies_count, p.is_pinned, p.is_essence, p.is_locked, p.created_at,
    u.username, u.avatar
    FROM posts p
    JOIN users u ON p.user_id = u.id
    ORDER BY p.created_at DESC
    LIMIT :limit");
$stmt->bindValue(':limit', 10, PDO::PARAM_INT);
$stmt->execute();
$latestPosts = $stmt->fetchAll();

$pageTitle = t('home_page_title', '首页');
include APP_ROOT . 'app/includes/header.php';
?>

<?php if (!empty($announcements)): ?>
    <?php foreach ($announcements as $announcement): ?>
        <div class="announcement-bar" data-alert>
            <span class="announcement-icon">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2a7 7 0 0 0-7 7v5l-2 2v1h18v-1l-2-2V9a7 7 0 0 0-7-7zm0 18a2 2 0 0 1-2-2h4a2 2 0 0 1-2 2z"/></svg>
            </span>
            <div class="announcement-body">
                <span class="announcement-title"><?php echo e($announcement['title']); ?></span>
                <span class="announcement-content"><?php echo e($announcement['content']); ?></span>
            </div>
            <button type="button" class="alert-close" aria-label="<?php echo e(t('home_close', '关闭')); ?>" data-close>&times;</button>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- 统计条 -->
<div class="home-stats-bar">
    <div class="home-stats-main">
        <span class="home-stat">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M8 14h.01M8 2v4M16 2v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            <?php echo e(t('home_stat_posts', '帖子：')); ?><strong><?php echo (int)$stats['posts']; ?></strong>
        </span>
        <span class="home-stat-sep">|</span>
        <span class="home-stat"><?php echo e(t('home_stat_users', '会员：')); ?><strong><?php echo (int)$stats['users']; ?></strong></span>
        <span class="home-stat-sep">|</span>
        <span class="home-stat"><?php echo e(t('home_stat_today', '今日：')); ?><strong><?php echo (int)$stats['today_posts']; ?></strong></span>
        <?php if (!empty($stats['newest_user'])): ?>
            <span class="home-stat-sep">|</span>
            <span class="home-stat"><?php echo e(t('home_stat_welcome', '欢迎：')); ?><a href="<?php echo e(site_url('profile', ['user_id' => (int)$stats['newest_user']['id']])); ?>"><strong><?php echo e($stats['newest_user']['username']); ?></strong></a></span>
        <?php endif; ?>
    </div>
    <div class="home-stats-links">
        <?php if (is_logged_in()): ?>
            <a href="<?php echo site_url('profile', ['tab' => 'posts']); ?>"><?php echo e(t('home_my_posts', '我的帖子')); ?></a>
        <?php else: ?>
            <a href="<?php echo site_url('login'); ?>"><?php echo e(t('home_my_posts', '我的帖子')); ?></a>
        <?php endif; ?>
        <span class="home-stats-links-sep">|</span>
        <a href="<?php echo site_url('home', ['sort' => 'latest_reply']); ?>"><?php echo e(t('home_latest_reply', '最新回复')); ?></a>
    </div>
</div>

<!-- 版块分区列表 -->
<?php if (!empty($categories)): ?>
    <?php foreach ($categories as $group): ?>
        <?php
        $category = $group['category'];
        $forums = $group['forums'];
        if (empty($forums)) {
            continue;
        }
        $catId = 'cat-' . (int)$category['id'];
        ?>
        <section class="category-section">
            <button type="button" class="category-header" aria-expanded="true" aria-controls="<?php echo $catId; ?>" data-collapse>
                <span class="category-title-bar"></span>
                <h2 class="category-title"><?php echo e($category['name']); ?></h2>
                <span class="category-toggle" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
                </span>
            </button>
            <div class="forum-grid" id="<?php echo $catId; ?>">
                <?php foreach ($forums as $forum): ?>
                    <article class="forum-card" data-forum-id="<?php echo (int)$forum['id']; ?>">
                        <div class="forum-card-icon">
                            <?php echo forum_icon($forum['icon'], 32, $forum['name']); ?>
                        </div>
                        <div class="forum-card-body">
                            <h3 class="forum-card-title">
                                <a href="<?php echo site_url('forum', ['id' => (int)$forum['id']]); ?>"><?php echo e($forum['name']); ?></a>
                                <span class="forum-card-count"><?php echo e(t('home_forum_count', '{threads} 主题 / {posts} 帖子', ['threads' => (int)$forum['threads_count'], 'posts' => (int)$forum['posts_count']])); ?></span>
                            </h3>
                            <?php if (!empty($forum['description'])): ?>
                                <p class="forum-card-desc"><?php echo e($forum['description']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($forum['last_post_title'])): ?>
                                <div class="forum-card-lastpost">
                                    <a href="<?php echo e(site_url('post', ['id' => (int)$forum['last_post_id']])); ?>" class="lastpost-title" title="<?php echo e(format_preview_text($forum['last_post_title'], 120)); ?>"><?php echo e(format_preview_text($forum['last_post_title'], 40)); ?></a>
                                    <?php if (!empty($forum['last_post_username'])): ?>
                                        <span class="lastpost-author"><?php echo e($forum['last_post_username']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($forum['last_post_time'])): ?>
                                        <span class="lastpost-time"><?php echo time_ago($forum['last_post_time']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php else: ?>
    <div class="empty-state card">
        <div class="empty-state-icon"><?php echo ui_icon('folder-open', 64); ?></div>
        <p><?php echo e(t('home_no_forums', '暂未创建任何版块。')); ?></p>
    </div>
<?php endif; ?>

<!-- 最新帖子 -->
<?php if (!empty($latestPosts)): ?>
    <section class="latest-posts-section">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?php echo e(t('home_latest_posts', '最新帖子')); ?></h2>
                <a href="/" class="btn btn-sm btn-secondary"><?php echo e(t('home_view_all', '查看全部')); ?></a>
            </div>
            <div id="new-latest-banner" style="display:none; align-items:center; justify-content:space-between; gap:0.5rem; background:rgba(37,99,235,0.08); color:var(--primary,#2563eb); padding:8px 12px; border-radius:6px; margin-bottom:10px;" role="status" aria-live="polite">
                <span><?php echo e(t('home_new_posts_tip', '有新的帖子，点击刷新')); ?></span>
                <button type="button" class="btn btn-sm btn-primary" onclick="location.reload()"><?php echo e(t('home_refresh', '刷新')); ?></button>
            </div>
            <div class="latest-list">
                <?php foreach ($latestPosts as $post): ?>
                    <div class="latest-item">
                        <img src="<?php echo avatar_url($post['avatar'], $post['username']); ?>" alt="" class="avatar avatar-sm">
                        <div class="latest-title">
                            <?php if ($post['is_pinned']): ?><span class="thread-badge pinned" title="<?php echo e(t('home_badge_pinned_title', '置顶')); ?>"><?php echo e(t('home_badge_pinned', '顶')); ?></span><?php endif; ?>
                            <?php if ($post['is_essence']): ?><span class="thread-badge essence" title="<?php echo e(t('home_badge_essence_title', '精华')); ?>"><?php echo e(t('home_badge_essence', '精')); ?></span><?php endif; ?>
                            <?php if ($post['is_locked']): ?><span class="thread-badge locked" title="<?php echo e(t('home_badge_locked_title', '锁定')); ?>"><?php echo e(t('home_badge_locked', '锁')); ?></span><?php endif; ?>
                            <a href="<?php echo e(site_url('post', ['id' => (int)$post['id']])); ?>"><?php echo e(format_preview_text($post['title'], 80)); ?></a>
                        </div>
                        <span class="latest-meta"><?php echo e($post['username']); ?> · <?php echo time_ago($post['created_at']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<script>
// 首页实时刷新（1 秒轮询）：统计条 + 版块卡片 + 新帖检测
// setTimeout 链 + 请求去重：响应慢时不堆积请求，不阻塞页面
var HOME_FORUM_COUNT_TPL = <?php echo json_encode(t('home_forum_count', '{threads} 主题 / {posts} 帖子')); ?>;
(function () {
    if (typeof fetch !== 'function') return;
    var statStrongs = document.querySelectorAll('.home-stats-bar .home-stat strong');
    var newUserLink = document.querySelector('.home-stats-bar .home-stat a');
    var banner = document.getElementById('new-latest-banner');

    // 初始最新帖子 id（用于检测新帖）
    var initialIds = [];
    document.querySelectorAll('.latest-list .latest-item a[href*="route=post"]').forEach(function (a) {
        var m = a.getAttribute('href').match(/id=(\d+)/);
        if (m) initialIds.push(parseInt(m[1], 10));
    });

    var checking = false;

    function poll() {
        setTimeout(function () {
            if (!checking) {
                checking = true;
                fetch('<?php echo site_url('api/home_realtime'); ?>&_=' + Date.now(), { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.success) return;
                        // 1. 统计条
                        if (statStrongs.length >= 3) {
                            statStrongs[0].textContent = data.stats.posts;
                            statStrongs[1].textContent = data.stats.users;
                            statStrongs[2].textContent = data.stats.today_posts;
                        }
                        if (newUserLink && data.stats.newest_user) {
                            newUserLink.textContent = data.stats.newest_user.username;
                            newUserLink.setAttribute('href', '<?php echo site_url('profile'); ?>&user_id=' + data.stats.newest_user.id);
                        }
                        // 2. 版块卡片：主题数 / 帖子数 / 最后发表
                        data.forums.forEach(function (f) {
                            var card = document.querySelector('.forum-card[data-forum-id="' + f.id + '"]');
                            if (!card) return;
                            var countEl = card.querySelector('.forum-card-count');
                            if (countEl) countEl.textContent = HOME_FORUM_COUNT_TPL.replace('{threads}', f.threads_count).replace('{posts}', f.posts_count);
                            var titleEl = card.querySelector('.lastpost-title');
                            var authorEl = card.querySelector('.lastpost-author');
                            var timeEl = card.querySelector('.lastpost-time');
                            if (titleEl) {
                                if (f.last_post_id > 0) {
                                    titleEl.textContent = f.last_post_title || '';
                                    titleEl.setAttribute('href', '<?php echo site_url('post'); ?>&id=' + f.last_post_id);
                                    if (authorEl) authorEl.textContent = f.last_post_username || '';
                                    if (timeEl) timeEl.textContent = f.last_post_time_ago || '';
                                } else {
                                    titleEl.textContent = '';
                                    if (authorEl) authorEl.textContent = '';
                                    if (timeEl) timeEl.textContent = '';
                                }
                            }
                        });
                        // 3. 新帖检测：出现初始列表中不存在的帖子 id 时提示刷新
                        if (banner && Array.isArray(data.latest)) {
                            var hasNewer = data.latest.some(function (p) { return initialIds.indexOf(p.id) === -1; });
                            if (hasNewer) banner.style.display = 'flex';
                        }
                    })
                    .catch(function () {})
                    .then(function () { checking = false; });
            }
            poll();
        }, 1000);
    }
    poll();
})();
</script>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
