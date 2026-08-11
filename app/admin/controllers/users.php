<?php
/**
 * 云界论坛 - 管理后台用户管理
 *
 * 列表展示：支持搜索、分页、查看详情、编辑、删除。
 * 详细编辑（权限组、勋章、积分等级）请跳转到 user_edit.php。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：用户管理（列表查看 + 封禁/禁言/解封/解禁言/解锁处置）
// 超管天然通过；社区管理员需 manage_user_dispose 权限。
// 删除/编辑/CSV 导出为超管专属，在对应分支与入口单独拦截。
require_permission('manage_user_dispose');

// 当前操作者是否超级管理员（控制删除/编辑/导出等超管专属入口的渲染）
$isSuperAdmin = is_super_admin();

$db = get_db();
$action = $_GET['action'] ?? 'list';
$userId = (int)($_GET['user_id'] ?? 0);

// 登录锁定列探测（任务 #10 添加，旧库可能缺失）
$hasLoginLockCols = false;
try {
    $db->query("SELECT login_fails, login_locked_until FROM users LIMIT 1");
    $hasLoginLockCols = true;
} catch (\Throwable $e) {
    $hasLoginLockCols = false;
}

// 写操作动作：仅接受 POST（CSRF 由 admin-init.php 对所有 POST 统一校验）
$userWriteActions = ['delete', 'unban', 'unmute', 'unlock_login'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', $userWriteActions, true)) {
    $postUserAction = $_POST['action'];
    $postUserId = (int)($_POST['user_id'] ?? 0);
    // 删除用户为超管专属动作（服务端硬拦截，不依赖前端隐藏）
    if ($postUserAction === 'delete') {
        require_super_admin();
    }
    if ($postUserId > 0) {
        if ($postUserAction === 'unlock_login') {
            // 层级保护：社区管理员不得对 role=admin 或社区管理员执行解锁
            if (!is_super_admin() && admin_dispose_target_blocked($postUserId)) {
                set_flash(t('admin_users_cannot_operate_community_admin', '不能对社区管理员执行该操作。'), 'error');
                redirect('/admin/users');
            }
            // 解锁登录：清除失败计数与锁定时间（非破坏性操作，对管理员同样可用）
            if ($hasLoginLockCols) {
                $db->prepare("UPDATE users SET login_fails = 0, login_locked_until = NULL WHERE id = :id")
                    ->execute([':id' => $postUserId]);
                set_flash(t('admin_users_login_unlocked', '已清除该用户的登录失败计数与锁定。'), 'success');
            } else {
                set_flash(t('admin_users_unlock_login_missing_cols', '当前数据库缺少登录锁定字段，无法执行解锁。'), 'error');
            }
            redirect('/admin/users');
        }
        $stmt = $db->prepare("SELECT role FROM users WHERE id = :id");
        $stmt->execute([':id' => $postUserId]);
        $target = $stmt->fetchColumn();
        if ($target === 'admin') {
            set_flash($postUserAction === 'delete'
                ? t('admin_users_cannot_delete_admin', '不能删除管理员账号。')
                : t('admin_users_cannot_operate_admin', '不能对管理员执行该操作。'), 'error');
        } elseif (!is_super_admin() && admin_user_has_role($postUserId, 'community_admin')) {
            // 层级保护：社区管理员不得处置 role=admin 或拥有 community_admin 角色的用户
            set_flash(t('admin_users_cannot_operate_community_admin', '不能对社区管理员执行该操作。'), 'error');
        } elseif ($postUserAction === 'delete') {
            $db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $postUserId]);
            set_flash(t('admin_users_deleted', '用户已删除。'), 'success');
        } elseif ($postUserAction === 'unban') {
            $db->prepare("UPDATE users SET status = 'active', banned_until = NULL, status_reason = '' WHERE id = :id")
                ->execute([':id' => $postUserId]);
            set_flash(t('admin_users_unbanned', '用户已解封。'), 'success');
        } elseif ($postUserAction === 'unmute') {
            $db->prepare("UPDATE users SET status = 'active', muted_until = NULL, status_reason = '' WHERE id = :id")
                ->execute([':id' => $postUserId]);
            set_flash(t('admin_users_unmuted', '用户已解除禁言。'), 'success');
        }
    }
    redirect('/admin/users');
}
// 旧 GET 写操作链接命中：不执行写操作，提示刷新
if (in_array($action, $userWriteActions, true)) {
    set_flash(t('post_flash_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
    redirect('/admin/users');
}

// 搜索 + 状态筛选 + 角色 + 用户组 + 注册时间 + 排序
$search = trim($_GET['search'] ?? '');
$allowedStatus = ['active' => 1, 'muted' => 1, 'banned' => 1];
$filterStatus = $_GET['status'] ?? '';
if (!isset($allowedStatus[$filterStatus])) {
    $filterStatus = '';
}

// 角色筛选（两级管理员体系分级：超级管理员 / 社区管理员 / 普通用户）
// 按白名单值校验防注入；community_admin 为 roles 表内置角色，经 user_roles 关联匹配
$roleFilterOptions = [
    'super_admin'     => t('admin_users_filter_super_admin', '超级管理员'),
    'community_admin' => t('admin_users_filter_community_admin', '社区管理员'),
    'user'            => t('admin_users_filter_user', '普通用户'),
];
$filterRole = $_GET['role'] ?? '';
if (!isset($roleFilterOptions[$filterRole])) { $filterRole = ''; }

// 用户组（积分等级）筛选：读取所有组，用于下拉与区间匹配
$userGroups = [];
try {
    $userGroups = $db->query("SELECT name, display_name, min_points, max_points FROM user_groups ORDER BY min_points ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { /* 忽略 */ }
$filterGroup = trim($_GET['group'] ?? '');
$groupRange = null;
if ($filterGroup !== '') {
    foreach ($userGroups as $g) {
        if ((string)$g['name'] === $filterGroup) { $groupRange = $g; break; }
    }
    if ($groupRange === null) $filterGroup = '';
}

// 注册时间范围筛选
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo = '';

// 排序（仅允许已知字段，防注入）
$sortField = trim($_GET['sort'] ?? '');
$sortDir   = strtolower(trim($_GET['dir'] ?? 'desc'));
$allowedSort = [
    'uid'         => 'u.uid',
    'username'    => 'u.username',
    'points'      => 'u.points',
    'post_count'  => 'post_count',
    'reply_count' => 'reply_count',
    'created_at'  => 'u.created_at',
    'last_active' => 'u.last_active',
];
if (!isset($allowedSort[$sortField])) { $sortField = ''; $sortDir = 'desc'; }
if ($sortDir !== 'asc' && $sortDir !== 'desc') { $sortDir = 'desc'; }

$conditions = [];
$params = [];
if ($search !== '') {
    // 隐私控制：邮箱仅超管可搜索（社区管理员看不到用户邮箱，避免按邮箱旁路检索）
    $searchEmailOk = $isSuperAdmin;
    if (ctype_digit($search)) {
        $conditions[] = $searchEmailOk
            ? "(u.username LIKE :search1 OR u.email LIKE :search2 OR u.uid = :uidExact)"
            : "(u.username LIKE :search1 OR u.uid = :uidExact)";
        $params[':uidExact'] = (int)$search;
    } else {
        $conditions[] = $searchEmailOk
            ? "(u.username LIKE :search1 OR u.email LIKE :search2)"
            : "(u.username LIKE :search1)";
    }
    $params[':search1'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
}
if ($filterStatus !== '') {
    $conditions[] = "u.status = :status";
    $params[':status'] = $filterStatus;
}
if ($filterRole !== '') {
    $roleCond = admin_role_filter_sql($filterRole);
    if ($roleCond !== '') {
        $conditions[] = $roleCond;
    }
}
if ($groupRange !== null) {
    // 注意：PDO 在 EMULATE_PREPARES=false 时不允许同一命名参数出现多次，
    // 因此这里拆成两个独立条件，不要写成 ":gMax IS NULL" 这种重复引用。
    $conditions[] = "u.points >= :gMin";
    $params[':gMin'] = (int)$groupRange['min_points'];
    if ($groupRange['max_points'] !== null && $groupRange['max_points'] !== '') {
        $conditions[] = "u.points <= :gMax";
        $params[':gMax'] = (int)$groupRange['max_points'];
    }
}
if ($dateFrom !== '') {
    $conditions[] = "DATE(u.created_at) >= :dateFrom";
    $params[':dateFrom'] = $dateFrom;
}
if ($dateTo !== '') {
    $conditions[] = "DATE(u.created_at) <= :dateTo";
    $params[':dateTo'] = $dateTo;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$orderBy = $sortField !== '' ? "ORDER BY " . $allowedSort[$sortField] . " " . $sortDir : "ORDER BY u.created_at DESC";

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// 顶部概览统计（均为单条轻量查询）
$statTotal    = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$statBanned   = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'banned'")->fetchColumn();
$statMuted    = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'muted'")->fetchColumn();
$statPending  = (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn();

$countStmt = $db->prepare("SELECT COUNT(*) FROM users u $where");
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare("
    SELECT u.*, COUNT(DISTINCT p.id) AS post_count, COUNT(DISTINCT r.id) AS reply_count,
           (SELECT COUNT(*) FROM reports rr
               WHERE (rr.post_id IN (SELECT id FROM posts WHERE user_id = u.id)
                  OR rr.reply_id IN (SELECT id FROM replies WHERE user_id = u.id))
                 AND rr.status = 'resolved'
           ) AS resolved_report_count,
           (SELECT COUNT(*) FROM reports rr
               WHERE (rr.post_id IN (SELECT id FROM posts WHERE user_id = u.id)
                  OR rr.reply_id IN (SELECT id FROM replies WHERE user_id = u.id))
                 AND rr.status = 'pending'
           ) AS pending_report_count,
           (SELECT COUNT(*) FROM sensitive_word_logs WHERE user_id = u.id) AS sensitive_hit_count,
           (SELECT COUNT(*) FROM sensitive_word_logs WHERE user_id = u.id AND action IN ('review','block')) AS sensitive_review_count,
           (SELECT COUNT(*) FROM sensitive_word_logs WHERE user_id = u.id AND action = 'replace') AS sensitive_replace_count,
           (SELECT COUNT(*) FROM ban_appeals WHERE user_id = u.id AND status = 'rejected') AS rejected_appeal_count,
           EXISTS (SELECT 1 FROM user_roles ur JOIN roles rr ON rr.id = ur.role_id
               WHERE ur.user_id = u.id AND rr.name = 'community_admin') AS is_community_admin
    FROM users u
    LEFT JOIN posts p ON p.user_id = u.id
    LEFT JOIN replies r ON r.user_id = u.id
    $where
    GROUP BY u.id
    $orderBy
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

$pageTitle = t('admin_users_page_title', '用户管理');
$activeMenu = 'users';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_users_page_title', '用户管理')); ?></h1>
    <div class="page-tools">
        <?php if ($isSuperAdmin): ?>
        <a href="<?php echo site_url('admin/user_create'); ?>" class="btn btn-primary"><?php echo e(t('admin_users_tool_create', '创建账号')); ?></a>
        <a href="<?php echo site_url('admin/user_groups'); ?>" class="btn btn-secondary"><?php echo e(t('admin_users_tool_user_groups', '用户组')); ?></a>
        <a href="<?php echo site_url('admin/roles'); ?>" class="btn btn-secondary"><?php echo e(t('admin_users_tool_roles', '权限组')); ?></a>
        <a href="<?php echo site_url('admin/medals'); ?>" class="btn btn-secondary"><?php echo e(t('admin_users_tool_medals', '勋章')); ?></a>
        <?php endif; ?>
    </div>
</div>

<!-- 概览卡片：复用后台 .stats-grid/.stat-card，加 compact 修饰避免过于 bulky -->
<div class="stats-grid stats-grid-compact">
    <div class="stat-card">
        <div class="stat-card-icon"><?php echo ui_icon('users', 22); ?></div>
        <div class="stat-card-body">
            <div class="stat-card-value"><?php echo number_format($statTotal); ?></div>
            <div class="stat-card-label"><?php echo e(t('admin_users_stat_total', '总用户')); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon"><?php echo ui_icon('lock', 22); ?></div>
        <div class="stat-card-body">
            <div class="stat-card-value"><?php echo number_format($statBanned); ?></div>
            <div class="stat-card-label"><?php echo e(t('admin_users_stat_banned', '已封禁')); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon"><?php echo ui_icon('message', 22); ?></div>
        <div class="stat-card-body">
            <div class="stat-card-value"><?php echo number_format($statMuted); ?></div>
            <div class="stat-card-label"><?php echo e(t('admin_users_stat_muted', '已禁言')); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon"><?php echo ui_icon('bell', 22); ?></div>
        <div class="stat-card-body">
            <div class="stat-card-value"><?php echo number_format($statPending); ?></div>
            <div class="stat-card-label"><?php echo e(t('admin_users_stat_pending', '待处理举报')); ?></div>
        </div>
    </div>
</div>

<!-- 筛选工具条（统一 .toolbar 布局） -->
<div class="toolbar card-toolbar">
    <form method="GET" action="<?php echo site_url('admin/users'); ?>" class="admin-toolbar-form" id="user-filter-form">
        <!-- 隐藏 route 字段：保证 GET 提交时路由不丢失（即使 action 被重写/副本未同步也不会跳回首页） -->
        <input type="hidden" name="route" value="admin/users">
        <div class="toolbar-field toolbar-field-grow">
            <input type="text" name="search" class="form-control" placeholder="<?php echo e(t('admin_users_search_placeholder', '搜索用户名、邮箱或 UID')); ?>" value="<?php echo e($search); ?>">
        </div>
        <div class="toolbar-field toolbar-field-select">
            <select name="status" id="status-filter" class="form-control">
                <option value=""<?php echo $filterStatus === '' ? ' selected' : ''; ?>><?php echo e(t('admin_users_filter_all_status', '全部状态')); ?></option>
                <option value="active"<?php echo $filterStatus === 'active' ? ' selected' : ''; ?>><?php echo e(t('admin_users_status_active', '正常')); ?></option>
                <option value="banned"<?php echo $filterStatus === 'banned' ? ' selected' : ''; ?>><?php echo e(t('admin_users_status_banned', '封禁')); ?></option>
                <option value="muted"<?php echo $filterStatus === 'muted' ? ' selected' : ''; ?>><?php echo e(t('admin_users_status_muted', '禁言')); ?></option>
            </select>
        </div>
        <div class="toolbar-field toolbar-field-select">
            <select name="role" id="role-filter" class="form-control">
                <option value=""><?php echo e(t('admin_users_filter_all_role', '全部角色')); ?></option>
                <?php foreach ($roleFilterOptions as $rv => $rl): ?>
                    <option value="<?php echo e($rv); ?>"<?php echo $filterRole === $rv ? ' selected' : ''; ?>><?php echo e($rl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field toolbar-field-select">
            <select name="group" id="group-filter" class="form-control">
                <option value=""><?php echo e(t('admin_users_filter_all_level', '全部等级')); ?></option>
                <?php foreach ($userGroups as $g): ?>
                    <option value="<?php echo e($g['name']); ?>"<?php echo $filterGroup === $g['name'] ? ' selected' : ''; ?>><?php echo e($g['display_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field toolbar-field-date">
            <input type="date" name="date_from" id="date-from-filter" class="form-control" value="<?php echo e($dateFrom); ?>" placeholder="<?php echo e(t('admin_users_placeholder_date_from', '开始日期')); ?>">
        </div>
        <div class="toolbar-field toolbar-field-date">
            <input type="date" name="date_to" id="date-to-filter" class="form-control" value="<?php echo e($dateTo); ?>" placeholder="<?php echo e(t('admin_users_placeholder_date_to', '结束日期')); ?>">
        </div>
        <button type="submit" class="btn btn-primary"><?php echo e(t('admin_users_filter_apply', '筛选')); ?></button>
        <?php if ($search !== '' || $filterStatus !== '' || $filterRole !== '' || $filterGroup !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
            <a href="<?php echo site_url('admin/users'); ?>" class="btn btn-secondary"><?php echo e(t('admin_users_filter_clear', '清除筛选')); ?></a>
        <?php endif; ?>
    </form>
</div>

<!-- 批量操作工具条（选中用户后显示，JS 以 style.display='block' 切换） -->
<div class="toolbar bulk-bar" id="bulk-bar" style="display:none;">
    <div class="bulk-bar-inner">
        <span class="bulk-count"><?php echo e(t('admin_users_bulk_selected', '已选')); ?> <strong id="bulk-count">0</strong> <?php echo e(t('admin_users_bulk_selected_items', '项')); ?></span>
        <div class="bulk-actions">
            <select id="bulk-action-select" class="form-control bulk-select">
                <option value=""><?php echo e(t('admin_users_bulk_select', '选择批量操作…')); ?></option>
                <option value="ban"><?php echo e(t('admin_users_bulk_ban', '封禁')); ?></option>
                <option value="mute"><?php echo e(t('admin_users_bulk_mute', '禁言')); ?></option>
                <option value="unban_unmute"><?php echo e(t('admin_users_bulk_unban_unmute', '解封 / 解除禁言')); ?></option>
                <?php if ($isSuperAdmin): ?>
                <option value="set_role"><?php echo e(t('admin_users_bulk_set_role', '设置角色')); ?></option>
                <option value="delete"><?php echo e(t('admin_users_bulk_delete', '删除')); ?></option>
                <?php endif; ?>
            </select>
            <span id="bulk-extra-fields" style="display:none;"></span>
            <button type="button" id="bulk-apply-btn" class="btn btn-primary"><?php echo e(t('admin_users_bulk_apply', '应用')); ?></button>
            <button type="button" id="bulk-clear-btn" class="btn btn-secondary"><?php echo e(t('admin_users_bulk_clear_selection', '取消选择')); ?></button>
        </div>
    </div>
</div>

<div class="card user-list-card">
    <div class="card-header user-list-header">
        <h2 class="card-title"><?php echo e(t('admin_users_list_title', '用户列表')); ?></h2>
        <div class="user-list-actions">
            <?php if ($isSuperAdmin): ?>
            <a href="#" id="export-csv-btn" class="btn btn-secondary btn-sm"><?php echo ui_icon('file-text', 16); ?> <?php echo e(t('admin_users_export_csv', '导出 CSV')); ?></a>
            <button type="button" id="bulk-notify-btn" class="btn btn-secondary btn-sm"><?php echo ui_icon('message', 16); ?> <?php echo e(t('admin_users_bulk_notify', '批量通知')); ?></button>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table data-table-compact user-table">
            <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" id="select-all" title="<?php echo e(t('admin_users_select_all_page', '全选当前页')); ?>"></th>
                    <th class="col-uid">
                        <a href="#" class="sort-th" data-sort="uid">UID</a>
                    </th>
                    <th>
                        <a href="#" class="sort-th" data-sort="username"><?php echo e(t('admin_users_th_username', '用户')); ?></a>
                    </th>
                    <th class="col-status"><?php echo e(t('admin_users_th_status', '状态')); ?></th>
                    <th class="col-risk"><?php echo e(t('admin_users_th_risk', '风险')); ?></th>
                    <th class="col-group"><?php echo e(t('admin_users_th_group', '等级')); ?></th>
                    <th class="col-number">
                        <a href="#" class="sort-th" data-sort="points"><?php echo e(t('admin_users_th_points', '积分')); ?></a>
                    </th>
                    <th class="col-number">
                        <a href="#" class="sort-th" data-sort="post_count"><?php echo e(t('admin_users_th_posts', '帖子')); ?></a>
                    </th>
                    <th class="col-number">
                        <a href="#" class="sort-th" data-sort="reply_count"><?php echo e(t('admin_users_th_replies', '回复')); ?></a>
                    </th>
                    <th class="col-time">
                        <a href="#" class="sort-th" data-sort="created_at"><?php echo e(t('admin_users_th_created_at', '注册时间')); ?></a>
                    </th>
                    <th class="col-time">
                        <a href="#" class="sort-th" data-sort="last_active"><?php echo e(t('admin_users_th_last_active', '最后活跃')); ?></a>
                    </th>
                    <th class="col-actions"><?php echo e(t('admin_users_th_actions', '操作')); ?></th>
                </tr>
            </thead>
            <tbody id="users-tbody">
                <?php foreach ($users as $u):
                    $group = get_user_group((int)$u['points']);
                    $lastActive = !empty($u['last_active']) ? time_ago($u['last_active']) : t('admin_users_never','从未');
                    // 兼容迁移导入产生的「用户名/邮箱为空」记录，避免列表出现空白行
                    $displayName  = !empty($u['username']) ? $u['username'] : ('UID ' . ($u['uid'] ?? '?'));
                    // 隐私控制：非超管（社区管理员）默认隐藏用户邮箱，申请披露经超管审核通过后可见
                    $emailVisible = $isSuperAdmin || admin_can_view_email((int)$u['id']);
                    $displayEmail = $emailVisible ? (!empty($u['email']) ? $u['email'] : '—') : t('admin_users_email_hidden', '已隐藏');
                    $avatarName   = !empty($u['username']) ? $u['username'] : ('UID' . ($u['uid'] ?? '?'));
                    $statusClass = $u['status'] === 'banned' ? 'badge-soft-danger' : ($u['status'] === 'muted' ? 'badge-soft-warning' : 'badge-soft-success');
                    $statusTitle = '';
                    $remainingSecondsInit = 0;
                    if ($u['status'] === 'banned' && !empty($u['banned_until'])) {
                        $statusTitle = t('admin_users_banned_until','封禁至：') . date('Y-m-d H:i', db_time($u['banned_until']));
                        $remainingSecondsInit = max(0, db_time($u['banned_until']) - time());
                    } elseif ($u['status'] === 'muted' && !empty($u['muted_until'])) {
                        $statusTitle = t('admin_users_muted_until','禁言至：') . date('Y-m-d H:i', db_time($u['muted_until']));
                        $remainingSecondsInit = max(0, db_time($u['muted_until']) - time());
                    }
                    if ($statusTitle !== '' && !empty($u['status_reason'])) {
                        $statusTitle .= t('admin_users_reason_prefix','&#10;原因：') . e($u['status_reason']);
                    }
                ?>
                    <tr>
                        <td class="col-check">
                            <input type="checkbox" class="row-check" value="<?php echo (int)$u['id']; ?>">
                        </td>
                        <td class="col-uid">
                            <code class="uid-code"><?php echo e((string)($u['uid'] ?? '-')); ?></code>
                        </td>
                        <td data-open-drawer="<?php echo (int)$u['id']; ?>" class="cell-clickable" title="<?php echo e(t('admin_users_view_detail', '点击查看用户详情')); ?>">
                            <div class="user-cell">
                                <img src="<?php echo avatar_url($u['avatar'] ?? null, $avatarName); ?>" alt="" class="avatar avatar-sm">
                                <div class="user-cell-info">
                                    <div class="user-cell-name">
                                        <?php echo e($displayName); ?>
                                        <?php if ($u['role'] === 'admin'): ?><span class="badge badge-danger text-xs"><?php echo e(t('admin_users_super_admin_badge', '超级管理员')); ?></span><?php elseif (!empty($u['is_community_admin'])): ?><span class="badge badge-warning text-xs"><?php echo e(t('common_community_admin', '社区管理员')); ?></span><?php endif; ?>
                                    </div>
                                    <div class="user-cell-email"><?php echo e($displayEmail); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="col-status">
                            <span class="badge <?php echo $statusClass; ?>"<?php if ($statusTitle !== ''): ?> title="<?php echo $statusTitle; ?>"<?php endif; ?> data-user-status="<?php echo (int)$u['id']; ?>"<?php if ($remainingSecondsInit > 0): ?> data-remaining-seconds="<?php echo (int)$remainingSecondsInit; ?>"<?php endif; ?>>
                                <?php echo format_user_status($u['status']); ?>
                            </span>
                            <?php if (($u['status'] === 'banned' || $u['status'] === 'muted') && $remainingSecondsInit > 0): ?>
                                <div class="text-muted text-xs remaining-text" data-remaining-for="<?php echo (int)$u['id']; ?>"></div>
                            <?php endif; ?>
                        </td>
                        <td class="col-risk">
                            <?php $risk = compute_user_risk($u); ?>
                            <?php $riskLevelClass = $risk['level'] === 'critical' ? 'badge-soft-danger' : ($risk['level'] === 'high' || $risk['level'] === 'medium' ? 'badge-soft-warning' : ($risk['level'] === 'low' ? 'badge-soft-success' : 'badge-soft-secondary')); ?>
                            <div class="risk-cell">
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="badge badge-soft-primary" data-risk-user="<?php echo (int)$u['id']; ?>" title="<?php echo e(t('admin_users_risk_exempt_title', '管理员账号不参与风险评分&#10;点击查看详情')); ?>"><?php echo e(t('admin_users_risk_exempt', '豁免')); ?></span>
                                <?php else: ?>
                                    <span class="badge <?php echo $riskLevelClass; ?>" data-risk-user="<?php echo (int)$u['id']; ?>" title="<?php echo e(t('admin_users_risk_title', '风险：{label}（{score} 分）&#10;点击查看详情', ['label' => $risk['label'], 'score' => (int)$risk['score']])); ?>">
                                        <?php echo e($risk['label']); ?> · <?php echo (int)$risk['score']; ?>
                                    </span>
                                <?php endif; ?>
                                <a href="javascript:void(0);" class="detail-link" data-open-drawer="<?php echo (int)$u['id']; ?>" title="<?php echo e(t('admin_users_view_detail', '点击查看用户详情')); ?>"><?php echo e(t('admin_users_detail_link', '详情')); ?></a>
                            </div>
                        </td>
                        <td class="col-group">
                            <span class="badge badge-outline" style="color:<?php echo e($group['color']); ?>;border-color:<?php echo e($group['color']); ?>;background:<?php echo e($group['color']); ?>15;">
                                <?php echo ui_icon($group['icon'], 12); ?>
                                <?php echo e($group['title']); ?>
                            </span>
                        </td>
                        <td class="col-number"><?php echo (int)$u['points']; ?></td>
                        <td class="col-number"><?php echo (int)$u['post_count']; ?></td>
                        <td class="col-number"><?php echo (int)$u['reply_count']; ?></td>
                        <td class="col-time"><?php echo e(date('Y-m-d', db_time($u['created_at']))); ?></td>
                        <td class="col-time"><?php echo e($lastActive); ?></td>
                        <td class="col-actions">
                            <div class="action-btns">
                                <?php if ($isSuperAdmin): ?>
                                <a href="<?php echo site_url('admin/user_edit', ['user_id' => (int)$u['id']]); ?>" class="btn btn-sm btn-primary" title="<?php echo e(t('admin_users_edit_title', '编辑')); ?>"><?php echo e(t('admin_users_edit', '编辑')); ?></a>
                                <?php endif; ?>
                                <div class="dropdown action-dropdown">
                                    <button type="button" class="btn btn-sm btn-secondary dropdown-toggle" data-toggle-dropdown><?php echo e(t('admin_users_more', '更多')); ?></button>
                                    <div class="dropdown-menu dropdown-menu-right">
                                        <a href="<?php echo site_url('profile', ['user_id' => (int)$u['id']]); ?>" target="_blank" class="dropdown-item"><?php echo e(t('admin_users_frontend_detail', '前台详情')); ?></a>
                                        <?php if ($u['role'] !== 'admin'): ?>
                                            <?php if ($u['status'] === 'banned'): ?>
                                                <?php echo admin_action_form(site_url('admin/users'), 'unban', ['user_id' => (int)$u['id']], t('admin_users_unban', '解封'), ['class' => 'dropdown-item', 'confirm' => t('admin_users_confirm_unban', '确定解封该用户吗？')]); ?>
                                            <?php elseif ($u['status'] === 'muted'): ?>
                                                <?php echo admin_action_form(site_url('admin/users'), 'unmute', ['user_id' => (int)$u['id']], t('admin_users_unmute', '解除禁言'), ['class' => 'dropdown-item', 'confirm' => t('admin_users_confirm_unmute', '确定解除该用户的禁言吗？')]); ?>
                                                <a href="<?php echo site_url('admin/user_ban', ['user_id' => (int)$u['id']]); ?>" class="dropdown-item text-danger"><?php echo e(t('admin_users_ban', '封号')); ?></a>
                                            <?php else: ?>
                                                <a href="<?php echo site_url('admin/user_ban', ['user_id' => (int)$u['id']]); ?>" class="dropdown-item text-danger"><?php echo e(t('admin_users_ban', '封号')); ?></a>
                                                <a href="<?php echo site_url('admin/user_mute', ['user_id' => (int)$u['id']]); ?>" class="dropdown-item text-warning"><?php echo e(t('admin_users_mute', '禁言')); ?></a>
                                            <?php endif; ?>
                                            <?php if ($hasLoginLockCols && !empty($u['login_locked_until']) && db_time($u['login_locked_until']) > time()): ?>
                                                <?php echo admin_action_form(site_url('admin/users'), 'unlock_login', ['user_id' => (int)$u['id']], t('admin_users_unlock_login', '解锁登录'), ['class' => 'dropdown-item text-warning', 'confirm' => t('admin_users_confirm_unlock_login', '确定清除该用户的登录失败计数与锁定吗？')]); ?>
                                            <?php endif; ?>
                                            <?php if ($isSuperAdmin): ?>
                                            <div class="dropdown-divider"></div>
                                            <?php echo admin_action_form(site_url('admin/users'), 'delete', ['user_id' => (int)$u['id']], t('admin_users_delete', '删除'), ['class' => 'dropdown-item text-danger', 'confirm' => t('admin_users_confirm_delete', '确定删除该用户吗？&#10;该用户的所有帖子、回复与签到记录将被一并删除，且无法恢复。')]); ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (empty($users)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><?php echo ui_icon('search', 28); ?></div>
            <p class="mb-0"><?php echo e(t('admin_users_no_users_found', '未找到匹配的用户')); ?></p>
            <p class="text-muted empty-state-hint"><?php echo e(t('admin_users_no_users_hint', '试试调整搜索关键词或筛选条件')); ?></p>
        </div>
    <?php endif; ?>
    <?php
        $paginateParams = [];
        if ($search !== '') $paginateParams['search'] = $search;
        if ($filterStatus !== '') $paginateParams['status'] = $filterStatus;
        if ($filterRole !== '') $paginateParams['role'] = $filterRole;
        if ($filterGroup !== '') $paginateParams['group'] = $filterGroup;
        if ($dateFrom !== '') $paginateParams['date_from'] = $dateFrom;
        if ($dateTo !== '') $paginateParams['date_to'] = $dateTo;
        if ($sortField !== '') { $paginateParams['sort'] = $sortField; $paginateParams['dir'] = $sortDir; }
        echo pagination($page, $total, $perPage, site_url('admin/users', $paginateParams));
    ?>
</div>

<!-- 风险详情弹层（静态布局见 admin.css #risk-modal） -->
<div class="modal-overlay" id="risk-modal" style="display:none;">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?php echo e(t('admin_users_risk_detail_modal', '风险详情')); ?></h2>
            <button type="button" class="btn btn-sm btn-secondary" data-close-risk-modal><?php echo e(t('admin_users_close', '关闭')); ?></button>
        </div>
        <div id="risk-modal-body"><?php echo e(t('admin_users_loading', '加载中…')); ?></div>
    </div>
</div>

<!-- 用户详情抽屉（静态布局见 admin.css #user-drawer-overlay） -->
<div class="drawer-overlay" id="user-drawer-overlay" style="display:none;">
    <div class="drawer-panel">
        <div id="user-drawer-body"><?php echo e(t('admin_users_loading', '加载中…')); ?></div>
    </div>
</div>

<!-- 批量通知弹窗（静态布局见 admin.css #notify-overlay） -->
<div class="modal-overlay" id="notify-overlay" style="display:none;">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?php echo e(t('admin_users_bulk_notify_title', '批量发送站内信')); ?></h2>
            <button type="button" class="btn btn-sm btn-secondary" data-close-notify><?php echo e(t('admin_users_close', '关闭')); ?></button>
        </div>
        <div class="notify-body">
            <p class="text-muted notify-desc">
                <?php echo e(t('admin_users_notify_desc_prefix', '将向')); ?> <strong id="notify-target-text"><?php echo e(t('admin_users_notify_selected_users', '已勾选的用户')); ?></strong> <?php echo e(t('admin_users_notify_desc_suffix', '发送一条站内信。勾选“发送给当前筛选结果”可群发给当前筛选条件下的全部用户（管理员除外）。')); ?>
            </p>
            <label class="form-check notify-check">
                <input type="checkbox" id="notify-scope-filter"> <?php echo e(t('admin_users_notify_scope_filter', '发送给当前筛选结果（忽略勾选）')); ?>
            </label>
            <textarea id="notify-content" class="form-control notify-textarea" rows="5" placeholder="<?php echo e(t('admin_users_notify_placeholder', '输入站内信内容…')); ?>"></textarea>
            <div class="notify-actions">
                <button type="button" class="btn btn-secondary" data-close-notify><?php echo e(t('admin_users_cancel', '取消')); ?></button>
                <button type="button" class="btn btn-primary" id="notify-send-btn"><?php echo e(t('admin_users_send', '发送')); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- 邮箱披露申请弹窗（隐私控制：非超管申请查看用户邮箱） -->
<div class="modal-overlay" id="email-disclosure-overlay" style="display:none;">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?php echo e(t('admin_users_edr_modal_title', '申请披露邮箱')); ?></h2>
            <button type="button" class="btn btn-sm btn-secondary" data-close-email-disclosure><?php echo e(t('admin_users_close', '关闭')); ?></button>
        </div>
        <div class="notify-body">
            <p class="text-muted notify-desc">
                <?php echo e(t('admin_users_edr_modal_desc_prefix', '目标用户：')); ?> <strong id="edr-target-name">-</strong>
            </p>
            <textarea id="edr-reason" class="form-control notify-textarea" rows="4" maxlength="200" placeholder="<?php echo e(t('admin_users_edr_reason_placeholder', '请说明申请原因（至少 5 个字），例如：该用户涉嫌违规，需要联系核实。')); ?>"></textarea>
            <div class="notify-actions">
                <button type="button" class="btn btn-secondary" data-close-email-disclosure><?php echo e(t('admin_users_cancel', '取消')); ?></button>
                <button type="button" class="btn btn-primary" id="edr-submit-btn" onclick="submitEmailDisclosure()"><?php echo e(t('admin_users_edr_submit', '提交申请')); ?></button>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>

<script>
(function () {
    'use strict';
    var tbody = document.getElementById('users-tbody');
    if (!tbody) return;

    var searchInput = document.querySelector('input[name="search"]');
    var statusSelect = document.getElementById('status-filter');
    var roleSelect = document.getElementById('role-filter');
    var groupSelect = document.getElementById('group-filter');
    var dateFromInput = document.getElementById('date-from-filter');
    var dateToInput = document.getElementById('date-to-filter');
    var currentSearch = searchInput ? searchInput.value : '';
    var currentStatus = statusSelect ? statusSelect.value : '';
    var currentRole = roleSelect ? roleSelect.value : '';
    var currentGroup = groupSelect ? groupSelect.value : '';
    var currentDateFrom = dateFromInput ? dateFromInput.value : '';
    var currentDateTo = dateToInput ? dateToInput.value : '';
    var currentSort = '<?php echo e($sortField); ?>';
    var currentDir = '<?php echo e($sortDir); ?>';
    var currentPage = <?php echo (int)$page; ?>;
    var perPage = <?php echo (int)$perPage; ?>;
    var ajaxUrl = '<?php echo site_url('admin/api/users_ajax'); ?>';
    var bulkUrl = '<?php echo site_url('admin/api/users_bulk_ajax'); ?>';
    var detailUrl = '<?php echo site_url('admin/api/user_detail_ajax'); ?>';
    var exportUrl = '<?php echo site_url('admin/api/users_export_csv'); ?>';
    var csrfToken = '<?php echo csrf_token(); ?>';
    var usersPageUrl = '<?php echo site_url('admin/users'); ?>';
    // 超管专属入口（编辑/删除）的渲染开关：与服务端门禁保持一致
    var IS_SUPER = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
    // 写操作按钮文案（JS 动态行生成 POST 表单用）
    var UA_LABELS = {
        unban: <?php echo json_encode(t('admin_users_unban', '解封')); ?>,
        unmute: <?php echo json_encode(t('admin_users_unmute', '解除禁言')); ?>,
        unlock_login: <?php echo json_encode(t('admin_users_unlock_login', '解锁登录')); ?>,
        del: <?php echo json_encode(t('admin_users_delete', '删除')); ?>
    };
    var UA_CONFIRMS = {
        unban: <?php echo json_encode(t('admin_users_confirm_unban', '确定解封该用户吗？')); ?>,
        unmute: <?php echo json_encode(t('admin_users_confirm_unmute', '确定解除该用户的禁言吗？')); ?>,
        unlock_login: <?php echo json_encode(t('admin_users_confirm_unlock_login', '确定清除该用户的登录失败计数与锁定吗？')); ?>,
        del: <?php echo json_encode(t('admin_users_confirm_delete', '确定删除该用户吗？&#10;该用户的所有帖子、回复与签到记录将被一并删除，且无法恢复。')); ?>
    };
    // 生成下拉菜单内的内联 POST 动作表单（确认框走 data-confirm 事件委托）
    function userActionFormItem(act, uid, label, extraCls, confirmMsg) {
        var h = '<form method="post" action="' + usersPageUrl + '" class="inline-action-form" data-confirm="' + String(confirmMsg).replace(/"/g, '&quot;') + '">';
        h += '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
        h += '<input type="hidden" name="action" value="' + act + '">';
        h += '<input type="hidden" name="user_id" value="' + uid + '">';
        h += '<button type="submit" class="dropdown-item' + (extraCls ? ' ' + extraCls : '') + '">' + escapeHtml(label) + '</button>';
        h += '</form>';
        return h;
    }
    var POLL_INTERVAL = 2000;  // 2 秒轮询；配合签名比对，仅数据真正变化时才重绘，消除整表闪烁
    var TICK_INTERVAL = 1000;  // 1 秒递减一次计数器
    var listChecking = false;  // 请求去重：上一请求未返回时跳过本次轮询，避免堆积
    var lastSignature = '';    // 列表数据签名：未变化则跳过重绘，避免每秒闪烁
    var selectedIds = new Set();   // 跨页保留的勾选集合（轮询重绘不丢失选中状态）

    // 存储每个用户的剩余秒数（基于服务端返回值，纯计数器递减）
    var remainingMap = {};

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(s);
        return div.innerHTML;
    }

    function formatRemaining(sec) {
        if (sec <= 0) return <?php echo json_encode(t('admin_users_expired','已到期')); ?>;
        var days = Math.floor(sec / 86400);
        var hours = Math.floor((sec % 86400) / 3600);
        var minutes = Math.floor((sec % 3600) / 60);
        var seconds = sec % 60;
        var parts = [];
        if (days > 0) parts.push(days + <?php echo json_encode(t('admin_users_unit_day','天')); ?>);
        if (hours > 0) parts.push(hours + <?php echo json_encode(t('admin_users_unit_hour','时')); ?>);
        if (minutes > 0) parts.push(minutes + <?php echo json_encode(t('admin_users_unit_minute','分')); ?>);
        parts.push(seconds + <?php echo json_encode(t('admin_users_unit_second','秒')); ?>);
        return <?php echo json_encode(t('admin_users_remaining_prefix','剩 ')); ?> + parts.join(' ');
    }

    // 初始化：从 DOM 读取服务端渲染的剩余秒数
    function initFromDom() {
        var badges = document.querySelectorAll('[data-user-status][data-remaining-seconds]');
        badges.forEach(function (badge) {
            var userId = badge.dataset.userStatus;
            var sec = parseInt(badge.dataset.remainingSeconds, 10) || 0;
            remainingMap[userId] = sec;
            updateRemainingText(userId, sec);
        });
    }

    function updateRemainingText(userId, sec) {
        var remainingEl = document.querySelector('[data-remaining-for="' + userId + '"]');
        if (remainingEl) {
            remainingEl.textContent = formatRemaining(sec);
        }
    }

    // 每秒递减所有封禁/禁言用户的剩余秒数（纯计数器，不使用 Date.now()）
    function tickRemaining() {
        Object.keys(remainingMap).forEach(function (userId) {
            if (remainingMap[userId] > 0) {
                remainingMap[userId]--;
                updateRemainingText(userId, remainingMap[userId]);
            }
        });
    }

    function renderRow(u) {
        var statusClass = u.status === 'banned' ? 'badge-soft-danger' : (u.status === 'muted' ? 'badge-soft-warning' : 'badge-soft-success');
        var remainingAttr = '';
        if ((u.status === 'banned' || u.status === 'muted') && u.remaining_seconds > 0) {
            remainingAttr = ' data-remaining-seconds="' + (parseInt(u.remaining_seconds, 10) || 0) + '"';
        }

        var statusBadge = '<span class="badge ' + statusClass + '" data-user-status="' + u.id + '"' + remainingAttr + '>' + escapeHtml(u.status_fmt) + '</span>';

        // 剩余时间小字
        var remainingHtml = '';
        if ((u.status === 'banned' || u.status === 'muted') && u.remaining_seconds > 0) {
            remainingHtml = '<div class="text-muted text-xs remaining-text" data-remaining-for="' + u.id + '" style="font-size:0.6875rem;margin-top:0.125rem;">' + escapeHtml(u.remaining_text) + '</div>';
        }

        // 风险徽章（管理员显示“豁免”，普通用户显示等级 + 分数）
        var riskLevelClass = u.risk_level === 'critical' ? 'badge-soft-danger' : (u.risk_level === 'high' || u.risk_level === 'medium' ? 'badge-soft-warning' : (u.risk_level === 'low' ? 'badge-soft-success' : 'badge-soft-secondary'));
        var riskHtml = '<div class="risk-cell">';
        if (u.role === 'admin') {
            riskHtml += '<span class="badge badge-soft-primary" data-risk-user="' + u.id + <?php echo json_encode(t('admin_users_js_risk_exempt_title', '" title="管理员账号不参与风险评分&#10;点击查看详情"')); ?> + '>' + <?php echo json_encode(t('admin_users_risk_exempt', '豁免')); ?> + '</span>';
        } else {
            riskHtml += '<span class="badge ' + riskLevelClass + '" data-risk-user="' + u.id + <?php echo json_encode(t('admin_users_js_risk_title_prefix','" title="风险：')); ?> + escapeHtml(u.risk_label) + <?php echo json_encode(t('admin_users_left_paren','（')); ?> + (parseInt(u.risk_score, 10) || 0) + <?php echo json_encode(t('admin_users_js_risk_title_suffix',' 分）&#10;点击查看详情">')); ?> + escapeHtml(u.risk_label) + ' · ' + (parseInt(u.risk_score, 10) || 0) + '</span>';
        }
        riskHtml += '<a href="javascript:void(0);" class="detail-link" data-open-drawer="' + u.id + <?php echo json_encode(t('admin_users_js_view_detail_link', '" title="点击查看用户详情">详情</a>')); ?>;

        // 用户组
        var groupBadge = '<span class="badge badge-outline" style="color:' + escapeHtml(u.group_color) + ';border-color:' + escapeHtml(u.group_color) + ';background:' + escapeHtml(u.group_color) + '15;">' + escapeHtml(u.group_title) + '</span>';

        // 操作按钮
        var actions = '<div class="action-btns">';
        if (IS_SUPER) {
            actions += '<a href="<?php echo site_url('admin/user_edit'); ?>?user_id=' + u.id + <?php echo json_encode(t('admin_users_js_edit_link','" class="btn btn-sm btn-primary" title="编辑">编辑</a> ')); ?>;
        }
        actions += '<div class="dropdown action-dropdown">';
        actions += '<?php echo json_encode(t('admin_users_js_more_btn','<button type="button" class="btn btn-sm btn-secondary dropdown-toggle" data-toggle-dropdown>更多</button>')); ?>';
        actions += '<div class="dropdown-menu dropdown-menu-right">';
        actions += '<a href="<?php echo site_url('profile', ['user_id' => '']); ?>' + u.id + <?php echo json_encode(t('admin_users_js_frontend_detail','" target="_blank" class="dropdown-item">前台详情</a>')); ?>;
        if (u.role !== 'admin') {
            if (u.status === 'banned') {
                actions += userActionFormItem('unban', u.id, UA_LABELS.unban, '', UA_CONFIRMS.unban);
            } else if (u.status === 'muted') {
                actions += userActionFormItem('unmute', u.id, UA_LABELS.unmute, '', UA_CONFIRMS.unmute);
                actions += '<a href="<?php echo site_url('admin/user_ban'); ?>&user_id=' + u.id + <?php echo json_encode(t('admin_users_js_ban_link','" class="dropdown-item text-danger">封号</a>')); ?>;
            } else {
                actions += '<a href="<?php echo site_url('admin/user_ban'); ?>&user_id=' + u.id + <?php echo json_encode(t('admin_users_js_ban_link','" class="dropdown-item text-danger">封号</a>')); ?>;
                actions += '<a href="<?php echo site_url('admin/user_mute'); ?>&user_id=' + u.id + <?php echo json_encode(t('admin_users_js_mute_link','" class="dropdown-item text-warning">禁言</a>')); ?>;
            }
            if (u.login_locked) {
                actions += userActionFormItem('unlock_login', u.id, UA_LABELS.unlock_login, 'text-warning', UA_CONFIRMS.unlock_login);
            }
            if (IS_SUPER) {
                actions += '<div class="dropdown-divider"></div>';
                actions += userActionFormItem('delete', u.id, UA_LABELS.del, 'text-danger', UA_CONFIRMS.del);
            }
        }
        actions += '</div></div></div>';

        // 角色徽标：超级管理员（red）> 社区管理员（warning），与主页面 PHP 渲染保持一致
        // 注意：必须与 PHP 初始渲染共用 admin_users_super_admin_badge，避免 JS 重绘后显示不一致
        var adminBadge = u.role === 'admin' ? ' <span class="badge badge-danger text-xs">' + <?php echo json_encode(t('admin_users_super_admin_badge','超级管理员')); ?> + '</span>' : (u.is_community_admin ? <?php echo json_encode(t('admin_users_js_community_admin_badge',' <span class="badge badge-warning text-xs">社区管理员</span>')); ?> : '');

        var checked = selectedIds.has(u.id) ? ' checked' : '';
        return '<tr data-user-id="' + u.id + '">' +
            '<td class="col-check"><input type="checkbox" class="row-check" value="' + u.id + '"' + checked + '></td>' +
            '<td class="col-uid"><code class="uid-code">' + escapeHtml(u.uid || '-') + '</code></td>' +
            '<td data-open-drawer="' + u.id + '" style="cursor:pointer;" title="点击查看用户详情"><div class="user-cell"><img src="' + escapeHtml(u.avatar_url || '') + '" alt="" class="avatar avatar-sm"><div class="user-cell-info"><div class="user-cell-name">' + escapeHtml(u.username || ('UID ' + (u.uid != null ? u.uid : '?'))) + adminBadge + '</div><div class="user-cell-email">' + (u.email_visible ? escapeHtml(u.email || '—') : <?php echo json_encode(t('admin_users_email_hidden','已隐藏')); ?>) + '</div></div></div></td>' +
            '<td class="col-status">' + statusBadge + remainingHtml + '</td>' +
            '<td class="col-risk">' + riskHtml + '</td>' +
            '<td class="col-group">' + groupBadge + '</td>' +
            '<td class="col-number">' + (parseInt(u.points, 10) || 0) + '</td>' +
            '<td class="col-number">' + (parseInt(u.post_count, 10) || 0) + '</td>' +
            '<td class="col-number">' + (parseInt(u.reply_count, 10) || 0) + '</td>' +
            '<td class="col-time">' + escapeHtml(u.created_at_fmt) + '</td>' +
            '<td class="col-time">' + escapeHtml(u.last_active_ago) + '</td>' +
            '<td class="col-actions">' + actions + '</td>' +
            '</tr>';
    }

    // 计算列表数据签名：仅关键字段变化时才需要重绘（消除每秒闪烁）
    function computeSignature(users) {
        return users.map(function (u) {
            return [u.id, u.status, u.risk_score, u.remaining_seconds, u.points,
                    u.post_count, u.reply_count, u.resolved_report_count,
                    u.pending_report_count, u.sensitive_hit_count, u.rejected_appeal_count,
                    u.role, u.is_community_admin ? 1 : 0].join(':');
        }).join('|');
    }

    // 从服务端拉取最新列表数据（带签名比对，仅变化时才重绘，避免整表闪烁）
    function refreshList() {
        if (listChecking) return;
        listChecking = true;
        var params = 'search=' + encodeURIComponent(currentSearch) +
                     '&status=' + encodeURIComponent(currentStatus) +
                     '&role=' + encodeURIComponent(currentRole) +
                     '&group=' + encodeURIComponent(currentGroup) +
                     '&date_from=' + encodeURIComponent(currentDateFrom) +
                     '&date_to=' + encodeURIComponent(currentDateTo) +
                     '&sort=' + encodeURIComponent(currentSort) +
                     '&dir=' + encodeURIComponent(currentDir) +
                     '&page=' + currentPage + '&limit=' + perPage + '&_=' + Date.now();
        fetch(ajaxUrl + '&' + params, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) return;
                var users = data.users || [];

                // 每次轮询都基于服务端权威值校准倒计时（即使不重绘也保持准确）
                remainingMap = {};
                users.forEach(function (u) {
                    if ((u.status === 'banned' || u.status === 'muted') && u.remaining_seconds > 0) {
                        remainingMap[u.id] = parseInt(u.remaining_seconds, 10) || 0;
                    }
                });

                if (users.length === 0) {
                    if (lastSignature !== 'EMPTY') {
                        tbody.innerHTML = <?php echo json_encode(t('admin_users_js_no_users','<tr><td colspan="12" class="text-muted text-center py-2">未找到用户。</td></tr>')); ?>;
                        lastSignature = 'EMPTY';
                    }
                    return;
                }

                // 安全检查：如果 AJAX 返回的用户数远少于初始渲染行数（且无筛选条件），
                // 可能是服务端异常，保留当前 DOM 避免表格突然变空/出现大片空白
                var initialRowCount = <?php echo count($users); ?>;
                if (initialRowCount > 1 && users.length < initialRowCount
                    && currentSearch === '' && currentStatus === '' && currentRole === ''
                    && currentGroup === '' && currentDateFrom === '' && currentDateTo === '') {
                    // 无筛选条件下返回行数少于初始渲染，跳过重绘保留当前 DOM
                    return;
                }

                // 签名未变 → 跳过重绘，保留当前 DOM（悬浮/焦点状态不丢失，无闪烁）
                var sig = computeSignature(users);
                if (sig === lastSignature) return;
                lastSignature = sig;

                tbody.innerHTML = users.map(renderRow).join('');
                syncSelectionUI();
            })
            .catch(function () {})
            .then(function () { listChecking = false; }); // 无论成败都释放去重锁
    }

    // ==================== 风险详情弹层 ====================
    function openRiskModal(userId) {
        if (!userId) return;
        var overlay = document.getElementById('risk-modal');
        var body = document.getElementById('risk-modal-body');
        if (!overlay || !body) return;
        overlay.style.display = 'flex';
        body.innerHTML = <?php echo json_encode(t('admin_users_js_loading','<p class="text-muted text-center py-2">加载中…</p>')); ?>;
        fetch('<?php echo site_url('admin/api/user_risk_detail_ajax'); ?>&user_id=' + userId, { credentials: 'same-origin' })
            .then(function (r) {
                return r.text().then(function (t) {
                    return { status: r.status, text: t };
                });
            })
            .then(function (res) {
                var d = null;
                try { d = JSON.parse(res.text); } catch (err) { d = null; }
                if (!d || d.error) {
                    var msg = d && d.error ? d.error : <?php echo json_encode(t('admin_users_js_api_error_prefix','接口返回异常（HTTP ')); ?> + res.status + <?php echo json_encode(t('admin_users_right_paren','）')); ?>;
                    body.innerHTML = '<p class="text-error text-center py-2">' + escapeHtml(msg) + '</p>' +
                        (res.text ? '<pre style="max-height:120px;overflow:auto;font-size:0.75rem;background:var(--surface-2);padding:0.5rem;border-radius:6px;">' + escapeHtml(res.text.substring(0, 300)) + '</pre>' : '');
                    return;
                }
                body.innerHTML = buildRiskDetailHtml(d);
            })
            .catch(function (e) {
                body.innerHTML = <?php echo json_encode(t('admin_users_js_load_failed_prefix','<p class="text-error text-center py-2">加载失败：')); ?> + escapeHtml(e && e.message ? e.message : <?php echo json_encode(t('admin_users_js_network_error','网络错误')); ?>) + '</p>';
            });
    }

    function closeRiskModal() {
        var overlay = document.getElementById('risk-modal');
        if (overlay) overlay.style.display = 'none';
    }

    // 弹层内容渲染
    function buildRiskDetailHtml(d) {
        var u = d.user, risk = d.risk, c = d.counts;
        var adminExempt = risk.level === 'admin';

        // 顶部用户信息 + 风险徽章
        var html = '';
        html += '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;margin-bottom:1rem;">';
        html += '<div><div style="font-weight:700;font-size:1.05rem;">' + escapeHtml(u.username || ('UID ' + (u.uid != null ? u.uid : '?'))) + ' <span class="text-muted" style="font-weight:400;">(UID ' + escapeHtml(u.uid) + ')</span></div>';
        html += '<div class="text-muted" style="font-size:0.8rem;">' + (u.email_visible ? escapeHtml(u.email) : <?php echo json_encode(t('admin_users_email_hidden','已隐藏')); ?>) + <?php echo json_encode(t('admin_users_js_registered_at',' · 注册于 ')); ?> + escapeHtml(u.created_at) + <?php echo json_encode(t('admin_users_js_post_count',' · 帖子 ')); ?> + u.post_count + <?php echo json_encode(t('admin_users_js_reply_count',' · 回复 ')); ?> + u.reply_count + '</div></div>';
        html += '<span class="badge" style="background:' + escapeHtml(risk.color) + ';color:#fff;">' + escapeHtml(u.status_fmt) + <?php echo json_encode(t('admin_users_js_risk_label',' · 风险 ')); ?> + escapeHtml(risk.label) + <?php echo json_encode(t('admin_users_left_paren','（')); ?> + (parseInt(risk.score, 10) || 0) + <?php echo json_encode(t('admin_users_score_close',' 分）')); ?> + '</span>';
        html += '</div>';

        // 评分构成
        html += '<div class="card" style="padding:1rem;margin-bottom:1rem;background:var(--surface-2);">';
        html += <?php echo json_encode(t('admin_users_risk_score_breakdown', '<div style="font-weight:600;margin-bottom:0.5rem;">评分构成</div>')); ?>;
        if (adminExempt) {
            html += <?php echo json_encode(t('admin_users_risk_admin_exempt_note', '<p class="text-muted mb-0">管理员账号不参与风险评分。</p>')); ?>;
        } else {
            html += '<table class="data-table data-table-compact" style="width:100%;">';
            html += <?php echo json_encode(t('admin_users_risk_table_header','<tr><th>维度</th><th>数量</th><th>分值</th></tr>')); ?>;
            html += <?php echo json_encode(t('admin_users_js_row_resolved_reports','<tr><td>确认违规举报</td><td>')); ?> + (c.resolved_report_count|0) + '</td><td>+' + ((c.resolved_report_count|0) * 6) + '</td></tr>';
            html += <?php echo json_encode(t('admin_users_js_row_pending_reports','<tr><td>待处理举报</td><td>')); ?> + (c.pending_report_count|0) + '</td><td>+' + ((c.pending_report_count|0) * 2) + '</td></tr>';
            html += <?php echo json_encode(t('admin_users_js_row_sensitive_review','<tr><td>需审核/拦截敏感词</td><td>')); ?> + (c.sensitive_review_count|0) + '</td><td>+' + ((c.sensitive_review_count|0) * 3) + '</td></tr>';
            html += <?php echo json_encode(t('admin_users_js_row_sensitive_replace','<tr><td>仅替换敏感词（已清洗）</td><td>')); ?> + (c.sensitive_replace_count|0) + <?php echo json_encode(t('admin_users_risk_no_score','</td><td>不计分</td></tr>')); ?>;
            html += <?php echo json_encode(t('admin_users_risk_rejected_report','<tr><td>被驳回举报（反向减分）</td><td>')); ?> + (c.rejected_report_count|0) + '</td><td>-' + ((c.rejected_report_count|0) * 3) + '</td></tr>';
            html += <?php echo json_encode(t('admin_users_js_row_rejected_appeal','<tr><td>申诉被拒</td><td>')); ?> + (c.rejected_appeal_count|0) + '</td><td>+' + ((c.rejected_appeal_count|0) * 4) + '</td></tr>';
            var statusBonus = u.status === 'muted' ? [<?php echo json_encode(t('admin_users_js_status_muted','禁言中')); ?>, '+10'] : (u.status === 'banned' ? [<?php echo json_encode(t('admin_users_js_status_banned','封禁中')); ?>, '+20'] : [<?php echo json_encode(t('admin_users_js_none','无')); ?>, '0']);
            html += <?php echo json_encode(t('admin_users_js_row_status_bonus','<tr><td>状态加成</td><td>')); ?> + statusBonus[0] + '</td><td>' + statusBonus[1] + '</td></tr>';
            html += '<tr><th colspan="2">' + <?php echo json_encode(t('admin_users_risk_total','风险总分')); ?> + '</th><th>' + (parseInt(risk.score, 10) || 0) + <?php echo json_encode(t('admin_users_left_paren','（')); ?> + escapeHtml(risk.label) + <?php echo json_encode(t('admin_users_right_paren','）')); ?> + '</th></tr>';
            html += '</table>';
        }
        html += '</div>';

        // 被举报记录
        html += <?php echo json_encode(t('admin_users_js_reports_section','<div style="font-weight:600;margin-bottom:0.5rem;">被举报记录</div>')); ?>;
        if (!d.reports || d.reports.length === 0) {
            html += <?php echo json_encode(t('admin_users_js_empty_note','<p class="text-muted" style="font-size:0.85rem;">无</p>')); ?>;
        } else {
            html += '<div class="risk-record-list">';
            d.reports.forEach(function (r) {
                var statusTxt = r.status === 'pending' ? <?php echo json_encode(t('admin_users_js_report_pending','<span class="badge badge-warning">待处理</span>')); ?> : (r.status === 'resolved' ? <?php echo json_encode(t('admin_users_js_report_resolved','<span class="badge badge-success">已确认违规</span>')); ?> : <?php echo json_encode(t('admin_users_js_report_rejected','<span class="badge badge-secondary">已驳回</span>')); ?>);
                html += '<div class="risk-record-item">';
                html += '<div class="risk-record-head"><span>' + escapeHtml(r.created_at_fmt) + ' · ' + escapeHtml(r.target_label) + ' · ' + escapeHtml(r.reason_type) + '</span>' + statusTxt + '</div>';
                html += '<div class="risk-record-body">' + escapeHtml(r.content_preview) + '</div>';
                html += '</div>';
            });
            html += '</div>';
        }

        // 敏感词记录
        html += <?php echo json_encode(t('admin_users_js_sensitive_section','<div style="font-weight:600;margin:1rem 0 0.5rem;">敏感词命中记录</div>')); ?>;
        if (!d.sensitive_logs || d.sensitive_logs.length === 0) {
            html += <?php echo json_encode(t('admin_users_js_empty_note','<p class="text-muted" style="font-size:0.85rem;">无</p>')); ?>;
        } else {
            html += '<div class="risk-record-list">';
            d.sensitive_logs.forEach(function (s) {
                html += '<div class="risk-record-item">';
                html += '<div class="risk-record-head"><span>' + escapeHtml(s.created_at_fmt) + ' · ' + escapeHtml(s.content_type) + '</span><span class="badge badge-danger" style="font-size:0.7rem;">' + escapeHtml(s.matched_word) + '</span></div>';
                if (s.snippet) html += '<div class="risk-record-body">' + escapeHtml(s.snippet) + '</div>';
                html += '</div>';
            });
            html += '</div>';
        }

        // 申诉记录
        html += <?php echo json_encode(t('admin_users_js_appeals_section','<div style="font-weight:600;margin:1rem 0 0.5rem;">申诉记录</div>')); ?>;
        if (!d.appeals || d.appeals.length === 0) {
            html += <?php echo json_encode(t('admin_users_js_empty_note','<p class="text-muted" style="font-size:0.85rem;">无</p>')); ?>;
        } else {
            html += '<div class="risk-record-list">';
            d.appeals.forEach(function (ap) {
                var statusTxt = ap.status === 'pending' ? <?php echo json_encode(t('admin_users_js_appeal_pending','<span class="badge badge-warning">待审核</span>')); ?> : (ap.status === 'approved' ? <?php echo json_encode(t('admin_users_js_appeal_approved','<span class="badge badge-success">已通过</span>')); ?> : <?php echo json_encode(t('admin_users_js_appeal_rejected','<span class="badge badge-danger">已拒绝</span>')); ?>);
                html += '<div class="risk-record-item">';
                html += '<div class="risk-record-head"><span>' + escapeHtml(ap.created_at_fmt) + ' · ' + escapeHtml(ap.appeal_type_fmt) + '</span>' + statusTxt + '</div>';
                html += '<div class="risk-record-body">' + escapeHtml(ap.appeal_reason) + '</div>';
                if (ap.admin_note) html += <?php echo json_encode(t('admin_users_js_admin_note','<div class="risk-record-note">管理员备注：')); ?> + escapeHtml(ap.admin_note) + '</div>';
                html += '</div>';
            });
            html += '</div>';
        }

        return html;
    }

    // 事件委托（轮询重绘后依然有效）：打开详情 / 关闭弹层
    document.addEventListener('click', function (e) {
        // data-confirm 确认框（事件委托，兼容 AJAX 动态生成的操作链接与内联动作表单）
        var confirmLink = e.target.closest('a[data-confirm], form[data-confirm]');
        if (confirmLink) {
            var message = confirmLink.getAttribute('data-confirm');
            if (!window.confirm(message)) {
                e.preventDefault();
                return;
            }
        }
        var openBtn = e.target.closest('[data-risk-user]');
        if (openBtn) {
            e.preventDefault();
            openRiskModal(parseInt(openBtn.getAttribute('data-risk-user'), 10) || 0);
            return;
        }
        if (e.target.closest('[data-close-risk-modal]')) {
            closeRiskModal();
            return;
        }
        var overlay = document.getElementById('risk-modal');
        if (overlay && e.target === overlay) {
            closeRiskModal();
        }
        // 用户详情抽屉
        var drawerTrigger = e.target.closest('[data-open-drawer]');
        if (drawerTrigger) {
            e.preventDefault();
            openUserDrawer(parseInt(drawerTrigger.getAttribute('data-open-drawer'), 10) || 0);
            return;
        }
        if (e.target.closest('[data-close-drawer]')) {
            closeDrawer();
            return;
        }
        var drawerOverlay = document.getElementById('user-drawer-overlay');
        if (drawerOverlay && e.target === drawerOverlay) {
            closeDrawer();
        }
        // 排序表头
        var sortTh = e.target.closest('.sort-th');
        if (sortTh) {
            e.preventDefault();
            var field = sortTh.getAttribute('data-sort');
            if (field) applySort(field);
            return;
        }
        // 批量通知弹窗关闭
        if (e.target.closest('[data-close-notify]')) {
            closeNotify();
            return;
        }
        var notifyOverlay = document.getElementById('notify-overlay');
        if (notifyOverlay && e.target === notifyOverlay) {
            closeNotify();
        }
        // 操作下拉菜单
        var dropdownToggle = e.target.closest('[data-toggle-dropdown]');
        if (dropdownToggle) {
            e.preventDefault();
            var menu = dropdownToggle.parentElement.querySelector('.dropdown-menu');
            var isOpen = menu && menu.classList.contains('open');
            document.querySelectorAll('.dropdown-menu.open').forEach(function (m) { m.classList.remove('open'); });
            if (menu && !isOpen) menu.classList.add('open');
            return;
        }
        if (!e.target.closest('.dropdown-menu')) {
            document.querySelectorAll('.dropdown-menu.open').forEach(function (m) { m.classList.remove('open'); });
        }
    });

    // 状态下拉框变更即提交（与服务器筛选保持同步，并回到第 1 页）
    if (statusSelect) {
        statusSelect.addEventListener('change', function () {
            currentStatus = this.value;
            currentPage = 1;
            if (this.form) this.form.submit();
        });
    }
    // 角色 / 等级下拉框变更即提交（与状态筛选行为一致）
    if (roleSelect) {
        roleSelect.addEventListener('change', function () {
            currentRole = this.value;
            currentPage = 1;
            if (this.form) this.form.submit();
        });
    }
    if (groupSelect) {
        groupSelect.addEventListener('change', function () {
            currentGroup = this.value;
            currentPage = 1;
            if (this.form) this.form.submit();
        });
    }

    // ==================== 选中 / 批量 / 排序 / 抽屉 / 通知 ====================

    // 同步批量工具条与全选框状态
    function syncSelectionUI() {
        var checks = tbody.querySelectorAll('.row-check');
        var allChecked = checks.length > 0;
        checks.forEach(function (c) { if (!c.checked) allChecked = false; });
        var selAll = document.getElementById('select-all');
        if (selAll) selAll.checked = allChecked && checks.length > 0;
        var bar = document.getElementById('bulk-bar');
        var cnt = document.getElementById('bulk-count');
        if (bar && cnt) {
            cnt.textContent = selectedIds.size;
            bar.style.display = selectedIds.size > 0 ? 'block' : 'none';
        }
    }

    // 排序：点击表头切换
    function applySort(field) {
        if (currentSort === field) {
            currentDir = (currentDir === 'asc') ? 'desc' : 'asc';
        } else {
            currentSort = field;
            currentDir = (field === 'username') ? 'asc' : 'desc';
        }
        currentPage = 1;
        document.querySelectorAll('.sort-th').forEach(function (a) {
            var f = a.getAttribute('data-sort');
            a.classList.remove('sort-active');
            a.textContent = a.textContent.replace(/ [▲▼]$/, '');
            if (f === currentSort) {
                a.classList.add('sort-active');
                a.textContent = a.textContent + (currentDir === 'asc' ? ' ▲' : ' ▼');
            }
        });
        refreshList();
        try {
            var url = new URL(window.location.href);
            url.searchParams.set('sort', currentSort);
            url.searchParams.set('dir', currentDir);
            history.replaceState(null, '', url.toString());
        } catch (e) {}
    }

    // 用户详情抽屉
    function openUserDrawer(userId) {
        if (!userId) return;
        var overlay = document.getElementById('user-drawer-overlay');
        var panel = document.getElementById('user-drawer-body');
        if (!overlay || !panel) return;
        overlay.classList.add('open');
        overlay.style.display = 'flex';
        panel.innerHTML = <?php echo json_encode(t('admin_users_js_loading','<p class="text-muted text-center py-2">加载中…</p>')); ?>;
        fetch(detailUrl + '&user_id=' + userId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success || !d.detail) {
                    panel.innerHTML = <?php echo json_encode(t('admin_users_js_drawer_load_failed','<p class="text-error text-center py-2">加载失败。</p>')); ?>;
                    return;
                }
                panel.innerHTML = buildUserDrawerHtml(d.detail);
            })
            .catch(function () {
                panel.innerHTML = <?php echo json_encode(t('admin_users_js_drawer_load_failed','<p class="text-error text-center py-2">加载失败。</p>')); ?>;
            });
    }
    function closeDrawer() {
        var overlay = document.getElementById('user-drawer-overlay');
        if (overlay) { overlay.classList.remove('open'); overlay.style.display = 'none'; }
    }
    function buildUserDrawerHtml(u) {
        var html = '';
        html += '<div class="drawer-head">';
        html += '<img src="' + escapeHtml(u.avatar) + '" alt="" class="avatar" style="width:48px;height:48px;border-radius:50%;">';
        html += '<div style="flex:1;"><div style="font-weight:700;font-size:1.05rem;">' + escapeHtml(u.username || ('UID ' + (u.uid != null ? u.uid : '?'))) + ' <span class="text-muted" style="font-weight:400;">(UID ' + escapeHtml(u.uid != null ? String(u.uid) : '-') + ')</span></div>';
        html += '<div class="text-muted" style="font-size:0.8rem;">' + (u.email_visible ? escapeHtml(u.email) : <?php echo json_encode(t('admin_users_email_hidden','已隐藏')); ?>) + '</div></div>';
        html += <?php echo json_encode(t('admin_users_js_drawer_close_btn','<button type="button" class="btn btn-sm btn-secondary" data-close-drawer>关闭</button></div>')); ?>;

        html += '<div class="drawer-stat-row">';
        html += '<div class="drawer-stat"><div class="ds-value">' + escapeHtml(u.status_fmt) + <?php echo json_encode(t('admin_users_js_label_status','</div><div class="ds-label">状态</div></div>')); ?>;
        html += '<div class="drawer-stat"><div class="ds-value">' + escapeHtml(u.group_title) + <?php echo json_encode(t('admin_users_js_label_group','</div><div class="ds-label">等级</div></div>')); ?>;
        html += '<div class="drawer-stat"><div class="ds-value">' + (parseInt(u.points,10)||0) + '</div><div class="ds-label">' + <?php echo json_encode(t('admin_users_th_points','积分')); ?> + '</div></div>';
        html += '<div class="drawer-stat"><div class="ds-value">' + (parseInt(u.coins,10)||0) + <?php echo json_encode(t('admin_users_js_label_coins','</div><div class="ds-label">金币</div></div>')); ?>;
        html += '</div>';

        html += '<div class="drawer-section"><div class="ds-k">' + <?php echo json_encode(t('admin_users_drawer_risk_score','风险评分')); ?> + '</div><div class="ds-v"><span class="badge" style="background:' + escapeHtml(u.risk.color) + ';color:#fff;">' + escapeHtml(u.risk.label) + <?php echo json_encode(t('admin_users_left_paren','（')); ?> + (parseInt(u.risk.score,10)||0) + <?php echo json_encode(t('admin_users_score_close',' 分）')); ?> + '</span></div></div>';
        // 角色分级文本：超级管理员 / 社区管理员 / 普通用户（两级管理员体系）
        var roleText = u.role === 'admin' ? <?php echo json_encode(t('admin_users_super_admin_badge','超级管理员')); ?> : (u.is_community_admin ? <?php echo json_encode(t('common_community_admin','社区管理员')); ?> : <?php echo json_encode(t('admin_users_filter_user','普通用户')); ?>);
        html += <?php echo json_encode(t('admin_users_js_drawer_role','<div class="drawer-section"><div class="ds-k">角色</div><div class="ds-v">')); ?> + escapeHtml(roleText) + '</div></div>';
        if (u.signature) html += <?php echo json_encode(t('admin_users_js_drawer_signature','<div class="drawer-section"><div class="ds-k">签名</div><div class="ds-v">')); ?> + escapeHtml(u.signature) + '</div></div>';
        html += '<div class="drawer-section"><div class="ds-k">' + <?php echo json_encode(t('admin_users_th_created_at','注册时间')); ?> + '</div><div class="ds-v">' + escapeHtml(u.created_at_fmt) + <?php echo json_encode(t('admin_users_left_paren','（')); ?> + (u.reg_days||0) + <?php echo json_encode(t('admin_users_js_drawer_days_ago',' 天前）</div></div>')); ?>;
        html += <?php echo json_encode(t('admin_users_js_drawer_last_active','<div class="drawer-section"><div class="ds-k">最后活跃</div><div class="ds-v">')); ?> + escapeHtml(u.last_active_txt) + '</div></div>';
        if ((u.status === 'banned' || u.status === 'muted') && u.status_reason) {
            html += <?php echo json_encode(t('admin_users_js_drawer_reason','<div class="drawer-section"><div class="ds-k">原因</div><div class="ds-v">')); ?> + escapeHtml(u.status_reason) + '</div></div>';
        }
        if (u.checkin_streak > 0) html += <?php echo json_encode(t('admin_users_js_drawer_checkin','<div class="drawer-section"><div class="ds-k">连续签到</div><div class="ds-v">')); ?> + (parseInt(u.checkin_streak,10)||0) + <?php echo json_encode(t('admin_users_js_drawer_days',' 天</div></div>')); ?>;

        html += <?php echo json_encode(t('admin_users_js_drawer_stats_title','<div class="drawer-subtitle">活动统计</div>')); ?>;
        html += '<div class="drawer-stat-row">';
        html += '<div class="drawer-stat"><div class="ds-value">' + (u.counts.post_count||0) + <?php echo json_encode(t('admin_users_js_label_posts','</div><div class="ds-label">帖子</div></div>')); ?>;
        html += '<div class="drawer-stat"><div class="ds-value">' + (u.counts.reply_count||0) + <?php echo json_encode(t('admin_users_js_label_replies','</div><div class="ds-label">回复</div></div>')); ?>;
        html += '<div class="drawer-stat"><div class="ds-value">' + (u.counts.resolved_report_count||0) + <?php echo json_encode(t('admin_users_js_label_resolved_reports','</div><div class="ds-label">违规举报</div></div>')); ?>;
        html += '<div class="drawer-stat"><div class="ds-value">' + (u.counts.pending_report_count||0) + <?php echo json_encode(t('admin_users_js_label_pending_reports','</div><div class="ds-label">待处理</div></div>')); ?>;
        html += '</div>';
        html += '<div class="drawer-stat-row" style="margin-top:0.5rem;">';
        html += '<div class="drawer-stat"><div class="ds-value">' + (u.counts.sensitive_hit_count||0) + <?php echo json_encode(t('admin_users_js_label_sensitive','</div><div class="ds-label">敏感词</div></div>')); ?>;
        html += '<div class="drawer-stat"><div class="ds-value">' + (u.counts.rejected_appeal_count||0) + <?php echo json_encode(t('admin_users_js_label_rejected_appeals','</div><div class="ds-label">申诉被拒</div></div>')); ?>;
        html += '</div>';

        if (u.recent_posts && u.recent_posts.length) {
            html += <?php echo json_encode(t('admin_users_js_drawer_recent_posts','<div class="drawer-subtitle">最近帖子</div>')); ?>;
            u.recent_posts.forEach(function (p) {
                html += '<div class="drawer-list-item"><span>' + escapeHtml(p.title) + '</span><span class="text-muted" style="font-size:0.72rem;">' + escapeHtml(p.created_at) + '</span></div>';
            });
        }
        if (u.recent_replies && u.recent_replies.length) {
            html += <?php echo json_encode(t('admin_users_js_drawer_recent_replies','<div class="drawer-subtitle">最近回复</div>')); ?>;
            u.recent_replies.forEach(function (r) {
                var preview = (r.content || '').replace(/<[^>]+>/g, '');
                if (preview.length > 40) preview = preview.substring(0, 40) + '…';
                html += '<div class="drawer-list-item"><span>' + escapeHtml(preview) + '</span><span class="text-muted" style="font-size:0.72rem;">' + escapeHtml(r.created_at) + '</span></div>';
            });
        }
        if (u.ban_history && u.ban_history.length) {
            html += <?php echo json_encode(t('admin_users_js_drawer_ban_history','<div class="drawer-subtitle">封禁 / 申诉历史</div>')); ?>;
            u.ban_history.forEach(function (ap) {
                var st = ap.status === 'pending' ? <?php echo json_encode(t('admin_users_js_ban_pending','待审核')); ?> : (ap.status === 'approved' ? <?php echo json_encode(t('admin_users_js_ban_approved','已通过')); ?> : <?php echo json_encode(t('admin_users_js_ban_rejected','已拒绝')); ?>);
                html += '<div class="drawer-list-item"><span>' + escapeHtml(ap.reason || '') + '</span><span class="text-muted" style="font-size:0.72rem;">' + escapeHtml(st) + '</span></div>';
            });
        }
        // 邮箱披露申请入口：非超管且该用户邮箱不可见时显示（隐私控制）
        if (!IS_SUPER && !u.email_visible) {
            html += '<div class="drawer-actions" style="margin-top:1rem;">';
            if (u.edr_locked) {
                html += '<p class="text-muted" style="font-size:0.8rem;">' + <?php echo json_encode(t('admin_users_edr_locked_hint','已提交过披露申请，请等待管理员审核。')); ?> + '</p>';
            } else {
                html += '<button type="button" class="btn btn-primary btn-sm" data-edr-user-id="' + u.id + '" data-edr-user-name="' + escapeHtml(u.username || '') + '" onclick="openEmailDisclosure(this)">' + <?php echo json_encode(t('admin_users_js_apply_email_disclosure','申请披露邮箱')); ?> + '</button>';
            }
            html += '</div>';
        }
        return html;
    }

    // 批量通知弹窗
    function openNotify() {
        var overlay = document.getElementById('notify-overlay');
        var txt = document.getElementById('notify-target-text');
        if (txt) {
            var ids = Array.from(selectedIds);
            txt.textContent = ids.length > 0 ? (<?php echo json_encode(t('admin_users_js_notify_selected_prefix','已勾选的 ')); ?> + ids.length + <?php echo json_encode(t('admin_users_js_notify_users',' 位用户')); ?>) : <?php echo json_encode(t('admin_users_js_notify_filter_users','当前筛选结果中的用户')); ?>;
        }
        if (overlay) overlay.style.display = 'flex';
    }
    function closeNotify() {
        var overlay = document.getElementById('notify-overlay');
        if (overlay) overlay.style.display = 'none';
    }

    // 邮箱披露申请：非超管在抽屉中申请查看用户邮箱（需超管审核）
    var edrTargetId = 0;
    function openEmailDisclosure(btn) {
        edrTargetId = parseInt(btn.getAttribute('data-edr-user-id'), 10) || 0;
        var nameEl = document.getElementById('edr-target-name');
        if (nameEl) nameEl.textContent = btn.getAttribute('data-edr-user-name') || '';
        var reasonEl = document.getElementById('edr-reason');
        if (reasonEl) reasonEl.value = '';
        // 先关闭用户详情抽屉，避免弹窗与抽屉叠加显示
        closeDrawer();
        var overlay = document.getElementById('email-disclosure-overlay');
        if (overlay) overlay.style.display = 'flex';
    }
    function closeEmailDisclosure() {
        var overlay = document.getElementById('email-disclosure-overlay');
        if (overlay) overlay.style.display = 'none';
    }
    function submitEmailDisclosure() {
        if (!edrTargetId) return;
        var reasonEl = document.getElementById('edr-reason');
        var reason = reasonEl ? reasonEl.value.trim() : '';
        if (reason.length < 5) { alert(<?php echo json_encode(t('admin_users_edr_reason_short','请填写申请原因（至少 5 个字）。')); ?>); return; }
        if (reason.length > 200) { alert(<?php echo json_encode(t('admin_users_edr_reason_long','申请原因不能超过 200 字。')); ?>); return; }
        var btn = document.getElementById('edr-submit-btn');
        if (btn) btn.disabled = true;
        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'apply');
        fd.append('ajax', '1');
        fd.append('target_user_id', edrTargetId);
        fd.append('reason', reason);
        fetch('<?php echo site_url('admin/email_disclosure'); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (txt) {
                if (btn) btn.disabled = false;
                // 服务端返回 JSON：成功则提示并刷新页面（抽屉状态同步为已申请），失败则展示具体原因
                var parsed = null;
                try { parsed = JSON.parse(txt); } catch (e) { parsed = null; }
                if (parsed && typeof parsed.ok === 'boolean') {
                    if (parsed.ok) {
                        alert(parsed.message || <?php echo json_encode(t('admin_users_edr_submitted','申请已提交，等待管理员审核，审核结果将通过站内通知告知。')); ?>);
                        location.reload();
                    } else {
                        alert(parsed.message || <?php echo json_encode(t('admin_users_edr_failed','申请提交失败，请稍后重试。')); ?>);
                    }
                    return;
                }
                closeEmailDisclosure();
                // 兜底：非 JSON 响应（重定向页面），含错误提示时告知用户，否则视为提交成功
                if (txt.indexOf('alert alert-error') !== -1) {
                    alert(<?php echo json_encode(t('admin_users_edr_failed','申请提交失败，请稍后重试。')); ?>);
                } else {
                    alert(<?php echo json_encode(t('admin_users_edr_submitted','申请已提交，等待管理员审核，审核结果将通过站内通知告知。')); ?>);
                    location.reload();
                }
            })
            .catch(function () {
                if (btn) btn.disabled = false;
                alert(<?php echo json_encode(t('admin_users_edr_network_error','网络错误，请重试。')); ?>);
            });
    }
    // 弹窗关闭按钮绑定
    var edrOverlay = document.getElementById('email-disclosure-overlay');
    if (edrOverlay) {
        edrOverlay.addEventListener('click', function (e) {
            if (e.target && (e.target.getAttribute('data-close-email-disclosure') !== null || e.target === edrOverlay)) {
                closeEmailDisclosure();
            }
        });
    }

    // 抽屉/弹窗按钮使用内联 onclick，函数须在全局作用域可见；IIFE 内声明需显式挂载到 window
    window.openEmailDisclosure = openEmailDisclosure;
    window.submitEmailDisclosure = submitEmailDisclosure;

    // 初始化：绑定事件
    initFromDom();

    // 全选当前页
    var selAll = document.getElementById('select-all');
    if (selAll) {
        selAll.addEventListener('change', function () {
            var checks = tbody.querySelectorAll('.row-check');
            checks.forEach(function (c) {
                c.checked = selAll.checked;
                var id = parseInt(c.value, 10);
                if (selAll.checked) selectedIds.add(id); else selectedIds.delete(id);
            });
            syncSelectionUI();
        });
    }
    // 行 checkbox 变化（事件委托在 tbody，重绘后仍有效）
    tbody.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('row-check')) {
            var id = parseInt(e.target.value, 10);
            if (e.target.checked) selectedIds.add(id); else selectedIds.delete(id);
            syncSelectionUI();
        }
    });
    // 批量操作下拉：动态显示额外字段
    var actionSel = document.getElementById('bulk-action-select');
    if (actionSel) {
        actionSel.addEventListener('change', function () {
            var extra = document.getElementById('bulk-extra-fields');
            if (!extra) return;
            var a = this.value, html = '';
            if (a === 'ban' || a === 'mute') {
                html = <?php echo json_encode(t('admin_users_js_bulk_reason_input','<input type="text" id="bulk-reason" class="form-control" placeholder="原因（可选）" style="width:150px;">')); ?> +
                       <?php echo json_encode(t('admin_users_js_bulk_days_input','<input type="number" id="bulk-days" class="form-control" placeholder="天数(0=永久)" style="width:120px;" min="0" value="0">')); ?>;
            } else if (a === 'set_role') {
                // 两级管理员体系：仅允许批量设为「普通用户 / 社区管理员」，管理员不可批量指定
                html = <?php echo json_encode(t('admin_users_js_bulk_role_select','<select id="bulk-role" class="form-control" style="width:auto;"><option value="user">普通用户</option><option value="community_admin">社区管理员</option></select>')); ?>;
            }
            extra.innerHTML = html;
            extra.style.display = html ? 'inline-block' : 'none';
        });
    }
    // 应用批量操作
    var applyBtn = document.getElementById('bulk-apply-btn');
    if (applyBtn) {
        applyBtn.addEventListener('click', function () {
            var action = document.getElementById('bulk-action-select').value;
            if (!action) { alert(<?php echo json_encode(t('admin_users_js_select_action','请选择批量操作')); ?>); return; }
            var ids = Array.from(selectedIds);
            if (ids.length === 0) { alert(<?php echo json_encode(t('admin_users_js_select_users','请先勾选用户')); ?>); return; }
            if (action === 'delete' && !confirm(<?php echo json_encode(t('admin_users_js_confirm_delete_prefix','确定删除选中的 ')); ?> + ids.length + <?php echo json_encode(t('admin_users_js_confirm_delete_suffix',' 位用户吗？此操作不可恢复。')); ?>)) return;
            if ((action === 'ban' || action === 'mute') && !confirm(<?php echo json_encode(t('admin_users_js_confirm_action_prefix','确定对选中的 ')); ?> + ids.length + <?php echo json_encode(t('admin_users_js_confirm_action_suffix',' 位用户执行该操作吗？')); ?>)) return;
            var fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('action', action);
            ids.forEach(function (id) { fd.append('ids[]', id); });
            if (action === 'ban' || action === 'mute') {
                fd.append('reason', (document.getElementById('bulk-reason') || {}).value || '');
                fd.append('days', (document.getElementById('bulk-days') || {}).value || '0');
            }
            if (action === 'set_role') {
                fd.append('role', (document.getElementById('bulk-role') || {}).value || 'user');
            }
            applyBtn.disabled = true;
            fetch(bulkUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    applyBtn.disabled = false;
                    if (d && d.success) {
                        alert(d.message || <?php echo json_encode(t('admin_users_js_success','操作成功')); ?>);
                        selectedIds.clear();
                        syncSelectionUI();
                        tbody.querySelectorAll('.row-check').forEach(function (c) { c.checked = false; });
                        if (selAll) selAll.checked = false;
                        refreshList();
                    } else {
                        alert(d.error || <?php echo json_encode(t('admin_users_js_failed','操作失败')); ?>);
                    }
                })
                .catch(function () { applyBtn.disabled = false; alert(<?php echo json_encode(t('admin_users_js_network_error','网络错误')); ?>); });
        });
    }
    // 清空选择
    var clearBtn = document.getElementById('bulk-clear-btn');
    if (clearBtn) clearBtn.addEventListener('click', function () {
        selectedIds.clear();
        syncSelectionUI();
        tbody.querySelectorAll('.row-check').forEach(function (c) { c.checked = false; });
        if (selAll) selAll.checked = false;
    });
    // 导出 CSV
    var exportBtn = document.getElementById('export-csv-btn');
    if (exportBtn) {
        exportBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var params = 'search=' + encodeURIComponent(currentSearch) +
                '&status=' + encodeURIComponent(currentStatus) +
                '&role=' + encodeURIComponent(currentRole) +
                '&group=' + encodeURIComponent(currentGroup) +
                '&date_from=' + encodeURIComponent(currentDateFrom) +
                '&date_to=' + encodeURIComponent(currentDateTo);
            window.location.href = exportUrl + '&' + params;
        });
    }
    // 批量通知发送
    var notifySend = document.getElementById('notify-send-btn');
    if (notifySend) {
        notifySend.addEventListener('click', function () {
            var content = (document.getElementById('notify-content') || {}).value || '';
            if (!content.trim()) { alert(<?php echo json_encode(t('admin_users_js_input_content','请输入站内信内容')); ?>); return; }
            var useFilter = document.getElementById('notify-scope-filter') && document.getElementById('notify-scope-filter').checked;
            var ids = Array.from(selectedIds);
            if (!useFilter && ids.length === 0) { alert(<?php echo json_encode(t('admin_users_js_notify_select_hint','请先勾选用户，或勾选“发送给当前筛选结果”。')); ?>); return; }
            if (!confirm(<?php echo json_encode(t('admin_users_js_confirm_send','确定发送站内信？')); ?> + (useFilter ? <?php echo json_encode(t('admin_users_js_send_to_filter','将发送给当前筛选结果中的所有用户。')); ?> : (<?php echo json_encode(t('admin_users_js_send_to_selected_prefix','将发送给选中的 ')); ?> + ids.length + <?php echo json_encode(t('admin_users_js_send_to_selected_suffix',' 位用户。')); ?>)))) return;
            var fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('action', 'send_pm');
            fd.append('content', content);
            if (useFilter) {
                fd.append('scope', 'filter');
                fd.append('search', currentSearch); fd.append('status', currentStatus);
                fd.append('role', currentRole); fd.append('group', currentGroup);
                fd.append('date_from', currentDateFrom); fd.append('date_to', currentDateTo);
            } else {
                ids.forEach(function (id) { fd.append('ids[]', id); });
            }
            notifySend.disabled = true;
            fetch(bulkUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    notifySend.disabled = false;
                    if (d && d.success) { alert(d.message || <?php echo json_encode(t('admin_users_js_sent','发送成功')); ?>); closeNotify(); }
                    else { alert(d.error || <?php echo json_encode(t('admin_users_js_send_failed','发送失败')); ?>); }
                })
                .catch(function () { notifySend.disabled = false; alert(<?php echo json_encode(t('admin_users_js_network_error','网络错误')); ?>); });
        });
    }
    // 批量通知按钮
    var notifyBtn = document.getElementById('bulk-notify-btn');
    if (notifyBtn) notifyBtn.addEventListener('click', openNotify);

    // 初始化排序表头箭头（页面初始带 sort 参数时）
    if (currentSort) {
        document.querySelectorAll('.sort-th').forEach(function (a) {
            if (a.getAttribute('data-sort') === currentSort) {
                a.classList.add('sort-active');
                a.textContent = a.textContent + (currentDir === 'asc' ? ' ▲' : ' ▼');
            }
        });
    }
    syncSelectionUI();

    // 启动倒计时（每秒递减计数器）
    setInterval(tickRemaining, TICK_INTERVAL);

    // 启动列表刷新（每 2 秒从服务端校准；配合签名比对，数据无变化时不重绘，避免闪烁）
    setInterval(refreshList, POLL_INTERVAL);
})();
</script>
