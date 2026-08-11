<?php return [
    // ---------- Permission gate common texts (Task #13 permission tiers) ----------
    'common_no_permission_page'            => 'You do not have permission to access this page.',
    'common_super_admin_only'              => 'This feature is only available to the super administrator.',
    'admin_users_cannot_operate_community_admin' => 'This operation cannot be performed on a community administrator.',

    // ---------- Roles (roles.php) permission whitelist texts ----------
    'admin_roles_label_perm_points'        => 'Permission points',
    'admin_roles_hint_permissions_whitelist' => 'admin_access grants access to the admin panel; the super administrator holds all permissions without assignment. Only permission points usable by community admin roles are listed here; forums, medals, announcements, sensitive words and user management are super-admin-only capabilities and are not assigned here.',
    'admin_roles_hint_no_permissions' => 'Permission points are managed by the system: the super administrator inherently holds all permissions, and built-in roles such as community admin have fixed permissions; they are not configured on this page.',

    // ---------- Permission point display names ----------
    'admin_perm_admin_access'              => 'Admin panel access',
    'admin_perm_manage_posts'              => 'Post management',
    'admin_perm_manage_replies'            => 'Reply management',
    'admin_perm_manage_reports'            => 'Report management',
    'admin_perm_manage_ban_appeals'        => 'Ban appeal management',
    'admin_perm_manage_user_dispose'       => 'User disposition (ban/mute/unban, etc.)',
    'admin_perm_manage_users'              => 'User management',
    'admin_perm_manage_forums'             => 'Forum management',
    'admin_perm_manage_medals'             => 'Medal management',
    'admin_perm_manage_announcements'      => 'Announcement management',
    'admin_perm_manage_sensitive_words'    => 'Sensitive word management',
    'admin_perm_manage_settings'           => 'Site settings (super admin only)',
    'admin_perm_manage_roles'              => 'Permission groups (super admin only)',
    'admin_perm_manage_backup'             => 'Data backup (super admin only)',
    'admin_perm_manage_update'             => 'System update (super admin only)',
    'admin_perm_manage_mail'               => 'Mail center (super admin only)',
    'admin_perm_manage_system_status'      => 'System status (super admin only)',
    'admin_perm_manage_data_migration'     => 'Data migration (super admin only)',
];
