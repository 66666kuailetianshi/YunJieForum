<?php
/**
 * 云界论坛 - 编辑器图片本地上传接口
 *
 * 返回 JSON：{ success: true, url: 'uploads/images/...' }
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => '站点未安装'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => '请求方式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!validate_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '安全验证失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '未选择图片文件'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['image'];
if (!empty($file['error'])) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => '文件大小超过服务器限制',
        UPLOAD_ERR_FORM_SIZE  => '文件大小超过表单限制',
        UPLOAD_ERR_PARTIAL    => '文件上传不完整',
        UPLOAD_ERR_NO_FILE    => '未选择文件',
        UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录不可用',
        UPLOAD_ERR_CANT_WRITE => '文件写入失败',
        UPLOAD_ERR_EXTENSION  => '上传被扩展阻止',
    ];
    $errorMsg = $uploadErrors[$file['error']] ?? '上传失败';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $errorMsg], JSON_UNESCAPED_UNICODE);
    exit;
}

$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '图片大小不能超过 5MB'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$mime = !empty($file['type']) ? strtolower($file['type']) : '';

if (!in_array($mime, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '仅支持 JPG、PNG、GIF、WEBP 格式的图片'], JSON_UNESCAPED_UNICODE);
    exit;
}

$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '无法识别的图片文件'], JSON_UNESCAPED_UNICODE);
    exit;
}

$realMime = image_type_to_mime_type($imageInfo[2]);
if (!in_array(strtolower($realMime), $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '图片格式校验失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExts, true)) {
    // 使用真实 MIME 对应的扩展名兜底
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $ext = $extMap[$mime] ?? 'jpg';
}

$subDir = date('Ym');
$destDir = UPLOAD_IMAGE_PATH . $subDir . DIRECTORY_SEPARATOR;
if (!is_dir($destDir)) {
    if (!mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '创建上传目录失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$filename = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
$destPath = $destDir . $filename;
$webUrl   = UPLOAD_IMAGE_URL . $subDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '保存图片失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'url' => $webUrl], JSON_UNESCAPED_UNICODE);
