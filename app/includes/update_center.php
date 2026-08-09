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
     * HTTP GET 取文本（优先 curl，回退 file_get_contents）
     *
     * @param bool $sslVerify 是否严格校验 SSL 证书（自签名证书源应关闭）
     */
    function uc_http_get(string $url, int $timeout = 15, bool $sslVerify = false): array {
        $ua = 'YunjieForum-Updater/' . uc_get_current_version();
        // 优先读取后台设置，未配置时默认跳过 SSL 校验（兼容自签名证书源）
        if ($sslVerify === null) {
            $sslVerify = uc_get_setting('update_ssl_verify', '0') === '1';
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);
            $data = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($data === false) {
                return ['ok' => false, 'error' => ($err !== '' ? $err : 'curl_failed'), 'code' => $code];
            }
            return ['ok' => true, 'data' => $data, 'code' => $code];
        }
        $ctx = stream_context_create([
            'http'  => ['timeout' => $timeout, 'user_agent' => $ua],
            'https' => ['timeout' => $timeout, 'user_agent' => $ua, 'verify_peer' => $sslVerify, 'verify_host' => $sslVerify ? 2 : false],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            return ['ok' => false, 'error' => 'file_get_contents_failed', 'code' => 0];
        }
        return ['ok' => true, 'data' => $data, 'code' => 200];
    }

    /**
     * HTTP 下载二进制到文件（优先 curl 流式写入）
     *
     * @param bool $sslVerify 是否严格校验 SSL 证书
     */
    function uc_http_download(string $url, string $dest, int $timeout = 600, bool $sslVerify = false): array {
        $ua = 'YunjieForum-Updater/' . uc_get_current_version();
        if ($sslVerify === null) {
            $sslVerify = uc_get_setting('update_ssl_verify', '0') === '1';
        }
        if (function_exists('curl_init')) {
            $fp = @fopen($dest, 'wb');
            if ($fp === false) {
                return ['ok' => false, 'error' => 'cannot_create_tmp:' . $dest];
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE            => $fp,
                CURLOPT_TIMEOUT         => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT       => $ua,
                CURLOPT_SSL_VERIFYPEER  => $sslVerify,
                CURLOPT_SSL_VERIFYHOST  => $sslVerify ? 2 : 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS       => 3,
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            if ($err !== '') {
                return ['ok' => false, 'error' => $err];
            }
            if ($code >= 400) {
                return ['ok' => false, 'error' => 'http_' . $code];
            }
            return ['ok' => true, 'code' => $code];
        }
        $res = uc_http_get($url, $timeout);
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
            return ['ok' => false, 'error' => $res['error']];
        }

        $body = trim($res['data']);
        $json = json_decode($body, true);

        // 标准 JSON 格式
        if (is_array($json) && !empty($json['version'])) {
            return ['ok' => true, 'meta' => $json];
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
            'package_url'     => $meta['package_url'] ?? '',
            'package_hash'    => $meta['package_hash'] ?? '',
            'hash_algo'       => strtolower($meta['hash_algo'] ?? 'sha256'),
            'size'            => (int)($meta['size'] ?? 0),
            'min_version'     => $meta['min_version'] ?? '',
            'requires_php'    => $meta['requires_php'] ?? '',
            'checked_at'      => time(),
        ];
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
        $stamp   = date('Ymd_His');
        $zipPath = $backupDir . 'update_pre_' . $stamp . '.zip';
        $zip     = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            return ['ok' => false, 'error' => 'backup_zip_open_failed'];
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
        $count = 0;
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
            if ($zip->extractTo($root, [$name])) {
                $count++;
            }
        }
        $zip->close();
        return ['ok' => true, 'files' => $count];
    }

    /**
     * 执行一次完整更新：检查 → 下载 → 校验 → 备份 → 覆盖
     */
    function uc_perform_update(): array {
        $check = uc_check_for_update();
        if (!empty($check['success']) && empty($check['update_available'])) {
            return [
                'success' => false,
                'error'   => 'no_update_available',
                'current' => $check['current'] ?? '',
                'latest'  => $check['latest'] ?? '',
            ];
        }
        if (empty($check['package_url'])) {
            return ['success' => false, 'error' => 'no_package_url'];
        }
        if (!uc_is_safe_url($check['package_url'])) {
            return ['success' => false, 'error' => 'invalid_package_url'];
        }
        if (empty($check['package_hash'])) {
            return ['success' => false, 'error' => 'no_package_hash'];
        }

        $tmpDir = DATA_PATH . 'tmp' . DIRECTORY_SEPARATOR;
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $safeVer = preg_replace('/[^a-z0-9.]/i', '_', $check['latest']);
        $pkgPath = $tmpDir . 'update_pkg_' . $safeVer . '.zip';

        $dl = uc_http_download($check['package_url'], $pkgPath, 600);
        if (!$dl['ok']) {
            @unlink($pkgPath);
            return ['success' => false, 'error' => 'download_failed: ' . ($dl['error'] ?? '')];
        }

        // 校验哈希
        $algo   = ($check['hash_algo'] === 'sha1') ? 'sha1' : 'sha256';
        $actual = hash_file($algo, $pkgPath);
        if ($actual === false || strcasecmp($actual, $check['package_hash']) !== 0) {
            @unlink($pkgPath);
            return [
                'success' => false,
                'error'   => 'hash_mismatch',
                'expected'=> $check['package_hash'],
                'actual'  => $actual,
            ];
        }

        // 备份当前代码
        $backup = uc_backup_files();
        if (!$backup['ok']) {
            @unlink($pkgPath);
            return ['success' => false, 'error' => 'backup_failed: ' . ($backup['error'] ?? '')];
        }

        // 覆盖解包
        $extract = uc_extract_package($pkgPath);
        @unlink($pkgPath);
        if (!$extract['ok']) {
            return [
                'success' => false,
                'error'   => 'extract_failed: ' . ($extract['error'] ?? ''),
                'backup'  => $backup['path'],
            ];
        }

        if (function_exists('set_site_setting')) {
            set_site_setting('update_last_version', $check['latest']);
            set_site_setting('update_last_check', (string)time());
        }
        return [
            'success' => true,
            'from'    => $check['current'],
            'to'      => $check['latest'],
            'backup'  => $backup['path'],
            'files'   => $extract['files'],
        ];
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
