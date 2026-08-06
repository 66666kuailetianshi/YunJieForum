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
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
readfile($path);
exit;