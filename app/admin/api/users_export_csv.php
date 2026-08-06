<?php
/**
 * 云界论坛 - 管理后台用户列表导出 CSV
 *
 * 按当前筛选条件导出用户列表为 CSV（UTF-8 BOM，避免 Excel 中文乱码）。
 * 仅管理员可用。文件名含时间戳。
 */

require_once APP_ROOT . 'app/includes/functions.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo t('admin_ajax_forbidden', '无权访问');
    exit;
}

$db = get_db();

// —— 复用与 users.php 一致的筛选条件 ——
$search = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? '';
if (!in_array($filterStatus, ['active', 'muted', 'banned'], true)) $filterStatus = '';
$filterRole = $_GET['role'] ?? '';
$allowedRoles = [];
try {
    $roleRows = $db->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL AND role <> ''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($roleRows as $r) { $allowedRoles[$r] = 1; }
} catch (\Throwable $e) {}
if (!isset($allowedRoles[$filterRole])) $filterRole = '';
$filterGroup = trim($_GET['group'] ?? '');
$groupRange = null;
if ($filterGroup !== '') {
    try {
        $g = $db->prepare("SELECT name, min_points, max_points FROM user_groups WHERE name=:n LIMIT 1");
        $g->execute([':n' => $filterGroup]);
        $groupRange = $g->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
    if (!$groupRange) $filterGroup = '';
}
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';

$conditions = [];
$params = [];
if ($search !== '') {
    if (ctype_digit($search)) {
        $conditions[] = "(u.username LIKE :s1 OR u.email LIKE :s2 OR u.uid = :uidExact)";
        $params[':uidExact'] = (int)$search;
    } else {
        $conditions[] = "(u.username LIKE :s1 OR u.email LIKE :s2)";
    }
    $params[':s1'] = '%' . $search . '%';
    $params[':s2'] = '%' . $search . '%';
}
if ($filterStatus !== '') { $conditions[] = "u.status = :status"; $params[':status'] = $filterStatus; }
if ($filterRole !== '') { $conditions[] = "u.role = :role"; $params[':role'] = $filterRole; }
if ($groupRange !== null) {
    $conditions[] = "u.points >= :gMin";
    $params[':gMin'] = (int)$groupRange['min_points'];
    if ($groupRange['max_points'] !== null && $groupRange['max_points'] !== '') {
        $conditions[] = "u.points <= :gMax";
        $params[':gMax'] = (int)$groupRange['max_points'];
    }
}
if ($dateFrom !== '') { $conditions[] = "DATE(u.created_at) >= :df"; $params[':df'] = $dateFrom; }
if ($dateTo !== '') { $conditions[] = "DATE(u.created_at) <= :dt"; $params[':dt'] = $dateTo; }
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// 导出所有匹配行（不分页），按需限制上限防止内存爆炸
$limit = 50000;
$stmt = $db->prepare("
    SELECT u.id, u.uid, u.username, u.email, u.role, u.status, u.points, u.coins,
           (SELECT COUNT(*) FROM posts WHERE user_id=u.id) AS post_count,
           (SELECT COUNT(*) FROM replies WHERE user_id=u.id) AS reply_count,
           u.last_active, u.created_at
    FROM users u
    $where
    ORDER BY u.created_at DESC
    LIMIT :lim
");
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 输出 CSV
$filename = 'users_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'w');
// UTF-8 BOM
fwrite($out, "\xEF\xBB\xBF");

$headers = ['UID', t('admin_ajax_csv_username', '用户名'), t('admin_ajax_csv_email', '邮箱'), t('admin_ajax_csv_role', '角色'), t('admin_ajax_csv_status', '状态'), t('admin_ajax_csv_points', '积分'), t('admin_ajax_csv_coins', '金币'), t('admin_ajax_csv_posts', '帖子数'), t('admin_ajax_csv_replies', '回复数'), t('admin_ajax_csv_last_active', '最后活跃'), t('admin_ajax_csv_registered_at', '注册时间')];
fputcsv($out, $headers);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['uid'] !== null ? $r['uid'] : '',
        $r['username'],
        $r['email'],
        $r['role'],
        format_user_status($r['status']),
        (int)$r['points'],
        (int)$r['coins'],
        (int)$r['post_count'],
        (int)$r['reply_count'],
        !empty($r['last_active']) ? date('Y-m-d H:i', db_time($r['last_active'])) : t('admin_ajax_never', '从未'),
        date('Y-m-d H:i', db_time($r['created_at'])),
    ]);
}
fclose($out);
exit;
