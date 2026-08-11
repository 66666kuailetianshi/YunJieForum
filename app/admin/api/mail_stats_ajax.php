<?php
/**
 * 云界论坛 - 邮件统计 AJAX 接口
 *
 * 实时返回邮件发送统计数据和最近日志，供邮件中心页面轮询刷新。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 细粒度门禁：邮件统计属邮件中心，仅超级管理员可用
if (!is_super_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('common_super_admin_only', '该功能仅最高管理员可用。')], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = get_db();

$typeLabels = [
    'verify'       => t('admin_ajax_mail_type_verify', '注册验证码'),
    'reset'        => t('admin_ajax_mail_type_reset', '密码重置'),
    'appeal'       => t('admin_ajax_mail_type_appeal', '申诉通知'),
    'ban'          => t('admin_ajax_mail_type_ban', '封禁通知'),
    'test'         => t('admin_ajax_mail_type_test', '测试邮件'),
    'notification' => t('admin_ajax_mail_type_notification', '系统通知'),
    'other'        => t('admin_ajax_mail_type_other', '其他'),
];

// 1 秒服务端缓存：合并并发轮询，避免每次请求执行约 8 条 SQL（防阻塞）
$data = realtime_cache('mail_stats_ajax', 1, function () use ($db, $typeLabels) {

// 统计数据
$stats = [
    'total'         => 0,
    'success'       => 0,
    'failed'        => 0,
    'bounced'       => 0,
    'today'         => 0,
    'today_success' => 0,
    'today_failed'  => 0,
    'success_rate'  => 0,
    'types'         => [],
];

try {
    $stats['total']   = (int)$db->query("SELECT COUNT(*) FROM mail_logs")->fetchColumn();
    $stats['success'] = (int)$db->query("SELECT COUNT(*) FROM mail_logs WHERE status='success'")->fetchColumn();
    $stats['failed']  = (int)$db->query("SELECT COUNT(*) FROM mail_logs WHERE status='failed'")->fetchColumn();
    // 退信数量（被退信处理器更新过的记录）
    $stats['bounced'] = (int)$db->query("SELECT COUNT(*) FROM mail_logs WHERE bounce_status='bounced'")->fetchColumn();
    $stats['success_rate'] = $stats['total'] > 0 ? round($stats['success'] / $stats['total'] * 100, 1) : 0;

    // SQLite CURRENT_TIMESTAMP 存储的是 UTC 时间，使用 gmdate 对齐
    $todayStart = gmdate('Y-m-d 00:00:00');
    $stmt = $db->prepare("SELECT COUNT(*) FROM mail_logs WHERE created_at >= :today");
    $stmt->execute([':today' => $todayStart]);
    $stats['today'] = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM mail_logs WHERE created_at >= :today AND status='success'");
    $stmt->execute([':today' => $todayStart]);
    $stats['today_success'] = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM mail_logs WHERE created_at >= :today AND status='failed'");
    $stmt->execute([':today' => $todayStart]);
    $stats['today_failed'] = (int)$stmt->fetchColumn();

    // 按类型统计
    $typeRows = $db->query("SELECT type, COUNT(*) as cnt, SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as ok FROM mail_logs GROUP BY type ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($typeRows as $row) {
        $typeKey = $row['type'];
        $stats['types'][] = [
            'key'     => $typeKey,
            'label'   => $typeLabels[$typeKey] ?? $typeKey,
            'total'   => (int)$row['cnt'],
            'success' => (int)$row['ok'],
            'failed'  => (int)$row['cnt'] - (int)$row['ok'],
        ];
    }

    // 最近 20 条日志（含退信信息，时间转换为本地时区显示）
    $recentLogs = $db->query("SELECT recipient, recipient_name, subject, type, status, error_message, created_at, bounce_status, bounce_type, bounce_reason, bounce_time FROM mail_logs ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($recentLogs as &$log) {
        $log['time_display'] = date('m-d H:i', db_time($log['created_at']));
        // 退信状态标识
        $log['is_bounced'] = ($log['bounce_status'] ?? 'pending') === 'bounced';
        if (!empty($log['bounce_time'])) {
            $log['bounce_time_display'] = date('m-d H:i', db_time($log['bounce_time']));
        }
    }
} catch (Exception $e) {
    // 表不存在时返回空数据
    $recentLogs = [];
}

return [
    'success'   => true,
    'stats'     => $stats,
    'logs'      => $recentLogs,
    'type_labels' => $typeLabels,
    'timestamp' => time(),
];
});

if (!is_array($data)) {
    $data = ['success' => true, 'stats' => null, 'logs' => [], 'type_labels' => $typeLabels, 'timestamp' => time()];
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);
