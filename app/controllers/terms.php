<?php
/**
 * 云界论坛 - 用户协议
 * 内容由管理员在后台「协议页面管理」中编辑维护
 */

require_once APP_ROOT . 'app/includes/functions.php';

$pageTitle = t('terms_page_title', '用户协议');

// 从数据库读取页面内容（管理员可在后台编辑）
$pageData = get_site_page('terms');

include APP_ROOT . 'app/includes/header.php';
?>

<nav class="breadcrumb" aria-label="<?php echo e(t('terms_breadcrumb_aria', '面包屑导航')); ?>">
    <a href="/"><?php echo e(t('terms_breadcrumb_home', '首页')); ?></a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?php echo e(t('terms_page_title', '用户协议')); ?></span>
</nav>

<div class="card doc-page">
    <header class="doc-header">
        <h1><?php echo e($pageData['title'] ?? t('terms_page_title', '用户协议')); ?></h1>
        <p class="doc-updated"><?php echo e(t('terms_last_updated', '最后更新日期：{date}', ['date' => $pageData['updated_at'] ?? date('Y-m-d')])); ?></p>
    </header>

    <?php if ($pageData): ?>
            <?php echo $pageData['content']; ?>
        <?php else: ?>
            <p><?php echo e(t('terms_intro', '欢迎您使用 {site}（以下简称"本论坛"）。请您仔细阅读以下用户协议内容。当您完成注册或登录流程时，即视为您已充分阅读、理解并同意接受本协议的全部条款。', ['site' => SITE_NAME])); ?></p>
            <h2><?php echo e(t('terms_s1_title', '一、账号注册与管理')); ?></h2>
            <ol>
                <li><?php echo e(t('terms_s1_i1', '用户应当使用真实、准确、完整的个人信息进行注册，并对所提供信息的真实性负责。')); ?></li>
                <li><?php echo e(t('terms_s1_i2', '用户注册成功后，将获得本论坛账号及相应的使用权。用户应妥善保管账号、密码及其他登录信息，对账号下的一切行为承担法律责任。')); ?></li>
                <li><?php echo e(t('terms_s1_i3', '用户不得将账号转让、出借、出租或以其他方式允许第三方使用。如发现账号被盗用或存在安全风险，应立即通知本论坛管理员。')); ?></li>
                <li><?php echo e(t('terms_s1_i4', '本论坛有权根据相关法律法规及平台管理需要，对违规账号采取警告、限制功能、封禁直至删除账号等措施。')); ?></li>
            </ol>
            <h2><?php echo e(t('terms_s2_title', '二、用户行为规范')); ?></h2>
            <ol>
                <li><?php echo e(t('terms_s2_i1', '用户应遵守中华人民共和国法律法规，遵守社会公德，尊重网络道德。')); ?></li>
                <li><?php echo e(t('terms_s2_i2', '禁止发布、传播以下违法违规内容：')); ?><ul><li><?php echo e(t('terms_s2_i2_a', '反对宪法所确定的基本原则的；')); ?></li><li><?php echo e(t('terms_s2_i2_b', '危害国家安全、泄露国家秘密、颠覆国家政权、破坏国家统一的；')); ?></li><li><?php echo e(t('terms_s2_i2_c', '损害国家荣誉和利益的；')); ?></li><li><?php echo e(t('terms_s2_i2_d', '煽动民族仇恨、民族歧视，破坏民族团结的；')); ?></li><li><?php echo e(t('terms_s2_i2_e', '破坏国家宗教政策，宣扬邪教和封建迷信的；')); ?></li><li><?php echo e(t('terms_s2_i2_f', '散布谣言，扰乱社会秩序，破坏社会稳定的；')); ?></li><li><?php echo e(t('terms_s2_i2_g', '散布淫秽、色情、赌博、暴力、凶杀、恐怖或者教唆犯罪的；')); ?></li><li><?php echo e(t('terms_s2_i2_h', '侮辱或者诽谤他人，侵害他人合法权益的；')); ?></li><li><?php echo e(t('terms_s2_i2_i', '含有法律、行政法规禁止的其他内容的。')); ?></li></ul></li>
                <li><?php echo e(t('terms_s2_i3', '禁止利用本论坛进行任何形式的商业广告、垃圾信息传播、恶意刷屏、刷量、灌水等行为。')); ?></li>
                <li><?php echo e(t('terms_s2_i4', '禁止未经授权收集、复制、修改、传播其他用户的个人信息或本论坛的内容数据。')); ?></li>
            </ol>
            <h2><?php echo e(t('terms_s3_title', '三、内容发布与知识产权')); ?></h2>
            <ol>
                <li><?php echo e(t('terms_s3_i1', '用户在本论坛发布的内容，包括但不限于帖子、回复、评论、图片等，均视为用户本人创作或已获得合法授权。')); ?></li>
                <li><?php echo e(t('terms_s3_i2', '用户授予本论坛免费的、非独家的、可转授权的使用权，用于内容的展示、推广、存档及为维护平台秩序所必需的处理。')); ?></li>
                <li><?php echo e(t('terms_s3_i3', '用户应确保发布内容不侵犯任何第三方的知识产权、肖像权、名誉权等合法权益。如有侵权行为，由发布者自行承担法律责任。')); ?></li>
                <li><?php echo e(t('terms_s3_i4', '本论坛有权根据投诉或管理需要，对涉嫌侵权或违规内容进行删除、屏蔽、下架等处理，且无需事先通知发布者。')); ?></li>
            </ol>
            <h2><?php echo e(t('terms_s4_title', '四、隐私与个人信息保护')); ?></h2>
            <ol>
                <li><?php echo e(t('terms_s4_i1', '本论坛重视用户个人信息保护，具体保护方式请参见《隐私政策》。')); ?></li>
                <li><?php echo e(t('terms_s4_i2', '用户同意本论坛在法律法规允许的范围内，收集、使用、存储和保护其个人信息。')); ?></li>
            </ol>
            <h2><?php echo e(t('terms_s5_title', '五、免责声明')); ?></h2>
            <ol>
                <li><?php echo e(t('terms_s5_i1', '本论坛尽力提供稳定、安全的服务，但不对服务的连续性、及时性、安全性作出绝对保证。')); ?></li>
                <li><?php echo e(t('terms_s5_i2', '对于因不可抗力、系统维护、网络故障、第三方原因等导致的服务中断或数据丢失，本论坛不承担赔偿责任，但将尽力减少损失。')); ?></li>
                <li><?php echo e(t('terms_s5_i3', '本论坛对用户之间因使用本服务而产生的纠纷不承担责任，用户应自行协商解决或通过法律途径处理。')); ?></li>
            </ol>
            <h2><?php echo e(t('terms_s6_title', '六、协议变更与终止')); ?></h2>
            <ol>
                <li><?php echo e(t('terms_s6_i1', '本论坛有权根据法律法规变化及平台运营需要，随时修改本协议。修改后的协议将在本页面公布，公布后即时生效。')); ?></li>
                <li><?php echo e(t('terms_s6_i2', '如用户不同意修改后的协议内容，应停止使用本论坛服务并注销账号。')); ?></li>
                <li><?php echo e(t('terms_s6_i3', '用户违反本协议或相关法律法规的，本论坛有权暂停或终止向用户提供服务。')); ?></li>
            </ol>
            <h2><?php echo e(t('terms_s7_title', '七、其他')); ?></h2>
            <ol>
                <li><?php echo e(t('terms_s7_i1', '本协议的订立、执行、解释及争议解决均适用中华人民共和国法律。')); ?></li>
                <li><?php echo e(t('terms_s7_i2', '如本协议任何条款被认定为无效或不可执行，不影响其他条款的效力。')); ?></li>
                <li><?php echo e(t('terms_s7_i3', '如您对本协议有任何疑问，可通过站内信或管理员邮箱与我们联系。')); ?></li>
            </ol>
    <?php endif; ?>
</div>

<?php include APP_ROOT . 'app/includes/footer.php'; ?>
