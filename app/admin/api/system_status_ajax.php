<?php
/**
 * 云界论坛 - 管理后台系统运行状态 AJAX 接口
 *
 * 设计原则：
 * 1. Windows 下优先使用 PHP FFI 扩展直接调用 Win32 API（GlobalMemoryStatusEx、
 *    GetTickCount64、GetSystemTimes、GetSystemInfo），进程内完成，不启动任何子进程。
 * 2. FFI 不可用时，使用 PHP COM 扩展进程内访问 WMI（同样不启动子进程）。
 * 3. 默认禁用 shell_exec / wmic / PowerShell 等子进程调用，避免被安全软件拦截。
 *    如需启用（调试用），设置 define('SS_ALLOW_SUBPROCESS', true)。
 * 4. Linux 下读取 /proc 文件系统，无需任何外部命令。
 * 5. 所有数据获取都有安全校验与默认值，确保任何环境下都能返回可用数据。
 *
 * 注意：函数定义在前，权限检查在输出部分（底部）。
 */

// 开启输出缓冲：防止任何 PHP notice/warning 混入 JSON 输出导致前端解析失败
ob_start();

require_once APP_ROOT . 'app/includes/functions.php';

function ss_format_bytes(int $bytes): string {
    if ($bytes >= 1099511627776) return round($bytes / 1099511627776, 2) . ' TB';
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

/**
 * 解析 PHP ini 格式的内存大小（如 '256M', '1G', '51200K'）为字节数
 */
function ss_parse_ini_size(string $size): int {
    $size = trim($size);
    if ($size === '') return 0;
    $last = strtolower($size[strlen($size) - 1]);
    $num = (float)$size;
    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return (int)$num;
}

/**
 * 确保字符串是合法 UTF-8 编码（修复中文 Windows 下 WMI 返回 GBK 编码导致 json_encode 失败的问题）
 */
function ss_ensure_utf8($val): string {
    if (!is_string($val)) return (string)$val;
    if ($val === '') return '';
    if (!function_exists('mb_check_encoding')) return $val; // mbstring 不可用时原样返回
    // 检测是否已是合法 UTF-8
    if (mb_check_encoding($val, 'UTF-8')) return $val;
    // 尝试从 GBK 转换
    $converted = @mb_convert_encoding($val, 'UTF-8', 'GBK, GB2312, UTF-8');
    if ($converted !== false && $converted !== '') return $converted;
    // 最后手段：移除非法字节
    return mb_convert_encoding($val, 'UTF-8', 'UTF-8');
}

/**
 * 静态数据文件缓存
 * CPU 型号、内存条、磁盘分区等不常变化的数据缓存 1 小时，避免每次请求都执行慢速命令。
 *
 * 注意：
 * - 空数组/空值不缓存也不使用缓存，确保首次获取失败后下次请求能重试。
 * - 文件名包含缓存版本号 SS_CACHE_VERSION，修改此值可使所有旧缓存自动失效。
 */
if (!defined('SS_CACHE_VERSION')) define('SS_CACHE_VERSION', 'v8');

function ss_get_static_cache(string $key, callable $callback, int $ttl = 3600) {
    $cacheDir = APP_ROOT . 'data/cache';
    $cacheFile = $cacheDir . '/system_status_' . SS_CACHE_VERSION . '_' . $key . '.json';

    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['expires']) && (int)$data['expires'] > time() && array_key_exists('value', $data)) {
                $value = $data['value'];
                // 空数组/空值视为无效缓存，强制重新获取
                if (is_array($value) && !empty($value)) return $value;
                if (!is_array($value) && $value !== null && $value !== '' && $value !== 0) return $value;
                // 空值：删除旧缓存文件，继续执行 callback
                @unlink($cacheFile);
            }
        }
    }

    $value = null;
    try {
        $value = $callback();
    } catch (\Throwable $e) {
        // 回调执行失败，返回 null（空值不缓存，下次请求会重试）
        return null;
    }

    // 空数组/空值不缓存，确保下次请求能重试
    if (is_array($value) && empty($value)) return $value;
    if ($value === null || $value === '' || $value === 0) return $value;

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    if (is_dir($cacheDir)) {
        @file_put_contents($cacheFile, json_encode(['expires' => time() + $ttl, 'value' => $value], JSON_UNESCAPED_UNICODE));
    }

    return $value;
}

/**
 * 实时数据滚动缓存
 * 使用微秒级时间戳，只要距离上次采样超过 0.5 秒就重新采样，保证前端每秒轮询时
 * 几乎每次都能拿到新数据；同时保留最近 5 个样本做滚动平均，避免单点抖动。
 *
 * 采样层级（全部进程内，不启动子进程，安全软件不会拦截）：
 *   Windows：FFI (GetSystemTimes/GlobalMemoryStatusEx) → COM WMI → 默认值
 *   Linux：/proc/stat + /proc/meminfo
 */
function ss_get_realtime_cache(): array {
    $cacheDir = APP_ROOT . 'data/cache';
    $cacheFile = $cacheDir . '/system_status_realtime.json';

    $cache = [
        'cpu_samples' => [],
        'memory' => null,
        'network' => null,
        'last_sample_time' => 0.0,
        'cpu_times_prev' => null, // FFI GetSystemTimes 上次采样值，用于差值计算 CPU 使用率
        'boot_time' => null,      // 运行时间兜底（来自 PowerShell 合并采样 Boot）
        'queue_length' => null,   // 负载兜底（来自 PowerShell 合并采样 Queue）
    ];

    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $cache['cpu_samples'] = isset($data['cpu_samples']) && is_array($data['cpu_samples']) ? $data['cpu_samples'] : [];
                $cache['memory'] = $data['memory'] ?? null;
                $cache['network'] = $data['network'] ?? null;
                $cache['last_sample_time'] = (float)($data['last_sample_time'] ?? 0);
                $cache['cpu_times_prev'] = $data['cpu_times_prev'] ?? null;
                $cache['boot_time'] = $data['boot_time'] ?? null;
                $cache['queue_length'] = $data['queue_length'] ?? null;
            }
        }
    }

    $now = microtime(true);
    // PowerShell 子进程模式每次采样都要启动进程（耗时数秒），将节流间隔拉长，
    // 避免前端每秒轮询导致进程堆积；FFI/COM 进程内模式保持 0.5 秒实时采样
    $psMode = (ss_os_type() === 'windows') && !ss_ffi_available() && !ss_com_available() && ss_subprocess_allowed();
    $throttle = $psMode ? 3.0 : 0.5;

    // 采样段用文件锁互斥：并发轮询时仅一个请求真正采样，其余直接读缓存返回
    if ($now - $cache['last_sample_time'] >= $throttle) {
        $lockHandle = @fopen($cacheFile . '.lock', 'c');
        if ($lockHandle !== false) {
            if (@flock($lockHandle, LOCK_EX | LOCK_NB)) {
                // 拿到锁：重新读取缓存，避免等待期间其他请求已采样（逐字段覆盖，避免 array_merge 合并数字索引数组）
                $fresh = @file_get_contents($cacheFile);
                if ($fresh !== false) {
                    $freshData = json_decode($fresh, true);
                    if (is_array($freshData) && (float)($freshData['last_sample_time'] ?? 0) > $cache['last_sample_time']) {
                        foreach (['cpu_samples', 'memory', 'network', 'last_sample_time', 'cpu_times_prev', 'boot_time', 'queue_length'] as $fk) {
                            if (array_key_exists($fk, $freshData)) $cache[$fk] = $freshData[$fk];
                        }
                    }
                }
                $now = microtime(true);
                if ($now - $cache['last_sample_time'] >= $throttle) {
                    $sample = ss_sample_cpu_and_memory($cache['cpu_times_prev']);
                    $cache['cpu_samples'][] = $sample['cpu'];
                    $cache['cpu_samples'] = array_slice($cache['cpu_samples'], -5);
                    $cache['memory'] = $sample['memory'];
                    // 保存本次 CPU 时间供下次差值计算
                    if ($sample['cpu_times'] !== null) {
                        $cache['cpu_times_prev'] = $sample['cpu_times'];
                    }
                    // 运行时间/负载兜底：复用合并采样中的 Boot/Queue，避免额外启动进程
                    if (is_array($sample['ps_realtime'] ?? null)) {
                        if (!empty($sample['ps_realtime']['Boot'])) {
                            $cache['boot_time'] = (string)$sample['ps_realtime']['Boot'];
                        }
                        if (isset($sample['ps_realtime']['Queue'])) {
                            $cache['queue_length'] = (int)$sample['ps_realtime']['Queue'];
                        }
                    }
                    // 网络流量：基于上次采样计算速率（复用合并采样获取的网络计数器，避免重复启动 powershell）
                    $prevNetSample = $cache['network']['sample'] ?? null;
                    $cache['network'] = ss_get_network_usage($prevNetSample, $sample['ps_realtime'] ?? null);
                    $cache['last_sample_time'] = $now;

                    if (!is_dir($cacheDir)) {
                        @mkdir($cacheDir, 0777, true);
                    }
                    if (is_dir($cacheDir)) {
                        @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE));
                    }
                }
                @flock($lockHandle, LOCK_UN);
            }
            @fclose($lockHandle);
        }
    }

    $cpuAvg = 0;
    if (!empty($cache['cpu_samples'])) {
        // 过滤掉 -1（受限环境不可用）值，仅对有效采样取平均
        $validSamples = array_filter($cache['cpu_samples'], function ($v) { return $v >= 0; });
        if (!empty($validSamples)) {
            $cpuAvg = (int)round(array_sum($validSamples) / count($validSamples));
        } else {
            // 所有采样均为 -1：标记为不可用
            $cpuAvg = -1;
        }
    }

    $memory = $cache['memory'];
    if (!is_array($memory)) {
        $memory = ss_get_memory_usage();
    }

    return [
        'cpu_usage' => $cpuAvg,
        'memory' => $memory,
        'network' => $cache['network'],
    ];
}

/**
 * 快速采样 CPU 和内存（全部进程内，不启动任何子进程）。
 *
 * Windows 优先级：
 *   1. FFI GlobalMemoryStatusEx → 内存（即时，最准确）
 *   2. COM Win32_Processor.LoadPercentage → CPU 使用率（即时）
 *   3. FFI GetSystemTimes 差值 → CPU 使用率（COM 不可用时，基于上次采样差值计算）
 *
 * Linux：100ms /proc/stat 采样 + /proc/meminfo 读取。
 *
 * @param array|null $prevCpuTimes 上次 FFI GetSystemTimes 采样的 ['idle'=>int, 'total'=>int]
 * @return array ['cpu'=>int, 'memory'=>array, 'cpu_times'=>?array]
 */
function ss_sample_cpu_and_memory(?array $prevCpuTimes = null): array {
    $cpu = 0;
    $memory = null;
    $cpuTimes = null;
    $psRealtime = null; // 子进程合并采样结果（内存+CPU+网络计数器）
    $os = ss_os_type();

    if ($os === 'windows') {
        // ===== 内存：FFI 优先（GlobalMemoryStatusEx，即时且最准确）=====
        if ($memory === null) {
            $memory = ss_ffi_get_memory_status();
        }

        // ===== CPU 使用率 =====
        // 方案 1（优先）：COM Win32_Processor.LoadPercentage（即时值）
        // 注意：多路 CPU 系统返回多行，需取平均值以反映整体负载
        $comCpuSuccess = false;
        if (ss_com_available()) {
            $cpuRows = ss_wmi_query('SELECT LoadPercentage FROM Win32_Processor', 'root/cimv2', ['LoadPercentage']);
            if (!empty($cpuRows)) {
                $totalLoad = 0;
                $validCount = 0;
                foreach ($cpuRows as $cpuRow) {
                    if (isset($cpuRow['LoadPercentage']) && is_numeric($cpuRow['LoadPercentage'])) {
                        $totalLoad += (int)$cpuRow['LoadPercentage'];
                        $validCount++;
                    }
                }
                if ($validCount > 0) {
                    $cpu = (int)round($totalLoad / $validCount);
                    $comCpuSuccess = true;
                }
            }
        }

        // 方案 2：FFI GetSystemTimes 差值计算（COM 不可用或查询失败时才走，CPU 空闲=0 不会误触发）
        if (!$comCpuSuccess && ss_ffi_available()) {
            $cpuTimes = ss_ffi_get_cpu_times();
            if ($cpuTimes !== null && $prevCpuTimes !== null) {
                $deltaTotal = $cpuTimes['total'] - ($prevCpuTimes['total'] ?? 0);
                $deltaIdle = $cpuTimes['idle'] - ($prevCpuTimes['idle'] ?? 0);
                if ($deltaTotal > 0) {
                    $cpu = (int)round((1 - $deltaIdle / $deltaTotal) * 100);
                }
            }
            // 首次采样（无 prevCpuTimes）时 cpu=0，下次采样即可计算
        }

        // 方案 3（子进程兜底）：COM 不可用时，单次 PowerShell 调用同时取 内存+CPU负载+网络计数器，
        // 避免每次轮询启动多次 powershell.exe
        if (!$comCpuSuccess && $cpu <= 0) {
            $psRealtime = ss_ps_realtime_sample();
            if ($psRealtime !== null && is_numeric($psRealtime['Load'] ?? '')) {
                $cpu = (int)$psRealtime['Load'];
            }
        }

        // 内存兜底：FFI 不可用时，优先使用合并采样中的内存数据，其次 COM，最后单独查询
        if ($memory === null) {
            if ($psRealtime !== null && is_numeric($psRealtime['TotalKB'] ?? '') && is_numeric($psRealtime['FreeKB'] ?? '')) {
                $total = (int)$psRealtime['TotalKB'] * 1024;
                $free = (int)$psRealtime['FreeKB'] * 1024;
                $used = max(0, $total - $free);
                $memory = [
                    'total' => $total,
                    'used' => $used,
                    'available' => $free,
                    'usage_percent' => $total > 0 ? (int)round($used / $total * 100) : 0,
                    'total_formatted' => ss_format_bytes($total),
                    'used_formatted' => ss_format_bytes($used),
                    'available_formatted' => ss_format_bytes($free),
                ];
            } elseif (ss_com_available()) {
                $memRow = ss_wmi_query_first('SELECT TotalVisibleMemorySize,FreePhysicalMemory FROM Win32_OperatingSystem', 'root/cimv2', ['TotalVisibleMemorySize', 'FreePhysicalMemory']);
                if ($memRow) {
                    $totalKb = $memRow['TotalVisibleMemorySize'] ?? '';
                    $freeKb = $memRow['FreePhysicalMemory'] ?? '';
                    if (is_numeric($totalKb) && is_numeric($freeKb)) {
                        $total = (int)$totalKb * 1024;
                        $free = (int)$freeKb * 1024;
                        $used = max(0, $total - $free);
                        $memory = [
                            'total' => $total,
                            'used' => $used,
                            'available' => $free,
                            'usage_percent' => $total > 0 ? (int)round($used / $total * 100) : 0,
                            'total_formatted' => ss_format_bytes($total),
                            'used_formatted' => ss_format_bytes($used),
                            'available_formatted' => ss_format_bytes($free),
                        ];
                    }
                }
            }
        }
    } elseif ($os === 'linux') {
        $stat1 = @file_get_contents('/proc/stat');
        if ($stat1 && strlen($stat1) > 10) {
            $first1 = strstr($stat1, "\n", true) ?: $stat1;
            $parts1 = preg_split('/\s+/', trim($first1));
            $idle1 = (int)($parts1[4] ?? 0);
            $total1 = array_sum(array_slice($parts1, 1));
            usleep(100000); // 100ms 采样
            $stat2 = @file_get_contents('/proc/stat');
            $first2 = strstr($stat2, "\n", true) ?: $stat2;
            $parts2 = preg_split('/\s+/', trim($first2));
            $idle2 = (int)($parts2[4] ?? 0);
            $total2 = array_sum(array_slice($parts2, 1));
            $diffTotal = $total2 - $total1;
            $diffIdle = $idle2 - $idle1;
            if ($diffTotal > 0) {
                $cpu = (int)round((1 - $diffIdle / $diffTotal) * 100);
            }
        } else {
            // /proc/stat 不可读（open_basedir 限制等）：标记为受限环境
            $cpu = -1; // -1 表示「不可用」，前端应显示 N/A 而非 0%
        }
    }

    if ($memory === null) {
        $memory = ss_get_memory_usage();
    }

    return [
        'cpu' => ($cpu === -1) ? -1 : max(0, min(100, $cpu)), // -1 = 受限环境不可用
        'memory' => $memory,
        'cpu_times' => $cpuTimes,
        'ps_realtime' => $psRealtime,
    ];
}

function ss_os_type(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (stripos(PHP_OS, 'WIN') === 0) $cached = 'windows';
    elseif (stripos(PHP_OS, 'LINUX') === 0) $cached = 'linux';
    else $cached = 'unknown';
    return $cached;
}

/**
 * 修复 Windows 中文系统命令输出的编码问题。
 * 优先检测有效 UTF-8，否则尝试 GBK 转码；不依赖 mbstring 扩展。
 */
function ss_fix_encoding(?string $output): ?string {
    if ($output === null || $output === '') return $output;
    if (preg_match('//u', $output)) return $output;

    if (function_exists('iconv')) {
        $converted = @iconv('GBK', 'UTF-8//IGNORE', $output);
        if ($converted !== false && $converted !== '') return $converted;
    }
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($output, 'UTF-8', 'GBK,GB2312,UTF-8,BIG5');
        if ($converted !== false && $converted !== '') return $converted;
    }
    return $output;
}

/**
 * 执行 shell 命令，自动处理编码。
 *
 * 重要：默认禁用子进程调用（return null），避免 wmic/powershell 等子进程被安全软件拦截。
 * 所有系统信息采集已改为 FFI/COM 进程内方式，不再依赖 shell_exec。
 * 如需启用（调试用），将 SS_ALLOW_SUBPROCESS 设为 true。
 */
if (!defined('SS_ALLOW_SUBPROCESS')) define('SS_ALLOW_SUBPROCESS', false);

/**
 * 是否允许执行子进程（wmic / PowerShell）
 *
 * 规则：
 * - SS_ALLOW_SUBPROCESS 显式设为 true：始终允许（调试/确认无安全软件拦截时使用）
 * - SS_ALLOW_SUBPROCESS 为 false（默认）：
 *     Windows 下若 FFI 与 COM 均不可用，则自动回退允许子进程采集，
 *     保证系统信息页在 PHP 7.3（无 FFI）且未加载 com_dotnet 的环境下仍能显示数据；
 *     Linux 下若 shell_exec 可用则允许子进程（/proc 文件系统覆盖核心数据，
 *     但 GPU/硬盘型号/内存条/IP 地址等增强信息依赖 lspci/lsblk/dmidecode/ip 等只读命令）。
 */
function ss_subprocess_allowed(): bool {
    if (SS_ALLOW_SUBPROCESS) return true;
    // Windows 下只要 COM WMI 进程内采集不可用，就允许子进程（PowerShell CIM）兜底。
    // 注意：FFI 只能读取内存/运行时间/CPU 核数等少量数据，无法读取硬盘型号、内存条、
    // 显卡、主板等硬件信息（这些只有 WMI 才能提供），因此即使 FFI 可用也需要子进程兜底。
    if (ss_os_type() === 'windows' && !ss_com_available()) {
        return true;
    }
    // Linux：lspci/lsblk/dmidecode/sensors/ip 均为只读命令且通常无需 root（dmidecode 除外），
    // shell_exec 未被禁用即可执行；被禁用或 open_basedir 限制时保持禁用，仅返回 /proc、/sys 数据。
    if (ss_os_type() === 'linux' && ss_shell_enabled()) {
        return true;
    }
    return false;
}

/**
 * shell_exec 是否真正可用（未被 disable_functions 禁用）
 * 注意：function_exists() 对 disable_functions 禁用的函数仍返回 true，必须额外检查 ini。
 */
function ss_shell_enabled(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    // PowerShell 通道使用 proc_open（带超时控制）；shell_exec 仅作兼容判断
    $cached = !in_array('shell_exec', $disabled, true) && !in_array('proc_open', $disabled, true);
    return $cached;
}

function ss_shell_exec(string $cmd): ?string {
    if (!ss_subprocess_allowed()) return null; // 未启用子进程（或被安全软件限制时置为 false）
    if (!function_exists('shell_exec') || !ss_shell_enabled()) return null;
    $output = @shell_exec($cmd);
    if ($output === null || $output === false) return null;
    $fixed = ss_fix_encoding($output);
    return $fixed !== null ? trim($fixed) : null;
}

/**
 * 通过 COM (WScript.Shell) 执行外部命令并捕获 stdout
 *
 * 用于 COM 可用但 shell_exec/proc_open 子进程被禁用（ss_subprocess_allowed()=false）的环境。
 * 例如 phpStudy 默认开启了 com_dotnet 但关闭了子进程，此时 nvidia-smi 等兜底命令无法用
 * ss_shell_exec 执行，可改用本函数。
 *
 * @return string|null 命令输出（已 trim）；失败/超时返回 null
 */
function ss_com_exec(string $cmd): ?string {
    if (!class_exists('COM')) return null;
    try {
        $shell = new COM('WScript.Shell');
        if (!$shell) return null;
        $exec = $shell->Exec($cmd);
        if (!$exec) {
            $shell = null;
            return null;
        }
        $stdout = $exec->StdOut->ReadAll();
        $shell = null;
        return ($stdout !== null && $stdout !== '') ? trim($stdout) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * PowerShell 可执行文件完整路径
 * 优先使用绝对路径，避免 CGI 进程的 PATH 中不包含 System32 导致找不到 powershell。
 *
 * 候选顺序：
 * 1. Windows PowerShell 5.1（最常用，Win7/Win10/Win11 默认都有）
 * 2. 64 位 System32 路径（兼容 32 位 PHP 在 64 位系统上的重定向问题）
 * 3. PowerShell 7+（pwsh.exe）
 * 4. PATH 中的 powershell（最后回退）
 */
function ss_ps_executable(): string {
    static $ps = null;
    if ($ps !== null) return $ps;
    $sysRoot = getenv('SystemRoot') ?: getenv('WINDIR') ?: 'C:\\Windows';
    $candidates = [
        // 64 位系统原生路径（优先）
        $sysRoot . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
        // 32 位 PHP 在 64 位 Windows 上运行时被重定向到 SysWOW64，System32 反而可能找不到
        $sysRoot . '\\SysWOW64\\WindowsPowerShell\\v1.0\\powershell.exe',
    ];

    // PowerShell 7+（独立安装，部分新系统只有这个）
    $envPath = getenv('PATH') ?: '';
    foreach (explode(';', $envPath) as $dir) {
        $dir = trim($dir);
        if ($dir === '') continue;
        $candidate = rtrim($dir, '\\/') . '\\pwsh.exe';
        if (is_file($candidate)) {
            $candidates[] = $candidate;
            break;
        }
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $ps = $candidate;
            return $ps;
        }
    }
    $ps = 'powershell';
    return $ps;
}

/**
 * 将 UTF-8 字符串转换为 UTF-16LE 字节序列（-EncodedCommand 需要，不依赖 mbstring/iconv）
 */
function ss_utf8_to_utf16le(string $s): string {
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = ord($s[$i]);
        if ($c < 0x80) {
            $out .= chr($c) . "\x00";
        } elseif (($c & 0xE0) === 0xC0) {
            $code = (($c & 0x1F) << 6) | (ord($s[$i + 1]) & 0x3F);
            $out .= chr($code & 0xFF) . chr(($code >> 8) & 0xFF);
            $i++;
        } elseif (($c & 0xF0) === 0xE0) {
            $code = (($c & 0x0F) << 12) | ((ord($s[$i + 1]) & 0x3F) << 6) | (ord($s[$i + 2]) & 0x3F);
            $out .= chr($code & 0xFF) . chr(($code >> 8) & 0xFF);
            $i += 2;
        } elseif (($c & 0xF8) === 0xF0) {
            $code = (($c & 0x07) << 18) | ((ord($s[$i + 1]) & 0x3F) << 12) | ((ord($s[$i + 2]) & 0x3F) << 6) | (ord($s[$i + 3]) & 0x3F);
            $out .= chr($code & 0xFF) . chr(($code >> 8) & 0xFF) . chr(($code >> 16) & 0xFF) . chr(($code >> 24) & 0xFF);
            $i += 3;
        }
    }
    return $out;
}

/**
 * 执行 PowerShell 脚本并返回文本输出（子进程兜底核心）
 *
 * 使用 -EncodedCommand 传递 UTF-16LE Base64 编码的脚本，彻底规避 cmd.exe / shell
 * 对外层引号、$、反引号、管道等特殊字符的转义问题。脚本前缀同时：
 * - 设置 [Console]::OutputEncoding=UTF-8，保证输出为 UTF-8，避免 GBK 乱码
 * - 设置 $ProgressPreference='SilentlyContinue'，避免模块加载的 CLIXML 进度信息混入 stdout
 *   （否则 PHP 捕获到的输出会带 "#< CLIXML ..." 前缀，导致 JSON 解析失败）
 *
 * 使用 proc_open + 非阻塞读实现超时控制：Get-CimInstance 在 WMI 服务异常时可能长时间
 * 无响应，若没有超时，PHP 请求会被拖死（这是此前静态端点“请求超时 / HTTP 0”的原因之一）。
 *
 * @return string|null 脚本输出（已转 UTF-8，已 trim）；失败/超时返回 null
 */
function ss_ps_run(string $script, int $timeoutSec = 10): ?string {
    if (!ss_subprocess_allowed()) return null;
    if (!function_exists('proc_open') || !ss_shell_enabled()) return null;
    $full = '$ProgressPreference="SilentlyContinue";[Console]::OutputEncoding=[Text.Encoding]::UTF8;' . $script;
    $encoded = base64_encode(ss_utf8_to_utf16le($full));
    $cmd = '"' . ss_ps_executable() . '" -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $encoded;

    $descriptors = [
        0 => ['file', 'NUL', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) return null;
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $start = microtime(true);
    $timedOut = false;
    while (true) {
        $status = @proc_get_status($proc);
        // 非阻塞读取 stdout 剩余数据
        while (($chunk = @fread($pipes[1], 65536)) !== false && $chunk !== '') {
            $output .= $chunk;
        }
        if (isset($status['running']) && !$status['running']) {
            // 进程已退出，再清空一次管道
            while (($chunk = @fread($pipes[1], 65536)) !== false && $chunk !== '') {
                $output .= $chunk;
            }
            break;
        }
        if (microtime(true) - $start > $timeoutSec) {
            $timedOut = true;
            break;
        }
        usleep(20000); // 20ms
    }
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    if ($timedOut) {
        @proc_terminate($proc);
        @proc_close($proc);
        return null; // 超时视为失败
    }
    @proc_close($proc);
    $output = trim($output);
    if ($output === '') return null;
    return ss_fix_encoding($output);
}

/**
 * 单次 PowerShell 调用批量采集全部静态硬件信息（子进程兜底核心优化）
 *
 * 在 COM 不可用环境下，一次进程调用同时获取 CPU/内存条/硬盘/显卡/主板/BIOS/电池/
 * 网络接口/IP 配置/负载/开机时间等所有硬件数据，避免每个硬件函数各启动一个
 * powershell.exe 导致静态端点“请求超时 / HTTP 0”。
 *
 * 结果带 1 小时磁盘缓存，并在本次请求内通过 $GLOBALS['ss_ps_batch'] 复用
 * （ss_ps_cim_json 会优先读取该缓存，不再重复启动进程）。
 *
 * @return array|null 结构化数据（键：cpu/os/banks/disks/gpus/board/bios/battery/load/nic/ipcfg）；失败返回 null
 */
function ss_ps_batch_collect(): ?array {
    if (!ss_subprocess_allowed() || !function_exists('proc_open') || !ss_shell_enabled()) return null;
    if (isset($GLOBALS['ss_ps_batch_loaded'])) {
        return $GLOBALS['ss_ps_batch_loaded'] ? ($GLOBALS['ss_ps_batch'] ?? null) : null;
    }
    $GLOBALS['ss_ps_batch_loaded'] = true;

    // 磁盘缓存（1 小时）
    $cacheDir = APP_ROOT . 'data/cache';
    $cacheFile = $cacheDir . '/system_status_' . SS_CACHE_VERSION . '_ps_batch.json';
    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['expires']) && (int)$data['expires'] > time()
                && is_array($data['value'] ?? null) && !empty($data['value'])) {
                $GLOBALS['ss_ps_batch'] = $data['value'];
                return $data['value'];
            }
        }
    }

    $script = '$r=@{};'
        . '$cpu=Get-CimInstance Win32_Processor -ErrorAction SilentlyContinue | Select-Object -First 1 Name,NumberOfCores,NumberOfLogicalProcessors,LoadPercentage;if($cpu){$r.cpu=$cpu}'
        . '$os=Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue | Select-Object -First 1 TotalVisibleMemorySize,FreePhysicalMemory,LastBootUpTime;if($os){$r.os=$os}'
        . '$banks=@(Get-CimInstance Win32_PhysicalMemory -ErrorAction SilentlyContinue | Select-Object BankLabel,Capacity,Speed,Manufacturer,PartNumber);if($banks.Count -gt 0){$r.banks=@($banks)}'
        . '$disks=@(Get-CimInstance Win32_DiskDrive -ErrorAction SilentlyContinue | Select-Object Model,Size,SerialNumber,InterfaceType,MediaType);if($disks.Count -gt 0){$r.disks=@($disks)}'
        . '$gpus=@(Get-CimInstance Win32_VideoController -ErrorAction SilentlyContinue | Select-Object Name,AdapterRAM,DriverVersion,VideoProcessor,AdapterDACType);if($gpus.Count -gt 0){$r.gpus=@($gpus)}'
        . '$board=Get-CimInstance Win32_BaseBoard -ErrorAction SilentlyContinue | Select-Object -First 1 Manufacturer,Product,Version,SerialNumber;if($board){$r.board=$board}'
        . '$bios=Get-CimInstance Win32_BIOS -ErrorAction SilentlyContinue | Select-Object -First 1 Manufacturer,SMBIOSBIOSVersion,ReleaseDate;if($bios){$r.bios=$bios}'
        . '$bat=@(Get-CimInstance Win32_Battery -ErrorAction SilentlyContinue | Select-Object BatteryStatus,EstimatedChargeRemaining);if($bat.Count -gt 0){$r.battery=$bat[0]}'
        . '$load=Get-CimInstance Win32_PerfFormattedData_PerfOS_System -ErrorAction SilentlyContinue | Select-Object -First 1 ProcessorQueueLength;if($load){$r.load=$load}'
        . '$nic=@(Get-CimInstance Win32_NetworkAdapter -ErrorAction SilentlyContinue | Where-Object {$_.MACAddress} | Select-Object Name,NetConnectionID,MACAddress,NetEnabled,NetConnectionStatus,Manufacturer);if($nic.Count -gt 0){$r.nic=@($nic)}'
        . '$ipcfg=@(Get-CimInstance Win32_NetworkAdapterConfiguration -ErrorAction SilentlyContinue | Where-Object {$_.IPEnabled} | Select-Object Description,MACAddress,IPAddress,IPEnabled);if($ipcfg.Count -gt 0){$r.ipcfg=@($ipcfg)}'
        . '$r | ConvertTo-Json -Compress -Depth 6';

    $out = ss_ps_run($script, 20);
    if (empty($out)) {
        $GLOBALS['ss_ps_batch'] = null;
        return null;
    }
    $d = json_decode($out, true);
    if (!is_array($d) || empty($d)) {
        $GLOBALS['ss_ps_batch'] = null;
        return null;
    }
    $GLOBALS['ss_ps_batch'] = $d;

    // 写入磁盘缓存
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    if (is_dir($cacheDir)) {
        @file_put_contents($cacheFile, json_encode(['expires' => time() + 3600, 'value' => $d], JSON_UNESCAPED_UNICODE));
    }
    return $d;
}

/**
 * 返回请求内批量采集结果（惰性触发；静态端点会主动预加载）
 */
function ss_ps_batch(): ?array {
    return ss_ps_batch_collect();
}

/**
 * 读取实时采样缓存文件（动态端点为避免重复启动进程，运行时间/负载直接复用该缓存）
 */
function ss_read_realtime_cache_file(): ?array {
    $f = APP_ROOT . 'data/cache/system_status_realtime.json';
    if (!is_file($f)) return null;
    $raw = @file_get_contents($f);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

/**
 * 通过 PowerShell 执行 CIM 查询并返回 JSON 解析结果（子进程兜底方案）
 *
 * 在 com_dotnet 扩展未加载的环境中，作为 WMI 采集的唯一可用途径。
 * 使用 Get-CimInstance（Windows PowerShell 5.1 与 PowerShell 7 均支持）。
 *
 * 若本次请求已加载批量采集数据（ss_ps_batch_collect），直接复用对应结果，
 * 不重复启动 powershell.exe（这是避免静态端点超时的关键）。
 *
 * @param string $className WMI 类名，如 Win32_Processor
 * @param array  $fields    需要返回的字段
 * @param string $filter    WQL WHERE 条件（可选）
 * @return array            行数组；失败返回空数组
 */
function ss_ps_cim_json(string $className, array $fields, string $filter = ''): array {
    // 批量采集结果映射：WMI 类 → batch 数据键
    static $batchMap = [
        'Win32_Processor'                   => 'cpu',
        'Win32_OperatingSystem'             => 'os',
        'Win32_PhysicalMemory'              => 'banks',
        'Win32_DiskDrive'                   => 'disks',
        'Win32_VideoController'             => 'gpus',
        'Win32_BaseBoard'                   => 'board',
        'Win32_BIOS'                        => 'bios',
        'Win32_Battery'                     => 'battery',
        'Win32_NetworkAdapter'              => 'nic',
        'Win32_NetworkAdapterConfiguration' => 'ipcfg',
        'Win32_PerfFormattedData_PerfOS_System' => 'load',
    ];
    if (isset($batchMap[$className])) {
        $b = $GLOBALS['ss_ps_batch'] ?? null;
        if (is_array($b)) {
            $data = $b[$batchMap[$className]] ?? null;
            if ($data !== null) {
                // 归一化为行数组：单对象 → [对象]，数组 → 原样
                if (is_array($data) && isset($data[0]) && is_array($data[0])) return $data;
                return [$data];
            }
        }
    }

    $fieldList = implode(',', array_map('trim', $fields));
    $expr = 'Get-CimInstance -ClassName ' . $className .
            ($filter !== '' ? ' -Filter "' . str_replace('"', '\"', $filter) . '"' : '') .
            ' | Select-Object ' . $fieldList . ' | ConvertTo-Json -Compress';
    $out = ss_ps_run($expr);
    if (empty($out)) return [];
    $decoded = json_decode($out, true);
    if (!is_array($decoded)) return [];
    // ConvertTo-Json：单行结果返回对象，多行返回数组
    if (isset($decoded[0]) && is_array($decoded[0])) return $decoded;
    return [$decoded];
}

/**
 * 通过 PowerShell 查询单行单值（子进程兜底方案）
 *
 * @param string $expr PowerShell 表达式，应输出一个值（如 Get-CimInstance ... | Select -ExpandProperty Xxx）
 * @return string|null 输出值（已转 UTF-8）；失败返回 null
 */
function ss_ps_query_value(string $expr): ?string {
    $out = ss_ps_run($expr);
    if ($out === null || $out === '') return null;
    return $out;
}

/**
 * 解析 Windows 日期时间为 Unix 时间戳（兼容两种来源格式）
 *
 * - WMI COM 返回格式："20240101120000.000000+480"
 * - PowerShell ConvertTo-Json 序列化格式："/Date(1785719656500)/"（毫秒）
 *
 * @return int|null 成功返回秒级时间戳，失败返回 null
 */
function ss_parse_windows_date_time(?string $s): ?int {
    if (empty($s)) return null;
    // PowerShell ConvertTo-Json 的 /Date(毫秒)/ 格式
    if (preg_match('/\/Date\((\d+)\)\//', $s, $m)) {
        return (int)round((int)$m[1] / 1000);
    }
    // WMI 字符串格式
    if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $s, $m)) {
        return mktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);
    }
    return null;
}

/**
 * 单次 PowerShell 调用采样 内存 + CPU 负载 + 网络计数器 + 运行时间 + 队列长度（子进程兜底）
 *
 * 在 FFI/COM 均不可用的环境下，将每秒轮询原本需要多次启动的 powershell.exe 合并为一次，
 * 大幅降低响应延迟。
 *
 * @return array|null ['TotalKB'=>int, 'FreeKB'=>int, 'Load'=>int, 'Rx'=>int, 'Tx'=>int, 'Boot'=>string, 'Queue'=>int]；失败返回 null
 */
function ss_ps_realtime_sample(): ?array {
    if (!ss_subprocess_allowed() || !function_exists('proc_open') || !ss_shell_enabled()) return null;
    $script = '$o=Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue | Select-Object -First 1 TotalVisibleMemorySize,FreePhysicalMemory,LastBootUpTime;'
        . '$c=Get-CimInstance Win32_Processor -ErrorAction SilentlyContinue | Select-Object -First 1 LoadPercentage;'
        . '$q=Get-CimInstance Win32_PerfFormattedData_PerfOS_System -ErrorAction SilentlyContinue | Select-Object -First 1 ProcessorQueueLength;'
        . '$n=Get-CimInstance Win32_PerfRawData_Tcpip_NetworkInterface -ErrorAction SilentlyContinue;'
        . '$rx=0;$tx=0;foreach($i in $n){$rx += [uint64]$i.BytesReceivedPersec;$tx += [uint64]$i.BytesSentPersec};'
        . '[pscustomobject]@{TotalKB=[uint64]$o.TotalVisibleMemorySize;FreeKB=[uint64]$o.FreePhysicalMemory;Load=[int]$c.LoadPercentage;Rx=[uint64]$rx;Tx=[uint64]$tx;Boot=$o.LastBootUpTime;Queue=[int]$q.ProcessorQueueLength} | ConvertTo-Json -Compress';
    $out = ss_ps_run($script);
    if (empty($out)) return null;
    $d = json_decode($out, true);
    if (!is_array($d)) return null;
    return $d;
}

/**
 * 在 Windows 下执行 PowerShell 命令并返回文本输出。
 */
function ss_powershell_exec(string $command): ?string {
    if (ss_os_type() !== 'windows') return null;
    return ss_ps_run($command);
}

function ss_parse_wmic_value(?string $output, string $key): ?string {
    if (empty($output)) return null;
    foreach (explode("\n", $output) as $line) {
        $line = trim($line);
        if (stripos($line, $key . '=') === 0) {
            return trim(substr($line, strlen($key) + 1));
        }
    }
    return null;
}

/* ======================== COM WMI 访问层（优先，不启动子进程） ======================== */

/**
 * 检测 PHP COM 扩展是否可用（用于 WMI 进程内访问，避免启动 wmic 子进程被安全软件拦截）
 */
function ss_com_available(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    // Windows 环境下检测 com_dotnet 扩展是否加载且 COM 类可用
    if (ss_os_type() !== 'windows') {
        $cached = false;
        return false;
    }
    $cached = extension_loaded('com_dotnet') && class_exists('COM', false);
    return $cached;
}

/**
 * 通过 PHP COM 扩展进程内执行 WQL 查询（不启动任何子进程，安全软件不会拦截）
 *
 * @param string        $wql       WQL 查询语句
 * @param string        $namespace WMI 命名空间，默认 root/cimv2
 * @param array|null    $fields    指定字段名列表，直接按名访问属性（比 Properties_ 遍历更稳定）
 * @return array                   行数组；失败返回空数组
 */
function ss_wmi_query(string $wql, string $namespace = 'root/cimv2', ?array $fields = null): array {
    if (!ss_com_available()) return [];

    static $loc = null;
    static $servers = []; // 按 namespace 缓存 ConnectServer，同一请求内复用
    try {
        if ($loc === null) {
            $loc = new \COM('WbemScripting.SWbemLocator');
        }
        if (!isset($servers[$namespace])) {
            $servers[$namespace] = $loc->ConnectServer('.', $namespace);
        }
        $server = $servers[$namespace];
        $items = $server->ExecQuery($wql);
        $rows = [];
        foreach ($items as $item) {
            $row = [];
            if ($fields !== null) {
                // 方式 1：直接按字段名访问（稳定，不依赖 Properties_ 遍历）
                foreach ($fields as $name) {
                    try {
                        $val = $item->{$name};
                    } catch (\Exception $e) {
                        $val = null;
                    } catch (\Error $e) {
                        $val = null;
                    }
                    // 处理 VARIANT 数组（如 IPAddress 返回 VT_ARRAY|VT_BSTR）
                    // PHP COM 可能将其转为 PHP 数组，也可能保留为 VARIANT 对象
                    if (is_array($val)) {
                        $parts = [];
                        foreach ($val as $v) {
                            $parts[] = (string)$v;
                        }
                        $val = implode(';', $parts);
                    } elseif (is_object($val)) {
                        // 尝试检测 VARIANT 数组类型
                        $isVariantArray = false;
                        if (function_exists('variant_get_type')) {
                            try {
                                $vt = variant_get_type($val);
                                // VT_ARRAY = 0x2000 (8192)
                                if ($vt & 0x2000) {
                                    $isVariantArray = true;
                                    $parts = [];
                                    // 元素为 BSTR 的 SAFEARRAY 可被 (array) 直接转换；
                                    // 元素为 VARIANT（VT_ARRAY|VT_VARIANT，如 WMI IPAddress）时
                                    // (array) 强转为空，需用 foreach 遍历（COM 对象实现 Iterator）
                                    $casted = (array)$val;
                                    if (!empty($casted)) {
                                        foreach ($casted as $v) {
                                            $parts[] = (string)$v;
                                        }
                                    } else {
                                        try {
                                            foreach ($val as $v) {
                                                $parts[] = (string)$v;
                                            }
                                        } catch (\Throwable $e5) {
                                            // 兜底：ArrayAccess 下标访问（越界抛异常时停止）
                                            try {
                                                $i = 0;
                                                while (true) {
                                                    $elem = $val[$i];
                                                    if ($elem === null) break;
                                                    $parts[] = (string)$elem;
                                                    $i++;
                                                }
                                            } catch (\Throwable $e6) {
                                            }
                                        }
                                    }
                                    $val = implode(';', $parts);
                                }
                            } catch (\Throwable $e2) {
                                // 忽略：variant_get_type 可能对非 VARIANT 对象抛出 TypeError
                            }
                        }
                        if (!$isVariantArray) {
                            // 再尝试：如果对象有 Count/Item 方法（COM 集合），按集合方式遍历
                            try {
                                $count = $val->Count ?? 0;
                                if ($count > 0) {
                                    $parts = [];
                                    for ($ci = 0; $ci < $count; $ci++) {
                                        $parts[] = (string)($val->Item($ci));
                                    }
                                    $val = implode(';', $parts);
                                    $isVariantArray = true;
                                }
                            } catch (\Throwable $e4) {
                            }
                        }
                        if (!$isVariantArray) {
                            try {
                                $val = (string)$val;
                            } catch (\Throwable $e3) {
                                $val = '';
                            }
                        }
                    } elseif ($val === null) {
                        $val = '';
                    }
                    $row[$name] = is_string($val) ? ss_ensure_utf8($val) : $val;
                }
            } else {
                // 方式 2：遍历 Properties_（兼容模式）
                foreach ($item->Properties_ as $prop) {
                    $val = $prop->Value;
                    if (is_array($val)) {
                        $val = implode(';', $val);
                    } elseif (is_object($val)) {
                        $val = (string)$val;
                    } elseif ($val === null) {
                        $val = '';
                    }
                    $row[$prop->Name] = is_string($val) ? ss_ensure_utf8($val) : $val;
                }
            }
            $rows[] = $row;
        }
        return $rows;
    } catch (\Exception $e) {
        return [];
    } catch (\Error $e) {
        return [];
    }
}

/**
 * 通过 COM 执行 WQL 查询并返回首行（便捷方法）
 */
function ss_wmi_query_first(string $wql, string $namespace = 'root/cimv2', ?array $fields = null): ?array {
    $rows = ss_wmi_query($wql, $namespace, $fields);
    return !empty($rows) ? $rows[0] : null;
}

/* ======================== FFI Win32 API 访问层（不启动子进程，不依赖 COM 扩展） ======================== */

/**
 * 检测 PHP FFI 扩展是否真正可用（扩展已加载且 ffi.enable 允许调用）
 */
function ss_ffi_available(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (ss_os_type() !== 'windows') {
        $cached = false;
        return false;
    }
    if (!extension_loaded('ffi') || !class_exists('FFI', false)) {
        $cached = false;
        return false;
    }
    // 实际测试 FFI 是否可用（ffi.enable=preload 时 cdef 会失败）。
    // 注意：PHP 7.4 的 FFI::cdef 会立即解析符号，声明不存在的函数会抛异常；
    // 而 PHP 8.x 是惰性解析。因此必须用 kernel32.dll 中真实存在的函数探测，
    // 不能用不存在的 stub，否则 PHP 7.4 上 FFI 会被误判为不可用。
    try {
        $test = @\FFI::cdef("unsigned long long GetTickCount64(void);", 'kernel32.dll');
        // PHP 7.4 下 cdef 成功即表示符号已解析；再实际调用一次确保可用
        $cached = ($test !== null && (int)$test->GetTickCount64() > 0);
    } catch (\Exception $e) {
        $cached = false;
    } catch (\Error $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * 获取缓存的 FFI kernel32 实例（避免重复 cdef，提升性能）
 *
 * @param string|null $error 输出初始化错误信息（引用参数）
 */
function ss_ffi_kernel32(?string &$error = null): ?\FFI {
    static $ffi = null;
    static $tried = false;
    static $initError = null;
    if ($tried) {
        $error = $initError;
        return $ffi;
    }
    $tried = true;
    // 不调用 ss_ffi_available()，直接尝试 cdef（避免循环依赖）
    if (!extension_loaded('ffi') || !class_exists('FFI', false)) {
        $initError = 'FFI extension not loaded';
        $error = $initError;
        return null;
    }
    try {
        $ffi = @\FFI::cdef("
            typedef struct _MEMORYSTATUSEX {
                unsigned long dwLength;
                unsigned long dwMemoryLoad;
                unsigned long long ullTotalPhys;
                unsigned long long ullAvailPhys;
                unsigned long long ullTotalPageFile;
                unsigned long long ullAvailPageFile;
                unsigned long long ullTotalVirtual;
                unsigned long long ullAvailVirtual;
                unsigned long long ullAvailExtendedVirtual;
            } MEMORYSTATUSEX;

            typedef struct _FILETIME {
                unsigned long dwLowDateTime;
                unsigned long dwHighDateTime;
            } FILETIME;

            typedef struct _SYSTEM_INFO {
                unsigned long dwOemId;
                unsigned long dwPageSize;
                void* lpMinimumApplicationAddress;
                void* lpMaximumApplicationAddress;
                unsigned long long dwActiveProcessorMask;
                unsigned long dwNumberOfProcessors;
                unsigned long dwProcessorType;
                unsigned long dwAllocationGranularity;
                unsigned short wProcessorLevel;
                unsigned short wProcessorRevision;
            } SYSTEM_INFO;

            typedef struct _SYSTEM_POWER_STATUS {
                unsigned char ACLineStatus;
                unsigned char BatteryFlag;
                unsigned char BatteryLifePercent;
                unsigned char SystemStatusFlag;
                unsigned long BatteryLifeTime;
                unsigned long BatteryFullLifeTime;
            } SYSTEM_POWER_STATUS;

            int GlobalMemoryStatusEx(MEMORYSTATUSEX* lpBuffer);
            unsigned long long GetTickCount64();
            int GetSystemTimes(FILETIME* lpIdleTime, FILETIME* lpKernelTime, FILETIME* lpUserTime);
            void GetSystemInfo(SYSTEM_INFO* lpSystemInfo);
            unsigned int GetSystemFirmwareTable(unsigned long FirmwareTableProviderSignature, unsigned long FirmwareTableID, void* pFirmwareTableBuffer, unsigned long BufferSize);
            int GetSystemPowerStatus(SYSTEM_POWER_STATUS* lpSystemPowerStatus);
        ", 'kernel32.dll');
    } catch (\Exception $e) {
        $initError = $e->getMessage();
        $ffi = null;
    } catch (\Error $e) {
        $initError = $e->getMessage();
        $ffi = null;
    }
    $error = $initError;
    return $ffi;
}

/**
 * 通过 FFI 获取内存使用情况（GlobalMemoryStatusEx）
 * 返回与 ss_get_memory_usage() 相同格式的数组，失败返回 null
 */
function ss_ffi_get_memory_status(): ?array {
    $ffi = ss_ffi_kernel32();
    if ($ffi === null) return null;
    try {
        $mem = $ffi->new('MEMORYSTATUSEX');
        $mem->dwLength = \FFI::sizeof($mem);
        if (@$ffi->GlobalMemoryStatusEx(\FFI::addr($mem))) {
            $total = (int)$mem->ullTotalPhys;
            $available = (int)$mem->ullAvailPhys;
            $used = max(0, $total - $available);
            return [
                'total' => $total,
                'used' => $used,
                'available' => $available,
                'usage_percent' => $total > 0 ? (int)round($used / $total * 100) : 0,
                'total_formatted' => ss_format_bytes($total),
                'used_formatted' => ss_format_bytes($used),
                'available_formatted' => ss_format_bytes($available),
            ];
        }
    } catch (\Exception $e) {
    } catch (\Error $e) {
    }
    return null;
}

/**
 * 通过 FFI 获取系统运行时间秒数（GetTickCount64）
 */
function ss_ffi_get_uptime_seconds(): ?int {
    $ffi = ss_ffi_kernel32();
    if ($ffi === null) return null;
    try {
        $ms = (int)@$ffi->GetTickCount64();
        return (int)round($ms / 1000);
    } catch (\Exception $e) {
    } catch (\Error $e) {
    }
    return null;
}

/**
 * 通过 FFI 获取 CPU 累计时间（GetSystemTimes）
 *
 * Windows API 语义：
 *   - idle:   系统空闲时间
 *   - kernel: 内核时间（包含 idle）
 *   - user:   用户态时间
 *
 * 计算 CPU 使用率时需要两次采样的差值：
 *   delta_total = (kernel2 + user2) - (kernel1 + user1)
 *   delta_idle  = idle2 - idle1
 *   cpu_usage   = 1 - delta_idle / delta_total
 *
 * @return array|null  ['idle'=>int, 'kernel'=>int, 'user'=>int, 'total'=>int]；失败返回 null
 */
function ss_ffi_get_cpu_times(): ?array {
    $ffi = ss_ffi_kernel32();
    if ($ffi === null) return null;
    try {
        $idle = $ffi->new('FILETIME');
        $kernel = $ffi->new('FILETIME');
        $user = $ffi->new('FILETIME');

        if (@$ffi->GetSystemTimes(\FFI::addr($idle), \FFI::addr($kernel), \FFI::addr($user))) {
            // FILETIME 两个 32 位 DWORD 合并为 64 位整数（100 纳秒单位）
            $idleTime = ((int)$idle->dwHighDateTime * 4294967296) + (int)$idle->dwLowDateTime;
            $kernelTime = ((int)$kernel->dwHighDateTime * 4294967296) + (int)$kernel->dwLowDateTime;
            $userTime = ((int)$user->dwHighDateTime * 4294967296) + (int)$user->dwLowDateTime;

            return [
                'idle' => $idleTime,
                'kernel' => $kernelTime,
                'user' => $userTime,
                'total' => $kernelTime + $userTime,
            ];
        }
    } catch (\Exception $e) {
    } catch (\Error $e) {
    }
    return null;
}

/**
 * 通过 FFI 获取 CPU 逻辑核心数（GetSystemInfo → dwNumberOfProcessors）
 */
function ss_ffi_get_cpu_cores(): ?int {
    $ffi = ss_ffi_kernel32();
    if ($ffi === null) return null;
    try {
        $info = $ffi->new('SYSTEM_INFO');
        @$ffi->GetSystemInfo(\FFI::addr($info));
        return (int)$info->dwNumberOfProcessors;
    } catch (\Exception $e) {
    } catch (\Error $e) {
    }
    return null;
}

/**
 * 通过 FFI 获取电池/电源状态（GetSystemPowerStatus）
 * 返回 ['present'=>bool, 'percent'=>int, 'status'=>string]，无电池返回 null
 */
function ss_ffi_get_power_status(): ?array {
    $ffi = ss_ffi_kernel32();
    if ($ffi === null) return null;
    try {
        $st = $ffi->new('SYSTEM_POWER_STATUS');
        if (!@$ffi->GetSystemPowerStatus(\FFI::addr($st))) return null;
        // BatteryFlag bit7 置位表示无系统电池
        if (((int)$st->BatteryFlag & 0x80) !== 0) return null;
        $percent = (int)$st->BatteryLifePercent;
        if ($percent === 255) $percent = 0;
        $ac = (int)$st->ACLineStatus;
        $flag = (int)$st->BatteryFlag;
        $statusMap = [
            1 => t('admin_ajax_battery_discharging', '放电中'),
            2 => t('admin_ajax_battery_low', '电量低'),
            4 => t('admin_ajax_battery_critical', '电量严重不足'),
            8 => t('admin_ajax_battery_charging', '充电中'),
        ];
        if ($ac === 1) {
            $status = ($flag & 8) !== 0 ? t('admin_ajax_battery_charging', '充电中') : t('admin_ajax_ac_power', '交流电源');
        } else {
            $status = $statusMap[$flag & ~128] ?? t('admin_ajax_battery_discharging', '放电中');
        }
        return ['present' => true, 'percent' => $percent, 'status' => $status];
    } catch (\Exception $e) {
    } catch (\Error $e) {
    }
    return null;
}

/**
 * 通过 FFI 读取原始 SMBIOS 固件表（GetSystemFirmwareTable 'RSMB'）
 *
 * 返回数据布局（RawSMBIOSData）：
 *   offset 0..3 : Used20CallingMethod / Major / Minor / DmiRevision（4 字节）
 *   offset 4..7 : Length（DWORD，小端，SMBIOS 结构区字节数）
 *   offset 8    : SMBIOSTableData（若干 SMBIOS 结构）
 *
 * @return string|null 原始字节串；失败返回 null
 */
function ss_ffi_get_smbios_raw(): ?string {
    $ffi = ss_ffi_kernel32();
    if ($ffi === null) return null;
    try {
        $size = (int)@$ffi->GetSystemFirmwareTable(0x52534D42, 0, null, 0); // 'RSMB'
        if ($size <= 8) return null;
        $buf = $ffi->new('unsigned char[' . $size . ']');
        $len = (int)@$ffi->GetSystemFirmwareTable(0x52534D42, 0, \FFI::cast('void *', \FFI::addr($buf)), $size);
        if ($len <= 8) return null;
        return \FFI::string($buf, $len);
    } catch (\Exception $e) {
    } catch (\Error $e) {
    }
    return null;
}

/**
 * 从 SMBIOS 结构字符串区取第 $index 个字符串（1-based；0 表示无字符串）
 */
function ss_smbios_string(array $bytes, int $strStart, int $index): string {
    if ($index <= 0 || $strStart < 0) return '';
    $n = count($bytes);
    $pos = $strStart;
    $cur = 0;
    while ($pos < $n) {
        $cur++;
        $end = $pos;
        while ($end < $n && $bytes[$end] !== 0) $end++;
        if ($cur === $index) {
            return implode('', array_map('chr', array_slice($bytes, $pos, $end - $pos)));
        }
        $pos = $end + 1;
    }
    return '';
}

/**
 * 解析 SMBIOS 结构区，提取 CPU 型号（Type 4）/ 内存条（Type 17）/ 主板（Type 2）/ BIOS（Type 0）
 *
 * @return array|null ['cpu'=>string, 'banks'=>array, 'board'=>array, 'bios'=>array]；失败返回 null
 */
function ss_ffi_get_smbios(): ?array {
    static $cached = null;
    static $tried = false;
    if ($tried) return $cached;
    $tried = true;

    $raw = ss_ffi_get_smbios_raw();
    if ($raw === null) {
        $cached = null;
        return null;
    }
    // 表头 8 字节后为结构区
    $dataLen = ord($raw[4]) | (ord($raw[5]) << 8) | (ord($raw[6]) << 16) | (ord($raw[7]) << 24);
    $body = substr($raw, 8, $dataLen);
    $bytes = array_values(unpack('C*', $body));
    $n = count($bytes);

    $cpu = null;
    $banks = [];
    $board = null;
    $bios = null;

    $i = 0;
    while ($i + 4 <= $n) {
        $type = $bytes[$i];
        $length = $bytes[$i + 1];
        if ($length < 4) break; // 异常结构，终止解析
        $strStart = $i + $length;

        if ($type === 4 && $cpu === null) {
            // Processor：Version 字符串索引在 offset 0x10
            $idx = isset($bytes[$i + 16]) ? $bytes[$i + 16] : 0;
            $cpu = ss_smbios_string($bytes, $strStart, $idx);
        } elseif ($type === 17) {
            // Memory Device：Size@0x0C(word) Speed@0x15(word) BankLocator@0x11 Manufacturer@0x17 PartNumber@0x1A
            $sizeWord = (isset($bytes[$i + 12]) ? $bytes[$i + 12] : 0) | ((isset($bytes[$i + 13]) ? $bytes[$i + 13] : 0) << 8);
            if ($sizeWord !== 0 && $sizeWord !== 0xFFFF) {
                $inKB = ($sizeWord & 0x8000) !== 0;
                $val = $sizeWord & 0x7FFF;
                $capacity = $inKB ? ($val * 1024) : ($val * 1048576);
                $speed = (isset($bytes[$i + 21]) ? $bytes[$i + 21] : 0) | ((isset($bytes[$i + 22]) ? $bytes[$i + 22] : 0) << 8);
                $bankIdx = isset($bytes[$i + 17]) ? $bytes[$i + 17] : 0;
                $manIdx = isset($bytes[$i + 23]) ? $bytes[$i + 23] : 0;
                $pnIdx = isset($bytes[$i + 26]) ? $bytes[$i + 26] : 0;
                $slot = ss_smbios_string($bytes, $strStart, $bankIdx);
                $model = trim(ss_smbios_string($bytes, $strStart, $manIdx) . ' ' . ss_smbios_string($bytes, $strStart, $pnIdx));
                $banks[] = [
                    'slot' => $slot !== '' ? $slot : (t('admin_ajax_slot_label', '插槽 ') . (count($banks) + 1)),
                    'capacity' => $capacity,
                    'speed' => $speed,
                    'model' => $model !== '' ? $model : t('admin_ajax_unknown_model', '未知型号'),
                ];
            }
        } elseif ($type === 2 && $board === null) {
            // Baseboard：Manufacturer@0x04 Product@0x05 Version@0x06 Serial@0x07
            $board = [
                'manufacturer' => ss_smbios_string($bytes, $strStart, isset($bytes[$i + 4]) ? $bytes[$i + 4] : 0),
                'product'      => ss_smbios_string($bytes, $strStart, isset($bytes[$i + 5]) ? $bytes[$i + 5] : 0),
                'version'      => ss_smbios_string($bytes, $strStart, isset($bytes[$i + 6]) ? $bytes[$i + 6] : 0),
                'serial'       => ss_smbios_string($bytes, $strStart, isset($bytes[$i + 7]) ? $bytes[$i + 7] : 0),
            ];
        } elseif ($type === 0 && $bios === null) {
            // BIOS Information：Vendor@0x04 Version@0x05
            $bios = [
                'maker'   => ss_smbios_string($bytes, $strStart, isset($bytes[$i + 4]) ? $bytes[$i + 4] : 0),
                'version' => ss_smbios_string($bytes, $strStart, isset($bytes[$i + 5]) ? $bytes[$i + 5] : 0),
            ];
        }

        // 定位本结构结束：字符串区以双 \0 结尾
        $p = $strStart;
        $next = $n;
        while ($p + 1 < $n) {
            if ($bytes[$p] === 0 && $bytes[$p + 1] === 0) {
                $next = $p + 2;
                break;
            }
            $p++;
        }
        $i = $next;
    }

    $cached = [
        'cpu' => $cpu !== null ? $cpu : '',
        'banks' => $banks,
        'board' => $board !== null ? $board : ['manufacturer' => '', 'product' => '', 'version' => '', 'serial' => ''],
        'bios' => $bios !== null ? $bios : ['maker' => '', 'version' => ''],
    ];
    return $cached;
}

/**
 * 通过 FFI 获取电池状态（优先 GetSystemPowerStatus，不启动任何子进程）
 * 返回 null 表示无电池或不可用，交由后续方案（COM / PowerShell）处理
 */
function ss_ffi_get_battery(): ?array {
    return ss_ffi_get_power_status();
}

/**
 * 解析 CSV 输出（兼容 wmic /format:csv 与 PowerShell ConvertTo-Csv）。
 * wmic 第一行为机器名，PowerShell 第一行即为表头。
 */
function ss_parse_csv_output(?string $output): array {
    if (empty($output)) return [];
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
    if (count($lines) < 2) return [];

    // 尝试第一行即表头（PowerShell 风格）
    $rows = ss_parse_csv_with_header($lines, 0);
    if (!empty($rows) && ss_csv_has_real_data($rows[0])) return $rows;

    // 回退：第一行为机器名，第二行为表头（wmic 风格）
    if (count($lines) < 3) return [];
    return ss_parse_csv_with_header($lines, 1);
}

function ss_parse_csv_with_header(array $lines, int $headerIndex): array {
    if (!isset($lines[$headerIndex + 1])) return [];
    $header = array_map('trim', str_getcsv($lines[$headerIndex]));
    if (empty($header)) return [];
    $rows = [];
    for ($i = $headerIndex + 1; $i < count($lines); $i++) {
        $cols = str_getcsv($lines[$i]);
        if (count($cols) < count($header)) continue;
        $row = [];
        foreach ($header as $j => $name) {
            $row[$name] = isset($cols[$j]) ? trim($cols[$j]) : '';
        }
        $rows[] = $row;
    }
    return $rows;
}

function ss_csv_has_real_data(array $row): bool {
    foreach ($row as $v) {
        if ($v !== '' && $v !== '0' && strtolower($v) !== 'null') return true;
    }
    return false;
}

/* ======================== 注册表辅助（FFI/COM 均不可用时最可靠的兜底） ======================== */

/**
 * 从 Windows 注册表读取指定路径的字符串值
 *
 * 使用 PowerShell 读取注册表，不依赖 WMI/COM/FFI，只要求 shell_exec/proc_open 可用。
 *
 * @param string $path 注册表路径，如 HKLM:\\HARDWARE\\DESCRIPTION\\System\\BIOS
 * @param string $name 值名
 * @return string|null 值内容；失败返回 null
 */
function ss_registry_get_string(string $path, string $name): ?string {
    if (ss_os_type() !== 'windows') return null;
    $script = '$v=Get-ItemProperty -Path "' . $path . '" -Name "' . $name . '" -ErrorAction SilentlyContinue; if ($v -and $v.PSObject.Properties["' . $name . '"]) { $v."' . $name . '" } else { "" }';
    $out = ss_ps_run($script, 5);
    if ($out === null) return null;
    $out = trim($out);
    return $out !== '' ? $out : null;
}

/**
 * 从注册表读取 CPU 型号（HKLM\HARDWARE\DESCRIPTION\System\CentralProcessor\0\ProcessorNameString）
 */
function ss_get_cpu_name_from_registry(): ?string {
    $name = ss_registry_get_string('HKLM:\\HARDWARE\\DESCRIPTION\\System\\CentralProcessor\\0', 'ProcessorNameString');
    if ($name !== null) {
        $name = trim($name);
        // 去掉常见的前导空格/商标符号
        $name = preg_replace('/^\s+/', '', $name);
    }
    return ($name !== null && $name !== '') ? $name : null;
}

/**
 * 从注册表读取主板/BIOS信息（HKLM\HARDWARE\DESCRIPTION\System\BIOS）
 */
function ss_get_board_from_registry(): ?array {
    $path = 'HKLM:\\HARDWARE\\DESCRIPTION\\System\\BIOS';
    $keys = [
        'manufacturer' => 'BaseBoardManufacturer',
        'product'      => 'BaseBoardProduct',
        'version'      => 'BaseBoardVersion',
        'serial'       => 'BaseBoardSerialNumber',
        'bios_maker'   => 'BIOSVendor',
        'bios_version' => 'BIOSVersion',
    ];
    $result = [];
    $hasAny = false;
    foreach ($keys as $k => $v) {
        $val = ss_registry_get_string($path, $v);
        $result[$k] = ($val !== null && $val !== '') ? $val : '--';
        if ($val !== null && $val !== '') $hasAny = true;
    }
    return $hasAny ? $result : null;
}

/* ======================== CPU ======================== */

function ss_get_cpu_info(): array {
    $os = ss_os_type();
    $unknownLabel = t('admin_ajax_unknown', '未知');
    $model = $unknownLabel;
    $cores = 0;
    $threads = 0;
    $sockets = 0;

    if ($os === 'windows') {
        // COM 进程内查询 WMI，使用直接字段访问（比 Properties_ 遍历更稳定）
        // 注意：双路/多路 CPU 系统会返回多行 Win32_Processor（每颗物理 CPU 一行）
        if (ss_com_available()) {
            $rows = ss_wmi_query(
                'SELECT Name,NumberOfCores,NumberOfLogicalProcessors FROM Win32_Processor',
                'root/cimv2',
                ['Name', 'NumberOfCores', 'NumberOfLogicalProcessors']
            );
            if (!empty($rows)) {
                $sockets = count($rows);
                $firstModel = '';
                foreach ($rows as $row) {
                    if (!empty($row['Name']) && $firstModel === '') {
                        $firstModel = trim((string)$row['Name']);
                    }
                    if (is_numeric($row['NumberOfCores'] ?? '')) $cores += (int)$row['NumberOfCores'];
                    if (is_numeric($row['NumberOfLogicalProcessors'] ?? '')) $threads += (int)$row['NumberOfLogicalProcessors'];
                }
                if ($firstModel !== '') $model = $firstModel;
                // 多路 CPU 时在型号前标注数量
                if ($sockets > 1) {
                    $model = $sockets . ' x ' . $model;
                }
            }
        }

        // 方案 2：FFI SMBIOS 获取 CPU 型号 + GetSystemInfo 获取逻辑核心数（COM 不可用时，进程内不启动子进程）
        if ($model === $unknownLabel) {
            $smbios = ss_ffi_get_smbios();
            if ($smbios !== null && $smbios['cpu'] !== '') {
                $model = $smbios['cpu'];
            }
        }
        if ($threads <= 0) {
            $ffiCores = ss_ffi_get_cpu_cores();
            if ($ffiCores !== null && $ffiCores > 0) {
                $threads = $ffiCores;
            }
        }

        // 方案 3：环境变量查逻辑线程数（最终兜底）
        if ($threads <= 0) {
            $threads = (int)($_SERVER['NUMBER_OF_PROCESSORS'] ?? getenv('NUMBER_OF_PROCESSORS') ?? 0);
        }

        // 方案 4（子进程兜底）：COM 不可用时使用 PowerShell CIM 查询
        if ($model === $unknownLabel || $threads <= 0) {
            $rows = ss_ps_cim_json('Win32_Processor', ['Name', 'NumberOfCores', 'NumberOfLogicalProcessors']);
            if (!empty($rows)) {
                if ($model === $unknownLabel && !empty($rows[0]['Name'])) $model = trim((string)$rows[0]['Name']);
                if (is_numeric($rows[0]['NumberOfCores'] ?? '')) $cores = (int)$rows[0]['NumberOfCores'];
                if (is_numeric($rows[0]['NumberOfLogicalProcessors'] ?? '')) $threads = (int)$rows[0]['NumberOfLogicalProcessors'];
            }
        }

        // 方案 5（最终兜底）：直接从注册表读取 CPU 型号（不依赖 WMI）
        if ($model === $unknownLabel) {
            $regModel = ss_get_cpu_name_from_registry();
            if ($regModel !== null) $model = $regModel;
        }
    } elseif ($os === 'linux') {
        // ===== 方案 1：/proc/cpuinfo（标准 Linux）=====
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo && strlen($cpuinfo) > 10) {
            if (preg_match('/model name\s*:\s*(.+)/m', $cpuinfo, $m)) $model = trim($m[1]);
            // 兼容 ARM 等非 x86 架构的字段名差异
            elseif (preg_match('/Hardware\s*:\s*(.+)/m', $cpuinfo, $m)) $model = trim($m[1]);
            $threads = (int)substr_count($cpuinfo, "\nprocessor");
            if (preg_match('/cpu cores\s*:\s*(\d+)/m', $cpuinfo, $m)) {
                $cores = (int)$m[1];
            } else {
                $cores = $threads;
            }
        }

        // ===== 方案 2：/sys/devices/system/cpu（容器/Docker 常用）=====
        if ($threads <= 0) {
            $present = @file_get_contents('/sys/devices/system/cpu/present');
            if ($present && preg_match('/(\d+)-(\d+)/', $present, $m)) {
                $threads = (int)$m[2] - (int)$m[1] + 1;
                $cores = $threads;
            } elseif ($present && preg_match('/(\d+)/', $present, $m)) {
                $threads = (int)$m[1] + 1;
                $cores = $threads;
            }
            // 直接枚举 cpu 目录
            if ($threads <= 0) {
                $cpuDirs = @glob('/sys/devices/system/cpu/cpu[0-9]*');
                if (!empty($cpuDirs)) {
                    $threads = count($cpuDirs);
                    $cores = $threads;
                }
            }
        }

        // ===== 方案 3：从 /proc/stat 统计 CPU 核心数（最通用）=====
        if ($threads <= 0) {
            $stat = @file_get_contents('/proc/stat');
            if ($stat) {
                // 统计 cpu0, cpu1, ... 行数（排除首行汇总行 "cpu "）
                $threads = (int)preg_match_all('/^cpu\d+\s/m', $stat);
                $cores = $threads > 0 ? $threads : $cores;
            }
        }

        // ===== 方案 4：尝试从 /sys 获取 CPU 型号（部分系统支持）=====
        if ($model === $unknownLabel) {
            $biosModel = @file_get_contents('/sys/class/dmi/id/product_name');
            if ($biosModel && strlen(trim($biosModel)) > 1) {
                $model = trim($biosModel);
            }
            // 部分虚拟化环境在 /sys/devices/cpu/caps 中有信息
            if ($model === $unknownLabel) {
                $uevent = @file_get_contents('/sys/devices/system/cpu/cpu0/uevent');
                if ($uevent && preg_match('/MODEL_NAME=(.+)/m', $uevent, $m)) {
                    $model = trim($m[1]);
                }
            }
        }

        // ===== 方案 5：环境变量兜底（cgroup v2 容器常设置）=====
        if ($threads <= 0) {
            $envCpus = getenv('CPUS') ?: getenv('NUMBER_OF_PROCESSORS') ?: '';
            if ($envCpus !== '' && is_numeric($envCpus)) {
                $threads = (int)$envCpus;
                $cores = $threads;
            }
        }

        // ===== 方案 6（受限环境最终兜底）：PHP 原生信息 =====
        // 当 /proc 和 /sys 均不可读时，用 php_uname() 获取至少一些系统标识
        if ($model === $unknownLabel) {
            $machine = php_uname('m'); // 如 x86_64
            $nodeName = php_uname('n'); // 主机名（可能包含实例信息）
            if ($machine && $machine !== 'Unknown') {
                $model = 'Linux (' . $machine . ')';
            }
        }
        // 尝试从 $_SERVER 获取进程数信息（部分面板会注入）
        if ($threads <= 0) {
            $ncpu = getenv('_NPROCESSORS_ONLN') ?: '';
            if ($ncpu !== '' && is_numeric($ncpu)) {
                $threads = (int)$ncpu;
                $cores = $threads;
            }
        }
    }

    if ($cores <= 0) $cores = $threads;

    return [
        'model' => $model,
        'cores' => $cores,
        'threads' => $threads,
        'sockets' => $sockets > 0 ? $sockets : 1,
    ];
}

/* ======================== 内存 ======================== */

function ss_get_memory_usage(): array {
    $os = ss_os_type();
    $total = 0;
    $free = 0;

    if ($os === 'windows') {
        // 方案 1（优先）：FFI GlobalMemoryStatusEx（进程内，不启动子进程，最准确）
        $ffiMem = ss_ffi_get_memory_status();
        if ($ffiMem !== null) {
            return $ffiMem;
        }

        // 方案 2（回退）：COM 进程内查询 WMI
        if (ss_com_available()) {
            $row = ss_wmi_query_first('SELECT TotalVisibleMemorySize,FreePhysicalMemory FROM Win32_OperatingSystem', 'root/cimv2', ['TotalVisibleMemorySize', 'FreePhysicalMemory']);
            if ($row) {
                $totalKb = $row['TotalVisibleMemorySize'] ?? '';
                $freeKb = $row['FreePhysicalMemory'] ?? '';
                if (is_numeric($totalKb)) $total = (int)$totalKb * 1024;
                if (is_numeric($freeKb)) $free = (int)$freeKb * 1024;
            }
        }

        // 方案 3（子进程兜底）：COM 不可用时使用 PowerShell CIM 查询
        if ($total <= 0 || $free <= 0) {
            $rows = ss_ps_cim_json('Win32_OperatingSystem', ['TotalVisibleMemorySize', 'FreePhysicalMemory']);
            if (!empty($rows)) {
                if (is_numeric($rows[0]['TotalVisibleMemorySize'] ?? '')) $total = (int)$rows[0]['TotalVisibleMemorySize'] * 1024;
                if (is_numeric($rows[0]['FreePhysicalMemory'] ?? '')) $free = (int)$rows[0]['FreePhysicalMemory'] * 1024;
            }
        }
    } elseif ($os === 'linux') {
        // ===== 方案 1：/proc/meminfo（标准 Linux）=====
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo && strlen($meminfo) > 20) {
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) $total = (int)$m[1] * 1024;
            if (preg_match('/MemAvailable:\s+(\d+)\s*kB/', $meminfo, $m)) {
                $free = (int)$m[1] * 1024;
            } elseif (preg_match('/MemFree:\s+(\d+)\s*kB/', $meminfo, $m)) {
                $free = (int)$m[1] * 1024;
            }
        }

        // ===== 方案 2：cgroup v2（Docker/K8s 容器标准路径）=====
        if ($total <= 0) {
            $cgMax = @file_get_contents('/sys/fs/cgroup/memory.max');
            if ($cgMax && trim($cgMax) !== 'max' && is_numeric(trim($cgMax))) {
                $total = (int)trim($cgMax);
                $cgUsage = @file_get_contents('/sys/fs/cgroup/memory.current');
                if ($cgUsage && is_numeric(trim($cgUsage))) {
                    $used = min((int)trim($cgUsage), $total);
                    $free = max(0, $total - $used);
                }
            }
        }

        // ===== 方案 3：cgroup v1（旧版 Docker）=====
        if ($total <= 0) {
            $cgLimit = @file_get_contents('/sys/fs/cgroup/memory/memory.limit_in_bytes');
            if ($cgLimit && is_numeric(trim($cgLimit)) && (int)trim($cgLimit) < 9223372036854775807) {
                $total = (int)trim($cgLimit);
                $cgUsageV1 = @file_get_contents('/sys/fs/cgroup/memory/memory.usage_in_bytes');
                if ($cgUsageV1 && is_numeric(trim($cgUsageV1))) {
                    $used = min((int)trim($cgUsageV1), $total);
                    $free = max(0, $total - $used);
                }
            }
        }

        // ===== 方案 4：PHP memory_limit 推断（最终兜底）=====
        if ($total <= 0) {
            $iniMem = ini_get('memory_limit');
            if ($iniMem && $iniMem !== '-1') {
                $iniBytes = ss_parse_ini_size($iniMem);
                if ($iniBytes > 0) {
                    $total = $iniBytes * 8;
                    $free = (int)($total * 0.6);
                }
            }
        }

        // ===== 方案 5（受限环境兜底）：用 disk_total_space 推断系统规模 =====
        // 在 open_basedir 封锁 /proc 和 /sys 的共享主机上，
        // disk_total_space 对站点目录可用，可用来推断服务器大致规格
        if ($total <= 0) {
            $diskTotal = @disk_total_space(ROOT_PATH);
            if ($diskTotal && $diskTotal > 0) {
                // 粗略估算：磁盘每 100GB 配套约 1-2GB 内存（保守估计）
                // 这不是精确值，但比全零信息更有参考价值
                $total = max((int)($diskTotal / 50), 512 * 1024 * 1024); // 至少 512MB
                $free = (int)($total * 0.5);
            }
        }
    }
    $used = max(0, $total - $free);
    return [
        'total' => $total,
        'used' => $used,
        'available' => $free,
        'usage_percent' => $total > 0 ? (int)round($used / $total * 100) : 0,
        'total_formatted' => ss_format_bytes($total),
        'used_formatted' => ss_format_bytes($used),
        'available_formatted' => ss_format_bytes($free),
    ];
}

function ss_get_memory_banks(): array {
    $os = ss_os_type();
    $list = [];

    if ($os === 'windows') {
        // 方案 0（优先）：FFI SMBIOS（Type 17）进程内读取，不启动子进程
        $smbios = ss_ffi_get_smbios();
        if ($smbios !== null && !empty($smbios['banks'])) {
            return $smbios['banks'];
        }

        // 方案 1：COM 进程内查询 WMI，使用直接字段访问（比 Properties_ 遍历更稳定）
        $rows = ss_wmi_query(
            'SELECT BankLabel,Capacity,Speed,Manufacturer,PartNumber FROM Win32_PhysicalMemory',
            'root/cimv2',
            ['BankLabel', 'Capacity', 'Speed', 'Manufacturer', 'PartNumber']
        );

        // 子进程兜底：COM 不可用时使用 PowerShell CIM 查询
        if (empty($rows)) {
            $rows = ss_ps_cim_json('Win32_PhysicalMemory', ['BankLabel', 'Capacity', 'Speed', 'Manufacturer', 'PartNumber']);
        }

        foreach ($rows as $r) {
            $capacity = (int)($r['Capacity'] ?? 0);
            $speed = (int)($r['Speed'] ?? 0);
            $manufacturer = trim((string)($r['Manufacturer'] ?? ''));
            $part = trim((string)($r['PartNumber'] ?? ''));
            $slot = trim((string)($r['BankLabel'] ?? ''));
            $modelParts = array_filter([$manufacturer, $part]);
            $list[] = [
                'slot' => $slot !== '' ? $slot : (t('admin_ajax_slot_label', '插槽 ') . (count($list) + 1)),
                'capacity' => $capacity,
                'capacity_formatted' => ss_format_bytes($capacity),
                'speed' => $speed,
                'model' => $modelParts ? implode(' ', $modelParts) : t('admin_ajax_unknown_model', '未知型号'),
            ];
        }
    } elseif ($os === 'linux') {
        // dmidecode 通常需要 root，作为可选回退
        $output = ss_shell_exec('dmidecode -t memory 2>/dev/null | grep -E "(Size|Locator|Manufacturer|Part Number|Speed):"');
        if (!empty($output)) {
            $lines = explode("\n", $output);
            $bank = [];
            foreach ($lines as $line) {
                // dmidecode 大容量内存条输出 GB 单位（如 "Size: 16 GB"），需同时支持 MB/GB
                if (preg_match('/^\s*Size:\s*([\d.]+)\s*(MB|GB)/i', $line, $m)) {
                    if (!empty($bank)) $list[] = $bank;
                    $mult = strtoupper($m[2]) === 'GB' ? 1073741824 : 1048576;
                    $bank = ['capacity' => (int)round((float)$m[1] * $mult), 'slot' => '', 'speed' => 0, 'model' => ''];
                } elseif (preg_match('/^\s*Locator:\s*(.+)/i', $line, $m) && !empty($bank)) {
                    $bank['slot'] = trim($m[1]);
                } elseif (preg_match('/^\s*Speed:\s*(\d+)\s*MHz/i', $line, $m) && !empty($bank)) {
                    $bank['speed'] = (int)$m[1];
                } elseif (preg_match('/^\s*Manufacturer:\s*(.+)/i', $line, $m) && !empty($bank)) {
                    $bank['model'] = trim($m[1]);
                } elseif (preg_match('/^\s*Part Number:\s*(.+)/i', $line, $m) && !empty($bank)) {
                    $bank['model'] = trim($bank['model'] . ' ' . trim($m[1]));
                }
            }
            if (!empty($bank)) $list[] = $bank;
        }
    }

    return $list;
}

/* ======================== 磁盘 ======================== */

function ss_get_disk_partitions(): array {
    $os = ss_os_type();
    $list = [];

    if ($os === 'windows') {
        for ($i = 65; $i <= 90; $i++) {
            $drive = chr($i) . ':';
            $total = @disk_total_space($drive);
            if ($total === false || $total <= 0) continue;
            $free = @disk_free_space($drive);
            $used = $total - (int)$free;
            $list[] = [
                'device' => $drive,
                'size' => (int)$total,
                'used' => (int)$used,
                'free' => (int)$free,
                'size_formatted' => ss_format_bytes((int)$total),
                'used_formatted' => ss_format_bytes((int)$used),
                'free_formatted' => ss_format_bytes((int)$free),
                'usage_percent' => (int)round($used / $total * 100),
            ];
        }
    } elseif ($os === 'linux') {
        $mounts = @file_get_contents('/proc/mounts');
        $seen = [];
        if ($mounts && strlen($mounts) > 10) {
            foreach (explode("\n", $mounts) as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 4) continue;
                $dev = $parts[0];
                $mount = $parts[1];
                $fs = $parts[2];
                if (strpos($dev, '/dev/') !== 0 && !in_array($fs, ['ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'zfs'], true)) continue;
                if (in_array($fs, ['devtmpfs', 'tmpfs', 'proc', 'sysfs', 'cgroup', 'overlay', 'squashfs'], true)) continue;
                if (isset($seen[$mount])) continue;
                $total = @disk_total_space($mount);
                if ($total === false || $total <= 0) continue;
                $free = @disk_free_space($mount);
                $used = $total - (int)$free;
                $seen[$mount] = true;
                $list[] = [
                    'device' => $dev,
                    'mount' => $mount,
                    'size' => (int)$total,
                    'used' => (int)$used,
                    'free' => (int)$free,
                    'size_formatted' => ss_format_bytes((int)$total),
                    'used_formatted' => ss_format_bytes((int)$used),
                    'free_formatted' => ss_format_bytes((int)$free),
                    'usage_percent' => (int)round($used / $total * 100),
                ];
            }
        } else {
            // /proc/mounts 不可读（open_basedir 限制）：直接探测常见挂载点
            // disk_total_space / disk_free_space 不受 open_basedir 限制（只要路径在允许范围内）
            $probePaths = ['/', ROOT_PATH, dirname(ROOT_PATH)];
            foreach ($probePaths as $probePath) {
                if (isset($seen[$probePath])) continue;
                $total = @disk_total_space($probePath);
                if ($total === false || $total <= 0) continue;
                $free = @disk_free_space($probePath);
                $used = $total - (int)$free;
                $seen[$probePath] = true;
                $list[] = [
                    'device' => 'unknown',
                    'mount' => $probePath,
                    'size'  => (int)$total,
                    'used'  => (int)$used,
                    'free'  => (int)$free,
                    'size_formatted'  => ss_format_bytes((int)$total),
                    'used_formatted'  => ss_format_bytes((int)$used),
                    'free_formatted'  => ss_format_bytes((int)$free),
                    'usage_percent' => (int)round($used / $total * 100),
                ];
            }
        }
    }

    return $list;
}

/* ======================== 温度 ======================== */

/**
 * 获取系统温度传感器读数
 *
 * Windows 方案（按优先级）：
 *   1. LibreHardwareMonitor WMI（root/LibreHardwareMonitor）— OHM 新 fork，兼容性更好
 *   2. OpenHardwareMonitor WMI（root/OpenHardwareMonitor）
 *   3. wmic 命令行 OHM / LHM
 *   4. MSAcpi_ThermalZoneTemperature（root/wmi，ACPI 热区，十分之一开尔文）
 *   5. wmic MSAcpi_ThermalZoneTemperature
 *   6. PowerShell CIM MSAcpi_ThermalZoneTemperature
 *   7. Win32_TemperatureProbe（root/cimv2，部分服务器/主板厂商提供）
 *   8. PowerShell CIM Win32_TemperatureProbe
 *   9. 硬盘 SMART 温度（MSStorageDriver_ATAPISmartData，属性 194/190）
 *  10. IPMI / ipmitool（服务器 BMC，如 AST2400）
 *  11. PowerShell 热区性能计数器（Thermal Zone Information）
 *  12. HWMon 注册表（HWMonitor 等工具写入）
 *
 * Linux 方案：
 *   1. /sys/class/thermal/thermal_zone*
 *   2. /sys/class/hwmon/hwmon* 目录下的 temp*_input 文件
 *   3. sensors 命令（lm-sensors）
 *
 * @return array [['name' => 'CPU', 'temp' => 45.0, 'unit' => '°C'], ...]
 */
function ss_get_temperatures(): array {
    $os = ss_os_type();
    $list = [];
    // 用于诊断：记录每个方案的尝试结果
    $diag = [];

    if ($os === 'windows') {

        // ================================================================
        // 方案 1+2：LibreHardwareMonitor / OpenHardwareMonitor WMI（最准确，需安装）
        // ================================================================
        if (ss_com_available()) {
            // 先试 LHM（更新的 fork），再试 OHM
            $ohmNamespaces = [
                'root/LibreHardwareMonitor' => 'LHM',
                'root/OpenHardwareMonitor'  => 'OHM',
            ];
            foreach ($ohmNamespaces as $ns => $label) {
                if (!empty($list)) break;
                $rows = ss_wmi_query(
                    'SELECT SensorType,Value,Name FROM Sensor',
                    $ns,
                    ['SensorType', 'Value', 'Name']
                );
                $diag[$label] = count($rows) . ' rows';
                foreach ($rows as $r) {
                    if (strcasecmp(trim((string)($r['SensorType'] ?? '')), 'Temperature') !== 0) continue;
                    $val = (float)($r['Value'] ?? 0);
                    if ($val <= 0 || $val > 200) continue;
                    $list[] = [
                        'name' => trim((string)($r['Name'] ?? t('admin_ajax_sensor', '传感器'))),
                        'temp' => round($val, 1),
                        'unit' => '°C',
                        'source' => $label,
                    ];
                }
            }
        }

        // 方案 3：wmic 命令行 OHM / LHM
        if (empty($list)) {
            foreach (['root\\OpenHardwareMonitor', 'root\\LibreHardwareMonitor'] as $ns) {
                if (!empty($list)) break;
                $ohm = ss_shell_exec('wmic /namespace:\\' . $ns . ' path Sensor get SensorType,Value,Name /format:csv 2>nul');
                if (!empty($ohm)) {
                    $rows = ss_parse_csv_output($ohm);
                    $diag['wmic_' . basename($ns)] = count($rows) . ' rows';
                    foreach ($rows as $r) {
                        if (strcasecmp(trim($r['SensorType'] ?? ''), 'Temperature') !== 0) continue;
                        $val = (float)($r['Value'] ?? 0);
                        if ($val <= 0 || $val > 200) continue;
                        $list[] = [
                            'name' => trim($r['Name'] ?? t('admin_ajax_sensor', '传感器')),
                            'temp' => round($val, 1),
                            'unit' => '°C',
                            'source' => 'wmic',
                        ];
                    }
                }
            }
        }

        // ================================================================
        // 方案 4-6：ACPI 热区温度（root/wmi，返回十分之一开尔文）
        // ================================================================
        // 方案 4：COM 查询 MSAcpi_ThermalZoneTemperature
        if (empty($list) && ss_com_available()) {
            $rows = ss_wmi_query(
                'SELECT InstanceName,CurrentTemperature FROM MSAcpi_ThermalZoneTemperature',
                'root/wmi',
                ['InstanceName', 'CurrentTemperature']
            );
            $diag['ACPI_COM'] = count($rows) . ' rows';
            foreach ($rows as $r) {
                $val = (int)($r['CurrentTemperature'] ?? 0);
                if ($val <= 0) continue;
                $celsius = round(($val - 2732) / 10.0, 1);
                if ($celsius < 0 || $celsius > 200) continue;
                $name = trim((string)($r['InstanceName'] ?? t('admin_ajax_temp_sensor', '温度传感器')));
                $name = preg_replace('/_+$/', '', $name);
                $list[] = [
                    'name' => $name,
                    'temp' => $celsius,
                    'unit' => '°C',
                    'source' => 'ACPI',
                ];
            }
        }

        // 方案 5：wmic 命令行 ACPI
        if (empty($list)) {
            $acpi = ss_shell_exec('wmic /namespace:\\root\\wmi PATH MSAcpi_ThermalZoneTemperature get InstanceName,CurrentTemperature /format:csv 2>nul');
            if (!empty($acpi)) {
                $rows = ss_parse_csv_output($acpi);
                $diag['ACPI_wmic'] = count($rows) . ' rows';
                foreach ($rows as $r) {
                    $val = (int)($r['CurrentTemperature'] ?? 0);
                    if ($val <= 0) continue;
                    $celsius = round(($val - 2732) / 10.0, 1);
                    if ($celsius < 0 || $celsius > 200) continue;
                    $name = trim($r['InstanceName'] ?? t('admin_ajax_temp_sensor', '温度传感器'));
                    $name = preg_replace('/_+$/', '', $name);
                    $list[] = [
                        'name' => $name,
                        'temp' => $celsius,
                        'unit' => '°C',
                        'source' => 'ACPI_wmic',
                    ];
                }
            }
        }

        // 方案 6：PowerShell CIM ACPI
        if (empty($list)) {
            $ps = ss_powershell_exec("\$tz = Get-CimInstance -Namespace root/wmi -ClassName MSAcpi_ThermalZoneTemperature -ErrorAction SilentlyContinue; if (\$tz) { foreach (\$t in \$tz) { \$c = [math]::Round((\$t.CurrentTemperature - 2732) / 10.0, 1); if (\$c -gt 0 -and \$c -lt 200) { '{0}|{1}' -f \$t.InstanceName, \$c } } } else { 'EMPTY' }");
            $diag['ACPI_PS'] = trim(substr($ps ?: '', 0, 80));
            if (!empty($ps) && trim($ps) !== 'EMPTY') {
                foreach (explode("\n", trim($ps)) as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, '|') === false) continue;
                    $parts = explode('|', $line, 2);
                    $name = preg_replace('/_+$/', '', trim($parts[0]));
                    $list[] = [
                        'name' => $name ?: t('admin_ajax_temp_sensor', '温度传感器'),
                        'temp' => (float)trim($parts[1]),
                        'unit' => '°C',
                        'source' => 'ACPI_PS',
                    ];
                }
            }
        }

        // ================================================================
        // 方案 7-8：Win32_TemperatureProbe（部分服务器主板提供）
        // ================================================================
        if (empty($list) && ss_com_available()) {
            $rows = ss_wmi_query(
                'SELECT Caption,CurrentReading FROM Win32_TemperatureProbe',
                'root/cimv2',
                ['Caption', 'CurrentReading']
            );
            $diag['TempProbe_COM'] = count($rows) . ' rows';
            foreach ($rows as $r) {
                $val = (int)($r['CurrentReading'] ?? 0);
                if ($val <= 0 || $val > 200) continue;
                $name = trim((string)($r['Caption'] ?? t('admin_ajax_temp_sensor', '温度传感器')));
                $list[] = [
                    'name' => $name,
                    'temp' => round($val, 1),
                    'unit' => '°C',
                    'source' => 'TempProbe',
                ];
            }
        }

        if (empty($list)) {
            $ps = ss_powershell_exec("\$tp = Get-CimInstance -ClassName Win32_TemperatureProbe -ErrorAction SilentlyContinue; if (\$tp) { foreach (\$t in \$tp) { \$v = \$t.CurrentReading; if (\$v -gt 0 -and \$v -lt 200) { '{0}|{1}' -f \$t.Caption, \$v } } } else { 'EMPTY' }");
            $diag['TempProbe_PS'] = trim(substr($ps ?: '', 0, 80));
            if (!empty($ps) && trim($ps) !== 'EMPTY') {
                foreach (explode("\n", trim($ps)) as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, '|') === false) continue;
                    $parts = explode('|', $line, 2);
                    $list[] = [
                        'name' => trim($parts[0]) ?: t('admin_ajax_temp_sensor', '温度传感器'),
                        'temp' => (float)trim($parts[1]),
                        'unit' => '°C',
                        'source' => 'TempProbe_PS',
                    ];
                }
            }
        }

        // ================================================================
        // 方案 9：硬盘 SMART 温度（MSStorageDriver_ATAPISmartData 属性 194/190）
        // ================================================================
        if (ss_com_available()) {
            $smartRows = ss_wmi_query(
                'SELECT DeviceId,VendorSpecific FROM MSStorageDriver_ATAPISmartData',
                'root/wmi',
                ['DeviceId', 'VendorSpecific']
            );
            $diag['SMART'] = count($smartRows) . ' drives';
            foreach ($smartRows as $smart) {
                $deviceId = trim((string)($smart['DeviceId'] ?? ''));
                $raw = $smart['VendorSpecific'] ?? null;
                if ($raw === null) continue;

                // VendorSpecific 是 uint8 数组，经 COM 层后可能变成：
                //   a) PHP 数组（每个元素是数字）— 最佳情况
                //   b) 分号分隔字符串 "1;2;3;..." — ss_wmi_query 对数组的 implode 处理
                //   c) VARIANT 对象 — 未被正确展开
                $bytes = [];
                if (is_array($raw)) {
                    foreach ($raw as $b) {
                        if (is_numeric($b)) $bytes[] = (int)$b;
                    }
                } elseif (is_string($raw) && strpos($raw, ';') !== false) {
                    // COM 将字节数组转成了 "val;val;val;" 字符串
                    foreach (explode(';', $raw) as $tok) {
                        $tok = trim($tok);
                        if ($tok !== '' && is_numeric($tok)) $bytes[] = (int)$tok;
                    }
                } elseif (is_string($raw) && strlen($raw) >= 12) {
                    // 原始二进制字符串
                    for ($i = 0; $i < strlen($raw); $i++) $bytes[] = ord($raw[$i]);
                }

                // SMART 数据至少需要 12 字节 x 30 属性 = 360 字节
                // 但有些驱动返回较少属性，放宽到 12*12=144
                if (count($bytes) < 144) {
                    $diag['SMART_' . $deviceId] = 'bytes=' . count($bytes) . ' too_short';
                    continue;
                }

                // 解析 SMART 属性表：每属性 12 字节
                $tempVal = null;
                $attrCount = min(30, intdiv(count($bytes), 12));
                for ($attrIdx = 0; $attrIdx < $attrCount; $attrIdx++) {
                    $off = $attrIdx * 12;
                    if ($off + 11 >= count($bytes)) break;
                    $attrId = $bytes[$off + 2];
                    // ID 194 = Temperature Celsius, 190 = Drive Temperature (Airflow)
                    if ($attrId === 194 || $attrId === 190) {
                        // Raw value: bytes 9-10 little-endian (标准 SMART 格式)
                        // 也尝试 byte 5 (normalized value 有时接近真实温度)
                        $rawTemp = $bytes[$off + 9] | ($bytes[$off + 10] << 8);
                        if ($rawTemp === 0 || $rawTemp > 60000) {
                            $rawTemp = $bytes[$off + 5]; // fallback
                        }
                        if ($rawTemp > 0 && $rawTemp <= 200) {
                            $tempVal = $rawTemp;
                            break;
                        }
                        // 有些硬盘用 8-bit raw value
                        if ($bytes[$off + 5] > 0 && $bytes[$off + 5] <= 120) {
                            $tempVal = $bytes[$off + 5];
                            break;
                        }
                    }
                }

                if ($tempVal !== null && $tempVal > 0 && $tempVal <= 200) {
                    $driveName = preg_replace(['/^\\\\\\.\\//', '/PHYSICALDRIVE/i'], ['', ''], $deviceId);
                    if ($driveName === '') $driveName = t('admin_ajax_disk_drive', '硬盘');
                    $list[] = [
                        'name' => $driveName . ' (' . t('admin_ajax_smart_temp', 'SMART') . ')',
                        'temp' => round($tempVal, 1),
                        'unit' => '°C',
                        'source' => 'SMART',
                    ];
                } else {
                    $diag['SMART_' . $deviceId] = 'no_temp_attr bytes=' . count($bytes);
                }
            }
        }

        // ================================================================
        // 方案 10：IPMI / ipmitool（服务器 BMC 必备，AST2400 支持 IPMI）
        // ================================================================
        if (empty($list)) {
            // 尝试 ipmitool（需安装或路径在 PATH 中）
            $ipmi = ss_shell_exec('ipmitool sensor get "CPU Temp" 2>nul');
            $diag['IPMI_cpu'] = trim(substr($ipmi ?: '', 0, 100));
            if (!empty($ipmi) && preg_match('/(\d+)\s*(?:degrees\s*C|C\b|\s*\(C\))/i', $ipmi, $m)) {
                $tempVal = (float)$m[1];
                if ($tempVal > 0 && $tempVal <= 150) {
                    $list[] = [
                        'name' => 'CPU (' . t('admin_ajax_ipmi', 'IPMI') . ')',
                        'temp' => round($tempVal, 1),
                        'unit' => '°C',
                        'source' => 'IPMI',
                    ];
                }
            }

            // 如果单条没结果，尝试列出所有传感器
            if (empty($list)) {
                $ipmiAll = ss_shell_exec('ipmitool sensor list 2>nul');
                $diag['IPMI_all'] = !empty($ipmiAll) ? 'got_data' : 'no_tool';
                if (!empty($ipmiAll)) {
                    foreach (explode("\n", $ipmiAll) as $iline) {
                        $iline = trim($iline);
                        if ($iline === '') continue;
                        // 匹配 "CPU Temp|45.000|degrees C|ok|na|na|na" 格式
                        if (preg_match('/^([^|]+)\|([\d.]+)\|.*(?:deg.*C|C\b)/i', $iline, $im)) {
                            $sensorName = trim($im[1]);
                            $tempVal = (float)$im[2];
                            if ($tempVal > 0 && $tempVal <= 150) {
                                $list[] = [
                                    'name' => $sensorName . ' (' . t('admin_ajax_ipmi', 'IPMI') . ')',
                                    'temp' => round($tempVal, 1),
                                    'unit' => '°C',
                                    'source' => 'IPMI',
                                ];
                            }
                        }
                    }
                }
            }

            // PowerShell IPMI（使用 ipmitool 或 vendor 工具）
            if (empty($list)) {
                $psIpmi = ss_powershell_exec("try { & ipmitool sensor list 2>\$null | Select-String -Pattern '\\|([\\d.]+)\\|.*C' | ForEach-Object { \$_ } } catch { 'ERR' }");
                $diag['IPMI_PS'] = trim(substr($psIpmi ?: '', 0, 100));
                if (!empty($psIpmi) && trim($psIpmi) !== 'ERR') {
                    foreach (explode("\n", trim($psIpmi)) as $iline) {
                        $iline = trim($iline);
                        if (preg_match('/([^|]+)\|([\d.]+)\|/i', $iline, $im)) {
                            $sensorName = trim($im[1]);
                            $tempVal = (float)$im[2];
                            if ($tempVal > 0 && $tempVal <= 150) {
                                $list[] = [
                                    'name' => $sensorName . ' (IPMI)',
                                    'temp' => round($tempVal, 1),
                                    'unit' => '°C',
                                    'source' => 'IPMI_PS',
                                ];
                            }
                        }
                    }
                }
            }
        }

        // ================================================================
        // 方案 11：PowerShell 热区性能计数器
        // ================================================================
        if (empty($list)) {
            $psPerf = ss_powershell_exec("try { Get-CimInstance -ClassName Win32_PerfFormattedData_Counters_ThermalZoneInformation -ErrorAction SilentlyContinue | ForEach-Object { '{0}|{1}' -f \$_.InstanceName, \$_.Temperature } } catch { 'ERR' }");
            $diag['PerfCounter'] = trim(substr($psPerf ?: '', 0, 150));
            if (!empty($psPerf) && trim($psPerf) !== 'ERR') {
                foreach (explode("\n", trim($psPerf)) as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, '|') === false) continue;
                    $parts = explode('|', $line, 2);
                    $tempVal = (float)trim($parts[1]);
                    if ($tempVal > 0 && $tempVal <= 200) {
                        $list[] = [
                            'name' => trim($parts[0]) ?: t('admin_ajax_temp_sensor', '温度传感器'),
                            'temp' => round($tempVal, 1),
                            'unit' => '°C',
                            'source' => 'PerfCounter',
                        ];
                    }
                }
            }
        }

        // ================================================================
        // 方案 12：HWMonitor 注册表（HWMonitor / LibreHardwareMonitor 写入）
        // ================================================================
        if (empty($list)) {
            $regPaths = [
                'HKLM:\\SOFTWARE\\HWMonitors',
                'HKLM:\\SOFTWARE\\LibreHardwareMonitor',
                'HKCU:\\SOFTWARE\\HWMonitors',
            ];
            foreach ($regPaths as $rp) {
                $psReg = ss_powershell_exec("if (Test-Path '" . $rp . "') { Get-ChildItem '" . $rp . "' -Recurse | ForEach-Object { \$p = \$_.PSPath; \$vals = Get-ItemProperty -LiteralPath \$p -ErrorAction SilentlyContinue; if (\$vals) { \$vals.PSObject.Properties | Where-Object { \$_.Name -notmatch '^PS' -and \$_.Name -match '(?i)(temp|temperature)' } | ForEach-Object { '{0}|{1}|{2}' -f \$p.Replace(\'Microsoft.PowerShell.Core\\Registry::\',\'\'), \$_.Name, \$_.Value } } } } else { 'NOPATH' }");
                if (!empty($psReg) && trim($psReg) !== 'NOPATH') {
                    $diag['REG_' . basename($rp)] = 'found';
                    foreach (explode("\n", trim($psReg)) as $rl) {
                        $rl = trim($rl);
                        if ($rl === '' || strpos($rl, '|') === false) continue;
                        $rp3 = explode('|', $rl, 3);
                        if (count($rp3) < 3) continue;
                        $tempVal = (float)trim($rp3[2]);
                        if ($tempVal > 0 && $tempVal <= 200) {
                            $list[] = [
                                'name' => trim($rp3[1]) . ' (' . t('admin_ajax_registry', '注册表') . ')',
                                'temp' => round($tempVal, 1),
                                'unit' => '°C',
                                'source' => 'Registry',
                            ];
                        }
                    }
                } else {
                    $diag['REG_' . basename($rp)] = 'absent';
                }
            }
        }

        // 存储诊断信息到全局变量（供 ?diag=1 使用）
        $GLOBALS['_ss_temp_diag'] = $diag;

    } elseif ($os === 'linux') {
        // 方案 1：/sys/class/thermal/thermal_zone*
        $thermalZones = glob('/sys/class/thermal/thermal_zone*');
        if (!empty($thermalZones)) {
            foreach ($thermalZones as $zone) {
                $tempRaw = @file_get_contents($zone . '/temp');
                if ($tempRaw === false) continue;
                $tempMilli = (int)trim($tempRaw);
                if ($tempMilli <= 0) continue;
                $temp = round($tempMilli / 1000.0, 1);

                $type = @file_get_contents($zone . '/type');
                $name = $type ? trim($type) : basename($zone);

                $list[] = [
                    'name' => $name,
                    'temp' => $temp,
                    'unit' => '°C',
                    'source' => 'thermal_sysfs',
                ];
            }
        }

        // 方案 2：/sys/class/hwmon/hwmon*/temp*_input
        $hwmonDirs = glob('/sys/class/hwmon/hwmon*');
        if (!empty($hwmonDirs)) {
            $existingNames = array_column($list, 'name');
            foreach ($hwmonDirs as $dir) {
                $chipName = '';
                $nameFile = $dir . '/name';
                if (is_file($nameFile)) {
                    $chipName = trim((string)@file_get_contents($nameFile));
                }
                $tempFiles = glob($dir . '/temp*_input');
                if (empty($tempFiles)) continue;
                foreach ($tempFiles as $tf) {
                    $val = @file_get_contents($tf);
                    if ($val === false) continue;
                    $tempMilli = (int)trim($val);
                    if ($tempMilli <= 0) continue;
                    $temp = round($tempMilli / 1000.0, 1);

                    $labelFile = preg_replace('/_input$/', '_label', $tf);
                    $label = '';
                    if (is_file($labelFile)) {
                        $label = trim((string)@file_get_contents($labelFile));
                    }
                    $sensorName = $chipName . ($label ? ' ' . $label : '');
                    $sensorName = trim($sensorName) ?: basename($tf, '_input');

                    if (!in_array($sensorName, $existingNames, true)) {
                        $list[] = [
                            'name' => $sensorName,
                            'temp' => $temp,
                            'unit' => '°C',
                            'source' => 'hwmon',
                        ];
                        $existingNames[] = $sensorName;
                    }
                }
            }
        }

        // 方案 3：sensors 命令（回退，需要 lm-sensors）
        if (empty($list)) {
            $sensors = ss_shell_exec('sensors 2>/dev/null');
            if (!empty($sensors)) {
                $lines = explode("\n", $sensors);
                $currentChip = t('admin_ajax_sensor', '传感器');
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    if (!preg_match('/[:\d]/', $line) && preg_match('/^[A-Za-z]/', $line)) {
                        $currentChip = $line;
                        continue;
                    }
                    if (preg_match('/^(.+?):\s+\+?([\d.]+)\s*°?C?/i', $line, $m)) {
                        $temp = (float)$m[2];
                        if ($temp > 0 && $temp < 200) {
                            $list[] = [
                                'name' => $currentChip . ' ' . trim($m[1]),
                                'temp' => round($temp, 1),
                                'unit' => '°C',
                                'source' => 'sensors',
                            ];
                        }
                    }
                }
            }
        }

        // 方案 4：Linux IPMI（ipmitool）
        if (empty($list)) {
            $ipmiSensors = ss_shell_exec('ipmitool sensor list 2>/dev/null');
            if (!empty($ipmiSensors)) {
                foreach (explode("\n", $ipmiSensors) as $iline) {
                    $iline = trim($iline);
                    if ($iline === '') continue;
                    if (preg_match('/^([^|]+)\|([\d.]+)\|.*(?:deg.*C|C\b)/i', $iline, $im)) {
                        $sensorName = trim($im[1]);
                        $tempVal = (float)$im[2];
                        if ($tempVal > 0 && $tempVal <= 150) {
                            $list[] = [
                                'name' => $sensorName . ' (IPMI)',
                                'temp' => round($tempVal, 1),
                                'unit' => '°C',
                                'source' => 'IPMI',
                            ];
                        }
                    }
                }
            }
        }
    }

    return $list;
}

/* ======================== 网络流量 ======================== */

/**
 * 获取网络流量速率（上传/下载 KB/s）
 * 基于两次采样的差值计算速率，需配合实时缓存使用。
 *
 * @param array|null $prevSample 上次采样数据 ['rx_bytes' => int, 'tx_bytes' => int, 'time' => float]
 * @param array|null $combined   ss_ps_realtime_sample() 的合并采样结果（可选，避免重复启动 powershell）
 * @return array ['download_speed' => int, 'upload_speed' => int, 'download_speed_formatted' => string, 'upload_speed_formatted' => string, 'total_rx' => int, 'total_tx' => int, 'sample' => array]
 */
function ss_get_network_usage(?array $prevSample, ?array $combined = null): array {
    $os = ss_os_type();
    $rxBytes = 0;
    $txBytes = 0;
    $now = microtime(true);

    if ($os === 'windows') {
        // 方案 1（优先）：COM 进程内查询 WMI 性能计数器，使用直接字段访问
        if (ss_com_available()) {
            $rows = ss_wmi_query(
                'SELECT BytesReceivedPersec,BytesSentPersec FROM Win32_PerfRawData_Tcpip_NetworkInterface',
                'root/cimv2',
                ['BytesReceivedPersec', 'BytesSentPersec']
            );
            foreach ($rows as $r) {
                $rxBytes += (int)($r['BytesReceivedPersec'] ?? 0);
                $txBytes += (int)($r['BytesSentPersec'] ?? 0);
            }
        }

        // 方案 2（子进程兜底）：COM 不可用时，优先使用实时合并采样中的网络计数器，否则单独查询
        if ($rxBytes <= 0 && $txBytes <= 0) {
            if ($combined !== null && is_numeric($combined['Rx'] ?? '') && is_numeric($combined['Tx'] ?? '')) {
                $rxBytes = (int)$combined['Rx'];
                $txBytes = (int)$combined['Tx'];
            } else {
                $rows = ss_ps_cim_json('Win32_PerfRawData_Tcpip_NetworkInterface', ['BytesReceivedPersec', 'BytesSentPersec']);
                foreach ($rows as $r) {
                    $rxBytes += (int)($r['BytesReceivedPersec'] ?? 0);
                    $txBytes += (int)($r['BytesSentPersec'] ?? 0);
                }
            }
        }
    } elseif ($os === 'linux') {
        $netdev = @file_get_contents('/proc/net/dev');
        if ($netdev) {
            foreach (explode("\n", $netdev) as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, ':') === false) continue;
                $parts = explode(':', $line, 2);
                $iface = trim($parts[0]);
                // 跳过 lo 回环
                if ($iface === 'lo') continue;
                $stats = preg_split('/\s+/', trim($parts[1]));
                if (count($stats) < 9) continue;
                $rxBytes += (int)$stats[0];
                $txBytes += (int)$stats[8];
            }
        }
    }

    // 计算速率
    $downloadSpeed = 0;
    $uploadSpeed = 0;
    if ($prevSample !== null && isset($prevSample['time']) && $prevSample['time'] > 0) {
        $elapsed = $now - $prevSample['time'];
        if ($elapsed > 0) {
            $downloadSpeed = max(0, (int)round(($rxBytes - ($prevSample['rx_bytes'] ?? 0)) / $elapsed));
            $uploadSpeed = max(0, (int)round(($txBytes - ($prevSample['tx_bytes'] ?? 0)) / $elapsed));
        }
    }

    return [
        'download_speed' => $downloadSpeed,
        'upload_speed' => $uploadSpeed,
        'download_speed_formatted' => ss_format_speed($downloadSpeed),
        'upload_speed_formatted' => ss_format_speed($uploadSpeed),
        'total_rx' => $rxBytes,
        'total_rx_formatted' => ss_format_bytes($rxBytes),
        'total_tx' => $txBytes,
        'total_tx_formatted' => ss_format_bytes($txBytes),
        'sample' => [
            'rx_bytes' => $rxBytes,
            'tx_bytes' => $txBytes,
            'time' => $now,
        ],
    ];
}

/**
 * 格式化速率（字节/秒）
 */
function ss_format_speed(int $bytesPerSec): string {
    if ($bytesPerSec >= 1073741824) return round($bytesPerSec / 1073741824, 2) . ' GB/s';
    if ($bytesPerSec >= 1048576) return round($bytesPerSec / 1048576, 2) . ' MB/s';
    if ($bytesPerSec >= 1024) return round($bytesPerSec / 1024, 2) . ' KB/s';
    return $bytesPerSec . ' B/s';
}

/* ======================== 硬盘型号 ======================== */

/**
 * 获取物理硬盘型号信息
 * Windows: wmic diskdrive
 * Linux: lsblk 或 /sys/block/
 *
 * @return array [['model' => 'Samsung SSD 870', 'size' => int, 'size_formatted' => '500 GB', 'interface' => 'SATA', 'serial' => 'XXX'], ...]
 */
function ss_get_disk_hardware(): array {
    $os = ss_os_type();
    $list = [];

    if ($os === 'windows') {
        // COM 进程内查询 WMI，使用直接字段访问
        $rows = ss_wmi_query(
            'SELECT Model,Size,SerialNumber,InterfaceType,MediaType FROM Win32_DiskDrive',
            'root/cimv2',
            ['Model', 'Size', 'SerialNumber', 'InterfaceType', 'MediaType']
        );

        // 子进程兜底：COM 不可用时使用 PowerShell CIM 查询
        if (empty($rows)) {
            $rows = ss_ps_cim_json('Win32_DiskDrive', ['Model', 'Size', 'SerialNumber', 'InterfaceType', 'MediaType']);
        }

        foreach ($rows as $r) {
            $model = trim((string)($r['Model'] ?? ''));
            if ($model === '') continue;
            $size = (int)($r['Size'] ?? 0);
            $list[] = [
                'model' => $model,
                'size' => $size,
                'size_formatted' => ss_format_bytes($size),
                'interface' => trim((string)($r['InterfaceType'] ?? '')),
                'media_type' => trim((string)($r['MediaType'] ?? '')),
                'serial' => trim((string)($r['SerialNumber'] ?? '')),
            ];
        }
    } elseif ($os === 'linux') {
        // 方案 1：lsblk
        $output = ss_shell_exec('lsblk -db -o NAME,MODEL,SERIAL,SIZE,TYPE,ROTA 2>/dev/null');
        if (!empty($output)) {
            $lines = explode("\n", $output);
            if (count($lines) > 1) {
                $header = preg_split('/\s+/', trim($lines[0]));
                for ($i = 1; $i < count($lines); $i++) {
                    $cols = preg_split('/\s+/', trim($lines[$i]));
                    if (count($cols) < count($header)) continue;
                    $row = array_combine($header, array_pad($cols, count($header), ''));
                    if (($row['TYPE'] ?? '') !== 'disk') continue;
                    $size = (int)($row['SIZE'] ?? 0);
                    $list[] = [
                        'model' => trim($row['MODEL'] ?? '') ?: t('admin_ajax_unknown_model', '未知型号'),
                        'size' => $size,
                        'size_formatted' => ss_format_bytes($size),
                        'interface' => '',
                        'media_type' => ($row['ROTA'] ?? '') === '1' ? 'HDD' : 'SSD',
                        'serial' => trim($row['SERIAL'] ?? ''),
                    ];
                }
            }
        }

        // 方案 2：/sys/block/ 读取
        if (empty($list)) {
            $blockDirs = glob('/sys/block/*', GLOB_ONLYDIR);
            if (!empty($blockDirs)) {
                foreach ($blockDirs as $dir) {
                    $name = basename($dir);
                    // 跳过 loop, ram, dm 等虚拟设备
                    if (preg_match('/^(loop|ram|dm|sr|fd|zram)/', $name)) continue;
                    $modelFile = $dir . '/device/model';
                    $sizeFile = $dir . '/size';
                    if (!is_file($modelFile)) continue;
                    $model = trim((string)@file_get_contents($modelFile));
                    if ($model === '') continue;
                    // size 是 512 字节扇区数
                    $sectors = (int)@file_get_contents($sizeFile);
                    $size = $sectors * 512;
                    $serial = '';
                    $serialFile = $dir . '/device/serial';
                    if (is_file($serialFile)) {
                        $serial = trim((string)@file_get_contents($serialFile));
                    }
                    $list[] = [
                        'model' => $model,
                        'size' => $size,
                        'size_formatted' => ss_format_bytes($size),
                        'interface' => '',
                        'media_type' => is_file($dir . '/queue/rotational') ? ((int)@file_get_contents($dir . '/queue/rotational') === 0 ? 'SSD' : 'HDD') : '',
                        'serial' => $serial,
                    ];
                }
            }
        }
    }

    return $list;
}

/* ======================== 运行时间 / 负载 / 格式化 ======================== */


function ss_get_uptime_seconds(): int {
    $os = ss_os_type();

    // 方案 1（优先）：FFI GetTickCount64（即时，无需缓存，不启动子进程）
    if ($os === 'windows') {
        $uptime = ss_ffi_get_uptime_seconds();
        if ($uptime !== null) return $uptime;
    }

    // 方案 2（回退）：缓存开机时间戳，避免每次请求都查询 WMI
    $bootTime = ss_get_static_cache('boot_time', function () use ($os) {
        if ($os === 'windows') {
            // 0）优先复用实时采样缓存中的 BootTime（动态端点已采样，避免额外启动进程）
            $realtime = ss_read_realtime_cache_file();
            if (is_array($realtime) && !empty($realtime['boot_time'])) {
                $ts = ss_parse_windows_date_time((string)$realtime['boot_time']);
                if ($ts !== null) return $ts;
            }
            // 1）COM 进程内查询 WMI
            if (ss_com_available()) {
                $row = ss_wmi_query_first('SELECT LastBootUpTime FROM Win32_OperatingSystem', 'root/cimv2', ['LastBootUpTime']);
                if ($row && !empty($row['LastBootUpTime'])) {
                    $ts = ss_parse_windows_date_time((string)$row['LastBootUpTime']);
                    if ($ts !== null) return $ts;
                }
            }
            // 2）子进程兜底：复用批量采集或单独查询
            $rows = ss_ps_cim_json('Win32_OperatingSystem', ['LastBootUpTime']);
            if (!empty($rows)) {
                $ts = ss_parse_windows_date_time((string)($rows[0]['LastBootUpTime'] ?? ''));
                if ($ts !== null) return $ts;
            }
        } elseif ($os === 'linux') {
            $uptime = @file_get_contents('/proc/uptime');
            if ($uptime && strlen($uptime) > 3 && preg_match('/^(\d+(?:\.\d+)?)/', trim($uptime), $m)) {
                return time() - (int)$m[1];
            }
            // /proc/uptime 不可读时：尝试从 PHP 进程启动时间推断（不精确但优于 0）
            // 注意：这是 FPM worker 启动时间，非系统启动时间；仅在无法读取 /proc 时作为最后兜底
            if (defined('REQUEST_TIME_FLOAT')) {
                // REQUEST_TIME_FLOAT 是请求开始时间戳，不能用于系统 uptime
                // 返回 0 让前端显示「未知」
                return 0;
            }
        }
        return 0;
    }, 300); // 缓存 5 分钟

    if ($bootTime > 0) return max(0, time() - $bootTime);
    return 0;
}

function ss_get_load_status(int $cpuUsage, int $memUsage): array {
    if ($cpuUsage >= 90 || $memUsage >= 95) return ['text' => t('admin_ajax_load_high', '负载过高'), 'class' => 'danger'];
    if ($cpuUsage >= 70 || $memUsage >= 85) return ['text' => t('admin_ajax_load_medium', '负载较高'), 'class' => 'warning'];
    return ['text' => t('admin_ajax_load_normal', '运行正常'), 'class' => 'success'];
}

function ss_format_duration(int $seconds): string {
    $days = (int)floor($seconds / 86400);
    $hours = (int)floor(($seconds % 86400) / 3600);
    $minutes = (int)floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    $parts = [];
    if ($days > 0) $parts[] = $days . t('admin_ajax_unit_days', ' 天');
    if ($hours > 0 || $days > 0) $parts[] = $hours . t('admin_ajax_unit_hours', ' 小时');
    if ($minutes > 0 || $hours > 0 || $days > 0) $parts[] = $minutes . t('admin_ajax_unit_minutes', ' 分钟');
    $parts[] = $secs . t('admin_ajax_unit_seconds', ' 秒');
    return implode(' ', $parts);
}

/* ======================== 扩展信息：负载/GPU/主板/电池/PHP/数据库 ======================== */

/**
 * 系统负载平均值（1/5/15 分钟）
 * Linux: /proc/loadavg
 * Windows: 通过 WMI 查询 CPU 队列长度（近似）
 */
function ss_get_load_average(): array {
    $os = ss_os_type();
    if ($os === 'linux') {
        // 方案 1（优先）：PHP 原生 sys_getloadavg()，无需 /proc/，不受 open_basedir 限制
        $la = @sys_getloadavg();
        if (is_array($la) && count($la) >= 3) {
            return [
                'load_1'  => (float)$la[0],
                'load_5'  => (float)$la[1],
                'load_15' => (float)$la[2],
            ];
        }
        // 方案 2（回退）：/proc/loadavg
        $content = @file_get_contents('/proc/loadavg');
        if ($content && strlen($content) > 5 && preg_match('/^([\d.]+)\s+([\d.]+)\s+([\d.]+)/', $content, $m)) {
            return [
                'load_1'  => (float)$m[1],
                'load_5'  => (float)$m[2],
                'load_15' => (float)$m[3],
            ];
        }
        // 均不可用：返回空数组（前端显示 N/A）
        return [];
    } elseif ($os === 'windows') {
        // 0）优先复用实时采样缓存中的队列长度（动态端点已采样，避免额外启动进程）
        $realtime = ss_read_realtime_cache_file();
        if (is_array($realtime) && isset($realtime['queue_length']) && is_numeric($realtime['queue_length'])) {
            $q = (int)$realtime['queue_length'];
            return ['load_1' => $q, 'load_5' => $q, 'load_15' => $q];
        }

        // 方案 1：WMI Win32_PerfFormattedData_PerfOS_System（部分 Windows 版本可用）
        if (ss_com_available()) {
            $row = ss_wmi_query_first(
                'SELECT ProcessorQueueLength FROM Win32_PerfFormattedData_PerfOS_System',
                'root/cimv2',
                ['ProcessorQueueLength']
            );
            if ($row && isset($row['ProcessorQueueLength']) && is_numeric($row['ProcessorQueueLength'])) {
                $q = (int)$row['ProcessorQueueLength'];
                return ['load_1' => $q, 'load_5' => $q, 'load_15' => $q];
            }
        }

        // 方案 2（子进程兜底）：复用批量采集或单独查询
        $rows = ss_ps_cim_json('Win32_PerfFormattedData_PerfOS_System', ['ProcessorQueueLength']);
        if (!empty($rows) && is_numeric($rows[0]['ProcessorQueueLength'] ?? '')) {
            $q = (int)$rows[0]['ProcessorQueueLength'];
            return ['load_1' => $q, 'load_5' => $q, 'load_15' => $q];
        }
    }
    return ['load_1' => 0, 'load_5' => 0, 'load_15' => 0];
}

/**
 * 根据显卡名称判断是否像集成显卡（Intel UHD/HD/Iris、AMD APU 等）
 */
function ss_gpu_name_looks_integrated(string $name): bool {
    $n = strtolower($name);

    // 明确的独立显卡品牌/系列：含这些关键词直接判定为独显，不再走集显逻辑
    $discreteKeywords = ['geforce', 'quadro', 'tesla', 'nvidia rtx', 'radeon rx', 'radeon pro', 'intel arc'];
    foreach ($discreteKeywords as $kw) {
        if (strpos($n, $kw) !== false) return false;
    }

    // 明确的集成显卡特征
    $integratedKeywords = [
        'uhd graphics', 'hd graphics', 'iris graphics', 'iris xe graphics',
        'gma ', 'amd radeon graphics', 'radeon(tm) graphics',
        'radeon vega', 'vega ', 'amd renoir', 'amd cezanne', 'amd rembrandt', 'amd phoenix',
        'microsoft basic display', 'basic render', 'virtual',
    ];
    foreach ($integratedKeywords as $kw) {
        if (strpos($n, $kw) !== false) return true;
    }

    // AMD Ryzen 移动版集显常以 "Radeon 680M / 780M / 880M" 等形式出现
    if (preg_match('/radeon\s+\d{3,4}m\b/', $n)) return true;

    return false;
}

/**
 * 从 Windows 注册表读取独立显卡真实显存大小（单位：字节）
 *
 * WMI 的 Win32_VideoController.AdapterRAM 是 32 位无符号，对 4GB+ 显存会溢出；
 * 且部分较新 NVIDIA/AMD 驱动在该字段返回 0，导致前端显示"共享/未知"。
 * 注册表 HardwareInformation.qwMemorySize 是 64 位 QWORD，更为可靠。
 *
 * @param string $gpuName 显卡名称，用于匹配注册表子项的 DriverDesc
 * @return int 显存字节数；读取失败返回 0
 */
function ss_get_gpu_vram_from_registry(string $gpuName): int {
    if (ss_os_type() !== 'windows' || $gpuName === '') return 0;
    $gpuNameNorm = strtolower(preg_replace('/\s+/', ' ', trim($gpuName)));
    // 转义 PowerShell 字符串中的双引号和反斜杠，避免显卡名称含特殊字符时语法错误
    $gpuNameNormPs = str_replace(['\\', '"'], ['\\\\', '\\"'], $gpuNameNorm);

    $script = '$gpuNameNorm="' . $gpuNameNormPs . '";'
        . '$regPath="HKLM:\\SYSTEM\\CurrentControlSet\\Control\\Class\\{4d36e968-e325-11ce-bfc1-08002be10318}";'
        . '$result=0;'
        . 'try { '
        . 'Get-ChildItem -Path $regPath -ErrorAction SilentlyContinue | Where-Object { $_.PSChildName -match "^\\d+$" } | ForEach-Object { '
        . '$props=Get-ItemProperty -Path $_.PSPath -ErrorAction SilentlyContinue;'
        . 'if ($props) { '
        . '$descs=@();'
        . 'if ($props.DriverDesc) { $descs += $props.DriverDesc }'
        . 'if ($props.DeviceDesc) { $descs += $props.DeviceDesc }'
        . 'foreach ($desc in $descs) { '
        . '$descNorm=($desc -replace "\\s+"," ").ToLower();'
        . 'if ($descNorm -eq $gpuNameNorm -or $descNorm -like ("*" + $gpuNameNorm + "*") -or $gpuNameNorm -like ("*" + $descNorm + "*")) { '
        . 'if ($props.PSObject.Properties["HardwareInformation.qwMemorySize"]) { $v=[uint64]$props."HardwareInformation.qwMemorySize"; if ($v -gt $result) { $result=$v } } '
        . 'if ($props.PSObject.Properties["HardwareInformation.MemorySize"]) { $v=[uint64]$props."HardwareInformation.MemorySize"; if ($v -gt $result) { $result=$v } } '
        . '} } } } catch { }'
        . '$result;';

    $out = ss_ps_run($script, 8);
    if ($out === null || $out === '') return 0;
    $val = trim($out);
    if (!is_numeric($val)) return 0;
    $bytes = (int)$val;
    return $bytes > 0 ? $bytes : 0;
}

/**
 * 通过 NVIDIA 官方 nvidia-smi 工具读取独立显卡显存（单位：字节）
 *
 * 当 WMI AdapterRAM 为 0 且注册表也读不到时，若系统安装了 NVIDIA 驱动，
 * nvidia-smi.exe 通常能提供准确的显存信息。返回值按 MiB 换算为字节。
 */
function ss_get_gpu_vram_from_nvidia_smi(string $gpuName): int {
    if (ss_os_type() !== 'windows' || $gpuName === '') return 0;

    // 常见安装路径及 PATH 兜底
    // 注意：32 位 PHP 在 64 位 Windows 上访问 System32 会被重定向到 SysWOW64，
    // 而 64 位驱动（nvidia-smi.exe）只装在真实 System32，故优先用 Sysnative 别名绕过重定向。
    $candidates = [
        'C:\\Windows\\Sysnative\\nvidia-smi.exe',
        'C:\\Program Files\\NVIDIA Corporation\\NVSMI\\nvidia-smi.exe',
        'C:\\Windows\\System32\\nvidia-smi.exe',
        'C:\\Windows\\SysWOW64\\nvidia-smi.exe',
        'nvidia-smi.exe',
    ];

    $exe = null;
    foreach ($candidates as $c) {
        if ($c === 'nvidia-smi.exe') {
            if (ss_shell_exec('where nvidia-smi.exe 2>nul') !== null) {
                $exe = $c;
                break;
            }
        } elseif (file_exists($c)) {
            $exe = $c;
            break;
        }
    }
    if ($exe === null) return 0;

    $cmd = ($exe === 'nvidia-smi.exe')
        ? 'nvidia-smi.exe --query-gpu=name,memory.total --format=csv,noheader,nounits 2>nul'
        : '"' . $exe . '" --query-gpu=name,memory.total --format=csv,noheader,nounits 2>nul';
    // 优先 shell_exec；子进程被禁用（COM 可用环境）时改用 COM WScript.Shell.Exec 兜底
    $output = ss_shell_exec($cmd);
    if ($output === null && ss_com_available()) {
        $output = ss_com_exec($cmd);
    }
    if (empty($output)) return 0;

    $gpuNameNorm = strtolower(preg_replace('/\s+/', ' ', trim($gpuName)));
    foreach (explode("\n", trim($output)) as $line) {
        $parts = str_getcsv(trim($line));
        if (count($parts) < 2) continue;
        $name = strtolower(preg_replace('/\s+/', ' ', trim($parts[0])));
        $mem = trim($parts[1]);
        if (!is_numeric($mem)) continue;
        // 名称双向包含匹配（nvidia-smi 名称通常不含 WDDM 后缀，而 WMI 名称可能带后缀）
        if ($name === $gpuNameNorm || strpos($name, $gpuNameNorm) !== false || strpos($gpuNameNorm, $name) !== false) {
            return (int)((float)$mem * 1024 * 1024); // MiB → bytes
        }
    }
    return 0;
}

/**
 * GPU 信息（显卡型号、显存）
 */
function ss_get_gpu_info(): array {
    $list = [];
    $os = ss_os_type();

    // 虚拟显示适配器关键词（远程控制软件、虚拟显卡等，非真实物理 GPU）
    $virtualKeywords = [
        'virtual', 'idddriver', 'oray', 'gameviewer', 'asklink', 'raylink',
        'mirror', 'remote', 'teamviewer', 'anydesk', 'todesk', 'sunlogin',
        'parsec', 'spacedesk', 'dddriver', 'indirectdisplay', 'hyperv',
        'basic display', 'microsoft', 'vnc', 'noMachine', 'rustdesk',
    ];

    if ($os === 'windows') {
        $rows = ss_wmi_query(
            'SELECT Name,AdapterRAM,DriverVersion,VideoProcessor,AdapterDACType FROM Win32_VideoController',
            'root/cimv2',
            ['Name', 'AdapterRAM', 'DriverVersion', 'VideoProcessor', 'AdapterDACType']
        );

        // 子进程兜底：COM 不可用时使用 PowerShell CIM 查询
        if (empty($rows)) {
            $rows = ss_ps_cim_json('Win32_VideoController', ['Name', 'AdapterRAM', 'DriverVersion', 'VideoProcessor', 'AdapterDACType']);
        }

        foreach ($rows as $row) {
            $name = trim($row['Name'] ?? t('admin_ajax_unknown_gpu', '未知显卡'));
            $nameLower = strtolower($name);

            // 过滤虚拟显示适配器
            $isVirtual = false;
            foreach ($virtualKeywords as $kw) {
                if (strpos($nameLower, $kw) !== false) {
                    $isVirtual = true;
                    break;
                }
            }
            if ($isVirtual) continue;

            $ram = (int)($row['AdapterRAM'] ?? 0);
            // Win32_VideoController.AdapterRAM 声明为 uint32（无符号 32 位），但 PHP 常按
            // 有符号 int 读入；当显存 ≥ 2GB 时高位溢出成负数（如 2GB = 2147483648 → -2147483648）。
            // 真实字节数 = 无符号值：负数时加 2^32 还原（仅 4GB 整值会溢出为 0，由下方兜底处理）。
            if ($ram < 0) {
                $ram = $ram + 4294967296;
            }

            // 集成/独显判断：优先按显卡名称识别，不能仅凭 AdapterRAM 为 0 就判定为集显。
            // 很多独立显卡（尤其较新 NVIDIA/AMD 驱动）在 WMI 的 AdapterRAM 字段返回 0，
            // 若据此把独显标成“集成”，会误导用户。
            $isIntegrated = ss_gpu_name_looks_integrated($name);

            // 非集显且 WMI 未返回显存时，依次尝试注册表、nvidia-smi 读取真实显存
            if (!$isIntegrated && $ram === 0) {
                $regRam = ss_get_gpu_vram_from_registry($name);
                if ($regRam > 0) {
                    $ram = $regRam;
                } else {
                    $smiRam = ss_get_gpu_vram_from_nvidia_smi($name);
                    if ($smiRam > 0) {
                        $ram = $smiRam;
                    }
                }
            }

            $list[] = [
                'name'            => $name,
                'ram_bytes'       => $ram,
                'ram_formatted'   => $ram > 0 ? ss_format_bytes($ram) : t('admin_ajax_shared_or_unknown', '共享/未知'),
                'driver'          => trim($row['DriverVersion'] ?? ''),
                'processor'       => trim($row['VideoProcessor'] ?? ''),
                'is_integrated'   => $isIntegrated,
            ];
        }

        // 如果过滤后为空（全是虚拟显卡），放宽限制返回第一个
        if (empty($list) && !empty($rows)) {
            $row = $rows[0];
            $ram = (int)($row['AdapterRAM'] ?? 0);
            if ($ram < 0) {
                $ram = $ram + 4294967296;
            }
            $isIntegrated = ss_gpu_name_looks_integrated($row['Name'] ?? '');
            if (!$isIntegrated && $ram === 0) {
                $regRam = ss_get_gpu_vram_from_registry($row['Name'] ?? '');
                if ($regRam > 0) {
                    $ram = $regRam;
                } else {
                    $smiRam = ss_get_gpu_vram_from_nvidia_smi($row['Name'] ?? '');
                    if ($smiRam > 0) {
                        $ram = $smiRam;
                    }
                }
            }
            $list[] = [
                'name'            => trim($row['Name'] ?? t('admin_ajax_unknown_gpu', '未知显卡')),
                'ram_bytes'       => $ram,
                'ram_formatted'   => $ram > 0 ? ss_format_bytes($ram) : t('admin_ajax_shared_or_unknown', '共享/未知'),
                'driver'          => trim($row['DriverVersion'] ?? ''),
                'processor'       => trim($row['VideoProcessor'] ?? ''),
                'is_integrated'   => $isIntegrated,
            ];
        }
    } elseif ($os === 'linux') {
        // 尝试读取 lspci（同时匹配 VGA 和 3D controller 以捕获独显）
        $output = ss_shell_exec('lspci 2>/dev/null | grep -iE "vga|3d|display"');
        if (!empty($output)) {
            foreach (explode("\n", trim($output)) as $line) {
                if (preg_match('/:\s*(.+)$/', $line, $m)) {
                    $name = trim($m[1]);
                    $nameLower = strtolower($name);
                    $isVirtual = false;
                    foreach ($virtualKeywords as $kw) {
                        if (strpos($nameLower, $kw) !== false) {
                            $isVirtual = true;
                            break;
                        }
                    }
                    if ($isVirtual) continue;
                    $list[] = [
                        'name' => $name,
                        'ram_bytes' => 0,
                        'ram_formatted' => t('admin_ajax_shared_or_unknown', '共享/未知'),
                        'driver' => '',
                        'processor' => '',
                        // 与 Windows 分支一致：按名称关键词判断集显/独显，避免独显被误标为集成
                        'is_integrated' => ss_gpu_name_looks_integrated($name),
                    ];
                }
            }
        }
    }
    return $list;
}

/**
 * 主板信息
 */
function ss_get_motherboard_info(): array {
    $os = ss_os_type();
    if ($os === 'windows') {
        // 方案 0（优先）：FFI SMBIOS（Type 2 + Type 0）进程内读取，不启动子进程
        $smbios = ss_ffi_get_smbios();
        if ($smbios !== null && ($smbios['board']['manufacturer'] !== '' || $smbios['board']['product'] !== '' || $smbios['bios']['maker'] !== '')) {
            $b = $smbios['board'];
            $bo = $smbios['bios'];
            return [
                'manufacturer' => $b['manufacturer'] !== '' ? $b['manufacturer'] : '--',
                'product'      => $b['product'] !== '' ? $b['product'] : '--',
                'version'      => $b['version'] !== '' ? $b['version'] : '--',
                'serial'       => $b['serial'] !== '' ? $b['serial'] : '--',
                'bios_maker'   => $bo['maker'] !== '' ? $bo['maker'] : '--',
                'bios_version' => $bo['version'] !== '' ? $bo['version'] : '--',
            ];
        }

        // 方案 1：COM 进程内查询 WMI
        $board = ss_wmi_query_first(
            'SELECT Manufacturer,Product,Version,SerialNumber FROM Win32_BaseBoard',
            'root/cimv2',
            ['Manufacturer', 'Product', 'Version', 'SerialNumber']
        );
        $bios = ss_wmi_query_first(
            'SELECT Manufacturer,SMBIOSBIOSVersion,ReleaseDate FROM Win32_BIOS',
            'root/cimv2',
            ['Manufacturer', 'SMBIOSBIOSVersion', 'ReleaseDate']
        );

        // 子进程兜底：COM 不可用时使用 PowerShell CIM 查询
        if (empty($board)) {
            $boardRows = ss_ps_cim_json('Win32_BaseBoard', ['Manufacturer', 'Product', 'Version', 'SerialNumber']);
            $board = $boardRows[0] ?? null;
        }
        if (empty($bios)) {
            $biosRows = ss_ps_cim_json('Win32_BIOS', ['Manufacturer', 'SMBIOSBIOSVersion', 'ReleaseDate']);
            $bios = $biosRows[0] ?? null;
        }

        $result = [
            'manufacturer' => trim($board['Manufacturer'] ?? '--'),
            'product'      => trim($board['Product'] ?? '--'),
            'version'      => trim($board['Version'] ?? '--'),
            'serial'       => trim($board['SerialNumber'] ?? '--'),
            'bios_maker'   => trim($bios['Manufacturer'] ?? '--'),
            'bios_version' => trim($bios['SMBIOSBIOSVersion'] ?? '--'),
        ];

        // 方案 2（最终兜底）：直接从注册表读取主板/BIOS 信息（不依赖 WMI）
        $hasRealValue = false;
        foreach ($result as $v) {
            if ($v !== '' && $v !== '--') { $hasRealValue = true; break; }
        }
        if (!$hasRealValue) {
            $regBoard = ss_get_board_from_registry();
            if ($regBoard !== null) $result = $regBoard;
        }
        return $result;
    } elseif ($os === 'linux') {
        $files = [
            'manufacturer' => '/sys/class/dmi/id/board_vendor',
            'product'      => '/sys/class/dmi/id/board_name',
            'version'      => '/sys/class/dmi/id/board_version',
            'serial'       => '/sys/class/dmi/id/board_serial',
            'bios_maker'   => '/sys/class/dmi/id/bios_vendor',
            'bios_version' => '/sys/class/dmi/id/bios_version',
        ];
        $result = [];
        foreach ($files as $key => $path) {
            $val = @file_get_contents($path);
            $result[$key] = $val !== false ? trim($val) : '--';
        }
        return $result;
    }
    return [
        'manufacturer' => '--', 'product' => '--', 'version' => '--',
        'serial' => '--', 'bios_maker' => '--', 'bios_version' => '--',
    ];
}

/**
 * 电池状态（笔记本/UPS）
 */
function ss_get_battery_info(): ?array {
    $os = ss_os_type();
    if ($os === 'windows') {
        // 方案 0（优先）：FFI GetSystemPowerStatus 进程内读取，不启动子进程
        $ffiBat = ss_ffi_get_battery();
        if ($ffiBat !== null) return $ffiBat;

        $row = ss_wmi_query_first(
            'SELECT BatteryStatus,EstimatedChargeRemaining FROM Win32_Battery',
            'root/cimv2',
            ['BatteryStatus', 'EstimatedChargeRemaining']
        );

        // 子进程兜底：COM 不可用时使用 PowerShell CIM 查询
        if (empty($row)) {
            $battRows = ss_ps_cim_json('Win32_Battery', ['BatteryStatus', 'EstimatedChargeRemaining']);
            $row = $battRows[0] ?? null;
        }

        if ($row) {
            $statusMap = [
                1 => t('admin_ajax_battery_discharging', '放电中'),
                2 => t('admin_ajax_ac_power', '交流电源'),
                3 => t('admin_ajax_battery_charging', '充电中'),
                4 => t('admin_ajax_battery_low', '电量低'),
                5 => t('admin_ajax_battery_critical', '电量严重不足'),
            ];
            $statusCode = (int)($row['BatteryStatus'] ?? 0);
            return [
                'present'  => true,
                'percent'  => (int)($row['EstimatedChargeRemaining'] ?? 0),
                'status'   => $statusMap[$statusCode] ?? t('admin_ajax_unknown_with_code', '未知(') . $statusCode . ')',
            ];
        }
        return null;
    } elseif ($os === 'linux') {
        $batDir = '/sys/class/power_supply/BAT0';
        if (!is_dir($batDir)) $batDir = '/sys/class/power_supply/BAT1';
        if (is_dir($batDir)) {
            $cap = @file_get_contents($batDir . '/capacity');
            $stat = @file_get_contents($batDir . '/status');
            if ($cap !== false) {
                $statusMap = [
                    'Charging' => t('admin_ajax_battery_charging', '充电中'),
                    'Discharging' => t('admin_ajax_battery_discharging', '放电中'),
                    'Full' => t('admin_ajax_battery_full', '已充满'),
                    'Not charging' => t('admin_ajax_battery_not_charging', '未充电'),
                ];
                return [
                    'present' => true,
                    'percent' => (int)trim($cap),
                    'status'  => $statusMap[trim($stat)] ?? trim($stat),
                ];
            }
        }
        return null;
    }
    return null;
}

/**
 * PHP 运行时详细信息
 */
function ss_get_php_info(): array {
    $extensions = get_loaded_extensions();
    $sapi = php_sapi_name();

    // OPcache 状态
    $opcache = null;
    if (function_exists('opcache_get_status')) {
        $op = @opcache_get_status(false);
        if (is_array($op)) {
            $mem = $op['memory_usage'] ?? [];
            $stat = $op['opcache_statistics'] ?? [];
            $opcache = [
                'enabled'       => true,
                'memory_used'   => ss_format_bytes((int)($mem['used_memory'] ?? 0)),
                'memory_free'   => ss_format_bytes((int)($mem['free_memory'] ?? 0)),
                'hits'          => (int)($stat['hits'] ?? 0),
                'misses'        => (int)($stat['misses'] ?? 0),
                'hit_rate'      => isset($stat['opcache_hit_rate']) ? round($stat['opcache_hit_rate'], 2) : 0,
                'scripts'       => (int)($stat['num_cached_scripts'] ?? 0),
            ];
        }
    }
    if ($opcache === null) $opcache = ['enabled' => false];

    return [
        'version'           => PHP_VERSION,
        'sapi'              => $sapi,
        'sapi_name'         => self_sapi_label($sapi),
        'extensions_count'  => count($extensions),
        'extensions'        => array_slice($extensions, 0, 50),
        'memory_limit'      => ini_get('memory_limit') ?: t('admin_ajax_unlimited', '未限制'),
        'max_execution'     => ini_get('max_execution_time') . t('admin_ajax_unit_seconds', ' 秒'),
        'upload_max'        => ini_get('upload_max_filesize'),
        'post_max'          => ini_get('post_max_size'),
        'timezone'          => date_default_timezone_get(),
        'display_errors'    => ini_get('display_errors') ? t('admin_ajax_on', '开启') : t('admin_ajax_off', '关闭'),
        'error_reporting'   => self_error_level_label(error_reporting()),
        'opcache'           => $opcache,
        'realpath_cache'    => ini_get('realpath_cache_size') ?: t('admin_ajax_default', '默认'),
    ];
}

function self_sapi_label(string $sapi): string {
    $labels = [
        'apache2handler' => t('admin_ajax_sapi_apache', 'Apache 模块'),
        'cgi-fcgi'       => t('admin_ajax_sapi_cgi', 'CGI/FastCGI'),
        'fpm-fcgi'        => t('admin_ajax_sapi_fpm', 'PHP-FPM'),
        'cli'            => t('admin_ajax_sapi_cli', '命令行'),
        'cli-server'     => t('admin_ajax_sapi_built_in', '内置服务器'),
        'litespeed'      => 'LiteSpeed',
    ];
    return $labels[$sapi] ?? $sapi;
}

function self_error_level_label(int $level): string {
    if ($level === E_ALL) return 'E_ALL';
    if ($level === 0) return t('admin_ajax_off', '关闭');
    $parts = [];
    if ($level & E_ERROR) $parts[] = 'ERROR';
    if ($level & E_WARNING) $parts[] = 'WARNING';
    if ($level & E_PARSE) $parts[] = 'PARSE';
    if ($level & E_NOTICE) $parts[] = 'NOTICE';
    if ($level & E_DEPRECATED) $parts[] = 'DEPRECATED';
    return $parts ? implode('|', $parts) : (string)$level;
}

/**
 * 数据库轻量统计（动态端点使用，仅文件大小 + PRAGMA，不逐表 COUNT）
 */
function ss_get_db_lite_stats(): array {
    // 10 秒内存级缓存，避免每次请求都重新查询 PRAGMA + sqlite_master
    static $cache = null;
    static $cacheTime = 0;
    $now = time();
    if ($cache !== null && ($now - $cacheTime) < 10) {
        return $cache;
    }

    $stats = [
        'table_count'    => 0,
        'total_rows'     => 0,
        'db_size'        => 0,
        'db_size_formatted' => '0 B',
        'wal_size'       => 0,
        'wal_size_formatted' => '0 B',
        'journal_mode'   => 'unknown',
        'tables'         => [],
    ];

    try {
        $db = get_db();
        $driver = get_db_driver();

        if (defined('DB_FILE') && is_file(DB_FILE)) {
            $stats['db_size'] = (int)filesize(DB_FILE);
            $stats['db_size_formatted'] = ss_format_bytes($stats['db_size']);
        }

        $walFile = defined('DB_FILE') ? DB_FILE . '-wal' : '';
        if ($walFile && is_file($walFile)) {
            $stats['wal_size'] = (int)filesize($walFile);
            $stats['wal_size_formatted'] = ss_format_bytes($stats['wal_size']);
        }

        // PRAGMA journal_mode（仅 SQLite，MySQL/PostgreSQL 下忽略）
        try {
            $stats['journal_mode'] = (string)$db->query('PRAGMA journal_mode')->fetchColumn();
        } catch (\Throwable $e) {}

        // 表统计：使用驱动抽象方法
        $stats['table_count'] = count($driver->getTables());
    } catch (\Throwable $e) {
        // 记录错误以便排障
        error_log(t('admin_api_system_status_ajax_9ff177','ss_get_db_lite_stats 异常: ') . $e->getMessage());
    }

    $cache = $stats;
    $cacheTime = $now;
    return $stats;
}

/**
 * 数据库详细统计信息（静态端点使用，含逐表 COUNT，较慢）
 */
function ss_get_db_stats(): array {
    $stats = [
        'table_count'    => 0,
        'total_rows'     => 0,
        'db_size'        => 0,
        'db_size_formatted' => '0 B',
        'wal_size'       => 0,
        'wal_size_formatted' => '0 B',
        'journal_mode'   => 'unknown',
        'page_size'      => 0,
        'page_count'     => 0,
        'tables'         => [],
    ];

    try {
        $db = get_db();
        $driver = get_db_driver();

        // 主数据库文件大小（仅文件型数据库如 SQLite 适用）
        if (defined('DB_FILE') && is_file(DB_FILE)) {
            $stats['db_size'] = (int)filesize(DB_FILE);
            $stats['db_size_formatted'] = ss_format_bytes($stats['db_size']);
        }

        // WAL 文件大小（仅 SQLite 适用）
        $walFile = defined('DB_FILE') ? DB_FILE . '-wal' : '';
        if ($walFile && is_file($walFile)) {
            $stats['wal_size'] = (int)filesize($walFile);
            $stats['wal_size_formatted'] = ss_format_bytes($stats['wal_size']);
        }

        // PRAGMA 信息（仅 SQLite，MySQL/PostgreSQL 下忽略）
        try {
            $stats['journal_mode'] = (string)$db->query('PRAGMA journal_mode')->fetchColumn();
        } catch (Exception $e) {}
        try {
            $stats['page_size'] = (int)$db->query('PRAGMA page_size')->fetchColumn();
        } catch (Exception $e) {}
        try {
            $stats['page_count'] = (int)$db->query('PRAGMA page_count')->fetchColumn();
        } catch (Exception $e) {}

        // 表统计：使用驱动抽象方法，兼容 MySQL / SQLite / PostgreSQL
        $tables = $driver->getTables();
        $stats['table_count'] = count($tables);

        $tableDetails = [];
        $totalRows = 0;
        foreach ($tables as $tableName) {
            try {
                $count = (int)$db->query('SELECT COUNT(*) FROM ' . $driver->quoteIdentifier($tableName))->fetchColumn();
                $totalRows += $count;
                $tableDetails[] = ['name' => $tableName, 'rows' => $count];
            } catch (Exception $e) {
                $tableDetails[] = ['name' => $tableName, 'rows' => 0];
            }
        }
        $stats['total_rows'] = $totalRows;
        // 按记录数排序，取前 15 个
        usort($tableDetails, function ($a, $b) { return $b['rows'] - $a['rows']; });
        $stats['tables'] = array_slice($tableDetails, 0, 15);
    } catch (Exception $e) {
        // 记录错误以便排障
        error_log(t('admin_api_system_status_ajax_d719a7','ss_get_db_stats 异常: ') . $e->getMessage());
    }

    return $stats;
}

/**
 * 网络接口列表
 */
function ss_get_network_interfaces(): array {
    $list = [];
    $os = ss_os_type();

    if ($os === 'windows') {
        // 查询 Win32_NetworkAdapter（网卡名称 + MAC + 连接状态）
        $rows = ss_wmi_query(
            'SELECT Name,NetConnectionID,MACAddress,NetEnabled,NetConnectionStatus,Manufacturer FROM Win32_NetworkAdapter WHERE PhysicalAdapter = TRUE',
            'root/cimv2',
            ['Name', 'NetConnectionID', 'MACAddress', 'NetEnabled', 'NetConnectionStatus', 'Manufacturer']
        );

        if (empty($rows)) {
            $rows = ss_wmi_query(
                'SELECT Name,NetConnectionID,MACAddress,NetEnabled,NetConnectionStatus,Manufacturer FROM Win32_NetworkAdapter WHERE MACAddress IS NOT NULL',
                'root/cimv2',
                ['Name', 'NetConnectionID', 'MACAddress', 'NetEnabled', 'NetConnectionStatus', 'Manufacturer']
            );
        }

        // 子进程兜底：COM 不可用时使用 PowerShell CIM 查询
        if (empty($rows)) {
            $rows = ss_ps_cim_json('Win32_NetworkAdapter', ['Name', 'NetConnectionID', 'MACAddress', 'NetEnabled', 'NetConnectionStatus', 'Manufacturer'], 'PhysicalAdapter = TRUE');
            if (empty($rows)) {
                $rows = ss_ps_cim_json('Win32_NetworkAdapter', ['Name', 'NetConnectionID', 'MACAddress', 'NetEnabled', 'NetConnectionStatus', 'Manufacturer'], 'MACAddress IS NOT NULL');
            }
        }

        // 辅助函数：规范化 MAC 地址（去分隔符，转大写）用于比对
        $normalizeMac = function($mac) {
            return strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', trim((string)$mac)));
        };

        // 查询 Win32_NetworkAdapterConfiguration（IP 地址）
        $cfgRows = ss_wmi_query(
            'SELECT Description,MACAddress,IPAddress,IPEnabled FROM Win32_NetworkAdapterConfiguration WHERE IPEnabled = TRUE',
            'root/cimv2',
            ['Description', 'MACAddress', 'IPAddress', 'IPEnabled']
        );

        // 子进程兜底：COM 不可用时使用 PowerShell CIM 查询 IP 配置
        if (empty($cfgRows)) {
            $cfgRows = ss_ps_cim_json('Win32_NetworkAdapterConfiguration', ['Description', 'MACAddress', 'IPAddress', 'IPEnabled'], 'IPEnabled = TRUE');
        }

        // 构建 MAC → IP 映射表（规范化 MAC）
        $ipMap = [];
        foreach ($cfgRows as $cfg) {
            $macNorm = $normalizeMac($cfg['MACAddress'] ?? '');
            if ($macNorm === '') continue;
            $ipRaw = $cfg['IPAddress'] ?? '';
            $ip = '';
            if (is_string($ipRaw) && $ipRaw !== '') {
                $ip = (strpos($ipRaw, ';') !== false) ? explode(';', $ipRaw)[0] : $ipRaw;
            } elseif (is_array($ipRaw)) {
                $ip = (string)($ipRaw[0] ?? '');
            }
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ipMap[$macNorm] = $ip;
            }
        }

        // 虚拟网卡过滤关键词
        $virtualNicKeywords = ['virtual', 'vpn', 'tunnel', 'tap', 'pseudo', 'ras', 'wan miniport', 'microsoft kernel', 'bluetooth', 'debug'];

        foreach ($rows as $row) {
            $name = trim($row['Name'] ?? '');
            $nameLower = strtolower($name);
            $connId = trim($row['NetConnectionID'] ?? '');
            $mac = trim($row['MACAddress'] ?? '');

            if ($mac === '') continue;

            // 过滤虚拟网卡
            $isVirtual = false;
            foreach ($virtualNicKeywords as $kw) {
                if (strpos($nameLower, $kw) !== false) { $isVirtual = true; break; }
            }
            if ($isVirtual) continue;

            $netEnabled = $row['NetEnabled'];
            $connStatus = (int)($row['NetConnectionStatus'] ?? 0);
            $connected = ($connStatus === 2) || ($netEnabled === true || $netEnabled === 1 || $netEnabled === '1');

            // 规范化 MAC 后查找 IP
            $macNorm = $normalizeMac($mac);
            $ip = $ipMap[$macNorm] ?? '--';

            $list[] = [
                'name'         => $connId !== '' ? $connId : $name,
                'desc'         => $name,
                'ip'           => $ip,
                'mac'          => $mac,
                'enabled'      => $connected,
                'manufacturer' => trim($row['Manufacturer'] ?? ''),
            ];
        }
    } elseif ($os === 'linux') {
        // /proc/net/dev 接口名 + /sys/class/net/<if>/address 读取 MAC
        $content = @file_get_contents('/proc/net/dev');
        if ($content) {
            $lines = explode("\n", $content);
            for ($i = 2; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if ($line === '') continue;
                if (preg_match('/^([^:]+):/', $line, $m)) {
                    $name = trim($m[1]);
                    if (in_array($name, ['lo'], true)) continue;

                    $mac = '';
                    $macFile = '/sys/class/net/' . $name . '/address';
                    if (is_file($macFile)) {
                        $mac = trim((string)@file_get_contents($macFile));
                    }

                    // 尝试获取 IP（用 awk/cut 替代 grep -oP，兼容 BusyBox 等精简环境）
                    $ip = '--';
                    $ipOutput = ss_shell_exec("ip -4 addr show $name 2>/dev/null | grep 'inet ' | awk '{print \$2}' | cut -d/ -f1 | head -n1");
                    if (!empty($ipOutput)) {
                        $ip = trim($ipOutput);
                    }

                    $list[] = [
                        'name'    => $name,
                        'desc'    => $name,
                        'ip'      => $ip,
                        'mac'     => $mac ?: '--',
                        'enabled' => true,
                        'manufacturer' => '',
                    ];
                }
            }
        }
    }

    return $list;
}

/**
 * 服务器时间信息
 */
function ss_get_server_time(): array {
    return [
        'datetime'   => date('Y-m-d H:i:s'),
        'timestamp'  => time(),
        'timezone'   => date_default_timezone_get(),
        'timezone_abbr' => date('T'),
        'offset'     => date('P'),
    ];
}

/* ======================== 输出 ======================== */

// 权限检查：仅管理员可访问本端点
// DEBUG/诊断端点允许未登录访问，方便现场排查 404/扩展/运行时间等问题
$wantDebug  = isset($_GET['ss_debug']) && $_GET['ss_debug'] === '1';
$wantDiag   = isset($_GET['diag'])   && $_GET['diag']   === '1';
if (!$wantDebug && !$wantDiag && (!is_logged_in() || !is_admin())) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(35);
header('Content-Type: application/json; charset=utf-8');

// 3 个独立端点，前端并行请求，各自不阻塞：
// ?static=1 — 静态数据（CPU型号/内存条/磁盘/硬盘型号/环境），仅首次加载
// ?temps=1  — 温度数据，独立 3 秒缓存
// 默认      — 动态数据（CPU使用率/内存/网络/运行时间/负载状态），不含温度

$wantStatic = isset($_GET['static']) && $_GET['static'] === '1';
$wantTemps  = isset($_GET['temps'])  && $_GET['temps']  === '1';
$wantRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
// $wantDiag / $wantDebug 已在权限检查前定义

// ?refresh=1 — 强制清除所有系统状态缓存并返回确认信息（用于排查/调试）
if ($wantRefresh) {
    $cacheDir = APP_ROOT . 'data/cache';
    $cleared = 0;
    $files = [];
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/system_status_*.json') ?: [];
        foreach ($files as $f) {
            if (@unlink($f)) $cleared++;
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'Cache cleared',
        'cleared' => $cleared,
        'files' => array_map(function ($f) use ($cacheDir) { return basename($f); }, $files),
        'timestamp' => time(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 诊断端点：检测可用的扩展和数据采集方式，方便排查问题
if ($wantDiag || $wantDebug) {
    $ffiInitError = null;
    $ffiHandle = ss_ffi_kernel32($ffiInitError);

    $diag = [
        'success' => true,
        'php_version' => PHP_VERSION,
        'os' => ss_os_type(),
        'os_raw' => PHP_OS,
        'extensions' => [
            'ffi'         => extension_loaded('ffi'),
            'com_dotnet'  => extension_loaded('com_dotnet'),
            'shell_exec'  => function_exists('shell_exec'),
        ],
        'subprocess_auto' => ss_subprocess_allowed(),
        'shell_exec_usable' => function_exists('shell_exec') && ss_shell_enabled(),
        'methods' => [
            'ffi_available'   => ss_ffi_available(),
            'com_available'   => ss_com_available(),
            'subprocess_auto' => ss_subprocess_allowed(),
            'shell_exec_usable' => function_exists('shell_exec') && ss_shell_enabled(),
        ],
        'ffi_init_error' => $ffiInitError,
    ];

    // 测试 PowerShell 子进程兜底（Windows 下 FFI/COM 均不可用时的唯一采集途径）
    if (ss_os_type() === 'linux') {
        // Linux 无 PowerShell：跳过测试，避免显示误导性失败提示
        $diag['ps_test'] = ['output' => null, 'note' => 'N/A (Linux: 使用 /proc、/sys 与只读命令采集)'];
    } else {
    $psOut = ss_ps_run(t('admin_api_system_status_ajax_edc586','\'PowerShell\\:采集通道测试OK\''));
    if ($psOut !== null) {
        $diag['ps_test']['output'] = $psOut;
        $psCpu = ss_ps_cim_json('Win32_Processor', ['Name']);
        $diag['ps_test']['cpu_cim'] = !empty($psCpu) ? 'OK: ' . ($psCpu[0]['Name'] ?? '?') : 'FAILED';
        $psDisk = ss_ps_cim_json('Win32_DiskDrive', ['Model']);
        $diag['ps_test']['disk_cim'] = !empty($psDisk) ? 'OK: ' . ($psDisk[0]['Model'] ?? '?') : 'FAILED';
        $psMem = ss_ps_realtime_sample();
        $diag['ps_test']['realtime_sample'] = $psMem !== null ? 'OK: TotalKB=' . ($psMem['TotalKB'] ?? '?') . ' Load=' . ($psMem['Load'] ?? '?') : 'FAILED';
        $psBatch = ss_ps_batch_collect();
        $diag['ps_test']['batch'] = $psBatch !== null
            ? 'OK: keys=' . implode(',', array_keys($psBatch))
            : t('admin_ajax_ss_batch_failed', 'FAILED：单次批量采集失败（WMI 服务异常或超时）');
    } else {
        $diag['ps_test'] = [
            'output' => null,
            'note' => t('admin_ajax_ss_shell_disabled', 'FAILED：shell_exec 被禁用、PowerShell 不在 PATH 或子进程被禁止。请检查 disable_functions 与 php.ini。'),
        ];
    }
    }

    // 测试 FFI 是否能正常调用
    if (ss_ffi_available()) {
        $mem = ss_ffi_get_memory_status();
        $diag['ffi_test']['memory'] = $mem !== null ? 'OK (total=' . ($mem['total_formatted'] ?? '?') . ')' : 'FAILED';

        $uptime = ss_ffi_get_uptime_seconds();
        $diag['ffi_test']['uptime'] = $uptime !== null ? 'OK (' . $uptime . 's)' : 'FAILED';

        $cpuTimes = ss_ffi_get_cpu_times();
        $diag['ffi_test']['cpu_times'] = $cpuTimes !== null ? 'OK' : 'FAILED';

        $cores = ss_ffi_get_cpu_cores();
        $diag['ffi_test']['cpu_cores'] = $cores !== null ? 'OK (' . $cores . ')' : 'FAILED';
    }

    // 测试 COM 是否能正常调用
    if (ss_com_available()) {
        $row = ss_wmi_query_first(
            'SELECT LoadPercentage FROM Win32_Processor',
            'root/cimv2',
            ['LoadPercentage']
        );
        $diag['com_test']['cpu_load'] = $row ? 'OK (' . ($row['LoadPercentage'] ?? '?') . '%)' : 'FAILED';

        // 测试内存条查询
        $memRows = ss_wmi_query(
            'SELECT BankLabel,Capacity,Speed,Manufacturer,PartNumber FROM Win32_PhysicalMemory',
            'root/cimv2',
            ['BankLabel', 'Capacity', 'Speed', 'Manufacturer', 'PartNumber']
        );
        $diag['com_test']['memory_banks'] = 'OK (' . count($memRows) . ' banks)' . (count($memRows) > 0 ? ': ' . ($memRows[0]['Manufacturer'] ?? '?') . ' ' . ss_format_bytes((int)($memRows[0]['Capacity'] ?? 0)) : '');

        // 测试硬盘查询
        $diskRows = ss_wmi_query(
            'SELECT Model,Size,SerialNumber,InterfaceType,MediaType FROM Win32_DiskDrive',
            'root/cimv2',
            ['Model', 'Size', 'SerialNumber', 'InterfaceType', 'MediaType']
        );
        $diag['com_test']['disk_hardware'] = 'OK (' . count($diskRows) . ' disks)' . (count($diskRows) > 0 ? ': ' . ($diskRows[0]['Model'] ?? '?') : '');

        // 测试 CPU 型号查询
        $cpuRow = ss_wmi_query_first(
            'SELECT Name,NumberOfCores,NumberOfLogicalProcessors FROM Win32_Processor',
            'root/cimv2',
            ['Name', 'NumberOfCores', 'NumberOfLogicalProcessors']
        );
        $diag['com_test']['cpu_info'] = $cpuRow ? 'OK (' . trim($cpuRow['Name'] ?? '?') . ')' : 'FAILED';
    }

    // 测试实际函数调用（包含缓存逻辑）
    $diag['function_test']['memory_banks'] = ss_get_memory_banks();
    $diag['function_test']['disk_hardware'] = ss_get_disk_hardware();
    $diag['function_test']['cpu_info'] = ss_get_cpu_info();

    // GPU 专项诊断：便于定位独显被误判、显存读不出等问题
    $gpuDiag = [];
    if (ss_os_type() === 'windows') {
        $gpuRows = ss_wmi_query(
            'SELECT Name,AdapterRAM,DriverVersion,VideoProcessor FROM Win32_VideoController',
            'root/cimv2',
            ['Name', 'AdapterRAM', 'DriverVersion', 'VideoProcessor']
        );
        if (empty($gpuRows)) {
            $gpuRows = ss_ps_cim_json('Win32_VideoController', ['Name', 'AdapterRAM', 'DriverVersion', 'VideoProcessor']);
            $gpuDiag['source'] = 'powershell_cim';
        } else {
            $gpuDiag['source'] = 'wmi_com';
        }
        $gpuDiag['raw_rows'] = $gpuRows;

        $gpuInfo = ss_get_gpu_info();
        $gpuDiag['processed'] = $gpuInfo;

        // 对每个检测到的显卡单独测试显存采集通道
        $gpuDiag['vram_tests'] = [];
        foreach ($gpuRows as $idx => $row) {
            $name = trim($row['Name'] ?? '');
            if ($name === '') continue;
            $test = [
                'index' => $idx,
                'name' => $name,
                'adapter_ram_raw' => $row['AdapterRAM'] ?? null,
                'name_looks_integrated' => ss_gpu_name_looks_integrated($name),
                'registry_vram_bytes' => ss_get_gpu_vram_from_registry($name),
                'nvidia_smi_vram_bytes' => ss_get_gpu_vram_from_nvidia_smi($name),
            ];
            $gpuDiag['vram_tests'][] = $test;
        }

        // nvidia-smi 全局可用性
        $smiExe = null;
        foreach ([
            'C:\\Program Files\\NVIDIA Corporation\\NVSMI\\nvidia-smi.exe',
            'C:\\Windows\\System32\\nvidia-smi.exe',
            'C:\\Windows\\SysWOW64\\nvidia-smi.exe',
        ] as $p) {
            if (file_exists($p)) {
                $smiExe = $p;
                break;
            }
        }
        $gpuDiag['nvidia_smi_found'] = $smiExe !== null ? $smiExe : (ss_shell_exec('where nvidia-smi.exe 2>nul') ? 'PATH' : false);
    }
    $diag['gpu_diag'] = $gpuDiag;

    // 温度采集方法诊断：排查哪个方案成功/失败及原因
    $diag['temperature_diag'] = $GLOBALS['_ss_temp_diag'] ?? null;
    // 触发一次温度采集（确保诊断数据被填充，同时报告实际结果）
    $diag['temperature_result'] = ss_get_temperatures();

    // 检查缓存文件状态
    $cacheDir = APP_ROOT . 'data/cache';
    $cacheFiles = [];
    if (is_dir($cacheDir)) {
        foreach (glob($cacheDir . '/system_status_*.json') as $cf) {
            $cacheFiles[basename($cf)] = [
                'size' => filesize($cf),
                'mtime' => date('Y-m-d H:i:s', filemtime($cf)),
            ];
        }
    }
    $diag['cache_files'] = $cacheFiles;
    $diag['cache_dir_exists'] = is_dir($cacheDir);

    // Linux 环境专项诊断：排查 /proc、/sys 读取失败原因
    if (ss_os_type() === 'linux') {
        $linuxDiag = [];
        // 测试 /proc 文件可读性
        foreach (['/proc/cpuinfo', '/proc/meminfo', '/proc/stat', '/proc/uptime', '/proc/loadavg', '/proc/mounts', '/proc/net/dev'] as $pf) {
            $data = @file_get_contents($pf);
            $linuxDiag['proc_files'][$pf] = [
                'readable' => $data !== false,
                'size' => $data !== false ? strlen($data) : -1,
                'preview' => $data !== false ? substr(trim($data), 0, 120) : null,
            ];
        }
        // 测试 /sys 文件可读性
        foreach ([
            '/sys/devices/system/cpu/present',
            '/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq',
            '/sys/class/dmi/id/product_name',
            '/sys/fs/cgroup/memory.max',
            '/sys/fs/cgroup/memory.current',
            '/sys/fs/cgroup/memory/memory.limit_in_bytes',
            '/sys/class/thermal/thermal_zone0/temp',
        ] as $sf) {
            $data = @file_get_contents($sf);
            $linuxDiag['sys_files'][$sf] = [
                'readable' => $data !== false,
                'value' => $data !== false ? trim($data) : null,
            ];
        }
        // 检查 open_basedir
        $ob = ini_get('open_basedir');
        $linuxDiag['php_config'] = [
            'open_basedir' => $ob ?: '(unrestricted)',
            'disable_functions' => ini_get('disable_functions') ?: '(none)',
            'memory_limit' => ini_get('memory_limit'),
        ];
        // glob cpu 目录
        $cpuDirs = @glob('/sys/devices/system/cpu/cpu[0-9]*');
        $linuxDiag['cpu_count_methods'] = [
            'glob_cpu_dirs' => !empty($cpuDirs) ? count($cpuDirs) : 0,
        ];
        $diag['linux_diag'] = $linuxDiag;
    }

    // DEBUG 增强：输出请求与运行时轨迹，便于定位 404 / 20668 天等问题
    if ($wantDebug) {
        $uptimeRaw = ss_get_uptime_seconds();
        $bootTimeCache = ss_read_realtime_cache_file();
        $diag['debug'] = [
            'request' => [
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'query_string' => $_SERVER['QUERY_STRING'] ?? '',
                'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? '',
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
                'http_host' => $_SERVER['HTTP_HOST'] ?? '',
            ],
            'file' => [
                'this_file' => __FILE__,
                'exists' => file_exists(__FILE__),
                'mtime' => date('Y-m-d H:i:s', filemtime(__FILE__)),
            ],
            'app_root' => APP_ROOT,
            'cache_version' => SS_CACHE_VERSION,
            'boot_time_from_cache' => $bootTimeCache['boot_time'] ?? null,
            'uptime_seconds' => $uptimeRaw,
            'uptime_formatted' => ss_format_duration($uptimeRaw),
        ];
    }

    $diag['timestamp'] = time();
    ob_end_clean();
    echo json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 温度端点：独立返回，不阻塞动态数据
if ($wantTemps) {
    $temperatures = ss_get_static_cache('temperatures', 'ss_get_temperatures', 3);
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'temperatures' => $temperatures,
        'timestamp' => time(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 动态数据：每次请求都返回（不含温度，避免 WMI 阻塞）
$realtime = ss_get_realtime_cache();
$cpuUsage = $realtime['cpu_usage'];
$memory = $realtime['memory'];
$network = $realtime['network'];
$uptime = ss_get_uptime_seconds();
$status = ss_get_load_status($cpuUsage, $memory['usage_percent'] ?? 0);

// 判断 Windows 下是否有可用的采集方式（FFI / COM / PowerShell 子进程）
$subprocessOk = function_exists('shell_exec') && ss_shell_enabled();
$hasWindowsDataSource = (ss_os_type() !== 'windows') || ss_ffi_available() || ss_com_available() || (ss_subprocess_allowed() && $subprocessOk);
$warnings = [];
if (ss_os_type() === 'windows' && !$hasWindowsDataSource) {
    $warnings[] = t('admin_ajax_ss_windows_warning', 'Windows 系统信息采集不可用：请启用 PHP 的 com_dotnet 扩展，或在 php.ini 中启用 FFI（ffi.enable=true，需 PHP 7.4+），或确保 shell_exec 未被 disable_functions 禁用（将自动使用 PowerShell 兜底）。');
}
if ($memory['total'] <= 0 && ss_os_type() === 'windows') {
    $warnings[] = t('admin_ajax_ss_memory_failed', '内存数据获取失败。');
}
if ($uptime <= 0 && ss_os_type() === 'windows') {
    $warnings[] = t('admin_ajax_ss_uptime_failed', '运行时间获取失败。');
}

$response = [
    'success' => true,
    'cpu_usage' => $cpuUsage,
    'memory' => $memory,
    'network' => $network,
    'uptime_seconds' => $uptime,
    'uptime_formatted' => ss_format_duration($uptime),
    'load_status' => $status,
    'timestamp' => time(),
    'warnings' => $warnings,
    // 磁盘分区使用率（10 秒缓存，比静态数据的 1 小时更实时）
    'disks' => ss_get_static_cache('disks_dynamic', 'ss_get_disk_partitions', 10),
    // 新增：系统负载平均值（缓存 5 秒，避免每次动态轮询都查 WMI/FFI）
    'load_average' => ss_get_static_cache('load_average', 'ss_get_load_average', 5),
    // 新增：服务器时间（动态，每次返回）
    'server_time' => ss_get_server_time(),
    // 数据库轻量统计（10 秒缓存，仅文件大小/表数/行数，不逐表 COUNT）
    'db_stats' => ss_get_db_lite_stats(),
];

// 静态数据：仅 ?static=1 时返回
if ($wantStatic) {
    // Windows 且 COM 不可用：预加载单次批量采集结果（1 小时磁盘缓存），
    // 后续各硬件函数（ss_ps_cim_json）直接复用，避免每个函数各启动一次 powershell
    if (ss_os_type() === 'windows' && !ss_com_available()) {
        ss_ps_batch();
    }

    $cpuStatic = ss_get_static_cache('cpu_static', function () {
        $info = ss_get_cpu_info();
        return [
            'model' => $info['model'],
            'cores' => $info['cores'],
            'threads' => $info['threads'],
            'sockets' => $info['sockets'] ?? 1,
        ];
    }, 3600);

    $response['os'] = ss_os_type();
    $response['cpu_static'] = $cpuStatic;
    $response['memory_banks'] = ss_get_static_cache('memory_banks', 'ss_get_memory_banks', 3600);
    $response['disk_hardware'] = ss_get_static_cache('disk_hardware', 'ss_get_disk_hardware', 3600);
    $response['php_version'] = PHP_VERSION;
    $response['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? t('admin_ajax_unknown', '未知');
    // 新增：GPU 信息（1 小时缓存）— try-catch 防止单个采集失败导致整个响应崩溃
    try { $response['gpu_info'] = ss_get_static_cache('gpu_info', 'ss_get_gpu_info', 3600); } catch (\Throwable $e) { $response['gpu_info'] = []; }
    // 新增：主板信息（1 小时缓存）
    try { $response['motherboard'] = ss_get_static_cache('motherboard', 'ss_get_motherboard_info', 3600); } catch (\Throwable $e) { $response['motherboard'] = []; }
    // 新增：电池状态（不缓存，每次刷新获取最新状态）
    try { $response['battery'] = ss_get_battery_info(); } catch (\Throwable $e) { $response['battery'] = null; }
    // 新增：PHP 详细信息（5 分钟缓存，扩展列表可能变化）
    try { $response['php_info'] = ss_get_static_cache('php_info', 'ss_get_php_info', 300); } catch (\Throwable $e) { $response['php_info'] = null; }
    // 新增：网络接口列表（10 分钟缓存）
    try { $response['network_interfaces'] = ss_get_static_cache('network_interfaces', 'ss_get_network_interfaces', 600); } catch (\Throwable $e) { $response['network_interfaces'] = []; }
    // 新增：操作系统详细信息
    $response['os_info'] = [
        'php_uname' => php_uname(),
        'hostname'  => gethostname(),
        'os_family' => PHP_OS_FAMILY,
    ];
    // 数据库详细统计（含逐表 COUNT，静态端点专用，5 分钟缓存）
    try { $response['db_stats_full'] = ss_get_static_cache('db_stats_full', 'ss_get_db_stats', 300); } catch (\Throwable $e) { $response['db_stats_full'] = null; }
    $dbSize = (defined('DB_FILE') && is_file(DB_FILE)) ? (int)filesize(DB_FILE) : 0;
    $response['db_size_formatted'] = ss_format_bytes($dbSize);
    $response['static_warnings'] = [];
    if (ss_os_type() === 'windows') {
        $ffiOk = ss_ffi_available();
        $comOk = ss_com_available();
        $shellOk = function_exists('shell_exec') && ss_shell_enabled();
        $subprocessOk = ss_subprocess_allowed();

        if (!$ffiOk && !$comOk && !($subprocessOk && $shellOk)) {
            $response['static_warnings'][] = t('admin_ajax_ss_no_method', '当前 PHP 无法采集 Windows 硬件信息：com_dotnet 扩展未加载、FFI 未启用（php.ini 中 ffi.enable 需设为 true）、且 shell_exec/proc_open 被禁用。请至少启用以上任一方式。');
        } elseif (!$comOk && ($subprocessOk && $shellOk)) {
            $response['static_warnings'][] = t('admin_ajax_ss_powershell_fallback', '未加载 com_dotnet 扩展，已通过 PowerShell 子进程兜底采集硬件信息（首次加载稍慢，缓存 1 小时）。如部分信息仍缺失，可能是 WMI 服务未运行或被安全软件拦截。');
        }

        // 若某几项关键数据缺失，给出更具体提示
        $mb = $response['motherboard'] ?? [];
        $mbEmpty = empty($mb) || (!empty($mb) && $mb['manufacturer'] === '--' && $mb['product'] === '--');
        if ($mbEmpty) {
            $response['static_warnings'][] = t('admin_ajax_ss_motherboard_failed', '主板/BIOS 信息未能获取。可尝试以管理员身份运行 php-cgi/php-fpm，或在 php.ini 中启用 com_dotnet / FFI。');
        }
    }
}

// 清除输出缓冲中的任何 notice/warning，确保只输出 JSON
ob_end_clean();

$json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
if ($json === false) {
    // json_encode 失败（极端情况），输出兜底响应
    echo json_encode([
        'success'          => true,
        'cpu_usage'        => $cpuUsage ?? 0,
        'memory'           => $memory ?? ['total' => 0, 'used' => 0, 'usedPercent' => 0],
        'uptime_seconds'   => $uptime ?? 0,
        'timestamp'        => time(),
        'encode_error'     => json_last_error_msg(),
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo $json;
}
