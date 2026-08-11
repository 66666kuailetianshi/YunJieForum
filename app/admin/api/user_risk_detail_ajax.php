<?php
/**
 * 云界论坛 - 管理后台用户风险详情 AJAX 接口
 *
 * 返回单个用户的风险评分构成与相关记录，供用户管理页"风险详情"弹层展示。
 *
 * 参数：
 *   - user_id: 用户 ID（必填）
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once dirname(__DIR__) . '/layout/admin-helpers.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 细粒度门禁：风险详情属用户管理，需 manage_user_dispose 权限（超管天然通过）
if (!has_permission('manage_user_dispose')) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$userId = (int)($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['error' => t('admin_ajax_invalid_param', '参数错误')], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = get_db();

// 近 90 天截止时间（用于时效加权，PHP 生成后内联进 SQL，避免占位符数量依赖）
$cutoff = date('Y-m-d H:i:s', time() - 90 * 86400);

// 用户基本信息 + 风险计数
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
$u = $stmt->fetch();

if (!$u) {
    echo json_encode(['error' => t('admin_ajax_user_not_found_short', '用户不存在')], JSON_UNESCAPED_UNICODE);
    exit;
}

$risk = compute_user_risk($u);

// 被举报记录（关联帖子/回复内容）
$reportStmt = $db->prepare("
    SELECT r.*, p.title AS post_title, p.content AS post_content, rp.content AS reply_content
    FROM reports r
    LEFT JOIN posts p ON p.id = r.post_id
    LEFT JOIN replies rp ON rp.id = r.reply_id
    WHERE r.post_id IN (SELECT id FROM posts WHERE user_id = :uid1)
       OR r.reply_id IN (SELECT id FROM replies WHERE user_id = :uid2)
    ORDER BY r.created_at DESC
    LIMIT 10
");
$reportStmt->execute([':uid1' => $userId, ':uid2' => $userId]);
$reports = $reportStmt->fetchAll();
foreach ($reports as &$rp) {
    $rp['created_at_fmt'] = db_datetime($rp['created_at']);
    // 展示内容摘要（优先取回复内容，其次取帖子标题+内容）
    if (!empty($rp['reply_content'])) {
        $rp['content_preview'] = mb_substr($rp['reply_content'], 0, 60, 'UTF-8');
        $rp['target_label'] = t('admin_ajax_reply_label', '回复 #') . $rp['reply_id'];
    } else {
        $rp['content_preview'] = (!empty($rp['post_title']) ? $rp['post_title'] . t('admin_ajax_colon', '：') : '') . mb_substr($rp['post_content'] ?? '', 0, 60, 'UTF-8');
        $rp['target_label'] = t('admin_ajax_post_label', '帖子 #') . $rp['post_id'];
    }
    if (empty($rp['content_preview'])) $rp['content_preview'] = t('admin_ajax_content_deleted', '内容已删除');
}
unset($rp);

// 敏感词命中记录
$swStmt = $db->prepare("
    SELECT * FROM sensitive_word_logs
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT 10
");
$swStmt->execute([':uid' => $userId]);
$sensitiveLogs = $swStmt->fetchAll();
foreach ($sensitiveLogs as &$s) {
    $s['created_at_fmt'] = db_datetime($s['created_at']);
    $s['snippet'] = mb_substr($s['original_snippet'] ?? '', 0, 60, 'UTF-8');
}
unset($s);

// 申诉记录
$appealStmt = $db->prepare("
    SELECT * FROM ban_appeals
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT 10
");
$appealStmt->execute([':uid' => $userId]);
$appeals = $appealStmt->fetchAll();
foreach ($appeals as &$ap) {
    $ap['created_at_fmt'] = db_datetime($ap['created_at']);
    $ap['appeal_type_fmt'] = ($ap['appeal_type'] ?? 'ban') === 'mute' ? t('admin_ajax_appeal_type_mute', '禁言申诉') : t('admin_ajax_appeal_type_ban', '封禁申诉');
}
unset($ap);

// 隐私控制：非超管（社区管理员）默认隐藏邮箱，申请披露经超管审核通过后可见
$emailVisible = admin_can_view_email((int)$u['id']);
// 一次性披露：展示即消耗（仅社区管理员经申请批准后查看时生效）
if ($emailVisible) {
    admin_consume_email_disclosure((int)$u['id']);
}

echo json_encode([
    'user' => [
        'id'            => (int)$u['id'],
        'uid'           => $u['uid'] ?? '-',
        'username'      => $u['username'],
        'email'         => $emailVisible ? $u['email'] : '',
        'email_visible' => $emailVisible ? 1 : 0,
        'role'        => $u['role'],
        'status'      => $u['status'],
        'status_fmt'  => format_user_status($u['status']),
        'created_at'  => date('Y-m-d', db_time($u['created_at'])),
        'post_count'  => (int)$u['post_count'],
        'reply_count' => (int)$u['reply_count'],
    ],
    'risk' => $risk,
    'counts' => [
        'resolved_report_count'        => (int)$u['resolved_report_count'],
        'pending_report_count'         => (int)$u['pending_report_count'],
        'rejected_report_count'        => (int)$u['rejected_report_count'],
        'sensitive_hit_count'          => (int)$u['sensitive_hit_count'],
        'sensitive_review_count'       => (int)$u['sensitive_review_count'],
        'sensitive_replace_count'      => (int)$u['sensitive_replace_count'],
        'recent_resolved_report_count' => (int)$u['recent_resolved_report_count'],
        'recent_sensitive_review_count'=> (int)$u['recent_sensitive_review_count'],
        'rejected_appeal_count'        => (int)$u['rejected_appeal_count'],
    ],
    'reports'       => $reports,
    'sensitive_logs'=> $sensitiveLogs,
    'appeals'       => $appeals,
], JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
