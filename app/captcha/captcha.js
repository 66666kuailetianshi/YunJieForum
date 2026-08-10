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

        /* ---------- 抗绕过：PoW 工作量证明 + 蜜罐 ---------- */
        // 自包含 SHA-256（不依赖 crypto.subtle，HTTP 环境也可用）
        function sha256hex(msg) {
            function rrot(x, n) { return (x >>> n) | (x << (32 - n)); }
            var K = [0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
                     0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
                     0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
                     0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
                     0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
                     0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
                     0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
                     0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2];
            var H = [0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19];
            var m = unescape(encodeURIComponent(msg));
            var len = m.length;
            var bitLen = len * 8;
            m += String.fromCharCode(0x80);
            while (m.length % 64 !== 56) m += String.fromCharCode(0);
            for (var i = 0; i < 4; i++) {
                m += String.fromCharCode((bitLen >>> (24 - i * 8)) & 0xff);
            }
            var w = new Array(64);
            for (var off = 0; off < m.length; off += 64) {
                for (var t = 0; t < 16; t++) {
                    var j = off + t * 4;
                    w[t] = (m.charCodeAt(j) << 24) | (m.charCodeAt(j + 1) << 16) | (m.charCodeAt(j + 2) << 8) | m.charCodeAt(j + 3);
                }
                for (var t = 16; t < 64; t++) {
                    var s0 = rrot(w[t - 15], 7) ^ rrot(w[t - 15], 18) ^ (w[t - 15] >>> 3);
                    var s1 = rrot(w[t - 2], 17) ^ rrot(w[t - 2], 19) ^ (w[t - 2] >>> 10);
                    w[t] = (w[t - 16] + s0 + w[t - 7] + s1) | 0;
                }
                var a = H[0], b = H[1], c = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7];
                for (var t = 0; t < 64; t++) {
                    var S1 = rrot(e, 6) ^ rrot(e, 11) ^ rrot(e, 25);
                    var ch = (e & f) ^ (~e & g);
                    var t1 = (h + S1 + ch + K[t] + w[t]) | 0;
                    var S0 = rrot(a, 2) ^ rrot(a, 13) ^ rrot(a, 22);
                    var maj = (a & b) ^ (a & c) ^ (b & c);
                    var t2 = (S0 + maj) | 0;
                    h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
                }
                H[0] = (H[0] + a) | 0; H[1] = (H[1] + b) | 0; H[2] = (H[2] + c) | 0; H[3] = (H[3] + d) | 0;
                H[4] = (H[4] + e) | 0; H[5] = (H[5] + f) | 0; H[6] = (H[6] + g) | 0; H[7] = (H[7] + h) | 0;
            }
            function hex(n) { return ('00000000' + (n >>> 0).toString(16)).slice(-8); }
            return hex(H[0]) + hex(H[1]) + hex(H[2]) + hex(H[3]) + hex(H[4]) + hex(H[5]) + hex(H[6]) + hex(H[7]);
        }

        // 计算 PoW nonce：sha256(prefix + nonce) 前 bits 位需等于 target
        function computePowNonce(pow) {
            if (!pow || !pow.prefix || !pow.target) return '';
            var prefix = pow.prefix, target = pow.target, nonce = 0, limit = 8000000;
            while (nonce < limit) {
                if (sha256hex(prefix + nonce).indexOf(target) === 0) return String(nonce);
                nonce++;
            }
            return '';
        }

        // 拼装验证请求体（自动附加 PoW nonce）
        function buildVerifyBody(base) {
            if (state.pow) {
                try { base.pow_nonce = computePowNonce(state.pow); } catch (e) { base.pow_nonce = ''; }
            }
            return JSON.stringify(base);
        }

        // 注入蜜罐隐藏字段（机器人自动填写即判失败）
        function injectHoneypot() {
            if (!formEl || !state.hpName) return;
            if (formEl.querySelector('input[name="' + state.hpName + '"]')) return;
            var hp = document.createElement('input');
            hp.type = 'text';
            hp.name = state.hpName;
            hp.tabIndex = -1;
            hp.autocomplete = 'off';
            hp.setAttribute('aria-hidden', 'true');
            hp.style.position = 'absolute';
            hp.style.left = '-9999px';
            hp.style.width = '1px';
            hp.style.height = '1px';
            hp.style.opacity = '0';
            hp.style.pointerEvents = 'none';
            formEl.appendChild(hp);
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
                        // 抗绕过：捕获 PoW 挑战、蜜罐字段名、限流状态
                        state.pow = data.pow || null;
                        state.hpName = data.hp_name || '';
                        state.blocked = !!data.blocked;
                        if (state.hpName) {
                            injectHoneypot();
                        }
                        if (state.blocked) {
                            setStatus('error', data.blocked_msg || '操作过于频繁，请稍后再试', '');
                            throw new Error('blocked');
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
        var state = { token: '', passed: false, checking: false, display: 'inline', pow: null, hpName: '', blocked: false };
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
            (storedDisplay === 'popup') ? storedDisplay : 'inline' :
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
                    // ========== 只在首次初始化时（get）设置 display，后续不覆盖 ==========
                    if (data && data.display && !state.token) {
                        state.display = data.display;
                    }
                    if (data && data.ok) {
                        passed();
                    } else if (data && data.challenge === 'slider') {
                        showSlider(data);
                    } else if (data && data.challenge === 'click') {
                        showClick(data);
                    } else if (data && data.challenge === 'swap') {
                        showSwap(data);
                    } else if (data && data.challenge === 'letter') {
                        showLetter(data);
                    } else if (data && (data.error === 'invalid' || data.error === 'expired') && !refresh) {
                        // token 失效或过期：自动重新初始化并最多重试一次
                        log('check token stale, reinitializing', data.error);
                        fetch(apiAction('get'), { cache: 'no-store', credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (newData) {
                                if (!newData || !newData.enabled) {
                                    fail('验证未启用', '请刷新页面后重试');
                                    return;
                                }
                                state.blocked = !!newData.blocked;
                                if (state.blocked) {
                                    fail('操作过于频繁', newData.blocked_msg || '请稍后再试');
                                    return;
                                }
                                state.pow = newData.pow || null;
                                state.hpName = newData.hp_name || '';
                                if (state.hpName) injectHoneypot();
                                state.token = newData.token;
                                tokenInput.value = newData.token;
                                // 使用新 token 重试一次（refresh=true 防止再次进入此分支）
                                onCheck(true);
                            })
                            .catch(function (err) { log('reinit ✗', err); fail('验证初始化失败', '请刷新页面后重试'); });
                    } else {
                        var failMsg = data && data.error ? ('验证失败 (' + data.error + ')') : '';
                        var failSub = data && data.message ? data.message : '请点击重新验证';
                        fail(failMsg, failSub);
                    }
                })
                .catch(function (err) { state.checking = false; log('check ✗ 网络错误', err); fail('网络异常，请重试'); });
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

        function fail(msg, sub) {
            setStatus('error', msg || '验证失败，请重试', sub || '请点击重新验证');
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
            var renderedH = stageEl.clientHeight || H;
            var scaleX = renderedW > 0 ? W / renderedW : 1;
            var scaleY = renderedH > 0 ? H / renderedH : 1;
            var maxX = W - pw;
            var x = 0;
            var dragging = false;
            var startClientX = 0;
            var startX = 0;
            var done = false;

            // ========== 拖拽行为风控数据采集 ==========
            var dragStartTime = 0;          // 拖拽开始时间戳
            var trajectory = [];             // 轨迹点 [{t, x}, ...]
            var lastTrajectoryTime = 0;      // 上次采样时间（节流 20ms）
            var TRAJECTORY_SAMPLE_INTERVAL = 20; // 轨迹采样间隔 ms

            function setX(nx) {
                x = Math.max(0, Math.min(maxX, nx));
                // ========== 使用正确的比例转换 ==========
                var displayX = x / scaleX;
                var displayY = gapY / scaleY;
                pieceImg.style.left = displayX + 'px';
                pieceImg.style.top = displayY + 'px';
                // 拼图块需跟随舞台缩放，否则响应式下与背景比例失调
                pieceImg.style.width = (pw / scaleX) + 'px';
                pieceImg.style.height = (pw / scaleY) + 'px';
                
                var ratio = maxX > 0 ? x / maxX : 0;
                var trackW = trackEl.offsetWidth - 44;
                knob.style.left = Math.max(0, Math.min(trackW, ratio * trackW)) + 'px';
                fill.style.width = (ratio * 100) + '%';
            }

            function verify() {
                log('slider verify → x=' + x);
                // ========== 计算风控指标 ==========
                var dragDuration = dragStartTime > 0 ? Math.round(performance.now() - dragStartTime) : 0;
                var trajectoryData = trajectory.length > 1 ? trajectory.slice(0, 80) : []; // 上传最多 80 个轨迹点
                fetch(apiAction('slider'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: buildVerifyBody({ token: state.token, x: x, duration: dragDuration, traj: trajectoryData })
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
                            var failMsg = '验证失败';
                            if (res && res.reason) failMsg += ' (' + res.reason + ')';
                            tip.textContent = failMsg;
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
                // ========== 开始记录风控数据 ==========
                dragStartTime = performance.now();
                trajectory = [{t: 0, x: x}];
                lastTrajectoryTime = dragStartTime;
                pieceImg.classList.add('sc-dragging');
                knob.classList.add('sc-dragging');
                if (tip) tip.style.display = 'none';
            }
            function onMove(clientX) {
                if (!dragging) return;
                // clientX 是屏幕 CSS 像素，x 是图像逻辑坐标，需按 scaleX 换算
                setX(startX + (clientX - startClientX) * scaleX);
                // ========== 采集轨迹点（节流采样）==========
                var now = performance.now();
                if (now - lastTrajectoryTime >= TRAJECTORY_SAMPLE_INTERVAL) {
                    trajectory.push({t: Math.round(now - dragStartTime), x: x});
                    lastTrajectoryTime = now;
                }
            }
            function onUp() {
                if (!dragging) return;
                dragging = false;
                // ========== 结束轨迹记录（确保包含终点）==========
                var now = performance.now();
                if (trajectory.length > 0 && trajectory[trajectory.length - 1].x !== x) {
                    trajectory.push({t: Math.round(now - dragStartTime), x: x});
                }
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
                    state.passed = false;
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
                    body: buildVerifyBody({ token: state.token, seq: selected })
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
                        var clickFailMsg = '点选错误，请重试';
                        if (res && res.reason) clickFailMsg += ' (' + res.reason + ')';
                        setStatus('error', clickFailMsg, '');
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
                state.passed = false;
                state.checking = false;
                onCheck();
            });
        }

        /* ---------- 推理拼图交换验证（交换 2 个图块复原图片）---------- */
        function showSwap(data) {
            host = ensureHost();
            setStatus('', '请完成推理验证', '点击选中一个图块，再点击另一个图块，交换两者位置，使图片恢复完整');
            var cols = data.cols || 2;
            var rows = data.rows || 2;
            var pw = data.piece_w || 70;
            var ph = data.piece_h || 70;
            var gap = data.gap || 3;
            var sw = data.stage_w || (pw * cols + gap * (cols - 1));
            var sh = data.stage_h || (ph * rows + gap * (rows - 1));
            var pieces = data.pieces || [];
            var total = cols * rows;

            // ========== 核心数据结构 ==========
            // currentOrder[pos] = 该位置上图块的 correct 值（0,1,2,3...）
            // 初始顺序由后端返回的 pieces[].correct 决定
            var initialOrder = pieces.map(function(p) { return p.correct; });
            var currentOrder = initialOrder.slice();
            var selected = null;

            function renderTile(tile, pos) {
                var pieceCorrect = currentOrder[pos];
                var piece = pieces.find(function(p) { return p.correct === pieceCorrect; });
                if (!piece) return;
                var row = Math.floor(pos / cols);
                var col = pos % cols;
                var left = col * (pw + gap);
                var top = row * (ph + gap);
                tile.setAttribute('data-pos', pos);
                tile.style.width = pw + 'px';
                tile.style.height = ph + 'px';
                tile.style.left = left + 'px';
                tile.style.top = top + 'px';
                var img = tile.querySelector('img');
                if (img) img.src = piece.b64 || '';
                var idx = tile.querySelector('.sw-tile-idx');
                if (idx) idx.textContent = pos + 1;
            }

            function buildScene() {
                var html = '<div class="sw-box">';
                html += '<div class="sw-prompt"><span class="sw-prompt-label">请完成推理验证</span></div>';
                html += '<div class="sw-prompt-hint" style="font-size:12px;color:#6b7280;margin-bottom:6px;">图块已被打乱，点击两个图块交换位置，使图片恢复完整</div>';
                html += '<div class="sw-scene" style="width:' + sw + 'px;height:' + sh + 'px;">';
                for (var pos = 0; pos < pieces.length; pos++) {
                    html += '<div class="sw-tile" style="width:' + pw + 'px;height:' + ph + 'px;"></div>';
                }
                html += '</div>';
                html += '<div class="sw-tools"><button type="button" class="sw-reset"><span class="sw-reset-ic">&#8634;</span> 重置</button><button type="button" class="sw-refresh">换一张</button></div>';
                html += '</div>';
                host.innerHTML = html;

                // 填充每个图块的内容（位置 + 图片 + 索引）
                var tiles = host.querySelectorAll('.sw-tile');
                for (var i = 0; i < tiles.length; i++) {
                    tiles[i].innerHTML = '<img src="" draggable="false" style="width:100%;height:100%;"><span class="sw-tile-idx"></span>';
                    renderTile(tiles[i], i);
                }
            }

            function doSwap(posI, posJ) {
                // 交换两个位置上的图块数据
                var tmp = currentOrder[posI];
                currentOrder[posI] = currentOrder[posJ];
                currentOrder[posJ] = tmp;

                // 局部更新两个图块，避免 innerHTML 重建导致事件监听器丢失
                var tileI = host.querySelector('.sw-tile[data-pos="' + posI + '"]');
                var tileJ = host.querySelector('.sw-tile[data-pos="' + posJ + '"]');
                if (tileI) renderTile(tileI, posI);
                if (tileJ) renderTile(tileJ, posJ);
            }

            function reset() {
                selected = null;
                currentOrder = initialOrder.slice();
                var tiles = host.querySelectorAll('.sw-tile');
                for (var i = 0; i < tiles.length; i++) {
                    tiles[i].classList.remove('sw-selected');
                    renderTile(tiles[i], i);
                }
            }

            function isSolved() {
                for (var i = 0; i < currentOrder.length; i++) {
                    if (currentOrder[i] !== i) return false;
                }
                return true;
            }

            function submitOrder() {
                setStatus('checking', '正在校验…', '');
                log('swap verify →', currentOrder);
                fetch(apiAction('swap'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: buildVerifyBody({ token: state.token, order: currentOrder })
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
                            var swapFailMsg = '交换错误，请重试';
                            if (res && res.reason) swapFailMsg += ' (' + res.reason + ')';
                            setStatus('error', swapFailMsg, '请继续交换图块使图片恢复完整');
                            setTimeout(reset, 400);
                        }
                    })
                    .catch(function (err) {
                        log('swap verify ✗ 网络错误', err);
                        setStatus('error', '网络异常，请重试', '');
                        setTimeout(reset, 600);
                    });
            }

            function onSceneClick(e) {
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
                    if (isSolved()) {
                        submitOrder();
                    }
                }
            }

            function onRefresh() {
                state.passed = false;
                state.checking = false;
                onCheck(true);
            }

            log('swap init →', currentOrder);
            buildScene();
            var scene = host.querySelector('.sw-scene');
            if (scene) scene.addEventListener('click', onSceneClick);

            var resetBtn = host.querySelector('.sw-reset');
            if (resetBtn) resetBtn.addEventListener('click', reset);
            var refreshBtn = host.querySelector('.sw-refresh');
            if (refreshBtn) refreshBtn.addEventListener('click', onRefresh);
        }

        /* ---------- 图片字母验证（输入图片中的字符，传统验证码） ---------- */
        function showLetter(data) {
            host = ensureHost();
            setStatus('', '请输入验证码', '请输入图片中的字母和数字（不区分大小写）');
            host.innerHTML =
                '<div class="lc-box">' +
                    '<div class="lc-scene">' +
                        '<img class="lc-img" src="' + (data.bg_b64 || '') + '" alt="captcha" draggable="false">' +
                    '</div>' +
                    '<div class="lc-input-row">' +
                        '<input type="text" class="lc-input" maxlength="' + (data.length || 6) + '" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="请输入图中的字符">' +
                        '<button type="button" class="lc-submit">验证</button>' +
                    '</div>' +
                    '<div class="lc-tools"><button type="button" class="lc-refresh">&#8635; 换一张</button></div>' +
                '</div>';

            var input = host.querySelector('.lc-input');
            var submitBtn = host.querySelector('.lc-submit');
            var refreshBtn = host.querySelector('.lc-refresh');

            function doSubmit() {
                var val = (input.value || '').replace(/\s+/g, '');
                if (!val) { input.focus(); return; }
                setStatus('checking', '正在校验…', '');
                log('letter verify →', val);
                fetch(apiAction('letter'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: buildVerifyBody({ token: state.token, input: val })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        log('letter verify ←', res);
                        if (res && res.ok) {
                            passed();
                            return;
                        }
                        setStatus('error', '验证码错误，请重试', '');
                        setTimeout(function () {
                            setStatus('', '请输入验证码', '请输入图片中的字母和数字（不区分大小写）');
                            input.value = '';
                            input.focus();
                        }, 600);
                    })
                    .catch(function (err) {
                        log('letter verify ✗ 网络错误', err);
                        setStatus('error', '网络异常，请重试', '');
                    });
            }

            submitBtn.addEventListener('click', doSubmit);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); doSubmit(); }
            });
            refreshBtn.addEventListener('click', function () {
                state.passed = false;
                state.checking = false;
                onCheck(true);
            });
            setTimeout(function () { input.focus(); }, 50);
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
