<?php
/**
 * 云界论坛 - 服务协议
 * 内容由管理员在后台「协议页面管理」中编辑维护
 */

require_once APP_ROOT . 'app/includes/functions.php';

$pageTitle = t('service_page_title', '服务协议');

// 从数据库读取页面内容（管理员可在后台编辑）
$pageData = get_site_page('service');

include APP_ROOT . 'app/includes/header.php';
?>

<nav class="breadcrumb" aria-label="<?php echo e(t('service_breadcrumb_aria', '面包屑导航')); ?>">
    <a href="/"><?php echo e(t('service_breadcrumb_home', '首页')); ?></a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?php echo e(t('service_page_title', '服务协议')); ?></span>
</nav>

<div class="card doc-page">
    <header class="doc-header">
        <h1><?php echo e($pageData['title'] ?? t('service_page_title', '服务协议')); ?></h1>
        <p class="doc-updated"><?php echo e(t('service_last_updated', '最后更新日期：{date}', ['date' => $pageData['updated_at'] ?? date('Y-m-d')])); ?></p>
    </header>

    <?php if ($pageData): ?>
            <?php echo $pageData['content']; ?>
        <?php else: ?>
            <p><?php echo e(t('service_intro', '欢迎使用{site}。在注册账号并使用本论坛服务前，请您仔细阅读并充分理解本服务协议的全部内容。', ['site' => SITE_NAME])); ?></p>
            <h2><?php echo e(t('service_s1_title', '一、协议接受')); ?></h2>
            <ol>
                <li><?php echo e(t('service_s1_i1', '您通过注册、登录或以任何方式使用本论坛服务的行为，即视为您已阅读并同意本协议的全部条款。')); ?></li>
                <li><?php echo e(t('service_s1_i2', '如您不同意本协议任何条款，请立即停止使用本论坛服务。')); ?></li>
            </ol>
            <h2><?php echo e(t('service_s2_title', '二、账号注册与管理')); ?></h2>
            <ol>
                <li><?php echo e(t('service_s2_i1', '您在注册时应提供真实、准确、完整的个人信息。')); ?></li>
                <li><?php echo e(t('service_s2_i2', '您应妥善保管账号和密码，对通过您账号进行的所有活动承担法律责任。')); ?></li>
            </ol>
            <h2><?php echo e(t('service_s3_title', '三、用户行为规范')); ?></h2>
            <ol>
                <li><?php echo e(t('service_s3_i1', '您承诺不会利用本论坛从事任何违法违规活动。')); ?></li>
                <li><?php echo e(t('service_s3_i2', '本论坛有权对违规内容和账号采取删除、警告、禁言、封禁等措施。')); ?></li>
            </ol>
            <h2><?php echo e(t('service_s4_title', '四、隐私保护')); ?></h2>
            <ol>
                <li><?php echo e(t('service_s4_i1', '您的个人信息将按照《隐私政策》的规定进行收集、使用和保护。')); ?></li>
            </ol>
            <h2><?php echo e(t('service_s5_title', '五、法律适用')); ?></h2>
            <ol>
                <li><?php echo e(t('service_s5_i1', '本协议的订立、执行、解释及争议解决均适用中华人民共和国法律。')); ?></li>
            </ol>
    <?php endif; ?>
</div>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
