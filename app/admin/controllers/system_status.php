<?php
/**
 * 云界论坛 - 管理后台运行状态监控
 *
 * 本页只负责渲染静态结构；所有硬件与实时数据通过 AJAX 异步加载，
 * 避免页面渲染时被 WMI 查询阻塞。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$pageTitle = t('admin_sys_title', '运行状态监控');
$activeMenu = 'system_status';

require_once dirname(__DIR__) . '/layout/header.php';

function ss_page_format_bytes(int $bytes): string {
    if ($bytes >= 1099511627776) {
        return round($bytes / 1099511627776, 2) . ' TB';
    }
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

$dbSize = defined('DB_FILE') && is_file(DB_FILE) ? (int)filesize(DB_FILE) : 0;
?>

<style>
.status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.status-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    box-shadow: var(--shadow-sm);
}
.status-card-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-muted);
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.status-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 0.25rem;
    word-break: break-word;
}
.status-sub {
    font-size: 0.8125rem;
    color: var(--text-muted);
}
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 600;
}
.status-badge.success { background: rgba(16, 185, 129, 0.15); color: #10b981; }
.status-badge.warning { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
.status-badge.danger  { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
[data-theme="dark"] .status-badge.success { background: rgba(16, 185, 129, 0.25); }
[data-theme="dark"] .status-badge.warning { background: rgba(245, 158, 11, 0.25); }
[data-theme="dark"] .status-badge.danger  { background: rgba(239, 68, 68, 0.25); }

.progress-track {
    width: 100%;
    height: 0.625rem;
    background: var(--surface-3);
    border-radius: 9999px;
    overflow: hidden;
    margin-top: 0.75rem;
}
.progress-fill {
    height: 100%;
    border-radius: 9999px;
    transition: width 0.4s ease;
    min-width: 2px;
}
.progress-fill.low    { background: #10b981; }
.progress-fill.medium { background: #f59e0b; }
.progress-fill.high   { background: #ef4444; }

.bank-list {
    list-style: none;
    margin: 0;
    padding: 0;
}
.bank-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.625rem 0;
    border-bottom: 1px solid var(--border);
    font-size: 0.875rem;
}
.bank-item:last-child { border-bottom: none; }
.bank-slot { color: var(--text-muted); min-width: 5rem; }
.bank-model { flex: 1; margin: 0 0.75rem; color: var(--text); }
.bank-meta { color: var(--text-muted); white-space: nowrap; }

.disk-list { margin-top: 0.75rem; }
.disk-item {
    padding: 0.875rem 0;
    border-bottom: 1px solid var(--border);
}
.disk-item:last-child { border-bottom: none; }
.disk-header {
    display: flex;
    justify-content: space-between;
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
}
.disk-device { font-weight: 600; color: var(--text); }
.disk-meta { color: var(--text-muted); }

.last-update {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-left: auto;
    transition: color 0.2s ease;
}
.last-update.flash {
    color: var(--primary);
}

.temp-list {
    list-style: none;
    margin: 0;
    padding: 0;
}
.temp-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--border);
    font-size: 0.875rem;
}
.temp-item:last-child { border-bottom: none; }
.temp-name { color: var(--text-muted); }
.temp-value {
    font-weight: 600;
    color: var(--text);
}
.temp-value.normal { color: #10b981; }
.temp-value.warning { color: #f59e0b; }
.temp-value.danger { color: #ef4444; }

.net-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
}
.net-arrow {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 50%;
    font-size: 0.75rem;
}
.net-arrow.down { background: rgba(16, 185, 129, 0.15); color: #10b981; }
.net-arrow.up   { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }

.hw-disk-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.625rem 0;
    border-bottom: 1px solid var(--border);
    font-size: 0.875rem;
}
.hw-disk-item:last-child { border-bottom: none; }
.hw-disk-model { flex: 1; margin: 0 0.75rem; color: var(--text); font-weight: 500; }
.hw-disk-meta { color: var(--text-muted); white-space: nowrap; }
.hw-disk-badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}
.hw-disk-badge.ssd { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
.hw-disk-badge.hdd { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
</style>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_sys_title', '运行状态监控')); ?></h1>
    <div class="page-tools">
        <a href="<?php echo site_url('admin/api/system_status_ajax', ['diag' => '1']); ?>" target="_blank" class="btn btn-sm btn-secondary" title="<?php echo e(t('admin_sys_diag_title', '查看当前环境可用的采集方式（FFI/COM/PowerShell）\"')); ?>><?php echo e(t('admin_sys_diag_btn', '诊断采集通道')); ?></a>
        <span class="last-update" id="lastUpdate"><?php echo e(t('admin_sys_loading', '正在加载…')); ?></span>
    </div>
</div>

<div class="status-grid">
    <!-- 运行状态 -->
    <div class="status-card">
        <div class="status-card-title">
            <span><?php echo e(t('admin_sys_status', '运行状态')); ?></span>
            <span class="status-badge success" id="loadStatusBadge"><?php echo e(t('admin_sys_status_ok', '运行正常')); ?></span>
        </div>
        <div class="status-value" id="uptimeValue">--</div>
        <div class="status-sub"><?php echo e(t('admin_sys_uptime', '系统持续运行时间')); ?></div>
    </div>

    <!-- CPU -->
    <div class="status-card">
        <div class="status-card-title">
            <span><?php echo e(t('admin_sys_cpu_load', 'CPU 实时负载')); ?></span>
            <span id="cpuUsageText">0%</span>
        </div>
        <div class="status-value" id="cpuModel">--</div>
        <div class="status-sub" id="cpuCores">--</div>
        <div class="progress-track">
            <div class="progress-fill low" id="cpuUsageBar" style="width:0%"></div>
        </div>
    </div>

    <!-- 内存 -->
    <div class="status-card">
        <div class="status-card-title">
            <span><?php echo e(t('admin_sys_mem_usage', '内存实时使用')); ?></span>
            <span id="memUsageText">0%</span>
        </div>
        <div class="status-value" id="memUsed">--</div>
        <div class="status-sub" id="memTotal">--</div>
        <div class="progress-track">
            <div class="progress-fill low" id="memUsageBar" style="width:0%"></div>
        </div>
    </div>

    <!-- 系统负载 -->
    <div class="status-card">
        <div class="status-card-title">
            <span><?php echo e(t('admin_sys_load_avg', '系统负载平均值')); ?></span>
            <span class="status-sub"><?php echo e(t('admin_sys_load_avg_sub', '1/5/15 分钟')); ?></span>
        </div>
        <div class="status-value" id="loadAvg1" style="font-size:1.5rem;">--</div>
        <div class="status-sub">
            <span><?php echo e(t('admin_sys_load_5min', '5 分钟：')); ?><strong id="loadAvg5">--</strong></span>
            <span style="margin-left:0.75rem;"><?php echo e(t('admin_sys_load_15min', '15 分钟：')); ?><strong id="loadAvg15">--</strong></span>
        </div>
    </div>

    <!-- 服务器时间 -->
    <div class="status-card">
        <div class="status-card-title">
            <span><?php echo e(t('admin_sys_server_time', '服务器时间')); ?></span>
            <span class="status-sub" id="serverTzAbbr">--</span>
        </div>
        <div class="status-value" id="serverTime" style="font-size:1.5rem;">--</div>
        <div class="status-sub" id="serverTz">--</div>
    </div>

    <!-- 数据库统计 -->
    <div class="status-card">
        <div class="status-card-title">
            <span><?php echo e(t('admin_sys_database', '数据库')); ?></span>
            <span class="status-sub" id="dbJournalMode">--</span>
        </div>
        <div class="status-value" style="font-size:1rem;line-height:1.7;">
            <div><?php echo e(t('admin_sys_db_mainfile', '主文件：')); ?><strong id="dbSize">--</strong></div>
            <div><?php echo e(t('admin_sys_db_wal', 'WAL：')); ?><strong id="dbWalSize">--</strong></div>
            <div><?php echo e(t('admin_sys_db_tables', '表：')); ?><strong id="dbTableCount">--</strong><?php echo e(t('admin_sys_db_tables_unit', ' 个 / ')); ?><strong id="dbTotalRows">--</strong><?php echo e(t('admin_sys_db_rows_unit', ' 行')); ?></div>
        </div>
    </div>

    <!-- 温度监控 -->
    <div class="status-card">
        <div class="status-card-title">
            <span><?php echo e(t('admin_sys_temp', '温度监控')); ?></span>
            <span class="status-sub" id="tempCount">--</span>
        </div>
        <div id="tempList">
            <p class="text-muted text-center py-2"><?php echo e(t('admin_sys_loading', '正在加载…')); ?></p>
        </div>
    </div>

    <!-- 网络流量 -->
    <div class="status-card">
        <div class="status-card-title">
            <span><?php echo e(t('admin_sys_network', '网络流量')); ?></span>
            <span class="status-sub"><?php echo e(t('admin_sys_net_rate', '实时速率')); ?></span>
        </div>
        <div class="net-item">
            <span class="net-arrow down">↓</span>
            <span style="flex:1;"><?php echo e(t('admin_sys_download', '下载')); ?></span>
            <span id="netDownloadSpeed" style="font-weight:600;color:#10b981;">-- B/s</span>
        </div>
        <div class="net-item">
            <span class="net-arrow up">↑</span>
            <span style="flex:1;"><?php echo e(t('admin_sys_upload', '上传')); ?></span>
            <span id="netUploadSpeed" style="font-weight:600;color:#3b82f6;">-- B/s</span>
        </div>
        <div class="status-sub" style="margin-top:0.5rem;" id="netTotal"><?php echo e(t('admin_sys_net_total', '累计：↓ -- / ↑ --')); ?></div>
    </div>

    <!-- 电池状态 -->
    <div class="status-card" id="batteryCard" style="display:none;">
        <div class="status-card-title">
            <span><?php echo e(t('admin_sys_battery', '电池状态')); ?></span>
            <span class="status-sub" id="batteryStatus">--</span>
        </div>
        <div class="status-value" id="batteryPercent">--</div>
        <div class="progress-track">
            <div class="progress-fill low" id="batteryBar" style="width:0%"></div>
        </div>
    </div>
</div>

<!-- 环境信息 + PHP 详细信息 -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('admin_sys_php_env', 'PHP 运行环境')); ?></h2>
    </div>
    <div id="phpInfoPanel" style="padding:1rem 1.25rem;">
        <p class="text-muted text-center py-2"><?php echo e(t('admin_sys_loading', '正在加载…')); ?></p>
    </div>
</div>

<!-- GPU 信息 -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('admin_sys_gpu', '显卡信息')); ?></h2>
    </div>
    <div id="gpuInfoPanel" style="padding:1rem 1.25rem;">
        <p class="text-muted text-center py-2"><?php echo e(t('admin_sys_loading', '正在加载…')); ?></p>
    </div>
</div>

<!-- 主板 + BIOS 信息 -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('admin_sys_motherboard', '主板与 BIOS')); ?></h2>
    </div>
    <div id="motherboardPanel" style="padding:1rem 1.25rem;">
        <p class="text-muted text-center py-2"><?php echo e(t('admin_sys_loading', '正在加载…')); ?></p>
    </div>
</div>

<!-- 网络接口 -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('admin_sys_nic', '网络接口')); ?></h2>
    </div>
    <div id="networkInterfacesPanel" style="padding:1rem 1.25rem;">
        <p class="text-muted text-center py-2"><?php echo e(t('admin_sys_loading', '正在加载…')); ?></p>
    </div>
</div>

<!-- 数据库表 Top15 -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('admin_sys_db_tables_top', '数据库表（按记录数 Top 15）')); ?></h2>
    </div>
    <div id="dbTablesPanel" style="padding:1rem 1.25rem;">
        <p class="text-muted text-center py-2"><?php echo e(t('admin_sys_loading', '正在加载…')); ?></p>
    </div>
</div>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('admin_sys_memory_banks', '内存条信息')); ?></h2>
    </div>
    <div id="memoryBanks">
        <p class="text-muted text-center py-2"><?php echo e(t('admin_sys_loading', '正在加载…')); ?></p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('admin_sys_disk_part', '磁盘分区')); ?></h2>
    </div>
    <div id="diskPartitions" class="disk-list">
        <p class="text-muted text-center py-2"><?php echo e(t('admin_sys_loading', '正在加载…')); ?></p>
    </div>
</div>

<div class="card" style="margin-top:1.5rem;">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('admin_sys_disk_hw', '硬盘型号')); ?></h2>
    </div>
    <div id="diskHardware">
        <p class="text-muted text-center py-2"><?php echo e(t('admin_sys_loading', '正在加载…')); ?></p>
    </div>
</div>

<script>
(function () {
    var API_URL = '/index.php?route=admin/api/system_status_ajax'; // 绝对路径：以 / 开头，避免在 /admin/xxx 页面被浏览器解析成 /admin/index.php
    var DYNAMIC_INTERVAL = 2000; // 动态数据 2 秒轮询
    var TEMP_INTERVAL = 3000;    // 温度 3 秒轮询
    var ADMIN_SYS_LOADING_TEXT = <?php echo json_encode(t('admin_sys_loading', '正在加载…')); ?>;

    // DEBUG: 把关键信息打印到浏览器控制台，便于诊断
    console.log('[SS-DEBUG] API_URL =', API_URL);
    console.log('[SS-DEBUG] page URL =', window.location.href);
    console.log('[SS-DEBUG] timestamp =', new Date().toISOString());

    // 缓存所有 DOM 引用
    var el = {
        lastUpdate: document.getElementById('lastUpdate'),
        loadStatusBadge: document.getElementById('loadStatusBadge'),
        uptimeValue: document.getElementById('uptimeValue'),
        cpuModel: document.getElementById('cpuModel'),
        cpuCores: document.getElementById('cpuCores'),
        cpuUsageText: document.getElementById('cpuUsageText'),
        cpuUsageBar: document.getElementById('cpuUsageBar'),
        memUsed: document.getElementById('memUsed'),
        memTotal: document.getElementById('memTotal'),
        memUsageText: document.getElementById('memUsageText'),
        memUsageBar: document.getElementById('memUsageBar'),
        memoryBanks: document.getElementById('memoryBanks'),
        diskPartitions: document.getElementById('diskPartitions'),
        diskHardware: document.getElementById('diskHardware'),
        tempList: document.getElementById('tempList'),
        tempCount: document.getElementById('tempCount'),
        netDownloadSpeed: document.getElementById('netDownloadSpeed'),
        netUploadSpeed: document.getElementById('netUploadSpeed'),
        netTotal: document.getElementById('netTotal'),
        // 新增元素
        loadAvg1: document.getElementById('loadAvg1'),
        loadAvg5: document.getElementById('loadAvg5'),
        loadAvg15: document.getElementById('loadAvg15'),
        serverTime: document.getElementById('serverTime'),
        serverTz: document.getElementById('serverTz'),
        serverTzAbbr: document.getElementById('serverTzAbbr'),
        dbSize: document.getElementById('dbSize'),
        dbWalSize: document.getElementById('dbWalSize'),
        dbTableCount: document.getElementById('dbTableCount'),
        dbTotalRows: document.getElementById('dbTotalRows'),
        dbJournalMode: document.getElementById('dbJournalMode'),
        batteryCard: document.getElementById('batteryCard'),
        batteryStatus: document.getElementById('batteryStatus'),
        batteryPercent: document.getElementById('batteryPercent'),
        batteryBar: document.getElementById('batteryBar'),
        phpInfoPanel: document.getElementById('phpInfoPanel'),
        gpuInfoPanel: document.getElementById('gpuInfoPanel'),
        motherboardPanel: document.getElementById('motherboardPanel'),
        networkInterfacesPanel: document.getElementById('networkInterfacesPanel'),
        dbTablesPanel: document.getElementById('dbTablesPanel')
    };

    var dynamicTimer = null;
    var tempTimer = null;
    var uptimeTimer = null;
    var isFetchingDynamic = false;
    var isFetchingStatic = false;
    var isFetchingTemp = false;
    var uptimeSeconds = 0;
    var uptimeSyncedAt = 0;
    var uptimeInitialized = false;
    var staticLoaded = false;
    var lastValues = {};

    function formatDuration(seconds) {
        var days = Math.floor(seconds / 86400);
        var hours = Math.floor((seconds % 86400) / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        var secs = seconds % 60;
        var parts = [];
        if (days > 0) parts.push(days + <?php echo json_encode(t('admin_sys_day', ' 天')); ?>);
        if (hours > 0 || days > 0) parts.push(hours + <?php echo json_encode(t('admin_sys_hour', ' 小时')); ?>);
        if (minutes > 0 || hours > 0 || days > 0) parts.push(minutes + <?php echo json_encode(t('admin_sys_min', ' 分钟')); ?>);
        parts.push(secs + <?php echo json_encode(t('admin_sys_sec', ' 秒')); ?>);
        return parts.join(' ');
    }

    function tickUptime() {
        var elapsed = Math.floor((Date.now() - uptimeSyncedAt) / 1000);
        el.uptimeValue.textContent = formatDuration(uptimeSeconds + elapsed);
    }

    function startUptimeTicker() {
        if (uptimeTimer) return;
        uptimeTimer = setInterval(tickUptime, 1000);
    }

    function barClass(percent) {
        if (percent >= 85) return 'high';
        if (percent >= 60) return 'medium';
        return 'low';
    }

    function tempClass(temp) {
        if (temp >= 80) return 'danger';
        if (temp >= 60) return 'warning';
        return 'normal';
    }

    function escapeHtml(text) {
        if (text == null) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setIfChanged(element, key, value, isHtml) {
        if (lastValues[key] === value) return;
        lastValues[key] = value;
        if (isHtml) element.innerHTML = value;
        else element.textContent = value;
    }

    // 渲染静态数据（只调用一次）
    function renderStatic(data) {
        if (!data || !data.success) return;

        // 显示静态数据警告
        if (data.static_warnings && data.static_warnings.length > 0) {
            var warnHtml = '<div class="alert alert-warning" style="margin-bottom:1rem;">' +
                '<strong>' + <?php echo json_encode(t('admin_sys_cfg_tip', '配置提示：')); ?> + '</strong>' + escapeHtml(data.static_warnings.join(' ')) +
                '</div>';
            var warnContainer = document.getElementById('staticWarnings');
            if (!warnContainer) {
                warnContainer = document.createElement('div');
                warnContainer.id = 'staticWarnings';
                var memBanksCard = el.memoryBanks.closest('.card');
                if (memBanksCard) memBanksCard.insertAdjacentElement('beforebegin', warnContainer);
            }
            warnContainer.innerHTML = warnHtml;
        }

        var cpu = data.cpu_static || {};
        el.cpuModel.textContent = cpu.model || '--';
        el.cpuCores.textContent = (cpu.cores || 0) + <?php echo json_encode(t('admin_sys_cpu_cores', ' 核 ')); ?> + (cpu.threads || 0) + <?php echo json_encode(t('admin_sys_cpu_threads', ' 线程')); ?>;

        var banksHtml = '';
        var banks = data.memory_banks || [];
        if (banks.length > 0) {
            banksHtml = '<ul class="bank-list">';
            for (var i = 0; i < banks.length; i++) {
                var b = banks[i];
                banksHtml += '<li class="bank-item">' +
                    '<span class="bank-slot">' + escapeHtml(b.slot) + '</span>' +
                    '<span class="bank-model">' + escapeHtml(b.model) + '</span>' +
                    '<span class="bank-meta">' + escapeHtml(b.capacity_formatted) +
                    (b.speed ? ' / ' + b.speed + ' MHz' : '') + '</span></li>';
            }
            banksHtml += '</ul>';
        } else {
            banksHtml = '<p class="text-muted text-center py-2">' + <?php echo json_encode(t('admin_sys_no_mem', '未能获取内存条信息。')); ?> + '</p>';
        }
        el.memoryBanks.innerHTML = banksHtml;

        // 磁盘分区使用率现在在动态数据中实时刷新，此处不再渲染
        var hwDisksHtml = '';
        var hwDisks = data.disk_hardware || [];
        if (hwDisks.length > 0) {
            for (var i = 0; i < hwDisks.length; i++) {
                var d = hwDisks[i];
                var mediaType = (d.media_type || '').toUpperCase();
                var badge = '';
                if (mediaType.indexOf('SSD') !== -1 || (mediaType.indexOf('FIXED HARD DISK') !== -1 && mediaType.indexOf('HDD') === -1)) {
                    badge = '<span class="hw-disk-badge ssd">SSD</span>';
                } else if (mediaType.indexOf('HDD') !== -1 || mediaType === 'FIXED HARD DISK') {
                    badge = '<span class="hw-disk-badge hdd">HDD</span>';
                }
                var metaParts = [];
                if (d.size_formatted) metaParts.push(d.size_formatted);
                if (d.interface) metaParts.push(d.interface);
                if (d.serial) metaParts.push('SN: ' + d.serial);
                hwDisksHtml += '<div class="hw-disk-item">' +
                    '<span>' + badge + '</span>' +
                    '<span class="hw-disk-model">' + escapeHtml(d.model) + '</span>' +
                    '<span class="hw-disk-meta">' + escapeHtml(metaParts.join(' / ')) + '</span></div>';
            }
        } else {
            hwDisksHtml = '<p class="text-muted text-center py-2">' + <?php echo json_encode(t('admin_sys_no_disk', '未能获取硬盘型号信息。')); ?> + '</p>';
        }
        el.diskHardware.innerHTML = hwDisksHtml;

        // 渲染新增的扩展信息（GPU/主板/PHP/网络接口/数据库表/电池）
        renderStaticExtended(data);

        // 用静态端点的详细数据补充数据库总行数
        var dbFull2 = data.db_stats_full;
        if (dbFull2 && typeof dbFull2.total_rows === 'number') {
            el.dbTotalRows.textContent = dbFull2.total_rows.toLocaleString();
        }

        staticLoaded = true;
    }

    // 渲染动态数据
    function renderDynamic(data) {
        if (!data || !data.success) {
            el.lastUpdate.textContent = <?php echo json_encode(t('admin_sys_load_fail', '加载失败')); ?>;
            return;
        }

        // 显示后端警告（扩展未启用等）
        if (data.warnings && data.warnings.length > 0) {
            var warnHtml = '<div class="alert alert-warning" style="margin-bottom:1rem;">' +
                '<strong>' + <?php echo json_encode(t('admin_sys_cfg_tip', '配置提示：')); ?> + '</strong>' + escapeHtml(data.warnings.join(' ')) +
                '</div>';
            var warnContainer = document.getElementById('dynamicWarnings');
            if (!warnContainer) {
                warnContainer = document.createElement('div');
                warnContainer.id = 'dynamicWarnings';
                document.querySelector('.page-header').insertAdjacentElement('afterend', warnContainer);
            }
            warnContainer.innerHTML = warnHtml;
        }

        var status = data.load_status || { text: <?php echo json_encode(t('admin_sys_unknown', '未知')); ?>, class: 'warning' };
        var badgeClass = 'status-badge ' + status.class;
        if (el.loadStatusBadge.className !== badgeClass) {
            el.loadStatusBadge.className = badgeClass;
        }
        setIfChanged(el.loadStatusBadge, 'status_text', status.text);

        if (typeof data.uptime_seconds === 'number') {
            var serverUptime = Math.max(0, data.uptime_seconds);
            if (!uptimeInitialized) {
                uptimeSeconds = serverUptime;
                uptimeSyncedAt = Date.now();
                tickUptime();
                startUptimeTicker();
                uptimeInitialized = true;
            } else {
                var clientUptime = uptimeSeconds + Math.floor((Date.now() - uptimeSyncedAt) / 1000);
                if (Math.abs(clientUptime - serverUptime) > 2) {
                    uptimeSeconds = serverUptime;
                    uptimeSyncedAt = Date.now();
                    tickUptime();
                }
            }
        }

        var cpuUsage = data.cpu_usage || 0;
        setIfChanged(el.cpuUsageText, 'cpu_usage', cpuUsage + '%');
        var cpuBarWidth = Math.min(100, Math.max(0, cpuUsage)) + '%';
        if (lastValues.cpu_bar_w !== cpuBarWidth) {
            lastValues.cpu_bar_w = cpuBarWidth;
            el.cpuUsageBar.style.width = cpuBarWidth;
        }
        var cpuBarClass = 'progress-fill ' + barClass(cpuUsage);
        if (lastValues.cpu_bar_c !== cpuBarClass) {
            lastValues.cpu_bar_c = cpuBarClass;
            el.cpuUsageBar.className = cpuBarClass;
        }

        var mem = data.memory || {};
        setIfChanged(el.memUsed, 'mem_used', mem.used_formatted || '--');
        setIfChanged(el.memTotal, 'mem_total', <?php echo json_encode(t('admin_sys_mem_total_pre', '总共 ')); ?> + (mem.total_formatted || '--') + <?php echo json_encode(t('admin_sys_mem_total_avail', '，可用 ')); ?> + (mem.available_formatted || '--'));
        setIfChanged(el.memUsageText, 'mem_pct', (mem.usage_percent || 0) + '%');
        var memBarWidth = Math.min(100, Math.max(0, mem.usage_percent || 0)) + '%';
        if (lastValues.mem_bar_w !== memBarWidth) {
            lastValues.mem_bar_w = memBarWidth;
            el.memUsageBar.style.width = memBarWidth;
        }
        var memBarClass = 'progress-fill ' + barClass(mem.usage_percent || 0);
        if (lastValues.mem_bar_c !== memBarClass) {
            lastValues.mem_bar_c = memBarClass;
            el.memUsageBar.className = memBarClass;
        }

        var net = data.network || {};
        setIfChanged(el.netDownloadSpeed, 'net_down', net.download_speed_formatted || '-- B/s');
        setIfChanged(el.netUploadSpeed, 'net_up', net.upload_speed_formatted || '-- B/s');
        setIfChanged(el.netTotal, 'net_total', <?php echo json_encode(t('admin_sys_net_total_pre', '累计：↓ ')); ?> + (net.total_rx_formatted || '--') + <?php echo json_encode(t('admin_sys_net_total_sep', ' / ↑ ')); ?> + (net.total_tx_formatted || '--'));

        // 磁盘分区使用率（动态刷新）
        var disks = data.disks || [];
        if (disks.length > 0) {
            var disksHtml = '';
            for (var i = 0; i < disks.length; i++) {
                var d = disks[i];
                disksHtml += '<div class="disk-item">' +
                    '<div class="disk-header">' +
                        '<span class="disk-device">' + escapeHtml(d.device) + '</span>' +
                        '<span class="disk-meta">' + <?php echo json_encode(t('admin_sys_disk_used', '已用 ')); ?> + d.usage_percent + '%' + <?php echo json_encode(t('admin_sys_disk_paren_l', '（')); ?> +
                            d.used_formatted + <?php echo json_encode(t('admin_sys_disk_sep', ' / ')); ?> + d.size_formatted + <?php echo json_encode(t('admin_sys_disk_paren_r', '）')); ?> + '</span>' +
                    '</div>' +
                    '<div class="progress-track">' +
                        '<div class="progress-fill ' + barClass(d.usage_percent) + '" ' +
                        'style="width:' + Math.min(100, d.usage_percent) + '%"></div>' +
                    '</div></div>';
            }
            setIfChanged(el.diskPartitions, 'disks', disksHtml, true);
        }

        // 系统负载平均值
        var la = data.load_average || {};
        setIfChanged(el.loadAvg1, 'la1', String(la.load_1 ?? '--'));
        setIfChanged(el.loadAvg5, 'la5', String(la.load_5 ?? '--'));
        setIfChanged(el.loadAvg15, 'la15', String(la.load_15 ?? '--'));

        // 服务器时间
        var st = data.server_time || {};
        setIfChanged(el.serverTime, 'stime', st.datetime || '--');
        setIfChanged(el.serverTz, 'stz', <?php echo json_encode(t('admin_sys_tz_pre', '时区：')); ?> + (st.timezone || '--') + ' (UTC' + (st.offset || '?') + ')');
        setIfChanged(el.serverTzAbbr, 'tzabbr', st.timezone_abbr || '--');

        // 数据库轻量统计（动态端点不包含逐表数据）
        var dbs = data.db_stats || {};
        setIfChanged(el.dbSize, 'dbsize', dbs.db_size_formatted || '--');
        setIfChanged(el.dbWalSize, 'dbwal', dbs.wal_size > 0 ? dbs.wal_size_formatted : <?php echo json_encode(t('admin_sys_none', '无')); ?>);
        setIfChanged(el.dbTableCount, 'dbtc', String(dbs.table_count || 0));
        setIfChanged(el.dbTotalRows, 'dbtr', '--');  // 动态端点不含行数
        setIfChanged(el.dbJournalMode, 'dbjm', <?php echo json_encode(t('admin_sys_mode_pre', '模式：')); ?> + (dbs.journal_mode || '--'));

        el.lastUpdate.textContent = <?php echo json_encode(t('admin_sys_last_update', '上次更新：')); ?> + new Date().toLocaleTimeString();
        el.lastUpdate.classList.add('flash');
        setTimeout(function () { el.lastUpdate.classList.remove('flash'); }, 300);
    }

    // 渲染静态扩展信息（GPU/主板/PHP/网络接口/数据库表/电池）
    function renderStaticExtended(data) {
        if (!data || !data.success) return;

        // 电池状态（有电池才显示）
        var bat = data.battery;
        if (bat && bat.present) {
            el.batteryCard.style.display = '';
            setIfChanged(el.batteryPercent, 'batpct', bat.percent + '%');
            setIfChanged(el.batteryStatus, 'batstat', bat.status || '--');
            var bw = Math.min(100, Math.max(0, bat.percent)) + '%';
            if (lastValues.bat_bar_w !== bw) {
                lastValues.bat_bar_w = bw;
                el.batteryBar.style.width = bw;
            }
            var bc = 'progress-fill ' + barClass(bat.percent);
            if (lastValues.bat_bar_c !== bc) {
                lastValues.bat_bar_c = bc;
                el.batteryBar.className = bc;
            }
        }

        // PHP 详细信息
        var pi = data.php_info;
        if (pi) {
            var opcacheHtml = '';
            if (pi.opcache && pi.opcache.enabled) {
                var op = pi.opcache;
                opcacheHtml = '<div style="margin-top:0.5rem;padding:0.625rem 0.875rem;background:var(--surface-3);border-radius:8px;">' +
                    '<div style="font-weight:600;margin-bottom:0.25rem;color:#10b981;">' + <?php echo json_encode(t('admin_sys_opcache_on', 'OPcache 已启用')); ?> + '</div>' +
                    '<div style="font-size:0.8rem;color:var(--text-muted);">' +
                        <?php echo json_encode(t('admin_sys_opcache_mem', '内存：')); ?> + escapeHtml(op.memory_used) + ' / ' + escapeHtml(op.memory_free) + ' · ' +
                        <?php echo json_encode(t('admin_sys_opcache_hit', '命中：')); ?> + op.hits + ' · ' + <?php echo json_encode(t('admin_sys_opcache_miss', '失败：')); ?> + op.misses + ' · ' +
                        <?php echo json_encode(t('admin_sys_opcache_hitrate', '命中率：')); ?> + '<strong>' + op.hit_rate + '%</strong> · ' + <?php echo json_encode(t('admin_sys_opcache_scripts', '脚本：')); ?> + op.scripts +
                    '</div></div>';
            } else {
                opcacheHtml = '<div style="margin-top:0.5rem;font-size:0.8rem;color:var(--text-muted);">' + <?php echo json_encode(t('admin_sys_opcache_off', 'OPcache：未启用')); ?> + '</div>';
            }
            var phpHtml = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.875rem;font-size:0.875rem;line-height:1.8;">' +
                '<div><strong>' + <?php echo json_encode(t('admin_sys_php_ver', 'PHP 版本：')); ?> + '</strong>' + escapeHtml(pi.version) + '</div>' +
                '<div><strong>' + <?php echo json_encode(t('admin_sys_php_sapi', 'SAPI：')); ?> + '</strong>' + escapeHtml(pi.sapi_name) + ' (' + escapeHtml(pi.sapi) + ')</div>' +
                '<div><strong>' + <?php echo json_encode(t('admin_sys_php_mem', '内存限制：')); ?> + '</strong>' + escapeHtml(pi.memory_limit) + '</div>' +
                '<div><strong>' + <?php echo json_encode(t('admin_sys_php_maxexec', '最大执行时间：')); ?> + '</strong>' + escapeHtml(pi.max_execution) + '</div>' +
                '<div><strong>' + <?php echo json_encode(t('admin_sys_php_upload', '上传限制：')); ?> + '</strong>' + escapeHtml(pi.upload_max) + '</div>' +
                '<div><strong>' + <?php echo json_encode(t('admin_sys_php_post', 'POST 限制：')); ?> + '</strong>' + escapeHtml(pi.post_max) + '</div>' +
                '<div><strong>' + <?php echo json_encode(t('admin_sys_php_tz', '时区：')); ?> + '</strong>' + escapeHtml(pi.timezone) + '</div>' +
                '<div><strong>' + <?php echo json_encode(t('admin_sys_php_display', '错误显示：')); ?> + '</strong>' + escapeHtml(pi.display_errors) + '</div>' +
                '<div><strong>' + <?php echo json_encode(t('admin_sys_php_errlevel', '错误级别：')); ?> + '</strong>' + escapeHtml(pi.error_reporting) + '</div>' +
                '<div><strong>' + <?php echo json_encode(t('admin_sys_php_extcount', '扩展数：')); ?> + '</strong>' + pi.extensions_count + <?php echo json_encode(t('admin_sys_php_extcount_unit', ' 个')); ?> + '</div>' +
                '<div><strong>' + <?php echo json_encode(t('admin_sys_php_realpath', 'realpath 缓存：')); ?> + '</strong>' + escapeHtml(pi.realpath_cache) + '</div>' +
            '</div>' + opcacheHtml +
            '<details style="margin-top:0.75rem;"><summary style="cursor:pointer;font-size:0.8125rem;color:var(--text-muted);">' + <?php echo json_encode(t('admin_sys_loaded_ext', '已加载扩展（点击展开）')); ?> + '</summary>' +
                '<div style="margin-top:0.5rem;font-size:0.75rem;color:var(--text-muted);line-height:1.8;">' +
                (pi.extensions || []).map(function (x) { return '<code style="display:inline-block;margin:2px 4px;padding:1px 6px;background:var(--surface-3);border-radius:3px;">' + escapeHtml(x) + '</code>'; }).join('') +
                '</div></details>';
            setIfChanged(el.phpInfoPanel, 'phpinfo', phpHtml, true);
        }

        // GPU 信息
        var gpus = data.gpu_info || [];
        if (gpus.length > 0) {
            var gpuHtml = '';
            for (var i = 0; i < gpus.length; i++) {
                var g = gpus[i];
                var badge = g.is_integrated ? '<span class="hw-disk-badge hdd<?php echo addslashes(t('admin_system_status_9fe2c4','>\' + <?php echo json_encode(t(\'admin_sys_gpu_integrated\', \'集成\')); ?> + \'</span>\' : \'<span class=')); ?>hw-disk-badge ssd">' + <?php echo json_encode(t('admin_sys_gpu_dedicated', '独显')); ?> + '</span>';
                gpuHtml += '<div class="hw-disk-item">' +
                    badge +
                    '<span class="hw-disk-model">' + escapeHtml(g.name) + '</span>' +
                    '<span class="hw-disk-meta">' + <?php echo json_encode(t('admin_sys_gpu_vram', '显存：')); ?> + escapeHtml(g.ram_formatted) +
                    (g.driver ? <?php echo json_encode(t('admin_sys_gpu_driver', ' · 驱动 ')); ?> + escapeHtml(g.driver) : '') + '</span></div>';
            }
            setIfChanged(el.gpuInfoPanel, 'gpu', gpuHtml, true);
        } else {
            setIfChanged(el.gpuInfoPanel, 'gpu', '<p class="text-muted text-center py-2">' + <?php echo json_encode(t('admin_sys_no_gpu', '未能获取显卡信息。')); ?> + '</p>', true);
        }

        // 主板信息
        var mb = data.motherboard || {};
        var mbHtml = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.875rem;font-size:0.875rem;line-height:1.8;">' +
            '<div><strong>' + <?php echo json_encode(t('admin_sys_mb_maker', '制造商：')); ?> + '</strong>' + escapeHtml(mb.manufacturer || '--') + '</div>' +
            '<div><strong>' + <?php echo json_encode(t('admin_sys_mb_product', '产品型号：')); ?> + '</strong>' + escapeHtml(mb.product || '--') + '</div>' +
            '<div><strong>' + <?php echo json_encode(t('admin_sys_mb_version', '版本：')); ?> + '</strong>' + escapeHtml(mb.version || '--') + '</div>' +
            '<div><strong>' + <?php echo json_encode(t('admin_sys_mb_serial', '序列号：')); ?> + '</strong>' + escapeHtml(mb.serial || '--') + '</div>' +
            '<div><strong>' + <?php echo json_encode(t('admin_sys_mb_bios_maker', 'BIOS 厂商：')); ?> + '</strong>' + escapeHtml(mb.bios_maker || '--') + '</div>' +
            '<div><strong>' + <?php echo json_encode(t('admin_sys_mb_bios_ver', 'BIOS 版本：')); ?> + '</strong>' + escapeHtml(mb.bios_version || '--') + '</div>' +
            '</div>';
        // 加入操作系统信息
        var osi = data.os_info || {};
        if (osi.php_uname) {
            mbHtml += '<div style="margin-top:0.875rem;padding-top:0.875rem;border-top:1px solid var(--border);font-size:0.8125rem;color:var(--text-muted);">' +
                '<strong>' + <?php echo json_encode(t('admin_sys_hostname', '主机名：')); ?> + '</strong>' + escapeHtml(osi.hostname || '--') + ' · ' +
                '<strong>' + <?php echo json_encode(t('admin_sys_os', '系统：')); ?> + '</strong>' + escapeHtml(osi.php_uname) + '</div>';
        }
        setIfChanged(el.motherboardPanel, 'mb', mbHtml, true);

        // 网络接口
        var nics = data.network_interfaces || [];
        if (nics.length > 0) {
            var nicHtml = '';
            for (var i = 0; i < nics.length; i++) {
                var n = nics[i];
                nicHtml += '<div class="hw-disk-item">' +
                    '<span class="hw-disk-badge ' + (n.enabled ? 'ssd' : 'hdd') + '">' + (n.enabled ? <?php echo json_encode(t('admin_sys_nic_connected', '已连接')); ?> : <?php echo json_encode(t('admin_sys_nic_disconnected', '未连接')); ?>) + '</span>' +
                    '<span class="hw-disk-model">' + escapeHtml(n.name) + '</span>' +
                    '<span class="hw-disk-meta">' + <?php echo json_encode(t('admin_sys_nic_ip', 'IP：')); ?> + escapeHtml(n.ip || '--') + <?php echo json_encode(t('admin_sys_nic_mac', ' · MAC：')); ?> + escapeHtml(n.mac || '--') +
                    (n.manufacturer ? <?php echo json_encode(t('admin_sys_nic_manuf', ' · ')); ?> + escapeHtml(n.manufacturer) : '') + '</span></div>';
            }
            setIfChanged(el.networkInterfacesPanel, 'nics', nicHtml, true);
        } else {
            setIfChanged(el.networkInterfacesPanel, 'nics', '<p class="text-muted text-center py-2">' + <?php echo json_encode(t('admin_sys_no_nic', '未能获取网络接口信息。')); ?> + '</p>', true);
        }

        // 数据库表 Top15（使用静态端点的详细数据）
        var dbFull = data.db_stats_full;
        var tables = (dbFull && dbFull.tables) || [];
        if (tables.length > 0) {
            var maxRows = tables[0].rows || 1;
            var tablesHtml = '';
            for (var i = 0; i < tables.length; i++) {
                var t = tables[i];
                var pct = maxRows > 0 ? Math.round(t.rows / maxRows * 100) : 0;
                tablesHtml += '<div class="disk-item">' +
                    '<div class="disk-header">' +
                        '<span class="disk-device">' + escapeHtml(t.name) + '</span>' +
                        '<span class="disk-meta">' + t.rows.toLocaleString() + <?php echo json_encode(t('admin_sys_db_row_unit', ' 行')); ?> + '</span>' +
                    '</div>' +
                    '<div class="progress-track" style="height:0.375rem;">' +
                        '<div class="progress-fill low" style="width:' + pct + '%"></div>' +
                    '</div></div>';
            }
            setIfChanged(el.dbTablesPanel, 'tables', tablesHtml, true);
        } else {
            setIfChanged(el.dbTablesPanel, 'tables', '<p class="text-muted text-center py-2">' + <?php echo json_encode(t('admin_sys_no_tables', '暂无表数据。')); ?> + '</p>', true);
        }
    }

    // 渲染温度数据（独立渲染，不阻塞动态数据）
    function renderTemps(data) {
        if (!data || !data.success) return;

        var temps = data.temperatures || [];
        if (temps.length > 0) {
            var tempsHtml = '<ul class="temp-list">';
            for (var i = 0; i < temps.length; i++) {
                var t = temps[i];
                var temp = parseFloat(t.temp) || 0;
                tempsHtml += '<li class="temp-item">' +
                    '<span class="temp-name">' + escapeHtml(t.name) + '</span>' +
                    '<span class="temp-value ' + tempClass(temp) + '">' + temp.toFixed(1) + ' ' + escapeHtml(t.unit || <?php echo json_encode(t('admin_sys_unit_c', '°C')); ?>) + '</span></li>';
            }
            tempsHtml += '</ul>';
            setIfChanged(el.tempList, 'temps', tempsHtml, true);
            setIfChanged(el.tempCount, 'temp_count', temps.length + <?php echo json_encode(t('admin_sys_sensor_unit', ' 个传感器')); ?>);
        } else {
            setIfChanged(el.tempList, 'temps', '<p class="text-muted text-center py-2">' + <?php echo json_encode(t('admin_sys_no_temp', '未能获取温度数据（需硬件支持或安装 OpenHardwareMonitor）。')); ?> + '</p>', true);
            setIfChanged(el.tempCount, 'temp_count', '0' + <?php echo json_encode(t('admin_sys_sensor_unit', ' 个传感器')); ?>);
        }
    }

    function fetchStatic() {
        if (isFetchingStatic) return;
        isFetchingStatic = true;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', API_URL + '&static=1&_=' + Date.now(), true);
        xhr.timeout = 30000;
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            isFetchingStatic = false;
            if (xhr.status !== 200) {
                var debugMsg = '静态数据请求失败 (HTTP ' + xhr.status + ')' +
                    '\n请求URL: ' + API_URL + '&static=1' +
                    '\n页面URL: ' + window.location.href +
                    '\n响应前200字: ' + xhr.responseText.substring(0, 200);
                console.error('[SS-DEBUG] static error:\n' + debugMsg);
                showStaticError(<?php echo json_encode(t('admin_sys_static_fail_pre', '静态数据请求失败 (HTTP ')); ?> + xhr.status + '<br><small>' + <?php echo json_encode(t('admin_sys_static_fail_url', 'URL: ')); ?> + escapeHtml(API_URL) + '<br>响应: ' + escapeHtml(xhr.responseText.substring(0, 200)) + '</small>');
                return;
            }
            try {
                renderStatic(JSON.parse(xhr.responseText));
            } catch (e) {
                // 显示前 200 字符响应内容，方便诊断
                var preview = xhr.responseText.substring(0, 200);
                showStaticError(<?php echo json_encode(t('admin_sys_static_parse_fail', '静态数据解析失败：')); ?> + preview);
            }
        };
        xhr.ontimeout = function () {
            isFetchingStatic = false;
            showStaticError(<?php echo json_encode(t('admin_sys_static_timeout', '静态数据请求超时（30 秒），可能是 WMI 查询较慢')); ?>);
        };
        xhr.onerror = function () {
            isFetchingStatic = false;
            showStaticError(<?php echo json_encode(t('admin_sys_static_error', '静态数据请求出错')); ?>);
        };
        xhr.send();
    }

    // 显示静态数据加载错误提示（替代"正在加载…"）
    function showStaticError(msg) {
        var panels = ['memoryBanks', 'diskHardware', 'phpInfoPanel', 'gpuInfoPanel', 'motherboardPanel', 'networkInterfacesPanel', 'dbTablesPanel'];
        var html = '<p class="text-muted text-center py-2" style="color:#ef4444;">' + escapeHtml(msg) + '</p>';
        for (var i = 0; i < panels.length; i++) {
            var el2 = document.getElementById(panels[i]);
            if (el2 && el2.textContent === ADMIN_SYS_LOADING_TEXT) {
                el2.innerHTML = html;
            }
        }
    }

    function fetchDynamic() {
        if (isFetchingDynamic) return;
        isFetchingDynamic = true;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', API_URL + '&_=' + Date.now(), true);
        xhr.timeout = 8000;
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            isFetchingDynamic = false;
            if (xhr.status !== 200) {
                var dynDebug = '动态数据请求失败 (HTTP ' + xhr.status + ')' +
                    '\n请求URL: ' + API_URL +
                    '\n页面URL: ' + window.location.href +
                    '\n响应前200字: ' + xhr.responseText.substring(0, 200);
                console.error('[SS-DEBUG] dynamic error:\n' + dynDebug);
                el.lastUpdate.innerHTML = <?php echo json_encode(t('admin_sys_req_fail_pre', '请求失败 (HTTP ')); ?> + xhr.status + '<br><small>' + escapeHtml(API_URL) + '</small>';
                return;
            }
            try { renderDynamic(JSON.parse(xhr.responseText)); } catch (e) {
                el.lastUpdate.textContent = <?php echo json_encode(t('admin_sys_parse_fail', '数据解析失败')); ?>;
            }
        };
        xhr.ontimeout = function () { isFetchingDynamic = false; el.lastUpdate.textContent = <?php echo json_encode(t('admin_sys_req_timeout', '请求超时')); ?>; };
        xhr.onerror = function () { isFetchingDynamic = false; el.lastUpdate.textContent = <?php echo json_encode(t('admin_sys_net_error', '网络错误')); ?>; };
        xhr.send();
    }

    function fetchTemps() {
        if (isFetchingTemp) return;
        isFetchingTemp = true;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', API_URL + '&temps=1&_=' + Date.now(), true);
        xhr.timeout = 10000;
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            isFetchingTemp = false;
            if (xhr.status !== 200) return;
            try { renderTemps(JSON.parse(xhr.responseText)); } catch (e) {}
        };
        xhr.ontimeout = function () { isFetchingTemp = false; };
        xhr.onerror = function () { isFetchingTemp = false; };
        xhr.send();
    }

    function scheduleDynamic() {
        dynamicTimer = setTimeout(function () {
            fetchDynamic();
            if (!document.hidden) scheduleDynamic();
        }, DYNAMIC_INTERVAL);
    }

    function scheduleTemps() {
        tempTimer = setTimeout(function () {
            fetchTemps();
            if (!document.hidden) scheduleTemps();
        }, TEMP_INTERVAL);
    }

    // 首次加载：3 个请求并行发出，各自独立渲染
    fetchDynamic();      // 动态数据（最高优先级，最快返回）
    fetchStatic();       // 静态数据（较慢，但不阻塞动态）
    fetchTemps();        // 温度（最慢，独立加载）

    // 启动轮询
    scheduleDynamic();
    scheduleTemps();

    // 页面不可见时停止所有轮询
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (dynamicTimer) { clearTimeout(dynamicTimer); dynamicTimer = null; }
            if (tempTimer) { clearTimeout(tempTimer); tempTimer = null; }
            if (uptimeTimer) { clearInterval(uptimeTimer); uptimeTimer = null; }
        } else {
            if (!staticLoaded) fetchStatic();
            fetchDynamic();
            fetchTemps();
            scheduleDynamic();
            scheduleTemps();
            startUptimeTicker();
        }
    });
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
