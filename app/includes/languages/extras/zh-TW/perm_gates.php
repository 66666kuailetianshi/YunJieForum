<?php return [
    // ---------- 權限門禁通用文案（任務 #13 權限分級） ----------
    'common_no_permission_page'            => '你沒有權限訪問該頁面。',
    'common_super_admin_only'              => '該功能僅最高管理員可用。',
    'admin_users_cannot_operate_community_admin' => '不能對社區管理員執行該操作。',

    // ---------- 權限組（roles.php）權限點白名單文案 ----------
    'admin_roles_label_perm_points'        => '權限點',
    'admin_roles_hint_permissions_whitelist' => 'admin_access 可進入管理後台；超級管理員無需分配即擁有全部權限。這裡僅列出社群管理員等角色可用的權限點，版塊、勳章、公告、敏感詞、用戶管理等為超管專屬能力，不在此分配。',
    'admin_roles_hint_no_permissions' => '權限點由系統內建管理：超級管理員天然擁有全部權限，社群管理員等內建角色的權限由系統固定，不在此頁面配置。',

    // ---------- 權限點顯示名 ----------
    'admin_perm_admin_access'              => '後台訪問',
    'admin_perm_manage_posts'              => '帖子管理',
    'admin_perm_manage_replies'            => '回覆管理',
    'admin_perm_manage_reports'            => '舉報管理',
    'admin_perm_manage_ban_appeals'        => '申訴管理',
    'admin_perm_manage_user_dispose'       => '用戶處置（封禁/禁言/解封等）',
    'admin_perm_manage_users'              => '用戶管理',
    'admin_perm_manage_forums'             => '版塊管理',
    'admin_perm_manage_medals'             => '勳章管理',
    'admin_perm_manage_announcements'      => '公告管理',
    'admin_perm_manage_sensitive_words'    => '敏感詞管理',
    'admin_perm_manage_settings'           => '站點設置（超管專屬）',
    'admin_perm_manage_roles'              => '權限組（超管專屬）',
    'admin_perm_manage_backup'             => '數據備份（超管專屬）',
    'admin_perm_manage_update'             => '系統更新（超管專屬）',
    'admin_perm_manage_mail'               => '郵件中心（超管專屬）',
    'admin_perm_manage_system_status'      => '運行狀態（超管專屬）',
    'admin_perm_manage_data_migration'     => '數據遷移（超管專屬）',
];
