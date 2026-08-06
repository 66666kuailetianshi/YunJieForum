<?php
/**
 * 云界论坛 - 管理后台邮件中心
 *
 * 整合 SMTP 配置、连通性测试、邮件模板预览为一体。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';
require_once APP_ROOT . 'app/includes/bounce_processor.php';

$db = get_db();

$errors   = [];
$success  = false;
$testMsg  = null;   // 测试邮件反馈
$saveMsg  = null;   // 配置保存反馈

// 加载退信处理配置与统计
$bounceProcessor = new BounceProcessor();
$bounceConfig = $bounceProcessor->getConfig();
$bounceStats = $bounceProcessor->getBounceStats();
$bounceRecentLogs = $bounceProcessor->getRecentBounceLogs(5);
$imapAvailable = function_exists('imap_open');

// 群发通知所需数据
$notifyStats = [
    'total_users'      => 0,
    'active_users'     => 0,
    'users_with_email' => 0,
];
try {
    $notifyStats['total_users']      = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $notifyStats['active_users']     = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
    $notifyStats['users_with_email'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE email IS NOT NULL AND email != '' AND status = 'active'")->fetchColumn();
} catch (Exception $e) {}
$notifyGroups = [];
try {
    $notifyGroups = $db->query("SELECT id, name FROM user_groups ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$siteName = defined('SITE_NAME') ? SITE_NAME : APP_NAME;

$smtpEnabled    = defined('SMTP_ENABLED') ? SMTP_ENABLED : false;
$smtpHost       = defined('SMTP_HOST') ? SMTP_HOST : '';
$smtpPort       = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
$smtpUser       = defined('SMTP_USER') ? SMTP_USER : '';
$smtpPass       = defined('SMTP_PASS') ? SMTP_PASS : '';
$smtpEncryption = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls';
$smtpFrom       = defined('SMTP_FROM') ? SMTP_FROM : '';
$smtpFromName   = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : $siteName;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'test') {
        // 发送测试邮件（使用当前已保存的配置）
        $testEmail = trim($_POST['test_email'] ?? '');
        $testSubject = trim($_POST['test_subject'] ?? '');
        $testContent = trim($_POST['test_content'] ?? '');
        if ($testSubject === '') {
            $testSubject = t('mail_center_test_subject', '【{site}】SMTP 测试邮件', ['site' => $siteName]);
        }
        if ($testContent === '') {
            $testContent = t('mail_center_test_default_content', '这是一封来自 {site} 的 SMTP 测试邮件。如果您收到此邮件，说明邮件发送配置正确。', ['site' => $siteName]);
        }
        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $testMsg = ['type' => 'error', 'text' => t('mail_center_test_email_invalid', '请输入有效的测试收件邮箱。')];
        } else {
            // 转义内容并转换为 HTML 段落
            $htmlContent = '<p>' . nl2br(e($testContent)) . '</p>';
            $htmlContent .= '<p style="color:#71717a;">' . t('mail_center_test_sent_meta', '发送时间：{time}<br>收件人：{email}', ['time' => date('Y-m-d H:i:s'), 'email' => e($testEmail)]) . '</p>';
            $body = render_email_template(t('mail_center_test_mail_title', 'SMTP 测试邮件'), $htmlContent, [
                'subject'     => $testSubject,
                'action_text' => t('mail_center_go_to', '前往 {site}', ['site' => $siteName]),
            ]);
            $result = send_mail($testEmail, '', $testSubject, $body, 'test');
            if ($result['success']) {
                $testMsg = ['type' => 'success', 'text' => t('mail_center_test_sent', '测试邮件已发送至 {email}，请查收。', ['email' => $testEmail])];
            } else {
                $testMsg = ['type' => 'error', 'text' => t('mail_center_test_failed', '发送失败：{error}', ['error' => $result['error']])];
            }
        }
    } else {
        // 保存 SMTP 配置
        $newSmtpEnabled = !empty($_POST['smtp_enabled']);
        $newSmtpHost    = trim($_POST['smtp_host'] ?? '');
        $newSmtpPort    = (int)($_POST['smtp_port'] ?? 587);
        $newSmtpUser    = trim($_POST['smtp_user'] ?? '');
        $newSmtpPass    = $_POST['smtp_pass'] ?? '';
        // 密码留空时保留原密码
        if ($newSmtpPass === '' && defined('SMTP_PASS') && SMTP_PASS !== '') {
            $newSmtpPass = SMTP_PASS;
        }
        $newSmtpEncryption = in_array($_POST['smtp_encryption'] ?? '', ['', 'ssl', 'tls'], true) ? ($_POST['smtp_encryption'] ?? '') : 'tls';
        $newSmtpFrom       = trim($_POST['smtp_from'] ?? '');
        $newSmtpFromName   = trim($_POST['smtp_from_name'] ?? $siteName);

        if ($newSmtpEnabled) {
            if ($newSmtpHost === '' || $newSmtpPort <= 0 || $newSmtpFrom === '') {
                $errors[] = t('mail_center_error_required_fields', '启用邮件功能时，SMTP 服务器、端口和发件人邮箱不能为空。');
            } elseif (!filter_var($newSmtpFrom, FILTER_VALIDATE_EMAIL)) {
                $errors[] = t('mail_center_error_from_email_invalid', '发件人邮箱格式不正确。');
            }
        }

        if (empty($errors)) {
            if (!is_dir(DATA_PATH)) {
                @mkdir(DATA_PATH, 0755, true);
            }

            // 同时读取并保留站点名称/副标题（site_config.php 是共享配置文件）
            $savedName   = defined('SITE_NAME') ? SITE_NAME : APP_NAME;
            $savedSlogan = defined('SITE_SLOGAN') ? SITE_SLOGAN : '';

            $configContent  = t('admin_mail_center_8d75ab','<?php\\n// 站点配置（更新于 ') . date('Y-m-d H:i:s') . "）\n";
            $configContent .= "define('SITE_NAME', " . var_export($savedName, true) . ");\n";
            if ($savedSlogan !== '') {
                $configContent .= "define('SITE_SLOGAN', " . var_export($savedSlogan, true) . ");\n";
            }

            if ($newSmtpEnabled) {
                $configContent .= "define('SMTP_ENABLED', true);\n";
                $configContent .= "define('SMTP_HOST', " . var_export($newSmtpHost, true) . ");\n";
                $configContent .= "define('SMTP_PORT', " . var_export($newSmtpPort, true) . ");\n";
                $configContent .= "define('SMTP_USER', " . var_export($newSmtpUser, true) . ");\n";
                $configContent .= "define('SMTP_PASS', " . var_export($newSmtpPass, true) . ");\n";
                $configContent .= "define('SMTP_ENCRYPTION', " . var_export($newSmtpEncryption, true) . ");\n";
                $configContent .= "define('SMTP_FROM', " . var_export($newSmtpFrom, true) . ");\n";
                $configContent .= "define('SMTP_FROM_NAME', " . var_export($newSmtpFromName, true) . ");\n";
            } else {
                $configContent .= "define('SMTP_ENABLED', false);\n";
            }

            if (@file_put_contents(DATA_PATH . 'site_config.php', $configContent) !== false) {
                // 同步退信处理账户信息（同发件邮箱使用相同账户和授权码）
                if ($newSmtpEnabled && $newSmtpUser !== '') {
                    try {
                        ddl_exec("CREATE TABLE IF NOT EXISTS mail_bounce_config (
                            id INTEGER PRIMARY KEY CHECK (id = 1),
                            enabled INTEGER DEFAULT 0,
                            protocol VARCHAR(10) DEFAULT 'imap',
                            host VARCHAR(255) DEFAULT '',
                            port INTEGER DEFAULT 993,
                            encryption VARCHAR(10) DEFAULT 'ssl',
                            username VARCHAR(255) DEFAULT '',
                            password VARCHAR(255) DEFAULT '',
                            mailbox VARCHAR(100) DEFAULT 'INBOX',
                            last_check DATETIME DEFAULT NULL,
                            last_check_count INTEGER DEFAULT 0,
                            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        )");
                        ddl_exec("INSERT OR IGNORE INTO mail_bounce_config (id) VALUES (1)");
                        $stmt = $db->prepare("UPDATE mail_bounce_config SET username = :u, password = :p, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
                        $stmt->execute([':u' => $newSmtpFrom, ':p' => $newSmtpPass]);
                    } catch (Exception $e) {}
                }
                set_flash(t('mail_center_saved', '邮件配置已保存。'), 'success');
                redirect('/admin/mail_center');
            } else {
                $errors[] = t('mail_center_error_save_failed', '保存失败，请检查 data 目录是否可写。');
            }
        }
    }
}

// 加密方式中文标签
$encLabels = ['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL (SMTPS)', '' => t('mail_center_enc_none', '无加密')];
$encLabel  = $encLabels[$smtpEncryption] ?? $smtpEncryption;

// 邮件发送统计 - 首次加载从 PHP 读取，后续通过 AJAX 实时刷新
$stats = [
    'total'    => 0,
    'success'  => 0,
    'failed'   => 0,
    'today'    => 0,
    'today_success' => 0,
    'today_failed'  => 0,
    'success_rate'  => 0,
    'types'    => [],
];
$typeLabels = [
    'verify'       => t('mail_center_type_verify', '注册验证码'),
    'reset'        => t('mail_center_type_reset', '密码重置'),
    'appeal'       => t('mail_center_type_appeal', '申诉通知'),
    'ban'          => t('mail_center_type_ban', '封禁通知'),
    'test'         => t('mail_center_type_test', '测试邮件'),
    'notification' => t('mail_center_type_notification', '系统通知'),
    'other'        => t('mail_center_type_other', '其他'),
];
try {
    $stats['total']   = (int)$db->query("SELECT COUNT(*) FROM mail_logs")->fetchColumn();
    $stats['success'] = (int)$db->query("SELECT COUNT(*) FROM mail_logs WHERE status='success'")->fetchColumn();
    $stats['failed']  = (int)$db->query("SELECT COUNT(*) FROM mail_logs WHERE status='failed'")->fetchColumn();
    $stats['success_rate'] = $stats['total'] > 0 ? round($stats['success'] / $stats['total'] * 100, 1) : 0;

    $todayStart = gmdate('Y-m-d 00:00:00');
    $stmt = $db->prepare("SELECT COUNT(*) FROM mail_logs WHERE created_at >= :today");
    $stmt->execute([':today' => $todayStart]);
    $stats['today'] = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM mail_logs WHERE created_at >= :today AND status='success'");
    $stmt->execute([':today' => $todayStart]);
    $stats['today_success'] = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM mail_logs WHERE created_at >= :today AND status='failed'");
    $stmt->execute([':today' => $todayStart]);
    $stats['today_failed'] = (int)$stmt->fetchColumn();

    $typeRows = $db->query("SELECT type, COUNT(*) as cnt, SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as ok FROM mail_logs GROUP BY type ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($typeRows as $row) {
        $typeKey = $row['type'];
        $stats['types'][] = [
            'key'    => $typeKey,
            'label'  => $typeLabels[$typeKey] ?? $typeKey,
            'total'  => (int)$row['cnt'],
            'success'=> (int)$row['ok'],
            'failed' => (int)$row['cnt'] - (int)$row['ok'],
        ];
    }

    $recentLogs = $db->query("SELECT recipient, recipient_name, subject, type, status, error_message, created_at, bounce_status, bounce_type, bounce_reason FROM mail_logs ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentLogs = [];
}

$pageTitle = t('mail_center_title', '邮件中心');
$activeMenu = 'mail_center';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo t('mail_center_title', '邮件中心'); ?></h1>
        <p class="page-subtitle"><?php echo t('mail_center_subtitle', '配置 SMTP 发信、测试连通性、预览邮件模板'); ?></p>
    </div>
    <div class="page-tools">
        <a href="<?php echo site_url('admin/site_settings'); ?>" class="btn btn-secondary btn-sm"><?php echo t('mail_center_site_settings', '站点设置'); ?></a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
        <?php echo show_message($err, 'error'); ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($testMsg): ?>
    <?php echo show_message($testMsg['text'], $testMsg['type']); ?>
<?php endif; ?>

<?php
$flash = get_flash();
if ($flash): ?>
    <?php echo show_message($flash['message'], $flash['type']); ?>
<?php endif; ?>

<!-- 状态横幅 -->
<div class="mail-status-banner <?php echo $smtpEnabled ? 'is-on' : 'is-off'; ?>">
    <div class="mail-status-banner-icon">
        <?php if ($smtpEnabled): ?>
            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 6l-10 7L2 6"/><rect x="2" y="4" width="20" height="16" rx="2"/></svg>
        <?php else: ?>
            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/><line x1="2" y1="20" x2="22" y2="20"/></svg>
        <?php endif; ?>
    </div>
    <div class="mail-status-banner-body">
        <div class="mail-status-banner-title">
            <?php echo $smtpEnabled ? t('mail_center_status_on', '邮件服务已启用') : t('mail_center_status_off', '邮件服务未启用'); ?>
        </div>
        <div class="mail-status-banner-meta">
            <?php if ($smtpEnabled): ?>
                <span class="mail-meta-item"><span class="mail-meta-label"><?php echo t('mail_center_status_server', '服务器'); ?></span><?php echo e($smtpHost . ':' . $smtpPort); ?></span>
                <span class="mail-meta-divider"></span>
                <span class="mail-meta-item"><span class="mail-meta-label"><?php echo t('mail_center_status_encryption', '加密'); ?></span><?php echo e($encLabel); ?></span>
                <span class="mail-meta-divider"></span>
                <span class="mail-meta-item"><span class="mail-meta-label"><?php echo t('mail_center_status_from', '发件人'); ?></span><?php echo e($smtpFromName); ?> &lt;<?php echo e($smtpFrom); ?>&gt;</span>
            <?php else: ?>
                <span><?php echo t('mail_center_status_off_hint', '启用后可发送注册验证码、密码重置、申诉通知等邮件。'); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="mail-status-banner-badge">
        <?php echo $smtpEnabled ? t('mail_center_status_running', '运行中') : t('mail_center_status_stopped', '已停用'); ?>
    </div>
</div>

<!-- Tab 导航 -->
<div class="mail-tabs" role="tablist">
    <button type="button" class="mail-tab active" data-tab="overview" role="tab">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        <span><?php echo t('mail_center_tab_overview', '概览'); ?></span>
    </button>
    <button type="button" class="mail-tab" data-tab="bounce" role="tab">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
        <span><?php echo t('mail_center_tab_bounce', '退信处理'); ?></span>
        <?php if ($bounceStats['total_bounced'] > 0): ?>
        <span class="mail-tab-badge"><?php echo $bounceStats['total_bounced']; ?></span>
        <?php endif; ?>
    </button>
    <button type="button" class="mail-tab" data-tab="config" role="tab">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        <span><?php echo t('mail_center_tab_config', 'SMTP 配置'); ?></span>
    </button>
    <button type="button" class="mail-tab" data-tab="notify" role="tab">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        <span><?php echo t('mail_center_tab_notify', '群发通知'); ?></span>
    </button>
    <button type="button" class="mail-tab" data-tab="template" role="tab">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <span><?php echo t('mail_center_tab_template', '模板预览'); ?></span>
    </button>
</div>

<!-- Tab 面板：概览 -->
<div class="mail-tab-panel active" data-panel="overview">
<!-- 邮件发送统计 -->
<div class="mail-stats-grid">
    <div class="mail-stat-card">
        <div class="mail-stat-icon mail-stat-icon-total">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        </div>
        <div class="mail-stat-body">
            <div class="mail-stat-value" id="stat-total"><?php echo $stats['total']; ?></div>
            <div class="mail-stat-label"><?php echo t('mail_center_stat_total', '累计发送'); ?></div>
        </div>
    </div>
    <div class="mail-stat-card">
        <div class="mail-stat-icon mail-stat-icon-success">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="mail-stat-body">
            <div class="mail-stat-value" id="stat-success"><?php echo $stats['success']; ?></div>
            <div class="mail-stat-label"><?php echo t('mail_center_stat_success', '发送成功'); ?></div>
        </div>
    </div>
    <div class="mail-stat-card">
        <div class="mail-stat-icon mail-stat-icon-failed">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="mail-stat-body">
            <div class="mail-stat-value" id="stat-failed"><?php echo $stats['failed']; ?></div>
            <div class="mail-stat-label"><?php echo t('mail_center_stat_failed', '发送失败'); ?></div>
        </div>
    </div>
    <div class="mail-stat-card">
        <div class="mail-stat-icon mail-stat-icon-rate">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        </div>
        <div class="mail-stat-body">
            <div class="mail-stat-value" id="stat-rate"><?php echo $stats['success_rate']; ?>%</div>
            <div class="mail-stat-label"><?php echo t('mail_center_stat_rate', '成功率'); ?></div>
        </div>
    </div>
    <div class="mail-stat-card">
        <div class="mail-stat-icon mail-stat-icon-today">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <div class="mail-stat-body">
            <div class="mail-stat-value" id="stat-today"><?php echo $stats['today']; ?></div>
            <div class="mail-stat-label"><?php echo t('mail_center_stat_today', '今日发送'); ?></div>
            <div class="mail-stat-sub" id="stat-today-detail"><?php echo t('mail_center_stat_today_detail', '{x} 成功 / {y} 失败', ['x' => $stats['today_success'], 'y' => $stats['today_failed']]); ?></div>
        </div>
    </div>
</div>

<!-- 按类型统计 + 最近日志 -->
<div class="mail-grid">
    <div class="mail-main">
        <div class="card mail-card">
            <div class="card-header">
                <h2 class="card-title"><?php echo t('mail_center_type_distribution', '邮件类型分布'); ?></h2>
            </div>
            <div class="mail-type-list" id="mail-type-list">
                <?php if (!empty($stats['types'])): ?>
                    <?php foreach ($stats['types'] as $t): ?>
                        <div class="mail-type-row">
                            <div class="mail-type-label"><?php echo e($t['label']); ?></div>
                            <div class="mail-type-bar-wrap">
                                <div class="mail-type-bar" style="width: <?php echo $stats['total'] > 0 ? min(100, $t['total'] / $stats['total'] * 100) : 0; ?>%;"></div>
                            </div>
                            <div class="mail-type-num"><?php echo $t['total']; ?></div>
                            <div class="mail-type-rate">
                                <span class="mail-type-ok"><?php echo $t['success']; ?></span>
                                <span class="mail-type-sep">/</span>
                                <span class="mail-type-fail"><?php echo $t['failed']; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <p class="text-muted text-center py-2"><?php echo t('mail_center_no_records', '暂无发送记录。'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="mail-side">
        <div class="card mail-card">
            <div class="card-header">
                <h2 class="card-title"><?php echo t('mail_center_recent_logs', '最近发送记录'); ?></h2>
                <span class="mail-refresh-hint" id="mail-refresh-hint"><?php echo t('mail_center_refresh_live', '实时刷新中'); ?></span>
            </div>
            <div class="mail-log-list" id="mail-log-list">
                <?php if (!empty($recentLogs)): ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <div class="mail-log-item<?php echo (!empty($log['bounce_status']) && $log['bounce_status'] === 'bounced') ? ' is-bounced' : ''; ?>">
                            <div class="mail-log-row1">
                                <span class="mail-log-status <?php echo $log['status'] === 'success' ? 'is-ok' : 'is-fail'; ?>"></span>
                                <span class="mail-log-recipient" title="<?php echo e($log['recipient']); ?>"><?php echo e($log['recipient']); ?></span>
                                <?php if (!empty($log['bounce_status']) && $log['bounce_status'] === 'bounced'): ?>
                                <span class="mail-log-bounce" title="<?php echo e($log['bounce_reason'] ?? ''); ?>"><?php echo ($log['bounce_type'] ?? 'hard') === 'soft' ? t('mail_center_bounce_soft', '软退信') : t('mail_center_bounce_hard', '硬退信'); ?></span>
                                <?php endif; ?>
                                <span class="mail-log-time"><?php echo e(date('m-d H:i', db_time($log['created_at']))); ?></span>
                            </div>
                            <div class="mail-log-row2">
                                <span class="mail-log-type"><?php echo e($typeLabels[$log['type']] ?? $log['type']); ?></span>
                                <span class="mail-log-subject" title="<?php echo e($log['subject']); ?>"><?php echo e($log['subject']); ?></span>
                            </div>
                            <?php if ($log['status'] !== 'success' && !empty($log['error_message'])): ?>
                            <div class="mail-log-error"><?php echo e(mb_substr($log['error_message'], 0, 120)); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <p class="text-muted text-center py-2"><?php echo t('mail_center_no_records', '暂无发送记录。'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 退信处理 -->
</div><!-- 关闭 overview 面板 -->

<div class="mail-tab-panel" data-panel="bounce">
<div class="card mail-card" id="bounce-card">
    <div class="card-header">
        <h2 class="card-title"><?php echo t('mail_center_tab_bounce', '退信处理'); ?></h2>
        <label class="mail-switch <?php echo !empty($bounceConfig['enabled']) ? 'is-on' : ''; ?>">
            <input type="checkbox" id="bounce_enabled" <?php echo !empty($bounceConfig['enabled']) ? 'checked' : ''; ?>>
            <span class="mail-switch-track"><span class="mail-switch-thumb"></span></span>
            <span class="mail-switch-label"><?php echo !empty($bounceConfig['enabled']) ? t('mail_center_enabled', '已启用') : t('mail_center_disabled', '已停用'); ?></span>
        </label>
    </div>

    <?php if (!$imapAvailable): ?>
    <div class="mail-tip mail-tip-warning">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span><?php echo t('mail_center_imap_missing', 'PHP 未启用 <code>imap</code> 扩展，退信处理功能不可用。请在 <code>php.ini</code> 中启用 <code>extension=imap</code>。'); ?></span>
    </div>
    <?php endif; ?>

    <!-- 退信统计 -->
    <div class="bounce-stats-grid">
        <div class="bounce-stat-item">
            <div class="bounce-stat-value" id="bounce-stat-total"><?php echo $bounceStats['total_bounced']; ?></div>
            <div class="bounce-stat-label"><?php echo t('mail_center_stat_bounced', '已退信'); ?></div>
        </div>
        <div class="bounce-stat-item">
            <div class="bounce-stat-value" id="bounce-stat-hard" style="color:#ef4444;"><?php echo $bounceStats['hard_bounced']; ?></div>
            <div class="bounce-stat-label"><?php echo t('mail_center_bounce_hard', '硬退信'); ?></div>
        </div>
        <div class="bounce-stat-item">
            <div class="bounce-stat-value" id="bounce-stat-soft" style="color:#f59e0b;"><?php echo $bounceStats['soft_bounced']; ?></div>
            <div class="bounce-stat-label"><?php echo t('mail_center_bounce_soft', '软退信'); ?></div>
        </div>
        <div class="bounce-stat-item">
            <div class="bounce-stat-value" id="bounce-stat-pending" style="color:#3b82f6;"><?php echo $bounceStats['pending']; ?></div>
            <div class="bounce-stat-label"><?php echo t('mail_center_stat_pending', '待确认'); ?></div>
        </div>
    </div>

    <div class="mail-grid">
        <!-- 左侧：退信邮箱配置 -->
        <div class="mail-main">
            <input type="hidden" id="bounce_csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="bounce_protocol"><?php echo t('mail_center_bounce_protocol', '协议'); ?></label>
                    <select class="form-control" id="bounce_protocol">
                        <option value="imap" <?php echo ($bounceConfig['protocol'] ?? 'imap') === 'imap' ? 'selected' : ''; ?>><?php echo t('mail_center_bounce_imap_recommend', 'IMAP（推荐）'); ?></option>
                        <option value="pop3" <?php echo ($bounceConfig['protocol'] ?? '') === 'pop3' ? 'selected' : ''; ?>>POP3</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="bounce_encryption"><?php echo t('mail_center_bounce_encryption', '加密方式'); ?></label>
                    <select class="form-control" id="bounce_encryption">
                        <option value="ssl" <?php echo ($bounceConfig['encryption'] ?? 'ssl') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                        <option value="tls" <?php echo ($bounceConfig['encryption'] ?? '') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                        <option value="" <?php echo ($bounceConfig['encryption'] ?? '') === '' ? 'selected' : ''; ?>><?php echo t('mail_center_enc_none', '无加密'); ?></option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="bounce_host"><?php echo t('mail_center_bounce_host', '退信邮箱服务器'); ?></label>
                    <input type="text" class="form-control" id="bounce_host" value="<?php echo e($bounceConfig['host'] ?? ''); ?>" placeholder="imap.qq.com">
                </div>
                <div class="form-group">
                    <label class="form-label" for="bounce_port"><?php echo t('mail_center_bounce_port', '端口'); ?></label>
                    <input type="number" class="form-control" id="bounce_port" value="<?php echo e((string)($bounceConfig['port'] ?? 993)); ?>" placeholder="993">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="bounce_username"><?php echo t('mail_center_bounce_username', '邮箱账号'); ?> <span style="font-weight:400;color:var(--text-muted);">（<?php echo t('mail_center_bounce_same_as_from', '与 SMTP 发件人相同'); ?>）</span></label>
                    <input type="text" class="form-control" id="bounce_username" value="<?php echo e(!empty($bounceConfig['username']) ? $bounceConfig['username'] : $smtpFrom); ?>" placeholder="<?php echo e(t('mail_center_bounce_username_ph', '与发件人邮箱一致')); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="bounce_password"><?php echo t('mail_center_bounce_password', '密码 / 授权码'); ?> <span style="font-weight:400;color:var(--text-muted);">（<?php echo t('mail_center_bounce_same_as_smtp', '与 SMTP 相同'); ?>）</span></label>
                    <div class="input-pwd-wrap">
                        <input type="password" class="form-control" id="bounce_password" value="<?php echo e(!empty($bounceConfig['password']) ? $bounceConfig['password'] : $smtpPass); ?>" placeholder="<?php echo e(t('mail_center_bounce_password_ph', '与 SMTP 授权码一致')); ?>" autocomplete="new-password">
                        <button type="button" class="input-pwd-toggle" data-target="bounce_password" aria-label="<?php echo e(t('mail_center_show_hide_pwd', '显示或隐藏密码')); ?>">
                            <svg class="pwd-icon-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="pwd-icon-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>
            </div>
            <p class="form-hint" style="margin-top:-0.5rem;margin-bottom:1rem;">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <?php echo t('mail_center_bounce_sync_hint', '退信处理与 SMTP 发件使用同一邮箱账户和授权码。保存 SMTP 配置时将自动同步。'); ?>
            </p>
            <div class="form-group">
                <label class="form-label" for="bounce_mailbox"><?php echo t('mail_center_bounce_mailbox', '邮箱文件夹'); ?></label>
                <input type="text" class="form-control" id="bounce_mailbox" value="<?php echo e($bounceConfig['mailbox'] ?? 'INBOX'); ?>" placeholder="INBOX">
                <p class="form-hint"><?php echo t('mail_center_bounce_mailbox_hint', '一般填 INBOX，部分邮箱需填写其他文件夹名称。'); ?></p>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" id="bounce-save-btn"><?php echo t('mail_center_save_config', '保存配置'); ?></button>
                <button type="button" class="btn btn-secondary" id="bounce-test-btn"><?php echo t('mail_center_test_connection', '测试连接'); ?></button>
                <button type="button" class="btn btn-primary" id="bounce-check-btn" <?php echo !$imapAvailable ? 'disabled' : ''; ?>><?php echo t('mail_center_check_bounce_now', '立即检查退信'); ?></button>
            </div>
        </div>

        <!-- 右侧：检查历史 + 常见服务商配置 -->
        <div class="mail-side">
            <div class="bounce-status-box" id="bounce-status-box">
                <div class="bounce-status-title"><?php echo t('mail_center_bounce_last_check', '上次检查'); ?></div>
                <div class="bounce-status-time" id="bounce-last-check"><?php echo $bounceStats['last_check'] ? date('Y-m-d H:i:s', db_time($bounceStats['last_check'])) : t('mail_center_bounce_never_checked', '从未检查'); ?></div>
                <div class="bounce-status-meta"><?php echo t('mail_center_bounce_match_count', '匹配 <strong id="bounce-last-count">{n}</strong> 条退信', ['n' => $bounceStats['last_count']]); ?></div>
            </div>

            <div class="card-header" style="padding-left:0;">
                <h3 class="card-title" style="font-size:0.95rem;"><?php echo t('mail_center_bounce_history', '检查历史'); ?></h3>
            </div>
            <div class="bounce-history-list" id="bounce-history-list">
                <?php if (empty($bounceRecentLogs)): ?>
                <p class="text-muted" style="font-size:0.8125rem;"><?php echo t('mail_center_bounce_no_history', '暂无检查记录。'); ?></p>
                <?php else: ?>
                    <?php foreach ($bounceRecentLogs as $blog): ?>
                    <div class="bounce-history-item">
                        <span class="bounce-history-time"><?php echo e(date('m-d H:i', db_time($blog['check_time']))); ?></span>
                        <span class="bounce-history-info"><?php echo t('mail_center_bounce_history_info', '扫描 {n} 封，匹配 {m} 条', ['n' => (int)$blog['found_count'], 'm' => (int)$blog['processed_count']]); ?></span>
                        <?php if (!empty($blog['error_message'])): ?>
                        <span class="bounce-history-error" title="<?php echo e($blog['error_message']); ?>"><?php echo t('mail_center_bounce_failed', '失败'); ?></span>
                        <?php else: ?>
                        <span class="bounce-history-ok"><?php echo t('mail_center_bounce_success', '成功'); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="card-header" style="padding-left:0;">
                <h3 class="card-title" style="font-size:0.95rem;"><?php echo t('mail_center_common_providers', '常见服务商配置'); ?></h3>
            </div>
            <ul class="mail-faq-list">
                <li>
                    <span class="mail-faq-key"><?php echo t('mail_center_provider_qq', 'QQ 邮箱'); ?></span>
                    <span><?php echo t('mail_center_provider_qq_imap', 'IMAP <code>imap.qq.com:993</code>（SSL），需使用授权码。'); ?></span>
                </li>
                <li>
                    <span class="mail-faq-key"><?php echo t('mail_center_provider_163', '网易 163'); ?></span>
                    <span><?php echo t('mail_center_provider_163_imap', 'IMAP <code>imap.163.com:993</code>（SSL），需使用授权码。'); ?></span>
                </li>
                <li>
                    <span class="mail-faq-key">Gmail</span>
                    <span><?php echo t('mail_center_provider_gmail_imap', 'IMAP <code>imap.gmail.com:993</code>（SSL），需使用应用专用密码。'); ?></span>
                </li>
                <li>
                    <span class="mail-faq-key"><?php echo t('mail_center_provider_exmail', '腾讯企业邮'); ?></span>
                    <span><?php echo t('mail_center_provider_exmail_imap', 'IMAP <code>imap.exmail.qq.com:993</code>（SSL）。'); ?></span>
                </li>
                <li>
                    <span class="mail-faq-key"><?php echo t('mail_center_provider_aliyun', '阿里云邮'); ?></span>
                    <span><?php echo t('mail_center_provider_aliyun_imap', 'IMAP <code>imap.qiye.aliyun.com:993</code>（SSL）。'); ?></span>
                </li>
            </ul>
        </div>
    </div>

    <!-- 退信检查结果反馈 -->
    <div class="bounce-result-box" id="bounce-result-box" style="display:none;"></div>
</div>
</div><!-- 关闭 bounce 面板 -->

<!-- Tab 面板：SMTP 配置 -->
<div class="mail-tab-panel" data-panel="config">
<div class="mail-grid">
    <!-- 左侧：SMTP 配置 -->
    <div class="mail-main">
        <form method="POST" action="<?php echo site_url('admin/mail_center'); ?>" class="card mail-card">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="save">

            <div class="card-header">
                <h2 class="card-title"><?php echo t('mail_center_tab_config', 'SMTP 配置'); ?></h2>
                <label class="mail-switch <?php echo $smtpEnabled ? 'is-on' : ''; ?>">
                    <input type="checkbox" name="smtp_enabled" id="smtp_enabled" value="1" <?php echo $smtpEnabled ? 'checked' : ''; ?>>
                    <span class="mail-switch-track"><span class="mail-switch-thumb"></span></span>
                    <span class="mail-switch-label"><?php echo $smtpEnabled ? t('mail_center_enabled', '已启用') : t('mail_center_disabled', '已停用'); ?></span>
                </label>
            </div>

            <div id="smtp-fields" class="mail-fields" <?php echo $smtpEnabled ? '' : 'style="display:none;"'; ?>>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="smtp_host"><?php echo t('mail_center_smtp_host', 'SMTP 服务器'); ?></label>
                        <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?php echo e($smtpHost); ?>" placeholder="smtp.example.com">
                        <p class="form-hint"><?php echo t('mail_center_smtp_host_hint', '邮件服务器地址，不含端口与协议前缀。'); ?></p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="smtp_port"><?php echo t('mail_center_smtp_port', '端口'); ?></label>
                        <input type="number" class="form-control" id="smtp_port" name="smtp_port" value="<?php echo e((string)$smtpPort); ?>" min="1" max="65535" placeholder="587">
                        <p class="form-hint"><?php echo t('mail_center_smtp_port_hint', '常用：25 / 465 (SSL) / 587 (TLS)。'); ?></p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="smtp_encryption"><?php echo t('mail_center_bounce_encryption', '加密方式'); ?></label>
                        <select class="form-control" id="smtp_encryption" name="smtp_encryption">
                            <option value="tls" <?php echo $smtpEncryption === 'tls' ? 'selected' : ''; ?>><?php echo t('mail_center_enc_tls', 'TLS（STARTTLS）'); ?></option>
                            <option value="ssl" <?php echo $smtpEncryption === 'ssl' ? 'selected' : ''; ?>><?php echo t('mail_center_enc_ssl', 'SSL（SMTPS）'); ?></option>
                            <option value="" <?php echo $smtpEncryption === '' ? 'selected' : ''; ?>><?php echo t('mail_center_enc_none', '无加密'); ?></option>
                        </select>
                        <p class="form-hint"><?php echo t('mail_center_enc_tls_recommend', '推荐使用 TLS。'); ?></p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="smtp_user"><?php echo t('mail_center_smtp_username', '用户名'); ?></label>
                        <input type="text" class="form-control" id="smtp_user" name="smtp_user" value="<?php echo e($smtpUser); ?>" placeholder="<?php echo e(t('mail_center_smtp_username_ph', '通常与发件人邮箱相同')); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="smtp_pass"><?php echo t('mail_center_smtp_password', '密码 / 授权码'); ?></label>
                        <div class="input-pwd-wrap">
                            <input type="password" class="form-control" id="smtp_pass" name="smtp_pass" value="<?php echo e($smtpPass); ?>" placeholder="<?php echo e(t('mail_center_smtp_password_ph', '留空则保留原密码')); ?>" autocomplete="new-password">
                            <button type="button" class="input-pwd-toggle" data-target="smtp_pass" aria-label="<?php echo e(t('mail_center_show_hide_pwd', '显示或隐藏密码')); ?>">
                                <svg class="pwd-icon-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="pwd-icon-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                        <p class="form-hint"><?php echo t('mail_center_smtp_password_hint', '部分邮箱需使用授权码而非登录密码。'); ?></p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="smtp_from"><?php echo t('mail_center_smtp_from', '发件人邮箱'); ?></label>
                        <input type="email" class="form-control" id="smtp_from" name="smtp_from" value="<?php echo e($smtpFrom); ?>" placeholder="noreply@example.com">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="smtp_from_name"><?php echo t('mail_center_smtp_from_name', '发件人名称'); ?></label>
                    <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" value="<?php echo e($smtpFromName); ?>" placeholder="<?php echo e(t('mail_center_smtp_from_name_ph', '留空则使用站点名称')); ?>">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?php echo t('mail_center_save_config', '保存配置'); ?></button>
                    <span class="form-hint" style="margin:0;"><?php echo t('mail_center_save_config_hint', '保存后可前往右侧发送测试邮件。'); ?></span>
                </div>
            </div>

            <?php if (!$smtpEnabled): ?>
                <p class="text-muted" style="margin:0;"><?php echo t('mail_center_smtp_disabled_hint', '当前邮件功能处于停用状态，开启开关并填写 SMTP 信息后即可启用。'); ?></p>
            <?php endif; ?>
        </form>
    </div>

    <!-- 右侧：测试邮件 -->
    <div class="mail-side">
        <form method="POST" action="<?php echo site_url('admin/mail_center'); ?>" class="card mail-card">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="test">

            <div class="card-header">
                <h2 class="card-title"><?php echo t('mail_center_test_mail', '发送测试邮件'); ?></h2>
            </div>

            <?php if (!$smtpEnabled): ?>
                <div class="mail-tip mail-tip-warning">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span><?php echo t('mail_center_test_disabled_hint', '邮件服务未启用，测试邮件将无法发送。请先在左侧启用并保存配置。'); ?></span>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label" for="test_email"><?php echo t('mail_center_test_recipient', '收件邮箱'); ?></label>
                <input type="email" class="form-control" id="test_email" name="test_email" placeholder="someone@example.com" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="test_subject"><?php echo t('mail_center_test_subject_label', '主题（可选）'); ?></label>
                <input type="text" class="form-control" id="test_subject" name="test_subject" placeholder="<?php echo e(t('mail_center_test_subject_ph', '留空使用默认主题')); ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="test_content"><?php echo t('mail_center_test_content_label', '内容（可选）'); ?></label>
                <textarea class="form-control" id="test_content" name="test_content" rows="4" placeholder="<?php echo e(t('mail_center_test_content_ph', '留空使用默认内容')); ?>"></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block" <?php echo $smtpEnabled ? '' : 'disabled'; ?>>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                <?php echo t('mail_center_test_send', '发送测试邮件'); ?>
            </button>
        </form>

        <!-- 常见问题提示 -->
        <div class="card mail-card mail-faq">
            <div class="card-header">
                <h2 class="card-title"><?php echo t('mail_center_config_tips', '配置提示'); ?></h2>
            </div>
            <ul class="mail-faq-list">
                <li>
                    <span class="mail-faq-key"><?php echo t('mail_center_provider_qq', 'QQ 邮箱'); ?></span>
                    <span><?php echo t('mail_center_provider_qq_smtp', '主机 <code>smtp.qq.com</code>，端口 <code>465</code>（SSL），密码需使用授权码。'); ?></span>
                </li>
                <li>
                    <span class="mail-faq-key"><?php echo t('mail_center_provider_163', '网易 163'); ?></span>
                    <span><?php echo t('mail_center_provider_163_smtp', '主机 <code>smtp.163.com</code>，端口 <code>465</code>（SSL），密码需使用授权码。'); ?></span>
                </li>
                <li>
                    <span class="mail-faq-key">Gmail</span>
                    <span><?php echo t('mail_center_provider_gmail_smtp', '主机 <code>smtp.gmail.com</code>，端口 <code>587</code>（TLS），需开启应用专用密码。'); ?></span>
                </li>
                <li>
                    <span class="mail-faq-key"><?php echo t('mail_center_provider_aliyun', '阿里云邮'); ?></span>
                    <span><?php echo t('mail_center_provider_aliyun_smtp', '主机 <code>smtp.qiye.aliyun.com</code>，端口 <code>465</code>（SSL），使用完整邮箱作为账号。'); ?></span>
                </li>
            </ul>
        </div>
    </div>
</div>
</div><!-- 关闭 config 面板 -->

<!-- Tab 面板：群发通知 -->
<div class="mail-tab-panel" data-panel="notify">
    <div class="card mail-card">
        <div class="card-header">
            <h2 class="card-title"><?php echo t('mail_center_notify_title', '群发通知邮件'); ?></h2>
        </div>

        <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.25rem;">
            <div class="stat-card">
                <div class="stat-card-value"><?php echo $notifyStats['total_users']; ?></div>
                <div class="stat-card-label"><?php echo t('mail_center_notify_total_users', '用户总数'); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-value" style="color:#10b981;"><?php echo $notifyStats['active_users']; ?></div>
                <div class="stat-card-label"><?php echo t('mail_center_notify_active_users', '活跃用户'); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-value" style="color:#3b82f6;"><?php echo $notifyStats['users_with_email']; ?></div>
                <div class="stat-card-label"><?php echo t('mail_center_notify_email_ok', '可接收通知'); ?></div>
            </div>
        </div>

        <form id="notify-form">
            <input type="hidden" id="csrf_token" value="<?php echo csrf_token(); ?>">

            <div class="form-group">
                <label class="form-label"><?php echo t('mail_center_notify_target', '发送目标'); ?></label>
                <div class="notify-target-tabs">
                    <button type="button" class="notify-target-tab active" data-target="all"><?php echo t('mail_center_notify_target_all', '全体用户'); ?></button>
                    <button type="button" class="notify-target-tab" data-target="group"><?php echo t('mail_center_notify_target_group', '按用户组'); ?></button>
                    <button type="button" class="notify-target-tab" data-target="user"><?php echo t('mail_center_notify_target_user', '指定用户'); ?></button>
                </div>
                <div class="notify-target-panels">
                    <div class="notify-target-panel active" data-panel="all">
                        <p class="text-muted" style="margin:0.5rem 0 0;"><?php echo t('mail_center_notify_all_hint', '向所有已填写邮箱的活跃用户发送通知（共 {n} 人）。', ['n' => $notifyStats['users_with_email']]); ?></p>
                    </div>
                    <div class="notify-target-panel" data-panel="group">
                        <select class="form-control" id="target_group" multiple style="height:120px;">
                            <?php foreach ($notifyGroups as $g): ?>
                            <option value="<?php echo (int)$g['id']; ?>"><?php echo e($g['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint"><?php echo t('mail_center_notify_group_hint', '按住 Ctrl/Cmd 可多选用户组。'); ?></p>
                    </div>
                    <div class="notify-target-panel" data-panel="user">
                        <input type="text" class="form-control" id="target_users" placeholder="<?php echo e(t('mail_center_notify_user_ph', '输入用户名，多个用英文逗号分隔')); ?>">
                        <p class="form-hint"><?php echo t('mail_center_notify_user_hint', '例如：admin, user1, user2'); ?></p>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="notify_subject"><?php echo t('mail_center_notify_subject', '邮件主题'); ?> <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="notify_subject" placeholder="<?php echo e(t('mail_center_notify_subject_ph', '例如：重要通知：论坛将于本周六进行系统维护')); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="notify_content"><?php echo t('mail_center_notify_content', '邮件内容'); ?> <span class="text-danger">*</span></label>
                <textarea class="form-control" id="notify_content" rows="8" placeholder="<?php echo e(t('mail_center_notify_content_ph', '请输入通知正文，支持 HTML 格式...')); ?>" required></textarea>
                <p class="form-hint"><?php echo t('mail_center_notify_content_hint', '支持 HTML 标签，将使用统一的邮件模板包装。'); ?></p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="notify-submit-btn"><?php echo t('mail_center_notify_send', '发送邮件'); ?></button>
                <button type="button" class="btn btn-secondary" id="notify-preview-btn"><?php echo t('mail_center_notify_preview', '预览邮件'); ?></button>
            </div>
        </form>
    </div>

    <div class="card mail-card" id="notify-result-card" style="display:none;">
        <div class="card-header">
            <h2 class="card-title"><?php echo t('mail_center_notify_result', '发送结果'); ?></h2>
        </div>
        <div id="notify-result-content"></div>
    </div>

    <div class="modal-overlay" id="preview-modal" style="display:none;">
        <div class="modal-box modal-box-flat" style="width:720px;max-width:calc(100vw - 2rem);">
            <div class="modal-header">
                <h3 class="modal-title"><?php echo t('mail_center_notify_mail_preview', '邮件预览'); ?></h3>
                <button type="button" class="modal-close" id="preview-close">&times;</button>
            </div>
            <div class="modal-body" style="padding:0;background:#f4f4f5;border:none;border-bottom-left-radius:var(--radius-lg);border-bottom-right-radius:var(--radius-lg);">
                <iframe id="preview-frame" style="display:block;width:100%;height:240px;border:none;background:transparent;"></iframe>
            </div>
        </div>
    </div>
</div><!-- 关闭 notify 面板 -->

<!-- Tab 面板：模板预览 -->
<div class="mail-tab-panel" data-panel="template">
    <div class="card mail-card">
        <div class="card-header">
            <h2 class="card-title"><?php echo t('mail_center_template_preview', '邮件模板预览'); ?></h2>
            <div class="mail-template-tabs" role="tablist">
                <button type="button" class="mail-template-tab active" data-tpl="verify" role="tab"><?php echo t('mail_center_type_verify', '注册验证码'); ?></button>
                <button type="button" class="mail-template-tab" data-tpl="password_change" role="tab"><?php echo t('mail_center_template_password_change', '修改密码验证码'); ?></button>
                <button type="button" class="mail-template-tab" data-tpl="reset" role="tab"><?php echo t('mail_center_type_reset', '密码重置'); ?></button>
                <button type="button" class="mail-template-tab" data-tpl="appeal" role="tab"><?php echo t('mail_center_type_appeal', '申诉通知'); ?></button>
            </div>
        </div>
        <p class="text-muted" style="margin-top:0;"><?php echo t('mail_center_template_hint', '所有发往用户的邮件均使用统一品牌模板，下方为实时预览。'); ?></p>

        <div class="mail-preview-wrapper">
            <iframe id="mail-preview-frame" class="mail-preview-frame" title="<?php echo e(t('mail_center_template_preview', '邮件模板预览')); ?>" loading="lazy"></iframe>
        </div>
    </div>
</div><!-- 关闭 template 面板 -->

<script>
// === Tab 切换 ===
(function () {
    var tabs = document.querySelectorAll('.mail-tab');
    var panels = document.querySelectorAll('.mail-tab-panel');
    var previewLoaded = false;
    var previewFrame = document.getElementById('mail-preview-frame');

    function switchTo(name) {
        tabs.forEach(function (t) {
            t.classList.toggle('active', t.dataset.tab === name);
        });
        panels.forEach(function (p) {
            p.classList.toggle('active', p.dataset.panel === name);
        });
        // 懒加载模板预览 iframe
        if (name === 'template' && !previewLoaded && previewFrame) {
            var src = previewFrame.dataset.src || previewFrame.getAttribute('src');
            if (src) {
                previewFrame.src = src;
                previewLoaded = true;
            } else if (typeof renderPreview === 'function') {
                renderPreview();
                previewLoaded = true;
            }
        }
        // 记忆当前 Tab
        try { localStorage.setItem('mail_center_tab', name); } catch (e) {}
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () { switchTo(t.dataset.tab); });
    });

    // 恢复上次 Tab
    var saved = null;
    try { saved = localStorage.getItem('mail_center_tab'); } catch (e) {}
    if (saved && document.querySelector('.mail-tab-panel[data-panel="' + saved + '"]')) {
        switchTo(saved);
    }
})();

// === 密码字段显示/隐藏切换（通用，适用于所有 .input-pwd-toggle） ===
(function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.input-pwd-toggle');
        if (!btn) return;
        var targetId = btn.dataset.target;
        if (!targetId) return;
        var input = document.getElementById(targetId);
        if (!input) return;
        var isPwd = input.type === 'password';
        input.type = isPwd ? 'text' : 'password';
        // 切换图标
        var eye = btn.querySelector('.pwd-icon-eye');
        var eyeOff = btn.querySelector('.pwd-icon-eye-off');
        if (eye) eye.style.display = isPwd ? 'none' : '';
        if (eyeOff) eyeOff.style.display = isPwd ? '' : 'none';
        // 更新 aria-label
        btn.setAttribute('aria-label', isPwd ? '<?php echo t('mail_center_hide_pwd', '隐藏密码'); ?>' : '<?php echo t('mail_center_show_pwd', '显示密码'); ?>');
        // 保持输入框焦点
        input.focus();
    });
})();

(function () {
    // SMTP 启用开关：动态切换表单显示与开关标签
    var cb  = document.getElementById('smtp_enabled');
    var box = document.getElementById('smtp-fields');
    if (cb && box) {
        function sync() {
            box.style.display = cb.checked ? 'block' : 'none';
            var sw = cb.closest('.mail-switch');
            if (sw) {
                sw.classList.toggle('is-on', cb.checked);
                var label = sw.querySelector('.mail-switch-label');
                if (label) label.textContent = cb.checked ? '<?php echo t('mail_center_enabled', '已启用'); ?>' : '<?php echo t('mail_center_disabled', '已停用'); ?>';
            }
        }
        cb.addEventListener('change', sync);
        sync();
    }

    // 邮件模板预览
    var templates = {
        verify: '<?php echo addslashes_js(build_preview_template('verify', $siteName)); ?>',
        password_change: '<?php echo addslashes_js(build_preview_template('password_change', $siteName)); ?>',
        reset:  '<?php echo addslashes_js(build_preview_template('reset', $siteName)); ?>',
        appeal: '<?php echo addslashes_js(build_preview_template('appeal', $siteName)); ?>'
    };
    var frame = document.getElementById('mail-preview-frame');
    var tabs  = document.querySelectorAll('.mail-template-tab');

    function render(key) {
        if (!frame || !templates[key]) return;
        var doc = frame.contentDocument || frame.contentWindow.document;
        doc.open();
        doc.write(templates[key]);
        doc.close();
        // 自适应高度
        setTimeout(function () {
            try {
                frame.style.height = (doc.documentElement.scrollHeight + 24) + 'px';
            } catch (e) {}
        }, 80);
    }
    // 暴露给 Tab 切换懒加载使用
    window.renderPreview = function () { render('verify'); };

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            render(tab.dataset.tpl);
        });
    });

    // 懒加载：仅当 template 面板默认可见时才立即渲染
    var templatePanel = document.querySelector('.mail-tab-panel[data-panel="template"]');
    if (templatePanel && templatePanel.classList.contains('active')) {
        render('verify');
    }
    window.addEventListener('resize', function () {
        var active = document.querySelector('.mail-template-tab.active');
        if (active && templatePanel && templatePanel.classList.contains('active')) render(active.dataset.tpl);
    });
})();

// === 群发通知 ===
(function () {
    var csrfToken = document.getElementById('csrf_token') ? document.getElementById('csrf_token').value : '';

    // 目标类型切换
    var tabs = document.querySelectorAll('.notify-target-tab');
    var panels = document.querySelectorAll('.notify-target-panel');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var target = tab.dataset.target;
            tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
            panels.forEach(function (p) { p.classList.toggle('active', p.dataset.panel === target); });
        });
    });

    var form = document.getElementById('notify-form');
    if (!form) return;
    var resultCard = document.getElementById('notify-result-card');
    var resultContent = document.getElementById('notify-result-content');
    var submitBtn = document.getElementById('notify-submit-btn');

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var targetType = document.querySelector('.notify-target-tab.active').dataset.target;
        var targetGroup = [];
        var targetUsers = '';
        if (targetType === 'group') {
            var sel = document.getElementById('target_group');
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].selected) targetGroup.push(sel.options[i].value);
            }
        } else if (targetType === 'user') {
            targetUsers = document.getElementById('target_users').value.trim();
        }
        var subject = document.getElementById('notify_subject').value.trim();
        var content = document.getElementById('notify_content').value.trim();
        if (!subject || !content) { alert('<?php echo t('mail_center_notify_required', '请填写邮件主题和内容。'); ?>'); return; }

        submitBtn.disabled = true;
        submitBtn.textContent = '<?php echo t('mail_center_notify_sending_btn', '发送中...'); ?>';
        resultCard.style.display = 'block';
        resultContent.innerHTML = '<p class="text-muted"><?php echo t('mail_center_notify_sending', '正在发送邮件，请稍候...'); ?></p>';

        var formData = new FormData();
        formData.append('action', 'send_notify');
        formData.append('csrf_token', csrfToken);
        formData.append('target_type', targetType);
        formData.append('target_group', targetGroup.join(','));
        formData.append('target_users', targetUsers);
        formData.append('subject', subject);
        formData.append('content', content);

        fetch('<?php echo site_url('admin/api/mail_notify_ajax'); ?>', { method: 'POST', body: formData, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    var html = '<div class="notify-result-ok"><strong><?php echo t('mail_center_notify_done', '发送完成'); ?></strong></div>';
                    html += '<div class="notify-result-stats">';
                    html += '<span><?php echo t('mail_center_notify_target_users', '目标用户：'); ?><strong>' + res.target_count + '</strong> <?php echo t('mail_center_notify_person', '人'); ?></span>';
                    html += '<span><?php echo t('mail_center_notify_success_count', '发送成功：'); ?><strong style="color:#10b981;">' + res.success_count + '</strong> <?php echo t('mail_center_notify_person', '人'); ?></span>';
                    html += '<span><?php echo t('mail_center_notify_failed_count', '发送失败：'); ?><strong style="color:#ef4444;">' + res.failed_count + '</strong> <?php echo t('mail_center_notify_person', '人'); ?></span>';
                    html += '</div>';
                    if (res.failed_count > 0 && res.failed_list && res.failed_list.length > 0) {
                        html += '<div class="notify-result-fail"><strong><?php echo t('mail_center_notify_failed_list', '失败列表：'); ?></strong><br>';
                        for (var i = 0; i < res.failed_list.length && i < 10; i++) {
                            html += escapeHtml(res.failed_list[i].username + ' <' + res.failed_list[i].email + '>: ' + res.failed_list[i].error) + '<br>';
                        }
                        if (res.failed_list.length > 10) html += '<?php echo t('mail_center_notify_more', '... 等 {n} 条'); ?>'.replace('{n}', res.failed_list.length);
                        html += '</div>';
                    }
                    resultContent.innerHTML = html;
                } else {
                    resultContent.innerHTML = '<p class="text-danger"><?php echo t('mail_center_notify_failed', '发送失败：'); ?>' + escapeHtml(res.error || '<?php echo t('mail_center_notify_unknown_error', '未知错误'); ?>') + '</p>';
                }
            })
            .catch(function () { resultContent.innerHTML = '<p class="text-danger"><?php echo t('mail_center_notify_network_error', '网络错误，发送失败。'); ?></p>'; })
            .finally(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = '<?php echo t('mail_center_notify_send', '发送邮件'); ?>';
            });
    });

    var previewBtn = document.getElementById('notify-preview-btn');
    var previewModal = document.getElementById('preview-modal');
    var previewFrame = document.getElementById('preview-frame');
    var previewClose = document.getElementById('preview-close');

    if (previewBtn) {
        previewBtn.addEventListener('click', function () {
            var subject = document.getElementById('notify_subject').value.trim();
            var content = document.getElementById('notify_content').value.trim();
            if (!subject || !content) { alert('<?php echo t('mail_center_notify_required_preview', '请填写邮件主题和内容后再预览。'); ?>'); return; }
            var formData = new FormData();
            formData.append('action', 'preview');
            formData.append('csrf_token', csrfToken);
            formData.append('subject', subject);
            formData.append('content', content);
            fetch('<?php echo site_url('admin/api/mail_notify_ajax'); ?>', { method: 'POST', body: formData, cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success && res.html) {
                        var doc = previewFrame.contentDocument || previewFrame.contentWindow.document;
                        doc.open(); doc.write(res.html); doc.close();
                        // 自适应高度：等待图片/样式加载后取 body 真实高度
                        var fit = function () {
                            try {
                                var b = doc.body;
                                var h = Math.max(
                                    b.scrollHeight, b.offsetHeight,
                                    b.clientHeight, doc.documentElement.scrollHeight
                                );
                                var winH = window.innerHeight || 700;
                                previewFrame.style.height = Math.min(Math.max(h + 8, 200), winH - 120) + 'px';
                            } catch (e) {}
                        };
                        fit();
                        // 兜底：图片/字体加载后可能变化，再补一次
                        setTimeout(fit, 100);
                        setTimeout(fit, 400);
                        previewFrame.onload = fit;
                        previewModal.style.display = 'flex';
                    } else { alert('<?php echo t('mail_center_notify_preview_failed', '预览生成失败：'); ?>' + (res.error || '<?php echo t('mail_center_notify_unknown_error', '未知错误'); ?>')); }
                })
                .catch(function () { alert('<?php echo t('mail_center_notify_preview_network_error', '网络错误，无法生成预览。'); ?>'); });
        });
    }
    if (previewClose) previewClose.addEventListener('click', function () { previewModal.style.display = 'none'; });
    if (previewModal) previewModal.addEventListener('click', function (e) { if (e.target === previewModal) previewModal.style.display = 'none'; });
})();

// === 邮件统计实时刷新 ===
(function () {
    var BASE_INTERVAL = 1000;     // 基础间隔 1 秒（mail_stats_ajax 服务端 1 秒缓存合并并发，不阻塞）
    var IDLE_INTERVAL = 5000;    // 数据无变化时延长到 5 秒
    var FAIL_INTERVAL = 30000;    // 连续失败时退避到 30 秒
    var MAX_FAIL = 3;
    var failCount = 0;
    var lastFingerprint = '';     // 数据指纹，用于检测数据是否变化
    var typeLabels = <?php echo json_encode($typeLabels, JSON_UNESCAPED_UNICODE); ?>;

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function pulse(el) {
        if (!el) return;
        el.classList.remove('is-pulse');
        void el.offsetWidth; // 触发重绘以重启动画
        el.classList.add('is-pulse');
    }

    function setNum(id, val) {
        var el = document.getElementById(id);
        if (!el) return;
        var old = parseInt(el.textContent, 10);
        if (old !== val) {
            el.textContent = val;
            pulse(el);
        }
    }

    function updateStats(stats) {
        setNum('stat-total', stats.total);
        setNum('stat-success', stats.success);
        setNum('stat-failed', stats.failed);
        var rateEl = document.getElementById('stat-rate');
        if (rateEl) rateEl.textContent = stats.success_rate + '%';
        setNum('stat-today', stats.today);
        var detailEl = document.getElementById('stat-today-detail');
        if (detailEl) detailEl.textContent = '<?php echo t('mail_center_stat_today_detail', '{x} 成功 / {y} 失败'); ?>'.replace('{x}', stats.today_success).replace('{y}', stats.today_failed);

        // 更新类型分布
        var typeList = document.getElementById('mail-type-list');
        if (!typeList) return;
        if (stats.types && stats.types.length > 0) {
            var html = '';
            for (var i = 0; i < stats.types.length; i++) {
                var t = stats.types[i];
                var pct = stats.total > 0 ? Math.min(100, t.total / stats.total * 100) : 0;
                html += '<div class="mail-type-row">' +
                    '<div class="mail-type-label">' + escapeHtml(t.label) + '</div>' +
                    '<div class="mail-type-bar-wrap"><div class="mail-type-bar" style="width:' + pct + '%;"></div></div>' +
                    '<div class="mail-type-num">' + t.total + '</div>' +
                    '<div class="mail-type-rate">' +
                    '<span class="mail-type-ok">' + t.success + '</span>' +
                    '<span class="mail-type-sep">/</span>' +
                    '<span class="mail-type-fail">' + t.failed + '</span>' +
                    '</div></div>';
            }
            typeList.innerHTML = html;
        } else {
            typeList.innerHTML = '<p class="text-muted text-center py-2"><?php echo t('mail_center_no_records', '暂无发送记录。'); ?></p>';
        }
    }

    function updateLogs(logs) {
        var list = document.getElementById('mail-log-list');
        if (!list) return;
        if (!logs || logs.length === 0) {
            list.innerHTML = '<p class="text-muted text-center py-2"><?php echo t('mail_center_no_records', '暂无发送记录。'); ?></p>';
            return;
        }
        var html = '';
        for (var i = 0; i < logs.length; i++) {
            var log = logs[i];
            var statusClass = log.status === 'success' ? 'is-ok' : 'is-fail';
            var typeLabel = typeLabels[log.type] || log.type;
            var errorBlock = '';
            if (log.status !== 'success' && log.error_message) {
                var err = log.error_message.length > 120 ? log.error_message.substring(0, 120) : log.error_message;
                errorBlock = '<div class="mail-log-error">' + escapeHtml(err) + '</div>';
            }
            // 退信标识
            var bounceTag = '';
            if (log.is_bounced) {
                var btype = log.bounce_type === 'soft' ? '<?php echo t('mail_center_bounce_soft', '软退信'); ?>' : '<?php echo t('mail_center_bounce_hard', '硬退信'); ?>';
                bounceTag = '<span class="mail-log-bounce" title="' + escapeHtml(log.bounce_reason || '') + (log.bounce_time_display ? '（' + log.bounce_time_display + '）' : '') + '">' + btype + '</span>';
            }
            html += '<div class="mail-log-item' + (log.is_bounced ? ' is-bounced' : '') + '">' +
                '<div class="mail-log-row1">' +
                '<span class="mail-log-status ' + statusClass + '"></span>' +
                '<span class="mail-log-recipient" title="' + escapeHtml(log.recipient) + '">' + escapeHtml(log.recipient) + '</span>' +
                bounceTag +
                '<span class="mail-log-time">' + escapeHtml(log.time_display) + '</span>' +
                '</div>' +
                '<div class="mail-log-row2">' +
                '<span class="mail-log-type">' + escapeHtml(typeLabel) + '</span>' +
                '<span class="mail-log-subject" title="' + escapeHtml(log.subject) + '">' + escapeHtml(log.subject) + '</span>' +
                '</div>' +
                errorBlock +
                '</div>';
        }
        list.innerHTML = html;
    }

    function updateHint(text, ok) {
        var hint = document.getElementById('mail-refresh-hint');
        if (!hint) return;
        hint.textContent = text;
        hint.classList.toggle('is-ok', !!ok);
    }

    function fetchStats() {
        // 概览面板不可见时跳过请求，节省资源
        var overview = document.querySelector('.mail-tab-panel[data-panel="overview"]');
        if (overview && !overview.classList.contains('active')) {
            scheduleNext(BASE_INTERVAL);
            return;
        }
        fetch('<?php echo site_url('admin/api/mail_stats_ajax'); ?>', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    handleFail();
                    return;
                }
                failCount = 0;
                // 计算数据指纹，避免无变化的 DOM 更新
                var fp = JSON.stringify(data.stats) + '|' + (data.logs ? data.logs.length + ':' + (data.logs[0] ? data.logs[0].created_at + data.logs[0].recipient : '') : '');
                var changed = (fp !== lastFingerprint);
                lastFingerprint = fp;
                if (changed) {
                    updateStats(data.stats);
                    updateLogs(data.logs);
                }
                var now = new Date();
                var hh = String(now.getHours()).padStart(2, '0');
                var mm = String(now.getMinutes()).padStart(2, '0');
                var ss = String(now.getSeconds()).padStart(2, '0');
                updateHint('<?php echo t('mail_center_refreshed_at', '已刷新 {time}'); ?>'.replace('{time}', hh + ':' + mm + ':' + ss), true);
                // 数据无变化时延长轮询间隔，有变化时恢复基础间隔
                scheduleNext(changed ? BASE_INTERVAL : IDLE_INTERVAL);
            })
            .catch(function () {
                handleFail();
            });
    }

    function handleFail() {
        failCount++;
        updateHint(failCount >= MAX_FAIL ? '<?php echo t('mail_center_refresh_slow', '刷新失败，已减慢轮询'); ?>' : '<?php echo t('mail_center_refresh_failed', '刷新失败'); ?>', false);
        scheduleNext(failCount >= MAX_FAIL ? FAIL_INTERVAL : BASE_INTERVAL);
    }

    var timer = null;
    function scheduleNext(delay) {
        if (timer) { clearTimeout(timer); timer = null; }
        if (document.hidden) return; // 页面隐藏时不调度
        timer = setTimeout(fetchStats, delay);
    }

    function startPolling() {
        if (timer) return;
        fetchStats();
    }
    function stopPolling() {
        if (timer) { clearTimeout(timer); timer = null; }
    }

    // 暴露给退信处理模块调用
    window.fetchMailStats = function () {
        lastFingerprint = ''; // 强制下次更新
        fetchStats();
    };

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) stopPolling();
        else startPolling();
    });

    startPolling();
})();

// === 退信处理 ===
(function () {
    var csrfToken = document.getElementById('bounce_csrf_token');
    csrfToken = csrfToken ? csrfToken.value : '';

    function $(id) { return document.getElementById(id); }
    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // 开关切换
    var cb = $('bounce_enabled');
    if (cb) {
        cb.addEventListener('change', function () {
            var sw = cb.closest('.mail-switch');
            if (sw) {
                sw.classList.toggle('is-on', cb.checked);
                var label = sw.querySelector('.mail-switch-label');
                if (label) label.textContent = cb.checked ? '<?php echo t('mail_center_enabled', '已启用'); ?>' : '<?php echo t('mail_center_disabled', '已停用'); ?>';
            }
        });
    }

    function getFormData() {
        return {
            enabled: $('bounce_enabled') ? ($('bounce_enabled').checked ? 1 : 0) : 0,
            protocol: $('bounce_protocol') ? $('bounce_protocol').value : 'imap',
            host: $('bounce_host') ? $('bounce_host').value.trim() : '',
            port: $('bounce_port') ? parseInt($('bounce_port').value, 10) || 993 : 993,
            encryption: $('bounce_encryption') ? $('bounce_encryption').value : 'ssl',
            username: $('bounce_username') ? $('bounce_username').value.trim() : '',
            password: $('bounce_password') ? $('bounce_password').value : '',
            mailbox: $('bounce_mailbox') ? $('bounce_mailbox').value.trim() : 'INBOX',
            auto_check: 1
        };
    }

    function showResultBox(type, message, details) {
        var box = $('bounce-result-box');
        if (!box) return;
        var html = '<div class="bounce-result-' + (type === 'success' ? 'ok' : 'error') + '">' + escapeHtml(message) + '</div>';
        if (details && details.length > 0) {
            html += '<div class="bounce-result-details"><strong><?php echo t('mail_center_bounce_matched', '匹配的退信记录：'); ?></strong><ul>';
            for (var i = 0; i < details.length; i++) {
                html += '<li><span class="bounce-detail-recipient">' + escapeHtml(details[i].recipient || '<?php echo t('mail_center_unknown', '未知'); ?>') +
                    '</span> <span class="bounce-detail-type">' + escapeHtml(details[i].type === 'hard' ? '<?php echo t('mail_center_bounce_hard', '硬退信'); ?>' : '<?php echo t('mail_center_bounce_soft', '软退信'); ?>') +
                    '</span> <span class="bounce-detail-reason">' + escapeHtml(details[i].reason) + '</span></li>';
            }
            html += '</ul></div>';
        }
        box.innerHTML = html;
        box.style.display = 'block';
    }

    function updateBounceStats(stats) {
        if (!stats) return;
        if ($('bounce-stat-total')) $('bounce-stat-total').textContent = stats.total_bounced;
        if ($('bounce-stat-hard')) $('bounce-stat-hard').textContent = stats.hard_bounced;
        if ($('bounce-stat-soft')) $('bounce-stat-soft').textContent = stats.soft_bounced;
        if ($('bounce-stat-pending')) $('bounce-stat-pending').textContent = stats.pending;
        if (stats.last_check && $('bounce-last-check')) {
            $('bounce-last-check').textContent = stats.last_check;
        }
        if ($('bounce-last-count')) $('bounce-last-count').textContent = stats.last_count;
    }

    function setButtonLoading(btn, loading, originalText) {
        if (!btn) return;
        if (loading) {
            btn.dataset.originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = '<?php echo t('mail_center_processing', '处理中...'); ?>';
        } else {
            btn.disabled = false;
            btn.textContent = originalText || btn.dataset.originalText || btn.textContent;
        }
    }

    // 保存配置
    var saveBtn = $('bounce-save-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var data = getFormData();
            var formData = new FormData();
            formData.append('action', 'save_config');
            formData.append('csrf_token', csrfToken);
            for (var k in data) formData.append(k, data[k]);

            setButtonLoading(saveBtn, true);
            fetch('<?php echo site_url('admin/api/bounce_ajax'); ?>', { method: 'POST', body: formData, cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        showResultBox('success', res.message || '<?php echo t('mail_center_bounce_saved', '配置已保存'); ?>');
                    } else {
                        showResultBox('error', res.error || '<?php echo t('mail_center_bounce_save_failed', '保存失败'); ?>');
                    }
                })
                .catch(function () { showResultBox('error', '<?php echo t('mail_center_bounce_save_network_error', '网络错误，保存失败'); ?>'); })
                .finally(function () { setButtonLoading(saveBtn, false, '<?php echo t('mail_center_save_config', '保存配置'); ?>'); });
        });
    }

    // 测试连接
    var testBtn = $('bounce-test-btn');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            var data = getFormData();
            var formData = new FormData();
            formData.append('action', 'test_connection');
            formData.append('csrf_token', csrfToken);
            for (var k in data) formData.append(k, data[k]);

            setButtonLoading(testBtn, true);
            fetch('<?php echo site_url('admin/api/bounce_ajax'); ?>', { method: 'POST', body: formData, cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        showResultBox('success', res.message || '<?php echo t('mail_center_bounce_conn_ok', '连接成功'); ?>');
                    } else {
                        showResultBox('error', res.message || res.error || '<?php echo t('mail_center_bounce_conn_failed', '连接失败'); ?>');
                    }
                })
                .catch(function () { showResultBox('error', '<?php echo t('mail_center_bounce_conn_network_error', '网络错误，测试失败'); ?>'); })
                .finally(function () { setButtonLoading(testBtn, false, '<?php echo t('mail_center_test_connection', '测试连接'); ?>'); });
        });
    }

    // 立即检查退信
    var checkBtn = $('bounce-check-btn');
    if (checkBtn) {
        checkBtn.addEventListener('click', function () {
            var formData = new FormData();
            formData.append('action', 'check_bounces');
            formData.append('csrf_token', csrfToken);
            formData.append('max_messages', '50');

            setButtonLoading(checkBtn, true);
            fetch('<?php echo site_url('admin/api/bounce_ajax'); ?>', { method: 'POST', body: formData, cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        showResultBox('success', res.message || '<?php echo t('mail_center_bounce_check_done', '检查完成'); ?>', res.details || []);
                        updateBounceStats(res.stats);
                        // 同时刷新邮件统计（退信会改变失败数量）
                        if (typeof window.fetchMailStats === 'function') {
                            window.fetchMailStats();
                        }
                    } else {
                        showResultBox('error', res.message || res.error || '<?php echo t('mail_center_bounce_check_failed', '检查失败'); ?>');
                        if (res.stats) updateBounceStats(res.stats);
                    }
                })
                .catch(function () { showResultBox('error', '<?php echo t('mail_center_bounce_check_network_error', '网络错误，检查失败'); ?>'); })
                .finally(function () { setButtonLoading(checkBtn, false, '<?php echo t('mail_center_check_bounce_now', '立即检查退信'); ?>'); });
        });
    }
})();

<?php
/**
 * 简单的 JS 字符串转义（PHP 端辅助）
 */
function addslashes_js(string $s): string {
    return str_replace(
        ["\\", "'",  "\n", "\r", "\t"],
        ["\\\\", "\\'", "\\n", "\\r", "\\t"],
        $s
    );
}

/**
 * 构造预览用的邮件模板 HTML（与实际发送的模板一致）
 */
function build_preview_template(string $type, string $siteName): string {
    switch ($type) {
        case 'verify':
            $title = t('mail_center_type_verify', '注册验证码');
            $body  = '<p>' . t('mail_center_tpl_hello', '您好，') . '</p>';
            $body .= '<p>' . t('mail_center_tpl_verify_intro', '您正在注册 <strong>{site}</strong> 账号，验证码为：', ['site' => e($siteName)]) . '</p>';
            $body .= '<p style="margin:16px 0;text-align:center;"><span style="display:inline-block;padding:12px 28px;font-size:30px;font-weight:700;letter-spacing:8px;color:#4f46e5;background:#eef2ff;border-radius:10px;border:1px dashed #c7d2fe;">836492</span></p>';
            $body .= '<p>' . t('mail_center_tpl_code_valid', '验证码 <strong>10 分钟</strong>内有效，请勿泄露给他人。') . '</p>';
            $body .= '<p style="color:#71717a;">' . t('mail_center_tpl_ignore_hint', '如非本人操作，请忽略此邮件。') . '</p>';
            return render_email_template($title, $body, ['subject' => t('mail_center_tpl_verify_subject', '【{site}】注册验证码', ['site' => $siteName])]);

        case 'password_change':
            $title = t('mail_center_template_password_change', '修改密码验证码');
            $body  = '<p>' . t('mail_center_tpl_hello', '您好，') . '</p>';
            $body .= '<p>' . t('mail_center_tpl_pwd_change_intro', '您正在修改 <strong>{site}</strong> 账号密码，验证码为：', ['site' => e($siteName)]) . '</p>';
            $body .= '<p style="margin:16px 0;text-align:center;"><span style="display:inline-block;padding:12px 28px;font-size:30px;font-weight:700;letter-spacing:8px;color:#4f46e5;background:#eef2ff;border-radius:10px;border:1px dashed #c7d2fe;">836492</span></p>';
            $body .= '<p>' . t('mail_center_tpl_code_valid', '验证码 <strong>10 分钟</strong>内有效，请勿泄露给他人。') . '</p>';
            $body .= '<p style="color:#71717a;">' . t('mail_center_tpl_ignore_hint', '如非本人操作，请忽略此邮件。') . '</p>';
            return render_email_template($title, $body, ['subject' => t('mail_center_tpl_pwd_change_subject', '【{site}】修改密码验证码', ['site' => $siteName])]);

        case 'reset':
            $title = t('mail_center_type_reset', '密码重置');
            $body  = '<p>' . t('mail_center_tpl_reset_hello', '您好，<strong>示例用户</strong>') . '</p>';
            $body .= '<p>' . t('mail_center_tpl_reset_intro', '您申请了重置密码，请点击下方按钮完成重置（链接 <strong>1 小时</strong>内有效）：') . '</p>';
            $body .= '<p style="color:#71717a;">' . t('mail_center_tpl_reset_ignore_hint', '如非本人操作，请忽略此邮件，您的密码不会发生变化。') . '</p>';
            return render_email_template($title, $body, [
                'subject'     => t('mail_center_tpl_reset_subject', '【{site}】重置密码', ['site' => $siteName]),
                'action_url'  => '#',
                'action_text' => t('mail_center_tpl_reset_action', '立即重置密码'),
            ]);

        case 'appeal':
            $title = t('mail_center_tpl_appeal_title', '封禁申诉已通过');
            $body  = '<p>' . t('mail_center_tpl_appeal_hello', '你好，<strong>示例用户</strong>：') . '</p>';
            $body .= '<p>' . t('mail_center_tpl_appeal_body', '你的封禁申诉已通过管理员审核，账号已解封，现在可以正常登录 {site}。', ['site' => e($siteName)]) . '</p>';
            $body .= '<p style="background:#f0fdf4;padding:12px 16px;border-left:4px solid #10b981;border-radius:6px;margin:12px 0;">' . t('mail_center_tpl_appeal_note', '管理员备注：申诉材料属实，予以通过。') . '</p>';
            return render_email_template($title, $body, [
                'subject'     => t('mail_center_tpl_appeal_subject', '【{site}】你的封禁申诉已通过', ['site' => $siteName]),
                'action_text' => t('mail_center_go_to', '前往 {site}', ['site' => $siteName]),
            ]);
    }
    return '';
}
?>
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
