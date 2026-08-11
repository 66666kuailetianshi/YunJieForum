<?php
/**
 * 云界论坛 - 管理后台工单系统
 *
 * 社区管理员与超级管理员均可查看工单列表、创建工单、回复跟进、处理工单
 * （状态流转：待处理 → 处理中 → 已解决 / 已关闭）。
 * 主要用于反馈与跟进站点问题（如 bug）。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$db = get_db();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$errors = [];

/**
 * 工单详情视图链接：提交人为管理员 → 后台详情；普通用户 → 前台详情
 */
function ticket_view_link(PDO $db, int $ticketId, int $reporterId): string {
    $q = $db->prepare(
        "SELECT u.role,
                EXISTS (SELECT 1 FROM user_roles ur JOIN roles rr ON rr.id = ur.role_id
                    WHERE ur.user_id = u.id AND rr.name = 'community_admin') AS is_community_admin
         FROM users u WHERE u.id = :id"
    );
    $q->execute([':id' => $reporterId]);
    $u = $q->fetch();
    if ($u && ($u['role'] === 'admin' || !empty($u['is_community_admin']))) {
        return '/admin/tickets?id=' . $ticketId;
    }
    return '/ticket?id=' . $ticketId;
}

// —— 创建工单 ——
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
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
        $stmt = $db->prepare("INSERT INTO tickets (title, content, reporter_id, source, status) VALUES (:t, :c, :uid, 'admin', 'open')");
        $stmt->execute([':t' => $title, ':c' => $content, ':uid' => $currentUserId]);
        set_flash(t('tickets_flash_created', '工单已提交，等待处理。'), 'success');
        redirect('/admin/tickets');
    }
}

// —— 回复工单 ——
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply') {
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    if ($ticketId <= 0) {
        $errors[] = t('tickets_flash_invalid_params', '参数无效。');
    } elseif ($content === '') {
        $errors[] = t('tickets_err_reply_empty', '回复内容不能为空。');
    } elseif (mb_strlen($content, 'UTF-8') > 2000) {
        $errors[] = t('tickets_err_content_long', '回复内容不能超过 2000 字。');
    } else {
        $chk = $db->prepare("SELECT reporter_id, title FROM tickets WHERE id = :id");
        $chk->execute([':id' => $ticketId]);
        $ticket = $chk->fetch();
        if (!$ticket) {
            $errors[] = t('tickets_flash_not_found', '工单不存在。');
        } else {
            $stmt = $db->prepare("INSERT INTO ticket_replies (ticket_id, user_id, content) VALUES (:tid, :uid, :c)");
            $stmt->execute([':tid' => $ticketId, ':uid' => $currentUserId, ':c' => $content]);
            $db->prepare("UPDATE tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute([':id' => $ticketId]);
            // 回复人非提交人时，站内通知提交人
            if ((int)$ticket['reporter_id'] !== $currentUserId) {
                send_notification((int)$ticket['reporter_id'], 'system',
                    t('tickets_notify_reply_title', '工单有新回复'),
                    t('tickets_notify_reply_content', '您的工单「{title}」有新回复，请查看。', ['title' => $ticket['title']]),
                    ticket_view_link($db, $ticketId, (int)$ticket['reporter_id']));
            }
            set_flash(t('tickets_flash_replied', '回复已提交。'), 'success');
            redirect('/admin/tickets?id=' . $ticketId);
        }
    }
}

// —— 状态流转（所有管理员可操作）——
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $newStatus = (string)($_POST['status'] ?? '');
    $allowed = ['open', 'in_progress', 'resolved', 'closed'];
    if ($ticketId <= 0 || !in_array($newStatus, $allowed, true)) {
        set_flash(t('tickets_flash_status_invalid', '无效的状态或工单 ID。'), 'error');
        redirect('/admin/tickets');
    }
    $chk = $db->prepare("SELECT reporter_id, title FROM tickets WHERE id = :id");
    $chk->execute([':id' => $ticketId]);
    $ticket = $chk->fetch();
    if (!$ticket) {
        set_flash(t('tickets_flash_not_found', '工单不存在。'), 'error');
        redirect('/admin/tickets');
    }
    $db->prepare("UPDATE tickets SET status = :s, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
        ->execute([':s' => $newStatus, ':id' => $ticketId]);
    $statusTxt = [
        'open'        => t('tickets_status_open', '待处理'),
        'in_progress' => t('tickets_status_in_progress', '处理中'),
        'resolved'    => t('tickets_status_resolved', '已解决'),
        'closed'      => t('tickets_status_closed', '已关闭'),
    ][$newStatus];
    // 操作人非提交人时，站内通知提交人
    if ((int)$ticket['reporter_id'] !== $currentUserId) {
        send_notification((int)$ticket['reporter_id'], 'system',
            t('tickets_notify_status_title', '工单状态更新'),
            t('tickets_notify_status_content', '您的工单「{title}」状态已更新为：{status}。', ['title' => $ticket['title'], 'status' => $statusTxt]),
            ticket_view_link($db, $ticketId, (int)$ticket['reporter_id']));
    }
    set_flash(t('tickets_flash_status_updated', '工单状态已更新。'), 'success');
    redirect('/admin/tickets?id=' . $ticketId);
}

/**
 * 状态流转表单（内联小按钮）
 */
function ticket_status_form(int $ticketId, string $status, string $label): string {
    return '<form method="post" action="' . e(site_url('admin/tickets')) . '" class="inline-action-form">'
        . '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="action" value="update_status">'
        . '<input type="hidden" name="ticket_id" value="' . $ticketId . '">'
        . '<input type="hidden" name="status" value="' . e($status) . '">'
        . '<button type="submit" class="btn btn-sm btn-secondary">' . e($label) . '</button>'
        . '</form>';
}

// 数据加载：详情（?id=N）或列表
$viewId = (int)($_GET['id'] ?? 0);
$ticket = null;
$replies = [];
if ($viewId > 0) {
    $stmt = $db->prepare(
        "SELECT t.*, u.username AS reporter_name
         FROM tickets t JOIN users u ON u.id = t.reporter_id
         WHERE t.id = :id"
    );
    $stmt->execute([':id' => $viewId]);
    $ticket = $stmt->fetch();
    if ($ticket) {
        $stmt = $db->prepare(
            "SELECT r.*, u.username AS user_name
             FROM ticket_replies r JOIN users u ON u.id = r.user_id
             WHERE r.ticket_id = :tid ORDER BY r.created_at ASC"
        );
        $stmt->execute([':tid' => $viewId]);
        $replies = $stmt->fetchAll();
    } else {
        set_flash(t('tickets_flash_not_found', '工单不存在。'), 'error');
        redirect('/admin/tickets');
    }
} else {
    $srcFilter = (string)($_GET['src'] ?? '');
    $where = '';
    $params = [];
    if ($srcFilter === 'user' || $srcFilter === 'admin') {
        $where = ' WHERE t.source = :src';
        $params = [':src' => $srcFilter];
    }
    $stmt = $db->prepare(
        "SELECT t.*, u.username AS reporter_name,
                (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id = t.id) AS reply_count
         FROM tickets t JOIN users u ON u.id = t.reporter_id" . $where . "
         ORDER BY t.updated_at DESC LIMIT 200"
    );
    $stmt->execute($params);
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

// 来源徽标（user=前台用户反馈 / admin=后台管理员工单）
$ticketSourceBadge = function (string $source) {
    if ($source === 'user') {
        return '<span class="badge badge-info">' . e(t('tickets_source_user', '用户工单')) . '</span>';
    }
    return '<span class="badge badge-secondary">' . e(t('tickets_source_admin', '管理员工单')) . '</span>';
};

$activeMenu = 'tickets';
require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-head">
    <h1><?php echo e(t('tickets_page_title', '工单系统')); ?></h1>
    <p class="text-muted"><?php echo e(t('tickets_page_desc', '社区管理员与超级管理员均可查看和跟进工单，用于反馈站点问题（如 bug）；前台用户提交的反馈也会汇总到这里统一处理。')); ?></p>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?>
            <div><?php echo e($err); ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($ticket): ?>
    <!-- ==================== 工单详情 ==================== -->
    <div class="card">
        <div class="card-header">
            <h3>#<?php echo (int)$ticket['id']; ?> <?php echo e($ticket['title']); ?></h3>
            <?php echo $ticketStatusBadge($ticket['status']); ?>
        </div>
        <div class="notify-body">
            <p class="text-muted text-xs">
                <?php echo $ticketSourceBadge($ticket['source']); ?>
                <?php echo e(t('tickets_detail_meta_reporter', '提交人：{name}', ['name' => $ticket['reporter_name']])); ?> ·
                <?php echo e(t('tickets_detail_meta_created', '提交于 {time}', ['time' => db_datetime($ticket['created_at'])])); ?>
                <?php if ($ticket['created_at'] !== $ticket['updated_at']): ?>
                    · <?php echo e(t('tickets_detail_meta_updated', '更新于 {time}', ['time' => db_datetime($ticket['updated_at'])])); ?>
                <?php endif; ?>
            </p>
            <div class="ticket-content" style="white-space:pre-wrap;line-height:1.7;"><?php echo e($ticket['content']); ?></div>
        </div>
    </div>

    <!-- 状态管理（所有管理员可操作） -->
    <div class="card">
            <div class="card-header">
                <h3><?php echo e(t('tickets_status_manage', '状态管理')); ?></h3>
            </div>
            <div class="notify-body">
                <?php if ($ticket['status'] === 'open'): ?>
                    <?php echo ticket_status_form($ticket['id'], 'in_progress', t('tickets_btn_start', '开始处理')); ?>
                    <?php echo ticket_status_form($ticket['id'], 'closed', t('tickets_btn_close', '关闭工单')); ?>
                <?php elseif ($ticket['status'] === 'in_progress'): ?>
                    <?php echo ticket_status_form($ticket['id'], 'resolved', t('tickets_btn_resolve', '标记已解决')); ?>
                    <?php echo ticket_status_form($ticket['id'], 'closed', t('tickets_btn_close', '关闭工单')); ?>
                <?php else: ?>
                    <?php echo ticket_status_form($ticket['id'], 'open', t('tickets_btn_reopen', '重新打开')); ?>
                <?php endif; ?>
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
            <form method="post" action="<?php echo site_url('admin/tickets'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="ticket_id" value="<?php echo (int)$ticket['id']; ?>">
                <textarea name="content" class="form-control notify-textarea" rows="4" maxlength="2000" placeholder="<?php echo e(t('tickets_reply_placeholder', '输入回复内容…')); ?>" required></textarea>
                <div class="notify-actions">
                    <a href="<?php echo site_url('admin/tickets'); ?>" class="btn btn-secondary"><?php echo e(t('tickets_back_to_list', '返回列表')); ?></a>
                    <button type="submit" class="btn btn-primary"><?php echo e(t('tickets_btn_reply', '回复')); ?></button>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <!-- ==================== 新建工单 ==================== -->
    <div class="card">
        <div class="card-header">
            <h3><?php echo e(t('tickets_new_title', '新建工单')); ?></h3>
        </div>
        <div class="notify-body">
            <form method="post" action="<?php echo site_url('admin/tickets'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label><?php echo e(t('tickets_title_label', '标题')); ?></label>
                    <input type="text" name="title" class="form-control" maxlength="200" placeholder="<?php echo e(t('tickets_title_placeholder', '简要描述问题（如：后台某功能报错）')); ?>" required>
                </div>
                <div class="form-group">
                    <label><?php echo e(t('tickets_content_label', '问题描述')); ?></label>
                    <textarea name="content" class="form-control notify-textarea" rows="5" maxlength="2000" placeholder="<?php echo e(t('tickets_content_placeholder', '请详细描述问题现象、操作步骤与影响范围…')); ?>" required></textarea>
                </div>
                <div class="notify-actions">
                    <button type="submit" class="btn btn-primary"><?php echo e(t('tickets_btn_create', '提交工单')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- 工单列表 -->
    <div class="card">
        <div class="card-header">
            <h3><?php echo e(t('tickets_list_title', '全部工单')); ?></h3>
            <div class="ticket-filter-tabs" style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                <a href="<?php echo site_url('admin/tickets'); ?>" class="btn btn-sm <?php echo ($srcFilter ?? '') === '' ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo e(t('tickets_filter_all', '全部')); ?></a>
                <a href="<?php echo site_url('admin/tickets', ['src' => 'user']); ?>" class="btn btn-sm <?php echo ($srcFilter ?? '') === 'user' ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo e(t('tickets_filter_user', '用户工单')); ?></a>
                <a href="<?php echo site_url('admin/tickets', ['src' => 'admin']); ?>" class="btn btn-sm <?php echo ($srcFilter ?? '') === 'admin' ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo e(t('tickets_filter_admin', '管理员工单')); ?></a>
            </div>
        </div>
        <?php if (empty($tickets)): ?>
            <p class="text-muted"><?php echo e(t('tickets_empty_list', '暂无工单。')); ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?php echo e(t('tickets_th_id', 'ID')); ?></th>
                            <th><?php echo e(t('tickets_th_source', '来源')); ?></th>
                            <th><?php echo e(t('tickets_th_title', '标题')); ?></th>
                            <th><?php echo e(t('tickets_th_reporter', '提交人')); ?></th>
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
                                <td><?php echo $ticketSourceBadge($tk['source']); ?></td>
                                <td><a href="<?php echo site_url('admin/tickets', ['id' => (int)$tk['id']]); ?>" class="detail-link"><?php echo e(mb_substr($tk['title'], 0, 50, 'UTF-8')); ?><?php if (mb_strlen($tk['title']) > 50) echo '…'; ?></a></td>
                                <td><?php echo e($tk['reporter_name']); ?></td>
                                <td><?php echo $ticketStatusBadge($tk['status']); ?></td>
                                <td><?php echo (int)$tk['reply_count']; ?></td>
                                <td><div><?php echo e(db_datetime($tk['updated_at'])); ?></div></td>
                                <td>
                                    <a href="<?php echo site_url('admin/tickets', ['id' => (int)$tk['id']]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('tickets_btn_view', '查看')); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php

require_once dirname(__DIR__) . '/layout/footer.php';
?>
