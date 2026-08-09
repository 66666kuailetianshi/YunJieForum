<?php
/**
 * 云界论坛 - 全局配置文件
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
}

define('APP_NAME', t('common_bb1a21','云界论坛'));
define('APP_VERSION', '1.3.5-beta');
define('SITE_URL', ''); // 留空则自动检测

define('DATA_PATH', ROOT_PATH . 'data' . DIRECTORY_SEPARATOR);

// 应用根目录（用于统一路径引用）
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
}

// 先加载安装时写入的站点配置（含 DB_TYPE / DB_FILE / SITE_NAME 等），
// 必须放在 DB_FILE 默认值定义之前，否则站点配置中的 DB_FILE 会被默认值覆盖
$siteConfigFile = DATA_PATH . 'site_config.php';
if (is_file($siteConfigFile)) {
    @include_once $siteConfigFile;
}

if (!defined('DB_FILE')) {
    define('DB_FILE', DATA_PATH . 'forum.db');
}
define('INSTALLED_FILE', DATA_PATH . 'installed.lock');

define('UPLOAD_PATH', ROOT_PATH . 'uploads' . DIRECTORY_SEPARATOR);
define('AVATAR_PATH', UPLOAD_PATH . 'avatars' . DIRECTORY_SEPARATOR);
define('AVATAR_URL', 'uploads/avatars/');
define('UPLOAD_IMAGE_PATH', UPLOAD_PATH . 'images' . DIRECTORY_SEPARATOR);
define('UPLOAD_IMAGE_URL', 'uploads/images/');

define('POSTS_PER_PAGE', 10);
define('REPLIES_PER_PAGE', 20);
define('COOKIE_REMEMBER_DAYS', 400);

// 是否仅通过 HTTPS 传输 remember cookie
// 默认为 false 以保证 HTTP 环境下也能正常保存；HTTPS 站点可改为 true 提升安全性
define('COOKIE_SECURE', false);

// 记住账号密码功能：加密密钥 cookie 有效期（天）
define('CRED_KEY_COOKIE_DAYS', 400);

// 签到积分规则：基础分 + 连续天数奖励（封顶 30 分）
define('CHECKIN_BASE_POINTS', 5);
define('CHECKIN_STREAK_BONUS', 2);
define('CHECKIN_MAX_POINTS', 30);
define('CHECKIN_MILESTONE_7_DAYS', 10);   // 连续 7 天额外奖励
define('CHECKIN_MILESTONE_30_DAYS', 50);  // 连续 30 天额外奖励
define('CHECKIN_COINS', 1);               // 每次签到获得金币数

// 内容贡献积分规则
define('POST_POINTS', 10);
define('REPLY_POINTS', 3);
define('REPLY_RECEIVED_POINTS', 2);       // 帖子收到回复，楼主获得积分
define('FAVORITE_RECEIVED_POINTS', 3);    // 帖子被收藏，楼主获得积分

// 每日积分上限（防刷）
define('POINTS_DAILY_TOTAL_CAP', 200);           // 每日总积分上限
define('POINTS_DAILY_POST_CAP', 5);              // 每日发帖奖励次数
define('POINTS_DAILY_REPLY_CAP', 20);            // 每日回复奖励次数
define('POINTS_DAILY_REPLY_RECEIVED_CAP', 10);   // 每日收到回复奖励次数
define('POINTS_DAILY_FAVORITE_RECEIVED_CAP', 10);// 每日被收藏奖励次数

// 错误报告：生产环境不显示错误，仅记录日志
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('log_errors', '1');
ini_set('error_log', DATA_PATH . 'error.log');
// 本地调试时直接在页面显示错误，便于定位问题
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
ini_set('display_errors', $isLocal ? '1' : '0');

// 设置默认时区（必须在 session_start 之前）
date_default_timezone_set('Asia/Shanghai');

// 开启输出缓冲（必须在 session_start 之前，避免 headers already sent）
if (ob_get_level() === 0) {
    ob_start();
}

// 启动 session，配置安全 cookie 标志
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// 加载扩展兼容层（无 mbstring 时提供 fallback）
require_once APP_ROOT . 'app/includes/compat.php';

// 数据库类型与连接配置（site_config.php 优先，此处为默认值）
if (!defined('DB_TYPE'))  { define('DB_TYPE', 'sqlite'); }
if (!defined('DB_HOST'))  { define('DB_HOST', 'localhost'); }
if (!defined('DB_PORT'))  { define('DB_PORT', '3306'); }
if (!defined('DB_NAME'))  { define('DB_NAME', 'forum'); }
if (!defined('DB_USER'))  { define('DB_USER', ''); }
if (!defined('DB_PASS'))  { define('DB_PASS', ''); }

if (!defined('SITE_NAME')) {
    define('SITE_NAME', APP_NAME);
}
if (!defined('SITE_SLOGAN')) {
    define('SITE_SLOGAN', '');
}

// ==================== 多语言支持 ====================
// 可用语言列表
function get_available_languages(): array {
    return [
        'zh-CN' => ['name' => t('common_936591','简体中文'), 'flag' => 'CN'],
        'zh-TW' => ['name' => t('common_1f7e4a','繁體中文'), 'flag' => 'TW'],
        'en'    => ['name' => 'English',    'flag' => 'US'],
    ];
}

// 检测当前语言
// 原则：站点语言唯一由后台「站点设置」决定（数据库 site_lang → 文件 SITE_LANG）。
// 运行阶段所有用户（管理员/普通用户/游客）在所有页面一律使用同一语言，保证全站统一；
// 仅安装向导阶段允许通过 URL 参数 / Cookie 选择语言。
function detect_language(): string {
    $available = get_available_languages();
    $codes = array_keys($available);

    // 安装向导阶段（尚未完成安装）：允许选择语言（URL 参数优先，Cookie 记忆上次选择）
    if (!file_exists(INSTALLED_FILE)) {
        if (!empty($_GET['lang']) && in_array($_GET['lang'], $codes, true)) {
            return $_GET['lang'];
        }
        if (!empty($_COOKIE['forum_lang']) && in_array($_COOKIE['forum_lang'], $codes, true)) {
            return $_COOKIE['forum_lang'];
        }
    }

    // 站点配置（所有用户统一使用站点默认语言）
    // 数据库中的 site_lang 优先（管理员后台切换时写入，避免 OPcache 下 include 的配置文件变更不生效）
    try {
        if (!function_exists('get_site_setting')) {
            require_once APP_ROOT . 'app/includes/db.php';
        }
        $dbSiteLang = get_site_setting('site_lang', '');
        if ($dbSiteLang !== '' && in_array($dbSiteLang, $codes, true)) {
            return $dbSiteLang;
        }
    } catch (Throwable $e) {
        // 数据库不可用时回退到文件配置
    }
    if (defined('SITE_LANG') && in_array(SITE_LANG, $codes, true)) {
        return SITE_LANG;
    }

    // 默认中文
    return 'zh-CN';
}

// 定义当前语言
if (!defined('APP_LANG')) {
    define('APP_LANG', detect_language());
}

// 设置语言 Cookie（30 天有效期）——仅安装向导阶段记忆所选语言；
// 正常运行阶段全站语言由站点设置统一决定，不读写 Cookie，避免管理员与普通用户语言不一致。
if (!file_exists(INSTALLED_FILE)
    && !empty($_GET['lang'])
    && in_array($_GET['lang'], array_keys(get_available_languages()), true)) {
    setcookie('forum_lang', $_GET['lang'], time() + 86400 * 30, '/', '', false, false);
    $_COOKIE['forum_lang'] = $_GET['lang']; // 立即生效，确保同一请求中可用
}

// 加载语言包（基础包 + extras 目录下按语言拆分的分批扩展包，避免并行编辑冲突）
if (!function_exists('load_language_pack')) {
    function load_language_pack(string $code): array {
        $base = APP_ROOT . 'app/includes/languages' . DIRECTORY_SEPARATOR . $code . '.php';
        $pack = [];
        if (is_file($base)) {
            $loaded = require $base;
            if (is_array($loaded)) {
                $pack = $loaded;
            }
        }
        $extraDir = APP_ROOT . 'app/includes/languages' . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . $code;
        if (is_dir($extraDir)) {
            foreach (glob($extraDir . DIRECTORY_SEPARATOR . '*.php') as $ef) {
                $extra = require $ef;
                if (is_array($extra)) {
                    $pack = array_merge($pack, $extra);
                }
            }
        }
        return $pack;
    }
}
$LANG = load_language_pack(APP_LANG);
// 回退到简体中文
if (empty($LANG) && APP_LANG !== 'zh-CN') {
    $LANG = load_language_pack('zh-CN');
}

/**
 * 翻译函数：获取语言包中指定 key 的文本
 * @param string $key    语言包键名
 * @param string $default 未找到时的默认值（建议直接写中文原文，作为简体中文回退）
 * @param array  $vars   占位符替换，如 ['name' => $user] 会把文本中的 {name} 替换为对应值
 * @return string
 */
function t(string $key, string $default = '', array $vars = []): string {
    global $LANG;
    $text = '';
    if (isset($LANG[$key]) && is_string($LANG[$key]) && $LANG[$key] !== '') {
        $text = $LANG[$key];
    }
    if ($text === '' && $default !== '') {
        $text = $default;
    }
    if ($text === '') {
        $text = $key;
    }
    if (!empty($vars)) {
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string)$v, $text);
        }
    }
    return $text;
}

// 加载全局 i18n 辅助函数（多语言机制见本文件 get_available_languages / t / load_language_pack）
// 说明：语言切换器（render_language_switcher 等）已按需求移除，全站语言由后台「站点设置」统一决定。

// SMTP 配置（由安装向导或后台站点设置写入 data/site_config.php）
if (!defined('SMTP_ENABLED')) {
    define('SMTP_ENABLED', false);
}
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', '');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587);
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', '');
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', '');
}
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', 'tls');
}
if (!defined('SMTP_FROM')) {
    define('SMTP_FROM', '');
}
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', SITE_NAME);
}

// 发送基本安全响应头
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
