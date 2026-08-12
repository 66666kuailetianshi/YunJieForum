<?php
/**
 * 云界论坛 - 系统更新中心（共享逻辑）
 *
 * 被以下两处共用：
 *   - app/admin/controllers/update_center.php  （后台页面）
 *   - app/admin/api/update_ajax.php           （检查 / 更新接口）
 *
 * 设计要点（安全优先）：
 *   1. 更新源（update_source_url）由管理员在后台配置，metadata 取 {base}/{channel}/version.json。
 *   2. 更新包必须提供 package_hash（sha256/sha1），下载后严格校验，校验失败不上线。
 *   3. 应用更新前先对现有代码（app/、public/ 及入口文件）做整包备份到 data/backups/，
 *      任何一步失败都回退、绝不留下半成品。
 *   4. 解包时禁止路径穿越、禁止覆盖 data/（保留用户数据、配置与数据库）。
 *   5. 自动更新（可选）按管理员设定的间隔触发，同样走「校验 + 备份 + 覆盖」全流程。
 */

if (!function_exists('uc_get_current_version')) {

    /**
     * 当前安装版本
     */
    function uc_get_current_version(): string {
        return defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
    }

    /**
     * 读取更新中心相关站点设置（与 functions.php 的 get_site_setting 解耦）
     */
    function uc_get_setting(string $k, string $def = ''): string {
        return function_exists('get_site_setting') ? (string)get_site_setting($k, $def) : $def;
    }

    /**
     * 从失败响应中提取可读的正文预览（压缩空白、限长，供错误诊断输出）
     */
    function uc_body_preview(string $body, int $maxLen = 300): string {
        $text = trim((string)preg_replace('/\s+/', ' ', $body));
        $len  = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($len > $maxLen) {
            $text = (function_exists('mb_substr') ? mb_substr($text, 0, $maxLen, 'UTF-8') : substr($text, 0, $maxLen)) . '…';
        }
        return $text;
    }

    /**
     * HTTP GET 取文本（优先 curl，回退 file_get_contents）
     *
     * 失败（传输错误或 HTTP 状态码 >= 400）时返回体附带 body 字段，
     * 即服务器实际返回的失败正文，供上层输出诊断。
     *
     * @param bool|null $sslVerify 是否严格校验 SSL 证书（自签名证书源应关闭）；传 null 时读取后台设置 update_ssl_verify，未配置默认开启校验
     */
    function uc_http_get(string $url, int $timeout = 15, ?bool $sslVerify = null): array {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        // 未显式传参时读取后台设置，默认开启 SSL 校验（防止更新通道被中间人篡改）
        if ($sslVerify === null) {
            $sslVerify = uc_get_setting('update_ssl_verify', '1') === '1';
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_ENCODING       => '',          // 支持压缩传输
                CURLOPT_HTTPHEADER     => ['Accept: */*'],
                CURLOPT_HEADER         => false,        // 不返回单独的头信息，统一从 $code/$data 中获取
            ]);
            $data = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            $curlErrNo = curl_errno($ch);
            $certInfo = [];
            if ($sslVerify && function_exists('curl_getinfo')) {
                $cert = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
                $certInfo = ['ssl_verify_result' => $cert];
            }
            curl_close($ch);
            if ($data === false || $curlErrNo !== 0) {
                $details = "curl errno=$curlErrNo";
                if (!empty($err)) $details .= ", error=$err";
                if (!empty($certInfo)) $details .= ", ".json_encode($certInfo);
                return ['ok' => false, 'error' => "curl_failed: $details", 'code' => $code];
            }
            if ($code >= 400) {
                // 把响应头也打出来：有些 CDN/WAF 会把错误页当成正常响应
                $respHeaders = [];
                foreach ($http_response_header ?? [] as $h) {
                    if (is_string($h)) $respHeaders[] = $h;
                }
                $headerStr = !empty($respHeaders) ? " headers:".json_encode($respHeaders) : '';
                return ['ok' => false, 'error' => "http_$code$headerStr", 'code' => $code, 'body' => (string)$data, 'headers' => $respHeaders];
            }
            return ['ok' => true, 'data' => $data, 'code' => $code];
        }
        $ctx = stream_context_create([
            'http'  => ['timeout' => $timeout, 'user_agent' => $ua],
            'https' => ['timeout' => $timeout, 'user_agent' => $ua, 'verify_peer' => $sslVerify, 'verify_host' => $sslVerify ? 2 : false],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        // file_get_contents 失败时仍可从 $http_response_header 拿到状态行
        $code = 200;
        if (is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $code = (int)$m[1];
                }
            }
        }
        if ($data === false) {
            $streamMeta = stream_context_get_params($ctx) ?? [];
            $metaKey = http_response_header();
            return ['ok' => false, 'error' => 'file_get_contents_failed', 'code' => $code];
        }
        if ($code >= 400) {
            return ['ok' => false, 'error' => 'http_' . $code, 'code' => $code, 'body' => (string)$data];
        }
        return ['ok' => true, 'data' => $data, 'code' => 200];
    }

    /**
     * HTTP 下载二进制到文件（优先 curl 流式写入）
     *
     * @param bool|null $sslVerify 是否严格校验 SSL 证书；传 null 时读取后台设置 update_ssl_verify，未配置默认开启校验
     * @param callable|null $progressCallback 进度回调 function($downloadedBytes, $totalBytes): void
     */
    function uc_http_download(string $url, string $dest, int $timeout = 600, ?bool $sslVerify = null, $progressCallback = null): array {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        if ($sslVerify === null) {
            $sslVerify = uc_get_setting('update_ssl_verify', '1') === '1';
        }
        if (function_exists('curl_init')) {
            $fp = @fopen($dest, 'wb');
            if ($fp === false) {
                return ['ok' => false, 'error' => 'cannot_create_tmp:' . $dest];
            }
            $ch = curl_init($url);
            $opts = [
                CURLOPT_FILE            => $fp,
                CURLOPT_TIMEOUT         => $timeout,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_USERAGENT       => $ua,
                CURLOPT_SSL_VERIFYPEER  => $sslVerify,
                CURLOPT_SSL_VERIFYHOST  => $sslVerify ? 2 : 0,
                CURLOPT_FOLLOWLOCATION  => true,
                CURLOPT_MAXREDIRS       => 5,
                CURLOPT_ENCODING        => '',
                CURLOPT_HTTPHEADER      => ['Accept: */*'],
                CURLOPT_NOPROGRESS      => false,
                CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($progressCallback) {
                    if ($progressCallback && is_callable($progressCallback)) {
                        call_user_func($progressCallback, $downloaded, $downloadSize);
                    }
                    return 0;
                },
            ];
            curl_setopt_array($ch, $opts);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            if ($err !== '') {
                return ['ok' => false, 'error' => $err];
            }
            if ($code >= 400) {
                // 读取失败响应正文预览（错误页/JSON 错误信息），便于诊断后清理临时文件
                $body = '';
                $fpr  = @fopen($dest, 'rb');
                if ($fpr) {
                    $body = (string)@fread($fpr, 1024);
                    fclose($fpr);
                }
                @unlink($dest);
                return ['ok' => false, 'error' => 'http_' . $code, 'code' => $code, 'body' => $body];
            }
            return ['ok' => true, 'code' => $code];
        }
        // curl 不可用时的回退：继承当前已解析的 SSL 校验策略，保持行为一致
        $res = uc_http_get($url, $timeout, $sslVerify);
        if (!$res['ok']) {
            return $res;
        }
        if (@file_put_contents($dest, $res['data']) === false) {
            return ['ok' => false, 'error' => 'write_tmp_failed'];
        }
        return ['ok' => true, 'code' => 200];
    }

    /**
     * metadata 地址
     * 支持两种模式：
     *   1. 目录基础地址：{base}/{channel}/version.json（如 https://example.com/updates）
     *   2. 直链文件地址：直接作为 version.json 使用（如 https://pan.example.com/f/xxx/version.txt）
     */
    function uc_metadata_url(): string {
        $url = trim(uc_get_setting('update_source_url'));
        if ($url === '') {
            return '';
        }
        $url = rtrim($url, '/');

        // 模式 2：直链文件（以 .json / .txt / .yml / .yaml 结尾）→ 直接使用
        if (preg_match('/\.(json|txt|yml|yaml)$/i', $url)) {
            return $url;
        }

        // 模式 1：目录基础地址 → 拼接 {channel}/version.json
        $channel = uc_get_setting('update_channel', 'stable');
        return $url . '/' . rawurlencode($channel) . '/version.json';
    }

    /**
     * 解析更新包下载地址
     *
     * 优先级：
     *   1. metadata 显式提供的 package_url（绝对地址，最高优先）
     *   2. 目录基础模式（update_source_url 以目录形式配置）→ 自动推导
     *      {base}/{channel}/version.json 的同级目录下的 update.zip
     *      （即 {base}/{channel}/update.zip）
     *   3. 直链文件模式（version.txt/json 直链）→ 无法推导，必须显式提供 package_url
     *
     * 这样即使 metadata 只写了版本号，只要把更新包按约定命名放在对应目录，
     * 系统也能自动定位并远程拉取，避免出现 no_package_url。
     */
    function uc_resolve_package_url(array $meta): string {
        if (!empty($meta['package_url']) && is_string($meta['package_url'])) {
            return trim($meta['package_url']);
        }
        $metaUrl = uc_metadata_url();
        if ($metaUrl === '') {
            return '';
        }
        // 直链文件模式：路径无法推导包地址，必须显式提供 package_url
        if (preg_match('/\.(json|txt|yml|yaml)$/i', $metaUrl)) {
            return '';
        }
        // 目录基础模式：去掉末尾的 version.json，拼接 update.zip
        $dir = preg_replace('#/[^/]*$#', '', rtrim($metaUrl, '/'));
        return $dir . '/update.zip';
    }

    /**
     * 拉取并解析 metadata
     * 支持 JSON 格式和纯文本格式（每行 key=value 或仅版本号）
     */
    function uc_fetch_metadata(): array {
        $url = uc_metadata_url();
        if ($url === '') {
            return ['ok' => false, 'error' => 'update_source_not_configured'];
        }
        $res = uc_http_get($url, 20);
        if (!$res['ok']) {
            $err = $res['error'];
            if (!empty($res['body'])) {
                $err .= ' | ' . uc_body_preview((string)$res['body']);
            }
            return ['ok' => false, 'error' => $err];
        }

        $body = trim($res['data']);
        $json = json_decode($body, true);

        // 标准 JSON 格式
        if (is_array($json) && !empty($json['version'])) {
            return ['ok' => true, 'meta' => $json];
        }

        // 容错：body 前面可能有 "version=X.Y.Z" 或 "version:X.Y.Z" 等前缀（如某些网盘自动追加）
        // 尝试从第一个 { 或 [ 开始提取 JSON 部分
        if (($pos = strpos($body, '{')) !== false || ($pos = strpos($body, '[')) !== false) {
            $jsonPart = substr($body, $pos);
            $json2 = json_decode($jsonPart, true);
            if (is_array($json2) && !empty($json2['version'])) {
                return ['ok' => true, 'meta' => $json2];
            }
        }

        // 纯文本格式：尝试解析 "version=1.3.6" 或裸版本号 "1.3.6"
        if (preg_match('/^(?:version\s*[:=]\s*)?([\d][\w.\-+]+)$/im', $body, $m)) {
            return ['ok' => true, 'meta' => [
                'version'      => trim($m[1]),
                'release_date' => '',
                'download_url' => '',
                'changelog'    => '',
                'sha256'       => '',
                'notes'        => '',
            ]];
        }

        // JSON 解析成功但无 version 字段
        if (is_array($json)) {
            return ['ok' => false, 'error' => 'metadata_no_version', 'debug_keys' => array_keys($json)];
        }

        return ['ok' => false, 'error' => 'metadata_invalid_json', 'preview' => substr($body, 0, 200)];
    }

    /**
     * 检查是否有可用更新（供页面与接口共用）
     */
    function uc_check_for_update(): array {
        $current = uc_get_current_version();
        $fetch   = uc_fetch_metadata();
        if (!$fetch['ok']) {
            return [
                'success' => false,
                'error'   => $fetch['error'],
                'current' => $current,
            ];
        }
        $meta   = $fetch['meta'];
        $latest = (string)$meta['version'];
        if (function_exists('set_site_setting')) {
            set_site_setting('update_last_check', (string)time());
        }
        return [
            'success'         => true,
            'current'         => $current,
            'latest'          => $latest,
            'update_available'=> version_compare($latest, $current, '>'),
            'channel'         => uc_get_setting('update_channel', 'stable'),
            'release_date'    => $meta['release_date'] ?? '',
            'changelog'       => $meta['changelog'] ?? '',
            'package_url'     => uc_resolve_package_url($meta),
            'package_hash'    => $meta['package_hash'] ?? '',
            'hash_algo'       => strtolower($meta['hash_algo'] ?? 'sha256'),
            'size'            => (int)($meta['size'] ?? 0),
            'min_version'     => $meta['min_version'] ?? '',
            'requires_php'    => $meta['requires_php'] ?? '',
            'checked_at'      => time(),
        ];
    }

    /**
     * 更新进度管理（基于临时 JSON 文件，供前端轮询）
     *
     * 进度文件路径：data/tmp/update_progress.json
     * 结构：{ stage, stage_label, progress(0-100), total, downloaded, message, error, done }
     */

    /** 获取进度文件路径 */
    function uc_progress_path(): string {
        return DATA_PATH . 'tmp' . DIRECTORY_SEPARATOR . 'update_progress.json';
    }

    /** 初始化/写入进度 */
    function uc_progress_write(array $data): void {
        $dir = DATA_PATH . 'tmp' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        @file_put_contents(uc_progress_path(), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** 读取进度（供轮询接口使用） */
    function uc_progress_read(): array {
        $path = uc_progress_path();
        if (!is_file($path)) { return ['stage' => '', 'done' => true]; }
        $raw = @file_get_contents($path);
        if ($raw === false) { return ['stage' => '', 'done' => true]; }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : ['stage' => '', 'done' => true];
    }

    /** 清除进度文件 */
    function uc_progress_clear(): void {
        @unlink(uc_progress_path());
    }

    /**
     * 是否安全的 http(s) 地址
     */
    function uc_is_safe_url(string $url): bool {
        return strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0;
    }

    /**
     * 备份当前代码（app/、public/ 及入口文件）到 data/backups/update_pre_{时间戳}.zip
     * 不备份 data/（保留用户数据、配置、数据库与历史备份）。
     */
    function uc_backup_files(): array {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'zip_extension_missing'];
        }
        $root      = rtrim(APP_ROOT, '/\\');
        $backupDir = DATA_PATH . 'backups' . DIRECTORY_SEPARATOR;
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }
        // Linux 上备份失败最常见根因：data/backups 对 Web 用户不可写
        if (!is_dir($backupDir) || !is_writable($backupDir)) {
            return [
                'ok'      => false,
                'error'   => 'backup_dir_not_writable',
                'details' => uc_dir_writability_report($backupDir, 'data/backups/'),
            ];
        }
        $stamp   = date('Ymd_His');
        $zipPath = $backupDir . 'update_pre_' . $stamp . '.zip';
        $zip     = new ZipArchive();
        $openRes = $zip->open($zipPath, ZipArchive::CREATE);
        if ($openRes !== true) {
            $failed = [
                'ok'      => false,
                'error'   => 'backup_zip_open_failed',
                'details' => [
                    'open_result' => is_int($openRes) ? $openRes : (string)$openRes,
                    'zip_status'  => (string)$zip->getStatusString(),
                    'dest'        => $zipPath,
                    'writable'    => is_writable($backupDir),
                    'disk_free'   => (($f = @disk_free_space($backupDir)) !== false) ? uc_format_bytes((int)$f) : 'unknown',
                    'hint'        => '无法在 data/backups 创建备份压缩包，请检查目录权限与磁盘剩余空间。',
                ],
            ];
            $zip->close();
            return $failed;
        }

        // 需要备份的代码位置
        $roots = ['app', 'public'];
        foreach ($roots as $dir) {
            $full = $root . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($full)) {
                continue;
            }
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iter as $fileInfo) {
                $path = $fileInfo->getPathname();
                $rel  = $dir . '/' . ltrim(str_replace($full, '', $path), '/\\');
                $rel  = str_replace('\\', '/', $rel);
                if ($fileInfo->isDir()) {
                    $zip->addEmptyDir($rel);
                } else {
                    $zip->addFile($path, $rel);
                }
            }
        }

        // 顶层入口文件
        $topFiles = ['index.php', 'install.php', 'LICENSE', 'README.md', 'README.en.md', 'README.zh-TW.md'];
        foreach ($topFiles as $tf) {
            $p = $root . DIRECTORY_SEPARATOR . $tf;
            if (is_file($p)) {
                $zip->addFile($p, $tf);
            }
        }

        $zip->close();
        if (!is_file($zipPath)) {
            return ['ok' => false, 'error' => 'backup_zip_not_created'];
        }
        return ['ok' => true, 'path' => $zipPath, 'size' => filesize($zipPath)];
    }

    /**
     * 将更新包解压覆盖到安装根目录（跳过 data/ 与路径穿越）
     *
     * 采用手动逐条目解压（getFromIndex + file_put_contents）而非 ZipArchive::extractTo：
     * extractTo 在目标文件已存在/只读/权限不足等情况下可能静默失败且无法得知具体文件，
     * 手动解压可精确感知每个文件的写入结果并返回失败清单。
     */
    function uc_extract_package(string $zipPath): array {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'zip_extension_missing'];
        }
        $root = rtrim(APP_ROOT, '/\\');
        $zip  = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'package_open_failed'];
        }
        $count  = 0;
        $failed = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            $norm = str_replace('\\', '/', $name);
            // 禁止路径穿越
            if (strpos($norm, '../') !== false || strpos($norm, '/..') !== false) {
                continue;
            }
            // 禁止覆盖 data/（保留用户数据与配置）
            if ($norm === 'data' || preg_match('#^data(/|$)#i', $norm)) {
                continue;
            }
            $dest = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $norm);
            // 目录条目：确保目录存在
            if (substr($norm, -1) === '/') {
                if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
                    $failed[] = $norm . ' (mkdir)';
                } else {
                    $count++;
                }
                continue;
            }
            // 文件条目：确保父目录存在
            $parent = dirname($dest);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
                $failed[] = $norm . ' (mkdir)';
                continue;
            }
            // Windows 上只读文件会导致写入失败：先解除只读再写入
            if (is_file($dest) && !is_writable($dest)) {
                @chmod($dest, 0644);
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $failed[] = $norm . ' (read)';
                continue;
            }
            if (@file_put_contents($dest, $content) === false) {
                // 附上 OS 级错误信息（如 Permission denied），便于 Linux 权限问题排查
                $lastErr = error_get_last();
                $why = ($lastErr['message'] ?? '') !== '' ? $lastErr['message'] : 'write_failed';
                $failed[] = $norm . ' (write): ' . $why;
                continue;
            }
            $count++;
        }
        $zip->close();
        if (!empty($failed)) {
            return ['ok' => false, 'files' => $count, 'failed' => $failed, 'error' => 'partial_extract_failed'];
        }
        return ['ok' => true, 'files' => $count];
    }

    /**
     * 读取更新包内 app/includes/config.php 声明的 APP_VERSION
     *
     * 用于解压前校验「包内实际版本」与「更新源声明版本」一致，
     * 防止更新包版本名不副实导致更新后版本不变、反复提示可更新。
     */
    function uc_package_version(string $zipPath): string {
        if (!class_exists('ZipArchive')) {
            return '';
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return '';
        }
        $content = $zip->getFromName('app/includes/config.php');
        $zip->close();
        if ($content === false) {
            return '';
        }
        if (preg_match('/define\s*\(\s*[\'"]APP_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * 执行一次完整更新：检查 → 下载 → 校验 → 备份 → 覆盖
     * 各阶段写入进度文件供前端轮询
     *
     * @param bool $force 强制模式：即使当前已是最新版本（版本号相同），
     *                    也重新下载更新包并覆盖安装（用于修复代码未更新到位等情况）
     */
    function uc_perform_update(bool $force = false): array {
        // 阶段 0：准备
        uc_progress_write(['stage' => 'preparing', 'stage_label' => 'preparing', 'progress' => 0, 'total' => 0, 'downloaded' => 0, 'message' => '', 'error' => null, 'done' => false]);

        $check = uc_check_for_update();
        // 区分「检查失败（网络错误等）」与「确实无更新」
        if (empty($check['success'])) {
            // 检查接口本身失败（网络错误、源未配置等），不应误报为「无更新」
            uc_progress_write(['stage' => 'error', 'stage_label' => 'error', 'progress' => 0, 'total' => 0, 'downloaded' => 0, 'message' => '', 'error' => 'check_failed: ' . ($check['error'] ?? 'unknown'), 'done' => true]);
            return [
                'success' => false,
                'error'   => 'check_failed: ' . ($check['error'] ?? 'unknown'),
                'current' => $check['current'] ?? '',
                'latest'  => $check['latest'] ?? '',
            ];
        }
        // 强制模式下允许同版本重新安装；非强制模式才拦截「已是最新」
        if (empty($check['update_available']) && !$force) {
            uc_progress_write(['stage' => 'error', 'stage_label' => 'error', 'progress' => 0, 'total' => 0, 'downloaded' => 0, 'message' => '', 'error' => 'no_update_available', 'done' => true]);
            return [
                'success' => false,
                'error'   => 'no_update_available',
                'current' => $check['current'] ?? '',
                'latest'  => $check['latest'] ?? '',
            ];
        }
        if (empty($check['package_url'])) {
            uc_progress_write(['stage' => 'error', 'stage_label' => 'error', 'progress' => 0, 'total' => 0, 'downloaded' => 0, 'message' => '', 'error' => 'no_package_url', 'done' => true]);
            return [
                'success' => false,
                'error'   => 'no_package_url',
                'hint'    => '更新源未提供更新包地址。请提供以下任一方式：① 在 version.json 中加入 package_url 字段（绝对下载地址）；② 将更新源配置为目录地址，并把更新包命名为 update.zip 放在「{通道}/」目录下。',
            ];
        }
        if (!uc_is_safe_url($check['package_url'])) {
            uc_progress_write(['stage' => 'error', 'stage_label' => 'error', 'progress' => 0, 'total' => 0, 'downloaded' => 0, 'message' => '', 'error' => 'invalid_package_url', 'done' => true]);
            return ['success' => false, 'error' => 'invalid_package_url'];
        }
        if (empty($check['package_hash'])) {
            // 默认强制要求哈希；若管理员显式开启「跳过哈希校验」且信任该更新源，则放行
            if (uc_get_setting('update_skip_hash', '0') !== '1') {
                uc_progress_write(['stage' => 'error', 'stage_label' => 'error', 'progress' => 0, 'total' => 0, 'downloaded' => 0, 'message' => '', 'error' => 'no_package_hash', 'done' => true]);
                return [
                    'success' => false,
                    'error'   => 'no_package_hash',
                    'hint'    => '更新包缺少哈希校验值（package_hash）。为安全起见默认禁止无校验更新。请在 version.json 中加入 package_hash（sha256 值）后重试，或在下方「更新设置」中开启「跳过哈希校验」（仅在信任该更新源时开启）。',
                ];
            }
        }

        $tmpDir = DATA_PATH . 'tmp' . DIRECTORY_SEPARATOR;
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $safeVer = preg_replace('/[^a-z0-9.]/i', '_', $check['latest']);
        $pkgPath = $tmpDir . 'update_pkg_' . $safeVer . '.zip';

        // 阶段 1：下载（0% → 80%，支持进度回调）
        uc_progress_write(['stage' => 'downloading', 'stage_label' => 'downloading', 'progress' => 1, 'total' => ($check['size'] > 0 ? (int)$check['size'] : 0), 'downloaded' => 0, 'message' => '', 'error' => null, 'done' => false]);
        $dlTotalSize = 0;
        $dlDownloaded = 0;
        // SSL 校验策略传 null：继承后台 update_ssl_verify 设置（默认开启），与元数据通道保持一致
        $dl = uc_http_download($check['package_url'], $pkgPath, 600, null, function ($downloaded, $total) use (&$dlDownloaded, &$dlTotalSize) {
            $dlDownloaded = $downloaded;
            $dlTotalSize = $total > 0 ? $total : $dlTotalSize;
            // 下载阶段占 1%-80%
            $pct = ($dlTotalSize > 0) ? min(79, max(1, (int)(($downloaded / $dlTotalSize) * 79))) : min(79, 1 + (int)($downloaded / (512 * 1024))); // 无大小时按每 512KB 进度
            uc_progress_write([
                'stage'       => 'downloading',
                'stage_label' => 'downloading',
                'progress'    => $pct,
                'total'       => $dlTotalSize,
                'downloaded'  => $downloaded,
                'message'     => '',
                'error'       => null,
                'done'        => false,
            ]);
        });
        if (!$dl['ok']) {
            @unlink($pkgPath);
            $dlErr = 'download_failed: ' . ($dl['error'] ?? '');
            if (!empty($dl['body'])) {
                $dlErr .= ' | ' . uc_body_preview((string)$dl['body']);
            }
            uc_progress_write(['stage' => 'error', 'stage_label' => 'error', 'progress' => 0, 'total' => $dlTotalSize, 'downloaded' => $dlDownloaded, 'message' => '', 'error' => $dlErr, 'done' => true]);
            return ['success' => false, 'error' => $dlErr];
        }

        // 阶段 2：校验哈希（80% → 85%）
        uc_progress_write(['stage' => 'verifying', 'stage_label' => 'verifying', 'progress' => 80, 'total' => $dlTotalSize, 'downloaded' => $dlDownloaded, 'message' => '', 'error' => null, 'done' => false]);
        $skipHash = ($check['package_hash'] === '');
        if (!$skipHash) {
            $algo   = ($check['hash_algo'] === 'sha1') ? 'sha1' : 'sha256';
            $actual = hash_file($algo, $pkgPath);
            if ($actual === false || strcasecmp($actual, $check['package_hash']) !== 0) {
                @unlink($pkgPath);
                uc_progress_write(['stage' => 'error', 'stage_label' => 'error', 'progress' => 80, 'total' => $dlTotalSize, 'downloaded' => $dlDownloaded, 'message' => '', 'error' => 'hash_mismatch', 'done' => true]);
                return [
                    'success' => false,
                    'error'   => 'hash_mismatch',
                    'expected'=> $check['package_hash'],
                    'actual'  => $actual,
                ];
            }
        }

        // 阶段 3：备份当前代码（85% → 92%）
        uc_progress_write(['stage' => 'backing_up', 'stage_label' => 'backing_up', 'progress' => 85, 'total' => $dlTotalSize, 'downloaded' => $dlDownloaded, 'message' => '', 'error' => null, 'done' => false]);
        $backup = uc_backup_files();
        if (!$backup['ok']) {
            @unlink($pkgPath);
            uc_progress_write(['stage' => 'error', 'stage_label' => 'error', 'progress' => 85, 'total' => $dlTotalSize, 'downloaded' => $dlDownloaded, 'message' => '', 'error' => 'backup_failed: ' . ($backup['error'] ?? ''), 'done' => true]);
            return [
                'success' => false,
                'error'   => 'backup_failed: ' . ($backup['error'] ?? ''),
                'details' => $backup['details'] ?? null,
            ];
        }

        // 阶段 3.5：校验包内版本与声明版本一致（92% → 95%）
        uc_progress_write(['stage' => 'verifying_pkg', 'stage_label' => 'verifying_pkg', 'progress' => 92, 'total' => $dlTotalSize, 'downloaded' => $dlDownloaded, 'message' => '', 'error' => null, 'done' => false]);
        $pkgVersion = uc_package_version($pkgPath);
        if ($pkgVersion !== '' && strcasecmp($pkgVersion, $check['latest']) !== 0) {
            @unlink($pkgPath);
            uc_progress_write(['stage' => 'error', 'stage_label' => 'error', 'progress' => 92, 'total' => $dlTotalSize, 'downloaded' => $dlDownloaded, 'message' => '', 'error' => 'package_version_mismatch', 'done' => true]);
            return [
                'success'        => false,
                'error'          => 'package_version_mismatch',
                'package_version'=> $pkgVersion,
                'declared'       => $check['latest'],
                'backup'         => $backup['path'],
            ];
        }

        // 阶段 4：解压覆盖（95% → 100%）
        uc_progress_write(['stage' => 'extracting', 'stage_label' => 'extracting', 'progress' => 95, 'total' => $dlTotalSize, 'downloaded' => $dlDownloaded, 'message' => '', 'error' => null, 'done' => false]);
        $extract = uc_extract_package($pkgPath);
        @unlink($pkgPath);
        if (!$extract['ok']) {
            uc_progress_write(['stage' => 'error', 'stage_label' => 'error', 'progress' => 95, 'total' => $dlTotalSize, 'downloaded' => $dlDownloaded, 'message' => '', 'error' => 'extract_failed: ' . ($extract['error'] ?? ''), 'done' => true]);
            return [
                'success' => false,
                'error'   => 'extract_failed: ' . ($extract['error'] ?? ''),
                'failed'  => $extract['failed'] ?? [],
                'backup'  => $backup['path'],
            ];
        }

        if (function_exists('set_site_setting')) {
            set_site_setting('update_last_version', $check['latest']);
            set_site_setting('update_last_check', (string)time());
        }
        uc_progress_write(['stage' => 'done', 'stage_label' => 'done', 'progress' => 100, 'total' => $dlTotalSize, 'downloaded' => $dlTotalSize, 'message' => '', 'error' => null, 'done' => true]);
        return [
            'success' => true,
            'from'    => $check['current'],
            'to'      => $check['latest'],
            'backup'  => $backup['path'],
            'files'   => $extract['files'],
        ];
    }

    /**
     * 上传错误码 → PHP 常量名（便于诊断输出）
     */
    function uc_upload_errno_name(int $code): string {
        $map = [
            UPLOAD_ERR_OK         => 'UPLOAD_ERR_OK',
            UPLOAD_ERR_INI_SIZE   => 'UPLOAD_ERR_INI_SIZE',
            UPLOAD_ERR_FORM_SIZE  => 'UPLOAD_ERR_FORM_SIZE',
            UPLOAD_ERR_PARTIAL    => 'UPLOAD_ERR_PARTIAL',
            UPLOAD_ERR_NO_FILE    => 'UPLOAD_ERR_NO_FILE',
            UPLOAD_ERR_NO_TMP_DIR => 'UPLOAD_ERR_NO_TMP_DIR',
            UPLOAD_ERR_CANT_WRITE => 'UPLOAD_ERR_CANT_WRITE',
            UPLOAD_ERR_EXTENSION  => 'UPLOAD_ERR_EXTENSION',
        ];
        return $map[$code] ?? 'UNKNOWN';
    }

    /**
     * 目录可写性诊断：目录是否存在 / 是否需要创建 / 是否可写 / 属主与权限
     *
     * 用于 Linux 上「手动上传更新包失败」的排查（最常见根因：
     * data/tmp 或 data/backups 对 Web 用户如 www-data 不可写）。
     */
    function uc_dir_writability_report(string $dir, string $label = ''): array {
        $exists = is_dir($dir);
        $mkdirOk = null;
        if (!$exists) {
            $mkdirOk = @mkdir($dir, 0755, true) || is_dir($dir);
            $exists  = is_dir($dir);
        }
        $writable = $exists && is_writable($dir);
        $owner = false;
        if ($exists) {
            $owner = @fileowner($dir);
            if ($owner !== false && function_exists('posix_getpwuid')) {
                $pw = @posix_getpwuid((int)$owner);
                if (is_array($pw) && !empty($pw['name'])) {
                    $owner = $pw['name'] . ' (uid:' . (int)$owner . ')';
                }
            }
        }
        $perms = ($exists && ($p = @fileperms($dir)) !== false) ? substr(sprintf('%o', $p), -4) : '';
        return [
            'label'         => $label !== '' ? $label : $dir,
            'path'          => $dir,
            'realpath'      => $exists ? realpath($dir) : '',
            'exists'        => $exists,
            'mkdir_attempt' => $mkdirOk,
            'writable'      => $writable,
            'owner'         => $owner,
            'perms'         => $perms,
            'hint'          => $writable ? '' : '目录不存在或 Web 用户（如 www-data）无写权限。可执行：chown -R www-data:www-data ' . rtrim(DATA_PATH, '/\\') . ' 后再试（或 chmod -R 775）。',
        ];
    }

    /**
     * 上传环境诊断信息：PHP 上传限制、上传错误码、文件信息、目标目录状态。
     * 供失败响应附带 details 字段，方便 Linux 等环境排查上传失败根因。
     */
    function uc_upload_env_details(string $reason, array $file = [], int $maxSize = 0): array {
        $errMap = [
            'upload_err_ini_size'    => '文件大小超过 PHP upload_max_filesize 限制',
            'upload_err_form_size'   => '文件大小超过表单 MAX_FILE_SIZE 限制',
            'upload_err_partial'     => '文件仅上传了一部分（网络中断或代理截断）',
            'upload_err_no_file'     => '未收到上传文件（可能未选择文件或请求未附带文件）',
            'upload_err_no_tmp_dir'  => 'PHP 临时上传目录（upload_tmp_dir）不存在或不可用',
            'upload_err_cant_write'  => 'PHP 无法将上传文件写入临时目录（upload_tmp_dir 无写权限）',
            'upload_err_extension'   => '上传被 PHP 扩展（如 fileinfo）中止',
            'upload_err_unknown'     => '未知上传错误',
            'not_zip'                => '仅支持 .zip 格式的更新包',
            'file_too_large'         => '文件超过系统上限 ' . uc_format_bytes($maxSize),
            'zip_extension_missing'  => '服务器未启用 ZipArchive 扩展（php-zip）',
            'not_uploaded_file'      => '上传文件来源无效（is_uploaded_file 校验失败，可能被代理/防火墙改写）',
            'move_failed'            => 'move_uploaded_file 失败：无法写入 data/tmp 目标目录',
        ];
        $tmpDir  = DATA_PATH . 'tmp' . DIRECTORY_SEPARATOR;
        $dirInfo = uc_dir_writability_report($tmpDir, 'data/tmp/');
        return [
            'reason'      => $reason,
            'reason_text' => $errMap[$reason] ?? $reason,
            'php'         => [
                'version'             => PHP_VERSION,
                'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
                'post_max_size'       => (string)ini_get('post_max_size'),
                'max_file_uploads'    => (string)ini_get('max_file_uploads'),
                'upload_tmp_dir'      => (string)ini_get('upload_tmp_dir') ?: '(系统默认)',
            ],
            'upload'      => [
                'error_code'    => (int)($file['error'] ?? -1),
                'error_name'    => uc_upload_errno_name((int)($file['error'] ?? -1)),
                'original_name' => (string)($file['name'] ?? ''),
                'size'          => (int)($file['size'] ?? 0),
                'size_text'     => uc_format_bytes((int)($file['size'] ?? 0)),
                'tmp_name'      => (string)($file['tmp_name'] ?? ''),
            ],
            'data_tmp'    => $dirInfo,
            'dest_path'   => $tmpDir . 'upload_update_input.zip',
        ];
    }

    /**
     * 保存手动上传的更新包（.zip）到 data/tmp/upload_update_input.zip
     *
     * 与远程更新的区别：上传包来自管理员本地文件，无需下载与哈希校验；
     * 仍会做基础校验（扩展名、大小、ZIP 有效性）。
     *
     * 失败时返回结构附带 details（上传错误码 / PHP 上传限制 / data/tmp 目录
     * 可写性 / move_uploaded_file 失败原因等），前端据此输出完整诊断，便于
     * Linux 等环境排查「手动上传更新包失败」的根因。
     */
    function uc_save_upload_package(array $file): array {
        // PHP 上传错误码 → 可读键名（Linux 上常见：upload_max_filesize/post_max_size 过小、upload_tmp_dir 无权限）
        $uploadErrMap = [
            UPLOAD_ERR_INI_SIZE   => 'upload_err_ini_size',
            UPLOAD_ERR_FORM_SIZE  => 'upload_err_form_size',
            UPLOAD_ERR_PARTIAL    => 'upload_err_partial',
            UPLOAD_ERR_NO_FILE    => 'upload_err_no_file',
            UPLOAD_ERR_NO_TMP_DIR => 'upload_err_no_tmp_dir',
            UPLOAD_ERR_CANT_WRITE => 'upload_err_cant_write',
            UPLOAD_ERR_EXTENSION  => 'upload_err_extension',
        ];
        $errCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
            $key = $uploadErrMap[$errCode] ?? 'upload_err_unknown';
            return ['ok' => false, 'error' => $key, 'details' => uc_upload_env_details($key, $file)];
        }
        $name = strtolower((string)($file['name'] ?? ''));
        if (substr($name, -4) !== '.zip') {
            return ['ok' => false, 'error' => 'not_zip', 'details' => uc_upload_env_details('not_zip', $file)];
        }
        // 与数据迁移 ZIP 上限保持一致
        $maxSize = 256 * 1024 * 1024;
        if ((int)($file['size'] ?? 0) > $maxSize) {
            return ['ok' => false, 'error' => 'file_too_large', 'details' => uc_upload_env_details('file_too_large', $file, $maxSize)];
        }
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'zip_extension_missing', 'details' => uc_upload_env_details('zip_extension_missing', $file)];
        }
        $tmpDir = DATA_PATH . 'tmp' . DIRECTORY_SEPARATOR;
        $dest   = $tmpDir . 'upload_update_input.zip';

        // 目录诊断：Linux 下最常见原因是 data/tmp 对 Web 用户不可写（mkdir 加 @ 静默失败）
        $dirInfo = uc_dir_writability_report($tmpDir, 'data/tmp/');
        if (!$dirInfo['writable']) {
            return ['ok' => false, 'error' => 'tmp_dir_not_writable', 'details' => $dirInfo];
        }

        // 只接受 PHP 上传产生的临时文件（is_uploaded_file 校验），防止伪造路径文件
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            return ['ok' => false, 'error' => 'not_uploaded_file', 'details' => uc_upload_env_details('not_uploaded_file', $file)];
        }
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $lastErr = error_get_last();
            $details = uc_upload_env_details('move_failed', $file);
            $details['dest_existed'] = file_exists($dest);
            $details['last_error']   = ($lastErr['message'] ?? '') !== '' ? $lastErr['message'] : '';
            $free = @disk_free_space($tmpDir);
            $details['disk_free']    = $free !== false ? uc_format_bytes((int)$free) : 'unknown';
            return ['ok' => false, 'error' => 'move_failed', 'details' => $details];
        }
        // 用 ZipArchive 打开校验压缩包有效性，无效则清理并拒绝
        $zip = new ZipArchive();
        $openRes = $zip->open($dest);
        $zipStatus = (string)$zip->getStatusString();
        if ($openRes !== true || $zip->numFiles < 1) {
            $badSize = (int)@filesize($dest);
            $zip->close();
            @unlink($dest);
            return [
                'ok'      => false,
                'error'   => 'invalid_zip',
                'details' => [
                    'zip_open_result' => is_int($openRes) ? $openRes : (string)$openRes,
                    'zip_status'      => $zipStatus,
                    'file_size'       => uc_format_bytes($badSize),
                    'hint'            => '压缩包可能已损坏，或不是有效的 ZIP 文件。请重新打包后上传。',
                ],
            ];
        }
        $zip->close();
        return ['ok' => true, 'path' => $dest, 'size' => (int)$file['size']];
    }

    /**
     * 解析已保存的上传更新包信息（包内版本 / 文件数 / 与当前版本的关系）
     * 供前端在安装前预览确认。
     */
    function uc_inspect_upload_package(string $zipPath): array {
        if (!is_file($zipPath)) {
            return ['ok' => false, 'error' => 'pkg_not_found'];
        }
        $pkgVersion = uc_package_version($zipPath);
        $fileCount  = 0;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) === true) {
                $fileCount = $zip->numFiles;
                $zip->close();
            }
        }
        $current = uc_get_current_version();
        $cmp = ($pkgVersion === '') ? 0 : version_compare($pkgVersion, $current);
        return [
            'ok'       => true,
            'version'  => $pkgVersion,
            'current'  => $current,
            'relation' => $pkgVersion === '' ? 'unknown' : ($cmp > 0 ? 'upgrade' : ($cmp < 0 ? 'downgrade' : 'same')),
            'files'    => $fileCount,
            'size'     => (int)@filesize($zipPath),
        ];
    }

    /**
     * 执行手动上传更新包的安装：备份 → 解压覆盖 → 记录版本
     *
     * 安全约束：
     *   - 包内必须能识别 APP_VERSION（缺少 config.php 视为无效更新包，拒绝安装，防呆）；
     *   - 安装前自动备份当前代码（uc_backup_files），失败即中止；
     *   - 解压沿用 uc_extract_package 的路径穿越防护与 data/ 保护。
     *
     * @param string $zipPath 已保存到 data/tmp/ 的上传包路径
     */
    function uc_perform_upload_update(string $zipPath): array {
        // 防呆：必须是能识别版本的云界论坛更新包
        $pkgVersion = uc_package_version($zipPath);
        if ($pkgVersion === '') {
            @unlink($zipPath);
            return ['success' => false, 'error' => 'bad_package'];
        }

        // 阶段 1：备份当前代码
        uc_progress_write(['stage' => 'backing_up', 'stage_label' => 'backing_up', 'progress' => 10, 'total' => 0, 'downloaded' => 0, 'message' => '', 'error' => null, 'done' => false]);
        $backup = uc_backup_files();
        if (!$backup['ok']) {
            @unlink($zipPath);
            return [
                'success' => false,
                'error'   => 'backup_failed: ' . ($backup['error'] ?? ''),
                'backup'  => '',
                'details' => $backup['details'] ?? null,
            ];
        }

        // 阶段 2：解压覆盖
        uc_progress_write(['stage' => 'extracting', 'stage_label' => 'extracting', 'progress' => 60, 'total' => 0, 'downloaded' => 0, 'message' => '', 'error' => null, 'done' => false]);
        $extract = uc_extract_package($zipPath);
        @unlink($zipPath); // 安装完成（或失败）后清理上传包
        if (!$extract['ok']) {
            return [
                'success' => false,
                'error'   => 'extract_failed: ' . ($extract['error'] ?? ''),
                'failed'  => $extract['failed'] ?? [],
                'backup'  => $backup['path'],
            ];
        }

        // 阶段 3：记录本次安装的版本
        if (function_exists('set_site_setting')) {
            set_site_setting('update_last_version', $pkgVersion);
            set_site_setting('update_last_check', (string)time());
        }
        uc_progress_write(['stage' => 'done', 'stage_label' => 'done', 'progress' => 100, 'total' => 0, 'downloaded' => 0, 'message' => '', 'error' => null, 'done' => true]);
        return [
            'success' => true,
            'from'    => uc_get_current_version(),
            'to'      => $pkgVersion,
            'backup'  => $backup['path'],
            'files'   => $extract['files'],
        ];
    }

    /**
     * 历史更新备份目录（与数据备份共用 data/backups/，以 update_pre_ 前缀区分）
     */
    function uc_update_backup_dir(): string {
        return DATA_PATH . 'backups' . DIRECTORY_SEPARATOR;
    }

    /**
     * 校验历史更新备份文件名（update_pre_Ymd_His.zip），防止路径穿越
     */
    function uc_is_update_backup_name(string $filename): bool {
        return (bool)preg_match('/^update_pre_\d{8}_\d{6}\.zip$/', $filename);
    }

    /**
     * 从文件名解析备份时间（update_pre_Ymd_His.zip），解析失败回退文件 mtime
     */
    function uc_update_backup_time(string $filename, string $path): int {
        if (preg_match('/^update_pre_(\d{8}_\d{6})\.zip$/', $filename, $m)) {
            $dt = DateTime::createFromFormat('Ymd_His', $m[1]);
            if ($dt !== false) {
                return $dt->getTimestamp();
            }
        }
        return (int)@filemtime($path);
    }

    /**
     * 列出全部历史更新备份（时间倒序）
     *
     * @return array [{filename, size, time}]
     */
    function uc_list_update_backups(): array {
        $dir   = uc_update_backup_dir();
        $files = is_dir($dir) ? glob($dir . 'update_pre_*.zip') : [];
        $list  = [];
        foreach ((array)$files as $path) {
            $name = basename($path);
            if (!uc_is_update_backup_name($name)) {
                continue;
            }
            $list[] = [
                'filename' => $name,
                'size'     => (int)@filesize($path),
                'time'     => uc_update_backup_time($name, $path),
            ];
        }
        usort($list, function ($a, $b) {
            return ($b['time'] <=> $a['time']) ?: strcmp($b['filename'], $a['filename']);
        });
        return $list;
    }

    /**
     * 删除指定历史更新备份（仅允许符合命名规则的文件），同时清理分享记录
     */
    function uc_delete_update_backup(string $filename): array {
        if (!uc_is_update_backup_name($filename)) {
            return ['success' => false, 'error' => 'invalid_filename'];
        }
        $path = uc_update_backup_dir() . $filename;
        if (!is_file($path)) {
            return ['success' => false, 'error' => 'not_found'];
        }
        if (!@unlink($path)) {
            return ['success' => false, 'error' => 'delete_failed'];
        }
        @unlink(uc_update_backup_share_path($filename));
        return ['success' => true];
    }

    /**
     * 分享记录文件路径（与备份同目录，{filename}.share.json）
     */
    function uc_update_backup_share_path(string $filename): string {
        return uc_update_backup_dir() . $filename . '.share.json';
    }

    /**
     * 读取分享记录；不存在或已过期返回 null（过期时顺带清理）
     */
    function uc_get_update_backup_share(string $filename): ?array {
        if (!uc_is_update_backup_name($filename)) {
            return null;
        }
        $metaPath = uc_update_backup_share_path($filename);
        if (!is_file($metaPath)) {
            return null;
        }
        $raw = @file_get_contents($metaPath);
        $meta = $raw === false ? null : json_decode($raw, true);
        if (!is_array($meta) || empty($meta['token']) || empty($meta['expires'])) {
            return null;
        }
        if (time() >= (int)$meta['expires']) {
            @unlink($metaPath);
            return null;
        }
        return $meta;
    }

    /**
     * 创建（或复用未过期的）分享链接
     *
     * 令牌为 48 位随机十六进制串，无法由其他信息推导；默认 7 天过期，
     * 到期后链接自动失效。重复调用返回同一链接，直至过期。
     */
    function uc_create_update_backup_share(string $filename, int $ttlDays = 7): array {
        if (!uc_is_update_backup_name($filename)) {
            return ['success' => false, 'error' => 'invalid_filename'];
        }
        $path = uc_update_backup_dir() . $filename;
        if (!is_file($path)) {
            return ['success' => false, 'error' => 'not_found'];
        }
        $meta = uc_get_update_backup_share($filename);
        if ($meta === null) {
            $meta = [
                'token'   => bin2hex(random_bytes(24)),
                'created' => time(),
                'expires' => time() + $ttlDays * 86400,
            ];
            if (@file_put_contents(uc_update_backup_share_path($filename), json_encode($meta, JSON_UNESCAPED_UNICODE)) === false) {
                return ['success' => false, 'error' => 'share_write_failed'];
            }
        }
        // 生成完整绝对链接：优先用「当前访问域名」推导（多域名/镜像部署也能点开即用），
        // CLI 等无请求上下文时才回退 SITE_URL 配置/自动推导
        $relUrl = function_exists('site_url')
            ? site_url('api/share_backup', ['file' => $filename, 'token' => $meta['token']])
            : ('/index.php?route=api/share_backup&file=' . urlencode($filename) . '&token=' . $meta['token']);
        $base = function_exists('current_site_url') ? current_site_url() : '';
        if ($base === '') {
            $base = function_exists('site_absolute_url') ? site_absolute_url() : '';
        }
        return ['success' => true, 'url' => $base . $relUrl, 'expires' => (int)$meta['expires']];
    }

    /**
     * 人类可读的字节大小
     */
    function uc_format_bytes(int $bytes): string {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = min((int)floor(log($bytes, 1024)), count($units) - 1);
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    /**
     * 自动更新触发（按间隔，由后台页面加载时调用）
     */
    function uc_try_auto_update(): array {
        if (uc_get_setting('update_auto_enabled', '0') !== '1') {
            return ['ran' => false, 'reason' => 'disabled'];
        }
        $interval = (int)uc_get_setting('update_auto_interval', '24');
        if ($interval < 1) {
            $interval = 1;
        }
        $last = (int)uc_get_setting('update_last_check', '0');
        if (time() - $last < $interval * 3600) {
            return ['ran' => false, 'reason' => 'interval'];
        }
        try {
            $res = uc_perform_update();
            return ['ran' => true, 'result' => $res];
        } catch (\Throwable $e) {
            return ['ran' => true, 'result' => ['success' => false, 'error' => 'exception: ' . $e->getMessage()]];
        }
    }
}
