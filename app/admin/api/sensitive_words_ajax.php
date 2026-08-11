<?php
/**
 * 云界论坛 - 敏感词就地更新 AJAX 接口
 *
 * 支持的就地操作：
 *   - toggle: 切换启用/禁用
 *   - set_level: 修改等级
 *   - set_category: 修改分类
 *   - delete: 删除单条
 *
 * 所有操作均通过 POST + CSRF 令牌校验。
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 细粒度门禁：敏感词管理仅超级管理员可用
if (!is_super_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('common_super_admin_only', '该功能仅最高管理员可用。')], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => t('admin_ajax_post_only', '仅支持 POST 请求')], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!validate_csrf()) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'sensitive_filter' . DIRECTORY_SEPARATOR . 'helper.php';

$db = get_db();
$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['error' => t('admin_ajax_invalid_id', '无效的 ID')], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {
    case 'toggle':
        // 前端始终会传 enabled 字段（'1' 或 '0'），不能用 isset 判断，
        // 否则禁用操作时值为 '0'，isset 仍返回 true，导致永远启用。
        $enabledVal = $_POST['enabled'] ?? '0';
        $enabled = in_array($enabledVal, ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
        // 取出当前敏感词内容，用于写入审计日志
        $wordRow = $db->prepare("SELECT word, enabled FROM sensitive_words WHERE id = :id LIMIT 1");
        $wordRow->execute([':id' => $id]);
        $existing = $wordRow->fetch();
        $stmt = $db->prepare("UPDATE sensitive_words SET enabled = :enabled WHERE id = :id");
        $stmt->execute([':enabled' => $enabled, ':id' => $id]);
        clear_sensitive_filter_cache();
        // 仅在状态实际变更时记录审计日志
        if ($existing && (int)$existing['enabled'] !== $enabled) {
            log_sw_status_change(
                $id,
                (string)($existing['word'] ?? ''),
                $enabled === 1 ? 'enable' : 'disable',
                (int)($_SESSION['user_id'] ?? 0),
                'manual'
            );
        }
        echo json_encode(['ok' => true, 'id' => $id, 'enabled' => $enabled], JSON_UNESCAPED_UNICODE);
        exit;

    case 'set_level':
        $level = (int)($_POST['level'] ?? 0);
        if ($level < 1 || $level > 3) {
            echo json_encode(['error' => t('admin_ajax_invalid_level', '等级无效')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $db->prepare("UPDATE sensitive_words SET level = :level WHERE id = :id");
        $stmt->execute([':level' => $level, ':id' => $id]);
        clear_sensitive_filter_cache();
        echo json_encode(['ok' => true, 'id' => $id, 'level' => $level], JSON_UNESCAPED_UNICODE);
        exit;

    case 'set_category':
        $category = trim($_POST['category'] ?? '');
        if ($category === '') {
            echo json_encode(['error' => t('admin_ajax_category_required', '分类不能为空')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $db->prepare("UPDATE sensitive_words SET category = :category WHERE id = :id");
        $stmt->execute([':category' => $category, ':id' => $id]);
        clear_sensitive_filter_cache();
        echo json_encode(['ok' => true, 'id' => $id, 'category' => $category], JSON_UNESCAPED_UNICODE);
        exit;

    case 'delete':
        $stmt = $db->prepare("DELETE FROM sensitive_words WHERE id = :id");
        $stmt->execute([':id' => $id]);
        clear_sensitive_filter_cache();
        echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;

    default:
        echo json_encode(['error' => t('admin_ajax_unknown_action', '未知操作')], JSON_UNESCAPED_UNICODE);
        exit;
}
