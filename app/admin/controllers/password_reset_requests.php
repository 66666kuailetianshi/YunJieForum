<?php
/**
 * 云界论坛 - 管理后台密码重置审核
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$db = get_db();
$action = $_GET['action'] ?? 'list';
$requestId = (int)($_GET['request_id'] ?? 0);

if (in_array($action, ['approve', 'reject'], true) && $requestId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $adminId = (int)($_SESSION['user_id'] ?? 0);
    $note = isset($_POST['admin_note']) ? trim($_POST['admin_note']) : '';

    $reqStmt = $db->prepare("SELECT prr.*, u.username FROM password_reset_requests prr JOIN users u ON prr.user_id = u.id WHERE prr.id = :id AND prr.status = 'pending'");
    $reqStmt->execute([':id' => $requestId]);
    $reqRow = $reqStmt->fetch();

    if ($reqRow) {
        try {
            $db->beginTransaction();

            if ($action === 'approve') {
                $newPassword = $_POST['new_password'] ?? '';
                if (strlen($newPassword) < 6) {
                    throw new Exception(t('admin_pwdreset_err_password_short', '新密码长度不能少于 6 位。'));
                } elseif (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
                    throw new Exception(t('admin_pwdreset_err_password_mixed', '密码必须同时包含字母和数字。'));
                }
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $db->prepare("UPDATE users SET password = :password, reset_token = NULL, reset_expires = NULL, force_password_change = 1 WHERE id = :id")
                    ->execute([':password' => $hash, ':id' => $reqRow['user_id']]);
                // 清除可能存在的 remember_token，强制重新登录
                $db->prepare("UPDATE users SET remember_token = NULL WHERE id = :id")
                    ->execute([':id' => $reqRow['user_id']]);

                // 通知用户审核结果（登录后才能看到）
                add_notification(
                    (int)$reqRow['user_id'],
                    'system',
                    t('admin_pwdreset_notify_approved_title', '密码重置申请已通过'),
                    t('admin_pwdreset_notify_approved_content', '管理员已审核通过您的密码重置申请，登录后请立即修改密码。'),
                    site_url('login')
                );

                // 无 SMTP 时无法发邮件，把新密码暂存 session 回显给管理员，由管理员通过其他渠道告知用户
                $_SESSION['password_reset_temp_password'] = $newPassword;

                $status = 'approved';
                $flashMsg = t('admin_pwdreset_flash_approved', '已审核通过并设置新密码。');
            } else {
                // 通知用户审核结果
                add_notification(
                    (int)$reqRow['user_id'],
                    'system',
                    t('admin_pwdreset_notify_rejected_title', '密码重置申请未通过'),
                    t('admin_pwdreset_notify_rejected_content', '您的密码重置申请未通过审核{reason}。', ['reason' => $note ? t('admin_pwdreset_notify_rejected_reason', '，原因：') . $note : '']),
                    site_url('login')
                );

                $status = 'rejected';
                $flashMsg = t('admin_pwdreset_flash_rejected', '已驳回该申请。');
            }

            $stmt = $db->prepare("UPDATE password_reset_requests SET status = :status, admin_note = :note, handled_by = :admin_id, handled_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([
                ':status'   => $status,
                ':note'     => $note,
                ':admin_id' => $adminId > 0 ? $adminId : null,
                ':id'       => $requestId,
            ]);

            $db->commit();
            set_flash($flashMsg, 'success');
        } catch (Exception $e) {
            $db->rollBack();
            set_flash(t('admin_pwdreset_flash_failed', '处理失败：') . $e->getMessage(), 'error');
        }
    } else {
        set_flash(t('admin_pwdreset_flash_not_found', '申请记录不存在或已处理。'), 'error');
    }
    redirect('/admin/password_reset_requests');
}

// CSRF 统一校验：涉及状态变更的 GET 操作必须先通过校验，失败时明确提示（避免静默失败）
if ($action === 'delete' && !validate_csrf()) {
    set_flash(t('admin_pwdreset_csrf_failed', '安全校验失败（链接已过期），请刷新页面后重新操作。'), 'error');
    redirect('/admin/password_reset_requests');
}

if ($action === 'delete' && $requestId > 0) {
    $db->prepare("DELETE FROM password_reset_requests WHERE id = :id")
        ->execute([':id' => $requestId]);
    set_flash(t('admin_pwdreset_flash_deleted', '申请记录已删除。'), 'success');
    redirect('/admin/password_reset_requests');
}

$filterStatus = $_GET['status'] ?? 'pending';
$allowedStatuses = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = 'pending';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = $filterStatus === 'all' ? '' : "WHERE prr.status = :status";
$countSql = "SELECT COUNT(*) FROM password_reset_requests prr " . $where;
$listSql = "SELECT prr.*,
        u.username AS username,
        handler.username AS handler_name
    FROM password_reset_requests prr
    JOIN users u ON prr.user_id = u.id
    LEFT JOIN users handler ON prr.handled_by = handler.id
    " . $where . "
    ORDER BY prr.created_at DESC
    LIMIT :limit OFFSET :offset";

if ($filterStatus === 'all') {
    $total = (int)$db->query($countSql)->fetchColumn();
    $stmt = $db->prepare($listSql);
} else {
    $countStmt = $db->prepare($countSql);
    $countStmt->execute([':status' => $filterStatus]);
    $total = (int)$countStmt->fetchColumn();
    $stmt = $db->prepare($listSql);
    $stmt->bindValue(':status', $filterStatus, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$requests = $stmt->fetchAll();

$pageTitle = t('admin_pwdreset_title', '密码重置审核');
$activeMenu = 'password_reset_requests';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_pwdreset_title', '密码重置审核')); ?></h1>
</div>

<?php if (!empty($_SESSION['password_reset_temp_password'])): ?>
    <?php $tempPassword = $_SESSION['password_reset_temp_password']; unset($_SESSION['password_reset_temp_password']); ?>
    <div class="alert alert-warning" id="reset-password-alert" style="word-break:break-all;">
        <strong><?php echo t('admin_pwdreset_alert_temp_prefix', '新密码（仅显示一次，'); ?><span id="reset-password-countdown">60</span><?php echo t('admin_pwdreset_alert_temp_suffix', ' 秒后自动隐藏）：'); ?></strong>
        <code id="reset-temp-password" autocomplete="off" data-once="true" style="font-size:1.1em;padding:0.25rem 0.5rem;user-select:all;"><?php echo e($tempPassword); ?></code>
        <button type="button" class="btn btn-sm btn-secondary" onclick="var t=document.getElementById('reset-temp-password');navigator.clipboard.writeText(t.innerText).then(function(){alert(<?php echo json_encode(t('admin_pwdreset_js_copied', '已复制新密码')); ?>);});" style="margin-left:0.5rem;"><?php echo e(t('admin_pwdreset_btn_copy', '复制')); ?></button>
        <p class="mt-1 mb-0" style="font-size:0.875rem;"><?php echo e(t('admin_pwdreset_alert_smtp_notice', '站点未启用 SMTP，无法自动发送邮件，请通过 QQ/微信/电话等其他渠道将该密码告知用户。用户首次登录后会被强制要求修改密码。')); ?></p>
    </div>
    <script>
    (function() {
        var alertEl = document.getElementById('reset-password-alert');
        var countdownEl = document.getElementById('reset-password-countdown');
        var passwordEl = document.getElementById('reset-temp-password');
        if (!alertEl || !countdownEl) return;
        var seconds = 60;
        var timer = setInterval(function() {
            seconds--;
            countdownEl.textContent = seconds;
            if (seconds <= 0) {
                clearInterval(timer);
                if (passwordEl) passwordEl.textContent = '******';
                alertEl.style.opacity = '0.5';
                alertEl.style.transition = 'opacity 1s';
                countdownEl.textContent = <?php echo json_encode(t('admin_pwdreset_js_hidden', '已隐藏')); ?>;
            }
        }, 1000);
    })();
    </script>
<?php endif; ?>

<div class="card">
    <div class="filter-tabs">
        <?php foreach ($allowedStatuses as $st): ?>
            <a href="<?php echo site_url('admin/password_reset_requests', ['status' => $st]); ?>" class="filter-tab <?php echo $filterStatus === $st ? 'active' : ''; ?>">
                <?php echo e(format_password_reset_status_label($st)); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?php echo e(t('admin_pwdreset_th_username', '用户名')); ?></th>
                    <th><?php echo e(t('admin_pwdreset_th_email', '邮箱')); ?></th>
                    <th><?php echo e(t('admin_pwdreset_th_status', '状态')); ?></th>
                    <th><?php echo e(t('admin_pwdreset_th_verification', '身份验证')); ?></th>
                    <th><?php echo e(t('admin_pwdreset_th_request_time', '申请时间')); ?></th>
                    <th><?php echo e(t('admin_pwdreset_th_actions', '操作')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted"><?php echo e(t('admin_pwdreset_empty', '暂无密码重置申请')); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                        <?php
                        $isHighRisk = $req['status'] === 'pending' && (!$req['has_security_question'] || !$req['security_verified']);
                        ?>
                        <tr>
                            <td><?php echo $req['id']; ?></td>
                            <td>
                                <a href="<?php echo site_url('profile', ['user_id' => (int)$req['user_id']]); ?>" target="_blank">
                                    <?php echo e($req['username']); ?>
                                </a>
                            </td>
                            <td><?php echo e($req['email']); ?></td>
                            <td>
                                <?php if ($req['status'] === 'pending'): ?>
                                    <span class="badge badge-warning"><?php echo e(t('admin_pwdreset_status_pending', '待审核')); ?></span>
                                <?php elseif ($req['status'] === 'approved'): ?>
                                    <span class="badge badge-success"><?php echo e(t('admin_pwdreset_status_approved', '已通过')); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?php echo e(t('admin_pwdreset_status_rejected', '已驳回')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req['has_security_question'] && $req['security_verified']): ?>
                                    <span class="badge badge-success"><?php echo e(t('admin_pwdreset_sec_verified', '密保已验证')); ?></span>
                                <?php elseif ($req['has_security_question'] && !$req['security_verified']): ?>
                                    <span class="badge badge-warning"><?php echo e(t('admin_pwdreset_sec_not_verified', '密保未验证')); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><?php echo e(t('admin_pwdreset_sec_not_set', '未设置密保')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo time_ago($req['created_at']); ?></td>
                            <td>
                                <?php if ($req['status'] === 'pending'): ?>
                                    <?php if ($isHighRisk): ?>
                                        <div class="text-error text-sm mb-1"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px;"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg> <?php echo e(t('admin_pwdreset_risk_notice', '该申请未通过密保验证，请谨慎审核。')); ?></div>
                                    <?php endif; ?>
                                    <form method="POST" action="<?php echo site_url('admin/password_reset_requests', ['action' => 'approve', 'request_id' => (int)$req['id'], 'csrf_token' => csrf_token()]); ?>" style="display:inline-block;margin-bottom:0.25rem;" onsubmit=t('admin_password_reset_requests_3a092d','return confirm(<?php echo json_encode(t(\'admin_pwdreset_confirm_approve\', \'确定要通过该密码重置申请吗？\')); ?> + <?php echo json_encode(t(\'admin_pwdreset_confirm_approve_risk\', \' 注意：该申请未通过密保验证。\')); ?>)')>
                                        <input type="password" name="new_password" class="form-control form-control-sm" placeholder="<?php echo e(t('admin_pwdreset_placeholder_new_password', '新密码（至少6位）\"')); ?> style="width:160px;display:inline-block;margin-right:0.25rem;" required minlength="6">
                                        <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="<?php echo e(t('admin_pwdreset_placeholder_note', '备注（可选）\"')); ?> style="width:120px;display:inline-block;margin-right:0.25rem;">
                                        <button type="submit" class="btn btn-sm btn-success"><?php echo e(t('admin_pwdreset_btn_approve', '通过')); ?></button>
                                    </form>
                                    <form method="POST" action="<?php echo site_url('admin/password_reset_requests', ['action' => 'reject', 'request_id' => (int)$req['id'], 'csrf_token' => csrf_token()]); ?>" style="display:inline-block;">
                                        <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="<?php echo e(t('admin_pwdreset_placeholder_reject_reason', '驳回原因\"')); ?> style="width:140px;display:inline-block;margin-right:0.25rem;">
                                        <button type="submit" class="btn btn-sm btn-secondary"><?php echo e(t('admin_pwdreset_btn_reject', '驳回')); ?></button>
                                    </form>
                                <?php else: ?>
                                    <div class="text-muted text-sm">
                                        <?php if (!empty($req['handler_name'])): ?>
                                            <?php echo e(t('admin_pwdreset_handled_by', '{name} 于 {time}', ['name' => $req['handler_name'], 'time' => date('Y-m-d H:i', db_time($req['handled_at'] ?? 'now'))])); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($req['admin_note'])): ?>
                                            <div><?php echo e(t('admin_pwdreset_note_label', '备注：') . $req['admin_note']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <a href="<?php echo site_url('admin/password_reset_requests', ['action' => 'delete', 'request_id' => (int)$req['id'], 'csrf_token' => csrf_token()]); ?>" class="btn btn-sm btn-danger" data-confirm="<?php echo e(t('admin_pwdreset_confirm_delete', '确定删除该申请记录吗？\"')); ?>><?php echo e(t('admin_pwdreset_btn_delete', '删除')); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo pagination($page, $total, $perPage, site_url('admin/password_reset_requests', ['status' => $filterStatus])); ?>
</div>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
