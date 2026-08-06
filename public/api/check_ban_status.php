<?php
/**
 * 云界论坛 - 封禁状态实时检测端点
 *
 * 供前端轮询使用：管理员封禁后，在线用户无需手动刷新即可被立即踢下线。
 * 仅返回最小化数据，降低轮询开销。
 */

require_once APP_ROOT . 'app/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 未登录用户：若 session 存在 banned_info（被封禁踢出登录态的会话），
// 仍按封禁状态返回，避免封禁页轮询被误判为"已解禁"而跳转登录
if (!is_logged_in()) {
    $bannedInfo = $_SESSION['banned_info'] ?? null;
    if (is_array($bannedInfo)) {
        $untilRaw = $bannedInfo['until_raw'] ?? null;
        $permanent = $untilRaw === null;
        $remaining = $permanent ? 0 : max(0, db_time($untilRaw) - time());
        echo json_encode([
            'banned'            => true,
            'until'             => $bannedInfo['until'] ?? '永久',
            'reason'            => $bannedInfo['reason'] ?? '',
            'remaining_seconds' => $remaining,
            'permanent'         => $permanent,
            'server_time'       => time(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['banned' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)$_SESSION['user_id'];

// 直接查询数据库最新状态（绕过 session 缓存）
if (is_user_banned($userId)) {
    // 取封禁详情，由前端用于展示并跳转
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT username, banned_until, status_reason FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
    } catch (Exception $e) {
        $row = null;
    }

    $bannedUntilRaw = ($row && !empty($row['banned_until'])) ? $row['banned_until'] : null;
    $until = $bannedUntilRaw ? date('Y-m-d H:i', db_time($bannedUntilRaw)) : '永久';
    $reason = ($row && !empty($row['status_reason'])) ? $row['status_reason'] : '';

    // 服务端权威计算剩余秒数（不依赖客户端时间）
    $remainingSeconds = 0;
    $permanent = false;
    if ($bannedUntilRaw === null) {
        $permanent = true;
    } else {
        $remainingSeconds = max(0, db_time($bannedUntilRaw) - time());
    }

    // 同步清空服务端 session（踢下线），下次请求 enforce_user_ban 也会处理
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['banned_info'] = [
        'username'  => ($row && !empty($row['username'])) ? $row['username'] : '',
        'until'     => $until,
        'until_raw' => $bannedUntilRaw,
        'reason'    => $reason,
    ];

    // 清理 remember cookie，防止自动重新登录
    if (!empty($_COOKIE['forum_remember'])) {
        setcookie('forum_remember', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => COOKIE_SECURE,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    echo json_encode([
        'banned'            => true,
        'until'             => $until,
        'reason'            => $reason,
        'remaining_seconds' => $remainingSeconds,
        'permanent'         => $permanent,
        'server_time'       => time(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 未被封禁：继续检测禁言状态
// ===================== 禁言状态实时检测 =====================
// 与封禁检测合并于同一轮询端点：管理员禁言/解禁后，被禁言用户
// 无需刷新页面即可在界面上看到禁言提示（不踢下线，仅拦截发帖/回帖）。
$muted = is_user_muted($userId);
if (!$muted) {
    echo json_encode(['banned' => false, 'muted' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = get_db();
$stmt = $db->prepare("SELECT muted_until, status_reason FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $userId]);
$muteRow = $stmt->fetch();
$muteUntilRaw = ($muteRow && !empty($muteRow['muted_until'])) ? $muteRow['muted_until'] : null;
$muteReason = ($muteRow && !empty($muteRow['status_reason'])) ? $muteRow['status_reason'] : '';
$untilText = $muteUntilRaw ? date('Y-m-d H:i', db_time($muteUntilRaw)) : '永久';

echo json_encode([
    'banned' => false,
    'muted'  => true,
    'mute'   => [
        'until'     => $untilText,
        'until_raw' => $muteUntilRaw,
        'reason'    => $muteReason,
        'permanent' => $muteUntilRaw === null,
        'message'   => '你当前处于禁言状态（至 ' . $untilText . '）。' . ($muteReason !== '' ? '原因：' . $muteReason : ''),
    ],
], JSON_UNESCAPED_UNICODE);
exit;
