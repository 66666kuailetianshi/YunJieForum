<?php
/**
 * 云界论坛 - 管理后台用户详情 AJAX 接口
 *
 * 返回某用户的完整资料、活动统计、封禁/申诉历史、风险评分、最近帖文与回复，
 * 供用户管理页的侧边抽屉渲染。仅管理员可用。
 *
 * 参数：
 *   - user_id: 用户 ID（必填）
 *
 * 返回结构为「扁平对象」，与前端 buildUserDrawerHtml() 一一对应：
 *   { success, detail: { ...用户字段, risk:{}, counts:{}, recent_posts:[], recent_replies:[], ban_history:[] } }
 */

require_once APP_ROOT . 'app/includes/functions.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$userId = (int)($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['error' => t('admin_ajax_invalid_user_id', '无效的用户 ID。')], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = get_db();

// 近 90 天截止时间（用于风险时效加权；PHP 生成后内联，避免占位符重复）
$cutoff = date('Y-m-d H:i:s', time() - 90 * 86400);

// 用户基本信息 + 风险相关计数（一次查询完成，避免多次往返）
// 注意：这里用相关子查询引用 u.id，不重复使用命名占位符
// （PDO 在 ATTR_EMULATE_PREPARES=false 时不允许同名参数出现多次）
$stmt = $db->prepare("
    SELECT u.*,
           (SELECT COUNT(*) FROM posts WHERE user_id = u.id) AS post_count,
           (SELECT COUNT(*) FROM replies WHERE user_id = u.id) AS reply_count,
           (SELECT COUNT(*) FROM reports r
               WHERE (r.post_id IN (SELECT id FROM posts WHERE user_id = u.id)
                  OR r.reply_id IN (SELECT id FROM replies WHERE user_id = u.id))
                 AND r.status = 'resolved'
           ) AS resolved_report_count,
           (SELECT COUNT(*) FROM reports r
               WHERE (r.post_id IN (SELECT id FROM posts WHERE user_id = u.id)
                  OR r.reply_id IN (SELECT id FROM replies WHERE user_id = u.id))
                 AND r.status = 'pending'
           ) AS pending_report_count,
           (SELECT COUNT(*) FROM reports r
               WHERE (r.post_id IN (SELECT id FROM posts WHERE user_id = u.id)
                  OR r.reply_id IN (SELECT id FROM replies WHERE user_id = u.id))
                 AND r.status = 'rejected'
           ) AS rejected_report_count,
           (SELECT COUNT(*) FROM sensitive_word_logs WHERE user_id = u.id) AS sensitive_hit_count,
           (SELECT COUNT(*) FROM sensitive_word_logs WHERE user_id = u.id AND action IN ('review','block')) AS sensitive_review_count,
           (SELECT COUNT(*) FROM sensitive_word_logs WHERE user_id = u.id AND action = 'replace') AS sensitive_replace_count,
           (SELECT COUNT(*) FROM reports r
               WHERE (r.post_id IN (SELECT id FROM posts WHERE user_id = u.id)
                  OR r.reply_id IN (SELECT id FROM replies WHERE user_id = u.id))
                 AND r.status = 'resolved' AND r.created_at >= '{$cutoff}'
           ) AS recent_resolved_report_count,
           (SELECT COUNT(*) FROM sensitive_word_logs WHERE user_id = u.id AND action IN ('review','block') AND created_at >= '{$cutoff}') AS recent_sensitive_review_count,
           (SELECT COUNT(*) FROM ban_appeals WHERE user_id = u.id AND status = 'rejected') AS rejected_appeal_count
    FROM users u
    WHERE u.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $userId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) {
    echo json_encode(['error' => t('admin_ajax_user_not_found', '用户不存在。')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 等级组 & 风险评分
$group = get_user_group((int)($u['points'] ?? 0));
$risk  = compute_user_risk($u);

// 申诉 / 封禁历史（最近 10 条）
$banHistory = [];
try {
    $bh = $db->prepare("SELECT id, status, appeal_reason, ban_reason, created_at, handled_at
                        FROM ban_appeals WHERE user_id = :uid ORDER BY id DESC LIMIT 10");
    $bh->execute([':uid' => $userId]);
    foreach ($bh->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $reason = trim((string)($row['appeal_reason'] ?? ''));
        if ($reason === '') $reason = trim((string)($row['ban_reason'] ?? ''));
        if ($reason === '') $reason = t('admin_ajax_no_reason', '（无说明）');
        $banHistory[] = [
            'id'         => (int)$row['id'],
            'status'     => $row['status'],
            'reason'     => mb_substr($reason, 0, 60, 'UTF-8'),
            'created_at' => !empty($row['created_at']) ? db_datetime($row['created_at'], 'Y-m-d H:i') : '',
        ];
    }
} catch (\Throwable $e) {}

// 最近帖子（最近 5 条）
$recentPosts = [];
try {
    $rp = $db->prepare("SELECT id, title, created_at FROM posts WHERE user_id = :uid ORDER BY id DESC LIMIT 5");
    $rp->execute([':uid' => $userId]);
    foreach ($rp->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $title = trim((string)($row['title'] ?? ''));
        if ($title === '') $title = t('admin_ajax_no_title', '（无标题）');
        $recentPosts[] = [
            'id'         => (int)$row['id'],
            'title'      => mb_substr($title, 0, 40, 'UTF-8'),
            'created_at' => !empty($row['created_at']) ? db_datetime($row['created_at'], 'Y-m-d H:i') : '',
        ];
    }
} catch (\Throwable $e) {}

// 最近回复（最近 5 条）
$recentReplies = [];
try {
    $rr = $db->prepare("SELECT id, content, post_id, created_at FROM replies WHERE user_id = :uid ORDER BY id DESC LIMIT 5");
    $rr->execute([':uid' => $userId]);
    foreach ($rr->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recentReplies[] = [
            'id'         => (int)$row['id'],
            'post_id'    => (int)$row['post_id'],
            'content'    => mb_substr(strip_tags((string)($row['content'] ?? '')), 0, 60, 'UTF-8'),
            'created_at' => !empty($row['created_at']) ? db_datetime($row['created_at'], 'Y-m-d H:i') : '',
        ];
    }
} catch (\Throwable $e) {}

// 格式化
$regDays       = !empty($u['created_at']) ? (int)max(0, floor((time() - db_time($u['created_at'])) / 86400)) : 0;
$lastActiveTxt = !empty($u['last_active']) ? time_ago($u['last_active']) : t('admin_ajax_never', '从未');

// 扁平结构：前端 buildUserDrawerHtml(detail) 直接按字段取值
$detail = [
    'id'              => (int)$u['id'],
    'uid'             => isset($u['uid']) && $u['uid'] !== null ? (int)$u['uid'] : null,
    'username'        => $u['username'],
    'email'           => $u['email'] ?? '',
    'avatar'          => avatar_url($u['avatar'] ?? null, (string)$u['username']),
    'role'            => $u['role'] ?? 'user',
    'status'          => $u['status'] ?? 'active',
    'status_fmt'      => format_user_status($u['status'] ?? 'active'),
    'status_reason'   => $u['status_reason'] ?? '',
    'points'          => (int)($u['points'] ?? 0),
    'coins'           => (int)($u['coins'] ?? 0),
    'signature'       => $u['signature'] ?? '',
    'created_at_fmt'  => !empty($u['created_at']) ? db_datetime($u['created_at'], 'Y-m-d H:i') : '-',
    'reg_days'        => $regDays,
    'last_active_txt' => $lastActiveTxt,
    'banned_until'    => $u['banned_until'] ?? null,
    'muted_until'     => $u['muted_until'] ?? null,
    'checkin_streak'  => (int)($u['checkin_streak'] ?? 0),
    'group_title'     => $group['title'],
    'group_color'     => $group['color'],
    'risk'            => [
        'score' => (int)($risk['score'] ?? 0),
        'level' => $risk['level'] ?? 'low',
        'label' => $risk['label'] ?? t('admin_ajax_risk_normal', '正常'),
        'color' => $risk['color'] ?? '#94a3b8',
    ],
    'counts'          => [
        'post_count'             => (int)($u['post_count'] ?? 0),
        'reply_count'            => (int)($u['reply_count'] ?? 0),
        'resolved_report_count'  => (int)($u['resolved_report_count'] ?? 0),
        'pending_report_count'   => (int)($u['pending_report_count'] ?? 0),
        'sensitive_hit_count'    => (int)($u['sensitive_hit_count'] ?? 0),
        'sensitive_review_count' => (int)($u['sensitive_review_count'] ?? 0),
        'rejected_appeal_count'  => (int)($u['rejected_appeal_count'] ?? 0),
    ],
    'ban_history'     => $banHistory,
    'recent_posts'    => $recentPosts,
    'recent_replies'  => $recentReplies,
];

echo json_encode(
    ['success' => true, 'detail' => $detail],
    JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
);
