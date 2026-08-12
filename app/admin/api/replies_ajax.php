<?php
/**
 * 云界论坛 - 回复管理 AJAX 接口
 * 提供回复内容获取等操作
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 细粒度门禁：回复管理需 manage_replies 权限（超管天然通过）
if (!has_permission('manage_replies')) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';

if ($action === 'get_content' && validate_csrf()) {
    $replyId = (int)($_POST['reply_id'] ?? 0);
    if ($replyId <= 0) {
        echo json_encode(['success' => false, 'error' => t('admin_ajax_invalid_reply_id', '无效的回复 ID')]);
        exit;
    }

    $db = get_db();
    $stmt = $db->prepare(
        "SELECT r.*, u.username, p.title AS post_title, p.id AS post_id
         FROM replies r
         JOIN users u ON r.user_id = u.id OR r.user_id = u.uid
         JOIN posts p ON r.post_id = p.id
         WHERE r.id = :id"
    );
    $stmt->execute([':id' => $replyId]);
    $reply = $stmt->fetch();

    if (!$reply) {
        echo json_encode(['success' => false, 'error' => t('admin_ajax_reply_not_found', '回复不存在')]);
        exit;
    }

    // 获取被回复楼层的楼层号和引用内容
    $replyToFloor = 0;
    $quoteContent = '';
    if ($reply['reply_to']) {
        $ref = $db->prepare("SELECT floor, quote_content FROM replies WHERE id = :id");
        $ref->execute([':id' => $reply['reply_to']]);
        $refRow = $ref->fetch();
        if ($refRow) {
            $replyToFloor = (int)$refRow['floor'];
            $quoteContent = mb_substr(strip_tags($refRow['quote_content'] ?? ''), 0, 200, 'UTF-8');
        }
    }

    echo json_encode([
        'success'        => true,
        'id'             => $reply['id'],
        'username'       => $reply['username'],
        'post_title'     => $reply['post_title'],
        'post_id'        => $reply['post_id'],
        'content'        => str_replace('src="uploads/', 'src="../uploads/', bbcode($reply['content'])),
        'floor'          => (int)$reply['floor'],
        'reply_to'       => (int)$reply['reply_to'],
        'reply_to_floor' => $replyToFloor,
        'quote_content'  => $quoteContent,
        'created_at'     => date('Y-m-d H:i', strtotime($reply['created_at'])),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'error' => t('admin_ajax_invalid_action', '无效操作')], JSON_UNESCAPED_UNICODE);
