<?php
/**
 * 云界论坛 - 管理后台全局待处理计数 AJAX 接口
 *
 * 返回所有需要 badge 的 pending 计数，供侧栏菜单实时更新。
 */

require_once APP_ROOT . 'app/includes/functions.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 1 秒服务端缓存：合并多管理员/多标签页的并发轮询，避免每次请求都查 3 张表（防阻塞）
$data = realtime_cache('admin_pending_counts', 1, function () {
    return [
        'reports'        => get_pending_report_count(),
        'ban_appeals'    => get_pending_ban_appeal_count(),
        'password_reset' => get_pending_password_reset_count(),
    ];
});

if (!is_array($data)) {
    $data = [
        'reports'        => get_pending_report_count(),
        'ban_appeals'    => get_pending_ban_appeal_count(),
        'password_reset' => get_pending_password_reset_count(),
    ];
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);
