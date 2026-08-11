<?php
/**
 * 云界论坛 - 管理后台公共辅助函数
 *
 * 由 layout/admin-init.php 统一引入，供所有后台控制器与后台 API 复用。
 * 注意：不经 admin-init.php 的后台 API（如 posts_ajax.php）若需使用本文件
 * 中的函数，须自行 require_once 本文件。
 */

if (!function_exists('admin_apply_post_flag')) {
    /**
     * 对单个帖子应用状态动作（置顶/加精/锁定/删除）。
     *
     * 与原 posts.php 内联实现行为一致：flag 类动作直接 UPDATE 单字段；
     * 删除动作走 delete_post()（含版块/用户统计同步）。
     * 使用 prepare 占位符，杜绝 SQL 字符串插值。
     *
     * @param int    $postId 帖子 ID（调用方负责 int 化，<=0 视为无效）
     * @param string $action pin|unpin|essence|unessence|lock|unlock|delete
     * @return array ['success' => bool, 'message' => string, 'type' => 'success'|'error']
     */
    function admin_apply_post_flag(int $postId, string $action): array {
        static $flagCols = [
            'pin'       => ['is_pinned', 1],
            'unpin'     => ['is_pinned', 0],
            'essence'   => ['is_essence', 1],
            'unessence' => ['is_essence', 0],
            'lock'      => ['is_locked', 1],
            'unlock'    => ['is_locked', 0],
        ];
        static $flagMsgs = null;
        if ($flagMsgs === null) {
            $flagMsgs = [
                'pin'       => t('admin_posts_flash_pinned', '已置顶。'),
                'unpin'     => t('admin_posts_flash_unpinned', '已取消置顶。'),
                'essence'   => t('admin_posts_flash_essenced', '已加精。'),
                'unessence' => t('admin_posts_flash_unessenced', '已取消加精。'),
                'lock'      => t('admin_posts_flash_locked', '已锁定。'),
                'unlock'    => t('admin_posts_flash_unlocked', '已解锁。'),
            ];
        }

        if ($postId <= 0) {
            return ['success' => false, 'message' => t('admin_posts_flash_invalid_post', '无效的帖子 ID。'), 'type' => 'error'];
        }

        if (isset($flagCols[$action])) {
            list($col, $val) = $flagCols[$action];
            $db = get_db();
            $stmt = $db->prepare("UPDATE posts SET {$col} = :val WHERE id = :id");
            $stmt->execute([':val' => $val, ':id' => $postId]);
            return ['success' => true, 'message' => $flagMsgs[$action], 'type' => 'success'];
        }

        if ($action === 'delete') {
            if (delete_post($postId)) {
                return ['success' => true, 'message' => t('admin_posts_flash_deleted', '帖子已删除。'), 'type' => 'success'];
            }
            return ['success' => false, 'message' => t('admin_posts_flash_delete_failed', '帖子不存在或删除失败。'), 'type' => 'error'];
        }

        return ['success' => false, 'message' => t('admin_posts_flash_unknown_action', '未知操作。'), 'type' => 'error'];
    }
}

if (!function_exists('admin_action_form')) {
    /**
     * 输出内联 POST 动作表单（替代原 GET 写操作链接）。
     *
     * 结构：<form method="post"> + 隐藏 csrf_token + 隐藏 action +
     * 其余 params 隐藏域 + <button type="submit">。
     * 依赖全局样式 .inline-action-form{display:inline;margin:0}（style.css）。
     *
     * @param string $url    表单 action 地址（应为只含路径的站内地址）
     * @param string $action 隐藏域 action 值
     * @param array  $params 其余隐藏域 name => value
     * @param string $label  按钮文字（可为空，配合 icon 纯图标按钮）
     * @param array  $opts   class（默认 btn btn-sm btn-secondary）、confirm（映射 data-confirm，
     *                       由 main.js 的 [data-confirm] 委托拦截）、icon（按钮内 SVG 等原始 HTML，
     *                       调用方保证内容可信）、title（按钮 title，缺省取 label）
     * @return string 完整 form HTML
     */
    function admin_action_form(string $url, string $action, array $params = [], string $label = '', array $opts = []): string {
        $class   = isset($opts['class']) && $opts['class'] !== '' ? $opts['class'] : 'btn btn-sm btn-secondary';
        $confirm = isset($opts['confirm']) ? (string)$opts['confirm'] : '';
        $icon    = isset($opts['icon']) ? (string)$opts['icon'] : '';
        $title   = isset($opts['title']) && $opts['title'] !== '' ? (string)$opts['title'] : $label;

        $html = '<form method="post" action="' . e($url) . '" class="inline-action-form"';
        if ($confirm !== '') {
            $html .= ' data-confirm="' . e($confirm) . '"';
        }
        $html .= '>';
        $html .= '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
        $html .= '<input type="hidden" name="action" value="' . e($action) . '">';
        foreach ($params as $name => $value) {
            $html .= '<input type="hidden" name="' . e((string)$name) . '" value="' . e((string)$value) . '">';
        }
        $html .= '<button type="submit" class="' . e($class) . '"';
        if ($title !== '') {
            $html .= ' title="' . e($title) . '"';
        }
        $html .= '>' . $icon . ($label !== '' ? e($label) : '') . '</button>';
        $html .= '</form>';
        return $html;
    }
}

if (!function_exists('admin_user_has_role')) {
    /**
     * 判断目标用户是否拥有指定内置角色（按 roles.name 匹配，如 community_admin）。
     * 查询异常时按「拥有」处理（从严，避免误放行处置动作）。
     */
    function admin_user_has_role(int $userId, string $roleName): bool {
        if ($userId <= 0) {
            return false;
        }
        try {
            $db = get_db();
            $stmt = $db->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :uid AND r.name = :name");
            $stmt->execute([':uid' => $userId, ':name' => $roleName]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('admin_dispose_target_blocked')) {
    /**
     * 用户处置动作（封禁/禁言/解封/解禁言/解锁等）层级保护检查。
     *
     * 规则：
     *  - 目标 users.role = 'admin'（超级管理员）：任何操作者都不可处置；
     *  - 操作者非超级管理员时：目标拥有 community_admin 角色也不可处置，
     *    防止社区管理员之间互相处置或向上越权。
     *
     * @return bool true = 拦截（应阻止本次处置动作）
     */
    function admin_dispose_target_blocked(int $targetUserId): bool {
        if ($targetUserId <= 0) {
            return true;
        }
        try {
            $db = get_db();
            $stmt = $db->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $targetUserId]);
            $role = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return true;
        }
        if ($role === false) {
            // 目标用户不存在
            return true;
        }
        if ($role === 'admin') {
            return true;
        }
        if (!is_super_admin() && admin_user_has_role($targetUserId, 'community_admin')) {
            return true;
        }
        return false;
    }
}

if (!function_exists('admin_backup_download_token')) {
    /**
     * 备份下载一次性派生令牌：hash_hmac('sha256', $filename, csrf_token())。
     *
     * 不可由 session_id 直接推导，且绑定具体文件名；令牌随当前 session 的
     * CSRF token 派生，刷新页面即可重新生成，无持久状态。
     */
    function admin_backup_download_token(string $filename): string {
        return hash_hmac('sha256', $filename, csrf_token());
    }
}

if (!function_exists('admin_backup_download_token_valid')) {
    /**
     * 校验备份下载令牌（常量时间比较）。
     */
    function admin_backup_download_token_valid(string $filename, string $token): bool {
        return $token !== '' && hash_equals(admin_backup_download_token($filename), $token);
    }
}

if (!function_exists('admin_role_filter_sql')) {
    /**
     * 用户管理「角色筛选」分级条件：super_admin / community_admin / user。
     *
     * 返回可直接拼接进 WHERE 的 SQL 片段（不含前缀 AND），无效值返回 ''（全部）。
     * 与 roles 表（user_roles 关联）配合，支持按两级管理员体系分级筛选：
     *  - super_admin：users.role = 'admin'（超管，天然拥有全部权限）
     *  - community_admin：拥有内置角色 community_admin（users.role 仍为 'user'）
     *  - user：普通用户（排除上述两类）
     */
    function admin_role_filter_sql(string $filterRole): string {
        if ($filterRole === 'super_admin') {
            return "u.role = 'admin'";
        }
        if ($filterRole === 'community_admin') {
            return "EXISTS (SELECT 1 FROM user_roles ur JOIN roles rr ON rr.id = ur.role_id WHERE ur.user_id = u.id AND rr.name = 'community_admin')";
        }
        if ($filterRole === 'user') {
            return "u.role = 'user' AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles rr ON rr.id = ur.role_id WHERE ur.user_id = u.id AND rr.name = 'community_admin')";
        }
        return '';
    }
}

if (!function_exists('ensure_builtin_role')) {
    /**
     * 确保内置角色存在（被误删后自动重建），返回角色 ID。
     *
     * 播种（db.php init_db）为 INSERT OR IGNORE 幂等，但不会重建已被删除的
     * 内置角色；若 community_admin 等内置角色缺失，授权写入会静默失败
     * （表现为保存角色后仍为普通用户）。授权写入前需经此函数兑底。
     */
    function ensure_builtin_role(string $name, string $displayName, string $permissions): int {
        $db = get_db();
        $quoted = $db->quote($name);
        $rid = (int)$db->query("SELECT id FROM roles WHERE name = $quoted LIMIT 1")->fetchColumn();
        if ($rid > 0) {
            return $rid;
        }
        $stmt = ddl_prepare($db, "INSERT OR IGNORE INTO roles (name, display_name, permissions) VALUES (:name, :display_name, :permissions)");
        $stmt->execute([':name' => $name, ':display_name' => $displayName, ':permissions' => $permissions]);
        return (int)$db->query("SELECT id FROM roles WHERE name = $quoted LIMIT 1")->fetchColumn();
    }
}

if (!function_exists('admin_can_view_email')) {
    /**
     * 判断当前管理员能否查看指定用户的邮箱（隐私控制）。
     *
     * 超级管理员始终可见；社区管理员仅在其针对该用户的披露申请审核通过
     * （status='approved' 且尚未查看过，viewed_at 为空）后可查看一次；
     * 查看自己的邮箱始终允许。
     */
    function admin_can_view_email(int $targetUserId): bool {
        if ($targetUserId <= 0) return false;
        $currentId = (int)($_SESSION['user_id'] ?? 0);
        if ($currentId <= 0) return false;
        if ($currentId === $targetUserId) return true;
        if (is_super_admin()) return true;
        try {
            $db = get_db();
            $stmt = $db->prepare("SELECT COUNT(*) FROM email_disclosure_requests WHERE applicant_id = :aid AND target_user_id = :tid AND status = 'approved' AND viewed_at IS NULL");
            $stmt->execute([':aid' => $currentId, ':tid' => $targetUserId]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            // 表未就绪（首次部署迁移前）时按不可见处理，不抛异常
            return false;
        }
    }
}

if (!function_exists('admin_consume_email_disclosure')) {
    /**
     * 一次性邮箱披露：社区管理员查看目标用户邮箱后标记已查看（viewed_at），
     * 此后该申请失效，如需再看需重新申请。超管与本人查看不消耗。
     */
    function admin_consume_email_disclosure(int $targetUserId): void {
        $currentId = (int)($_SESSION['user_id'] ?? 0);
        if ($currentId <= 0 || $targetUserId <= 0 || $targetUserId === $currentId || is_super_admin()) {
            return;
        }
        try {
            $db = get_db();
            $stmt = $db->prepare("UPDATE email_disclosure_requests SET viewed_at = CURRENT_TIMESTAMP WHERE applicant_id = :aid AND target_user_id = :tid AND status = 'approved' AND viewed_at IS NULL");
            $stmt->execute([':aid' => $currentId, ':tid' => $targetUserId]);
        } catch (\Throwable $e) {
            // 表未就绪时静默忽略，不影响本次展示
        }
    }
}

if (!function_exists('get_pending_email_disclosure_count')) {
    /**
     * 待审核邮箱披露申请数（菜单 badge / 超管审核页）
     */
    function get_pending_email_disclosure_count(): int {
        try {
            $db = get_db();
            return (int)$db->query("SELECT COUNT(*) FROM email_disclosure_requests WHERE status = 'pending'")->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('get_open_ticket_count')) {
    /**
     * 待处理（open）工单数（菜单 badge）
     */
    function get_open_ticket_count(): int {
        try {
            $db = get_db();
            return (int)$db->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
