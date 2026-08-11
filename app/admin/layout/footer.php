<?php
/**
 * 云界论坛 - 管理后台底部
 */
?>
                </div>
            </div>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <p>&copy; <?php echo date('Y'); ?> <?php echo e(SITE_NAME); ?>. All rights reserved.</p>
            <p class="footer-version"><?php echo e(t('admin_panel', '管理后台')); ?> v<?php echo e(APP_VERSION); ?></p>
        </div>
    </footer>

    <script src="/public/js/main.js?v=<?php echo e(APP_VERSION); ?>-ui2" defer></script>
    <script>
    // 全局待处理计数轮询：实时更新侧栏菜单 badge
    (function () {
        var BADGE_MAP = {
            'reports':        'reports',
            'ban_appeals':    'ban_appeals',
            'password_reset_requests': 'password_reset',
            'email_disclosure': 'email_disclosure',
            'tickets':        'tickets'
        };
        var INTERVAL = 5000; // 5 秒（pending_counts_ajax 服务端 1 秒缓存合并并发，不阻塞）
        var BASE_INTERVAL = INTERVAL;
        var maxInterval = 30000; // 错误时最大退避 30 秒
        var failCount = 0;
        var timerId = null;

        function scheduleNext(delay) {
            if (timerId) clearTimeout(timerId);
            timerId = setTimeout(updateBadges, delay);
        }

        function updateBadges() {
            try {
                fetch('<?php echo site_url('admin/api/pending_counts_ajax'); ?>&_=' + Date.now(), { credentials: 'same-origin' })
                    .then(function (r) {
                        // 非 2xx 响应（418 WAF 拦截、5xx 等）触发退避
                        if (!r.ok) { throw new Error('HTTP ' + r.status); }
                        return r.json();
                    })
                    .then(function (data) {
                        // 成功：重置退避计数器
                        failCount = 0;
                        scheduleNext(BASE_INTERVAL);

                        if (!data || data.error) return;
                        Object.keys(BADGE_MAP).forEach(function (menuKey) {
                            var dataKey = BADGE_MAP[menuKey];
                            var count = parseInt(data[dataKey], 10) || 0;
                            var el = document.querySelector('[data-badge-key="' + menuKey + '"]');
                            if (!el) return;
                            var current = parseInt(el.textContent, 10) || 0;
                            if (count !== current) {
                                el.textContent = count;
                                el.style.display = count > 0 ? '' : 'none';
                                // 数字变化时闪烁提示
                                el.classList.add('badge-pulse');
                                setTimeout(function () { el.classList.remove('badge-pulse'); }, 600);
                            }
                        });
                    })
                    .catch(function () {
                        // 失败：指数退避，避免被 WAF/CDN 进一步拦截
                        failCount++;
                        var backoff = Math.min(BASE_INTERVAL * Math.pow(2, failCount), maxInterval);
                        scheduleNext(backoff);
                    });
            } catch (e) {
                scheduleNext(BASE_INTERVAL);
            }
        }

        // 首次立即执行一次，之后按间隔轮询
        updateBadges();
    })();

    // 菜单分组折叠/展开
    (function () {
        var titles = document.querySelectorAll('.admin-menu-group-title');
        titles.forEach(function (title) {
            title.addEventListener('click', function () {
                var group = title.closest('.admin-menu-group');
                if (!group) return;
                var expanded = group.classList.toggle('is-expanded');
                title.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                // 记忆展开状态
                try {
                    var key = 'admin_menu_group_' + group.dataset.group;
                    localStorage.setItem(key, expanded ? '1' : '0');
                } catch (e) {}
            });
        });
        // 恢复用户记忆的展开状态（仅对非默认展开组生效）
        document.querySelectorAll('.admin-menu-group').forEach(function (group) {
            var isActive = group.querySelector('.admin-menu-link.active');
            if (isActive) return; // 包含当前页的组始终展开
            if (group.dataset.group === 'overview') return; // 概览组默认展开
            try {
                var key = 'admin_menu_group_' + group.dataset.group;
                var saved = localStorage.getItem(key);
                if (saved === '1') {
                    group.classList.add('is-expanded');
                    var t = group.querySelector('.admin-menu-group-title');
                    if (t) t.setAttribute('aria-expanded', 'true');
                } else if (saved === '0') {
                    group.classList.remove('is-expanded');
                    var t2 = group.querySelector('.admin-menu-group-title');
                    if (t2) t2.setAttribute('aria-expanded', 'false');
                }
            } catch (e) {}
        });
    })();
    </script>
</body>
</html>
