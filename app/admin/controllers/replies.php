<?php
/**
 * 云界论坛 - 管理后台回复管理（增强版）
 * 支持搜索、筛选、批量操作、内容预览
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$db = get_db();
$driver = get_db_driver();
$action = $_GET['action'] ?? 'list';
$replyId = (int)($_GET['reply_id'] ?? 0);

// ===================== 批量删除 =====================
if ($action === 'batch' && validate_csrf()) {
    $ids = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function($v) { return $v > 0; });
    if (!empty($ids) && ($_POST['batch_action'] ?? '') === 'delete') {
        foreach ($ids as $id) delete_reply($id);
        set_flash(t('admin_replies_flash_batch_deleted', '已删除 {n} 条回复。', ['n' => count($ids)]), 'success');
    }
    redirect('/admin/replies' . ($_SERVER['QUERY_STRING'] ? '?' . preg_replace('/&?action=batch[^&]*/', '', $_SERVER['QUERY_STRING']) : ''));
}

// ===================== 单个删除 =====================
// CSRF 统一校验：涉及状态变更的 GET 操作必须先通过校验，失败时明确提示（避免静默失败）
if ($action === 'delete' && !validate_csrf()) {
    set_flash(t('admin_replies_flash_csrf_failed', '安全校验失败（链接已过期），请刷新页面后重新操作。'), 'error');
    redirect('/admin/replies');
}

if ($action === 'delete' && $replyId > 0) {
    if (delete_reply($replyId)) set_flash(t('admin_replies_flash_deleted', '回复已删除。'), 'success');
    else set_flash(t('admin_replies_flash_delete_failed', '回复不存在或删除失败。'), 'error');
    redirect('/admin/replies');
}

// ===================== 搜索与筛选参数 =====================
$search = trim($_GET['search'] ?? '');
$postFilter = (int)($_GET['post_id'] ?? 0);
$authorFilter = trim($_GET['author'] ?? '');
$sort = in_array($_GET['sort'] ?? '', ['created_at', 'floor']) ? $_GET['sort'] : 'created_at';
$order = ($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// ===================== 构建查询条件 =====================
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(r.content LIKE :search OR u.username LIKE :search2 OR r.id = :search_id)';
    $params[':search'] = "%$search%";
    $params[':search2'] = "%$search%";
    $params[':search_id'] = is_numeric($search) ? (int)$search : 0;
}
if ($postFilter > 0) {
    $where[] = 'r.post_id = :post_id';
    $params[':post_id'] = $postFilter;
}
if ($authorFilter !== '') {
    $where[] = 'u.username LIKE :author';
    $params[':author'] = "%$authorFilter%";
}

$whereSql = implode(' AND ', $where);

// ===================== 统计卡片 =====================
$today = date('Y-m-d');
$stats = [
    'total'      => (int)$db->query("SELECT COUNT(*) FROM replies")->fetchColumn(),
    'today'      => (int)$db->query("SELECT COUNT(*) FROM replies WHERE " . $driver->dateColExpr('created_at') . " = " . $db->quote($today))->fetchColumn(),
    'totalPosts' => (int)$db->query("SELECT COUNT(*) FROM posts")->fetchColumn(),
];

// ===================== 查询回复列表 =====================
$total = $stats['total'];
if (!empty($params)) {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM replies r JOIN users u ON r.user_id = u.id JOIN posts p ON r.post_id = p.id WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
}

$sql = "SELECT r.*, u.username, p.title AS post_title, p.id AS post_id
    FROM replies r
    JOIN users u ON r.user_id = u.id
    JOIN posts p ON r.post_id = p.id
    WHERE $whereSql
    ORDER BY r.{$sort} {$order}
    LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$replies = $stmt->fetchAll();

// 查询字符串
function replies_qs(array $overrides = []): string {
    $p = $_GET; unset($p['page']);
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') unset($p[$k]); else $p[$k] = $v;
    }
    $qs = http_build_query($p);
    return $qs ? '?' . $qs : '';
}

$pageTitle = t('admin_replies_title', '回复管理');
$activeMenu = 'replies';
require_once dirname(__DIR__) . '/layout/header.php';
?>

<style>
/* ===== 回复管理样式 ===== */
.stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; margin-bottom: 1.25rem; }
.stats-row .stat-card {
    background: var(--surface); border: 1px solid var(--border-soft);
    border-radius: var(--radius-md); padding: 1rem 1.125rem; cursor: default;
    transition: all 0.2s; display: flex; align-items: center; gap: 0.75rem;
}
.stats-row .stat-card:hover { border-color: var(--primary); box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
.stats-row .stat-icon { width: 40px; height: 40px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stats-row .stat-icon svg { width: 20px; height: 20px; }
.stat-icon--indigo { background: #eef2ff; color: #6366f1; }
.stat-icon--green  { background: #ecfdf5; color: #10b981; }
.stat-icon--blue   { background: #eff6ff; color: #3b82f6; }
.stats-row .stat-info { min-width: 0; }
.stats-row .stat-num { font-size: 1.35rem; font-weight: 700; color: var(--text); line-height: 1.2; }
.stats-row .stat-label { font-size: 0.72rem; color: var(--text-muted); margin-top: 2px; }

.filter-bar {
    display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;
    padding: 0.75rem 1rem; background: var(--surface); border: 1px solid var(--border-soft);
    border-radius: var(--radius-md); margin-bottom: 0.75rem;
}
.filter-bar input {
    height: 35px; font-size: 0.85rem; padding: 0 0.65rem;
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    background: var(--bg); color: var(--text); outline: none;
}
.filter-bar input:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(59,130,246,0.12); }
.filter-bar input[type="search"] { min-width: 200px; }
.filter-bar input[type="number"] { width: 90px; min-width: 0; }
.filter-bar input[type="text"]   { width: 110px; min-width: 0; }
.filter-bar .btn { height: 35px; padding: 0 0.85rem; font-size: 0.825rem; }

.batch-bar {
    display: none; align-items: center; gap: 0.5rem;
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

.sort-link { color: var(--text-muted); text-decoration: none; white-space: nowrap; user-select: none; }
.sort-link:hover { color: var(--text); }
.sort-link.active { color: var(--primary); font-weight: 600; }
.sort-link.asc::after  { content: ' ▲'; font-size: 0.55rem; }
.sort-link.desc::after { content: ' ▼'; font-size: 0.55rem; }

/* 回复内容预览 */
.reply-preview {
    cursor: pointer; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    overflow: hidden; max-width: 320px; font-size: 0.85rem; line-height: 1.5; 
}
.reply-preview:hover { color: var(--primary); }
.reply-ref { display: block; font-size: 0.7rem; color: var(--text-muted); margin-top: 2px; }

/* 操作按钮 */
.actions { display: flex; gap: 4px; }
.actions .btn-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid transparent;
    background: transparent; color: var(--text-muted); cursor: pointer; transition: all 0.15s;
    text-decoration: none; padding: 0;
}
.actions .btn-icon:hover { background: var(--bg-soft); color: var(--text); border-color: var(--border); }
.actions .btn-icon.danger:hover { color: #dc2626; background: #fef2f2; border-color: #fecaca; }
.actions .btn-icon svg { width: 15px; height: 15px; }

/* 模态框 */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 999; justify-content: center; align-items: flex-start; padding-top: 5vh; }
.modal-overlay.show { display: flex; }
.modal-content { background: var(--surface); border-radius: var(--radius-lg); width: 95%; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-soft); position: sticky; top: 0; background: var(--surface); z-index: 1; }
.modal-header h3 { margin: 0; font-size: 1rem; }
.modal-body { padding: 1rem 1.25rem; line-height: 1.7; font-size: 0.9rem; word-break: break-word; }
.modal-body .reply-meta { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.75rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border-soft); }
.modal-close { background: none; border: none; font-size: 1.25rem; cursor: pointer; color: var(--text-muted); padding: 0.25rem; line-height: 1; }

@media (max-width: 768px) {
    .stats-row { grid-template-columns: repeat(2, 1fr); }
    .filter-bar { flex-direction: column; align-items: stretch; }
    .filter-bar input[type="search"] { width: 100%; min-width: 0; }
}
</style>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_replies_title', '回复管理')); ?></h1>
    <div class="page-actions">
        <span style="font-size:0.8rem;color:var(--text-muted);"><?php echo e(t('admin_replies_total_count', '共 {n} 条回复', ['n' => $total])); ?></span>
    </div>
</div>

<!-- 统计卡片 -->
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon stat-icon--indigo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div>
        <div class="stat-info"><div class="stat-num"><?php echo number_format($stats['total']); ?></div><div class="stat-label"><?php echo e(t('admin_replies_stat_all', '全部回复')); ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon--green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div>
        <div class="stat-info"><div class="stat-num"><?php echo number_format($stats['today']); ?></div><div class="stat-label"><?php echo e(t('admin_replies_stat_today', '今日新增')); ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon--blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
        <div class="stat-info"><div class="stat-num"><?php echo number_format($stats['totalPosts']); ?></div><div class="stat-label"><?php echo e(t('admin_replies_stat_posts', '关联帖子')); ?></div></div>
    </div>
</div>

<!-- 筛选栏 -->
<form method="get" action="<?php echo site_url('admin/replies'); ?>" class="filter-bar">
    <input type="search" name="search" value="<?php echo e($search); ?>" placeholder="<?php echo e(t('admin_replies_search_placeholder', '搜索回复内容、作者或 ID...')); ?>">
    <input type="number" name="post_id" value="<?php echo $postFilter > 0 ? $postFilter : ''; ?>" placeholder="<?php echo e(t('admin_replies_post_id_placeholder', '帖子 ID')); ?>" min="1">
    <input type="text" name="author" value="<?php echo e($authorFilter); ?>" placeholder="<?php echo e(t('admin_replies_author_placeholder', '作者')); ?>">
    <button type="submit" class="btn btn-primary btn-sm"><?php echo e(t('admin_replies_filter', '筛选')); ?></button>
    <?php if ($search || $postFilter || $authorFilter): ?>
        <a href="<?php echo site_url('admin/replies'); ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;"><?php echo e(t('admin_replies_clear', '清除')); ?></a>
    <?php endif; ?>
</form>

<!-- 批量操作栏 -->
<div class="batch-bar" id="batch-bar">
    <span class="batch-count" id="batch-count"><?php echo e(t('admin_replies_batch_selected', '已选 {n} 项', ['n' => 0])); ?></span>
    <button class="btn btn-sm btn-danger" onclick="batchAction('delete')"><?php echo e(t('admin_replies_batch_delete', '批量删除')); ?></button>
</div>

<div class="card" style="overflow:hidden;">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="select-all" onchange="toggleSelectAll(this)"></th>
                    <th style="width:48px;">ID</th>
                    <th style="width:48px;"><?php echo e(t('admin_replies_th_floor', '楼层')); ?></th>
                    <th><?php echo e(t('admin_replies_th_content', '回复内容')); ?></th>
                    <th style="width:85px;"><?php echo e(t('admin_replies_th_author', '作者')); ?></th>
                    <th style="width:160px;"><?php echo e(t('admin_replies_th_post', '所属帖子')); ?></th>
                    <th style="width:130px;">
                        <a href="<?php echo site_url('admin/replies', array_merge(array_diff_key($_GET, ['page' => 1, 'route' => 1]), ['sort' => 'created_at', 'order' => ($sort === 'created_at' && $order === 'asc') ? 'desc' : 'asc'])); ?>" class="sort-link<?php echo $sort === 'created_at' ? ' active ' . $order : ''; ?>"><?php echo e(t('admin_replies_th_time', '时间')); ?></a>
                    </th>
                    <th style="width:100px;"><?php echo e(t('admin_replies_th_actions', '操作')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($replies)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:2.5rem;color:var(--text-muted);"><?php echo e(t('admin_replies_empty', '暂无回复数据')); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($replies as $reply): ?>
                        <tr>
                            <td><input type="checkbox" class="reply-check" value="<?php echo $reply['id']; ?>" onchange="updateBatchBar()"></td>
                            <td><code style="font-size:0.8rem;">#<?php echo $reply['id']; ?></code></td>
                            <td><span style="font-weight:600;color:var(--primary);font-size:0.85rem;">#<?php echo (int)$reply['floor']; ?></span></td>
                            <td>
                                <div class="reply-preview" onclick="viewReplyContent(<?php echo $reply['id']; ?>)" title="<?php echo e(t('admin_replies_click_to_view', '点击查看完整内容')); ?>">
                                    <?php echo e($reply['content']); ?>
                                </div>
                                <?php if ($reply['reply_to']): ?>
                                    <span class="reply-ref">↳ <?php echo e(t('admin_replies_reply_to_ref', '回复 #{n}', ['n' => $reply['reply_to']])); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?php echo site_url('profile', ['user_id' => (int)$reply['user_id']]); ?>" style="font-size:0.85rem;"><?php echo e($reply['username']); ?></a></td>
                            <td>
                                <a href="<?php echo site_url('post', ['id' => (int)$reply['post_id']]); ?>" target="_blank" style="font-size:0.8rem;">
                                    <?php $ptitle = e($reply['post_title']); ?>
                                    <?php echo mb_strlen($ptitle, 'UTF-8') > 22 ? mb_substr($ptitle, 0, 22, 'UTF-8') . '…' : $ptitle; ?>
                                </a>
                            </td>
                            <td style="font-size:0.8rem;color:var(--text-muted);white-space:nowrap;"><?php echo date('Y-m-d H:i', strtotime($reply['created_at'])); ?></td>
                            <td>
                                <div class="actions">
                                    <a href="<?php echo site_url('post', ['id' => (int)$reply['post_id']]); ?>#reply-<?php echo (int)$reply['id']; ?>" target="_blank" class="btn-icon" title="<?php echo e(t('admin_replies_action_view_in_post', '在原帖查看')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></a>
                                    <button class="btn-icon" onclick="viewReplyContent(<?php echo $reply['id']; ?>)" title="<?php echo e(t('admin_replies_action_preview', '预览内容')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></button>
                                    <a href="<?php echo site_url('admin/replies', ['action' => 'delete', 'reply_id' => (int)$reply['id'], 'csrf_token' => csrf_token()]); ?>" class="btn-icon danger" data-confirm="<?php echo e(t('admin_replies_confirm_delete', '确定删除该回复吗？此操作不可撤销。')); ?>" title="<?php echo e(t('admin_replies_action_delete', '删除')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo pagination($page, $total, $perPage, site_url('admin/replies', array_diff_key($_GET, ['page' => 1, 'route' => 1]))); ?>
</div>

<!-- 回复内容预览模态框 -->
<div class="modal-overlay" id="reply-modal">
    <div class="modal-content" style="max-width:700px;">
        <div class="modal-header">
            <h3 id="reply-modal-title"><?php echo e(t('admin_replies_modal_title', '回复内容')); ?></h3>
            <button class="modal-close" onclick="closeReplyModal()">&times;</button>
        </div>
        <div class="modal-body" id="reply-modal-body">
            <p style="text-align:center;color:var(--text-muted);padding:2rem;"><?php echo e(t('admin_replies_loading', '加载中...')); ?></p>
        </div>
    </div>
</div>

<!-- 批量操作表单 -->
<form id="batch-form" method="post" action="<?php echo site_url('admin/replies'); ?>" style="display:none;">
    <input type="hidden" name="action" value="batch">
    <input type="hidden" name="ids" id="batch-ids" value="">
    <input type="hidden" name="batch_action" id="batch-action" value="">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
</form>

<script>
(function() {
    var replyCache = {};

    window.toggleSelectAll = function(cb) {
        var checks = document.querySelectorAll('.reply-check');
        for (var i = 0; i < checks.length; i++) checks[i].checked = cb.checked;
        updateBatchBar();
    };

    window.updateBatchBar = function() {
        var checks = document.querySelectorAll('.reply-check:checked');
        var bar = document.getElementById('batch-bar');
        var count = checks.length;
        if (count > 0) {
            bar.classList.add('show');
            document.getElementById('batch-count').textContent = <?php echo json_encode(t('admin_replies_batch_selected', '已选 {n} 项')); ?>.replace('{n}', count);
        } else {
            bar.classList.remove('show');
            document.getElementById('select-all').checked = false;
        }
    };

    window.batchAction = function(action) {
        var checks = document.querySelectorAll('.reply-check:checked');
        if (checks.length === 0) { alert(<?php echo json_encode(t('admin_replies_js_select_first', '请先选择回复。')); ?>); return; }
        if (action === 'delete' && !confirm(<?php echo json_encode(t('admin_replies_js_confirm_batch_delete', '确定批量删除已选的 {n} 条回复吗？此操作不可撤销。')); ?>.replace('{n}', checks.length))) return;
        var ids = [];
        for (var i = 0; i < checks.length; i++) ids.push(checks[i].value);
        document.getElementById('batch-ids').value = ids.join(',');
        document.getElementById('batch-action').value = action;
        document.getElementById('batch-form').submit();
    };

    // ===== 回复内容预览 =====
    window.viewReplyContent = function(replyId) {
        var modal = document.getElementById('reply-modal');
        var body = document.getElementById('reply-modal-body');
        modal.classList.add('show');
        document.getElementById('reply-modal-title').textContent = <?php echo json_encode(t('admin_replies_loading', '加载中...')); ?>;
        body.innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:2rem;">' + <?php echo json_encode(t('admin_replies_loading', '加载中...')); ?> + '</p>';

        if (replyCache[replyId]) {
            renderReplyModal(replyCache[replyId]);
            return;
        }

        fetch('<?php echo site_url('admin/api/replies_ajax'); ?>&action=get_content&reply_id=' + replyId + '&csrf_token=<?php echo csrf_token(); ?>')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    replyCache[replyId] = data;
                    renderReplyModal(data);
                } else {
                    body.innerHTML = '<p style="color:#ef4444;padding:1rem;">' + <?php echo json_encode(t('admin_replies_js_load_failed', '加载失败：')); ?> + (data.error || <?php echo json_encode(t('admin_replies_js_unknown_error', '未知错误')); ?>) + '</p>';
                }
            })
            .catch(function() {
                body.innerHTML = '<p style="color:#ef4444;padding:1rem;">' + <?php echo json_encode(t('admin_replies_js_network_error', '网络错误，加载失败。')); ?> + '</p>';
            });
    };

    function renderReplyModal(d) {
        document.getElementById('reply-modal-title').textContent = <?php echo json_encode(t('admin_replies_modal_reply_title', '回复 #{id}')); ?>.replace('{id}', d.id) + ' — ' + (d.username || '');
        var body = document.getElementById('reply-modal-body');
        var quote = '';
        if (d.reply_to_floor > 0) {
            quote = '<div style="background:#f8fafb;border-left:3px solid var(--border);padding:0.5rem 0.75rem;margin-bottom:0.75rem;font-size:0.85rem;border-radius:0 var(--radius-sm) var(--radius-sm) 0;">';
            quote += '<span style="color:var(--text-muted);">↳ ' + <?php echo json_encode(t('admin_replies_quote_reply_to_floor', '回复第 {n} 楼')); ?>.replace('{n}', d.reply_to_floor) + '</span>';
            if (d.quote_content) quote += '<p style="margin:0.25rem 0 0;color:var(--text-muted);font-size:0.8rem;">' + esc(d.quote_content).substring(0, 200) + '</p>';
            quote += '</div>';
        }
        body.innerHTML = '<div class="reply-meta">' +
            '<strong>' + esc(d.username) + '</strong> · ' + esc(d.post_title || '') +
            ' · ' + (d.created_at || '') + ' · ' + <?php echo json_encode(t('admin_replies_floor_no', '第 {n} 楼')); ?>.replace('{n}', (d.floor || '0')) +
            (d.post_id ? ' · <a href="<?php echo site_url('post'); ?>&id=' + d.post_id + '#reply-' + d.id + '" target="_blank">' + <?php echo json_encode(t('admin_replies_action_view_in_post', '在原帖查看')); ?> + '</a>' : '') +
            '</div>' + quote +
            '<div style="max-height:50vh;overflow-y:auto;">' + (d.content || '<p style="color:var(--text-muted);">' + <?php echo json_encode(t('admin_replies_preview_no_content', '暂无内容')); ?> + '</p>') + '</div>';
    }

    function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s || '')); return d.innerHTML; }

    window.closeReplyModal = function() { document.getElementById('reply-modal').classList.remove('show'); };
    document.getElementById('reply-modal').addEventListener('click', function(e) { if (e.target === this) closeReplyModal(); });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('reply-modal').classList.contains('show')) closeReplyModal();
    });
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>