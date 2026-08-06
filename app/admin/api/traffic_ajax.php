<?php
/**
 * 云界论坛 - 管理后台流量监测 AJAX 接口
 * 提供实时流量数据，每 5 秒轮询一次
 * 
 * 性能优化：查询从 ~17 次合并到 ~5 次，总量级数据使用 30s 文件缓存
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/db.php';

// 清理所有已有的输出缓冲区，然后开启新的缓冲区
// 确保任何 PHP 警告/通知不会污染 JSON 响应
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

if (!is_logged_in() || !is_admin()) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(8);
header('Content-Type: application/json; charset=utf-8');

try {
$db = get_db();
$driver = get_db_driver();
$today = date('Y-m-d');
$currentHour = (int)date('G');

// ===================== 总量级缓存（30 秒 TTL，避免每次全表扫描）=====================
$totalStats = traffic_total_cache($db);

// ===================== 查询 1：今日 + 昨日统计 + 在线访客（合并 2→1 查询）=====================
$yesterday = date('Y-m-d', strtotime('yesterday'));
$summaryRow = $db->query(
    "SELECT 
        COALESCE(SUM(CASE WHEN stat_date = '$today'     THEN page_views END), 0) AS today_pv,
        COALESCE(SUM(CASE WHEN stat_date = '$today'     THEN unique_visitors END), 0) AS today_uv,
        COALESCE(SUM(CASE WHEN stat_date = '$yesterday' THEN page_views END), 0) AS yesterday_pv,
        COALESCE(SUM(CASE WHEN stat_date = '$yesterday' THEN unique_visitors END), 0) AS yesterday_uv
    FROM traffic_stats WHERE stat_date IN ('$today', '$yesterday')"
)->fetch();

$todayPv      = (int)$summaryRow['today_pv'];
$todayUv      = (int)$summaryRow['today_uv'];
$yesterdayPv  = (int)$summaryRow['yesterday_pv'];
$yesterdayUv  = (int)$summaryRow['yesterday_uv'];

// 在线访客 + 当前小时 PV：使用驱动抽象方法保证跨数据库兼容
$onlineCount = (int)$db->query(
    "SELECT COUNT(*) FROM traffic_visitors WHERE last_visit >= {$driver->minutesAgo(5)}"
)->fetchColumn();
$currentHourPv = (int)$db->query(
    "SELECT COALESCE(page_views, 0) FROM traffic_stats WHERE stat_date = '$today' AND stat_hour = $currentHour"
)->fetchColumn();

// ===================== 查询 2：24 小时分布（今日 + 昨日，合并 1 查询）=====================
$allHourlyRows = $db->query(
    "SELECT stat_date, stat_hour, page_views, unique_visitors 
     FROM traffic_stats 
     WHERE stat_date IN ('$today', '$yesterday') 
     ORDER BY stat_date ASC, stat_hour ASC"
)->fetchAll();

$hourlyPv = array_fill(0, 24, 0);
$hourlyUv = array_fill(0, 24, 0);
$yesterdayHourlyPv = array_fill(0, 24, 0);

foreach ($allHourlyRows as $row) {
    $h = (int)$row['stat_hour'];
    if ($row['stat_date'] === $today) {
        $hourlyPv[$h] = (int)$row['page_views'];
        $hourlyUv[$h] = (int)$row['unique_visitors'];
    } elseif ($row['stat_date'] === $yesterday) {
        $yesterdayHourlyPv[$h] = (int)$row['page_views'];
    }
}

// ===================== 查询 3：今日访客批量统计（合并来源+UA+设备+热门页面 → 1 查询）=====================
$allVisitors = $db->query(
    "SELECT page, referrer, device_type, user_agent, views 
     FROM traffic_visitors 
     WHERE visit_date = '$today'"
)->fetchAll();

// --- 热门页面 TOP10 ---
$pageViews = [];
foreach ($allVisitors as $v) {
    $page = $v['page'];
    if (!isset($pageViews[$page])) $pageViews[$page] = 0;
    $pageViews[$page] += (int)$v['views'];
}
arsort($pageViews);
$hotPagesList = [];
$totalTodayViews = array_sum($pageViews);
$rank = 0;
foreach ($pageViews as $page => $views) {
    if ($rank++ >= 10) break;
    $hotPagesList[] = [
        'page'    => $page,
        'views'   => $views,
        'percent' => $totalTodayViews > 0 ? round($views / $totalTodayViews * 100, 1) : 0,
    ];
}

// --- 来源分布 ---
$referrerMap = [];
$directCount = 0;
foreach ($allVisitors as $v) {
    $ref = $v['referrer'];
    if ($ref === '' || $ref === null) {
        $directCount++;
    } else {
        if (!isset($referrerMap[$ref])) $referrerMap[$ref] = 0;
        $referrerMap[$ref]++;
    }
}
arsort($referrerMap);
$referrers = [];
    if ($directCount > 0) {
        $referrers[] = ['source' => t('admin_ajax_direct_visit', '直接访问'), 'count' => $directCount];
    }
$refRank = 0;
foreach ($referrerMap as $source => $count) {
    if ($refRank++ >= 10) break;
    $referrers[] = ['source' => $source, 'count' => $count];
}

// --- 设备分布 ---
$deviceMap = [];
foreach ($allVisitors as $v) {
    $dt = $v['device_type'] ?: 'unknown';
    if (!isset($deviceMap[$dt])) $deviceMap[$dt] = ['visitors' => 0, 'views' => 0];
    $deviceMap[$dt]['visitors']++;
    $deviceMap[$dt]['views'] += (int)$v['views'];
}
$deviceLabels = ['desktop' => t('admin_ajax_device_desktop', '桌面端'), 'mobile' => t('admin_ajax_device_mobile', '手机端'), 'tablet' => t('admin_ajax_device_tablet', '平板端')];
$devices = [];
foreach ($deviceMap as $type => $data) {
    $devices[] = [
        'type'     => $type,
        'label'    => $deviceLabels[$type] ?? t('admin_ajax_unknown', '未知'),
        'visitors' => $data['visitors'],
        'views'    => $data['views'],
    ];
}

// --- UA 采样解析（最多 500 条，避免逐条正则全表扫描）---
$uaSample = array_slice($allVisitors, 0, 500);
$browserStats = [];
$osStats = [];
foreach ($uaSample as $v) {
    $ua = $v['user_agent'];
    if ($ua === '' || $ua === null) continue;

    $browser = t('admin_ajax_other_browser', '其他');
    if (strpos($ua, 'Edg/') !== false) $browser = 'Edge';
    elseif (strpos($ua, 'Chrome/') !== false) $browser = 'Chrome';
    elseif (strpos($ua, 'Firefox/') !== false) $browser = 'Firefox';
    elseif (strpos($ua, 'Safari/') !== false && strpos($ua, 'Chrome') === false) $browser = 'Safari';
    elseif (preg_match('/MSIE|Trident/', $ua)) $browser = 'IE';
    elseif (preg_match('/Opera|OPR\//', $ua)) $browser = 'Opera';
    $browserStats[$browser] = ($browserStats[$browser] ?? 0) + 1;

    // OS 检测
    $os = t('admin_ajax_other_os', '其他');
    if (strpos($ua, 'Windows NT 10.0') !== false) $os = t('admin_ajax_os_win10', 'Windows 10/11');
    elseif (strpos($ua, 'Windows NT 6.') !== false) $os = t('admin_ajax_os_win7', 'Windows 7/8');
    elseif (strpos($ua, 'Windows') !== false) $os = t('admin_ajax_os_windows', 'Windows');
    elseif (strpos($ua, 'Mac OS X') !== false) $os = 'macOS';
    elseif (strpos($ua, 'Linux') !== false && strpos($ua, 'Android') === false) $os = 'Linux';
    elseif (strpos($ua, 'Android') !== false) $os = 'Android';
    elseif (preg_match('/iPhone|iPad|iPod/', $ua)) $os = 'iOS';
    $osStats[$os] = ($osStats[$os] ?? 0) + 1;
}
arsort($browserStats);
arsort($osStats);
$browsers = [];
foreach ($browserStats as $name => $count) $browsers[] = ['name' => $name, 'count' => $count];
$osList = [];
foreach ($osStats as $name => $count) $osList[] = ['name' => $name, 'count' => $count];

// ===================== 查询 4：近 7 天趋势 =====================
$sevenDaysAgo = date('Y-m-d', strtotime('6 days ago'));
$dailyRows = $db->query(
    "SELECT stat_date, SUM(page_views) as pv, SUM(unique_visitors) as uv 
     FROM traffic_stats WHERE stat_date >= '$sevenDaysAgo' 
     GROUP BY stat_date ORDER BY stat_date ASC"
)->fetchAll();
$daily = [];
foreach ($dailyRows as $row) {
    $daily[] = ['date' => $row['stat_date'], 'pv' => (int)$row['pv'], 'uv' => (int)$row['uv']];
}

// ===================== 查询 5：最近访客（利用 last_visit 索引，仅扫描近期数据）=====================
$recentVisitors = $db->query(
    "SELECT ip_hash, user_agent, page, device_type, first_visit, last_visit, views 
     FROM traffic_visitors 
     WHERE last_visit >= {$driver->daysAgo(1)}
     ORDER BY last_visit DESC LIMIT 20"
)->fetchAll();
$recentList = [];
foreach ($recentVisitors as $row) {
    $ua = $row['user_agent'];
    $ipShort = substr($row['ip_hash'], 0, 8);

    $browser = t('admin_ajax_unknown_short', '未知');
    if (strpos($ua, 'Edg/') !== false) $browser = 'Edge';
    elseif (strpos($ua, 'Chrome/') !== false) $browser = 'Chrome';
    elseif (strpos($ua, 'Firefox/') !== false) $browser = 'Firefox';
    elseif (strpos($ua, 'Safari/') !== false && strpos($ua, 'Chrome') === false) $browser = 'Safari';

    $os = t('admin_ajax_unknown_short', '未知');
    if (strpos($ua, 'Windows NT 10') !== false) $os = 'Win10/11';
    elseif (strpos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (strpos($ua, 'Mac OS X') !== false) $os = 'macOS';
    elseif (strpos($ua, 'Linux') !== false && strpos($ua, 'Android') === false) $os = 'Linux';
    elseif (strpos($ua, 'Android') !== false) $os = 'Android';
    elseif (preg_match('/iPhone|iPad/', $ua)) $os = 'iOS';

    $dtLabels = ['mobile' => t('admin_ajax_dev_mobile', '手机'), 'tablet' => t('admin_ajax_dev_tablet', '平板'), 'desktop' => t('admin_ajax_dev_desktop', '电脑')];
    $dt = $row['device_type'] ?? 'unknown';
    $deviceLabel = $dtLabels[$dt] ?? t('admin_ajax_unknown_short', '未知');

    $recentList[] = [
        'ip_short'   => $ipShort,
        'browser'    => $browser,
        'os'         => $os,
        'device'     => $deviceLabel,
        'page'       => $row['page'],
        'last_visit' => db_datetime($row['last_visit']),
        'views'      => (int)$row['views'],
    ];
}

// ===================== 辅助函数 =====================

// 在输出 JSON 前检查缓冲区中是否有 PHP 警告，记录后清掉
$unexpectedOutput = ob_get_contents();
if ($unexpectedOutput !== '' && $unexpectedOutput !== false) {
    ob_clean();
    error_log(t('admin_api_traffic_ajax_11cbc5','traffic_ajax 意外输出: ') . $unexpectedOutput);
}

echo json_encode([
    'success'           => true,
    'timestamp'         => time(),
    'today_pv'          => $todayPv,
    'today_uv'          => $todayUv,
    'yesterday_pv'      => $yesterdayPv,
    'yesterday_uv'      => $yesterdayUv,
    'total_pv'          => $totalStats['total_pv'],
    'total_uv'          => $totalStats['total_uv'],
    'online_count'      => $onlineCount,
    'current_hour_pv'   => $currentHourPv,
    'current_hour'      => $currentHour,
    'hourly_pv'          => $hourlyPv,
    'hourly_uv'          => $hourlyUv,
    'yesterday_hourly_pv' => $yesterdayHourlyPv,
    'daily'             => $daily,
    'hot_pages'         => $hotPagesList,
    'referrers'         => $referrers,
    'devices'           => $devices,
    'browsers'          => $browsers,
    'os_list'           => $osList,
    'recent_visitors'   => $recentList,
], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // 记录真实错误到日志（包含输出缓冲区中可能存在的 PHP 警告信息）
    $outputBuffer = ob_get_contents();
    ob_end_clean();
    if ($outputBuffer !== '' && $outputBuffer !== false) {
        error_log(t('admin_api_traffic_ajax_876d59','traffic_ajax 缓冲输出: ') . $outputBuffer);
    }
    error_log(t('admin_api_traffic_ajax_55916f','traffic_ajax 异常: ') . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    error_log(t('admin_api_traffic_ajax_6c265c','traffic_ajax 堆栈: ') . $e->getTraceAsString());
    
    // 清理所有输出缓冲区后返回 JSON 错误
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => t('admin_ajax_server_error_retry', '服务器内部错误，请稍后重试'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 正常结束：将输出缓冲区发送给客户端
ob_end_flush();

// ===================== 辅助函数（文件末尾）=====================

/**
 * 总量级统计数据缓存（30 秒 TTL，避免每次全表扫描 total PV / total UV）
 */
function traffic_total_cache($db): array {
    $cacheFile = APP_ROOT . 'data/cache/traffic_total_stats.json';
    $cacheDir = dirname($cacheFile);
    $now = time();

    // 读缓存
    $cached = null;
    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $cached = json_decode($raw, true);
            if (is_array($cached) && ($cached['expires'] ?? 0) > $now) {
                return $cached;
            }
        }
    }

    // 缓存已过期或不存在，重新查询
    $totalPv = (int)$db->query("SELECT COALESCE(SUM(page_views), 0) FROM traffic_stats")->fetchColumn();
    $totalUv = (int)$db->query("SELECT COUNT(*) FROM traffic_visitors")->fetchColumn();

    $data = [
        'total_pv'  => $totalPv,
        'total_uv'  => $totalUv,
        'expires'   => $now + 30,
    ];

    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    if (is_dir($cacheDir)) @file_put_contents($cacheFile, json_encode($data));

    return $data;
}
