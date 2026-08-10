<?php
/**
 * 云界论坛 - 公共头部模板（火绒安全论坛风格）
 */

require_once __DIR__ . '/functions.php';

// 若当前账号已被封禁，立即强制登出
enforce_user_ban();

$currentUser = current_user();

// 管理员重置密码后，强制用户先修改密码再访问其他页面
if ($currentUser && !empty($currentUser['force_password_change']) && current_route() !== 'force_change_password') {
    redirect('/force_change_password');
}

// 兼容旧安装：若系统中没有管理员，自动将最早注册用户提升为管理员
ensure_admin_exists();
// 仅在无管理员触发升级时才需要刷新当前用户缓存；该场景极少发生，
// 不再每次请求都清空缓存重查，避免每个页面多一次数据库查询。

$pageTitle = isset($pageTitle) ? $pageTitle : SITE_NAME;

// 更新在线状态
update_last_active();

// 流量统计埋点
track_visit();

// 未读站内信数
$unreadPm = unread_pm_count();

// 未读通知数与最近通知（合并为一次查询，从结果集计数）
$unreadNotify = 0;
$recentNotifications = [];
if (is_logged_in() && $currentUser) {
    $stmt = get_db()->prepare("SELECT * FROM notifications WHERE user_id = :uid AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([':uid' => $currentUser['id']]);
    $recentNotifications = $stmt->fetchAll();
    // 额外获取未读总数（仅在最近5条不足以覆盖全部未读时）
    if (count($recentNotifications) >= 5) {
        $unreadNotify = get_unread_notification_count((int)$currentUser['id']);
    } else {
        $unreadNotify = count($recentNotifications);
    }
}

// 用户下拉卡片所需统计（合并 replies + favorites 为一次查询）
$userDropdownStats = null;
if (is_logged_in() && $currentUser) {
    $db = get_db();
    $stmt = $db->prepare("SELECT
        (SELECT COUNT(*) FROM replies WHERE user_id = :uid1) AS replies,
        (SELECT COUNT(*) FROM favorites WHERE user_id = :uid2) AS favorites");
    $stmt->execute([':uid1' => $currentUser['id'], ':uid2' => $currentUser['id']]);
    $counts = $stmt->fetch();
    $userDropdownStats = [
        'points'    => (int)$currentUser['points'],
        'posts'     => (int)$currentUser['posts_count'],
        'replies'   => (int)($counts['replies'] ?? 0),
        'favorites' => (int)($counts['favorites'] ?? 0),
    ];
}

?>
<!DOCTYPE html>
<html lang="<?php echo e(APP_LANG); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="description" content="<?php echo e(t('common_meta_description', '一个简洁美观的社区论坛') . ' - ' . SITE_NAME); ?>">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo e($pageTitle); ?> · <?php echo e(SITE_NAME); ?></title>
    <script>
        // 主题初始化 + 切换（内联，不依赖外部 JS，避免缓存导致失效）
        (function () {
            var KEY = 'yj-theme';
            function apply(t) { if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t); }
            try { apply(localStorage.getItem(KEY)); } catch (e) {}
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', bind);
            } else {
                bind();
            }
            function bind() {
                var btn = document.getElementById('themeToggle');
                if (!btn || btn._themeBound) return;
                btn._themeBound = true;
                btn.addEventListener('click', function () {
                    var current = document.documentElement.getAttribute('data-theme');
                    var next;
                    if (current === 'dark') next = 'light';
                    else if (current === 'light') next = 'dark';
                    else next = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'light' : 'dark';
                    apply(next);
                    try { localStorage.setItem(KEY, next); } catch (e) {}
                });
            }
        })();
    </script>
    <link rel="stylesheet" href="/public/css/style.css?v=<?php echo e(APP_VERSION); ?>-ui2">
    <link rel="stylesheet" href="/index.php?route=captcha/assets&file=captcha.css&v=<?php echo e(APP_VERSION); ?>-ui2">
    <link rel="stylesheet" href="/public/css/tokens.css?v=<?php echo e(APP_VERSION); ?>-ui2">
    <link rel="stylesheet" href="/public/css/base.css?v=<?php echo e(APP_VERSION); ?>-ui2">
    <link rel="stylesheet" href="/public/css/utilities.css?v=<?php echo e(APP_VERSION); ?>-ui2">
    <link rel="stylesheet" href="/public/css/dark.css?v=<?php echo e(APP_VERSION); ?>-ui2">
    <link rel="stylesheet" href="/public/css/header.css?v=<?php echo e(APP_VERSION); ?>-ui3">
    <?php if (!empty($extraStyles) && is_array($extraStyles)): ?>
        <?php foreach ($extraStyles as $style): ?>
            <link rel="stylesheet" href="<?php echo e($style); ?>?v=<?php echo e(APP_VERSION); ?>-ui2">
        <?php endforeach; ?>
    <?php endif; ?>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/public/images/logo.svg">
</head>
<body>
    <header class="site-header">
        <div class="header-top">
            <div class="container header-top-inner">
                <a href="/" class="brand" aria-label="<?php echo e(t('common_brand_home_aria', '{site} 首页', ['site' => SITE_NAME])); ?>">
                    <img src="/public/images/logo.svg" alt="" class="brand-logo">
                    <div class="brand-info">
                        <span class="brand-name"><?php echo e(SITE_NAME); ?></span>
                        <?php if (SITE_SLOGAN !== ''): ?>
                            <span class="brand-slogan"><?php echo e(SITE_SLOGAN); ?></span>
                        <?php endif; ?>
                    </div>
                </a>

                <nav class="main-nav" id="mainNav" aria-label="<?php echo e(t('nav_main', '主导航')); ?>">
                    <ul class="nav-list">
                        <li><a href="/" class="nav-link <?php echo current_route() === 'home' ? 'active' : ''; ?>"><?php echo e(t('nav_home', '首页')); ?></a></li>
                        <?php if (is_logged_in()): ?>
                            <li><a href="<?php echo site_url('new_post'); ?>" class="nav-link <?php echo current_route() === 'new_post' ? 'active' : ''; ?>"><?php echo e(t('nav_new_post', '发帖')); ?></a></li>
                            <?php if (is_admin()): ?>
                                <li><a href="<?php echo site_url('admin'); ?>" class="nav-link nav-link-admin"><?php echo e(t('nav_admin', '管理后台')); ?></a></li>
                            <?php endif; ?>
                        <?php else: ?>
                            <li><a href="<?php echo site_url('login'); ?>" class="nav-link"><?php echo e(t('nav_login', '登录')); ?></a></li>
                            <li><a href="<?php echo site_url('register'); ?>" class="nav-link nav-link-primary"><?php echo e(t('nav_register', '注册')); ?></a></li>
                        <?php endif; ?>
                    </ul>
                </nav>

                <div class="header-tools">
                    <!-- 搜索框 -->
                    <form action="<?php echo site_url('search'); ?>" method="get" class="header-search" role="search">
                        <input type="text" name="q" placeholder="<?php echo e(t('search_placeholder', '搜索内容…')); ?>" class="header-search-input" value="<?php echo e((string)($_GET['q'] ?? '')); ?>" maxlength="100">
                        <button type="submit" class="header-search-btn" aria-label="<?php echo e(t('common_search_aria', '搜索')); ?>">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        </button>
                    </form>

                    <button class="theme-toggle" id="themeToggle" type="button" aria-label="<?php echo e(t('toggle_theme', '切换深色模式')); ?>" title="<?php echo e(t('toggle_theme', '切换主题')); ?>">
                        <svg class="theme-icon-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
                        <svg class="theme-icon-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </button>

                    <?php if (is_logged_in() && $currentUser): ?>
                        <div class="notification-dropdown" id="notificationDropdown">
                            <button type="button" class="notification-trigger" id="notificationTrigger" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo e(t('common_notification_aria', '通知')); ?>">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                                <?php if ($unreadNotify > 0): ?>
                                    <span class="notification-badge"><?php echo $unreadNotify; ?></span>
                                <?php endif; ?>
                            </button>
                            <div class="notification-panel" id="notificationPanel">
                                <div class="notification-panel-header">
                                    <span class="notification-panel-title"><?php echo e(t('notifications', '通知')); ?></span>
                                    <div class="notification-panel-actions">
                                        <?php if ($unreadNotify > 0): ?>
                                            <a href="<?php echo e(site_url('notifications', ['action' => 'mark_all_read', 'csrf_token' => csrf_token(), 'redirect' => $_SERVER['REQUEST_URI'] ?? '/'])); ?>" class="notification-panel-mark"><?php echo e(t('mark_all_read', '全部已读')); ?></a>
                                        <?php endif; ?>
                                        <a href="<?php echo site_url('notifications'); ?>" class="notification-panel-all"><?php echo e(t('view_all', '查看全部')); ?></a>
                                    </div>
                                </div>
                                <ul class="notification-list">
                                    <?php if (empty($recentNotifications)): ?>
                                        <li class="notification-empty"><?php echo e(t('no_notifications', '暂无通知')); ?></li>
                                    <?php else: ?>
                                        <?php foreach ($recentNotifications as $n): ?>
                                            <li class="notification-item <?php echo (int)$n['is_read'] === 0 ? 'is-unread' : 'is-read'; ?>">
                                                <a href="<?php echo e(site_url('notification_read', ['id' => (int)$n['id'], 'csrf_token' => csrf_token()])); ?>" class="notification-link">
                                                    <div class="notification-title"><?php echo e($n['title']); ?></div>
                                                    <?php if ($n['content'] !== ''): ?>
                                                        <div class="notification-content"><?php echo e(mb_substr(strip_tags($n['content']), 0, 40, 'UTF-8')); ?></div>
                                                    <?php endif; ?>
                                                    <div class="notification-time"><?php echo time_ago($n['created_at']); ?></div>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (is_logged_in() && $currentUser && $userDropdownStats): ?>
                        <div class="user-dropdown">
                            <a href="<?php echo site_url('profile'); ?>" class="header-user" aria-haspopup="true" aria-expanded="false" aria-controls="userDropdownPanel" id="userDropdownTrigger">
                                <img src="<?php echo avatar_url($currentUser['avatar'], $currentUser['username']); ?>" alt="" class="header-user-avatar">
                                <span class="header-user-name"><?php echo e($currentUser['username']); ?></span>
                                <svg class="user-dropdown-caret" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                            </a>
                            <div class="user-dropdown-panel" id="userDropdownPanel">
                                <div class="user-dropdown-header">
                                    <img src="<?php echo avatar_url($currentUser['avatar'], $currentUser['username']); ?>" alt="" class="user-dropdown-avatar">
                                    <div class="user-dropdown-meta">
                                        <div class="user-dropdown-name"><?php echo e($currentUser['username']); ?></div>
                                        <div class="user-dropdown-role">
                                            <span><?php echo e(t('view_profile', '查看个人资料')); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="user-dropdown-stats">
                                    <div class="user-dropdown-stat">
                                        <span class="user-dropdown-stat-num"><?php echo $userDropdownStats['points']; ?></span>
                                        <span class="user-dropdown-stat-label"><?php echo e(t('stat_points', '积分')); ?></span>
                                    </div>
                                    <div class="user-dropdown-stat">
                                        <span class="user-dropdown-stat-num"><?php echo $userDropdownStats['posts']; ?></span>
                                        <span class="user-dropdown-stat-label"><?php echo e(t('stat_posts', '帖子')); ?></span>
                                    </div>
                                    <div class="user-dropdown-stat">
                                        <span class="user-dropdown-stat-num"><?php echo $userDropdownStats['replies']; ?></span>
                                        <span class="user-dropdown-stat-label"><?php echo e(t('stat_replies', '回复')); ?></span>
                                    </div>
                                    <div class="user-dropdown-stat">
                                        <span class="user-dropdown-stat-num"><?php echo $userDropdownStats['favorites']; ?></span>
                                        <span class="user-dropdown-stat-label"><?php echo e(t('stat_favorites', '收藏')); ?></span>
                                    </div>
                                </div>

                                <div class="user-dropdown-tabs">
                                    <a href="<?php echo site_url('pm'); ?>" class="user-dropdown-tab">
                                        <span class="user-dropdown-tab-label"><?php echo e(t('pm_messages', '消息')); ?></span>
                                        <span class="user-dropdown-tab-count" id="pm-tab-count">(<?php echo $unreadPm; ?>)</span>
                                    </a>
                                    <a href="<?php echo site_url('favorites'); ?>" class="user-dropdown-tab">
                                        <span class="user-dropdown-tab-label"><?php echo e(t('nav_favorites', '收藏')); ?></span>
                                        <span class="user-dropdown-tab-count">(<?php echo $userDropdownStats['favorites']; ?>)</span>
                                    </a>
                                </div>

                                <div class="user-dropdown-menu">
                                    <a href="<?php echo site_url('profile', ['tab' => 'posts']); ?>" class="user-dropdown-menu-item">
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        <span><?php echo e(t('my_topics', '我的主题')); ?></span>
                                    </a>
                                    <a href="<?php echo site_url('favorites'); ?>" class="user-dropdown-menu-item">
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                        <span><?php echo e(t('my_favorites', '我的收藏')); ?></span>
                                    </a>
                                    <a href="<?php echo site_url('pm'); ?>" class="user-dropdown-menu-item" id="pm-menu-anchor">
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                        <span><?php echo e(t('pm_messages', '站内消息')); ?></span>
                                        <?php if ($unreadPm > 0): ?><span class="user-dropdown-menu-badge" id="pm-menu-badge"><?php echo $unreadPm; ?></span><?php endif; ?>
                                    </a>
                                    <?php if (is_admin()): ?>
                                        <a href="<?php echo site_url('admin'); ?>" class="user-dropdown-menu-item user-dropdown-menu-item-admin">
                                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                                            <span><?php echo e(t('nav_admin', '管理后台')); ?></span>
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?php echo site_url('profile'); ?>" class="user-dropdown-menu-item">
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                        <span><?php echo e(t('user_center', '个人中心')); ?></span>
                                    </a>
                                </div>

                                <a href="<?php echo e(site_url('logout', ['csrf_token' => csrf_token()])); ?>" class="user-dropdown-logout" data-confirm="<?php echo e(t('confirm_logout', '确定要退出登录吗？')); ?>">
                                    <?php echo e(t('logout', '退出登录')); ?>
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?php echo site_url('login'); ?>" class="header-user">
                            <span class="header-user-avatar header-user-avatar-text">?</span>
                            <span class="header-user-name"><?php echo e(t('nav_login', '登录')); ?></span>
                        </a>
                    <?php endif; ?>

                    <button class="nav-toggle" id="navToggle" aria-label="<?php echo e(t('toggle_nav', '切换导航菜单')); ?>" aria-expanded="false">
                        <span class="nav-toggle-bar"></span>
                        <span class="nav-toggle-bar"></span>
                        <span class="nav-toggle-bar"></span>
                    </button>
                </div>
            </div>
        </div>

    <script>
    // 通知下拉菜单
    (function () {
        var trigger = document.getElementById('notificationTrigger');
        var panel = document.getElementById('notificationPanel');
        if (!trigger || !panel) return;

        function positionPanel() {
            var rect = trigger.getBoundingClientRect();
            var panelWidth = panel.offsetWidth || 320;
            var left = rect.right - panelWidth;
            if (left < 8) left = 8;
            panel.style.top = (rect.bottom + 8) + 'px';
            panel.style.left = left + 'px';
        }

        function close() {
            panel.classList.remove('is-visible');
            trigger.setAttribute('aria-expanded', 'false');
        }

        function toggle(e) {
            if (e) e.stopPropagation();
            if (panel.classList.contains('is-visible')) {
                close();
                return;
            }
            // 关闭用户下拉，避免重叠
            var userPanel = document.getElementById('userDropdownPanel');
            if (userPanel) userPanel.classList.remove('is-visible');
            positionPanel();
            panel.classList.add('is-visible');
            trigger.setAttribute('aria-expanded', 'true');
        }

        trigger.addEventListener('click', toggle);
        document.addEventListener('click', function (e) {
            if (!panel.contains(e.target) && !trigger.contains(e.target)) close();
        });
        window.addEventListener('resize', function () {
            if (panel.classList.contains('is-visible')) positionPanel();
        });
    })();
    </script>

    </header>

    <main class="site-main">
        <div class="container">
            <?php
            $flash = get_flash();
            if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type'] === 'error' ? 'error' : $flash['type']); ?>" data-alert>
                    <span class="alert-icon">
                        <?php
                        $icons = ['success' => '✓', 'error' => '✕', 'warning' => '!', 'info' => 'i'];
                        echo isset($icons[$flash['type']]) ? $icons[$flash['type']] : 'i';
                        ?>
                    </span>
                    <span class="alert-message"><?php echo e($flash['message']); ?></span>
                    <button type="button" class="alert-close" aria-label="<?php echo e(t('common_close_aria', '关闭')); ?>" data-close>&times;</button>
                </div>
            <?php endif; ?>
