<?php
/**
 * 云界论坛 - 管理后台 IP 库管理 AJAX 接口
 *
 * 动作：
 *   action=status     GET   查看 IP 库状态（xdb 文件信息、抽样验证、访客数据覆盖情况）
 *   action=query      POST  在线查询 IP 归属地（需 CSRF）
 *   action=dl_status  POST  查询分片下载任务状态（可恢复中断任务，需 CSRF）
 *   action=dl_start   POST  新建/恢复分片下载任务（探测源、Range 支持，需 CSRF）
 *   action=dl_fetch   POST  下载一个分片写入临时文件（单次请求 ≤8MB，需 CSRF）
 *   action=dl_cancel  POST  取消下载并清理临时文件（需 CSRF）
 *   action=dl_finalize POST 校验并安装已下载完成的 xdb（需 CSRF）
 *   action=upload     POST  上传官方 ip2region_v4.xdb / ip2region_v6.xdb 安装 IP 库（需 CSRF）
 *   action=delete     POST  删除 IP 库 xdb 文件（需 CSRF）
 *
 * IP 库形态：ip2region 官方 xdb 二进制直读（app/data/ip2region_v4.xdb / v6.xdb），
 * 运行期按官方格式直查；上传/下载 xdb 后原子替换（临时文件 + rename）。
 * IP 库为可选项，默认不随代码分发，需在后台「下载 IP 库」或「上传更新」中安装。
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/db.php';

// 清理所有已有的输出缓冲区，然后开启新的缓冲区
// 确保任何 PHP 警告/通知不会污染 JSON 响应
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

if (!is_logged_in() || !is_admin()) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 细粒度门禁：IP 库管理仅超级管理员可用
if (!is_super_admin()) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => t('common_super_admin_only', '该功能仅最高管理员可用。')], JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

try {
    if ($action === 'status') {
        $status = ip_region_db_status();
        // 访客数据覆盖情况（粗粒度统计，仅供展示）
        $db = get_db();
        $covered = null;
        try {
            $total = (int)$db->query("SELECT COUNT(*) FROM traffic_visitors")->fetchColumn();
            $withRegion = (int)$db->query("SELECT COUNT(*) FROM traffic_visitors WHERE region != ''")->fetchColumn();
            $covered = $total > 0 ? round($withRegion / $total * 100, 1) : 0;
        } catch (Throwable $e) {
            // 表不存在等场景忽略
        }
        ob_end_clean();
        echo json_encode(['success' => true, 'status' => $status, 'covered_ratio' => $covered], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 以下动作均需 POST + CSRF
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!validate_csrf()) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'query') {
        $ip = trim((string)($_POST['ip'] ?? ''));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => t('ipdb_query_invalid_ip', '请输入合法的 IP 地址。')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $raw = ip_region_query($ip);
        if ($raw === null) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => t('ipdb_query_no_hit', '未查询到该 IP 的归属地（无 IP 库或库内无记录）。')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'ip'      => $ip,
            'raw'     => $raw,
            'display' => ip_region_display($raw),
            'parts'   => ip_region_parts($raw),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'dl_status') {
        ob_end_clean();
        echo json_encode(['success' => true, 'task' => ip_region_dl_task_view(ip_region_dl_read_state())], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'dl_start') {
        $source = (string)($_POST['source'] ?? '');
        $resume = ($_POST['resume'] ?? '') === '1';
        $sources = ip_region_download_sources();
        if (!isset($sources[$source])) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => t('ipdb_download_bad_source', '不支持的下载来源。')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $state = ip_region_dl_read_state();
        $isResume = $resume && $state !== null && ($state['source'] ?? '') === $source;
        if (!$isResume) {
            // 全新任务：清理旧残留，探测各文件大小并校验源支持分段下载
            ip_region_dl_clear();
            $files = [];
            foreach ($sources[$source]['urls'] as $ver => $url) {
                $probe = ip_region_dl_probe($url, $sources[$source]['headers']);
                if (!$probe['ok']) {
                    ip_region_dl_clear();
                    // 拼装诊断信息，便于定位服务器访问下载源的真实原因
                    $detail = '';
                    if (!empty($probe['http_code'])) {
                        $detail .= ' HTTP ' . $probe['http_code'];
                    }
                    if (!empty($probe['curl_error'])) {
                        $detail .= '，' . $probe['curl_error'];
                    }
                    ob_end_clean();
                    echo json_encode([
                        'success' => false,
                        'error' => t('ipdb_dl_probe_failed', '无法获取文件信息') . '（' . $ver . '）' . $detail
                                . '。' . t('ipdb_dl_probe_hint', '请检查服务器能否访问外网，或改用「上传更新」方式安装。'),
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                if (empty($probe['range'])) {
                    ip_region_dl_clear();
                    ob_end_clean();
                    echo json_encode(['success' => false, 'error' => t('ipdb_dl_no_range', '该下载源不支持分段下载，请改用「上传更新」方式安装。')], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $files[$ver] = ['url' => $url, 'total' => (int)$probe['total'], 'range' => true];
            }
            $state = ['source' => $source, 'status' => 'downloading', 'updated' => time(), 'files' => $files];
            ip_region_dl_write_state($state);
        } else {
            // 断点续传：保留已下载的临时文件
            $state['status'] = 'downloading';
            $state['updated'] = time();
            ip_region_dl_write_state($state);
        }
        ob_end_clean();
        echo json_encode(['success' => true, 'task' => ip_region_dl_task_view($state)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'dl_fetch') {
        $ver    = (string)($_POST['ver'] ?? '');
        $offset = (int)($_POST['offset'] ?? -1);
        $size   = (int)($_POST['size'] ?? 0);
        $state  = ip_region_dl_read_state();
        if ($state === null || !isset($state['files'][$ver])) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => 'no_task'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $f = $state['files'][$ver];
        $total = (int)$f['total'];
        if ($offset < 0 || $size < 1 || $size > 8 * 1024 * 1024 || $offset > $total) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => 'bad_param'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $sources = ip_region_download_sources();
        $cfg = $sources[$state['source']] ?? null;
        if ($cfg === null) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => 'no_task'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        @set_time_limit(180);
        $r = ip_region_dl_fetch($f['url'], $ver, $offset, $size, $cfg['headers']);
        if (!$r['ok']) {
            $errMap = [
                'open_dest'     => t('ipdb_dl_err_open_dest', '无法写入临时文件，请检查 data/tmp 目录权限。'),
                'download_failed' => t('ipdb_dl_err_download', '分片下载失败（网络中断或超时）。'),
                'no_range'      => t('ipdb_dl_no_range', '该下载源不支持分段下载。'),
                'empty'         => t('ipdb_dl_err_empty', '未获取到数据。'),
            ];
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => $errMap[$r['error']] ?? $r['error'], 'next_offset' => $offset], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $next = $offset + $r['written'];
        $done = ($total > 0) && ($next >= $total);
        ob_end_clean();
        echo json_encode([
            'success'     => true,
            'ver'         => $ver,
            'offset'      => $offset,
            'written'     => $r['written'],
            'next_offset' => $next,
            'done'        => $done,
            'total'       => $total,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'dl_cancel') {
        ip_region_dl_clear();
        ob_end_clean();
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'dl_finalize') {
        $state = ip_region_dl_read_state();
        if ($state === null) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => 'no_task'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ok     = [];
        $errors = [];
        foreach (['v4', 'v6'] as $ver) {
            if (!isset($state['files'][$ver])) {
                continue;
            }
            $r = ip_region_dl_install($ver);
            if (!empty($r['ok'])) {
                $ok[$ver] = ['imported' => $r['imported'], 'size' => $r['size']];
            } else {
                $errors[$ver] = $r['error'] ?? 'install_failed';
            }
        }
        ip_region_dl_clear();
        if (empty($ok)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => t('ipdb_dl_finalize_failed', '校验安装失败'), 'errors' => $errors], JSON_UNESCAPED_UNICODE);
            exit;
        }
        ob_end_clean();
        echo json_encode(['success' => true, 'ok' => $ok, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'upload') {
        $result = ip_region_upload($_FILES['file'] ?? []);
        ob_end_clean();
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        if (!ip_region_db_clear()) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => t('ipdb_delete_failed', '删除 IP 库 xdb 文件失败，请检查文件权限。')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        ob_end_clean();
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_action'], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    $outputBuffer = ob_get_contents();
    ob_end_clean();
    if ($outputBuffer !== '' && $outputBuffer !== false) {
        error_log('ip_db_ajax 缓冲输出: ' . $outputBuffer);
    }
    error_log('ip_db_ajax 异常: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => t('admin_ajax_server_error_retry', '服务器内部错误，请稍后重试'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

ob_end_flush();

/**
 * 上传官方 xdb 文件（.xdb）安装 IP 归属地库
 * 按文件头部 ipVersion 识别 v4 / v6，不依赖文件名。
 */
function ip_region_upload(array $file): array
{
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => t('ipdb_upload_no_file', '未收到上传文件。')];
    }
    $name = (string)($file['name'] ?? '');
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'xdb') {
        return ['success' => false, 'error' => t('ipdb_upload_not_xdb', '仅支持官方 ip2region_v4.xdb / ip2region_v6.xdb 文件。')];
    }
    $maxSize = 200 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxSize) {
        return ['success' => false, 'error' => t('ipdb_upload_too_large', '文件过大（超过 200MB）。')];
    }

    // 保存到临时位置再识别/安装
    $tmpDir = DATA_PATH . 'tmp' . DIRECTORY_SEPARATOR;
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0755, true);
    }
    $rand = bin2hex(random_bytes(4));
    $tmp = $tmpDir . 'ipdb_upload_' . $rand . '.xdb';
    if (!move_uploaded_file($file['tmp_name'], $tmp)) {
        return ['success' => false, 'error' => t('ipdb_upload_move_failed', '保存上传文件失败，请检查 data/tmp 目录权限。')];
    }

    // 按头部 ipVersion 识别类型（不依赖文件名）
    $hdr = ip_region_xdb_read_header($tmp);
    if ($hdr === null) {
        @unlink($tmp);
        return ['success' => false, 'error' => t('ipdb_upload_bad_xdb', '文件不是合法的 ip2region xdb 数据文件。')];
    }
    $ver = ($hdr['ipVersion'] === 6) ? 'v6' : (($hdr['ipVersion'] === 4) ? 'v4' : '');
    if ($ver === '') {
        @unlink($tmp);
        return ['success' => false, 'error' => t('ipdb_upload_bad_xdb', '文件不是合法的 ip2region xdb 数据文件。')];
    }

    $result = ip_region_xdb_install($tmp, $ver);
    @unlink($tmp);
    return $result;
}

/**
 * 分片下载任务状态：读写 data/tmp/ipdb_dl_state.json
 * 结构：{source, status, updated, files: {v4: {url, total, range}, v6: {...}}}
 * 各文件已下载字节数以临时文件实际大小为准（filesize），不写入状态，避免计数漂移。
 */

function ip_region_dl_state_path(): string
{
    return DATA_PATH . 'tmp' . DIRECTORY_SEPARATOR . 'ipdb_dl_state.json';
}

function ip_region_dl_tmp_path(string $ver): string
{
    return DATA_PATH . 'tmp' . DIRECTORY_SEPARATOR . 'ipdb_dl_' . $ver . '.xdb';
}

function ip_region_dl_read_state(): ?array
{
    $path = ip_region_dl_state_path();
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $state = ($raw !== false) ? json_decode($raw, true) : null;
    return is_array($state) ? $state : null;
}

function ip_region_dl_write_state(array $state): void
{
    $path = ip_region_dl_state_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE));
}

function ip_region_dl_clear(): void
{
    @unlink(ip_region_dl_state_path());
    foreach (['v4', 'v6'] as $ver) {
        @unlink(ip_region_dl_tmp_path($ver));
    }
}

/**
 * 任务视图：把状态文件转换为前端可渲染的结构（含各文件已下载字节数 / 完成标记）
 */
function ip_region_dl_task_view(?array $state): array
{
    $view = ['source' => '', 'status' => 'none', 'files' => ['v4' => null, 'v6' => null]];
    if ($state === null) {
        return $view;
    }
    foreach (['v4', 'v6'] as $ver) {
        $f = $state['files'][$ver] ?? null;
        if ($f === null) {
            $view['files'][$ver] = null;
            continue;
        }
        $tmp = ip_region_dl_tmp_path($ver);
        $bytes = is_file($tmp) ? (int)@filesize($tmp) : 0;
        $total = (int)($f['total'] ?? 0);
        $view['files'][$ver] = [
            'total' => $total,
            'range' => !empty($f['range']),
            'bytes' => $bytes,
            'done'  => ($total > 0) && ($bytes >= $total),
        ];
    }
    $view['source'] = (string)($state['source'] ?? '');
    $view['status'] = (string)($state['status'] ?? 'downloading');
    return $view;
}

/**
 * 探测远端文件：确认支持 HTTP Range 并获取总大小（发送 bytes=0-0 读取 Content-Range）。
 * 返回 ['ok' => bool, 'range' => bool, 'total' => int, 'error' => string, 'http_code' => int, 'curl_error' => string]
 */
function ip_region_dl_probe(string $url, array $headers): array
{
    $allHeaders = array_merge(['Range: bytes=0-0', 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'], $headers);
    $code = 0;
    $contentRange = '';
    $curlErrno = 0;
    $curlError = '';

    if (function_exists('curl_init')) {
        $ch = @curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'range' => false, 'total' => 0, 'error' => 'curl_init_failed', 'http_code' => 0, 'curl_error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$code, &$contentRange) {
                // 兼容 HTTP/1.1「HTTP/1.1 206」与 HTTP/2「HTTP/2 206」两种状态行
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $code = (int)$m[1];
                } elseif (stripos($line, 'Content-Range:') === 0) {
                    $contentRange = trim(substr($line, 15));
                }
                return strlen($line);
            },
        ]);
        @curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = (string)curl_error($ch);
        if ($code === 0) {
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }
        curl_close($ch);
    } else {
        // 无 curl 回退：stream 上下文 + $http_response_header
        $ctx = stream_context_create([
            'http' => ['timeout' => 30, 'follow_location' => 1, 'max_redirects' => 5, 'header' => implode("\r\n", $allHeaders)],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $http_response_header = [];
        @file_get_contents($url, false, $ctx);
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int)$m[1];
            } elseif (stripos($h, 'Content-Range:') === 0) {
                $contentRange = trim(substr($h, 15));
            }
        }
    }

    if ($code === 206 && preg_match('#/(\d+)\s*$#', $contentRange, $m)) {
        return ['ok' => true, 'range' => true, 'total' => (int)$m[1]];
    }
    return [
        'ok' => false, 'range' => false, 'total' => 0, 'error' => 'probe_failed',
        'http_code' => $code, 'curl_errno' => $curlErrno, 'curl_error' => $curlError,
    ];
}

/**
 * 下载一个分片并写入临时文件指定偏移（fseek 覆写，同一分片重试幂等）。
 * 要求远端支持 Range（返回 206），否则视为失败。
 * 返回 ['ok' => bool, 'written' => int, 'error' => string]
 */
function ip_region_dl_fetch(string $url, string $ver, int $offset, int $size, array $headers): array
{
    $dest = ip_region_dl_tmp_path($ver);
    $fp = @fopen($dest, 'c+b');
    if ($fp === false) {
        return ['ok' => false, 'written' => 0, 'error' => 'open_dest'];
    }
    fseek($fp, $offset);

    $range = 'Range: bytes=' . $offset . '-' . ($offset + $size - 1);
    $httpHeaders = array_merge([$range, 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'], $headers);
    $code = 0;
    $written = 0;
    $err = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => 150,
            CURLOPT_HTTPHEADER     => $httpHeaders,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_WRITEFUNCTION  => function ($ch, $data) use ($fp, &$written) {
                $n = fwrite($fp, $data);
                if ($n === false) {
                    return 0;
                }
                $written += $n;
                return $n;
            },
        ]);
        @curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = (string)curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            fclose($fp);
            return ['ok' => false, 'written' => $written, 'error' => 'download_failed'];
        }
    } else {
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 150, 'follow_location' => 1, 'max_redirects' => 5, 'header' => implode("\r\n", $httpHeaders)],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $http_response_header = [];
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in !== false) {
            $written = (int)stream_copy_to_stream($in, $fp, $size);
            fclose($in);
        }
        foreach ($http_response_header as $h) {
            if (stripos($h, 'HTTP/') === 0) {
                $code = (int)substr($h, 9, 3);
            }
        }
    }
    fclose($fp);

    if ($code !== 206) {
        return ['ok' => false, 'written' => $written, 'error' => 'no_range'];
    }
    if ($written < 1) {
        return ['ok' => false, 'written' => 0, 'error' => 'empty'];
    }
    return ['ok' => true, 'written' => $written, 'error' => ''];
}

/**
 * 校验并安装单个已下载完成的 xdb 临时文件（原子替换，安装成功后临时文件被消耗）。
 */
function ip_region_dl_install(string $ver): array
{
    $tmp = ip_region_dl_tmp_path($ver);
    $state = ip_region_dl_read_state();
    $total = (int)(($state['files'][$ver]['total'] ?? 0));
    $fs = @filesize($tmp);
    if ($fs === false || ($total > 0 && $fs < $total)) {
        return ['ok' => false, 'error' => 'incomplete'];
    }
    $hdr = ip_region_xdb_read_header($tmp);
    $expect = ($ver === 'v6') ? 6 : 4;
    if ($hdr === null || $hdr['ipVersion'] !== $expect) {
        return ['ok' => false, 'error' => 'bad_xdb'];
    }
    $r = ip_region_xdb_install($tmp, $ver);
    if (empty($r['success'])) {
        return ['ok' => false, 'error' => 'install_failed'];
    }
    return ['ok' => true, 'imported' => $r['imported'] ?? 0, 'size' => $r['size'] ?? 0];
}

/**
 * 可用下载源：github（官方仓库）/ cn（国内网盘 pan.szczk.top）。
 * 返回 ['label' => 展示名, 'headers' => 请求头, 'urls' => ['v4'=>.., 'v6'=>..]]。
 */
function ip_region_download_sources(): array
{
    return [
        'github' => [
            'label'   => 'GitHub',
            'headers' => [],
            'urls'    => [
                'v4' => 'https://raw.githubusercontent.com/lionsoul2014/ip2region/master/data/ip2region_v4.xdb',
                'v6' => 'https://raw.githubusercontent.com/lionsoul2014/ip2region/master/data/ip2region_v6.xdb',
            ],
        ],
        'cn' => [
            'label'   => '国内网盘',
            'headers' => ['Referer: https://pan.szczk.top/'],
            'urls'    => [
                'v4' => 'https://pan.szczk.top/f/d/xL4u1/ip2region_v4.xdb',
                'v6' => 'https://pan.szczk.top/f/d/l7PTx/ip2region_v6.xdb',
            ],
        ],
    ];
}
