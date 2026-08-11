<?php
/**
 * 云界论坛 - 管理后台申诉审核（封禁申诉 + 禁言申诉）
 *
 * 列表展示所有申诉，支持按状态与申诉类型筛选。
 * 审核操作：通过（自动解除对应处罚 + 邮件通知 + 站内通知）/ 拒绝（邮件通知 + 站内通知）。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';
require_once APP_ROOT . 'app/includes/mailer.php'; // functions.php 已懒加载 mailer，本文件审核通过后直接调用 send_mail，需自行引入

// 权限门禁：申诉管理（超管天然通过；社区管理员需 manage_ban_appeals 权限）
require_permission('manage_ban_appeals');

$db = get_db();
$action = $_GET['action'] ?? 'list';
$appealId = (int)($_GET['appeal_id'] ?? 0);

// 审核处理：通过 / 拒绝
// 注意：CSRF 校验失败必须明确提示，否则管理员点击"通过/拒绝"会静默失败，
// 造成"点了没反应 / 账号没解封"的假象（多标签页操作、登录 token 轮换等都会触发）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash(t('admin_appeals_flash_csrf_form', '安全校验失败（表单已过期），请刷新页面后重新操作。'), 'error');
        redirect('/admin/ban_appeals');
    }
    // 删除申诉记录（POST 化，替代原 GET 删除链接）
    if (($_POST['action'] ?? '') === 'delete') {
        $delAppealId = (int)($_POST['appeal_id'] ?? 0);
        if ($delAppealId > 0) {
            $db->prepare("DELETE FROM ban_appeals WHERE id = :id")->execute([':id' => $delAppealId]);
            set_flash(t('admin_appeals_flash_deleted', '申诉记录已删除。'), 'success');
        }
        redirect('/admin/ban_appeals');
    }
    if (!isset($_POST['handle_appeal'])) {
        redirect('/admin/ban_appeals');
    }

    $id = (int)($_POST['appeal_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';   // approve / reject
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($id <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
        set_flash(t('admin_appeals_flash_invalid_params', '参数无效。'), 'error');
        redirect('/admin/ban_appeals');
    }

    // 取出申诉记录
    $stmt = $db->prepare("SELECT * FROM ban_appeals WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $appeal = $stmt->fetch();

    if (!$appeal) {
        set_flash(t('admin_appeals_flash_not_found', '申诉记录不存在。'), 'error');
        redirect('/admin/ban_appeals');
    }
    if ($appeal['status'] !== 'pending') {
        set_flash(t('admin_appeals_flash_already_handled', '该申诉已处理，无法重复操作。'), 'error');
        redirect('/admin/ban_appeals');
    }

    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
    $operatorId = (int)($_SESSION['user_id'] ?? 0);
    $username = $appeal['username'];
    $email = $appeal['email'];
    $userId = (int)$appeal['user_id'];

    // 用事务包裹：申诉状态更新 + 用户解封必须原子生效，避免半成功状态
    $db->beginTransaction();
    try {
        // 更新申诉记录
        $updateStmt = $db->prepare("UPDATE ban_appeals SET status = :status, admin_note = :note, handled_by = :oid, handled_at = CURRENT_TIMESTAMP WHERE id = :id");
        $updateStmt->execute([
            ':status' => $newStatus,
            ':note'   => $adminNote,
            ':oid'    => $operatorId,
            ':id'     => $id,
        ]);

        if ($decision === 'approve' && $userId > 0) {
            // 通过：按申诉类型自动解除对应处罚（优先执行，保证即使后续通知/邮件出错，解除也已生效）
            if (($appeal['appeal_type'] ?? 'ban') === 'mute') {
                $db->prepare("UPDATE users SET status = 'active', muted_until = NULL, status_reason = '' WHERE id = :id")
                    ->execute([':id' => $userId]);
            } else {
                $db->prepare("UPDATE users SET status = 'active', banned_until = NULL, status_reason = '' WHERE id = :id")
                    ->execute([':id' => $userId]);
            }
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        set_flash(t('admin_appeals_flash_handle_failed', '处理失败：{error}', ['error' => $e->getMessage()]), 'error');
        redirect('/admin/ban_appeals');
    }

    $isMuteAppeal = ($appeal['appeal_type'] ?? 'ban') === 'mute';
    $penaltyLabel = $isMuteAppeal ? t('admin_ban_appeals_e0c932','禁言') : t('admin_ban_appeals_059962','封禁');
    $restoreLabel = $isMuteAppeal ? t('admin_ban_appeals_b93a7b','解除禁言') : t('admin_ban_appeals_47db9a','解封');
    $restoredText = $isMuteAppeal ? t('admin_ban_appeals_c7e31d','账号已解除禁言') : t('admin_ban_appeals_f316fd','账号已解封');

    if ($decision === 'approve') {
        if ($userId <= 0) {
            // 申诉记录未关联有效用户，无法自动解除处罚（保留闪存警告，申诉状态已标记）
            set_flash(t('admin_appeals_flash_approved_no_user', '已通过 {username} 的申诉，但申诉记录未关联有效用户（ID 无效），无法自动解除处罚，请到用户管理中手动处理。', ['username' => $username]), 'warning');
            redirect('/admin/ban_appeals');
        }

        // 站内通知
        send_notification(
            $userId,
            'appeal_approved',
            t('admin_ban_appeals_f06830','申诉已通过'),
            t('admin_ban_appeals_approved_body', '你的{penalty}申诉已通过审核，{restored}，可以正常使用账号。', ['penalty' => $penaltyLabel, 'restored' => $restoredText]) . ($adminNote !== '' ? t('admin_ban_appeals_2b49c0','管理员备注：') . $adminNote : '')
        );

        // 邮件通知
        if ($email !== '') {
            $subject = '【' . SITE_NAME . t('admin_ban_appeals_2646ba','】你的') . $penaltyLabel . t('admin_ban_appeals_f06830','申诉已通过');
            $body  = t('admin_ban_appeals_e31d49','<p>你好，<strong>') . e($username) . '</strong>：</p>';
            $body .= t('admin_ban_appeals_cc097e','<p>你的') . $penaltyLabel . t('admin_ban_appeals_b2b4b9','申诉已通过管理员审核，') . $restoredText . t('admin_ban_appeals_9f182e','，现在可以正常使用 ') . e(SITE_NAME) . '。</p>';
            if ($adminNote !== '') {
                $body .= t('admin_ban_appeals_129012','<p style="background:#f0fdf4;padding:12px 16px;border-left:4px solid #10b981;border-radius:6px;margin:12px 0;">管理员备注：') . e($adminNote) . '</p>';
            }
            $body = render_email_template($penaltyLabel . t('admin_ban_appeals_f06830','申诉已通过'), $body, [
                'subject'     => $subject,
                'action_text' => t('admin_ban_appeals_8d0b9c','前往 ') . SITE_NAME,
            ]);
            send_mail($email, $username, $subject, $body, 'appeal');
        }

        set_flash(t('admin_appeals_flash_approved', '已通过 {username} 的申诉，{restored}。', ['username' => $username, 'restored' => $restoredText]), 'success');
    } else {
        // 拒绝
        send_notification(
            $userId,
            'appeal_rejected',
            t('admin_ban_appeals_a215a0','申诉未通过'),
            t('admin_ban_appeals_rejected_body', '你的{penalty}申诉未通过审核。', ['penalty' => $penaltyLabel]) . ($adminNote !== '' ? t('admin_ban_appeals_2b49c0','管理员备注：') . $adminNote : '')
        );

        if ($email !== '') {
            $subject = '【' . SITE_NAME . t('admin_ban_appeals_2646ba','】你的') . $penaltyLabel . t('admin_ban_appeals_a215a0','申诉未通过');
            $body  = t('admin_ban_appeals_e31d49','<p>你好，<strong>') . e($username) . '</strong>：</p>';
            $body .= t('admin_ban_appeals_02056d','<p>很遗憾，你的') . $penaltyLabel . t('admin_ban_appeals_8e1a69','申诉未通过管理员审核，账号将继续保持') . $penaltyLabel . t('admin_ban_appeals_a41923','状态。</p>');
            if ($adminNote !== '') {
                $body .= t('admin_ban_appeals_3507f8','<p style="background:#fef2f2;padding:12px 16px;border-left:4px solid #ef4444;border-radius:6px;margin:12px 0;">管理员备注：') . e($adminNote) . '</p>';
            }
            $body = render_email_template($penaltyLabel . t('admin_ban_appeals_a215a0','申诉未通过'), $body, ['subject' => $subject]);
            send_mail($email, $username, $subject, $body, 'appeal');
        }

        set_flash(t('admin_appeals_flash_rejected', '已拒绝 {username} 的申诉，邮件通知已发送。', ['username' => $username]), 'success');
    }

    redirect('/admin/ban_appeals');
}

// 旧 GET 删除链接命中：不执行删除，提示刷新（删除已改由上方 POST 分支处理）
if ($action === 'delete') {
    set_flash(t('post_flash_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
    redirect('/admin/ban_appeals');
}

// 筛选
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['', 'pending', 'approved', 'rejected'], true)) {
    $statusFilter = '';
}
// 申诉类型筛选：ban 封禁申诉 / mute 禁言申诉
$typeFilter = $_GET['type'] ?? '';
if (!in_array($typeFilter, ['', 'ban', 'mute'], true)) {
    $typeFilter = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = '';
$params = [];
$conds = [];
if ($statusFilter !== '') {
    $conds[] = 'status = :status';
    $params[':status'] = $statusFilter;
}
if ($typeFilter !== '') {
    $conds[] = 'appeal_type = :atype';
    $params[':atype'] = $typeFilter;
}
if (!empty($conds)) {
    $where = 'WHERE ' . implode(' AND ', $conds);
}

$total = 0;
$countStmt = $db->prepare("SELECT COUNT(*) FROM ban_appeals $where");
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare("SELECT * FROM ban_appeals $where ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$appeals = $stmt->fetchAll();

// 统计各状态数量
$stats = [
    'total'    => (int)$db->query("SELECT COUNT(*) FROM ban_appeals")->fetchColumn(),
    'pending'  => (int)$db->query("SELECT COUNT(*) FROM ban_appeals WHERE status = 'pending'")->fetchColumn(),
    'approved' => (int)$db->query("SELECT COUNT(*) FROM ban_appeals WHERE status = 'approved'")->fetchColumn(),
    'rejected' => (int)$db->query("SELECT COUNT(*) FROM ban_appeals WHERE status = 'rejected'")->fetchColumn(),
    'ban'      => (int)$db->query("SELECT COUNT(*) FROM ban_appeals WHERE appeal_type = 'ban'")->fetchColumn(),
    'mute'     => (int)$db->query("SELECT COUNT(*) FROM ban_appeals WHERE appeal_type = 'mute'")->fetchColumn(),
];

// 申诉类型显示辅助
function appeal_type_badge(?string $type): string {
    if ($type === 'mute') {
        return '<span class="badge badge-warn">' . e(t('admin_appeals_type_mute', '禁言申诉')) . '</span>';
    }
    return '<span class="badge badge-danger">' . e(t('admin_appeals_type_ban', '封禁申诉')) . '</span>';
}

$pageTitle = t('admin_appeals_title', '申诉管理');
$activeMenu = 'ban_appeals';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_appeals_title', '申诉管理')); ?></h1>
    <p class="text-muted ba-subtitle"><?php echo e(t('admin_appeals_subtitle', '统一处理封禁申诉与禁言申诉')); ?></p>
</div>

<!-- 统计卡片 -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-value" id="stat-total"><?php echo $stats['total']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_appeals_stat_total', '申诉总数')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value stat-warn" id="stat-pending"><?php echo $stats['pending']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_appeals_stat_pending', '待审核')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value stat-success" id="stat-approved"><?php echo $stats['approved']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_appeals_stat_approved', '已通过')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value stat-danger" id="stat-rejected"><?php echo $stats['rejected']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_appeals_stat_rejected', '已拒绝')); ?></div>
    </div>
</div>

<!-- 筛选 Tab -->
<div class="card">
    <div class="filter-tabs">
        <a href="<?php echo site_url('admin/ban_appeals', $typeFilter !== '' ? ['type' => $typeFilter] : []); ?>" class="filter-tab <?php echo $statusFilter === '' ? 'active' : ''; ?>"><?php echo e(t('admin_appeals_tab_all', '全部')); ?></a>
        <a href="<?php echo site_url('admin/ban_appeals', ['status' => 'pending'] + ($typeFilter !== '' ? ['type' => $typeFilter] : [])); ?>" class="filter-tab <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>"><?php echo e(t('admin_appeals_tab_pending', '待审核')); ?> <?php if ($stats['pending'] > 0) echo '<span class="badge badge-warning">' . $stats['pending'] . '</span>'; ?></a>
        <a href="<?php echo site_url('admin/ban_appeals', ['status' => 'approved'] + ($typeFilter !== '' ? ['type' => $typeFilter] : [])); ?>" class="filter-tab <?php echo $statusFilter === 'approved' ? 'active' : ''; ?>"><?php echo e(t('admin_appeals_tab_approved', '已通过')); ?></a>
        <a href="<?php echo site_url('admin/ban_appeals', ['status' => 'rejected'] + ($typeFilter !== '' ? ['type' => $typeFilter] : [])); ?>" class="filter-tab <?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>"><?php echo e(t('admin_appeals_tab_rejected', '已拒绝')); ?></a>
        <span class="filter-tab-divider"></span>
        <a href="<?php echo site_url('admin/ban_appeals', $statusFilter !== '' ? ['status' => $statusFilter] : []); ?>" class="filter-tab <?php echo $typeFilter === '' ? 'active' : ''; ?>"><?php echo e(t('admin_appeals_tab_all_types', '全部类型')); ?></a>
        <a href="<?php echo site_url('admin/ban_appeals', ['type' => 'ban'] + ($statusFilter !== '' ? ['status' => $statusFilter] : [])); ?>" class="filter-tab <?php echo $typeFilter === 'ban' ? 'active' : ''; ?>"><?php echo e(t('admin_appeals_type_ban', '封禁申诉')); ?> <?php if ($stats['ban'] > 0) echo '<span class="badge badge-danger">' . $stats['ban'] . '</span>'; ?></a>
        <a href="<?php echo site_url('admin/ban_appeals', ['type' => 'mute'] + ($statusFilter !== '' ? ['status' => $statusFilter] : [])); ?>" class="filter-tab <?php echo $typeFilter === 'mute' ? 'active' : ''; ?>"><?php echo e(t('admin_appeals_type_mute', '禁言申诉')); ?> <?php if ($stats['mute'] > 0) echo '<span class="badge badge-warning">' . $stats['mute'] . '</span>'; ?></a>
    </div>
</div>

<!-- 申诉列表 -->
<div class="card">
    <?php if (empty($appeals)): ?>
        <p class="text-muted" id="appeal-empty"><?php echo e(t('admin_ban_appeals_empty', '暂无申诉记录。')); ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?php echo e(t('admin_ban_appeals_th_type', '类型')); ?></th>
                        <th><?php echo e(t('admin_ban_appeals_th_username', '用户名')); ?></th>
                        <th><?php echo e(t('admin_ban_appeals_th_reason', '申诉理由')); ?></th>
                        <th><?php echo e(t('admin_ban_appeals_th_penalty_reason', '处罚原因')); ?></th>
                        <th><?php echo e(t('admin_ban_appeals_th_status', '状态')); ?></th>
                        <th><?php echo e(t('admin_ban_appeals_th_submitted_at', '提交时间')); ?></th>
                        <th><?php echo e(t('admin_ban_appeals_th_actions', '操作')); ?></th>
                    </tr>
                </thead>
                <tbody id="appeal-tbody">
                    <?php foreach ($appeals as $a): ?>
                        <tr>
                            <td><?php echo appeal_type_badge($a['appeal_type'] ?? 'ban'); ?></td>
                            <td>
                                <div class="font-semibold"><?php echo e($a['username']); ?></div>
                                <?php if (is_super_admin()): ?>
                                    <div class="text-muted text-xs"><?php echo e($a['email']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="ba-reason-cell">
                                <div class="ba-reason-clamp"><?php echo e(mb_substr($a['appeal_reason'], 0, 100, 'UTF-8')); ?><?php if (mb_strlen($a['appeal_reason']) > 100) echo '…'; ?></div>
                            </td>
                            <td class="ba-penalty-cell">
                                <?php if ($a['ban_reason'] !== ''): ?>
                                    <?php echo e(mb_substr($a['ban_reason'], 0, 50, 'UTF-8')); ?>
                                <?php else: ?>
                                    <span class="text-muted"><?php echo e(t('admin_ban_appeals_287a1d', '未填写')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($a['status'] === 'pending'): ?>
                                    <span class="badge badge-warning"><?php echo e(t('admin_ban_appeals_status_pending', '待审核')); ?></span>
                                <?php elseif ($a['status'] === 'approved'): ?>
                                    <span class="badge badge-success"><?php echo e(t('admin_ban_appeals_status_approved', '已通过')); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><?php echo e(t('admin_ban_appeals_status_rejected', '已拒绝')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo e(db_datetime($a['created_at'])); ?></div>
                                <?php if (!empty($a['handled_at'])): ?>
                                    <div class="text-muted text-xs"><?php echo e(t('admin_ban_appeals_handled_at', '处理于 {time}', ['time' => db_datetime($a['handled_at'])])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo site_url('admin/ban_appeals', ['action' => 'view', 'appeal_id' => (int)$a['id']]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('admin_ban_appeals_view', '查看')); ?></a>
                                <?php if ($a['status'] === 'pending'): ?>
                                    <a href="<?php echo site_url('admin/ban_appeals', ['action' => 'view', 'appeal_id' => (int)$a['id']]); ?>" class="btn btn-sm btn-primary"><?php echo e(t('admin_ban_appeals_review', '审核')); ?></a>
                                <?php endif; ?>
                                <?php echo admin_action_form(site_url('admin/ban_appeals'), 'delete', ['appeal_id' => (int)$a['id']], t('admin_ban_appeals_delete', '删除'), ['class' => 'btn btn-sm btn-danger', 'confirm' => t('admin_ban_appeals_delete_confirm', '确定删除该申诉记录吗？')]); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php echo pagination($page, $total, $perPage, site_url('admin/ban_appeals', array_merge($statusFilter !== '' ? ['status' => $statusFilter] : [], $typeFilter !== '' ? ['type' => $typeFilter] : []))); ?>
    <?php endif; ?>
</div>

<?php
// 详情 / 审核弹层
if ($action === 'view' && $appealId > 0):
    $stmt = $db->prepare("SELECT * FROM ban_appeals WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $appealId]);
    $detail = $stmt->fetch();
    if ($detail):
?>
<div class="modal-overlay ba-detail-overlay" onclick="if(event.target===this)location.href='<?php echo site_url('admin/ban_appeals'); ?>';">
    <div class="card ba-detail-card">
        <div class="card-header">
            <h2 class="card-title"><?php echo e(t('admin_ban_appeals_detail_title', '申诉详情')); ?></h2>
            <a href="<?php echo site_url('admin/ban_appeals'); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('admin_ban_appeals_close', '关闭')); ?></a>
        </div>

        <div class="ba-detail-body">
            <div class="ba-detail-grid">
                <div>
                    <div class="text-muted text-xs"><?php echo e(t('admin_ban_appeals_detail_type', '申诉类型')); ?></div>
                    <div><?php echo appeal_type_badge($detail['appeal_type'] ?? 'ban'); ?></div>
                </div>
                <div>
                    <div class="text-muted text-xs"><?php echo e(t('admin_ban_appeals_detail_username', '用户名')); ?></div>
                    <div class="font-semibold"><?php echo e($detail['username']); ?></div>
                </div>
                <?php if (is_super_admin()): ?>
                    <div>
                        <div class="text-muted text-xs"><?php echo e(t('admin_ban_appeals_detail_email', '邮箱')); ?></div>
                        <div><?php echo e($detail['email']); ?></div>
                    </div>
                <?php endif; ?>
                <div>
                    <div class="text-muted text-xs"><?php echo e(t('admin_ban_appeals_detail_submitted_at', '提交时间')); ?></div>
                    <div><?php echo e(db_datetime($detail['created_at'])); ?></div>
                </div>
                <div>
                    <div class="text-muted text-xs"><?php echo e(t('admin_ban_appeals_detail_status', '状态')); ?></div>
                    <?php if ($detail['status'] === 'pending'): ?>
                        <span class="badge badge-warning"><?php echo e(t('admin_ban_appeals_status_pending', '待审核')); ?></span>
                    <?php elseif ($detail['status'] === 'approved'): ?>
                        <span class="badge badge-success"><?php echo e(t('admin_ban_appeals_status_approved', '已通过')); ?></span>
                    <?php else: ?>
                        <span class="badge badge-danger"><?php echo e(t('admin_ban_appeals_status_rejected', '已拒绝')); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_ban_appeals_penalty_reason', '处罚原因')); ?></label>
                <div class="ba-info-box"><?php echo e($detail['ban_reason'] !== '' ? $detail['ban_reason'] : t('admin_ban_appeals_287a1d','未填写')); ?></div>
            </div>

            <?php if (!empty($detail['ban_until'])): ?>
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_ban_appeals_penalty_until', '处罚期限')); ?></label>
                <div class="ba-info-box"><?php echo e(date('Y-m-d H:i', db_time($detail['ban_until']))); ?></div>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_ban_appeals_appeal_reason', '申诉理由')); ?></label>
                <div style="padding:0.75rem;background:var(--surface-2);border-radius:var(--radius);white-space:pre-wrap;word-break:break-word;"><?php echo e($detail['appeal_reason']); ?></div>
            </div>

            <?php if ($detail['status'] !== 'pending'): ?>
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('admin_ban_appeals_admin_note', '管理员备注')); ?></label>
                    <div style="padding:0.75rem;background:var(--surface-2);border-radius:var(--radius);"><?php echo e($detail['admin_note'] !== '' ? $detail['admin_note'] : t('admin_ban_appeals_720777','无')); ?></div>
                </div>
            <?php else: ?>
                <!-- 审核表单 -->
                <form method="POST" action="<?php echo site_url('admin/ban_appeals'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="handle_appeal" value="1">
                    <input type="hidden" name="appeal_id" value="<?php echo (int)$detail['id']; ?>">

                    <div class="form-group">
                        <label class="form-label" for="admin_note"><?php echo e(t('admin_ban_appeals_admin_note_optional', '管理员备注（可选，将随邮件通知用户）')); ?></label>
                        <textarea id="admin_note" name="admin_note" class="form-control" rows="3" maxlength="500" placeholder="<?php echo e(t('admin_ban_appeals_admin_note_placeholder', '可填写审核说明，例如：经核实，确属误封。或：申诉理由不充分，维持原处罚。')); ?>"></textarea>
                    </div>

                    <div class="form-actions">
                        <?php if (($detail['appeal_type'] ?? 'ban') === 'mute'): ?>
                            <button type="submit" name="decision" value="approve" class="btn btn-success" data-confirm="<?php echo e(t('admin_ban_appeals_confirm_approve_mute', '确定通过该申诉吗？通过后账号将自动解除禁言，并发送邮件通知用户。')); ?>"><?php echo e(t('admin_ban_appeals_btn_approve_mute', '通过申诉（解除禁言）')); ?></button>
                        <?php else: ?>
                            <button type="submit" name="decision" value="approve" class="btn btn-success" data-confirm="<?php echo e(t('admin_ban_appeals_confirm_approve_ban', '确定通过该申诉吗？通过后账号将自动解封，并发送邮件通知用户。')); ?>"><?php echo e(t('admin_ban_appeals_btn_approve_ban', '通过申诉（解封）')); ?></button>
                        <?php endif; ?>
                        <button type="submit" name="decision" value="reject" class="btn btn-danger" data-confirm="<?php echo e(t('admin_ban_appeals_confirm_reject', '确定拒绝该申诉吗？将发送邮件通知用户。')); ?>"><?php echo e(t('admin_ban_appeals_btn_reject', '拒绝申诉')); ?></button>
                        <a href="<?php echo site_url('admin/ban_appeals'); ?>" class="btn btn-secondary"><?php echo e(t('admin_ban_appeals_cancel', '取消')); ?></a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
    endif;
endif;
?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>

<script>
(function () {
    'use strict';
    // 仅在列表页（非审核弹层）启动轮询
    var tbody = document.getElementById('appeal-tbody');
    var emptyEl = document.getElementById('appeal-empty');
    if (!tbody && !emptyEl) return;
    // 审核弹层打开时暂停刷新
    if (document.querySelector('.modal-overlay')) return;

    var currentStatus = '<?php echo $statusFilter; ?>';
    var currentType = '<?php echo $typeFilter; ?>';
    var currentPage = <?php echo $page; ?>;
    var perPage = <?php echo $perPage; ?>;
    var ajaxUrl = '<?php echo site_url('admin/api/ban_appeals_ajax'); ?>';
        var banAppealsUrl = '<?php echo site_url('admin/ban_appeals'); ?>';
        var csrfToken = '<?php echo csrf_token(); ?>';
        var DEL_LABEL = <?php echo json_encode(t('admin_ban_appeals_delete', '删除')); ?>;
        var DEL_CONFIRM = <?php echo json_encode(t('admin_ban_appeals_delete_confirm', '确定删除该申诉记录吗？')); ?>;
    var INTERVAL = 1000; // 1 秒（ban_appeals_ajax 服务端 1 秒缓存合并并发，不阻塞）
    var checking = false; // 请求去重：上一请求未返回时跳过本次轮询，避免堆积

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(s);
        return div.innerHTML;
    }

    function pulseStat(el, newVal) {
        if (!el) return;
        var oldVal = parseInt(el.textContent, 10) || 0;
        if (newVal !== oldVal) {
            el.textContent = newVal;
            el.classList.add('pulse');
            setTimeout(function () { el.classList.remove('pulse'); }, 600);
        }
    }

    function renderRow(a) {
        var statusBadge = '';
        if (a.status === 'pending') statusBadge = <?php echo json_encode(t('admin_ban_appeals_9718ac','<span class="badge badge-warning">待审核</span>')); ?>;
        else if (a.status === 'approved') statusBadge = <?php echo json_encode(t('admin_ban_appeals_452750','<span class="badge badge-success">已通过</span>')); ?>;
        else statusBadge = <?php echo json_encode(t('admin_ban_appeals_4e768e','<span class="badge badge-danger">已拒绝</span>')); ?>;

        var typeBadge = a.appeal_type === 'mute'
            ? <?php echo json_encode(t('admin_ban_appeals_fff06a','<span class="badge" style="background:#f59e0b;color:#fff;">禁言申诉</span>')); ?>
            : <?php echo json_encode(t('admin_ban_appeals_8908df','<span class="badge badge-danger">封禁申诉</span>')); ?>;

        var reason = a.ban_reason !== '' ? escapeHtml(a.ban_reason.length > 50 ? a.ban_reason.substring(0, 50) : a.ban_reason) : <?php echo json_encode(t('admin_ban_appeals_22f2f3','<span class="text-muted">未填写</span>')); ?>;
        var appealText = a.appeal_reason.length > 100 ? a.appeal_reason.substring(0, 100) + '…' : a.appeal_reason;

        var handledHtml = a.handled_at_fmt ? <?php echo json_encode(t('admin_ban_appeals_66f0c7','<div class="text-muted text-xs">处理于 ')); ?> + escapeHtml(a.handled_at_fmt) + '</div>' : '';

        var actionHtml = '<a href="<?php echo site_url('admin/ban_appeals'); ?>&action=view&appeal_id=' + a.id + <?php echo json_encode(t('admin_ban_appeals_803dce','" class="btn btn-sm btn-secondary">查看</a> ')); ?>;
        if (a.status === 'pending') {
            actionHtml += '<a href="<?php echo site_url('admin/ban_appeals'); ?>&action=view&appeal_id=' + a.id + <?php echo json_encode(t('admin_ban_appeals_53e582','" class="btn btn-sm btn-primary">审核</a> ')); ?>;
        }
        actionHtml += '<form method="post" action="' + banAppealsUrl + '" class="inline-action-form" data-confirm="' + escapeHtml(DEL_CONFIRM) + '" onsubmit="return confirm(this.getAttribute(\'data-confirm\'));">'
            + '<input type="hidden" name="csrf_token" value="' + csrfToken + '">'
            + '<input type="hidden" name="action" value="delete">'
            + '<input type="hidden" name="appeal_id" value="' + a.id + '">'
            + '<button type="submit" class="btn btn-sm btn-danger">' + escapeHtml(DEL_LABEL) + '</button></form> ';

        return '<tr>' +
            '<td>' + typeBadge + '</td>' +
            '<td><div style="font-weight:600;">' + escapeHtml(a.username) + '</div><div class="text-muted text-xs">' + escapeHtml(a.email) + '</div></td>' +
            '<td style="max-width:300px;"><div style="max-height:60px;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(appealText) + '</div></td>' +
            '<td style="max-width:200px;">' + reason + '</td>' +
            '<td>' + statusBadge + '</td>' +
            '<td><div>' + escapeHtml(a.created_at_fmt) + '</div>' + handledHtml + '</td>' +
            '<td>' + actionHtml + '</td>' +
            '</tr>';
    }

    function refresh() {
        if (checking) return;
        checking = true;
        var params = 'status=' + encodeURIComponent(currentStatus) + '&type=' + encodeURIComponent(currentType) + '&page=' + currentPage + '&limit=' + perPage + '&_=' + Date.now();
        fetch(ajaxUrl + '&' + params, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) return;

                // 更新统计卡片
                if (data.stats) {
                    pulseStat(document.getElementById('stat-total'), data.stats.total);
                    pulseStat(document.getElementById('stat-pending'), data.stats.pending);
                    pulseStat(document.getElementById('stat-approved'), data.stats.approved);
                    pulseStat(document.getElementById('stat-rejected'), data.stats.rejected);
                }

                // 更新列表（仅当不在审核弹层时）
                if (document.querySelector('.modal-overlay')) return;

                var appeals = data.appeals || [];
                if (appeals.length === 0) {
                    if (tbody) {
                        tbody.innerHTML = <?php echo json_encode(t('admin_ban_appeals_f9d086','<tr><td colspan="7" class="text-center text-muted">暂无申诉记录</td></tr>')); ?>;
                    }
                    return;
                }
                if (!tbody && emptyEl) {
                    // 从空状态切换到列表
                    emptyEl.outerHTML = '<div class="table-responsive"><table class="data-table"><thead><tr><th>' + <?php echo json_encode(t('admin_ban_appeals_th_type', '类型')); ?> + '</th><th>' + <?php echo json_encode(t('admin_ban_appeals_th_username', '用户名')); ?> + '</th><th>' + <?php echo json_encode(t('admin_ban_appeals_th_reason', '申诉理由')); ?> + '</th><th>' + <?php echo json_encode(t('admin_ban_appeals_th_penalty_reason', '处罚原因')); ?> + '</th><th>' + <?php echo json_encode(t('admin_ban_appeals_th_status', '状态')); ?> + '</th><th>' + <?php echo json_encode(t('admin_ban_appeals_th_submitted_at', '提交时间')); ?> + '</th><th>' + <?php echo json_encode(t('admin_ban_appeals_th_actions', '操作')); ?> + '</th></tr></thead><tbody id="appeal-tbody"></tbody></table></div>';
                    tbody = document.getElementById('appeal-tbody');
                }
                if (tbody) {
                    tbody.innerHTML = appeals.map(renderRow).join('');
                }
            })
            .catch(function () {})
            .then(function () { checking = false; }); // 无论成败都释放去重锁
    }

    setInterval(refresh, INTERVAL);
})();
</script>
