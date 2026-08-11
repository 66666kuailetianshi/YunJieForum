<?php
/**
 * 云界论坛 - 公共底部模板
 */
?>
        </div>
    </main>

        <footer class="site-footer">
        <div class="container footer-inner">
            <p>&copy; <?php echo date('Y'); ?> <?php echo e(SITE_NAME); ?> · <?php echo e(t('all_rights_reserved', '保留所有权利')); ?></p>
            <p class="footer-links">
                <a href="/index.php?route=service"><?php echo e(t('footer_service', '服务协议')); ?></a>
                <span class="footer-sep">·</span>
                <a href="/index.php?route=terms"><?php echo e(t('footer_terms', '用户协议')); ?></a>
                <span class="footer-sep">·</span>
                <a href="/index.php?route=privacy"><?php echo e(t('footer_privacy', '隐私政策')); ?></a>
                <span class="footer-sep">·</span>
                <a href="/index.php?route=disclaimer"><?php echo e(t('footer_disclaimer', '免责声明')); ?></a>
            </p>
        </div>
    </footer>

    <script src="/public/js/main.js?v=<?php echo e(APP_VERSION); ?>-ui2" defer></script>
    <script src="/public/js/lightbox.js?v=<?php echo e(APP_VERSION); ?>-ui2" defer></script>
    <script src="/index.php?route=captcha/assets&file=captcha.js&v=<?php echo e(APP_VERSION); ?>-ui2" defer></script>
<?php
// 仅对已登录用户注入封禁状态轮询：管理员封禁后立即踢下线并跳转封禁页
if (is_logged_in() && !is_admin()):
?>
    <script>
    (function () {
        // 排除封禁页本身，避免死循环
        if (location.pathname.indexOf('banned') !== -1 || location.search.indexOf('route=banned') !== -1) return;
        var INTERVAL = 10000; // 10 秒轮询，封禁后最多 10 秒内感知（端点带 3 秒服务端缓存）
        var timer = null;
        var MUTE_DEFAULT = <?php echo json_encode(t('mute_tip_default', '你当前处于禁言状态，无法发帖或回复。')); ?>;
        var PM_NEW = <?php echo json_encode(t('pm_new_message', ' 给你发来新消息')); ?>;
        // 显示/隐藏禁言提示条（禁言不踢下线，仅提示并拦截发帖/回帖）
        // 样式由 .toast/.mute-tip/.mute-tip--floating 组件类提供，配合 .is-visible 切换显隐
        function showMuteTip(m) {
            var el = document.getElementById('mute-tip');
            if (!el) {
                el = document.createElement('div');
                el.id = 'mute-tip';
                el.className = 'toast mute-tip mute-tip--floating';
                el.setAttribute('role', 'status');
                document.body.appendChild(el);
            }
            el.textContent = (m && m.message) ? m.message : MUTE_DEFAULT;
            el.classList.add('is-visible');
        }
        function hideMuteTip() {
            var el = document.getElementById('mute-tip');
            if (el) el.classList.remove('is-visible');
        }
        function checkBan() {
            try {
                fetch('<?php echo site_url('api/check_ban_status'); ?>&_=' + Date.now(), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.banned) {
                            // 立即跳转封禁页
                            window.location.href = '<?php echo site_url('banned'); ?>';
                            return;
                        }
                        if (data && data.muted) {
                            showMuteTip(data.mute);
                        } else {
                            hideMuteTip();
                        }
                    })
                    .catch(function () {});
            } catch (e) {}
        }
        // 页面可见时轮询，隐藏时暂停（节省资源）
        function start() { if (!timer) timer = setInterval(checkBan, INTERVAL); }
        function stop()  { if (timer) { clearInterval(timer); timer = null; } }
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) stop(); else { checkBan(); start(); }
        });
        start();
    })();
    </script>
<?php endif; ?>

<?php
// 仅对已登录用户注入站内信未读轮询：收到新私信时弹 toast 提醒并更新头部未读徽标
if (is_logged_in()):
?>
    <script>
    (function () {
        var INTERVAL = 15000; // 15 秒轮询一次
        var URL = '<?php echo site_url('api/pm_unread'); ?>&_=' + Date.now();
        var timer = null;
        var lastUnread = null; // null 表示尚未完成首次轮询（首次不弹提醒）

        // 从头部 DOM 读取初始未读数（避免先弹一次旧消息的提醒）
        function readInitialUnread() {
            var el = document.getElementById('pm-tab-count');
            if (!el) return 0;
            var m = (el.textContent || '').match(/\((\d+)\)/);
            return m ? parseInt(m[1], 10) : 0;
        }
        lastUnread = readInitialUnread();

        // 更新头部未读徽标
        function updateBadges(unread) {
            var tabEl = document.getElementById('pm-tab-count');
            if (tabEl) tabEl.textContent = '(' + unread + ')';

            var menuEl = document.getElementById('pm-menu-badge');
            if (unread > 0) {
                if (menuEl) {
                    menuEl.textContent = unread;
                    menuEl.style.display = '';
                } else {
                    var target = document.getElementById('pm-menu-anchor');
                    if (target) {
                        menuEl = document.createElement('span');
                        menuEl.className = 'user-dropdown-menu-badge';
                        menuEl.id = 'pm-menu-badge';
                        menuEl.textContent = unread;
                        target.appendChild(menuEl);
                    }
                }
            } else if (menuEl) {
                menuEl.style.display = 'none';
            }
        }

        // 弹出新私信提醒卡片
        function showPmToast(latest) {
            if (!latest || !latest.username) return;
            // 正在站内信页面时不打扰
            if (location.pathname.indexOf('/pm') !== -1 || (location.search || '').indexOf('route=pm') !== -1) return;

            var wrap = document.getElementById('pm-toast-wrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'pm-toast-wrap';
                wrap.className = 'pm-toast-wrap';
                document.body.appendChild(wrap);
            }
            var toast = document.createElement('a');
            toast.href = '<?php echo site_url('pm', ['action' => 'view']); ?>&id=' + latest.conversation_id;
            toast.className = 'pm-toast';
            toast.innerHTML =
                '<img src="' + latest.avatar + '" alt="" class="pm-toast-avatar">' +
                '<div class="pm-toast-body">' +
                '<div class="pm-toast-title">' + escapeHtml(latest.username) + PM_NEW + '</div>' +
                '<div class="pm-toast-content">' + escapeHtml(latest.content) + '</div>' +
                '</div>' +
                '<button type="button" data-close-toast class="pm-toast-close" aria-label="' . e(t('common_close_aria', '关闭')) . '">×</button>';
            wrap.appendChild(toast);

            var closer = toast.querySelector('[data-close-toast]');
            if (closer) closer.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toast.remove();
            });

            setTimeout(function () {
                if (toast.parentNode) toast.remove();
            }, 10000);
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function poll() {
            try {
                fetch(URL, { cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.success) return;
                        var unread = parseInt(data.unread, 10) || 0;
                        if (lastUnread !== null && unread > lastUnread) {
                            showPmToast(data.latest);
                        }
                        lastUnread = unread;
                        updateBadges(unread);
                    })
                    .catch(function () {});
            } catch (e) {}
        }

        function start() { if (!timer) timer = setInterval(poll, INTERVAL); }
        function stop()  { if (timer) { clearInterval(timer); timer = null; } }
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) stop(); else { poll(); start(); }
        });
        start();
    })();
    </script>
<?php endif; ?>
</body>
</html>
