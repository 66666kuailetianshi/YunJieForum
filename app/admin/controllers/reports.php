<?php
/**
 * 云界论坛 - 管理后台举报处理
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$db = get_db();
$action = $_GET['action'] ?? 'list';
$reportId = (int)($_GET['report_id'] ?? 0);

if (in_array($action, ['resolve', 'reject'], true) && $reportId > 0 && validate_csrf()) {
    $adminId = (int)($_SESSION['user_id'] ?? 0);
    $status = $action === 'resolve' ? 'resolved' : 'rejected';
    $note = isset($_POST['admin_note']) ? trim($_POST['admin_note']) : '';
    $handleAction = isset($_POST['handle_action']) ? $_POST['handle_action'] : 'none';

    $reportStmt = $db->prepare("SELECT * FROM reports WHERE id = :id");
    $reportStmt->execute([':id' => $reportId]);
    $reportRow = $reportStmt->fetch();

    if ($reportRow) {
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("UPDATE reports SET status = :status, admin_note = :note, handled_by = :admin_id, handled_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([
                ':status'   => $status,
                ':note'     => $note,
                ':admin_id' => $adminId > 0 ? $adminId : null,
                ':id'       => $reportId,
            ]);

            if ($action === 'resolve' && $handleAction === 'delete') {
                if (!empty($reportRow['reply_id'])) {
                    delete_reply((int)$reportRow['reply_id']);
                } elseif (!empty($reportRow['post_id'])) {
                    delete_post((int)$reportRow['post_id']);
                }
            }

            $db->commit();
            set_flash($action === 'resolve' ? t('admin_reports_flash_resolved', '举报已处理。') : t('admin_reports_flash_rejected', '举报已驳回。'), 'success');
        } catch (Exception $e) {
            $db->rollBack();
            set_flash(t('admin_reports_flash_handle_failed', '处理失败：{error}', ['error' => $e->getMessage()]), 'error');
        }
    } else {
        set_flash(t('admin_reports_flash_not_found', '举报记录不存在。'), 'error');
    }
    redirect('/admin/reports');
}

// CSRF 统一校验：涉及状态变更的 GET 操作必须先通过校验，失败时明确提示（避免静默失败）
if ($action === 'delete' && !validate_csrf()) {
    set_flash(t('admin_reports_flash_csrf_failed', '安全校验失败（链接已过期），请刷新页面后重新操作。'), 'error');
    redirect('/admin/reports');
}

if ($action === 'delete' && $reportId > 0) {
    $db->prepare("DELETE FROM reports WHERE id = :id")->execute([':id' => $reportId]);
    set_flash(t('admin_reports_flash_deleted', '举报记录已删除。'), 'success');
    redirect('/admin/reports');
}

$filterStatus = $_GET['status'] ?? 'pending';
$allowedStatuses = ['all', 'pending', 'resolved', 'rejected'];
if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = 'pending';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = $filterStatus === 'all' ? '' : "WHERE r.status = :status";
$countSql = "SELECT COUNT(*) FROM reports r " . $where;
$listSql = "SELECT r.*,
        reporter.username AS reporter_name,
        author.username AS author_name,
        handler.username AS handler_name,
        p.title AS post_title,
        p.id AS post_id
    FROM reports r
    LEFT JOIN users reporter ON r.reporter_id = reporter.id
    LEFT JOIN replies rep ON r.reply_id = rep.id
    LEFT JOIN users author ON rep.user_id = author.id
    LEFT JOIN users handler ON r.handled_by = handler.id
    LEFT JOIN posts p ON r.post_id = p.id
    " . $where . "
    ORDER BY r.created_at DESC
    LIMIT :limit OFFSET :offset";

if ($filterStatus === 'all') {
    $total = (int)$db->query($countSql)->fetchColumn();
    $stmt = $db->prepare($listSql);
} else {
    $countStmt = $db->prepare($countSql);
    $countStmt->execute([':status' => $filterStatus]);
    $total = (int)$countStmt->fetchColumn();
    $stmt = $db->prepare($listSql);
    $stmt->bindValue(':status', $filterStatus, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$reports = $stmt->fetchAll();

$pageTitle = t('admin_reports_title', '举报管理');
$activeMenu = 'reports';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_reports_title', '举报管理')); ?></h1>
</div>

<!-- 统计卡片 -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-value" id="rpt-stat-total"><?php echo (int)$db->query("SELECT COUNT(*) FROM reports")->fetchColumn(); ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_reports_stat_total', '举报总数')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" id="rpt-stat-pending" style="color:#f59e0b;"><?php echo (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn(); ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_reports_stat_pending', '待处理')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" id="rpt-stat-resolved" style="color:#10b981;"><?php echo (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 'resolved'")->fetchColumn(); ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_reports_stat_resolved', '已处理')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" id="rpt-stat-rejected" style="color:#ef4444;"><?php echo (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 'rejected'")->fetchColumn(); ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_reports_stat_rejected', '已驳回')); ?></div>
    </div>
</div>

<div class="card">
    <div class="filter-tabs">
        <?php foreach ($allowedStatuses as $st): ?>
            <a href="<?php echo site_url('admin/reports', ['status' => $st]); ?>" class="filter-tab <?php echo $filterStatus === $st ? 'active' : ''; ?>">
                <?php echo e(t('admin_reports_tab_' . $st, format_report_tab_label($st))); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?php echo e(t('admin_reports_th_content', '被举报内容')); ?></th>
                    <th><?php echo e(t('admin_reports_th_author', '作者')); ?></th>
                    <th><?php echo e(t('admin_reports_th_reporter', '举报人')); ?></th>
                    <th><?php echo e(t('admin_reports_th_reason', '原因')); ?></th>
                    <th><?php echo e(t('admin_reports_th_status', '状态')); ?></th>
                    <th><?php echo e(t('admin_reports_th_time', '时间')); ?></th>
                    <th><?php echo e(t('admin_reports_th_actions', '操作')); ?></th>
                </tr>
            </thead>
            <tbody id="rpt-tbody">
                <?php if (empty($reports)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted"><?php echo e(t('admin_reports_empty', '暂无举报记录')); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><?php echo $report['id']; ?></td>
                            <td>
                                <?php if (!empty($report['post_id'])): ?>
                                    <a href="<?php echo site_url('post', ['id' => (int)$report['post_id']]); ?>" target="_blank">
                                        <?php echo e(mb_substr($report['post_title'] ?? t('admin_reports_unknown_post', '未知帖子'), 0, 30, 'UTF-8')); ?>
                                    </a>
                                    <?php if (!empty($report['reply_id'])): ?>
                                        <span class="text-muted"><?php echo e(t('admin_reports_reply_ref', '#回复{n}', ['n' => (int)$report['reply_id']])); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted"><?php echo e(t('admin_reports_reply_ref', '#回复{n}', ['n' => (int)$report['reply_id']])); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e($report['author_name'] ?? t('admin_reports_unknown', '未知')); ?></td>
                            <td><?php echo e($report['reporter_name'] ?? t('admin_reports_unknown', '未知')); ?></td>
                            <td>
                                <span class="badge badge-info"><?php echo e(t('admin_reports_reason_' . $report['reason_type'], format_report_reason($report['reason_type']))); ?></span>
                                <?php if (!empty($report['reason'])): ?>
                                    <div class="text-muted text-sm mt-1"><?php echo e(mb_substr($report['reason'], 0, 40, 'UTF-8')); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($report['status'] === 'pending'): ?>
                                    <span class="badge badge-warning"><?php echo e(t('admin_reports_status_' . $report['status'], format_report_status($report['status']))); ?></span>
                                <?php elseif ($report['status'] === 'resolved'): ?>
                                    <span class="badge badge-success"><?php echo e(t('admin_reports_status_' . $report['status'], format_report_status($report['status']))); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?php echo e(t('admin_reports_status_' . $report['status'], format_report_status($report['status']))); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo time_ago($report['created_at']); ?></td>
                            <td>
                                <?php if ($report['status'] === 'pending'): ?>
                                    <form method="POST" action="<?php echo site_url('admin/reports', ['action' => 'resolve', 'report_id' => (int)$report['id'], 'csrf_token' => csrf_token()]); ?>" style="display:inline-block;margin-bottom:0.25rem;">
                                        <select name="handle_action" class="form-control form-control-sm" style="width:150px;display:inline-block;margin-right:0.25rem;" title="<?php echo e(t('admin_reports_handle_action', '处理动作')); ?>">
                                            <option value="none"><?php echo e(t('admin_reports_handle_mark_only', '仅标记已处理')); ?></option>
                                            <option value="delete"><?php echo e(t('admin_reports_handle_delete_content', '删除被举报内容')); ?></option>
                                        </select>
                                        <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="<?php echo e(t('admin_reports_note_placeholder', '处理备注（可选）')); ?>" style="width:140px;display:inline-block;margin-right:0.25rem;">
                                        <button type="submit" class="btn btn-sm btn-success"><?php echo e(t('admin_reports_btn_resolve', '处理')); ?></button>
                                    </form>
                                    <form method="POST" action="<?php echo site_url('admin/reports', ['action' => 'reject', 'report_id' => (int)$report['id'], 'csrf_token' => csrf_token()]); ?>" style="display:inline-block;">
                                        <button type="submit" class="btn btn-sm btn-secondary"><?php echo e(t('admin_reports_btn_reject', '驳回')); ?></button>
                                    </form>
                                <?php else: ?>
                                    <div class="text-muted text-sm">
                                        <?php if (!empty($report['handler_name'])): ?>
                                            <?php echo e(t('admin_reports_handled_by', '{name} 于 {time}', ['name' => $report['handler_name'], 'time' => date('Y-m-d H:i', db_time($report['handled_at'] ?? 'now'))])); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($report['admin_note'])): ?>
                                            <div><?php echo e(t('admin_reports_note_prefix', '备注：')); ?><?php echo e($report['admin_note']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <a href="<?php echo site_url('admin/reports', ['action' => 'delete', 'report_id' => (int)$report['id'], 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-danger" data-confirm="<?php echo e(t('admin_reports_confirm_delete', '确定删除该举报记录吗？')); ?>"><?php echo e(t('admin_reports_btn_delete', '删除')); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo pagination($page, $total, $perPage, site_url('admin/reports', ['status' => $filterStatus])); ?>
</div>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>

<script>
(function () {
    'use strict';
    var tbody = document.getElementById('rpt-tbody');
    if (!tbody) return;

    var currentStatus = '<?php echo $filterStatus; ?>';
    var currentPage = <?php echo $page; ?>;
    var perPage = <?php echo $perPage; ?>;
    var ajaxUrl = '<?php echo site_url('admin/api/reports_ajax'); ?>';
    var INTERVAL = 1000; // 1 秒（reports_ajax 服务端 1 秒缓存合并并发，不阻塞）
    var checking = false; // 请求去重：上一请求未返回时跳过本次轮询，避免堆积
    var csrfToken = '<?php echo csrf_token(); ?>';

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(s);
        return div.innerHTML;
    }

    function pulseStat(el, newVal) {
        if (!el) return;
        var oldVal = parseInt(el.textContent, 10) || 0;
        if (newVal !== oldVal) {
            el.textContent = newVal;
            el.classList.add('pulse');
            setTimeout(function () { el.classList.remove('pulse'); }, 600);
        }
    }

    function renderRow(r) {
        var statusBadge = '';
        if (r.status === 'pending') statusBadge = '<span class="badge badge-warning">' + escapeHtml(r.status_fmt) + '</span>';
        else if (r.status === 'resolved') statusBadge = '<span class="badge badge-success">' + escapeHtml(r.status_fmt) + '</span>';
        else statusBadge = '<span class="badge badge-secondary">' + escapeHtml(r.status_fmt) + '</span>';

        // 被举报内容
        var contentHtml = '';
        if (r.post_id) {
            contentHtml = '<a href="<?php echo site_url('post'); ?>&id=' + r.post_id + '" target="_blank">' + escapeHtml((r.post_title || <?php echo json_encode(t('admin_reports_unknown_post', '未知帖子')); ?>).substring(0, 30)) + '</a>';
            if (r.reply_id) contentHtml += ' <span class="text-muted">' + escapeHtml(<?php echo json_encode(t('admin_reports_reply_ref', '#回复{n}')); ?>.replace('{n}', r.reply_id)) + '</span>';
        } else {
            contentHtml = '<span class="text-muted">' + escapeHtml(<?php echo json_encode(t('admin_reports_reply_ref', '#回复{n}')); ?>.replace('{n}', r.reply_id)) + '</span>';
        }

        // 原因
        var reasonHtml = '<span class="badge badge-info">' + escapeHtml(r.reason_type_fmt) + '</span>';
        if (r.reason) {
            reasonHtml += '<div class="text-muted text-sm mt-1">' + escapeHtml(r.reason.substring(0, 40)) + '</div>';
        }

        // 操作
        var actionHtml = '';
        if (r.status === 'pending') {
            actionHtml = '<form method="POST" action="<?php echo site_url('admin/reports'); ?>&action=resolve&report_id=' + r.id + '&csrf_token=' + csrfToken + '" style="display:inline-block;margin-bottom:0.25rem;">' +
                '<select name="handle_action" class="form-control form-control-sm" style="width:150px;display:inline-block;margin-right:0.25rem;" title="' + escapeHtml(<?php echo json_encode(t('admin_reports_handle_action', '处理动作')); ?>) + '">' +
                '<option value="none">' + escapeHtml(<?php echo json_encode(t('admin_reports_handle_mark_only', '仅标记已处理')); ?>) + '</option>' +
                '<option value="delete">' + escapeHtml(<?php echo json_encode(t('admin_reports_handle_delete_content', '删除被举报内容')); ?>) + '</option>' +
                '</select>' +
                '<input type="text" name="admin_note" class="form-control form-control-sm" placeholder="' + escapeHtml(<?php echo json_encode(t('admin_reports_note_placeholder', '处理备注（可选）')); ?>) + '" style="width:140px;display:inline-block;margin-right:0.25rem;">' +
                '<button type="submit" class="btn btn-sm btn-success">' + escapeHtml(<?php echo json_encode(t('admin_reports_btn_resolve', '处理')); ?>) + '</button>' +
                '</form>' +
                '<form method="POST" action="<?php echo site_url('admin/reports'); ?>&action=reject&report_id=' + r.id + '&csrf_token=' + csrfToken + '" style="display:inline-block;">' +
                '<button type="submit" class="btn btn-sm btn-secondary">' + escapeHtml(<?php echo json_encode(t('admin_reports_btn_reject', '驳回')); ?>) + '</button>' +
                '</form>';
        } else {
            actionHtml = '<div class="text-muted text-sm">';
            if (r.handler_name) {
                actionHtml += escapeHtml(<?php echo json_encode(t('admin_reports_handled_by', '{name} 于 {time}')); ?>.replace('{name}', r.handler_name).replace('{time}', r.handled_at_fmt));
            }
            if (r.admin_note) {
                actionHtml += '<div>' + escapeHtml(<?php echo json_encode(t('admin_reports_note_prefix', '备注：')); ?>) + escapeHtml(r.admin_note) + '</div>';
            }
            actionHtml += '</div>';
            actionHtml += ' <a href="<?php echo site_url('admin/reports'); ?>&action=delete&report_id=' + r.id + '&csrf_token=' + csrfToken + '" class="btn btn-sm btn-danger" data-confirm="' + escapeHtml(<?php echo json_encode(t('admin_reports_confirm_delete', '确定删除该举报记录吗？')); ?>) + '">' + escapeHtml(<?php echo json_encode(t('admin_reports_btn_delete', '删除')); ?>) + '</a>';
        }

        return '<tr>' +
            '<td>' + r.id + '</td>' +
            '<td>' + contentHtml + '</td>' +
            '<td>' + escapeHtml(r.author_name || <?php echo json_encode(t('admin_reports_unknown', '未知')); ?>) + '</td>' +
            '<td>' + escapeHtml(r.reporter_name || <?php echo json_encode(t('admin_reports_unknown', '未知')); ?>) + '</td>' +
            '<td>' + reasonHtml + '</td>' +
            '<td>' + statusBadge + '</td>' +
            '<td>' + escapeHtml(r.created_at_ago) + '</td>' +
            '<td>' + actionHtml + '</td>' +
            '</tr>';
    }

    function refresh() {
        if (checking) return;
        checking = true;
        var params = 'status=' + encodeURIComponent(currentStatus) + '&page=' + currentPage + '&limit=' + perPage + '&_=' + Date.now();
        fetch(ajaxUrl + '&' + params, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) return;

                // 更新统计卡片
                if (data.stats) {
                    pulseStat(document.getElementById('rpt-stat-total'), data.stats.total);
                    pulseStat(document.getElementById('rpt-stat-pending'), data.stats.pending);
                    pulseStat(document.getElementById('rpt-stat-resolved'), data.stats.resolved);
                    pulseStat(document.getElementById('rpt-stat-rejected'), data.stats.rejected);
                }

                // 更新列表
                var reports = data.reports || [];
                if (reports.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">' + escapeHtml(<?php echo json_encode(t('admin_reports_empty', '暂无举报记录')); ?>) + '</td></tr>';
                    return;
                }
                tbody.innerHTML = reports.map(renderRow).join('');
            })
            .catch(function () {})
            .then(function () { checking = false; }); // 无论成败都释放去重锁
    }

    setInterval(refresh, INTERVAL);
})();
</script>
