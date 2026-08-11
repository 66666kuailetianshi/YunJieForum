<?php
/**
 * 云界论坛 - 管理后台申诉 AJAX 接口（封禁申诉 + 禁言申诉）
 *
 * 返回统计 + 列表数据，供前端轮询实时刷新。
 *
 * 参数：
 *   - status: 状态筛选（all/pending/approved/rejected，默认 all）
 *   - type:   申诉类型筛选（all/ban/mute，默认 all）
 *   - limit:  每页条数（默认 20）
 *   - page:   页码（默认 1）
 */

require_once APP_ROOT . 'app/includes/functions.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 细粒度门禁：申诉管理需 manage_ban_appeals 权限（超管天然通过）
if (!has_permission('manage_ban_appeals')) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$db = get_db();

$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['', 'pending', 'approved', 'rejected'], true)) {
    $statusFilter = '';
}

$typeFilter = $_GET['type'] ?? '';
if (!in_array($typeFilter, ['', 'ban', 'mute'], true)) {
    $typeFilter = '';
}

$limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// 1 秒服务端缓存：合并并发轮询，避免每次请求执行多条 SQL（防阻塞）
$data = realtime_cache('ban_appeals_ajax_' . $statusFilter . '_' . $typeFilter . '_' . $page . '_' . $limit, 1, function () use ($db, $statusFilter, $typeFilter, $limit, $page, $offset) {

// 统计
$stats = [
    'total'    => (int)$db->query("SELECT COUNT(*) FROM ban_appeals")->fetchColumn(),
    'pending'  => (int)$db->query("SELECT COUNT(*) FROM ban_appeals WHERE status = 'pending'")->fetchColumn(),
    'approved' => (int)$db->query("SELECT COUNT(*) FROM ban_appeals WHERE status = 'approved'")->fetchColumn(),
    'rejected' => (int)$db->query("SELECT COUNT(*) FROM ban_appeals WHERE status = 'rejected'")->fetchColumn(),
    'ban'      => (int)$db->query("SELECT COUNT(*) FROM ban_appeals WHERE appeal_type = 'ban'")->fetchColumn(),
    'mute'     => (int)$db->query("SELECT COUNT(*) FROM ban_appeals WHERE appeal_type = 'mute'")->fetchColumn(),
];

// 列表
$conds = [];
$params = [];
if ($statusFilter !== '') {
    $conds[] = 'status = :status';
    $params[':status'] = $statusFilter;
}
if ($typeFilter !== '') {
    $conds[] = 'appeal_type = :atype';
    $params[':atype'] = $typeFilter;
}
$where = !empty($conds) ? 'WHERE ' . implode(' AND ', $conds) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM ban_appeals $where");
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare("SELECT * FROM ban_appeals $where ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$appeals = $stmt->fetchAll();

// 格式化时间
foreach ($appeals as &$a) {
    $a['created_at_fmt'] = db_datetime($a['created_at']);
    $a['handled_at_fmt'] = !empty($a['handled_at']) ? db_datetime($a['handled_at']) : '';
    $a['appeal_type'] = $a['appeal_type'] ?? 'ban';
}
unset($a);

return [
    'stats'   => $stats,
    'total'   => $total,
    'page'    => $page,
    'perPage' => $limit,
    'appeals' => $appeals,
];
});

if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['error' => t('admin_ajax_gen_failed', '数据生成失败')], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);
