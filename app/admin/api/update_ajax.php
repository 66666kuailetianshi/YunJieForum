<?php
/**
 * 云界论坛 - 系统更新中心 AJAX 接口
 *
 * 动作：
 *   action=check            GET   检查更新，返回 latest / update_available / changelog 等
 *   action=update           POST  执行更新（下载 → 校验 → 备份 → 覆盖），需 CSRF
 *   action=upload_inspect   POST  上传本地更新包并解析（包内版本 / 文件数），需 CSRF
 *   action=install_upload   POST  安装已上传的更新包（备份 → 覆盖），需 CSRF
 *   action=backup_list      GET   列出历史更新备份（含带令牌的下载链接）
 *   action=backup_delete    POST  删除指定历史更新备份，需 CSRF
 *   action=backup_share     POST  生成（或复用）历史更新备份的分享链接，需 CSRF
 */

ob_start();

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/update_center.php';
require_once APP_ROOT . 'app/admin/layout/admin-helpers.php';

// 权限校验
if (!is_logged_in() || !is_admin()) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 细粒度门禁：系统更新仅超级管理员可用
if (!is_super_admin()) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));

if ($action === 'check') {
    $result = uc_check_for_update();
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!validate_csrf()) {
        ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = uc_perform_update(!empty($_POST['force']));
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'upload_inspect') {
    // 上传本地更新包并解析（上传 → 保存到 data/tmp/ → 读取包内版本/文件数）
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!validate_csrf()) {
        ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(120);
    $saved = uc_save_upload_package($_FILES['file'] ?? []);
    if (!$saved['ok']) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $saved['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $info = uc_inspect_upload_package($saved['path']);
    if (!$info['ok']) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $info['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'    => true,
        'version'    => $info['version'],
        'current'    => $info['current'],
        'relation'   => $info['relation'],
        'files'      => $info['files'],
        'size'       => $info['size'],
        'size_text'  => uc_format_bytes($info['size']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'install_upload') {
    // 安装已上传的更新包（备份 → 解压覆盖），同步执行
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!validate_csrf()) {
        ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(600);
    $zipPath = DATA_PATH . 'tmp' . DIRECTORY_SEPARATOR . 'upload_update_input.zip';
    if (!is_file($zipPath)) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'pkg_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = uc_perform_upload_update($zipPath);
    uc_progress_clear();
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'progress') {
    // 轮询更新进度（无需 CSRF，只读）
    $prog = uc_progress_read();
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($prog, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'backup_list') {
    // 历史更新备份列表（只读；下载链接为令牌派生的一次性地址）
    $items = [];
    foreach (uc_list_update_backups() as $b) {
        $items[] = [
            'filename'     => $b['filename'],
            'size'         => $b['size'],
            'size_text'    => uc_format_bytes($b['size']),
            'time_text'    => date('Y-m-d H:i:s', $b['time']),
            'download_url' => site_url('admin/update_center', ['action' => 'download', 'filename' => $b['filename'], 'token' => admin_backup_download_token($b['filename'])]),
        ];
    }
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'backup_delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!validate_csrf()) {
        ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = uc_delete_update_backup((string)($_POST['filename'] ?? ''));
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'backup_share') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!validate_csrf()) {
        ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = uc_create_update_backup_share((string)($_POST['filename'] ?? ''));
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

ob_end_clean();
http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);
exit;
