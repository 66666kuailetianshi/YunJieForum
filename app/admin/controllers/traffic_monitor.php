<?php
/**
 * 云界论坛管理后台 - 流量监测
 * 实时展示 PV/UV、24H 分布、7 天趋势、来源、设备、浏览器、热门页面等
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：流量监测仅超级管理员可用
require_super_admin();

$pageTitle = t('admin_traffic_title', '流量监测');
$activeMenu = 'traffic_monitor';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo e(t('admin_traffic_title', '流量监测')); ?></h1>
        <p class="page-subtitle"><?php echo e(t('admin_traffic_subtitle', '实时追踪网站访问数据，每 5 秒自动刷新')); ?></p>
    </div>
    <div class="page-tools">
        <span id="refresh-indicator" class="tm-indicator">
            <span id="refresh-dot" class="tm-dot"></span>
            <span id="last-refresh">--</span>
        </span>
    </div>
</div>

<!-- 概览卡片 -->
<div class="stats-grid mb-6">
    <div class="stat-card">
        <div class="stat-card-value" id="stat-online">0</div>
        <div class="stat-card-label"><?php echo e(t('admin_traffic_online', '当前在线')); ?></div>
        <div class="tm-sub"><?php echo e(t('admin_traffic_online_sub', '实时访客')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" id="stat-today-pv">0</div>
        <div class="stat-card-label"><?php echo e(t('admin_traffic_today_pv', '今日 PV')); ?></div>
        <div class="tm-sub" id="stat-today-pv-vs">--</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" id="stat-today-uv">0</div>
        <div class="stat-card-label"><?php echo e(t('admin_traffic_today_uv', '今日 UV')); ?></div>
        <div class="tm-sub" id="stat-today-uv-vs">--</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" id="stat-hour-pv">0</div>
        <div class="stat-card-label"><?php echo e(t('admin_traffic_hour_pv', '当前小时 PV')); ?></div>
        <div class="tm-sub" id="stat-hour-label">--</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" id="stat-total-pv">0</div>
        <div class="stat-card-label"><?php echo e(t('admin_traffic_total_pv', '累计 PV')); ?></div>
        <div class="tm-sub">--</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" id="stat-total-uv">0</div>
        <div class="stat-card-label"><?php echo e(t('admin_traffic_total_uv', '累计 UV')); ?></div>
        <div class="tm-sub">--</div>
    </div>
</div>

<!-- 图表区 -->
<div class="tm-grid-2">
    <!-- 24 小时分布 -->
    <div class="card">
        <h2 class="card-title mb-1"><?php echo e(t('admin_traffic_hourly_title', '24 小时访问分布')); ?></h2>
        <p class="text-muted mb-2 text-xs">
            <span class="tm-swatch-pv"></span> <?php echo e(t('admin_traffic_legend_pv_today', '今日 PV')); ?>
            <span class="tm-swatch-uv"></span> <?php echo e(t('admin_traffic_legend_uv_today', '今日 UV')); ?>
            <span class="tm-swatch-dash"></span> <?php echo e(t('admin_traffic_legend_pv_yesterday', '昨日 PV')); ?>
        </p>
        <canvas id="chart-hourly" height="240" class="tm-chart"></canvas>
    </div>

    <!-- 7 天趋势 -->
    <div class="card">
        <h2 class="card-title mb-1"><?php echo e(t('admin_traffic_daily_title', '近 7 天趋势')); ?></h2>
        <p class="text-muted mb-2 text-xs">
            <span class="tm-swatch-pv"></span> PV
            <span class="tm-swatch-uv"></span> UV
        </p>
        <canvas id="chart-daily" height="240" class="tm-chart"></canvas>
    </div>
</div>

<!-- 分析区 -->
<div class="tm-grid-2">

    <!-- 流量来源 -->
    <div class="card">
        <h2 class="card-title mb-1"><?php echo e(t('admin_traffic_referrer', '流量来源')); ?></h2>
        <div id="referrer-list" class="text-sm"></div>
    </div>

    <!-- 设备分布 -->
    <div class="card">
        <h2 class="card-title mb-1"><?php echo e(t('admin_traffic_device', '设备分布')); ?></h2>
        <div id="device-bars" class="tm-bars"></div>
    </div>

    <!-- 浏览器 -->
    <div class="card">
        <h2 class="card-title mb-1"><?php echo e(t('admin_traffic_browser', '浏览器分布')); ?></h2>
        <div id="browser-bars" class="tm-bars"></div>
    </div>

    <!-- 操作系统 -->
    <div class="card">
        <h2 class="card-title mb-1"><?php echo e(t('admin_traffic_os', '操作系统分布')); ?></h2>
        <div id="os-bars" class="tm-bars"></div>
    </div>

</div>

<!-- 热门页面 -->
<div class="card mb-6">
    <h2 class="card-title mb-1"><?php echo e(t('admin_traffic_hot_title', '今日热门页面 TOP10')); ?></h2>
    <div class="table-responsive">
        <table class="data-table data-table-compact">
            <thead>
                <tr><th class="col-number">#</th><th><?php echo e(t('admin_traffic_th_path', '页面路径')); ?></th><th class="col-number"><?php echo e(t('admin_traffic_th_views', '访问量')); ?></th><th class="col-number"><?php echo e(t('admin_traffic_th_ratio', '占比')); ?></th><th><?php echo e(t('admin_traffic_th_heat', '热度')); ?></th></tr>
            </thead>
            <tbody id="hot-pages-body"></tbody>
        </table>
    </div>
</div>

<!-- 最近访客 -->
<div class="card">
    <h2 class="card-title mb-1"><?php echo e(t('admin_traffic_recent_title', '最近访客')); ?></h2>
    <div class="table-responsive">
        <table class="data-table data-table-compact">
            <thead>
                <tr><th><?php echo e(t('admin_traffic_th_visitor', '访客标识')); ?></th><th><?php echo e(t('admin_traffic_th_device', '设备')); ?></th><th><?php echo e(t('admin_traffic_th_system', '系统')); ?></th><th><?php echo e(t('admin_traffic_th_browser', '浏览器')); ?></th><th><?php echo e(t('admin_traffic_th_page', '访问页面')); ?></th><th class="col-number"><?php echo e(t('admin_traffic_th_browsecount', '浏览次数')); ?></th><th class="col-time"><?php echo e(t('admin_traffic_th_lastactive', '最后活跃')); ?></th></tr>
            </thead>
            <tbody id="recent-visitors-body"></tbody>
        </table>
    </div>
    <div id="recent-visitors-pagination" class="tm-pag-pad"></div>
</div>

<script>
(function() {
    var REFRESH_MS = 5000;
    var autoTimer = null;
    var prevData = {};
    var fetching = false;      // 请求去重：正在请求中时不发新请求
    var lastDataHash = '';     // 快速比对数据是否变化，避免无意义的重绘
    var recentPage = 1;        // 最近访客当前页码
    // ========== 图表渲染 ==========

    function drawHourlyChart(data) {
        var canvas = document.getElementById('chart-hourly');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var dpr = window.devicePixelRatio || 1;
        var w = canvas.offsetWidth;     // 跟随 CSS，不强制设置宽度
        var h = 240;

        // 仅在尺寸变化时重置 Canvas（重置内部像素缓冲区）
        if (canvas._lastW !== w || canvas._lastH !== h) {
            canvas._lastW = w;
            canvas._lastH = h;
            canvas.width = w * dpr;
            canvas.height = h * dpr;
        }

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);  // 绝对变换，不累积
        ctx.clearRect(0, 0, w, h);

        var pv = data.hourly_pv || [];
        var uv = data.hourly_uv || [];
        var ypv = data.yesterday_hourly_pv || [];
        var maxVal = Math.max.apply(null, pv.concat(ypv)) || 1;
        maxVal = Math.ceil(maxVal * 1.2);

        var pad = { top: 15, right: 20, bottom: 28, left: 40 };
        var cw = w - pad.left - pad.right;
        var ch = h - pad.top - pad.bottom;
        var barW = (cw / 24) * 0.7;
        var gap = (cw / 24) * 0.15;

        // 网格线
        ctx.strokeStyle = '#e5e7eb';
        ctx.lineWidth = 0.5;
        for (var i = 0; i <= 4; i++) {
            var y = pad.top + (ch / 4) * i;
            ctx.beginPath();
            ctx.moveTo(pad.left, y);
            ctx.lineTo(w - pad.right, y);
            ctx.stroke();
            ctx.fillStyle = '#9ca3af';
            ctx.font = '10px system-ui';
            ctx.fillText(Math.round(maxVal - (maxVal / 4) * i), 2, y + 3);
        }

        // 柱状图
        for (var i = 0; i < 24; i++) {
            var x = pad.left + (cw / 24) * i + gap;
            var showLabel = (i === 0 || i === 6 || i === 12 || i === 18 || i === 23);

            // 昨日 PV 虚线
            if (ypv[i] > 0) {
                var yh = pad.top + ch - (ypv[i] / maxVal) * ch;
                var xc = x + barW / 2;
                ctx.strokeStyle = '#9ca3af';
                ctx.lineWidth = 1;
                ctx.setLineDash([3, 3]);
                ctx.beginPath();
                ctx.moveTo(xc, yh - 3);
                ctx.lineTo(xc, yh);
                ctx.stroke();
                ctx.setLineDash([]);
                ctx.fillStyle = '#9ca3af';
                ctx.beginPath();
                ctx.arc(xc, yh, 2.5, 0, Math.PI * 2);
                ctx.fill();
            }

            // PV 柱
            if (pv[i] > 0) {
                var barH = (pv[i] / maxVal) * ch;
                ctx.fillStyle = i === (new Date()).getHours() ? '#3b82f6' : '#60a5fa';
                ctx.fillRect(x, pad.top + ch - barH, barW, barH);
            }
            // UV 柱
            if (uv[i] > 0) {
                var barUvH = (uv[i] / maxVal) * ch;
                ctx.fillStyle = '#86efac';
                ctx.fillRect(x + barW + 1, pad.top + ch - barUvH, barW * 0.5, barUvH);
            }

            // X 轴标签
            if (showLabel) {
                ctx.fillStyle = '#6b7280';
                ctx.font = '10px system-ui';
                ctx.textAlign = 'center';
                ctx.fillText(i + ':00', x + barW / 2, h - 4);
            }
        }
    }

    function drawDailyChart(data) {
        var canvas = document.getElementById('chart-daily');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var dpr = window.devicePixelRatio || 1;
        var w = canvas.offsetWidth;
        var h = 240;

        if (canvas._lastW !== w || canvas._lastH !== h) {
            canvas._lastW = w;
            canvas._lastH = h;
            canvas.width = w * dpr;
            canvas.height = h * dpr;
        }

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);

        var daily = data.daily || [];
        var rawMax = 0;
        for (var i = 0; i < daily.length; i++) {
            rawMax = Math.max(rawMax, daily[i].pv, daily[i].uv);
        }
        rawMax = Math.max(1, Math.ceil((rawMax || 1) * 1.15));
        // 取一个“漂亮”的 Y 轴上限，确保刻度是均匀整数
        var steps = 5;
        var step = Math.ceil(rawMax / steps);
        var niceStep = step <= 1 ? 1 : (step <= 5 ? 5 : (step <= 10 ? 10 : Math.ceil(step / 10) * 10));
        var maxVal = niceStep * steps;

        var pad = { top: 15, right: 20, bottom: 28, left: 40 };
        var cw = w - pad.left - pad.right;
        var ch = h - pad.top - pad.bottom;
        var n = daily.length || 7;
        // 柱子宽度按 7 天密度计算，避免数据少时柱子过粗；同时不超过单格 55%
        var slotW = cw / Math.max(n, 7);
        var barW = Math.min(slotW * 0.55, (cw / n) * 0.55);
        var gap = (cw / n) - barW;

        // 网格 + Y 轴刻度（均匀整数）
        ctx.strokeStyle = '#e5e7eb';
        ctx.lineWidth = 0.5;
        for (var i = 0; i <= steps; i++) {
            var y = pad.top + (ch / steps) * i;
            ctx.beginPath();
            ctx.moveTo(pad.left, y);
            ctx.lineTo(w - pad.right, y);
            ctx.stroke();
            ctx.fillStyle = '#9ca3af';
            ctx.font = '10px system-ui';
            ctx.textAlign = 'right';
            ctx.fillText(String(maxVal - niceStep * i), pad.left - 6, y + 3);
        }

        var today = (new Date()).toISOString().slice(0, 10);
        for (var i = 0; i < daily.length; i++) {
            var d = daily[i];
            var x = pad.left + (cw / n) * i + gap / 2;
            var isToday = d.date === today;

            // PV
            if (d.pv > 0) {
                var bh = (d.pv / maxVal) * ch;
                ctx.fillStyle = isToday ? '#3b82f6' : '#93c5fd';
                ctx.fillRect(x, pad.top + ch - bh, barW, bh);
            }
            // UV
            if (d.uv > 0) {
                var uvH = (d.uv / maxVal) * ch;
                ctx.fillStyle = isToday ? '#22c55e' : '#86efac';
                ctx.fillRect(x + barW + 2, pad.top + ch - uvH, barW * 0.55, uvH);
            }

            // 日期标签（隔一天显示一个，避免拥挤；数据少于 10 天全显示）
            ctx.fillStyle = '#6b7280';
            ctx.font = '10px system-ui';
            ctx.textAlign = 'center';
            if (n <= 10 || i % 2 === 0) {
                ctx.fillText(d.date.slice(5), x + barW / 2, h - 4);
            }
        }
    }

    // ========== 进度条辅助 ==========

    function maxVal(arr, key) {
        var m = 0;
        for (var i = 0; i < arr.length; i++) { m = Math.max(m, arr[i][key] || 0); }
        return m || 1;
    }

    function renderBars(containerId, items, key, getLabel) {
        var el = document.getElementById(containerId);
        if (!el) return;
        var max = maxVal(items, key);
        var html = '';
        var colors = ['#3b82f6', '#8b5cf6', '#06b6d4', '#10b981', '#f59e0b', '#ef4444', '#ec4899', '#6366f1', '#14b8a6', '#84cc16'];
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var val = item[key] || 0;
            var pct = max > 0 ? Math.round(val / max * 100) : 0;
            var label = getLabel ? getLabel(item) : (item.name || item.source || item.label || '-');
            html += '<div class="bar-label"><span>' + e(label) + '</span><span style="color:var(--muted);">' + val + '</span></div>';
            html += '<div style="background:#f3f4f6;border-radius:4px;"><div class="bar-fill" style="width:' + pct + '%;background:' + colors[i % colors.length] + ';"></div></div>';
        }
        if (items.length === 0) {
            html = '<p class="text-muted" style="font-size:0.85rem;"><?php echo e(t('admin_traffic_no_data', '暂无数据')); ?></p>';
        }
        el.innerHTML = html;
    }

    function e(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // ========== 渲染热页表格 ==========

    function renderHotPages(pages) {
        var tbody = document.getElementById('hot-pages-body');
        if (!tbody) return;
        var html = '';
        var totalViews = 0;
        for (var i = 0; i < pages.length; i++) { totalViews += pages[i].views; }
        if (pages.length === 0) {
            html = '<tr><td colspan="5" style="text-align:center;color:var(--muted);"><?php echo e(t('admin_traffic_no_pages', '今日暂无访问数据')); ?></td></tr>';
        }
        for (var i = 0; i < pages.length; i++) {
            var p = pages[i];
            var pct = totalViews > 0 ? Math.round(p.views / totalViews * 100) : 0;
            var heatW = Math.max(5, pct * 2);
            html += '<tr>';
            html += '<td>' + (i + 1) + '</td>';
            html += '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + e(p.page) + '">' + e(p.page) + '</td>';
            html += '<td><strong>' + p.views + '</strong></td>';
            html += '<td>' + pct + '%</td>';
            html += '<td><span class="heat-bar" style="width:' + heatW + 'px;"></span></td>';
            html += '</tr>';
        }
        tbody.innerHTML = html;
    }

    function renderRecentVisitors(visitors) {
        var tbody = document.getElementById('recent-visitors-body');
        if (!tbody) return;
        var html = '';
        if (visitors.length === 0) {
            html = '<tr><td colspan="7" style="text-align:center;color:var(--muted);"><?php echo e(t('admin_traffic_no_visitors', '暂无访客数据')); ?></td></tr>';
        }
        for (var i = 0; i < visitors.length; i++) {
            var v = visitors[i];
            html += '<tr>';
            html += '<td><code style="font-size:0.8rem;">' + e(v.ip_short) + '</code></td>';
            html += '<td>' + e(v.device || '-') + '</td>';
            html += '<td>' + e(v.os || '-') + '</td>';
            html += '<td>' + e(v.browser || '-') + '</td>';
            html += '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + e(v.page) + '">' + e(v.page) + '</td>';
            html += '<td>' + v.views + '</td>';
            html += '<td>' + e(v.last_visit) + '</td>';
            html += '</tr>';
        }
        tbody.innerHTML = html;
    }

    // ========== 最近访客分页 ==========

    function renderRecentPagination(data) {
        var el = document.getElementById('recent-visitors-pagination');
        if (!el) return;
        var page = data.recent_page || 1;
        var totalPages = data.recent_total_pages || 1;
        var total = data.recent_total || 0;
        var info = <?php echo json_encode(t('admin_traffic_page_info', '第 {p} / {tp} 页，共 {n} 条')); ?>;
        info = info.replace('{p}', page).replace('{tp}', totalPages).replace('{n}', total);

        var html = '<div class="pagination">';
        // 上一页
        if (page > 1) {
            html += '<a class="btn btn-secondary rv-page-btn" data-page="' + (page - 1) + '"><?php echo e(t('admin_traffic_page_prev', '上一页')); ?></a>';
        } else {
            html += '<span class="btn btn-disabled"><?php echo e(t('admin_traffic_page_prev', '上一页')); ?></span>';
        }
        // 页码
        if (totalPages > 1) {
            html += '<div class="page-numbers">';
            var start = Math.max(1, page - 2);
            var end = Math.min(totalPages, page + 2);
            if (start > 1) {
                html += '<a class="page-number rv-page-btn" data-page="1">1</a>';
                if (start > 2) html += '<span class="page-ellipsis">...</span>';
            }
            for (var i = start; i <= end; i++) {
                if (i === page) {
                    html += '<span class="page-number active">' + i + '</span>';
                } else {
                    html += '<a class="page-number rv-page-btn" data-page="' + i + '">' + i + '</a>';
                }
            }
            if (end < totalPages) {
                if (end < totalPages - 1) html += '<span class="page-ellipsis">...</span>';
                html += '<a class="page-number rv-page-btn" data-page="' + totalPages + '">' + totalPages + '</a>';
            }
            html += '</div>';
        }
        html += '<span class="page-info">' + info + '</span>';
        // 下一页
        if (page < totalPages) {
            html += '<a class="btn btn-secondary rv-page-btn" data-page="' + (page + 1) + '"><?php echo e(t('admin_traffic_page_next', '下一页')); ?></a>';
        } else {
            html += '<span class="btn btn-disabled"><?php echo e(t('admin_traffic_page_next', '下一页')); ?></span>';
        }
        html += '</div>';
        el.innerHTML = html;
    }

    function updateStats(data) {
        // 数字动画
        animateNum('stat-online', data.online_count || 0);
        animateNum('stat-today-pv', data.today_pv || 0);
        animateNum('stat-today-uv', data.today_uv || 0);
        animateNum('stat-hour-pv', data.current_hour_pv || 0);
        animateNum('stat-total-pv', data.total_pv || 0);
        animateNum('stat-total-uv', data.total_uv || 0);

        // 对比信息
        var pvVs = data.today_pv - data.yesterday_pv;
        var uvVs = data.today_uv - data.yesterday_uv;
        document.getElementById('stat-today-pv-vs').textContent = pvVs >= 0 ? <?php echo json_encode(t('admin_traffic_vs_plus', '较昨日 +')); ?> + pvVs : <?php echo json_encode(t('admin_traffic_vs_minus', '较昨日 ')); ?> + pvVs;
        document.getElementById('stat-today-uv-vs').textContent = uvVs >= 0 ? <?php echo json_encode(t('admin_traffic_vs_plus', '较昨日 +')); ?> + uvVs : <?php echo json_encode(t('admin_traffic_vs_minus', '较昨日 ')); ?> + uvVs;
        document.getElementById('stat-hour-label').textContent = (data.current_hour || 0) + <?php echo json_encode(t('admin_traffic_hour_suffix', ':00 时段')); ?>;

        document.getElementById('last-refresh').textContent = new Date().toLocaleTimeString('zh-CN');

        // 闪烁点
        var dot = document.getElementById('refresh-dot');
        if (dot) { dot.classList.add('pulse-dot'); setTimeout(function() { dot.classList.remove('pulse-dot'); }, 400); }
    }

    function animateNum(id, target) {
        var el = document.getElementById(id);
        if (!el) return;
        var current = parseInt(el.textContent) || 0;
        if (current === target) return;
        if (prevData[id] !== target) {
            el.classList.add('pulse');
            setTimeout(function() { el.classList.remove('pulse'); }, 400);
        }
        prevData[id] = target;
        var steps = 8;
        var step = (target - current) / steps;
        var i = 0;
        function tick() {
            current += step;
            if (++i >= steps) { el.textContent = target.toLocaleString(); return; }
            el.textContent = Math.round(current).toLocaleString();
            requestAnimationFrame(tick);
        }
        tick();
    }

    // ========== 主刷新函数 ==========

    function refresh() {
        // 请求去重：如果上一次请求还没返回，跳过本次轮询
        if (fetching) return;
        fetching = true;

        fetch('<?php echo site_url('admin/api/traffic_ajax'); ?>&page=' + recentPage + '&_=' + Date.now())
            .then(function(r) { return r.json(); })
            .then(function(data) {
                fetching = false;
                if (!data.success) return;

                // 快速比对：如果核心指标完全没变化，跳过重绘
                var hash = [
                    data.online_count,
                    data.today_pv, data.today_uv,
                    data.current_hour_pv,
                    data.total_pv, data.total_uv,
                    data.hourly_pv ? data.hourly_pv.join(',') : '',
                    data.hot_pages ? data.hot_pages.length : 0,
                    data.recent_visitors ? data.recent_visitors.length : 0,
                    data.recent_total ? data.recent_total : 0,
                    data.recent_page ? data.recent_page : 0
                ].join('|');
                var changed = (hash !== lastDataHash);
                lastDataHash = hash;

                // 统计数字始终更新（动画平滑过渡）
                updateStats(data);

                // 图表和列表仅在数据变化时重绘
                if (changed) {
                    drawHourlyChart(data);
                    drawDailyChart(data);
                    renderHotPages(data.hot_pages || []);
                    renderRecentVisitors(data.recent_visitors || []);
                    renderRecentPagination(data);
                    renderBars('referrer-list', data.referrers || [], 'count', function(item) { return item.source; });
                    renderBars('device-bars', data.devices || [], 'visitors', function(item) { return item.label + ' (' + item.visitors + <?php echo json_encode(t('admin_traffic_people_suffix', '人)')); ?>; });
                    renderBars('browser-bars', data.browsers || [], 'count', function(item) { return item.name; });
                    renderBars('os-bars', data.os_list || [], 'count', function(item) { return item.name; });
                }
            })
            .catch(function(err) {
                fetching = false;
                console.error(<?php echo json_encode(t('admin_traffic_monitor_bea187','流量数据加载失败:')); ?>, err);
                var indicator = document.getElementById('refresh-indicator');
                if (indicator && !indicator.querySelector('.err-msg')) {
                    var span = document.createElement('span');
                    span.className = 'err-msg';
                    span.textContent = <?php echo json_encode(t('admin_traffic_load_fail', '加载失败，请检查浏览器控制台')); ?>;
                    span.style.cssText = 'color:#ef4444;font-size:0.75rem;margin-left:0.5rem;';
                    indicator.appendChild(span);
                }
                var dot = document.getElementById('refresh-dot');
                if (dot) dot.style.background = '#ef4444';
            });
    }

    // 窗口 resize 时重绘图表
    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(refresh, 300);
    });

    // 最近访客分页点击（事件委托，渲染后自动生效）
    var rvPager = document.getElementById('recent-visitors-pagination');
    if (rvPager) {
        rvPager.addEventListener('click', function (e) {
            var btn = e.target.closest('.rv-page-btn');
            if (!btn) return;
            var p = parseInt(btn.getAttribute('data-page'), 10);
            if (!p || isNaN(p) || p === recentPage) return;
            recentPage = p;
            refresh();
        });
    }

    // 启动
    refresh();
    autoTimer = setInterval(refresh, REFRESH_MS);

    // 页面不可见时暂停轮询，节省服务器资源
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
        } else {
            if (!autoTimer) {
                refresh();
                autoTimer = setInterval(refresh, REFRESH_MS);
            }
        }
    });
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
