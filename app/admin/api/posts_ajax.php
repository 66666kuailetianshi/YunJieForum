<?php
/**
 * 云界论坛 - 帖子管理 AJAX 接口
 * 提供帖子内容获取、回复列表等操作
 */

ob_start();

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';
// 后台公共辅助函数（帖子动作 flag 等，供后续接口复用）
require_once APP_ROOT . 'app/admin/layout/admin-helpers.php';

if (!is_logged_in() || !is_admin()) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 细粒度门禁：帖子管理需 manage_posts 权限（超管天然通过）
if (!has_permission('manage_posts')) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';

if ($action === 'get_content' && validate_csrf()) {
    $postId = (int)($_POST['post_id'] ?? 0);
    if ($postId <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => t('admin_ajax_invalid_post_id', '无效的帖子 ID')]);
        exit;
    }

    $db = get_db();

    // 获取帖子
    $stmt = $db->prepare("SELECT p.*, u.username, f.name AS forum_name
        FROM posts p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN forums f ON p.forum_id = f.id
        WHERE p.id = :id");
    $stmt->execute([':id' => $postId]);
    $post = $stmt->fetch();

    if (!$post) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => t('admin_ajax_post_not_found', '帖子不存在')]);
        exit;
    }

    $fixImg = function($html) {
        return str_replace('src="uploads/', 'src="../uploads/', $html);
    };

    // 获取所有回复
    $replies = $db->prepare(
        "SELECT r.*, u.username
         FROM replies r
         JOIN users u ON r.user_id = u.id OR r.user_id = u.uid
         WHERE r.post_id = :pid
         ORDER BY r.floor ASC"
    );
    $replies->execute([':pid' => $postId]);
    $replyList = $replies->fetchAll();

    $replyData = [];
    foreach ($replyList as $r) {
        $replyData[] = [
            'id'         => (int)$r['id'],
            'floor'      => (int)$r['floor'],
            'username'   => $r['username'],
            'content'    => $fixImg(bbcode($r['content'])),
            'reply_to'   => (int)$r['reply_to'],
            'created_at' => date('Y-m-d H:i', strtotime($r['created_at'])),
        ];
    }

    // 风险评估
    $risk = assess_post_risk($post['content'], $post['title']);

    ob_end_clean();
    echo json_encode([
        'success'     => true,
        'title'       => $post['title'],
        'content'     => $fixImg(bbcode($post['content'])),
        'username'    => $post['username'],
        'forum_name'  => $post['forum_name'] ?? '',
        'created_at'  => date('Y-m-d H:i', strtotime($post['created_at'])),
        'views'       => (int)$post['views'],
        'replies_count' => (int)$post['replies_count'],
        'replies'     => $replyData,
        'risk'        => $risk,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

ob_end_clean();
echo json_encode(['success' => false, 'error' => t('admin_ajax_invalid_action', '无效操作')], JSON_UNESCAPED_UNICODE);
