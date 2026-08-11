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

// 按权限按需查询：只返回当前用户有权查看的菜单项对应的计数，
// 无权项不下发（前端侧栏对应菜单已隐藏，badge 元素也不存在）。
$canReports    = has_permission('manage_reports');
$canBanAppeals = has_permission('manage_ban_appeals');
$canPwReset    = is_super_admin();
$canEdr        = is_super_admin(); // 邮箱披露申请仅超管可见
$canTickets    = is_admin();       // 工单系统所有管理员可见

// 1 秒服务端缓存：合并多管理员/多标签页的并发轮询，避免每次请求都查 5 张表（防阻塞）
// 缓存 key 按权限组合区分，避免不同权限管理员互相看到对方的计数
$cacheKey = 'admin_pending_counts_' . ($canReports ? 1 : 0) . ($canBanAppeals ? 1 : 0) . ($canPwReset ? 1 : 0) . ($canEdr ? 1 : 0) . ($canTickets ? 1 : 0);
$data = realtime_cache($cacheKey, 1, function () use ($canReports, $canBanAppeals, $canPwReset, $canEdr, $canTickets) {
    $out = [];
    if ($canReports)    $out['reports']          = get_pending_report_count();
    if ($canBanAppeals) $out['ban_appeals']      = get_pending_ban_appeal_count();
    if ($canPwReset)    $out['password_reset']   = get_pending_password_reset_count();
    if ($canEdr)        $out['email_disclosure'] = get_pending_email_disclosure_count();
    if ($canTickets)    $out['tickets']          = get_open_ticket_count();
    return $out;
});

if (!is_array($data)) {
    $data = [];
    if ($canReports)    $data['reports']          = get_pending_report_count();
    if ($canBanAppeals) $data['ban_appeals']      = get_pending_ban_appeal_count();
    if ($canPwReset)    $data['password_reset']   = get_pending_password_reset_count();
    if ($canEdr)        $data['email_disclosure'] = get_pending_email_disclosure_count();
    if ($canTickets)    $data['tickets']          = get_open_ticket_count();
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);
