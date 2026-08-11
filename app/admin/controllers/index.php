<?php
/**
 * 云界论坛 - 管理后台概览
 *
 * 数据聚合中心：集中展示用户、内容、举报、邮件、流量等关键统计。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：概览页对所有后台管理员（含社区管理员）开放，
// 显式声明 admin_access 权限要求（admin-init 总闸已含同等校验，此处为矩阵完整性）。
require_permission('admin_access');

$db = get_db();

// === 聚合所有关键统计 ===
$stats = [
    // 用户
    'users'              => 0,
    'users_active'       => 0,
    'users_banned'       => 0,
    'users_today'        => 0,
    // 内容
    'posts'              => 0,
    'replies'            => 0,
    'checkins'           => 0,
    'posts_today'        => 0,
    // 举报与审核
    'reports_pending'    => 0,
    'appeals_pending'    => 0,
    'resets_pending'     => 0,
    // 邮件
    'mail_total'         => 0,
    'mail_success'       => 0,
    'mail_failed'        => 0,
    'mail_today'         => 0,
    'mail_bounced'       => 0,
    // 流量（今日）
    'traffic_today_pv'   => 0,
    'traffic_today_uv'   => 0,
];

try {
    // 用户统计（合并为 1 次查询）
    $todayStart = gmdate('Y-m-d 00:00:00');
    $stmt = $db->prepare("SELECT COUNT(*) AS total_users,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_users,
        SUM(CASE WHEN status='banned' THEN 1 ELSE 0 END) AS banned_users,
        SUM(CASE WHEN created_at >= :today THEN 1 ELSE 0 END) AS users_today
        FROM users");
    $stmt->execute([':today' => $todayStart]);
    $row = $stmt->fetch();
    $stats['users']        = (int)$row['total_users'];
    $stats['users_active'] = (int)$row['active_users'];
    $stats['users_banned'] = (int)$row['banned_users'];
    $stats['users_today']  = (int)$row['users_today'];

    // 内容统计（合并为 1 次查询）
    $stmt = $db->prepare("SELECT
        (SELECT COUNT(*) FROM posts) AS total_posts,
        (SELECT COUNT(*) FROM replies) AS total_replies,
        (SELECT COUNT(*) FROM checkins) AS total_checkins,
        (SELECT COUNT(*) FROM posts WHERE created_at >= :today) AS posts_today");
    $stmt->execute([':today' => $todayStart]);
    $row = $stmt->fetch();
    $stats['posts']       = (int)$row['total_posts'];
    $stats['replies']     = (int)$row['total_replies'];
    $stats['checkins']    = (int)$row['total_checkins'];
    $stats['posts_today'] = (int)$row['posts_today'];

    // 举报与审核（合并为 1 次查询）
    $row = $db->query("SELECT
        (SELECT COUNT(*) FROM reports WHERE status='pending') AS pending_reports,
        (SELECT COUNT(*) FROM ban_appeals WHERE status='pending') AS pending_appeals,
        (SELECT COUNT(*) FROM password_reset_requests WHERE status='pending') AS pending_resets")->fetch();
    $stats['reports_pending'] = (int)$row['pending_reports'];
    $stats['appeals_pending'] = (int)$row['pending_appeals'];
    $stats['resets_pending']  = (int)$row['pending_resets'];

    // 邮件统计（合并为 1 次查询）
    $stmt = $db->prepare("SELECT COUNT(*) AS mail_total,
        SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS mail_success,
        SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS mail_failed,
        SUM(CASE WHEN bounce_status='bounced' THEN 1 ELSE 0 END) AS mail_bounced,
        SUM(CASE WHEN created_at >= :today THEN 1 ELSE 0 END) AS mail_today
        FROM mail_logs");
    $stmt->execute([':today' => $todayStart]);
    $row = $stmt->fetch();
    $stats['mail_total']   = (int)$row['mail_total'];
    $stats['mail_success'] = (int)$row['mail_success'];
    $stats['mail_failed']  = (int)$row['mail_failed'];
    $stats['mail_bounced'] = (int)$row['mail_bounced'];
    $stats['mail_today']   = (int)$row['mail_today'];

    // 流量统计（今日）
    $today = gmdate('Y-m-d');
    $stmt = $db->prepare("SELECT COALESCE(SUM(page_views),0), COALESCE(SUM(unique_visitors),0) FROM traffic_stats WHERE stat_date = :today");
    $stmt->execute([':today' => $today]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    $stats['traffic_today_pv'] = (int)$row[0];
    $stats['traffic_today_uv'] = (int)$row[1];
} catch (Exception $e) {
    // 忽略统计异常
}

// 最近帖子与用户
$recentPosts = [];
$recentUsers = [];
try {
    $recentPosts = $db->query("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id = u.id ORDER BY p.created_at DESC LIMIT 5")->fetchAll();
    $recentUsers = $db->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5")->fetchAll();
} catch (Exception $e) {
    // 忽略异常
}

// 待办事项（需管理员关注）
$todoItems = [];
if ($stats['reports_pending'] > 0)  $todoItems[] = ['label' => t('admin_dash_todo_reports', '待处理举报'), 'count' => $stats['reports_pending'], 'url' => site_url('admin/reports'), 'color' => '#ef4444'];
if ($stats['appeals_pending'] > 0)  $todoItems[] = ['label' => t('admin_dash_todo_appeals', '待审核申诉'), 'count' => $stats['appeals_pending'], 'url' => site_url('admin/ban_appeals'), 'color' => '#f59e0b'];
if ($stats['resets_pending'] > 0)   $todoItems[] = ['label' => t('admin_dash_todo_resets', '待审核密码重置'), 'count' => $stats['resets_pending'], 'url' => site_url('admin/password_reset_requests'), 'color' => '#3b82f6'];

$pageTitle = t('admin_dash_title', '管理概览');
$activeMenu = 'dashboard';

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_dash_title', '管理概览')); ?></h1>
    <p class="page-desc"><?php echo e(t('admin_dash_subtitle', '站点关键数据一览')); ?></p>
</div>

<?php if (!empty($todoItems)): ?>
<div class="card" style="background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);border-color:#fbbf24;margin-bottom:1rem;">
    <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
        <strong style="color:#92400e;"><?php echo e(t('admin_dash_todo_heading', '待办事项：')); ?></strong>
        <?php foreach ($todoItems as $item): ?>
        <a href="<?php echo $item['url']; ?>" class="dashboard-todo-item" style="background:<?php echo $item['color']; ?>;">
            <span class="dashboard-todo-count"><?php echo $item['count']; ?></span>
            <span class="dashboard-todo-label"><?php echo e($item['label']); ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- 用户统计 -->
<h3 class="stats-section-title"><?php echo e(t('admin_dash_sec_users', '用户')); ?></h3>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-value"><?php echo $stats['users']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_users', '注册用户')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" style="color:#10b981;"><?php echo $stats['users_active']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_users_active', '活跃用户')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" style="color:#ef4444;"><?php echo $stats['users_banned']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_users_banned', '封禁用户')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" style="color:#3b82f6;"><?php echo $stats['users_today']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_users_today', '今日新增')); ?></div>
    </div>
</div>

<!-- 内容统计 -->
<h3 class="stats-section-title"><?php echo e(t('admin_dash_sec_content', '内容')); ?></h3>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-value"><?php echo $stats['posts']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_posts', '帖子总数')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value"><?php echo $stats['replies']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_replies', '回复总数')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value"><?php echo $stats['checkins']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_checkins', '签到记录')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" style="color:#3b82f6;"><?php echo $stats['posts_today']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_posts_today', '今日新帖')); ?></div>
    </div>
</div>

<!-- 邮件与流量统计 -->
<h3 class="stats-section-title"><?php echo e(t('admin_dash_sec_mail_traffic', '邮件与流量')); ?></h3>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-value"><?php echo $stats['mail_total']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_mail_total', '邮件总数')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" style="color:#10b981;"><?php echo $stats['mail_success']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_mail_success', '发送成功')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" style="color:#ef4444;"><?php echo $stats['mail_failed']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_mail_failed', '发送失败')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" style="color:#f59e0b;"><?php echo $stats['mail_bounced']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_mail_bounced', '退信数量')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" style="color:#3b82f6;"><?php echo $stats['mail_today']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_mail_today', '今日邮件')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" style="color:#8b5cf6;"><?php echo $stats['traffic_today_pv']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_traffic_pv', '今日访问 PV')); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value" style="color:#8b5cf6;"><?php echo $stats['traffic_today_uv']; ?></div>
        <div class="stat-card-label"><?php echo e(t('admin_dash_stat_traffic_uv', '今日访客 UV')); ?></div>
    </div>
</div>

<!-- 最近帖子与最近用户 -->
<div class="dashboard-grid">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?php echo e(t('admin_dash_recent_posts', '最近帖子')); ?></h2>
            <a href="<?php echo site_url('admin/posts'); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('admin_dash_view_all', '查看全部')); ?></a>
        </div>
        <?php if (empty($recentPosts)): ?>
            <p class="text-muted"><?php echo e(t('admin_dash_empty_posts', '暂无帖子。')); ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr><th><?php echo e(t('admin_dash_th_title', '标题')); ?></th><th><?php echo e(t('admin_dash_th_author', '作者')); ?></th><th><?php echo e(t('admin_dash_th_time', '时间')); ?></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPosts as $post): ?>
                            <tr>
                                <td><a href="<?php echo site_url('post', ['id' => (int)$post['id']]); ?>" target="_blank"><?php echo e(mb_substr($post['title'], 0, 40, 'UTF-8')); ?></a></td>
                                <td><?php echo e($post['username']); ?></td>
                                <td><?php echo time_ago($post['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?php echo e(t('admin_dash_recent_users', '最近注册用户')); ?></h2>
            <a href="<?php echo site_url('admin/users'); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('admin_dash_view_all', '查看全部')); ?></a>
        </div>
        <?php if (empty($recentUsers)): ?>
            <p class="text-muted"><?php echo e(t('admin_dash_empty_users', '暂无用户。')); ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr><th><?php echo e(t('admin_dash_th_username', '用户名')); ?></th><th><?php echo e(t('admin_dash_th_email', '邮箱')); ?></th><th><?php echo e(t('admin_dash_th_regtime', '注册时间')); ?></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentUsers as $u): ?>
                            <tr>
                                <td><?php echo e($u['username']); ?></td>
                                <td><?php echo e($u['email']); ?></td>
                                <td><?php echo e(date('Y-m-d H:i', db_time($u['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>