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
    try {
        $result = uc_check_for_update();
    } catch (\Throwable $e) {
        // 与 update/install_upload 分支一致：捕获 PHP 异常并附现场信息，避免前端只看到 500 空白
        $result = [
            'success' => false,
            'error'   => 'exception: ' . $e->getMessage(),
            'details' => [
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ],
        ];
    }
    if (empty($result['details'])) {
        $result['details'] = null;
    }
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
    try {
        $result = uc_perform_update(!empty($_POST['force']));
    } catch (\Throwable $e) {
        // 捕获 PHP 异常并附现场信息，避免前端只看到空白/500
        $result = [
            'success' => false,
            'error'   => 'exception: ' . $e->getMessage(),
            'details' => [
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ],
        ];
    }
    if (empty($result['details'])) {
        $result['details'] = null;
    }
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
    // 预检：当 post_max_size / upload_max_filesize 被超出时，PHP 会清空 $_POST 和 $_FILES，
    // 此时 $_FILES['file'] 不存在或 error 为 UPLOAD_ERR_NO_FILE，但更关键的是需要尽早给前端一个明确提示。
    if (empty($_FILES) || !isset($_FILES['file'])) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'upload_err_no_file',
            'details' => array_merge(uc_upload_env_details('upload_err_no_file', []), [
                'hint'     => '未收到上传文件。可能原因：文件超过 PHP 的 post_max_size 或 upload_max_filesize 限制（请检查 php.ini），或表单字段名不匹配。',
                'php_post_max'   => ini_get('post_max_size'),
                'php_upload_max' => ini_get('upload_max_filesize'),
                'php_files_empty' => empty($_FILES),
                '_files_keys'     => array_keys($_FILES ?? []),
            ]),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(120);
    try {
        $saved = uc_save_upload_package($_FILES['file'] ?? []);
        if (!$saved['ok']) {
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => $saved['error'],
                'details' => $saved['details'] ?? null,
            ], JSON_UNESCAPED_UNICODE);
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
    } catch (\Throwable $e) {
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'exception: ' . $e->getMessage(),
            'details' => [
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
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
        // 附带 data/tmp 目录诊断，方便排查「上传成功但提示包已消失」的场景
        echo json_encode([
            'success' => false,
            'error'   => 'pkg_not_found',
            'details' => array_merge(uc_upload_env_details('pkg_not_found'), [
                'upload_input_exists' => file_exists($zipPath),
                'hint'                => '上传的更新包未保存到 data/tmp/upload_update_input.zip。请重新上传，或检查 data/tmp 目录权限。',
            ]),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        // 安装开始即写初始进度（覆盖可能残留的旧进度文件），供前端轮询展示
        uc_progress_write(['stage' => 'preparing', 'stage_label' => 'preparing', 'progress' => 0, 'total' => 0, 'downloaded' => 0, 'message' => '', 'error' => null, 'done' => false]);
        $result = uc_perform_upload_update($zipPath);
    } catch (\Throwable $e) {
        // 安装过程抛出的 PHP 异常（如 ZipArchive / 文件系统错误）附现场信息返回
        $result = [
            'success' => false,
            'error'   => 'exception: ' . $e->getMessage(),
            'details' => [
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ],
        ];
    }
    if (empty($result['details'])) {
        $result['details'] = null;
    }
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
