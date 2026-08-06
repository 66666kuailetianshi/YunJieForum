<?php
/**
 * 云界论坛 - 管理后台敏感词命中日志
 *
 * 特性：
 * 1. 实时刷新：每 5 秒 AJAX 轮询获取新日志，无需手动刷新页面。
 * 2. 统计仪表盘：总命中数、今日命中、处理类型分布、24 小时趋势。
 * 3. 命中词 TOP 10 排行、内容类型分布、活跃用户。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';
require_once APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'sensitive_filter' . DIRECTORY_SEPARATOR . 'helper.php';

$db = get_db();

// 当前 Tab：hits(命中日志) / status(启用禁用操作记录)
$tab = $_GET['tab'] ?? 'hits';
if (!in_array($tab, ['hits', 'status'], true)) $tab = 'hits';

// 清空日志
// CSRF 统一校验：涉及状态变更的 GET 操作必须先通过校验，失败时明确提示（避免静默失败）
if (isset($_GET['action']) && $_GET['action'] === 'clear' && !validate_csrf()) {
    set_flash(t('admin_swl_csrf_failed', '安全校验失败（链接已过期），请刷新页面后重新操作。'), 'error');
    redirect('/admin/sensitive_word_logs');
}

if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    if ($tab === 'status') {
        $db->exec("DELETE FROM sensitive_word_status_logs");
        set_flash(t('admin_swl_flash_status_cleared', '操作记录已清空。'), 'success');
        redirect('/admin/sensitive_word_logs?tab=status');
    } else {
        $db->exec("DELETE FROM sensitive_word_logs");
        set_flash(t('admin_swl_flash_logs_cleared', '日志已清空。'), 'success');
        redirect('/admin/sensitive_word_logs');
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

if ($tab === 'status') {
    // 启用/禁用操作记录
    $total = (int)$db->query("SELECT COUNT(*) FROM sensitive_word_status_logs")->fetchColumn();
    $stmt = $db->prepare("SELECT * FROM sensitive_word_status_logs ORDER BY id DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $statusLogs = $stmt->fetchAll();

    $initialStatusStats = [
        'total'         => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_status_logs")->fetchColumn(),
        'today'         => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_status_logs WHERE created_at >= " . $db->quote(gmdate('Y-m-d H:i:s', strtotime('today'))))->fetchColumn(),
        'enable_count'  => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_status_logs WHERE action = 'enable'")->fetchColumn(),
        'disable_count' => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_status_logs WHERE action = 'disable'")->fetchColumn(),
    ];
    $lastStatusId = (int)$db->query("SELECT MAX(id) FROM sensitive_word_status_logs")->fetchColumn();
} else {
    // 命中日志
    $total = (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs")->fetchColumn();
    $stmt = $db->prepare("SELECT s.*, u.username FROM sensitive_word_logs s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.id DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll();

    // 初始统计数据（前端会通过 AJAX 实时更新）
    $initialStats = [
        'total'         => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs")->fetchColumn(),
        'today'         => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs WHERE created_at >= " . $db->quote(gmdate('Y-m-d H:i:s', strtotime('today'))))->fetchColumn(),
        'replace_count' => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs WHERE action = 'replace'")->fetchColumn(),
        'reject_count'  => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs WHERE action = 'reject'")->fetchColumn(),
        'review_count'  => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs WHERE action = 'review'")->fetchColumn(),
    ];

    // 获取当前最新 ID（用于增量刷新）
    $lastId = (int)$db->query("SELECT MAX(id) FROM sensitive_word_logs")->fetchColumn();
}

$pageTitle = t('admin_swl_title', '敏感词命中日志');
$activeMenu = 'sensitive_words';
require_once dirname(__DIR__) . '/layout/header.php';
?>

<style>
/* ===== 统计卡片 ===== */
.swl-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem; }
.swl-stat-card { background: var(--surface); border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 1rem 1.125rem; position: relative; overflow: hidden; transition: var(--transition); }
.swl-stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.swl-stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: var(--radius-lg) var(--radius-lg) 0 0; }
.swl-stat-card.sc-total::before { background: var(--primary); }
.swl-stat-card.sc-today::before { background: var(--warning); }
.swl-stat-card.sc-replace::before { background: var(--secondary); }
.swl-stat-card.sc-reject::before { background: var(--error); }
.swl-stat-card.sc-review::before { background: var(--info, #0ea5e9); }
.swl-stat-label { font-size: var(--text-xs); color: var(--text-3); font-weight: 500; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.25rem; }
.swl-stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1.1; color: var(--text); transition: color 0.3s; }
.swl-stat-value.pulse { color: var(--primary); animation: swl-pulse 0.6s ease; }
@keyframes swl-pulse { 0% { transform: scale(1); } 50% { transform: scale(1.15); } 100% { transform: scale(1); } }
.swl-stat-sub { font-size: var(--text-xs); color: var(--text-4); margin-top: 0.125rem; }

/* ===== 24 小时趋势图 ===== */
.swl-chart-card { margin-bottom: 1.25rem; }
.swl-chart { display: flex; align-items: flex-end; gap: 2px; height: 80px; padding-top: 0.5rem; }
.swl-chart-bar { flex: 1; min-height: 2px; background: var(--primary); border-radius: 2px 2px 0 0; transition: height 0.3s ease, background 0.3s; position: relative; cursor: default; }
.swl-chart-bar:hover { background: var(--brand-hover); }
.swl-chart-bar:hover::after { content: attr(data-label); position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: var(--text); color: var(--surface); font-size: var(--text-xs); padding: 2px 6px; border-radius: 4px; white-space: nowrap; margin-bottom: 4px; z-index: 10; }
.swl-chart-axis { display: flex; justify-content: space-between; margin-top: 0.375rem; font-size: 10px; color: var(--text-4); }

/* ===== TOP 10 命中词 ===== */
.swl-top-list { display: flex; flex-direction: column; gap: 0.375rem; }
.swl-top-item { display: flex; align-items: center; gap: 0.5rem; }
.swl-top-rank { width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: var(--text-xs); font-weight: 700; flex-shrink: 0; background: var(--surface-3); color: var(--text-3); }
.swl-top-rank.r1 { background: var(--error); color: #fff; }
.swl-top-rank.r2 { background: var(--warning); color: #fff; }
.swl-top-rank.r3 { background: var(--success); color: #fff; }
.swl-top-word { flex: 1; font-size: var(--text-sm); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.swl-top-bar { flex: 0 0 80px; height: 6px; border-radius: 3px; background: var(--surface-2); overflow: hidden; }
.swl-top-bar-fill { height: 100%; border-radius: 3px; background: var(--primary); transition: width 0.5s ease; }
.swl-top-count { font-size: var(--text-xs); color: var(--text-3); min-width: 28px; text-align: right; font-weight: 600; }

/* ===== 实时日志列表 ===== */
.swl-log-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem; }
.swl-live-indicator { display: inline-flex; align-items: center; gap: 0.375rem; font-size: var(--text-sm); color: var(--text-3); }
.swl-live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--success); animation: swl-blink 2s ease-in-out infinite; }
.swl-live-dot.paused { background: var(--text-4); animation: none; }
@keyframes swl-blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

.swl-log-list { display: flex; flex-direction: column; gap: 0.375rem; }
.swl-log-item { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.625rem 0.875rem; background: var(--surface); border: 1px solid var(--border-soft); border-radius: var(--radius-md); transition: var(--transition); border-left: 3px solid var(--border); }
.swl-log-item.act-replace { border-left-color: var(--secondary); }
.swl-log-item.act-reject { border-left-color: var(--error); }
.swl-log-item.act-review { border-left-color: var(--warning); }
.swl-log-item.new { animation: swl-slide-in 0.4s ease; }
@keyframes swl-slide-in { from { opacity: 0; transform: translateX(-12px); } to { opacity: 1; transform: translateX(0); } }
.swl-log-item:hover { box-shadow: var(--shadow-sm); }
.swl-log-info { flex: 1; min-width: 0; }
.swl-log-top { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.25rem; }
.swl-log-word { font-weight: 600; font-size: var(--text-sm); }
.swl-log-user { font-size: var(--text-xs); color: var(--text-3); }
.swl-log-snippet { font-size: var(--text-xs); color: var(--text-4); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 400px; }
.swl-log-time { font-size: var(--text-xs); color: var(--text-4); white-space: nowrap; flex-shrink: 0; }
</style>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_swl_header_title', '敏感词日志')); ?></h1>
    <div class="flex gap-1">
        <a href="<?php echo site_url('admin/sensitive_words'); ?>" class="btn btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;"><polyline points="15 18 9 12 15 6"/></svg>
            <?php echo e(t('admin_swl_btn_back', '返回敏感词管理')); ?>
        </a>
        <?php if ($tab === 'hits'): ?>
        <button id="swl-toggle-live" class="btn btn-primary">
            <span id="swl-live-dot" class="swl-live-dot" style="display:inline-block;margin-right:4px;"></span>
            <span id="swl-live-text"><?php echo e(t('admin_swl_live_monitoring', '实时监控中')); ?></span>
        </button>
        <?php endif; ?>
        <a href="<?php echo site_url('admin/sensitive_word_logs', ['tab' => $tab, 'action' => 'clear', 'csrf_token' => csrf_token()]); ?>" class="btn btn-danger" data-confirm="<?php echo e($tab === 'status' ? t('admin_swl_clear_confirm_status', '确定清空所有操作记录吗？') : t('admin_swl_clear_confirm_logs', '确定清空所有日志吗？')); ?>"><?php echo e($tab === 'status' ? t('admin_swl_btn_clear_status', '清空操作记录') : t('admin_swl_btn_clear_logs', '清空日志')); ?></a>
    </div>
</div>

<!-- Tab 切换 -->
<div class="card mb-2">
    <div class="filter-tabs">
        <a href="<?php echo site_url('admin/sensitive_word_logs', ['tab' => 'hits']); ?>" class="filter-tab <?php echo $tab === 'hits' ? 'active' : ''; ?>"><?php echo e(t('admin_swl_tab_hits', '命中日志')); ?></a>
        <a href="<?php echo site_url('admin/sensitive_word_logs', ['tab' => 'status']); ?>" class="filter-tab <?php echo $tab === 'status' ? 'active' : ''; ?>"><?php echo e(t('admin_swl_tab_status', '启用/禁用操作记录')); ?></a>
    </div>
</div>

<?php if ($tab === 'status'): ?>
<!-- ====================== 启用/禁用操作记录 Tab ====================== -->

<!-- 统计卡片 -->
<div class="swl-stats-grid">
    <div class="swl-stat-card sc-total">
        <div class="swl-stat-label"><?php echo e(t('admin_swl_status_total', '累计操作')); ?></div>
        <div class="swl-stat-value" id="stat-status-total"><?php echo $initialStatusStats['total']; ?></div>
        <div class="swl-stat-sub"><?php echo e(t('admin_swl_status_total_sub', '条操作记录')); ?></div>
    </div>
    <div class="swl-stat-card sc-today">
        <div class="swl-stat-label"><?php echo e(t('admin_swl_status_today', '今日操作')); ?></div>
        <div class="swl-stat-value" id="stat-status-today"><?php echo $initialStatusStats['today']; ?></div>
        <div class="swl-stat-sub"><?php echo e(t('admin_swl_status_today_sub', '条状态变更')); ?></div>
    </div>
    <div class="swl-stat-card sc-replace">
        <div class="swl-stat-label"><?php echo e(t('admin_swl_status_enable', '启用次数')); ?></div>
        <div class="swl-stat-value" id="stat-status-enable"><?php echo $initialStatusStats['enable_count']; ?></div>
        <div class="swl-stat-sub"><?php echo e(t('admin_swl_status_enable_sub', '累计启用')); ?></div>
    </div>
    <div class="swl-stat-card sc-reject">
        <div class="swl-stat-label"><?php echo e(t('admin_swl_status_disable', '禁用次数')); ?></div>
        <div class="swl-stat-value" id="stat-status-disable"><?php echo $initialStatusStats['disable_count']; ?></div>
        <div class="swl-stat-sub"><?php echo e(t('admin_swl_status_disable_sub', '累计禁用')); ?></div>
    </div>
</div>

<!-- 24 小时趋势图 + TOP 10 操作词 -->
<div class="grid-2 mb-2">
    <div class="card swl-chart-card">
        <h2 class="profile-card-title"><?php echo e(t('admin_swl_chart_hourly', '今日 24 小时趋势')); ?></h2>
        <div class="swl-chart" id="status-hourly-chart">
            <!-- 由 JS 动态填充 -->
        </div>
        <div class="swl-chart-axis">
            <span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>23:59</span>
        </div>
    </div>
    <div class="card">
        <h2 class="profile-card-title"><?php echo e(t('admin_swl_status_top_words', '操作词 TOP 10')); ?></h2>
        <div class="swl-top-list" id="status-top-words">
            <!-- 由 JS 动态填充 -->
        </div>
    </div>
</div>

<!-- 来源分布 + 操作者 TOP 5 -->
<div class="grid-2 mb-2">
    <div class="card">
        <h2 class="profile-card-title"><?php echo e(t('admin_swl_status_source_dist', '操作来源分布')); ?></h2>
        <div id="status-source-dist">
            <!-- 由 JS 动态填充 -->
        </div>
    </div>
    <div class="card">
        <h2 class="profile-card-title"><?php echo e(t('admin_swl_status_operators', '操作者 TOP 5')); ?></h2>
        <div class="swl-top-list" id="status-active-operators">
            <!-- 由 JS 动态填充 -->
        </div>
    </div>
</div>

<!-- 实时操作记录列表 -->
<div class="card">
    <div class="swl-log-toolbar">
        <h2 class="profile-card-title" style="margin:0;"><?php echo e(t('admin_swl_status_log_title', '启用/禁用操作记录')); ?> <span class="text-muted" style="font-weight:400;font-size:var(--text-sm);"><?php echo t('admin_swl_log_count_prefix', '（共 '); ?><span id="status-log-count"><?php echo $total; ?></span><?php echo t('admin_swl_log_count_suffix', ' 条）'); ?></span></h2>
        <div class="swl-live-indicator">
            <span class="swl-live-dot" id="status-live-indicator"></span>
            <span id="status-live-status"><?php echo e(t('admin_swl_live_refresh_5s', '每 5 秒自动刷新')); ?></span>
        </div>
    </div>

    <div class="swl-log-list" id="status-log-list">
        <?php foreach ($statusLogs as $log): ?>
            <div class="swl-log-item <?php echo $log['action'] === 'enable' ? 'act-replace' : 'act-reject'; ?>" data-id="<?php echo (int)$log['id']; ?>">
                <div class="swl-log-info">
                    <div class="swl-log-top">
                        <span class="swl-log-word"><?php echo e($log['word']); ?></span>
                        <?php if ($log['action'] === 'enable'): ?>
                            <span class="badge badge-secondary"><?php echo e(t('admin_swl_badge_enable', '启用')); ?></span>
                        <?php else: ?>
                            <span class="badge badge-danger"><?php echo e(t('admin_swl_badge_disable', '禁用')); ?></span>
                        <?php endif; ?>
                        <span class="swl-log-user"><?php echo e($log['operator_name'] !== '' ? $log['operator_name'] : 'UID:' . $log['operator_id']); ?></span>
                        <?php
                        $sourceLabels = ['manual' => t('admin_swl_source_manual', '手动切换'), 'batch' => t('admin_swl_source_batch', '批量操作'), 'edit' => t('admin_swl_source_edit', '编辑保存')];
                        $srcLabel = $sourceLabels[$log['source']] ?? $log['source'];
                        ?>
                        <span class="swl-log-user">· <?php echo e($srcLabel); ?> · #<?php echo (int)$log['word_id']; ?></span>
                    </div>
                </div>
                <div class="swl-log-time"><?php echo e(db_datetime($log['created_at'])); ?></div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($statusLogs)): ?>
            <div class="empty-state">
                <p class="text-muted" id="status-empty-msg"><?php echo e(t('admin_swl_status_empty', '暂无操作记录，在敏感词管理页切换启用/禁用状态后将自动记录。')); ?></p>
            </div>
        <?php endif; ?>
    </div>

    <?php echo pagination($page, $total, $perPage, site_url('admin/sensitive_word_logs', ['tab' => 'status'])); ?>
</div>

<script>
(function() {
    'use strict';

    var ajaxUrl = '<?php echo site_url('admin/api/sensitive_logs_ajax'); ?>';
    var lastId = <?php echo $lastStatusId; ?>;
    var liveEnabled = true;
    var refreshInterval = 1000; // 1 秒（sensitive_logs_ajax 服务端 1 秒缓存合并并发，不阻塞）
    var checking = false; // 请求去重：上一请求未返回时跳过本次轮询，避免堆积
    var timer = null;
    var maxDisplay = 50;

    var logList = document.getElementById('status-log-list');

    var sourceLabels = { manual: <?php echo json_encode(t('admin_swl_source_manual', '手动切换')); ?>, batch: <?php echo json_encode(t('admin_swl_source_batch', '批量操作')); ?>, edit: <?php echo json_encode(t('admin_swl_source_edit', '编辑保存')); ?> };

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(s);
        return div.innerHTML;
    }

    function pulseStat(el) {
        if (!el) return;
        el.classList.add('pulse');
        setTimeout(function() { el.classList.remove('pulse'); }, 600);
    }

    function updateStat(id, newVal) {
        var el = document.getElementById(id);
        if (!el) return;
        var oldVal = parseInt(el.textContent) || 0;
        if (newVal !== oldVal) {
            el.textContent = newVal;
            pulseStat(el);
        }
    }

    function updateStats(stats) {
        updateStat('stat-status-total', stats.total);
        updateStat('stat-status-today', stats.today);
        updateStat('stat-status-enable', stats.enable_count);
        updateStat('stat-status-disable', stats.disable_count);
        var countEl = document.getElementById('status-log-count');
        if (countEl) countEl.textContent = stats.total;

        renderHourlyChart(stats.hourly);
        renderTopWords(stats.top_words);
        renderSourceDist(stats.source_dist);
        renderActiveOperators(stats.active_operators);
    }

    function renderHourlyChart(hourly) {
        if (!hourly) return;
        var container = document.getElementById('status-hourly-chart');
        if (!container) return;
        var max = Math.max.apply(null, hourly);
        if (max === 0) max = 1;
        var html = '';
        for (var i = 0; i < 24; i++) {
            var h = String(i).padStart(2, '0');
            var c = hourly[i] || 0;
            var pct = Math.round(c / max * 100);
            html += '<div class="swl-chart-bar" style="height:' + Math.max(pct, 2) + '%;" data-label="' + h + ':00 - ' + c + <?php echo json_encode(t('admin_swl_js_count_unit', ' 条')); ?> + '"></div>';
        }
        container.innerHTML = html;
    }

    function renderTopWords(words) {
        var container = document.getElementById('status-top-words');
        if (!container) return;
        if (!words || words.length === 0) {
            container.innerHTML = '<p class="text-muted" style="font-size:var(--text-sm);">' + <?php echo json_encode(t('admin_swl_js_no_data', '暂无数据')); ?> + '</p>';
            return;
        }
        var maxCount = words[0].count;
        var html = '';
        for (var i = 0; i < words.length; i++) {
            var w = words[i];
            var pct = Math.round(w.count / maxCount * 100);
            var rankClass = i < 3 ? ' r' + (i + 1) : '';
            html += '<div class="swl-top-item">' +
                '<span class="swl-top-rank' + rankClass + '">' + (i + 1) + '</span>' +
                '<span class="swl-top-word">' + escapeHtml(w.word) + '</span>' +
                '<span class="swl-top-bar"><span class="swl-top-bar-fill" style="width:' + pct + '%;"></span></span>' +
                '<span class="swl-top-count">' + w.count + '</span>' +
                '</div>';
        }
        container.innerHTML = html;
    }

    function renderSourceDist(sources) {
        var container = document.getElementById('status-source-dist');
        if (!container) return;
        if (!sources || sources.length === 0) {
            container.innerHTML = '<p class="text-muted" style="font-size:var(--text-sm);">' + <?php echo json_encode(t('admin_swl_js_no_data', '暂无数据')); ?> + '</p>';
            return;
        }
        var total = 0;
        for (var i = 0; i < sources.length; i++) total += sources[i].count;
        var colors = ['#6366f1', '#ef4444', '#10b981'];
        var html = '';
        for (var i = 0; i < sources.length; i++) {
            var t = sources[i];
            var pct = total > 0 ? Math.round(t.count / total * 100) : 0;
            var color = colors[i % colors.length];
            var label = sourceLabels[t.source] || t.source;
            html += '<div class="swl-top-item" style="margin-bottom:0.5rem;">' +
                '<span class="swl-top-rank" style="background:' + color + ';color:#fff;width:auto;padding:0 8px;border-radius:var(--radius-full);font-size:var(--text-xs);">' + escapeHtml(label) + '</span>' +
                '<span class="swl-top-word">' + t.count + <?php echo json_encode(t('admin_swl_js_times', ' 次')); ?> + '</span>' +
                '<span class="swl-top-bar"><span class="swl-top-bar-fill" style="width:' + pct + '%;background:' + color + ';"></span></span>' +
                '<span class="swl-top-count">' + pct + '%</span>' +
                '</div>';
        }
        container.innerHTML = html;
    }

    function renderActiveOperators(users) {
        var container = document.getElementById('status-active-operators');
        if (!container) return;
        if (!users || users.length === 0) {
            container.innerHTML = '<p class="text-muted" style="font-size:var(--text-sm);">' + <?php echo json_encode(t('admin_swl_js_no_data', '暂无数据')); ?> + '</p>';
            return;
        }
        var maxCount = users[0].count;
        var html = '';
        for (var i = 0; i < users.length; i++) {
            var u = users[i];
            var pct = Math.round(u.count / maxCount * 100);
            var rankClass = i < 3 ? ' r' + (i + 1) : '';
            html += '<div class="swl-top-item">' +
                '<span class="swl-top-rank' + rankClass + '">' + (i + 1) + '</span>' +
                '<span class="swl-top-word">' + escapeHtml(u.username) + '</span>' +
                '<span class="swl-top-bar"><span class="swl-top-bar-fill" style="width:' + pct + '%;"></span></span>' +
                '<span class="swl-top-count">' + u.count + '</span>' +
                '</div>';
        }
        container.innerHTML = html;
    }

    function prependLogs(logs) {
        if (!logs || logs.length === 0) return;
        var emptyMsg = document.getElementById('status-empty-msg');
        if (emptyMsg) emptyMsg.parentElement.remove();

        for (var i = logs.length - 1; i >= 0; i--) {
            var log = logs[i];
            if (logList.querySelector('[data-id="' + log.id + '"]')) continue;

            var item = document.createElement('div');
            item.className = 'swl-log-item ' + (log.action === 'enable' ? 'act-replace' : 'act-reject') + ' new';
            item.setAttribute('data-id', log.id);

            var actionBadge = log.action === 'enable'
                ? '<span class="badge badge-secondary">' + <?php echo json_encode(t('admin_swl_badge_enable', '启用')); ?> + '</span>'
                : '<span class="badge badge-danger">' + <?php echo json_encode(t('admin_swl_badge_disable', '禁用')); ?> + '</span>';
            var srcLabel = sourceLabels[log.source] || log.source;

            item.innerHTML =
                '<div class="swl-log-info">' +
                    '<div class="swl-log-top">' +
                        '<span class="swl-log-word">' + escapeHtml(log.word) + '</span>' +
                        actionBadge +
                        '<span class="swl-log-user">' + escapeHtml(log.operator_name || ('UID:' + log.operator_id)) + '</span>' +
                        '<span class="swl-log-user">· ' + escapeHtml(srcLabel) + ' · #' + log.word_id + '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="swl-log-time">' + escapeHtml(log.created_at) + '</div>';

            logList.insertBefore(item, logList.firstChild);

            if (parseInt(log.id) > lastId) lastId = parseInt(log.id);
        }

        while (logList.children.length > maxDisplay) {
            logList.removeChild(logList.lastChild);
        }
    }

    function refresh() {
        if (checking) return;
        checking = true;
        var params = 'mode=status_stats&last_id=' + lastId + '&limit=20';
        fetch(ajaxUrl + '&' + params, { credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.error) {
                    console.error('AJAX error:', data.error);
                    return;
                }
                if (data.status_stats) updateStats(data.status_stats);
                if (data.status_logs && data.status_logs.length > 0) {
                    prependLogs(data.status_logs);
                    if (data.status_max_id > lastId) lastId = data.status_max_id;
                }
            })
            .catch(function(err) {
                console.error('Fetch error:', err);
            })
            .then(function() { checking = false; }); // 无论成败都释放去重锁
    }

    refresh();
    timer = setInterval(refresh, refreshInterval);
})();
</script>

<?php else: ?>
<!-- ====================== 命中日志 Tab ====================== -->

<!-- 统计卡片 -->
<div class="swl-stats-grid">
    <div class="swl-stat-card sc-total">
        <div class="swl-stat-label"><?php echo e(t('admin_swl_hits_total', '累计命中')); ?></div>
        <div class="swl-stat-value" id="stat-total"><?php echo $initialStats['total']; ?></div>
        <div class="swl-stat-sub"><?php echo e(t('admin_swl_hits_total_sub', '条历史记录')); ?></div>
    </div>
    <div class="swl-stat-card sc-today">
        <div class="swl-stat-label"><?php echo e(t('admin_swl_hits_today', '今日命中')); ?></div>
        <div class="swl-stat-value" id="stat-today"><?php echo $initialStats['today']; ?></div>
        <div class="swl-stat-sub"><?php echo e(t('admin_swl_hits_today_sub', '条拦截记录')); ?></div>
    </div>
    <div class="swl-stat-card sc-replace">
        <div class="swl-stat-label"><?php echo e(t('admin_swl_hits_replace', '自动替换')); ?></div>
        <div class="swl-stat-value" id="stat-replace"><?php echo $initialStats['replace_count']; ?></div>
        <div class="swl-stat-sub"><?php echo e(t('admin_swl_hits_replace_sub', '已自动处理')); ?></div>
    </div>
    <div class="swl-stat-card sc-reject">
        <div class="swl-stat-label"><?php echo e(t('admin_swl_hits_reject', '直接拦截')); ?></div>
        <div class="swl-stat-value" id="stat-reject"><?php echo $initialStats['reject_count']; ?></div>
        <div class="swl-stat-sub"><?php echo e(t('admin_swl_hits_reject_sub', '阻止内容发布')); ?></div>
    </div>
    <div class="swl-stat-card sc-review">
        <div class="swl-stat-label"><?php echo e(t('admin_swl_hits_review', '人工审核')); ?></div>
        <div class="swl-stat-value" id="stat-review"><?php echo $initialStats['review_count']; ?></div>
        <div class="swl-stat-sub"><?php echo e(t('admin_swl_hits_review_sub', '待审核处理')); ?></div>
    </div>
</div>

<!-- 24 小时趋势图 + TOP 10 -->
<div class="grid-2 mb-2">
    <div class="card swl-chart-card">
        <h2 class="profile-card-title"><?php echo e(t('admin_swl_chart_hourly', '今日 24 小时趋势')); ?></h2>
        <div class="swl-chart" id="hourly-chart">
            <!-- 由 JS 动态填充 -->
        </div>
        <div class="swl-chart-axis">
            <span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>23:59</span>
        </div>
    </div>
    <div class="card">
        <h2 class="profile-card-title"><?php echo e(t('admin_swl_hits_top_words', '命中词 TOP 10')); ?></h2>
        <div class="swl-top-list" id="top-words">
            <!-- 由 JS 动态填充 -->
        </div>
    </div>
</div>

<!-- 内容类型分布 + 活跃用户 -->
<div class="grid-2 mb-2">
    <div class="card">
        <h2 class="profile-card-title"><?php echo e(t('admin_swl_hits_type_dist', '内容类型分布')); ?></h2>
        <div id="type-dist">
            <!-- 由 JS 动态填充 -->
        </div>
    </div>
    <div class="card">
        <h2 class="profile-card-title"><?php echo e(t('admin_swl_hits_active_users', '活跃用户 TOP 5')); ?></h2>
        <div class="swl-top-list" id="active-users">
            <!-- 由 JS 动态填充 -->
        </div>
    </div>
</div>

<!-- 实时日志列表 -->
<div class="card">
    <div class="swl-log-toolbar">
        <h2 class="profile-card-title" style="margin:0;"><?php echo e(t('admin_swl_hits_log_title', '实时命中日志')); ?> <span class="text-muted" style="font-weight:400;font-size:var(--text-sm);"><?php echo t('admin_swl_log_count_prefix', '（共 '); ?><span id="log-count"><?php echo $total; ?></span><?php echo t('admin_swl_log_count_suffix', ' 条）'); ?></span></h2>
        <div class="swl-live-indicator">
            <span class="swl-live-dot" id="live-indicator"></span>
            <span id="live-status"><?php echo e(t('admin_swl_live_refresh_5s', '每 5 秒自动刷新')); ?></span>
        </div>
    </div>

    <div class="swl-log-list" id="log-list">
        <?php foreach ($logs as $log): ?>
            <div class="swl-log-item act-<?php echo e($log['action']); ?>" data-id="<?php echo (int)$log['id']; ?>">
                <div class="swl-log-info">
                    <div class="swl-log-top">
                        <span class="swl-log-word"><?php echo e($log['matched_word']); ?></span>
                        <?php if ($log['action'] === 'replace'): ?><span class="badge badge-secondary"><?php echo e(t('admin_swl_action_replace', '替换')); ?></span>
                        <?php elseif ($log['action'] === 'reject'): ?><span class="badge badge-danger"><?php echo e(t('admin_swl_action_reject', '拦截')); ?></span>
                        <?php elseif ($log['action'] === 'review'): ?><span class="badge badge-warning"><?php echo e(t('admin_swl_action_review', '审核')); ?></span><?php endif; ?>
                        <span class="swl-log-user"><?php echo e($log['username'] ?? 'UID:' . $log['user_id']); ?></span>
                        <span class="swl-log-user">· <?php echo e($log['content_type']); ?></span>
                    </div>
                    <div class="swl-log-snippet"><?php echo e($log['original_snippet']); ?></div>
                </div>
                <div class="swl-log-time"><?php echo e(db_datetime($log['created_at'])); ?></div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <p class="text-muted" id="empty-msg"><?php echo e(t('admin_swl_hits_empty', '暂无命中日志，开启实时监控后将自动显示新记录。')); ?></p>
            </div>
        <?php endif; ?>
    </div>

    <?php echo pagination($page, $total, $perPage, site_url('admin/sensitive_word_logs')); ?>
</div>

<script>
(function() {
    'use strict';

    var ajaxUrl = '<?php echo site_url('admin/api/sensitive_logs_ajax'); ?>';
    var lastId = <?php echo $lastId; ?>;
    var liveEnabled = true;
    var refreshInterval = 1000; // 1 秒（sensitive_logs_ajax 服务端 1 秒缓存合并并发，不阻塞）
    var checking = false; // 请求去重：上一请求未返回时跳过本次轮询，避免堆积
    var timer = null;
    var maxDisplay = 50; // 列表最多显示条数

    // DOM 元素
    var logList = document.getElementById('log-list');
    var statTotal = document.getElementById('stat-total');
    var statToday = document.getElementById('stat-today');
    var statReplace = document.getElementById('stat-replace');
    var statReject = document.getElementById('stat-reject');
    var statReview = document.getElementById('stat-review');
    var logCount = document.getElementById('log-count');
    var liveIndicator = document.getElementById('live-indicator');
    var liveStatus = document.getElementById('live-status');
    var toggleBtn = document.getElementById('swl-toggle-live');
    var toggleText = document.getElementById('swl-live-text');
    var toggleDot = document.getElementById('swl-live-dot');

    var actionLabels = { replace: <?php echo json_encode(t('admin_swl_action_replace', '替换')); ?>, reject: <?php echo json_encode(t('admin_swl_action_reject', '拦截')); ?>, review: <?php echo json_encode(t('admin_swl_action_review', '审核')); ?> };
    var contentTypeLabels = { post: <?php echo json_encode(t('admin_swl_type_post', '发帖')); ?>, reply: <?php echo json_encode(t('admin_swl_type_reply', '回复')); ?>, pm: <?php echo json_encode(t('admin_swl_type_pm', '私信')); ?>, profile: <?php echo json_encode(t('admin_swl_type_profile', '资料')); ?>, register: <?php echo json_encode(t('admin_swl_type_register', '注册')); ?> };

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(s);
        return div.innerHTML;
    }

    function pulseStat(el) {
        if (!el) return;
        el.classList.add('pulse');
        setTimeout(function() { el.classList.remove('pulse'); }, 600);
    }

    function updateStat(id, newVal) {
        var el = document.getElementById(id);
        if (!el) return;
        var oldVal = parseInt(el.textContent) || 0;
        if (newVal !== oldVal) {
            el.textContent = newVal;
            pulseStat(el);
        }
    }

    // 更新统计仪表盘
    function updateStats(stats) {
        updateStat('stat-total', stats.total);
        updateStat('stat-today', stats.today);
        updateStat('stat-replace', stats.replace_count);
        updateStat('stat-reject', stats.reject_count);
        updateStat('stat-review', stats.review_count);
        if (logCount) logCount.textContent = stats.total;

        // 24 小时趋势图
        renderHourlyChart(stats.hourly);

        // TOP 10 命中词
        renderTopWords(stats.top_words);

        // 内容类型分布
        renderTypeDist(stats.type_dist);

        // 活跃用户
        renderActiveUsers(stats.active_users);
    }

    function renderHourlyChart(hourly) {
        if (!hourly) return;
        var container = document.getElementById('hourly-chart');
        if (!container) return;
        var max = Math.max.apply(null, hourly);
        if (max === 0) max = 1;
        var html = '';
        for (var i = 0; i < 24; i++) {
            var h = String(i).padStart(2, '0');
            var c = hourly[i] || 0;
            var pct = Math.round(c / max * 100);
            html += '<div class="swl-chart-bar" style="height:' + Math.max(pct, 2) + '%;" data-label="' + h + ':00 - ' + c + <?php echo json_encode(t('admin_swl_js_count_unit', ' 条')); ?> + '"></div>';
        }
        container.innerHTML = html;
    }

    function renderTopWords(words) {
        var container = document.getElementById('top-words');
        if (!container) return;
        if (!words || words.length === 0) {
            container.innerHTML = '<p class="text-muted" style="font-size:var(--text-sm);">' + <?php echo json_encode(t('admin_swl_js_no_data', '暂无数据')); ?> + '</p>';
            return;
        }
        var maxCount = words[0].count;
        var html = '';
        for (var i = 0; i < words.length; i++) {
            var w = words[i];
            var pct = Math.round(w.count / maxCount * 100);
            var rankClass = i < 3 ? ' r' + (i + 1) : '';
            html += '<div class="swl-top-item">' +
                '<span class="swl-top-rank' + rankClass + '">' + (i + 1) + '</span>' +
                '<span class="swl-top-word">' + escapeHtml(w.word) + '</span>' +
                '<span class="swl-top-bar"><span class="swl-top-bar-fill" style="width:' + pct + '%;"></span></span>' +
                '<span class="swl-top-count">' + w.count + '</span>' +
                '</div>';
        }
        container.innerHTML = html;
    }

    function renderTypeDist(types) {
        var container = document.getElementById('type-dist');
        if (!container) return;
        if (!types || types.length === 0) {
            container.innerHTML = '<p class="text-muted" style="font-size:var(--text-sm);">' + <?php echo json_encode(t('admin_swl_js_no_data', '暂无数据')); ?> + '</p>';
            return;
        }
        var total = 0;
        for (var i = 0; i < types.length; i++) total += types[i].count;
        var colors = ['#6366f1', '#ef4444', '#10b981', '#f59e0b', '#0ea5e9', '#8b5cf6', '#ec4899'];
        var html = '';
        for (var i = 0; i < types.length; i++) {
            var t = types[i];
            var pct = total > 0 ? Math.round(t.count / total * 100) : 0;
            var color = colors[i % colors.length];
            var label = contentTypeLabels[t.type] || t.type;
            html += '<div class="swl-top-item" style="margin-bottom:0.5rem;">' +
                '<span class="swl-top-rank" style="background:' + color + ';color:#fff;width:auto;padding:0 8px;border-radius:var(--radius-full);font-size:var(--text-xs);">' + escapeHtml(label) + '</span>' +
                '<span class="swl-top-word">' + t.count + <?php echo json_encode(t('admin_swl_js_times', ' 次')); ?> + '</span>' +
                '<span class="swl-top-bar"><span class="swl-top-bar-fill" style="width:' + pct + '%;background:' + color + ';"></span></span>' +
                '<span class="swl-top-count">' + pct + '%</span>' +
                '</div>';
        }
        container.innerHTML = html;
    }

    function renderActiveUsers(users) {
        var container = document.getElementById('active-users');
        if (!container) return;
        if (!users || users.length === 0) {
            container.innerHTML = '<p class="text-muted" style="font-size:var(--text-sm);">' + <?php echo json_encode(t('admin_swl_js_no_data', '暂无数据')); ?> + '</p>';
            return;
        }
        var maxCount = users[0].count;
        var html = '';
        for (var i = 0; i < users.length; i++) {
            var u = users[i];
            var pct = Math.round(u.count / maxCount * 100);
            var rankClass = i < 3 ? ' r' + (i + 1) : '';
            html += '<div class="swl-top-item">' +
                '<span class="swl-top-rank' + rankClass + '">' + (i + 1) + '</span>' +
                '<span class="swl-top-word">' + escapeHtml(u.username) + '</span>' +
                '<span class="swl-top-bar"><span class="swl-top-bar-fill" style="width:' + pct + '%;"></span></span>' +
                '<span class="swl-top-count">' + u.count + '</span>' +
                '</div>';
        }
        container.innerHTML = html;
    }

    // 前置插入新日志
    function prependLogs(logs) {
        if (!logs || logs.length === 0) return;
        var emptyMsg = document.getElementById('empty-msg');
        if (emptyMsg) emptyMsg.parentElement.remove();

        for (var i = logs.length - 1; i >= 0; i--) {
            var log = logs[i];
            // 避免重复
            if (logList.querySelector('[data-id="' + log.id + '"]')) continue;

            var item = document.createElement('div');
            item.className = 'swl-log-item act-' + log.action + ' new';
            item.setAttribute('data-id', log.id);

            var actionBadge = '';
            if (log.action === 'replace') actionBadge = '<span class="badge badge-secondary">' + <?php echo json_encode(t('admin_swl_action_replace', '替换')); ?> + '</span>';
            else if (log.action === 'reject') actionBadge = '<span class="badge badge-danger">' + <?php echo json_encode(t('admin_swl_action_reject', '拦截')); ?> + '</span>';
            else if (log.action === 'review') actionBadge = '<span class="badge badge-warning">' + <?php echo json_encode(t('admin_swl_action_review', '审核')); ?> + '</span>';

            var typeLabel = contentTypeLabels[log.content_type] || log.content_type;

            item.innerHTML =
                '<div class="swl-log-info">' +
                    '<div class="swl-log-top">' +
                        '<span class="swl-log-word">' + escapeHtml(log.matched_word) + '</span>' +
                        actionBadge +
                        '<span class="swl-log-user">' + escapeHtml(log.username || ('UID:' + log.user_id)) + '</span>' +
                        '<span class="swl-log-user">· ' + escapeHtml(typeLabel) + '</span>' +
                    '</div>' +
                    '<div class="swl-log-snippet">' + escapeHtml(log.original_snippet) + '</div>' +
                '</div>' +
                '<div class="swl-log-time">' + escapeHtml(log.created_at) + '</div>';

            logList.insertBefore(item, logList.firstChild);

            // 更新 lastId
            if (parseInt(log.id) > lastId) lastId = parseInt(log.id);
        }

        // 移除超出最大显示数的旧条目
        while (logList.children.length > maxDisplay) {
            logList.removeChild(logList.lastChild);
        }
    }

    // 执行一次刷新
    function refresh() {
        if (checking) return;
        checking = true;
        var params = 'mode=all&last_id=' + lastId + '&limit=20';
        fetch(ajaxUrl + '&' + params, { credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.error) {
                    console.error('AJAX error:', data.error);
                    return;
                }
                if (data.stats) updateStats(data.stats);
                if (data.logs && data.logs.length > 0) {
                    prependLogs(data.logs);
                    if (data.max_id > lastId) lastId = data.max_id;
                }
            })
            .catch(function(err) {
                console.error('Fetch error:', err);
            })
            .then(function() { checking = false; }); // 无论成败都释放去重锁
    }

    // 切换实时监控
    function toggleLive() {
        liveEnabled = !liveEnabled;
        if (liveEnabled) {
            toggleText.textContent = <?php echo json_encode(t('admin_swl_live_monitoring', '实时监控中')); ?>;
            toggleDot.classList.remove('paused');
            liveIndicator.classList.remove('paused');
            liveStatus.textContent = <?php echo json_encode(t('admin_swl_live_every_second', '每秒自动刷新')); ?>;
            refresh();
            timer = setInterval(refresh, refreshInterval);
        } else {
            toggleText.textContent = <?php echo json_encode(t('admin_swl_live_paused', '已暂停')); ?>;
            toggleDot.classList.add('paused');
            liveIndicator.classList.add('paused');
            liveStatus.textContent = <?php echo json_encode(t('admin_swl_live_paused_auto', '已暂停自动刷新')); ?>;
            if (timer) { clearInterval(timer); timer = null; }
        }
    }

    toggleBtn.addEventListener('click', toggleLive);

    // 初始加载统计
    refresh();
    // 启动定时刷新
    timer = setInterval(refresh, refreshInterval);
})();
</script>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
