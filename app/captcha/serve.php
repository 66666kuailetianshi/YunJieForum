<?php
/**
 * 云界论坛 - 人机验证静态资源出口（独立模块）
 *
 * 通过白名单安全地输出本目录下的前端资源：
 *   /index.php?route=captcha/assets&file=captcha.js
 *   /index.php?route=captcha/assets&file=captcha.css
 *
 * 返回带缓存与正确 Content-Type 的静态内容，避免把 PHP 源文件直接暴露在 public/ 下。
 */

$file = $_GET['file'] ?? '';

$whitelist = [
    'captcha.js'  => ['text/javascript; charset=utf-8', __DIR__ . '/captcha.js'],
    'captcha.css' => ['text/css; charset=utf-8', __DIR__ . '/captcha.css'],
];

if (!isset($whitelist[$file])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

list($mime, $path) = $whitelist[$file];

header('Content-Type: ' . $mime);
// 静态资源（captcha.js / captcha.css）允许浏览器缓存 7 天：
// 引用 URL 已带版本参数（?v=APP_VERSION），版本升级自动失效。
// 验证码图片/接口的缓存头在各自端点中保持不缓存，不受此处影响。
header('Cache-Control: public, max-age=604800');
readfile($path);
exit;