<?php
/**
 * 云界论坛 - 安装向导
 *
 * 完整的分步安装流程：
 * 1. 数据库配置（选择类型、输入连接信息）
 * 2. 环境检测（自动验证服务器环境 + 数据库连接）
 * 3. 站点配置（站点信息 + 管理员 + SMTP）
 * 4. 安装完成
 *
 * 安装前步骤：语言选择 → 授权协议
 */

require_once __DIR__ . '/app/includes/config.php';
require_once __DIR__ . '/app/includes/functions.php';
require_once __DIR__ . '/app/includes/db.php';

// 已安装则跳转首页（同时验证数据库完整性，防止部分安装残留）
$alreadyInstalled = false;
$installCheckError = '';
if (file_exists(INSTALLED_FILE)) {
    try {
        $driver = get_db_driver();
        $tables = $driver->getTables();
        $requiredTables = ['users', 'posts', 'forums'];
        $missingTables = array_diff($requiredTables, $tables);
        if (empty($missingTables)) {
            $alreadyInstalled = true;
        } else {
            $installCheckError = '数据库不完整，缺少表：' . implode(', ', $missingTables);
        }
    } catch (\Throwable $e) {
        $installCheckError = '数据库连接失败：' . $e->getMessage();
    }
}
if ($alreadyInstalled) {
    set_flash('论坛已经安装完成', 'info');
    redirect('/');
}

// 环境检测（基础环境，不包含数据库连接）
$phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
$pdoOk = extension_loaded('pdo');
$pdoSqliteOk = extension_loaded('pdo_sqlite');
$pdoMysqlOk = extension_loaded('pdo_mysql');
$pdoPgsqlOk = extension_loaded('pdo_pgsql');
$mbstringOk = extension_loaded('mbstring');
$dataWritable = is_dir(DATA_PATH) && is_writable(DATA_PATH);
if (!is_dir(DATA_PATH)) {
    $dataWritable = @mkdir(DATA_PATH, 0755, true) && is_writable(DATA_PATH);
}

// 根据用户选择的数据库类型确定需要哪个驱动
$dbType = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
switch ($dbType) {
    case 'mysql':
        $dbExtOk = $pdoMysqlOk;
        break;
    case 'pgsql':
        $dbExtOk = $pdoPgsqlOk;
        break;
    default:
        $dbExtOk = $pdoSqliteOk;
}
switch ($dbType) {
    case 'mysql':
        $dbExtName = 'PDO_MySQL';
        break;
    case 'pgsql':
        $dbExtName = 'PDO_PgSQL';
        break;
    default:
        $dbExtName = 'PDO_SQLite';
}

// 需要启用的 php.ini 扩展名（按数据库类型动态生成，用于安装教程提示）
switch ($dbType) {
    case 'mysql':
        $needExtList = ['pdo_mysql'];
        break;
    case 'pgsql':
        $needExtList = ['pdo_pgsql'];
        break;
    default:
        $needExtList = ['pdo_sqlite', 'sqlite3'];
}

// PHP 实际可用的 PDO 驱动列表（诊断用）
$availableDrivers = [];
try {
    $availableDrivers = PDO::getAvailableDrivers();
} catch (\Throwable $e) {
    $availableDrivers = [];
}
$allOk = $phpOk && $pdoOk && $dbExtOk && $dataWritable;

// 步骤控制
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($step < 1 || $step > 4) {
    $step = 1;
}

// 如果环境未通过，强制停在第二步
if ($step >= 3 && !$allOk) {
    $step = 2;
}

$errors = [];
$success = false;

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = '安全验证失败，请刷新页面重试。';
    } else {
        $action = $_POST['action'] ?? '';

        // 第 1 步：保存数据库配置
        if ($action === 'save_db_config') {
            $dbType = in_array($_POST['db_type'] ?? '', ['sqlite', 'mysql', 'pgsql'], true) ? $_POST['db_type'] : 'sqlite';

            if ($dbType === 'sqlite') {
                $dbFile = trim($_POST['db_file'] ?? '');
                if ($dbFile === '') {
                    $dbFile = DATA_PATH . 'forum.db';
                }
                // 确保路径安全：统一使用正斜杠
                $dbFile = str_replace('\\', '/', $dbFile);
                // 防止目录遍历攻击
                if (strpos($dbFile, '..') !== false) {
                    $errors[] = '数据库文件路径包含非法字符（..）。';
                }
                if (strpos($dbFile, '/') === false) {
                    $dbFile = DATA_PATH . $dbFile;
                }
                $configContent = "<?php\ndefine('DB_TYPE', 'sqlite');\ndefine('DB_FILE', " . var_export($dbFile, true) . ");\n";
            } else {
                $dbHost = trim($_POST['db_host'] ?? 'localhost');
                $dbPort = trim($_POST['db_port'] ?? ($dbType === 'mysql' ? '3306' : '5432'));
                $dbName = trim($_POST['db_name'] ?? 'forum');
                $dbUser = trim($_POST['db_user'] ?? '');
                $dbPass = $_POST['db_pass'] ?? '';

                if ($dbHost === '' || $dbName === '' || $dbUser === '') {
                    $errors[] = '数据库服务器地址、数据库名和用户名不能为空。';
                } else {
                    // 测试数据库连接
                    try {
                        $testDsn = ($dbType === 'mysql')
                            ? "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4"
                            : "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
                        $testDb = new PDO($testDsn, $dbUser, $dbPass, [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        ]);
                        // MySQL：尝试创建数据库（如果不存在）
                        if ($dbType === 'mysql') {
                            $testDb->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                        }
                        $testDb->exec('SELECT 1');
                        $configContent = "<?php\ndefine('DB_TYPE', " . var_export($dbType, true) . ");\ndefine('DB_HOST', " . var_export($dbHost, true) . ");\ndefine('DB_PORT', " . var_export($dbPort, true) . ");\ndefine('DB_NAME', " . var_export($dbName, true) . ");\ndefine('DB_USER', " . var_export($dbUser, true) . ");\ndefine('DB_PASS', " . var_export($dbPass, true) . ");\n";
                    } catch (Exception $e) {
                        // PostgreSQL：如果数据库不存在，尝试连接默认库并创建
                        if ($dbType === 'pgsql') {
                            try {
                                $adminDsn = "pgsql:host={$dbHost};port={$dbPort};dbname=postgres";
                                $adminDb = new PDO($adminDsn, $dbUser, $dbPass, [
                                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                ]);
                                $adminDb->exec("CREATE DATABASE \"{$dbName}\"");
                                $configContent = "<?php\ndefine('DB_TYPE', " . var_export($dbType, true) . ");\ndefine('DB_HOST', " . var_export($dbHost, true) . ");\ndefine('DB_PORT', " . var_export($dbPort, true) . ");\ndefine('DB_NAME', " . var_export($dbName, true) . ");\ndefine('DB_USER', " . var_export($dbUser, true) . ");\ndefine('DB_PASS', " . var_export($dbPass, true) . ");\n";
                            } catch (Exception $e2) {
                                $errors[] = '数据库连接失败：' . $e->getMessage() . '。自动创建数据库也失败：' . $e2->getMessage();
                            }
                        } else {
                            $errors[] = '数据库连接失败：' . $e->getMessage();
                        }
                    }
                }
            }

            if (empty($errors)) {
                if (file_put_contents(DATA_PATH . 'site_config.php', $configContent) === false) {
                    $errors[] = '无法写入配置文件，请检查 data 目录权限。';
                } else {
                    redirect('install.php?step=2');
                }
            }
        }

        if ($action === 'install' && $allOk) {
            // 步骤 3：执行安装
            try {
                // 重新安装时，先删除旧的数据库文件
                $dbType = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
                if (!empty($_POST['reinstall'])) {
                    if ($dbType === 'sqlite' && file_exists(DB_FILE)) @unlink(DB_FILE);
                    if (file_exists(INSTALLED_FILE)) @unlink(INSTALLED_FILE);
                }

                // 在初始化数据库前，先校验所有用户输入
                $siteName = trim($_POST['site_name'] ?? APP_NAME);
                if ($siteName === '') {
                    $siteName = APP_NAME;
                }
                $siteSlogan = trim($_POST['site_slogan'] ?? '');
                if (mb_strlen($siteSlogan) > 100) {
                    $siteSlogan = mb_substr($siteSlogan, 0, 100);
                }

                // 可选：SMTP 邮件配置（在校验阶段抛出异常，避免 init_db 后回滚）
                $smtpEnabled = !empty($_POST['smtp_enabled']);
                if ($smtpEnabled) {
                    $smtpHost = trim($_POST['smtp_host'] ?? '');
                    $smtpPort = (int)($_POST['smtp_port'] ?? 587);
                    $smtpUser = trim($_POST['smtp_user'] ?? '');
                    $smtpPass = $_POST['smtp_pass'] ?? '';
                    $smtpEncryption = in_array($_POST['smtp_encryption'] ?? '', ['', 'ssl', 'tls'], true) ? ($_POST['smtp_encryption'] ?? '') : 'tls';
                    $smtpFrom = trim($_POST['smtp_from'] ?? '');
                    $smtpFromName = trim($_POST['smtp_from_name'] ?? $siteName);

                    if ($smtpHost === '' || $smtpPort <= 0 || $smtpFrom === '') {
                        throw new Exception('启用邮件功能时，SMTP 服务器、端口和发件人邮箱不能为空。');
                    }
                    if (!filter_var($smtpFrom, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception('发件人邮箱格式不正确。');
                    }
                }

                init_db();

                // 保存站点配置（追加到已有的 DB 配置后面）
                $siteConfigFile = DATA_PATH . 'site_config.php';
                $existingConfig = is_file($siteConfigFile) ? file_get_contents($siteConfigFile) : "<?php\n";

                $configContent = $existingConfig;
                // 移除旧的 SITE_NAME/SITE_SLOGAN/SMTP_ 定义（如果有，重新安装时）
                $configContent = preg_replace('/^define\(\'SITE_NAME\'.*$\n?/m', '', $configContent);
                $configContent = preg_replace('/^define\(\'SITE_SLOGAN\'.*$\n?/m', '', $configContent);
                $configContent = preg_replace('/^define\(\'SMTP_.*$\n?/m', '', $configContent);
                // 清除多余连续空行
                $configContent = preg_replace("/\n{3,}/", "\n\n", $configContent);
                $configContent = rtrim($configContent) . "\n";
                $configContent .= "define('SITE_NAME', " . var_export($siteName, true) . ");\n";
                if ($siteSlogan !== '') {
                    $configContent .= "define('SITE_SLOGAN', " . var_export($siteSlogan, true) . ");\n";
                }

                if ($smtpEnabled) {
                    $configContent .= "define('SMTP_ENABLED', true);\n";
                    $configContent .= "define('SMTP_HOST', " . var_export($smtpHost, true) . ");\n";
                    $configContent .= "define('SMTP_PORT', " . var_export($smtpPort, true) . ");\n";
                    $configContent .= "define('SMTP_USER', " . var_export($smtpUser, true) . ");\n";
                    $configContent .= "define('SMTP_PASS', " . var_export($smtpPass, true) . ");\n";
                    $configContent .= "define('SMTP_ENCRYPTION', " . var_export($smtpEncryption, true) . ");\n";
                    $configContent .= "define('SMTP_FROM', " . var_export($smtpFrom, true) . ");\n";
                    $configContent .= "define('SMTP_FROM_NAME', " . var_export($smtpFromName, true) . ");\n";
                }

                if (file_put_contents(DATA_PATH . 'site_config.php', $configContent) === false) {
                    throw new Exception('无法写入站点配置文件，请检查 data 目录权限。');
                }

                // 写入安装锁
                if (file_put_contents(INSTALLED_FILE, date('Y-m-d H:i:s')) === false) {
                    throw new Exception('无法写入安装锁文件，请检查 data 目录权限。');
                }

                // 跳转到完成页
                redirect('install.php?step=4');
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
                $step = 3;
            }
        }
    }
}

// 重新加载 site_config.php 以确保 DB_* 常量在当前请求中生效
// 注意：此处的 redirect() 会在 db_config 保存成功后退出，因此主要覆盖
// site_config.php 已存在但常量未定义的情况（如直接访问 step=2）
if (!defined('DB_TYPE')) {
    $siteConfigFile = DATA_PATH . 'site_config.php';
    if (is_file($siteConfigFile)) {
        @include $siteConfigFile;
    }
}

$steps = [
    1 => ['label' => t('install_step_db', '数据库'), 'icon' => '1'],
    2 => ['label' => t('install_step_env', '环境检测'), 'icon' => '2'],
    3 => ['label' => t('install_step_config', '站点配置'), 'icon' => '3'],
    4 => ['label' => t('install_step_done', '完成'), 'icon' => '✓'],
];

// 语言是否已选定（有 URL 参数或 Cookie）
$langSelected = !empty($_GET['lang']) || !empty($_COOKIE['forum_lang']);

// 授权协议是否已接受（有 URL 参数或 Cookie）
$licenseAccepted = isset($_GET['license']) || !empty($_COOKIE['forum_license']);
// 点击同意后设置 Cookie 并跳转
if (isset($_GET['license']) && $_GET['license'] === 'accepted') {
    setcookie('forum_license', 'accepted', time() + 86400 * 30, '/', '', false, true);
    $_COOKIE['forum_license'] = 'accepted';
    $licenseAccepted = true;
}

// 重新选择语言：清除语言 Cookie 后回到语言选择页
if (isset($_GET['change_lang'])) {
    setcookie('forum_lang', '', time() - 3600, '/');
    setcookie('forum_license', '', time() - 3600, '/');
    $_COOKIE['forum_lang'] = '';
    $_COOKIE['forum_license'] = '';
    redirect('install.php');
}

$pageTitle = t('install_title', '安装向导');
?>
<!DOCTYPE html>
<html lang="<?php echo str_replace('_', '-', APP_LANG); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?php echo e($pageTitle); ?> - <?php echo e(APP_NAME); ?></title>
    <link rel="stylesheet" href="public/css/style.css?v=<?php echo e(APP_VERSION); ?>">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
</head>
<body class="auth-page">
    <div class="install-container">
        <!-- 头部 Logo -->
        <div class="install-hero">
            <img src="public/images/logo.svg" alt="" class="install-logo">
            <h1 class="install-title"><?php echo e(t('app_name', APP_NAME)); ?></h1>
            <p class="install-subtitle"><?php echo e(t('app_slogan', '轻量级社区论坛系统 · PHP + SQLite · 开箱即用')); ?></p>
        </div>

        <?php if (!$langSelected): ?>
        <!-- ===== 语言选择页（安装前必须先选择语言） ===== -->
        <div class="card" id="lang-select-card">
            <h2 class="card-title mb-2" style="text-align:center;">🌐 <?php echo e(t('install_select_lang', '请选择你的语言')); ?></h2>
            <p class="text-muted mb-2" style="text-align:center;"><?php echo e(t('install_select_lang_desc', 'Please select your preferred language / 请选择你的语言')); ?></p>
            <div class="lang-select-grid">
                <?php foreach (get_available_languages() as $code => $info): ?>
                <a href="install.php?lang=<?php echo $code; ?>" class="lang-card">
                    <span class="lang-flag"><?php echo $info['flag'] === 'CN' ? '🇨🇳' : ($info['flag'] === 'TW' ? '🇹🇼' : '🇺🇸'); ?></span>
                    <span class="lang-name"><?php echo e($info['name']); ?></span>
                    <?php if ($code === 'zh-CN'): ?><span class="lang-badge">默认 Default</span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <style>
        .lang-select-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-top: 0.5rem; }
        .lang-card {
            display: flex; flex-direction: column; align-items: center; gap: 0.5rem;
            padding: 1.5rem 1rem; border: 2px solid var(--border); border-radius: var(--radius-md);
            text-decoration: none; color: var(--text); transition: all 0.2s;
            background: var(--surface); cursor: pointer;
        }
        .lang-card:hover { border-color: var(--primary); box-shadow: 0 4px 20px rgba(59,130,246,0.15); transform: translateY(-2px); }
        .lang-flag { font-size: 2.5rem; line-height: 1; }
        .lang-name { font-size: 1rem; font-weight: 600; }
        .lang-badge { font-size: 0.65rem; padding: 2px 8px; background: var(--bg-soft); border-radius: 999px; color: var(--text-muted); }
        @media (max-width: 500px) { .lang-select-grid { grid-template-columns: 1fr; } }
        </style>
        <?php elseif (!$licenseAccepted): ?>
        <!-- ===== 授权协议书（语言选择后、安装步骤前） ===== -->
        <div class="card" id="license-card">
            <h2 class="card-title mb-2" style="text-align:center;"><?php echo ui_icon('file-text', 28); ?> <?php echo e(t('install_license_title', '软件许可协议')); ?></h2>
            <p class="text-muted mb-2" style="text-align:center;"><?php echo e(t('install_license_subtitle', '请仔细阅读以下许可协议，同意后方可继续安装。')); ?></p>

            <div class="license-content">
                <p><?php echo e(t('install_license_intro', '在使用云界论坛前，请仔细阅读以下条款。')); ?></p>

                <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="license-item">
                    <h4><?php echo e(t("install_license_item{$i}_title", "条款 {$i}")); ?></h4>
                    <p><?php echo e(t("install_license_item{$i}_text", '')); ?></p>
                </div>
                <?php endfor; ?>
            </div>

            <div class="license-actions">
                <a href="install.php?license=accepted" class="btn btn-primary"><?php echo e(t('install_license_agree', '我同意上述协议条款')); ?></a>
                <a href="/" class="btn btn-secondary"><?php echo e(t('install_license_decline', '我不同意，退出安装')); ?></a>
            </div>
            <p style="text-align:center; margin-top:1rem; font-size:0.8125rem;">
                <a href="install.php?change_lang=1" style="color:var(--text-muted); text-decoration:underline;">
                    &#8592; <?php echo e(t('install_change_lang', '返回语言选择')); ?>
                </a>
            </p>
        </div>

        <style>
        .license-content {
            background: var(--bg-soft); border: 1px solid var(--border); border-radius: var(--radius-md);
            padding: 1.5rem; margin-bottom: 1.5rem; max-height: 400px; overflow-y: auto;
            font-size: 0.9375rem; line-height: 1.8;
        }
        .license-item { margin-bottom: 1rem; }
        .license-item h4 { font-size: 0.9375rem; margin-bottom: 0.25rem; color: var(--text); }
        .license-item p { color: var(--text-muted); margin: 0; }
        .license-actions { display: flex; gap: 0.75rem; flex-wrap: wrap; }
        .license-actions .btn { flex: 1; min-width: 150px; text-align: center; text-decoration: none; }
        </style>
        <?php else: ?>


        <!-- 步骤指示器 -->
        <div class="install-steps">
            <?php foreach ($steps as $num => $info): ?>
                <div class="install-step <?php echo $step > $num ? 'done' : ($step === $num ? 'active' : ''); ?>">
                    <div class="install-step-circle">
                        <?php echo $step > $num ? '✓' : $info['icon']; ?>
                    </div>
                    <span class="install-step-label"><?php echo e($info['label']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <?php echo show_message($err, 'error'); ?>
            <?php endforeach; ?>
            <?php
            // 安装失败时显示 DDL 执行日志
            $log = get_ddl_install_log();
            if (!empty($log)):
                $errorCount = 0;
                $okCount = 0;
                $skipCount = 0;
                foreach ($log as $logEntry) {
                    if ($logEntry['status'] === 'error') {
                        $errorCount++;
                    } elseif ($logEntry['status'] === 'ok') {
                        $okCount++;
                    } elseif ($logEntry['status'] === 'skipped') {
                        $skipCount++;
                    }
                }
            ?>
            <details class="install-log" open>
                <summary class="install-log-summary">
                    DDL 执行日志（<?php echo count($log); ?> 条：<?php echo $okCount; ?> 成功 / <?php echo $errorCount; ?> 失败 / <?php echo $skipCount; ?> 跳过）
                </summary>
                <div class="install-log-body">
                    <table class="install-log-table">
                        <thead><tr><th>状态</th><th>表/索引</th><th>原始 SQL (SQLite)</th><th>翻译后 SQL</th><th>耗时</th><th>错误</th></tr></thead>
                        <tbody>
                        <?php foreach ($log as $entry): ?>
                        <tr class="log-<?php echo $entry['status']; ?>">
                            <td class="log-status"><?php echo $entry['status'] === 'ok' ? '✓' : ($entry['status'] === 'skipped' ? '⊘' : '✕'); ?></td>
                            <td><?php echo e($entry['table']); ?></td>
                            <td class="log-sql"><code><?php echo e(mb_strlen($entry['sql_orig']) > 200 ? mb_substr($entry['sql_orig'], 0, 200) . '...' : $entry['sql_orig']); ?></code></td>
                            <td class="log-sql"><code><?php echo e(mb_strlen($entry['sql_trans']) > 200 ? mb_substr($entry['sql_trans'], 0, 200) . '...' : $entry['sql_trans']); ?></code></td>
                            <td><?php echo $entry['elapsed_ms']; ?>ms</td>
                            <td class="log-error"><?php echo e($entry['error'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($installCheckError !== ''): ?>
            <!-- 检测到残留的安装锁文件，数据库不完整 -->
            <div class="alert alert-error" style="margin-bottom:1rem;">
                <strong><?php echo e(t('install_corrupt_warning', '检测到问题：安装锁文件存在，但')); ?></strong><?php echo e($installCheckError); ?>。
                <?php echo e(t('install_corrupt_desc', '这可能是因为之前的安装被中断或数据库文件损坏。')); ?>
            </div>
            <form method="post" action="install.php?step=3" style="margin-bottom:1rem;">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="install">
                <input type="hidden" name="reinstall" value="1">
                <p style="margin-bottom:0.75rem;font-size:0.85rem;color:var(--text-muted);">
                    <?php echo e(t('install_choice_reinstall', '你可以选择重新安装（将重建数据库），或手动删除 data/installed.lock 和 data/forum.db 文件后刷新本页。')); ?>
                </p>
                <button type="submit" class="btn btn-primary" name="submit" value="1"><?php echo e(t('install_reinstall_btn', '重新安装')); ?></button>
                <a href="install.php?step=2" class="btn btn-secondary" style="text-decoration:none;"><?php echo e(t('install_manual_fix_btn', '手动修复后刷新')); ?></a>
            </form>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <!-- 步骤 1：数据库配置 -->
            <form method="post" action="install.php" id="db-config-form">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="save_db_config">

                <div class="card">
                    <h2 class="card-title mb-1"><?php echo e(t('install_db_title', '数据库设置')); ?></h2>
                    <p class="text-muted mb-2"><?php echo e(t('install_db_desc', '选择数据库类型并填写连接信息，系统将自动测试连接。')); ?></p>

                    <!-- 数据库类型选择 -->
                    <label class="form-label mb-1"><?php echo e(t('install_db_type', '数据库类型')); ?></label>
                    <div class="db-type-grid mb-2" id="db-type-selector">
                        <label class="db-type-card <?php echo (defined('DB_TYPE') && DB_TYPE === 'sqlite') ? 'active' : ''; ?>">
                            <input type="radio" name="db_type" value="sqlite" <?php echo (!defined('DB_TYPE') || DB_TYPE === 'sqlite') ? 'checked' : ''; ?> onchange="toggleDbFields()">
                            <span class="db-type-icon"><?php echo ui_icon('hard-drive', 28); ?></span>
                            <span class="db-type-name">SQLite</span>
                            <span class="db-type-desc"><?php echo e(t('install_db_sqlite_desc', '文件型数据库，无需额外配置')); ?></span>
                        </label>
                        <label class="db-type-card <?php echo (defined('DB_TYPE') && DB_TYPE === 'mysql') ? 'active' : ''; ?>">
                            <input type="radio" name="db_type" value="mysql" <?php echo (defined('DB_TYPE') && DB_TYPE === 'mysql') ? 'checked' : ''; ?> onchange="toggleDbFields()">
                            <span class="db-type-icon"><?php echo ui_icon('database', 28); ?></span>
                            <span class="db-type-name">MySQL</span>
                            <span class="db-type-desc"><?php echo e(t('install_db_mysql_desc', '适合中大型站点，性能强')); ?></span>
                        </label>
                        <label class="db-type-card <?php echo (defined('DB_TYPE') && DB_TYPE === 'pgsql') ? 'active' : ''; ?>">
                            <input type="radio" name="db_type" value="pgsql" <?php echo (defined('DB_TYPE') && DB_TYPE === 'pgsql') ? 'checked' : ''; ?> onchange="toggleDbFields()">
                            <span class="db-type-icon"><?php echo ui_icon('server', 28); ?></span>
                            <span class="db-type-name">PostgreSQL</span>
                            <span class="db-type-desc"><?php echo e(t('install_db_pgsql_desc', '高级开源数据库，功能丰富')); ?></span>
                        </label>
                    </div>

                    <!-- SQLite 配置 -->
                    <div id="sqlite-fields" class="db-fields">
                        <div class="form-group">
                            <label class="form-label" for="db_file"><?php echo e(t('install_db_file', '数据库文件路径')); ?></label>
                            <input type="text" class="form-control" id="db_file" name="db_file" value="<?php echo e(defined('DB_FILE') ? DB_FILE : DATA_PATH . 'forum.db'); ?>" placeholder="data/forum.db">
                            <p class="form-hint"><?php echo e(t('install_db_file_hint', '建议使用默认路径。修改前请确保目标目录可写。')); ?></p>
                        </div>
                    </div>

                    <!-- MySQL / PostgreSQL 配置 -->
                    <div id="remote-fields" class="db-fields" style="display:none;">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="db_host"><?php echo e(t('install_db_host', '数据库服务器')); ?></label>
                                <input type="text" class="form-control" id="db_host" name="db_host" value="<?php echo e(defined('DB_HOST') ? DB_HOST : 'localhost'); ?>" placeholder="localhost">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="db_port"><?php echo e(t('install_db_port', '端口')); ?></label>
                                <input type="text" class="form-control" id="db_port" name="db_port" value="<?php echo e(defined('DB_PORT') ? DB_PORT : '3306'); ?>" placeholder="3306" size="6">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="db_name"><?php echo e(t('install_db_name', '数据库名')); ?></label>
                            <input type="text" class="form-control" id="db_name" name="db_name" value="<?php echo e(defined('DB_NAME') ? DB_NAME : 'forum'); ?>" placeholder="forum">
                            <p class="form-hint"><?php echo e(t('install_db_name_hint', 'MySQL 环境下如果数据库不存在将自动尝试创建。')); ?></p>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="db_user"><?php echo e(t('install_db_user', '用户名')); ?></label>
                                <input type="text" class="form-control" id="db_user" name="db_user" value="<?php echo e(defined('DB_USER') ? DB_USER : ''); ?>" placeholder="root">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="db_pass"><?php echo e(t('install_db_pass', '密码')); ?></label>
                                <input type="password" class="form-control" id="db_pass" name="db_pass" value="<?php echo e(defined('DB_PASS') ? DB_PASS : ''); ?>" placeholder="留空则不设密码">
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block mt-2"><?php echo e(t('install_db_next', '保存并下一步')); ?></button>
                    <p style="text-align:center; margin-top:0.75rem; font-size:0.8125rem;">
                        <a href="install.php?change_lang=1" style="color:var(--text-muted); text-decoration:underline;">
                            &#8592; <?php echo e(t('install_change_lang', '返回语言选择')); ?>
                        </a>
                    </p>
                </div>
            </form>

            <style>
            .db-type-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }
            .db-type-card {
                display: flex; flex-direction: column; align-items: center; gap: 0.35rem;
                padding: 1.25rem 0.75rem; border: 2px solid var(--border); border-radius: var(--radius-md);
                cursor: pointer; transition: all 0.2s; background: var(--surface); text-align: center;
            }
            .db-type-card:hover { border-color: var(--primary); box-shadow: 0 2px 12px rgba(59,130,246,0.12); }
            .db-type-card.active { border-color: var(--primary); background: var(--primary-soft, #eef2ff); }
            .db-type-card input[type="radio"] { display: none; }
            .db-type-icon { color: var(--text-muted); }
            .db-type-name { font-weight: 700; font-size: 1rem; }
            .db-type-desc { font-size: 0.75rem; color: var(--text-muted); }
            .db-fields { margin-top: 0.5rem; }
            @media (max-width: 500px) { .db-type-grid { grid-template-columns: 1fr; } }

        /* === 安装日志样式 === */
        .install-log {
            margin-top: 1rem; border: 1px solid var(--border); border-radius: var(--radius-md);
            background: var(--surface); overflow: hidden;
        }
        .install-log[open] { border-color: var(--primary); }
        .install-log-summary {
            padding: 0.75rem 1rem; cursor: pointer; user-select: none;
            font-weight: 600; font-size: 0.875rem; color: var(--text);
            background: var(--bg-soft); border-bottom: 1px solid var(--border);
        }
        .install-log[open] .install-log-summary { border-bottom-color: var(--primary); color: var(--primary); }
        .install-log-body {
            max-height: 500px; overflow: auto; padding: 0;
        }
        .install-log-table {
            width: 100%; border-collapse: collapse; font-size: 0.75rem; line-height: 1.4;
        }
        .install-log-table thead {
            position: sticky; top: 0; z-index: 1;
        }
        .install-log-table th {
            background: #1e293b; color: #e2e8f0; padding: 0.4rem 0.5rem; text-align: left;
            font-weight: 600; white-space: nowrap;
        }
        .install-log-table td {
            padding: 0.35rem 0.5rem; border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        .log-ok { background: transparent; }
        .log-skipped { background: #fffbeb; }
        .log-error { background: #fef2f2; }
        .log-status { text-align: center; font-weight: bold; }
        .log-ok .log-status { color: #10b981; }
        .log-skipped .log-status { color: #f59e0b; }
        .log-error .log-status { color: #ef4444; }
        .log-sql { max-width: 350px; word-break: break-all; }
        .log-sql code { font-size: 0.7rem; background: var(--bg-soft); padding: 1px 4px; border-radius: 3px; }
        .log-error { color: #ef4444; max-width: 250px; word-break: break-all; font-size: 0.7rem; }
        /* =================== */
            </style>

            <script>
            function toggleDbFields() {
                var type = document.querySelector('input[name="db_type"]:checked').value;
                document.getElementById('sqlite-fields').style.display = (type === 'sqlite') ? '' : 'none';
                document.getElementById('remote-fields').style.display = (type !== 'sqlite') ? '' : 'none';
                // 更新端口默认值
                document.getElementById('db_port').value = (type === 'mysql') ? '3306' : '5432';
                // 更新高亮
                document.querySelectorAll('.db-type-card').forEach(function(c) { c.classList.remove('active'); });
                var checked = document.querySelector('input[name="db_type"]:checked');
                if (checked) checked.closest('.db-type-card').classList.add('active');
            }
            toggleDbFields();
            </script>

        <?php elseif ($step === 2): ?>
            <!-- 步骤 2：环境检测 -->
            <div class="card">
                <h2 class="card-title mb-2"><?php echo e(t('install_env_check', '环境检测')); ?></h2>
                <p class="text-muted mb-2"><?php echo e(t('install_env_desc', '请确认服务器满足以下运行要求。')); ?></p>

                <ul class="check-list">
                    <li class="check-item <?php echo $phpOk ? 'success' : 'error'; ?>">
                        <span><?php echo e(t('install_php_version', 'PHP 版本 ≥ 7.4')); ?></span>
                        <span class="check-item-text">
                            <?php echo e(t('install_current', '当前') . ' ' . PHP_VERSION); ?>
                            <span class="check-item-icon"><?php echo $phpOk ? '✓' : '✕'; ?></span>
                        </span>
                    </li>
                    <li class="check-item <?php echo $pdoOk ? 'success' : 'error'; ?>">
                        <span><?php echo e(t('install_pdo_ext', 'PDO 扩展')); ?></span>
                        <span class="check-item-text">
                            <?php echo $pdoOk ? t('install_installed', '已安装') : t('install_not_installed', '未安装'); ?>
                            <span class="check-item-icon"><?php echo $pdoOk ? '✓' : '✕'; ?></span>
                        </span>
                    </li>
                    <li class="check-item <?php echo $dbExtOk ? 'success' : 'error'; ?>">
                        <span><?php echo e($dbExtName); ?> <?php echo e(t('install_ext', '扩展')); ?></span>
                        <span class="check-item-text">
                            <?php echo $dbExtOk ? t('install_installed', '已安装') : t('install_not_installed', '未安装'); ?>
                            <span class="check-item-icon"><?php echo $dbExtOk ? '✓' : '✕'; ?></span>
                        </span>
                    </li>
                    <li class="check-item <?php echo $pdoOk ? 'success' : 'warning'; ?>">
                        <span><?php echo e(t('install_pdo_drivers', '当前已加载的数据库驱动')); ?></span>
                        <span class="check-item-text" style="font-size: 0.85rem;">
                            <?php echo $availableDrivers ? implode('、', array_map('e', $availableDrivers)) : t('install_no_driver', '无'); ?>
                            <span class="check-item-icon"><?php echo $pdoOk ? '✓' : '!'; ?></span>
                        </span>
                    </li>
                    <li class="check-item <?php echo $mbstringOk ? 'success' : 'warning'; ?>">
                        <span><?php echo e(t('install_mbstring', 'mbstring 扩展')); ?></span>
                        <span class="check-item-text">
                            <?php echo $mbstringOk ? t('install_installed', '已安装') : t('install_not_installed_fb', '未安装（已启用兼容层）'); ?>
                            <span class="check-item-icon"><?php echo $mbstringOk ? '✓' : '!'; ?></span>
                        </span>
                    </li>
                    <li class="check-item <?php echo $dataWritable ? 'success' : 'error'; ?>">
                        <span><?php echo e(t('install_data_writable', 'data 目录可写')); ?></span>
                        <span class="check-item-text">
                            <?php echo $dataWritable ? t('install_writable', '可写') : t('install_not_writable', '不可写'); ?>
                            <span class="check-item-icon"><?php echo $dataWritable ? '✓' : '✕'; ?></span>
                        </span>
                    </li>
                </ul>

                <?php if ($allOk): ?>
                    <div class="alert alert-success">
                        <?php echo e(t('install_env_pass', '环境检测通过！可以继续下一步安装。')); ?>
                    </div>
                    <a href="install.php?step=3" class="btn btn-primary btn-block"><?php echo e(t('next', '下一步')); ?>：<?php echo e(t('install_step_config', '配置站点')); ?></a>
                    <a href="install.php?step=1" class="btn btn-secondary btn-block mt-1"><?php echo e(t('previous', '上一步')); ?></a>
                <?php else: ?>
                    <div class="alert alert-error">
                        <?php echo e(t('install_env_fail', '环境检测未通过，请修复以下问题后刷新本页。')); ?>
                    </div>

                    <?php if (!$dbExtOk): ?>
                        <div class="card" style="padding: 1.25rem; background: var(--bg-soft); border-color: var(--border);">
                            <h4 class="card-title mb-1"><?php echo e(t('install_how_enable_ext', '如何启用')); ?> <?php echo e($dbExtName); ?>？</h4>
                            <ol style="margin: 0.5rem 0 0; padding-left: 1.25rem; line-height: 1.9; font-size: 0.9375rem;">
                                <li>打开 PHP 安装目录，如 <code>E:\phpstudy_pro\Extensions\php\php7.4.3nts\</code></li>
                                <li>编辑 <code>php.ini</code> 文件</li>
                                <?php foreach ($needExtList as $ext): ?>
                                <li>找到 <code>;extension=<?php echo e($ext); ?></code>，去掉前面的 <code>;</code></li>
                                <?php endforeach; ?>
                                <li>保存文件，重启 Web 服务器（Apache/Nginx）</li>
                                <li>刷新本页</li>
                            </ol>
                            <p style="margin: 0.75rem 0 0; font-size: 0.875rem; color: var(--text-muted);">
                                <?php echo e(t('install_ext_tip', '提示：修改 php.ini 后必须重启 Web 服务器（或 PHP-FPM）才会生效；请确认修改的是网站实际使用的 PHP 版本对应的 php.ini。')); ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if (!$dataWritable): ?>
                        <div class="card" style="padding: 1.25rem; background: var(--bg-soft); border-color: var(--border);">
                            <h4 class="card-title mb-1"><?php echo e(t('install_how_data_writable', '如何让 data 目录可写？')); ?></h4>
                            <p style="margin: 0.5rem 0 0; font-size: 0.9375rem;">请手动创建 <code>data</code> 目录并设置写权限：</p>
                            <pre style="background: #1e293b; color: #e2e8f0; padding: 0.875rem; border-radius: var(--radius-sm); margin-top: 0.75rem; font-size: 0.875rem; overflow-x: auto;">mkdir data
chmod 755 data</pre>
                        </div>
                    <?php endif; ?>

                    <a href="install.php?step=2" class="btn btn-secondary btn-block mt-2"><?php echo e(t('install_retest', '重新检测')); ?></a>
                    <a href="install.php?step=1" class="btn btn-secondary btn-block mt-1"><?php echo e(t('previous', '上一步')); ?></a>
                <?php endif; ?>
            </div>

        <?php elseif ($step === 3): ?>
            <!-- 步骤 3：站点配置与安装 -->
            <div class="card" id="install-card">
                <h2 class="card-title mb-2"><?php echo e(t('install_config', '配置安装')); ?></h2>
                <p class="text-muted mb-2"><?php echo e(t('install_config_desc', '设置站点名称，点击开始安装即可完成部署。')); ?></p>

                <form method="POST" action="install.php?step=3" data-validate id="install-form">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="install">

                    <div class="form-group">
                        <label class="form-label" for="site_name"><?php echo e(t('install_site_name', '站点名称')); ?></label>
                        <input type="text" class="form-control" id="site_name" name="site_name" value="<?php echo e(APP_NAME); ?>" required maxlength="50">
                        <p class="form-hint"><?php echo e(t('install_site_name_hint', '将显示在浏览器标题栏和页头，可随时在后台修改。')); ?></p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="site_slogan"><?php echo e(t('install_site_slogan', '站点副标题')); ?></label>
                        <input type="text" class="form-control" id="site_slogan" name="site_slogan" value="" maxlength="100" placeholder="例如：官方网站、交流社区（留空则不显示）">
                        <p class="form-hint"><?php echo e(t('install_site_slogan_hint', '显示在 logo 下方的简短说明，留空则前台不显示。')); ?></p>
                    </div>

                    <div class="form-group" style="border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem; background: var(--surface-2);">
                        <label class="flex items-center gap-2" style="cursor: pointer; font-weight: 600;">
                            <input type="checkbox" name="smtp_enabled" id="smtp_enabled" value="1">
                            <span><?php echo e(t('install_smtp_title', '启用 SMTP 邮件发送')); ?></span>
                        </label>
                        <p class="form-hint" style="margin-top: 0.5rem;"><?php echo e(t('install_smtp_hint', '启用后注册需要邮箱验证码，并自动开启「忘记密码」功能。')); ?></p>

                        <div id="smtp-fields" style="margin-top: 1rem; display: none;">
                            <div class="form-group">
                                <label class="form-label" for="smtp_host"><?php echo e(t('install_smtp_host', 'SMTP 服务器')); ?></label>
                                <input type="text" class="form-control" id="smtp_host" name="smtp_host" placeholder="例如：smtp.example.com">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="smtp_port"><?php echo e(t('install_smtp_port', '端口')); ?></label>
                                <input type="number" class="form-control" id="smtp_port" name="smtp_port" value="587" min="1" max="65535">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="smtp_encryption"><?php echo e(t('install_smtp_encryption', '加密方式')); ?></label>
                                <select class="form-control" id="smtp_encryption" name="smtp_encryption">
                                    <option value="tls" selected>TLS（STARTTLS）</option>
                                    <option value="ssl">SSL（SMTPS）</option>
                                    <option value=""><?php echo e(t('install_no_encryption', '无加密')); ?></option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="smtp_user"><?php echo e(t('install_smtp_user', '用户名')); ?></label>
                                <input type="text" class="form-control" id="smtp_user" name="smtp_user" placeholder="通常与发件人邮箱相同">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="smtp_pass"><?php echo e(t('install_smtp_pass', '密码 / 授权码')); ?></label>
                                <input type="password" class="form-control" id="smtp_pass" name="smtp_pass">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="smtp_from"><?php echo e(t('install_smtp_from', '发件人邮箱')); ?></label>
                                <input type="email" class="form-control" id="smtp_from" name="smtp_from" placeholder="noreply@example.com">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="smtp_from_name"><?php echo e(t('install_smtp_from_name', '发件人名称')); ?></label>
                                <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" placeholder="留空则使用站点名称">
                            </div>
                        </div>
                    </div>

                    <script>
                    (function() {
                        var cb = document.getElementById('smtp_enabled');
                        var fields = document.getElementById('smtp-fields');
                        if (!cb || !fields) return;
                        function toggle() { fields.style.display = cb.checked ? 'block' : 'none'; }
                        cb.addEventListener('change', toggle);
                        toggle();
                    })();
                    </script>

                    <div class="alert alert-warning">
                        <?php echo e(t('install_warning', '即将执行：系统将创建数据库表结构并写入初始数据，请勿重复执行。')); ?>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" id="install-submit"><?php echo e(t('install_btn_install', '开始安装')); ?></button>
                    <a href="install.php?step=2" class="btn btn-secondary btn-block mt-1"><?php echo e(t('previous', '上一步')); ?></a>
                </form>
            </div>

            <!-- 安装进度遮罩 -->
            <div class="install-progress-overlay" id="installProgress" aria-hidden="true">
                <div class="install-progress-box">
                    <div class="install-progress-spinner" aria-hidden="true">
                        <svg viewBox="0 0 50 50" width="56" height="56">
                            <circle class="ip-track" cx="25" cy="25" r="20" fill="none" stroke-width="4"/>
                            <circle class="ip-spin" cx="25" cy="25" r="20" fill="none" stroke-width="4"/>
                        </svg>
                    </div>
                    <h3 class="install-progress-title"><?php echo e(t('install_installing', '正在安装，请稍候...')); ?></h3>
                    <p class="install-progress-subtitle" id="progressSubtitle"><?php echo e(t('install_progress_init', '正在初始化')); ?></p>

                    <div class="install-progress-bar">
                        <div class="install-progress-bar-fill" id="progressBar" style="width: 0%;"></div>
                    </div>

                    <ul class="install-progress-steps" id="progressSteps">
                        <li data-step="0"><span class="ips-icon"></span><span class="ips-text"><?php echo e(t('install_progress_dir', '创建数据目录')); ?></span></li>
                        <li data-step="1"><span class="ips-icon"></span><span class="ips-text"><?php echo e(t('install_progress_db', '初始化数据库表')); ?></span></li>
                        <li data-step="2"><span class="ips-icon"></span><span class="ips-text"><?php echo e(t('install_progress_insert', '写入默认版块与角色')); ?></span></li>
                        <li data-step="3"><span class="ips-icon"></span><span class="ips-text"><?php echo e(t('install_progress_config', '保存站点配置')); ?></span></li>
                        <li data-step="4"><span class="ips-icon"></span><span class="ips-text"><?php echo e(t('install_progress_lock', '写入安装锁文件')); ?></span></li>
                    </ul>

                    <p class="install-progress-hint"><?php echo e(t('install_installing_hint', '请勿关闭或刷新页面，安装完成后将自动跳转。')); ?></p>
                </div>
            </div>

            <script>
            (function() {
                var form = document.getElementById('install-form');
                if (!form) return;
                var overlay = document.getElementById('installProgress');
                var submitBtn = document.getElementById('install-submit');
                var steps = document.querySelectorAll('#progressSteps li');
                var bar = document.getElementById('progressBar');
                var subtitle = document.getElementById('progressSubtitle');
                var total = steps.length;
                var current = 0;
                var timer = null;

                function activateStep(i) {
                    steps.forEach(function(s, idx) {
                        s.classList.remove('active', 'done');
                        if (idx < i) s.classList.add('done');
                        if (idx === i) s.classList.add('active');
                    });
                    var pct = Math.min(100, Math.round(((i + 1) / total) * 100));
                    if (bar) bar.style.width = pct + '%';
                    if (subtitle && steps[i]) {
                        subtitle.textContent = steps[i].querySelector('.ips-text').textContent;
                    }
                }

                form.addEventListener('submit', function() {
                    if (overlay) {
                        overlay.style.display = 'flex';
                        overlay.setAttribute('aria-hidden', 'false');
                    }
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.classList.add('btn-disabled');
                    }
                    activateStep(0);
                    timer = setInterval(function() {
                        current++;
                        if (current >= total) {
                            // 全部完成，停留在最后一步，等待服务端跳转
                            if (timer) clearInterval(timer);
                            if (subtitle) subtitle.textContent = '即将完成...';
                            return;
                        }
                        activateStep(current);
                    }, 700);
                });
            })();
            </script>

        <?php elseif ($step === 4): ?>
            <!-- 步骤 4：安装完成 -->
            <div class="card text-center">
                <div class="install-success-icon"><?php echo ui_icon('party', 64); ?></div>
                <h2 class="card-title mb-1"><?php echo e(t('install_success', '安装成功！')); ?></h2>
                <p class="text-muted mb-2"><?php echo e(t('install_success_desc', '恭喜，' . APP_NAME . ' 已成功部署。')); ?></p>

                <div class="alert alert-success" style="text-align: left;">
                    <strong><?php echo e(t('install_next_steps', '下一步：')); ?></strong>
                    <ul style="margin: 0.5rem 0 0; padding-left: 1.25rem; line-height: 1.9;">
                        <li><?php echo e(t('install_step1', '注册第一个账号，该账号将自动获得管理员权限')); ?></li>
                        <li><?php echo e(t('install_step2', '登录后进入「管理后台」进行详细配置')); ?></li>
                        <li><?php echo e(t('install_step3', '在「权限组」中配置版主等管理角色')); ?></li>
                        <li><?php echo e(t('install_step4', '在「勋章管理」中创建并授予用户勋章')); ?></li>
                    </ul>
                </div>

                <a href="index.php?route=register" class="btn btn-primary btn-block"><?php echo e(t('install_go_register', '去注册管理员账号')); ?></a>
                <a href="/" class="btn btn-secondary btn-block mt-1"><?php echo e(t('install_visit_forum', '先逛逛论坛')); ?></a>
            </div>

        <?php endif; ?>

        <?php endif; ?>

        <!-- 页脚 -->
        <div style="text-align: center; margin-top: 2rem; color: var(--text-muted); font-size: 0.8125rem;">
            <p>&copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?> · v<?php echo e(APP_VERSION); ?></p>
        </div>
    </div>

    <script src="public/js/main.js?v=<?php echo e(APP_VERSION); ?>" defer></script>
</body>
</html>
