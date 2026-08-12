<?php
/**
 * 云界论坛 - 全局配置文件
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
}

define('APP_NAME', t('common_bb1a21','云界论坛'));
define('APP_VERSION', '1.5.3');
define('SITE_URL', ''); // 留空则自动检测；设置后仅影响邮件等对外场景的规范域名，分享链接始终按当前访问域名生成

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

// 判断当前请求是否通过 HTTPS（兼容反向代理后的 X-Forwarded-Proto）
$__is_https = false;
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $__is_https = true;
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    $__is_https = true;
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_SCHEME']) && strtolower($_SERVER['HTTP_X_FORWARDED_SCHEME']) === 'https') {
    $__is_https = true;
} elseif (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strcasecmp($_SERVER['HTTP_FRONT_END_HTTPS'], 'off') !== 0) {
    $__is_https = true;
}

// 是否仅通过 HTTPS 传输 remember cookie
// 默认按实际协议自动判断；HTTP 环境下为 false，HTTPS（含反向代理后）为 true。
// 如需强制指定，可在 data/site_config.php 中先定义 COOKIE_SECURE。
if (!defined('COOKIE_SECURE')) {
    define('COOKIE_SECURE', $__is_https);
}

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
    // 受限环境（open_basedir）会话持久化修复：
    // 若 php.ini 默认的 session.save_path 不在 open_basedir 白名单内或不可写，
    // 会话将无法跨请求保存，导致所有表单（登录/注册/发帖）的 CSRF 与验证码
    // 校验永远失败、反复停留在登录页。优先使用项目内 data/sessions
    // （位于 open_basedir 白名单内且通常可写），其次回退系统临时目录。
    $sessRoot = dirname(__DIR__, 2); // app/includes -> 项目根
    $sessCandidates = [
        $sessRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'sessions',
        sys_get_temp_dir(),
    ];
    $sessDirUsed = '';
    foreach ($sessCandidates as $sessDir) {
        if (!is_dir($sessDir)) {
            @mkdir($sessDir, 0755, true);
        }
        if (is_dir($sessDir) && is_writable($sessDir)) {
            session_save_path($sessDir);
            $sessDirUsed = $sessDir;
            break;
        }
    }
    if ($sessDirUsed === '') {
        error_log('[yunjie] 无可写的 session 保存目录，登录/验证码状态可能无法保持。候选目录：' . implode(', ', $sessCandidates));
    }

    // 允许 site_config.php 强制指定 session cookie 安全标志；
    // 若未指定则按实际协议自动判断（$__is_https 在文件顶部已计算）。
    // 反向代理场景如检测异常，可在 data/site_config.php 中定义：
    // define('SESSION_COOKIE_SECURE', false);
    if (!defined('SESSION_COOKIE_SECURE')) {
        define('SESSION_COOKIE_SECURE', $__is_https);
    }

    // 会话加固：严格模式拒绝未经服务端签发的会话 ID（防会话固定攻击），
    // 客户端提交的未知 session id 会被丢弃并重新生成。
    ini_set('session.use_strict_mode', '1');

    $sessCookieParams = [
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => SESSION_COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    // 若站点在 HTTP 下但 cookie 仍无法写入（如浏览器 SameSite 策略），
    // 保留 SESSION_COOKIE_SAMESITE 覆盖入口，便于调试。
    if (defined('SESSION_COOKIE_SAMESITE')) {
        $sessCookieParams['samesite'] = SESSION_COOKIE_SAMESITE;
    }

    session_set_cookie_params($sessCookieParams);
    session_start();

    // 会话初始化后记录诊断信息，便于排查「登录状态记不住」问题
    if (empty($_SESSION['_session_diag'])) {
        $_SESSION['_session_diag'] = [
            'save_path' => $sessDirUsed,
            'secure'    => SESSION_COOKIE_SECURE,
            'samesite'  => $sessCookieParams['samesite'],
            'started_at'=> time(),
        ];
    }
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
// 性能优化：合并后的语言包以 var_export 预编译缓存到 data/cache/lang_{code}.php，
// 失效键为「基础包 + 所有 extras 分片 mtime 最大值」，任一源文件更新即重建；
// 缓存目录不存在/不可写/生成失败时静默回退逐文件加载（受限环境兼容）。
if (!function_exists('load_language_pack')) {
    function load_language_pack(string $code): array {
        $base = APP_ROOT . 'app/includes/languages' . DIRECTORY_SEPARATOR . $code . '.php';
        $extraDir = APP_ROOT . 'app/includes/languages' . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . $code;

        // 收集全部源分片（基础包 + extras），glob 失败/无 extras 时仅含基础包
        $srcFiles = [];
        if (is_file($base)) {
            $srcFiles[] = $base;
        }
        if (is_dir($extraDir)) {
            $extras = glob($extraDir . DIRECTORY_SEPARATOR . '*.php');
            if (is_array($extras)) {
                foreach ($extras as $ef) {
                    $srcFiles[] = $ef;
                }
            }
        }
        if (empty($srcFiles)) {
            return [];
        }
        $srcMtime = 0;
        foreach ($srcFiles as $f) {
            $mt = @filemtime($f);
            if ($mt !== false && $mt > $srcMtime) {
                $srcMtime = $mt;
            }
        }

        // 1) 优先读预编译缓存：文件头声明的 mtime 不低于源文件最大值才命中
        $cacheFile = DATA_PATH . 'cache' . DIRECTORY_SEPARATOR . 'lang_' . $code . '.php';
        if (is_file($cacheFile) && @filemtime($cacheFile) !== false && filemtime($cacheFile) >= $srcMtime) {
            try {
                $cached = include $cacheFile;
                if (is_array($cached) && !empty($cached)) {
                    return $cached;
                }
            } catch (Throwable $e) {
                // 缓存损坏：走下方回退加载并尝试重建
            }
        }

        // 2) 回退：逐文件加载（原有逻辑，缓存不可用时永远可用）
        $pack = [];
        foreach ($srcFiles as $f) {
            try {
                $loaded = require $f;
                if (is_array($loaded)) {
                    $pack = array_merge($pack, $loaded);
                }
            } catch (Throwable $e) {
                // 单个分片损坏不阻断整体加载
            }
        }

        // 3) 惰性重建缓存：本次请求已回退加载，顺手尝试写缓存；失败不影响本次请求
        if (!empty($pack)) {
            try {
                $cacheDir = DATA_PATH . 'cache';
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0755, true);
                }
                if (is_dir($cacheDir) && is_writable($cacheDir)) {
                    $exported = var_export($pack, true);
                    $content = "<?php\n// 语言包预编译缓存（自动生成，勿手工编辑）\n// lang={$code} src_max_mtime={$srcMtime}\nreturn " . $exported . ";\n";
                    $tmp = $cacheFile . '.tmp.' . getmypid();
                    if (@file_put_contents($tmp, $content, LOCK_EX) !== false) {
                        // 对齐源文件最大 mtime，避免刚写入即被判失效；失败不阻断
                        @touch($tmp, $srcMtime);
                        @rename($tmp, $cacheFile);
                    }
                }
            } catch (Throwable $e) {
                // 静默降级：缓存写入失败不影响本次请求
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
