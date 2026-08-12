<?php
/**
 * 云界论坛 - 搜索页
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

update_last_active();

$db = get_db();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = POSTS_PER_PAGE;
$offset = ($page - 1) * $perPage;

$hasQuery = $q !== '';
$results = [];
$total = 0;

if ($hasQuery) {
    $keyword = '%' . $q . '%';

    $stmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE title LIKE :q1 OR content LIKE :q2");
    $stmt->execute([':q1' => $keyword, ':q2' => $keyword]);
    $total = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT p.*, u.username, u.avatar, f.name AS forum_name, f.icon AS forum_icon
        FROM posts p
        JOIN users u ON p.user_id = u.id OR p.user_id = u.uid
        LEFT JOIN forums f ON p.forum_id = f.id
        WHERE p.title LIKE :q1 OR p.content LIKE :q2
        ORDER BY p.created_at DESC
        LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':q1', $keyword, PDO::PARAM_STR);
    $stmt->bindValue(':q2', $keyword, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll();
}

// 热门搜索词：随机取 10 个帖子标题中的词
$hotTerms = [];
if (!$hasQuery) {
    $stmt = $db->query("SELECT title FROM posts ORDER BY id DESC LIMIT 10");
    $titles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($titles as $title) {
        $words = preg_split('/[\s,，。.!！?？、:：;；()（）]+/u', $title);
        foreach ($words as $word) {
            $word = trim($word);
            $len = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
            if ($word !== '' && $len >= 2 && !in_array($word, $hotTerms, true)) {
                $hotTerms[] = $word;
                if (count($hotTerms) >= 10) {
                    break 2;
                }
            }
        }
    }
}

/**
 * 关键词高亮（先转义再标记，避免 XSS）
 */
function search_highlight($text, $keyword) {
    $escaped = e($text);
    if ($keyword === '') {
        return $escaped;
    }
    $escapedKeyword = e($keyword);
    if ($escapedKeyword === '') {
        return $escaped;
    }
    $pattern = '/' . preg_quote($escapedKeyword, '/') . '/iu';
    return preg_replace($pattern, '<mark>$0</mark>', $escaped);
}

$pageTitle = $hasQuery ? t('search_page_title_query', '搜索：{q}', ['q' => $q]) : t('search_page_title', '搜索');
include APP_ROOT . 'app/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('search_heading', '搜索')); ?></h1>
</div>

<div class="card mb-1">
    <form method="GET" action="<?php echo site_url('search'); ?>" class="flex gap-1">
        <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e(t('search_input_placeholder', '输入关键词搜索帖子...')); ?>" autocomplete="off">
        <button type="submit" class="btn btn-primary"><?php echo e(t('search_submit', '搜索')); ?></button>
    </form>
</div>

<?php if ($hasQuery): ?>
    <?php if (empty($results)): ?>
        <div class="empty-state card">
            <div class="empty-state-icon"><?php echo ui_icon('search', 64); ?></div>
            <p><?php echo e(t('search_no_result', '没有找到包含「{q}」的帖子。', ['q' => $q])); ?></p>
            <a href="<?php echo site_url('search'); ?>" class="btn btn-secondary"><?php echo e(t('search_retry', '重新搜索')); ?></a>
        </div>
    <?php else: ?>
        <p class="text-muted mb-1"><?php echo e(t('search_result_count', '共找到 {total} 条与「{q}」相关的结果', ['total' => $total, 'q' => $q])); ?></p>
        <div class="post-list">
            <?php foreach ($results as $post): ?>
                <article class="post-item">
                    <h2 class="post-title">
                        <a href="<?php echo site_url('post', ['id' => (int)$post['id']]); ?>"><?php echo search_highlight(strip_bbcode($post['title']), $q); ?></a>
                    </h2>
                    <div class="post-meta">
                        <?php if (!empty($post['forum_name'])): ?>
                            <span class="post-meta-item">
                                <span class="post-meta-icon"><?php echo forum_icon($post['forum_icon'] ?? null, 16, $post['forum_name']); ?></span>
                                <span><?php echo e($post['forum_name']); ?></span>
                            </span>
                        <?php endif; ?>
                        <span class="post-meta-item">
                            <img src="<?php echo avatar_url($post['avatar'], $post['username']); ?>" alt="" class="avatar avatar-sm">
                            <span><?php echo e($post['username']); ?></span>
                        </span>
                        <span class="post-meta-item"><?php echo time_ago($post['created_at']); ?></span>
                    </div>
                    <div class="post-stats">
                        <span class="post-stat"><?php echo ui_icon('eye', 14); ?> <?php echo (int)$post['views']; ?></span>
                        <span class="post-stat"><?php echo ui_icon('message', 14); ?> <?php echo (int)$post['replies_count']; ?></span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        $baseUrl = site_url('search', ['q' => $q]);
        echo pagination($page, $total, $perPage, $baseUrl);
        ?>
    <?php endif; ?>
<?php else: ?>
    <div class="card">
        <h2 class="card-title mb-1"><?php echo e(t('search_hot', '热门搜索')); ?></h2>
        <?php if (empty($hotTerms)): ?>
            <div class="empty-state" style="padding: 2rem 1rem;">
                <div class="empty-state-icon"><?php echo ui_icon('file-text', 48); ?></div>
                <p><?php echo e(t('search_no_hot', '暂无帖子，无法生成热门搜索词。')); ?></p>
            </div>
        <?php else: ?>
            <div class="flex flex-wrap gap-1">
                <?php foreach ($hotTerms as $term): ?>
                    <a href="<?php echo e(site_url('search', ['q' => $term])); ?>" class="badge badge-primary"><?php echo e($term); ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
