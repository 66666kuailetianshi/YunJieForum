<?php
/**
 * 云界论坛 - 人机验证调试面板（仅管理员）
 *
 * 在后台以 session 方式直接测试 captcha 完整链路：
 *  - get → check（低行为分会强制下发挑战）→ slider/click verify
 *  - 支持 slider、click、auto 三种模式独立测试
 *  - 显示内部调试数据（gap 位置、答案序列等）
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';
require_once APP_ROOT . 'app/captcha/core.php';

$pageTitle  = '人机验证调试';
$activeMenu = 'captcha_debug';

$debugMode = get_site_setting('captcha_debug', '0') === '1';
$captchaEnabled = captcha_enabled();
$captchaStyle = captcha_style();

// ---------- 调试动作（POST，无需 CSRF 因为只是服务器端调试） ----------
$result = null;
$sessionCaptcha = $_SESSION['captcha'] ?? null;

$action   = $_POST['action'] ?? $_GET['action'] ?? '';
$testType = $_POST['type'] ?? 'click';

if ($action === 'init') {
    // 初始化新挑战
    captcha_new();
    $sessionCaptcha = $_SESSION['captcha'];
} elseif ($action === 'force_challenge') {
    // 强制下发指定类型的挑战（绕过行为打分）
    $cap = $_SESSION['captcha'] ?? null;
    if (is_array($cap) && !empty($cap['token'])) {
        $cap['attempts'] = 0;
        $_SESSION['captcha'] = $cap;
        if ($testType === 'click') {
            $result = captcha_click_challenge($cap);
        } else {
            $result = captcha_slider_challenge($cap);
        }
        $sessionCaptcha = $_SESSION['captcha'];
    } else {
        // token 过期，重新申请
        captcha_new();
        $cap = $_SESSION['captcha'];
        if ($testType === 'click') {
            $result = captcha_click_challenge($cap);
        } else {
            $result = captcha_slider_challenge($cap);
        }
        $sessionCaptcha = $_SESSION['captcha'];
    }
} elseif ($action === 'verify_slider') {
    $x = isset($_POST['x']) ? (int)$_POST['x'] : 0;
    $token = $_SESSION['captcha']['token'] ?? '';
    $result = captcha_slider_verify($token, $x);
    $sessionCaptcha = $_SESSION['captcha'];
} elseif ($action === 'verify_click') {
    $seq = isset($_POST['seq']) ? explode(',', $_POST['seq']) : [];
    $token = $_SESSION['captcha']['token'] ?? '';
    $result = captcha_click_verify($token, $seq);
    $sessionCaptcha = $_SESSION['captcha'];
}

require_once dirname(__DIR__) . '/layout/header.php';
?>
<style>
/* 调试页内嵌真实验证码组件所需的样式不冲突处理 */
.live-captcha-box { padding: 1rem; border: 1px dashed var(--border); border-radius: 8px;
    background: var(--surface-2); display: flex; flex-direction: column; align-items: center; gap: .75rem; }
.live-captcha-box .captcha-result { font-size: 13px; min-height: 20px; }
.btn-ic { line-height: 1; }
</style>
<link rel="stylesheet" href="<?php echo e(site_url('captcha/assets', ['file' => 'captcha.css'])); ?>">

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo e(t('captcha_debug_title', '人机验证调试')); ?></h1>
        <p class="page-subtitle"><?php echo e(t('captcha_debug_desc', '在管理员后台测试拼图/点文字验证的完整链路，无需在登录页手动触发。')); ?></p>
    </div>
    <div class="page-tools">
        <a href="<?php echo site_url('admin/site_settings'); ?>" class="btn btn-secondary btn-sm"><?php echo e(t('back_to_settings', '站点设置')); ?></a>
    </div>
</div>

<!-- 状态总览 -->
<div class="card" style="margin-bottom: 1rem;">
    <h3 style="margin-top:0;"><?php echo e(t('debug_status', '当前状态')); ?></h3>
    <table style="width:100%;border-collapse:collapse;">
        <tr><td style="padding:4px 12px 4px 0;width:140px;color:var(--text-muted);"><?php echo e(t('debug_captcha_enabled', '验证码开关')); ?></td>
            <td><span class="badge <?php echo $captchaEnabled ? 'badge-success' : 'badge-muted'; ?>"><?php echo $captchaEnabled ? t('common_on', '开启') : t('common_off', '关闭'); ?></span></td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:var(--text-muted);"><?php echo e(t('debug_captcha_style', '验证方式')); ?></td>
            <td><strong><?php echo e($captchaStyle); ?></strong> <span class="text-muted">(<?php echo $captchaStyle === 'slider' ? '拼图' : ($captchaStyle === 'click' ? '点文字' : '智能混合'); ?>)</span></td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:var(--text-muted);"><?php echo e(t('debug_mode', '调试模式')); ?></td>
            <td><span class="badge <?php echo $debugMode ? 'badge-warning' : 'badge-muted'; ?>"><?php echo $debugMode ? t('debug_on', '已开启（前端旁路）') : t('debug_off', '未开启'); ?></span></td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:var(--text-muted);">Session Token</td>
            <td><code style="font-size:11px;word-break:break-all;"><?php echo e($sessionCaptcha['token'] ?? '(无)'); ?></code></td></tr>
    </table>
    <?php if (!$debugMode): ?>
    <div class="alert alert-warning" style="margin-top:0.75rem;"><?php echo e(t('debug_hint', '提示：开启「调试模式」后，前台登录/注册/找回密码页将跳过人机验证，方便开发调试。')); ?></div>
    <?php endif; ?>
</div>

<!-- 真实组件测试区：与前台登录/注册页完全一致的交互 -->
<div class="card" style="margin-bottom:1rem;">
    <h3 style="margin-top:0;"><?php echo e(t('debug_live_test', '真实组件测试（与前台一致）')); ?></h3>
    <p class="text-muted" style="font-size:13px;"><?php echo e(t('debug_live_desc', '直接拖拽滑块或点击文字完成验证，与登录/注册页提交体验完全一致。下方会实时显示验证结果与由真实操作写入的 Session 状态。')); ?></p>
    <div class="live-captcha-box">
        <div id="captcha"
             data-api="<?php echo e(site_url('api/captcha')); ?>"
             data-live="1"></div>
        <input type="hidden" name="captcha_token" id="captcha_token">
        <div class="captcha-result" id="liveCaptchaResult"><?php echo e(t('debug_live_ready', '等待操作…（验证通过后此处显示「已验证」）')); ?></div>
    </div>
</div>

<!-- 操作区 -->
<div style="display:flex;gap:1rem;flex-wrap:wrap;">

<!-- 左侧：控制面板 -->
<div style="flex:1;min-width:280px;">
    <div class="card" style="margin-bottom:1rem;">
        <h3 style="margin-top:0;"><?php echo e(t('debug_control', '控制面板')); ?></h3>
        <form method="POST" style="margin-bottom:0.75rem;">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="init">
            <button type="submit" class="btn btn-secondary btn-sm" style="width:100%;">&#8635; <?php echo e(t('debug_new_token', '申请新 Token')); ?></button>
        </form>

        <form method="POST">
            <div class="form-group" style="margin-bottom:0.5rem;">
                <label class="form-label" style="font-size:13px;"><?php echo e(t('debug_challenge_type', '挑战类型')); ?></label>
                <select name="type" class="form-control form-control-sm">
                    <option value="slider" <?php echo ($_POST['type'] ?? '') === 'slider' ? 'selected' : ''; ?>><?php echo e(t('debug_type_slider', '拼图滑块')); ?></option>
                    <option value="click" <?php echo ($_POST['type'] ?? '') !== 'slider' ? 'selected' : ''; ?>><?php echo e(t('debug_type_click', '点文字')); ?></option>
                </select>
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="force_challenge">
            <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">&#9654; <?php echo e(t('debug_force_challenge', '强制下发挑战')); ?></button>
        </form>

        <form method="POST" style="margin-top:0.5rem;">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="force_challenge">
            <input type="hidden" name="type" value="click">
            <button type="submit" class="btn btn-secondary btn-sm" style="width:100%;">&#9654; <?php echo e(t('debug_force_click', '强制下发点文字挑战')); ?></button>
        </form>
    </div>

    <?php if (!empty($result)): ?>
    <div class="card" style="margin-bottom:1rem;">
        <h3 style="margin-top:0;"><?php echo e(t('debug_api_result', 'API 响应')); ?></h3>
        <pre style="background:var(--surface-2);padding:10px;border-radius:6px;font-size:12px;overflow:auto;max-height:260px;"><?php echo e(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
    </div>
    <?php endif; ?>
</div>

<!-- 右侧：可视化预览 -->
<div style="flex:2;min-width:360px;">
    <?php if ($action === 'force_challenge' && $testType === 'slider' && !empty($result) && empty($result['ok'])): ?>
    <!-- 拼图滑块预览 -->
    <div class="card" style="margin-bottom:1rem;">
        <h3 style="margin-top:0;"><?php echo e(t('debug_slider_preview', '拼图预览')); ?></h3>
        <p class="text-muted" style="font-size:13px;margin-bottom:0.5rem;">
            <?php echo e(t('debug_gap_pos', '缺口位置')); ?>: gapX = <strong><?php echo (int)($sessionCaptcha['gap'] ?? 0); ?></strong> &nbsp;|&nbsp;
            容差: &plusmn;<?php echo SLIDER_CAPTCHA_TOLERANCE; ?>px
        </p>
        <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;background:var(--surface-2);">
            <img src="<?php echo e($result['bg_b64'] ?? ''); ?>" alt="bg" style="display:block;width:100%;height:auto;">
        </div>
        <details style="margin-top:0.75rem;">
            <summary style="cursor:pointer;font-size:13px;color:var(--text-muted);">拼图块预览</summary>
            <div style="border:1px solid var(--border);border-radius:6px;padding:4px;margin-top:6px;background:var(--surface-2);overflow:auto;">
                <img src="<?php echo e($result['piece_b64'] ?? ''); ?>" alt="piece" style="display:block;width:auto;max-width:100%;height:auto;">
            </div>
        </details>

        <!-- 手动输入 X 坐标验证 -->
        <form method="POST" style="margin-top:0.75rem;display:flex;gap:0.5rem;align-items:flex-end;">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="verify_slider">
            <div class="form-group" style="margin-bottom:0;flex:1;">
                <label class="form-label" style="font-size:13px;"><?php echo e(t('debug_enter_x', '输入拖拽位置 X（px）')); ?></label>
                <input type="number" name="x" class="form-control form-control-sm" placeholder="<?php echo (int)($sessionCaptcha['gap'] ?? 0); ?>" value="<?php echo (int)($sessionCaptcha['gap'] ?? 0); ?>" style="max-width:160px;">
            </div>
            <?php if (($sessionCaptcha['mode'] ?? '') === 'slider'): ?>
            <button type="submit" class="btn btn-primary btn-sm"><?php echo e(t('debug_verify', '校验')); ?></button>
            <?php else: ?>
            <span class="text-muted" style="font-size:12px;"><?php echo e(t('debug_need_slider_first', '需先下发拼图挑战')); ?></span>
            <?php endif; ?>
        </form>

        <?php if ($action === 'verify_slider' && !empty($result)): ?>
        <div class="alert <?php echo !empty($result['ok']) ? 'alert-success' : 'alert-error'; ?>" style="margin-top:0.5rem;">
            <?php echo !empty($result['ok']) ? '&#10003; ' . e(t('debug_verify_pass', '验证通过')) : '&#10007; ' . e(t('debug_verify_fail', '验证失败')); ?>
        </div>
        <?php endif; ?>
    </div>
    <?php elseif ($action === 'force_challenge' && $testType === 'click' && !empty($result) && empty($result['ok'])): ?>
    <!-- 点文字预览 -->
    <div class="card" style="margin-bottom:1rem;">
        <h3 style="margin-top:0;"><?php echo e(t('debug_click_preview', '点文字预览')); ?></h3>
        <p class="text-muted" style="font-size:13px;margin-bottom:0.5rem;">
            <?php echo e(t('debug_prompt', '提示词')); ?>: <strong style="font-size:18px;letter-spacing:0.3em;">"<?php echo e($result['prompt'] ?? ''); ?>"</strong>
            &nbsp;|&nbsp;
            <?php echo e(t('debug_answer', '正确答案')); ?>: <code><?php echo e(implode(' → ', $sessionCaptcha['answer'] ?? [])); ?></code>
        </p>

        <!-- 真实背景 + 散落文字渲染 -->
        <div style="position:relative;width:<?php echo (int)($result['width'] ?? 300); ?>px;height:<?php echo (int)($result['height'] ?? 150); ?>px;border:1px solid var(--border);border-radius:8px;overflow:hidden;background:var(--surface-2);margin-bottom:0.75rem;">
            <img src="<?php echo e($result['bg_b64'] ?? ''); ?>" alt="bg" style="display:block;width:100%;height:100%;object-fit:cover;">
            <?php foreach (($result['positions'] ?? []) as $p): ?>
            <span style="position:absolute;left:<?php echo (int)$p['x']; ?>px;top:<?php echo (int)$p['y']; ?>px;transform:translate(-50%,-50%) rotate(<?php echo (int)$p['angle']; ?>deg);font-size:<?php echo (int)$p['size']; ?>px;font-weight:700;color:<?php echo e($p['color']); ?>;text-shadow:0 1px 2px rgba(255,255,255,.9),0 0 2px rgba(0,0,0,.3);"><?php echo e($p['ch']); ?></span>
            <?php endforeach; ?>
        </div>

        <!-- 手动输入点击序列验证 -->
        <form method="POST" style="display:flex;gap:0.5rem;align-items:flex-end;">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="verify_click">
            <div class="form-group" style="margin-bottom:0;flex:1;">
                <label class="form-label" style="font-size:13px;"><?php echo e(t('debug_enter_seq', '输入点击序列（用逗号分隔）')); ?></label>
                <input type="text" name="seq" class="form-control form-control-sm" placeholder="<?php echo e(implode(',', $sessionCaptcha['answer'] ?? [])); ?>" value="<?php echo e(implode(',', $sessionCaptcha['answer'] ?? [])); ?>" style="max-width:240px;">
            </div>
            <?php if (($sessionCaptcha['mode'] ?? '') === 'click'): ?>
            <button type="submit" class="btn btn-primary btn-sm"><?php echo e(t('debug_verify', '校验')); ?></button>
            <?php else: ?>
            <span class="text-muted" style="font-size:12px;"><?php echo e(t('debug_need_click_first', '需先下发点文字挑战')); ?></span>
            <?php endif; ?>
        </form>

        <!-- 快捷测试按钮 -->
        <?php if (($sessionCaptcha['mode'] ?? '') === 'click'): ?>
        <div style="margin-top:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="verify_click">
                <input type="hidden" name="seq" value="<?php echo e(implode(',', $sessionCaptcha['answer'] ?? [])); ?>">
                <button type="submit" class="btn btn-success btn-sm">&#10003; <?php echo e(t('debug_test_correct', '测试正确序列')); ?></button>
            </form>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="verify_click">
                <?php $wrong = ($sessionCaptcha['bank'] ?? []) !== ($sessionCaptcha['answer'] ?? []) ? (isset($sessionCaptcha['bank'][0]) && !in_array($sessionCaptcha['bank'][0], $sessionCaptcha['answer'] ?? []) ? $sessionCaptcha['bank'][0] : ($sessionCaptcha['bank'][1] ?? 'X')) : 'X'; ?>
                <input type="hidden" name="seq" value="<?php echo e($wrong); ?>">
                <button type="submit" class="btn btn-error btn-sm">&#10007; <?php echo e(t('debug_test_wrong', '测试错误序列')); ?></button>
            </form>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="verify_click">
                <input type="hidden" name="seq" value="<?php echo e(implode(',', array_reverse($sessionCaptcha['answer'] ?? []))); ?>">
                <button type="submit" class="btn btn-warning btn-sm">&#8644; <?php echo e(t('debug_test_reverse', '测试逆序')); ?></button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($action === 'verify_click' && !empty($result)): ?>
        <div class="alert <?php echo !empty($result['ok']) ? 'alert-success' : 'alert-error'; ?>" style="margin-top:0.5rem;">
            <?php echo !empty($result['ok']) ? '&#10003; ' . e(t('debug_verify_pass', '验证通过')) : '&#10007; ' . e(t('debug_verify_fail', '验证失败')); ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!--  Session 原始数据 -->
    <?php if (!empty($sessionCaptcha)): ?>
    <div class="card">
        <details open>
            <summary style="cursor:pointer;font-weight:600;font-size:14px;">Session captcha 数据</summary>
            <pre style="background:var(--surface-2);padding:10px;border-radius:6px;font-size:11px;overflow:auto;max-height:300px;margin-top:8px;"><?php
                $dump = $sessionCaptcha;
                unset($dump['svg']);
                echo e(json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            ?></pre>
        </details>
    </div>
    <?php endif; ?>
</div>

</div>

<script src="<?php echo e(site_url('captcha/assets', ['file' => 'captcha.js'])); ?>" defer></script>
<script>
// 真实组件验证通过时，captcha.js 会把 token 写入 #captcha_token，据此提示结果
(function () {
    var poll = setInterval(function () {
        var tokenInput = document.getElementById('captcha_token');
        var result = document.getElementById('liveCaptchaResult');
        if (!tokenInput || !result) return;
        if (tokenInput.value) {
            result.textContent = '已验证通过（token 已写入，可提交）';
            result.style.color = 'var(--success, #16a34a)';
            clearInterval(poll);
        }
    }, 400);
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
