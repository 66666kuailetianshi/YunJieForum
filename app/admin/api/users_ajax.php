<?php
/**
 * 云界论坛 - 管理后台用户列表 AJAX 接口
 *
 * 返回用户列表 JSON，供前端轮询实时刷新。
 * 自动处理封禁/禁言到期状态：到期则更新为 active。
 *
 * 参数：
 *   - search: 搜索关键词（用户名/邮箱/UID）
 *   - page:   页码（默认 1）
 *   - limit:  每页条数（默认 15）
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once dirname(__DIR__) . '/layout/admin-helpers.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 细粒度门禁：用户列表属用户管理，需 manage_user_dispose 权限（超管天然通过）
if (!has_permission('manage_user_dispose')) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$db = get_db();

// 注：自动解封/解除禁言已由 get_db() 中的 auto_expire_user_status() 统一处理
// 此处无需重复执行

// 1 秒服务端缓存：合并并发轮询，避免每次请求执行 3 条 SQL + 逐行格式化（防阻塞）
$cacheKey = 'users_ajax_' . md5((string)($_GET['search'] ?? '')) . '_' . ((string)($_GET['status'] ?? '')) . '_'
    . ((string)($_GET['role'] ?? '')) . '_' . ((string)($_GET['group'] ?? '')) . '_'
    . ((string)($_GET['date_from'] ?? '')) . '_' . ((string)($_GET['date_to'] ?? '')) . '_'
    . ((string)($_GET['sort'] ?? '')) . '_' . ((string)($_GET['dir'] ?? '')) . '_'
    . ((int)($_GET['page'] ?? 1)) . '_' . ((int)($_GET['limit'] ?? 15));
$data = realtime_cache($cacheKey, 1, function () use ($db) {

// 搜索 + 状态筛选 + 角色 + 用户组 + 注册时间 + 排序
$search = trim($_GET['search'] ?? '');
$allowedStatus = ['active' => 1, 'muted' => 1, 'banned' => 1];
$filterStatus = $_GET['status'] ?? '';
if (!isset($allowedStatus[$filterStatus])) {
    $filterStatus = '';
}
$filterRole = $_GET['role'] ?? '';
// 角色筛选（两级管理员体系分级白名单，防注入）
if (!in_array($filterRole, ['super_admin', 'community_admin', 'user'], true)) { $filterRole = ''; }

$filterGroup = trim($_GET['group'] ?? '');
$groupRange = null;
if ($filterGroup !== '') {
    try {
        $g = $db->prepare("SELECT name, min_points, max_points FROM user_groups WHERE name = :n LIMIT 1");
        $g->execute([':n' => $filterGroup]);
        $groupRange = $g->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
    if (!$groupRange) $filterGroup = '';
}

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo = '';

$sortField = trim($_GET['sort'] ?? '');
$sortDir   = strtolower(trim($_GET['dir'] ?? 'desc'));
$allowedSort = [
    'uid'         => 'u.uid',
    'username'    => 'u.username',
    'points'      => 'u.points',
    'post_count'  => 'post_count',
    'reply_count' => 'reply_count',
    'created_at'  => 'u.created_at',
    'last_active' => 'u.last_active',
];
if (!isset($allowedSort[$sortField])) { $sortField = ''; $sortDir = 'desc'; }
if ($sortDir !== 'asc' && $sortDir !== 'desc') { $sortDir = 'desc'; }

$conditions = [];
$params = [];
if ($search !== '') {
    // 隐私控制：邮箱仅超管可搜索（社区管理员看不到用户邮箱，避免按邮箱旁路检索）
    $searchEmailOk = is_super_admin();
    if (ctype_digit($search)) {
        $conditions[] = $searchEmailOk
            ? "(u.username LIKE :search1 OR u.email LIKE :search2 OR u.uid = :uidExact)"
            : "(u.username LIKE :search1 OR u.uid = :uidExact)";
        $params[':uidExact'] = (int)$search;
    } else {
        $conditions[] = $searchEmailOk
            ? "(u.username LIKE :search1 OR u.email LIKE :search2)"
            : "(u.username LIKE :search1)";
    }
    $params[':search1'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
}
if ($filterStatus !== '') {
    $conditions[] = "u.status = :status";
    $params[':status'] = $filterStatus;
}
if ($filterRole !== '') {
    $roleCond = admin_role_filter_sql($filterRole);
    if ($roleCond !== '') {
        $conditions[] = $roleCond;
    }
}
if ($groupRange !== null) {
    $conditions[] = "u.points >= :gMin";
    $params[':gMin'] = (int)$groupRange['min_points'];
    if ($groupRange['max_points'] !== null && $groupRange['max_points'] !== '') {
        $conditions[] = "u.points <= :gMax";
        $params[':gMax'] = (int)$groupRange['max_points'];
    }
}
if ($dateFrom !== '') {
    $conditions[] = "DATE(u.created_at) >= :dateFrom";
    $params[':dateFrom'] = $dateFrom;
}
if ($dateTo !== '') {
    $conditions[] = "DATE(u.created_at) <= :dateTo";
    $params[':dateTo'] = $dateTo;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$orderBy = $sortField !== '' ? "ORDER BY " . $allowedSort[$sortField] . " " . $sortDir : "ORDER BY u.created_at DESC";

$limit = min(100, max(1, (int)($_GET['limit'] ?? 15)));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// 总数
$countStmt = $db->prepare("SELECT COUNT(*) FROM users u $where");
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();

// 列表
$stmt = $db->prepare("
    SELECT u.*,
           (SELECT COUNT(*) FROM posts WHERE user_id = u.id) AS post_count,
           (SELECT COUNT(*) FROM replies WHERE user_id = u.id) AS reply_count,
           (SELECT COUNT(*) FROM reports r
               WHERE (r.post_id IN (SELECT id FROM posts WHERE user_id = u.id)
                  OR r.reply_id IN (SELECT id FROM replies WHERE user_id = u.id))
                 AND r.status = 'resolved'
           ) AS resolved_report_count,
           (SELECT COUNT(*) FROM reports r
               WHERE (r.post_id IN (SELECT id FROM posts WHERE user_id = u.id)
                  OR r.reply_id IN (SELECT id FROM replies WHERE user_id = u.id))
                 AND r.status = 'pending'
           ) AS pending_report_count,
           (SELECT COUNT(*) FROM sensitive_word_logs WHERE user_id = u.id) AS sensitive_hit_count,
           (SELECT COUNT(*) FROM sensitive_word_logs WHERE user_id = u.id AND action IN ('review','block')) AS sensitive_review_count,
           (SELECT COUNT(*) FROM sensitive_word_logs WHERE user_id = u.id AND action = 'replace') AS sensitive_replace_count,
           (SELECT COUNT(*) FROM ban_appeals WHERE user_id = u.id AND status = 'rejected') AS rejected_appeal_count,
           EXISTS (SELECT 1 FROM user_roles ur JOIN roles rr ON rr.id = ur.role_id
               WHERE ur.user_id = u.id AND rr.name = 'community_admin') AS is_community_admin
    FROM users u
    $where
    $orderBy
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

// 格式化字段
$now = time();
foreach ($users as &$u) {
    // 隐私控制：非超管（社区管理员）默认隐藏邮箱，申请披露经超管审核通过后可见
    $u['email_visible'] = admin_can_view_email((int)$u['id']) ? 1 : 0;
    if (!$u['email_visible']) {
        $u['email'] = '';
    }
    $u['status_fmt'] = format_user_status($u['status']);
    $u['created_at_fmt'] = date('Y-m-d', db_time($u['created_at']));
    $u['last_active_ago'] = !empty($u['last_active']) ? time_ago($u['last_active']) : t('admin_ajax_never', '从未');

    // 风险评分
    $risk = compute_user_risk($u);
    $u['risk_score'] = $risk['score'];
    $u['risk_level'] = $risk['level'];
    $u['risk_label'] = $risk['label'];
    $u['risk_color'] = $risk['color'];

    // 计算剩余时间（服务端权威，不依赖客户端时间）
    $u['remaining_seconds'] = 0;
    $u['remaining_text'] = '';
    if ($u['status'] === 'banned' && !empty($u['banned_until'])) {
        $remaining = db_time($u['banned_until']) - $now;
        $u['remaining_seconds'] = max(0, $remaining);
        $u['remaining_text'] = $remaining > 0 ? format_remaining_time($u['banned_until']) : t('admin_ajax_expired', '已到期');
        $u['until_fmt'] = date('Y-m-d H:i', db_time($u['banned_until']));
    } elseif ($u['status'] === 'muted' && !empty($u['muted_until'])) {
        $remaining = db_time($u['muted_until']) - $now;
        $u['remaining_seconds'] = max(0, $remaining);
        $u['remaining_text'] = $remaining > 0 ? format_remaining_time($u['muted_until']) : t('admin_ajax_expired', '已到期');
        $u['until_fmt'] = date('Y-m-d H:i', db_time($u['muted_until']));
    }

    // 用户组
    $group = get_user_group((int)$u['points']);
    $u['group_title'] = $group['title'];
    $u['group_color'] = $group['color'];
    $u['group_icon'] = $group['icon'];

    // 头像
    $u['avatar_url'] = avatar_url($u['avatar'], $u['username']);

    // 登录锁定状态（列缺失时恒为 0，供列表「解锁登录」按钮显示判断）
    $u['login_locked'] = !empty($u['login_locked_until']) && db_time($u['login_locked_until']) > $now ? 1 : 0;

    // IP 定位：最后活跃 IP / 注册 IP + 实时归属地（未安装 IP 库时归属地为空）
    $u['ip_last'] = (string)($u['last_ip'] ?? '');
    $u['ip_last_region'] = $u['ip_last'] !== '' ? ip_region_display(ip_region_query($u['ip_last'])) : '';
    $u['ip_register'] = (string)($u['register_ip'] ?? '');
    $u['ip_register_region'] = $u['ip_register'] !== '' ? ip_region_display(ip_region_query($u['ip_register'])) : '';
}
unset($u);

return [
    'total'   => $total,
    'page'    => $page,
    'perPage' => $limit,
    'users'   => $users,
    'now'     => $now * 1000,
];
});

if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['error' => t('admin_ajax_gen_failed', '数据生成失败')], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);
