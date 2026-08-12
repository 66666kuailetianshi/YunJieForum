<?php
/**
 * 云界论坛 - 首页实时数据接口（1 秒轮询）
 *
 * 返回统计条 + 版块卡片 + 最新帖子 id，供首页 JS 每秒实时更新。
 * 服务端 1 秒文件缓存：多用户并发轮询时每秒只查询一次数据库，不阻塞。
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (file_exists(INSTALLED_FILE) === false) {
    echo json_encode(['success' => false, 'error' => '未安装'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $data = realtime_cache('home_realtime', 1, function () {
        $stats = forum_stats();
        $categories = get_forums_by_category();

        // 版块卡片轻量数据（时间由服务端格式化，前端直接显示）
        $forums = [];
        foreach ($categories as $group) {
            foreach ($group['forums'] as $f) {
                $forums[] = [
                    'id'                 => (int)$f['id'],
                    'threads_count'      => (int)$f['threads_count'],
                    'posts_count'        => (int)$f['posts_count'],
                    'last_post_id'       => (int)$f['last_post_id'],
                    'last_post_title'    => format_preview_text($f['last_post_title'], 40),
                    'last_post_username' => $f['last_post_username'],
                    'last_post_time_ago' => !empty($f['last_post_time']) ? time_ago($f['last_post_time']) : '',
                ];
            }
        }

        // 最新帖子（标题/作者/徽章/时间，供前端重渲染列表）
        $db = get_db();
        $stmt = $db->query("SELECT p.id, p.title, p.is_pinned, p.is_essence, p.is_locked, p.created_at, u.username
            FROM posts p
            JOIN users u ON p.user_id = u.id OR p.user_id = u.uid
            ORDER BY p.created_at DESC
            LIMIT 10");
        $latest = [];
        foreach ($stmt->fetchAll() as $row) {
            $latest[] = [
                'id'            => (int)$row['id'],
                'title'         => format_preview_text($row['title'], 80),
                'username'      => $row['username'],
                'is_pinned'     => (int)$row['is_pinned'] === 1,
                'is_essence'    => (int)$row['is_essence'] === 1,
                'is_locked'     => (int)$row['is_locked'] === 1,
                'created_at_ago' => time_ago($row['created_at']),
            ];
        }

        return [
            'success'    => true,
            'stats'      => [
                'posts'         => (int)$stats['posts'],
                'users'         => (int)$stats['users'],
                'today_posts'   => (int)$stats['today_posts'],
                'newest_user'   => !empty($stats['newest_user'])
                    ? ['id' => (int)$stats['newest_user']['id'], 'username' => $stats['newest_user']['username']]
                    : null,
            ],
            'forums'     => $forums,
            'latest'     => $latest,
            'time'       => time(),
        ];
    });

    if (!is_array($data) || empty($data['success'])) {
        throw new RuntimeException('首页实时数据生成失败');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '首页实时数据加载失败'], JSON_UNESCAPED_UNICODE);
}
