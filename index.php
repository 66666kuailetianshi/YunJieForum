<?php
/**
 * 云界论坛 - 前端控制器 / 路由入口
 */

// 应用根目录
define('APP_ROOT', __DIR__ . DIRECTORY_SEPARATOR);

// ==================== 路由解析 ====================
// 兼容三种访问方式（无需服务器重写规则也能工作）：
// 1) index.php?route=xxx   —— .htaccess / nginx 重写带 route 参数
// 2) index.php?s=xxx       —— 部分 nginx（如 phpstudy 默认）重写使用 s 参数
// 3) /xxx 直接访问         —— nginx try_files $uri $uri/ /index.php 兜底时，
//                            从 REQUEST_URI 自动解析出路由
$requestRoute = $_GET['route'] ?? ($_GET['s'] ?? '');
// 某些重写规则会把整段 URI（含查询串）写入 route 参数，这里做兼容处理
$requestRoute = explode('?', (string)$requestRoute)[0];
$requestRoute = ltrim($requestRoute, '/');
if ($requestRoute === '') {
    $requestPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $requestRoute = trim($requestPath, '/');
    // 去掉入口文件名前缀（/index.php 或 index.php）
    $requestRoute = preg_replace('/^index\.php(\/|$)/i', '', $requestRoute);
    // 无重写规则时（如 Apache ErrorDocument 404 / nginx error_page 兜底进入），
    // 服务器可能带着 404 状态码；这里恢复为 200，
    // 真正无效的路由会在分发处重新设为 404。
    http_response_code(200);
}

// 清理路由
$route = $requestRoute;
$route = trim($route, '/');
$route = basename($route);
$route = preg_replace('/\.php$/i', '', $route);
$route = $route ?: 'home';

// --- 静态资源兼容：浏览器会自动请求 /favicon.ico，直接返回 logo.svg ---
if ($route === 'favicon.ico') {
    $logoSvg = APP_ROOT . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'logo.svg';
    if (file_exists($logoSvg)) {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=86400');
        readfile($logoSvg);
        exit;
    }
    http_response_code(404);
    exit;
}

// ==================== 路由分发 ====================

// --- 人机验证静态资源 (/index.php?route=captcha/assets&file=xxx) ---
// 由 app/captcha/serve.php 白名单安全输出 captcha.js / captcha.css
if ($requestRoute === 'captcha/assets') {
    require APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'captcha' . DIRECTORY_SEPARATOR . 'serve.php';
    exit;
}

// --- 管理后台路由 (/admin/xxx) ---
if (strpos($requestRoute, 'admin') === 0) {
    $adminRoute = trim(substr($requestRoute, 5), '/');
    $adminRoute = basename($adminRoute);
    $adminRoute = preg_replace('/\.php$/i', '', $adminRoute);

    // Admin API (/admin/api/xxx)
    if (strpos($requestRoute, 'admin/api') === 0) {
        $apiRoute = trim(substr($requestRoute, 9), '/');
        $apiRoute = basename($apiRoute);
        // 去掉 .php 后缀，避免拼接时重复（URL 已含 .php）
        $apiRoute = preg_replace('/\.php$/i', '', $apiRoute);
        $apiFile = APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . $apiRoute . '.php';

        if (file_exists($apiFile)) {
            require $apiFile;
            exit;
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => '接口不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $adminRoute = $adminRoute ?: 'index';
    $adminFile = APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . $adminRoute . '.php';
    if (file_exists($adminFile)) {
        require $adminFile;
        exit;
    }
    http_response_code(404);
    echo '<h1>404 - 管理页面不存在</h1>';
    exit;
}

// --- 安装向导路由 ---
if ($route === 'install') {
    $installedFile = APP_ROOT . 'data' . DIRECTORY_SEPARATOR . 'installed.lock';
    if (file_exists($installedFile)) {
        $dbFile = APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db.php';
        if (file_exists($dbFile)) {
            try {
                @include_once APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
                @include_once $dbFile;
                $driver = get_db_driver();
                $tables = $driver->getTables();
                if (in_array('users', $tables)) {
                    header('Location: /');
                    exit;
                }
            } catch (Throwable $e) {}
        }
    }
    require APP_ROOT . 'install.php';
    exit;
}

// --- 公共 API 路由 (/api/xxx) ---
if (strpos($requestRoute, 'api/') === 0) {
    $apiRoute = trim(substr($requestRoute, 4), '/');
    $apiRoute = basename($apiRoute);
    // 去掉 .php 后缀，避免拼接时重复（URL 已含 .php）
    $apiRoute = preg_replace('/\.php$/i', '', $apiRoute);
    // 人机验证接口已独立到 app/captcha/ 模块
    if ($apiRoute === 'captcha') {
        $apiFile = APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'captcha' . DIRECTORY_SEPARATOR . 'api.php';
    } else {
        $apiFile = APP_ROOT . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . $apiRoute . '.php';
    }
    if (file_exists($apiFile)) {
        require $apiFile;
        exit;
    }
}

// --- 前台页面路由 ---
// 未安装时跳转
if ($route !== 'install') {
    $installedFile = APP_ROOT . 'data' . DIRECTORY_SEPARATOR . 'installed.lock';
    if (!file_exists($installedFile)) {
        header('Location: install.php');
        exit;
    }
}

// 前台路由映射
$routes = [
    'home'                      => 'home',
    'index'                     => 'home',
    'login'                     => 'login',
    'register'                  => 'register',
    'logout'                    => 'logout',
    'forum'                     => 'forum',
    'post'                      => 'post',
    'new_post'                  => 'new_post',
    'search'                    => 'search',
    'profile'                   => 'profile',
    'favorites'                 => 'favorites',
    'subscriptions'            => 'subscriptions',
    'tag'                      => 'tag',
    'edit_post'                => 'edit_post',
    'checkin'                   => 'checkin',
    'pm'                        => 'pm',
    'notifications'             => 'notifications',
    'notification_read'         => 'notification_read',
    'report'                    => 'report',
    'forgot_password'           => 'forgot_password',
    'reset_password'            => 'reset_password',
    'appeal'                    => 'appeal',
    'banned'                    => 'banned',
    'force_change_password'     => 'force_change_password',
    'send_email_code'           => 'send_email_code',
    'send_password_change_code' => 'send_password_change_code',
    'privacy'                   => 'privacy',
    'terms'                     => 'terms',
    'disclaimer'                => 'disclaimer',
    'service'                   => 'service',
    'ticket'                    => 'ticket',
];

$controller = $routes[$route] ?? null;

if ($controller === null) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>404 - 页面不存在</title>'
       . '<style>body{font-family:system-ui;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f5f5f5}'
       . '.box{text-align:center;background:#fff;padding:3rem;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08)}'
       . 'h1{font-size:4rem;margin:0;color:#6366f1}a{color:#6366f1}</style></head>'
       . '<body><div class="box"><h1>404</h1><p>页面不存在</p><p><a href="/">返回首页</a></p></div></body></html>';
    exit;
}

$controllerFile = APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . $controller . '.php';
if (!file_exists($controllerFile)) {
    http_response_code(404);
    echo '<h1>404</h1>';
    exit;
}
require $controllerFile;
