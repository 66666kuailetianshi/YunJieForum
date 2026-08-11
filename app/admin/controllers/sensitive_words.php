<?php
/**
 * 云界论坛 - 管理后台敏感词管理
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：敏感词管理仅超级管理员可用
require_super_admin();

require_once APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'sensitive_filter' . DIRECTORY_SEPARATOR . 'helper.php';

$db = get_db();
$driver = get_db_driver();
$action = $_GET['action'] ?? 'words';
$errors = [];
$success = '';

// 预定义标准分类（与 init_default_sensitive_words 保持一致）
$standardCategories = [t('admin_sensitive_words_4b3c8f','政治敏感'), t('admin_sensitive_words_f95e9b','违法犯罪'), t('admin_sensitive_words_3579fd','毒品相关'), t('admin_sensitive_words_38c84c','网络安全'), t('admin_sensitive_words_fdcb9a','诈骗'), t('admin_sensitive_words_f7f8ea','色情低俗'), t('admin_sensitive_words_0418ec','辱骂攻击'), t('admin_sensitive_words_57e697','广告引流'), t('admin_sensitive_words_2ecc5f','隐私骚扰'), t('admin_sensitive_words_1a26ed','其他')];

// 添加/编辑敏感词
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_word']) && validate_csrf()) {
    $id = (int)($_POST['id'] ?? 0);
    $word = trim($_POST['word'] ?? '');
    $category = trim($_POST['category'] ?? t('admin_sensitive_words_1a26ed','其他'));
    $level = (int)($_POST['level'] ?? 1);
    $matchMode = in_array($_POST['match_mode'] ?? '', ['exact', 'word', 'regex'], true) ? $_POST['match_mode'] : 'exact';
    $replacement = trim($_POST['replacement'] ?? '***');
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    if ($word === '') {
        $errors[] = t('admin_sensitive_err_word_required', '请输入敏感词。');
    } elseif ($level < 1 || $level > 3) {
        $errors[] = t('admin_sensitive_err_level_invalid', '等级选择无效。');
    } elseif ($matchMode === 'regex' && @preg_match('/' . $word . '/iu', '') === false) {
        $errors[] = t('admin_sensitive_err_regex_invalid', '正则表达式无效。');
    } else {
        if ($id > 0) {
            // 记录编辑前状态，用于检测 enabled 是否变更
            $oldStmt = $db->prepare("SELECT word, enabled FROM sensitive_words WHERE id = :id LIMIT 1");
            $oldStmt->execute([':id' => $id]);
            $oldRow = $oldStmt->fetch();
            $stmt = $db->prepare("UPDATE sensitive_words SET word = :word, category = :category, level = :level, match_mode = :match_mode, replacement = :replacement, enabled = :enabled WHERE id = :id");
            $stmt->execute([':word' => $word, ':category' => $category, ':level' => $level, ':match_mode' => $matchMode, ':replacement' => $replacement, ':enabled' => $enabled, ':id' => $id]);
            if ($oldRow && (int)$oldRow['enabled'] !== $enabled) {
                log_sw_status_change($id, $word, $enabled === 1 ? 'enable' : 'disable', (int)($_SESSION['user_id'] ?? 0), 'edit');
            }
        } else {
            $stmt = $db->prepare("INSERT INTO sensitive_words (word, category, level, match_mode, replacement, enabled) VALUES (:word, :category, :level, :match_mode, :replacement, :enabled)");
            $stmt->execute([':word' => $word, ':category' => $category, ':level' => $level, ':match_mode' => $matchMode, ':replacement' => $replacement, ':enabled' => $enabled]);
            $newId = (int)$db->lastInsertId();
            if ($newId > 0 && $enabled === 1) {
                log_sw_status_change($newId, $word, 'enable', (int)($_SESSION['user_id'] ?? 0), 'edit');
            }
        }
        clear_sensitive_filter_cache();
        set_flash(t('admin_sensitive_flash_saved', '敏感词已保存。'), 'success');
        redirect('/admin/sensitive_words?action=words');
    }
}

// 删除单个敏感词：仅接受 POST（CSRF 由 admin-init.php 对所有 POST 统一校验）
// 注意：必须置于下方批量 POST 分支之前，命中后直接 redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_word') {
    $delWordId = (int)($_POST['id'] ?? 0);
    if ($delWordId > 0) {
        $db->prepare("DELETE FROM sensitive_words WHERE id = :id")->execute([':id' => $delWordId]);
        clear_sensitive_filter_cache();
        set_flash(t('admin_sensitive_flash_deleted', '敏感词已删除。'), 'success');
    }
    redirect('/admin/sensitive_words?action=words');
}
// 旧 GET 删除链接命中：不执行删除，提示刷新
if ($action === 'delete_word') {
    set_flash(t('post_flash_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
    redirect('/admin/sensitive_words?action=words');
}

// 批量启用/禁用/删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_action']) && validate_csrf()) {
    $ids = array_map('intval', $_POST['ids'] ?? []);
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $batch = $_POST['batch_action'];
        $operatorId = (int)($_SESSION['user_id'] ?? 0);
        if ($batch === 'enable') {
            // 先查出当前 enabled=0 的词，只对真正变更的记录写入审计日志
            $stmt = $db->prepare("SELECT id, word FROM sensitive_words WHERE id IN ($in) AND enabled = 0");
            $stmt->execute($ids);
            $changedRows = $stmt->fetchAll();
            $db->prepare("UPDATE sensitive_words SET enabled = 1 WHERE id IN ($in)")->execute($ids);
            foreach ($changedRows as $row) {
                log_sw_status_change((int)$row['id'], (string)$row['word'], 'enable', $operatorId, 'batch');
            }
            set_flash(t('admin_sensitive_flash_batch_enabled', '已批量启用。'), 'success');
        } elseif ($batch === 'disable') {
            $stmt = $db->prepare("SELECT id, word FROM sensitive_words WHERE id IN ($in) AND enabled = 1");
            $stmt->execute($ids);
            $changedRows = $stmt->fetchAll();
            $db->prepare("UPDATE sensitive_words SET enabled = 0 WHERE id IN ($in)")->execute($ids);
            foreach ($changedRows as $row) {
                log_sw_status_change((int)$row['id'], (string)$row['word'], 'disable', $operatorId, 'batch');
            }
            set_flash(t('admin_sensitive_flash_batch_disabled', '已批量禁用。'), 'success');
        } elseif ($batch === 'delete') {
            $db->prepare("DELETE FROM sensitive_words WHERE id IN ($in)")->execute($ids);
            set_flash(t('admin_sensitive_flash_batch_deleted', '已批量删除。'), 'success');
        }
        clear_sensitive_filter_cache();
    }
    redirect('/admin/sensitive_words?action=words');
}

// 白名单管理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_whitelist']) && validate_csrf()) {
    $whiteWord = trim($_POST['white_word'] ?? '');
    if ($whiteWord === '') {
        $errors[] = t('admin_sensitive_err_white_required', '请输入白名单词汇。');
    } else {
        $stmt = $db->prepare("INSERT INTO sensitive_word_whitelist (word) VALUES (:word)");
        $stmt->execute([':word' => $whiteWord]);
        clear_sensitive_filter_cache();
        set_flash(t('admin_sensitive_flash_white_added', '白名单已添加。'), 'success');
        redirect('/admin/sensitive_words?action=whitelist');
    }
}
// 删除白名单：仅接受 POST（CSRF 由 admin-init.php 对所有 POST 统一校验）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_whitelist') {
    $delWhiteId = (int)($_POST['id'] ?? 0);
    if ($delWhiteId > 0) {
        $db->prepare("DELETE FROM sensitive_word_whitelist WHERE id = :id")->execute([':id' => $delWhiteId]);
        clear_sensitive_filter_cache();
        set_flash(t('admin_sensitive_flash_white_deleted', '白名单已删除。'), 'success');
    }
    redirect('/admin/sensitive_words?action=whitelist');
}
// 旧 GET 删除链接命中：不执行删除，提示刷新
if ($action === 'delete_whitelist') {
    set_flash(t('post_flash_method_changed', '操作方式已变更，请刷新页面重试。'), 'error');
    redirect('/admin/sensitive_words?action=whitelist');
}

// 测试
$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_text']) && validate_csrf()) {
    $testText = $_POST['test_text'] ?? '';
    $testResult = find_sensitive_words($testText);
}

// 处理批量导入
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_import']) && validate_csrf()) {
    $batchText = $_POST['batch_words'] ?? '';
    $batchLevel = (int)($_POST['batch_level'] ?? 1);
    $batchCategory = trim($_POST['batch_category'] ?? t('admin_sensitive_words_1a26ed','其他'));
    if ($batchCategory === '') $batchCategory = t('admin_sensitive_words_1a26ed','其他');
    $lines = preg_split('/\r\n|\r|\n/', $batchText);
    $inserted = 0;
    $stmt = sql_prepare($db, "INSERT OR IGNORE INTO sensitive_words (word, category, level, match_mode, replacement, enabled) VALUES (:word, :category, :level, 'exact', '***', 1)");
    foreach ($lines as $line) {
        $w = trim($line);
        if ($w !== '') {
            $stmt->execute([':word' => $w, ':category' => $batchCategory, ':level' => $batchLevel]);
            $inserted += $stmt->rowCount();
        }
    }
    clear_sensitive_filter_cache();
    set_flash(t('admin_sensitive_flash_imported', '批量导入完成，新增 {count} 个敏感词。', ['count' => $inserted]), 'success');
    redirect('/admin/sensitive_words?action=words');
}

// 列表数据
$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$levelFilter = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$where = '';
$params = [];
$conditions = [];
if ($search !== '') {
    $conditions[] = "word LIKE :search";
    $params[':search'] = '%' . $search . '%';
}
if ($categoryFilter !== '') {
    $conditions[] = "category = :category";
    $params[':category'] = $categoryFilter;
}
if ($levelFilter >= 1 && $levelFilter <= 3) {
    $conditions[] = "level = :level";
    $params[':level'] = $levelFilter;
}
if (!empty($conditions)) {
    $where = "WHERE " . implode(' AND ', $conditions);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$countStmt = $db->prepare("SELECT COUNT(*) FROM sensitive_words $where");
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare("SELECT * FROM sensitive_words $where ORDER BY id DESC LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$words = $stmt->fetchAll();

// 获取所有分类（预定义标准分类 + 数据库中已有的自定义分类，合并去重）
$dbCategories = $db->query("SELECT DISTINCT category FROM sensitive_words ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
$categories = array_values(array_unique(array_merge($standardCategories, $dbCategories)));

// 统计数据
$swStats = [
    'total'    => (int)$db->query("SELECT COUNT(*) FROM sensitive_words")->fetchColumn(),
    'enabled'  => (int)$db->query("SELECT COUNT(*) FROM sensitive_words WHERE enabled = 1")->fetchColumn(),
    'disabled' => (int)$db->query("SELECT COUNT(*) FROM sensitive_words WHERE enabled = 0")->fetchColumn(),
    'level1'   => (int)$db->query("SELECT COUNT(*) FROM sensitive_words WHERE level = 1 AND enabled = 1")->fetchColumn(),
    'level2'   => (int)$db->query("SELECT COUNT(*) FROM sensitive_words WHERE level = 2 AND enabled = 1")->fetchColumn(),
    'level3'   => (int)$db->query("SELECT COUNT(*) FROM sensitive_words WHERE level = 3 AND enabled = 1")->fetchColumn(),
    'whitelist' => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_whitelist")->fetchColumn(),
    'logs_today' => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs WHERE " . $driver->dateColExpr('created_at') . " = " . $driver->curDateExpr())->fetchColumn(),
    'logs_total' => (int)$db->query("SELECT COUNT(*) FROM sensitive_word_logs")->fetchColumn(),
];

// 分类分布
$catDistRows = $db->query("SELECT category, COUNT(*) as cnt FROM sensitive_words WHERE enabled = 1 GROUP BY category ORDER BY cnt DESC")->fetchAll();
$catDist = [];
foreach ($catDistRows as $row) {
    $catDist[$row['category']] = (int)$row['cnt'];
}

// 分类颜色映射（稳定分配，按分类名哈希取色）
$catColorPalette = ['#6366f1', '#ef4444', '#10b981', '#f59e0b', '#0ea5e9', '#8b5cf6', '#ec4899', '#14b8a6', '#64748b', '#f97316', '#84cc16', '#06b6d4'];
$catColors = [];
$sortedCats = $categories;
sort($sortedCats);
foreach ($sortedCats as $idx => $cat) {
    $catColors[$cat] = $catColorPalette[$idx % count($catColorPalette)];
}

// 白名单列表
$whitelist = $db->query("SELECT * FROM sensitive_word_whitelist ORDER BY id DESC")->fetchAll();

// 编辑模式
$editWord = null;
if ($action === 'edit_word' && isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM sensitive_words WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => (int)$_GET['id']]);
    $editWord = $stmt->fetch();
}

$pageTitle = t('admin_sensitive_title', '敏感词管理');
$activeMenu = 'sensitive_words';
require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?php echo e(t('admin_sensitive_title', '敏感词管理')); ?></h1>
</div>

<div class="card mb-2">
    <div class="filter-tabs">
        <a href="<?php echo site_url('admin/sensitive_words', ['action' => 'words']); ?>" class="filter-tab <?php echo $action === 'words' || $action === 'edit_word' ? 'active' : ''; ?>"><?php echo e(t('admin_sensitive_tab_words', '敏感词库')); ?></a>
        <a href="<?php echo site_url('admin/sensitive_words', ['action' => 'whitelist']); ?>" class="filter-tab <?php echo $action === 'whitelist' ? 'active' : ''; ?>"><?php echo e(t('admin_sensitive_tab_whitelist', '白名单')); ?></a>
        <a href="<?php echo site_url('admin/sensitive_words', ['action' => 'test']); ?>" class="filter-tab <?php echo $action === 'test' ? 'active' : ''; ?>"><?php echo e(t('admin_sensitive_tab_test', '测试工具')); ?></a>
        <a href="<?php echo site_url('admin/sensitive_word_logs'); ?>" class="filter-tab"><?php echo e(t('admin_sensitive_tab_logs', '日志')); ?></a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
        <?php echo show_message($err, 'error'); ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($action === 'words' || $action === 'edit_word'): ?>

<!-- 统计卡片 -->
<div class="sw-stats-grid">
    <div class="sw-stat-card sc-total">
        <div class="sw-stat-label"><?php echo e(t('admin_sensitive_stat_total', '敏感词总数')); ?></div>
        <div class="sw-stat-value"><?php echo $swStats['total']; ?></div>
        <div class="sw-stat-sub"><?php echo e(t('admin_sensitive_stat_total_sub', '启用 {enabled} · 禁用 {disabled}', ['enabled' => $swStats['enabled'], 'disabled' => $swStats['disabled']])); ?></div>
    </div>
    <div class="sw-stat-card sc-enabled">
        <div class="sw-stat-label"><?php echo e(t('admin_sensitive_stat_level1', '替换级')); ?></div>
        <div class="sw-stat-value"><?php echo $swStats['level1']; ?></div>
        <div class="sw-stat-sub"><?php echo e(t('admin_sensitive_stat_level1_sub', '自动替换文本')); ?></div>
    </div>
    <div class="sw-stat-card sc-intercept">
        <div class="sw-stat-label"><?php echo e(t('admin_sensitive_stat_level2', '拦截级')); ?></div>
        <div class="sw-stat-value"><?php echo $swStats['level2']; ?></div>
        <div class="sw-stat-sub"><?php echo e(t('admin_sensitive_stat_level2_sub', '阻止内容发布')); ?></div>
    </div>
    <div class="sw-stat-card sc-whitelist">
        <div class="sw-stat-label"><?php echo e(t('admin_sensitive_stat_whitelist', '白名单')); ?></div>
        <div class="sw-stat-value"><?php echo $swStats['whitelist']; ?></div>
        <div class="sw-stat-sub"><?php echo e(t('admin_sensitive_stat_whitelist_sub', '降低误伤短语')); ?></div>
    </div>
    <div class="sw-stat-card sc-logs-today">
        <div class="sw-stat-label"><?php echo e(t('admin_sensitive_stat_today', '今日命中')); ?></div>
        <div class="sw-stat-value"><?php echo $swStats['logs_today']; ?></div>
        <div class="sw-stat-sub"><?php echo e(t('admin_sensitive_stat_today_sub', '条拦截记录')); ?></div>
    </div>
    <div class="sw-stat-card sc-logs-total">
        <div class="sw-stat-label"><?php echo e(t('admin_sensitive_stat_all_time', '累计命中')); ?></div>
        <div class="sw-stat-value"><?php echo $swStats['logs_total']; ?></div>
        <div class="sw-stat-sub"><?php echo e(t('admin_sensitive_stat_all_time_sub', '条历史记录')); ?></div>
    </div>
</div>

<!-- 分类分布条 -->
<?php if (!empty($catDist) && $swStats['enabled'] > 0): ?>
<div class="card mb-2 sw-cat-bar-wrap">
    <div class="flex items-center justify-between mb-1">
        <h2 class="profile-card-title" style="margin:0;font-size:var(--text-base);"><?php echo e(t('admin_sensitive_cat_dist', '分类分布')); ?></h2>
        <span class="text-muted" style="font-size:var(--text-xs);"><?php echo e(t('admin_sensitive_cat_dist_enabled', '启用中 {count} 条', ['count' => $swStats['enabled']])); ?></span>
    </div>
    <div class="sw-cat-bar">
        <?php foreach ($catDist as $catName => $catCnt): $pct = round($catCnt / $swStats['enabled'] * 100); $color = $catColors[$catName] ?? '#71717a'; ?>
            <div class="sw-cat-bar-seg" style="width: <?php echo $pct; ?>%; background: <?php echo $color; ?>;" title="<?php echo e($catName); ?>: <?php echo $catCnt; ?> (<?php echo $pct; ?>%)">
                <?php if ($pct >= 8): ?><?php echo e($catName); ?><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="sw-cat-legend">
        <?php foreach ($catDist as $catName => $catCnt): $color = $catColors[$catName] ?? '#71717a'; ?>
            <span class="sw-cat-legend-item"><span class="sw-cat-legend-dot" style="background: <?php echo $color; ?>"></span><?php echo e($catName); ?> (<?php echo $catCnt; ?>)</span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- A+D 双栏主从布局：左栏列表 + 右栏详情面板 -->
<div class="sw-master-detail">

<!-- 左栏：列表区 -->
<div class="sw-master">
    <!-- 搜索 + 分类/等级筛选工具栏 -->
    <div class="card mb-2">
        <div class="sw-toolbar">
            <form method="GET" action="<?php echo site_url('admin/sensitive_words'); ?>" class="admin-search-form">
                <input type="hidden" name="action" value="words">
                <input type="text" name="search" class="form-control" placeholder="<?php echo e(t('admin_sensitive_search_ph', '搜索敏感词…')); ?>" value="<?php echo e($search); ?>">
                <button type="submit" class="btn btn-primary"><?php echo e(t('admin_sensitive_search', '搜索')); ?></button>
                <?php if ($search !== '' || $categoryFilter !== '' || $levelFilter > 0): ?>
                    <a href="<?php echo site_url('admin/sensitive_words', ['action' => 'words']); ?>" class="btn btn-secondary"><?php echo e(t('admin_sensitive_clear', '清除')); ?></a>
                <?php endif; ?>
            </form>
            <div class="sw-filter-row">
                <div class="sw-filter-group">
                    <label for="swFilterCategory"><?php echo e(t('admin_sensitive_label_category', '分类')); ?></label>
                    <select id="swFilterCategory" class="form-control">
                        <option value=""><?php echo e(t('admin_sensitive_filter_all', '全部')); ?></option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo e($cat); ?>" <?php echo $categoryFilter === $cat ? 'selected' : ''; ?>><?php echo e($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sw-filter-group">
                    <label for="swFilterLevel"><?php echo e(t('admin_sensitive_label_level', '等级')); ?></label>
                    <select id="swFilterLevel" class="form-control">
                        <option value=""><?php echo e(t('admin_sensitive_filter_all', '全部')); ?></option>
                        <option value="1" <?php echo $levelFilter === 1 ? 'selected' : ''; ?>><?php echo e(t('admin_sensitive_level_opt1', '1 - 自动替换')); ?></option>
                        <option value="2" <?php echo $levelFilter === 2 ? 'selected' : ''; ?>><?php echo e(t('admin_sensitive_level_opt2', '2 - 直接拦截')); ?></option>
                        <option value="3" <?php echo $levelFilter === 3 ? 'selected' : ''; ?>><?php echo e(t('admin_sensitive_level_opt3', '3 - 人工审核')); ?></option>
                    </select>
                </div>
            </div>
        </div>
    </div>

<!-- 敏感词列表 -->
<form method="POST" action="<?php echo site_url('admin/sensitive_words', ['action' => 'words']); ?>" id="sw-list-form">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <div class="card">
        <div class="flex items-center justify-between mb-2" style="flex-wrap:wrap; gap:0.75rem;">
            <div class="flex items-center gap-2" style="flex-wrap:wrap;">
                <h2 class="profile-card-title" style="margin:0;"><?php echo e(t('admin_sensitive_list_title', '敏感词列表')); ?></h2>
                <span class="sw-list-meta">
                    <?php echo e(t('admin_sensitive_list_count_prefix', '共')); ?> <strong><?php echo $total; ?></strong> <?php echo e(t('admin_sensitive_list_count_suffix', '条')); ?>
                    <?php if ($total !== $swStats['total']): ?>· <?php echo e(t('admin_sensitive_list_filtered', '已筛选')); ?><?php endif; ?>
                </span>
            </div>
        </div>
        <?php if (empty($words)): ?>
            <div class="sw-empty-row" style="padding: 3rem 1rem; text-align:center;">
                <svg class="sw-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="width:48px;height:48px;margin:0 auto 0.75rem;display:block;opacity:0.4;">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <div class="sw-empty-text"><?php echo e(t('admin_sensitive_empty_title', '未找到敏感词')); ?></div>
                <div class="sw-empty-hint"><?php echo ($search !== '' || $categoryFilter !== '' || $levelFilter > 0) ? e(t('admin_sensitive_empty_hint_filtered', '尝试调整筛选条件或清除筛选')) : e(t('admin_sensitive_empty_hint_default', '在右侧面板添加或批量导入敏感词')); ?></div>
            </div>
        <?php else: ?>
            <div class="sw-table-wrap">
                <table class="sw-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="sw-check-all" aria-label="<?php echo e(t('admin_sensitive_check_all', '全选')); ?>"></th>
                            <th class="sortable" data-sort="word"><?php echo e(t('admin_sensitive_th_word', '敏感词')); ?> <span class="sort-ind"></span></th>
                            <th class="sortable" data-sort="category" style="width: 140px;"><?php echo e(t('admin_sensitive_th_category', '分类')); ?> <span class="sort-ind"></span></th>
                            <th class="sortable" data-sort="level" style="width: 90px;"><?php echo e(t('admin_sensitive_th_level', '等级')); ?> <span class="sort-ind"></span></th>
                            <th style="width: 64px;"><?php echo e(t('admin_sensitive_th_status', '状态')); ?></th>
                            <th style="width: 76px;"><?php echo e(t('admin_sensitive_th_actions', '操作')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $modeLabels = ['exact' => t('admin_sensitive_mode_short_exact', '精'), 'word' => t('admin_sensitive_mode_short_word', '整'), 'regex' => t('admin_sensitive_mode_short_regex', '正')];
                        $levelLabels = [1 => t('admin_sensitive_level_short_1', '替换'), 2 => t('admin_sensitive_level_short_2', '拦截'), 3 => t('admin_sensitive_level_short_3', '审核')];
                        foreach ($words as $w): $lv = (int)$w['level']; $mode = $w['match_mode']; $catColor = $catColors[$w['category']] ?? '#71717a'; $isEditing = $editWord && $editWord['id'] == $w['id']; ?>
                        <tr class="sw-row<?php echo $w['enabled'] ? '' : ' disabled'; ?><?php echo $isEditing ? ' selected' : ''; ?>" data-id="<?php echo (int)$w['id']; ?>" data-word="<?php echo e($w['word']); ?>" data-category="<?php echo e($w['category']); ?>" data-level="<?php echo $lv; ?>" data-mode="<?php echo e($mode); ?>" data-replacement="<?php echo e($w['replacement']); ?>" data-enabled="<?php echo (int)$w['enabled']; ?>">
                            <td><input type="checkbox" name="ids[]" value="<?php echo $w['id']; ?>" class="sw-check" aria-label="<?php echo e(t('admin_sensitive_select_row', '选择此行')); ?>"></td>
                            <td class="sw-cell-word">
                                <span class="sw-cell-word-text"><?php echo e($w['word']); ?></span>
                                <span class="sw-mode-badge mode-<?php echo e($mode); ?>" title="<?php echo $mode === 'exact' ? e(t('admin_sensitive_mode_title_exact', '精确匹配（子串）')) : ($mode === 'word' ? e(t('admin_sensitive_mode_title_word', '整词匹配')) : e(t('admin_sensitive_mode_title_regex', '正则表达式'))); ?>"><?php echo e($modeLabels[$mode] ?? '?'); ?></span>
                            </td>
                            <td>
                                <span class="sw-cat-wrap" style="--cat-color: <?php echo $catColor; ?>">
                                    <span class="sw-cat-dot" aria-hidden="true"></span>
                                    <select class="sw-cat-select" data-id="<?php echo $w['id']; ?>" aria-label="<?php echo e(t('admin_sensitive_label_category', '分类')); ?>">
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo e($cat); ?>" <?php echo $cat === $w['category'] ? 'selected' : ''; ?>><?php echo e($cat); ?></option>
                                        <?php endforeach; ?>
                                        <?php if (!in_array($w['category'], $categories, true)): ?>
                                            <option value="<?php echo e($w['category']); ?>" selected><?php echo e($w['category']); ?></option>
                                        <?php endif; ?>
                                    </select>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="sw-level-pill lv-<?php echo $lv; ?>" data-id="<?php echo $w['id']; ?>" data-level="<?php echo $lv; ?>" title="<?php echo e(t('admin_sensitive_click_to_toggle_level', '点击切换等级')); ?>"><?php echo e($levelLabels[$lv] ?? 'L' . $lv); ?></button>
                            </td>
                            <td>
                                <label class="sw-switch" title="<?php echo $w['enabled'] ? e(t('admin_sensitive_click_to_disable', '点击禁用')) : e(t('admin_sensitive_click_to_enable', '点击启用')); ?>">
                                    <input type="checkbox" class="sw-toggle" data-id="<?php echo $w['id']; ?>" <?php echo $w['enabled'] ? 'checked' : ''; ?>>
                                    <span class="sw-switch-slider"></span>
                                </label>
                            </td>
                            <td class="sw-cell-actions">
                                <a href="<?php echo site_url('admin/sensitive_words', ['action' => 'edit_word', 'id' => (int)$w['id']]); ?>" class="sw-icon-btn btn-edit" title="<?php echo e(t('admin_sensitive_action_edit', '编辑')); ?>" aria-label="<?php echo e(t('admin_sensitive_action_edit', '编辑')); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                    </svg>
                                </a>
                                <button type="button" class="sw-icon-btn btn-delete sw-delete-btn" data-id="<?php echo $w['id']; ?>" title="<?php echo e(t('admin_sensitive_action_delete', '删除')); ?>" aria-label="<?php echo e(t('admin_sensitive_action_delete', '删除')); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/>
                                        <path d="M10 11v6M14 11v6"/>
                                        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <!-- 内嵌批量操作栏 -->
            <div class="sw-batch-bar">
                <span class="sw-batch-info"><?php echo e(t('admin_sensitive_selected_prefix', '已选')); ?> <strong id="sw-batch-count">0</strong> <?php echo e(t('admin_sensitive_selected_suffix', '项')); ?></span>
                <select name="batch_action" class="form-control">
                    <option value=""><?php echo e(t('admin_sensitive_batch_action_placeholder', '批量操作…')); ?></option>
                    <option value="enable"><?php echo e(t('admin_sensitive_batch_enable', '批量启用')); ?></option>
                    <option value="disable"><?php echo e(t('admin_sensitive_batch_disable', '批量禁用')); ?></option>
                    <option value="delete"><?php echo e(t('admin_sensitive_batch_delete', '批量删除')); ?></option>
                </select>
                <button type="submit" class="btn btn-secondary"><?php echo e(t('admin_sensitive_batch_execute', '执行')); ?></button>
            </div>
        <?php endif; ?>
        <?php
        $pageParams = ['action' => 'words'];
        if ($search !== '') $pageParams['search'] = $search;
        if ($categoryFilter !== '') $pageParams['category'] = $categoryFilter;
        if ($levelFilter > 0) $pageParams['level'] = $levelFilter;
        echo pagination($page, $total, $perPage, site_url('admin/sensitive_words', $pageParams));
        ?>
    </div>
</form>
</div><!-- /.sw-master -->

<!-- 右栏：详情面板 -->
<div class="sw-detail" id="swDetail">
    <div class="sw-detail-card">
        <div class="sw-detail-header">
            <h3>
                <span id="swDetailTitle"><?php echo $editWord ? e(t('admin_sensitive_edit_word', '编辑敏感词')) : e(t('admin_sensitive_add_word', '新增敏感词')); ?></span>
                <span class="sw-detail-tag" id="swDetailTag" style="display:<?php echo $editWord ? 'inline-block' : 'none'; ?>;">
                    ID #<?php echo $editWord ? (int)$editWord['id'] : ''; ?>
                </span>
            </h3>
            <button type="button" class="sw-detail-close" id="swDetailClose" aria-label="<?php echo e(t('admin_sensitive_close', '关闭')); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="sw-detail-body" id="swDetailBody">
            <!-- 编辑/新增表单 -->
            <form method="POST" action="<?php echo site_url('admin/sensitive_words', ['action' => 'words']); ?>" id="swEditForm">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="id" id="swFormId" value="<?php echo $editWord ? (int)$editWord['id'] : 0; ?>">
                <input type="hidden" name="save_word" value="1">
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('admin_sensitive_label_word', '敏感词 / 正则')); ?></label>
                    <input type="text" name="word" id="swFormWord" class="form-control" value="<?php echo e($editWord['word'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('admin_sensitive_label_category', '分类')); ?></label>
                    <select name="category" id="swFormCategory" class="form-control">
                        <?php foreach ($standardCategories as $cat): ?>
                            <option value="<?php echo e($cat); ?>" <?php echo (($editWord['category'] ?? t('admin_sensitive_words_1a26ed','其他')) === $cat) ? 'selected' : ''; ?>><?php echo e($cat); ?></option>
                        <?php endforeach; ?>
                        <?php if (!empty($editWord['category']) && !in_array($editWord['category'], $standardCategories, true)): ?>
                            <option value="<?php echo e($editWord['category']); ?>" selected><?php echo e($editWord['category']); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('admin_sensitive_label_level', '等级')); ?></label>
                    <select name="level" id="swFormLevel" class="form-control">
                        <option value="1" <?php echo ($editWord['level'] ?? 1) == 1 ? 'selected' : ''; ?>><?php echo e(t('admin_sensitive_level_opt1', '1 - 自动替换')); ?></option>
                        <option value="2" <?php echo ($editWord['level'] ?? 1) == 2 ? 'selected' : ''; ?>><?php echo e(t('admin_sensitive_level_opt2', '2 - 直接拦截')); ?></option>
                        <option value="3" <?php echo ($editWord['level'] ?? 1) == 3 ? 'selected' : ''; ?>><?php echo e(t('admin_sensitive_level_opt3', '3 - 人工审核')); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('admin_sensitive_label_match_mode', '匹配模式')); ?></label>
                    <select name="match_mode" id="swFormMode" class="form-control">
                        <option value="exact" <?php echo ($editWord['match_mode'] ?? 'exact') === 'exact' ? 'selected' : ''; ?>><?php echo e(t('admin_sensitive_mode_exact', '精确匹配（子串）')); ?></option>
                        <option value="word" <?php echo ($editWord['match_mode'] ?? 'exact') === 'word' ? 'selected' : ''; ?>><?php echo e(t('admin_sensitive_mode_word', '整词匹配')); ?></option>
                        <option value="regex" <?php echo ($editWord['match_mode'] ?? 'exact') === 'regex' ? 'selected' : ''; ?>><?php echo e(t('admin_sensitive_mode_regex', '正则表达式')); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo e(t('admin_sensitive_label_replacement', '替换文本')); ?></label>
                    <input type="text" name="replacement" id="swFormReplacement" class="form-control" value="<?php echo e($editWord['replacement'] ?? '***'); ?>">
                </div>
                <div class="form-group">
                    <label class="flex items-center gap-1 cursor-pointer">
                        <input type="checkbox" name="enabled" id="swFormEnabled" value="1" <?php echo ($editWord['enabled'] ?? 1) ? 'checked' : ''; ?>>
                        <span><?php echo e(t('admin_sensitive_enabled', '启用')); ?></span>
                    </label>
                </div>
                <div class="flex gap-1">
                    <button type="submit" class="btn btn-primary"><?php echo $editWord ? e(t('admin_sensitive_save_changes', '保存修改')) : e(t('admin_sensitive_add', '添加')); ?></button>
                    <?php if ($editWord): ?>
                        <a href="<?php echo site_url('admin/sensitive_words', ['action' => 'words']); ?>" class="btn btn-secondary"><?php echo e(t('admin_sensitive_cancel', '取消')); ?></a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- 批量导入（折叠） -->
            <div style="margin-top:1.25rem; padding-top:1rem; border-top:1px dashed var(--border-soft);">
                <div class="sw-collapse-toggle" id="swBatchToggle" aria-expanded="false">
                    <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    <span style="font-size:var(--text-sm);font-weight:500;color:var(--text-2);"><?php echo e(t('admin_sensitive_batch_import', '批量导入')); ?></span>
                </div>
                <div class="sw-collapsible collapsed" id="swBatchPanel" style="max-height:0;">
                    <form method="POST" action="<?php echo site_url('admin/sensitive_words', ['action' => 'words']); ?>" class="mt-3">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <div class="form-group">
                            <label class="form-label"><?php echo e(t('admin_sensitive_label_one_per_line', '每行一个词')); ?></label>
                            <textarea name="batch_words" class="form-control" rows="5" placeholder="傻逼&#10;他妈的&#10;垃圾广告"></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?php echo e(t('admin_sensitive_label_category', '分类')); ?></label>
                            <select name="batch_category" class="form-control">
                                <?php foreach ($standardCategories as $cat): ?>
                                    <option value="<?php echo e($cat); ?>"><?php echo e($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?php echo e(t('admin_sensitive_label_default_level', '默认等级')); ?></label>
                            <select name="batch_level" class="form-control">
                                <option value="1"><?php echo e(t('admin_sensitive_level_opt1', '1 - 自动替换')); ?></option>
                                <option value="2"><?php echo e(t('admin_sensitive_level_opt2', '2 - 直接拦截')); ?></option>
                                <option value="3"><?php echo e(t('admin_sensitive_level_opt3', '3 - 人工审核')); ?></option>
                            </select>
                        </div>
                        <button type="submit" name="batch_import" class="btn btn-primary btn-sm"><?php echo e(t('admin_sensitive_batch_import', '批量导入')); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div><!-- /.sw-detail -->

</div><!-- /.sw-master-detail -->

<!-- 移动端详情面板遮罩 -->
<div class="sw-detail-backdrop" id="swDetailBackdrop"></div>

<!-- 浮动批量操作栏（选中后从底部滑出） -->
<div class="sw-floating-bar" id="swFloatingBar" role="region" aria-label="<?php echo e(t('admin_sensitive_batch_action_aria', '批量操作')); ?>">
    <span class="sw-fb-count"><?php echo e(t('admin_sensitive_selected_prefix', '已选')); ?> <strong id="swFbCount">0</strong> <?php echo e(t('admin_sensitive_selected_suffix', '项')); ?></span>
    <span class="sw-fb-divider"></span>
    <button type="button" class="sw-fb-btn" data-batch="enable"><?php echo e(t('admin_sensitive_action_enable', '启用')); ?></button>
    <button type="button" class="sw-fb-btn" data-batch="disable"><?php echo e(t('admin_sensitive_action_disable', '禁用')); ?></button>
    <button type="button" class="sw-fb-btn danger" data-batch="delete"><?php echo e(t('admin_sensitive_action_delete', '删除')); ?></button>
    <span class="sw-fb-divider"></span>
    <button type="button" class="sw-fb-btn" id="swFbSelectAll"><?php echo e(t('admin_sensitive_select_all_page', '全选本页')); ?></button>
    <button type="button" class="sw-fb-close" id="swFbClose" aria-label="<?php echo e(t('admin_sensitive_cancel_selection', '取消选择')); ?>">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
</div>

<?php elseif ($action === 'whitelist'): ?>

<div class="grid-2 mb-2">
    <div class="card">
        <h2 class="profile-card-title"><?php echo e(t('admin_sensitive_add_whitelist', '添加白名单')); ?></h2>
        <form method="POST" action="<?php echo site_url('admin/sensitive_words', ['action' => 'whitelist']); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="form-group">
                <label class="form-label"><?php echo e(t('admin_sensitive_label_white_word', '白名单词汇 / 短语')); ?></label>
                <input type="text" name="white_word" class="form-control" placeholder="<?php echo e(t('admin_sensitive_white_word_ph', '例如：法轮寺')); ?>" required>
                <p class="form-hint"><?php echo e(t('admin_sensitive_white_hint', '当白名单短语包含某个敏感词时，该敏感词不会被命中。')); ?></p>
            </div>
            <button type="submit" name="save_whitelist" class="btn btn-primary"><?php echo e(t('admin_sensitive_add', '添加')); ?></button>
        </form>
    </div>
</div>

<div class="card">
    <h2 class="profile-card-title mb-2"><?php echo e(t('admin_sensitive_whitelist_title', '白名单列表')); ?> <span class="text-muted" style="font-weight:400;font-size:var(--text-sm);">（<?php echo e(t('admin_sensitive_total_count', '共 {count} 条', ['count' => count($whitelist)])); ?>）</span></h2>
    <?php if (empty($whitelist)): ?>
        <div class="empty-state">
            <p class="text-muted"><?php echo e(t('admin_sensitive_whitelist_empty', '暂无白名单，在左上方添加。')); ?></p>
        </div>
    <?php else: ?>
        <div class="swl-cloud">
            <?php foreach ($whitelist as $w): ?>
                <span class="swl-chip<?php echo $w['enabled'] ? '' : ' disabled'; ?>">
                    <?php echo e($w['word']); ?>
                    <?php echo admin_action_form(site_url('admin/sensitive_words'), 'delete_whitelist', ['id' => (int)$w['id']], '×', ['class' => 'swl-chip-del', 'confirm' => t('admin_sensitive_confirm_delete', '确定删除吗？'), 'title' => t('admin_sensitive_action_delete_white', '删除白名单')] ); ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php elseif ($action === 'test'): ?>

<div class="card">
    <h2 class="profile-card-title"><?php echo e(t('admin_sensitive_test_title', '敏感词测试')); ?></h2>
    <form method="POST" action="<?php echo site_url('admin/sensitive_words', ['action' => 'test']); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <div class="form-group">
            <label class="form-label"><?php echo e(t('admin_sensitive_label_test_text', '待检测文本')); ?></label>
            <textarea name="test_text" class="form-control" rows="5" placeholder="<?php echo e(t('admin_sensitive_test_text_ph', '输入要测试的文本')); ?>"><?php echo e($_POST['test_text'] ?? ''); ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo e(t('admin_sensitive_test_btn', '检测')); ?></button>
    </form>

    <?php if ($testResult !== null): ?>
        <div class="mt-2">
            <h3 class="text-base"><?php echo e(t('admin_sensitive_test_results', '命中结果（{count} 个）', ['count' => count($testResult)])); ?></h3>
            <?php if (empty($testResult)): ?>
                <p class="text-muted"><?php echo e(t('admin_sensitive_no_hits', '未命中任何敏感词。')); ?></p>
            <?php else: ?>
                <div class="swl-cloud">
                    <?php foreach ($testResult as $m): ?>
                        <span class="swl-chip" style="border-color: var(--error); background: var(--error-light);">
                            <code><?php echo e($m['word']); ?></code>
                            <span class="text-muted" style="font-size:var(--text-xs);"><?php echo e($m['category']); ?> · L<?php echo $m['level']; ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<script>
(function() {
    'use strict';

    // i18n strings injected from PHP
    var i18n = {
        editWord: <?php echo json_encode(t('admin_sensitive_edit_word', '编辑敏感词'), JSON_UNESCAPED_UNICODE); ?>,
        addWord: <?php echo json_encode(t('admin_sensitive_add_word', '新增敏感词'), JSON_UNESCAPED_UNICODE); ?>,
        saveChanges: <?php echo json_encode(t('admin_sensitive_save_changes', '保存修改'), JSON_UNESCAPED_UNICODE); ?>,
        add: <?php echo json_encode(t('admin_sensitive_add', '添加'), JSON_UNESCAPED_UNICODE); ?>,
        cancel: <?php echo json_encode(t('admin_sensitive_cancel', '取消'), JSON_UNESCAPED_UNICODE); ?>,
        idPrefix: <?php echo json_encode(t('admin_sensitive_id_prefix', 'ID #'), JSON_UNESCAPED_UNICODE); ?>,
        submitBtn: <?php echo json_encode(t('admin_sensitive_submit_btn', '提交中…'), JSON_UNESCAPED_UNICODE); ?>,
        opFailed: <?php echo json_encode(t('admin_sensitive_op_failed', '操作失败'), JSON_UNESCAPED_UNICODE); ?>,
        netError: <?php echo json_encode(t('admin_sensitive_net_error', '网络错误'), JSON_UNESCAPED_UNICODE); ?>,
        deleteFailed: <?php echo json_encode(t('admin_sensitive_delete_failed', '删除失败'), JSON_UNESCAPED_UNICODE); ?>,
        deleted: <?php echo json_encode(t('admin_sensitive_deleted', '已删除'), JSON_UNESCAPED_UNICODE); ?>,
        levelReplace: <?php echo json_encode(t('admin_sensitive_level_short_1', '替换'), JSON_UNESCAPED_UNICODE); ?>,
        levelReject: <?php echo json_encode(t('admin_sensitive_level_short_2', '拦截'), JSON_UNESCAPED_UNICODE); ?>,
        levelReview: <?php echo json_encode(t('admin_sensitive_level_short_3', '审核'), JSON_UNESCAPED_UNICODE); ?>,
        confirmAgain: <?php echo json_encode(t('admin_sensitive_click_again_confirm_delete', '再次点击确认删除'), JSON_UNESCAPED_UNICODE); ?>,
        deleteTitle: <?php echo json_encode(t('admin_sensitive_action_delete', '删除'), JSON_UNESCAPED_UNICODE); ?>,
        defaultCategory: <?php echo json_encode(t('admin_sensitive_category_other', '其他'), JSON_UNESCAPED_UNICODE); ?>
    };

    var ajaxUrl = '<?php echo site_url('admin/api/sensitive_words_ajax'); ?>';
    var csrfToken = '<?php echo csrf_token(); ?>';

    function post(action, data) {
        var body = new FormData();
        body.append('csrf_token', csrfToken);
        body.append('action', action);
        for (var k in data) {
            if (Object.prototype.hasOwnProperty.call(data, k)) body.append(k, data[k]);
        }
        return fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(res) { return res.json(); });
    }

    function flashRow(row) {
        if (!row) return;
        row.classList.remove('flash');
        void row.offsetWidth; // 触发重排，便于重复动画
        row.classList.add('flash');
        setTimeout(function() { row.classList.remove('flash'); }, 1000);
    }

    function notify(msg, type) {
        if (window.showToast) { window.showToast(msg, type); return; }
        alert(msg);
    }

    // ===== 主从交互：点击行加载详情到右栏 =====
    var swDetail = document.getElementById('swDetail');
    var swDetailBackdrop = document.getElementById('swDetailBackdrop');
    var swDetailClose = document.getElementById('swDetailClose');
    var swDetailTitle = document.getElementById('swDetailTitle');
    var swDetailTag = document.getElementById('swDetailTag');
    var swFormId = document.getElementById('swFormId');
    var swFormWord = document.getElementById('swFormWord');
    var swFormCategory = document.getElementById('swFormCategory');
    var swFormLevel = document.getElementById('swFormLevel');
    var swFormMode = document.getElementById('swFormMode');
    var swFormReplacement = document.getElementById('swFormReplacement');
    var swFormEnabled = document.getElementById('swFormEnabled');
    var swEditForm = document.getElementById('swEditForm');

    function loadRowToDetail(row) {
        if (!row || !swFormWord) return;
        // 清除其他行的选中态
        document.querySelectorAll('.sw-row.selected').forEach(function(r) {
            if (r !== row) r.classList.remove('selected');
        });
        row.classList.add('selected');
        // 填充表单
        var id = row.dataset.id;
        swFormId.value = id;
        swFormWord.value = row.dataset.word || '';
        var rowCat = row.dataset.category || i18n.defaultCategory;
        // 如果行分类不在 select 选项中，先追加选项再选中
        if (!Array.from(swFormCategory.options).some(function(o) { return o.value === rowCat; })) {
            var opt = document.createElement('option');
            opt.value = rowCat;
            opt.textContent = rowCat;
            swFormCategory.appendChild(opt);
        }
        swFormCategory.value = rowCat;
        swFormLevel.value = row.dataset.level || '1';
        swFormMode.value = row.dataset.mode || 'exact';
        swFormReplacement.value = row.dataset.replacement || '***';
        swFormEnabled.checked = row.dataset.enabled !== '0';
        // 更新标题
        swDetailTitle.textContent = i18n.editWord;
        swDetailTag.style.display = 'inline-block';
        swDetailTag.textContent = i18n.idPrefix + id;
        var submitBtn = swEditForm.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.textContent = i18n.saveChanges;
        // 取消按钮显示
        var cancelLink = swEditForm.querySelector('a.btn-secondary');
        if (!cancelLink) {
            cancelLink = document.createElement('a');
            cancelLink.href = '<?php echo site_url('admin/sensitive_words', ['action' => 'words']); ?>';
            cancelLink.className = 'btn btn-secondary';
            cancelLink.textContent = i18n.cancel;
            swEditForm.querySelector('.flex.gap-1').appendChild(cancelLink);
        }
        // 移动端打开抽屉
        openMobileDrawer();
        // 滚动表单到顶部
        swFormWord.focus({ preventScroll: true });
    }

    function resetDetailToAdd() {
        if (!swFormWord) return;
        document.querySelectorAll('.sw-row.selected').forEach(function(r) { r.classList.remove('selected'); });
        swFormId.value = 0;
        swFormWord.value = '';
        swFormCategory.value = i18n.defaultCategory;
        swFormLevel.value = '1';
        swFormMode.value = 'exact';
        swFormReplacement.value = '***';
        swFormEnabled.checked = true;
        swDetailTitle.textContent = i18n.addWord;
        swDetailTag.style.display = 'none';
        var submitBtn = swEditForm.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.textContent = i18n.add;
        var cancelLink = swEditForm.querySelector('a.btn-secondary');
        if (cancelLink) cancelLink.remove();
    }

    function isMobileLayout() {
        return window.matchMedia && window.matchMedia('(max-width: 920px)').matches;
    }
    function openMobileDrawer() {
        // 仅在移动端（抽屉模式）才显示遮罩并锁定滚动
        if (!isMobileLayout()) return;
        if (swDetail) swDetail.classList.add('visible');
        if (swDetailBackdrop) swDetailBackdrop.classList.add('visible');
        document.body.style.overflow = 'hidden';
    }
    function closeMobileDrawer() {
        if (swDetail) swDetail.classList.remove('visible');
        if (swDetailBackdrop) swDetailBackdrop.classList.remove('visible');
        document.body.style.overflow = '';
    }
    if (swDetailClose) swDetailClose.addEventListener('click', closeMobileDrawer);
    if (swDetailBackdrop) swDetailBackdrop.addEventListener('click', closeMobileDrawer);

    // 行点击（排除控件）加载详情
    document.querySelectorAll('.sw-row').forEach(function(row) {
        row.addEventListener('click', function(e) {
            // 排除控件自身的点击
            if (e.target.closest('.sw-check, .sw-cat-select, .sw-level-pill, .sw-switch, .sw-icon-btn')) return;
            loadRowToDetail(this);
        });
    });

    // 编辑按钮也触发行加载（而非跳转）
    document.querySelectorAll('.sw-icon-btn.btn-edit').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var row = this.closest('.sw-row');
            loadRowToDetail(row);
        });
    });

    // 批量导入折叠面板
    var batchToggle = document.getElementById('swBatchToggle');
    var batchPanel = document.getElementById('swBatchPanel');
    if (batchToggle && batchPanel) {
        batchToggle.addEventListener('click', function() {
            var collapsed = batchPanel.classList.toggle('collapsed');
            this.setAttribute('aria-expanded', !collapsed);
            if (collapsed) {
                batchPanel.style.maxHeight = '0';
            } else {
                batchPanel.style.maxHeight = batchPanel.scrollHeight + 'px';
            }
        });
    }

    // 分类/等级下拉筛选跳转
    function buildFilterUrl(params) {
        var url = '<?php echo site_url('admin/sensitive_words'); ?>&action=words';
        if (params.search) url += '&search=' + encodeURIComponent(params.search);
        if (params.category) url += '&category=' + encodeURIComponent(params.category);
        if (params.level) url += '&level=' + encodeURIComponent(params.level);
        return url;
    }
    var swFilterCategory = document.getElementById('swFilterCategory');
    var swFilterLevel = document.getElementById('swFilterLevel');
    function applyFilters() {
        var params = {
            search: '<?php echo e($search); ?>',
            category: swFilterCategory ? swFilterCategory.value : '',
            level: swFilterLevel ? swFilterLevel.value : ''
        };
        window.location.href = buildFilterUrl(params);
    }
    if (swFilterCategory) swFilterCategory.addEventListener('change', applyFilters);
    if (swFilterLevel) swFilterLevel.addEventListener('change', applyFilters);

    // 若从 URL 进入编辑模式（移动端自动打开抽屉）
    <?php if ($editWord): ?>
    if (window.matchMedia && window.matchMedia('(max-width: 920px)').matches) {
        openMobileDrawer();
    }
    <?php endif; ?>

    // 表单提交后若是新增模式，重置表单（由后端 redirect 处理，此处仅做 UX 优化：提交时禁用按钮防重复）
    if (swEditForm) {
        swEditForm.addEventListener('submit', function() {
            var btn = this.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.dataset.originalText = btn.textContent; btn.textContent = i18n.submitBtn; }
        });
    }

    // 删除单条后若右栏正在编辑该条，重置为新增
    function checkDetailAfterDelete(deletedId) {
        if (swFormId && swFormId.value === String(deletedId)) {
            resetDetailToAdd();
            closeMobileDrawer();
        }
    }

    // 1. 就地切换启用/禁用
    document.querySelectorAll('.sw-toggle').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var id = this.dataset.id;
            var row = this.closest('.sw-row');
            var checked = this.checked;
            post('toggle', { id: id, enabled: checked ? '1' : '0' })
                .then(function(data) {
                    if (data.ok) {
                        row.classList.toggle('disabled', !checked);
                        flashRow(row);
                    } else {
                        notify(data.error || i18n.opFailed, 'error');
                        cb.checked = !checked;
                    }
                })
                .catch(function(err) {
                    console.error(err);
                    notify(i18n.netError, 'error');
                    cb.checked = !checked;
                });
        });
    });

    // 2. 就地切换等级（点击药丸循环 1→2→3→1）
    var LEVEL_LABELS = { 1: i18n.levelReplace, 2: i18n.levelReject, 3: i18n.levelReview };
    document.querySelectorAll('.sw-level-pill').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.dataset.id;
            var current = parseInt(this.dataset.level, 10) || 1;
            var next = current >= 3 ? 1 : current + 1;
            var row = this.closest('.sw-row');
            var self = this;
            post('set_level', { id: id, level: next })
                .then(function(data) {
                    if (data.ok) {
                        self.classList.remove('lv-1', 'lv-2', 'lv-3');
                        self.classList.add('lv-' + next);
                        self.dataset.level = next;
                        self.textContent = LEVEL_LABELS[next] || ('L' + next);
                        row.dataset.level = next;
                        flashRow(row);
                    } else {
                        notify(data.error || i18n.opFailed, 'error');
                    }
                })
                .catch(function(err) {
                    console.error(err);
                    notify(i18n.netError, 'error');
                });
        });
    });

    // 3. 就地切换分类
    document.querySelectorAll('.sw-cat-select').forEach(function(sel) {
        var original = sel.value;
        sel.addEventListener('change', function() {
            var id = this.dataset.id;
            var category = this.value;
            var row = this.closest('.sw-row');
            var self = this;
            post('set_category', { id: id, category: category })
                .then(function(data) {
                    if (data.ok) {
                        row.dataset.category = category;
                        flashRow(row);
                        original = category;
                    } else {
                        notify(data.error || i18n.opFailed, 'error');
                        self.value = original;
                    }
                })
                .catch(function(err) {
                    console.error(err);
                    notify(i18n.netError, 'error');
                    self.value = original;
                });
        });
    });

    // 4. 删除单条（AJAX，双击内联确认，无原生弹窗）
    document.querySelectorAll('.sw-delete-btn').forEach(function(btn) {
        var armed = false;
        var armTimer = null;
        var originalHTML = btn.innerHTML;
        btn.addEventListener('click', function() {
            if (armed) {
                // 第二次点击：执行删除
                clearTimeout(armTimer);
                var id = this.dataset.id;
                var row = this.closest('.sw-row');
                row.classList.add('deleting');
                post('delete', { id: id })
                    .then(function(data) {
                        if (data.ok) {
                            row.style.transition = 'opacity 0.3s, height 0.3s';
                            row.style.opacity = '0';
                            setTimeout(function() {
                                row.remove();
                                // 更新总数显示
                                var metaEl = document.querySelector('.sw-list-meta strong');
                                if (metaEl) {
                                    var n = parseInt(metaEl.textContent, 10) - 1;
                                    metaEl.textContent = n;
                                }
                                // 同步选中态与浮动栏
                                if (typeof updateSelectionUI === 'function') updateSelectionUI();
                                // 若右栏正在编辑该条，重置为新增
                                if (typeof checkDetailAfterDelete === 'function') checkDetailAfterDelete(id);
                            }, 300);
                            notify(i18n.deleted, 'success');
                        } else {
                            row.classList.remove('deleting');
                            notify(data.error || i18n.deleteFailed, 'error');
                        }
                    })
                    .catch(function(err) {
                        console.error(err);
                        row.classList.remove('deleting');
                        notify(i18n.netError, 'error');
                    });
                return;
            }
            // 第一次点击：武装确认状态
            armed = true;
            this.classList.add('armed');
            this.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
            this.title = i18n.confirmAgain;
            armTimer = setTimeout(function() {
                armed = false;
                btn.classList.remove('armed');
                btn.innerHTML = originalHTML;
                btn.title = i18n.deleteTitle;
            }, 3000);
        });
    });

    // 5. 全选 + 选中态同步 + 浮动批量栏
    var checkAll = document.getElementById('sw-check-all');
    var floatingBar = document.getElementById('swFloatingBar');
    var fbCount = document.getElementById('swFbCount');
    var batchCount = document.getElementById('sw-batch-count');
    var listForm = document.getElementById('sw-list-form');

    function getCheckedBoxes() {
        return document.querySelectorAll('.sw-check:checked');
    }
    function getAllBoxes() {
        return document.querySelectorAll('.sw-check');
    }
    function updateSelectionUI() {
        var all = getAllBoxes();
        var checked = getCheckedBoxes();
        var count = checked.length;
        // 同步全选框状态
        if (checkAll) {
            checkAll.checked = all.length > 0 && all.length === count;
            checkAll.indeterminate = count > 0 && count < all.length;
        }
        // 同步行选中态样式
        all.forEach(function(c) {
            var row = c.closest('.sw-row');
            if (row) row.classList.toggle('checked', c.checked);
        });
        // 更新计数
        if (fbCount) fbCount.textContent = count;
        if (batchCount) batchCount.textContent = count;
        // 浮动栏显示/隐藏
        if (floatingBar) {
            floatingBar.classList.toggle('visible', count > 0);
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', function() {
            document.querySelectorAll('.sw-check').forEach(function(c) {
                c.checked = checkAll.checked;
            });
            updateSelectionUI();
        });
    }
    document.querySelectorAll('.sw-check').forEach(function(c) {
        c.addEventListener('change', updateSelectionUI);
    });

    // 浮动栏：取消选择
    var fbClose = document.getElementById('swFbClose');
    if (fbClose) {
        fbClose.addEventListener('click', function() {
            document.querySelectorAll('.sw-check').forEach(function(c) { c.checked = false; });
            updateSelectionUI();
        });
    }
    // 浮动栏：全选本页
    var fbSelectAll = document.getElementById('swFbSelectAll');
    if (fbSelectAll) {
        fbSelectAll.addEventListener('click', function() {
            document.querySelectorAll('.sw-check').forEach(function(c) { c.checked = true; });
            updateSelectionUI();
        });
    }
    // 浮动栏：批量操作
    document.querySelectorAll('.sw-fb-btn[data-batch]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var action = this.dataset.batch;
            var checked = getCheckedBoxes();
            if (checked.length === 0) return;
            var ids = Array.prototype.map.call(checked, function(c) { return c.value; });
            // 通过隐藏字段提交表单
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'batch_action';
            input.value = action;
            listForm.appendChild(input);
            // 确保所有 ids[] 都保留（默认表单已有），直接提交
            listForm.submit();
        });
    });

    // 6. 表头排序（前端）
    var table = document.querySelector('.sw-table');
    if (table) {
        var sortState = { key: null, dir: 'asc' };

        table.querySelectorAll('th.sortable').forEach(function(th) {
            th.addEventListener('click', function() {
                var sortKey = this.dataset.sort;
                if (sortState.key === sortKey) {
                    sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortState.key = sortKey;
                    sortState.dir = 'asc';
                }

                // 更新表头视觉
                table.querySelectorAll('th.sortable').forEach(function(t) {
                    t.classList.remove('asc', 'desc', 'active');
                    var ind = t.querySelector('.sort-ind');
                    if (ind) ind.textContent = '';
                });
                this.classList.add('active', sortState.dir);
                var ind = this.querySelector('.sort-ind');
                if (ind) ind.textContent = sortState.dir === 'asc' ? '↑' : '↓';

                var tbody = table.querySelector('tbody');
                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                rows.sort(function(a, b) {
                    var av = a.dataset[sortKey] || '';
                    var bv = b.dataset[sortKey] || '';
                    if (sortKey === 'level') {
                        av = parseInt(av) || 0;
                        bv = parseInt(bv) || 0;
                        return sortState.dir === 'asc' ? av - bv : bv - av;
                    }
                    if (sortState.dir === 'asc') return av.localeCompare(bv, 'zh-Hans-CN');
                    return bv.localeCompare(av, 'zh-Hans-CN');
                });
                rows.forEach(function(r) { tbody.appendChild(r); });
            });
        });
    }
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
