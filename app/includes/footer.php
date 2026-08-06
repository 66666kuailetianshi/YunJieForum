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
                <a href="<?php echo site_url('service'); ?>"><?php echo e(t('footer_service', '服务协议')); ?></a>
                <span class="footer-sep">·</span>
                <a href="<?php echo site_url('terms'); ?>"><?php echo e(t('footer_terms', '用户协议')); ?></a>
                <span class="footer-sep">·</span>
                <a href="<?php echo site_url('privacy'); ?>"><?php echo e(t('footer_privacy', '隐私政策')); ?></a>
                <span class="footer-sep">·</span>
                <a href="<?php echo site_url('disclaimer'); ?>"><?php echo e(t('footer_disclaimer', '免责声明')); ?></a>
            </p>
        </div>
    </footer>

    <script src="/public/js/main.js?v=<?php echo e(APP_VERSION); ?>-ui2" defer></script>
    <script src="/public/js/lightbox.js?v=<?php echo e(APP_VERSION); ?>-ui2" defer></script>
<?php
// 仅对已登录用户注入封禁状态轮询：管理员封禁后立即踢下线并跳转封禁页
if (is_logged_in() && !is_admin()):
?>
    <script>
    (function () {
        // 排除封禁页本身，避免死循环
        if (location.pathname.indexOf('banned') !== -1 || location.search.indexOf('route=banned') !== -1) return;
        var INTERVAL = 5000; // 5 秒轮询，封禁后最多 5 秒内感知
        var timer = null;
        var MUTE_DEFAULT = <?php echo json_encode(t('mute_tip_default', '你当前处于禁言状态，无法发帖或回复。')); ?>;
        var PM_NEW = <?php echo json_encode(t('pm_new_message', ' 给你发来新消息')); ?>;
        // 显示/隐藏禁言提示条（禁言不踢下线，仅提示并拦截发帖/回帖）
        function showMuteTip(m) {
            var el = document.getElementById('mute-tip');
            if (!el) {
                el = document.createElement('div');
                el.id = 'mute-tip';
                el.style.cssText = 'position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:9999;background:#fef3c7;color:#92400e;padding:10px 18px;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.15);font-size:14px;max-width:90vw;text-align:center;';
                document.body.appendChild(el);
            }
            el.textContent = (m && m.message) ? m.message : MUTE_DEFAULT;
            el.style.display = 'block';
        }
        function hideMuteTip() {
            var el = document.getElementById('mute-tip');
            if (el) el.style.display = 'none';
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
                wrap.style.cssText = 'position:fixed;top:16px;right:16px;z-index:99999;display:flex;flex-direction:column;gap:10px;max-width:340px;width:calc(100vw - 32px);';
                document.body.appendChild(wrap);
            }
            var toast = document.createElement('a');
            toast.href = '<?php echo site_url('pm', ['action' => 'view']); ?>&id=' + latest.conversation_id;
            toast.style.cssText = 'display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--border,#e5e7eb);border-left:4px solid var(--primary,#6366f1);border-radius:12px;padding:10px 12px;box-shadow:0 8px 24px rgba(0,0,0,.14);text-decoration:none;color:inherit;animation:pmToastIn .3s ease;';
            toast.innerHTML =
                '<img src="' + latest.avatar + '" alt="" style="width:36px;height:36px;border-radius:50%;flex-shrink:0;object-fit:cover;background:#f1f5f9;">' +
                '<div style="min-width:0;flex:1;">' +
                '<div style="font-weight:600;font-size:13px;line-height:1.4;">' + escapeHtml(latest.username) + PM_NEW + '</div>' +
                '<div style="font-size:12px;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px;">' + escapeHtml(latest.content) + '</div>' +
                '</div>' +
                '<button type="button" data-close-toast style="border:none;background:none;color:#9ca3af;cursor:pointer;font-size:14px;line-height:1;padding:2px 4px;" aria-label="' . e(t('common_close_aria', '关闭')) . '">×</button>';
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
