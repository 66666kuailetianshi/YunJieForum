<?php
/**
 * 云界论坛 - 数据备份 AJAX 接口
 *
 * 支持操作：
 *  - list:      列出所有备份
 *  - create:    创建新备份
 *  - delete:    删除指定备份
 *  - restore:   恢复指定备份
 *  - stats:     获取统计信息
 *
 * 下载通过 admin/backup.php?action=download&filename=xxx 直接访问
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';
require_once APP_ROOT . 'app/includes/backup_manager.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 仅管理员可访问
if (!is_admin()) {
    echo json_encode(['success' => false, 'error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$manager = new BackupManager();

try {
    switch ($action) {
        case 'list':
            $backups = $manager->listBackups();
            $stats = $manager->getStats();
            // 追加可读格式
            foreach ($backups as &$b) {
                $b['size_readable'] = format_bytes($b['size']);
                $b['original_size_readable'] = format_bytes($b['original_size']);
                $b['created_at_display'] = date('Y-m-d H:i:s', $b['created_at_ts']);
            }
            echo json_encode([
                'success' => true,
                'backups' => $backups,
                'stats'   => $stats,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'stats':
            $stats = $manager->getStats();
            $stats['db_size_readable'] = format_bytes($stats['db_size']);
            $stats['wal_size_readable'] = format_bytes($stats['wal_size']);
            $stats['total_size_readable'] = format_bytes($stats['total_size']);
            echo json_encode(['success' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
            break;

        case 'create':
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $description = trim($_POST['description'] ?? '');
            $result = $manager->createBackup($description);
            if (!empty($result['meta'])) {
                $result['meta']['size_readable'] = format_bytes($result['meta']['size']);
                $result['meta']['original_size_readable'] = format_bytes($result['meta']['original_size']);
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'delete':
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $filename = $_POST['filename'] ?? '';
            $result = $manager->deleteBackup($filename);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'restore':
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $filename = $_POST['filename'] ?? '';
            $result = $manager->restoreBackup($filename);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'save_auto_config':
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $config = json_decode($_POST['config'] ?? '{}', true);
            if (!is_array($config)) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_invalid_config', '无效的配置数据')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $manager->saveAutoBackupConfig($config);
            $saved = $manager->getAutoBackupConfig();
            echo json_encode([
                'success'  => true,
                'message'  => t('admin_ajax_backup_saved', '自动备份设置已保存'),
                'last_run' => $saved['last_run'] ? date('Y-m-d H:i:s', $saved['last_run']) : null,
                'next_run' => $saved['enabled'] && $saved['last_run']
                    ? date('Y-m-d H:i:s', $saved['last_run'] + $saved['interval'] * 3600)
                    : null,
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

/**
 * 格式化字节为可读单位
 */
function format_bytes(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}
