<?php
/**
 * 云界论坛 - 管理后台敏感词命中日志 AJAX 接口
 *
 * 支持实时刷新：前端轮询获取最新日志和统计数据。
 *
 * 参数：
 *   - mode: 'stats' | 'logs' | 'all' | 'status_logs' | 'status_stats'（默认 all）
 *   - last_id: 仅返回 ID 大于此值的日志（增量刷新）
 *   - limit: 日志条数上限（默认 30，最大 100）
 *   - action_filter: 按处理动作筛选（replace/reject/review）
 *   - content_type_filter: 按内容类型筛选
 *   - status_action_filter: 按启用/禁用动作筛选（enable/disable）
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 细粒度门禁：敏感词日志仅超级管理员可用
if (!is_super_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('common_super_admin_only', '该功能仅最高管理员可用。')], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$db = get_db();
$driver = get_db_driver();
$mode = $_GET['mode'] ?? 'all';
$lastId = (int)($_GET['last_id'] ?? 0);
$limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
$actionFilter = $_GET['action_filter'] ?? '';
$contentTypeFilter = $_GET['content_type_filter'] ?? '';
$statusActionFilter = $_GET['status_action_filter'] ?? '';

// 1 秒服务端缓存：合并并发轮询，避免每次请求执行多条聚合 SQL（防阻塞）
$data = realtime_cache('sensitive_logs_' . md5(serialize($_GET)), 1, function () use ($db, $driver, $mode, $lastId, $limit, $actionFilter, $contentTypeFilter, $statusActionFilter) {

$response = [];

/* ======================== 统计数据 ======================== */
$needStats = $mode === 'stats' || $mode === 'all';
if ($needStats) {
    $stats = [
        'total'        => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs")->fetchColumn(),
        'today'        => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs WHERE created_at >= " . $db->quote(gmdate('Y-m-d H:i:s', strtotime('today'))))->fetchColumn(),
        'replace_count'=> (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs WHERE action = 'replace'")->fetchColumn(),
        'reject_count' => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs WHERE action = 'reject'")->fetchColumn(),
        'review_count' => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs WHERE action = 'review'")->fetchColumn(),
    ];

    // 今日 24 小时分布（用于趋势图）— 按本地时区计算
    $todayStartUtc = gmdate('Y-m-d H:i:s', strtotime('today'));
    $groupH = $driver->groupByHour('created_at', 8);
    $hourlyRows = $db->query("SELECT {$groupH} as h, COUNT(*) as c FROM sensitive_word_logs WHERE created_at >= " . $db->quote($todayStartUtc) . " GROUP BY h ORDER BY h ASC")->fetchAll();
    $hourly = array_fill(0, 24, 0);
    foreach ($hourlyRows as $row) {
        $hourly[(int)$row['h']] = (int)$row['c'];
    }
    $stats['hourly'] = $hourly;

    // 近 7 天趋势 — 按本地时区计算
    $sevenDaysAgoUtc = gmdate('Y-m-d H:i:s', strtotime('6 days ago', strtotime('today')));
    $dCol = $driver->dateColExpr('created_at', '+8 hours');
    $dailyRows = $db->query("SELECT {$dCol} as d, COUNT(*) as c FROM sensitive_word_logs WHERE created_at >= " . $db->quote($sevenDaysAgoUtc) . " GROUP BY d ORDER BY d ASC")->fetchAll();
    $daily = [];
    foreach ($dailyRows as $row) {
        $daily[] = ['date' => $row['d'], 'count' => (int)$row['c']];
    }
    $stats['daily'] = $daily;

    // 命中词 TOP 10
    $topRows = $db->query("SELECT matched_word, COUNT(*) AS c FROM sensitive_word_logs GROUP BY matched_word ORDER BY c DESC LIMIT 10")->fetchAll();
    $topWords = [];
    foreach ($topRows as $row) {
        $topWords[] = ['word' => $row['matched_word'], 'count' => (int)$row['c']];
    }
    $stats['top_words'] = $topWords;

    // 按内容类型分布
    $typeRows = $db->query("SELECT content_type, COUNT(*) AS c FROM sensitive_word_logs GROUP BY content_type ORDER BY c DESC")->fetchAll();
    $typeDist = [];
    foreach ($typeRows as $row) {
        $typeDist[] = ['type' => $row['content_type'], 'count' => (int)$row['c']];
    }
    $stats['type_dist'] = $typeDist;

    // 最近活跃用户 TOP 5
    $activeRows = $db->query("SELECT s.user_id, MAX(u.username) AS username, COUNT(*) AS c FROM sensitive_word_logs s LEFT JOIN users u ON s.user_id = u.id GROUP BY s.user_id ORDER BY c DESC LIMIT 5")->fetchAll();
    $activeUsers = [];
    foreach ($activeRows as $row) {
        $activeUsers[] = [
            'username' => $row['username'] ?? (t('admin_ajax_uid_prefix', 'UID:') . $row['user_id']),
            'count' => (int)$row['c'],
        ];
    }
    $stats['active_users'] = $activeUsers;

    $response['stats'] = $stats;
}

/* ======================== 日志列表 ======================== */
$needLogs = $mode === 'logs' || $mode === 'all';
if ($needLogs) {
    $where = [];
    $params = [];
    if ($lastId > 0) {
        $where[] = 's.id > :last_id';
        $params[':last_id'] = $lastId;
    }
    if ($actionFilter !== '' && in_array($actionFilter, ['replace', 'reject', 'review'], true)) {
        $where[] = 's.action = :action';
        $params[':action'] = $actionFilter;
    }
    if ($contentTypeFilter !== '') {
        $where[] = 's.content_type = :ctype';
        $params[':ctype'] = $contentTypeFilter;
    }
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT s.*, u.username FROM sensitive_word_logs s LEFT JOIN users u ON s.user_id = u.id $whereClause ORDER BY s.id DESC LIMIT :limit";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll();

    // 将 UTC 时间转换为本地时区显示
    foreach ($logs as &$log) {
        if (!empty($log['created_at'])) {
            $log['created_at'] = db_datetime($log['created_at']);
        }
    }
    unset($log);

    $response['logs'] = $logs;
    $response['max_id'] = !empty($logs) ? (int)$logs[0]['id'] : $lastId;
}

/* ======================== 启用/禁用操作记录 ======================== */
$needStatusStats = $mode === 'status_stats' || $mode === 'status_logs';
if ($needStatusStats) {
    $statusStats = [
        'total'         => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_status_logs")->fetchColumn(),
        'today'         => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_status_logs WHERE created_at >= " . $db->quote(gmdate('Y-m-d H:i:s', strtotime('today'))))->fetchColumn(),
        'enable_count'  => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_status_logs WHERE action = 'enable'")->fetchColumn(),
        'disable_count' => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_status_logs WHERE action = 'disable'")->fetchColumn(),
    ];

    // 今日 24 小时分布
    $todayStartUtc = gmdate('Y-m-d H:i:s', strtotime('today'));
    $groupH2 = $driver->groupByHour('created_at', 8);
    $hourlyRows = $db->query("SELECT {$groupH2} as h, COUNT(*) as c FROM sensitive_word_status_logs WHERE created_at >= " . $db->quote($todayStartUtc) . " GROUP BY h ORDER BY h ASC")->fetchAll();
    $hourly = array_fill(0, 24, 0);
    foreach ($hourlyRows as $row) {
        $hourly[(int)$row['h']] = (int)$row['c'];
    }
    $statusStats['hourly'] = $hourly;

    // 近 7 天趋势
    $sevenDaysAgoUtc = gmdate('Y-m-d H:i:s', strtotime('6 days ago', strtotime('today')));
    $dCol2 = $driver->dateColExpr('created_at', '+8 hours');
    $dailyRows = $db->query("SELECT {$dCol2} as d, COUNT(*) as c FROM sensitive_word_status_logs WHERE created_at >= " . $db->quote($sevenDaysAgoUtc) . " GROUP BY d ORDER BY d ASC")->fetchAll();
    $daily = [];
    foreach ($dailyRows as $row) {
        $daily[] = ['date' => $row['d'], 'count' => (int)$row['c']];
    }
    $statusStats['daily'] = $daily;

    // 操作词 TOP 10
    $topRows = $db->query("SELECT word, COUNT(*) AS c FROM sensitive_word_status_logs WHERE word <> '' GROUP BY word ORDER BY c DESC LIMIT 10")->fetchAll();
    $topWords = [];
    foreach ($topRows as $row) {
        $topWords[] = ['word' => $row['word'], 'count' => (int)$row['c']];
    }
    $statusStats['top_words'] = $topWords;

    // 来源分布
    $sourceRows = $db->query("SELECT source, COUNT(*) AS c FROM sensitive_word_status_logs GROUP BY source ORDER BY c DESC")->fetchAll();
    $sourceDist = [];
    foreach ($sourceRows as $row) {
        $sourceDist[] = ['source' => $row['source'], 'count' => (int)$row['c']];
    }
    $statusStats['source_dist'] = $sourceDist;

    // 操作者 TOP 5
    $operatorRows = $db->query("SELECT operator_id, MAX(operator_name) AS username, COUNT(*) AS c FROM sensitive_word_status_logs WHERE operator_id > 0 GROUP BY operator_id ORDER BY c DESC LIMIT 5")->fetchAll();
    $activeOperators = [];
    foreach ($operatorRows as $row) {
        $activeOperators[] = [
            'username' => $row['username'] !== '' ? $row['username'] : (t('admin_ajax_uid_prefix', 'UID:') . $row['operator_id']),
            'count' => (int)$row['c'],
        ];
    }
    $statusStats['active_operators'] = $activeOperators;

    $response['status_stats'] = $statusStats;
}

$needStatusLogs = $mode === 'status_logs' || $mode === 'status_stats';
if ($needStatusLogs) {
    $where = [];
    $params = [];
    if ($lastId > 0) {
        $where[] = 'id > :last_id';
        $params[':last_id'] = $lastId;
    }
    if ($statusActionFilter !== '' && in_array($statusActionFilter, ['enable', 'disable'], true)) {
        $where[] = 'action = :action';
        $params[':action'] = $statusActionFilter;
    }
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT * FROM sensitive_word_status_logs $whereClause ORDER BY id DESC LIMIT :limit";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $statusLogs = $stmt->fetchAll();

    foreach ($statusLogs as &$log) {
        if (!empty($log['created_at'])) {
            $log['created_at'] = db_datetime($log['created_at']);
        }
    }
    unset($log);

    $response['status_logs'] = $statusLogs;
    $response['status_max_id'] = !empty($statusLogs) ? (int)$statusLogs[0]['id'] : $lastId;
}

return $response;
});

if (!is_array($data)) {
    $data = [];
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);
