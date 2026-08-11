<?php
/**
 * 云界论坛 - 管理后台站点设置
 *
 * 仅负责站点基本信息（名称 / 副标题）。
 * SMTP 邮件配置已迁移至「邮件中心」mail_center.php。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：站点设置仅超级管理员可用
require_super_admin();

$errors  = [];
$success = false;

$siteName   = defined('SITE_NAME') ? SITE_NAME : APP_NAME;
$siteSlogan = defined('SITE_SLOGAN') ? SITE_SLOGAN : '';

$smtpEnabled = defined('SMTP_ENABLED') ? SMTP_ENABLED : false;
$smtpFrom    = defined('SMTP_FROM') ? SMTP_FROM : '';

$sliderCaptchaEnabled = get_site_setting('captcha_enabled', get_site_setting('slider_captcha_enabled', '0')) === '1';
$captchaStyle   = get_site_setting('captcha_style', 'slider');
$captchaDebug   = get_site_setting('captcha_debug', '0') === '1';
$captchaDisplay = get_site_setting('captcha_display', 'inline');
$captchaDifficulty = get_site_setting('captcha_difficulty', 'normal');
// 图片字母验证难度（独立设置；未设置时按整体难度映射显示，与 core.php 保持一致）
$captchaLetterDifficulty = get_site_setting('captcha_letter_difficulty', '');
if ($captchaLetterDifficulty === '') {
    $letterMap = ['easy' => 'simple', 'normal' => 'hard', 'hard' => 'very_hard'];
    $captchaLetterDifficulty = $letterMap[$captchaDifficulty] ?? 'easy';
}
$captchaTriggerMode = get_site_setting('captcha_trigger_mode', 'suspicious');
$captchaSkipCooldown = get_site_setting('captcha_skip_cooldown', '600');
// 强化防机器人：工作量证明（PoW）
$captchaPowEnabled = get_site_setting('captcha_pow_enabled', '1') === '1';
$captchaPowBits    = (int)get_site_setting('captcha_pow_bits', '3');
if ($captchaPowBits < 1) $captchaPowBits = 1;
if ($captchaPowBits > 6) $captchaPowBits = 6;
// 强化防机器人：蜜罐字段（诱导机器人自曝）
$captchaHoneypotEnabled = get_site_setting('captcha_honeypot_enabled', '1') === '1';
// 强化防机器人：失败升级（多次失败切换更严挑战）
$captchaEscalationEnabled = get_site_setting('captcha_escalation_enabled', '1') === '1';
// 强化防机器人：挑战轮换（跨请求随机挑战类型，抗行为画像）
$captchaRotationEnabled = get_site_setting('captcha_rotation_enabled', '0') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $newName   = trim($_POST['site_name'] ?? '');
    $newSlogan = trim($_POST['site_slogan'] ?? '');
    $newLang   = trim($_POST['site_lang'] ?? APP_LANG);

    if ($newName === '') {
        $newName = APP_NAME;
    }
    if (mb_strlen($newName) > 50) {
        $newName = mb_substr($newName, 0, 50);
    }
    if (mb_strlen($newSlogan) > 100) {
        $newSlogan = mb_substr($newSlogan, 0, 100);
    }
    // 验证语言代码有效
    $availLangs = array_keys(get_available_languages());
    if (!in_array($newLang, $availLangs, true)) {
        $newLang = 'zh-CN';
    }

    if (empty($errors)) {
        if (!is_dir(DATA_PATH)) {
            @mkdir(DATA_PATH, 0755, true);
        }

        // 读取现有配置文件，保留 DB_* 数据库配置，仅更新站点基本信息与 SMTP 配置
        $existingConfig = is_file(DATA_PATH . 'site_config.php') ? @file_get_contents(DATA_PATH . 'site_config.php') : '';
        if ($existingConfig === false || $existingConfig === '') {
            $existingConfig = "<?php\n";
        }
        // 移除旧的 SITE_NAME/SITE_SLOGAN/SITE_LANG/SMTP_* 定义（后面重新写入）
        $configContent = preg_replace('/^define\(\'SITE_NAME\'.*$\n?/m', '', $existingConfig);
        $configContent = preg_replace('/^define\(\'SITE_SLOGAN\'.*$\n?/m', '', $configContent);
        $configContent = preg_replace('/^define\(\'SITE_LANG\'.*$\n?/m', '', $configContent);
        $configContent = preg_replace('/^define\(\'SMTP_.*$\n?/m', '', $configContent);
        $configContent = rtrim($configContent) . "\n";
        $configContent .= t('admin_site_settings_170b7e','// 站点配置（更新于 ') . date('Y-m-d H:i:s') . "）\n";
        $configContent .= "define('SITE_NAME', " . var_export($newName, true) . ");\n";
        if ($newSlogan !== '') {
            $configContent .= "define('SITE_SLOGAN', " . var_export($newSlogan, true) . ");\n";
        }
        $configContent .= "define('SITE_LANG', " . var_export($newLang, true) . ");\n";

        // 保留 SMTP 配置（由邮件中心管理）
        if (defined('SMTP_ENABLED')) {
            $configContent .= "define('SMTP_ENABLED', " . var_export(SMTP_ENABLED, true) . ");\n";
        }
        if (defined('SMTP_HOST') && SMTP_HOST !== '') {
            $configContent .= "define('SMTP_HOST', " . var_export(SMTP_HOST, true) . ");\n";
            $configContent .= "define('SMTP_PORT', " . var_export(defined('SMTP_PORT') ? SMTP_PORT : 587, true) . ");\n";
            $configContent .= "define('SMTP_USER', " . var_export(defined('SMTP_USER') ? SMTP_USER : '', true) . ");\n";
            $configContent .= "define('SMTP_PASS', " . var_export(defined('SMTP_PASS') ? SMTP_PASS : '', true) . ");\n";
            $configContent .= "define('SMTP_ENCRYPTION', " . var_export(defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls', true) . ");\n";
            $configContent .= "define('SMTP_FROM', " . var_export(defined('SMTP_FROM') ? SMTP_FROM : '', true) . ");\n";
            $configContent .= "define('SMTP_FROM_NAME', " . var_export(defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : $newName, true) . ");\n";
        }

        if (@file_put_contents(DATA_PATH . 'site_config.php', $configContent) !== false) {
            // 全站语言写入数据库（前台/后台所有页面同步生效，不依赖文件缓存；
            // 所有用户语言统一由站点设置决定，无需写 Cookie）
            set_site_setting('site_lang', $newLang);
            // 人机验证开关（注册/登录/找回密码页），同时写旧键以兼容历史版本
            $captchaEnabled = !empty($_POST['captcha_enabled']) ? '1' : '0';
            set_site_setting('captcha_enabled', $captchaEnabled);
            set_site_setting('slider_captcha_enabled', $captchaEnabled);
            // 验证方式：拼图 / 点文字 / 推理交换 / 图片字母 / 智能混合
            $captchaStyle = $_POST['captcha_style'] ?? 'slider';
            if (!in_array($captchaStyle, ['slider', 'click', 'swap', 'letter', 'auto'], true)) {
                $captchaStyle = 'slider';
            }
            set_site_setting('captcha_style', $captchaStyle);
            // 触发模式
            $captchaTriggerMode = $_POST['captcha_trigger_mode'] ?? 'suspicious';
            if (!in_array($captchaTriggerMode, ['always', 'suspicious', 'high_risk'], true)) {
                $captchaTriggerMode = 'suspicious';
            }
            set_site_setting('captcha_trigger_mode', $captchaTriggerMode);
            // 冷却期
            $captchaSkipCooldown = (int)($_POST['captcha_skip_cooldown'] ?? '600');
            if ($captchaSkipCooldown < 60) $captchaSkipCooldown = 60;
            if ($captchaSkipCooldown > 86400) $captchaSkipCooldown = 86400;
            set_site_setting('captcha_skip_cooldown', (string)$captchaSkipCooldown);
            // 显示方式：内嵌 / 弹窗
            $captchaDisplay = $_POST['captcha_display'] ?? 'inline';
            if (!in_array($captchaDisplay, ['inline', 'popup'], true)) {
                $captchaDisplay = 'inline';
            }
            set_site_setting('captcha_display', $captchaDisplay);
            // 验证难度：简单 / 普通 / 困难
            $captchaDifficulty = $_POST['captcha_difficulty'] ?? 'normal';
            if (!in_array($captchaDifficulty, ['easy', 'normal', 'hard'], true)) {
                $captchaDifficulty = 'normal';
            }
            set_site_setting('captcha_difficulty', $captchaDifficulty);
            // 图片字母验证难度：简单 / 容易 / 困难 / 超难 / 地狱（独立于整体难度）
            $captchaLetterDifficulty = $_POST['captcha_letter_difficulty'] ?? 'easy';
            if (!in_array($captchaLetterDifficulty, ['simple', 'easy', 'hard', 'very_hard', 'hell'], true)) {
                $captchaLetterDifficulty = 'easy';
            }
            set_site_setting('captcha_letter_difficulty', $captchaLetterDifficulty);
            // 调试模式：开启后前台跳过验证
            $captchaDebug = !empty($_POST['captcha_debug']) ? '1' : '0';
            set_site_setting('captcha_debug', $captchaDebug);
            // 强化防机器人：工作量证明（PoW）开启与难度
            $captchaPowEnabled = !empty($_POST['captcha_pow_enabled']) ? '1' : '0';
            set_site_setting('captcha_pow_enabled', $captchaPowEnabled);
            $captchaPowBits = (int)($_POST['captcha_pow_bits'] ?? '3');
            if ($captchaPowBits < 1) $captchaPowBits = 1;
            if ($captchaPowBits > 6) $captchaPowBits = 6;
            set_site_setting('captcha_pow_bits', (string)$captchaPowBits);
            // 强化防机器人：蜜罐字段
            $captchaHoneypotEnabled = !empty($_POST['captcha_honeypot_enabled']) ? '1' : '0';
            set_site_setting('captcha_honeypot_enabled', $captchaHoneypotEnabled);
            // 强化防机器人：失败升级
            $captchaEscalationEnabled = !empty($_POST['captcha_escalation_enabled']) ? '1' : '0';
            set_site_setting('captcha_escalation_enabled', $captchaEscalationEnabled);
            // 强化防机器人：挑战轮换
            $captchaRotationEnabled = !empty($_POST['captcha_rotation_enabled']) ? '1' : '0';
            set_site_setting('captcha_rotation_enabled', $captchaRotationEnabled);
            set_flash(t('settings_save_success', '站点设置已保存。'), 'success');
            redirect('/admin/site_settings');
        } else {
            $errors[] = t('admin_settings_save_failed', '保存失败，请检查 data 目录是否可写。');
        }
    }
}

$pageTitle = t('settings_title', '站点设置');
$activeMenu = 'settings';

$currentLang = APP_LANG;

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo e(t('settings_title', '站点设置')); ?></h1>
        <p class="page-subtitle"><?php echo e(t('settings_basic_desc', '管理站点基本信息与品牌标识')); ?></p>
    </div>
    <div class="page-tools">
        <a href="<?php echo site_url('admin/mail_center'); ?>" class="btn btn-secondary btn-sm"><?php echo e(t('mail_center', '邮件中心')); ?></a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
        <?php echo show_message($err, 'error'); ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php
$flash = get_flash();
if ($flash): ?>
    <?php echo show_message($flash['message'], $flash['type']); ?>
<?php endif; ?>

<div class="card">
    <h2 class="card-title mb-1"><?php echo e(t('settings_basic', '基本信息')); ?></h2>
    <p class="text-muted mb-2"><?php echo e(t('settings_basic_hint', '修改站点名称和副标题，副标题留空则前台不显示。')); ?></p>

    <form method="POST" action="<?php echo site_url('admin/site_settings'); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <div class="form-group">
            <label class="form-label" for="site_name"><?php echo e(t('settings_site_name', '站点名称')); ?></label>
            <input type="text" class="form-control" id="site_name" name="site_name" value="<?php echo e($siteName); ?>" required maxlength="50">
            <p class="form-hint"><?php echo e(t('settings_site_name_hint', '将显示在浏览器标题栏和导航栏。')); ?></p>
        </div>

        <div class="form-group">
            <label class="form-label" for="site_slogan"><?php echo e(t('settings_site_slogan', '站点副标题')); ?></label>
            <input type="text" class="form-control" id="site_slogan" name="site_slogan" value="<?php echo e($siteSlogan); ?>" maxlength="100" placeholder="<?php echo e(t('admin_settings_slogan_placeholder', '例如：官方网站、交流社区（留空则不显示）')); ?>">
            <p class="form-hint"><?php echo e(t('settings_site_slogan_hint', '显示在 logo 下方的简短说明，留空则前台不显示。')); ?></p>
        </div>

        <div class="form-group">
            <label class="form-label" for="site_lang"><?php echo e(t('settings_language', '界面语言')); ?></label>
            <select class="form-control" id="site_lang" name="site_lang" style="max-width: 260px;">
                <?php foreach (get_available_languages() as $code => $info): ?>
                    <option value="<?php echo $code; ?>" <?php echo $code === $currentLang ? 'selected' : ''; ?>>
                        <?php echo e($info['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint"><?php echo e(t('settings_language_hint', '切换后管理员后台和前台界面的语言将同步变更。')); ?></p>
        </div>

        <div class="form-group">
            <label class="flex items-center gap-1 cursor-pointer">
                <input type="checkbox" id="captcha_enabled" name="captcha_enabled" value="1" <?php echo $sliderCaptchaEnabled ? 'checked' : ''; ?>>
                <span><?php echo e(t('settings_slider_captcha', '启用验证码（人机验证）')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('settings_slider_captcha_hint', '开启后，注册、登录、找回密码页面将显示「我是人类」验证框：正常用户点击即可通过，可疑行为会展开挑战，可有效防止机器人注册与撞库攻击。')); ?></p>
            <label class="form-label mt-3" for="captcha_style"><?php echo e(t('settings_captcha_style', '验证方式')); ?></label>
            <select class="form-control" id="captcha_style" name="captcha_style">
                <option value="slider" <?php echo $captchaStyle === 'slider' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_slider', '拼图验证（拖拽滑块对齐缺口）')); ?></option>
                <option value="click" <?php echo $captchaStyle === 'click' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_click', '点文字验证（按顺序点击文字）')); ?></option>
                <option value="swap" <?php echo $captchaStyle === 'swap' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_swap', '推理交换验证（交换图块复原图片）')); ?></option>
                <option value="letter" <?php echo $captchaStyle === 'letter' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_letter', '图片字母验证（输入图中的字符）')); ?></option>
                <option value="auto" <?php echo $captchaStyle === 'auto' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_auto', '智能混合（随机切换两种）')); ?></option>
            </select>
            <p class="form-hint"><?php echo e(t('settings_captcha_style_hint', '拼图验证：拖动拼图块与缺口对齐；点文字验证：按提示词顺序依次点击候选字；推理交换：拖动交换打乱的图块使其恢复完整；图片字母：输入图片中显示的字符。智能混合会在多种验证间随机切换，安全性与体验的平衡更好。')); ?></p>
            <label class="form-label mt-3" for="captcha_display"><?php echo e(t('settings_captcha_display', '显示方式')); ?></label>
            <select class="form-control" id="captcha_display" name="captcha_display">
                <option value="inline" <?php echo $captchaDisplay === 'inline' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_display_inline', '内嵌式（验证框直接显示在表单中）')); ?></option>
                <option value="popup" <?php echo $captchaDisplay === 'popup' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_display_popup', '弹窗式（点击提交时弹出验证框）')); ?></option>
            </select>
            <p class="form-hint"><?php echo e(t('settings_captcha_display_hint', '弹窗式在用户点击登录、注册、重置密码提交按钮时才弹出验证框，界面更简洁；内嵌式则始终显示在表单中。')); ?></p>
            <label class="form-label mt-3" for="captcha_difficulty"><?php echo e(t('settings_captcha_difficulty', '验证难度')); ?></label>
            <select class="form-control" id="captcha_difficulty" name="captcha_difficulty">
                <option value="easy" <?php echo $captchaDifficulty === 'easy' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_difficulty_easy', '简单（友好通过，验证少）')); ?></option>
                <option value="normal" <?php echo $captchaDifficulty === 'normal' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_difficulty_normal', '普通（默认，平衡体验与安全）')); ?></option>
                <option value="hard" <?php echo $captchaDifficulty === 'hard' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_difficulty_hard', '困难（严格校验，挑战更多）')); ?></option>
            </select>
            <p class="form-hint"><?php echo e(t('settings_captcha_difficulty_hint', '难度越高，行为验证通过门槛越高、滑块容差越小、点选目标字越多，防止机器人也更严格。')); ?></p>
            <label class="form-label mt-3" for="captcha_letter_difficulty"><?php echo e(t('settings_captcha_letter_difficulty', '图片字母验证难度')); ?></label>
            <select class="form-control" id="captcha_letter_difficulty" name="captcha_letter_difficulty">
                <option value="simple" <?php echo $captchaLetterDifficulty === 'simple' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_letter_difficulty_simple', '简单（4 位字符，干扰最少）')); ?></option>
                <option value="easy" <?php echo $captchaLetterDifficulty === 'easy' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_letter_difficulty_easy', '容易（4 位字符，轻量干扰）')); ?></option>
                <option value="hard" <?php echo $captchaLetterDifficulty === 'hard' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_letter_difficulty_hard', '困难（5 位字符，中等干扰）')); ?></option>
                <option value="very_hard" <?php echo $captchaLetterDifficulty === 'very_hard' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_letter_difficulty_very_hard', '超难（6 位字符，强干扰）')); ?></option>
                <option value="hell" <?php echo $captchaLetterDifficulty === 'hell' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_letter_difficulty_hell', '地狱（7 位字符，极强干扰）')); ?></option>
            </select>
            <p class="form-hint"><?php echo e(t('settings_captcha_letter_difficulty_hint', '仅对「图片字母验证」方式生效，可独立于上方整体难度单独设置。难度越高字符越多、旋转与干扰越强，机器人越难识别。')); ?></p>
            <label class="form-label mt-3" for="captcha_trigger_mode"><?php echo e(t('settings_captcha_trigger_mode', '触发模式')); ?></label>
            <select class="form-control" id="captcha_trigger_mode" name="captcha_trigger_mode">
                <option value="always" <?php echo $captchaTriggerMode === 'always' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_trigger_always', '始终显示（安全级别最高）')); ?></option>
                <option value="suspicious" <?php echo $captchaTriggerMode === 'suspicious' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_trigger_suspicious', '可疑行为触发（默认，体验与安全平衡）')); ?></option>
                <option value="high_risk" <?php echo $captchaTriggerMode === 'high_risk' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_trigger_high_risk', '仅高风险操作触发（发帖/私信等）')); ?></option>
            </select>
            <p class="form-hint"><?php echo e(t('settings_captcha_trigger_hint', '始终显示：所有用户每次都必须完成验证；可疑行为触发：仅检测到机器人特征时才弹出验证；高风险触发：仅在发帖、私信等敏感操作时验证。')); ?></p>
            <label class="form-label mt-3" for="captcha_skip_cooldown"><?php echo e(t('settings_captcha_skip_cooldown', '冷却期（秒）')); ?></label>
            <input type="number" class="form-control" id="captcha_skip_cooldown" name="captcha_skip_cooldown" value="<?php echo e($captchaSkipCooldown); ?>" min="60" max="86400" style="max-width: 200px;">
            <p class="form-hint"><?php echo e(t('settings_captcha_skip_cooldown_hint', '用户通过验证后，在此期间内不会再被要求验证。建议 600-1800 秒。')); ?></p>
            <hr class="form-divider" style="margin: 1rem 0;">
            <p class="text-muted mb-1" style="font-weight:600;"><?php echo e(t('settings_captcha_hardening', '强化防机器人（抗跳过 / 抗 AI 识别）')); ?></p>
            <label class="flex items-center gap-1" style="margin-top: 0.5rem; cursor: pointer;">
                <input type="checkbox" id="captcha_pow_enabled" name="captcha_pow_enabled" value="1" <?php echo $captchaPowEnabled ? 'checked' : ''; ?>>
                <span><?php echo e(t('settings_captcha_pow', '启用工作量证明（PoW）')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('settings_captcha_pow_hint', '验证时浏览器需在前端计算一个满足「哈希前 N 位为零」的 nonce 才允许提交。这能消耗自动化脚本的算力、防止直接 POST 跳过验证，且对真人几乎无感（毫秒级）。')); ?></p>
            <label class="form-label mt-3" for="captcha_pow_bits"><?php echo e(t('settings_captcha_pow_bits', 'PoW 难度（前导零位数 1-6）')); ?></label>
            <input type="number" class="form-control" id="captcha_pow_bits" name="captcha_pow_bits" value="<?php echo e($captchaPowBits); ?>" min="1" max="6" style="max-width: 200px;">
            <p class="form-hint"><?php echo e(t('settings_captcha_pow_bits_hint', '位数越高，前端求解耗时越长。普通 3、严格 4-5。设置过高会明显拖慢正常用户提交，请谨慎。')); ?></p>
            <label class="flex items-center gap-1" style="margin-top: 0.75rem; cursor: pointer;">
                <input type="checkbox" id="captcha_honeypot_enabled" name="captcha_honeypot_enabled" value="1" <?php echo $captchaHoneypotEnabled ? 'checked' : ''; ?>>
                <span><?php echo e(t('settings_captcha_honeypot', '启用蜜罐字段')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('settings_captcha_honeypot_hint', '在表单中埋入一个对真人隐藏、仅机器人会自动填写的输入框。一旦被填写即判定为机器并直接拒绝，专门克制「识别验证码后自动 POST」的脚本。')); ?></p>
            <label class="flex items-center gap-1" style="margin-top: 0.75rem; cursor: pointer;">
                <input type="checkbox" id="captcha_escalation_enabled" name="captcha_escalation_enabled" value="1" <?php echo $captchaEscalationEnabled ? 'checked' : ''; ?>>
                <span><?php echo e(t('settings_captcha_escalation', '失败自动升级挑战')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('settings_captcha_escalation_hint', '同一会话连续验证失败后，自动切换为更严苛的挑战并收紧容差（滑块容差减半、强制滑块），显著增加机器人撞库成本。')); ?></p>
            <label class="flex items-center gap-1" style="margin-top: 0.75rem; cursor: pointer;">
                <input type="checkbox" id="captcha_rotation_enabled" name="captcha_rotation_enabled" value="1" <?php echo $captchaRotationEnabled ? 'checked' : ''; ?>>
                <span><?php echo e(t('settings_captcha_rotation', '启用挑战轮换')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('settings_captcha_rotation_hint', '在「智能混合」基础上，同一会话每隔几次验证随机切换挑战类型，防止机器人针对单一验证方式建立行为模型。')); ?></p>
            <label class="flex items-center gap-1" style="margin-top: 0.75rem; cursor: pointer;">
                <input type="checkbox" id="captcha_debug" name="captcha_debug" value="1" <?php echo $captchaDebug ? 'checked' : ''; ?>>
                <span style="font-weight:600;"><?php echo e(t('settings_captcha_debug', '调试模式（前台绕过验证）')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('settings_captcha_debug_hint', '开启后登录/注册/找回密码页将跳过人机验证（token 任意值均通过），方便开发调试。验证码组件仍正常渲染，可在「验证码调试」页面进行完整测试。生产环境请关闭。')); ?></p>
        </div>

        <button type="submit" class="btn btn-primary"><?php echo e(t('settings_save', '保存设置')); ?></button>
    </form>
</div>

<!-- 邮件服务快捷入口 -->
<a href="<?php echo site_url('admin/mail_center'); ?>" class="card mail-entry-card">
    <div class="mail-entry-icon <?php echo $smtpEnabled ? 'is-on' : 'is-off'; ?>">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
    </div>
    <div class="mail-entry-body">
        <div class="mail-entry-title">
            <?php echo e(t('mail_service', '邮件服务')); ?>
            <span class="mail-entry-badge <?php echo $smtpEnabled ? 'is-on' : 'is-off'; ?>"><?php echo $smtpEnabled ? t('mail_running', '运行中') : t('mail_disabled', '未启用'); ?></span>
        </div>
        <div class="mail-entry-meta">
            <?php if ($smtpEnabled && $smtpFrom !== ''): ?>
                <?php echo e(t('mail_current_sender', '当前发件人：')); ?><?php echo e($smtpFrom); ?>
            <?php else: ?>
                <?php echo e(t('mail_entry_hint', '前往邮件中心配置 SMTP，启用注册验证码、密码重置等邮件功能。')); ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="mail-entry-arrow">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
    </div>
</a>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
