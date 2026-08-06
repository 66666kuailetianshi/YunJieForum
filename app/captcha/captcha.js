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

        function buildSignals() {
            var s = SIG.samples, n = s.length, dist = 0, i;
            for (i = 1; i < n; i++) dist += Math.hypot(s[i].x - s[i - 1].x, s[i].y - s[i - 1].y);
            var cx = 0, cy = 0;
            for (i = 0; i < n; i++) { cx += s[i].x; cy += s[i].y; }
            if (n) { cx /= n; cy /= n; }
            var vari = 0;
            for (i = 0; i < n; i++) vari += Math.hypot(s[i].x - cx, s[i].y - cy);
            vari = n ? vari / n : 0;
            return {
                samples: n,
                dist: Math.round(dist),
                variance: Math.round(vari),
                elapsed: Date.now() - SIG.pageStart,
                clicks: SIG.clicks,
                keys: SIG.keys,
                noPointer: SIG.noPointer && n === 0
            };
        }

        /* ---------- 组件状态 ---------- */
        var state = { token: '', passed: false, checking: false };
        var widget, box, title, sub, body;

        function renderChrome() {
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

        function onCheck() {
            if (state.passed || state.checking) return;
            state.checking = true;
            setStatus('checking', '正在验证…', '');
            log('check →', buildSignals());
            fetch(apiAction('check'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: state.token, signals: buildSignals() })
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    state.checking = false;
                    log('check ←', data);
                    if (data && data.ok) {
                        passed();
                    } else if (data && data.challenge === 'slider') {
                        showSlider(data);
                    } else if (data && data.challenge === 'click') {
                        showClick(data);
                    } else {
                        fail();
                    }
                })
                .catch(function (err) { state.checking = false; log('check ✗ 网络错误', err); fail(); });
        }

        function passed() {
            state.passed = true;
            tokenInput.value = state.token;
            body.innerHTML = '';
            setStatus('success', '验证通过', '已通过人机验证');
        }

        function fail() {
            setStatus('error', '验证失败，请重试', '请点击重新验证');
            setTimeout(function () {
                setStatus('', '我是人类', '保护本站免受垃圾信息干扰');
            }, 1500);
        }

        /* ---------- 内嵌拼图滑块（真实图片 + 缺口拼图） ---------- */
        function showSlider(data) {
            setStatus('', '请完成拼图验证', '按住滑块拖动，使拼图块与缺口对齐');
            body.innerHTML =
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

            var sbox = body.querySelector('.sc-box');
            var bgImg = body.querySelector('.sc-bg');
            var pieceImg = body.querySelector('.sc-piece');
            var knob = body.querySelector('.sc-knob');
            var fill = body.querySelector('.sc-track-fill');
            var tip = body.querySelector('.sc-track-tip');
            var refreshBtn = body.querySelector('.sc-refresh');
            var stageEl = body.querySelector('.sc-stage');
            var trackEl = body.querySelector('.sc-track');

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
                    onCheck();
                });
            }
            setX(0);
        }

        /* ---------- 内嵌点文字验证（仿网易易盾「点选文字」） ---------- */
        function showClick(data) {
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
                body.innerHTML = html;
            }

            var selected = [];
            var answer = (data.prompt || '').split('');

            function reset() {
                selected = [];
                var tiles = body.querySelectorAll('.cc-piece');
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
                        var tiles = body.querySelectorAll('.cc-piece.cc-selected');
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
            var scene = body.querySelector('.cc-scene');
            if (scene) {
                scene.addEventListener('click', function (e) {
                    var t = e.target;
                    if (!t.classList.contains('cc-piece')) return;
                    if (t.classList.contains('cc-selected') || t.classList.contains('cc-disabled')) return;
                    t.classList.add('cc-selected');
                    t.setAttribute('data-index', selected.length + 1);
                    selected.push(t.getAttribute('data-ch'));
                    if (selected.length >= need) {
                        var all = body.querySelectorAll('.cc-piece');
                        for (var j = 0; j < all.length; j++) {
                            if (!all[j].classList.contains('cc-selected')) all[j].classList.add('cc-disabled');
                        }
                        submit();
                    }
                });
            }
            var resetBtn = body.querySelector('.cc-reset');
            if (resetBtn) resetBtn.addEventListener('click', reset);
            var refreshBtn = body.querySelector('.cc-refresh');
            if (refreshBtn) refreshBtn.addEventListener('click', function () {
                state.checking = false;
                onCheck();
            });
        }

        /* ---------- 初始化：申请挑战 ---------- */
        var initialToken = (tokenInput.value || '').trim();
        if (initialToken) {
            state.token = initialToken;
            renderChrome();
        } else {
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
