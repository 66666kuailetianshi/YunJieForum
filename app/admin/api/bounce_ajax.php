<?php
/**
 * 云界论坛 - 邮件退信处理 AJAX 接口
 *
 * 支持的操作：
 *  - get_config:    获取退信处理配置和统计
 *  - save_config:   保存退信处理配置
 *  - test_connection: 测试退信邮箱连接
 *  - check_bounces: 手动触发退信检查
 *  - get_logs:      获取退信检查日志
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';
require_once APP_ROOT . 'app/includes/bounce_processor.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 仅管理员可访问
if (!is_admin()) {
    echo json_encode(['success' => false, 'error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 细粒度门禁：退信处理属邮件中心，仅超级管理员可用
if (!is_super_admin()) {
    echo json_encode(['success' => false, 'error' => t('common_super_admin_only', '该功能仅最高管理员可用。')], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'get_config';

try {
    $processor = new BounceProcessor();

    switch ($action) {
        case 'get_config':
            $config = $processor->getConfig();
            $stats = $processor->getBounceStats();
            $recentLogs = $processor->getRecentBounceLogs(10);

            // 检查 imap 扩展
            $imapAvailable = function_exists('imap_open');

            echo json_encode([
                'success'         => true,
                'config'          => $config,
                'stats'           => $stats,
                'recent_logs'     => $recentLogs,
                'imap_available'  => $imapAvailable,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'save_config':
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $data = [
                'enabled'    => $_POST['enabled'] ?? 0,
                'protocol'   => $_POST['protocol'] ?? 'imap',
                'host'       => $_POST['host'] ?? '',
                'port'       => $_POST['port'] ?? 993,
                'encryption' => $_POST['encryption'] ?? 'ssl',
                'username'   => $_POST['username'] ?? '',
                'password'   => $_POST['password'] ?? '',
                'mailbox'    => $_POST['mailbox'] ?? 'INBOX',
                'auto_check' => $_POST['auto_check'] ?? 0,
            ];
            $processor->saveConfig($data);
            echo json_encode(['success' => true, 'message' => t('admin_ajax_bounce_saved', '退信处理配置已保存')], JSON_UNESCAPED_UNICODE);
            break;

        case 'test_connection':
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 如果传了新配置，先保存再测试
            if (isset($_POST['host'])) {
                $data = [
                    'enabled'    => 1,
                    'protocol'   => $_POST['protocol'] ?? 'imap',
                    'host'       => $_POST['host'] ?? '',
                    'port'       => $_POST['port'] ?? 993,
                    'encryption' => $_POST['encryption'] ?? 'ssl',
                    'username'   => $_POST['username'] ?? '',
                    'password'   => $_POST['password'] ?? '',
                    'mailbox'    => $_POST['mailbox'] ?? 'INBOX',
                    'auto_check' => $_POST['auto_check'] ?? 0,
                ];
                $processor->saveConfig($data);
                $processor = new BounceProcessor();
            }
            $result = $processor->testConnection();
            echo json_encode([
                'success' => $result['success'],
                'message' => $result['message'],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'check_bounces':
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $maxMessages = (int)($_POST['max_messages'] ?? 50);
            $maxMessages = max(1, min(200, $maxMessages));
            $result = $processor->processBounces($maxMessages);

            // 重新获取统计
            $stats = $processor->getBounceStats();

            echo json_encode([
                'success'   => $result['success'],
                'found'     => $result['found'],
                'processed' => $result['processed'],
                'error'     => $result['error'],
                'details'   => $result['details'],
                'stats'     => $stats,
                'message'   => $result['success']
                    ? sprintf(t('admin_ajax_bounce_check_done', '检查完成：扫描 %d 封邮件，匹配 %d 条退信'), $result['found'], $result['processed'])
                    : t('admin_ajax_bounce_check_failed', '检查失败：') . ($result['error'] ?? t('admin_ajax_unknown_error', '未知错误')),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_logs':
            $limit = (int)($_GET['limit'] ?? 20);
            $limit = max(1, min(100, $limit));
            $logs = $processor->getRecentBounceLogs($limit);
            // 转换时间格式
            foreach ($logs as &$log) {
                $log['time_display'] = $log['check_time'] ? date('m-d H:i:s', db_time($log['check_time'])) : '';
                if (!empty($log['details'])) {
                    $decoded = json_decode($log['details'], true);
                    if (is_array($decoded)) {
                        $log['details_parsed'] = $decoded;
                    }
                }
            }
            echo json_encode([
                'success' => true,
                'logs'    => $logs,
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['success' => false, 'error' => t('admin_ajax_unknown_action', '未知操作')], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => t('admin_ajax_server_error', '服务器异常：') . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
