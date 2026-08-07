/**
 * 云界论坛 - 自建人机验证组件（仿 Cloudflare Turnstile / reCAPTCHA 复选框）
 *
 * 插件化版本（仿 jigsaw-verify）：
 *   - 拼图滑块使用 Canvas + base64 PNG（GD 生成，服务端）
 *   - 拼图小块从背景中裁切，带右侧凸起
 *   - 拖拽滑块时用 canvas 实时渲染拼图小块位置
 *
 * 依赖后台开启人机验证后，页面上存在：
 *   <div id="captcha" data-api="/api/captcha"></div>
 *   <input type="hidden" name="captcha_token" id="captcha_token">
 */
(function () {
    'use strict';

    function init() {
        var container = document.getElementById('captcha');
        if (!container) return;
        var tokenInput = document.getElementById('captcha_token');
        if (!tokenInput) return;
        var apiUrl = container.getAttribute('data-api') || '/api/captcha';

        function apiAction(action) {
            return apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') + 'action=' + action;
        }

        /* ---------- 调试日志（F12 控制台） ---------- */
        function log() {
            if (window.console && window.console.debug) {
                try { window.console.debug.apply(window.console, ['[captcha]'].concat(Array.prototype.slice.call(arguments))); } catch (e) {}
            }
        }

        /* ---------- 行为特征采集（页面级） ---------- */
        var SIG = {
            samples: [],
            clicks: 0,
            keys: 0,
            pageStart: Date.now(),
            noPointer: true,
            lastSample: 0
        };

        document.addEventListener('pointerdown', function () { SIG.noPointer = false; }, { passive: true });
        document.addEventListener('mousemove', function (e) { sample(e.clientX, e.clientY); }, { passive: true });
        document.addEventListener('touchmove', function (e) {
            if (e.touches[0]) sample(e.touches[0].clientX, e.touches[0].clientY);
        }, { passive: true });
        document.addEventListener('click', function () { SIG.clicks++; });
        document.addEventListener('keydown', function () { SIG.keys++; });

        function sample(x, y) {
            SIG.noPointer = false;
            var now = Date.now();
            if (now - SIG.lastSample < 30) return;
            SIG.lastSample = now;
            SIG.samples.push({ x: x, y: y });
            if (SIG.samples.length > 3000) SIG.samples.shift();
        }

        // ========== Popup 模式优化：延迟初始化直到用户点击提交按钮 ==========
        var initDone = false;
        function ensureInitialized() {
            if (state.display !== 'popup' || initDone) return Promise.resolve();
            // 仅在需要时才获取挑战
            if (!state.token) {
                return fetch(apiAction('get'), { cache: 'no-store', credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        log('get ←', data);
                        if (!data || !data.enabled) {
                            log('验证未启用或调试模式');
                            container.style.display = 'none';
                            throw new Error('disabled');
                        }
                        state.token = data.token;
                        tokenInput.value = data.token;
                        initDone = true;
                    });
            } else {
                initDone = true;
                return Promise.resolve();
            }
        }

        function buildSignals() {
            var s = SIG.samples, n = s.length, dist = 0, i;
            for (i = 1; i < n; i++) dist += Math.hypot(s[i].x - s[i - 1].x, s[i].y - s[i - 1].y);
            var cx = 0, cy = 0;
            for (i = 0; i < n; i++) { cx += s[i].x; cy += s[i].y; }
            if (n) { cx /= n; cy /= n; }
            var vari = 0;
            for (i = 0; i < n; i++) vari += Math.hypot(s[i].x - cx, s[i].y - cy);
            vari = n ? vari / n : 0;

            // 恢复轨迹分析：起止点是否在同一侧（判断是直接点击还是有"恢复"动作）
            var recovery = 0;
            var accelChanges = 0;
            if (n >= 2) {
                var first = s[0], last = s[n - 1];
                var dxEnd = last.x - first.x, dyEnd = last.y - first.y;
                // 起止水平距离大且有折返
                if (Math.abs(dxEnd) > 50) {
                    var maxX = first.x, minX = first.x;
                    for (var k = 1; k < n; k++) {
                        if (s[k].x > maxX) maxX = s[k].x;
                        if (s[k].x < minX) minX = s[k].x;
                    }
                    var rangeX = maxX - minX;
                    if (rangeX > Math.abs(dxEnd) * 1.2 && rangeX > 60) {
                        recovery = 1;
                    }
                }
                // 加速度变化计数：检测快速移动→停顿→反向
                var speeds = [];
                for (var j = 1; j < n; j++) {
                    speeds.push(Math.hypot(s[j].x - s[j - 1].x, s[j].y - s[j - 1].y));
                }
                if (speeds.length >= 3) {
                    var avgSpd = speeds.reduce(function(a, b) { return a + b; }, 0) / speeds.length;
                    for (var m = 1; m < speeds.length; m++) {
                        if (speeds[m] > avgSpd * 2 && speeds[m - 1] < avgSpd * 0.5) {
                            accelChanges++;
                        }
                    }
                }
            }

            return {
                samples: n,
                dist: Math.round(dist),
                variance: Math.round(vari),
                elapsed: Date.now() - SIG.pageStart,
                clicks: SIG.clicks,
                keys: SIG.keys,
                noPointer: SIG.noPointer && n === 0,
                recovery: recovery,
                accel_changes: accelChanges
            };
        }

        /* ---------- 组件状态 ---------- */
        var state = { token: '', passed: false, checking: false, display: 'inline' };
        var widget, box, title, sub, body;
        var popupEl = null, popupBody = null, host = null;
        var popupStatus = null, popupStatusTitle = null, popupStatusSub = null;
        var popupStatusTick = null, popupStatusSpinner = null;

        /* ---------- 弹窗/触发模式：点击提交按钮或移入输入框时弹验证 ---------- */
        var formEl = container.closest('form');
        var submitTriggered = false; // 用户已点击提交，验证通过后自动真正提交
        
        // ========== 显示方式优先级：容器 data-display > API 响应 > 默认值 ==========
        var storedDisplay = container.getAttribute('data-display');
        state.display = storedDisplay ? 
            (storedDisplay === 'trigger' || storedDisplay === 'popup') ? storedDisplay : 'inline' :
            'inline';

        if (state.display === 'popup' && formEl) {
            var submitBtn = formEl.querySelector('button[type="submit"], input[type="submit"]');
            if (submitBtn) {
                submitBtn.addEventListener('click', function (e) {
                    // 已通过验证则放行正常提交
                    if (state.passed) return;
                    // 在浏览器原生校验之前拦截，先弹人机验证
                    e.preventDefault();
                    e.stopPropagation();
                    if (!state.checking) {
                        submitTriggered = true;
                        ensureInitialized().then(function () {
                            openPopup();
                            onCheck();
                        }).catch(function (err) {
                            log('init ✗', err);
                            setStatus('error', '验证未就绪', '请刷新页面后重试');
                        });
                    }
                });
            }
        } else if (state.display === 'trigger' && formEl) {
            // ========== Trigger 模式：鼠标移入表单输入框时自动显示验证框 ==========
            var inputs = formEl.querySelectorAll('input[type="text"], input[type="password"], input[type="email"], textarea');
            inputs.forEach(function(input) {
                input.addEventListener('mouseenter', function() {
                    if (state.passed) return;
                    ensureInitialized().then(function() {
                        openPopup();
                        onCheck();
                    }).catch(function(err) {
                        log('trigger init ✗', err);
                    });
                });
            });
        }

        function renderChrome() {
            if (state.display === 'popup' && !body) {
                // ========== Popup 模式：不预渲染内嵌容器，仅准备弹窗结构 ==========
                container.innerHTML = '';
                return;
            }
            container.innerHTML = '<div class="cap-widget" id="cap-widget">' +
                    '<div class="cap-check" role="button" tabindex="0" aria-label="人机验证">' +
                        '<div class="cap-box"><span class="cap-tick">&#10003;</span></div>' +
                        '<div class="cap-info">' +
                            '<div class="cap-title">我是人类</div>' +
                            '<div class="cap-sub">保护本站免受垃圾信息干扰</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="cap-body"></div>' +
                    '<div class="cap-brand">' +
                        '<span class="cap-brand-logo">云</span>' +
                        '<span class="cap-brand-text">云界验证</span>' +
                    '</div>' +
                '</div>';
            widget = container.querySelector('.cap-widget');
            box = container.querySelector('.cap-box');
            title = container.querySelector('.cap-title');
            sub = container.querySelector('.cap-sub');
            body = container.querySelector('.cap-body');
            var checkEl = container.querySelector('.cap-check');
            checkEl.addEventListener('click', onCheck);
            checkEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onCheck(); }
            });
        }

        function setStatus(st, t, s) {
            if (state.display === 'popup' && popupStatus) {
                popupStatus.classList.remove('cap-popup-status-checking', 'cap-popup-status-success', 'cap-popup-status-error', 'cap-popup-status-hide');
                if (!st) {
                    popupStatus.classList.add('cap-popup-status-hide');
                } else {
                    popupStatus.classList.add('cap-popup-status-' + st);
                    if (st === 'checking') {
                        popupStatusSpinner.style.display = '';
                        popupStatusTick.style.display = 'none';
                    } else if (st === 'error') {
                        popupStatusSpinner.style.display = 'none';
                        popupStatusTick.style.display = '';
                        popupStatusTick.textContent = '\u2717';
                    } else {
                        popupStatusSpinner.style.display = 'none';
                        popupStatusTick.style.display = '';
                        popupStatusTick.textContent = '\u2713';
                    }
                }
                if (t) popupStatusTitle.textContent = t;
                if (s) popupStatusSub.textContent = s;
                return;
            }
            widget.classList.remove('cap-checking', 'cap-success', 'cap-error');
            if (st) widget.classList.add('cap-' + st);
            if (st === 'checking') {
                box.innerHTML = '<span class="cap-spinner"></span>';
            } else if (st === 'error') {
                box.innerHTML = '<span class="cap-tick">&#10007;</span>';
            } else {
                box.innerHTML = '<span class="cap-tick">&#10003;</span>';
            }
            if (t) title.textContent = t;
            if (s) sub.textContent = s;
        }

        function onCheck(refresh) {
            if (state.passed || state.checking) return;
            state.checking = true;
            setStatus('checking', '正在验证…', '');
            log('check →', buildSignals());
            fetch(apiAction('check'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: state.token, signals: buildSignals(), refresh: !!refresh })
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    state.checking = false;
                    log('check ←', data);
                    if (data && data.display) state.display = data.display;
                    if (data && data.ok) {
                        passed();
                    } else if (data && data.challenge === 'slider') {
                        showSlider(data);
                    } else if (data && data.challenge === 'click') {
                        showClick(data);
                    } else if (data && data.challenge === 'swap') {
                        showSwap(data);
                    } else {
                        fail();
                    }
                })
                .catch(function (err) { state.checking = false; log('check ✗ 网络错误', err); fail(); });
        }

        /* ---------- 弹窗模式 ---------- */
        function openPopup() {
            if (popupEl) return;
            popupEl = document.createElement('div');
            popupEl.className = 'cap-popup-overlay';
            popupEl.innerHTML =
                '<div class="cap-popup-dialog" role="dialog" aria-modal="true">' +
                    '<div class="cap-popup-head">' +
                        '<span class="cap-popup-title">人机验证</span>' +
                        '<button type="button" class="cap-popup-close" aria-label="关闭">&#10005;</button>' +
                    '</div>' +
                    '<div class="cap-popup-status cap-popup-status-hide">' +
                        '<span class="cap-popup-status-tick">&#10003;</span>' +
                        '<span class="cap-popup-status-spinner"></span>' +
                        '<div class="cap-popup-status-text">' +
                            '<div class="cap-popup-status-title"></div>' +
                            '<div class="cap-popup-status-sub"></div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="cap-popup-body"></div>' +
                '</div>';
            document.body.appendChild(popupEl);
            popupBody = popupEl.querySelector('.cap-popup-body');
            popupStatus = popupEl.querySelector('.cap-popup-status');
            popupStatusTitle = popupEl.querySelector('.cap-popup-status-title');
            popupStatusSub = popupEl.querySelector('.cap-popup-status-sub');
            popupStatusTick = popupEl.querySelector('.cap-popup-status-tick');
            popupStatusSpinner = popupEl.querySelector('.cap-popup-status-spinner');
            var closeBtn = popupEl.querySelector('.cap-popup-close');
            closeBtn.addEventListener('click', closePopup);
            popupEl.addEventListener('click', function (e) {
                if (e.target === popupEl) closePopup();
            });
            document.addEventListener('keydown', popupKeyHandler);
        }
        function popupKeyHandler(e) {
            if (e.key === 'Escape') closePopup();
        }
        function closePopup() {
            if (!popupEl) return;
            document.removeEventListener('keydown', popupKeyHandler);
            popupEl.parentNode && popupEl.parentNode.removeChild(popupEl);
            popupEl = null;
            popupBody = null;
        }
        /* 返回当前挑战应渲染到的容器：弹窗模式用弹窗主体，否则用内嵌 .cap-body */
        function ensureHost() {
            if (state.display === 'popup') {
                if (!popupEl) openPopup();
                host = popupBody;
            } else {
                host = body;
            }
            return host;
        }

        function passed() {
            state.passed = true;
            tokenInput.value = state.token;
            if (host) host.innerHTML = '';
            setStatus('success', '验证通过', '已通过人机验证');
            closePopup();
            // 弹窗模式：验证通过后，若用户此前已点击提交，则继续真正提交表单
            if (state.display === 'popup' && formEl && submitTriggered) {
                submitTriggered = false;
                formEl.requestSubmit ? formEl.requestSubmit() : formEl.submit();
            }
        }

        function fail() {
            setStatus('error', '验证失败，请重试', '请点击重新验证');
            setTimeout(function () {
                setStatus('', '我是人类', '保护本站免受垃圾信息干扰');
            }, 1500);
        }

        /* ---------- 内嵌/弹窗拼图滑块（真实图片 + 缺口拼图） ---------- */
        function showSlider(data) {
            host = ensureHost();
            setStatus('', '请完成拼图验证', '按住滑块拖动，使拼图块与缺口对齐');
            host.innerHTML =
                '<div class="sc-box">' +
                    '<div class="sc-stage" style="height:' + (data.height || 150) + 'px;">' +
                        '<img class="sc-bg" src="' + (data.bg_b64 || '') + '" alt="bg" draggable="false">' +
                        '<img class="sc-piece" src="' + (data.piece_b64 || '') + '" alt="piece" draggable="false" style="top:' + (data.gap_y || 0) + 'px;">' +
                    '</div>' +
                    '<div class="sc-track">' +
                        '<div class="sc-track-fill"></div>' +
                        '<div class="sc-track-tip">向右拖动滑块填充拼图</div>' +
                        '<div class="sc-knob"><span class="sc-knob-icon">&#10132;</span></div>' +
                    '</div>' +
                    '<div class="sc-tools"><button type="button" class="sc-refresh">换一张</button></div>' +
                '</div>';

            var sbox = host.querySelector('.sc-box');
            var bgImg = host.querySelector('.sc-bg');
            var pieceImg = host.querySelector('.sc-piece');
            var knob = host.querySelector('.sc-knob');
            var fill = host.querySelector('.sc-track-fill');
            var tip = host.querySelector('.sc-track-tip');
            var refreshBtn = host.querySelector('.sc-refresh');
            var stageEl = host.querySelector('.sc-stage');
            var trackEl = host.querySelector('.sc-track');

            var W = data.width || 300;
            var H = data.height || 150;
            var pw = data.piece_width || 58;
            var gapY = data.gap_y || 0;
            var renderedW = stageEl.clientWidth || W;
            var scale = renderedW > 0 ? W / renderedW : 1;
            var maxX = W - pw;
            var x = 0;
            var dragging = false;
            var startClientX = 0;
            var startX = 0;
            var done = false;

            function setX(nx) {
                x = Math.max(0, Math.min(maxX, nx));
                pieceImg.style.left = (x / scale) + 'px';
                pieceImg.style.top = (gapY / scale) + 'px';
                var ratio = maxX > 0 ? x / maxX : 0;
                var trackW = trackEl.offsetWidth - 44;
                knob.style.left = Math.max(0, Math.min(trackW, ratio * trackW)) + 'px';
                fill.style.width = (ratio * 100) + '%';
            }

            function verify() {
                log('slider verify → x=' + x);
                fetch(apiAction('slider'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: state.token, x: x })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        log('slider verify ←', res, '提交x=' + x);
                        if (res && res.ok) {
                            done = true;
                            sbox.classList.add('sc-success');
                            tip.style.display = '';
                            tip.textContent = '✓ 验证通过';
                            knob.innerHTML = '<span class="sc-knob-icon">&#10003;</span>';
                            setTimeout(passed, 450);
                        } else {
                            sbox.classList.add('sc-error');
                            tip.style.display = '';
                            tip.textContent = '验证失败，请重试';
                            setTimeout(function () {
                                sbox.classList.remove('sc-error');
                                pieceImg.classList.add('sc-back');
                                knob.classList.add('sc-back');
                                fill.classList.add('sc-back');
                                setX(0);
                                tip.style.display = '';
                                tip.textContent = '向右拖动滑块填充拼图';
                                knob.innerHTML = '<span class="sc-knob-icon">&#10132;</span>';
                                setTimeout(function () {
                                    pieceImg.classList.remove('sc-back');
                                    knob.classList.remove('sc-back');
                                    fill.classList.remove('sc-back');
                                }, 520);
                            }, 600);
                        }
                    })
                    .catch(function (err) {
                        log('slider verify ✗ 网络错误', err);
                        sbox.classList.add('sc-error');
                        setTimeout(function () { sbox.classList.remove('sc-error'); }, 600);
                    });
            }

            function onDown(clientX) {
                if (done) return;
                dragging = true;
                startClientX = clientX;
                startX = x;
                pieceImg.classList.add('sc-dragging');
                knob.classList.add('sc-dragging');
                if (tip) tip.style.display = 'none';
            }
            function onMove(clientX) {
                if (!dragging) return;
                setX(startX + (clientX - startClientX));
            }
            function onUp() {
                if (!dragging) return;
                dragging = false;
                pieceImg.classList.remove('sc-dragging');
                knob.classList.remove('sc-dragging');
                verify();
            }

            // 支持鼠标 + 触摸
            sbox.addEventListener('mousedown', function (e) { e.preventDefault(); onDown(e.clientX); });
            window.addEventListener('mousemove', function (e) { onMove(e.clientX); });
            window.addEventListener('mouseup', onUp);
            sbox.addEventListener('touchstart', function (e) { if (e.touches[0]) onDown(e.touches[0].clientX); }, { passive: false });
            window.addEventListener('touchmove', function (e) { if (e.touches[0]) onMove(e.touches[0].clientX); }, { passive: false });
            window.addEventListener('touchend', onUp);

            if (refreshBtn) {
                refreshBtn.addEventListener('click', function () {
                    state.checking = false;
                    onCheck(true);
                });
            }
            setX(0);
        }

        /* ---------- 内嵌/弹窗点文字验证（仿网易易盾「点选文字」） ---------- */
        function showClick(data) {
            host = ensureHost();
            setStatus('', '请完成点选验证', '请按顺序点击正确的文字');
            var need = data.need || 2;
            var positions = data.positions || [];
            var width = data.width || 300;
            var height = data.height || 150;

            function buildScene() {
                var html = '<div class="cc-box">';
                html += '<div class="cc-prompt"><span class="cc-prompt-label">请依次点击：</span><span class="cc-prompt-word">“' + (data.prompt || '') + '”</span></div>';
                html += '<div class="cc-scene" style="width:' + width + 'px;height:' + height + 'px;">';
                html += '<img class="cc-bg" src="' + (data.bg_b64 || '') + '" alt="captcha" draggable="false">';
                for (var i = 0; i < positions.length; i++) {
                    var p = positions[i];
                    html += '<button type="button" class="cc-piece" data-ch="' + p.ch + '" style="left:' + p.x + 'px;top:' + p.y + 'px;color:' + p.color + ';font-size:' + p.size + 'px;transform:translate(-50%,-50%) rotate(' + p.angle + 'deg);">' + p.ch + '</button>';
                }
                html += '</div>';
                html += '<div class="cc-tools"><button type="button" class="cc-reset"><span class="cc-reset-ic">&#8634;</span> 重选</button><button type="button" class="cc-refresh">换一张</button></div>';
                html += '</div>';
                host.innerHTML = html;
            }

            var selected = [];
            var answer = (data.prompt || '').split('');

            function reset() {
                selected = [];
                var tiles = host.querySelectorAll('.cc-piece');
                for (var i = 0; i < tiles.length; i++) {
                    tiles[i].classList.remove('cc-selected', 'cc-wrong', 'cc-disabled');
                    tiles[i].removeAttribute('data-index');
                }
            }

            function submit() {
                setStatus('checking', '正在校验…', '');
                log('click verify →', selected);
                fetch(apiAction('click'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: state.token, seq: selected })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        log('click verify ←', res);
                        if (res && res.ok) {
                            passed();
                            return;
                        }
                        var tiles = host.querySelectorAll('.cc-piece.cc-selected');
                        for (var i = 0; i < tiles.length; i++) tiles[i].classList.add('cc-wrong');
                        setStatus('error', '点选错误，请重试', '');
                        setTimeout(function () {
                            setStatus('', '请完成点选验证', '请按顺序点击正确的文字');
                            reset();
                        }, 700);
                    })
                    .catch(function (err) { log('click verify ✗ 网络错误', err); setStatus('error', '网络异常，请重试', ''); setTimeout(reset, 700); });
            }

            buildScene();
            var scene = host.querySelector('.cc-scene');
            if (scene) {
                scene.addEventListener('click', function (e) {
                    var t = e.target;
                    if (!t.classList.contains('cc-piece')) return;
                    if (t.classList.contains('cc-selected') || t.classList.contains('cc-disabled')) return;
                    t.classList.add('cc-selected');
                    t.setAttribute('data-index', selected.length + 1);
                    selected.push(t.getAttribute('data-ch'));
                    if (selected.length >= need) {
                        var all = host.querySelectorAll('.cc-piece');
                        for (var j = 0; j < all.length; j++) {
                            if (!all[j].classList.contains('cc-selected')) all[j].classList.add('cc-disabled');
                        }
                        submit();
                    }
                });
            }
            var resetBtn = host.querySelector('.cc-reset');
            if (resetBtn) resetBtn.addEventListener('click', reset);
            var refreshBtn = host.querySelector('.cc-refresh');
            if (refreshBtn) refreshBtn.addEventListener('click', function () {
                state.checking = false;
                onCheck();
            });
        }

        /* ---------- 推理拼图交换验证（交换 2 个图块复原图片）---------- */
        function showSwap(data) {
            host = ensureHost();
            setStatus('', '请完成推理验证', '点击选中图块后，再点击另一图块交换位置，使图片恢复完整');
            var cols = data.cols || 2;
            var rows = data.rows || 2;
            var pw = data.piece_w || 70;
            var ph = data.piece_h || 70;
            var sw = data.stage_w || 296;
            var sh = data.stage_h || 146;
            var gap = data.gap || 3;
            var pieces = data.pieces || [];
            var total = cols * rows;

            function buildScene() {
                var html = '<div class="sw-box">';
                html += '<div class="sw-prompt"><span class="sw-prompt-label">推理验证：</span><span class="sw-prompt-word">交换被打乱的图块，恢复完整图片</span></div>';
                html += '<div class="sw-scene" style="width:' + (sw + gap) + 'px;height:' + (sh + gap) + 'px;">';
                for (var i = 0; i < pieces.length; i++) {
                    var row = Math.floor(i / cols);
                    var col = i % cols;
                    var left = col * (pw + gap);
                    var top = row * (ph + gap);
                    // ========== 使用 data-pos 表示当前位置，data-content 存储正确内容索引 ==========
                    html += '<div class="sw-tile" data-pos="' + i + '" data-content="' + pieces[i].correct + '" style="width:' + pw + 'px;height:' + ph + 'px;left:' + left + 'px;top:' + top + 'px;">';
                    html += '<img src="' + (pieces[i].b64 || '') + '" draggable="false" style="width:100%;height:100%;">';
                    html += '<span class="sw-tile-idx">' + (i + 1) + '</span>';
                    html += '</div>';
                }
                html += '</div>';
                html += '<div class="sw-tools"><button type="button" class="sw-reset"><span class="sw-reset-ic">&#8634;</span> 重置</button><button type="button" class="sw-refresh">换一张</button></div>';
                html += '</div>';
                host.innerHTML = html;
            }

            // ========== 每个 tile 存储它的内容（content.correct）==========
            // initialOrder[pos] = 该位置上放的图块的 correct 值
            var initialContent = pieces.map(function(p) { return p.correct; });
            var currentOrder = initialContent.slice(); // 当前每个位置的内容

            function reset() {
                selected = null;
                var tiles = host.querySelectorAll('.sw-tile');
                for (var i = 0; i < tiles.length; i++) {
                    tiles[i].classList.remove('sw-selected', 'sw-success');
                }
                currentOrder = initialContent.slice();
            }

            function doSwap(posI, posJ) {
                // 交换两个位置的内容
                var tmp = currentOrder[posI];
                currentOrder[posI] = currentOrder[posJ];
                currentOrder[posJ] = tmp;

                // 更新显示位置（带动画）
                var tileI = host.querySelector('.sw-tile[data-pos="' + posI + '"]');
                var tileJ = host.querySelector('.sw-tile[data-pos="' + posJ + '"]');
                if (tileI && tileJ) {
                    var rowI = Math.floor(posI / cols), colI = posI % cols;
                    var rowJ = Math.floor(posJ / cols), colJ = posJ % cols;
                    var leftI = colI * (pw + gap), topI = rowI * (ph + gap);
                    var leftJ = colJ * (pw + gap), topJ = rowJ * (ph + gap);
                    tileI.style.transition = 'all 0.25s ease';
                    tileJ.style.transition = 'all 0.25s ease';
                    tileI.style.left = leftI + 'px';
                    tileI.style.top = topI + 'px';
                    tileJ.style.left = leftJ + 'px';
                    tileJ.style.top = topJ + 'px';
                    // 交换 data-pos 属性
                    tileI.setAttribute('data-pos', posJ);
                    tileJ.setAttribute('data-pos', posI);
                }
                // 短暂延迟后清除 transition 避免后续点击卡顿
                setTimeout(function() {
                    var all = host.querySelectorAll('.sw-tile');
                    for (var k = 0; k < all.length; k++) {
                        all[k].style.transition = '';
                    }
                }, 280);
            }

            function submitOrder() {
                setStatus('checking', '正在校验…', '');
                log('swap verify →', currentOrder);
                fetch(apiAction('swap'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: state.token, order: currentOrder })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        log('swap verify ←', res);
                        if (res && res.ok) {
                            var allTiles = host.querySelectorAll('.sw-tile');
                            for (var i = 0; i < allTiles.length; i++) {
                                allTiles[i].classList.add('sw-success');
                            }
                            setStatus('success', '验证通过', '推理验证已通过');
                            setTimeout(passed, 600);
                        } else {
                            setStatus('error', '交换错误，请重试', '请继续交换图块使图片恢复完整');
                            setTimeout(reset, 400);
                        }
                    })
                    .catch(function (err) {
                        log('swap verify ✗ 网络错误', err);
                        setStatus('error', '网络异常，请重试', '');
                        setTimeout(reset, 600);
                    });
            }

            buildScene();
            var scene = host.querySelector('.sw-scene');
            if (scene) {
                scene.addEventListener('click', function (e) {
                    var tile = e.target.closest('.sw-tile');
                    if (!tile) return;
                    var pos = parseInt(tile.getAttribute('data-pos'), 10);
                    if (isNaN(pos)) return;

                    if (selected === null) {
                        // 第一次点击：选中
                        selected = pos;
                        tile.classList.add('sw-selected');
                    } else if (pos === selected) {
                        // 取消选中
                        tile.classList.remove('sw-selected');
                        selected = null;
                    } else {
                        // 交换
                        var prev = host.querySelector('.sw-tile[data-pos="' + selected + '"]');
                        if (prev) prev.classList.remove('sw-selected');
                        doSwap(selected, pos);
                        selected = null;
                        // 自动检测是否复原
                        var expected = Array.from({length: total}, (_, i) => i);
                        if (JSON.stringify(currentOrder) === JSON.stringify(expected)) {
                            submitOrder();
                        }
                    }
                });
            }

            var resetBtn = host.querySelector('.sw-reset');
            if (resetBtn) resetBtn.addEventListener('click', reset);
            var refreshBtn = host.querySelector('.sw-refresh');
            if (refreshBtn) refreshBtn.addEventListener('click', function () {
                state.checking = false;
                onCheck(true);
            });
        }

        /* ---------- 初始化：申请挑战（仅在非 popup 模式时立即执行）---------- */
        var initialToken = (tokenInput.value || '').trim();
        if (initialToken && state.display !== 'popup') {
            state.token = initialToken;
            // ========== Inline 模式才需要渲染组件 ==========
            renderChrome();
        } else if (!initialToken && state.display === 'inline') {
            // inline 模式直接获取
            fetch(apiAction('get'), { cache: 'no-store', credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    log('get ←', data);
                    if (!data || !data.enabled) {
                        log('验证未启用或调试模式，隐藏组件');
                        container.style.display = 'none';
                        return;
                    }
                    state.token = data.token;
                    tokenInput.value = data.token;
                    renderChrome();
                })
                .catch(function (err) {
                    log('get ✗ 网络错误', err);
                    container.style.display = 'none';
                });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
