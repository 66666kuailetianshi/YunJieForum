<?php
/**
 * 云界论坛 - 管理后台帖子管理（增强版）
 * 支持搜索、筛选、排序、批量操作、状态管理、风险评估
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：帖子管理（超管天然通过；社区管理员需 manage_posts 权限）
require_permission('manage_posts');

$db = get_db();
$driver = get_db_driver();
$action = $_GET['action'] ?? 'list';
$postId = (int)($_GET['post_id'] ?? 0);

// 单个写操作动作：仅接受 POST（CSRF 由 admin-init.php 对所有 POST 统一校验）
$postActionFlags = ['pin', 'unpin', 'essence', 'unessence', 'lock', 'unlock', 'delete'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', $postActionFlags, true)) {
    $flagResult = admin_apply_post_flag((int)($_POST['post_id'] ?? 0), $_POST['action']);
    set_flash($flagResult['message'], $flagResult['type']);
    redirect('/admin/posts');
}
// 旧 GET 写操作链接命中：不执行写操作，提示刷新
if (in_array($action, $postActionFlags, true)) {
    set_flash(t('post_flash_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
    redirect('/admin/posts');
}

// ===================== 批量操作 =====================
if ($action === 'batch' && validate_csrf()) {
    $ids = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function($v) { return $v > 0; });
    $batchAction = $_POST['batch_action'] ?? '';

    if (!empty($ids) && !empty($batchAction)) {
        $placeholders = implode(',', $ids);
        $labels = ['pin' => t('admin_posts_batch_label_pin', '置顶'), 'unpin' => t('admin_posts_batch_label_unpin', '取消置顶'), 'essence' => t('admin_posts_batch_label_essence', '加精'), 'unessence' => t('admin_posts_batch_label_unessence', '取消加精'), 'lock' => t('admin_posts_batch_label_lock', '锁定'), 'unlock' => t('admin_posts_batch_label_unlock', '解锁')];
        switch ($batchAction) {
            case 'pin': case 'unpin': case 'essence': case 'unessence': case 'lock': case 'unlock':
                $flag = in_array($batchAction, ['pin', 'essence', 'lock']) ? 1 : 0;
                $col = ['pin' => 'is_pinned', 'unpin' => 'is_pinned', 'essence' => 'is_essence', 'unessence' => 'is_essence', 'lock' => 'is_locked', 'unlock' => 'is_locked'][$batchAction];
                $batchPlaceholders = [];
                $batchParams = [];
                foreach ($ids as $i => $id) {
                    $key = ':id' . $i;
                    $batchPlaceholders[] = $key;
                    $batchParams[$key] = $id;
                }
                $batchStmt = $db->prepare("UPDATE posts SET $col = $flag WHERE id IN (" . implode(',', $batchPlaceholders) . ")");
                $batchStmt->execute($batchParams);
                set_flash(t('admin_posts_flash_batch_done', '已{action} {n} 个帖子。', ['action' => $labels[$batchAction], 'n' => count($ids)]), 'success');
                break;
            case 'delete':
                foreach ($ids as $id) delete_post($id);
                set_flash(t('admin_posts_flash_batch_deleted', '已删除 {n} 个帖子。', ['n' => count($ids)]), 'success');
                break;
        }
    }
    redirect('/admin/posts' . ($_SERVER['QUERY_STRING'] ? '?' . preg_replace('/&?action=batch[^&]*/', '', $_SERVER['QUERY_STRING']) : ''));
}

// ===================== 搜索与筛选参数 =====================
$search = trim($_GET['search'] ?? '');
$forumFilter = (int)($_GET['forum_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$sort = in_array($_GET['sort'] ?? '', ['created_at', 'views', 'replies_count', 'title']) ? $_GET['sort'] : 'created_at';
$order = ($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// ===================== 构建查询条件 =====================
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(p.title LIKE :search OR u.username LIKE :search2 OR p.id = :search_id)';
    $params[':search'] = "%$search%";
    $params[':search2'] = "%$search%";
    $params[':search_id'] = is_numeric($search) ? (int)$search : 0;
}
if ($forumFilter > 0) {
    $where[] = 'p.forum_id = :forum_id';
    $params[':forum_id'] = $forumFilter;
}
switch ($statusFilter) {
    case 'pinned':   $where[] = 'p.is_pinned = 1'; break;
    case 'essence':  $where[] = 'p.is_essence = 1'; break;
    case 'locked':   $where[] = 'p.is_locked = 1'; break;
    case 'normal':   $where[] = 'p.is_pinned = 0 AND p.is_essence = 0 AND p.is_locked = 0'; break;
    // risk_high / risk_medium → PHP 后过滤
}

$whereSql = implode(' AND ', $where);

// ===================== 统计卡片 =====================
// 5 项统计合并为单条 SUM(CASE WHEN)（标准 SQL，SQLite/MySQL/PostgreSQL 通用）
$today = date('Y-m-d');
$statsRow = $db->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN " . $driver->dateColExpr('created_at') . " = " . $db->quote($today) . " THEN 1 ELSE 0 END) AS today,
        SUM(CASE WHEN is_pinned = 1 THEN 1 ELSE 0 END) AS pinned,
        SUM(CASE WHEN is_essence = 1 THEN 1 ELSE 0 END) AS essence,
        SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) AS locked
    FROM posts")->fetch();
$stats = [
    'total'   => (int)($statsRow['total'] ?? 0),
    'today'   => (int)($statsRow['today'] ?? 0),
    'pinned'  => (int)($statsRow['pinned'] ?? 0),
    'essence' => (int)($statsRow['essence'] ?? 0),
    'locked'  => (int)($statsRow['locked'] ?? 0),
];

// ===================== 查询帖子列表 =====================
// 单一分支：有筛选参数时走 prepare，否则直接 query，COUNT 只执行一次
if (!empty($params)) {
    $countStmt = $db->prepare("SELECT COUNT(DISTINCT p.id) FROM posts p JOIN users u ON p.user_id = u.id OR p.user_id = u.uid WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
} else {
    $total = (int)$db->query("SELECT COUNT(DISTINCT p.id) FROM posts p JOIN users u ON p.user_id = u.id OR p.user_id = u.uid WHERE $whereSql")->fetchColumn();
}

$sql = "SELECT p.*, u.username, f.name AS forum_name
    FROM posts p
    JOIN users u ON p.user_id = u.id OR p.user_id = u.uid
    LEFT JOIN forums f ON p.forum_id = f.id
    WHERE $whereSql
    ORDER BY p.is_pinned DESC, p.{$sort} {$order}
    LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$allPosts = $stmt->fetchAll();

// 风险等级后过滤
if (in_array($statusFilter, ['risk_high', 'risk_medium'])) {
    $allPosts = array_filter($allPosts, function($post) use ($statusFilter) {
        $risk = assess_post_risk($post['content'], $post['title']);
        return $statusFilter === 'risk_high' ? $risk['level'] === 'high' : $risk['level'] === 'medium';
    });
    $total = count($allPosts);
}

// 版块列表
$forums = $db->query("SELECT id, name FROM forums ORDER BY display_order ASC, id ASC")->fetchAll();

// 查询字符串辅助
function posts_qs(array $overrides = []): string {
    $p = $_GET; unset($p['page']);
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') unset($p[$k]); else $p[$k] = $v;
    }
    $qs = http_build_query($p);
    return $qs ? '?' . $qs : '';
}

$pageTitle = t('admin_posts_title', '帖子管理');
$activeMenu = 'posts';
require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_posts_title', '帖子管理')); ?></h1>
    <div class="page-actions">
        <span class="text-xs text-muted"><?php echo e(t('admin_posts_total_count', '共 {n} 个帖子', ['n' => $total])); ?></span>
    </div>
</div>

<!-- 统计卡片 -->
<div class="stats-row">
    <div class="stat-card <?php echo $statusFilter === '' && !$forumFilter && !$search ? 'active' : ''; ?>" onclick="location.href='<?php echo site_url('admin/posts'); ?>'">
        <div class="stat-icon stat-icon--blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
        <div class="stat-info"><div class="stat-num"><?php echo number_format($stats['total']); ?></div><div class="stat-label"><?php echo e(t('admin_posts_stat_all', '全部帖子')); ?></div></div>
    </div>
    <div class="stat-card <?php echo $statusFilter === 'pinned' ? 'active' : ''; ?>" onclick="location.href='<?php echo site_url('admin/posts', ['status' => 'pinned']); ?>'">
        <div class="stat-icon stat-icon--amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2l2 8h6l-4 5 2 7-6-3-6 3 2-7-4-5h6l2-8z"/></svg></div>
        <div class="stat-info"><div class="stat-num"><?php echo number_format($stats['pinned']); ?></div><div class="stat-label"><?php echo e(t('admin_posts_stat_pinned', '置顶')); ?></div></div>
    </div>
    <div class="stat-card <?php echo $statusFilter === 'essence' ? 'active' : ''; ?>" onclick="location.href='<?php echo site_url('admin/posts', ['status' => 'essence']); ?>'">
        <div class="stat-icon stat-icon--pink"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 3h12l4 8-10 11L2 11z"/></svg></div>
        <div class="stat-info"><div class="stat-num"><?php echo number_format($stats['essence']); ?></div><div class="stat-label"><?php echo e(t('admin_posts_stat_essence', '精华')); ?></div></div>
    </div>
    <div class="stat-card <?php echo $statusFilter === 'locked' ? 'active' : ''; ?>" onclick="location.href='<?php echo site_url('admin/posts', ['status' => 'locked']); ?>'">
        <div class="stat-icon stat-icon--red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg></div>
        <div class="stat-info"><div class="stat-num"><?php echo number_format($stats['locked']); ?></div><div class="stat-label"><?php echo e(t('admin_posts_stat_locked', '已锁定')); ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon--green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div>
        <div class="stat-info"><div class="stat-num"><?php echo number_format($stats['today']); ?></div><div class="stat-label"><?php echo e(t('admin_posts_stat_today', '今日新增')); ?></div></div>
    </div>
</div>

<!-- 筛选栏 -->
<form method="get" action="<?php echo site_url('admin/posts'); ?>" class="filter-bar">
    <input type="search" name="search" value="<?php echo e($search); ?>" placeholder="<?php echo e(t('admin_posts_search_placeholder', '搜索标题、作者或 ID...')); ?>">
    <select name="forum_id">
        <option value="0"><?php echo e(t('admin_posts_all_forums', '全部版块')); ?></option>
        <?php foreach ($forums as $f): ?>
            <option value="<?php echo $f['id']; ?>" <?php echo $forumFilter === (int)$f['id'] ? 'selected' : ''; ?>><?php echo e($f['name']); ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status">
        <option value=""><?php echo e(t('admin_posts_all_status', '全部状态')); ?></option>
        <option value="pinned" <?php echo $statusFilter === 'pinned' ? 'selected' : ''; ?>><?php echo e(t('admin_posts_status_pinned', '置顶')); ?></option>
        <option value="essence" <?php echo $statusFilter === 'essence' ? 'selected' : ''; ?>><?php echo e(t('admin_posts_status_essence', '精华')); ?></option>
        <option value="locked" <?php echo $statusFilter === 'locked' ? 'selected' : ''; ?>><?php echo e(t('admin_posts_status_locked', '已锁定')); ?></option>
        <option value="normal" <?php echo $statusFilter === 'normal' ? 'selected' : ''; ?>><?php echo e(t('admin_posts_status_normal', '普通')); ?></option>
        <option value="risk_high" <?php echo $statusFilter === 'risk_high' ? 'selected' : ''; ?>><?php echo e(t('admin_posts_status_risk_high', '高风险')); ?></option>
        <option value="risk_medium" <?php echo $statusFilter === 'risk_medium' ? 'selected' : ''; ?>><?php echo e(t('admin_posts_status_risk_medium', '中风险')); ?></option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><?php echo e(t('admin_posts_filter', '筛选')); ?></button>
    <?php if ($search || $forumFilter || $statusFilter): ?>
        <a href="<?php echo site_url('admin/posts'); ?>" class="btn btn-secondary btn-sm"><?php echo e(t('admin_posts_clear', '清除')); ?></a>
    <?php endif; ?>
</form>

<!-- 批量操作栏 -->
<div class="batch-bar" id="batch-bar">
    <span class="batch-count" id="batch-count"><?php echo e(t('admin_posts_batch_selected', '已选 {n} 项', ['n' => 0])); ?></span>
    <button class="btn btn-sm btn-secondary" onclick="batchAction('pin')"><?php echo e(t('admin_posts_batch_pin', '置顶')); ?></button>
    <button class="btn btn-sm btn-secondary" onclick="batchAction('unpin')"><?php echo e(t('admin_posts_batch_unpin', '取消置顶')); ?></button>
    <button class="btn btn-sm btn-secondary" onclick="batchAction('essence')"><?php echo e(t('admin_posts_batch_essence', '加精')); ?></button>
    <button class="btn btn-sm btn-secondary" onclick="batchAction('unessence')"><?php echo e(t('admin_posts_batch_unessence', '取消加精')); ?></button>
    <button class="btn btn-sm btn-secondary" onclick="batchAction('lock')"><?php echo e(t('admin_posts_batch_lock', '锁定')); ?></button>
    <button class="btn btn-sm btn-secondary" onclick="batchAction('unlock')"><?php echo e(t('admin_posts_batch_unlock', '解锁')); ?></button>
    <button class="btn btn-sm btn-danger" onclick="batchAction('delete')"><?php echo e(t('admin_posts_batch_delete', '删除')); ?></button>
</div>

<div class="card card-clip">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="select-all" onchange="toggleSelectAll(this)"></th>
                    <th style="width:48px;">ID</th>
                    <th><?php echo e(t('admin_posts_th_title_status', '标题 / 状态')); ?></th>
                    <th style="width:90px;"><?php echo e(t('admin_posts_th_author', '作者')); ?></th>
                    <th style="width:85px;"><?php echo e(t('admin_posts_th_forum', '版块')); ?></th>
                    <th style="width:55px;"><?php echo e(t('admin_posts_th_risk', '风险')); ?></th>
                    <th style="width:70px;"><?php echo e(t('admin_posts_th_interact', '互动')); ?></th>
                    <th style="width:130px;">
                        <a href="<?php echo e(site_url('admin/posts', array_merge(array_diff_key($_GET, ['page' => 1, 'route' => 1]), ['sort' => 'created_at', 'order' => ($sort === 'created_at' && $order === 'asc') ? 'desc' : 'asc']))); ?>" class="sort-link<?php echo $sort === 'created_at' ? ' active ' . $order : ''; ?>"><?php echo e(t('admin_posts_th_time', '时间')); ?></a>
                    </th>
                    <th class="col-ip"><?php echo e(t('admin_posts_th_ip', 'IP 定位')); ?></th>
                    <th style="width:155px;"><?php echo e(t('admin_posts_th_actions', '操作')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allPosts)): ?>
                    <tr><td colspan="10" class="empty-cell"><?php echo e(t('admin_posts_empty', '暂无帖子数据')); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($allPosts as $post): ?>
                        <?php
                        $risk = assess_post_risk($post['content'], $post['title']);
                        $riskClass = ['high' => 'risk-high', 'medium' => 'risk-medium', 'low' => 'risk-low', 'safe' => 'risk-safe'][$risk['level']] ?? 'risk-safe';
                        $title = e(strip_bbcode($post['title']));
                        $titleDisplay = mb_strlen($title, 'UTF-8') > 36 ? mb_substr($title, 0, 36, 'UTF-8') . '…' : $title;
                        ?>
                        <tr>
                            <td><input type="checkbox" class="post-check" value="<?php echo $post['id']; ?>" onchange="updateBatchBar()"></td>
                            <td><code class="text-xs">#<?php echo $post['id']; ?></code></td>
                            <td>
                                <a href="<?php echo site_url('post', ['id' => (int)$post['id']]); ?>" target="_blank" class="font-medium"><?php echo $titleDisplay; ?></a>
                                <?php if ($post['is_pinned'] || $post['is_essence'] || $post['is_locked']): ?>
                                <div class="post-tags">
                                    <?php if ($post['is_pinned']): ?><span class="post-tag tag-pin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2l2 8h6l-4 5 2 7-6-3-6 3 2-7-4-5h6l2-8z"/></svg><?php echo e(t('admin_posts_tag_pinned', '置顶')); ?></span><?php endif; ?>
                                    <?php if ($post['is_essence']): ?><span class="post-tag tag-best"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 3h12l4 8-10 11L2 11z"/></svg><?php echo e(t('admin_posts_tag_essence', '精华')); ?></span><?php endif; ?>
                                    <?php if ($post['is_locked']): ?><span class="post-tag tag-lock"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg><?php echo e(t('admin_posts_tag_locked', '锁定')); ?></span><?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?php echo site_url('profile', ['user_id' => (int)$post['user_id']]); ?>" class="text-sm"><?php echo e($post['username']); ?></a></td>
                            <td class="text-xs text-muted"><?php echo e($post['forum_name'] ?? '-'); ?></td>
                            <td><span class="risk-tag <?php echo $riskClass; ?>" title="<?php echo e(t('admin_posts_risk_score_title', '风险分数: {score}/100', ['score' => $risk['score']])); ?>"><?php echo $risk['label']; ?></span></td>
                            <td>
                                <div class="interact">
                                    <span title="<?php echo e(t('admin_posts_views', '浏览')); ?>"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:2px;color:var(--text-muted);"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><span class="val"><?php echo number_format($post['views']); ?></span></span>
                                    <span title="<?php echo e(t('admin_posts_replies', '回复')); ?>"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:2px;color:var(--text-muted);"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg><span class="val"><?php echo number_format($post['replies_count']); ?></span></span>
                                </div>
                            </td>
                            <td style="font-size:0.8rem;color:var(--text-muted);white-space:nowrap;"><?php echo date('Y-m-d H:i', strtotime($post['created_at'])); ?></td>
                            <?php
                            // 发帖 IP 定位：实时查归属地（未安装 IP 库时为空）
                            $postIp = (string)($post['ip'] ?? '');
                            $postIpRegion = $postIp !== '' ? ip_region_display(ip_region_query($postIp)) : '';
                            ?>
                            <td class="col-ip">
                                <div class="ip-cell">
                                    <div class="ip-main" title="<?php echo e(t('admin_posts_ip_title', '发帖 IP')); ?>"><?php echo $postIp !== '' ? e($postIp) : '—'; ?>
                                        <?php if ($postIpRegion !== ''): ?><span class="text-muted ip-region"><?php echo e($postIpRegion); ?></span><?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="actions">
                                    <a href="<?php echo site_url('post', ['id' => (int)$post['id']]); ?>" target="_blank" class="btn-icon" title="<?php echo e(t('admin_posts_action_view', '查看帖子')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></a>
                                    <button class="btn-icon" onclick="viewContent(<?php echo $post['id']; ?>)" title="<?php echo e(t('admin_posts_action_preview', '预览内容')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></button>
                                    <?php echo admin_action_form(site_url('admin/posts'), $post['is_pinned'] ? 'unpin' : 'pin', ['post_id' => (int)$post['id']], '', ['class' => 'btn-icon' . ($post['is_pinned'] ? ' active' : ''), 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2l2 8h6l-4 5 2 7-6-3-6 3 2-7-4-5h6l2-8z"/></svg>', 'title' => $post['is_pinned'] ? t('admin_posts_action_unpin', '取消置顶') : t('admin_posts_action_pin', '置顶')]); ?>
                                    <?php echo admin_action_form(site_url('admin/posts'), $post['is_essence'] ? 'unessence' : 'essence', ['post_id' => (int)$post['id']], '', ['class' => 'btn-icon' . ($post['is_essence'] ? ' active' : ''), 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 3h12l4 8-10 11L2 11z"/></svg>', 'title' => $post['is_essence'] ? t('admin_posts_action_unessence', '取消加精') : t('admin_posts_action_essence', '加精')]); ?>
                                    <?php echo admin_action_form(site_url('admin/posts'), $post['is_locked'] ? 'unlock' : 'lock', ['post_id' => (int)$post['id']], '', ['class' => 'btn-icon' . ($post['is_locked'] ? ' active' : ''), 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>', 'title' => $post['is_locked'] ? t('admin_posts_action_unlock', '解锁') : t('admin_posts_action_lock', '锁定')]); ?>
                                    <?php echo admin_action_form(site_url('admin/posts'), 'delete', ['post_id' => (int)$post['id']], '', ['class' => 'btn-icon danger', 'confirm' => t('admin_posts_confirm_delete', '确定删除该帖子吗？所有回复也将一并删除。'), 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg>', 'title' => t('admin_posts_action_delete', '删除')]); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo pagination($page, $total, $perPage, site_url('admin/posts', array_diff_key($_GET, ['page' => 1, 'route' => 1]))); ?>
</div>

<!-- 内容预览模态框 -->
<div class="modal-overlay" id="content-modal">
    <div class="modal-content" id="content-modal-box" style="max-width:860px;">
        <div class="modal-header">
            <h3 id="modal-title"><?php echo e(t('admin_posts_modal_title', '帖子内容')); ?></h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modal-body">
            <p style="text-align:center;color:var(--text-muted);padding:2rem;"><?php echo e(t('admin_posts_loading', '加载中...')); ?></p>
        </div>
    </div>
</div>

<!-- 批量操作表单 -->
<form id="batch-form" method="post" action="<?php echo site_url('admin/posts'); ?>" style="display:none;">
    <input type="hidden" name="action" value="batch">
    <input type="hidden" name="ids" id="batch-ids" value="">
    <input type="hidden" name="batch_action" id="batch-action" value="">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
</form>

<script>
(function() {
    window.toggleSelectAll = function(cb) {
        var checks = document.querySelectorAll('.post-check');
        for (var i = 0; i < checks.length; i++) checks[i].checked = cb.checked;
        updateBatchBar();
    };

    window.updateBatchBar = function() {
        var checks = document.querySelectorAll('.post-check:checked');
        var bar = document.getElementById('batch-bar');
        var count = checks.length;
        if (count > 0) {
            bar.classList.add('show');
            document.getElementById('batch-count').textContent = <?php echo json_encode(t('admin_posts_batch_selected', '已选 {n} 项')); ?>.replace('{n}', count);
        } else {
            bar.classList.remove('show');
            document.getElementById('select-all').checked = false;
        }
    };

    window.batchAction = function(action) {
        var checks = document.querySelectorAll('.post-check:checked');
        if (checks.length === 0) { alert(<?php echo json_encode(t('admin_posts_js_select_first', '请先选择帖子。')); ?>); return; }
        if (action === 'delete' && !confirm(<?php echo json_encode(t('admin_posts_js_confirm_batch_delete', '确定批量删除已选的 {n} 个帖子吗？此操作不可撤销。')); ?>.replace('{n}', checks.length))) return;
        var ids = [];
        for (var i = 0; i < checks.length; i++) ids.push(checks[i].value);
        document.getElementById('batch-ids').value = ids.join(',');
        document.getElementById('batch-action').value = action;
        document.getElementById('batch-form').submit();
    };

    // ===== 内容预览 =====
    window.viewContent = function(postId) {
        var modal = document.getElementById('content-modal');
        var body = document.getElementById('modal-body');
        modal.classList.add('show');
        document.getElementById('modal-title').textContent = <?php echo json_encode(t('admin_posts_loading', '加载中...')); ?>;
        body.innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:2rem;">' + <?php echo json_encode(t('admin_posts_loading', '加载中...')); ?> + '</p>';

        var previewFd = new FormData();
        previewFd.append('action', 'get_content');
        previewFd.append('post_id', postId);
        previewFd.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);
        fetch('<?php echo site_url('admin/api/posts_ajax'); ?>', { method: 'POST', body: previewFd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    renderPreview(data);
                } else {
                    body.innerHTML = '<p style="color:#ef4444;padding:1rem;">' + <?php echo json_encode(t('admin_posts_js_load_failed', '加载失败：')); ?> + (data.error || <?php echo json_encode(t('admin_posts_js_unknown_error', '未知错误')); ?>) + '</p>';
                }
            })
            .catch(function() {
                body.innerHTML = '<p style="color:#ef4444;padding:1rem;">' + <?php echo json_encode(t('admin_posts_js_network_error', '网络错误，加载失败。')); ?> + '</p>';
            });
    };

    function renderPreview(d) {
        var risk = d.risk || {};
        var rc = {high: '#dc2626', medium: '#f59e0b', low: '#3b82f6', safe: '#22c55e'};
        var rColor = rc[risk.level] || '#999';

        var riskBar = '<div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.75rem;padding:0.5rem 0.75rem;border-radius:var(--radius-sm);background:' + rColor + '15;border-left:3px solid ' + rColor + ';">';
        riskBar += '<span style="font-weight:700;color:' + rColor + ';font-size:0.85rem;">' + <?php echo json_encode(t('admin_posts_preview_risk', '风险评估：')); ?> + (risk.label || '—') + '</span>';
        riskBar += '<span style="font-size:0.75rem;color:var(--text-muted);">' + <?php echo json_encode(t('admin_posts_preview_score', '分数 {score}/100')); ?>.replace('{score}', (risk.score || 0)) + '</span>';
        if (risk.details && risk.details.length) riskBar += '<span style="font-size:0.72rem;color:' + rColor + ';margin-left:auto;">' + risk.details.join(' · ') + '</span>';
        riskBar += '</div>';

        var meta = '<div class="preview-meta"><strong>' + esc(d.username) + '</strong>';
        if (d.forum_name) meta += ' · ' + esc(d.forum_name);
        meta += ' · ' + (d.created_at || '') + ' · ' + <?php echo json_encode(t('admin_posts_preview_views', '{n} 浏览')); ?>.replace('{n}', (d.views || 0)) + ' · ' + <?php echo json_encode(t('admin_posts_preview_replies', '{n} 回复')); ?>.replace('{n}', (d.replies_count || 0)) + '</div>';

        var content = '<div class="preview-content">' + (d.content || '<p style="color:var(--text-muted);">' + <?php echo json_encode(t('admin_posts_preview_no_content', '暂无内容')); ?> + '</p>') + '</div>';

        var replies = '';
        if (d.replies && d.replies.length) {
            replies = '<div class="preview-replies"><h4 style="margin:0 0 0.75rem;font-size:0.9rem;border-bottom:1px solid var(--border-soft);padding-bottom:0.5rem;">' + <?php echo json_encode(t('admin_posts_preview_all_replies', '全部回复（{n} 条）')); ?>.replace('{n}', d.replies.length) + '</h4>';
            for (var i = 0; i < d.replies.length; i++) {
                var r = d.replies[i];
                replies += '<div class="preview-reply-item"><div class="preview-reply-header">';
                replies += '<span style="font-weight:600;color:var(--primary);">#' + r.floor + '</span> <strong>' + esc(r.username) + '</strong>';
                if (r.reply_to > 0) replies += ' <span style="color:var(--text-muted);font-size:0.8rem;">↳ #' + r.reply_to + '</span>';
                replies += '<span style="color:var(--text-muted);font-size:0.75rem;margin-left:auto;">' + r.created_at + '</span>';
                replies += '</div><div class="preview-reply-body">' + (r.content || '') + '</div></div>';
            }
            replies += '</div>';
        } else if ((d.replies_count || 0) === 0) {
            replies = '<p style="text-align:center;color:var(--text-muted);padding:1rem;font-size:0.85rem;">' + <?php echo json_encode(t('admin_posts_preview_no_replies', '暂无回复')); ?> + '</p>';
        }

        document.getElementById('modal-title').textContent = d.title || <?php echo json_encode(t('admin_posts_modal_title', '帖子内容')); ?>;
        document.getElementById('modal-body').innerHTML = '<div style="max-height:70vh;overflow-y:auto;">' +
            riskBar + meta + '<hr style="border:0;border-top:1px solid var(--border-soft);margin:0.5rem 0;">' +
            content + '<hr style="border:0;border-top:1px solid var(--border-soft);margin:0.5rem 0;">' + replies + '</div>';
    }

    function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s || '')); return d.innerHTML; }

    window.closeModal = function() { document.getElementById('content-modal').classList.remove('show'); };

    document.getElementById('content-modal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>