<?php
/**
 * 云界论坛 - 管理后台举报管理 AJAX 接口
 *
 * 返回统计 + 列表数据，供前端轮询实时刷新。
 *
 * 参数：
 *   - status: 状态筛选（all/pending/resolved/rejected，默认 pending）
 *   - limit:  每页条数（默认 15）
 *   - page:   页码（默认 1）
 */

require_once APP_ROOT . 'app/includes/functions.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 细粒度门禁：举报管理需 manage_reports 权限（超管天然通过）
if (!has_permission('manage_reports')) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$db = get_db();

$filterStatus = $_GET['status'] ?? 'pending';
$allowedStatuses = ['all', 'pending', 'resolved', 'rejected'];
if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = 'pending';
}

$limit = min(100, max(1, (int)($_GET['limit'] ?? 15)));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// 1 秒服务端缓存：合并并发轮询，避免每次请求执行 6 条 SQL（防阻塞）
$data = realtime_cache('reports_ajax_' . $filterStatus . '_' . $page . '_' . $limit, 1, function () use ($db, $filterStatus, $limit, $page, $offset) {

// 统计：4 项合并为单条 SUM(CASE WHEN)（标准 SQL，SQLite/MySQL/PostgreSQL 通用）
$statsRow = $db->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected
    FROM reports")->fetch();
$stats = [
    'total'    => (int)($statsRow['total'] ?? 0),
    'pending'  => (int)($statsRow['pending'] ?? 0),
    'resolved' => (int)($statsRow['resolved'] ?? 0),
    'rejected' => (int)($statsRow['rejected'] ?? 0),
];

// 列表
$where = $filterStatus === 'all' ? '' : "WHERE r.status = :status";
$params = [];
if ($filterStatus !== 'all') {
    $params[':status'] = $filterStatus;
}

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

$countSql = "SELECT COUNT(*) FROM reports r " . $where;

$countStmt = $db->prepare($countSql);
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare($listSql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$reports = $stmt->fetchAll();

// 格式化字段供前端使用
foreach ($reports as &$r) {
    $r['reason_type_fmt'] = format_report_reason($r['reason_type']);
    $r['status_fmt'] = format_report_status($r['status']);
    $r['created_at_ago'] = time_ago($r['created_at']);
    $r['handled_at_fmt'] = !empty($r['handled_at']) ? date('Y-m-d H:i', db_time($r['handled_at'])) : '';
}
unset($r);

return [
    'stats'   => $stats,
    'total'   => $total,
    'page'    => $page,
    'perPage' => $limit,
    'reports' => $reports,
];
});

if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['error' => t('admin_ajax_gen_failed', '数据生成失败')], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);
