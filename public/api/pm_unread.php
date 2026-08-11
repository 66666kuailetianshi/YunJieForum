<?php
/**
 * 云界论坛 - 站内信未读实时查询接口
 * 返回未读数与最新一条未读消息摘要，供前端轮询提醒使用
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

// 5 秒服务端缓存：合并同一用户的并发轮询，避免每次请求都查询（客户端轮询间隔 15 秒）
$data = realtime_cache('pm_unread_' . $userId, 5, function () use ($db, $userId) {
    $unread = 0;
    $latest = null;

    $stmt = $db->prepare("SELECT COUNT(*) FROM pm_messages m
        JOIN pm_conversations c ON m.conversation_id = c.id
        WHERE (c.user1_id = :uid1 OR c.user2_id = :uid2)
          AND m.sender_id != :uid3
          AND m.is_read = 0");
    $stmt->execute([':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
    $unread = (int)$stmt->fetchColumn();

    if ($unread > 0) {
        $stmt = $db->prepare("SELECT m.conversation_id, m.content, m.created_at,
                u.username, u.avatar
            FROM pm_messages m
            JOIN pm_conversations c ON m.conversation_id = c.id
            JOIN users u ON m.sender_id = u.id
            WHERE (c.user1_id = :uid1 OR c.user2_id = :uid2)
              AND m.sender_id != :uid3
              AND m.is_read = 0
            ORDER BY m.created_at DESC, m.id DESC
            LIMIT 1");
        $stmt->execute([':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $preview = $row['content'];
            // 去掉 BBCode 标签，生成纯文本摘要
            $preview = preg_replace('/\[[^\]]*\]/', '', $preview);
            $preview = trim($preview);
            if (mb_strlen($preview, 'UTF-8') > 40) {
                $preview = mb_substr($preview, 0, 40, 'UTF-8') . '…';
            }
            $latest = [
                'conversation_id' => (int)$row['conversation_id'],
                'username'        => $row['username'],
                'avatar'          => avatar_url($row['avatar'], $row['username']),
                'content'         => $preview,
                'created_at'      => time_ago($row['created_at']),
            ];
        }
    }

    return [
        'unread' => $unread,
        'latest' => $latest,
    ];
});

echo json_encode([
    'success' => true,
    'time'    => time(),
    'unread'  => (int)$data['unread'],
    'latest'  => $data['latest'],
], JSON_UNESCAPED_UNICODE);
