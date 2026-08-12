<?php
/**
 * 云界论坛 - 管理后台「IP 库管理」
 *
 * 提供：
 *   1. 当前 IP 库状态（xdb 文件路径、数据规模、大小、更新时间、抽样验证、访客覆盖比例）。
 *   2. 在线查询单个 IP 的归属地（离线数据库，无需联网）。
 *   3. 上传官方 xdb 文件 ip2region_v4.xdb / ip2region_v6.xdb 更新 IP 库。
 *   4. 删除 IP 库 xdb 文件（删除后流量监测将无法展示地域信息）。
 *
 * 数据形态：ip2region 官方 xdb 二进制直读（app/data/ip2region_v4.xdb / v6.xdb）。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：IP 库管理仅超级管理员可用
require_super_admin();

$pageTitle  = t('ipdb_title', 'IP 库管理');
$activeMenu = 'ip_database';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo e(t('ipdb_title', 'IP 库管理')); ?></h1>
        <p class="page-subtitle"><?php echo e(t('ipdb_desc', '离线 IP 归属地库管理（参考 ip2region），用于流量监测的访客地域展示与统计。')); ?></p>
    </div>
</div>

<!-- 库文件状态 -->
<div class="card mb-6">
    <h2 class="card-title mb-1"><?php echo e(t('ipdb_status_title', '库文件状态')); ?></h2>
    <div class="update-meta" id="ipdb-status">
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('ipdb_status_state', '状态')); ?></span>
            <span class="update-meta-value" id="ipdb-status-state">--</span>
        </div>
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('ipdb_status_path', '文件路径')); ?></span>
            <span class="update-meta-value" id="ipdb-status-path">--</span>
        </div>
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('ipdb_status_scale', '数据规模')); ?></span>
            <span class="update-meta-value" id="ipdb-status-scale">--</span>
        </div>
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('ipdb_status_size', '文件大小')); ?></span>
            <span class="update-meta-value" id="ipdb-status-size">--</span>
        </div>
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('ipdb_status_mtime', '更新时间')); ?></span>
            <span class="update-meta-value" id="ipdb-status-mtime">--</span>
        </div>
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('ipdb_status_sample', '抽样验证')); ?></span>
            <span class="update-meta-value" id="ipdb-status-sample">--</span>
        </div>
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('ipdb_status_covered', '访客数据地域覆盖')); ?></span>
            <span class="update-meta-value" id="ipdb-status-covered">--</span>
        </div>
    </div>
</div>

<!-- 下载 IP 库（可选安装） -->
<div class="card mb-6">
    <h2 class="card-title mb-1"><?php echo e(t('ipdb_download_title', '下载 IP 库')); ?></h2>
    <p class="text-muted mb-2 text-xs"><?php echo e(t('ipdb_download_hint', 'IP 库为可选项，默认不随系统分发。请选择来源一键下载并安装，支持断点续传、随时暂停/继续/取消。')); ?></p>
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" id="ipdb-dl-github-btn"><?php echo e(t('ipdb_download_btn_github', '从 GitHub 下载并安装')); ?></button>
        <button type="button" class="btn btn-primary" id="ipdb-dl-cn-btn"><?php echo e(t('ipdb_download_btn_cn', '从国内网盘下载并安装')); ?></button>
    </div>
    <p class="text-muted mb-0 text-xs" style="margin-top:0.75rem;"><?php echo e(t('ipdb_download_cn_open', '国内网盘手动下载（备用，若服务端下载失败）：')); ?>
        <a href="https://pan.szczk.top/f/d/xL4u1/ip2region_v4.xdb" target="_blank" rel="noopener">ip2region_v4.xdb</a> ·
        <a href="https://pan.szczk.top/f/d/l7PTx/ip2region_v6.xdb" target="_blank" rel="noopener">ip2region_v6.xdb</a>
    </p>
    <div id="ipdb-dl-panel" class="update-progress-wrap" style="display:none;">
        <div id="ipdb-dl-progress"></div>
        <div style="display:flex;gap:0.5rem;margin-top:0.75rem;align-items:center;">
            <button type="button" class="btn btn-primary" id="ipdb-dl-pause-btn"><?php echo e(t('ipdb_dl_pause', '暂停')); ?></button>
            <button type="button" class="btn btn-primary" id="ipdb-dl-resume-btn"><?php echo e(t('ipdb_dl_resume', '继续')); ?></button>
            <button type="button" class="btn btn-danger" id="ipdb-dl-cancel-btn"><?php echo e(t('ipdb_dl_cancel', '取消')); ?></button>
        </div>
        <div id="ipdb-dl-msg" class="mt-2" style="margin-top:0.5rem;"></div>
    </div>
</div>

<div class="tm-grid-2">
    <!-- 在线查询 -->
    <div class="card">
        <h2 class="card-title mb-1"><?php echo e(t('ipdb_query_title', '在线查询')); ?></h2>
        <p class="text-muted mb-2 text-xs"><?php echo e(t('ipdb_query_hint', '使用已安装的离线 IP 库查询归属地，无需联网。')); ?></p>
        <div class="input-group">
            <input type="text" id="ipdb-query-input" class="form-input" placeholder="<?php echo e(t('ipdb_query_placeholder', '输入 IP 地址，如 223.5.5.5')); ?>" autocomplete="off">
            <button type="button" class="btn btn-primary" id="ipdb-query-btn"><?php echo e(t('ipdb_query_btn', '查询')); ?></button>
        </div>
        <div id="ipdb-query-result" class="mt-2"></div>
    </div>

    <!-- 上传更新 -->
    <div class="card">
        <h2 class="card-title mb-1"><?php echo e(t('ipdb_upload_title', '上传更新')); ?></h2>
        <p class="text-muted mb-2 text-xs"><?php echo e(t('ipdb_upload_hint', '支持 ip2region 官方 xdb 文件 ip2region_v4.xdb / ip2region_v6.xdb，上传后立即生效（原子替换），单个文件不超过 200MB。')); ?></p>
        <div class="input-group">
            <input type="file" id="ipdb-upload-file" class="form-input" accept=".xdb">
            <button type="button" class="btn btn-primary" id="ipdb-upload-btn"><?php echo e(t('ipdb_upload_btn', '上传并导入')); ?></button>
        </div>
        <div id="ipdb-upload-result" class="mt-2"></div>
    </div>
</div>

<!-- 危险操作 -->
<div class="card mt-6" style="border-color: var(--danger, #ef4444);">
    <h2 class="card-title mb-1" style="color: var(--danger, #ef4444);"><?php echo e(t('ipdb_danger_title', '危险操作')); ?></h2>
    <div class="d-flex" style="display:flex;align-items:center;gap:1rem;justify-content:space-between;flex-wrap:wrap;">
        <p class="text-muted mb-0" style="margin:0;"><?php echo e(t('ipdb_delete_hint', '删除 IP 库 xdb 文件后，新访问记录将不再记录地域信息，历史地域数据保留展示。')); ?></p>
        <button type="button" class="btn btn-danger" id="ipdb-delete-btn"><?php echo e(t('ipdb_delete_btn', '删除 IP 库')); ?></button>
    </div>
</div>

<script>
(function () {
    // 接口地址：两条通道都基于当前脚本目录推导（支持子目录部署）：
    //   1) 路由形式  <子目录>/index.php?route=admin/api/ip_db_ajax   —— 不依赖 /app/ 直连，也不依赖伪静态重写
    //   2) 直连文件  <子目录>/app/admin/api/ip_db_ajax.php           —— 若 nginx 未拦截 /app/ 目录则可用
    // 默认优先走路由；任一通道返回 404（旧版 index.php 无 admin/api 路由 / /app/ 被 nginx deny）时，
    // 自动切换另一条通道再试一次，并把选中的通道固定下来。
    var apiBase = '<?php echo e(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\')); ?>';
    var apiRouted = apiBase + '/index.php?route=admin/api/ip_db_ajax';
    var apiDirect = apiBase + '/app/admin/api/ip_db_ajax.php';
    var apiUseDirect = false;  // 当前通道：false=路由，true=直连
    var apiSwitched = false;   // 是否已切换过通道（最多切换一次）
    var csrfToken = '<?php echo csrf_token(); ?>';

    function apiUrlFor(action) {
        var base = apiUseDirect ? apiDirect : apiRouted;
        return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'action=' + encodeURIComponent(action);
    }

    function apiFetch(action, opts) {
        var url = apiUrlFor(action);
        return fetch(url, opts).then(function (r) {
            // 404（路由不存在 / /app/ 被拦截）时切换另一条通道重试一次
            if (!apiSwitched && r.status === 404) {
                apiSwitched = true;
                apiUseDirect = !apiUseDirect;
                console.warn('[ipdb] 接口 404，切换通道重试 -> ' + (apiUseDirect ? '直连' : '路由'));
                return apiFetch(action, opts);
            }
            return r;
        });
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }

    function fmtSize(bytes) {
        if (!bytes) return '--';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return bytes + ' B';
    }

    function fmtTime(ts) {
        if (!ts || ts === '0') return '--';
        var d = new Date(parseInt(ts, 10) * 1000);
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function postForm(action, formData) {
        formData.append('csrf_token', csrfToken);
        return apiFetch(action, { method: 'POST', body: formData, cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) {
                return r.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        // 服务器返回了非 JSON（404/500 HTML 错误页等），保留状态码便于定位
                        return { success: false, error: 'HTTP ' + r.status + '：服务器返回了非 JSON 响应' };
                    }
                });
            });
    }

    function showMsg(el, html, isError) {
        el.innerHTML = '<div class="' + (isError ? 'alert alert-error' : 'alert alert-success') + '">' + html + '</div>';
    }

    // ========== 状态加载 ==========
    function loadStatus() {
        var stateEl = document.getElementById('ipdb-status-state');
        stateEl.textContent = '<?php echo e(t('ipdb_status_loading', '加载中…')); ?>';
        apiFetch('status', { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    stateEl.textContent = '<?php echo e(t('ipdb_status_load_failed', '加载失败')); ?>';
                    return;
                }
                var st = data.status || {};
                if (!st.exists) {
                    stateEl.textContent = '<?php echo e(t('ipdb_status_missing', '未安装')); ?>';
                    stateEl.style.color = 'var(--danger, #ef4444)';
                    document.getElementById('ipdb-status-path').textContent = st.file || '--';
                    document.getElementById('ipdb-status-scale').textContent = '--';
                    document.getElementById('ipdb-status-size').textContent = '--';
                    document.getElementById('ipdb-status-mtime').textContent = '--';
                    document.getElementById('ipdb-status-sample').textContent = '--';
                    document.getElementById('ipdb-status-covered').textContent = '--';
                    return;
                }
                stateEl.textContent = '<?php echo e(t('ipdb_status_installed', '已安装')); ?>';
                stateEl.style.color = '';
                document.getElementById('ipdb-status-path').textContent = st.file || '--';
                var scale = 'IPv4 ' + (st.v4_lines || 0) + ' 段 · IPv6 ' + (st.v6_lines || 0) + ' 段';
                document.getElementById('ipdb-status-scale').textContent = scale;
                document.getElementById('ipdb-status-size').textContent = fmtSize(st.size);
                var mtimeParts = [];
                if (st.v4_updated && st.v4_updated !== '0') mtimeParts.push('IPv4 ' + fmtTime(st.v4_updated));
                if (st.v6_updated && st.v6_updated !== '0') mtimeParts.push('IPv6 ' + fmtTime(st.v6_updated));
                document.getElementById('ipdb-status-mtime').textContent = mtimeParts.join(' / ') || '--';
                var sampleEl = document.getElementById('ipdb-status-sample');
                if (st.sample_ok) {
                    sampleEl.textContent = '<?php echo e(t('ipdb_status_sample_ok', '正常')); ?>';
                    sampleEl.style.color = '';
                } else {
                    sampleEl.textContent = '<?php echo e(t('ipdb_status_sample_fail', '无法命中')); ?>';
                    sampleEl.style.color = 'var(--danger, #ef4444)';
                }
                var coveredEl = document.getElementById('ipdb-status-covered');
                if (data.covered_ratio !== null && data.covered_ratio !== undefined) {
                    coveredEl.textContent = data.covered_ratio + '%';
                } else {
                    coveredEl.textContent = '--';
                }
            })
            .catch(function () {
                stateEl.textContent = '<?php echo e(t('ipdb_status_load_failed', '加载失败')); ?>';
            });
    }

    // ========== 在线查询 ==========
    var queryBtn = document.getElementById('ipdb-query-btn');
    queryBtn.addEventListener('click', function () {
        var ip = document.getElementById('ipdb-query-input').value.trim();
        var resultEl = document.getElementById('ipdb-query-result');
        if (!ip) { showMsg(resultEl, '<?php echo e(t('ipdb_query_invalid_ip', '请输入合法的 IP 地址。')); ?>', true); return; }
        var fd = new FormData();
        fd.append('ip', ip);
        queryBtn.disabled = true;
        queryBtn.textContent = '<?php echo e(t('ipdb_querying', '查询中…')); ?>';
        postForm('query', fd)
            .then(function (data) {
                if (!data.success) {
                    showMsg(resultEl, esc(data.error), true);
                    return;
                }
                var html = '';
                html += '<div class="ipdb-query-box" style="background:var(--bg-soft,#f8fafc);border:1px solid var(--border,#e5e7eb);border-radius:8px;padding:0.75rem 1rem;">';
                html += '<div style="font-size:0.8rem;color:var(--muted,#6b7280);">' + esc(data.ip) + ' <?php echo e(t('ipdb_query_result', '查询结果')); ?>：</div>';
                html += '<div style="font-size:1.05rem;font-weight:600;margin-top:0.25rem;">' + esc(data.display || data.raw) + '</div>';
                if (data.raw) {
                    html += '<div style="font-size:0.75rem;color:var(--muted,#6b7280);margin-top:0.25rem;">' + esc(data.raw) + '</div>';
                }
                html += '</div>';
                resultEl.innerHTML = html;
            })
            .catch(function () {
                showMsg(resultEl, '<?php echo e(t('ipdb_query_failed', '查询失败，请稍后重试。')); ?>', true);
            })
            .finally(function () {
                queryBtn.disabled = false;
                queryBtn.textContent = '<?php echo e(t('ipdb_query_btn', '查询')); ?>';
            });
    });

    // ========== 上传更新 ==========
    var uploadBtn = document.getElementById('ipdb-upload-btn');
    uploadBtn.addEventListener('click', function () {
        var fileInput = document.getElementById('ipdb-upload-file');
        var resultEl = document.getElementById('ipdb-upload-result');
        var file = fileInput.files[0];
        if (!file) { showMsg(resultEl, '<?php echo e(t('ipdb_upload_no_file', '未收到上传文件。')); ?>', true); return; }
        var fd = new FormData();
        fd.append('file', file);
        uploadBtn.disabled = true;
        uploadBtn.textContent = '<?php echo e(t('ipdb_uploading', '导入中…')); ?>';
        postForm('upload', fd)
            .then(function (data) {
                if (!data.success) {
                    showMsg(resultEl, esc(data.error), true);
                    return;
                }
                var msg = '';
                if (data.ok) {
                    // zip 包：可能导入 v4/v6 一个或两个
                    var names = Object.keys(data.ok);
                    var parts = names.map(function (n) {
                        var r = data.ok[n];
                        return n + '（' + (r.imported || 0) + ' 段）';
                    });
                    msg = '<?php echo e(t('ipdb_upload_ok', 'IP 库已更新')); ?>：' + parts.join('、');
                } else {
                    msg = '<?php echo e(t('ipdb_upload_ok', 'IP 库已更新')); ?>（' + (data.type || '') + '，导入 ' + (data.imported || 0) + ' 段）';
                }
                showMsg(resultEl, esc(msg), false);
                fileInput.value = '';
                loadStatus();
            })
            .catch(function () {
                showMsg(resultEl, '<?php echo e(t('ipdb_upload_failed', '上传失败，请稍后重试。')); ?>', true);
            })
            .finally(function () {
                uploadBtn.disabled = false;
                uploadBtn.textContent = '<?php echo e(t('ipdb_upload_btn', '上传并导入')); ?>';
            });
    });

    // ========== 分片下载（多线程并发 / 暂停 / 继续 / 取消） ==========
    var CHUNK = 4 * 1024 * 1024; // 单分片上限（服务端 ≤8MB）
    var PROBE_TIMEOUT = 90 * 1000; // 连接下载源超时（毫秒），超过则自动终止
    var MAX_THREADS = 4; // 并发下载线程数
    var dl = {
        source: '',
        status: 'none',                    // none | downloading | done
        files: { v4: null, v6: null },     // {total, bytes, done, error, chunks:[{off,size,done,failed}]}
        verOrder: ['v4', 'v6'],
        running: false,                    // 分片调度是否在跑
        paused: false,                     // 是否暂停
        busy: false,                       // 探测/启动请求进行中
        cancelPending: false,              // 已请求取消，等当前分片结束后执行
        startTimeout: false,               // 连接下载源超时，已自动终止
        taskId: 0,                         // 任务标识，用于丢弃旧任务的迟到线程结果
        taskSeq: 0,
        threads: {},                       // 活动线程: tid -> {v, idx, t0}
        threadSeq: 0,
        activeThreads: 0,                  // 当前活动线程数
        errors: {},                        // ver -> 最近一次分片错误信息
        bytesAccum: 0,                     // 本次运行累计已写入字节（用于平均速度）
        startedAt: 0,                      // 本次运行开始时间戳（用于平均速度）
        speed: 0
    };
    var dlPanel = document.getElementById('ipdb-dl-panel');
    var dlProgress = document.getElementById('ipdb-dl-progress');
    var dlMsg = document.getElementById('ipdb-dl-msg');
    var dlPauseBtn = document.getElementById('ipdb-dl-pause-btn');
    var dlResumeBtn = document.getElementById('ipdb-dl-resume-btn');
    var dlCancelBtn = document.getElementById('ipdb-dl-cancel-btn');
    var DL_TXT = {
        ipv4: '<?php echo e(t('ipdb_dl_ver_ipv4', 'IPv4 库')); ?>',
        ipv6: '<?php echo e(t('ipdb_dl_ver_ipv6', 'IPv6 库')); ?>',
        start: '<?php echo e(t('ipdb_dl_starting', '正在连接下载源…')); ?>',
        downloading: '<?php echo e(t('ipdb_dl_downloading', '正在下载，请勿关闭页面…')); ?>',
        finalize: '<?php echo e(t('ipdb_dl_finalizing', '正在校验并安装…')); ?>',
        cancelConfirm: '<?php echo e(t('ipdb_dl_cancel_confirm', '确定取消下载并清除已下载的数据吗？')); ?>',
        restartConfirm: '<?php echo e(t('ipdb_dl_restart_confirm', '已有进行中的下载任务，重新开始将清除已下载的进度。确定重新下载吗？')); ?>',
        ok: '<?php echo e(t('ipdb_download_ok', '下载并安装成功')); ?>',
        partial: '<?php echo e(t('ipdb_download_partial', '部分文件失败')); ?>',
        netErr: '<?php echo e(t('ipdb_dl_network_error', '网络错误，下载中断。可点击「继续」重试。')); ?>',
        noProg: '<?php echo e(t('ipdb_dl_no_progress', '未能获取数据，请重试或更换下载源。')); ?>',
        fetchFailed: '<?php echo e(t('ipdb_dl_fetch_failed', '下载分片失败')); ?>',
        resumeHint: '<?php echo e(t('ipdb_dl_resume_hint', '检测到未完成的下载任务，可点击「继续」恢复下载。')); ?>',
        startTimeout: '<?php echo e(t('ipdb_dl_start_timeout', '连接下载源超时，已自动终止下载。请检查服务器网络后重试。')); ?>'
    };

    function verLabel(v) { return v === 'v6' ? DL_TXT.ipv6 : DL_TXT.ipv4; }

    function dlSetMsg(html, isError) {
        if (!dlMsg) return;
        dlMsg.innerHTML = html ? '<div class="' + (isError ? 'alert alert-error' : 'alert alert-success') + '">' + html + '</div>' : '';
    }

    // ---------- 多线程分片：localStorage 断点记录 ----------
    function dlLsKey(v) { return 'ipdb_dl_progress:' + dl.source + ':' + v; }
    function dlLsSave(v) {
        var f = dl.files[v];
        if (!f || !f.chunks) return;
        try {
            localStorage.setItem(dlLsKey(v), JSON.stringify(f.chunks.map(function (c) { return c.done ? 1 : 0; })));
        } catch (e) { /* 忽略存储失败 */ }
    }
    function dlLsClear(v) { try { localStorage.removeItem(dlLsKey(v)); } catch (e) {} }
    function dlLsClearAll() { dl.verOrder.forEach(function (v) { dlLsClear(v); }); }

    // 按文件总大小切分分片（最后一片不足 CHUNK）
    function dlChunksFor(f) {
        var arr = [];
        if (!f.total || f.total < 1) return arr;
        var n = Math.ceil(f.total / CHUNK);
        for (var i = 0; i < n; i++) {
            arr.push({ off: i * CHUNK, size: Math.min(CHUNK, f.total - i * CHUNK), done: false, failed: false });
        }
        return arr;
    }

    // 从 localStorage 恢复分片完成情况；无记录（如换浏览器）时按已下载字节数回退为连续前缀
    function dlRestoreChunks(v, f) {
        var chunks = dlChunksFor(f);
        var ls = null;
        try { ls = JSON.parse(localStorage.getItem(dlLsKey(v)) || 'null'); } catch (e) { ls = null; }
        if (ls && Array.isArray(ls) && ls.length === chunks.length) {
            for (var i = 0; i < ls.length; i++) if (ls[i]) chunks[i].done = true;
        } else if (f.bytes > 0 && chunks.length) {
            var full = Math.floor(f.bytes / CHUNK);
            for (var j = 0; j < chunks.length && j < full; j++) chunks[j].done = true;
        }
        return chunks;
    }

    // 按分片完成情况重算已下载字节数与完成标记
    function dlApplyChunks(v) {
        var f = dl.files[v];
        if (!f || !f.chunks) return;
        var bytes = 0, done = 0;
        f.chunks.forEach(function (c) { if (c.done) { bytes += c.size; done++; } });
        f.bytes = bytes;
        f.done = f.chunks.length > 0 && done === f.chunks.length;
    }

    function dlApplyTask(task) {
        if (!task) return;
        dl.source = task.source || dl.source;
        dl.status = task.status || 'downloading';
        var files = task.files || { v4: null, v6: null };
        dl.verOrder.forEach(function (v) {
            var f = files[v];
            if (!f) { files[v] = null; return; }
            f.total = f.total || 0;
            f.error = false;
            f.chunks = dlRestoreChunks(v, f);
            dlApplyChunks(v);
        });
        dl.files = files;
        dl.errors = {};
        dl.threads = {};
        dl.activeThreads = 0;
        dl.bytesAccum = 0;
        dl.startedAt = Date.now();
    }

    function dlRender() {
        if (!dlPanel) return;
        if (dl.status === 'none' || !dl.files) { dlPanel.style.display = 'none'; return; }
        dlPanel.style.display = '';
        var html = '';
        dl.verOrder.forEach(function (v) {
            var f = dl.files[v];
            if (!f) return;
            var total = f.total || 0;
            var bytes = f.bytes || 0;
            var pct = total > 0 ? Math.min(100, Math.round(bytes / total * 100)) : 0;
            var barCls = f.error ? ' is-error' : (f.done ? ' is-done' : '');
            var stageCls = f.done ? ' is-done' : (f.error ? ' is-error' : '');
            html += '<div class="update-progress-header">';
            html += '<span class="update-progress-stage' + stageCls + '">' + verLabel(v) + '</span>';
            html += '<span class="update-progress-pct">' + pct + '%</span></div>';
            html += '<div class="update-progress-bar-outer"><div class="update-progress-bar-inner' + barCls + '" style="width:' + pct + '%"></div></div>';
            html += '<div class="update-progress-detail">' + fmtSize(bytes) + ' / ' + (total ? fmtSize(total) : '--') + '</div>';
        });
        if (dl.running && !dl.paused && dl.activeThreads > 0) {
            var el = (Date.now() - dl.startedAt) / 1000;
            dl.speed = el > 0 ? dl.bytesAccum / el : 0;
            if (dl.speed > 0) {
                html += '<div class="update-progress-detail"><?php echo e(t('ipdb_dl_speed', '总速度')); ?> ' + fmtSize(dl.speed) + '/s · ' + dl.activeThreads + ' <?php echo e(t('ipdb_dl_threads', '线程')); ?></div>';
            }
        }
        dlProgress.innerHTML = html;

        var downloading = dl.status === 'downloading';
        var starting = dl.busy && !dl.running; // 正在探测/启动，尚未进入分片循环
        dlPauseBtn.style.display = (downloading && dl.running && !dl.paused) ? '' : 'none';
        dlResumeBtn.style.display = (downloading && !starting && !dl.startTimeout && (!dl.running || dl.paused)) ? '' : 'none';
        dlCancelBtn.style.display = downloading ? '' : 'none';
    }

    // 探测/启动类请求的防卡死保护：超过 PROBE_TIMEOUT 毫秒未返回则自动取消并提示
    function dlProbeRequest(makeFormData, onSuccess) {
        dl.startTimeout = false;
        dl.busy = true;
        dlRender();
        var timer = setTimeout(function () {
            dl.startTimeout = true;
            dl.busy = false;
            dlSetMsg(DL_TXT.startTimeout, true);
            dlRender();
            var cfd = new FormData();
            postForm('dl_cancel', cfd).catch(function () { /* 忽略 */ });
        }, PROBE_TIMEOUT);
        postForm('dl_start', makeFormData())
            .then(function (data) {
                clearTimeout(timer);
                if (dl.startTimeout) return; // 已超时自动终止，忽略迟到的响应
                dl.busy = false;
                if (dl.cancelPending) { dl.cancelPending = false; dlDoCancel(); return; } // 探测期间被取消
                if (!data.success) { dlSetMsg(esc(data.error), true); dlRender(); return; }
                onSuccess(data);
            })
            .catch(function () {
                clearTimeout(timer);
                if (dl.startTimeout) return;
                dl.busy = false;
                if (dl.cancelPending) { dl.cancelPending = false; dlDoCancel(); return; }
                dlSetMsg(DL_TXT.netErr, true);
                dlRender();
            });
    }

    function dlStart(source) {
        // 立即显示面板（占位进度），探测阶段就有可见反馈
        dl.source = source;
        dl.status = 'downloading';
        dl.taskId = ++dl.taskSeq;
        dl.files = {
            v4: { total: 0, bytes: 0, done: false, error: false },
            v6: { total: 0, bytes: 0, done: false, error: false }
        };
        dl.running = false;
        dl.paused = false;
        dl.cancelPending = false;
        dl.threads = {};
        dl.activeThreads = 0;
        dl.errors = {};
        dl.bytesAccum = 0;
        dlLsClearAll(); // 全新下载，清掉旧分片记录
        dlSetMsg(DL_TXT.start, false);
        dlProbeRequest(function () {
            var fd = new FormData();
            fd.append('source', source);
            return fd;
        }, function (data) {
            dlApplyTask(data.task);
            dl.running = true;
            dl.paused = false;
            dlSetMsg(DL_TXT.downloading, false);
            dlRender();
            dlPump();
        });
    }

    function dlResume() {
        if (!dl.source) {
            dlSetMsg(DL_TXT.netErr, true);
            return;
        }
        dlStartWithResume(dl.source);
    }

    function dlStartWithResume(source) {
        dl.status = 'downloading';
        dl.taskId = ++dl.taskSeq;
        dl.cancelPending = false;
        dlSetMsg(DL_TXT.start, false);
        dlProbeRequest(function () {
            var fd = new FormData();
            fd.append('source', source);
            fd.append('resume', '1');
            return fd;
        }, function (data) {
            dlApplyTask(data.task);
            dl.running = true;
            dl.paused = false;
            dlSetMsg(DL_TXT.downloading, false);
            dlRender();
            dlPump();
        });
    }

    // 判断某分片是否正在被线程下载
    function dlThreadForChunk(v, i) {
        var keys = Object.keys(dl.threads);
        for (var k = 0; k < keys.length; k++) {
            var t = dl.threads[keys[k]];
            if (t && t.v === v && t.idx === i) return t;
        }
        return null;
    }

    // 调度器：填补空闲线程，线程全部结束后判断收尾（全部完成 → 安装；有失败 → 停止等待重试）
    function dlPump() {
        if (!dl.running || dl.paused) { dlRender(); return; }
        while (dl.activeThreads < MAX_THREADS) {
            var pick = null;
            dl.verOrder.forEach(function (v) {
                if (pick) return;
                var f = dl.files[v];
                if (!f || f.done) return;
                for (var i = 0; i < f.chunks.length; i++) {
                    var c = f.chunks[i];
                    if (c.done || c.failed) continue;
                    if (dlThreadForChunk(v, i)) continue;
                    pick = { v: v, i: i };
                    return;
                }
            });
            if (!pick) break;
            dlFetchThread(pick.v, pick.i);
        }
        if (dl.activeThreads === 0) {
            var allDone = true, hasErr = false, hasPending = false;
            dl.verOrder.forEach(function (v) {
                var f = dl.files[v];
                if (!f) return;
                if (!f.done) allDone = false;
                if (f.error) hasErr = true;
                (f.chunks || []).forEach(function (c) { if (!c.done && !c.failed) hasPending = true; });
            });
            if (allDone) {
                dl.running = false;
                dlFinalize();
                return;
            }
            if (hasErr || !hasPending) {
                var errs = [];
                dl.verOrder.forEach(function (v) { if (dl.errors[v]) errs.push(verLabel(v) + '：' + dl.errors[v]); });
                dlSetMsg((errs.length ? errs.join('；') + '。' : '') + DL_TXT.netErr, true);
                dl.running = false;
                dlRender();
                return;
            }
        }
        dlRender();
    }

    function dlFetchThread(ver, idx) {
        var f = dl.files[ver];
        var chunk = f.chunks[idx];
        var tid = 't' + (++dl.threadSeq);
        var taskId = dl.taskId;
        dl.threads[tid] = { v: ver, idx: idx, t0: Date.now() };
        dl.activeThreads++;
        var fd = new FormData();
        fd.append('ver', ver);
        fd.append('offset', String(chunk.off));
        fd.append('size', String(chunk.size));
        postForm('dl_fetch', fd)
            .then(function (data) {
                delete dl.threads[tid];
                if (dl.activeThreads > 0) dl.activeThreads--;
                if (taskId !== dl.taskId) { dlRender(); return; } // 任务已被替换，丢弃旧线程结果
                if (dl.cancelPending) { dl.cancelPending = false; dlDoCancel(); return; }
                if (!dl.running || dl.paused) {
                    // 暂停：已完整写回的仍计入并落盘，但不调度新分片
                    if (data.success && data.written >= chunk.size) {
                        chunk.done = true;
                        dlApplyChunks(ver);
                        dlLsSave(ver);
                    }
                    dlRender();
                    return;
                }
                if (!data.success) {
                    if (data.error === 'no_task') {
                        // 服务端任务已不存在（如被另一页面取消），停止所有线程并隐藏面板
                        dl.running = false;
                        dl.status = 'none';
                        dl.taskId = ++dl.taskSeq;
                        dl.files = { v4: null, v6: null };
                        dl.threads = {};
                        dl.activeThreads = 0;
                        dlRender();
                        return;
                    }
                    chunk.failed = true;
                    f.error = true;
                    dl.errors[ver] = data.error || DL_TXT.fetchFailed;
                } else if (data.written < chunk.size || data.next_offset === data.offset) {
                    chunk.failed = true;
                    f.error = true;
                    dl.errors[ver] = DL_TXT.noProg;
                } else {
                    chunk.done = true;
                    dl.bytesAccum += data.written;
                    dlApplyChunks(ver);
                    dlLsSave(ver);
                }
                dlRender();
                dlPump();
            })
            .catch(function () {
                delete dl.threads[tid];
                if (dl.activeThreads > 0) dl.activeThreads--;
                if (taskId !== dl.taskId) { dlRender(); return; }
                if (dl.cancelPending) { dl.cancelPending = false; dlDoCancel(); return; }
                if (!dl.running || dl.paused) { dlRender(); return; }
                chunk.failed = true;
                f.error = true;
                dl.errors[ver] = DL_TXT.netErr;
                dlRender();
                dlPump();
            });
    }

    function dlFinalize() {
        dl.busy = true;
        dlSetMsg(DL_TXT.finalize, false);
        dlRender();
        var fd = new FormData();
        postForm('dl_finalize', fd)
            .then(function (data) {
                dl.busy = false;
                if (!data.success) {
                    dlSetMsg(esc(data.error || '<?php echo e(t('ipdb_dl_finalize_failed', '校验安装失败')); ?>'), true);
                    dlRender();
                    return;
                }
                var okList = Object.keys(data.ok || {}).map(function (n) {
                    return n.toUpperCase() + '（' + ((data.ok[n] && data.ok[n].imported) || 0) + ' 段）';
                });
                var msg = DL_TXT.ok + '：' + okList.join('、');
                if (data.errors && Object.keys(data.errors).length) {
                    msg += '。' + DL_TXT.partial + ': ' + esc(Object.keys(data.errors).join(', '));
                }
                dlLsClearAll(); // 安装完成，清理分片记录
                dlSetMsg(msg, !okList.length);
                dl.status = 'done';
                dl.files = { v4: null, v6: null };
                dl.running = false;
                dlRender();
                loadStatus();
            })
            .catch(function () {
                dl.busy = false;
                dlSetMsg(DL_TXT.netErr, true);
                dlRender();
            });
    }

    function dlStartBtn(source) {
        if (dl.status === 'downloading') {
            if (!window.confirm(DL_TXT.restartConfirm)) return;
            dl.running = false;
            dl.paused = false;
        }
        dlStart(source);
    }

    document.getElementById('ipdb-dl-github-btn').addEventListener('click', function () { dlStartBtn('github'); });
    document.getElementById('ipdb-dl-cn-btn').addEventListener('click', function () { dlStartBtn('cn'); });

    dlPauseBtn.addEventListener('click', function () {
        dl.paused = true;
        dl.running = false;
        dlRender();
    });

    dlResumeBtn.addEventListener('click', function () {
        dlResume();
    });

    dlCancelBtn.addEventListener('click', function () {
        if (!window.confirm(DL_TXT.cancelConfirm)) return;
        if (dl.busy) {
            // 当前分片请求进行中，标记待取消，等其结束后执行
            dl.cancelPending = true;
            dl.paused = true;
            dl.running = false;
            dlRender();
            return;
        }
        dlDoCancel();
    });

    function dlDoCancel() {
        dlLsClearAll();
        dl.taskId = ++dl.taskSeq; // 使在途线程的结果全部失效
        dl.busy = true;
        var fd = new FormData();
        postForm('dl_cancel', fd)
            .then(function () {
                dl.busy = false;
                dl.status = 'none';
                dl.files = { v4: null, v6: null };
                dl.source = '';
                dl.running = false;
                dl.paused = false;
                dl.threads = {};
                dl.activeThreads = 0;
                dl.errors = {};
                dlSetMsg('');
                dlRender();
            })
            .catch(function () {
                dl.busy = false;
                dlSetMsg(DL_TXT.netErr, true);
            });
    }

    // 页面加载时检测未完成的下载任务，允许断点续传
    postForm('dl_status', new FormData())
        .then(function (data) {
            if (data.success && data.task && data.task.status === 'downloading') {
                dlApplyTask(data.task);
                dlRender();
                dlSetMsg(DL_TXT.resumeHint, false);
            }
        })
        .catch(function () { /* 静默忽略 */ });

    // ========== 删除 ==========
    document.getElementById('ipdb-delete-btn').addEventListener('click', function () {
        var btn = this;
        if (!window.confirm('<?php echo e(t('ipdb_delete_confirm', '确定要删除 IP 库数据库吗？删除后新访问记录将不再记录地域信息。')); ?>')) return;
        var fd = new FormData();
        btn.disabled = true;
        postForm('delete', fd)
            .then(function (data) {
                if (!data.success) {
                    window.alert(esc(data.error));
                    return;
                }
                window.alert('<?php echo e(t('ipdb_delete_ok', 'IP 库数据库已删除。')); ?>');
                loadStatus();
            })
            .catch(function () {
                window.alert('<?php echo e(t('ipdb_delete_failed', '删除 IP 库数据库失败，请检查文件权限。')); ?>');
            })
            .finally(function () {
                btn.disabled = false;
            });
    });

    // 初始加载状态
    loadStatus();
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
