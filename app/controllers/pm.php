<?php
/**
 * 云界论坛 - 站内信系统
 */

require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';
require_once APP_ROOT . 'app/components/sensitive_filter/helper.php';

if (file_exists(INSTALLED_FILE) === false) {
    redirect('/install');
}

require_login();

update_last_active();

$db = get_db();
$user = current_user();
$userId = (int)$user['id'];
$muteMessage = get_user_mute_message($userId);

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$validActions = ['list', 'view', 'send', 'new', 'delete', 'delete_message'];
if (!in_array($action, $validActions, true)) {
    $action = 'list';
}

// ============================================
// 处理 POST：发送消息
// ============================================
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash(t('pm_csrf_failed', '安全验证失败，请刷新页面重试。'), 'error');
        redirect('/pm');
    }

    if (is_user_banned($userId)) {
        set_flash(t('pm_banned', '您的账号已被封禁，无法发送私信。'), 'error');
        redirect('/pm');
    }

    if ($muteMessage !== null) {
        set_flash($muteMessage . t('pm_cannot_send', '无法发送私信。'), 'error');
        redirect('/pm');
    }

    $content = trim(isset($_POST['content']) ? $_POST['content'] : '');
    $toUserId = (int)(isset($_POST['to_user_id']) ? $_POST['to_user_id'] : 0);

    // 若未提供 to_user_id，尝试通过用户名解析
    if ($toUserId <= 0) {
        $toUsername = trim(isset($_POST['to_username']) ? $_POST['to_username'] : '');
        if ($toUsername !== '') {
            $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1");
            $stmt->execute([':username' => $toUsername]);
            $row = $stmt->fetch();
            if ($row) {
                $toUserId = (int)$row['id'];
            }
        }
    }

    if ($toUserId <= 0) {
        set_flash(t('pm_invalid_recipient', '请选择有效的收件人。'), 'error');
        redirect('/pm?action=new');
    }

    if ($toUserId === $userId) {
        set_flash(t('pm_no_self', '不能给自己发送私信。'), 'error');
        redirect('/pm?action=new');
    }

    if ($content === '') {
        set_flash(t('pm_content_empty', '消息内容不能为空。'), 'error');
        redirect('/pm?action=new&to=' . $toUserId);
    }

    // 敏感词过滤
    $swErrors = [];
    $processedContent = sw_process_content($content, 'pm', $userId, null, $swErrors);
    if (!empty($swErrors)) {
        set_flash(implode(' ', $swErrors), 'error');
        redirect('/pm?action=new&to=' . $toUserId);
    }

    // 校验收件人是否存在
    $stmt = $db->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $toUserId]);
    if (!$stmt->fetch()) {
        set_flash(t('pm_recipient_not_found', '收件人不存在。'), 'error');
        redirect('/pm?action=new');
    }

    // 查找或创建会话（约定 user1_id < user2_id 以避免重复）
    $userA = min($userId, $toUserId);
    $userB = max($userId, $toUserId);

    $stmt = $db->prepare("SELECT id FROM pm_conversations WHERE user1_id = :u1 AND user2_id = :u2 LIMIT 1");
    $stmt->execute([':u1' => $userA, ':u2' => $userB]);
    $conv = $stmt->fetch();

    try {
        $db->beginTransaction();

        if ($conv) {
            $conversationId = (int)$conv['id'];
        } else {
            $stmt = $db->prepare("INSERT INTO pm_conversations (user1_id, user2_id, last_message_at, created_at) VALUES (:u1, :u2, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $stmt->execute([':u1' => $userA, ':u2' => $userB]);
            $conversationId = (int)$db->lastInsertId();
        }

        // 插入消息
        $stmt = $db->prepare("INSERT INTO pm_messages (conversation_id, sender_id, content, is_read, created_at) VALUES (:cid, :sender, :content, 0, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':cid' => $conversationId,
            ':sender' => $userId,
            ':content' => $processedContent,
        ]);

        // 更新会话最后消息时间
        $stmt = $db->prepare("UPDATE pm_conversations SET last_message_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':id' => $conversationId]);

        $db->commit();
        redirect('/pm?action=view&id=' . $conversationId);
    } catch (Exception $e) {
        $db->rollBack();
        set_flash(t('pm_send_failed', '发送失败，请重试。'), 'error');
        redirect('/pm?action=new&to=' . $toUserId);
    }
}

// ============================================
// 处理 POST：删除会话 / 删除消息
// ============================================
if (($action === 'delete' || $action === 'delete_message') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash(t('pm_csrf_failed', '安全验证失败，请刷新页面重试。'), 'error');
        redirect('/pm');
    }

    if ($action === 'delete') {
        $conversationId = (int)(isset($_POST['conversation_id']) ? $_POST['conversation_id'] : 0);
        if ($conversationId <= 0) {
            set_flash(t('pm_conv_not_found', '会话不存在。'), 'error');
            redirect('/pm');
        }

        $stmt = $db->prepare("SELECT * FROM pm_conversations WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $conversationId]);
        $conv = $stmt->fetch();
        if (!$conv) {
            set_flash(t('pm_conv_not_found', '会话不存在。'), 'error');
            redirect('/pm');
        }
        if ((int)$conv['user1_id'] !== $userId && (int)$conv['user2_id'] !== $userId) {
            set_flash(t('pm_no_delete_permission', '你没有权限删除该会话。'), 'error');
            redirect('/pm');
        }

        // 删除消息与会话（外键已配置 CASCADE，此处显式删除以防外键未启用）
        $stmt = $db->prepare("DELETE FROM pm_messages WHERE conversation_id = :cid");
        $stmt->execute([':cid' => $conversationId]);
        $stmt = $db->prepare("DELETE FROM pm_conversations WHERE id = :cid");
        $stmt->execute([':cid' => $conversationId]);
        clear_unread_pm_cache();

        set_flash(t('pm_conv_deleted', '会话已删除。'), 'success');
        redirect('/pm');
    }

    if ($action === 'delete_message') {
        $messageId = (int)(isset($_POST['message_id']) ? $_POST['message_id'] : 0);
        $conversationId = (int)(isset($_POST['conversation_id']) ? $_POST['conversation_id'] : 0);

        // 校验会话归属
        $stmt = $db->prepare("SELECT * FROM pm_conversations WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $conversationId]);
        $conv = $stmt->fetch();
        if (!$conv || ((int)$conv['user1_id'] !== $userId && (int)$conv['user2_id'] !== $userId)) {
            set_flash(t('pm_no_conv_permission', '你没有权限操作该会话。'), 'error');
            redirect('/pm');
        }

        if ($messageId <= 0) {
            set_flash(t('pm_msg_not_found', '消息不存在。'), 'error');
            redirect('/pm?action=view&id=' . $conversationId);
        }

        $stmt = $db->prepare("SELECT * FROM pm_messages WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $messageId]);
        $msg = $stmt->fetch();
        if (!$msg || (int)$msg['conversation_id'] !== $conversationId) {
            set_flash(t('pm_msg_not_found', '消息不存在。'), 'error');
            redirect('/pm?action=view&id=' . $conversationId);
        }

        // 只允许删除自己发送的消息
        if ((int)$msg['sender_id'] !== $userId) {
            set_flash(t('pm_only_own_msg', '只能删除自己发送的消息。'), 'error');
            redirect('/pm?action=view&id=' . $conversationId);
        }

        $stmt = $db->prepare("DELETE FROM pm_messages WHERE id = :id");
        $stmt->execute([':id' => $messageId]);
        clear_unread_pm_cache();

        set_flash(t('pm_msg_deleted', '消息已删除。'), 'success');
        redirect('/pm?action=view&id=' . $conversationId);
    }
}

// ============================================
// 处理 view 动作（提前处理已读标记与回帖跳转）
// ============================================
$conversation = null;
$otherUser = null;
$messages = [];

if ($action === 'view') {
    $conversationId = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
    if ($conversationId <= 0) {
        set_flash(t('pm_conv_not_found', '会话不存在。'), 'error');
        redirect('/pm');
    }

    // 查询会话并验证参与者身份
    $stmt = $db->prepare("SELECT * FROM pm_conversations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $conversationId]);
    $conversation = $stmt->fetch();

    if (!$conversation) {
        set_flash(t('pm_conv_not_found', '会话不存在。'), 'error');
        redirect('/pm');
    }

    $convUser1 = (int)$conversation['user1_id'];
    $convUser2 = (int)$conversation['user2_id'];

    if ($convUser1 !== $userId && $convUser2 !== $userId) {
        set_flash(t('pm_no_view_permission', '你没有权限查看该会话。'), 'error');
        redirect('/pm');
    }

    // 确定对方用户
    $otherUserId = ($convUser1 === $userId) ? $convUser2 : $convUser1;
    $stmt = $db->prepare("SELECT id, username, avatar FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $otherUserId]);
    $otherUser = $stmt->fetch();

    if (!$otherUser) {
        set_flash(t('pm_other_user_not_found', '对方用户不存在。'), 'error');
        redirect('/pm');
    }

    // 标记对方发的消息为已读
    $stmt = $db->prepare("UPDATE pm_messages SET is_read = 1 WHERE conversation_id = :cid AND sender_id = :sender AND is_read = 0");
    $stmt->execute([':cid' => $conversationId, ':sender' => $otherUserId]);
    clear_unread_pm_cache();

    // 消息分页（默认显示最后一页/最新消息；按时间正序展示，旧消息在上）
    $msgPerPage = 20;
    $countStmt = $db->prepare("SELECT COUNT(*) FROM pm_messages WHERE conversation_id = :cid");
    $countStmt->execute([':cid' => $conversationId]);
    $msgTotal = (int)$countStmt->fetchColumn();
    $msgTotalPages = max(1, (int)ceil($msgTotal / $msgPerPage));
    $msgPage = isset($_GET['p']) ? max(1, (int)$_GET['p']) : $msgTotalPages;
    $msgPage = min($msgPage, $msgTotalPages);
    $msgOffset = ($msgPage - 1) * $msgPerPage;
    $stmt = $db->prepare("SELECT m.*, u.username, u.avatar
        FROM pm_messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.conversation_id = :cid
        ORDER BY m.created_at DESC, m.id DESC
        LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $msgPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $msgOffset, PDO::PARAM_INT);
    $stmt->execute();
    $messages = array_reverse($stmt->fetchAll());
}

// ============================================
// 处理 new 动作：预选收件人
// ============================================
$preselectUser = null;
if ($action === 'new') {
    $toId = (int)(isset($_GET['to']) ? $_GET['to'] : 0);
    if ($toId > 0 && $toId !== $userId) {
        $stmt = $db->prepare("SELECT id, username FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $toId]);
        $preselectUser = $stmt->fetch();
    }
}

// ============================================
// 公共数据：会话列表（用于侧边/列表页）
// ============================================
$conversations = [];
if ($action === 'list') {
    $stmt = $db->prepare("SELECT c.*,
            CASE WHEN c.user1_id = :uid THEN c.user2_id ELSE c.user1_id END AS other_id,
            u.username AS other_username,
            u.avatar AS other_avatar,
            lm.content AS last_content,
            lm.sender_id AS last_sender_id,
            lm.created_at AS last_created_at,
            (SELECT COUNT(*) FROM pm_messages mm
             WHERE mm.conversation_id = c.id
               AND mm.sender_id != :uid2
               AND mm.is_read = 0) AS unread_count
        FROM pm_conversations c
        JOIN users u ON u.id = CASE WHEN c.user1_id = :uid3 THEN c.user2_id ELSE c.user1_id END
        LEFT JOIN pm_messages lm ON lm.id = (
            SELECT id FROM pm_messages
            WHERE conversation_id = c.id
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        )
        WHERE c.user1_id = :uid4 OR c.user2_id = :uid5
        ORDER BY c.last_message_at DESC");
    $stmt->execute([
        ':uid' => $userId,
        ':uid2' => $userId,
        ':uid3' => $userId,
        ':uid4' => $userId,
        ':uid5' => $userId,
    ]);
    $conversations = $stmt->fetchAll();
}

// ============================================
// 渲染页面
// ============================================
$extraStyles = ['/public/css/pm.css'];

if ($action === 'list') {
    $pageTitle = t('pm_page_title', '站内信');
    include APP_ROOT . 'app/includes/header.php';
    ?>
    <div class="page-header">
        <div>
            <nav class="breadcrumb" aria-label="<?php echo e(t('pm_breadcrumb_aria', '面包屑导航\"')); ?> style="margin-bottom: 0.5rem;">
                <a href="/"><?php echo e(t('pm_home', '首页')); ?></a>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current"><?php echo e(t('pm_page_title', '站内信')); ?></span>
            </nav>
            <h1 class="page-title"><?php echo e(t('pm_page_title', '站内信')); ?></h1>
        </div>
        <a href="<?php echo site_url('pm', ['action' => 'new']); ?>" class="btn btn-primary"><?php echo e(t('pm_new', '发私信')); ?></a>
    </div>

    <div class="pm-card pm-list-card">
        <div class="pm-card-header">
            <h2><?php echo e(t('pm_conv_list', '会话列表')); ?> <span class="pm-count-pill"><?php echo e(t('pm_conv_count', '共 {n} 个会话', ['n' => count($conversations)])); ?></span></h2>
        </div>
        <?php if (empty($conversations)): ?>
            <div class="pm-empty">
                <svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <p><?php echo e(t('pm_empty_prefix', '还没有任何会话，')); ?><a href="<?php echo site_url('pm', ['action' => 'new']); ?>"><?php echo e(t('pm_empty_link', '发一封私信')); ?></a><?php echo e(t('pm_empty_suffix', ' 开始聊天吧～')); ?></p>
            </div>
        <?php else: ?>
            <div class="pm-conv-list">
                <?php foreach ($conversations as $conv): ?>
                    <?php
                    $convUnread = (int)$conv['unread_count'];
                    $lastContent = $conv['last_content'] !== null ? $conv['last_content'] : '';
                    $lastPreview = mb_strlen($lastContent) > 40 ? mb_substr($lastContent, 0, 40, 'UTF-8') . '...' : $lastContent;
                    $lastIsMine = $conv['last_sender_id'] !== null && (int)$conv['last_sender_id'] === $userId;
                    if ($lastIsMine) {
                        $lastPreview = t('pm_me_prefix', '我: ') . $lastPreview;
                    }
                    ?>
                    <a href="<?php echo site_url('pm', ['action' => 'view', 'id' => (int)$conv['id']]); ?>" class="pm-conv<?php echo $convUnread > 0 ? ' is-unread' : ''; ?>">
                        <img src="<?php echo avatar_url($conv['other_avatar'], $conv['other_username']); ?>" alt="" class="pm-conv-avatar">
                        <div class="pm-conv-body">
                            <div class="pm-conv-top">
                                <span class="pm-conv-name"><?php echo e($conv['other_username']); ?></span>
                                <?php if ($conv['last_created_at']): ?>
                                    <span class="pm-conv-time"><?php echo time_ago($conv['last_created_at']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="pm-conv-preview<?php echo $lastIsMine ? ' is-mine' : ''; ?>"><?php echo e($lastPreview); ?></div>
                        </div>
                        <?php if ($convUnread > 0): ?>
                            <span class="pm-conv-badge"><?php echo $convUnread; ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    include APP_ROOT . 'app/includes/footer.php';
    exit;
}

if ($action === 'view') {
    $pageTitle = t('pm_conv_title', '与 {name} 的对话', ['name' => $otherUser['username']]);
    include APP_ROOT . 'app/includes/header.php';
    ?>
    <div class="page-header">
        <div>
            <nav class="breadcrumb" aria-label="<?php echo e(t('pm_breadcrumb_aria', '面包屑导航\"')); ?> style="margin-bottom: 0.5rem;">
                <a href="/"><?php echo e(t('pm_home', '首页')); ?></a>
                <span class="breadcrumb-separator">/</span>
                <a href="<?php echo site_url('pm'); ?>"><?php echo e(t('pm_page_title', '站内信')); ?></a>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current"><?php echo t('pm_conv_title', '与 {name} 的对话', ['name' => e($otherUser['username'])]); ?></span>
            </nav>
            <h1 class="page-title"><?php echo t('pm_conv_title', '与 {name} 的对话', ['name' => e($otherUser['username'])]); ?></h1>
        </div>
        <a href="<?php echo site_url('pm'); ?>" class="btn btn-secondary"><?php echo e(t('pm_back_to_list', '返回列表')); ?></a>
    </div>

    <div class="pm-chat">
        <div class="pm-chat-header">
            <a href="<?php echo site_url('profile', ['user_id' => (int)$otherUser['id']]); ?>" title="<?php echo t('pm_view_profile_of', '查看 {name} 的主页', ['name' => e($otherUser['username'])]); ?>">
                <img src="<?php echo avatar_url($otherUser['avatar'], $otherUser['username']); ?>" alt="" class="pm-conv-avatar">
            </a>
            <div class="pm-chat-peer">
                <div class="pm-chat-peer-name"><a href="<?php echo site_url('profile', ['user_id' => (int)$otherUser['id']]); ?>" title="<?php echo t('pm_view_profile_of', '查看 {name} 的主页', ['name' => e($otherUser['username'])]); ?>"><?php echo e($otherUser['username']); ?></a></div>
                <div class="pm-chat-peer-sub"><?php echo e(t('pm_msg_count', '共 {n} 条消息', ['n' => $msgTotal])); ?></div>
            </div>
            <div class="pm-chat-header-actions">
                <a href="<?php echo site_url('profile', ['user_id' => (int)$otherUser['id']]); ?>" class="btn btn-sm btn-secondary"><?php echo e(t('pm_profile_btn', '个人主页')); ?></a>
                <form method="POST" action="<?php echo site_url('pm', ['action' => 'delete']); ?>" onsubmit=t('pm_4d9d43','return confirm(<?php echo e(json_encode(t(\'pm_delete_conv_confirm\', \'确定删除整个会话吗？此操作不可撤销。\'))); ?>);')>
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="conversation_id" value="<?php echo (int)$conversation['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><?php echo e(t('pm_delete_conv', '删除会话')); ?></button>
                </form>
            </div>
        </div>

        <div class="pm-chat-messages" id="pm-chat-messages">
            <?php if ($msgTotal > $msgPerPage): ?>
                <div class="pm-day-divider"><?php echo e(t('pm_earlier_messages', '— 以下是更早的消息 —')); ?></div>
                <?php echo pagination($msgPage, $msgTotal, $msgPerPage, site_url('pm', ['action' => 'view', 'id' => $conversationId])); ?>
            <?php endif; ?>
            <?php if (empty($messages)): ?>
                <div class="pm-empty">
                    <svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    <p><?php echo e(t('pm_no_messages', '还没有消息，在下方说点什么吧～')); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $msg):
                    $isMine = (int)$msg['sender_id'] === $userId;
                    $msgAvatar = $isMine ? $user['avatar'] : $msg['avatar'];
                    $msgName = $isMine ? $user['username'] : $msg['username'];
                    $msgProfileUrl = site_url('profile', ['user_id' => (int)$msg['sender_id']]);
                    ?>
                    <div class="pm-msg<?php echo $isMine ? ' is-mine' : ''; ?>" data-msg-id="<?php echo (int)$msg['id']; ?>">
                        <a href="<?php echo $msgProfileUrl; ?>" title="<?php echo t('pm_view_profile_of', '查看 {name} 的主页', ['name' => e($msgName)]); ?>">
                            <img src="<?php echo avatar_url($msgAvatar, $msgName); ?>" alt="" class="pm-msg-avatar">
                        </a>
                        <div class="pm-msg-main">
                            <div class="pm-msg-meta">
                                <span class="pm-msg-author"><a href="<?php echo $msgProfileUrl; ?>" title="<?php echo t('pm_view_profile_of', '查看 {name} 的主页', ['name' => e($msgName)]); ?>"><?php echo e($msgName); ?></a></span>
                                <span><?php echo time_ago($msg['created_at']); ?></span>
                                <?php if ($isMine): ?>
                                    <form method="POST" action="<?php echo site_url('pm', ['action' => 'delete_message']); ?>" class="pm-msg-delete-form" onsubmit=t('pm_109c2d','return confirm(<?php echo e(json_encode(t(\'pm_delete_msg_confirm\', \'确定删除这条消息吗？\'))); ?>);')>
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="message_id" value="<?php echo (int)$msg['id']; ?>">
                                        <input type="hidden" name="conversation_id" value="<?php echo (int)$conversation['id']; ?>">
                                        <button type="submit" title="<?php echo e(t('pm_delete_msg_title', '删除这条消息\"')); ?>><?php echo e(t('pm_delete', '删除')); ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <div class="pm-msg-bubble">
                                <?php echo bbcode($msg['content']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div id="pm-scroll-anchor"></div>
        </div>

        <?php if ($muteMessage !== null): ?>
        <div class="pm-chat-input">
            <p class="text-error mb-0"><?php echo e($muteMessage); ?><?php echo e(t('pm_cannot_send', '无法发送私信。')); ?></p>
        </div>
        <?php else: ?>
        <div class="pm-chat-input">
            <form method="POST" action="<?php echo site_url('pm', ['action' => 'send']); ?>" data-validate>
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="to_user_id" value="<?php echo (int)$otherUser['id']; ?>">
                <div class="bbcode-toolbar" style="display: flex; flex-wrap: wrap; gap: 0.375rem; margin-bottom: 0.5rem; align-items: center;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="insertBBCode('pm-content', '[b]', '[/b]')" title="<?php echo e(t('pm_bold', '加粗\"')); ?>><strong>B</strong></button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="insertBBCode('pm-content', '[i]', '[/i]')" title="<?php echo e(t('pm_italic', '斜体\"')); ?>><em>I</em></button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="insertBBCode('pm-content', '[u]', '[/u]')" title="<?php echo e(t('pm_underline', '下划线\"')); ?>><u>U</u></button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="insertPmLink()" title="<?php echo e(t('pm_insert_link', '插入链接\"')); ?>><?php echo e(t('pm_link', '链接')); ?></button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="EditorUpload.uploadLocalImage('pm-content', '<?php echo csrf_token(); ?>')" title="<?php echo e(t('pm_upload_image', '上传本地图片\"')); ?>><?php echo e(t('pm_image', '图片')); ?></button>
                    <button type="button" class="btn btn-sm btn-secondary" id="pm-emoji-btn" onclick="togglePmEmojiPanel(event)" title="<?php echo e(t('pm_insert_emoji', '插入表情\"')); ?>><?php echo e(t('pm_emoji', '表情')); ?></button>
                </div>
                <!-- 工具栏内联输入面板（替代 prompt 弹窗，只需填地址） -->
                <div class="toolbar-input-panel" id="pm-toolbar-input-panel">
                    <input type="text" class="form-control" id="pm-toolbar-input-main" placeholder="">
                    <div class="toolbar-input-actions">
                        <button type="button" class="btn btn-sm btn-primary" onclick="submitPmToolbarInput()"><?php echo e(t('pm_confirm', '确定')); ?></button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="cancelPmToolbarInput()"><?php echo e(t('pm_cancel', '取消')); ?></button>
                    </div>
                    <div class="toolbar-input-error" id="pm-toolbar-input-error" style="display:none;"></div>
                </div>
                <!-- 表情选择面板 -->
                <div class="emoji-panel" id="pm-emoji-panel" style="display: none; position: absolute; z-index: 1200;">
                    <div class="emoji-panel-header">
                        <span class="emoji-panel-title"><?php echo e(t('pm_choose_emoji', '选择表情')); ?></span>
                        <button type="button" class="emoji-panel-close" onclick="togglePmEmojiPanel(event)" aria-label="<?php echo e(t('pm_close', '关闭\"')); ?>>&times;</button>
                    </div>
                    <div class="emoji-grid">
                        <?php foreach (get_emoji_list() as $emoji): ?>
                            <button type="button" class="emoji-btn" title="<?php echo e($emoji['name']); ?>" onclick="insertPmEmoji('<?php echo e($emoji['code']); ?>')"><?php echo e($emoji['code']); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <textarea name="content" id="pm-content" rows="3" placeholder="<?php echo e(t('pm_reply_placeholder', '写下你的回复... 支持 BBCode 语法\"')); ?> required></textarea>
                <div class="pm-chat-input-bar">
                    <span class="pm-chat-input-hint"><?php echo e(t('pm_bbcode_hint', '支持 BBCode：加粗、链接、图片；可直接发送本地图片或表情')); ?></span>
                    <button type="submit" class="btn btn-primary"><?php echo e(t('pm_send', '发送')); ?></button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <script src="../public/js/editor.js"></script>
    <script>
    // ---------- 站内信编辑器：BBCode 与表情 ----------
    function insertBBCode(id, open, close) {
        var ta = document.getElementById(id);
        if (!ta) return;
        var start = ta.selectionStart;
        var end = ta.selectionEnd;
        var sel = ta.value.substring(start, end);
        var replacement = open + sel + close;
        ta.value = ta.value.substring(0, start) + replacement + ta.value.substring(end);
        ta.focus();
        ta.setSelectionRange(start + open.length + sel.length, start + open.length + sel.length);
    }

    function insertPmEmoji(emoji) {
        var ta = document.getElementById('pm-content');
        if (!ta) return;
        insertBBCode('pm-content', emoji, '');
        togglePmEmojiPanel(null, false);
    }

    // 插入链接：内联面板只需填地址，编辑器选中文字时自动作为链接文字
    var pmToolbarPanel = document.getElementById('pm-toolbar-input-panel');
    var pmToolbarInputMain = document.getElementById('pm-toolbar-input-main');
    var pmToolbarInputError = document.getElementById('pm-toolbar-input-error');
    var currentPmToolbarAction = null;

    function showPmToolbarInput(action, mainPlaceholder) {
        currentPmToolbarAction = action;
        pmToolbarInputMain.placeholder = mainPlaceholder || '';
        pmToolbarInputMain.value = '';
        pmToolbarInputError.style.display = 'none';
        pmToolbarInputError.textContent = '';
        pmToolbarPanel.classList.add('is-visible');
        pmToolbarInputMain.focus();
    }

    function hidePmToolbarInput() {
        pmToolbarPanel.classList.remove('is-visible');
        currentPmToolbarAction = null;
    }

    function showPmToolbarError(msg) {
        pmToolbarInputError.textContent = msg;
        pmToolbarInputError.style.display = 'block';
    }

    function submitPmToolbarInput() {
        if (!currentPmToolbarAction) return;
        var main = pmToolbarInputMain.value.trim();
        pmToolbarInputError.style.display = 'none';
        pmToolbarInputError.textContent = '';
        if (currentPmToolbarAction === 'link') {
            if (!main) {
                showPmToolbarError(<?php echo json_encode(t('pm_link_url_required', '请输入链接地址。')); ?>);
                return;
            }
            // 自动补全协议：未以 http:// 或 https:// 开头时自动补上 https://
            if (!/^https?:\/\//i.test(main)) {
                main = 'https://' + main;
            }
            // 若编辑器中已选中文字，则用选中文字作为链接文字，否则直接插入地址
            var ta = document.getElementById('pm-content');
            var hasSelection = ta && ta.selectionEnd > ta.selectionStart;
            if (hasSelection) {
                insertBBCode('pm-content', '[url=' + main + ']', '[/url]');
            } else {
                insertBBCode('pm-content', '[url]' + main + '[/url]', '');
            }
        }
        hidePmToolbarInput();
    }

    function cancelPmToolbarInput() {
        hidePmToolbarInput();
    }

    function insertPmLink() {
        showPmToolbarInput('link', <?php echo json_encode(t('pm_link_url_placeholder', '请输入链接地址（可省略 https://，会自动补全）')); ?>);
    }

    // 面板内回车确认、ESC 关闭
    document.addEventListener('keydown', function (e) {
        if (!pmToolbarPanel || !pmToolbarPanel.classList.contains('is-visible')) return;
        if (e.key === 'Enter') {
            e.preventDefault();
            submitPmToolbarInput();
        } else if (e.key === 'Escape') {
            hidePmToolbarInput();
        }
    });

    function togglePmEmojiPanel(event, forceState) {
        if (event) event.stopPropagation();
        var panel = document.getElementById('pm-emoji-panel');
        if (!panel) return;
        if (typeof forceState === 'boolean') {
            panel.style.display = forceState ? 'block' : 'none';
        } else {
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        }
    }

    // 点击页面其他区域关闭表情面板
    document.addEventListener('click', function (e) {
        var panel = document.getElementById('pm-emoji-panel');
        var btn = document.getElementById('pm-emoji-btn');
        if (!panel || panel.style.display === 'none') return;
        if (btn && btn.contains(e.target)) return;
        if (panel.contains(e.target)) return;
        panel.style.display = 'none';
    });

    // ---------- 会话实时轮询 ----------
    (function () {
        var box = document.getElementById('pm-chat-messages');
        if (!box) return;

        var conversationId = <?php echo (int)$conversationId; ?>;
        var isLatestPage = <?php echo (int)($msgPage === $msgTotalPages ? 1 : 0); ?>;

        function lastMsgId() {
            var msgs = box.querySelectorAll('.pm-msg[data-msg-id]');
            var max = 0;
            for (var i = 0; i < msgs.length; i++) {
                var id = parseInt(msgs[i].getAttribute('data-msg-id'), 10) || 0;
                if (id > max) max = id;
            }
            return max;
        }

        function scrollToBottom() {
            // 优先滚动到锚点：兼容内容高度在图片加载后变化的情况
            var anchor = document.getElementById('pm-scroll-anchor');
            if (anchor) {
                anchor.scrollIntoView({ block: 'end', behavior: 'auto' });
                return;
            }
            box.scrollTop = box.scrollHeight;
        }

        // 生成消息气泡 HTML（与服务端渲染结构一致）
        function buildMessage(m) {
            var align = m.is_mine ? ' is-mine' : '';
            var name = m.is_mine ? '<?php echo e($user['username']); ?>' : m.username;
            var viewProfileTitle = <?php echo json_encode(t('pm_view_profile_of', '查看 {name} 的主页')); ?>.replace('{name}', escapeHtml(name));
            return '<div class="pm-msg' + align + '" data-msg-id="' + m.id + '">' +
                '<a href="' + m.profile + '" title="' + viewProfileTitle + '">' +
                '<img src="' + m.avatar + '" alt="" class="pm-msg-avatar"></a>' +
                '<div class="pm-msg-main">' +
                '<div class="pm-msg-meta">' +
                '<span class="pm-msg-author"><a href="' + m.profile + '" title="' + viewProfileTitle + '">' + escapeHtml(name) + '</a></span>' +
                '<span>' + (m.created_at || '') + '</span>' +
                '</div>' +
                '<div class="pm-msg-bubble">' + m.content + '</div>' +
                '</div></div>';
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function poll() {
            var url = '<?php echo site_url('api/pm_poll'); ?>&conversation_id=' + conversationId + '&after_id=' + lastMsgId();
            fetch(url, { cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success || !data.messages || !data.messages.length) return;
                    var empty = box.querySelector('.pm-empty');
                    if (empty) empty.remove();
                    data.messages.forEach(function (m) {
                        var div = document.createElement('div');
                        div.innerHTML = buildMessage(m);
                        while (div.firstChild) box.appendChild(div.firstChild);
                    });
                    if (isLatestPage) scrollToBottom();
                })
                .catch(function () {});
        }

        var timer = setInterval(poll, 6000);
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) clearInterval(timer);
            else { timer = setInterval(poll, 6000); poll(); }
        });

        // 进入会话且处于最新一页时，滚动到最新消息
        // （页面加载 / 图片加载完成后再次滚动，保证始终停留在最新消息）
        if (isLatestPage) {
            scrollToBottom();
            setTimeout(scrollToBottom, 200);
            window.addEventListener('load', function () {
                scrollToBottom();
            });
        }
    })();
    </script>
    <?php
    include APP_ROOT . 'app/includes/footer.php';
    exit;
}

if ($action === 'new') {
    $pageTitle = t('pm_new', '发私信');
    include APP_ROOT . 'app/includes/header.php';
    ?>
    <div class="page-header">
        <div>
            <nav class="breadcrumb" aria-label="<?php echo e(t('pm_breadcrumb_aria', '面包屑导航')); ?>" style="margin-bottom: 0.5rem;">
                <a href="/"><?php echo e(t('pm_home', '首页')); ?></a>
                <span class="breadcrumb-separator">/</span>
                <a href="<?php echo site_url('pm'); ?>"><?php echo e(t('pm_page_title', '站内信')); ?></a>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current"><?php echo e(t('pm_new', '发私信')); ?></span>
            </nav>
            <h1 class="page-title"><?php echo e(t('pm_new', '发私信')); ?></h1>
        </div>
        <a href="<?php echo site_url('pm'); ?>" class="btn btn-secondary"><?php echo e(t('pm_back_to_list', '返回列表')); ?></a>
    </div>

    <div class="pm-card pm-compose">
        <div class="pm-card-header">
            <h2><?php echo e(t('pm_compose_heading', '发送新私信')); ?></h2>
        </div>
        <?php if ($muteMessage !== null): ?>
        <div class="pm-compose-body">
            <p class="text-error"><?php echo e($muteMessage); ?><?php echo e(t('pm_cannot_send', '无法发送私信。')); ?></p>
            <p class="mb-0">
                <a href="<?php echo site_url('appeal'); ?>"><?php echo e(t('pm_appeal_link', '对处罚有异议？申请申诉')); ?></a>
            </p>
        </div>
        <?php else: ?>
        <div class="pm-compose-body">
            <form method="POST" action="<?php echo site_url('pm', ['action' => 'send']); ?>" data-validate>
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="form-group">
                    <label class="form-label" for="to_username"><?php echo e(t('pm_label_recipient', '收件人')); ?></label>
                    <?php if ($preselectUser): ?>
                        <div class="pm-compose-user">
                            <img src="<?php echo avatar_url($preselectUser['avatar'] ?? '', $preselectUser['username']); ?>" alt="">
                            <span class="pm-compose-user-name"><?php echo e($preselectUser['username']); ?></span>
                            <span class="text-muted" style="font-size: 0.8125rem;"><?php echo e(t('pm_preselected', '（已为你预选收件人）')); ?></span>
                        </div>
                        <input type="hidden" name="to_username" value="<?php echo e($preselectUser['username']); ?>">
                    <?php else: ?>
                        <input type="text" class="form-control" id="to_username" name="to_username"
                               value="" placeholder="<?php echo e(t('pm_recipient_placeholder', '请输入收件人用户名')); ?>" required>
                        <p class="form-hint"><?php echo e(t('pm_recipient_hint', '请输入对方的用户名。')); ?></p>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="content"><?php echo e(t('pm_label_content', '消息内容')); ?></label>
                    <textarea class="form-control" id="content" name="content" rows="6" placeholder="<?php echo e(t('pm_content_placeholder', '写下你想说的话...')); ?>" required></textarea>
                    <p class="form-hint"><?php echo e(t('pm_bbcode_hint_compose', '支持 BBCode：加粗、链接、图片、引用。')); ?></p>
                </div>
                <div class="flex gap-1">
                    <button type="submit" class="btn btn-primary"><?php echo e(t('pm_send_pm', '发送私信')); ?></button>
                    <a href="<?php echo site_url('pm'); ?>" class="btn btn-secondary"><?php echo e(t('pm_cancel', '取消')); ?></a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php
    include APP_ROOT . 'app/includes/footer.php';
    exit;
}
