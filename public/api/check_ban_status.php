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

// 直接查询数据库最新状态（绕过 session 缓存）：
// 单条查询同时覆盖封禁与禁言判定（不修改 functions.php 原函数）；
// 3 秒文件缓存合并高频轮询，封禁态不写缓存以保证踢下线副作用立即执行
$banRow = realtime_cache('ban_status_' . $userId, 3, function () use ($userId) {
    $db = get_db();
    $stmt = $db->prepare("SELECT status, banned_until, muted_until, status_reason FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $r = $stmt->fetch();
    if ($r && $r['status'] === 'banned') {
        // 封禁态不缓存：确保每次命中都执行踢下线副作用
        throw new \RuntimeException('banned');
    }
    return $r ? [
        'status'        => $r['status'],
        'banned_until'  => $r['banned_until'],
        'muted_until'   => $r['muted_until'],
        'status_reason' => $r['status_reason'],
    ] : null;
});

if ($banRow === null) {
    // 缓存被跳过（封禁态）或查询失败：回退为无缓存直查
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT status, banned_until, muted_until, status_reason FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $banRow = $stmt->fetch();
    } catch (Exception $e) {
        $banRow = null;
    }
}

$banned = is_array($banRow)
    && $banRow['status'] === 'banned'
    && (empty($banRow['banned_until']) || db_time($banRow['banned_until']) > time());

if ($banned) {
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

// 未被封禁：继续检测禁言状态（复用上方单条查询结果，不再二次查库）
// ===================== 禁言状态实时检测 =====================
// 与封禁检测合并于同一轮询端点：管理员禁言/解禁后，被禁言用户
// 无需刷新页面即可在界面上看到禁言提示（不踢下线，仅拦截发帖/回帖）。
$muted = is_array($banRow)
    && $banRow['status'] === 'muted'
    && (empty($banRow['muted_until']) || db_time($banRow['muted_until']) > time());
if (!$muted) {
    echo json_encode(['banned' => false, 'muted' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$muteUntilRaw = !empty($banRow['muted_until']) ? $banRow['muted_until'] : null;
$muteReason = !empty($banRow['status_reason']) ? $banRow['status_reason'] : '';
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
