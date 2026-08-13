<?php
/**
 * 云界论坛 - 我的订阅（版块订阅列表）
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

// 取消订阅（写操作统一走 POST）
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
if ($action === 'unsubscribe') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        set_flash(t('sub_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
        redirect('/subscriptions');
    }
    $forumId = (int)($_POST['forum_id'] ?? 0);
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if ($forumId > 0 && validate_csrf($token)) {
        unsubscribe_forum($forumId);
        set_flash(t('sub_unsubscribed', '已取消订阅。'), 'success');
    } else {
        set_flash(t('sub_csrf_failed', '操作失败，安全验证未通过。'), 'error');
    }
    redirect('/subscriptions');
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT COUNT(*) FROM forum_subscriptions WHERE user_id = :uid");
$stmt->execute([':uid' => $userId]);
$total = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT s.created_at AS subscribed_at, f.* FROM forum_subscriptions s JOIN forums f ON s.forum_id = f.id WHERE s.user_id = :uid ORDER BY s.created_at DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$subs = $stmt->fetchAll();

$csrfToken = csrf_token();

$pageTitle = t('sub_page_title', '我的订阅');
include APP_ROOT . 'app/includes/header.php';
?>

<style>
.sub-list .post-stats form.inline-form { display: inline; margin: 0; }
</style>

<nav class="breadcrumb" aria-label="<?php echo e(t('sub_breadcrumb_aria', '面包屑导航')); ?>">
    <a href="/"><?php echo e(t('sub_home', '首页')); ?></a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?php echo e(t('sub_page_title', '我的订阅')); ?></span>
</nav>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('sub_title_count', '我的订阅（{n}）', ['n' => $total])); ?></h1>
</div>

<?php if (empty($subs)): ?>
    <div class="empty-state card">
        <div class="empty-state-icon"><?php echo ui_icon('bell', 64); ?></div>
        <p><?php echo e(t('sub_empty', '你还没有订阅任何版块。在版块页面点击“订阅”即可在新帖发布时收到通知。')); ?></p>
        <a href="/" class="btn btn-primary"><?php echo e(t('sub_browse', '去逛逛')); ?></a>
    </div>
<?php else: ?>
    <div class="post-list">
        <?php foreach ($subs as $item): ?>
            <article class="post-item">
                <h2 class="post-title">
                    <a href="<?php echo site_url('forum', ['id' => (int)$item['id']]); ?>"><?php echo e($item['name']); ?></a>
                </h2>
                <div class="post-meta">
                    <?php if (!empty($item['description'])): ?>
                        <span class="post-meta-item"><?php echo e(mb_substr($item['description'], 0, 80, 'UTF-8')); ?></span>
                    <?php endif; ?>
                    <span class="post-meta-item"><?php echo e(t('sub_subscribed_at', '订阅于 {time}', ['time' => time_ago($item['subscribed_at'])])); ?></span>
                </div>
                <div class="post-stats">
                    <a class="btn btn-sm btn-secondary" href="<?php echo site_url('forum', ['id' => (int)$item['id']]); ?>"><?php echo e(t('sub_go_forum', '进入版块')); ?></a>
                    <form method="post" action="<?php echo e(site_url('subscriptions')); ?>" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="unsubscribe">
                        <input type="hidden" name="forum_id" value="<?php echo (int)$item['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger" data-confirm="<?php echo e(t('sub_unsubscribe_confirm', '确定取消订阅该版块吗？')); ?>">
                            <?php echo e(t('sub_unsubscribe', '取消订阅')); ?>
                        </button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php echo pagination($page, $total, $perPage, site_url('subscriptions')); ?>
<?php endif; ?>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
