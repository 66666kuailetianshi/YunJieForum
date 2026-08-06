<?php
/**
 * 云界论坛 - 管理后台封号用户
 *
 * 支持设置封禁期限与原因，管理员账号不可被封禁。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$db = get_db();
$userId = (int)($_GET['user_id'] ?? 0);
$errors = [];

if ($userId <= 0) {
    set_flash(t('admin_userban_no_user_specified', '未指定用户。'), 'error');
    redirect('/admin/users');
}

$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    set_flash(t('admin_userban_user_not_found', '用户不存在。'), 'error');
    redirect('/admin/users');
}

if ($user['role'] === 'admin') {
    set_flash(t('admin_userban_cannot_ban_admin', '不能对管理员账号执行封号操作。'), 'error');
    redirect('/admin/users');
}

$durations = [
    '1'      => t('admin_userban_duration_1_day', '1 天'),
    '7'      => t('admin_userban_duration_7_days', '7 天'),
    '30'     => t('admin_userban_duration_30_days', '30 天'),
    '365'    => t('admin_userban_duration_1_year', '1 年'),
    '0'      => t('admin_userban_duration_permanent', '永久'),
    'custom' => t('admin_userban_duration_custom', '自定义时长'),
];

// 自定义时长单位换算为秒数
$unitToSeconds = [
    'minute' => 60,
    'hour'   => 3600,
    'day'    => 86400,
    'month'  => 2592000,   // 30 天
    'year'   => 31536000,  // 365 天
];
$unitLabels = [
    'minute' => t('admin_userban_unit_minute', '分钟'),
    'hour'   => t('admin_userban_unit_hour', '小时'),
    'day'    => t('admin_userban_unit_day', '天'),
    'month'  => t('admin_userban_unit_month', '月'),
    'year'   => t('admin_userban_unit_year', '年'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $duration = $_POST['duration'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    $bannedUntil = null;
    $untilText = t('admin_userban_duration_permanent', '永久');

    if ($duration === 'custom') {
        // 自定义时长
        $customValue = (int)($_POST['custom_value'] ?? 0);
        $customUnit = $_POST['custom_unit'] ?? '';
        if ($customValue <= 0) {
            $errors[] = t('admin_userban_error_value_positive', '请输入大于 0 的自定义时长数值。');
        } elseif (!isset($unitToSeconds[$customUnit])) {
            $errors[] = t('admin_userban_error_unit_invalid', '请选择有效的自定义时长单位。');
        } elseif ($customValue > 100) {
            $errors[] = t('admin_userban_error_value_max', '自定义时长数值不能超过 100。');
        } else {
            $seconds = $customValue * $unitToSeconds[$customUnit];
            $bannedUntil = gmdate('Y-m-d H:i:s', time() + $seconds);
            $untilText = t('admin_userban_until_custom', '{value} {unit}（至 {time}）', [
                'value' => $customValue,
                'unit'  => $unitLabels[$customUnit],
                'time'  => date('Y-m-d H:i', db_time($bannedUntil)),
            ]);
        }
    } elseif ($duration === '0') {
        // 永久
        $bannedUntil = null;
        $untilText = t('admin_userban_duration_permanent', '永久');
    } elseif (array_key_exists((string)$duration, $durations)) {
        // 预设时长（天数）
        $days = (int)$duration;
        $bannedUntil = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        $untilText = date('Y-m-d H:i', db_time($bannedUntil));
    } else {
        $errors[] = t('admin_userban_error_duration_invalid', '请选择有效的封禁期限。');
    }

    if (empty($errors)) {
        $db->prepare("UPDATE users SET status = 'banned', banned_until = :banned_until, muted_until = NULL, status_reason = :reason WHERE id = :id")
            ->execute([
                ':banned_until' => $bannedUntil,
                ':reason' => $reason,
                ':id' => $userId,
            ]);

        // 清除 remember_token，防止被封禁用户通过 Cookie 自动重新登录
        $db->exec("UPDATE users SET remember_token = NULL WHERE id = " . (int)$userId);

        // 向被封禁用户发送通知
        $notifyContent = t('admin_userban_notify_duration', '封禁期限：{until}', ['until' => $untilText]);
        if ($reason !== '') {
            $notifyContent .= t('admin_userban_notify_reason', '，原因：{reason}', ['reason' => $reason]);
        }
        send_notification($userId, 'ban', t('admin_userban_notify_title', '你的账号已被封禁'), $notifyContent, null);

        set_flash(t('admin_userban_flash_banned', '用户 {name} 已被封禁（{until}）。', ['name' => $user['username'], 'until' => $untilText]), 'success');
        redirect('/admin/users');
    }
}

$pageTitle = t('admin_userban_page_title_named', '封号用户：{name}', ['name' => $user['username']]);
$activeMenu = 'users';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_userban_heading', '封号用户')); ?></h1>
    <a href="<?php echo site_url('admin/users'); ?>" class="btn btn-secondary"><?php echo e(t('admin_userban_back_to_list', '返回用户列表')); ?></a>
</div>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
        <?php echo show_message($err, 'error'); ?>
    <?php endforeach; ?>
<?php endif; ?>

<div class="card" style="max-width: 600px;">
    <div class="user-cell mb-2">
        <img src="<?php echo avatar_url($user['avatar'], $user['username']); ?>" alt="" class="avatar">
        <div class="user-cell-info">
            <div class="user-cell-name"><?php echo e($user['username']); ?></div>
            <div class="user-cell-email"><?php echo e($user['email']); ?></div>
        </div>
    </div>

    <form method="POST" action="<?php echo site_url('admin/user_ban', ['user_id' => (int)$userId]); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <div class="form-group">
            <label class="form-label" for="duration"><?php echo e(t('admin_userban_label_duration', '封禁期限')); ?></label>
            <select class="form-control" id="duration" name="duration" required>
                <?php foreach ($durations as $days => $label): ?>
                    <option value="<?php echo $days; ?>" <?php echo (($_POST['duration'] ?? '') === (string)$days) ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" id="custom-duration-group" style="display:none;">
            <label class="form-label"><?php echo e(t('admin_userban_label_custom_duration', '自定义时长')); ?></label>
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <input type="number" class="form-control" id="custom_value" name="custom_value" min="1" max="100" step="1" value="<?php echo e($_POST['custom_value'] ?? ''); ?>" placeholder="<?php echo e(t('admin_userban_placeholder_value', '数值\"')); ?> style="width:120px;">
                <select class="form-control" id="custom_unit" name="custom_unit" style="width:140px;">
                    <?php foreach ($unitLabels as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo (($_POST['custom_unit'] ?? '') === $key) ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <small class="text-muted"><?php echo e(t('admin_userban_custom_hint', '数值范围 1-100，最长可设置 100 年')); ?></small>
        </div>

        <div class="form-group">
            <label class="form-label" for="reason"><?php echo e(t('admin_userban_label_reason', '封禁原因')); ?></label>
            <textarea class="form-control" id="reason" name="reason" rows="3" placeholder="<?php echo e(t('admin_userban_placeholder_reason', '可选：填写封禁原因，用户登录时会看到此说明。')); ?>"><?php echo e($_POST['reason'] ?? ''); ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-danger"><?php echo e(t('admin_userban_submit', '确认封号')); ?></button>
            <a href="<?php echo site_url('admin/users'); ?>" class="btn btn-secondary"><?php echo e(t('admin_userban_cancel', '取消')); ?></a>
        </div>
    </form>
</div>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>

<script>
(function () {
    var select = document.getElementById('duration');
    var customGroup = document.getElementById('custom-duration-group');
    if (!select || !customGroup) return;

    function toggle() {
        if (select.value === 'custom') {
            customGroup.style.display = '';
            var inp = document.getElementById('custom_value');
            if (inp) inp.focus();
        } else {
            customGroup.style.display = 'none';
        }
    }

    select.addEventListener('change', toggle);
    // 初始化（回显错误表单时）
    toggle();
})();
</script>
