<?php
/**
 * 云界论坛 - 帖子回复数实时查询接口
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if (file_exists(INSTALLED_FILE) === false) {
    echo json_encode(['success' => false, 'error' => '未安装'], JSON_UNESCAPED_UNICODE);
    exit;
}

$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$authorFilter = isset($_GET['author']) ? (int)$_GET['author'] : 0;

if ($postId <= 0) {
    echo json_encode(['success' => false, 'error' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = get_db();

// 1 秒服务端缓存：合并同一帖子页面的并发轮询，避免每次请求都 COUNT 一次（防阻塞）
$count = (int)realtime_cache('post_replies_' . $postId . '_' . $authorFilter, 1, function () use ($db, $postId, $authorFilter) {
    if ($authorFilter > 0) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM replies WHERE post_id = :post_id AND user_id = :user_id");
        $stmt->execute([':post_id' => $postId, ':user_id' => $authorFilter]);
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM replies WHERE post_id = :post_id");
        $stmt->execute([':post_id' => $postId]);
    }
    return (int)$stmt->fetchColumn();
});

echo json_encode([
    'success' => true,
    'post_id' => $postId,
    'author'  => $authorFilter,
    'count'   => $count,
    'time'    => time(),
], JSON_UNESCAPED_UNICODE);
