<?php
/**
 * 云界论坛 - 邮件通知 AJAX 接口
 *
 * 支持操作：
 *  - preview:     生成邮件预览 HTML
 *  - send_notify: 发送通知邮件（全体用户/按用户组/指定用户）
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';
require_once APP_ROOT . 'app/includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 仅管理员可访问
if (!is_admin()) {
    echo json_encode(['success' => false, 'error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    $db = get_db();

    switch ($action) {
        case 'preview':
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $subject = trim($_POST['subject'] ?? '');
            $content = trim($_POST['content'] ?? '');
            if ($subject === '' || $content === '') {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_mail_subject_content_required', '请填写邮件主题和内容')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 使用统一邮件模板包装
            $html = render_email_template($subject, $content, [
                'subject' => $subject,
            ]);
            echo json_encode(['success' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);
            break;

        case 'send_notify':
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $targetType = $_POST['target_type'] ?? 'all';
            $targetGroup = isset($_POST['target_group']) && $_POST['target_group'] !== ''
                ? array_map('intval', explode(',', $_POST['target_group']))
                : [];
            $targetUsers = trim($_POST['target_users'] ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $content = trim($_POST['content'] ?? '');

            if ($subject === '' || $content === '') {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_mail_subject_content_required', '请填写邮件主题和内容')], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 查询目标用户
            $users = [];
            $params = [];

            if ($targetType === 'all') {
                // 全体活跃用户（有邮箱）
                $stmt = $db->query("SELECT id, username, email FROM users WHERE status = 'active' AND email IS NOT NULL AND email != ''");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($targetType === 'group' && !empty($targetGroup)) {
                // 按用户组
                $in = implode(',', array_fill(0, count($targetGroup), '?'));
                $stmt = $db->prepare("SELECT DISTINCT u.id, u.username, u.email FROM users u INNER JOIN user_group_members ugm ON u.id = ugm.user_id WHERE u.status = 'active' AND u.email IS NOT NULL AND u.email != '' AND ugm.group_id IN ($in)");
                foreach ($targetGroup as $i => $gid) {
                    $stmt->bindValue($i + 1, $gid, PDO::PARAM_INT);
                }
                $stmt->execute();
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($targetType === 'user' && $targetUsers !== '') {
                // 指定用户名
                $names = array_map('trim', explode(',', $targetUsers));
                $names = array_filter($names, function ($n) { return $n !== ''; });
                if (empty($names)) {
                    echo json_encode(['success' => false, 'error' => t('admin_ajax_username_required', '请输入至少一个用户名')], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $in = implode(',', array_fill(0, count($names), '?'));
                $stmt = $db->prepare("SELECT id, username, email FROM users WHERE status = 'active' AND email IS NOT NULL AND email != '' AND username IN ($in)");
                foreach ($names as $i => $name) {
                    $stmt->bindValue($i + 1, $name, PDO::PARAM_STR);
                }
                $stmt->execute();
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_mail_target_required', '请选择发送目标')], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (empty($users)) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_no_recipients', '没有符合条件的用户可接收通知')], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 使用统一邮件模板包装
            $body = render_email_template($subject, $content, [
                'subject' => $subject,
            ]);

            $successCount = 0;
            $failedCount = 0;
            $failedList = [];

            foreach ($users as $u) {
                $result = send_mail($u['email'], $u['username'], $subject, $body, 'notification');
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failedCount++;
                    $failedList[] = [
                        'username' => $u['username'],
                        'email'    => $u['email'],
                        'error'    => $result['error'] ?? t('admin_ajax_unknown_error', '未知错误'),
                    ];
                }
            }

            echo json_encode([
                'success'       => true,
                'target_count'  => count($users),
                'success_count' => $successCount,
                'failed_count'  => $failedCount,
                'failed_list'   => $failedList,
                'message'       => sprintf(t('admin_ajax_mail_sent', '发送完成：成功 %d 人，失败 %d 人'), $successCount, $failedCount),
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