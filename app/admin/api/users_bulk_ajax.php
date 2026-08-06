<?php
/**
 * 云界论坛 - 管理后台用户批量操作 / 批量站内信 AJAX 接口
 *
 * 仅管理员可用，所有写操作需 POST + CSRF。
 *
 * 操作类型（action）：
 *   - ban          批量封禁（需 ban_reason / ban_days）
 *   - mute         批量禁言（需 mute_reason / mute_days）
 *   - unban_unmute 批量解封 + 解除禁言
 *   - set_role     批量设置角色（需 role）
 *   - delete       批量删除（管理员除外）
 *   - send_pm      批量发送站内信（需 content；ids 或 scope=filter）
 *
 * 目标用户：
 *   - ids[]        显式选中的用户 ID 数组（ban/mute/delete/set_role 仅支持此方式）
 *   - scope=filter 配合筛选参数，对当前筛选结果批量发送站内信（仅 send_pm 支持）
 *
 * 安全：role=admin 始终被排除，避免误伤管理员。
 */

require_once APP_ROOT . 'app/includes/functions.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => t('admin_ajax_post_only', '仅支持 POST 请求')], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!validate_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_csrf_failed_retry', '安全校验失败，请刷新页面后重试。')], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = get_db();
$action = trim($_POST['action'] ?? '');
$operatorId = (int)$_SESSION['user_id'];

// 解析目标用户 ID
$ids = [];
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
    foreach ($_POST['ids'] as $v) {
        $id = (int)$v;
        if ($id > 0) $ids[] = $id;
    }
    $ids = array_values(array_unique($ids));
}

$scope = trim($_POST['scope'] ?? '');

$allowedActions = ['ban', 'mute', 'unban_unmute', 'set_role', 'delete', 'send_pm'];
if (!in_array($action, $allowedActions, true)) {
    echo json_encode(['error' => t('admin_ajax_unknown_action_with_dot', '未知操作类型。')], JSON_UNESCAPED_UNICODE);
    exit;
}

// send_pm 允许 scope=filter；其余写操作必须是显式 ids
if ($action === 'send_pm') {
    if (empty($ids) && $scope !== 'filter') {
        echo json_encode(['error' => t('admin_ajax_pm_select_recipients', '请选择接收用户或指定筛选范围。')], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    if (empty($ids)) {
        echo json_encode(['error' => t('admin_ajax_select_users_first', '请先勾选要操作的用户。')], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 管理员的 ID（用于保护）
$adminIds = [];
try {
    $adminIds = $db->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {}
$adminIdSet = array_flip($adminIds);

// 计算目标 ID 集合（send_pm + scope=filter 时按筛选条件拉取）
$targetIds = $ids;
if ($action === 'send_pm' && $scope === 'filter' && empty($ids)) {
    // 复用筛选条件：与 users.php / users_ajax.php 保持一致
    $search = trim($_POST['search'] ?? '');
    $filterStatus = $_POST['status'] ?? '';
    $filterRole = $_POST['role'] ?? '';
    $filterGroup = trim($_POST['group'] ?? '');
    $dateFrom = trim($_POST['date_from'] ?? '');
    $dateTo = trim($_POST['date_to'] ?? '');

    $conditions = ['u.role <> :adminRole'];
    $params = [':adminRole' => 'admin'];
    if ($search !== '') {
        if (ctype_digit($search)) {
            $conditions[] = "(u.username LIKE :s1 OR u.email LIKE :s2 OR u.uid = :uidExact)";
            $params[':uidExact'] = (int)$search;
        } else {
            $conditions[] = "(u.username LIKE :s1 OR u.email LIKE :s2)";
        }
        $params[':s1'] = '%' . $search . '%';
        $params[':s2'] = '%' . $search . '%';
    }
    if ($filterStatus !== '' && in_array($filterStatus, ['active', 'muted', 'banned'], true)) {
        $conditions[] = "u.status = :status";
        $params[':status'] = $filterStatus;
    }
    if ($filterRole !== '') {
        $conditions[] = "u.role = :role";
        $params[':role'] = $filterRole;
    }
    if ($filterGroup !== '') {
        try {
            $g = $db->prepare("SELECT min_points, max_points FROM user_groups WHERE name = :n LIMIT 1");
            $g->execute([':n' => $filterGroup]);
            $gr = $g->fetch(PDO::FETCH_ASSOC);
            if ($gr) {
                $conditions[] = "u.points >= :gMin";
                $params[':gMin'] = (int)$gr['min_points'];
                if ($gr['max_points'] !== null && $gr['max_points'] !== '') {
                    $conditions[] = "u.points <= :gMax";
                    $params[':gMax'] = (int)$gr['max_points'];
                }
            }
        } catch (\Throwable $e) {}
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $conditions[] = "DATE(u.created_at) >= :df";
        $params[':df'] = $dateFrom;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $conditions[] = "DATE(u.created_at) <= :dt";
        $params[':dt'] = $dateTo;
    }
    $where = 'WHERE ' . implode(' AND ', $conditions);
    $stmt = $db->prepare("SELECT u.id FROM users u $where");
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->execute();
    $targetIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// 过滤掉管理员（双保险）
$targetIds = array_values(array_filter($targetIds, function ($id) use ($adminIdSet) {
    return !isset($adminIdSet[$id]);
}));

if (empty($targetIds) && $action !== 'send_pm') {
    echo json_encode(['error' => t('admin_ajax_no_target_users', '没有可操作的目标用户（管理员已被自动排除）。')], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db->beginTransaction();
    $affected = 0;

    switch ($action) {
        case 'ban':
            $reason = trim($_POST['reason'] ?? '');
            $days = (int)($_POST['days'] ?? 0);
            $until = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
            $in = str_repeat('?,', count($targetIds) - 1) . '?';
            $stmt = $db->prepare("UPDATE users SET status='banned', banned_until=?, status_reason=?, muted_until=NULL WHERE id IN ($in) AND role<>'admin'");
            $stmt->execute(array_merge([$until, $reason], $targetIds));
            $affected = $stmt->rowCount();
            break;

        case 'mute':
            $reason = trim($_POST['reason'] ?? '');
            $days = (int)($_POST['days'] ?? 0);
            $until = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
            $in = str_repeat('?,', count($targetIds) - 1) . '?';
            $stmt = $db->prepare("UPDATE users SET status='muted', muted_until=?, status_reason=? WHERE id IN ($in) AND role<>'admin'");
            $stmt->execute(array_merge([$until, $reason], $targetIds));
            $affected = $stmt->rowCount();
            break;

        case 'unban_unmute':
            $in = str_repeat('?,', count($targetIds) - 1) . '?';
            $stmt = $db->prepare("UPDATE users SET status='active', banned_until=NULL, muted_until=NULL, status_reason='' WHERE id IN ($in) AND role<>'admin'");
            $stmt->execute($targetIds);
            $affected = $stmt->rowCount();
            break;

        case 'set_role':
            $role = trim($_POST['role'] ?? '');
            if (!in_array($role, ['user', 'moderator', 'admin'], true) || $role === 'admin') {
                // 不允许批量设为管理员（避免越权）——仅允许 user / moderator
                $db->rollBack();
                echo json_encode(['error' => t('admin_ajax_bulk_role_limited', '仅支持批量设为「普通用户」或「版主」，不能批量设为管理员。')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $in = str_repeat('?,', count($targetIds) - 1) . '?';
            $stmt = $db->prepare("UPDATE users SET role=? WHERE id IN ($in) AND role<>'admin'");
            $stmt->execute(array_merge([$role], $targetIds));
            $affected = $stmt->rowCount();
            break;

        case 'delete':
            $in = str_repeat('?,', count($targetIds) - 1) . '?';
            $stmt = $db->prepare("DELETE FROM users WHERE id IN ($in) AND role<>'admin'");
            $stmt->execute($targetIds);
            $affected = $stmt->rowCount();
            break;

        case 'send_pm':
            $content = trim($_POST['content'] ?? '');
            if ($content === '') {
                $db->rollBack();
                echo json_encode(['error' => t('admin_ajax_pm_content_empty', '站内信内容不能为空。')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 防 XSS：纯文本存储（前端渲染时转义）；与前台发信一致
            $content = strip_tags($content);
            if (is_effectively_empty($content)) {
                $db->rollBack();
                echo json_encode(['error' => t('admin_ajax_pm_content_empty', '站内信内容不能为空。')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $sent = 0;
            $skipped = 0;
            foreach ($targetIds as $toId) {
                if ($toId === $operatorId) { $skipped++; continue; }
                $userA = min($operatorId, $toId);
                $userB = max($operatorId, $toId);
                $conv = $db->prepare("SELECT id FROM pm_conversations WHERE user1_id=? AND user2_id=? LIMIT 1");
                $conv->execute([$userA, $userB]);
                $row = $conv->fetch();
                if ($row) {
                    $convId = (int)$row['id'];
                } else {
                    $ins = $db->prepare("INSERT INTO pm_conversations (user1_id, user2_id, last_message_at, created_at) VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                    $ins->execute([$userA, $userB]);
                    $convId = (int)$db->lastInsertId();
                }
                $msg = $db->prepare("INSERT INTO pm_messages (conversation_id, sender_id, content, is_read, created_at) VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP)");
                $msg->execute([$convId, $operatorId, $content]);
                $upd = $db->prepare("UPDATE pm_conversations SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?");
                $upd->execute([$convId]);
                $sent++;
            }
            $affected = $sent;
            $db->commit();
            echo json_encode([
                'success' => true,
                'action'  => $action,
                'sent'    => $sent,
                'skipped' => $skipped,
                'message' => t('admin_ajax_pm_sent_count', '已发送给 {sent} 位用户，跳过 {skipped} 位（自己）。', ['sent' => $sent, 'skipped' => $skipped]),
            ], JSON_UNESCAPED_UNICODE);
            exit;
    }

    $db->commit();
    echo json_encode([
        'success' => true,
        'action'  => $action,
        'affected' => $affected,
        'message' => t('admin_ajax_bulk_success', '操作成功，影响 {affected} 位用户。', ['affected' => $affected]),
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => t('admin_ajax_op_failed', '操作失败：') . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
