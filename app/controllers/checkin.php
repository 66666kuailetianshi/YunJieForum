<?php
/**
 * 云界论坛 - 签到处理
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

require_login();

$db = get_db();
$user = current_user();

// null 安全检查（用户被删除时 current_user 返回 null）
if (!$user) {
    session_destroy();
    redirect('/login');
}

// 仅接受 POST 签到：GET 命中不执行写操作，提示刷新
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash(t('post_flash_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
    redirect('/profile?tab=checkins');
}

// CSRF 校验：防止通过 <img src="checkin.php"> 强制签到
if (!validate_csrf()) {
    set_flash(t('checkin_csrf_failed', '签到失败，请从个人中心页面点击签到。'), 'error');
    redirect('/profile?tab=checkins');
}

$today = date('Y-m-d');

$yesterday = date('Y-m-d', strtotime('-1 day'));
$inTransaction = false;

try {
    $driver = get_db_driver();
    // 统一使用 PDO beginTransaction()：SQLite 下 exec("BEGIN IMMEDIATE")
    // 不会更新 PDO 的 inTransaction 标志，后续 commit() 会报
    // "There is no active transaction"。
    $db->beginTransaction();
    $inTransaction = true;

    // 在事务内重新查询 last_checkin，防止 TOCTOU 竞态
    $lockClause = $driver instanceof SQLiteDriver ? '' : ' FOR UPDATE';
    $stmt = $db->prepare("SELECT last_checkin, checkin_streak FROM users WHERE id = :id{$lockClause}");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $row = $stmt->fetch();

    if ($row && $row['last_checkin'] === $today) {
        $db->rollBack();
        set_flash(t('checkin_already_today', '今天已经签到过了，明天再来吧！'), 'warning');
        redirect('/profile?tab=checkins');
    }

    // 计算连续天数
    if ($row && $row['last_checkin'] === $yesterday) {
        $streak = (int)$row['checkin_streak'] + 1;
    } else {
        $streak = 1;
    }

    // 计算积分
    $points = min(CHECKIN_BASE_POINTS + ($streak - 1) * CHECKIN_STREAK_BONUS, CHECKIN_MAX_POINTS);

    // 里程碑奖励
    $milestonePoints = 0;
    if ($streak > 0 && $streak % 30 === 0) {
        $milestonePoints = CHECKIN_MILESTONE_30_DAYS;
    } elseif ($streak > 0 && $streak % 7 === 0) {
        $milestonePoints = CHECKIN_MILESTONE_7_DAYS;
    }

    $stmt = sql_prepare($db, "INSERT OR REPLACE INTO checkins (user_id, checkin_date, points, streak) VALUES (:user_id, :date, :points, :streak)");
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':date' => $today,
        ':points' => $points + $milestonePoints,
        ':streak' => $streak,
    ]);

    $stmt = $db->prepare("UPDATE users SET last_checkin = :date, checkin_streak = :streak WHERE id = :id");
    $stmt->execute([
        ':date' => $today,
        ':streak' => $streak,
        ':id' => $_SESSION['user_id'],
    ]);

    // 基础签到积分 + 金币
    $newGroup = add_user_points(
        $_SESSION['user_id'],
        $points,
        true,
        'checkin',
        'checkin',
        null,
        t('checkin_log_streak', '连续签到 {days} 天', ['days' => $streak]),
        CHECKIN_COINS
    );

    // 里程碑额外积分
    if ($milestonePoints > 0) {
        $milestoneGroup = add_user_points(
            $_SESSION['user_id'],
            $milestonePoints,
            true,
            'checkin_milestone',
            'checkin',
            null,
            t('checkin_log_milestone', '连续签到 {days} 天里程碑奖励', ['days' => $streak])
        );
        if (!$newGroup && $milestoneGroup) {
            $newGroup = $milestoneGroup;
        }
    }

    $db->commit();

    $message = t('checkin_success', '签到成功！获得 {points} 积分', ['points' => $points]);
    if ($milestonePoints > 0) {
        $message .= t('checkin_milestone_bonus', ' + {points} 里程碑积分', ['points' => $milestonePoints]);
    }
    if (CHECKIN_COINS > 0) {
        $message .= t('checkin_coins_bonus', ' + {coins} 金币', ['coins' => CHECKIN_COINS]);
    }
    $message .= t('checkin_streak_suffix', '，连续签到 {days} 天。', ['days' => $streak]);
    if ($newGroup) {
        $message .= t('checkin_level_up', ' 恭喜升级为 {title}！', ['title' => $newGroup['title']]);
    }
    set_flash($message, 'success');
} catch (Exception $e) {
    if ($inTransaction) {
        try { $db->rollBack(); } catch (Exception $ignored) {}
    }
    // 不暴露异常细节给用户
    error_log(t('checkin_486700','签到失败：') . $e->getMessage());
    set_flash(t('checkin_failed', '签到失败，请稍后重试。'), 'error');
}

redirect('/profile?tab=checkins');
