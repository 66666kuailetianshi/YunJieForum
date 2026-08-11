<?php
/**
 * 云界论坛 - 系统更新中心 AJAX 接口
 *
 * 动作：
 *   action=check    GET   检查更新，返回 latest / update_available / changelog 等
 *   action=update   POST  执行更新（下载 → 校验 → 备份 → 覆盖），需 CSRF
 */

ob_start();

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/update_center.php';

// 权限校验
if (!is_logged_in() || !is_admin()) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 细粒度门禁：系统更新仅超级管理员可用
if (!is_super_admin()) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));

if ($action === 'check') {
    $result = uc_check_for_update();
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!validate_csrf()) {
        ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = uc_perform_update(!empty($_POST['force']));
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'progress') {
    // 轮询更新进度（无需 CSRF，只读）
    $prog = uc_progress_read();
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($prog, JSON_UNESCAPED_UNICODE);
    exit;
}

ob_end_clean();
http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);
exit;
