<?php
/**
 * 云界论坛 - 隐私政策
 * 内容由管理员在后台「协议页面管理」中编辑维护
 */

require_once APP_ROOT . 'app/includes/functions.php';

$pageTitle = t('privacy_page_title', '隐私政策');

// 从数据库读取页面内容（管理员可在后台编辑）
$pageData = get_site_page('privacy');

include APP_ROOT . 'app/includes/header.php';
?>

<nav class="breadcrumb" aria-label="<?php echo e(t('privacy_breadcrumb_aria', '面包屑导航')); ?>">
    <a href="/"><?php echo e(t('privacy_breadcrumb_home', '首页')); ?></a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?php echo e(t('privacy_page_title', '隐私政策')); ?></span>
</nav>

<div class="card" style="max-width: 900px; margin: 0 auto;">
    <div class="page-header" style="border-bottom: 1px solid var(--border); padding-bottom: 1rem;">
        <h1 class="page-title"><?php echo e($pageData['title'] ?? t('privacy_page_title', '隐私政策')); ?></h1>
        <p class="text-muted"><?php echo e(t('privacy_last_updated', '最后更新日期：{date}', ['date' => $pageData['updated_at'] ?? date('Y-m-d')])); ?></p>
    </div>

    <div class="terms-content" style="line-height: 1.8; padding: 1rem 0;">
        <?php if ($pageData): ?>
            <?php echo $pageData['content']; ?>
        <?php else: ?>
            <p><?php echo e(t('privacy_intro', '{site}（以下简称"本论坛"）非常重视用户的隐私和个人信息保护。本隐私政策将帮助您了解我们如何收集、使用、存储、共享和保护您的个人信息，以及您享有的相关权利。', ['site' => SITE_NAME])); ?></p>
            <h2><?php echo e(t('privacy_s1_title', '一、我们收集的信息')); ?></h2>
            <ol>
                <li><strong><?php echo e(t('privacy_s1_i1_label', '账号信息：')); ?></strong><?php echo e(t('privacy_s1_i1', '当您注册账号时，我们会收集您的用户名、邮箱地址、密码（加密存储）等基本信息。')); ?></li>
                <li><strong><?php echo e(t('privacy_s1_i2_label', '个人资料信息：')); ?></strong><?php echo e(t('privacy_s1_i2', '您可以选择填写头像、个性签名、个人简介等资料信息，这些信息将用于展示您的个人主页。')); ?></li>
                <li><strong><?php echo e(t('privacy_s1_i3_label', '内容信息：')); ?></strong><?php echo e(t('privacy_s1_i3', '您在本论坛发布的帖子、回复、评论、点赞、收藏、私信等内容数据。')); ?></li>
                <li><strong><?php echo e(t('privacy_s1_i4_label', '设备与日志信息：')); ?></strong><?php echo e(t('privacy_s1_i4', '为保障服务安全与稳定，我们会收集您的 IP 地址、浏览器类型、操作系统、访问时间、操作日志等技术信息。')); ?></li>
                <li><strong><?php echo e(t('privacy_s1_i5_label', 'Cookie 与本地存储：')); ?></strong><?php echo e(t('privacy_s1_i5', '我们使用 Cookie 和类似技术来记录您的登录状态、偏好设置及提升用户体验。您可以在浏览器设置中管理 Cookie。')); ?></li>
            </ol>
            <h2><?php echo e(t('privacy_s2_title', '二、我们如何使用信息')); ?></h2>
            <ol>
                <li><?php echo e(t('privacy_s2_i1', '用于账号注册、登录验证、身份识别及密码找回等核心服务。')); ?></li>
                <li><?php echo e(t('privacy_s2_i2', '用于向您展示个性化内容、推荐感兴趣的版块或帖子。')); ?></li>
                <li><?php echo e(t('privacy_s2_i3', '用于维护论坛秩序，识别和处理违规行为、垃圾信息及安全风险。')); ?></li>
                <li><?php echo e(t('privacy_s2_i4', '用于改进服务质量、进行数据分析及故障排查。')); ?></li>
                <li><?php echo e(t('privacy_s2_i5', '用于在获得您同意或法律法规允许的情况下，向您发送通知、公告或服务相关信息。')); ?></li>
            </ol>
            <h2><?php echo e(t('privacy_s3_title', '三、信息的存储与保护')); ?></h2>
            <ol>
                <li><?php echo e(t('privacy_s3_i1', '我们会采取合理的技术措施和管理措施保护您的个人信息，防止数据泄露、损毁或丢失。')); ?></li>
                <li><?php echo e(t('privacy_s3_i2', '您的密码经过加密处理后存储，我们不会以明文形式保存您的密码。')); ?></li>
                <li><?php echo e(t('privacy_s3_i3', '我们会根据服务需要和法律法规要求，在合理期限内保留您的个人信息。超出保留期限后，我们会对个人信息进行删除或匿名化处理。')); ?></li>
            </ol>
            <h2><?php echo e(t('privacy_s4_title', '四、信息的共享与披露')); ?></h2>
            <ol>
                <li><?php echo e(t('privacy_s4_i1', '我们不会向任何第三方出售、出租或交易您的个人信息。')); ?></li>
                <li><?php echo e(t('privacy_s4_i2', '在以下情形中，我们可能会披露您的个人信息：')); ?><ul><li><?php echo e(t('privacy_s4_i2_a', '获得您的明确同意；')); ?></li><li><?php echo e(t('privacy_s4_i2_b', '根据法律法规、司法机关或行政机关的强制性要求；')); ?></li><li><?php echo e(t('privacy_s4_i2_c', '为保护本论坛、其他用户或公众的合法权益所必需；')); ?></li><li><?php echo e(t('privacy_s4_i2_d', '为处理纠纷、投诉或安全事件所必需。')); ?></li></ul></li>
            </ol>
            <h2><?php echo e(t('privacy_s5_title', '五、您的权利')); ?></h2>
            <ol>
                <li><strong><?php echo e(t('privacy_s5_i1_label', '访问与更正：')); ?></strong><?php echo e(t('privacy_s5_i1', '您可以登录账号，在个人中心查看和修改您的个人信息。')); ?></li>
                <li><strong><?php echo e(t('privacy_s5_i2_label', '删除：')); ?></strong><?php echo e(t('privacy_s5_i2', '在符合法律法规规定的情况下，您可以申请删除您的账号及相关个人信息。')); ?></li>
                <li><strong><?php echo e(t('privacy_s5_i3_label', '撤回同意：')); ?></strong><?php echo e(t('privacy_s5_i3', '您可以随时通过账号设置或联系我们，撤回对特定信息处理的同意。')); ?></li>
                <li><strong><?php echo e(t('privacy_s5_i4_label', '注销账号：')); ?></strong><?php echo e(t('privacy_s5_i4', '您有权申请注销账号。注销后，我们将按照法律法规要求处理您的个人信息。')); ?></li>
            </ol>
            <h2><?php echo e(t('privacy_s6_title', '六、Cookie 政策')); ?></h2>
            <ol>
                <li><?php echo e(t('privacy_s6_i1', '我们使用会话 Cookie 来维持您的登录状态，使用持久 Cookie 来实现"记住我"功能。')); ?></li>
                <li><?php echo e(t('privacy_s6_i2', '您可以通过浏览器设置清除或拒绝 Cookie，但这样可能会影响您使用本论坛的部分功能。')); ?></li>
            </ol>
            <h2><?php echo e(t('privacy_s7_title', '七、未成年人保护')); ?></h2>
            <ol>
                <li><?php echo e(t('privacy_s7_i1', '本论坛不面向未满 18 周岁的未成年人提供服务。若我们发现收集了未成年人的个人信息，将尽快删除。')); ?></li>
            </ol>
            <h2><?php echo e(t('privacy_s8_title', '八、政策更新')); ?></h2>
            <ol>
                <li><?php echo e(t('privacy_s8_i1', '我们可能会根据法律法规变化或服务调整，适时更新本隐私政策。更新后的政策将在本页面公布，公布后即时生效。')); ?></li>
                <li><?php echo e(t('privacy_s8_i2', '如您不同意更新后的政策内容，应停止使用本论坛服务。')); ?></li>
            </ol>
            <h2><?php echo e(t('privacy_s9_title', '九、联系我们')); ?></h2>
            <ol>
                <li><?php echo e(t('privacy_s9_i1', '如您对本隐私政策或个人信息处理有任何疑问、意见或投诉，可通过站内信或管理员邮箱与我们联系。')); ?></li>
            </ol>
        <?php endif; ?>
    </div>
</div>

<style>
.terms-content h2 {
    font-size: 1.25rem;
    font-weight: 600;
    margin-top: 1.75rem;
    margin-bottom: 0.75rem;
    color: var(--text);
}
.terms-content ol,
.terms-content ul {
    padding-left: 1.5rem;
    margin-bottom: 1rem;
}
.terms-content li {
    margin-bottom: 0.5rem;
}
.terms-content p {
    margin-bottom: 1rem;
}
</style>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
