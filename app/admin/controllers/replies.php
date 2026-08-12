<?php
/**
 * 云界论坛 - 管理后台回复管理（增强版）
 * 支持搜索、筛选、批量操作、内容预览
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：回复管理（超管天然通过；社区管理员需 manage_replies 权限）
require_permission('manage_replies');

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
// 仅接受 POST（CSRF 由 admin-init.php 对所有 POST 统一校验）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $delReplyId = (int)($_POST['reply_id'] ?? 0);
    if ($delReplyId > 0 && delete_reply($delReplyId)) set_flash(t('admin_replies_flash_deleted', '回复已删除。'), 'success');
    else set_flash(t('admin_replies_flash_delete_failed', '回复不存在或删除失败。'), 'error');
    redirect('/admin/replies');
}
// 旧 GET 删除链接命中：不执行删除，提示刷新
if ($action === 'delete') {
    set_flash(t('post_flash_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
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
// replies 表两项统计合并为单条 SUM(CASE WHEN)（标准 SQL 三方言通用）；
// totalPosts 属 posts 表，无法与 replies 表合并
$today = date('Y-m-d');
$replyStatsRow = $db->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN " . $driver->dateColExpr('created_at') . " = " . $db->quote($today) . " THEN 1 ELSE 0 END) AS today
    FROM replies")->fetch();
$stats = [
    'total'      => (int)($replyStatsRow['total'] ?? 0),
    'today'      => (int)($replyStatsRow['today'] ?? 0),
    'totalPosts' => (int)$db->query("SELECT COUNT(*) FROM posts")->fetchColumn(),
];

// ===================== 查询回复列表 =====================
$total = $stats['total'];
if (!empty($params)) {
    $countStmt = $db->prepare("SELECT COUNT(DISTINCT r.id) FROM replies r JOIN users u ON r.user_id = u.id OR r.user_id = u.uid JOIN posts p ON r.post_id = p.id WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
}

$sql = "SELECT r.*, u.username, p.title AS post_title, p.id AS post_id
    FROM replies r
    JOIN users u ON r.user_id = u.id OR r.user_id = u.uid
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

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_replies_title', '回复管理')); ?></h1>
    <div class="page-actions">
        <span class="text-xs text-muted"><?php echo e(t('admin_replies_total_count', '共 {n} 条回复', ['n' => $total])); ?></span>
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
        <a href="<?php echo site_url('admin/replies'); ?>" class="btn btn-secondary btn-sm"><?php echo e(t('admin_replies_clear', '清除')); ?></a>
    <?php endif; ?>
</form>

<!-- 批量操作栏 -->
<div class="batch-bar" id="batch-bar">
    <span class="batch-count" id="batch-count"><?php echo e(t('admin_replies_batch_selected', '已选 {n} 项', ['n' => 0])); ?></span>
    <button class="btn btn-sm btn-danger" onclick="batchAction('delete')"><?php echo e(t('admin_replies_batch_delete', '批量删除')); ?></button>
</div>

<div class="card card-clip">
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
                        <a href="<?php echo e(site_url('admin/replies', array_merge(array_diff_key($_GET, ['page' => 1, 'route' => 1]), ['sort' => 'created_at', 'order' => ($sort === 'created_at' && $order === 'asc') ? 'desc' : 'asc']))); ?>" class="sort-link<?php echo $sort === 'created_at' ? ' active ' . $order : ''; ?>"><?php echo e(t('admin_replies_th_time', '时间')); ?></a>
                    </th>
                    <th style="width:100px;"><?php echo e(t('admin_replies_th_actions', '操作')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($replies)): ?>
                    <tr><td colspan="8" class="empty-cell"><?php echo e(t('admin_replies_empty', '暂无回复数据')); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($replies as $reply): ?>
                        <tr>
                            <td><input type="checkbox" class="reply-check" value="<?php echo $reply['id']; ?>" onchange="updateBatchBar()"></td>
                            <td><code class="text-xs">#<?php echo $reply['id']; ?></code></td>
                            <td><span class="floor-num">#<?php echo (int)$reply['floor']; ?></span></td>
                            <td>
                                <div class="reply-preview" onclick="viewReplyContent(<?php echo $reply['id']; ?>)" title="<?php echo e(t('admin_replies_click_to_view', '点击查看完整内容')); ?>">
                                    <?php echo e($reply['content']); ?>
                                </div>
                                <?php if ($reply['reply_to']): ?>
                                    <span class="reply-ref">↳ <?php echo e(t('admin_replies_reply_to_ref', '回复 #{n}', ['n' => $reply['reply_to']])); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?php echo site_url('profile', ['user_id' => (int)$reply['user_id']]); ?>" class="text-sm"><?php echo e($reply['username']); ?></a></td>
                            <td>
                                <a href="<?php echo site_url('post', ['id' => (int)$reply['post_id']]); ?>" target="_blank" class="text-xs">
                                    <?php $ptitle = e($reply['post_title']); ?>
                                    <?php echo mb_strlen($ptitle, 'UTF-8') > 22 ? mb_substr($ptitle, 0, 22, 'UTF-8') . '…' : $ptitle; ?>
                                </a>
                            </td>
                            <td style="font-size:0.8rem;color:var(--text-muted);white-space:nowrap;"><?php echo date('Y-m-d H:i', strtotime($reply['created_at'])); ?></td>
                            <td>
                                <div class="actions">
                                    <a href="<?php echo site_url('post', ['id' => (int)$reply['post_id']]); ?>#reply-<?php echo (int)$reply['id']; ?>" target="_blank" class="btn-icon" title="<?php echo e(t('admin_replies_action_view_in_post', '在原帖查看')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></a>
                                    <button class="btn-icon" onclick="viewReplyContent(<?php echo $reply['id']; ?>)" title="<?php echo e(t('admin_replies_action_preview', '预览内容')); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></button>
                                    <?php echo admin_action_form(site_url('admin/replies'), 'delete', ['reply_id' => (int)$reply['id']], '', ['class' => 'btn-icon danger', 'confirm' => t('admin_replies_confirm_delete', '确定删除该回复吗？此操作不可撤销。'), 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg>', 'title' => t('admin_replies_action_delete', '删除')]); ?>
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

        var replyPreviewFd = new FormData();
        replyPreviewFd.append('action', 'get_content');
        replyPreviewFd.append('reply_id', replyId);
        replyPreviewFd.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);
        fetch('<?php echo site_url('admin/api/replies_ajax'); ?>', { method: 'POST', body: replyPreviewFd, credentials: 'same-origin' })
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