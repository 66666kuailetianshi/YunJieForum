<?php
/**
 * 云界论坛 - 管理后台站点设置
 *
 * 仅负责站点基本信息（名称 / 副标题）。
 * SMTP 邮件配置已迁移至「邮件中心」mail_center.php。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$errors  = [];
$success = false;

$siteName   = defined('SITE_NAME') ? SITE_NAME : APP_NAME;
$siteSlogan = defined('SITE_SLOGAN') ? SITE_SLOGAN : '';

$smtpEnabled = defined('SMTP_ENABLED') ? SMTP_ENABLED : false;
$smtpFrom    = defined('SMTP_FROM') ? SMTP_FROM : '';

$sliderCaptchaEnabled = get_site_setting('captcha_enabled', get_site_setting('slider_captcha_enabled', '0')) === '1';
$captchaStyle   = get_site_setting('captcha_style', 'slider');
$captchaDebug   = get_site_setting('captcha_debug', '0') === '1';

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
            // 验证方式：拼图 / 点文字 / 智能混合
            $captchaStyle = $_POST['captcha_style'] ?? 'slider';
            if (!in_array($captchaStyle, ['slider', 'click', 'auto'], true)) {
                $captchaStyle = 'slider';
            }
            set_site_setting('captcha_style', $captchaStyle);
            // 调试模式：开启后前台跳过验证
            $captchaDebug = !empty($_POST['captcha_debug']) ? '1' : '0';
            set_site_setting('captcha_debug', $captchaDebug);
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
            <label class="flex items-center gap-1" style="cursor: pointer;">
                <input type="checkbox" id="captcha_enabled" name="captcha_enabled" value="1" <?php echo $sliderCaptchaEnabled ? 'checked' : ''; ?>>
                <span><?php echo e(t('settings_slider_captcha', '启用验证码（人机验证）')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('settings_slider_captcha_hint', '开启后，注册、登录、找回密码页面将显示「我是人类」验证框：正常用户点击即可通过，可疑行为会展开挑战，可有效防止机器人注册与撞库攻击。')); ?></p>
            <label class="form-label" for="captcha_style" style="margin-top: 0.75rem;"><?php echo e(t('settings_captcha_style', '验证方式')); ?></label>
            <select class="form-control" id="captcha_style" name="captcha_style">
                <option value="slider" <?php echo $captchaStyle === 'slider' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_slider', '拼图验证（拖拽滑块对齐缺口）')); ?></option>
                <option value="click" <?php echo $captchaStyle === 'click' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_click', '点文字验证（按顺序点击文字）')); ?></option>
                <option value="auto" <?php echo $captchaStyle === 'auto' ? 'selected' : ''; ?>><?php echo e(t('settings_captcha_auto', '智能混合（随机切换两种）')); ?></option>
            </select>
            <p class="form-hint"><?php echo e(t('settings_captcha_style_hint', '拼图验证：拖动拼图块与缺口对齐；点文字验证：按提示词顺序依次点击候选字。智能混合会在两者间随机切换，安全性与体验的平衡更好。')); ?></p>
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
