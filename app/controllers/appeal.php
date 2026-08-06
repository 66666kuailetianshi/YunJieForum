<?php
/**
 * 云界论坛 - 封禁/禁言申诉独立页面
 *
 * 支持两种申诉类型：
 *   1. 封禁申诉（appeal_type='ban'）：被封禁用户在封禁页点击"申请申诉"后进入
 *   2. 禁言申诉（appeal_type='mute'）：已登录且处于禁言状态的用户进入
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

// ==================== 申诉类型判定 ====================
// 封禁申诉：未登录，session 携带 banned_info（封禁时已被踢出登录态）
$bannedInfo = $_SESSION['banned_info'] ?? null;
$isBanAppeal = is_array($bannedInfo);

// 禁言申诉：已登录且当前处于禁言状态（禁言不踢下线，允许浏览论坛）
$isMuteAppeal = false;
$muteInfo = null;
if (!$isBanAppeal && is_logged_in()) {
    $muteUid = (int)($_SESSION['user_id'] ?? 0);
    if (is_user_muted($muteUid)) {
        try {
            $db = get_db();
            $stmt = $db->prepare("SELECT username, muted_until, status_reason FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $muteUid]);
            $muteRow = $stmt->fetch();
            if ($muteRow) {
                $muteUntilRaw = !empty($muteRow['muted_until']) ? $muteRow['muted_until'] : null;
                $muteInfo = [
                    'username'  => $muteRow['username'],
                    'until'     => $muteUntilRaw ? date('Y-m-d H:i', db_time($muteUntilRaw)) : t('appeal_permanent', '永久'),
                    'until_raw' => $muteUntilRaw,
                    'reason'    => $muteRow['status_reason'] ?? '',
                ];
                $isMuteAppeal = true;
            }
        } catch (Exception $e) {}
    }
}

// 两种申诉类型都不满足则拒绝访问
if (!$isBanAppeal && !$isMuteAppeal) {
    redirect('/');
}

// 统一申诉类型参数
$appealType = $isMuteAppeal ? 'mute' : 'ban';       // ban / mute
$penaltyLabel = $isMuteAppeal ? t('appeal_penalty_mute', '禁言') : t('appeal_penalty_ban', '封禁');      // 处罚名称
$restoreLabel = $isMuteAppeal ? t('appeal_restore_mute', '解除禁言') : t('appeal_restore_ban', '解封');  // 恢复动作名称

// 处罚信息摘要（统一字段）
$until = $isMuteAppeal
    ? ($muteInfo['until'] ?? t('appeal_permanent', '永久'))
    : (!empty($bannedInfo['until']) ? $bannedInfo['until'] : t('appeal_permanent', '永久'));
$untilRaw = $isMuteAppeal
    ? ($muteInfo['until_raw'] ?? null)
    : (!empty($bannedInfo['until_raw']) ? $bannedInfo['until_raw'] : null);
$reason = $isMuteAppeal
    ? ($muteInfo['reason'] ?? '')
    : (!empty($bannedInfo['reason']) ? $bannedInfo['reason'] : '');

// 申诉用户归属（禁言申诉锁定当前登录用户；封禁申诉由用户自行输入）
$appealUsername = $isMuteAppeal ? ($muteInfo['username'] ?? '') : ($_SESSION['appeal_username'] ?? '');

// 查询当前用户最近的申诉记录（用于显示状态）
$existingAppeal = null;
try {
    $db = get_db();
    if ($isMuteAppeal) {
        // 禁言申诉：按 user_id 查询最近一条对应类型的申诉
        $stmt = $db->prepare("SELECT * FROM ban_appeals WHERE user_id = :uid AND appeal_type = 'mute' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':uid' => (int)$_SESSION['user_id']]);
        $existingAppeal = $stmt->fetch();
    } else {
        $stmt = $db->prepare("SELECT * FROM ban_appeals WHERE LOWER(username) = LOWER(:u) AND appeal_type = 'ban' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':u' => $appealUsername]);
        $existingAppeal = $stmt->fetch();
    }
} catch (Exception $e) {}

// 判断用户当前是否仍处于处罚状态（数据库权威判断）。
// 防止"旧申诉已通过但账号又被重新处罚"时误显示"已解除"
$currentlyPenalized = false;
if ($existingAppeal) {
    try {
        $stmt = $db->prepare("SELECT status, banned_until, muted_until FROM users WHERE id = :uid LIMIT 1");
        $stmt->execute([':uid' => $existingAppeal['user_id']]);
        $uRow = $stmt->fetch();
        if ($uRow) {
            if ($appealType === 'mute') {
                $currentlyPenalized = ($uRow['status'] === 'muted')
                    && (empty($uRow['muted_until']) || db_time($uRow['muted_until']) > time());
            } else {
                $currentlyPenalized = ($uRow['status'] === 'banned')
                    && (empty($uRow['banned_until']) || db_time($uRow['banned_until']) > time());
            }
        }
    } catch (Exception $e) {}
}

// 申诉提交逻辑
$appealErrors = [];
$appealSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_appeal'])) {
    // CSRF 校验失败不静默：明确提示，避免"提交了没反应"（页面仍会显示错误）
    if (!validate_csrf()) {
        $appealErrors[] = t('appeal_err_csrf', '安全校验失败，请刷新页面后重新提交。');
    } else {
        $appealReason = trim($_POST['appeal_reason'] ?? '');

        if (mb_strlen($appealReason) < 20) {
            $appealErrors[] = t('appeal_err_reason_too_short', '申诉理由至少 20 字，请详细说明。');
        } else {
            try {
                $db = get_db();
                $u = null;

                if ($isMuteAppeal) {
                    // 禁言申诉：锁定为当前登录用户，不接受任意用户名
                    $stmt = $db->prepare("SELECT id, username, email, status, muted_until, status_reason FROM users WHERE id = :id LIMIT 1");
                    $stmt->execute([':id' => (int)$_SESSION['user_id']]);
                    $u = $stmt->fetch();
                    if (!$u || $u['status'] !== 'muted') {
                        $appealErrors[] = t('appeal_err_not_muted', '你的账号当前未被禁言，无需申诉。');
                    }
                } else {
                    // 封禁申诉：校验输入的用户名
                    $appealUsernameInput = trim($_POST['appeal_username'] ?? '');
                    if ($appealUsernameInput === '') {
                        $appealErrors[] = t('appeal_err_username_required', '请输入用户名。');
                    } else {
                        $stmt = $db->prepare("SELECT id, username, email, status, banned_until, status_reason FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
                        $stmt->execute([':u' => $appealUsernameInput]);
                        $u = $stmt->fetch();
                        if (!$u) {
                            $appealErrors[] = t('appeal_err_user_not_found', '用户名不存在。');
                        } elseif ($u['status'] !== 'banned') {
                            $appealErrors[] = t('appeal_err_not_banned', '该账号未被封禁，无需申诉。');
                        }
                    }
                }

                if ($u && empty($appealErrors)) {
                    // 检查是否已有待审核申诉（同类型）
                    $checkStmt = $db->prepare("SELECT id FROM ban_appeals WHERE user_id = :uid AND appeal_type = :atype AND status = 'pending' LIMIT 1");
                    $checkStmt->execute([':uid' => $u['id'], ':atype' => $appealType]);
                    if ($checkStmt->fetchColumn()) {
                        $appealErrors[] = t('appeal_err_already_pending', '你已提交过申诉，请等待管理员审核。');
                    } else {
                        // 处罚期限与原因按类型取对应字段
                        $penaltyUntil = $isMuteAppeal ? ($u['muted_until'] ?? null) : ($u['banned_until'] ?? null);
                        $insertStmt = $db->prepare("INSERT INTO ban_appeals (user_id, username, email, ban_reason, ban_until, appeal_reason, status, appeal_type) VALUES (:uid, :uname, :email, :breason, :buntil, :areason, 'pending', :atype)");
                        $insertStmt->execute([
                            ':uid'      => $u['id'],
                            ':uname'    => $u['username'],
                            ':email'    => $u['email'],
                            ':breason'  => $u['status_reason'] ?? '',
                            ':buntil'   => $penaltyUntil,
                            ':areason'  => $appealReason,
                            ':atype'    => $appealType,
                        ]);
                        // 记录用户名到 session，便于 banned.php 识别已有申诉
                        if (!$isMuteAppeal) {
                            $_SESSION['appeal_username'] = $u['username'];
                        }
                        $appealSuccess = true;
                    }
                }
            } catch (Exception $e) {
                $appealErrors[] = t('appeal_err_submit_failed', '申诉提交失败，请稍后重试。');
            }
        }
    }
}

$pageTitle = t('appeal_page_title', '{penalty}申诉', ['penalty' => $penaltyLabel]);
include APP_ROOT . 'app/includes/header.php';
?>

<div class="auth-container">
    <div class="card banned-card">
        <div class="banned-visual">
            <div class="banned-icon" style="color:#6366f1;">
                <svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                </svg>
            </div>
            <h1 class="banned-title"><?php echo e(t('appeal_heading', '{penalty}申诉', ['penalty' => $penaltyLabel])); ?></h1>
            <p class="banned-subtitle"><?php echo e(t('appeal_subtitle', '请如实填写申诉信息，管理员审核后通过邮件通知结果')); ?></p>
        </div>

        <!-- 处罚信息摘要 -->
        <div class="banned-info">
            <div class="banned-info-item">
                <span class="banned-info-label"><?php echo e(t('appeal_info_until', '{penalty}期限', ['penalty' => $penaltyLabel])); ?></span>
                <span class="banned-info-value"><?php echo e($until); ?></span>
            </div>
            <div class="banned-info-item">
                <span class="banned-info-label"><?php echo e(t('appeal_info_reason', '{penalty}原因', ['penalty' => $penaltyLabel])); ?></span>
                <span class="banned-info-value"><?php echo $reason !== '' ? e($reason) : '<span class="text-muted">' . e(t('appeal_reason_empty', '未填写')) . '</span>'; ?></span>
            </div>
        </div>

        <?php if ($appealSuccess): ?>
            <div class="alert alert-success" style="margin-top:1rem;">
                <?php echo e(t('appeal_submitted_alert', '申诉已提交，管理员审核后会通过邮件通知你处理结果。')); ?>
            </div>
            <div class="banned-actions" style="margin-top:1rem;">
                <a href="<?php echo $isMuteAppeal ? site_url('pm') : site_url('banned'); ?>" class="btn btn-primary btn-block"><?php echo e($isMuteAppeal ? t('appeal_back_to_forum', '返回论坛') : t('appeal_back_to_banned', '返回封禁页')); ?></a>
            </div>
        <?php elseif ($existingAppeal && $existingAppeal['status'] === 'pending'): ?>
            <!-- 已有待审核申诉 -->
            <div class="alert alert-warning" style="margin-top:1rem;">
                <?php echo e(t('appeal_pending_alert', '你已提交过申诉，正在等待管理员审核，结果将通过邮件通知。')); ?>
            </div>
            <div class="appeal-section">
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('appeal_label_reason', '申诉理由')); ?></label>
                    <div style="padding:0.75rem;background:var(--surface-2);border-radius:var(--radius);white-space:pre-wrap;word-break:break-word;"><?php echo e($existingAppeal['appeal_reason']); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('appeal_label_submitted_at', '提交时间')); ?></label>
                    <div style="padding:0.75rem;background:var(--surface-2);border-radius:var(--radius);"><?php echo e(db_datetime($existingAppeal['created_at'])); ?></div>
                </div>
            </div>
            <div class="banned-actions" style="margin-top:1rem;">
                <a href="<?php echo $isMuteAppeal ? site_url('pm') : site_url('banned'); ?>" class="btn btn-primary btn-block"><?php echo e($isMuteAppeal ? t('appeal_back_to_forum', '返回论坛') : t('appeal_back_to_banned', '返回封禁页')); ?></a>
            </div>
        <?php elseif ($existingAppeal && $existingAppeal['status'] === 'approved' && !$currentlyPenalized): ?>
            <!-- 申诉已通过且当前确已恢复 -->
            <div class="alert alert-success" style="margin-top:1rem;">
                <?php echo e(t('appeal_approved_alert', '你的申诉已通过，{restore}成功', ['restore' => $restoreLabel])); ?><?php echo e($isMuteAppeal ? t('appeal_approved_suffix_mute', '，现在可以正常发言了。') : t('appeal_approved_suffix_ban', '，请重新登录。')); ?>
            </div>
            <div class="banned-actions" style="margin-top:1rem;">
                <a href="<?php echo $isMuteAppeal ? site_url('new_post') : site_url('login'); ?>" class="btn btn-primary btn-block"><?php echo e($isMuteAppeal ? t('appeal_go_new_post', '去发帖') : t('appeal_go_login', '前往登录')); ?></a>
            </div>
        <?php else: ?>
            <!-- 首次申诉 / 被拒绝后可再次申诉 / 申诉曾通过但当前又被重新处罚 -->
            <?php if ($existingAppeal && $existingAppeal['status'] === 'rejected'): ?>
                <div class="alert alert-error" style="margin-top:1rem;">
                    <?php echo e(t('appeal_rejected_alert', '你的申诉未通过审核，账号将继续保持{penalty}状态。如需补充新的证据或说明，可再次提交申诉。', ['penalty' => $penaltyLabel])); ?>
                </div>
                <?php if (!empty($existingAppeal['admin_note'])): ?>
                <div class="appeal-section">
                    <div class="form-group">
                        <label class="form-label"><?php echo e(t('appeal_label_admin_note', '上次管理员备注')); ?></label>
                        <div style="padding:0.75rem;background:var(--surface-2);border-radius:var(--radius);"><?php echo e($existingAppeal['admin_note']); ?></div>
                    </div>
                </div>
                <?php endif; ?>
            <?php elseif ($existingAppeal && $existingAppeal['status'] === 'approved' && $currentlyPenalized): ?>
                <!-- 申诉曾通过但账号当前又处于处罚状态（被重新处罚） -->
                <div class="alert alert-warning" style="margin-top:1rem;">
                    <?php echo e(t('appeal_repenalized_alert', '你曾有过一次通过的申诉，但当前账号又处于{penalty}状态。如有异议，可重新提交申诉。', ['penalty' => $penaltyLabel])); ?>
                </div>
            <?php endif; ?>
            <!-- 申诉表单 -->
            <div class="appeal-section">
                <?php if (!empty($appealErrors)): ?>
                    <div class="alert alert-error" style="margin-bottom:0.75rem;">
                        <?php foreach ($appealErrors as $err): ?>
                            <div><?php echo e($err); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?php echo site_url('appeal'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="submit_appeal" value="1">

                    <div class="form-group">
                        <label class="form-label" for="appeal_username"><?php echo e(t('appeal_label_username', '用户名')); ?></label>
                        <?php if ($isMuteAppeal): ?>
                            <input type="text" id="appeal_username" name="appeal_username" class="form-control" required readonly value="<?php echo e($appealUsername); ?>" tabindex="-1">
                            <small class="text-muted"><?php echo e(t('appeal_username_locked_hint', '当前登录账号，禁言申诉仅限本人账号。')); ?></small>
                        <?php else: ?>
                            <input type="text" id="appeal_username" name="appeal_username" class="form-control" required value="<?php echo e($_POST['appeal_username'] ?? ''); ?>" placeholder="<?php echo e(t('appeal_username_placeholder', '请输入你的用户名\"')); ?>>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="appeal_reason"><?php echo e(t('appeal_label_reason', '申诉理由')); ?></label>
                        <textarea id="appeal_reason" name="appeal_reason" class="form-control" rows="6" required minlength="20" maxlength="1000" placeholder="<?php echo e(t('appeal_reason_placeholder', '请详细说明你认为应当{restore}的理由（至少 20 字）', ['restore' => $restoreLabel])); ?>"><?php echo e($_POST['appeal_reason'] ?? ''); ?></textarea>
                        <small class="text-muted"><?php echo e(t('appeal_reason_hint', '至少 20 字，最多 1000 字')); ?></small>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-block"><?php echo e(t('appeal_submit_btn', '提交申诉')); ?></button>
                        <a href="<?php echo $isMuteAppeal ? site_url('pm') : site_url('banned'); ?>" class="btn btn-secondary btn-block" style="margin-top:0.5rem;"><?php echo e($isMuteAppeal ? t('appeal_back_to_forum', '返回论坛') : t('appeal_back_to_banned', '返回封禁页')); ?></a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
