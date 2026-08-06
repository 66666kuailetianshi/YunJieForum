<?php
/**
 * 云界论坛 - 免责声明
 * 内容由管理员在后台「协议页面管理」中编辑维护
 */

require_once APP_ROOT . 'app/includes/functions.php';

$pageTitle = t('disclaimer_page_title', '免责声明');

// 从数据库读取页面内容（管理员可在后台编辑）
$pageData = get_site_page('disclaimer');

include APP_ROOT . 'app/includes/header.php';
?>

<nav class="breadcrumb" aria-label="<?php echo e(t('disclaimer_breadcrumb_aria', '面包屑导航')); ?>">
    <a href="/"><?php echo e(t('disclaimer_breadcrumb_home', '首页')); ?></a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?php echo e(t('disclaimer_page_title', '免责声明')); ?></span>
</nav>

<div class="card" style="max-width: 900px; margin: 0 auto;">
    <div class="page-header" style="border-bottom: 1px solid var(--border); padding-bottom: 1rem;">
        <h1 class="page-title"><?php echo e($pageData['title'] ?? t('disclaimer_page_title', '免责声明')); ?></h1>
        <p class="text-muted"><?php echo e(t('disclaimer_last_updated', '最后更新日期：{date}', ['date' => $pageData['updated_at'] ?? date('Y-m-d')])); ?></p>
    </div>

    <div class="terms-content" style="line-height: 1.8; padding: 1rem 0;">
        <?php if ($pageData): ?>
            <?php echo $pageData['content']; ?>
        <?php else: ?>
            <p><?php echo e(t('disclaimer_intro', '{site}（以下简称"本论坛"）在此特别声明如下免责及责任限制条款。访问或使用本论坛即视为您已充分阅读、理解并同意接受本免责声明的全部内容。', ['site' => SITE_NAME])); ?></p>
            <h2><?php echo e(t('disclaimer_s1_title', '一、内容免责')); ?></h2>
            <ol>
                <li><?php echo e(t('disclaimer_s1_i1', '本论坛上的内容（包括但不限于帖子、回复、评论、图片、链接等）均由用户自行发布，仅代表发布者个人观点，与本论坛立场无关。')); ?></li>
                <li><?php echo e(t('disclaimer_s1_i2', '本论坛不对用户发布内容的真实性、准确性、完整性、合法性作任何明示或默示的保证。')); ?></li>
                <li><?php echo e(t('disclaimer_s1_i3', '如您发现本论坛上存在侵犯您合法权益的内容，请通过站内信或管理员邮箱联系我们。')); ?></li>
            </ol>
            <h2><?php echo e(t('disclaimer_s2_title', '二、服务免责')); ?></h2>
            <ol>
                <li><?php echo e(t('disclaimer_s2_i1', '本论坛按"现状"提供服务，不提供任何形式的明示或默示担保。')); ?></li>
                <li><?php echo e(t('disclaimer_s2_i2', '本论坛尽力保证服务的连续性和安全性，但不对因不可抗力、系统维护、网络故障等原因导致的服务中断或数据丢失承担责任。')); ?></li>
            </ol>
            <h2><?php echo e(t('disclaimer_s3_title', '三、链接免责')); ?></h2>
            <ol>
                <li><?php echo e(t('disclaimer_s3_i1', '本论坛可能包含指向第三方网站的链接，不构成对第三方内容的认可或推荐。')); ?></li>
                <li><?php echo e(t('disclaimer_s3_i2', '用户访问第三方网站应自行承担风险。')); ?></li>
            </ol>
            <h2><?php echo e(t('disclaimer_s4_title', '四、用户行为与赔偿责任')); ?></h2>
            <ol>
                <li><?php echo e(t('disclaimer_s4_i1', '用户应对其在本论坛上的所有行为承担法律责任。')); ?></li>
                <li><?php echo e(t('disclaimer_s4_i2', '用户之间因使用本论坛服务产生的任何纠纷，应由双方自行协商解决。')); ?></li>
            </ol>
            <h2><?php echo e(t('disclaimer_s5_title', '五、知识产权免责')); ?></h2>
            <ol>
                <li><?php echo e(t('disclaimer_s5_i1', '用户发布内容应确保拥有合法权利，如有侵权由发布者自行承担法律责任。')); ?></li>
                <li><?php echo e(t('disclaimer_s5_i2', '本论坛平台的知识产权归本论坛运营方所有。')); ?></li>
            </ol>
            <h2><?php echo e(t('disclaimer_s6_title', '六、法律适用与管辖')); ?></h2>
            <ol>
                <li><?php echo e(t('disclaimer_s6_i1', '本免责声明的订立、执行、解释及争议解决均适用中华人民共和国法律。')); ?></li>
                <li><?php echo e(t('disclaimer_s6_i2', '如本声明任何条款被认定为无效或不可执行，不影响其他条款的效力。')); ?></li>
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
