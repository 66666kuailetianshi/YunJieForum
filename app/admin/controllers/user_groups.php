<?php
/**
 * 云界论坛 - 管理后台用户组管理（积分等级）
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$db = get_db();
$action = $_GET['action'] ?? 'list';
$groupId = (int)($_GET['group_id'] ?? 0);
$errors = [];

$iconOptions = [
    'seedling' => t('admin_ugroups_icon_seedling', '幼苗'),
    'zap'      => t('admin_ugroups_icon_zap', '闪电'),
    'award'    => t('admin_ugroups_icon_award', '勋章'),
    'star'     => t('admin_ugroups_icon_star', '星星'),
    'crown'    => t('admin_ugroups_icon_crown', '皇冠'),
    'diamond'  => t('admin_ugroups_icon_diamond', '钻石'),
    'trophy'   => t('admin_ugroups_icon_trophy', '奖杯'),
    'shield'   => t('admin_ugroups_icon_shield', '护盾'),
    'rocket'   => t('admin_ugroups_icon_rocket', '火箭'),
    'heart'    => t('admin_ugroups_icon_heart', '爱心'),
    'bell'     => t('admin_ugroups_icon_bell', '铃铛'),
    'pen'      => t('admin_ugroups_icon_pen', '钢笔'),
];

// CSRF 统一校验：涉及状态变更的 GET 操作必须先通过校验，失败时明确提示（避免静默失败）
if ($action === 'delete' && !validate_csrf()) {
    set_flash(t('admin_ugroups_csrf_failed', '安全校验失败（链接已过期），请刷新页面后重新操作。'), 'error');
    redirect('/admin/user_groups');
}

// 删除用户组
if ($action === 'delete' && $groupId > 0) {
    $count = (int)$db->query("SELECT COUNT(*) FROM user_groups")->fetchColumn();
    if ($count <= 1) {
        set_flash(t('admin_ugroups_keep_one', '至少保留一个用户组。'), 'error');
    } else {
        $db->prepare("DELETE FROM user_groups WHERE id = :id")->execute([':id' => $groupId]);
        set_flash(t('admin_ugroups_deleted', '用户组已删除。'), 'success');
    }
    redirect('/admin/user_groups');
}

// 保存用户组
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $name = trim($_POST['name'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $minPoints = (int)($_POST['min_points'] ?? 0);
    $maxPointsRaw = trim($_POST['max_points'] ?? '');
    $maxPoints = $maxPointsRaw === '' ? null : (int)$maxPointsRaw;
    $color = trim($_POST['color'] ?? '#6366f1');
    $icon = trim($_POST['icon'] ?? 'star');
    $displayOrder = (int)($_POST['display_order'] ?? $minPoints);

    if ($name === '' || $displayName === '') {
        $errors[] = t('admin_ugroups_error_name_required', '标识和显示名称不能为空。');
    } elseif (!preg_match('/^[a-z0-9_]+$/', $name)) {
        $errors[] = t('admin_ugroups_error_name_charset', '用户组标识只能包含小写字母、数字和下划线。');
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $errors[] = t('admin_ugroups_error_color_format', '颜色格式不正确，请输入 6 位十六进制色值。');
    }
    if (!isset($iconOptions[$icon])) {
        $errors[] = t('admin_ugroups_error_icon_invalid', '图标选择不正确。');
    }

    // 检查 min/max 范围合理性
    if ($maxPoints !== null && $maxPoints < $minPoints) {
        $errors[] = t('admin_ugroups_error_max_lt_min', '积分上限不能低于下限。');
    }

    // 检查名称唯一性
    if (empty($errors)) {
        $checkSql = "SELECT id FROM user_groups WHERE name = :name" . ($groupId > 0 ? " AND id != :id" : "");
        $check = $db->prepare($checkSql);
        $params = [':name' => $name];
        if ($groupId > 0) {
            $params[':id'] = $groupId;
        }
        $check->execute($params);
        if ($check->fetch()) {
            $errors[] = t('admin_ugroups_error_name_exists', '用户组标识已存在。');
        }
    }

    if (empty($errors)) {
        if ($groupId > 0) {
            $stmt = $db->prepare("UPDATE user_groups SET name = :name, display_name = :display_name, min_points = :min_points, max_points = :max_points, color = :color, icon = :icon, display_order = :display_order WHERE id = :id");
            $stmt->execute([
                ':name' => $name,
                ':display_name' => $displayName,
                ':min_points' => $minPoints,
                ':max_points' => $maxPoints,
                ':color' => $color,
                ':icon' => $icon,
                ':display_order' => $displayOrder,
                ':id' => $groupId,
            ]);
            set_flash(t('admin_ugroups_updated', '用户组已更新。'), 'success');
        } else {
            $stmt = $db->prepare("INSERT INTO user_groups (name, display_name, min_points, max_points, color, icon, display_order) VALUES (:name, :display_name, :min_points, :max_points, :color, :icon, :display_order)");
            $stmt->execute([
                ':name' => $name,
                ':display_name' => $displayName,
                ':min_points' => $minPoints,
                ':max_points' => $maxPoints,
                ':color' => $color,
                ':icon' => $icon,
                ':display_order' => $displayOrder,
            ]);
            set_flash(t('admin_ugroups_created', '用户组已创建。'), 'success');
        }
        redirect('/admin/user_groups');
    }
}

$group = null;
if (($action === 'edit' || $action === 'add') && $groupId > 0) {
    $stmt = $db->prepare("SELECT * FROM user_groups WHERE id = :id");
    $stmt->execute([':id' => $groupId]);
    $group = $stmt->fetch();
    if (!$group) {
        set_flash(t('admin_ugroups_not_found', '用户组不存在。'), 'error');
        redirect('/admin/user_groups');
    }
}

$groups = $db->query("SELECT * FROM user_groups ORDER BY min_points ASC, display_order ASC, id ASC")->fetchAll();

$pageTitle = t('admin_ugroups_title', '用户组管理');
$activeMenu = 'user_groups';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_ugroups_title', '用户组管理')); ?></h1>
    <?php if ($action !== 'edit' && $action !== 'add'): ?>
        <a href="<?php echo site_url('admin/user_groups', ['action' => 'add']); ?>" class="btn btn-primary"><?php echo e(t('admin_ugroups_add', '新增用户组')); ?></a>
    <?php endif; ?>
</div>

<?php if ($action === 'edit' || $action === 'add'): ?>
    <div class="card">
        <h2 class="card-title mb-1"><?php echo e($group ? t('admin_ugroups_edit_title', '编辑用户组') : t('admin_ugroups_add_title', '新增用户组')); ?></h2>
        <p class="text-muted mb-2"><?php echo e(t('admin_ugroups_intro', '用户组按积分区间自动匹配，类似 Discuz 的等级制度。积分达到下限即升级，留空上限表示无上限。')); ?></p>
        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <?php echo show_message($err, 'error'); ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <form method="POST" action="<?php echo site_url('admin/user_groups', $group ? ['action' => 'edit', 'group_id' => (int)$group['id']] : ['action' => 'add']); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="form-row">
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_ugroups_label_name', '用户组标识')); ?></label>
                    <input type="text" class="form-control" name="name" value="<?php echo e($group['name'] ?? ''); ?>" required placeholder="<?php echo e(t('admin_ugroups_placeholder_name', '例如：senior\"')); ?>>
                    <p class="form-hint"><?php echo e(t('admin_ugroups_hint_name', '仅小写字母、数字、下划线，创建后不建议修改。')); ?></p>
                </div>
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_ugroups_label_display_name', '显示名称')); ?></label>
                    <input type="text" class="form-control" name="display_name" value="<?php echo e($group['display_name'] ?? ''); ?>" required placeholder="<?php echo e(t('admin_ugroups_placeholder_display_name', '例如：中级会员\"')); ?>>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_ugroups_label_min_points', '积分下限')); ?></label>
                    <input type="number" class="form-control" name="min_points" value="<?php echo (int)($group['min_points'] ?? 0); ?>" min="0" required>
                </div>
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_ugroups_label_max_points', '积分上限（留空表示无上限）')); ?></label>
                    <input type="number" class="form-control" name="max_points" value="<?php echo $group && $group['max_points'] !== null ? (int)$group['max_points'] : ''; ?>" min="0" placeholder="<?php echo e(t('admin_ugroups_placeholder_unlimited', '不限制\"')); ?>>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_ugroups_label_color', '代表色')); ?></label>
                    <div class="flex items-center gap-2">
                        <input type="color" class="form-control" style="width: 60px; padding: 2px; height: 38px;" name="color_preview" value="<?php echo e($group['color'] ?? '#6366f1'); ?>" oninput="this.form.color.value = this.value">
                        <input type="text" class="form-control" name="color" value="<?php echo e($group['color'] ?? '#6366f1'); ?>" required pattern="^#[0-9a-fA-F]{6}$" placeholder="#6366f1">
                    </div>
                </div>
                <div class="form-group form-group-half">
                    <label class="form-label"><?php echo e(t('admin_ugroups_label_icon', '图标')); ?></label>
                    <select class="form-control" name="icon">
                        <?php foreach ($iconOptions as $key => $label): ?>
                            <option value="<?php echo e($key); ?>" <?php echo ($group['icon'] ?? 'star') === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_ugroups_label_display_order', '排序权重')); ?></label>
                <input type="number" class="form-control" name="display_order" value="<?php echo (int)($group['display_order'] ?? ($group['min_points'] ?? 0)); ?>">
                <p class="form-hint"><?php echo e(t('admin_ugroups_hint_display_order', '数值越小排序越靠前，通常与积分下限保持一致即可。')); ?></p>
            </div>
            <button type="submit" class="btn btn-primary"><?php echo e(t('admin_ugroups_save', '保存')); ?></button>
            <a href="<?php echo site_url('admin/user_groups'); ?>" class="btn btn-secondary"><?php echo e(t('admin_ugroups_back', '返回')); ?></a>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?php echo e(t('admin_ugroups_col_order', '排序')); ?></th>
                        <th><?php echo e(t('admin_ugroups_col_name', '名称')); ?></th>
                        <th><?php echo e(t('admin_ugroups_col_points_range', '积分区间')); ?></th>
                        <th><?php echo e(t('admin_ugroups_col_color', '颜色')); ?></th>
                        <th><?php echo e(t('admin_ugroups_col_icon', '图标')); ?></th>
                        <th><?php echo e(t('admin_ugroups_col_actions', '操作')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $g): ?>
                        <tr>
                            <td><?php echo (int)$g['display_order']; ?></td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <span class="badge" style="background:<?php echo e($g['color']); ?>;color:#fff;">
                                        <?php echo ui_icon($g['icon'], 12); ?>
                                        <?php echo e($g['display_name']); ?>
                                    </span>
                                    <span class="text-muted text-xs"><?php echo e($g['name']); ?></span>
                                </div>
                            </td>
                            <td>
                                <?php echo (int)$g['min_points']; ?> -
                                <?php echo $g['max_points'] !== null ? (int)$g['max_points'] : '∞'; ?>
                            </td>
                            <td>
                                <span class="color-preview" style="background:<?php echo e($g['color']); ?>;"></span>
                                <code class="text-xs"><?php echo e($g['color']); ?></code>
                            </td>
                            <td><?php echo ui_icon($g['icon'], 18); ?> <?php echo e($iconOptions[$g['icon']] ?? $g['icon']); ?></td>
                            <td>
                                <a href="<?php echo site_url('admin/user_groups', ['action' => 'edit', 'group_id' => (int)$g['id']]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('admin_ugroups_action_edit', '编辑')); ?></a>
                                <a href="<?php echo site_url('admin/user_groups', ['action' => 'delete', 'group_id' => (int)$g['id'], 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-danger" data-confirm="<?php echo e(t('admin_ugroups_confirm_delete', '确定删除该用户组吗？\"&#10;<?php echo e(t(\'admin_ugroups_confirm_delete_note\', \'已归属该等级的用户将按剩余等级重新计算。\')); ?>')); ?>><?php echo e(t('admin_ugroups_action_delete', '删除')); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($groups)): ?>
            <p class="text-muted text-center py-2"><?php echo e(t('admin_ugroups_empty', '暂无用户组，请新增。')); ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
