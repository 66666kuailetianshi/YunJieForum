<?php
/**
 * 云界论坛 - 我的收藏
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

require_login();
update_last_active();

$db = get_db();
$userId = (int)$_SESSION['user_id'];

// 处理取消收藏
$action = isset($_GET['action']) ? $_GET['action'] : '';
if ($action === 'remove') {
    $removeId = (int)($_GET['id'] ?? 0);
    $token = isset($_GET['csrf_token']) ? $_GET['csrf_token'] : '';
    if ($removeId > 0 && validate_csrf($token)) {
        $stmt = $db->prepare("DELETE FROM favorites WHERE user_id = :uid AND post_id = :pid");
        $stmt->execute([':uid' => $userId, ':pid' => $removeId]);
        set_flash(t('fav_removed', '已取消收藏。'), 'success');
    } else {
        set_flash(t('fav_csrf_failed', '操作失败，安全验证未通过。'), 'error');
    }
    redirect('/favorites');
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// 收藏总数
$stmt = $db->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = :uid");
$stmt->execute([':uid' => $userId]);
$total = (int)$stmt->fetchColumn();

// 收藏列表
$stmt = $db->prepare("SELECT fav.created_at AS favorited_at, p.*, u.username, u.avatar, f.name AS forum_name, f.icon AS forum_icon
    FROM favorites fav
    JOIN posts p ON fav.post_id = p.id
    JOIN users u ON p.user_id = u.id
    LEFT JOIN forums f ON p.forum_id = f.id
    WHERE fav.user_id = :uid
    ORDER BY fav.created_at DESC
    LIMIT :limit OFFSET :offset");
$stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$favorites = $stmt->fetchAll();

$csrfToken = csrf_token();

$pageTitle = t('fav_page_title', '我的收藏');
include APP_ROOT . 'app/includes/header.php';
?>

<nav class="breadcrumb" aria-label="<?php echo e(t('fav_breadcrumb_aria', '面包屑导航\"')); ?>>
    <a href="/"><?php echo e(t('fav_home', '首页')); ?></a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?php echo e(t('fav_page_title', '我的收藏')); ?></span>
</nav>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('fav_title_count', '我的收藏（{n}）', ['n' => $total])); ?></h1>
</div>

<?php if (empty($favorites)): ?>
    <div class="empty-state card">
        <div class="empty-state-icon"><?php echo ui_icon('star', 64); ?></div>
        <p><?php echo e(t('fav_empty', '你还没有收藏任何帖子。')); ?></p>
        <a href="/" class="btn btn-primary"><?php echo e(t('fav_browse', '去逛逛')); ?></a>
    </div>
<?php else: ?>
    <div class="post-list">
        <?php foreach ($favorites as $item): ?>
            <article class="post-item">
                <h2 class="post-title">
                    <a href="<?php echo site_url('post', ['id' => (int)$item['id']]); ?>"><?php echo e(strip_bbcode($item['title'])); ?></a>
                </h2>
                <div class="post-meta">
                    <?php if (!empty($item['forum_name'])): ?>
                        <span class="post-meta-item">
                            <span class="post-meta-icon"><?php echo forum_icon($item['forum_icon'] ?? null, 16, $item['forum_name']); ?></span>
                            <span><?php echo e($item['forum_name']); ?></span>
                        </span>
                    <?php endif; ?>
                    <span class="post-meta-item">
                        <img src="<?php echo avatar_url($item['avatar'], $item['username']); ?>" alt="" class="avatar avatar-sm">
                        <span><?php echo e($item['username']); ?></span>
                    </span>
                    <span class="post-meta-item"><?php echo e(t('fav_favorited_at', '收藏于 {time}', ['time' => time_ago($item['favorited_at'])])); ?></span>
                </div>
                <div class="post-stats">
                    <span class="post-stat"><?php echo ui_icon('eye', 14); ?> <?php echo (int)$item['views']; ?></span>
                    <span class="post-stat"><?php echo ui_icon('message', 14); ?> <?php echo (int)$item['replies_count']; ?></span>
                    <a class="btn btn-sm btn-danger"
                       href="<?php echo site_url('favorites', ['action' => 'remove', 'id' => (int)$item['id'], 'csrf_token' => $csrfToken]); ?>"
                       data-confirm="<?php echo e(t('fav_remove_confirm', '确定取消收藏这篇帖子吗？\"')); ?>>
                        <?php echo e(t('fav_remove', '取消收藏')); ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php echo pagination($page, $total, $perPage, site_url('favorites')); ?>
<?php endif; ?>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
