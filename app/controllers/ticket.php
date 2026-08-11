<?php
/**
 * 云界论坛 - 用户意见反馈（工单）
 * 普通用户提交站点问题或改进建议，管理员在后台跟进处理；
 * 管理员后台另有内部工单入口（app/admin/controllers/tickets.php）。
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

require_login();

$db = get_db();
$currentUserId = (int)$_SESSION['user_id'];
$errors = [];

// —— 提交反馈 ——
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!validate_csrf()) {
        set_flash(t('ticket_csrf_failed', '安全验证失败，请刷新页面重试。'), 'error');
        redirect('/ticket');
    }
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    if ($title === '') {
        $errors[] = t('tickets_err_title_empty', '请填写工单标题。');
    } elseif (mb_strlen($title, 'UTF-8') > 200) {
        $errors[] = t('tickets_err_title_long', '工单标题不能超过 200 字。');
    }
    if ($content === '' || mb_strlen($content, 'UTF-8') < 5) {
        $errors[] = t('tickets_err_content_short', '请描述问题详情（至少 5 个字）。');
    } elseif (mb_strlen($content, 'UTF-8') > 2000) {
        $errors[] = t('tickets_err_content_long', '问题描述不能超过 2000 字。');
    }
    if (empty($errors)) {
        $stmt = $db->prepare("INSERT INTO tickets (title, content, reporter_id, source, status) VALUES (:t, :c, :uid, 'user', 'open')");
        $stmt->execute([':t' => $title, ':c' => $content, ':uid' => $currentUserId]);
        // 通知所有超管有新用户反馈
        try {
            $reporterName = '';
            $qn = $db->prepare("SELECT username FROM users WHERE id = :id");
            $qn->execute([':id' => $currentUserId]);
            $rn = $qn->fetch();
            if ($rn) {
                $reporterName = (string)$rn['username'];
            }
            $admins = $db->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
            foreach ($admins as $adm) {
                send_notification((int)$adm['id'], 'system',
                    t('tickets_notify_new_title', '收到新反馈'),
                    t('tickets_notify_new_content', '用户「{name}」提交了反馈「{title}」，请及时处理。', ['name' => $reporterName, 'title' => $title]),
                    '/admin/tickets');
            }
        } catch (\Throwable $e) {}
        set_flash(t('tickets_flash_created', '工单已提交，等待处理。'), 'success');
        redirect('/ticket');
    }
}

// —— 回复自己的工单 ——
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply') {
    if (!validate_csrf()) {
        set_flash(t('ticket_csrf_failed', '安全验证失败，请刷新页面重试。'), 'error');
        redirect('/ticket');
    }
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    if ($ticketId <= 0) {
        $errors[] = t('tickets_flash_invalid_params', '参数无效。');
    } elseif ($content === '') {
        $errors[] = t('tickets_err_reply_empty', '回复内容不能为空。');
    } elseif (mb_strlen($content, 'UTF-8') > 2000) {
        $errors[] = t('tickets_err_content_long', '回复内容不能超过 2000 字。');
    } else {
        $chk = $db->prepare("SELECT id FROM tickets WHERE id = :id AND reporter_id = :uid");
        $chk->execute([':id' => $ticketId, ':uid' => $currentUserId]);
        if (!$chk->fetch()) {
            $errors[] = t('tickets_flash_not_found', '工单不存在。');
        } else {
            $stmt = $db->prepare("INSERT INTO ticket_replies (ticket_id, user_id, content) VALUES (:tid, :uid, :c)");
            $stmt->execute([':tid' => $ticketId, ':uid' => $currentUserId, ':c' => $content]);
            $db->prepare("UPDATE tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute([':id' => $ticketId]);
            set_flash(t('tickets_flash_replied', '回复已提交。'), 'success');
            redirect('/ticket?id=' . $ticketId);
        }
    }
}

$pageTitle = t('ticket_page_title', '意见反馈');
include APP_ROOT . 'app/includes/header.php';

// 数据加载：详情（?id=N，仅本人）或列表
$viewId = (int)($_GET['id'] ?? 0);
$ticket = null;
$replies = [];
if ($viewId > 0) {
    $stmt = $db->prepare("SELECT * FROM tickets WHERE id = :id AND reporter_id = :uid");
    $stmt->execute([':id' => $viewId, ':uid' => $currentUserId]);
    $ticket = $stmt->fetch();
    if ($ticket) {
        $stmt = $db->prepare(
            "SELECT r.*, u.username AS user_name
             FROM ticket_replies r JOIN users u ON u.id = r.user_id
             WHERE r.ticket_id = :tid ORDER BY r.created_at ASC"
        );
        $stmt->execute([':tid' => $viewId]);
        $replies = $stmt->fetchAll();
    }
} else {
    $stmt = $db->prepare(
        "SELECT t.*,
                (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id = t.id) AS reply_count
         FROM tickets t WHERE t.reporter_id = :uid
         ORDER BY t.updated_at DESC LIMIT 100"
    );
    $stmt->execute([':uid' => $currentUserId]);
    $tickets = $stmt->fetchAll();
}

// 状态徽标
$ticketStatusBadge = function (string $status) {
    $map = [
        'open'        => ['badge-warning', t('tickets_status_open', '待处理')],
        'in_progress' => ['badge-info', t('tickets_status_in_progress', '处理中')],
        'resolved'    => ['badge-success', t('tickets_status_resolved', '已解决')],
        'closed'      => ['badge-secondary', t('tickets_status_closed', '已关闭')],
    ];
    list($cls, $label) = $map[$status] ?? $map['open'];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
};
?>

<nav class="breadcrumb" aria-label="<?php echo e(t('ticket_breadcrumb_aria', '面包屑导航')); ?>">
    <a href="/"><?php echo e(t('ticket_breadcrumb_home', '首页')); ?></a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?php echo e(t('ticket_page_title', '意见反馈')); ?></span>
</nav>

<div class="page-head">
    <h1><?php echo e(t('ticket_page_title', '意见反馈')); ?></h1>
    <p class="text-muted"><?php echo e(t('ticket_page_desc', '提交站点问题或改进建议，管理员将在后台跟进处理。')); ?></p>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?>
            <div><?php echo e($err); ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($ticket): ?>
    <!-- ==================== 反馈详情 ==================== -->
    <div class="card">
        <div class="card-header">
            <h3>#<?php echo (int)$ticket['id']; ?> <?php echo e($ticket['title']); ?></h3>
            <?php echo $ticketStatusBadge($ticket['status']); ?>
        </div>
        <div class="notify-body">
            <p class="text-muted text-xs">
                <?php echo e(t('tickets_detail_meta_created', '提交于 {time}', ['time' => db_datetime($ticket['created_at'])])); ?>
                <?php if ($ticket['created_at'] !== $ticket['updated_at']): ?>
                    · <?php echo e(t('tickets_detail_meta_updated', '更新于 {time}', ['time' => db_datetime($ticket['updated_at'])])); ?>
                <?php endif; ?>
            </p>
            <div class="ticket-content" style="white-space:pre-wrap;line-height:1.7;"><?php echo e($ticket['content']); ?></div>
        </div>
    </div>

    <!-- 回复列表 -->
    <div class="card">
        <div class="card-header">
            <h3><?php echo e(t('tickets_replies_title', '回复记录')); ?> (<?php echo count($replies); ?>)</h3>
        </div>
        <div class="notify-body">
            <?php if (empty($replies)): ?>
                <p class="text-muted"><?php echo e(t('tickets_empty_replies', '暂无回复。')); ?></p>
            <?php else: ?>
                <?php foreach ($replies as $rp): ?>
                    <div class="ticket-reply">
                        <div class="text-muted text-xs">
                            <strong><?php echo e($rp['user_name']); ?></strong> ·
                            <?php echo e(db_datetime($rp['created_at'])); ?>
                        </div>
                        <div style="white-space:pre-wrap;line-height:1.7;"><?php echo e($rp['content']); ?></div>
                    </div>
                    <?php if ($rp !== end($replies)): ?><hr class="ticket-reply-divider"><?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 回复表单 -->
    <div class="card">
        <div class="card-header">
            <h3><?php echo e(t('tickets_reply_title', '回复工单')); ?></h3>
        </div>
        <div class="notify-body">
            <form method="post" action="<?php echo site_url('ticket'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="ticket_id" value="<?php echo (int)$ticket['id']; ?>">
                <textarea name="content" class="form-control" rows="4" maxlength="2000" placeholder="<?php echo e(t('tickets_reply_placeholder', '输入回复内容…')); ?>" required></textarea>
                <div class="notify-actions" style="margin-top:1rem;">
                    <a href="<?php echo site_url('ticket'); ?>" class="btn btn-secondary"><?php echo e(t('tickets_back_to_list', '返回列表')); ?></a>
                    <button type="submit" class="btn btn-primary"><?php echo e(t('tickets_btn_reply', '回复')); ?></button>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <!-- ==================== 提交反馈 ==================== -->
    <div class="card">
        <div class="card-header">
            <h3><?php echo e(t('ticket_new_title', '提交反馈')); ?></h3>
        </div>
        <div class="notify-body">
            <form method="post" action="<?php echo site_url('ticket'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label><?php echo e(t('tickets_title_label', '标题')); ?></label>
                    <input type="text" name="title" class="form-control" maxlength="200" placeholder="<?php echo e(t('tickets_title_placeholder', '简要描述问题（如：后台某功能报错）')); ?>" required>
                </div>
                <div class="form-group">
                    <label><?php echo e(t('tickets_content_label', '问题描述')); ?></label>
                    <textarea name="content" class="form-control" rows="5" maxlength="2000" placeholder="<?php echo e(t('tickets_content_placeholder', '请详细描述问题现象、操作步骤与影响范围…')); ?>" required></textarea>
                </div>
                <div class="notify-actions" style="margin-top:1rem;">
                    <button type="submit" class="btn btn-primary"><?php echo e(t('ticket_btn_submit', '提交反馈')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- 我的反馈列表 -->
    <div class="card">
        <div class="card-header">
            <h3><?php echo e(t('ticket_list_title', '我的反馈')); ?></h3>
        </div>
        <?php if (empty($tickets)): ?>
            <p class="text-muted"><?php echo e(t('ticket_empty_list', '暂无反馈记录。')); ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?php echo e(t('tickets_th_id', 'ID')); ?></th>
                            <th><?php echo e(t('tickets_th_title', '标题')); ?></th>
                            <th><?php echo e(t('tickets_th_status', '状态')); ?></th>
                            <th><?php echo e(t('tickets_th_replies', '回复')); ?></th>
                            <th><?php echo e(t('tickets_th_updated_at', '更新时间')); ?></th>
                            <th><?php echo e(t('tickets_th_actions', '操作')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $tk): ?>
                            <tr>
                                <td><?php echo (int)$tk['id']; ?></td>
                                <td><a href="<?php echo site_url('ticket', ['id' => (int)$tk['id']]); ?>" class="detail-link"><?php echo e(mb_substr($tk['title'], 0, 50, 'UTF-8')); ?><?php if (mb_strlen($tk['title']) > 50) echo '…'; ?></a></td>
                                <td><?php echo $ticketStatusBadge($tk['status']); ?></td>
                                <td><?php echo (int)$tk['reply_count']; ?></td>
                                <td><div><?php echo e(db_datetime($tk['updated_at'])); ?></div></td>
                                <td>
                                    <a href="<?php echo site_url('ticket', ['id' => (int)$tk['id']]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('tickets_btn_view', '查看')); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
