<?php
/**
 * 云界论坛 - 历史更新备份分享下载端点（公开）
 *
 * 由后台「系统更新 → 历史更新备份」生成分享链接：
 *   /index.php?route=api/share_backup&file=update_pre_YYYYMMDD_HHMMSS.zip&token=xxx
 *
 * 安全设计：
 *   1. 无需登录即可下载（分享场景），但必须携带 48 位随机令牌，
 *      令牌存储于服务端分享记录中，无法由文件名等信息推导。
 *   2. 文件名严格白名单校验（update_pre_Ymd_His.zip），防止路径穿越。
 *   3. 分享链接默认 7 天过期，到期自动失效；删除备份时分享记录同步清除。
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/update_center.php';

function share_backup_deny(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '分享链接无效或已过期';
    exit;
}

$filename = (string)($_GET['file'] ?? '');
$token    = (string)($_GET['token'] ?? '');

// 文件名白名单 + 分享记录校验（过期记录由 uc_get_update_backup_share 自动清理）
if (!uc_is_update_backup_name($filename)) {
    share_backup_deny();
}
$meta = uc_get_update_backup_share($filename);
if ($meta === null || $token === '' || !hash_equals((string)$meta['token'], $token)) {
    share_backup_deny();
}

$filepath = uc_update_backup_dir() . $filename;
if (!is_file($filepath)) {
    share_backup_deny();
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, no-store, must-revalidate');
readfile($filepath);
exit;
