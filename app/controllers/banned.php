<?php
/**
 * 云界论坛 - 账号被封禁提示页
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

$bannedInfo = $_SESSION['banned_info'] ?? null;
if (!is_array($bannedInfo)) {
    redirect('/login');
}

// 关键：核对数据库真实封禁状态。申诉通过或封禁到期后 session 仍残留 banned_info，
// 若用户已解封应立刻清理会话并引导重新登录，而不是继续显示封禁页
$banUsername = $bannedInfo['username'] ?? ($_SESSION['appeal_username'] ?? ($_GET['u'] ?? ''));
if ($banUsername !== '') {
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT status, banned_until FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
        $stmt->execute([':u' => (string)$banUsername]);
        $uRow = $stmt->fetch();
        // 与 is_user_banned 保持完全一致：status=banned 且（永久封禁或未到期）才算仍被封禁
        $isActuallyBanned = false;
        if ($uRow) {
            if ($uRow['status'] === 'banned') {
                $isActuallyBanned = empty($uRow['banned_until']) || db_time($uRow['banned_until']) > time();
            }
        }
        if ($uRow && !$isActuallyBanned) {
            // 已解封：清理封禁会话，引导重新登录
            unset($_SESSION['banned_info']);
            set_flash(t('banned_unbanned_flash', '你的账号已解封，请重新登录。'), 'success');
            redirect('/login');
        }
    } catch (Exception $e) {}
}

$until = !empty($bannedInfo['until']) ? $bannedInfo['until'] : t('banned_permanent', '永久');
$untilRaw = !empty($bannedInfo['until_raw']) ? $bannedInfo['until_raw'] : null;
$reason = !empty($bannedInfo['reason']) ? $bannedInfo['reason'] : '';
$remaining = format_remaining_time($untilRaw);
// 服务端权威计算剩余秒数（不依赖客户端时间）
$permanent = $untilRaw === null;
$remainingSeconds = 0;
if (!$permanent) {
    $remainingSeconds = max(0, db_time($untilRaw) - time());
}

// 检查是否已有待审核申诉，决定按钮显示文案
$hasPendingAppeal = false;
try {
    $db = get_db();
    // 通过 banned_info 中的用户名查找（被封禁时 session 已清空，无法直接拿到 user_id）
    $appealUsername = $_SESSION['appeal_username'] ?? '';
    if ($appealUsername === '' && isset($_GET['u'])) {
        $appealUsername = (string)$_GET['u'];
    }
    if ($appealUsername !== '') {
        $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
        $stmt->execute([':u' => $appealUsername]);
        $uid = (int)$stmt->fetchColumn();
        if ($uid > 0) {
            $stmt = $db->prepare("SELECT id FROM ban_appeals WHERE user_id = :uid AND status = 'pending' LIMIT 1");
            $stmt->execute([':uid' => $uid]);
            $hasPendingAppeal = (bool)$stmt->fetchColumn();
        }
    }
} catch (Exception $e) {}

$pageTitle = t('banned_page_title', '账号已被封禁');
include APP_ROOT . 'app/includes/header.php';
?>

<div class="auth-container">
    <div class="card banned-card">
        <div class="banned-visual">
            <div class="banned-icon">
                <svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                </svg>
            </div>
            <h1 class="banned-title"><?php echo e(t('banned_page_title', '账号已被封禁')); ?></h1>
            <p class="banned-subtitle"><?php echo e(t('banned_subtitle', '暂时无法继续访问 {site}', ['site' => SITE_NAME])); ?></p>
        </div>

        <div class="banned-info">
            <div class="banned-info-item">
                <span class="banned-info-label"><?php echo e(t('banned_label_until', '封禁期限')); ?></span>
                <span class="banned-info-value"><?php echo e($until); ?></span>
            </div>
            <?php if (!$permanent): ?>
                <div class="banned-info-item banned-info-highlight">
                    <span class="banned-info-label"><?php echo e(t('banned_label_remaining', '剩余时间')); ?></span>
                    <span class="banned-info-value banned-remaining" id="banned-remaining" data-remaining-seconds="<?php echo (int)$remainingSeconds; ?>"><?php echo e($remaining); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($reason !== ''): ?>
                <div class="banned-info-item">
                    <span class="banned-info-label"><?php echo e(t('banned_label_reason', '封禁原因')); ?></span>
                    <span class="banned-info-value"><?php echo e($reason); ?></span>
                </div>
            <?php else: ?>
                <div class="banned-info-item">
                    <span class="banned-info-label"><?php echo e(t('banned_label_reason', '封禁原因')); ?></span>
                    <span class="banned-info-value text-muted"><?php echo e(t('banned_reason_empty', '未填写')); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="banned-actions">
            <a href="/" class="btn btn-primary btn-block"><?php echo e(t('banned_back_home', '返回首页')); ?></a>
            <a href="<?php echo site_url('appeal'); ?>" class="btn btn-secondary btn-block" style="margin-top:0.5rem;">
                <?php if ($hasPendingAppeal): ?>
                    <?php echo e(t('banned_view_appeal_status', '查看申诉状态')); ?>
                <?php else: ?>
                    <?php echo e(t('banned_submit_appeal', '对处罚有异议？申请申诉')); ?>
                <?php endif; ?>
            </a>
        </div>

        <p class="banned-hint">
            <?php if ($hasPendingAppeal): ?>
                <?php echo e(t('banned_hint_pending', '你已提交申诉，请等待管理员审核，结果将通过邮件通知。')); ?>
            <?php else: ?>
                <?php echo e(t('banned_hint_default', '如对处罚有异议，可点击上方按钮提交申诉。')); ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<script>
(function () {
    const el = document.getElementById('banned-remaining');
    if (!el) return;

    // 从服务端渲染的初始值开始（权威剩余秒数）
    let remainingSeconds = parseInt(el.dataset.remainingSeconds, 10) || 0;
    let expiredChecking = false;       // 防止重复请求解禁检查
    let lastCalibrate = Date.now();    // 上次服务端校准的真实时间戳（仅用于决定何时校准，不用于计算剩余时间）
    const CALIBRATE_INTERVAL = 20000;  // 20 秒向服务端校准一次

    function formatRemaining(sec) {
        if (sec <= 0) return <?php echo json_encode(t('banned_js_checking', '正在检查解禁状态…')); ?>;
        const days = Math.floor(sec / 86400);
        const hours = Math.floor((sec % 86400) / 3600);
        const minutes = Math.floor((sec % 3600) / 60);
        const seconds = sec % 60;
        const parts = [];
        if (days > 0) parts.push(days + <?php echo json_encode(t('banned_js_unit_day', ' 天')); ?>);
        if (hours > 0) parts.push(hours + <?php echo json_encode(t('banned_js_unit_hour', ' 小时')); ?>);
        if (minutes > 0) parts.push(minutes + <?php echo json_encode(t('banned_js_unit_minute', ' 分钟')); ?>);
        parts.push(seconds + <?php echo json_encode(t('banned_js_unit_second', ' 秒')); ?>);
        return <?php echo json_encode(t('banned_js_remaining_prefix', '还剩 ')); ?> + parts.join(' ');
    }

    // 倒计时归零后，调用后端检查最新封禁状态
    function checkBanStatus() {
        if (expiredChecking) return;
        expiredChecking = true;
        if (el) el.textContent = <?php echo json_encode(t('banned_js_verifying', '正在验证解禁状态…')); ?>;

        fetch('<?php echo site_url('api/check_ban_status'); ?>&_=' + Date.now(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.banned) {
                    // 仍处于封禁状态：用服务端返回的剩余秒数校准
                    if (typeof data.remaining_seconds === 'number') {
                        remainingSeconds = data.remaining_seconds;
                    }
                    if (data.permanent) {
                        // 变成永久封禁，刷新页面
                        window.location.reload();
                    } else if (remainingSeconds > 0) {
                        // 仍未到期，继续倒计时
                        expiredChecking = false;
                        el.textContent = formatRemaining(remainingSeconds);
                    } else {
                        // 服务端确认已到期但仍标记为 banned，刷新页面
                        window.location.reload();
                    }
                } else {
                    // 已解禁，跳转登录页
                    window.location.href = '<?php echo site_url('login'); ?>';
                }
            })
            .catch(function () {
                if (el) el.textContent = <?php echo json_encode(t('banned_js_network_error', '网络异常，3 秒后重试…')); ?>;
                setTimeout(function () {
                    expiredChecking = false;
                    checkBanStatus();
                }, 3000);
            });
    }

    // 定期从服务端校准剩余秒数（防止客户端计数漂移）
    function calibrateFromServer() {
        fetch('<?php echo site_url('api/check_ban_status'); ?>&_=' + Date.now(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.banned) {
                    // 已解禁，跳转登录页
                    window.location.href = '<?php echo site_url('login'); ?>';
                    return;
                }
                if (typeof data.remaining_seconds === 'number') {
                    remainingSeconds = data.remaining_seconds;
                }
                if (data.permanent) {
                    window.location.reload();
                }
                lastCalibrate = Date.now();
            })
            .catch(function () {});
    }

    // 每秒递减计数器（纯计数，不使用 Date.now() 计算剩余时间）
    function tick() {
        remainingSeconds--;
        if (remainingSeconds <= 0) {
            remainingSeconds = 0;
            el.textContent = formatRemaining(0);
            clearInterval(tickTimer);
            checkBanStatus();
            return;
        }
        el.textContent = formatRemaining(remainingSeconds);

        // 每 CALIBRATE_INTERVAL 毫秒向服务端校准一次
        if (Date.now() - lastCalibrate >= CALIBRATE_INTERVAL) {
            calibrateFromServer();
            lastCalibrate = Date.now();
        }
    }

    el.textContent = formatRemaining(remainingSeconds);
    const tickTimer = setInterval(tick, 1000);
})();
</script>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
