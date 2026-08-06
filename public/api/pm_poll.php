<?php
/**
 * 云界论坛 - 会话内新消息实时查询接口
 * 按消息 id 增量拉取新消息，返回服务端渲染好的 HTML 片段
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if (file_exists(INSTALLED_FILE) === false) {
    echo json_encode(['success' => false, 'error' => '未安装'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = get_db();
$userId = (int)$_SESSION['user_id'];

$conversationId = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;
$afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;

if ($conversationId <= 0) {
    echo json_encode(['success' => false, 'error' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 校验会话归属
$stmt = $db->prepare("SELECT * FROM pm_conversations WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $conversationId]);
$conv = $stmt->fetch();
if (!$conv || ((int)$conv['user1_id'] !== $userId && (int)$conv['user2_id'] !== $userId)) {
    echo json_encode(['success' => false, 'error' => '没有权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1 秒服务端缓存：合并并发轮询
$data = realtime_cache('pm_poll_' . $conversationId . '_' . $userId . '_' . $afterId, 1, function () use ($db, $userId, $conversationId, $afterId) {
    $stmt = $db->prepare("SELECT m.*, u.username, u.avatar
        FROM pm_messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.conversation_id = :cid AND m.id > :after_id
        ORDER BY m.created_at ASC, m.id ASC
        LIMIT 50");
    $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
    $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $messages = [];
    foreach ($rows as $row) {
        $messages[] = [
            'id'         => (int)$row['id'],
            'is_mine'    => (int)$row['sender_id'] === $userId,
            'username'   => $row['username'],
            'avatar'     => avatar_url($row['avatar'], $row['username']),
            'profile'    => site_url('profile', ['user_id' => (int)$row['sender_id']]),
            'content'    => bbcode($row['content']),
            'created_at' => time_ago($row['created_at']),
        ];
    }

    // 用户正在会话页查看，将对方发来的新消息标记为已读
    if (!empty($messages)) {
        $stmt = $db->prepare("UPDATE pm_messages SET is_read = 1
            WHERE conversation_id = :cid AND id > :after_id AND sender_id != :me AND is_read = 0");
        $stmt->execute([':cid' => $conversationId, ':after_id' => $afterId, ':me' => $userId]);
        clear_unread_pm_cache();
    }

    return $messages;
});

echo json_encode([
    'success'  => true,
    'time'     => time(),
    'messages' => $data,
], JSON_UNESCAPED_UNICODE);
