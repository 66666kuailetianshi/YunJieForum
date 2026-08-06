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
            'password_reset_requests': 'password_reset'
        };
        var INTERVAL = 1000; // 1 秒（pending_counts_ajax 服务端 1 秒缓存合并并发，不阻塞）

        function updateBadges() {
            try {
                fetch('<?php echo site_url('admin/api/pending_counts_ajax'); ?>&_=' + Date.now(), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
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
                    .catch(function () {});
            } catch (e) {}
        }

        setInterval(updateBadges, INTERVAL);
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
