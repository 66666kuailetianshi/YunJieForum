<?php
/**
 * 诊断端点 - 测试认证状态
 * 用于排查 403 问题
 */

require_once APP_ROOT . 'app/includes/functions.php';

$result = [
    'success' => true,
    'auth' => [
        'is_logged_in' => is_logged_in(),
        'is_admin'     => is_admin(),
        'session_id'   => session_status() === PHP_SESSION_ACTIVE ? session_id() : 'none',
        'user_id'      => $_SESSION['user_id'] ?? null,
        'username'     => $_SESSION['username'] ?? null,
    ],
    'php' => [
        'version'   => PHP_VERSION,
        'sapi'      => php_sapi_name(),
        'os'        => PHP_OS,
        'server_ip' => $_SERVER['SERVER_ADDR'] ?? 'unknown',
    ],
    'timestamp' => time(),
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
