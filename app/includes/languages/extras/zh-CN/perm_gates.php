<?php return [
    // ---------- 权限门禁通用文案（任务 #13 权限分级） ----------
    'common_no_permission_page'            => '你没有权限访问该页面。',
    'common_super_admin_only'              => '该功能仅最高管理员可用。',
    'admin_ajax_forbidden'                 => '无权访问',
    'admin_users_cannot_operate_community_admin' => '不能对社区管理员执行该操作。',

    // ---------- 权限组（roles.php）权限点白名单文案 ----------
    'admin_roles_label_perm_points'        => '权限点',
    'admin_roles_hint_permissions_whitelist' => 'admin_access 可进入管理后台；超级管理员无需分配即拥有全部权限。这里仅列出社区管理员等角色可用的权限点，版块、勋章、公告、敏感词、用户管理等为超管专属能力，不在此分配。',
    'admin_roles_hint_no_permissions' => '权限点由系统内置管理：超级管理员天然拥有全部权限，社区管理员等内置角色的权限由系统固定，不在此页面配置。',

    // ---------- 权限点显示名 ----------
    'admin_perm_admin_access'              => '后台访问',
    'admin_perm_manage_posts'              => '帖子管理',
    'admin_perm_manage_replies'            => '回复管理',
    'admin_perm_manage_reports'            => '举报管理',
    'admin_perm_manage_ban_appeals'        => '申诉管理',
    'admin_perm_manage_user_dispose'       => '用户处置（封禁/禁言/解封等）',
    'admin_perm_manage_users'              => '用户管理',
    'admin_perm_manage_forums'             => '版块管理',
    'admin_perm_manage_medals'             => '勋章管理',
    'admin_perm_manage_announcements'      => '公告管理',
    'admin_perm_manage_sensitive_words'    => '敏感词管理',
    'admin_perm_manage_settings'           => '站点设置（超管专属）',
    'admin_perm_manage_roles'              => '权限组（超管专属）',
    'admin_perm_manage_backup'             => '数据备份（超管专属）',
    'admin_perm_manage_update'             => '系统更新（超管专属）',
    'admin_perm_manage_mail'               => '邮件中心（超管专属）',
    'admin_perm_manage_system_status'      => '运行状态（超管专属）',
    'admin_perm_manage_data_migration'     => '数据迁移（超管专属）',
];
