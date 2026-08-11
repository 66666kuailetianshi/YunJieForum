<?php
/**
 * 云界论坛 - 管理后台头部
 *
 * 注意：此文件只负责输出 HTML 头部与侧边栏菜单。
 * 初始化、权限检查请在引入本文件前先引入 admin-init.php。
 */

$activeMenu = $activeMenu ?? 'dashboard';

// 菜单可见性判定：'perm' 为 'super' 时需超级管理员；
// 为具体权限串时按 has_permission() 检查；空值表示所有管理员可见。
$menuIsSuperAdmin = is_super_admin();
$menuPermVisible = function ($perm) use ($menuIsSuperAdmin) {
    if ($perm === null || $perm === '') return true;
    if ($menuIsSuperAdmin) return true;
    if ($perm === 'super') return false;
    return has_permission($perm);
};

$menuItems = [
    'dashboard'               => ['icon' => 'dashboard',      'label' => t('menu_dashboard', '概览'),         'url' => '/admin', 'perm' => ''],
    'system_status'           => ['icon' => 'system',         'label' => t('menu_system_status', '运行状态'),     'url' => '/admin/system_status', 'perm' => 'super'],
    'traffic_monitor'         => ['icon' => 'traffic',        'label' => t('menu_traffic_monitor', '流量监测'),     'url' => '/admin/traffic_monitor', 'perm' => 'super'],
    'users'                   => ['icon' => 'users',          'label' => t('menu_users', '用户管理'),     'url' => '/admin/users', 'perm' => 'manage_user_dispose'],
    'user_groups'             => ['icon' => 'user_groups',    'label' => t('menu_user_groups', '用户组'),       'url' => '/admin/user_groups', 'perm' => 'super'],
    'posts'                   => ['icon' => 'posts',          'label' => t('menu_posts', '帖子管理'),     'url' => '/admin/posts', 'perm' => 'manage_posts'],
    'replies'                 => ['icon' => 'replies',        'label' => t('menu_replies', '回复管理'),     'url' => '/admin/replies', 'perm' => 'manage_replies'],
    'reports'                 => ['icon' => 'reports',        'label' => t('menu_reports', '举报管理'),     'url' => '/admin/reports', 'perm' => 'manage_reports'],
    'ban_appeals'             => ['icon' => 'lock',           'label' => t('menu_ban_appeals', '申诉管理'),     'url' => '/admin/ban_appeals', 'perm' => 'manage_ban_appeals'],
    'email_disclosure'        => ['icon' => 'lock',           'label' => t('menu_email_disclosure', '邮箱披露申请'), 'url' => '/admin/email_disclosure', 'perm' => 'super'],
    'tickets'                 => ['icon' => 'send',            'label' => t('menu_tickets', '工单系统'),       'url' => '/admin/tickets', 'perm' => ''],
    'password_reset_requests' => ['icon' => 'key',            'label' => t('menu_password_reset', '密码重置审核'), 'url' => '/admin/password_reset_requests', 'perm' => 'super'],
    'forums'                  => ['icon' => 'forums',         'label' => t('menu_forums', '版块管理'),     'url' => '/admin/forums', 'perm' => 'super'],
    'announcements'           => ['icon' => 'announcements',  'label' => t('menu_announcements', '公告管理'),     'url' => '/admin/announcements', 'perm' => 'super'],
    'roles'                   => ['icon' => 'roles',          'label' => t('menu_roles', '权限组'),       'url' => '/admin/roles', 'perm' => 'super'],
    'medals'                  => ['icon' => 'medals',         'label' => t('menu_medals', '勋章管理'),     'url' => '/admin/medals', 'perm' => 'super'],
    'sensitive_words'         => ['icon' => 'shield',         'label' => t('menu_sensitive_words', '敏感词管理'),   'url' => '/admin/sensitive_words', 'perm' => 'super'],
    'mail_center'             => ['icon' => 'mail',           'label' => t('menu_mail_center', '邮件中心'),     'url' => '/admin/mail_center', 'perm' => 'super'],
    'backup'                  => ['icon' => 'backup',         'label' => t('menu_backup', '数据备份'),     'url' => '/admin/backup', 'perm' => 'super'],
    'data_migration'          => ['icon' => 'migration',       'label' => t('menu_data_migration', '数据迁移'),   'url' => '/admin/data_migration', 'perm' => 'super'],
    'settings'                => ['icon' => 'settings',       'label' => t('menu_settings', '站点设置'),     'url' => '/admin/site_settings', 'perm' => 'super'],
    'captcha_debug'           => ['icon' => 'shield',         'label' => t('menu_captcha_debug', '验证码调试'),   'url' => '/admin/captcha_debug', 'perm' => 'super'],
    'site_pages'              => ['icon' => 'document',       'label' => t('menu_site_pages', '协议页面管理'), 'url' => '/admin/site_pages', 'perm' => 'super'],
    'update_center'           => ['icon' => 'update',          'label' => t('menu_update_center', '系统更新'), 'url' => '/admin/update_center', 'perm' => 'super'],
    ];

// badge 按需查询：仅当对应菜单项可见时才查询待处理计数
if ($menuPermVisible($menuItems['reports']['perm'])) {
    $menuItems['reports']['badge'] = get_pending_report_count();
}
if ($menuPermVisible($menuItems['ban_appeals']['perm'])) {
    $menuItems['ban_appeals']['badge'] = get_pending_ban_appeal_count();
}
if ($menuPermVisible($menuItems['password_reset_requests']['perm'])) {
    $menuItems['password_reset_requests']['badge'] = get_pending_password_reset_count();
}
if ($menuPermVisible($menuItems['email_disclosure']['perm']) && $menuIsSuperAdmin) {
    $menuItems['email_disclosure']['badge'] = get_pending_email_disclosure_count();
}
if ($menuPermVisible($menuItems['tickets']['perm'])) {
    $menuItems['tickets']['badge'] = get_open_ticket_count();
}

// 过滤不可见菜单项（分组内全部不可见时，渲染阶段整组隐藏）
foreach ($menuItems as $menuKey => $menuItem) {
    if (!$menuPermVisible($menuItem['perm'] ?? '')) {
        unset($menuItems[$menuKey]);
    }
}

// 菜单分组定义：key => [组名, [菜单项 key 列表]]
// 分组逻辑：概览 → 用户管理 → 内容管理（版块/帖子/回复/公告）→ 审核与反馈（举报/申诉/重置/披露/工单/敏感词）→ 邮件中心 → 系统设置 → 运维与更新
$menuGroups = [
    'overview'  => [t('menu_group_overview', '概览'),       ['dashboard', 'system_status', 'traffic_monitor']],
    'users'     => [t('menu_group_users', '用户管理'),       ['users', 'user_groups', 'roles', 'medals']],
    'content'   => [t('menu_group_content', '内容管理'),     ['forums', 'posts', 'replies', 'announcements']],
    'review'    => [t('menu_group_review', '审核与反馈'),    ['reports', 'ban_appeals', 'password_reset_requests', 'email_disclosure', 'tickets', 'sensitive_words']],
    'mail'      => [t('menu_group_mail', '邮件中心'),        ['mail_center']],
    'settings'  => [t('menu_group_settings', '系统设置'),    ['settings', 'site_pages', 'captcha_debug']],
    'ops'       => [t('menu_group_ops', '运维与更新'),       ['backup', 'data_migration', 'update_center']],
];

/**
 * 渲染管理后台菜单 SVG 图标
 */
function admin_menu_icon(string $key): string {
    $icons = [
        'dashboard'     => '<path d="M3 3h7v9H3z"/><path d="M14 3h7v5h-7z"/><path d="M14 12h7v9h-7z"/><path d="M3 16h7v5H3z"/>',
        'system'        => '<rect x="4" y="4" width="16" height="16" rx="2" ry="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>',
        'traffic'       => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'users'         => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user_groups'   => '<path d="m2 4 3 12h14l3-12-6 7-4-7-4 7-6-7zm3 16h14"/>',
        'posts'         => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
        'replies'       => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'reports'       => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
        'lock'          => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'key'           => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        'forums'        => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'announcements' => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'roles'         => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'medals'        => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
        'shield'        => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'send'          => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'mail'          => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'backup'        => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'migration'     => '<path d="M16 3h5v5"/><path d="M21 3l-7 7"/><path d="M8 21H3v-5"/><path d="M3 21l7-7"/><line x1="21" y1="8" x2="13" y2="16"/><line x1="11" y1="8" x2="3" y2="16"/>',
        'settings'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'document'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
        'update'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    ];
    $path = $icons[$key] ?? $icons['dashboard'];
    return '<svg class="admin-menu-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(APP_LANG); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?php echo e($pageTitle ?? t('admin_panel', '管理后台')); ?> - <?php echo e(SITE_NAME); ?></title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
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
    <link rel="stylesheet" href="/public/css/style.css?v=<?php echo e(APP_VERSION); ?>-ui3">
    <link rel="stylesheet" href="/public/css/tokens.css?v=<?php echo e(APP_VERSION); ?>-ui3">
    <link rel="stylesheet" href="/public/css/base.css?v=<?php echo e(APP_VERSION); ?>-ui3">
    <link rel="stylesheet" href="/public/css/utilities.css?v=<?php echo e(APP_VERSION); ?>-ui3">
    <link rel="stylesheet" href="/public/css/components.css?v=<?php echo e(APP_VERSION); ?>-ui3">
    <link rel="stylesheet" href="/public/css/admin.css?v=<?php echo e(APP_VERSION); ?>-ui3">
    <link rel="stylesheet" href="/public/css/dark.css?v=<?php echo e(APP_VERSION); ?>-ui3">
    <link rel="stylesheet" href="/public/css/header.css?v=<?php echo e(APP_VERSION); ?>-ui3">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
</head>
<body>
    <header class="site-header">
        <div class="header-top">
            <div class="container header-top-inner">
                <a href="<?php echo site_url('admin'); ?>" class="brand" aria-label="<?php echo e(t('common_admin_brand_aria', '{site} 后台', ['site' => SITE_NAME])); ?>">
                    <img src="/public/images/logo.svg" alt="" class="brand-logo">
                    <div class="brand-info">
                        <span class="brand-name"><?php echo e(SITE_NAME); ?></span>
                        <span class="brand-slogan"><?php echo e(t('admin_panel', '管理后台')); ?></span>
                    </div>
                </a>

                <div class="header-tools">
                    <button class="theme-toggle" id="themeToggle" type="button" aria-label="<?php echo e(t('toggle_theme', '切换深色模式')); ?>" title="<?php echo e(t('toggle_theme', '切换主题')); ?>">
                        <svg class="theme-icon-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
                        <svg class="theme-icon-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </button>
                    <a href="/" class="nav-link"><?php echo e(t('back_to_frontend', '返回前台')); ?></a>
                    <form method="post" action="<?php echo site_url('logout'); ?>" class="inline-action-form" data-confirm="<?php echo e(t('confirm_logout', '确定要退出登录吗？')); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <button type="submit" class="nav-link"><?php echo e(t('logout', '退出')); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <main class="site-main">
        <div class="container">
            <?php
            $flash = get_flash();
            if ($flash): ?>
                <?php echo show_message($flash['message'], $flash['type']); ?>
            <?php endif; ?>

            <div class="admin-grid">
                <aside class="admin-sidebar">
                    <?php foreach ($menuGroups as $groupKey => $groupDef): ?>
                        <?php list($groupLabel, $groupItemKeys) = $groupDef; ?>
                        <?php
                        // 权限过滤后组内无任何可见项时整组隐藏
                        $groupVisibleKeys = array_intersect($groupItemKeys, array_keys($menuItems));
                        if (empty($groupVisibleKeys)) continue;
                        // 判断当前激活项是否在此组中，决定是否默认展开
                        $isGroupActive = in_array($activeMenu, $groupItemKeys, true);
                        // 默认展开"概览"组和包含当前激活项的组
                        $isExpanded = $isGroupActive || $groupKey === 'overview';
                        ?>
                        <div class="admin-menu-group<?php echo $isExpanded ? ' is-expanded' : ''; ?>" data-group="<?php echo e($groupKey); ?>">
                            <button type="button" class="admin-menu-group-title" aria-expanded="<?php echo $isExpanded ? 'true' : 'false'; ?>">
                                <span class="admin-menu-group-text"><?php echo e($groupLabel); ?></span>
                                <svg class="admin-menu-group-arrow" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <ul class="admin-menu">
                                <?php foreach ($groupItemKeys as $key): ?>
                                    <?php $item = $menuItems[$key] ?? null; if (!$item) continue; ?>
                                    <li class="admin-menu-item">
                                        <a href="<?php echo to_entry_url($item['url']); ?>" class="admin-menu-link <?php echo $activeMenu === $key ? 'active' : ''; ?>">
                                            <span class="admin-menu-icon"><?php echo admin_menu_icon($item['icon']); ?></span>
                                            <span class="admin-menu-label"><?php echo e($item['label']); ?></span>
                                            <?php if (isset($item['badge'])): ?>
                                                <span class="admin-menu-badge" data-badge-key="<?php echo e($key); ?>"<?php if ((int)$item['badge'] <= 0): ?> style="display:none;"<?php endif; ?>><?php echo (int)$item['badge']; ?></span>
                                            <?php endif; ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </aside>
                <div class="admin-content">
