<?php
/**
 * 云界论坛 - 公共函数库
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
// IP 归属地离线查询（ip2region xdb 格式，无第三方依赖）
require_once __DIR__ . '/ip2region.php';
// mailer.php 已改为懒加载：仅在真正发送邮件的函数内 require_once，
// 避免 99% 不发邮件的请求白白加载 17KB 邮件模块。

/**
 * HTML 实体转义，防止 XSS（接受 null，避免 PHP 8 TypeError）
 */
function e(?string $text): string {
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 页面跳转（清空输出缓冲，校验 URL 防止头注入）
 * 伪静态路径（/forum?id=1 等）会自动转换为入口文件形式（/index.php?route=forum&id=1），
 * 使站点不依赖服务器重写规则也能正常跳转。
 */
function redirect(string $url): void {
    // 校验 URL 不含换行符，防止 HTTP 头注入
    if (strpbrk($url, "\r\n") !== false) {
        $url = '/';
    }
    // 统一转换为入口文件形式，避免依赖伪静态重写规则
    $url = to_entry_url($url);
    // 清空所有输出缓冲，避免 "headers already sent" 错误
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header("Location: $url");
    exit;
}

/**
 * 生成站点链接（入口文件形式），不依赖任何服务器重写规则。
 * 例如：site_url('forum', ['id' => 5]) => /index.php?route=forum&id=5
 *
 * @param string $route  路由名（如 forum、post、admin/users）
 * @param array  $params 附加查询参数
 */
function site_url(string $route = '', array $params = []): string {
    $query = [];
    if ($route !== '') {
        $query['route'] = $route;
    }
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }
        $query[$key] = $value;
    }
    $qs = http_build_query($query);
    if ($qs === '') {
        return '/index.php';
    }
    return '/index.php?' . $qs;
}

/**
 * 将伪静态路径（/forum?id=1 等）转换为入口文件形式（/index.php?route=forum&id=1）。
 * 完整 URL、已含入口文件、真实 .php 文件等保持原样。
 */
function to_entry_url(string $url): string {
    if ($url === '' || $url === '/' || $url === 'index.php' || $url === '/index.php') {
        return $url;
    }
    if (strpos($url, '?') === 0 || ($url !== '' && $url[0] === '#')) {
        return $url;
    }
    if (preg_match('#^(https?:)?//#i', $url)) {
        return $url;
    }
    if (strpos($url, 'index.php') === 0 || strpos($url, '/index.php') === 0 || strpos($url, 'install.php') !== false) {
        return $url;
    }
    if (strpos($url, '.php') !== false) {
        return $url; // 其他真实 .php 文件（如 ../profile.php）保持原样
    }
    $queryStart = strpos($url, '?');
    $path = $queryStart === false ? $url : substr($url, 0, $queryStart);
    $query = $queryStart === false ? '' : substr($url, $queryStart + 1);
    $route = trim($path, '/');
    if ($route === '') {
        return $url;
    }
    $out = '/index.php?route=' . rawurlencode($route);
    if ($query !== '') {
        $out .= '&' . $query;
    }
    return $out;
}

/**
 * 获取当前路由名称（用于导航高亮等），兼容伪静态与入口文件两种 URL。
 * 例如：/forum?id=1、/index.php?route=forum 均返回 forum。
 */
function current_route(): string {
    if (!empty($_GET['route'])) {
        return (string)$_GET['route'];
    }
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = trim($path, '/');
    $path = preg_replace('/^index\.php(\/|$)/i', '', $path);
    if ($path === '') {
        return 'home';
    }
    return basename($path);
}

/**
 * 生成或验证 CSRF Token
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 验证 CSRF Token
 * 支持显式传入 token，或自动从 POST 中读取（不再接受 GET 传参，防止链接泄露与 CSRF 绕过）
 */
function validate_csrf(?string $token = null): bool {
    if ($token === null && isset($_POST['csrf_token'])) {
        $token = $_POST['csrf_token'];
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

/**
 * 轮换 CSRF Token（登录/注册后调用，防止 token 复用）
 */
function rotate_csrf_token(): void {
    unset($_SESSION['csrf_token']);
}

/**
 * 生成发帖幂等 Token（防止重复提交）
 *
 * 每次进入发帖页时生成，写入 session 并随表单提交；
 * 服务端校验通过后立即从 session 移除，确保同一个 token 只能成功发帖一次。
 */
function post_nonce_token(): string {
    if (empty($_SESSION['post_nonce'])) {
        $_SESSION['post_nonce'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['post_nonce'];
}

/**
 * 验证发帖幂等 Token
 *
 * 验证成功后立即销毁 token，防止重放；验证失败保留原 token，允许用户刷新后重试。
 */
function validate_post_nonce(?string $token = null): bool {
    if ($token === null) {
        $token = $_POST['post_nonce'] ?? '';
    }
    $expected = $_SESSION['post_nonce'] ?? '';
    if ($expected === '' || !hash_equals($expected, (string)$token)) {
        return false;
    }
    unset($_SESSION['post_nonce']);
    return true;
}

/**
 * 检查指定用户最近 N 秒内是否发布过标题+内容完全相同的帖子
 *
 * 作为幂等 token 失效后的第二道防线（例如用户新开标签页绕过 session）。
 * 跨库安全：不在 SQL 里使用数据库专属函数，查询后在 PHP 中做哈希比对。
 */
function has_recent_duplicate_post(int $userId, string $title, string $content, int $seconds = 30, ?int $boardId = null): bool {
    try {
        $db = get_db();
        $driver = get_db_driver();
        
        // 如果指定了 board_id，则只检查同一板块内的重复帖子
        if ($boardId !== null && $boardId > 0) {
            $whereClause = "user_id = :uid AND forum_id = :bid AND created_at >= ";
        } else {
            $whereClause = "user_id = :uid AND created_at >= ";
        }
        
        if ($driver instanceof SQLiteDriver) {
            $stmt = $db->prepare($whereClause . "datetime('now', '-' || :sec || ' seconds') ORDER BY created_at DESC LIMIT 10");
        } else {
            $stmt = $db->prepare($whereClause . "DATE_SUB(NOW(), INTERVAL :sec SECOND) ORDER BY created_at DESC LIMIT 10");
        }
        
        $params = [':uid' => $userId, ':sec' => $seconds];
        if ($boardId !== null && $boardId > 0) {
            $params[':bid'] = $boardId;
        }
        
        $stmt->execute($params);
        $expectedHash = md5($title . "\n" . $content);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (md5(($row['title'] ?? '') . "\n" . ($row['content'] ?? '')) === $expectedHash) {
                return true;
            }
        }
    } catch (\Throwable $e) {
        // 查询异常不阻塞发帖，仅记录
        error_log(t('common_9245a3','has_recent_duplicate_post 异常: ') . $e->getMessage());
    }
    return false;
}

/**
 * 校验 URL scheme 白名单，防止 javascript:/data: 等 XSS
 */
function sanitize_url(?string $url): string {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    // 允许 http:// https:// mailto: 以及 / 开头的相对路径、# 锚点
    if (preg_match('#^(https?:|mailto:|/|#)#i', $url)) {
        return $url;
    }
    return '';
}

/**
 * 尝试通过 Cookie 自动登录
 * 使用 SHA-256(remember_token) 作为查询索引，避免全表 bcrypt 比对
 */
function try_cookie_login(): void {
    static $tried = false;
    if ($tried) {
        return;
    }
    $tried = true;

    if (isset($_SESSION['user_id'])) {
        return;
    }
    if (empty($_COOKIE['forum_remember'])) {
        return;
    }
    // 若已经向浏览器输出内容，则不再尝试设置 cookie，避免 headers already sent 警告
    if (headers_sent()) {
        return;
    }
    try {
        $db = get_db();
        $cookieValue = $_COOKIE['forum_remember'];
        if (!is_string($cookieValue) || strlen($cookieValue) < 16) {
            return;
        }
        // 使用 SHA-256 哈希进行快速精确匹配
        $hash = hash('sha256', $cookieValue);
        $stmt = $db->prepare("SELECT id FROM users WHERE remember_token = :hash LIMIT 1");
        $stmt->execute([':hash' => $hash]);
        $userId = $stmt->fetchColumn();
        if ($userId) {
            $_SESSION['user_id'] = (int)$userId;
            // 命中 remember token 后轮换 token：即使旧 token 泄漏也即刻失效。
            // 轮换失败不阻断本次自动登录，仅记录日志。
            try {
                if (!headers_sent()) {
                    $newToken = bin2hex(random_bytes(32));
                    $newHash = hash('sha256', $newToken);
                    $upd = $db->prepare("UPDATE users SET remember_token = :hash WHERE id = :id");
                    $upd->execute([':hash' => $newHash, ':id' => (int)$userId]);
                    // 沿用 login.php 下发 cookie 的参数（expires/secure/httponly/samesite）
                    setcookie('forum_remember', $newToken, [
                        'expires'  => time() + COOKIE_REMEMBER_DAYS * 86400,
                        'path'     => '/',
                        'secure'   => COOKIE_SECURE,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('[remember] token 轮换失败: ' . $e->getMessage());
            }
            return;
        }
        // 未匹配则清除 cookie
        if (!headers_sent()) {
            setcookie('forum_remember', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => COOKIE_SECURE,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    } catch (Exception $e) {
        // 忽略数据库异常
    }
}

/**
 * 检查是否已登录
 */
function is_logged_in(): bool {
    try_cookie_login();
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
}

/**
 * 获取当前登录用户信息
 * 若 session 中的 user_id 已失效（用户被删除），自动登出
 */
function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    if (isset($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
    if ($user) {
        $_SESSION['user'] = $user;
        return $user;
    }
    // 用户不存在，清理会话
    unset($_SESSION['user_id'], $_SESSION['user']);
    return null;
}

/**
 * 要求登录
 */
function require_login(): void {
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        redirect('/login');
    }
}

/**
 * 获取用户所有权限标识列表
 */
function user_permissions(?int $userId = null): array {
    if ($userId === null) {
        $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    }
    if (!$userId) return [];

    // 请求内 static 缓存：同一请求内多次调用（is_admin/has_permission）只查一次库
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $db = get_db();
    $stmt = $db->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $role = $stmt->fetchColumn();

    $permissions = [];
    if ($role === 'admin') {
        // 超级管理员天然拥有全部权限（含白名单内所有权限点），
        // 保证 has_permission('manage_posts') 等细粒度判断对超管始终通过。
        $permissions = ADMIN_PERMISSION_WHITELIST;
    }

    $stmt = $db->prepare("SELECT r.permissions FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = :user_id");
    $stmt->execute([':user_id' => $userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $perms) {
        foreach (array_filter(array_map('trim', explode(',', $perms))) as $perm) {
            $permissions[] = $perm;
        }
    }

    $permissions = array_unique($permissions);
    $cache[$userId] = $permissions;
    return $permissions;
}

/**
 * 检查当前用户是否拥有指定权限
 */
function has_permission(string $permission): bool {
    return in_array($permission, user_permissions(), true);
}

/**
 * 检查当前用户是否为管理员（admin 角色或拥有 admin_access 权限）
 */
function is_admin(): bool {
    return has_permission('admin_access');
}

// ---------------------------------------------------------------------------
// 两级管理员体系基础层：超级管理员（users.role='admin'）+ 社区管理员
// （内置角色 community_admin，带 admin_access 但 users.role 仍为 'user'）。
// ---------------------------------------------------------------------------

/**
 * 后台管理权限点白名单。
 *
 * 用途：供后续 roles.php 权限编辑页白名单化使用——
 * 渲染复选框、校验提交的权限串时，只允许此列表内的权限点，
 * 防止任意构造权限（如写入 admin_access 实现提权）。
 * 其中 manage_settings 等为超管专属占位项：社区管理员角色不应被授予，
 * 仅超级管理员（users.role='admin'）天然拥有全部权限。
 */
if (!defined('ADMIN_PERMISSION_WHITELIST')) {
    define('ADMIN_PERMISSION_WHITELIST', [
        // 后台总闸
        'admin_access',
        // 内容管理
        'manage_posts',
        'manage_replies',
        'manage_reports',
        'manage_ban_appeals',
        'manage_user_dispose',
        'manage_users',
        'manage_forums',
        'manage_medals',
        'manage_announcements',
        'manage_sensitive_words',
        // 超管专属（社区管理员不应勾选）
        'manage_settings',
        'manage_roles',
        'manage_backup',
        'manage_update',
        'manage_mail',
        'manage_system_status',
        'manage_data_migration',
    ]);
}

/**
 * 检查当前用户是否为超级管理员（users.role === 'admin'）。
 *
 * 注意：不能只看 has_permission('admin_access')——
 * 社区管理员角色（community_admin）也带 admin_access，
 * 但其 users.role 仍为 'user'，不属于超级管理员。
 */
function is_super_admin(): bool {
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        return false;
    }
    // 请求内 static 缓存：同一请求内多次调用只查一次库（与 user_permissions 同模式）
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $role = $stmt->fetchColumn();
    } catch (Exception $e) {
        // 数据库异常时不放行，按非超管处理
        return false;
    }
    $cache[$userId] = ($role === 'admin');
    return $cache[$userId];
}

/**
 * 要求当前用户拥有指定权限，否则提示并跳转后台首页。
 * （写法仿 app/admin/layout/admin-init.php 的门禁）
 */
function require_permission(string $permission): void {
    if (!has_permission($permission)) {
        set_flash(t('common_no_permission_page', '你没有权限访问该页面。'), 'error');
        redirect('/admin/');
    }
}

/**
 * 要求当前用户为超级管理员，否则提示并跳转后台首页。
 */
function require_super_admin(): void {
    if (!is_super_admin()) {
        set_flash(t('common_super_admin_only', '该功能仅最高管理员可用。'), 'error');
        redirect('/admin/');
    }
}

// ---------------------------------------------------------------------------
// 账号级登录锁定基础层（login.php 接入由任务 #11 负责）。
// 锁定提示文案应复用「账号或密码错误」同一文案，防止账号枚举。
// ---------------------------------------------------------------------------

// 连续失败阈值与锁定时长（分钟）
if (!defined('LOGIN_MAX_FAILS')) {
    define('LOGIN_MAX_FAILS', 5);
}
if (!defined('LOGIN_LOCK_MINUTES')) {
    define('LOGIN_LOCK_MINUTES', 15);
}

/**
 * 按账号定位用户（用户名或邮箱，不区分大小写，与 login.php 查询条件一致）。
 * 返回 ['id', 'login_fails', 'login_locked_until'] 或 null（不存在/异常）。
 */
function login_lock_find_user(string $account): ?array {
    $account = strtolower(trim($account));
    if ($account === '') {
        return null;
    }
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT id, login_fails, login_locked_until FROM users WHERE LOWER(username) = LOWER(:a1) OR LOWER(email) = LOWER(:a2) LIMIT 1");
        $stmt->execute([':a1' => $account, ':a2' => $account]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    } catch (Exception $e) {
        error_log('[login_lock] 查询失败: ' . $e->getMessage());
        return null;
    }
}

/**
 * 检查账号是否处于登录锁定状态。
 * 锁定未过期返回 true；锁定已过期则自动清零计数并返回 false。
 * 任何异常均不阻断登录（返回 false）。
 */
function login_lock_check(string $account): bool {
    $user = login_lock_find_user($account);
    if (!$user || empty($user['login_locked_until'])) {
        return false;
    }
    if (db_time($user['login_locked_until']) > time()) {
        return true;
    }
    // 锁定已过期：自动清零计数与锁定时间
    try {
        $db = get_db();
        $db->prepare("UPDATE users SET login_fails = 0, login_locked_until = NULL WHERE id = :id")
            ->execute([':id' => (int)$user['id']]);
    } catch (Exception $e) {
        error_log('[login_lock] 过期清零失败: ' . $e->getMessage());
    }
    return false;
}

/**
 * 记录一次登录失败：计数 +1，达到阈值（LOGIN_MAX_FAILS）时写入锁定期
 * （LOGIN_LOCK_MINUTES 分钟，沿用 banned_until 的 gmdate UTC 写法），
 * 并清零失败计数（锁到期后重新获得完整尝试次数）。
 */
function login_lock_hit(string $account): void {
    $user = login_lock_find_user($account);
    if (!$user) {
        return;
    }
    try {
        $db = get_db();
        $fails = (int)($user['login_fails'] ?? 0) + 1;
        if ($fails >= LOGIN_MAX_FAILS) {
            $lockedUntil = gmdate('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
            $db->prepare("UPDATE users SET login_fails = 0, login_locked_until = :until WHERE id = :id")
                ->execute([':until' => $lockedUntil, ':id' => (int)$user['id']]);
        } else {
            $db->prepare("UPDATE users SET login_fails = :fails WHERE id = :id")
                ->execute([':fails' => $fails, ':id' => (int)$user['id']]);
        }
    } catch (Exception $e) {
        error_log('[login_lock] 失败计数写入异常: ' . $e->getMessage());
    }
}

/**
 * 登录成功后清零失败计数与锁定状态。
 */
function login_lock_clear(string $account): void {
    $user = login_lock_find_user($account);
    if (!$user) {
        return;
    }
    try {
        $db = get_db();
        $db->prepare("UPDATE users SET login_fails = 0, login_locked_until = NULL WHERE id = :id")
            ->execute([':id' => (int)$user['id']]);
    } catch (Exception $e) {
        error_log('[login_lock] 清零失败: ' . $e->getMessage());
    }
}

/**
 * 检查用户是否被封禁
 */
function is_user_banned(int $userId): bool {
    if ($userId <= 0) return false;
    $db = get_db();
    $stmt = $db->prepare("SELECT status, banned_until FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) return false;
    if ($row['status'] !== 'banned') return false;
    if (empty($row['banned_until'])) return true;
    return db_time($row['banned_until']) > time();
}

/**
 * 检查用户是否被禁言
 */
function is_user_muted(int $userId): bool {
    if ($userId <= 0) return false;
    $db = get_db();
    $stmt = $db->prepare("SELECT status, muted_until FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) return false;
    if ($row['status'] !== 'muted') return false;
    if (empty($row['muted_until'])) return true;
    return db_time($row['muted_until']) > time();
}

/**
 * 格式化用户状态为中文
 */
function format_user_status(?string $status): string {
    switch ($status) {
        case 'active': return t('common_f78d03','正常');
        case 'muted':  return t('common_e0c932','禁言');
        case 'banned': return t('common_059962','封禁');
        default:       return t('common_f78d03','正常');
    }
}

/**
 * 格式化剩余时间（用于封禁/禁言倒计时）
 * 永久返回 null，已过期返回空字符串
 */
function format_remaining_time(?string $datetime): ?string {
    if (empty($datetime)) {
        return null;
    }
    $ts = db_time($datetime);
    $diff = $ts - time();
    if ($diff <= 0) {
        return '';
    }
    if ($diff < 60) {
        return t('common_2e9952','即将解禁');
    }

    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    $minutes = floor(($diff % 3600) / 60);

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . t('common_f8bb05',' 天');
    }
    if ($hours > 0) {
        $parts[] = $hours . t('common_8d383e',' 小时');
    }
    if ($minutes > 0 && $days < 1) {
        $parts[] = $minutes . t('common_5b0acd',' 分钟');
    }
    return t('common_8404ac','还剩 ') . implode(' ', $parts);
}

/**
 * 获取用户禁言提示信息（未禁言返回 null）
 */
function get_user_mute_message(int $userId): ?string {
    if ($userId <= 0 || !is_user_muted($userId)) {
        return null;
    }
    $db = get_db();
    $stmt = $db->prepare("SELECT muted_until, status_reason FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $until = !empty($row['muted_until']) ? date('Y-m-d H:i', db_time($row['muted_until'])) : t('common_409752','永久');
    $reason = !empty($row['status_reason']) ? t('common_0f93c2','原因：') . $row['status_reason'] : '';
    return t('common_34d8e1','你当前处于禁言状态（至 ') . $until . '）。' . $reason;
}

/**
 * 检查当前登录用户是否被封禁，若被封禁则强制登出并跳转
 */
function enforce_user_ban(): void {
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $userId = (int)$_SESSION['user_id'];

    // 自动解封：如果封禁已到期，恢复用户状态
    $db = get_db();
    $stmt = $db->prepare("SELECT status, banned_until FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if ($user && $user['status'] === 'banned' && !empty($user['banned_until'])) {
        $bannedUntil = db_time($user['banned_until']);
        if ($bannedUntil <= time()) {
            $db->prepare("UPDATE users SET status = 'active', banned_until = NULL, status_reason = '' WHERE id = :id")->execute([':id' => $userId]);
            unset($_SESSION['user']);
            return;
        }
    }

    if (!is_user_banned($userId)) {
        return;
    }

    $stmt = $db->prepare("SELECT username, banned_until, status_reason FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();

    $bannedUntilRaw = ($row && !empty($row['banned_until'])) ? $row['banned_until'] : null;
    $until = $bannedUntilRaw ? date('Y-m-d H:i', db_time($bannedUntilRaw)) : t('common_409752','永久');
    $reason = ($row && !empty($row['status_reason'])) ? $row['status_reason'] : '';

    // 清理 remember cookie，防止自动重新登录
    if (!empty($_COOKIE['forum_remember'])) {
        setcookie('forum_remember', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => COOKIE_SECURE,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // 清空会话并重新生成 session id，保留封禁信息用于展示
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['banned_info'] = [
        'username' => ($row && !empty($row['username'])) ? $row['username'] : '',
        'until' => $until,
        'until_raw' => $bannedUntilRaw,
        'reason' => $reason,
    ];
    redirect('/banned');
}

/**
 * 确保系统中至少存在一个管理员。
 * 用于兼容旧安装或异常安装场景：如果没有管理员，将最早注册的用户自动提升为管理员。
 */
function ensure_admin_exists(): void {
    // 低频检查：60 秒内不重复查询，避免每个请求都 COUNT(*) 全表
    static $checked = false;
    if ($checked) return;
    $checked = true;

    if (isset($_SESSION['_admin_check_ts']) && (time() - (int)$_SESSION['_admin_check_ts']) < 60) {
        return;
    }

    try {
        $db = get_db();
        $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($adminCount > 0) {
            $_SESSION['_admin_check_ts'] = time();
            return;
        }

        // 将最早注册的用户提升为管理员
        $firstUser = $db->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($firstUser) {
            $db->prepare("UPDATE users SET role = 'admin' WHERE id = :id")->execute([':id' => $firstUser]);
            // 清空当前用户缓存，使权限立即生效
            if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$firstUser) {
                unset($_SESSION['user']);
            }
        }
        unset($_SESSION['_admin_check_ts']); // 提升后下次重新确认
    } catch (Exception $e) {
        // 忽略数据库异常，避免页面崩溃
    }
}

/**
 * 要求管理员权限
 */
function require_admin(): void {
    require_login();
    if (!is_admin()) {
        set_flash(t('common_efcffa','你没有权限访问该页面。'), 'error');
        redirect('/');
    }
}

/**
 * 显示消息提示
 */
function show_message(string $message, string $type = 'info'): string {
    if ($type === 'success') {
        $class = 'alert-success';
    } elseif ($type === 'error') {
        $class = 'alert-error';
    } elseif ($type === 'warning') {
        $class = 'alert-warning';
    } else {
        $class = 'alert-info';
    }
    return '<div class="alert ' . $class . '">' . e($message) . '</div>';
}

/**
 * 获取闪现消息
 */
function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function set_flash(string $message, string $type = 'info'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

/**
 * 把数据库中的时间字符串（SQLite CURRENT_TIMESTAMP 返回 UTC）解析为时间戳
 */
function db_time(string $datetime): int {
    // 附加 UTC 标识，确保 strtotime 按 UTC 解析，避免受 PHP 时区影响产生偏移
    return strtotime($datetime . ' UTC');
}

/**
 * 将数据库中的 UTC 时间字符串转换为本地时区显示字符串
 * SQLite CURRENT_TIMESTAMP 返回 UTC，此函数配合 PHP date_default_timezone_set 使用
 */
function db_datetime(string $datetime, string $format = 'Y-m-d H:i:s'): string {
    $ts = db_time($datetime);
    return $ts > 0 ? date($format, $ts) : $datetime;
}

/**
 * 按指定时区格式化数据库 UTC 时间字符串
 * - 若不指定时区或时区无效，回退到 PHP 默认时区（与 db_datetime 行为一致）
 * - 时间底层以 UTC 解析，再转换到目标时区，避免服务器时区导致的偏移
 */
function db_datetime_tz(string $datetime, string $tz = '', string $format = 'Y-m-d H:i'): string {
    $ts = db_time($datetime);
    if ($ts <= 0) {
        return $datetime;
    }
    $tzObj = null;
    if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
        try {
            $tzObj = new DateTimeZone($tz);
        } catch (Exception $e) {
            $tzObj = null;
        }
    }
    if ($tzObj instanceof DateTimeZone) {
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone($tzObj);
        return $dt->format($format);
    }
    return date($format, $ts);
}

/**
 * 格式化时间
 */
function time_ago(string $datetime): string {
    $time = db_time($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) return t('common_9e6366','刚刚');
    if ($diff < 3600) return floor($diff / 60) . t('common_7230a5',' 分钟前');
    if ($diff < 86400) return floor($diff / 3600) . t('common_53119a',' 小时前');
    if ($diff < 604800) return floor($diff / 86400) . t('common_73a4bf',' 天前');
    return date('Y-m-d H:i', $time);
}

/**
 * 生成分页 HTML（对 baseUrl 做 HTML 转义，防止 XSS）
 */
function pagination(int $page, int $total, int $perPage, string $baseUrl): string {
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    // 防止 baseUrl 中已包含 &amp; 等 HTML 实体时产生双重转义
    $rawUrl = htmlspecialchars_decode($baseUrl, ENT_QUOTES);
    $safeUrl = e($rawUrl);
    $separator = strpos($rawUrl, '?') === false ? '?' : '&amp;';

    $html = '<div class="pagination">';

    // 上一页
    if ($page > 1) {
        $html .= '<a class="btn btn-secondary" href="' . $safeUrl . $separator . 'page=' . ($page - 1) . t('common_e3f311','">上一页</a>');
    } else {
        $html .= t('common_687c14','<span class="btn btn-disabled">上一页</span>');
    }

    // 页码按钮
    if ($totalPages > 1) {
        $html .= '<div class="page-numbers">';
        $rangeStart = max(1, $page - 2);
        $rangeEnd = min($totalPages, $page + 2);
        if ($rangeStart > 1) {
            $html .= '<a class="page-number" href="' . $safeUrl . $separator . 'page=1">1</a>';
            if ($rangeStart > 2) {
                $html .= '<span class="page-ellipsis">...</span>';
            }
        }
        for ($i = $rangeStart; $i <= $rangeEnd; $i++) {
            if ($i === $page) {
                $html .= '<span class="page-number active">' . $i . '</span>';
            } else {
                $html .= '<a class="page-number" href="' . $safeUrl . $separator . 'page=' . $i . '">' . $i . '</a>';
            }
        }
        if ($rangeEnd < $totalPages) {
            if ($rangeEnd < $totalPages - 1) {
                $html .= '<span class="page-ellipsis">...</span>';
            }
            $html .= '<a class="page-number" href="' . $safeUrl . $separator . 'page=' . $totalPages . '">' . $totalPages . '</a>';
        }
        $html .= '</div>';
    }

    $html .= t('common_376e39','<span class="page-info">第 ') . $page . ' / ' . $totalPages . t('common_11f2da',' 页，共 ') . $total . t('common_ae8127',' 条</span>');

    // 下一页
    if ($page < $totalPages) {
        $html .= '<a class="btn btn-secondary" href="' . $safeUrl . $separator . 'page=' . ($page + 1) . t('common_cc2835','">下一页</a>');
    } else {
        $html .= t('common_138baf','<span class="btn btn-disabled">下一页</span>');
    }
    $html .= '</div>';
    return $html;
}

/**
 * 获取站点根路径（支持子目录部署）。
 * 若 SITE_URL 已配置则取其 path，否则返回根 /。
 */
function site_base_path(): string {
    if (defined('SITE_URL') && SITE_URL !== '') {
        $path = parse_url(SITE_URL, PHP_URL_PATH) ?: '/';
        return rtrim($path, '/') . '/';
    }
    return '/';
}

/**
 * 根据「当前请求」推导站点根 URL（协议 + 访问域名/IP + 根路径）。
 *
 * 不读取 SITE_URL 配置——用于分享链接等需要跟随浏览器实际访问域名的场景：
 * 多域名/镜像部署时，用户从哪个域名进来，生成的链接就用哪个域名，保证点开即用。
 * 协议识别兼容反向代理（X-Forwarded-Proto / X-Forwarded-Scheme / Front-End-Https）。
 *
 * 返回值末尾不含斜杠；CLI 等非 HTTP 环境（无 HTTP_HOST）返回空字符串。
 */
function current_site_url(): string {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return '';
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SCHEME']) && strtolower($_SERVER['HTTP_X_FORWARDED_SCHEME']) === 'https')
        || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strcasecmp($_SERVER['HTTP_FRONT_END_HTTPS'], 'off') !== 0);
    $scheme = $isHttps ? 'https' : 'http';
    // 从脚本路径中剥离管理后台子目录，得到站点根路径
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    if ($scriptDir === '/' || $scriptDir === '\\') {
        $scriptDir = '';
    }
    // 递归移除末尾的 /admin 或 /api（可能嵌套多层，如 /admin/api）
    while (preg_match('#/(admin|api)$#i', $scriptDir)) {
        $scriptDir = preg_replace('#/(admin|api)$#i', '', $scriptDir);
    }
    return rtrim($scheme . '://' . $host . $scriptDir, '/');
}

/**
 * 获取站点完整 URL（含协议和主机，用于邮件、RSS 等外部场景）。
 *
 * 优先使用 SITE_URL 常量（对外发布的规范域名）；若为空则根据当前请求自动推导。
 * 注意：若站点配置了 SITE_URL 但希望通过「当前访问域名」生成链接（如分享），
 * 应改用 current_site_url()。
 *
 * 返回值末尾不含斜杠，例如 http://example.com 或 https://example.com/forum
 */
function site_absolute_url(): string {
    if (defined('SITE_URL') && SITE_URL !== '') {
        return rtrim(SITE_URL, '/');
    }
    $url = current_site_url();
    return $url !== '' ? $url : 'http://localhost';
}

/**
 * 获取头像 URL（如果没有则显示首字母占位）
 * 对自定义头像做 scheme 白名单校验，防止 javascript: 等 XSS
 */
function avatar_url(?string $avatar, string $username): string {
    if (!empty($avatar)) {
        // 仅允许 http/https 协议的外链，其它（javascript:/data:text/html 等）一律拒绝
        if (preg_match('#^https?://#i', $avatar)) {
            return e($avatar);
        }
        // data:image/ 允许（SVG 占位头像等）
        if (preg_match('#^data:image/#i', $avatar)) {
            return e($avatar);
        }
        // 本地上传的头像路径（如 uploads/avatars/xxx.jpg）
        if (preg_match('#^uploads/avatars/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|gif|webp)$#i', $avatar)) {
            return site_base_path() . ltrim(e($avatar), '/');
        }
        // 其它情况回退到默认占位头像
    }
    $initial = mb_substr($username, 0, 1, 'UTF-8');
    $colors = ['#3b82f6', '#10b981', '#6366f1', '#ef4444', '#8b5cf6', '#ec4899'];
    // abs() 防止 32 位 PHP crc32 返回负值导致数组下标越界
    $color = $colors[abs(crc32($username)) % count($colors)];
    return 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><rect width="40" height="40" fill="' . $color . '" rx="20"/><text x="50%" y="55%" dominant-baseline="middle" text-anchor="middle" fill="#fff" font-size="18" font-family="Arial,sans-serif">' . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') . '</text></svg>');
}

/**
 * 内容安全过滤（允许换行）
 */
function safe_content(?string $content): string {
    $content = e((string)$content);
    return nl2br($content, false);
}

/**
 * 从 Markdown 风格简单转换链接（可选）
 */
function linkify(?string $text): string {
    $text = e((string)$text);
    $pattern = '/(https?:\/\/[^\s<]+)/i';
    $replacement = '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>';
    return preg_replace($pattern, $replacement, $text);
}

/**
 * 确保 user_groups 表存在（兼容旧版数据库自动迁移）
 */
function ensure_user_groups(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = get_db();
        ddl_exec("CREATE TABLE IF NOT EXISTS user_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(50) UNIQUE NOT NULL,
            display_name VARCHAR(100) NOT NULL,
            min_points INTEGER DEFAULT 0,
            max_points INTEGER DEFAULT NULL,
            color VARCHAR(20) DEFAULT '#6366f1',
            icon VARCHAR(50) DEFAULT 'star',
            display_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $defaultGroups = [
            ['newbie', t('common_6e2595','新手上路'), 0, 49, '#94a3b8', 'seedling'],
            ['member', t('common_f6f8a3','初级会员'), 50, 199, '#6366f1', 'zap'],
            ['senior', t('common_8f8a33','中级会员'), 200, 999, '#10b981', 'award'],
            ['advanced', t('common_a8cef6','高级会员'), 1000, 4999, '#2563eb', 'star'],
            ['veteran', t('common_61684c','资深会员'), 5000, 9999, '#8b5cf6', 'crown'],
            ['legend', t('common_c5ba91','论坛元老'), 10000, NULL, '#ef4444', 'crown'],
        ];
        foreach ($defaultGroups as $g) {
            $stmt = ddl_prepare($db, "INSERT OR IGNORE INTO user_groups (name, display_name, min_points, max_points, color, icon, display_order) VALUES (:name, :display_name, :min, :max, :color, :icon, :order)");
            $stmt->execute([':name' => $g[0], ':display_name' => $g[1], ':min' => $g[2], ':max' => $g[3], ':color' => $g[4], ':icon' => $g[5], ':order' => $g[2]]);
        }

        // 迁移：将旧版橙色等级颜色更新为新版靛蓝色
        ddl_exec("UPDATE OR IGNORE user_groups SET color = '#2563eb' WHERE name = 'advanced' AND color IN ('#f59e0b', '#f97316', '#ea580c')");
    } catch (\Throwable $e) {
        // 忽略迁移错误，避免阻塞页面
    }
}

/**
 * 确保 roles 权限组表存在并初始化默认角色
 */
function ensure_roles_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = get_db();
        ddl_exec("CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(50) UNIQUE NOT NULL,
            display_name VARCHAR(100) NOT NULL,
            permissions TEXT NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $defaultRoles = [
            ['moderator', t('common_bbc6db','版主'), 'manage_posts,manage_replies,manage_users,manage_forums'],
            ['vip', t('common_a80a39','VIP用户'), ''],
            // 与 db.php 播种保持一致：两级管理员体系内置角色
            ['community_admin', t('common_community_admin','社区管理员'), 'admin_access,manage_posts,manage_replies,manage_reports,manage_ban_appeals,manage_user_dispose'],
        ];
        foreach ($defaultRoles as $role) {
            $stmt = ddl_prepare($db, "INSERT OR IGNORE INTO roles (name, display_name, permissions) VALUES (:name, :display_name, :permissions)");
            $stmt->execute([':name' => $role[0], ':display_name' => $role[1], ':permissions' => $role[2]]);
        }
    } catch (\Throwable $e) {
        // 忽略迁移错误，避免阻塞页面
    }
}

/**
 * 确保 user_roles 用户角色关联表存在
 */
function ensure_user_roles_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = get_db();
        ddl_exec("CREATE TABLE IF NOT EXISTS user_roles (
            user_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, role_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        )");
    } catch (\Throwable $e) {
        // 忽略迁移错误，避免阻塞页面
    }
}

/**
 * 确保 user_medals 用户勋章关联表存在
 */
function ensure_user_medals_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = get_db();
        ddl_exec("CREATE TABLE IF NOT EXISTS user_medals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            medal_id INTEGER NOT NULL,
            awarded_by INTEGER DEFAULT NULL,
            awarded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (medal_id) REFERENCES medals(id) ON DELETE CASCADE,
            FOREIGN KEY (awarded_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE(user_id, medal_id)
        )");
    } catch (\Throwable $e) {
        // 忽略迁移错误，避免阻塞页面
    }
}

/**
 * 确保存在默认勋章（使用 SVG 图标名，而非表情）
 */
function ensure_default_medals(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = get_db();
        ddl_exec("CREATE TABLE IF NOT EXISTS medals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(50) UNIQUE NOT NULL,
            display_name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '',
            color VARCHAR(20) DEFAULT '#3b82f6',
            icon VARCHAR(50) DEFAULT 'star',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $defaultMedals = [
            ['founding_member', t('common_c5ba91','论坛元老'), t('common_ddb711','论坛早期贡献者'), '#ef4444', 'crown'],
            ['moderator', t('common_bbc6db','版主'), t('common_fdda33','社区管理员'), '#6366f1', 'shield'],
            ['vip', t('common_a80a39','VIP用户'), t('common_a34c44','尊贵会员'), '#8b5cf6', 'diamond'],
            ['senior_member', t('common_61684c','资深会员'), t('common_165475','活跃资深用户'), '#10b981', 'award'],
            ['excellent_author', t('common_0fa3cf','优秀作者'), t('common_f2b6d2','内容贡献者'), '#f59e0b', 'star'],
        ];
        foreach ($defaultMedals as $m) {
            $stmt = ddl_prepare($db, "INSERT OR IGNORE INTO medals (name, display_name, description, color, icon) VALUES (:name, :display_name, :description, :color, :icon)");
            $stmt->execute([':name' => $m[0], ':display_name' => $m[1], ':description' => $m[2], ':color' => $m[3], ':icon' => $m[4]]);
        }
    } catch (Exception $e) {
        // 忽略迁移错误，避免阻塞页面
    }
}

/**
 * 确保私信相关表存在（安装中断时自动修复）
 */
function ensure_pm_tables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = get_db();
        ddl_exec("CREATE TABLE IF NOT EXISTS pm_conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user1_id INTEGER NOT NULL,
            user2_id INTEGER NOT NULL,
            last_message_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user1_id, user2_id)
        )");
        ddl_exec("CREATE TABLE IF NOT EXISTS pm_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL,
            sender_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES pm_conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        ddl_exec("CREATE INDEX IF NOT EXISTS idx_pm_messages_conv ON pm_messages(conversation_id, created_at)");
        ddl_exec("CREATE INDEX IF NOT EXISTS idx_pm_conv_user1 ON pm_conversations(user1_id)");
        ddl_exec("CREATE INDEX IF NOT EXISTS idx_pm_conv_user2 ON pm_conversations(user2_id)");
    } catch (\Throwable $e) {
        // 忽略迁移错误，避免阻塞页面
    }
}

/**
 * 根据积分获取用户等级组（Discuz 风格）
 * 返回数组包含：title, color, icon, name, min_points 等
 */
function get_user_group(int $points): array {
    static $cache = [];
    if (isset($cache[$points])) {
        return $cache[$points];
    }
    // 注意：不要在此调用 ensure_user_groups()（DDL）——
    // get_user_group 是纯查询，若在业务事务内首次触发 CREATE TABLE，
    // MySQL 会隐式提交当前事务，导致外层 commit() 报 "There is no active transaction"。
    // user_groups 表由 init_db / auto_migrate 保证存在，缺失时下面降级为默认等级。
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM user_groups WHERE min_points <= :points ORDER BY min_points DESC LIMIT 1");
        $stmt->execute([':points' => $points]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($group) {
            $result = [
                'title' => $group['display_name'],
                'name' => $group['name'],
                'color' => $group['color'],
                'icon' => $group['icon'],
                'min_points' => (int)$group['min_points'],
                'max_points' => $group['max_points'] !== null ? (int)$group['max_points'] : null,
            ];
            $cache[$points] = $result;
            return $result;
        }
    } catch (Exception $e) {
        // 降级到默认
    }
    $default = ['title' => t('common_6e2595','新手上路'), 'name' => 'newbie', 'color' => '#94a3b8', 'icon' => 'seedling', 'min_points' => 0, 'max_points' => 49];
    $cache[$points] = $default;
    return $default;
}

/**
 * 楼层中文标签（传统论坛风格）
 */
function floor_label(int $floor): string {
    switch ($floor) {
        case 1:
            return t('common_b239bb','楼主');
        case 2:
            return t('common_e95b5c','沙发');
        case 3:
            return t('common_f7a132','板凳');
        case 4:
            return t('common_fde815','地板');
        default:
            return $floor . '#';
    }
}

/**
 * 根据帖子数/积分获取用户等级头衔（兼容旧调用）
 * 现行为：仅按积分从 user_groups 表匹配，不再要求发帖数
 */
function user_title(int $postsCount, int $points = 0): array {
    return get_user_group($points);
}

/**
 * 生成唯一数字 UID
 * 规则：8 位数字，不依赖自增 id，注册时生成并保证唯一
 */
function generate_uid(): int {
    $db = get_db();
    // 从 1000 开始顺序递增，避免旧版 8 位随机 UID 干扰
    $stmt = $db->prepare("SELECT COALESCE(MAX(uid), 0) FROM users WHERE uid >= 1000 AND uid < 10000000");
    $stmt->execute();
    $maxUid = (int)$stmt->fetchColumn();
    return max(1000, $maxUid + 1);
}

/**
 * 通过 UID 获取用户
 */
function get_user_by_uid(int $uid): ?array {
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE uid = :uid LIMIT 1");
        $stmt->execute([':uid' => $uid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 为没有 uid 的旧用户补充分配 uid
 */
function backfill_user_uids(): int {
    $db = get_db();
    $stmt = $db->query("SELECT id FROM users WHERE uid IS NULL ORDER BY id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $count = 0;
    foreach ($rows as $userId) {
        $uid = generate_uid();
        $update = $db->prepare("UPDATE users SET uid = :uid WHERE id = :id");
        $update->execute([':uid' => $uid, ':id' => $userId]);
        $count++;
    }
    return $count;
}

/**
 * 增加用户积分与金币，并在等级发生变化时返回新的等级信息
 * 调用方需要自行处理事务（若在外部事务中调用，不要在此函数内 beginTransaction）
 *
 * @param string $type        积分类型：post_create / reply_create / reply_received / favorite_received / checkin / checkin_milestone / other
 * @param string $sourceType  来源类型：post / reply / checkin 等
 * @param int    $sourceId    来源 ID
 * @param string $description 积分说明
 * @param int    $coins       同时增加的金币数量
 */
function add_user_points(
    int $userId,
    int $points,
    bool $inTransaction = false,
    string $type = 'other',
    ?string $sourceType = null,
    ?int $sourceId = null,
    ?string $description = null,
    int $coins = 0
): ?array {
    if ($points <= 0 && $coins <= 0) {
        return null;
    }

    // 按类型上限截断积分
    $typeCap = get_points_daily_cap($type);
    if ($typeCap > 0) {
        $typeEarned = get_user_daily_points($userId, $type);
        $points = min($points, max(0, $typeCap - $typeEarned));
    }

    // 按每日总上限截断积分
    $totalEarned = get_user_daily_points($userId);
    $points = min($points, max(0, POINTS_DAILY_TOTAL_CAP - $totalEarned));

    if ($points <= 0 && $coins <= 0) {
        return null;
    }

    try {
        $db = get_db();
        $oldGroup = null;
        if (!$inTransaction) {
            $db->beginTransaction();
        }

        // 获取增加积分前的等级
        $stmt = $db->prepare("SELECT points FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $oldPoints = (int)$stmt->fetchColumn();
        $oldGroup = get_user_group($oldPoints);

        // 增加积分与金币
        $sets = [];
        $params = [':id' => $userId];
        if ($points !== 0) {
            $sets[] = 'points = points + :points';
            $params[':points'] = $points;
        }
        if ($coins !== 0) {
            $sets[] = 'coins = coins + :coins';
            $params[':coins'] = $coins;
        }
        if (!empty($sets)) {
            $stmt = $db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $stmt->execute($params);
        }

        // 写入积分/金币流水日志
        $stmt = $db->prepare("INSERT INTO user_points_log (user_id, points, coins, type, source_type, source_id, description) VALUES (:user_id, :points, :coins, :type, :source_type, :source_id, :description)");
        $stmt->execute([
            ':user_id' => $userId,
            ':points' => $points,
            ':coins' => $coins,
            ':type' => $type,
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
            ':description' => $description ?? '',
        ]);

        // 获取增加后的等级
        $stmt = $db->prepare("SELECT points FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $newPoints = (int)$stmt->fetchColumn();
        $newGroup = get_user_group($newPoints);

        if (!$inTransaction) {
            $db->commit();
        }

        unset($_SESSION['user']); // 刷新缓存

        if ($newGroup['name'] !== ($oldGroup['name'] ?? '')) {
            return $newGroup;
        }
        return null; // 等级未变化
    } catch (Exception $e) {
        if (!$inTransaction) {
            try { $db->rollBack(); } catch (Exception $ignored) {}
            error_log(t('common_94a49b','增加积分失败：') . $e->getMessage());
            return null;
        } else {
            // 外部事务由调用方负责回滚，直接抛出
            throw $e;
        }
    }
}

/**
 * 获取用户今日已获得的积分（按类型或总计）
 */
function get_user_daily_points(int $userId, ?string $type = null): int {
    try {
        $db = get_db();
        $todayStart = date('Y-m-d 00:00:00');
        if ($type) {
            $stmt = $db->prepare("SELECT COALESCE(SUM(points), 0) FROM user_points_log WHERE user_id = :user_id AND type = :type AND created_at >= :today_start");
            $stmt->execute([':user_id' => $userId, ':type' => $type, ':today_start' => $todayStart]);
        } else {
            $stmt = $db->prepare("SELECT COALESCE(SUM(points), 0) FROM user_points_log WHERE user_id = :user_id AND created_at >= :today_start");
            $stmt->execute([':user_id' => $userId, ':today_start' => $todayStart]);
        }
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 获取指定积分类型的每日上限（0 表示无上限）
 */
function get_points_daily_cap(string $type): int {
    $caps = [
        'post_create' => POINTS_DAILY_POST_CAP * POST_POINTS,
        'reply_create' => POINTS_DAILY_REPLY_CAP * REPLY_POINTS,
        'reply_received' => POINTS_DAILY_REPLY_RECEIVED_CAP * REPLY_RECEIVED_POINTS,
        'favorite_received' => POINTS_DAILY_FAVORITE_RECEIVED_CAP * FAVORITE_RECEIVED_POINTS,
        'checkin' => CHECKIN_MAX_POINTS,
        'checkin_milestone' => CHECKIN_MILESTONE_7_DAYS + CHECKIN_MILESTONE_30_DAYS,
    ];
    return $caps[$type] ?? 0;
}

/**
 * 检查用户今日是否已对指定来源获得过某类积分（用于防重复奖励）
 */
function has_earned_points_for_source(int $userId, string $type, string $sourceType, int $sourceId): bool {
    try {
        $db = get_db();
        $todayStart = date('Y-m-d 00:00:00');
        $stmt = $db->prepare("SELECT COUNT(*) FROM user_points_log WHERE user_id = :user_id AND type = :type AND source_type = :source_type AND source_id = :source_id AND created_at >= :today_start");
        $stmt->execute([
            ':user_id' => $userId,
            ':type' => $type,
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
            ':today_start' => $todayStart,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 获取用户积分/金币流水日志
 */
function get_user_points_log(int $userId, int $limit = 30): array {
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM user_points_log WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * 积分类型中文名称
 */
function format_points_type(string $type): string {
    $map = [
        'post_create' => t('common_ed95b8','发布帖子'),
        'reply_create' => t('common_411355','回复帖子'),
        'reply_received' => t('common_dfcc94','收到回复'),
        'favorite_received' => t('common_2399b4','帖子被收藏'),
        'checkin' => t('common_c2dad4','每日签到'),
        'checkin_milestone' => t('common_261494','签到里程碑'),
        'other' => t('common_1a26ed','其他'),
    ];
    return $map[$type] ?? $type;
}

/**
 * 更新用户最后活跃时间（节流：5 分钟内只写一次，容错）
 */
function update_last_active(): void {
    if (!is_logged_in()) return;
    // 节流：5 分钟内只写库一次，降低 SQLite 写压力
    $lastSync = $_SESSION['last_active_sync'] ?? 0;
    $now = time();
    if ($now - $lastSync < 300) {
        return;
    }
    // 累计在线时长：取本次同步与上次同步之间的真实间隔（封顶 1 小时，避免异常跳变）
    $elapsed = $lastSync > 0 ? max(0, min($now - $lastSync, 3600)) : 0;
    $_SESSION['last_active_sync'] = $now;
    try {
        $db = get_db();
        $stmt = $db->prepare("UPDATE users SET last_active = CURRENT_TIMESTAMP, last_ip = :ip, online_time = COALESCE(online_time, 0) + :inc WHERE id = :id");
        $stmt->execute([':id' => $_SESSION['user_id'], ':ip' => client_ip(), ':inc' => $elapsed]);
    } catch (Exception $e) {
        // 数据库异常不影响页面渲染
    }
}

/**
 * 将秒数格式化为「X 天 X 小时 X 分钟」形式（自动省略为 0 的时间单位）
 */
function format_duration(int $seconds): string {
    $seconds = max(0, (int)$seconds);
    if ($seconds < 60) {
        return t('common_duration_less_than_min', '不到 1 分钟');
    }
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $parts = [];
    if ($days > 0) {
        $parts[] = $days . t('common_unit_day', ' 天');
    }
    if ($hours > 0) {
        $parts[] = $hours . t('common_unit_hour', ' 小时');
    }
    if ($minutes > 0) {
        $parts[] = $minutes . t('common_unit_min', ' 分钟');
    }
    return implode(' ', $parts);
}

/**
 * 实时数据文件缓存（1 秒级，用于高频轮询接口防并发穿透）
 *
 * - TTL 内直接返回缓存，多用户并发轮询时每秒只执行一次真实计算
 * - 原子写（临时文件 + rename），避免并发请求读到半写文件
 * - callback 抛异常时返回 null 且不写缓存，保证失败后可重试
 *
 * @param string   $key      缓存键（建议包含影响输出的参数）
 * @param int      $ttl      缓存秒数
 * @param callable $callback 实际计算函数，返回可序列化数据
 * @return mixed
 */
function realtime_cache(string $key, int $ttl, callable $callback) {
    $cacheDir = APP_ROOT . 'data/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . '/rt_' . md5($key) . '.json';

    $raw = @file_get_contents($cacheFile);
    if ($raw !== false) {
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['expires'], $data['value']) && (int)$data['expires'] > time()) {
            return $data['value'];
        }
    }

    $value = null;
    try {
        $value = $callback();
    } catch (\Throwable $e) {
        return null;
    }

    $payload = json_encode(['expires' => time() + $ttl, 'value' => $value], JSON_UNESCAPED_UNICODE);
    if ($payload !== false) {
        $tmp = $cacheFile . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @rename($tmp, $cacheFile);
        }
    }
    return $value;
}

/**
 * 获取论坛统计信息
 */
function forum_stats(): array {
    // 60 秒文件缓存：论坛统计延迟 60 秒更新完全无感知，避免每次首页访问执行 3 次 COUNT/SUM
    $cacheFile = APP_ROOT . 'data/forum_stats.cache';
    if (is_file($cacheFile)) {
        // JSON 格式缓存；旧 serialize 格式文件 json_decode 失败返回 null，视为 miss 自然重建
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['time']) && (time() - (int)$cached['time']) < 60) {
            return $cached['stats'];
        }
    }

    $db = get_db();

    // 合并 4 次独立 COUNT 为 1 次查询
    $row = $db->query("SELECT
        (SELECT COUNT(*) FROM users) AS users,
        (SELECT COUNT(*) FROM posts) AS posts,
        (SELECT COUNT(*) FROM replies) AS replies,
        (SELECT COUNT(*) FROM forums) AS forums")->fetch();
    $stats = [
        'users'    => (int)$row['users'],
        'posts'    => (int)$row['posts'],
        'replies'  => (int)$row['replies'],
        'forums'   => (int)$row['forums'],
    ];

    // 最新注册用户
    $stmt = $db->query("SELECT id, username FROM users ORDER BY created_at DESC LIMIT 1");
    $stats['newest_user'] = $stmt->fetch();

    // 今日发帖：按 PHP 配置的本地时区计算，转换为 UTC 后与数据库比较
    $todayStartLocal = strtotime('today');
    $todayStartUtc = gmdate('Y-m-d H:i:s', $todayStartLocal);
    $yesterdayStartUtc = gmdate('Y-m-d H:i:s', strtotime('yesterday'));

    // 合并今日/昨日发帖查询
    $stmt = $db->prepare("SELECT
        SUM(CASE WHEN created_at >= :today THEN 1 ELSE 0 END) AS today_posts,
        SUM(CASE WHEN created_at >= :yesterday AND created_at < :today2 THEN 1 ELSE 0 END) AS yesterday_posts
    FROM posts");
    $stmt->execute([
        ':today' => $todayStartUtc,
        ':yesterday' => $yesterdayStartUtc,
        ':today2' => $todayStartUtc,
    ]);
    $postRow = $stmt->fetch();
    $stats['today_posts'] = (int)($postRow['today_posts'] ?? 0);
    $stats['yesterday_posts'] = (int)($postRow['yesterday_posts'] ?? 0);

    @file_put_contents($cacheFile, json_encode(['time' => time(), 'stats' => $stats], JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $stats;
}

/**
 * 获取版块列表（按分类分组）
 */
function get_forums_by_category(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $db = get_db();

    // 单次查询获取所有版块及其分类，避免 N+1 查询
    $stmt = $db->query("SELECT f.*, fc.id AS cat_id, fc.name AS cat_name, fc.display_order AS cat_display_order, fc.created_at AS cat_created_at,
            u.username AS last_post_username, p.title AS last_post_title,
            COALESCE(f.last_post_time, p.created_at) AS last_post_time
        FROM forum_categories fc
        LEFT JOIN forums f ON f.category_id = fc.id
        LEFT JOIN posts p ON f.last_post_id = p.id
        LEFT JOIN users u ON p.user_id = u.id OR p.user_id = u.uid
        ORDER BY fc.display_order ASC, fc.id ASC, f.display_order ASC, f.id ASC");
    // 注：last_post_time 优先取 forums.last_post_time（最后回复/最后活动时间），
    // 回退到帖子创建时间，避免回复旧帖后首页"最后发表"时间不更新
    $rows = $stmt->fetchAll();

    // 在 PHP 中按 category_id 分组
    $result = [];
    $catIndex = [];
    foreach ($rows as $row) {
        $catId = $row['cat_id'];
        if (!isset($catIndex[$catId])) {
            $catIndex[$catId] = count($result);
            $result[] = [
                'category' => [
                    'id' => $row['cat_id'],
                    'name' => $row['cat_name'],
                    'display_order' => $row['cat_display_order'],
                    'created_at' => $row['cat_created_at'],
                ],
                'forums' => [],
            ];
        }
        // 如果版块存在（非纯分类行），添加到分类下
        if ($row['id'] !== null) {
            $result[$catIndex[$catId]]['forums'][] = $row;
        }
    }
    $cache = $result;
    return $result;
}

/**
 * 获取单个版块信息
 */
function get_forum(int $forumId): ?array {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM forums WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $forumId]);
    $forum = $stmt->fetch();
    return $forum ?: null;
}

/**
 * 获取活跃公告
 */
function get_active_announcements(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $db = get_db();
    try {
        $stmt = $db->query("SELECT * FROM announcements WHERE is_active = 1 ORDER BY display_order ASC, created_at DESC");
        $cache = $stmt->fetchAll();
    } catch (\PDOException $e) {
        // 表不存在时自动创建（旧版数据库迁移）
        // 优先用 SQLSTATE 判断表不存在，字符串匹配作为后备
        $isTableMissing = ($e->getCode() === '42S02');
        if (!$isTableMissing) {
            $msg = $e->getMessage();
            $isTableMissing = (strpos($msg, 'no such table') !== false || strpos($msg, 'exist') !== false);
        }
        if ($isTableMissing) {
            ddl_exec("CREATE TABLE IF NOT EXISTS announcements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                is_active INTEGER DEFAULT 1,
                display_order INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $siteName = defined('SITE_NAME') ? SITE_NAME : '云界论坛';
            $stmt = $db->prepare("INSERT INTO announcements (title, content, is_active, display_order) VALUES (:title, :content, 1, 0)");
            $stmt->execute([
                ':title'   => '欢迎来到' . $siteName,
                ':content' => $siteName . '正式开站！欢迎大家注册交流。',
            ]);
        }
        $cache = [];
    }
    return $cache;
}

/**
 * 更新版块统计（帖子数等）
 */
function update_forum_stats(int $forumId): void {
    $db = get_db();
    $stmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE forum_id = :fid");
    $stmt->execute([':fid' => $forumId]);
    $threads = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM replies r JOIN posts p ON r.post_id = p.id WHERE p.forum_id = :fid");
    $stmt->execute([':fid' => $forumId]);
    $replies = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("UPDATE forums SET threads_count = :threads, posts_count = :posts WHERE id = :id");
    $stmt->execute([':threads' => $threads, ':posts' => $threads + $replies, ':id' => $forumId]);

    // 发帖/回复/删帖/删回复都会经过这里：立即失效首页统计缓存，保证"实时刷新"
    $statsCache = APP_ROOT . 'data/forum_stats.cache';
    if (is_file($statsCache)) {
        @unlink($statsCache);
    }
}

/**
 * 更新版块最后帖子信息
 */
function update_forum_last_post(int $forumId, int $postId): void {
    $db = get_db();
    $stmt = $db->prepare("UPDATE forums SET last_post_id = :pid, last_post_time = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->execute([':pid' => $postId, ':id' => $forumId]);
}

/**
 * 重新计算并更新版块最后帖子信息（基于当前实际数据）
 */
function refresh_forum_last_post(int $forumId): void {
    $db = get_db();
    $stmt = $db->prepare("SELECT id, created_at FROM posts WHERE forum_id = :fid ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([':fid' => $forumId]);
    $last = $stmt->fetch();
    $lastId = $last ? (int)$last['id'] : null;
    $lastTime = $last ? $last['created_at'] : null;
    $stmt = $db->prepare("UPDATE forums SET last_post_id = :pid, last_post_time = :ptime WHERE id = :fid");
    $stmt->execute([':pid' => $lastId, ':ptime' => $lastTime, ':fid' => $forumId]);
}

/**
 * 更新用户发帖统计
 */
function update_user_post_stats(int $userId): void {
    $db = get_db();
    $stmt = $db->prepare("UPDATE users SET posts_count = (SELECT COUNT(*) FROM posts WHERE user_id = :uid) WHERE id = :uid2");
    $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
}

/**
 * 重新计算并更新帖子回复数
 */
function update_post_replies_count(int $postId): void {
    $db = get_db();
    $stmt = $db->prepare("UPDATE posts SET replies_count = (SELECT COUNT(*) FROM replies WHERE post_id = :pid) WHERE id = :pid2");
    $stmt->execute([':pid' => $postId, ':pid2' => $postId]);
}

/**
 * 删除帖子并同步相关统计
 */
function delete_post(int $postId): bool {
    $db = get_db();
    $stmt = $db->prepare("SELECT forum_id, user_id FROM posts WHERE id = :id");
    $stmt->execute([':id' => $postId]);
    $postInfo = $stmt->fetch();
    if (!$postInfo) {
        return false;
    }
    $forumId = (int)$postInfo['forum_id'];
    $userId = (int)$postInfo['user_id'];

    $db->prepare("DELETE FROM posts WHERE id = :id")->execute([':id' => $postId]);
    if ($forumId > 0) {
        update_forum_stats($forumId);
        refresh_forum_last_post($forumId);
    }
    if ($userId > 0) {
        update_user_post_stats($userId);
    }
    return true;
}

/**
 * 删除回复并同步相关统计
 */
function delete_reply(int $replyId): bool {
    $db = get_db();
    $stmt = $db->prepare("SELECT r.post_id, p.forum_id, r.user_id FROM replies r JOIN posts p ON r.post_id = p.id WHERE r.id = :id");
    $stmt->execute([':id' => $replyId]);
    $replyInfo = $stmt->fetch();
    if (!$replyInfo) {
        return false;
    }
    $postId = (int)$replyInfo['post_id'];
    $forumId = (int)$replyInfo['forum_id'];

    $db->prepare("DELETE FROM replies WHERE id = :id")->execute([':id' => $replyId]);
    update_post_replies_count($postId);
    if ($forumId > 0) {
        update_forum_stats($forumId);
        refresh_forum_last_post($forumId);
    }
    return true;
}

/**
 * 获取未读站内信数量
 */
function unread_pm_count(): int {
    if (!is_logged_in()) return 0;
    $cacheKey = 'unread_pm_count_cache';
    $cacheTtl = 30;
    if (isset($_SESSION[$cacheKey]['time'], $_SESSION[$cacheKey]['value']) && time() - (int)$_SESSION[$cacheKey]['time'] < $cacheTtl) {
        return (int)$_SESSION[$cacheKey]['value'];
    }
    $db = get_db();
    $userId = $_SESSION['user_id'];
    // 使用子查询 + IN 替代 OR，便于命中索引
    $stmt = $db->prepare("SELECT COUNT(*) FROM pm_messages m
        WHERE m.is_read = 0 AND m.sender_id != :uid
        AND m.conversation_id IN (
            SELECT id FROM pm_conversations WHERE user1_id = :uid1
            UNION
            SELECT id FROM pm_conversations WHERE user2_id = :uid2
        )");
    $stmt->execute([':uid' => $userId, ':uid1' => $userId, ':uid2' => $userId]);
    $count = (int)$stmt->fetchColumn();
    $_SESSION[$cacheKey] = ['time' => time(), 'value' => $count];
    return $count;
}

/**
 * 清除未读站内信数量缓存
 */
function clear_unread_pm_cache(): void {
    unset($_SESSION['unread_pm_count_cache']);
}

/**
 * 发送消息通知
 */
function send_notification(int $userId, string $type, string $title, string $content = '', ?string $link = null): bool {
    if ($userId <= 0) return false;
    try {
        $db = get_db();
        $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, content, link, is_read, created_at) VALUES (:user_id, :type, :title, :content, :link, 0, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':user_id' => $userId,
            ':type'    => $type,
            ':title'   => $title,
            ':content' => $content,
            ':link'    => $link,
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 获取未读通知数量
 */
function get_unread_notification_count(int $userId = 0): int {
    if ($userId <= 0 && is_logged_in()) {
        $userId = (int)$_SESSION['user_id'];
    }
    if ($userId <= 0) return 0;
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0");
        $stmt->execute([':user_id' => $userId]);
        $count = (int)$stmt->fetchColumn();
        $cache[$userId] = $count;
        return $count;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 标记通知为已读
 */
function mark_notification_read(int $notificationId, int $userId): bool {
    try {
        $db = get_db();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id");
        $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 检查帖子是否被收藏
 */
function is_favorited(int $postId): bool {
    if (!is_logged_in()) return false;
    $db = get_db();
    $stmt = $db->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = :uid AND post_id = :pid");
    $stmt->execute([':uid' => $_SESSION['user_id'], ':pid' => $postId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * 根据版块名称推断最合适的图标关键字
 */
function suggest_forum_icon_key(string $name): string {
    $rules = [
        '公告' => 'announcement', '通知' => 'announcement', '官方' => 'announcement',
        '后端' => 'code', '服务器' => 'code', 'php' => 'code', 'python' => 'code',
        'java' => 'code', 'go' => 'code', 'rust' => 'code', 'node' => 'code',
        '代码' => 'code', '编程' => 'code', '开发' => 'code', '程序' => 'code',
        '前端' => 'design', 'css' => 'design', 'html' => 'design', 'javascript' => 'design',
        'js' => 'design', 'vue' => 'design', 'react' => 'design', 'ui' => 'design',
        '数据库' => 'database', 'mysql' => 'database', 'sqlite' => 'database',
        'redis' => 'database', 'sql' => 'database', 'mongo' => 'database',
        '硬件' => 'cpu', 'cpu' => 'cpu', '显卡' => 'cpu', '主板' => 'cpu',
        '网络' => 'globe', 'wifi' => 'globe', '路由' => 'globe', 'http' => 'globe',
        '云' => 'cloud', 'cloud' => 'cloud', '运维' => 'cloud',
        '安全' => 'shield', '加密' => 'shield', '漏洞' => 'shield',
        '游戏' => 'game', '电竞' => 'game', 'steam' => 'game', '网游' => 'game',
        '影视' => 'film', '电影' => 'film', '电视' => 'film', '动漫' => 'film',
        '音乐' => 'music', '歌曲' => 'music',
        '摄影' => 'camera', '照片' => 'camera', '拍照' => 'camera',
        '体育' => 'sport', '足球' => 'sport', '篮球' => 'sport',
        '写作' => 'pen', '小说' => 'pen', '文学' => 'pen', '文章' => 'pen',
        '读书' => 'book', '书籍' => 'book', '阅读' => 'book',
        '资源' => 'gift', '分享' => 'gift', '下载' => 'gift',
        '闲聊' => 'chat', '灌水' => 'chat', '聊天' => 'chat', '交友' => 'chat',
        '咖啡' => 'coffee', '生活' => 'coffee', '日常' => 'coffee',
        '情感' => 'heart', '心情' => 'heart',
        '反馈' => 'help', '建议' => 'help', '意见' => 'help', '帮助' => 'help',
        '想法' => 'lightbulb', '创意' => 'lightbulb', '灵感' => 'lightbulb',
        '热门' => 'fire', '精华' => 'star', '推荐' => 'star',
        '工具' => 'tool', '软件' => 'tool',
        '电脑' => 'desktop', '笔记本' => 'desktop',
        '用户' => 'users', '会员' => 'users', '新人' => 'users',
        '邮件' => 'mail', '邮箱' => 'mail',
        '火箭' => 'rocket', '创业' => 'rocket', '项目' => 'rocket',
        '地图' => 'map', '位置' => 'map',
        '通知' => 'bell', '提醒' => 'bell',
        '设置' => 'cog', '配置' => 'cog',
        '活动' => 'party', '节日' => 'party', '庆祝' => 'party',
        '书签' => 'bookmark', '收藏' => 'bookmark',
        '标签' => 'flag', '标记' => 'flag',
    ];

    $lower = mb_strtolower($name, 'UTF-8');
    foreach ($rules as $word => $icon) {
        if (mb_strpos($lower, $word) !== false) {
            return $icon;
        }
    }
    return 'folder';
}

/**
 * 获取客户端 IP（与流量埋点口径一致：直连 REMOTE_ADDR，不信任代理转发头，保护隐私与安全）
 */
function client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '::1') {
        $ip = '127.0.0.1';
    }
    return $ip;
}

/**
 * 记录页面访问（流量埋点）
 * 在 includes/header.php 中调用，每个页面访问都会记录。
 * 使用 IP 哈希（非明文）保护隐私，按日期+IP 去重统计 UV。
 *
 * 准确性设计（v1.5.2 优化）：
 * - PV：每次页面访问都精确累加，不再被节流吞掉（修复同一会话快速翻页时 PV 严重低估）；
 * - UV：按「会话 × 小时」去重，每小时每会话最多计 1，跨小时边界也不遗漏；
 * - 爬虫：UA 命中已知爬虫/命令行抓取时直接忽略，避免机器人刷高 PV/UV；
 * - 地域：已配置 IP 库（data/ip2region/ip2region.xdb）时，离线查询 IP 归属地
 *   随访客明细行记录（traffic_visitors.region），仅存归属地不存明文 IP；
 * - 访客明细行（traffic_visitors）：仍按 60s 会话节流以控制写压力，
 *   仅影响「热页/设备 views、在线人数滞后 ≤60s」等次要指标，主指标 PV/UV 保持精确。
 */
function track_visit(): void {
    static $tracked = false;
    if ($tracked) return; // 防止同一请求多次记录
    $tracked = true;

    // 排除爬虫/非浏览器 UA（准确性：真实访客口径，避免机器人刷高 PV/UV）
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    if (is_crawler_ua($ua)) return;

    try {
        $db = get_db();

        // 获取客户端 IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($ip === '::1') $ip = '127.0.0.1';
        $ipHash = hash('sha256', $ip);

        // 本地日期
        $visitDate = date('Y-m-d');
        $visitHour = (int)date('G');

        // 当前页面路径
        $page = substr($_SERVER['REQUEST_URI'] ?? '/', 0, 255);

        // 来源（Referrer）：仅记录站外来源，本站内跳转视为直接访问
        $referrer = '';
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $ref = parse_url($_SERVER['HTTP_REFERER']);
            $refHost = $ref['host'] ?? '';
            $currentHost = $_SERVER['HTTP_HOST'] ?? '';
            if ($refHost && $refHost !== $currentHost) {
                $referrer = substr($refHost, 0, 500);
            }
        }

        // 设备类型检测
        $uaLower = strtolower($ua);
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobile))/i', $uaLower)) {
            $deviceType = 'tablet';
        } elseif (preg_match('/(iphone|ipod|android|blackberry|opera mini|windows phone|iemobile|mobile)/i', $uaLower)) {
            $deviceType = 'mobile';
        } else {
            $deviceType = 'desktop';
        }

        // ========== PV：每次访问都精确累加（不受节流影响）==========
        $upsertClause2 = get_db_driver()->upsertConflictClause('stat_date, stat_hour');
        $stmt2 = $db->prepare("INSERT INTO traffic_stats (stat_date, stat_hour, page_views, unique_visitors)
            VALUES (:date, :hour, 1, 0)
            {$upsertClause2} page_views = page_views + 1");
        $stmt2->execute([':date' => $visitDate, ':hour' => $visitHour]);

        // ========== UV：按「会话 × 小时」去重，跨小时不遗漏 ==========
        $uvKey = 'tv_uv_' . $visitDate . '_' . $visitHour;
        if (!isset($_SESSION[$uvKey])) {
            $_SESSION[$uvKey] = true;
            $stmt3 = $db->prepare("UPDATE traffic_stats SET unique_visitors = unique_visitors + 1
                WHERE stat_date = :date AND stat_hour = :hour");
            $stmt3->execute([':date' => $visitDate, ':hour' => $visitHour]);
        }

        // ========== 访客明细行：60s 会话节流（降低写压力）==========
        // 说明：该行服务于「在线人数 / 最近访客 / 热页与设备分布」等次要指标，
        // 节流仅使这些指标滞后 ≤60s 或按会话窗口近似，不影响 PV/UV 精确性。
        if (isset($_SESSION['tv_last_ts']) && (time() - (int)$_SESSION['tv_last_ts']) < 60) {
            return;
        }

        // 插入或更新访客记录（按 visit_date + ip_hash 去重）
        $upsertClause = get_db_driver()->upsertConflictClause('visit_date, ip_hash');
        // IP 归属地：离线查询（ip2region xdb），无数据文件时返回 null 不记录
        $region = ip_region_query($ip);
        $stmt = $db->prepare("INSERT INTO traffic_visitors (visit_date, ip_hash, user_agent, page, referrer, device_type, region, first_visit, last_visit, views)
            VALUES (:date, :ip, :ua, :page, :ref, :device, :region, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1)
            {$upsertClause}
                last_visit = CURRENT_TIMESTAMP,
                page = :page2,
                user_agent = :ua2,
                referrer = :ref2,
                device_type = :device2,
                region = :region2,
                views = views + 1");
        $stmt->execute([
            ':date'    => $visitDate,
            ':ip'      => $ipHash,
            ':ua'      => $ua,
            ':page'    => $page,
            ':ref'     => $referrer,
            ':device'  => $deviceType,
            ':region'  => $region ?? '',
            ':page2'   => $page,
            ':ua2'     => $ua,
            ':ref2'    => $referrer,
            ':device2' => $deviceType,
            ':region2' => $region ?? '',
        ]);

        // 记录本次写入时间，用于访客明细行节流
        $_SESSION['tv_last_ts'] = time();
    } catch (Exception $e) {
        // 流量统计失败不影响页面渲染
    }
}

/**
 * 判断 UA 是否为爬虫 / 机器人 / 命令行抓取
 * 命中返回 true（流量统计应忽略这类请求，保证真实访客口径）。
 */
function is_crawler_ua(string $ua): bool {
    if ($ua === '') return true; // 无 UA 的请求（多为脚本/非浏览器客户端）不计入
    return (bool)preg_match(
        '~(?:googlebot|bingbot|baiduspider|yandexbot|sogou|bytespider|semrushbot|ahrefsbot|mj12bot|dotbot|petalbot|applebot|duckduckbot|facebookexternalhit|twitterbot|bingpreview|preview|bot|crawler|spider|slurp|scrapy|curl|wget|python-requests|urllib|httpclient|headless|phantomjs|lighthouse|pingdom)~i',
        $ua
    );
}

/**
 * 规范化版块图标：无效时根据名称自动推断
 */
function normalize_forum_icon(?string $key, ?string $name = null): string {
    $key = trim((string)$key);
    $validKeys = array_keys(forum_icon_options());
    if ($key !== '' && in_array($key, $validKeys, true)) {
        return $key;
    }
    return ($name !== null && trim($name) !== '') ? suggest_forum_icon_key($name) : 'folder';
}

/**
 * 获取版块图标的 SVG 标记（基于关键字，不再使用 emoji）
 *
 * @param string|null $key  图标关键字（code/design/database/coffee/book/announcement/lightbulb 等）
 * @param int         $size SVG 尺寸（px）
 * @param string|null $name 版块名称（当 key 无效时用于智能推断）
 */
function forum_icon(?string $key, int $size = 24, ?string $name = null): string {
    $key = normalize_forum_icon($key, $name);

    $icons = [
        'code'         => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'design'       => '<circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
        'database'     => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'book'         => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'coffee'       => '<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>',
        'lightbulb'    => '<line x1="9" y1="18" x2="15" y2="18"/><line x1="10" y1="22" x2="14" y2="22"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14"/>',
        'announcement' => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'fire'         => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
        'star'         => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'game'         => '<line x1="6" y1="11" x2="10" y2="11"/><line x1="8" y1="9" x2="8" y2="13"/><line x1="15" y1="12" x2="15.01" y2="12"/><line x1="18" y1="10" x2="18.01" y2="10"/><path d="M17.32 5H6.68a4 4 0 0 0-3.978 3.59c-.006.052-.01.101-.017.152C2.604 9.416 2 14.456 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.414-1.414A2 2 0 0 1 9.828 16h4.344a2 2 0 0 1 1.414.586L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.544-.604-6.584-.685-7.258-.007-.05-.011-.1-.017-.152A4 4 0 0 0 17.32 5z"/>',
        'film'         => '<rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><line x1="7" y1="2" x2="7" y2="22"/><line x1="17" y1="2" x2="17" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="2" y1="7" x2="7" y2="7"/><line x1="2" y1="17" x2="7" y2="17"/><line x1="17" y1="17" x2="22" y2="17"/><line x1="17" y1="7" x2="22" y2="7"/>',
        'camera'       => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'sport'        => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="2" x2="12" y2="22"/><path d="M2 12h20"/>',
        'pen'          => '<path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/>',
        'desktop'      => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
        'globe'        => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'rocket'       => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91 0z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
        'help'         => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'party'        => '<path d="M5.8 11.3 2 22l10.7-3.79"/><path d="M4 3h.01"/><path d="M22 8h.01"/><path d="M15 2h.01"/><path d="M22 20h.01"/><path d="M22 2 4.5 12.5"/><path d="m22 8-8.5 8.5"/><path d="m22 14-7 7"/><path d="M4 14l.01.01"/><circle cx="6" cy="6" r=".5"/><circle cx="14" cy="4" r=".5"/><circle cx="21" cy="11" r=".5"/><circle cx="18" cy="18" r=".5"/>',
        'folder'       => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'chat'         => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'tool'         => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'heart'        => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'users'        => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'shield'       => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'gift'         => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
        'mail'         => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'bell'         => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'cog'          => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'music'        => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'cloud'        => '<path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>',
        'cpu'          => '<rect x="4" y="4" width="16" height="16" rx="2" ry="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>',
        'wifi'         => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
        'bookmark'     => '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',
        'flag'         => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
        'map'          => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>',
    ];

    $path = isset($icons[$key]) ? $icons[$key] : $icons['folder'];
    return '<svg class="forum-svg-icon" xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}

/**
 * 通用 UI 图标（用于空状态、统计、按钮等场景）
 *
 * @param string $name 图标名称：search/file-text/message-star/folder-open/eye/mail/check/lock/users/calendar等
 * @param int    $size SVG 尺寸（px）
 */
function ui_icon(string $name, int $size = 24): string {
    $icons = [
        'search'      => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
        'file-text'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/>',
        'message'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'star'        => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'folder-open' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'eye'         => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'mail'        => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'check'       => '<polyline points="20 6 9 17 4 12"/>',
        'lock'        => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'users'       => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'pen'         => '<path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/>',
        'chat'        => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'party'       => '<path d="M5.8 11.3 2 22l10.7-3.79"/><path d="M4 3h.01"/><path d="M22 8h.01"/><path d="M15 2h.01"/><path d="M22 20h.01"/><path d="M22 2 4.5 12.5"/><path d="m22 8-8.5 8.5"/><path d="m22 14-7 7"/><path d="M4 14l.01.01"/><circle cx="6" cy="6" r=".5"/><circle cx="14" cy="4" r=".5"/><circle cx="21" cy="11" r=".5"/><circle cx="18" cy="18" r=".5"/>',
        'heart'       => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'bell'        => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'arrow-left'  => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'zap'         => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'palette'     => '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
        'shield'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'rocket'      => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
        'seedling'    => '<path d="M12 2v20"/><path d="M12 7c-4.97 0-9 4.03-9 9a9 9 0 0 0 9-9z"/><path d="M12 7c4.97 0 9 4.03 9 9a9 9 0 0 1-9-9z"/>',
        'award'       => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
        'crown'       => '<path d="m2 4 3 12h14l3-12-6 7-4-7-4 7-6-7zm3 16h14"/>',
        'diamond'     => '<path d="M6 2h12l6 8-12 12L0 10l6-8z"/><path d="M12 22V10"/><path d="M12 10 0 10"/><path d="M12 10 24 10"/>',
        'trophy'      => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M9 5v7a3 3 0 0 0 6 0V5"/><path d="M12 22v-5"/>',
        'clock'       => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>',
        'globe'       => '<circle cx="12" cy="12" r="9"/><line x1="3" y1="12" x2="21" y2="12"/><path d="M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/>',
        'log-in'      => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
    ];
    $path = $icons[$name] ?? $icons['file-text'];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}

/**
 * 获取所有可用版块图标的键名与中文标签
 */
function forum_icon_options(): array {
    return [
        'code'         => t('common_33246d','代码'),
        'design'       => t('common_eb9375','设计'),
        'database'     => t('common_f4dbbc','数据库'),
        'book'         => t('common_2b1e69','书籍'),
        'coffee'       => t('common_db4224','咖啡'),
        'lightbulb'    => t('common_e77290','想法'),
        'announcement' => t('common_3f9569','公告'),
        'fire'         => t('common_61bf13','热门'),
        'star'         => t('common_7de02c','精华'),
        'game'         => t('common_2305ee','游戏'),
        'film'         => t('common_4c83e1','影视'),
        'camera'       => t('common_dc2d97','摄影'),
        'sport'        => t('common_82d0f5','体育'),
        'pen'          => t('common_2e33ad','写作'),
        'desktop'      => t('common_ec87a4','电脑'),
        'globe'        => t('common_0cbda6','网络'),
        'rocket'       => t('common_9171c7','火箭'),
        'help'         => t('common_adf465','帮助'),
        'party'        => t('common_1ed10b','庆祝'),
        'folder'       => t('common_46ecac','文件夹'),
        'chat'         => t('common_5358b2','聊天'),
        'tool'         => t('common_a72ef1','工具'),
        'heart'        => t('common_8e1145','生活'),
        'users'        => t('common_9ba763','用户'),
        'shield'       => t('common_8e662a','安全'),
        'gift'         => t('common_825109','礼物'),
        'mail'         => t('common_1c8e46','邮件'),
        'bell'         => t('common_7a66c0','通知'),
        'cog'          => t('common_7debf9','设置'),
        'music'        => t('common_afb3c4','音乐'),
        'cloud'        => t('common_565481','云端'),
        'cpu'          => t('common_b4cd99','硬件'),
        'wifi'         => t('common_6fb2d6','网络连接'),
        'bookmark'     => t('common_820f5b','书签'),
        'flag'         => t('common_c51524','标记'),
        'map'          => t('common_46e1ef','地图'),
    ];
}

/**
 * 获取表情列表（用于回复编辑器）
 */
function get_emoji_list(): array {
    return [
        ['code' => '😀', 'name' => t('common_cdd57d','微笑')],
        ['code' => '😁', 'name' => t('common_6a50f9','大笑')],
        ['code' => '😂', 'name' => t('common_f721e6','笑哭')],
        ['code' => '🤣', 'name' => t('common_e6dcd2','捧腹大笑')],
        ['code' => '😃', 'name' => t('common_d946da','开心')],
        ['code' => '😄', 'name' => t('common_04dbf1','露齿笑')],
        ['code' => '😅', 'name' => t('common_4743fb','汗')],
        ['code' => '😆', 'name' => t('common_642102','眯眼笑')],
        ['code' => '😉', 'name' => t('common_e9e777','眨眼')],
        ['code' => '😊', 'name' => t('common_cdd57d','微笑')],
        ['code' => '🙂', 'name' => t('common_7470fb','略微笑')],
        ['code' => '🙃', 'name' => t('common_92ccf3','倒脸')],
        ['code' => '😋', 'name' => t('common_e3e0d5','美味')],
        ['code' => '😎', 'name' => t('common_da008b','酷')],
        ['code' => '😍', 'name' => t('common_6f9fa8','花痴')],
        ['code' => '😘', 'name' => t('common_ac7e15','飞吻')],
        ['code' => '🥰', 'name' => t('common_03637e','害羞')],
        ['code' => '😗', 'name' => t('common_5fdb82','亲吻')],
        ['code' => '😙', 'name' => t('common_06226d','亲亲')],
        ['code' => '😚', 'name' => t('common_a896a6','腼腆')],
        ['code' => '🙏', 'name' => t('common_3b2fa1','合十')],
        ['code' => '🤝', 'name' => t('common_5052e6','握手')],
        ['code' => '👍', 'name' => t('common_e07f30','点赞')],
        ['code' => '👎', 'name' => t('common_f42201','踩')],
        ['code' => '👊', 'name' => t('common_d116db','拳头')],
        ['code' => '✊', 'name' => t('common_817c4e','举起拳头')],
        ['code' => '👏', 'name' => t('common_34acfb','鼓掌')],
        ['code' => '🙌', 'name' => t('common_667158','欢呼')],
        ['code' => '👐', 'name' => t('common_20e78a','张开手')],
        ['code' => '🤲', 'name' => t('common_9612f7','双手合十')],
        ['code' => '😢', 'name' => t('common_f0f1f8','哭')],
        ['code' => '😭', 'name' => t('common_886124','大哭')],
        ['code' => '😤', 'name' => t('common_0cfa09','生气')],
        ['code' => '😡', 'name' => t('common_afa04b','愤怒')],
        ['code' => '🤔', 'name' => t('common_a6c149','思考')],
        ['code' => '😴', 'name' => t('common_638026','睡觉')],
        ['code' => '😷', 'name' => t('common_4f75d7','口罩')],
        ['code' => '🤒', 'name' => t('common_7b2a34','生病')],
        ['code' => '🤢', 'name' => t('common_7a9c39','恶心')],
        ['code' => '🤮', 'name' => t('common_049e98','呕吐')],
        ['code' => '🥳', 'name' => t('common_1ed10b','庆祝')],
        ['code' => '🥺', 'name' => t('common_3266a5','可怜')],
        ['code' => '😱', 'name' => t('common_c114c7','惊吓')],
        ['code' => '😨', 'name' => t('common_8f9a55','害怕')],
        ['code' => '🤗', 'name' => t('common_44b04a','拥抱')],
        ['code' => '🤭', 'name' => t('common_e997dc','捂嘴')],
        ['code' => '🤫', 'name' => t('common_67ce17','安静')],
        ['code' => '🤥', 'name' => t('common_df46bd','撒谎')],
        ['code' => '😏', 'name' => t('common_a862fc','狡猾')],
        ['code' => '😒', 'name' => t('common_c748d9','无语')],
        ['code' => '🙄', 'name' => t('common_558b5c','白眼')],
        ['code' => '😬', 'name' => t('common_181e01','尴尬')],
        ['code' => '🤨', 'name' => t('common_185088','怀疑')],
        ['code' => '❤️', 'name' => t('common_a6f81e','红心')],
        ['code' => '💔', 'name' => t('common_25bb52','心碎')],
        ['code' => '💖', 'name' => t('common_836bd3','闪光心')],
        ['code' => '💯', 'name' => t('common_ae4225','一百分')],
        ['code' => '🔥', 'name' => t('common_efb262','火')],
        ['code' => '💩', 'name' => t('common_291179','便便')],
        ['code' => '🎉', 'name' => t('common_1ed10b','庆祝')],
        ['code' => '✨', 'name' => t('common_696737','闪光')],
        ['code' => '👀', 'name' => t('common_f3a75d','眼睛')],
        ['code' => '🐶', 'name' => t('common_2fcbe7','狗')],
        ['code' => '🐱', 'name' => t('common_e10bd9','猫')],
        ['code' => '🌹', 'name' => t('common_62e267','玫瑰')],
        ['code' => '🌈', 'name' => t('common_cd7881','彩虹')],
        ['code' => '🌞', 'name' => t('common_89da31','太阳')],
        ['code' => '🌙', 'name' => t('common_a6a01d','月亮')],
        ['code' => '⭐', 'name' => t('common_81619a','星星')],
    ];
}

/**
 * 简易 BBCode 解析
 * 对 [url] 和 [img] 中的 URL 做 scheme 白名单校验，防止 javascript: 等 XSS
 */
function bbcode(?string $text): string {
    $text = e((string)$text);
    // htmlspecialchars 把 [ ] 编码成了 &amp;#91; / &amp;#93;，先把它们还原才能解析 BBCode
    $text = str_replace(['&amp;#91;', '&amp;#93;'], ['[', ']'], $text);
    // 基础标签（已转义，正则替换安全）
    // [bi] 粗斜体：bold + italic 组合标签
    $text = preg_replace('/\[bi\](.*?)\[\/bi\]/i', '<strong><em>$1</em></strong>', $text);
    $text = preg_replace('/\[b\](.*?)\[\/b\]/i', '<strong>$1</strong>', $text);
    $text = preg_replace('/\[i\](.*?)\[\/i\]/i', '<em>$1</em>', $text);
    $text = preg_replace('/\[u\](.*?)\[\/u\]/i', '<u>$1</u>', $text);
    $text = preg_replace('/\[s\](.*?)\[\/s\]/i', '<del>$1</del>', $text);
    $text = preg_replace('/\[code\](.*?)\[\/code\]/is', '<pre><code>$1</code></pre>', $text);
    // [quote] 支持嵌套：迭代解析，从内到外逐层展开（最多 10 层）
    for ($qi = 0; $qi < 10; $qi++) {
        $next = preg_replace('/\[quote\](.*?)\[\/quote\]/is', '<blockquote class="quote-block">$1</blockquote>', $text);
        if ($next === $text) {
            break;
        }
        $text = $next;
    }
    $text = preg_replace('/\[color=([#a-zA-Z0-9]+)\](.*?)\[\/color\]/i', '<span style="color:$1">$2</span>', $text);
    // [size] 限制 8-36px
    $text = preg_replace_callback('/\[size=([0-9]+)\](.*?)\[\/size\]/i', function($m) {
        $size = max(8, min(36, (int)$m[1]));
        return '<span style="font-size:' . $size . 'px">' . $m[2] . '</span>';
    }, $text);
    // [img] 支持外链与本地上传图片
    $text = preg_replace_callback('/\[img\](.*?)\[\/img\]/i', function($m) {
        // 去除首尾空白；并还原 e() 转义出的实体（含用户粘贴时已带 &amp; 的双重转义），
        // 避免查询串中的 & 被双重转义后图片地址失效
        $url = trim($m[1]);
        for ($i = 0; $i < 3; $i++) {
            $decoded = str_ireplace(
                ['&amp;', '&quot;', '&lt;', '&gt;', '&#039;', '&#39;'],
                ['&', '"', '<', '>', "'", "'"],
                $url
            );
            if ($decoded === $url) break;
            $url = $decoded;
        }

        // 本地上传图片：补全站点基础路径，避免在 /admin/ 等子路径页面下解析错误
        if (preg_match('#^uploads/images/[a-zA-Z0-9_./-]+$#i', $url)) {
            $url = site_base_path() . ltrim($url, '/');
            $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            // 显示预览图，点击查看原图（灯箱通过 data-lightbox 触发）
            return '<img src="' . $escapedUrl . '" alt="" class="bbcode-image" data-lightbox loading="lazy">';
        }

        // 显式声明其它协议（javascript:、data: 等）一律拒绝，防止 XSS
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^https?://#i', $url)) {
            return t('common_8258df','[图片链接不合法]');
        }
        // 无协议地址自动补全 https://（如 //xxx.com/a.jpg、www.xxx.com/a.jpg）
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        // 重新转义后输出，保证属性内 & 等字符符合 HTML 规范
        // 显示预览图，点击查看原图（灯箱通过 data-lightbox 触发）
        $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return '<img src="' . $escapedUrl . '" alt="" class="bbcode-image" data-lightbox loading="lazy">';
    }, $text);
    // [url]URL[/url]：无协议地址自动补全 https://
    $text = preg_replace_callback('/\[url\](.*?)\[\/url\]/i', function($m) {
        $url = trim($m[1]);
        // 显式声明其它协议（javascript: 等）一律拒绝，防止 XSS
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^https?://#i', $url) && strpos($url, '//') !== 0) {
            return $m[0];
        }
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (!preg_match('#^https?://#i', $url) && !preg_match('#^mailto:#i', $url) && strpos($url, '/') !== 0) {
            $url = 'https://' . $url;
        }
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>';
    }, $text);
    // [url=...] 同理；显示文字留空时自动使用链接地址，避免出现空链接
    $text = preg_replace_callback('/\[url=(.*?)\](.*?)\[\/url\]/i', function($m) {
        $url = trim($m[1]);
        $label = trim($m[2]);
        // 显式声明其它协议（javascript: 等）一律拒绝，防止 XSS
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^https?://#i', $url) && strpos($url, '//') !== 0) {
            return $label;
        }
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (!preg_match('#^https?://#i', $url) && !preg_match('#^mailto:#i', $url) && strpos($url, '/') !== 0) {
            $url = 'https://' . $url;
        }
        if ($label === '') {
            $label = $url;
        }
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
    }, $text);
    // 保护已生成的 <img> 和 <a> 标签，避免自动链接破坏它们
    $placeholders = [];
    $text = preg_replace_callback('/<img[^>]*>/i', function($m) use (&$placeholders) {
        $key = '<!--IMG' . count($placeholders) . '-->';
        $placeholders[$key] = $m[0];
        return $key;
    }, $text);
    $text = preg_replace_callback('/<a\b[^>]*>.*?<\/a>/i', function($m) use (&$placeholders) {
        $key = '<!--A' . count($placeholders) . '-->';
        $placeholders[$key] = $m[0];
        return $key;
    }, $text);
    // 链接自动识别（http/https）
    $text = preg_replace('/(https?:\/\/[^\s<\[]+)/i', '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', $text);
    // 无协议 www. 域名自动识别（排除已生成标签属性内的 www）
    $text = preg_replace_callback('/(?<!["\'\/])(www\.[a-z0-9\-]+(?:\.[a-z0-9\-]+)+(?:\/[^\s<\[]*)?)/i', function($m) {
        return '<a href="http://' . $m[1] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
    }, $text);
    // 还原受保护的标签（循环替换，避免占位符嵌套时一次替换还原不完整）
    if (!empty($placeholders)) {
        for ($i = 0; $i < 5; $i++) {
            $before = $text;
            $text = str_replace(array_keys($placeholders), array_values($placeholders), $text);
            if ($text === $before) break;
        }
    }
    return nl2br($text, false);
}

/**
 * 去除 BBCode 标签，返回纯文本
 *
 * 用于标题、面包屑、元信息等处，避免 [quote]/[b]/[url] 等标签原样显示。
 * 支持嵌套 [quote]，最多处理 10 层。
 */
function strip_bbcode(?string $text): string {
    if ($text === null) {
        return '';
    }
    // 无参数简单标签
    $simpleTags = ['bi', 'b', 'i', 'u', 's', 'code'];
    foreach ($simpleTags as $tag) {
        $text = preg_replace('/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/is', '$1', $text);
    }
    // 嵌套 quote 迭代剥离
    for ($i = 0; $i < 10; $i++) {
        $next = preg_replace('/\[quote\](.*?)\[\/quote\]/is', '$1', $text);
        if ($next === $text) {
            break;
        }
        $text = $next;
    }
    // 带参数标签：保留标签内的文本（url 保留显示文字，img 保留图片地址）
    $text = preg_replace('/\[color=[^\]]+\](.*?)\[\/color\]/is', '$1', $text);
    $text = preg_replace('/\[size=[0-9]+\](.*?)\[\/size\]/is', '$1', $text);
    $text = preg_replace('/\[img\](.*?)\[\/img\]/is', '$1', $text);
    $text = preg_replace('/\[url\](.*?)\[\/url\]/is', '$1', $text);
    $text = preg_replace('/\[url=[^\]]+\](.*?)\[\/url\]/is', '$1', $text);
    return trim($text);
}

/**
 * 判断一段文本在可视层面是否实质为空
 *
 * 会依次剥离 HTML 标签、BBCode、HTML 实体，并移除所有 Unicode 空白/零宽/格式字符。
 * 用于发帖/回帖校验，防止仅输入空格、换行、&nbsp;、零宽空格、HTML 空标签等绕过非空检查。
 */
function is_effectively_empty(?string $text): bool {
    if ($text === null) {
        return true;
    }
    $text = trim($text);
    $text = strip_tags($text);
    $text = strip_bbcode($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // 移除各类空白、零宽字符、不间断空格、BOM 等格式字符
    $text = preg_replace('/[\s\p{Cf}\x{00A0}\x{200B}-\x{200F}\x{FEFF}]+/u', '', $text);
    return $text === '';
}

/**
 * 生成干净的预览文本
 *
 * 用于版块卡片“最后发表”、列表摘要等场景，剥离 HTML 标签、BBCode、HTML 实体，
 * 并截断到指定长度，避免 XSS 测试 payload 或原始标签污染界面。
 */
function format_preview_text(?string $text, int $maxLength = 60): string {
    if ($text === null) {
        return '';
    }
    // 先剥离 HTML 标签（防御直接写入的 <script> 等）
    $text = strip_tags($text);
    // 再剥离 BBCode
    $text = strip_bbcode($text);
    // 解码 HTML 实体（如 &#60;script&#62;）为可读字符
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // 规范化空白
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    // 截断并加省略号
    if (mb_strlen($text, 'UTF-8') > $maxLength) {
        $text = mb_substr($text, 0, $maxLength, 'UTF-8') . '…';
    }
    return $text;
}

/**
 * 帖子风险评估
 *
 * 基于内容特征计算风险分数（0~100）：
 *   - 0~30  低风险（正常内容）
 *   - 31~60 中风险（可疑内容，建议复核）
 *   - 61~100 高风险（疑似违规，建议处理）
 *
 * 检测维度：
 *   1. 敏感词/违禁词匹配
 *   2. 可疑外链（非白名单域名）
 *   3. 内容质量（纯数字/纯符号/重复字符占比过高）
 *   4. 标题异常（全大写、过多感叹号）
 *   5. 广告特征（联系方式、推广文案）
 *
 * @return array{score:int, level:string, label:string, details:string[]}
 */
function assess_post_risk(string $content, string $title = ''): array {
    $score = 0;
    $details = [];
    $text = strtolower($content . ' ' . $title);

    // 1. 敏感词匹配
    $sensitiveKeywords = [
        t('common_320335', '赌博'), t('common_8c00eb', '赌场'), t('common_dff609', '彩票'), t('common_ca499d', '色情'), t('common_90da4c', '裸聊'), t('common_a99179', '招嫖'), t('common_cb8aa0', '卖淫'),
        t('common_488480', '贩毒'), t('common_9bdb8f', '毒品'), t('common_3cc58f', '枪支'), t('common_4a5cf7', '弹药'), t('common_98c188', '炸药'),
        t('common_f397b9', '办假证'), t('common_a925fb', '假文凭'), t('common_e09d83', '代考'), t('common_600466', '替考'), t('common_b48629', '作弊器'),
        t('common_4a4620', '传销'), t('common_b621f7', '洗钱'), t('common_eed65a', '高利贷'), t('common_3ca73c', '裸贷'),
        t('common_0da2f7', '翻墙'), t('common_872b35', 'vpn推荐'), t('common_e61f8b', '科学上网'),
        t('common_f4455f', '成人'), 'av', t('common_dc7822', '性爱'), t('common_65ff22', '约炮'), t('common_cb0254', '一夜情'),
        t('common_32fc3c', '六合彩'), t('common_7786f5', '时时彩'), t('common_65b715', '北京赛车'), 'pk10',
        t('common_c89961', '刷单'), t('common_beb637', '兼职打字'), t('common_0d6a5d', '日赚'), t('common_07cdc7', '在家赚钱'),
        t('common_68406d', '微信'), t('common_44af81', 'QQ群'), t('common_71a0d3', '加群'), t('common_b8a8fe', '扫码'),  // 仅在搭配推广文案时提高风险
    ];
    $sensitiveCount = 0;
    foreach ($sensitiveKeywords as $kw) {
        if (mb_stripos($text, $kw) !== false) {
            $sensitiveCount++;
        }
    }
    if ($sensitiveCount > 0) {
        $score += min($sensitiveCount * 20, 60);
        $details[] = "检测到 {$sensitiveCount} 个敏感关键词";
    }

    // 2. 可疑外链（非白名单域名）
    $linkCount = preg_match_all('#https?://([^\s/\[\]]+)#i', $content, $linkMatches);
    $trustedDomains = [
        'github.com', 'gitee.com', 'stackoverflow.com', 'npmjs.com',
        'python.org', 'php.net', 'mysql.com', 'sqlite.org',
        'w3.org', 'mdn.', 'developer.mozilla.org',
    ];
    $untrustedLinks = 0;
    if ($linkCount > 0) {
        foreach ($linkMatches[1] as $domain) {
            $trusted = false;
            foreach ($trustedDomains as $td) {
                if (stripos($domain, $td) !== false) { $trusted = true; break; }
            }
            if (!$trusted) $untrustedLinks++;
        }
    }
    if ($untrustedLinks > 0) {
        $score += min($untrustedLinks * 10, 30);
        $details[] = "包含 {$untrustedLinks} 个非白名单外链";
    }

    // 3. 内容质量检测
    $plainContent = strip_tags($content);
    $plainLen = mb_strlen($plainContent);
    $digitsLen = mb_strlen(preg_replace('/[^0-9]/u', '', $plainContent));

    // 纯数字/符号占比超 70%
    if ($plainLen > 10) {
        $nonAlphaNum = mb_strlen(preg_replace('/[a-zA-Z0-9一-龥\s]/u', '', $plainContent));
        $ratio = $plainLen > 0 ? $nonAlphaNum / $plainLen : 0;
        if ($ratio > 0.7) {
            $score += 25;
            $details[] = t('common_29f4ec','内容中特殊符号占比过高 (') . round($ratio * 100) . '%)';
        }
    }

    // 重复字符（超过 15 个相同连续字符）
    if (preg_match('/(.)\1{14,}/u', $plainContent)) {
        $score += 15;
        $details[] = t('common_5baaaf','包含大量重复字符');
    }

    // 纯数字内容过长（疑似灌水/垃圾信息）
    if ($plainLen > 20 && $digitsLen / $plainLen > 0.7) {
        $score += 10;
        $details[] = t('common_ed8506','内容以数字为主，疑似灌水');
    }

    // 4. 标题异常
    $titleLen = mb_strlen($title);
    if ($titleLen > 0) {
        // 全大写
        $upperLen = mb_strlen(preg_replace('/[^A-Z]/u', '', $title));
        $alphaLen = mb_strlen(preg_replace('/[^a-zA-Z]/u', '', $title));
        if ($alphaLen > 3 && $alphaLen > 0 && $upperLen / $alphaLen > 0.8) {
            $score += 10;
            $details[] = t('common_f5bbcf','标题全大写');
        }
        // 过多感叹号
        $exclCount = mb_substr_count($title, '!') + mb_substr_count($title, '！');
        if ($exclCount >= 3) {
            $score += 8;
            $details[] = t('common_dc6dd7','标题包含过多感叹号');
        }
        // 标题过短（1-2 字符，疑似无意义标题）
        if ($titleLen <= 2) {
            $score += 5;
            $details[] = t('common_0cfb8a','标题过短（≤2字符）');
        }
    }

    // 5. 广告特征
    $adPatterns = [
        '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => t('common_ac33e1','包含邮箱地址'),
        '/1[3-9]\d{9}/' => t('common_ef48b4','包含手机号'),
        '/[Qq]{2}\s*[:：]?\s*\d{5,}/' => t('common_71031a','包含QQ联系方式'),
        '/(微信|WeChat|v信)\s*[:：]?\s*[a-zA-Z0-9_-]{5,}/' => t('common_26c87e','包含微信号'),
        '/[加➕]\s*[我俺]\s*[微vVWw][信xX]/' => t('common_e71f8f','引导添加微信'),
        '/(免费|低价|优惠|折扣|特价|限时|抢购|秒杀).*(点击|购买|下单|咨询)/' => t('common_b77461','推广文案'),
        '/价格\s*[:：]?\s*\d+/' => t('common_559ef6','包含价格信息'),
    ];
    $adCount = 0;
    foreach ($adPatterns as $pattern => $desc) {
        if (preg_match($pattern, $content)) {
            $adCount++;
            $details[] = $desc;
        }
    }
    if ($adCount > 0) {
        $score += min($adCount * 8, 35);
    }

    // 限制分数范围
    $score = max(0, min(100, $score));

    // 确定风险等级
    if ($score >= 61) {
        $level = 'high';
        $label = t('common_7a83b6','高风险');
    } elseif ($score >= 31) {
        $level = 'medium';
        $label = t('common_83a55f','中风险');
    } elseif ($score >= 10) {
        $level = 'low';
        $label = t('common_117a43','低风险');
    } else {
        $level = 'safe';
        $label = t('common_8e662a','安全');
    }

    return [
        'score'   => $score,
        'level'   => $level,
        'label'   => $label,
        'details' => $details,
    ];
}

/**
 * 是否启用邮箱验证（依赖 SMTP）
 */
function email_verification_enabled(): bool {
    return defined('SMTP_ENABLED') && SMTP_ENABLED === true;
}

/**
 * 发送注册邮箱验证码
 * 返回 ['success'=>bool, 'error'=>?string, 'wait'=>int]
 */
function send_email_verification_code(string $email): array {
    require_once APP_ROOT . 'app/includes/mailer.php'; // 懒加载：仅实际发送时加载邮件模块
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => t('common_208e60','邮箱地址无效。'), 'wait' => 0];
    }

    $now = time();
    $lastSent = $_SESSION['email_verify_sent_at'] ?? 0;
    $sendCount = $_SESSION['email_verify_send_count'] ?? 0;
    $countReset = $_SESSION['email_verify_count_reset'] ?? 0;

    // 每小时限制 5 次，发送间隔 60 秒
    if ($now > $countReset + 3600) {
        $sendCount = 0;
        $countReset = $now;
    }
    if ($now - $lastSent < 60) {
        return ['success' => false, 'error' => t('common_abf833','发送太频繁，请稍后再试。'), 'wait' => 60 - ($now - $lastSent)];
    }
    if ($sendCount >= 5) {
        return ['success' => false, 'error' => t('common_c452d8','本小时发送次数已达上限，请稍后再试。'), 'wait' => 0];
    }

    $code = generate_email_code(6);
    $_SESSION['email_verify_code'] = $code;
    $_SESSION['email_verify_email'] = $email;
    $_SESSION['email_verify_expires'] = $now + 600; // 10 分钟有效
    $_SESSION['email_verify_sent_at'] = $now;
    $_SESSION['email_verify_send_count'] = $sendCount + 1;
    $_SESSION['email_verify_count_reset'] = $countReset;

    $subject = '【' . SITE_NAME . t('common_224b1b','】注册验证码');
    $body  = t('common_f11f27','<p>您好，</p>');
    $body .= t('common_9a388b','<p>您正在注册 <strong>') . e(SITE_NAME) . t('common_4a7e71','</strong> 账号，验证码为：</p>');
    $body .= '<p style="margin:16px 0;text-align:center;"><span style="display:inline-block;padding:12px 28px;font-size:30px;font-weight:700;letter-spacing:8px;color:#4f46e5;background:#eef2ff;border-radius:10px;border:1px dashed #c7d2fe;">' . e($code) . '</span></p>';
    $body .= '<p>验证码 <strong>10 分钟</strong>内有效，请勿泄露给他人。</p>';
    $body .= t('common_a137d0','<p style="color:#71717a;">如非本人操作，请忽略此邮件。</p>');
    $body = render_email_template(t('common_40c1e8','注册验证码'), $body, ['subject' => $subject]);

    $result = send_mail($email, '', $subject, $body, 'verify');
    if (!$result['success']) {
        // 发送失败时清空本次验证码，避免用户输入已不存在的码
        clear_email_verification_code();
        return ['success' => false, 'error' => t('common_94dc14','验证码发送失败：') . $result['error'], 'wait' => 0];
    }
    return ['success' => true, 'error' => null, 'wait' => 0];
}

/**
 * 校验注册邮箱验证码
 */
function validate_email_verification_code(string $email, string $code): bool {
    if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $expected = $_SESSION['email_verify_code'] ?? '';
    $expectedEmail = $_SESSION['email_verify_email'] ?? '';
    $expires = $_SESSION['email_verify_expires'] ?? 0;
    if ($expected === '' || strtolower($expectedEmail) !== strtolower($email)) {
        return false;
    }
    if (time() > (int)$expires) {
        return false;
    }
    return hash_equals($expected, $code);
}

/**
 * 清空邮箱验证码会话
 */
function clear_email_verification_code(): void {
    unset(
        $_SESSION['email_verify_code'],
        $_SESSION['email_verify_email'],
        $_SESSION['email_verify_expires'],
        $_SESSION['email_verify_sent_at'],
        $_SESSION['email_verify_send_count'],
        $_SESSION['email_verify_count_reset']
    );
}

/**
 * 发送密码修改邮箱验证码（使用独立会话键，避免与注册验证码冲突）
 */
function send_password_change_email_code(string $email): array {
    require_once APP_ROOT . 'app/includes/mailer.php'; // 懒加载：仅实际发送时加载邮件模块
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => t('common_208e60','邮箱地址无效。'), 'wait' => 0];
    }

    $now = time();
    $lastSent = $_SESSION['pwd_change_verify_sent_at'] ?? 0;
    $sendCount = $_SESSION['pwd_change_verify_send_count'] ?? 0;
    $countReset = $_SESSION['pwd_change_verify_count_reset'] ?? 0;

    // 每小时限制 5 次，发送间隔 60 秒
    if ($now > $countReset + 3600) {
        $sendCount = 0;
        $countReset = $now;
    }
    if ($now - $lastSent < 60) {
        return ['success' => false, 'error' => t('common_abf833','发送太频繁，请稍后再试。'), 'wait' => 60 - ($now - $lastSent)];
    }
    if ($sendCount >= 5) {
        return ['success' => false, 'error' => t('common_c452d8','本小时发送次数已达上限，请稍后再试。'), 'wait' => 0];
    }

    $code = generate_email_code(6);
    $_SESSION['pwd_change_verify_code'] = $code;
    $_SESSION['pwd_change_verify_email'] = $email;
    $_SESSION['pwd_change_verify_expires'] = $now + 600; // 10 分钟有效
    $_SESSION['pwd_change_verify_sent_at'] = $now;
    $_SESSION['pwd_change_verify_send_count'] = $sendCount + 1;
    $_SESSION['pwd_change_verify_count_reset'] = $countReset;

    $subject = '【' . SITE_NAME . t('common_dec658','】修改密码验证码');
    $body  = t('common_f11f27','<p>您好，</p>');
    $body .= t('common_1934b5','<p>您正在修改 <strong>') . e(SITE_NAME) . t('common_bc47e3','</strong> 账号密码，验证码为：</p>');
    $body .= '<p style="margin:16px 0;text-align:center;"><span style="display:inline-block;padding:12px 28px;font-size:30px;font-weight:700;letter-spacing:8px;color:#4f46e5;background:#eef2ff;border-radius:10px;border:1px dashed #c7d2fe;">' . e($code) . '</span></p>';
    $body .= '<p>验证码 <strong>10 分钟</strong>内有效，请勿泄露给他人。</p>';
    $body .= t('common_a137d0','<p style="color:#71717a;">如非本人操作，请忽略此邮件。</p>');
    $body = render_email_template(t('common_f0c8a0','修改密码验证码'), $body, ['subject' => $subject]);

    $result = send_mail($email, '', $subject, $body, 'verify');
    if (!$result['success']) {
        clear_password_change_email_code();
        return ['success' => false, 'error' => t('common_94dc14','验证码发送失败：') . $result['error'], 'wait' => 0];
    }
    return ['success' => true, 'error' => null, 'wait' => 0];
}

/**
 * 校验密码修改邮箱验证码
 */
function validate_password_change_email_code(string $email, string $code): bool {
    if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $expected = $_SESSION['pwd_change_verify_code'] ?? '';
    $expectedEmail = $_SESSION['pwd_change_verify_email'] ?? '';
    $expires = $_SESSION['pwd_change_verify_expires'] ?? 0;
    if ($expected === '' || strtolower($expectedEmail) !== strtolower($email)) {
        return false;
    }
    if (time() > (int)$expires) {
        return false;
    }
    return hash_equals($expected, $code);
}

/**
 * 清空密码修改邮箱验证码会话
 */
function clear_password_change_email_code(): void {
    unset(
        $_SESSION['pwd_change_verify_code'],
        $_SESSION['pwd_change_verify_email'],
        $_SESSION['pwd_change_verify_expires'],
        $_SESSION['pwd_change_verify_sent_at'],
        $_SESSION['pwd_change_verify_send_count'],
        $_SESSION['pwd_change_verify_count_reset']
    );
}

/**
 * 获取举报原因选项
 */
function get_report_reason_types(): array {
    return [
        'spam'        => t('common_892813','垃圾广告'),
        'abuse'       => t('common_c23103','恶意攻击/人身攻击'),
        'porn'        => t('common_f7f8ea','色情低俗'),
        'politics'    => t('common_4b3c8f','政治敏感'),
        'infringement'=> t('common_edad6c','侵权/抄袭'),
        'misinformation'=> t('common_8df762','虚假信息'),
        'other'       => t('common_b244ea','其他原因'),
    ];
}

/**
 * 提交举报
 * 返回 ['success'=>bool, 'error'=>?string, 'id'=>?int]
 */
function add_report(int $reporterId, string $reasonType, string $reason, ?int $postId = null, ?int $replyId = null): array {
    if ($postId === null && $replyId === null) {
        return ['success' => false, 'error' => t('common_379b5b','举报对象不能为空。'), 'id' => null];
    }
    $reasonTypes = get_report_reason_types();
    if (!isset($reasonTypes[$reasonType])) {
        $reasonType = 'other';
    }
    $reason = trim($reason);
    if ($reasonType === 'other' && $reason === '') {
        return ['success' => false, 'error' => t('common_cad90b','请选择举报原因或填写补充说明。'), 'id' => null];
    }
    try {
        $db = get_db();
        // 同一用户对同一内容的待处理举报去重（区分帖子和回复）
        if ($replyId > 0) {
            // 对回复的举报
            $stmt = $db->prepare("SELECT id FROM reports WHERE reporter_id = :rid AND reply_id = :reply_id AND status = 'pending' LIMIT 1");
            $stmt->execute([':rid' => $reporterId, ':reply_id' => $replyId]);
        } else {
            // 对帖子的举报
            $stmt = $db->prepare("SELECT id FROM reports WHERE reporter_id = :rid AND post_id = :post_id AND reply_id IS NULL AND status = 'pending' LIMIT 1");
            $stmt->execute([':rid' => $reporterId, ':post_id' => $postId]);
        }
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => t('common_8f4420','您已举报过该内容，管理员正在处理中。'), 'id' => null];
        }
        $stmt = $db->prepare("INSERT INTO reports (post_id, reply_id, reporter_id, reason_type, reason, status, created_at) VALUES (:post_id, :reply_id, :reporter_id, :reason_type, :reason, 'pending', CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':post_id'    => $postId,
            ':reply_id'   => $replyId,
            ':reporter_id'=> $reporterId,
            ':reason_type'=> $reasonType,
            ':reason'     => $reason,
        ]);
        return ['success' => true, 'error' => null, 'id' => (int)$db->lastInsertId()];
    } catch (Exception $e) {
        return ['success' => false, 'error' => t('common_64a3c5','举报提交失败，请稍后重试。'), 'id' => null];
    }
}

/**
 * 获取待处理举报数量（用于后台角标）
 */
function get_pending_report_count(): int {
    try {
        $db = get_db();
        return (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 获取待审核密码重置申请数量
 */
function get_pending_password_reset_count(): int {
    try {
        $db = get_db();
        return (int)$db->query("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'pending'")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 获取待审核封禁申诉数量
 */
function get_pending_ban_appeal_count(): int {
    try {
        $db = get_db();
        return (int)$db->query("SELECT COUNT(*) FROM ban_appeals WHERE status = 'pending'")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 用户风险评分计算
 *
 * 设计原则：在"有效识别违规用户"与"避免误伤正常用户"之间取得平衡。
 * 只统计确实反映问题的数据，并引入反向减分、时效加权与产量归一化。
 *
 * 计分模型：
 *   1. 确凿证据（高权重）
 *      - 确认违规举报（reports.status='resolved'）：每条 +6
 *      - 触发"人工审核/拦截"的敏感词命中（sensitive_word_logs.action IN ('review','block')）：每条 +3
 *      - 申诉被驳回（ban_appeals.status='rejected'）：每起 +4（佐证其确属违规）
 *   2. 未核实证据（低权重）
 *      - 待处理举报（reports.status='pending'）：每条 +2
 *   3. 状态加成：禁言 +10，封禁 +20（已被处置）
 *   4. 时效加权：近 90 天确认违规/敏感词命中额外加权，旧账占比下降
 *   5. 反向减分：被驳回的举报（reports.status='rejected'，举报不实）每条 -3，
 *      且减分不超过确凿分总额，避免"靠被举报"刷低分
 *   6. 产量归一化：高产（≥50 条）且违规率极低（<5%）明显衰减，降低偶发误伤；
 *      但封禁/禁言用户保证最低严重度
 *
 * @param array $u 字段均可缺省：
 *   role, status, created_at, post_count, reply_count,
 *   resolved_report_count, pending_report_count, rejected_report_count,
 *   sensitive_review_count, sensitive_replace_count（缺省时由 sensitive_hit_count 回退）,
 *   rejected_appeal_count,
 *   recent_resolved_report_count, recent_sensitive_review_count（可选，时效加权）
 * @return array{score:int, level:string, label:string, color:string, detail:array}
 */
function compute_user_risk(array $u): array {
    // 管理员账号不参与风险评分（避免后台操作产生的举报/敏感词记录被误判）
    if (in_array(($u['role'] ?? ''), ['admin'], true)) {
        return ['score' => 0, 'level' => 'admin', 'label' => t('common_ef84e7','管理员'), 'color' => '#6366f1', 'detail' => []];
    }

    // 敏感词拆分：仅"需审核/拦截"计为违规；一级词被"替换"（已自动清洗）不计入风险
    $hasSplit = isset($u['sensitive_review_count']) || isset($u['sensitive_replace_count']);
    $sensitiveReview  = $hasSplit
        ? (int)($u['sensitive_review_count'] ?? 0)
        : (int)($u['sensitive_hit_count'] ?? 0);
    $sensitiveReplace = $hasSplit ? (int)($u['sensitive_replace_count'] ?? 0) : 0;

    $resolved = (int)($u['resolved_report_count'] ?? 0);
    $pending  = (int)($u['pending_report_count']  ?? 0);
    $rejected = (int)($u['rejected_report_count'] ?? 0);
    $appeal   = (int)($u['rejected_appeal_count'] ?? 0);

    // 1) 绝对风险分
    $score = 0;
    $score += $resolved * 6;
    $score += $pending  * 2;
    $score += $sensitiveReview * 3;
    $score += $appeal   * 4;

    // 3) 状态加成
    $statusBonus = 0;
    if (($u['status'] ?? '') === 'muted')  $statusBonus += 10;
    if (($u['status'] ?? '') === 'banned') $statusBonus += 20;
    $score += $statusBonus;

    // 4) 时效加权（仅在传入近期计数时启用；列表场景通常不传，退化为全生命周期）
    $recentResolved  = (int)($u['recent_resolved_report_count'] ?? 0);
    $recentSensitive = (int)($u['recent_sensitive_review_count'] ?? 0);
    if ($recentResolved > 0 || $recentSensitive > 0) {
        $score += $recentResolved * 2 + $recentSensitive * 1;
    }

    // 5) 反向减分：被驳回的举报说明用户被冤枉，但不超过确凿分总额
    $exoneration = $rejected * 3;
    $hardCap = $resolved * 6 + $sensitiveReview * 3 + $appeal * 4 + $statusBonus;
    $score -= min($exoneration, $hardCap);
    if ($score < 0) $score = 0;

    // 6) 按内容产量归一化，降低高产正常用户偶发违规的误伤
    $contentTotal = (int)($u['post_count'] ?? 0) + (int)($u['reply_count'] ?? 0);
    if ($contentTotal > 0) {
        $violationRate = ($resolved + $sensitiveReview) / $contentTotal;
        if ($contentTotal >= 50 && $violationRate < 0.05) {
            $score = (int)round($score * 0.5);
        } elseif ($contentTotal >= 20 && $violationRate < 0.02) {
            $score = (int)round($score * 0.75);
        }
    }

    // 已处置状态保证最低严重度（衰减后仍不低于处置对应等级）
    if (($u['status'] ?? '') === 'banned' && $score < 20) $score = 20;
    if (($u['status'] ?? '') === 'muted'  && $score < 10) $score = 10;

    // 等级划分
    if ($score >= 30) {
        $level = 'critical'; $label = t('common_c34687','极高'); $color = '#ef4444';
    } elseif ($score >= 15) {
        $level = 'high';     $label = t('common_b096b3','高');   $color = '#f97316';
    } elseif ($score >= 7) {
        $level = 'medium';   $label = t('common_086907','中');   $color = '#f59e0b';
    } elseif ($score > 0) {
        $level = 'low';      $label = t('common_b9ee25','低');   $color = '#10b981';
    } else {
        $level = 'none';     $label = t('common_720777','无');   $color = '#9ca3af';
    }

    return [
        'score'  => (int)$score,
        'level'  => $level,
        'label'  => $label,
        'color'  => $color,
        'detail' => [
            'resolved_report_count'   => $resolved,
            'pending_report_count'    => $pending,
            'rejected_report_count'   => $rejected,
            'sensitive_review_count'  => $sensitiveReview,
            'sensitive_replace_count' => $sensitiveReplace,
            'rejected_appeal_count'   => $appeal,
            'status_bonus'            => $statusBonus,
        ],
    ];
}

/**
 * 格式化举报状态
 */
function format_report_status(string $status): string {
    switch ($status) {
        case 'pending':
            return t('common_59a9eb','待处理');
        case 'resolved':
            return t('common_219bde','已处理');
        case 'rejected':
            return t('common_e944e4','已驳回');
        default:
            return $status;
    }
}

/**
 * 格式化举报筛选标签
 */
function format_report_tab_label(string $status): string {
    switch ($status) {
        case 'all':
            return t('common_778fc8','全部');
        case 'pending':
            return t('common_59a9eb','待处理');
        case 'resolved':
            return t('common_219bde','已处理');
        case 'rejected':
            return t('common_e944e4','已驳回');
        default:
            return $status;
    }
}

/**
 * 格式化密码重置申请筛选标签
 */
function format_password_reset_status_label(string $status): string {
    switch ($status) {
        case 'all':
            return t('admin_pwdreset_status_all', '全部');
        case 'pending':
            return t('admin_pwdreset_status_pending', '待审核');
        case 'approved':
            return t('admin_pwdreset_status_approved', '已通过');
        case 'rejected':
            return t('admin_pwdreset_status_rejected', '已驳回');
        default:
            return $status;
    }
}

/**
 * 添加一条站内通知
 */
function add_notification(int $userId, string $type, string $title, string $content, ?string $link = null): bool {
    try {
        $db = get_db();
        $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, content, link, created_at) VALUES (:user_id, :type, :title, :content, :link, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':user_id' => $userId,
            ':type'    => $type,
            ':title'   => $title,
            ':content' => $content,
            ':link'    => $link,
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 【已废弃】获取“记住账号密码”的本地加密密钥。
 *
 * 安全加固：原实现通过非 HttpOnly cookie 下发 AES 密钥，配合
 * localStorage 存储明文凭据密文，任何 XSS 即可解密出账号密码。
 * 自本版本起不再设置/下发 forum_cred_key cookie，仅保留函数壳
 * 避免存量调用方（login.php/register.php，由后续任务移除）致命错误。
 *
 * @deprecated 始终返回空字符串；调用方检测到空值应跳过自动填充逻辑。
 */
function get_remember_credentials_key(): string {
    return '';
}

/**
 * 格式化举报原因类型
 */
function format_report_reason(string $reasonType): string {
    return get_report_reason_types()[$reasonType] ?? t('common_b244ea','其他原因');
}

// 已安装且迁移版本已最新的环境下，无需再重复初始化默认勋章
// （ensure_default_medals() 已在 auto_migrate() 中随版本锁执行一次）
if (defined('INSTALLED_FILE') && file_exists(INSTALLED_FILE) && !is_migration_up_to_date()) {
    try {
        ensure_default_medals();
    } catch (\Throwable $e) {
        // 勋章初始化失败不影响页面正常加载
    }
}
