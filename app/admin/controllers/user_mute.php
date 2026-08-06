<?php
/**
 * 云界论坛 - 管理后台禁言用户
 *
 * 支持设置禁言期限与原因，管理员账号不可被禁言。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$db = get_db();
$userId = (int)($_GET['user_id'] ?? 0);
$errors = [];

if ($userId <= 0) {
    set_flash(t('admin_usermute_no_user_specified', '未指定用户。'), 'error');
    redirect('/admin/users');
}

$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    set_flash(t('admin_usermute_user_not_found', '用户不存在。'), 'error');
    redirect('/admin/users');
}

if ($user['role'] === 'admin') {
    set_flash(t('admin_usermute_cannot_mute_admin', '不能对管理员账号执行禁言操作。'), 'error');
    redirect('/admin/users');
}

$durations = [
    '1'      => t('admin_usermute_duration_1_day', '1 天'),
    '7'      => t('admin_usermute_duration_7_days', '7 天'),
    '30'     => t('admin_usermute_duration_30_days', '30 天'),
    '365'    => t('admin_usermute_duration_1_year', '1 年'),
    '0'      => t('admin_usermute_duration_permanent', '永久'),
    'custom' => t('admin_usermute_duration_custom', '自定义时长'),
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
    'minute' => t('admin_usermute_unit_minute', '分钟'),
    'hour'   => t('admin_usermute_unit_hour', '小时'),
    'day'    => t('admin_usermute_unit_day', '天'),
    'month'  => t('admin_usermute_unit_month', '月'),
    'year'   => t('admin_usermute_unit_year', '年'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $duration = $_POST['duration'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    $mutedUntil = null;
    $untilText = t('admin_usermute_duration_permanent', '永久');

    if ($duration === 'custom') {
        // 自定义时长
        $customValue = (int)($_POST['custom_value'] ?? 0);
        $customUnit = $_POST['custom_unit'] ?? '';
        if ($customValue <= 0) {
            $errors[] = t('admin_usermute_error_value_positive', '请输入大于 0 的自定义时长数值。');
        } elseif (!isset($unitToSeconds[$customUnit])) {
            $errors[] = t('admin_usermute_error_unit_invalid', '请选择有效的自定义时长单位。');
        } elseif ($customValue > 100) {
            $errors[] = t('admin_usermute_error_value_max', '自定义时长数值不能超过 100。');
        } else {
            $seconds = $customValue * $unitToSeconds[$customUnit];
            $mutedUntil = gmdate('Y-m-d H:i:s', time() + $seconds);
            $untilText = t('admin_usermute_until_custom', '{value} {unit}（至 {time}）', [
                'value' => $customValue,
                'unit'  => $unitLabels[$customUnit],
                'time'  => date('Y-m-d H:i', db_time($mutedUntil)),
            ]);
        }
    } elseif ($duration === '0') {
        // 永久
        $mutedUntil = null;
        $untilText = t('admin_usermute_duration_permanent', '永久');
    } elseif (array_key_exists((string)$duration, $durations)) {
        // 预设时长（天数）
        $days = (int)$duration;
        $mutedUntil = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        $untilText = date('Y-m-d H:i', db_time($mutedUntil));
    } else {
        $errors[] = t('admin_usermute_error_duration_invalid', '请选择有效的禁言期限。');
    }

    if (empty($errors)) {
        $db->prepare("UPDATE users SET status = 'muted', muted_until = :muted_until, banned_until = NULL, status_reason = :reason WHERE id = :id")
            ->execute([
                ':muted_until' => $mutedUntil,
                ':reason' => $reason,
                ':id' => $userId,
            ]);

        // 向被禁言用户发送通知
        $notifyContent = t('admin_usermute_notify_duration', '禁言期限：{until}', ['until' => $untilText]);
        if ($reason !== '') {
            $notifyContent .= t('admin_usermute_notify_reason', '，原因：{reason}', ['reason' => $reason]);
        }
        send_notification($userId, 'mute', t('admin_usermute_notify_title', '你已被禁言'), $notifyContent . t('admin_usermute_notify_appeal', '如对处罚有异议，可提交申诉。'), site_url('appeal'));

        set_flash(t('admin_usermute_flash_muted', '用户 {name} 已被禁言（{until}）。', ['name' => $user['username'], 'until' => $untilText]), 'success');
        redirect('/admin/users');
    }
}

$pageTitle = t('admin_usermute_page_title_named', '禁言用户：{name}', ['name' => $user['username']]);
$activeMenu = 'users';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_usermute_heading', '禁言用户')); ?></h1>
    <a href="<?php echo site_url('admin/users'); ?>" class="btn btn-secondary"><?php echo e(t('admin_usermute_back_to_list', '返回用户列表')); ?></a>
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

    <form method="POST" action="<?php echo site_url('admin/user_mute', ['user_id' => (int)$userId]); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <div class="form-group">
            <label class="form-label" for="duration"><?php echo e(t('admin_usermute_label_duration', '禁言期限')); ?></label>
            <select class="form-control" id="duration" name="duration" required>
                <?php foreach ($durations as $days => $label): ?>
                    <option value="<?php echo $days; ?>" <?php echo (($_POST['duration'] ?? '') === (string)$days) ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" id="custom-duration-group" style="display:none;">
            <label class="form-label"><?php echo e(t('admin_usermute_label_custom_duration', '自定义时长')); ?></label>
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <input type="number" class="form-control" id="custom_value" name="custom_value" min="1" max="100" step="1" value="<?php echo e($_POST['custom_value'] ?? ''); ?>" placeholder="<?php echo e(t('admin_usermute_placeholder_value', '数值\"')); ?> style="width:120px;">
                <select class="form-control" id="custom_unit" name="custom_unit" style="width:140px;">
                    <?php foreach ($unitLabels as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo (($_POST['custom_unit'] ?? '') === $key) ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <small class="text-muted"><?php echo e(t('admin_usermute_custom_hint', '数值范围 1-100，最长可设置 100 年')); ?></small>
        </div>

        <div class="form-group">
            <label class="form-label" for="reason"><?php echo e(t('admin_usermute_label_reason', '禁言原因')); ?></label>
            <textarea class="form-control" id="reason" name="reason" rows="3" placeholder="<?php echo e(t('admin_usermute_placeholder_reason', '可选：填写禁言原因，用户发帖时会看到此说明。')); ?>"><?php echo e($_POST['reason'] ?? ''); ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-warning"><?php echo e(t('admin_usermute_submit', '确认禁言')); ?></button>
            <a href="<?php echo site_url('admin/users'); ?>" class="btn btn-secondary"><?php echo e(t('admin_usermute_cancel', '取消')); ?></a>
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
    toggle();
})();
</script>
