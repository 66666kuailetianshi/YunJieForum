<?php
/**
 * 云界论坛 - 管理后台帖子管理（增强版）
 * 支持搜索、筛选、排序、批量操作、状态管理、风险评估
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$db = get_db();
$driver = get_db_driver();
$action = $_GET['action'] ?? 'list';
$postId = (int)($_GET['post_id'] ?? 0);

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

// ===================== 单个操作 =====================
// CSRF 统一校验：涉及状态变更的 GET 操作必须先通过校验，失败时明确提示（避免静默失败）
if (in_array($action, ['pin', 'unpin', 'essence', 'unessence', 'lock', 'unlock', 'delete'], true) && !validate_csrf()) {
    set_flash(t('admin_posts_flash_csrf_failed', '安全校验失败（链接已过期），请刷新页面后重新操作。'), 'error');
    redirect('/admin/posts');
}

if (in_array($action, ['pin', 'unpin', 'essence', 'unessence', 'lock', 'unlock', 'delete']) && $postId > 0) {
    $flags = ['pin' => 'is_pinned=1', 'unpin' => 'is_pinned=0', 'essence' => 'is_essence=1', 'unessence' => 'is_essence=0', 'lock' => 'is_locked=1', 'unlock' => 'is_locked=0'];
    $msgs = ['pin' => t('admin_posts_flash_pinned', '已置顶。'), 'unpin' => t('admin_posts_flash_unpinned', '已取消置顶。'), 'essence' => t('admin_posts_flash_essenced', '已加精。'), 'unessence' => t('admin_posts_flash_unessenced', '已取消加精。'), 'lock' => t('admin_posts_flash_locked', '已锁定。'), 'unlock' => t('admin_posts_flash_unlocked', '已解锁。')];
    if (isset($flags[$action])) {
        $db->exec("UPDATE posts SET {$flags[$action]} WHERE id = $postId");
        set_flash($msgs[$action], 'success');
    } elseif ($action === 'delete') {
        if (delete_post($postId)) set_flash(t('admin_posts_flash_deleted', '帖子已删除。'), 'success');
        else set_flash(t('admin_posts_flash_delete_failed', '帖子不存在或删除失败。'), 'error');
    }
    redirect('/admin/posts');
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
$today = date('Y-m-d');
$stats = [
    'total'   => (int)$db->query("SELECT COUNT(*) FROM posts")->fetchColumn(),
    'today'   => (int)$db->query("SELECT COUNT(*) FROM posts WHERE " . $driver->dateColExpr('created_at') . " = " . $db->quote($today))->fetchColumn(),
    'pinned'  => (int)$db->query("SELECT COUNT(*) FROM posts WHERE is_pinned = 1")->fetchColumn(),
    'essence' => (int)$db->query("SELECT COUNT(*) FROM posts WHERE is_essence = 1")->fetchColumn(),
    'locked'  => (int)$db->query("SELECT COUNT(*) FROM posts WHERE is_locked = 1")->fetchColumn(),
];

// ===================== 查询帖子列表 =====================
$total = (int)$db->query("SELECT COUNT(*) FROM posts p JOIN users u ON p.user_id = u.id WHERE $whereSql")->fetchColumn();
if (!empty($params)) {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM posts p JOIN users u ON p.user_id = u.id WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
}

$sql = "SELECT p.*, u.username, f.name AS forum_name
    FROM posts p
    JOIN users u ON p.user_id = u.id
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

<style>
/* ===== 帖子管理样式 ===== */
.stats-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.75rem; margin-bottom: 1.25rem; }
.stats-row .stat-card {
    background: var(--surface); border: 1px solid var(--border-soft);
    border-radius: var(--radius-md); padding: 1rem 1.125rem; cursor: pointer;
    transition: all 0.2s; display: flex; align-items: center; gap: 0.75rem;
}
.stats-row .stat-card:hover { border-color: var(--primary); box-shadow: 0 2px 12px rgba(0,0,0,0.06); transform: translateY(-1px); }
.stats-row .stat-card.active { border-color: var(--primary); background: color-mix(in srgb, var(--primary) 5%, var(--surface)); }
.stats-row .stat-icon { width: 40px; height: 40px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stats-row .stat-icon svg { width: 20px; height: 20px; }
.stat-icon--blue  { background: #eff6ff; color: #3b82f6; }
.stat-icon--amber { background: #fef3c7; color: #d97706; }
.stat-icon--pink  { background: #fce7f3; color: #db2777; }
.stat-icon--red   { background: #fee2e2; color: #dc2626; }
.stat-icon--green { background: #ecfdf5; color: #10b981; }
.stats-row .stat-info { min-width: 0; }
.stats-row .stat-num { font-size: 1.35rem; font-weight: 700; color: var(--text); line-height: 1.2; }
.stats-row .stat-label { font-size: 0.72rem; color: var(--text-muted); margin-top: 2px; }

.filter-bar {
    display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;
    padding: 0.75rem 1rem; background: var(--surface); border: 1px solid var(--border-soft);
    border-radius: var(--radius-md); margin-bottom: 0.75rem;
}
.filter-bar input, .filter-bar select {
    height: 35px; font-size: 0.85rem; padding: 0 0.65rem;
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    background: var(--bg); color: var(--text); outline: none;
}
.filter-bar input:focus, .filter-bar select:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(59,130,246,0.12); }
.filter-bar input[type="search"] { min-width: 200px; }
.filter-bar select { min-width: 110px; }
.filter-bar .btn { height: 35px; padding: 0 0.85rem; font-size: 0.825rem; }
.filter-bar .filter-gap { width: 4px; }

.batch-bar {
    display: none; align-items: center; gap: 0.5rem; flex-wrap: wrap;
    padding: 0.5rem 0.75rem; border-radius: var(--radius-sm); margin-bottom: 0.75rem;
    background: color-mix(in srgb, var(--primary) 8%, var(--surface));
    border: 1px solid color-mix(in srgb, var(--primary) 20%, var(--border));
}
.batch-bar.show { display: flex; }
.batch-bar .batch-count { font-weight: 600; font-size: 0.85rem; white-space: nowrap; }

/* 表格 */
.data-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.data-table thead { position: sticky; top: 0; z-index: 2; }
.data-table th {
    padding: 0.6rem 0.75rem; text-align: left; font-size: 0.75rem; font-weight: 600;
    color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em;
    background: var(--bg-soft); border-bottom: 2px solid var(--border); white-space: nowrap;
}
.data-table td {
    padding: 0.65rem 0.75rem; border-bottom: 1px solid var(--border-soft);
    vertical-align: middle;
}
.data-table tbody tr { transition: background 0.15s; }
.data-table tbody tr:hover { background: color-mix(in srgb, var(--primary) 3%, var(--bg)); }
.data-table tbody tr:nth-child(even) { background: rgba(128,128,128,0.02); }
.data-table tbody tr:nth-child(even):hover { background: color-mix(in srgb, var(--primary) 3%, var(--bg)); }
.data-table a { color: var(--text); text-decoration: none; }
.data-table a:hover { color: var(--primary); }

/* 状态标签 */
.post-tags { display: flex; gap: 3px; flex-wrap: wrap; margin-top: 3px; }
.post-tag {
    display: inline-flex; align-items: center; gap: 2px; padding: 1px 6px;
    border-radius: 3px; font-size: 0.68rem; font-weight: 600; line-height: 1.5;
}
.post-tag svg { width: 10px; height: 10px; flex-shrink: 0; }
.tag-pin { background: #fef3c7; color: #92400e; }
.tag-best { background: #fce7f3; color: #9d174d; }
.tag-lock { background: #fee2e2; color: #991b1b; }

/* 风险标签 */
.risk-tag {
    display: inline-block; padding: 2px 8px; border-radius: 3px;
    font-size: 0.7rem; font-weight: 700; white-space: nowrap;
}
.risk-high  { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.risk-medium{ background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
.risk-low   { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
.risk-safe  { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }

/* 操作按钮 */
.actions { display: flex; gap: 2px; flex-wrap: wrap; }
.actions .btn-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid transparent;
    background: transparent; color: var(--text-muted); cursor: pointer; transition: all 0.15s;
    text-decoration: none; padding: 0;
}
.actions .btn-icon:hover { background: var(--bg-soft); color: var(--text); border-color: var(--border); }
.actions .btn-icon.active { color: var(--primary); background: color-mix(in srgb, var(--primary) 8%, var(--surface)); }
.actions .btn-icon.danger:hover { color: #dc2626; background: #fef2f2; border-color: #fecaca; }
.actions .btn-icon svg { width: 15px; height: 15px; }

/* 排序链接 */
.sort-link { color: var(--text-muted); text-decoration: none; white-space: nowrap; user-select: none; }
.sort-link:hover { color: var(--text); }
.sort-link.active { color: var(--primary); font-weight: 600; }
.sort-link.asc::after  { content: ' ▲'; font-size: 0.55rem; }
.sort-link.desc::after { content: ' ▼'; font-size: 0.55rem; }

/* 互动数 */
.interact { display: flex; gap: 0.5rem; font-size: 0.8rem; }
.interact span { white-space: nowrap; }
.interact .val { font-weight: 600; color: var(--text); }

/* 模态框 */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 999; justify-content: center; align-items: flex-start; padding-top: 5vh; }
.modal-overlay.show { display: flex; }
.modal-content { background: var(--surface); border-radius: var(--radius-lg); width: 95%; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-soft); position: sticky; top: 0; background: var(--surface); z-index: 1; }
.modal-header h3 { margin: 0; font-size: 1rem; }
.modal-body { padding: 1rem 1.25rem; line-height: 1.7; font-size: 0.9rem; }
.modal-close { background: none; border: none; font-size: 1.25rem; cursor: pointer; color: var(--text-muted); padding: 0.25rem; line-height: 1; }

/* 预览内容 */
.preview-meta { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.5rem; }
.preview-content { line-height: 1.7; }
.preview-replies { margin-top: 0.5rem; }
.preview-reply-item {
    padding: 0.6rem 0.75rem; margin-bottom: 0.5rem;
    background: var(--bg-soft); border-radius: var(--radius-sm); border-left: 3px solid var(--border);
}
.preview-reply-header { display: flex; align-items: center; gap: 0.35rem; font-size: 0.8rem; margin-bottom: 0.35rem; }
.preview-reply-body { font-size: 0.85rem; line-height: 1.6; }

@media (max-width: 768px) {
    .stats-row { grid-template-columns: repeat(2, 1fr); }
    .filter-bar { flex-direction: column; align-items: stretch; }
    .filter-bar input[type="search"] { width: 100%; min-width: 0; }
    .data-table th:nth-child(5), .data-table td:nth-child(5),
    .data-table th:nth-child(6), .data-table td:nth-child(6) { display: none; }
}
</style>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_posts_title', '帖子管理')); ?></h1>
    <div class="page-actions">
        <span style="font-size:0.8rem;color:var(--text-muted);"><?php echo e(t('admin_posts_total_count', '共 {n} 个帖子', ['n' => $total])); ?></span>
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
        <a href="<?php echo site_url('admin/posts'); ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;"><?php echo e(t('admin_posts_clear', '清除')); ?></a>
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

<div class="card" style="overflow:hidden;">
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
                    <th style="width:155px;"><?php echo e(t('admin_posts_th_actions', '操作')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allPosts)): ?>
                    <tr><td colspan="9" style="text-align:center;padding:2.5rem;color:var(--text-muted);"><?php echo e(t('admin_posts_empty', '暂无帖子数据')); ?></td></tr>
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
                            <td><code style="font-size:0.8rem;">#<?php echo $post['id']; ?></code></td>
                            <td>
                                <a href="<?php echo site_url('post', ['id' => (int)$post['id']]); ?>" target="_blank" style="font-weight:500;"><?php echo $titleDisplay; ?></a>
                                <?php if ($post['is_pinned'] || $post['is_essence'] || $post['is_locked']): ?>
                                <div class="post-tags">
                                    <?php if ($post['is_pinned']): ?><span class="post-tag tag-pin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2l2 8h6l-4 5 2 7-6-3-6 3 2-7-4-5h6l2-8z"/></svg><?php echo e(t('admin_posts_tag_pinned', '置顶')); ?></span><?php endif; ?>
                                    <?php if ($post['is_essence']): ?><span class="post-tag tag-best"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 3h12l4 8-10 11L2 11z"/></svg><?php echo e(t('admin_posts_tag_essence', '精华')); ?></span><?php endif; ?>
                                    <?php if ($post['is_locked']): ?><span class="post-tag tag-lock"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg><?php echo e(t('admin_posts_tag_locked', '锁定')); ?></span><?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?php echo site_url('profile', ['user_id' => (int)$post['user_id']]); ?>" style="font-size:0.85rem;"><?php echo e($post['username']); ?></a></td>
                            <td style="font-size:0.8rem;color:var(--text-muted);"><?php echo e($post['forum_name'] ?? '-'); ?></td>
                            <td><span class="risk-tag <?php echo $riskClass; ?>" title="<?php echo e(t('admin_posts_risk_score_title', '风险分数: {score}/100', ['score' => $risk['score']])); ?>"><?php echo $risk['label']; ?></span></td>
                            <td>
                                <div class="interact">
                                    <span title="<?php echo e(t('admin_posts_views', '浏览')); ?>"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:2px;color:var(--text-muted);"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><span class="val"><?php echo number_format($post['views']); ?></span></span>
                                    <span title="<?php echo e(t('admin_posts_replies', '回复')); ?>"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:2px;color:var(--text-muted);"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg><span class="val"><?php echo number_format($post['replies_count']); ?></span></span>
                                </div>
                            </td>
                            <td style="font-size:0.8rem;color:var(--text-muted);white-space:nowrap;"><?php echo date('Y-m-d H:i', strtotime($post['created_at'])); ?></td>
                            <td>
                                <div class="actions">
                                    <a href="<?php echo site_url('post', ['id' => (int)$post['id']]); ?>" target="_blank" class="btn-icon" title="<?php echo e(t('admin_posts_action_view', '查看帖子')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></a>
                                    <button class="btn-icon" onclick="viewContent(<?php echo $post['id']; ?>)" title="<?php echo e(t('admin_posts_action_preview', '预览内容')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></button>
                                    <a href="<?php echo site_url('admin/posts', ['action' => $post['is_pinned'] ? 'unpin' : 'pin', 'post_id' => (int)$post['id'], 'csrf_token' => csrf_token()]); ?>" class="btn-icon<?php echo $post['is_pinned'] ? ' active' : ''; ?>" title="<?php echo e($post['is_pinned'] ? t('admin_posts_action_unpin', '取消置顶') : t('admin_posts_action_pin', '置顶')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2l2 8h6l-4 5 2 7-6-3-6 3 2-7-4-5h6l2-8z"/></svg></a>
                                    <a href="<?php echo site_url('admin/posts', ['action' => $post['is_essence'] ? 'unessence' : 'essence', 'post_id' => (int)$post['id'], 'csrf_token' => csrf_token()]); ?>" class="btn-icon<?php echo $post['is_essence'] ? ' active' : ''; ?>" title="<?php echo e($post['is_essence'] ? t('admin_posts_action_unessence', '取消加精') : t('admin_posts_action_essence', '加精')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 3h12l4 8-10 11L2 11z"/></svg></a>
                                    <a href="<?php echo site_url('admin/posts', ['action' => $post['is_locked'] ? 'unlock' : 'lock', 'post_id' => (int)$post['id'], 'csrf_token' => csrf_token()]); ?>" class="btn-icon<?php echo $post['is_locked'] ? ' active' : ''; ?>" title="<?php echo e($post['is_locked'] ? t('admin_posts_action_unlock', '解锁') : t('admin_posts_action_lock', '锁定')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg></a>
                                    <a href="<?php echo site_url('admin/posts', ['action' => 'delete', 'post_id' => (int)$post['id'], 'csrf_token' => csrf_token()]); ?>" class="btn-icon danger" data-confirm="<?php echo e(t('admin_posts_confirm_delete', '确定删除该帖子吗？所有回复也将一并删除。')); ?>" title="<?php echo e(t('admin_posts_action_delete', '删除')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg></a>
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

        fetch('<?php echo site_url('admin/api/posts_ajax'); ?>&action=get_content&post_id=' + postId + '&csrf_token=<?php echo csrf_token(); ?>')
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