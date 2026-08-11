<?php
/**
 * 云界论坛 - 管理后台邮箱披露申请
 *
 * 社区管理员默认看不到用户邮箱（隐私保护），可对目标用户发起披露申请并说明原因；
 * 超级管理员在此审核（同意后申请人可见该用户邮箱，拒绝可填写备注）。
 * 页面同时展示申请人自己的申请记录与审核状态。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$db = get_db();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$errors = [];

// —— 提交披露申请（任意管理员，目标用户需存在且非本人）——
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    $isAjax = ($_POST['ajax'] ?? '') === '1';
    $targetId = (int)($_POST['target_user_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $failMsg = '';
    if ($targetId <= 0) {
        $failMsg = t('email_disclosure_err_no_target', '未指定目标用户。');
    } elseif ($targetId === $currentUserId) {
        $failMsg = t('email_disclosure_err_self', '不能申请查看自己的邮箱。');
    } elseif ($reason === '' || mb_strlen($reason, 'UTF-8') < 5) {
        $failMsg = t('email_disclosure_err_reason_short', '请填写申请原因（至少 5 个字）。');
    } elseif (mb_strlen($reason, 'UTF-8') > 200) {
        $failMsg = t('email_disclosure_err_reason_long', '申请原因不能超过 200 字。');
    } else {
        // 防止对同一目标重复申请：存在 pending / 未消费的 approved 时拒绝重复提交
        $check = $db->prepare("SELECT id, status FROM email_disclosure_requests WHERE applicant_id = :aid AND target_user_id = :tid AND (status = 'pending' OR (status = 'approved' AND viewed_at IS NULL)) LIMIT 1");
        $check->execute([':aid' => $currentUserId, ':tid' => $targetId]);
        $exists = $check->fetch();
        if ($exists) {
            $failMsg = $exists['status'] === 'approved'
                ? t('email_disclosure_err_already_approved', '该用户邮箱已获准查看，无需重复申请。')
                : t('email_disclosure_err_pending', '已存在待审核的申请，请耐心等待。');
        }
    }
    // AJAX（用户管理弹窗）：返回 JSON，前端据此提示具体原因并在成功后刷新页面
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        if ($failMsg !== '') {
            echo json_encode(['ok' => false, 'message' => $failMsg], JSON_UNESCAPED_UNICODE);
        } else {
            $stmt = $db->prepare("INSERT INTO email_disclosure_requests (applicant_id, target_user_id, reason, status) VALUES (:aid, :tid, :reason, 'pending')");
            $stmt->execute([':aid' => $currentUserId, ':tid' => $targetId, ':reason' => $reason]);
            echo json_encode(['ok' => true, 'message' => t('email_disclosure_flash_applied', '申请已提交，等待管理员审核。')], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    // 非 AJAX（页面表单直提）：flash + 重定向
    if ($failMsg !== '') {
        set_flash($failMsg, 'error');
    } else {
        $stmt = $db->prepare("INSERT INTO email_disclosure_requests (applicant_id, target_user_id, reason, status) VALUES (:aid, :tid, :reason, 'pending')");
        $stmt->execute([':aid' => $currentUserId, ':tid' => $targetId, ':reason' => $reason]);
        set_flash(t('email_disclosure_flash_applied', '申请已提交，等待管理员审核。'), 'success');
    }
    // 社区管理员无本页访问权限（仅超管审核），提交后回用户管理页
    redirect(is_super_admin() ? '/admin/email_disclosure' : '/admin/users');
}

// 提交失败（原因不合法等）：以 flash 展示错误后返回（社区管理员回用户管理页）
if (!empty($errors)) {
    set_flash(implode(' ', $errors), 'error');
    redirect(is_super_admin() ? '/admin/email_disclosure' : '/admin/users');
}

// —— 审核操作（仅超级管理员）——
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve', 'reject'], true)) {
    require_super_admin();
    $reqId = (int)($_POST['request_id'] ?? 0);
    $note = trim($_POST['admin_note'] ?? '');
    if ($reqId <= 0) {
        set_flash(t('email_disclosure_flash_invalid_params', '参数无效。'), 'error');
        redirect('/admin/email_disclosure');
    }
    $stmt = $db->prepare("SELECT * FROM email_disclosure_requests WHERE id = :id AND status = 'pending'");
    $stmt->execute([':id' => $reqId]);
    $req = $stmt->fetch();
    if (!$req) {
        set_flash(t('email_disclosure_flash_not_found', '申请不存在或已处理。'), 'error');
        redirect('/admin/email_disclosure');
    }
    $newStatus = $_POST['action'] === 'approve' ? 'approved' : 'rejected';
    $upd = $db->prepare("UPDATE email_disclosure_requests SET status = :status, admin_note = :note, handled_by = :by, handled_at = CURRENT_TIMESTAMP WHERE id = :id");
    $upd->execute([':status' => $newStatus, ':note' => $note, ':by' => $currentUserId, ':id' => $reqId]);

    // 站内通知申请人审核结果
    $targetStmt = $db->prepare("SELECT username FROM users WHERE id = :id");
    $targetStmt->execute([':id' => (int)$req['target_user_id']]);
    $targetName = (string)$targetStmt->fetchColumn();
    $title = $newStatus === 'approved'
        ? t('email_disclosure_notify_title_approved', '邮箱披露申请已通过')
        : t('email_disclosure_notify_title_rejected', '邮箱披露申请未通过');
    $content = $newStatus === 'approved'
        ? t('email_disclosure_notify_content_approved', '您申请查看用户「{name}」的邮箱已获批准，现可在用户管理中查看。', ['name' => $targetName])
        : t('email_disclosure_notify_content_rejected', '您申请查看用户「{name}」的邮箱未获批准。{note}', [
            'name' => $targetName,
            'note' => $note !== '' ? t('email_disclosure_notify_note_prefix', '原因：') . $note : '',
        ]);
    send_notification((int)$req['applicant_id'], 'system', $title, $content, '/admin/users');

    set_flash($newStatus === 'approved'
        ? t('email_disclosure_flash_approved', '已同意该申请。')
        : t('email_disclosure_flash_rejected', '已拒绝该申请。'), 'success');
    redirect('/admin/email_disclosure');
}

// —— 页面仅超级管理员可访问（申请入口在用户管理，社区管理员通过站内通知获知结果）——
require_super_admin();

$isSuper = is_super_admin();

// 数据加载：超管看全部（待审核优先），社区管理员仅看自己的申请记录
if ($isSuper) {
    $requests = $db->query(
        "SELECT r.*, a.username AS applicant_name, t.username AS target_name
         FROM email_disclosure_requests r
         JOIN users a ON a.id = r.applicant_id
         JOIN users t ON t.id = r.target_user_id
         ORDER BY (r.status = 'pending') DESC, r.created_at DESC LIMIT 200"
    )->fetchAll();
} else {
    $stmt = $db->prepare(
        "SELECT r.*, t.username AS target_name
         FROM email_disclosure_requests r
         JOIN users t ON t.id = r.target_user_id
         WHERE r.applicant_id = :aid
         ORDER BY r.created_at DESC LIMIT 100"
    );
    $stmt->execute([':aid' => $currentUserId]);
    $requests = $stmt->fetchAll();
}

// 分组：待审核 / 历史
$pendingRequests = [];
$historyRequests = [];
foreach ($requests as $r) {
    if ($r['status'] === 'pending') {
        $pendingRequests[] = $r;
    } else {
        $historyRequests[] = $r;
    }
}

$activeMenu = 'email_disclosure';
require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-head">
    <h1><?php echo e(t('email_disclosure_page_title', '邮箱披露申请')); ?></h1>
    <p class="text-muted"><?php echo e(t('email_disclosure_page_desc', '社区管理员默认无法查看用户邮箱；如需查看，可在用户管理中发起披露申请并说明原因，由超级管理员审核。')); ?></p>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?>
            <div><?php echo e($err); ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($isSuper): ?>
    <!-- 待审核 -->
    <div class="card">
        <div class="card-header">
            <h3><?php echo e(t('email_disclosure_section_pending', '待审核')); ?></h3>
        </div>
        <?php if (empty($pendingRequests)): ?>
            <p class="text-muted"><?php echo e(t('email_disclosure_empty_pending', '暂无待审核的申请。')); ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?php echo e(t('email_disclosure_th_applicant', '申请人')); ?></th>
                            <th><?php echo e(t('email_disclosure_th_target', '目标用户')); ?></th>
                            <th><?php echo e(t('email_disclosure_th_reason', '申请原因')); ?></th>
                            <th><?php echo e(t('email_disclosure_th_submitted_at', '提交时间')); ?></th>
                            <th><?php echo e(t('email_disclosure_th_review', '审核')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingRequests as $r): ?>
                            <tr>
                                <td><?php echo e($r['applicant_name']); ?></td>
                                <td><?php echo e($r['target_name']); ?></td>
                                <td class="ba-reason-cell">
                                    <div class="ba-reason-clamp" title="<?php echo e($r['reason']); ?>"><?php echo e(mb_substr($r['reason'], 0, 120, 'UTF-8')); ?><?php if (mb_strlen($r['reason']) > 120) echo '…'; ?></div>
                                </td>
                                <td><div><?php echo e(db_datetime($r['created_at'])); ?></div></td>
                                <td>
                                    <form method="post" action="<?php echo site_url('admin/email_disclosure'); ?>" class="edr-review-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                        <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="<?php echo e(t('email_disclosure_note_placeholder', '备注（可选）')); ?>" maxlength="200">
                                        <button type="submit" name="action" value="approve" class="btn btn-sm btn-success"><?php echo e(t('email_disclosure_btn_approve', '同意')); ?></button>
                                        <button type="submit" name="action" value="reject" class="btn btn-sm btn-danger"><?php echo e(t('email_disclosure_btn_reject', '拒绝')); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- 申请记录 -->
<div class="card">
    <div class="card-header">
        <h3><?php echo $isSuper
            ? e(t('email_disclosure_section_history', '全部申请记录'))
            : e(t('email_disclosure_section_my_requests', '我的申请')); ?></h3>
    </div>
    <?php if (empty($historyRequests)): ?>
        <p class="text-muted"><?php echo e(t('email_disclosure_empty_history', '暂无申请记录。')); ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <?php if ($isSuper): ?>
                            <th><?php echo e(t('email_disclosure_th_applicant', '申请人')); ?></th>
                        <?php endif; ?>
                        <th><?php echo e(t('email_disclosure_th_target', '目标用户')); ?></th>
                        <th><?php echo e(t('email_disclosure_th_reason', '申请原因')); ?></th>
                        <th><?php echo e(t('email_disclosure_th_status', '状态')); ?></th>
                        <th><?php echo e(t('email_disclosure_th_submitted_at', '提交时间')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historyRequests as $r): ?>
                        <tr>
                            <?php if ($isSuper): ?>
                                <td><?php echo e($r['applicant_name']); ?></td>
                            <?php endif; ?>
                            <td><?php echo e($r['target_name']); ?></td>
                            <td class="ba-reason-cell">
                                <div class="ba-reason-clamp" title="<?php echo e($r['reason']); ?>"><?php echo e(mb_substr($r['reason'], 0, 120, 'UTF-8')); ?><?php if (mb_strlen($r['reason']) > 120) echo '…'; ?></div>
                                <?php if ($r['admin_note'] !== ''): ?>
                                    <div class="text-muted text-xs"><?php echo e(t('email_disclosure_admin_note', '审核备注：{note}', ['note' => $r['admin_note']])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                    <span class="badge badge-warning"><?php echo e(t('email_disclosure_status_pending', '待审核')); ?></span>
                                <?php elseif ($r['status'] === 'approved' && !empty($r['viewed_at'])): ?>
                                    <span class="badge badge-secondary"><?php echo e(t('email_disclosure_status_viewed', '已查看')); ?></span>
                                <?php elseif ($r['status'] === 'approved'): ?>
                                    <span class="badge badge-success"><?php echo e(t('email_disclosure_status_approved', '已同意')); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><?php echo e(t('email_disclosure_status_rejected', '已拒绝')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo e(db_datetime($r['created_at'])); ?></div>
                                <?php if (!empty($r['handled_at'])): ?>
                                    <div class="text-muted text-xs"><?php echo e(t('email_disclosure_handled_at', '处理于 {time}', ['time' => db_datetime($r['handled_at'])])); ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
